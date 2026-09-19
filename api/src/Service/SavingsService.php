<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\Money;
use Cake\ORM\Locator\LocatorAwareTrait;
use RuntimeException;

/**
 * The service centre's own cash position.
 *
 * This is a different figure from the P&L: `SettlementService::profitAndLoss()`
 * recognises income and expense the moment a charge freezes at ticket
 * closure, whether or not the money has actually moved. This ledger only
 * moves on a real cash event — a company invoice payment landing, a
 * technician payout actually being paid out, or a desk correction — which
 * is what makes it the right source for "can we afford to pay this
 * technician right now", a question the accrual figure cannot answer.
 *
 * Every write goes through `credit()` or `debit()`, which append one row
 * and stamp it with the balance immediately after — never an update to an
 * existing row. The running balance for a centre is therefore always just
 * "the newest row's `balance_after_paise`", recomputed from nothing.
 */
class SavingsService
{
    use LocatorAwareTrait;

    /**
     * The current balance for one centre, zero if it has never moved.
     */
    public function balance(int $serviceCenterId): Money
    {
        $last = $this->fetchTable('ServiceCenterSavingsEntries')->find()
            ->select(['balance_after_paise'])
            ->where(['service_center_id' => $serviceCenterId])
            ->orderByDesc('id')
            ->first();

        return Money::fromPaise((int)($last->balance_after_paise ?? 0));
    }

    /**
     * Every centre with its current balance, for the desk overview.
     *
     * @return list<array<string, mixed>>
     */
    public function balances(): array
    {
        $centers = $this->fetchTable('ServiceCenters')->find()
            ->select(['id', 'code', 'name'])
            ->orderByAsc('name')
            ->disableHydration()
            ->all()
            ->toList();

        // One balance each, not N+1: the newest row per centre, in a
        // single grouped query.
        $latest = $this->fetchTable('ServiceCenterSavingsEntries')->find()
            ->select([
                'service_center_id' => 'ServiceCenterSavingsEntries.service_center_id',
                'balance_after_paise' => 'MAX(ServiceCenterSavingsEntries.id)',
            ])
            ->groupBy('ServiceCenterSavingsEntries.service_center_id')
            ->disableHydration()
            ->all();

        $latestIds = [];
        foreach ($latest as $row) {
            $latestIds[] = (int)$row['balance_after_paise'];
        }

        $balancesByCenter = [];
        if ($latestIds !== []) {
            $rows = $this->fetchTable('ServiceCenterSavingsEntries')->find()
                ->select(['service_center_id', 'balance_after_paise'])
                ->where(['id IN' => $latestIds])
                ->disableHydration()
                ->all();

            foreach ($rows as $row) {
                $balancesByCenter[(int)$row['service_center_id']] = (int)$row['balance_after_paise'];
            }
        }

        $out = [];
        foreach ($centers as $center) {
            $out[] = [
                'service_center' => [
                    'id' => (int)$center['id'],
                    'code' => $center['code'],
                    'name' => $center['name'],
                ],
                'balance' => Money::fromPaise($balancesByCenter[(int)$center['id']] ?? 0)->jsonSerialize(),
            ];
        }

        return $out;
    }

    /**
     * The most recent entries for one centre, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function ledger(int $serviceCenterId, int $limit = 100): array
    {
        $rows = $this->fetchTable('ServiceCenterSavingsEntries')->find()
            ->select([
                'ServiceCenterSavingsEntries.id',
                'ServiceCenterSavingsEntries.entry_type',
                'ServiceCenterSavingsEntries.source_type',
                'ServiceCenterSavingsEntries.source_id',
                'ServiceCenterSavingsEntries.amount_paise',
                'ServiceCenterSavingsEntries.balance_after_paise',
                'ServiceCenterSavingsEntries.description',
                'ServiceCenterSavingsEntries.created',
                'created_by' => 'CreatedByUsers.name',
            ])
            ->leftJoinWith('CreatedByUsers')
            ->where(['ServiceCenterSavingsEntries.service_center_id' => $serviceCenterId])
            ->orderByDesc('ServiceCenterSavingsEntries.id')
            ->limit($limit)
            ->disableHydration()
            ->all()
            ->toList();

        return array_map(
            fn (array $row): array => [
                'id' => (int)$row['id'],
                'entry_type' => $row['entry_type'],
                'source_type' => $row['source_type'],
                'source_id' => $row['source_id'] !== null ? (int)$row['source_id'] : null,
                'amount' => Money::fromPaise((int)$row['amount_paise'])->jsonSerialize(),
                'balance_after' => Money::fromPaise((int)$row['balance_after_paise'])->jsonSerialize(),
                'description' => $row['description'],
                'created' => $row['created']->format('Y-m-d H:i:s'),
                'created_by' => $row['created_by'],
            ],
            $rows,
        );
    }

    /**
     * Money genuinely arriving — an invoice payment landing, most often.
     *
     * @return array<string, mixed> The entry just written.
     */
    public function credit(
        int $serviceCenterId,
        Money $amount,
        string $sourceType,
        ?int $sourceId,
        string $description,
        ?int $actorUserId = null,
    ): array {
        return $this->appendEntry(
            $serviceCenterId,
            'credit',
            $amount,
            $sourceType,
            $sourceId,
            $description,
            $actorUserId,
        );
    }

    /**
     * Money genuinely leaving — a technician payout actually paid, most
     * often.
     *
     * @return array<string, mixed> The entry just written.
     */
    public function debit(
        int $serviceCenterId,
        Money $amount,
        string $sourceType,
        ?int $sourceId,
        string $description,
        ?int $actorUserId = null,
    ): array {
        return $this->appendEntry(
            $serviceCenterId,
            'debit',
            $amount,
            $sourceType,
            $sourceId,
            $description,
            $actorUserId,
        );
    }

    /**
     * A desk correction: a signed amount, positive to credit and negative
     * to debit, for whatever a real cash event has not already covered.
     *
     * @return array<string, mixed> The entry just written.
     */
    public function adjust(
        int $serviceCenterId,
        Money $signedAmount,
        string $description,
        ?int $actorUserId = null,
    ): array {
        if ($signedAmount->isZero()) {
            throw new RuntimeException('An adjustment must move the balance one way or the other.');
        }

        return $signedAmount->isPositive()
            ? $this->credit($serviceCenterId, $signedAmount, 'manual_adjustment', null, $description, $actorUserId)
            : $this->debit(
                $serviceCenterId,
                $signedAmount->absolute(),
                'manual_adjustment',
                null,
                $description,
                $actorUserId,
            );
    }

    /**
     * Append one row and stamp it with the balance immediately after,
     * inside a transaction that locks the centre's newest row for its
     * duration — two debits racing each other read the same "previous
     * balance" otherwise, and one of the two balances written is wrong.
     *
     * @return array<string, mixed>
     */
    private function appendEntry(
        int $serviceCenterId,
        string $entryType,
        Money $amount,
        string $sourceType,
        ?int $sourceId,
        string $description,
        ?int $actorUserId,
    ): array {
        if (!$amount->isPositive()) {
            throw new RuntimeException('A savings entry must move a positive amount.');
        }

        $entries = $this->fetchTable('ServiceCenterSavingsEntries');

        return $entries->getConnection()->transactional(
            function () use (
                $entries,
                $serviceCenterId,
                $entryType,
                $amount,
                $sourceType,
                $sourceId,
                $description,
                $actorUserId,
            ): array {
                $last = $entries->find()
                    ->select(['balance_after_paise'])
                    ->where(['service_center_id' => $serviceCenterId])
                    ->orderByDesc('id')
                    ->epilog('FOR UPDATE')
                    ->first();

                $previous = (int)($last->balance_after_paise ?? 0);
                $balanceAfter = $entryType === 'credit' ? $previous + $amount->paise : $previous - $amount->paise;

                $entry = $entries->newEntity([
                    'service_center_id' => $serviceCenterId,
                    'entry_type' => $entryType,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'amount_paise' => $amount->paise,
                    'balance_after_paise' => $balanceAfter,
                    'description' => $description,
                    'created_by_user_id' => $actorUserId,
                ]);
                $entries->saveOrFail($entry);

                return [
                    'id' => (int)$entry->id,
                    'entry_type' => $entryType,
                    'amount' => $amount->jsonSerialize(),
                    'balance_after' => Money::fromPaise($balanceAfter)->jsonSerialize(),
                ];
            },
        );
    }
}
