<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Domain\Enum\PayoutMethod;
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
            'creditExposure', 'receivables', 'defectiveReturns', 'spareAgeing',
            'profitAndLoss', 'technicianDues',
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
        $query = $this->fetchTable('CompanyInvoices')->find()
            ->contain(['Companies'])
            ->orderByDesc('period_end');

        foreach (['company_id' => 'company_id', 'status' => 'status'] as $param => $column) {
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
        $companyId = (int)$this->request->getQuery('company_id');

        if ($companyId <= 0) {
            return $this->fail('validation_error', 'A company_id is required.', 422);
        }

        return $this->respond((new SettlementService())->previewInvoice($companyId, $start, $end));
    }

    /**
     * POST /api/invoices/generate
     */
    public function generateInvoice(): Response
    {
        $companyId = (int)$this->request->getData('company_id');
        if ($companyId <= 0) {
            return $this->fail('validation_error', 'A company_id is required.', 422, [
                'company_id' => ['Select the company to invoice.'],
            ]);
        }

        [$start, $end] = $this->period(fromBody: true);

        $result = (new SettlementService())->generateInvoice(
            $companyId,
            $start,
            $end,
            $this->currentUserId(),
        );

        if ($result['ok'] === false) {
            return $this->fail('nothing_to_invoice', 'No invoice was raised.', 422, $result['errors']);
        }

        return $this->respond(
            $this->fetchTable('CompanyInvoices')->get($result['invoice_id'], contain: ['Companies']),
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

        return $this->respond($this->fetchTable('CompanyInvoices')->get((int)$id));
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
            $this->currentUserId(),
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

    /**
     * GET /api/receivables
     *
     * What every company owes, split by how far along it is: closed work
     * nobody has invoiced yet, invoices drafted, invoices served and
     * unpaid, and what has been received. The first of those is the one
     * an invoice list cannot show, and it is the one that goes missing.
     */
    public function receivables(): Response
    {
        $asOf = $this->request->getQuery('as_of');

        return $this->respond((new SettlementService())->receivables(
            is_string($asOf) && $asOf !== '' ? $asOf : null,
        ));
    }

    // -----------------------------------------------------------------
    // profit & loss
    // -----------------------------------------------------------------

    /**
     * GET /api/reports/profit-loss
     *
     * Income, expenses and net margin for tickets closed in the period —
     * defaults to last calendar month, same as an invoice or payout run.
     */
    public function profitAndLoss(): Response
    {
        [$start, $end] = $this->period();

        return $this->respond((new SettlementService())->profitAndLoss($start, $end));
    }

    /**
     * GET /api/technician-dues
     *
     * What every technician is owed right now, whether or not a payout
     * has been raised for it yet.
     */
    public function technicianDues(): Response
    {
        $asOf = $this->request->getQuery('as_of');

        return $this->respond((new SettlementService())->technicianDues(
            is_string($asOf) && $asOf !== '' ? $asOf : null,
        ));
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

        // A technician is paid by US, out of our margin — the company
        // never pays them directly — so how the money left our hands is
        // the only record that a payout actually happened. Constrained to
        // the three routes in use: an unrecognised string here produces a
        // payout marked paid with no way to trace the transfer.
        $method = (string)$this->request->getData('method', 'bank_transfer');
        if (!in_array($method, PayoutMethod::values(), true)) {
            return $this->fail('validation_error', 'Unrecognised payment method.', 422, [
                'method' => [sprintf('Use one of: %s.', implode(', ', PayoutMethod::values()))],
            ]);
        }

        $result = (new SettlementService())->markPayoutPaid(
            (int)$id,
            $method,
            $this->request->getData('reference'),
            $this->currentUserId(),
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
        $companyId = (int)$this->request->getQuery('company_id', 0);

        return $this->respond((new SpareService())->defectiveReturnsDue(
            $companyId > 0 ? $companyId : null,
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
        $companyId = (int)$this->request->getQuery('company_id', 0);

        return $this->respond((new SpareService())->sparesNearingBillingCutoff(
            $companyId > 0 ? $companyId : null,
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
