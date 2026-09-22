<?php
declare(strict_types=1);

use App\Database\Migration\AppMigration;

/**
 * A technician cost that is not on any rate card.
 *
 * The rate card prices the job itself; it says nothing about the Rs.150
 * bata a technician is owed for a second visit, or the one-off lump sum
 * the desk agrees to pay for a job that went badly. Those are real costs,
 * tied to a real ticket, and they need to reach the technician's pending
 * pay and reduce our margin exactly like every other technician_payable
 * line — they just are not computed by RateResolver.
 *
 * `technician_expense_types` is the editable catalogue of what those
 * costs are called ("Bata / Transport", "Service charge", "Lump sum
 * payment") so the desk can add a new one from settings without a
 * deploy, the same NULL-means-shared-baseline convention as job_types,
 * resolutions and hold_reasons. The amount itself is never on this row —
 * it is typed by hand per ticket, because a lump sum is a judgement, not
 * a price.
 */
class CreateTechnicianExpenseTypes extends AppMigration
{
    public function up(): void
    {
        $types = $this->newTable('technician_expense_types');
        $types
            ->addColumn('company_id', 'biginteger', self::NULLABLE_KEY + [
                'comment' => 'null = shared baseline offered to every company',
            ])
            ->addColumn('code', 'string', ['limit' => 48, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('override_note', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => 'why this company deviates from the shared list',
            ])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0, 'signed' => false])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true]);
        $this->timestamps($types)
            ->addIndex(['company_id'], ['name' => 'idx_technician_expense_types_company'])
            ->addForeignKey('company_id', 'companies', 'id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_technician_expense_types_company',
            ])
            ->create();

        // Same COALESCE trick as the other master lists: a plain unique
        // index on (company_id, code) would let every company's shared row
        // collide as NULL only once, since MySQL treats NULLs as distinct.
        $this->execute(
            'ALTER TABLE technician_expense_types
                ADD COLUMN company_key BIGINT UNSIGNED
                    AS (CAST(COALESCE(company_id, 0) AS UNSIGNED)) VIRTUAL
                    COMMENT "0 = shared; enforces one code per owner"',
        );
        $this->execute(
            'CREATE UNIQUE INDEX uq_technician_expense_types_owner_code
                ON technician_expense_types (company_key, code)',
        );

        $this->table('technician_expense_types')->insert([
            [
                'company_id' => null,
                'code' => 'lump_sum',
                'name' => 'Lump sum payment',
                'description' => 'A one-off discretionary payment agreed for this job, paid out of margin.',
                'sort_order' => 10,
                'is_active' => true,
                'created' => date('Y-m-d H:i:s'),
                'modified' => date('Y-m-d H:i:s'),
            ],
            [
                'company_id' => null,
                'code' => 'service_charge',
                'name' => 'Service charge',
                'description' => 'An additional service charge to the technician outside the rate card.',
                'sort_order' => 20,
                'is_active' => true,
                'created' => date('Y-m-d H:i:s'),
                'modified' => date('Y-m-d H:i:s'),
            ],
            [
                'company_id' => null,
                'code' => 'bata_transport',
                'name' => 'Bata / Transport',
                'description' => 'Travel or petrol cost reimbursed to the technician for this job.',
                'sort_order' => 30,
                'is_active' => true,
                'created' => date('Y-m-d H:i:s'),
                'modified' => date('Y-m-d H:i:s'),
            ],
        ])->save();

        // ---------------------------------------------------------------
        // ticket_charges gets a provenance column for this new line type,
        // the same way rate_card_item_id names the item behind a base line.
        // ---------------------------------------------------------------
        $this->table('ticket_charges')
            ->addColumn('technician_expense_type_id', 'biginteger', self::NULLABLE_KEY)
            ->addIndex(['technician_expense_type_id'], ['name' => 'idx_ticket_charges_expense_type'])
            ->addForeignKey('technician_expense_type_id', 'technician_expense_types', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_charges_expense_type',
            ])
            ->update();

        $this->table('ticket_charges')
            ->changeColumn('line_type', 'string', [
                'limit' => 40,
                'null' => false,
                'comment' => 'base|sla_bonus|sla_penalty|travel|spare_cost|spare_margin|'
                    . 'company_royalty|technician_payout|technician_bonus_share|'
                    . 'technician_penalty_recovery|boq|adjustment|technician_expense',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('ticket_charges')
            ->dropForeignKey('technician_expense_type_id')
            ->removeIndexByName('idx_ticket_charges_expense_type')
            ->removeColumn('technician_expense_type_id')
            ->update();

        $this->table('technician_expense_types')->drop()->save();
    }
}
