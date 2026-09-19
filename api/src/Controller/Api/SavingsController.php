<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Domain\Money;
use App\Service\SavingsService;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * The service centre's own cash position — every branch's balance, the
 * ledger behind it, and the one write a person (not a ticket) makes to
 * it directly: a manual correction for whatever a real cash event does
 * not already cover.
 *
 * Every other entry on this ledger is written by `SettlementService`
 * itself, at the moment cash actually moves — an invoice payment
 * recorded, a technician payout marked paid. Nothing here computes a
 * balance; it only reads and corrects the one `SavingsService` keeps.
 */
class SavingsController extends ApiController
{
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        $this->Authentication->allowUnauthenticated(['balances', 'balance']);
    }

    /**
     * GET /api/service-centers/savings
     */
    public function balances(): Response
    {
        return $this->respond((new SavingsService())->balances());
    }

    /**
     * GET /api/service-centers/{id}/savings
     */
    public function balance(?string $id = null): Response
    {
        $id = (int)$this->routeParam('id', $id);
        $savings = new SavingsService();

        return $this->respond([
            'service_center_id' => $id,
            'balance' => $savings->balance($id)->jsonSerialize(),
            'ledger' => $savings->ledger($id, (int)$this->request->getQuery('limit', 100)),
        ]);
    }

    /**
     * POST /api/service-centers/{id}/savings/adjust
     *
     * A signed rupee amount: positive credits the balance, negative
     * debits it. Requires a reason, because unlike every other entry on
     * this ledger, nothing else explains why this one exists.
     */
    public function adjustSavings(?string $id = null): Response
    {
        $id = (int)$this->routeParam('id', $id);

        $amount = trim((string)$this->request->getData('amount'));
        $description = trim((string)$this->request->getData('description'));

        if ($description === '') {
            return $this->fail('validation_error', 'A reason is required.', 422, [
                'description' => ['Say what this adjustment corrects.'],
            ]);
        }

        try {
            $signed = Money::parse($amount);
        } catch (InvalidArgumentException) {
            return $this->fail('validation_error', 'A signed rupee amount is required.', 422, [
                'amount' => ['Use a plain number, negative to debit the balance.'],
            ]);
        }

        try {
            $entry = (new SavingsService())->adjust($id, $signed, $description, $this->currentUserId());
        } catch (RuntimeException $e) {
            return $this->fail('validation_error', $e->getMessage(), 422, ['amount' => [$e->getMessage()]]);
        }

        return $this->respond($entry, [], 201);
    }
}
