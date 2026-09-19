<?php
declare(strict_types=1);

use App\Database\Migration\AppMigration;

/**
 * The service centre's own cash position — an append-only ledger, one row
 * per real movement of money in or out of the business, in the same style
 * as `ticket_charges`: nothing here is ever edited, a correction is a new
 * row, and `balance_after_paise` is a snapshot taken in the same
 * transaction as the row that produced it so the running total is always
 * exactly reproducible from history.
 *
 * This is deliberately a different figure from the P&L. The P&L answers
 * "what did we earn this period" from charges frozen at ticket closure,
 * whether or not the money has actually arrived. This ledger answers
 * "how much cash do we actually have", and only moves when money
 * genuinely changes hands: a company invoice payment lands (credit), a
 * technician payout is actually paid out (debit), or the desk records
 * something neither of those covers (manual adjustment). A ticket closing
 * moves the first number and not this one.
 *
 * Scoped per `service_center_id` because tickets, technicians and their
 * payouts already are — six branches, six positions, not one shared pot.
 */
class CreateServiceCenterSavings extends AppMigration
{
    public function up(): void
    {
        $entries = $this->newTable('service_center_savings_entries');
        $entries
            ->addColumn('service_center_id', 'biginteger', self::KEY)
            ->addColumn('entry_type', 'string', [
                'limit' => 8,
                'null' => false,
                'comment' => 'credit|debit',
            ])
            ->addColumn('source_type', 'string', [
                'limit' => 32,
                'null' => false,
                'comment' => 'invoice_payment|technician_payout|manual_adjustment',
            ])
            // Polymorphic — a company_invoice id, a technician_payout id, or
            // null for a manual entry. No foreign key: the source table
            // differs by row, which a single constraint cannot express.
            ->addColumn('source_id', 'biginteger', self::NULLABLE_KEY);
        // Always a positive magnitude — direction lives in `entry_type`,
        // not in the sign, so a report can filter on one column and never
        // has to remember which way round this ledger's signs go.
        $this->money($entries, 'amount_paise');
        $this->money($entries, 'balance_after_paise');
        $entries
            ->addColumn('description', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('created_by_user_id', 'biginteger', self::NULLABLE_KEY);
        $this->timestamps($entries)
            // The running balance for a centre is "the newest row's
            // balance_after", so this is the index every read of it uses.
            ->addIndex(['service_center_id', 'id'], ['name' => 'idx_savings_center_sequence'])
            ->addIndex(['source_type', 'source_id'], ['name' => 'idx_savings_source'])
            ->addForeignKey('service_center_id', 'service_centers', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_savings_center',
            ])
            ->addForeignKey('created_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_savings_creator',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('service_center_savings_entries')->drop()->save();
    }
}
