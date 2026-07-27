<?php
declare(strict_types=1);

use App\Database\Migration\AppMigration;

/**
 * Lets the desk restate a single line on a draft invoice or payout, and
 * carries enough of the charge's shape onto the settlement line to show
 * the split without re-reading the ledger.
 *
 * `line_type` is copied rather than joined. The bucket totals on the
 * invoice header (subtotal, SLA, travel, spares, royalty) have to be
 * re-derived every time a line is restated, and deriving them from a join
 * back to `ticket_charges` would make the header depend on rows that are
 * deliberately immutable — the moment an adjustment is appended to the
 * ticket, a stored invoice would silently re-total. The copy is the point:
 * an invoice is a statement of what was billed, frozen at the moment it
 * was raised.
 *
 * The frozen charge itself is never touched by any of this. An override
 * lands on the invoice copy only, keeps the original amount alongside it,
 * and demands a reason — so the invoice can always be reconciled back to
 * the ledger as "this line, restated by this person, for this reason".
 */
class AddSettlementLineOverrides extends AppMigration
{
    public function up(): void
    {
        $invoiceLines = $this->table('vendor_invoice_lines');
        $invoiceLines
            ->addColumn('line_type', 'string', [
                'limit' => 32,
                'null' => false,
                'default' => 'base',
                'after' => 'ticket_id',
                'comment' => 'copied from ticket_charges.line_type; drives the header split',
            ])
            ->addColumn('ledger', 'string', [
                'limit' => 32,
                'null' => true,
                'after' => 'line_type',
                'comment' => 'copied from ticket_charges.ledger',
            ]);

        // Null until someone restates the line, which is what distinguishes
        // "as billed" from "as corrected" without a separate flag.
        $this->money($invoiceLines, 'original_amount_paise', [
            'null' => true,
            'default' => null,
            'after' => 'amount_paise',
        ]);

        $invoiceLines
            ->addColumn('override_reason', 'string', [
                'limit' => 255,
                'null' => true,
                'after' => 'original_amount_paise',
            ])
            ->addColumn('overridden_at', 'datetime', [
                'null' => true,
                'after' => 'override_reason',
            ])
            ->addColumn('overridden_by_user_id', 'biginteger', self::NULLABLE_KEY + [
                'after' => 'overridden_at',
            ])
            ->addForeignKey('overridden_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_vendor_invoice_lines_overrider',
            ])
            ->update();

        $payoutLines = $this->table('technician_payout_lines');
        $payoutLines
            ->addColumn('line_type', 'string', [
                'limit' => 32,
                'null' => false,
                'default' => 'technician_payout',
                'after' => 'ticket_id',
                'comment' => 'copied from ticket_charges.line_type; drives the header split',
            ]);

        $this->money($payoutLines, 'original_amount_paise', [
            'null' => true,
            'default' => null,
            'after' => 'amount_paise',
        ]);

        $payoutLines
            ->addColumn('override_reason', 'string', [
                'limit' => 255,
                'null' => true,
                'after' => 'original_amount_paise',
            ])
            ->addColumn('overridden_at', 'datetime', [
                'null' => true,
                'after' => 'override_reason',
            ])
            ->addColumn('overridden_by_user_id', 'biginteger', self::NULLABLE_KEY + [
                'after' => 'overridden_at',
            ])
            ->addForeignKey('overridden_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_technician_payout_lines_overrider',
            ])
            ->update();

        // Lines raised before this migration have the column default rather
        // than their real type, which would put an SLA bonus in the service
        // subtotal the first time the header is re-derived. Backfill from
        // the charge each line was copied from.
        $this->execute(
            'UPDATE vendor_invoice_lines l '
            . 'INNER JOIN ticket_charges c ON c.id = l.ticket_charge_id '
            . 'SET l.line_type = c.line_type, l.ledger = c.ledger',
        );

        $this->execute(
            'UPDATE technician_payout_lines l '
            . 'INNER JOIN ticket_charges c ON c.id = l.ticket_charge_id '
            . 'SET l.line_type = c.line_type',
        );
    }

    public function down(): void
    {
        $this->table('vendor_invoice_lines')
            ->dropForeignKey('overridden_by_user_id')
            ->removeColumn('line_type')
            ->removeColumn('ledger')
            ->removeColumn('original_amount_paise')
            ->removeColumn('override_reason')
            ->removeColumn('overridden_at')
            ->removeColumn('overridden_by_user_id')
            ->update();

        $this->table('technician_payout_lines')
            ->dropForeignKey('overridden_by_user_id')
            ->removeColumn('line_type')
            ->removeColumn('original_amount_paise')
            ->removeColumn('override_reason')
            ->removeColumn('overridden_at')
            ->removeColumn('overridden_by_user_id')
            ->update();
    }
}
