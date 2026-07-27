<?php
declare(strict_types=1);

use App\Database\Migration\AppMigration;

/**
 * Field technicians and how we pay them.
 *
 * What we earn from the vendor and what we pay the technician are two
 * independent numbers. Keeping them on separate, explicitly modelled
 * ledgers is what turns "gross margin per job, per technician, per
 * vendor" into a query instead of a spreadsheet.
 *
 * `technician_rates` is a superset that covers the three arrangements
 * that actually occur in this trade:
 *
 *   flat_per_job    a fixed amount per closed job
 *   pct_of_vendor   a percentage of what the vendor pays us
 *   salaried        a monthly wage, with per-job incentive on top
 *
 * The two share/recovery percentages matter more than they look. If the
 * technician keeps none of the SLA bonus they have no reason to close
 * fast; if we recover none of an SLA penalty we absorb the cost of their
 * delay. Both are policy dials, so both are data.
 */
class CreateTechnicians extends AppMigration
{
    public function up(): void
    {
        $techs = $this->newTable('technicians');
        $techs
            // A technician is a person who logs in; the profile hangs off
            // the user rather than the other way round, which keeps the
            // foreign keys acyclic.
            ->addColumn('user_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('service_center_id', 'biginteger', self::KEY)
            ->addColumn('code', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('phone', 'string', ['limit' => 24, 'null' => false])
            ->addColumn('alt_phone', 'string', ['limit' => 24, 'null' => true])
            ->addColumn('email', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('employment_type', 'string', [
                'limit' => 24,
                'null' => false,
                'default' => 'contractor',
                'comment' => 'contractor|employee',
            ])
            ->addColumn('joined_on', 'date', ['null' => true])
            ->addColumn('exited_on', 'date', ['null' => true])
            ->addColumn('address_line1', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('city', 'string', ['limit' => 96, 'null' => true])
            ->addColumn('district', 'string', ['limit' => 96, 'null' => true])
            ->addColumn('pincode', 'string', ['limit' => 12, 'null' => true])
            // Skills gate which job types the desk may assign to them.
            // Panel replacement is not something every technician can do.
            ->addColumn('skills', 'json', ['null' => true, 'comment' => 'array of job_type codes'])
            ->addColumn('id_proof_type', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('id_proof_number', 'string', ['limit' => 64, 'null' => true])
            // Payout destination.
            ->addColumn('bank_account_name', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('bank_account_number', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('bank_ifsc', 'string', ['limit' => 16, 'null' => true])
            ->addColumn('upi_id', 'string', ['limit' => 96, 'null' => true])
            // Simple capacity signal for the dispatch board.
            ->addColumn('max_open_tickets', 'integer', ['null' => false, 'default' => 10, 'signed' => false])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('notes', 'text', ['null' => true]);
        $this->timestamps($techs)
            ->addIndex(['code'], ['unique' => true, 'name' => 'uq_technicians_code'])
            ->addIndex(['user_id'], ['unique' => true, 'name' => 'uq_technicians_user'])
            ->addIndex(['service_center_id', 'is_active'], ['name' => 'idx_technicians_center'])
            ->addIndex(['phone'], ['name' => 'idx_technicians_phone'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_technicians_user',
            ])
            ->addForeignKey('service_center_id', 'service_centers', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_technicians_center',
            ])
            ->create();

        $rates = $this->newTable('technician_rates');
        $rates
            ->addColumn('technician_id', 'biginteger', self::KEY)
            ->addColumn('effective_from', 'date', ['null' => false])
            ->addColumn('effective_to', 'date', ['null' => true, 'comment' => 'null = current'])
            ->addColumn('model', 'string', [
                'limit' => 24,
                'null' => false,
                'default' => 'flat_per_job',
                'comment' => 'flat_per_job|pct_of_vendor|salaried',
            ]);

        $this->money($rates, 'flat_amount_paise', ['null' => true, 'default' => null]);
        $this->percent($rates, 'pct_of_vendor');
        $this->money($rates, 'monthly_salary_paise', ['null' => true, 'default' => null]);

        // What WE pay the technician for distance. Deliberately separate
        // from the vendor's Rs.3/km reimbursement — the spread between the
        // two rates is ours, and conflating them hides it.
        $rates->addColumn('travel_free_km', 'decimal', [
            'precision' => 8,
            'scale' => 2,
            'null' => false,
            'default' => '0.00',
        ]);
        $this->money($rates, 'travel_rate_per_km_paise', ['default' => 0]);

        // Share of an SLA bonus passed through to the technician who earned
        // it, and the share of an SLA penalty recovered from the technician
        // who caused it.
        $this->percent($rates, 'bonus_share_pct', ['default' => '100.00', 'null' => false]);
        $this->percent($rates, 'penalty_recovery_pct', ['default' => '0.00', 'null' => false]);

        $rates
            // null = every job type.
            ->addColumn('applies_to_job_types', 'json', ['null' => true])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('notes', 'text', ['null' => true]);
        $this->timestamps($rates)
            ->addIndex(
                ['technician_id', 'is_active', 'effective_from'],
                ['name' => 'idx_technician_rates_resolve'],
            )
            ->addForeignKey('technician_id', 'technicians', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_technician_rates_technician',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('technician_rates')->drop()->save();
        $this->table('technicians')->drop()->save();
    }
}
