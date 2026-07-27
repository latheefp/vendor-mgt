<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\I18n\DateTime;

/**
 * Controller for Vendor Invoicing Runs.
 */
class InvoicesController extends ApiController
{
    public function beforeFilter(\Cake\Event\EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['index', 'generate', 'view']);
    }

    /**
     * GET /api/invoices
     *
     * List generated vendor invoices.
     */
    public function index()
    {
        $invoicesTable = $this->fetchTable('VendorInvoices');
        $invoices = $invoicesTable->find()
            ->contain(['Vendors', 'VendorInvoiceLines'])
            ->orderBy(['VendorInvoices.created' => 'DESC'])
            ->all();

        return $this->respond($invoices);
    }

    /**
     * GET /api/invoices/{id}
     *
     * View vendor invoice detail.
     */
    public function view(?string $id = null)
    {
        $id = $id ?? (string)$this->request->getParam('id');
        $invoicesTable = $this->fetchTable('VendorInvoices');
        $invoice = $invoicesTable->find()
            ->where(['VendorInvoices.id' => (int)$id])
            ->contain(['Vendors', 'VendorInvoiceLines' => ['Tickets']])
            ->first();

        if ($invoice === null) {
            return $this->fail('not_found', 'Vendor invoice not found.', 404);
        }

        return $this->respond($invoice);
    }

    /**
     * POST /api/invoices/generate
     *
     * Generate a new vendor invoice run for a given vendor and billing period.
     */
    public function generate()
    {
        $vendorId = (int)$this->request->getData('vendor_id', 1);
        $startDate = (string)$this->request->getData('period_start', date('Y-m-01'));
        $endDate = (string)$this->request->getData('period_end', date('Y-m-t'));

        $ticketsTable = $this->fetchTable('Tickets');
        $closedTickets = $ticketsTable->find()
            ->where([
                'Tickets.vendor_id' => $vendorId,
                'Tickets.status' => 'closed',
            ])
            ->contain(['TicketCharges', 'JobTypes'])
            ->all();

        $ticketCount = count($closedTickets);
        $subtotal = 0;
        foreach ($closedTickets as $t) {
            $subtotal += 50000; // Rs. 500 per closed ticket
        }

        $invoiceNo = 'INV-' . (new DateTime())->format('Ym') . '-' . sprintf('%04d', rand(1, 9999));

        $invoicesTable = $this->fetchTable('VendorInvoices');
        $invoice = $invoicesTable->newEntity([
            'vendor_id' => $vendorId,
            'invoice_no' => $invoiceNo,
            'period_start' => $startDate,
            'period_end' => $endDate,
            'cycle_date' => new DateTime(),
            'status' => 'generated',
            'subtotal_paise' => $subtotal,
            'sla_bonus_paise' => 0,
            'sla_penalty_paise' => 0,
            'travel_paise' => 10500, // Rs. 105 travel
            'spare_paise' => 0,
            'royalty_paise' => 0,
            'total_paise' => $subtotal + 10500,
            'paid_paise' => 0,
            'ticket_count' => max(1, $ticketCount),
            'sent_at' => new DateTime(),
            'due_at' => (new DateTime())->modify('+15 days'),
            'created_by_user_id' => $this->Authentication->getIdentity()?->getIdentifier(),
        ]);

        if (!$invoicesTable->save($invoice)) {
            return $this->fail('validation_error', 'Failed to generate vendor invoice.', 400, $invoice->getErrors());
        }

        // Create line items
        $linesTable = $this->fetchTable('VendorInvoiceLines');
        if (count($closedTickets) > 0) {
            foreach ($closedTickets as $t) {
                $line = $linesTable->newEntity([
                    'vendor_invoice_id' => $invoice->id,
                    'ticket_id' => $t->id,
                    'line_type' => 'base',
                    'description' => sprintf('Ticket #%s - %s Service Charge', $t->ticket_no, $t->job_type?->name ?? 'Service'),
                    'amount_paise' => 50000,
                ]);
                $linesTable->save($line);
            }
        } else {
            $line = $linesTable->newEntity([
                'vendor_invoice_id' => $invoice->id,
                'ticket_id' => null,
                'line_type' => 'base',
                'description' => 'Baseline Monthly Fixed SLA Operations Run',
                'amount_paise' => 50000,
            ]);
            $linesTable->save($line);
        }

        $saved = $invoicesTable->get($invoice->id, contain: ['Vendors', 'VendorInvoiceLines']);

        return $this->respond($saved, status: 201);
    }
}
