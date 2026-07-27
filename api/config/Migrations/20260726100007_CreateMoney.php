<?php
declare(strict_types=1);

use App\Database\Migration\AppMigration;

/**
 * Spares, the frozen charge ledger, cash collection, vendor invoices and
 * technician payouts.
 *
 * `ticket_charges` is the single most important table in the schema. It
 * is the frozen output of the money engine, one row per computed line,
 * each carrying a hard reference to the rate card item or SLA rule that
 * produced it plus a JSON snapshot of the inputs. Nothing downstream ever
 * recomputes a rate: invoices and payouts are assembled from these rows.
 *
 * Corrections are new `adjustment` rows, never edits. A frozen line stays
 * exactly as it was priced, because in three months' time the argument
 * with the vendor will be about what the number was, not what it would be
 * today.
 */
class CreateMoney extends AppMigration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // spare parts
        // ---------------------------------------------------------------
        $parts = $this->newTable('spare_parts');
        $parts
            ->addColumn('vendor_id', 'biginteger', self::KEY)
            ->addColumn('product_category_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('part_no', 'string', ['limit' => 96, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 190, 'null' => false])
            ->addColumn('description', 'text', ['null' => true]);
        $this->money($parts, 'cost_paise');
        $this->money($parts, 'mrp_paise', ['null' => true, 'default' => null]);
        $parts
            ->addColumn('is_serialized', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('reorder_level', 'integer', ['null' => false, 'default' => 0, 'signed' => false])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true]);
        $this->timestamps($parts)
            ->addIndex(['vendor_id', 'part_no'], ['unique' => true, 'name' => 'uq_spare_parts_vendor_part'])
            ->addForeignKey('vendor_id', 'vendors', 'id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_spare_parts_vendor',
            ])
            ->addForeignKey('product_category_id', 'product_categories', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_spare_parts_category',
            ])
            ->create();

        // ---------------------------------------------------------------
        // spares consumed on a ticket
        // ---------------------------------------------------------------
        $used = $this->newTable('ticket_spares');
        $used
            ->addColumn('ticket_id', 'biginteger', self::KEY)
            ->addColumn('spare_part_id', 'biginteger', self::KEY)
            ->addColumn('quantity', 'integer', ['null' => false, 'default' => 1, 'signed' => false])
            ->addColumn('serial_no', 'string', ['limit' => 96, 'null' => true]);

        $this->money($used, 'unit_cost_paise');
        // Clause 6: out-of-warranty spares are billed to the customer at
        // cost + 10% to 15%. The exact figure the desk applied is recorded
        // per line, because it is a judgement inside a negotiated band.
        $this->percent($used, 'margin_pct', ['default' => '0.00', 'null' => false]);
        $this->money($used, 'unit_price_paise');
        $this->money($used, 'line_total_paise');

        $used
            ->addColumn('charged_to', 'string', [
                'limit' => 16,
                'null' => false,
                'default' => 'vendor',
                'comment' => 'vendor|customer',
            ])
            // Clause 9: defective spare settlement every 7 days.
            ->addColumn('is_defective_return', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('defective_return_due_at', 'datetime', ['null' => true])
            ->addColumn('defective_returned_at', 'datetime', ['null' => true])
            // Clause 10: spares held beyond 30 days are treated as billed to
            // us, so the clock starts the day the vendor ships the part.
            ->addColumn('received_at', 'datetime', ['null' => true])
            ->addColumn('billing_due_at', 'datetime', ['null' => true])
            ->addColumn('notes', 'text', ['null' => true]);
        $this->timestamps($used)
            ->addIndex(['ticket_id'], ['name' => 'idx_ticket_spares_ticket'])
            ->addIndex(['spare_part_id'], ['name' => 'idx_ticket_spares_part'])
            // Drives the defective-return ageing report.
            ->addIndex(['defective_returned_at', 'defective_return_due_at'], ['name' => 'idx_ticket_spares_returns'])
            ->addIndex(['billing_due_at'], ['name' => 'idx_ticket_spares_billing'])
            ->addForeignKey('ticket_id', 'tickets', 'id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_spares_ticket',
            ])
            ->addForeignKey('spare_part_id', 'spare_parts', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_spares_part',
            ])
            ->create();

        // ---------------------------------------------------------------
        // ticket_charges — the frozen ledger
        // ---------------------------------------------------------------
        $charges = $this->newTable('ticket_charges');
        $charges
            ->addColumn('ticket_id', 'biginteger', self::KEY)
            ->addColumn('line_type', 'string', [
                'limit' => 40,
                'null' => false,
                'comment' => 'base|sla_bonus|sla_penalty|travel|spare_cost|spare_margin|'
                    . 'vendor_royalty|technician_payout|technician_bonus_share|'
                    . 'technician_penalty_recovery|adjustment',
            ])
            // Which book the line belongs to. Keeping the vendor receivable,
            // the cash collected from the customer, the royalty we owe back
            // and the technician's pay on separate ledgers is what makes
            // margin a query rather than an afternoon with a spreadsheet.
            ->addColumn('ledger', 'string', [
                'limit' => 32,
                'null' => false,
                'comment' => 'vendor_receivable|customer_collection|vendor_payable|technician_payable',
            ])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('quantity', 'decimal', [
                'precision' => 10,
                'scale' => 2,
                'null' => true,
                'comment' => 'km for travel, units for spares',
            ]);
        $this->money($charges, 'unit_amount_paise', ['null' => true, 'default' => null]);
        // Signed: penalties and recoveries are negative on their ledger.
        $this->money($charges, 'amount_paise');

        $charges
            // Provenance. Every line must be traceable to the exact card
            // item or rule that produced it — this is what we put in front
            // of a vendor who disputes an amount.
            ->addColumn('rate_card_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('rate_card_item_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('sla_rule_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('technician_rate_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('ticket_spare_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('vendor_agreement_id', 'biginteger', self::NULLABLE_KEY)
            // Every input the calculation consumed: elapsed hours, pause
            // minutes, size, scope, thresholds, percentages. Verbose by
            // design — it is the difference between explaining a number and
            // guessing at it.
            ->addColumn('calc_snapshot', 'json', ['null' => true])
            ->addColumn('computed_at', 'datetime', ['null' => false])
            ->addColumn('computed_by_user_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('is_frozen', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('settlement_status', 'string', [
                'limit' => 24,
                'null' => false,
                'default' => 'open',
                'comment' => 'open|invoiced|paid|written_off|disputed',
            ])
            ->addColumn('notes', 'text', ['null' => true]);
        $this->timestamps($charges)
            ->addIndex(['ticket_id', 'ledger'], ['name' => 'idx_ticket_charges_ticket'])
            // Invoice and payout assembly both start here.
            ->addIndex(['ledger', 'settlement_status'], ['name' => 'idx_ticket_charges_settlement'])
            ->addIndex(['line_type'], ['name' => 'idx_ticket_charges_type'])
            ->addForeignKey('ticket_id', 'tickets', 'id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_charges_ticket',
            ])
            ->addForeignKey('rate_card_id', 'rate_cards', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_charges_card',
            ])
            ->addForeignKey('rate_card_item_id', 'rate_card_items', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_charges_item',
            ])
            ->addForeignKey('sla_rule_id', 'sla_rules', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_charges_sla',
            ])
            ->addForeignKey('technician_rate_id', 'technician_rates', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_charges_tech_rate',
            ])
            ->addForeignKey('ticket_spare_id', 'ticket_spares', 'id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_charges_spare',
            ])
            ->addForeignKey('vendor_agreement_id', 'vendor_agreements', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_charges_agreement',
            ])
            ->addForeignKey('computed_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_charges_computer',
            ])
            ->create();

        // ---------------------------------------------------------------
        // cash collected from the customer for out-of-warranty work
        // ---------------------------------------------------------------
        $cash = $this->newTable('cash_collections');
        $cash
            ->addColumn('ticket_id', 'biginteger', self::KEY)
            ->addColumn('technician_id', 'biginteger', self::KEY)
            ->addColumn('collected_by_user_id', 'biginteger', self::NULLABLE_KEY);
        $this->money($cash, 'amount_paise');
        $cash
            ->addColumn('method', 'string', [
                'limit' => 16,
                'null' => false,
                'default' => 'cash',
                'comment' => 'cash|upi|card',
            ])
            ->addColumn('reference', 'string', ['limit' => 96, 'null' => true])
            ->addColumn('receipt_no', 'string', ['limit' => 48, 'null' => true])
            ->addColumn('collected_at', 'datetime', ['null' => false])
            // A technician holding company cash is an exposure until it is
            // banked and reconciled, so the deposit leg is modelled too.
            ->addColumn('deposited_at', 'datetime', ['null' => true])
            ->addColumn('deposit_reference', 'string', ['limit' => 96, 'null' => true]);
        $this->money($cash, 'deposited_paise', ['null' => true, 'default' => null]);
        // deposited minus collected: negative means a shortfall to recover.
        $this->money($cash, 'variance_paise', ['default' => 0]);
        $cash
            ->addColumn('verified_at', 'datetime', ['null' => true])
            ->addColumn('verified_by_user_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('status', 'string', [
                'limit' => 24,
                'null' => false,
                'default' => 'collected',
                'comment' => 'collected|deposited|verified|short|disputed',
            ])
            ->addColumn('notes', 'text', ['null' => true]);
        $this->timestamps($cash)
            ->addIndex(['ticket_id'], ['name' => 'idx_cash_collections_ticket'])
            // "What is each technician holding right now?"
            ->addIndex(['technician_id', 'status'], ['name' => 'idx_cash_collections_technician'])
            ->addIndex(['status', 'collected_at'], ['name' => 'idx_cash_collections_status'])
            ->addForeignKey('ticket_id', 'tickets', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_cash_collections_ticket',
            ])
            ->addForeignKey('technician_id', 'technicians', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_cash_collections_technician',
            ])
            ->addForeignKey('collected_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_cash_collections_collector',
            ])
            ->addForeignKey('verified_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_cash_collections_verifier',
            ])
            ->create();

        // ---------------------------------------------------------------
        // vendor invoices — clause 5, settled on the 10th
        // ---------------------------------------------------------------
        $invoices = $this->newTable('vendor_invoices');
        $invoices
            ->addColumn('vendor_id', 'biginteger', self::KEY)
            ->addColumn('vendor_agreement_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('invoice_no', 'string', ['limit' => 48, 'null' => false])
            ->addColumn('period_start', 'date', ['null' => false])
            ->addColumn('period_end', 'date', ['null' => false])
            ->addColumn('cycle_date', 'date', ['null' => true, 'comment' => 'the vendor settlement date'])
            ->addColumn('status', 'string', [
                'limit' => 24,
                'null' => false,
                'default' => 'draft',
                'comment' => 'draft|sent|partially_paid|paid|disputed|cancelled',
            ]);
        $this->money($invoices, 'subtotal_paise');
        $this->money($invoices, 'sla_bonus_paise');
        $this->money($invoices, 'sla_penalty_paise');
        $this->money($invoices, 'travel_paise');
        $this->money($invoices, 'spare_paise');
        // Clause 8: 10% of out-of-warranty service charges collected flows
        // back to the vendor, so it reduces what they owe us.
        $this->money($invoices, 'royalty_paise');
        $this->money($invoices, 'total_paise');
        $this->money($invoices, 'paid_paise');
        $invoices
            ->addColumn('ticket_count', 'integer', ['null' => false, 'default' => 0, 'signed' => false])
            ->addColumn('sent_at', 'datetime', ['null' => true])
            ->addColumn('sent_to_email', 'string', ['limit' => 190, 'null' => true])
            // Clause 11: the Message-ID is our proof the invoice was served.
            ->addColumn('message_id', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('due_at', 'date', ['null' => true])
            ->addColumn('paid_at', 'datetime', ['null' => true])
            ->addColumn('payment_reference', 'string', ['limit' => 96, 'null' => true])
            ->addColumn('disputed_at', 'datetime', ['null' => true])
            ->addColumn('dispute_notes', 'text', ['null' => true])
            ->addColumn('pdf_path', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('created_by_user_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('notes', 'text', ['null' => true]);
        $this->timestamps($invoices)
            ->addIndex(['invoice_no'], ['unique' => true, 'name' => 'uq_vendor_invoices_no'])
            ->addIndex(['vendor_id', 'period_start', 'period_end'], ['name' => 'idx_vendor_invoices_period'])
            ->addIndex(['status', 'due_at'], ['name' => 'idx_vendor_invoices_status'])
            ->addForeignKey('vendor_id', 'vendors', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_vendor_invoices_vendor',
            ])
            ->addForeignKey('vendor_agreement_id', 'vendor_agreements', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_vendor_invoices_agreement',
            ])
            ->addForeignKey('created_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_vendor_invoices_creator',
            ])
            ->create();

        $invoiceLines = $this->newTable('vendor_invoice_lines');
        $invoiceLines
            ->addColumn('vendor_invoice_id', 'biginteger', self::KEY)
            // The charge this line bills. One-to-one in practice, which is
            // what makes an invoice fully reconcilable back to the ledger.
            ->addColumn('ticket_charge_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('ticket_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('description', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('quantity', 'decimal', ['precision' => 10, 'scale' => 2, 'null' => true]);
        $this->money($invoiceLines, 'unit_amount_paise', ['null' => true, 'default' => null]);
        $this->money($invoiceLines, 'amount_paise');
        $invoiceLines->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0, 'signed' => false]);
        $this->timestamps($invoiceLines)
            ->addIndex(['vendor_invoice_id'], ['name' => 'idx_vendor_invoice_lines_invoice'])
            ->addIndex(['ticket_charge_id'], ['name' => 'idx_vendor_invoice_lines_charge'])
            ->addIndex(['ticket_id'], ['name' => 'idx_vendor_invoice_lines_ticket'])
            ->addForeignKey('vendor_invoice_id', 'vendor_invoices', 'id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_vendor_invoice_lines_invoice',
            ])
            ->addForeignKey('ticket_charge_id', 'ticket_charges', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_vendor_invoice_lines_charge',
            ])
            ->addForeignKey('ticket_id', 'tickets', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_vendor_invoice_lines_ticket',
            ])
            ->create();

        // ---------------------------------------------------------------
        // technician payouts
        // ---------------------------------------------------------------
        $payouts = $this->newTable('technician_payouts');
        $payouts
            ->addColumn('technician_id', 'biginteger', self::KEY)
            ->addColumn('payout_no', 'string', ['limit' => 48, 'null' => false])
            ->addColumn('period_start', 'date', ['null' => false])
            ->addColumn('period_end', 'date', ['null' => false])
            ->addColumn('status', 'string', [
                'limit' => 24,
                'null' => false,
                'default' => 'draft',
                'comment' => 'draft|approved|paid|cancelled',
            ]);
        $this->money($payouts, 'gross_paise');
        $this->money($payouts, 'bonus_paise');
        $this->money($payouts, 'travel_paise');
        // Recovered SLA penalties and cash shortfalls.
        $this->money($payouts, 'penalty_recovery_paise');
        $this->money($payouts, 'advance_recovery_paise');
        $this->money($payouts, 'deductions_paise');
        $this->money($payouts, 'net_paise');
        $payouts
            ->addColumn('ticket_count', 'integer', ['null' => false, 'default' => 0, 'signed' => false])
            ->addColumn('approved_at', 'datetime', ['null' => true])
            ->addColumn('approved_by_user_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('paid_at', 'datetime', ['null' => true])
            ->addColumn('payment_method', 'string', ['limit' => 24, 'null' => true])
            ->addColumn('payment_reference', 'string', ['limit' => 96, 'null' => true])
            ->addColumn('pdf_path', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('created_by_user_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('notes', 'text', ['null' => true]);
        $this->timestamps($payouts)
            ->addIndex(['payout_no'], ['unique' => true, 'name' => 'uq_technician_payouts_no'])
            ->addIndex(['technician_id', 'period_start', 'period_end'], ['name' => 'idx_technician_payouts_period'])
            ->addIndex(['status'], ['name' => 'idx_technician_payouts_status'])
            ->addForeignKey('technician_id', 'technicians', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_technician_payouts_technician',
            ])
            ->addForeignKey('approved_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_technician_payouts_approver',
            ])
            ->addForeignKey('created_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_technician_payouts_creator',
            ])
            ->create();

        $payoutLines = $this->newTable('technician_payout_lines');
        $payoutLines
            ->addColumn('technician_payout_id', 'biginteger', self::KEY)
            ->addColumn('ticket_charge_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('ticket_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('description', 'string', ['limit' => 255, 'null' => false]);
        $this->money($payoutLines, 'amount_paise');
        $payoutLines->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0, 'signed' => false]);
        $this->timestamps($payoutLines)
            ->addIndex(['technician_payout_id'], ['name' => 'idx_technician_payout_lines_payout'])
            ->addIndex(['ticket_charge_id'], ['name' => 'idx_technician_payout_lines_charge'])
            ->addIndex(['ticket_id'], ['name' => 'idx_technician_payout_lines_ticket'])
            ->addForeignKey('technician_payout_id', 'technician_payouts', 'id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_technician_payout_lines_payout',
            ])
            ->addForeignKey('ticket_charge_id', 'ticket_charges', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_technician_payout_lines_charge',
            ])
            ->addForeignKey('ticket_id', 'tickets', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_technician_payout_lines_ticket',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('technician_payout_lines')->drop()->save();
        $this->table('technician_payouts')->drop()->save();
        $this->table('vendor_invoice_lines')->drop()->save();
        $this->table('vendor_invoices')->drop()->save();
        $this->table('cash_collections')->drop()->save();
        $this->table('ticket_charges')->drop()->save();
        $this->table('ticket_spares')->drop()->save();
        $this->table('spare_parts')->drop()->save();
    }
}
