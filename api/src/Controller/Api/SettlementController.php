<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\SettlementService;
use App\Service\SpareService;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use Cake\I18n\DateTime;

/**
 * Invoice runs, payout runs, and the two ageing reports that stop money
 * leaking quietly.
 *
 * Nothing here prices anything. Both runs copy charge lines that were
 * frozen at closure, which is what guarantees an invoice agrees with the
 * tickets behind it however many times the rate card has changed since.
 */
class SettlementController extends ApiController
{
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        $this->Authentication->allowUnauthenticated([
            'invoices', 'invoice', 'previewInvoice', 'payouts', 'payout',
            'creditExposure', 'defectiveReturns', 'spareAgeing',
        ]);
    }

    // -----------------------------------------------------------------
    // company invoices
    // -----------------------------------------------------------------

    /**
     * GET /api/invoices
     */
    public function invoices(): Response
    {
        $query = $this->fetchTable('VendorInvoices')->find()
            ->contain(['Vendors'])
            ->orderByDesc('period_end');

        foreach (['vendor_id' => 'vendor_id', 'status' => 'status'] as $param => $column) {
            $value = $this->request->getQuery($param);
            if ($value !== null && $value !== '') {
                $query->where([$column => $value]);
            }
        }

        return $this->respond($query->limit(100)->all());
    }

    /**
     * GET /api/invoices/{id}
     *
     * The total, and the parts it is made of. A company querying an
     * invoice never asks about the total — they ask about one line on one
     * job, so that is what this returns.
     */
    public function invoice(?string $id = null): Response
    {
        $id = $this->routeParam('id', $id);

        $detail = (new SettlementService())->invoiceDetail((int)$id);

        if ($detail === null) {
            return $this->fail('not_found', 'No such invoice.', 404);
        }

        return $this->respond($detail);
    }

    /**
     * PATCH /api/invoices/{id}/lines/{line_id}
     *
     * Restate one line on a draft invoice. The frozen charge behind it is
     * untouched — what changes is the invoice's own copy, and the amount
     * it was raised at is kept beside the new one.
     */
    public function overrideInvoiceLine(?string $id = null, ?string $lineId = null): Response
    {
        $id = $this->routeParam('id', $id);
        $lineId = $this->routeParam('line_id', $lineId);

        $amount = $this->request->getData('amount');
        if ($amount === null || trim((string)$amount) === '') {
            return $this->fail('validation_error', 'An amount is required.', 422, [
                'amount' => ['What should this line bill instead?'],
            ]);
        }

        $result = (new SettlementService())->overrideInvoiceLine(
            (int)$id,
            (int)$lineId,
            (string)$amount,
            (string)$this->request->getData('reason', ''),
            $this->currentUserId(),
        );

        if ($result['ok'] === false) {
            return $this->fail(
                $result['code'],
                'The line could not be changed.',
                $this->statusForCode($result['code']),
                $result['errors'],
            );
        }

        return $this->respond($result['invoice']);
    }

    /**
     * DELETE /api/invoices/{id}/lines/{line_id}
     *
     * Drop an override and go back to the amount the run raised.
     */
    public function resetInvoiceLine(?string $id = null, ?string $lineId = null): Response
    {
        $id = $this->routeParam('id', $id);
        $lineId = $this->routeParam('line_id', $lineId);

        $result = (new SettlementService())->resetInvoiceLine((int)$id, (int)$lineId);

        if ($result['ok'] === false) {
            return $this->fail(
                $result['code'],
                'The override could not be removed.',
                $this->statusForCode($result['code']),
                $result['errors'],
            );
        }

        return $this->respond($result['invoice']);
    }

    /**
     * GET /api/invoices/preview
     *
     * What a run would pick up, before committing to it. Worth having
     * separately: generating an invoice claims the charge lines, and an
     * operator who wanted to check a total should not have to cancel an
     * invoice to undo the look.
     */
    public function previewInvoice(): Response
    {
        [$start, $end] = $this->period();
        $vendorId = (int)$this->request->getQuery('vendor_id');

        if ($vendorId <= 0) {
            return $this->fail('validation_error', 'A vendor_id is required.', 422);
        }

        return $this->respond((new SettlementService())->previewInvoice($vendorId, $start, $end));
    }

    /**
     * POST /api/invoices/generate
     */
    public function generateInvoice(): Response
    {
        $vendorId = (int)$this->request->getData('vendor_id');
        if ($vendorId <= 0) {
            return $this->fail('validation_error', 'A vendor_id is required.', 422, [
                'vendor_id' => ['Select the company to invoice.'],
            ]);
        }

        [$start, $end] = $this->period(fromBody: true);

        $result = (new SettlementService())->generateInvoice(
            $vendorId,
            $start,
            $end,
            $this->currentUserId(),
        );

        if ($result['ok'] === false) {
            return $this->fail('nothing_to_invoice', 'No invoice was raised.', 422, $result['errors']);
        }

        return $this->respond(
            $this->fetchTable('VendorInvoices')->get($result['invoice_id'], contain: ['Vendors']),
            ['totals' => $result['totals']],
            201,
        );
    }

    /**
     * POST /api/invoices/{id}/send
     */
    public function sendInvoice(?string $id = null): Response
    {
        $id = $this->routeParam('id', $id);

        $email = trim((string)$this->request->getData('email'));
        if ($email === '') {
            // Clause 11 recognises email only. An invoice served any other
            // way may not count as served at all.
            return $this->fail('validation_error', 'An email address is required.', 422, [
                'email' => ['The agreement recognises email only.'],
            ]);
        }

        $result = (new SettlementService())->markInvoiceSent(
            (int)$id,
            $email,
            $this->request->getData('message_id'),
        );

        if ($result['ok'] === false) {
            return $this->fail('invalid_state', 'The invoice could not be sent.', 409, $result['errors']);
        }

        return $this->respond($this->fetchTable('VendorInvoices')->get((int)$id));
    }

    /**
     * POST /api/invoices/{id}/payment
     */
    public function recordPayment(?string $id = null): Response
    {
        $id = $this->routeParam('id', $id);

        $amount = (int)$this->request->getData('amount_paise');
        if ($amount <= 0) {
            return $this->fail('validation_error', 'A payment amount is required.', 422, [
                'amount_paise' => ['Must be more than zero.'],
            ]);
        }

        $result = (new SettlementService())->recordInvoicePayment(
            (int)$id,
            $amount,
            $this->request->getData('reference'),
        );

        return $this->respond($result);
    }

    /**
     * GET /api/companies/{id}/credit-exposure
     *
     * Clause 4 caps what may be outstanding. The moment worth knowing is
     * before the cap is breached, so this returns headroom rather than a
     * yes or no.
     */
    public function creditExposure(?string $id = null): Response
    {
        $id = $this->routeParam('id', $id);

        return $this->respond((new SettlementService())->creditExposure((int)$id));
    }

    // -----------------------------------------------------------------
    // technician payouts
    // -----------------------------------------------------------------

    /**
     * GET /api/payouts
     */
    public function payouts(): Response
    {
        $query = $this->fetchTable('TechnicianPayouts')->find()
            ->contain(['Technicians'])
            ->orderByDesc('period_end');

        $technicianId = $this->request->getQuery('technician_id');
        if ($technicianId !== null && $technicianId !== '') {
            $query->where(['technician_id' => $technicianId]);
        }

        $status = $this->request->getQuery('status');
        if ($status !== null && $status !== '') {
            $query->where(['status' => $status]);
        }

        return $this->respond($query->limit(100)->all());
    }

    /**
     * GET /api/payouts/{id}
     */
    public function payout(?string $id = null): Response
    {
        $id = $this->routeParam('id', $id);

        $detail = (new SettlementService())->payoutDetail((int)$id);

        if ($detail === null) {
            return $this->fail('not_found', 'No such payout.', 404);
        }

        return $this->respond($detail);
    }

    /**
     * PATCH /api/payouts/{id}/lines/{line_id}
     */
    public function overridePayoutLine(?string $id = null, ?string $lineId = null): Response
    {
        $id = $this->routeParam('id', $id);
        $lineId = $this->routeParam('line_id', $lineId);

        $amount = $this->request->getData('amount');
        if ($amount === null || trim((string)$amount) === '') {
            return $this->fail('validation_error', 'An amount is required.', 422, [
                'amount' => ['What should this line pay instead?'],
            ]);
        }

        $result = (new SettlementService())->overridePayoutLine(
            (int)$id,
            (int)$lineId,
            (string)$amount,
            (string)$this->request->getData('reason', ''),
            $this->currentUserId(),
        );

        if ($result['ok'] === false) {
            return $this->fail(
                $result['code'],
                'The line could not be changed.',
                $this->statusForCode($result['code']),
                $result['errors'],
            );
        }

        return $this->respond($result['payout']);
    }

    /**
     * DELETE /api/payouts/{id}/lines/{line_id}
     */
    public function resetPayoutLine(?string $id = null, ?string $lineId = null): Response
    {
        $id = $this->routeParam('id', $id);
        $lineId = $this->routeParam('line_id', $lineId);

        $result = (new SettlementService())->resetPayoutLine((int)$id, (int)$lineId);

        if ($result['ok'] === false) {
            return $this->fail(
                $result['code'],
                'The override could not be removed.',
                $this->statusForCode($result['code']),
                $result['errors'],
            );
        }

        return $this->respond($result['payout']);
    }

    /**
     * POST /api/payouts/generate
     */
    public function generatePayout(): Response
    {
        $technicianId = (int)$this->request->getData('technician_id');
        if ($technicianId <= 0) {
            return $this->fail('validation_error', 'A technician_id is required.', 422, [
                'technician_id' => ['Select the technician to pay.'],
            ]);
        }

        [$start, $end] = $this->period(fromBody: true);

        $result = (new SettlementService())->generatePayout(
            $technicianId,
            $start,
            $end,
            $this->currentUserId(),
        );

        if ($result['ok'] === false) {
            return $this->fail('nothing_to_pay', 'No payout was raised.', 422, $result['errors']);
        }

        return $this->respond(
            $this->fetchTable('TechnicianPayouts')->get($result['payout_id'], contain: ['Technicians']),
            ['totals' => $result['totals']],
            201,
        );
    }

    /**
     * POST /api/payouts/{id}/approve
     */
    public function approvePayout(?string $id = null): Response
    {
        $id = $this->routeParam('id', $id);

        $result = (new SettlementService())->approvePayout((int)$id, $this->currentUserId());

        if ($result['ok'] === false) {
            return $this->fail('invalid_state', 'The payout could not be approved.', 409, $result['errors']);
        }

        return $this->respond($this->fetchTable('TechnicianPayouts')->get((int)$id));
    }

    /**
     * POST /api/payouts/{id}/pay
     */
    public function payPayout(?string $id = null): Response
    {
        $id = $this->routeParam('id', $id);

        $result = (new SettlementService())->markPayoutPaid(
            (int)$id,
            (string)$this->request->getData('method', 'bank_transfer'),
            $this->request->getData('reference'),
        );

        if ($result['ok'] === false) {
            return $this->fail('invalid_state', 'The payout could not be marked paid.', 409, $result['errors']);
        }

        return $this->respond($this->fetchTable('TechnicianPayouts')->get((int)$id));
    }

    // -----------------------------------------------------------------
    // ageing
    // -----------------------------------------------------------------

    /**
     * GET /api/spares/defective-returns
     *
     * Clause 9 settles defectives on a cycle. What matters is the ones
     * about to miss the next one.
     */
    public function defectiveReturns(): Response
    {
        $vendorId = (int)$this->request->getQuery('vendor_id', 0);

        return $this->respond((new SpareService())->defectiveReturnsDue(
            $vendorId > 0 ? $vendorId : null,
            (int)$this->request->getQuery('within_days', 3),
        ));
    }

    /**
     * GET /api/spares/ageing
     *
     * Clause 10: parts approaching the day they become our cost. After
     * that date the money is already gone, so this is a warning list
     * rather than a report.
     */
    public function spareAgeing(): Response
    {
        $vendorId = (int)$this->request->getQuery('vendor_id', 0);

        return $this->respond((new SpareService())->sparesNearingBillingCutoff(
            $vendorId > 0 ? $vendorId : null,
            (int)$this->request->getQuery('within_days', 5),
        ));
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /**
     * The HTTP status a service-level failure code deserves.
     *
     * Kept apart from the message so the client can tell "that line is not
     * there" from "that line may no longer be changed" — the second is a
     * state the operator can do something about, the first is not.
     */
    private function statusForCode(string $code): int
    {
        return match ($code) {
            'not_found' => 404,
            'invalid_state' => 409,
            default => 422,
        };
    }

    /**
     * The period being settled, defaulting to last calendar month.
     *
     * Last month rather than this one because a run for the current month
     * would bill work that is still being done, and the corrections that
     * follow are exactly what an invoice is supposed to avoid.
     *
     * @return array{0: string, 1: string}
     */
    private function period(bool $fromBody = false): array
    {
        $read = fn (string $key): mixed => $fromBody
            ? $this->request->getData($key)
            : $this->request->getQuery($key);

        $start = $read('period_start');
        $end = $read('period_end');

        if (is_string($start) && is_string($end) && $start !== '' && $end !== '') {
            return [$start, $end];
        }

        $lastMonth = DateTime::now()->modify('first day of last month');

        return [
            $lastMonth->format('Y-m-01'),
            $lastMonth->modify('last day of this month')->format('Y-m-d'),
        ];
    }

}
