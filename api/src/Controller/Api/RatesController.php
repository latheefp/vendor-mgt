<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Domain\Charge\ChargeBuilder;
use App\Domain\Charge\ChargeLine;
use App\Domain\Enum\WarrantyScope;
use App\Domain\Exception\RateNotFoundException;
use App\Domain\Exception\UnrateableTicketException;
use App\Domain\Payout\PayoutBuilder;
use App\Domain\Payout\TechnicianRateData;
use App\Domain\Rate\RateContext;
use App\Domain\Rate\RateResolver;
use App\Domain\Sla\SlaCalculator;
use App\Domain\Sla\SlaEvaluator;
use App\Service\RateCardRepository;
use Cake\Http\Response;
use DateTimeImmutable;
use Throwable;

/**
 * Price a job without committing to it.
 *
 * This endpoint is the desk's "what will this job earn?" question and the
 * single most useful thing to have before the ticket screens exist: it runs
 * the real engine against the real rate card, so a quoted figure and an
 * invoiced figure are produced by the same code path.
 *
 * It is a preview only. Nothing is written and nothing is frozen — that
 * happens once, at closure.
 */
class RatesController extends ApiController
{
    /**
     * POST /api/rates/preview
     *
     * {
     *   "vendor_id": 1,
     *   "job_type": "service",
     *   "warranty_scope": "in_warranty",
     *   "size_inch": 55,
     *   "received_at": "2026-07-01 09:00:00",
     *   "closed_at":   "2026-07-02 15:00:00",
     *   "travel_km": 42,
     *   "technician_flat_rupees": 250
     * }
     */
    public function preview(): Response
    {
        $input = $this->request->getData();

        $vendorId = (int)($input['vendor_id'] ?? 0);
        if ($vendorId <= 0) {
            return $this->fail('validation_error', 'A vendor_id is required.', 422, [
                'vendor_id' => ['Select the vendor this job belongs to.'],
            ]);
        }

        $jobType = trim((string)($input['job_type'] ?? ''));
        if ($jobType === '') {
            return $this->fail('validation_error', 'A job_type is required.', 422, [
                'job_type' => ['Select what kind of job this is.'],
            ]);
        }

        $repository = new RateCardRepository();

        // A vendor may send their own wording ("Complaint Type: Service"),
        // so try the alias table before assuming it is one of our codes.
        $scopeOverride = null;
        $alias = $repository->resolveVendorJobType($vendorId, $jobType);
        if ($alias !== null) {
            $jobType = $alias['job_type_code'];
            $scopeOverride = $alias['warranty_scope'];
        }

        $scopeValue = $scopeOverride ?? (string)($input['warranty_scope'] ?? 'unknown');
        $scope = WarrantyScope::tryFrom($scopeValue);
        if ($scope === null) {
            return $this->fail('validation_error', 'Unrecognised warranty_scope.', 422, [
                'warranty_scope' => [
                    'Use one of: in_warranty, out_of_warranty, not_applicable, unknown.',
                ],
            ]);
        }

        try {
            $onDate = isset($input['received_at'])
                ? (new DateTimeImmutable((string)$input['received_at']))->format('Y-m-d')
                : null;

            $cardId = $repository->activeCardId($vendorId, $onDate);
            $terms = $repository->agreementTerms($vendorId, $onDate);
            $items = $repository->items($cardId);
            $rules = $repository->slaRules($cardId);

            $receivedAt = new DateTimeImmutable((string)($input['received_at'] ?? 'now'));
            $closedAt = isset($input['closed_at'])
                ? new DateTimeImmutable((string)$input['closed_at'])
                : null;

            $timing = (new SlaCalculator())->calculate(
                receivedAt: $receivedAt,
                firstContactAt: isset($input['first_contact_at'])
                    ? new DateTimeImmutable((string)$input['first_contact_at'])
                    : null,
                visitedAt: isset($input['visited_at'])
                    ? new DateTimeImmutable((string)$input['visited_at'])
                    : $closedAt,
                closedAt: $closedAt,
                holds: [],
                windows: $terms->windows(),
            );

            $rate = (new RateResolver())->resolve(
                new RateContext(
                    jobTypeCode: $jobType,
                    warrantyScope: $scope,
                    productCategoryCode: isset($input['product_category'])
                        ? (string)$input['product_category']
                        : null,
                    sizeInch: isset($input['size_inch']) ? (float)$input['size_inch'] : null,
                ),
                $items,
                $cardId,
            );

            $matched = (new SlaEvaluator())->evaluate($timing, $rules, $jobType, $scope);

            $travelKm = isset($input['travel_km']) ? (float)$input['travel_km'] : null;

            $charges = (new ChargeBuilder())->build(
                $rate,
                $matched,
                $terms,
                $timing,
                $travelKm,
            );

            // The technician side, when the caller supplies a rate to model.
            if (isset($input['technician_flat_rupees'])) {
                $technicianRate = new TechnicianRateData(
                    id: 0,
                    flatAmount: \App\Domain\Money::fromRupees((int)$input['technician_flat_rupees']),
                    bonusSharePct: (string)($input['technician_bonus_share_pct'] ?? '100.00'),
                    penaltyRecoveryPct: (string)($input['technician_penalty_recovery_pct'] ?? '0.00'),
                );

                $charges = $charges->withLines(
                    (new PayoutBuilder())->build($charges, $technicianRate, $jobType, $travelKm),
                );
            }

            return $this->respond([
                'rate_card_id' => $cardId,
                'resolved' => [
                    'label' => $rate->description(),
                    'amount' => $rate->amount()->jsonSerialize(),
                    'payer' => $rate->payer()->value,
                    'ledger' => $rate->ledger()->value,
                    'size_band' => $rate->item->describeBand(),
                ],
                'sla' => $timing->toArray(),
                'matched_rules' => array_map(
                    static fn ($m): array => [
                        'code' => $m->rule->code,
                        'description' => $m->description(),
                        'amount' => $m->amount()->jsonSerialize(),
                    ],
                    $matched,
                ),
                'lines' => array_map(
                    static fn (ChargeLine $l): array => [
                        'type' => $l->type->value,
                        'ledger' => $l->ledger->value,
                        'description' => $l->description,
                        'quantity' => $l->quantity,
                        'amount' => $l->amount->jsonSerialize(),
                    ],
                    $charges->lines,
                ),
                'totals' => $charges->summary(),
            ], ['preview' => true, 'persisted' => false]);
        } catch (UnrateableTicketException $e) {
            // Our data is incomplete — the desk can fix this.
            return $this->fail('unrateable_ticket', $e->getMessage(), 422);
        } catch (RateNotFoundException $e) {
            /*
             * Usually a genuine gap in the vendor agreement rather than a
             * bug. The Dianora card, for example, prices out-of-warranty
             * service for 24-43", 45-55" and 65-85" — so a 60" set has no
             * agreed rate at all, and neither does a 44" set anywhere.
             *
             * 409 rather than 500: nothing is broken, the two parties just
             * have not agreed a price yet.
             */
            return $this->fail('rate_not_found', $e->getMessage(), 409, [], [
                'context' => $e->context->toArray(),
                'rate_card_id' => $e->rateCardId,
                'action_required' => 'Confirm this rate with the vendor by email, then add it to the rate card.',
            ]);
        } catch (Throwable $e) {
            return $this->fail('preview_failed', $e->getMessage(), 500);
        }
    }
}
