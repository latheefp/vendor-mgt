<?php
declare(strict_types=1);

use App\Database\Migration\AppMigration;

/**
 * The two tables the operational flows need and the schema did not have.
 *
 * 1. TICKET NUMBER SEQUENCES.
 *
 *    Numbers were being generated as prefix + rand(1, 9999), guarded only
 *    by a unique index. At a few hundred tickets a month that collides
 *    regularly — the birthday bound puts an even chance of a clash around
 *    120 tickets in a period — and each collision surfaces as an intake
 *    failure on a customer phone call.
 *
 *    A counter row per company per period fixes it and, because the prefix
 *    and width come from that company's settings, gives each company a
 *    recognisable number series. The desk reads our number and the
 *    company's own reference side by side all day; making ours obviously
 *    ours is what stops the two being confused.
 *
 * 2. SPARE STOCK.
 *
 *    `spare_parts` describes a part and `ticket_spares` records consuming
 *    one, but nothing tracked where a part physically was. That gap is not
 *    academic under this agreement: clause 10 treats a spare held beyond
 *    30 days as billed to us, so a part sitting in a technician's bag
 *    silently becomes our cost. Stock is therefore a movement ledger
 *    rather than a quantity column — "we are three short" is a question
 *    about history, and a column that has been decremented forty times
 *    cannot answer it.
 */
class CreateOperations extends AppMigration
{
    public function up(): void
    {
        $this->createTicketSequences();
        $this->createSpareStock();
        $this->extendTicketSpares();
    }

    /**
     * One counter per company per period.
     */
    private function createTicketSequences(): void
    {
        $sequences = $this->newTable('ticket_sequences');
        $sequences
            ->addColumn('vendor_id', 'biginteger', self::KEY)
            ->addColumn('period', 'string', [
                'limit' => 16,
                'null' => false,
                'comment' => 'the reset bucket, e.g. 202607',
            ])
            ->addColumn('last_value', 'integer', [
                'null' => false,
                'default' => 0,
                'signed' => false,
            ]);
        $this->timestamps($sequences)
            // The allocator relies on this: it does an INSERT .. ON
            // DUPLICATE KEY UPDATE, which needs the conflict to be a unique
            // key violation rather than a second row.
            ->addIndex(['vendor_id', 'period'], ['unique' => true, 'name' => 'uq_ticket_sequences_period'])
            ->addForeignKey('vendor_id', 'vendors', 'id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_sequences_vendor',
            ])
            ->create();
    }

    /**
     * Where every part is, and how it got there.
     */
    private function createSpareStock(): void
    {
        $movements = $this->newTable('spare_stock_movements');
        $movements
            ->addColumn('spare_part_id', 'biginteger', self::KEY)
            ->addColumn('service_center_id', 'biginteger', self::KEY)
            // Set when the part is out with a technician rather than on a
            // shelf. This is the column clause 10 ageing runs on.
            ->addColumn('technician_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('ticket_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('movement_type', 'string', [
                'limit' => 24,
                'null' => false,
                'comment' => 'received|issued|consumed|returned_good|returned_defective|'
                    . 'sent_to_vendor|written_off|adjustment',
            ])
            // Signed. A receipt is +n and a consumption is -n on the same
            // column, so the balance at any location is a SUM and never a
            // subtraction that has to know which way each type points.
            ->addColumn('quantity', 'integer', [
                'null' => false,
                'signed' => true,
                'comment' => 'signed: positive adds to this location',
            ])
            ->addColumn('serial_no', 'string', ['limit' => 96, 'null' => true]);

        $this->money($movements, 'unit_cost_paise', ['null' => true, 'default' => null]);

        $movements
            ->addColumn('reference', 'string', [
                'limit' => 96,
                'null' => true,
                'comment' => 'vendor challan or courier docket',
            ])
            ->addColumn('occurred_at', 'datetime', ['null' => false])
            ->addColumn('actor_user_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => false]);

        $movements
            ->addIndex(['spare_part_id', 'service_center_id'], ['name' => 'idx_spare_movements_balance'])
            ->addIndex(['technician_id'], ['name' => 'idx_spare_movements_technician'])
            ->addIndex(['ticket_id'], ['name' => 'idx_spare_movements_ticket'])
            ->addIndex(['movement_type', 'occurred_at'], ['name' => 'idx_spare_movements_type'])
            ->addForeignKey('spare_part_id', 'spare_parts', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_spare_movements_part',
            ])
            ->addForeignKey('service_center_id', 'service_centers', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_spare_movements_center',
            ])
            ->addForeignKey('technician_id', 'technicians', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_spare_movements_technician',
            ])
            ->addForeignKey('ticket_id', 'tickets', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_spare_movements_ticket',
            ])
            ->addForeignKey('actor_user_id', 'users', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_spare_movements_actor',
            ])
            ->create();
    }

    /**
     * Close the loop between a consumed part and the defective one coming
     * back.
     */
    private function extendTicketSpares(): void
    {
        $this->table('ticket_spares')
            // Which service centre the part left. Needed to put a defective
            // return back where it came from rather than wherever the
            // technician happens to be.
            ->addColumn('issued_from_center_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('issued_to_technician_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('issued_at', 'datetime', ['null' => true])
            // Clause 9 settles defectives every 7 days; the settlement is
            // per batch, so the return needs its own reference to be
            // reconcilable against the company's credit note.
            ->addColumn('defective_return_reference', 'string', ['limit' => 96, 'null' => true])
            ->addColumn('defective_credit_received_at', 'datetime', ['null' => true])
            ->addIndex(['issued_to_technician_id'], ['name' => 'idx_ticket_spares_technician'])
            ->addForeignKey('issued_from_center_id', 'service_centers', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_spares_center',
            ])
            ->addForeignKey('issued_to_technician_id', 'technicians', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_spares_technician',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('ticket_spares')
            ->dropForeignKey('issued_from_center_id')
            ->dropForeignKey('issued_to_technician_id')
            ->removeIndexByName('idx_ticket_spares_technician')
            ->removeColumn('issued_from_center_id')
            ->removeColumn('issued_to_technician_id')
            ->removeColumn('issued_at')
            ->removeColumn('defective_return_reference')
            ->removeColumn('defective_credit_received_at')
            ->update();

        $this->table('spare_stock_movements')->drop()->save();
        $this->table('ticket_sequences')->drop()->save();
    }
}
