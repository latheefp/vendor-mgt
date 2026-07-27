<?php
declare(strict_types=1);

use App\Database\Migration\AppMigration;

/**
 * Makes every operational setting a per-company fact.
 *
 * Dianora is the only company on the system today, and that is precisely
 * why this migration exists now rather than at onboarding: the second
 * company arrives as a sales event, not as a planned release, and anything
 * still global on that day becomes a schema change under deadline.
 *
 * Most of the engine was already per-company. Rate cards, SLA rules, the
 * royalty and spare-margin percentages, travel terms, credit limit and the
 * SLA windows all hang off `vendors` / `vendor_agreements` already. Three
 * gaps remained, and this migration closes them.
 *
 * 1. THE MASTER LISTS WERE GLOBAL.
 *
 *    `job_types`, `product_categories`, `symptoms`, `resolutions` and
 *    `hold_reasons` had a single unique index on `code` and no owner. That
 *    is wrong in both directions: company B cannot add "Wall Mount Only"
 *    without offering it to company A, and cannot suppress a hold reason
 *    its own agreement does not recognise.
 *
 *    The fix is a nullable `vendor_id` meaning:
 *
 *      NULL          the shared baseline, offered to every company
 *      <vendor id>   that company's own entry
 *
 *    A company row SHADOWS a shared row with the same code rather than
 *    colliding with it, so "service" can mean a 48-hour close for one
 *    company and a 24-hour close for the next while both keep the code the
 *    rate resolver matches on. Codes therefore stay stable across
 *    companies — which the resolver depends on — while the rows behind
 *    them do not have to be.
 *
 *    Uniqueness is enforced on COALESCE(vendor_id, 0) via a stored
 *    generated column. A plain unique index on (vendor_id, code) would not
 *    do it: MySQL treats NULLs as distinct, so nothing would stop four
 *    shared rows all coded 'service'.
 *
 * 2. THE TECHNICIAN PAYOUT DIALS HAD NO COMPANY DEFAULT.
 *
 *    `bonus_share_pct` and `penalty_recovery_pct` existed only per
 *    technician. But an SLA bonus originates in a specific company's rate
 *    card, and how much of it reaches the field is a policy set per
 *    company, not renegotiated with each of forty technicians. The
 *    agreement now carries the default; the technician rate still
 *    overrides where someone is on individual terms.
 *
 * 3. THERE WAS NOWHERE TO PUT A SETTING THAT IS NOT A COLUMN.
 *
 *    Ticket number prefixes, portal branding, which shell a role lands on,
 *    whether closure demands a photo — real per-company settings that do
 *    not each justify a migration. `vendor_settings` is a typed key/value
 *    store with the same NULL-means-shared convention, so a platform
 *    default can be set once and overridden per company.
 */
class ScopeSettingsToCompany extends AppMigration
{
    /**
     * The master lists that become company-scopable.
     *
     * Deliberately excludes `states` and `districts`. Kerala has fourteen
     * districts regardless of whose warranty work we are doing, and giving
     * each company its own copy of the same geography would fragment the
     * one dimension every cross-company report needs to group by.
     *
     * @var array<string, string>  table => existing unique index name
     */
    private const SCOPED_LISTS = [
        'job_types' => 'uq_job_types_code',
        'product_categories' => 'uq_product_categories_code',
        'symptoms' => 'uq_symptoms_code',
        'resolutions' => 'uq_resolutions_code',
        'hold_reasons' => 'uq_hold_reasons_code',
    ];

    public function up(): void
    {
        $this->scopeMasterLists();
        $this->addPayoutDefaults();
        $this->allowTechnicianRatesToFollowPolicy();
        $this->createVendorSettings();
    }

    /**
     * Give each master list an optional owning company.
     */
    private function scopeMasterLists(): void
    {
        foreach (self::SCOPED_LISTS as $table => $uniqueIndex) {
            $this->table($table)
                ->addColumn('vendor_id', 'biginteger', self::NULLABLE_KEY + [
                    'comment' => 'null = shared baseline offered to every company',
                ])
                // The desk needs to see that a row is an override, not just
                // that it exists, so the reason travels with the row.
                ->addColumn('override_note', 'string', [
                    'limit' => 255,
                    'null' => true,
                    'comment' => 'why this company deviates from the shared list',
                ])
                ->addIndex(['vendor_id'], ['name' => 'idx_' . $table . '_vendor'])
                ->update();

            // Existing rows are all shared baseline, which is what a NULL
            // vendor_id already gives us — no backfill needed.

            $this->execute(sprintf('DROP INDEX %s ON %s', $uniqueIndex, $table));

            // COALESCE, not the raw column: MySQL lets NULLs repeat inside a
            // unique index, so (NULL, 'service') would not collide with
            // itself and the shared list could silently grow duplicates.
            $this->execute(sprintf(
                'ALTER TABLE %s
                    ADD COLUMN vendor_key BIGINT UNSIGNED
                        AS (CAST(COALESCE(vendor_id, 0) AS UNSIGNED)) VIRTUAL
                        COMMENT "0 = shared; enforces one code per owner"',
                $table,
            ));

            $this->execute(sprintf(
                'CREATE UNIQUE INDEX uq_%s_owner_code ON %s (vendor_key, code)',
                $table,
                $table,
            ));

            $this->execute('SET FOREIGN_KEY_CHECKS = 0;');

            $this->table($table)
                ->addForeignKey('vendor_id', 'vendors', 'id', [
                    // A company's own vocabulary dies with the company.
                    // Shared rows have vendor_id NULL and are untouched.
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_' . $table . '_vendor',
                ])
                ->update();

            $this->execute('SET FOREIGN_KEY_CHECKS = 1;');
        }
    }

    /**
     * Company-level defaults for what reaches the technician.
     */
    private function addPayoutDefaults(): void
    {
        $agreements = $this->table('vendor_agreements');

        // Mirrors the columns on `technician_rates`. Same names on purpose:
        // the resolver reads the technician's value and falls back to this
        // one, and matching names make that fallback obvious at the call
        // site instead of something you have to look up.
        $this->percent($agreements, 'technician_bonus_share_pct', [
            'default' => '100.00',
            'null' => false,
            'comment' => 'default share of an SLA bonus passed to the technician',
        ]);
        $this->percent($agreements, 'technician_penalty_recovery_pct', [
            'default' => '0.00',
            'null' => false,
            'comment' => 'default share of an SLA penalty recovered from the technician',
        ]);

        // Clause 8 pays royalty on out-of-warranty service charges. Whether
        // it also applies to the margin on spares sold at the same visit is
        // a per-company reading of that clause, and Dianora's answer must
        // not become every company's answer.
        $agreements->addColumn('royalty_applies_to_spares', 'boolean', [
            'null' => false,
            'default' => false,
            'comment' => 'does the out-of-warranty royalty extend to spare margin',
        ]);

        $agreements->update();
    }

    /**
     * Let a technician rate defer to company policy.
     *
     * These two columns were NOT NULL DEFAULT 100.00 / 0.00, which meant
     * every technician carried an explicit answer and there was no way to
     * express "whatever this company's policy says". Making them nullable
     * adds that third state; NULL now means follow the agreement, and the
     * rows that already hold a figure keep it as a deliberate override.
     */
    private function allowTechnicianRatesToFollowPolicy(): void
    {
        // changeColumn rather than the percent() helper: these columns
        // already exist, and percent() is an add.
        $nullablePercent = [
            'precision' => 5,
            'scale' => 2,
            'null' => true,
            'default' => null,
            'comment' => 'null = follow the company default on the agreement',
        ];

        $this->table('technician_rates')
            ->changeColumn('bonus_share_pct', 'decimal', $nullablePercent)
            ->changeColumn('penalty_recovery_pct', 'decimal', $nullablePercent)
            ->update();
    }

    /**
     * Typed key/value settings, with the same NULL-means-shared rule as
     * the master lists.
     *
     * Typed rather than free JSON because these are edited through an admin
     * form by someone who is not a developer: `value_type` is what lets the
     * API reject "forty-eight" for an integer window at write time instead
     * of discovering it when an SLA clock reads zero.
     */
    private function createVendorSettings(): void
    {
        $settings = $this->newTable('vendor_settings');
        $settings
            ->addColumn('vendor_id', 'biginteger', self::NULLABLE_KEY + [
                'comment' => 'null = platform default for every company',
            ])
            ->addColumn('setting_key', 'string', [
                'limit' => 96,
                'null' => false,
                'comment' => 'dotted namespace, e.g. ticket.number_prefix',
            ])
            ->addColumn('value_type', 'string', [
                'limit' => 16,
                'null' => false,
                'default' => 'string',
                'comment' => 'string|integer|decimal|boolean|json',
            ])
            // One column for every type. Casting on read is cheap; a column
            // per type means five nullable columns and a rule nobody
            // remembers about which one is authoritative.
            ->addColumn('value', 'text', ['null' => true])
            ->addColumn('label', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            // Some settings are ours to set, not the company's. A branding
            // colour is editable; the ledger currency is not.
            ->addColumn('is_editable', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true]);
        $this->timestamps($settings)
            ->addIndex(['vendor_id', 'setting_key'], ['name' => 'idx_vendor_settings_lookup'])
            ->addForeignKey('vendor_id', 'vendors', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_vendor_settings_vendor',
            ])
            ->create();

        // Same NULL problem as the master lists, same solution.
        $this->execute(
            'ALTER TABLE vendor_settings
                ADD COLUMN vendor_key BIGINT UNSIGNED
                    AS (CAST(COALESCE(vendor_id, 0) AS UNSIGNED)) VIRTUAL
                    COMMENT "0 = platform default; enforces one key per owner"',
        );
        $this->execute(
            'CREATE UNIQUE INDEX uq_vendor_settings_owner_key
                ON vendor_settings (vendor_key, setting_key)',
        );
    }

    public function down(): void
    {
        $this->table('vendor_settings')->drop()->save();

        // A NULL here meant "follow company policy", and the old schema has
        // no way to say that. Resolve them to the values the old defaults
        // implied before making the columns NOT NULL again.
        $this->execute(
            'UPDATE technician_rates
                SET bonus_share_pct = COALESCE(bonus_share_pct, 100.00),
                    penalty_recovery_pct = COALESCE(penalty_recovery_pct, 0.00)',
        );

        $this->table('technician_rates')
            ->changeColumn('bonus_share_pct', 'decimal', [
                'precision' => 5, 'scale' => 2, 'null' => false, 'default' => '100.00',
            ])
            ->changeColumn('penalty_recovery_pct', 'decimal', [
                'precision' => 5, 'scale' => 2, 'null' => false, 'default' => '0.00',
            ])
            ->update();

        $this->table('vendor_agreements')
            ->removeColumn('technician_bonus_share_pct')
            ->removeColumn('technician_penalty_recovery_pct')
            ->removeColumn('royalty_applies_to_spares')
            ->update();

        foreach (self::SCOPED_LISTS as $table => $uniqueIndex) {
            $this->execute(sprintf('DROP INDEX uq_%s_owner_code ON %s', $table, $table));
            $this->execute(sprintf('ALTER TABLE %s DROP COLUMN vendor_key', $table));

            // Reversing is only safe once the company-owned rows are gone —
            // the original index cannot hold two rows coded 'service'.
            $this->execute(sprintf('DELETE FROM %s WHERE vendor_id IS NOT NULL', $table));

            $this->table($table)
                ->dropForeignKey('vendor_id')
                ->removeIndexByName('idx_' . $table . '_vendor')
                ->removeColumn('vendor_id')
                ->removeColumn('override_note')
                ->update();

            $this->execute(sprintf(
                'CREATE UNIQUE INDEX %s ON %s (code)',
                $uniqueIndex,
                $table,
            ));
        }
    }
}
