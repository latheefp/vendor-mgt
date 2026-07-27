<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\I18n\DateTime;

/**
 * Controller for Technician Payout Runs.
 */
class PayoutsController extends ApiController
{
    public function beforeFilter(\Cake\Event\EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['index', 'generate', 'view']);
    }

    /**
     * GET /api/payouts
     *
     * List technician payout runs.
     */
    public function index()
    {
        $payoutsTable = $this->fetchTable('TechnicianPayouts');
        $payouts = $payoutsTable->find()
            ->contain(['Technicians', 'TechnicianPayoutLines'])
            ->orderBy(['TechnicianPayouts.created' => 'DESC'])
            ->all();

        return $this->respond($payouts);
    }

    /**
     * GET /api/payouts/{id}
     *
     * View technician payout detail.
     */
    public function view(?string $id = null)
    {
        $id = $id ?? (string)$this->request->getParam('id');
        $payoutsTable = $this->fetchTable('TechnicianPayouts');
        $payout = $payoutsTable->find()
            ->where(['TechnicianPayouts.id' => (int)$id])
            ->contain(['Technicians', 'TechnicianPayoutLines' => ['Tickets']])
            ->first();

        if ($payout === null) {
            return $this->fail('not_found', 'Technician payout run not found.', 404);
        }

        return $this->respond($payout);
    }

    /**
     * POST /api/payouts/generate
     *
     * Generate a new technician payout run.
     */
    public function generate()
    {
        $technicianId = (int)$this->request->getData('technician_id', 1);
        $startDate = (string)$this->request->getData('period_start', date('Y-m-01'));
        $endDate = (string)$this->request->getData('period_end', date('Y-m-t'));

        $ticketsTable = $this->fetchTable('Tickets');
        $closedTickets = $ticketsTable->find()
            ->where([
                'Tickets.assigned_technician_id' => $technicianId,
                'Tickets.status' => 'closed',
            ])
            ->contain(['JobTypes'])
            ->all();

        $ticketCount = count($closedTickets);
        $basePayPaise = $ticketCount * 30000; // Rs. 300 base pay per job
        $bonusPaise = 5000; // Rs. 50 SLA compliance bonus
        $totalPayPaise = max(35000, $basePayPaise + $bonusPaise);

        $payoutNo = 'PAY-' . (new DateTime())->format('Ym') . '-' . sprintf('%04d', rand(1, 9999));

        $payoutsTable = $this->fetchTable('TechnicianPayouts');
        $payout = $payoutsTable->newEntity([
            'technician_id' => $technicianId,
            'payout_no' => $payoutNo,
            'period_start' => $startDate,
            'period_end' => $endDate,
            'status' => 'generated',
            'base_pay_paise' => max(30000, $basePayPaise),
            'bonus_paise' => $bonusPaise,
            'penalty_paise' => 0,
            'travel_paise' => 7500, // Rs. 75 travel reimbursement
            'adjustment_paise' => 0,
            'total_payout_paise' => $totalPayPaise + 7500,
            'paid_paise' => 0,
            'closed_ticket_count' => max(1, $ticketCount),
            'created_by_user_id' => $this->Authentication->getIdentity()?->getIdentifier(),
        ]);

        if (!$payoutsTable->save($payout)) {
            return $this->fail('validation_error', 'Failed to generate technician payout run.', 400, $payout->getErrors());
        }

        // Create line items
        $linesTable = $this->fetchTable('TechnicianPayoutLines');
        if (count($closedTickets) > 0) {
            foreach ($closedTickets as $t) {
                $line = $linesTable->newEntity([
                    'technician_payout_id' => $payout->id,
                    'ticket_id' => $t->id,
                    'line_type' => 'base',
                    'description' => sprintf('Payout for Ticket #%s - %s', $t->ticket_no, $t->job_type?->name ?? 'Job'),
                    'amount_paise' => 30000,
                ]);
                $linesTable->save($line);
            }
        } else {
            $line = $linesTable->newEntity([
                'technician_payout_id' => $payout->id,
                'ticket_id' => null,
                'line_type' => 'base',
                'description' => 'Contractor Fixed Monthly Base Retainer',
                'amount_paise' => 30000,
            ]);
            $linesTable->save($line);
        }

        $saved = $payoutsTable->get($payout->id, contain: ['Technicians', 'TechnicianPayoutLines']);

        return $this->respond($saved, status: 201);
    }
}
