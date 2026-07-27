<?php
declare(strict_types=1);

use App\Database\Migration\AppMigration;

/**
 * Vendors (the manufacturers whose warranty work we perform) and the
 * agreements we sign with them.
 *
 * Every commercial term from the paper agreement lives on
 * `vendor_agreements` as data, because vendor #2 will not have the same
 * credit limit, royalty percentage, free-travel radius or SLA windows as
 * vendor #1. Anything hardcoded here becomes a rewrite at onboarding.
 *
 * Agreements are versioned by effective date and never edited in place:
 * an invoice raised in March must still be explainable under March's
 * terms after the terms change in April.
 */
class CreateVendors extends AppMigration
{
    public function up(): void
    {
        $vendors = $this->newTable('vendors');
        $vendors
            ->addColumn('code', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('legal_name', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('gstin', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('contact_person', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('phone', 'string', ['limit' => 24, 'null' => true])
            ->addColumn('support_phone', 'string', ['limit' => 24, 'null' => true])
            // Clause 11: "All communications should be through email only,
            // communication through other media may not be considered valid."
            // This is the address of record for every notice we send.
            ->addColumn('communication_email', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('accounts_email', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('address_line1', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('address_line2', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('city', 'string', ['limit' => 96, 'null' => true])
            ->addColumn('state', 'string', ['limit' => 96, 'null' => true])
            ->addColumn('pincode', 'string', ['limit' => 12, 'null' => true])
            ->addColumn('website', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('logo_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('onboarded_on', 'date', ['null' => true])
            ->addColumn('notes', 'text', ['null' => true]);
        $this->timestamps($vendors)
            ->addIndex(['code'], ['unique' => true, 'name' => 'uq_vendors_code'])
            ->addIndex(['is_active'], ['name' => 'idx_vendors_active'])
            ->create();

        $agreements = $this->newTable('vendor_agreements');
        $agreements
            ->addColumn('vendor_id', 'biginteger', self::KEY)
            ->addColumn('agreement_no', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('title', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('status', 'string', [
                'limit' => 24,
                'null' => false,
                'default' => 'draft',
                'comment' => 'draft|active|terminated|expired',
            ])
            ->addColumn('signed_on', 'date', ['null' => true])
            ->addColumn('effective_from', 'date', ['null' => false])
            ->addColumn('effective_to', 'date', ['null' => true, 'comment' => 'null = open ended']);

        // ---- commercial terms ---------------------------------------
        // Clause 4: "Service centres are not allowed to exceed the credit
        // limit of maximum amount of Rs.25,000". We track live exposure
        // against this and alert before it is breached.
        $this->money($agreements, 'credit_limit_paise', ['default' => 2500000]);

        // Clause 5: "Invoice clearance will be finalized every 10th of the month."
        $agreements->addColumn('invoice_cycle_day', 'integer', [
            'null' => false,
            'default' => 10,
            'signed' => false,
            'comment' => 'day of month the vendor settles invoices',
        ]);

        // Clause 8: 10% royalty on out-of-warranty service charges collected.
        $this->percent($agreements, 'oow_royalty_pct', ['default' => '10.00', 'null' => false]);

        // Clause 6: out-of-warranty spares billed to the customer at
        // "SPARE PART COST + 10% to 15% margin". Stored as a range because
        // that is how it was negotiated; the desk picks within it.
        $this->percent($agreements, 'spare_margin_min_pct', ['default' => '10.00', 'null' => false]);
        $this->percent($agreements, 'spare_margin_max_pct', ['default' => '15.00', 'null' => false]);

        // Important note 5: travel reimbursed at Rs.3/km beyond 15km from
        // the designated service centre.
        $agreements->addColumn('travel_free_km', 'decimal', [
            'precision' => 8,
            'scale' => 2,
            'null' => false,
            'default' => '15.00',
        ]);
        $this->money($agreements, 'travel_rate_per_km_paise', ['default' => 300]);

        // ---- SLA windows --------------------------------------------
        // Clauses 1-3: contact within 2h, visit within 48h, close within 48h.
        $agreements
            ->addColumn('sla_contact_hours', 'integer', ['null' => false, 'default' => 2, 'signed' => false])
            ->addColumn('sla_visit_hours', 'integer', ['null' => false, 'default' => 48, 'signed' => false])
            ->addColumn('sla_close_hours', 'integer', ['null' => false, 'default' => 48, 'signed' => false])
            // Clause 7: we carry repeat complaints from the same customer
            // for 3 months.
            ->addColumn('repeat_complaint_window_days', 'integer', [
                'null' => false,
                'default' => 90,
                'signed' => false,
            ])
            // Clause 9: defective spare settlement every 7 days.
            ->addColumn('defective_return_days', 'integer', ['null' => false, 'default' => 7, 'signed' => false])
            // Clause 10: spares held beyond 30 days are treated as billed to us.
            ->addColumn('spare_billing_days', 'integer', ['null' => false, 'default' => 30, 'signed' => false])
            // Important note 2: 2 months' notice before discontinuing service.
            ->addColumn('notice_period_days', 'integer', ['null' => false, 'default' => 60, 'signed' => false])
            ->addColumn('document_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('notes', 'text', ['null' => true]);

        $this->timestamps($agreements)
            ->addIndex(['vendor_id', 'agreement_no'], [
                'unique' => true,
                'name' => 'uq_vendor_agreements_no',
            ])
            ->addIndex(['vendor_id', 'status', 'effective_from'], ['name' => 'idx_vendor_agreements_lookup'])
            ->addForeignKey('vendor_id', 'vendors', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_vendor_agreements_vendor',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('vendor_agreements')->drop()->save();
        $this->table('vendors')->drop()->save();
    }
}
