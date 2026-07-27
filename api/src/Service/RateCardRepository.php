<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\Charge\AgreementTerms;
use App\Domain\Enum\Payer;
use App\Domain\Enum\PayoutModel;
use App\Domain\Enum\SlaComparator;
use App\Domain\Enum\SlaKind;
use App\Domain\Enum\SlaMetric;
use App\Domain\Enum\WarrantyScope;
use App\Domain\Money;
use App\Domain\Payout\TechnicianRateData;
use App\Domain\Rate\RateCardItemData;
use App\Domain\Sla\SlaRuleData;
use App\Domain\Sla\SlaWindows;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * The seam between the ORM and the money engine.
 *
 * `src/Domain` has no knowledge of CakePHP; this class is the only place
 * that turns database rows into the framework-free DTOs the engine
 * consumes. Keeping the translation in one place is what lets the pricing
 * logic be unit tested against the numbers in the signed agreement with no
 * database at all.
 */
class RateCardRepository
{
    use LocatorAwareTrait;

    /**
     * The rate card in force for a vendor on a given date.
     *
     * Date-driven rather than "the latest one", because a ticket worked in
     * March must be priced under March's card even if April's is now
     * active.
     */
    public function activeCardId(int $vendorId, ?string $onDate = null): int
    {
        $onDate ??= date('Y-m-d');

        $card = $this->fetchTable('RateCards')->find()
            ->select(['id'])
            ->where([
                'vendor_id' => $vendorId,
                'status' => 'active',
                'effective_from <=' => $onDate,
                'OR' => [
                    'effective_to IS' => null,
                    'effective_to >=' => $onDate,
                ],
            ])
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();

        if ($card === null) {
            throw new RecordNotFoundException(sprintf(
                'No active rate card for vendor %d on %s.',
                $vendorId,
                $onDate,
            ));
        }

        return (int)$card->id;
    }

    /**
     * All priced lines on a card, as domain DTOs.
     *
     * @return list<RateCardItemData>
     */
    public function items(int $rateCardId): array
    {
        $rows = $this->fetchTable('RateCardItems')->find()
            ->select([
                'RateCardItems.id',
                'RateCardItems.warranty_scope',
                'RateCardItems.size_min_inch',
                'RateCardItems.size_max_inch',
                'RateCardItems.amount_paise',
                'RateCardItems.payer',
                'RateCardItems.label',
                'RateCardItems.priority',
                'RateCardItems.is_active',
                'job_type_code' => 'JobTypes.code',
                'category_code' => 'ProductCategories.code',
            ])
            ->join([
                'JobTypes' => [
                    'table' => 'job_types',
                    'type' => 'INNER',
                    'conditions' => 'JobTypes.id = RateCardItems.job_type_id',
                ],
                'ProductCategories' => [
                    'table' => 'product_categories',
                    'type' => 'LEFT',
                    'conditions' => 'ProductCategories.id = RateCardItems.product_category_id',
                ],
            ])
            ->where([
                'RateCardItems.rate_card_id' => $rateCardId,
                'RateCardItems.is_active' => true,
            ])
            ->disableHydration()
            ->all();

        $items = [];
        foreach ($rows as $row) {
            $items[] = new RateCardItemData(
                id: (int)$row['id'],
                jobTypeCode: (string)$row['job_type_code'],
                warrantyScope: WarrantyScope::from((string)$row['warranty_scope']),
                amount: Money::fromPaise((int)$row['amount_paise']),
                payer: Payer::from((string)$row['payer']),
                label: (string)$row['label'],
                productCategoryCode: $row['category_code'] !== null ? (string)$row['category_code'] : null,
                sizeMinInch: $row['size_min_inch'] !== null ? (float)$row['size_min_inch'] : null,
                sizeMaxInch: $row['size_max_inch'] !== null ? (float)$row['size_max_inch'] : null,
                priority: (int)$row['priority'],
                isActive: (bool)$row['is_active'],
            );
        }

        return $items;
    }

    /**
     * The SLA bonus and penalty rules attached to a card.
     *
     * @return list<SlaRuleData>
     */
    public function slaRules(int $rateCardId): array
    {
        $rows = $this->fetchTable('SlaRules')->find()
            ->where(['rate_card_id' => $rateCardId, 'is_active' => true])
            ->orderByAsc('priority')
            ->orderByAsc('id')
            ->disableHydration()
            ->all();

        $rules = [];
        foreach ($rows as $row) {
            // Stored as a JSON array of job type codes; null means "any".
            $jobTypes = null;
            if (!empty($row['applies_to_job_types'])) {
                $decoded = is_array($row['applies_to_job_types'])
                    ? $row['applies_to_job_types']
                    : json_decode((string)$row['applies_to_job_types'], true);
                $jobTypes = is_array($decoded) ? array_values($decoded) : null;
            }

            $rules[] = new SlaRuleData(
                id: (int)$row['id'],
                code: (string)$row['code'],
                label: (string)$row['label'],
                kind: SlaKind::from((string)$row['kind']),
                metric: SlaMetric::from((string)$row['metric']),
                comparator: SlaComparator::from((string)$row['comparator']),
                amount: Money::fromPaise((int)$row['amount_paise']),
                thresholdFromHours: $row['threshold_from_hours'] !== null
                    ? (float)$row['threshold_from_hours']
                    : null,
                thresholdToHours: $row['threshold_to_hours'] !== null
                    ? (float)$row['threshold_to_hours']
                    : null,
                appliesToJobTypes: $jobTypes,
                warrantyScope: $row['warranty_scope'] !== null
                    ? WarrantyScope::from((string)$row['warranty_scope'])
                    : null,
                priority: (int)$row['priority'],
                isStackable: (bool)$row['is_stackable'],
                isActive: (bool)$row['is_active'],
            );
        }

        return $rules;
    }

    /**
     * The commercial terms in force for a vendor.
     */
    public function agreementTerms(int $vendorId, ?string $onDate = null): AgreementTerms
    {
        $onDate ??= date('Y-m-d');

        $row = $this->fetchTable('VendorAgreements')->find()
            ->where([
                'vendor_id' => $vendorId,
                'status' => 'active',
                'effective_from <=' => $onDate,
                'OR' => [
                    'effective_to IS' => null,
                    'effective_to >=' => $onDate,
                ],
            ])
            ->orderByDesc('effective_from')
            ->disableHydration()
            ->first();

        if ($row === null) {
            throw new RecordNotFoundException(sprintf(
                'No active agreement for vendor %d on %s.',
                $vendorId,
                $onDate,
            ));
        }

        return new AgreementTerms(
            id: (int)$row['id'],
            oowRoyaltyPct: (string)$row['oow_royalty_pct'],
            spareMarginMinPct: (string)$row['spare_margin_min_pct'],
            spareMarginMaxPct: (string)$row['spare_margin_max_pct'],
            travelFreeKm: (float)$row['travel_free_km'],
            travelRatePerKm: Money::fromPaise((int)$row['travel_rate_per_km_paise']),
            slaWindows: new SlaWindows(
                contactHours: (float)$row['sla_contact_hours'],
                visitHours: (float)$row['sla_visit_hours'],
                closeHours: (float)$row['sla_close_hours'],
            ),
            creditLimit: Money::fromPaise((int)$row['credit_limit_paise']),
            repeatComplaintWindowDays: (int)$row['repeat_complaint_window_days'],
            defectiveReturnDays: (int)$row['defective_return_days'],
            spareBillingDays: (int)$row['spare_billing_days'],
            technicianBonusSharePct: (string)$row['technician_bonus_share_pct'],
            technicianPenaltyRecoveryPct: (string)$row['technician_penalty_recovery_pct'],
            royaltyAppliesToSpares: (bool)$row['royalty_applies_to_spares'],
        );
    }

    /**
     * The pay arrangement in force for one technician, bound to the company
     * whose work is being priced.
     *
     * The binding is the important half. `bonus_share_pct` and
     * `penalty_recovery_pct` are nullable, and null means "follow company
     * policy" — so a rate loaded without an agreement to fall back on would
     * silently pay the hardcoded 100%/0% rather than what was agreed.
     * Requiring the terms here makes that impossible to forget.
     */
    public function technicianRate(
        int $technicianId,
        AgreementTerms $terms,
        ?string $onDate = null,
        ?string $jobTypeCode = null,
    ): ?TechnicianRateData {
        $onDate ??= date('Y-m-d');

        $rows = $this->fetchTable('TechnicianRates')->find()
            ->where([
                'technician_id' => $technicianId,
                'is_active' => true,
                'effective_from <=' => $onDate,
                'OR' => [
                    'effective_to IS' => null,
                    'effective_to >=' => $onDate,
                ],
            ])
            ->orderByDesc('effective_from')
            ->disableHydration()
            ->all();

        foreach ($rows as $row) {
            $appliesTo = null;
            if (!empty($row['applies_to_job_types'])) {
                $decoded = is_array($row['applies_to_job_types'])
                    ? $row['applies_to_job_types']
                    : json_decode((string)$row['applies_to_job_types'], true);
                $appliesTo = is_array($decoded) ? array_values($decoded) : null;
            }

            $rate = new TechnicianRateData(
                id: (int)$row['id'],
                model: PayoutModel::from((string)$row['model']),
                flatAmount: $row['flat_amount_paise'] !== null
                    ? Money::fromPaise((int)$row['flat_amount_paise'])
                    : null,
                pctOfVendor: $row['pct_of_vendor'] !== null ? (string)$row['pct_of_vendor'] : null,
                monthlySalary: $row['monthly_salary_paise'] !== null
                    ? Money::fromPaise((int)$row['monthly_salary_paise'])
                    : null,
                travelFreeKm: (float)$row['travel_free_km'],
                travelRatePerKm: Money::fromPaise((int)$row['travel_rate_per_km_paise']),
                // Left null when the column is null: that is the technician
                // deferring to company policy, not an absent value.
                bonusSharePct: $row['bonus_share_pct'] !== null ? (string)$row['bonus_share_pct'] : null,
                penaltyRecoveryPct: $row['penalty_recovery_pct'] !== null
                    ? (string)$row['penalty_recovery_pct']
                    : null,
                appliesToJobTypes: $appliesTo,
                isActive: (bool)$row['is_active'],
            );

            // A technician can hold several rates at once — one general,
            // one for panel work. The most recent that covers this job wins.
            if ($jobTypeCode === null || $rate->appliesToJobType($jobTypeCode)) {
                return $rate->underTerms($terms);
            }
        }

        return null;
    }

    /**
     * Translate a vendor's own complaint-type wording into our job type.
     *
     * Dianora sends "Complaint Type: Service"; another vendor will send
     * "Breakdown" or "Out of Warranty Repair". Matching is case-insensitive
     * because the column collates as utf8mb4_unicode_ci — which is what the
     * vendors' inconsistent casing requires.
     *
     * @return array{job_type_code: string, warranty_scope: ?string}|null
     */
    public function resolveVendorJobType(int $vendorId, string $vendorLabel): ?array
    {
        $row = $this->fetchTable('VendorJobTypeAliases')->find()
            ->select(['VendorJobTypeAliases.warranty_scope', 'job_type_code' => 'JobTypes.code'])
            ->join([
                'JobTypes' => [
                    'table' => 'job_types',
                    'type' => 'INNER',
                    'conditions' => 'JobTypes.id = VendorJobTypeAliases.job_type_id',
                ],
            ])
            ->where([
                'VendorJobTypeAliases.vendor_id' => $vendorId,
                'VendorJobTypeAliases.vendor_label' => trim($vendorLabel),
                'VendorJobTypeAliases.is_active' => true,
            ])
            ->disableHydration()
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'job_type_code' => (string)$row['job_type_code'],
            'warranty_scope' => $row['warranty_scope'] !== null ? (string)$row['warranty_scope'] : null,
        ];
    }
}
