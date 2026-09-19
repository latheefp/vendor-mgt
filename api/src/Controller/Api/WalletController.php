<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Model\Entity\Technician;
use App\Service\SettlementService;
use Cake\Http\Response;
use Cake\I18n\DateTime;

/**
 * A technician's own view of what they are owed, and the one action they
 * can take on it themselves.
 *
 * Nothing here is scoped by a client-supplied technician id — every
 * action resolves the caller's own technician record from their signed-in
 * user id and refuses to proceed without one. That self-scoping lives
 * here rather than behind a shared Authorization component because there
 * isn't one loaded anywhere in this app yet; until there is, every
 * technician-facing endpoint has to do this check itself.
 *
 * "Withdraw" does not move money by itself — there is no payment gateway
 * behind this app, only bank transfers a person makes and records. It
 * raises a draft payout exactly the way the desk's own "generate payout"
 * does, over every unclaimed charge to date rather than one period, so
 * the desk still approves and pays it, but the technician no longer has
 * to ask someone to start that run.
 */
class WalletController extends ApiController
{
    /**
     * GET /api/wallet/me
     */
    public function me(): Response
    {
        $technician = $this->ownTechnician();
        if ($technician === null) {
            return $this->fail('not_a_technician', 'Only a technician has a wallet.', 403);
        }

        $settlement = new SettlementService();

        return $this->respond([
            'due' => $settlement->technicianDue((int)$technician->id),
            'history' => $settlement->payoutHistoryForTechnician((int)$technician->id),
        ]);
    }

    /**
     * POST /api/wallet/me/withdraw
     *
     * Sweeps every unclaimed charge to date into one new draft payout.
     * Draft, not paid: the transfer itself is still a person at the desk
     * moving real money, which this cannot do on its own.
     */
    public function withdraw(): Response
    {
        $technician = $this->ownTechnician();
        if ($technician === null) {
            return $this->fail('not_a_technician', 'Only a technician has a wallet.', 403);
        }

        $result = (new SettlementService())->generatePayout(
            (int)$technician->id,
            // Far enough back to catch everything this technician has
            // ever earned and not yet claimed — a withdrawal is not a
            // billing cycle, so it is not scoped to one.
            '2000-01-01',
            DateTime::now()->format('Y-m-d'),
            $this->currentUserId(),
        );

        if ($result['ok'] === false) {
            return $this->fail('nothing_to_withdraw', 'There is nothing to withdraw yet.', 422, $result['errors']);
        }

        return $this->respond([
            'payout_id' => $result['payout_id'],
            'payout_no' => $result['payout_no'],
            'totals' => $result['totals'],
        ], [], 201);
    }

    /**
     * The technician record for whoever is signed in, or null when the
     * caller — desk staff, most likely — has none.
     */
    private function ownTechnician(): ?Technician
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return null;
        }

        return $this->fetchTable('Technicians')->find()
            ->where(['user_id' => $userId])
            ->first();
    }
}
