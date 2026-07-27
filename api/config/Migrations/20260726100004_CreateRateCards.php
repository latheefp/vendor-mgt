<?php
declare(strict_types=1);

use App\Database\Migration\AppMigration;

/**
 * Rate cards, their line items, and the SLA bonus/penalty rules.
 *
 * This is the heart of the system. Two rules govern it:
 *
 * 1. Rate cards are VERSIONED and IMMUTABLE once published. A vendor
 *    revising rates creates version n+1; version n stays exactly as it
 *    was so last quarter's invoices remain explainable.
 *
 * 2. SLA incentives are DATA, not code. The Dianora card pays +Rs.50 for
 *    an installation closed inside 24h, deducts Rs.50 for one closed after
 *    48h, and pays +Rs.75 / +Rs.50 for in-warranty service closed inside
 *    48h / 72h. The next vendor will have different numbers, different
 *    windows, and possibly a metric we do not use yet. Encoding any of
 *    that in PHP means a deploy per vendor.
 */
class CreateRateCards extends AppMigration
{
    public function up(): void
    {
        $cards = $this->newTable('rate_cards');
        $cards
            ->addColumn('vendor_id', 'biginteger', self::KEY)
            ->addColumn('vendor_agreement_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('name', 'string', ['limit' => 190, 'null' => false])
            ->addColumn('version', 'integer', ['null' => false, 'default' => 1, 'signed' => false])
            ->addColumn('status', 'string', [
                'limit' => 24,
                'null' => false,
                'default' => 'draft',
                'comment' => 'draft|active|superseded',
            ])
            ->addColumn('effective_from', 'date', ['null' => false])
            ->addColumn('effective_to', 'date', ['null' => true, 'comment' => 'null = current'])
            ->addColumn('currency', 'string', ['limit' => 3, 'null' => false, 'default' => 'INR'])
            // Once published nothing on this card or its items may change.
            // Charges hold a hard reference to the item that priced them.
            ->addColumn('published_at', 'datetime', ['null' => true])
            ->addColumn('published_by_user_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('notes', 'text', ['null' => true]);
        $this->timestamps($cards)
            ->addIndex(['vendor_id', 'version'], ['unique' => true, 'name' => 'uq_rate_cards_vendor_version'])
            ->addIndex(['vendor_id', 'status', 'effective_from'], ['name' => 'idx_rate_cards_lookup'])
            ->addForeignKey('vendor_id', 'vendors', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_rate_cards_vendor',
            ])
            ->addForeignKey('vendor_agreement_id', 'vendor_agreements', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_rate_cards_agreement',
            ])
            ->addForeignKey('published_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_rate_cards_publisher',
            ])
            ->create();

        $items = $this->newTable('rate_card_items');
        $items
            ->addColumn('rate_card_id', 'biginteger', self::KEY)
            ->addColumn('job_type_id', 'biginteger', self::KEY)
            // null = applies to every product category on this card.
            ->addColumn('product_category_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('warranty_scope', 'string', [
                'limit' => 24,
                'null' => false,
                'default' => 'not_applicable',
                'comment' => 'in_warranty|out_of_warranty|not_applicable',
            ])
            // Inclusive inch range. Both null = size independent, which is
            // how demo, exchange/delivery and panel work are priced.
            ->addColumn('size_min_inch', 'decimal', ['precision' => 6, 'scale' => 2, 'null' => true])
            ->addColumn('size_max_inch', 'decimal', ['precision' => 6, 'scale' => 2, 'null' => true]);

        $this->money($items, 'amount_paise');

        $items
            // Who hands over the money. In-warranty and installation work is
            // billed to the vendor; out-of-warranty is collected in cash from
            // the customer by the technician, which is a different ledger and
            // a different set of controls.
            ->addColumn('payer', 'string', [
                'limit' => 16,
                'null' => false,
                'default' => 'vendor',
                'comment' => 'vendor|customer',
            ])
            ->addColumn('label', 'string', [
                'limit' => 190,
                'null' => false,
                'comment' => 'verbatim wording from the agreement, for invoices',
            ])
            // Manual tie-break. The resolver prefers the most specific match
            // (narrowest size range, explicit category) and only consults this
            // when two items are equally specific.
            ->addColumn('priority', 'integer', ['null' => false, 'default' => 100, 'signed' => false])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true]);
        $this->timestamps($items)
            ->addIndex(
                ['rate_card_id', 'job_type_id', 'warranty_scope', 'is_active'],
                ['name' => 'idx_rate_card_items_resolve'],
            )
            ->addIndex(['product_category_id'], ['name' => 'idx_rate_card_items_category'])
            ->addForeignKey('rate_card_id', 'rate_cards', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_rate_card_items_card',
            ])
            ->addForeignKey('job_type_id', 'job_types', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_rate_card_items_job_type',
            ])
            ->addForeignKey('product_category_id', 'product_categories', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_rate_card_items_category',
            ])
            ->create();

        $sla = $this->newTable('sla_rules');
        $sla
            ->addColumn('rate_card_id', 'biginteger', self::KEY)
            ->addColumn('code', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('label', 'string', ['limit' => 190, 'null' => false])
            ->addColumn('kind', 'string', [
                'limit' => 16,
                'null' => false,
                'comment' => 'bonus|penalty',
            ])
            ->addColumn('metric', 'string', [
                'limit' => 32,
                'null' => false,
                'comment' => 'hours_to_close|hours_to_visit|hours_to_contact',
            ])
            ->addColumn('comparator', 'string', [
                'limit' => 16,
                'null' => false,
                'comment' => 'lte|gt|between',
            ])
            ->addColumn('threshold_from_hours', 'decimal', [
                'precision' => 8,
                'scale' => 2,
                'null' => true,
                'comment' => 'exclusive lower bound for gt/between',
            ])
            ->addColumn('threshold_to_hours', 'decimal', [
                'precision' => 8,
                'scale' => 2,
                'null' => true,
                'comment' => 'inclusive upper bound for lte/between',
            ]);

        // Always a positive magnitude. `kind` decides the sign so that a
        // rule can never be stored with a sign that contradicts its type.
        $this->money($sla, 'amount_paise', ['signed' => false]);

        $sla
            // JSON array of job_type codes; null = every job type.
            ->addColumn('applies_to_job_types', 'json', ['null' => true])
            ->addColumn('warranty_scope', 'string', [
                'limit' => 24,
                'null' => true,
                'comment' => 'null = any scope',
            ])
            // Within one metric the lowest priority wins first, and matching
            // stops there unless the rule is stackable. That is what keeps
            // "closed within 48h" and "closed 48-72h" from both paying out.
            ->addColumn('priority', 'integer', ['null' => false, 'default' => 100, 'signed' => false])
            ->addColumn('is_stackable', 'boolean', [
                'null' => false,
                'default' => false,
                'comment' => 'false = first match for this metric wins',
            ])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('notes', 'text', ['null' => true]);
        $this->timestamps($sla)
            ->addIndex(['rate_card_id', 'code'], ['unique' => true, 'name' => 'uq_sla_rules_card_code'])
            ->addIndex(['rate_card_id', 'metric', 'is_active', 'priority'], ['name' => 'idx_sla_rules_resolve'])
            ->addForeignKey('rate_card_id', 'rate_cards', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_sla_rules_card',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('sla_rules')->drop()->save();
        $this->table('rate_card_items')->drop()->save();
        $this->table('rate_cards')->drop()->save();
    }
}
