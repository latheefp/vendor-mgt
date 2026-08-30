<?php
declare(strict_types=1);

use App\Database\Migration\AppMigration;

/**
 * Renames the counterparty from "vendor" to "company", everywhere.
 *
 * WHY THIS EXISTS
 *
 * The schema was built calling Dianora our "vendor". That word was wrong
 * in three separate ways and each one had already started costing us:
 *
 * 1. It contradicts the signed agreement. Service Agreement 2 names the
 *    two parties throughout as "the service centre" (us) and "The
 *    Company" (Dianora — see Important Note 5, "The Company shall
 *    reimburse travel expenses..."). The signature block reads
 *    "Signature (Service Centre)" against "Signature (Dianora
 *    Electronics PVT LTD)". When an invoice is disputed, the words on
 *    the screen and the words in the contract have to be the same words.
 *
 * 2. It is backwards in accounting. A vendor is someone you buy from.
 *    Dianora predominantly OWES us — which is why the old ledger case
 *    `vendor_receivable` needed a comment reading "money the vendor owes
 *    us" to stop people reading it as its own opposite.
 *
 * 3. It points in both directions at once. From Dianora's side of the
 *    table, WE are the vendor. A role word that swaps meaning depending
 *    on who is holding the paper is the one word you cannot put on a
 *    document both parties read.
 *
 * "Company" carries none of that. It is what the agreement calls them,
 * it is what the next manufacturer will be called, and it cannot be read
 * as us.
 *
 * WHAT WE ARE, FOR THE AVOIDANCE OF DOUBT
 *
 * We are the SERVICE CENTRE, and that already has a home: `service_centers`,
 * one row per branch (Thamarassery today, more later). Nothing in this
 * migration touches it. The two sides of the agreement are now two
 * different tables with two different names, which is the entire point:
 *
 *   companies         the manufacturers whose warranty work we perform
 *   service_centers   our own branches performing it
 *
 * ORDER OF OPERATIONS
 *
 * MySQL will not let us rename our way through this in one pass, so the
 * work is staged. Foreign keys come off first — not because renaming a
 * table breaks them (MySQL repoints them itself) but because their NAMES
 * would keep saying vendor forever, and a constraint violation that
 * names `fk_tickets_vendor` is exactly the confusion we are removing.
 * The generated `vendor_key` columns come off next: each is an
 * expression over `vendor_id`, so the column underneath cannot be
 * renamed while they still reference it, and their unique indexes have
 * to go before the columns do or MySQL silently narrows the index to its
 * remaining column.
 *
 * DATA, NOT JUST SCHEMA
 *
 * The last step rewrites stored values. `payer`, `ledger` and `line_type`
 * are varchars backing PHP enums, so 'vendor' / 'vendor_receivable' /
 * 'vendor_payable' / 'vendor_royalty' are live values on real rows — a
 * schema-only rename would leave every one of them unmappable at the
 * moment the new enum loads them. The out-of-warranty royalty on an
 * already-frozen ticket must still resolve after this runs.
 */
class RenameVendorToCompany extends AppMigration
{
    /**
     * Tables whose own name carried the word.
     *
     * @var array<string, string>
     */
    private const TABLES = [
        'vendors' => 'companies',
        'vendor_agreements' => 'company_agreements',
        'vendor_invoices' => 'company_invoices',
        'vendor_invoice_lines' => 'company_invoice_lines',
        'vendor_job_type_aliases' => 'company_job_type_aliases',
        'vendor_settings' => 'company_settings',
    ];

    /**
     * Foreign keys, keyed by the table they live on AFTER the table
     * rename above. Dropped and re-added rather than left alone, so the
     * constraint names match the columns they now constrain.
     *
     * @var array<string, array<string, array{0: string, 1: string, 2: string, 3: string}>>
     */
    private const FOREIGN_KEYS = [
        //  table                    new constraint name             column                  -> referenced table       on delete
        'brands' => [
            'fk_brands_company' => ['company_id', 'companies', 'CASCADE', 'fk_brands_vendor'],
        ],
        'hold_reasons' => [
            'fk_hold_reasons_company' => ['company_id', 'companies', 'CASCADE', 'fk_hold_reasons_vendor'],
        ],
        'job_types' => [
            'fk_job_types_company' => ['company_id', 'companies', 'CASCADE', 'fk_job_types_vendor'],
        ],
        'product_categories' => [
            'fk_product_categories_company' => ['company_id', 'companies', 'CASCADE', 'fk_product_categories_vendor'],
        ],
        'products' => [
            'fk_products_company' => ['company_id', 'companies', 'CASCADE', 'fk_products_vendor'],
        ],
        'resolutions' => [
            'fk_resolutions_company' => ['company_id', 'companies', 'CASCADE', 'fk_resolutions_vendor'],
        ],
        'spare_parts' => [
            'fk_spare_parts_company' => ['company_id', 'companies', 'CASCADE', 'fk_spare_parts_vendor'],
        ],
        'symptoms' => [
            'fk_symptoms_company' => ['company_id', 'companies', 'CASCADE', 'fk_symptoms_vendor'],
        ],
        'ticket_sequences' => [
            'fk_ticket_sequences_company' => ['company_id', 'companies', 'CASCADE', 'fk_ticket_sequences_vendor'],
        ],
        'rate_cards' => [
            'fk_rate_cards_company' => ['company_id', 'companies', 'CASCADE', 'fk_rate_cards_vendor'],
            'fk_rate_cards_agreement' => ['company_agreement_id', 'company_agreements', 'SET NULL', 'fk_rate_cards_agreement'],
        ],
        'ticket_charges' => [
            'fk_ticket_charges_agreement' => ['company_agreement_id', 'company_agreements', 'RESTRICT', 'fk_ticket_charges_agreement'],
        ],
        'tickets' => [
            'fk_tickets_company' => ['company_id', 'companies', 'RESTRICT', 'fk_tickets_vendor'],
            'fk_tickets_agreement' => ['company_agreement_id', 'company_agreements', 'RESTRICT', 'fk_tickets_agreement'],
        ],
        'company_agreements' => [
            'fk_company_agreements_company' => ['company_id', 'companies', 'RESTRICT', 'fk_vendor_agreements_vendor'],
        ],
        'company_settings' => [
            'fk_company_settings_company' => ['company_id', 'companies', 'CASCADE', 'fk_vendor_settings_vendor'],
        ],
        'company_job_type_aliases' => [
            'fk_company_aliases_company' => ['company_id', 'companies', 'CASCADE', 'fk_vendor_aliases_vendor'],
            'fk_company_aliases_job_type' => ['job_type_id', 'job_types', 'CASCADE', 'fk_vendor_aliases_job_type'],
        ],
        'company_invoices' => [
            'fk_company_invoices_company' => ['company_id', 'companies', 'RESTRICT', 'fk_vendor_invoices_vendor'],
            'fk_company_invoices_agreement' => ['company_agreement_id', 'company_agreements', 'RESTRICT', 'fk_vendor_invoices_agreement'],
            'fk_company_invoices_creator' => ['created_by_user_id', 'users', 'SET NULL', 'fk_vendor_invoices_creator'],
        ],
        'company_invoice_lines' => [
            'fk_company_invoice_lines_invoice' => ['company_invoice_id', 'company_invoices', 'CASCADE', 'fk_vendor_invoice_lines_invoice'],
            'fk_company_invoice_lines_ticket' => ['ticket_id', 'tickets', 'RESTRICT', 'fk_vendor_invoice_lines_ticket'],
            'fk_company_invoice_lines_charge' => ['ticket_charge_id', 'ticket_charges', 'RESTRICT', 'fk_vendor_invoice_lines_charge'],
            'fk_company_invoice_lines_overrider' => ['overridden_by_user_id', 'users', 'SET NULL', 'fk_vendor_invoice_lines_overrider'],
        ],
    ];

    /**
     * The generated owner-key columns, keyed by table (post-rename), with
     * the second column of their unique index.
     *
     * Each is `COALESCE(company_id, 0)`, which is what makes a NULL
     * owner — the shared baseline row — collide with other shared rows
     * on the same code. A plain unique index on (company_id, code) will
     * not do it: MySQL treats NULLs as distinct, so nothing would stop
     * four shared rows all coded 'service'.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const OWNER_KEYS = [
        //  table                  index name                         2nd col        old index name                     comment
        'hold_reasons' => ['uq_hold_reasons_owner_code', 'code', 'uq_hold_reasons_owner_code', '0 = shared; enforces one code per owner'],
        'job_types' => ['uq_job_types_owner_code', 'code', 'uq_job_types_owner_code', '0 = shared; enforces one code per owner'],
        'product_categories' => ['uq_product_categories_owner_code', 'code', 'uq_product_categories_owner_code', '0 = shared; enforces one code per owner'],
        'resolutions' => ['uq_resolutions_owner_code', 'code', 'uq_resolutions_owner_code', '0 = shared; enforces one code per owner'],
        'symptoms' => ['uq_symptoms_owner_code', 'code', 'uq_symptoms_owner_code', '0 = shared; enforces one code per owner'],
        'company_settings' => ['uq_company_settings_owner_key', 'setting_key', 'uq_vendor_settings_owner_key', '0 = platform default; enforces one key per owner'],
    ];

    /**
     * Column renames, keyed by table name AFTER the table rename.
     *
     * @var array<string, array<string, string>>
     */
    private const COLUMNS = [
        'brands' => ['vendor_id' => 'company_id'],
        'hold_reasons' => [
            'vendor_id' => 'company_id',
            // Clause 11 again: a hold only survives a dispute if the
            // company was emailed about it. The flag says whose inbox.
            'requires_vendor_notice' => 'requires_company_notice',
        ],
        'job_types' => ['vendor_id' => 'company_id'],
        'product_categories' => ['vendor_id' => 'company_id'],
        'products' => ['vendor_id' => 'company_id'],
        'resolutions' => ['vendor_id' => 'company_id'],
        'spare_parts' => ['vendor_id' => 'company_id'],
        'symptoms' => ['vendor_id' => 'company_id'],
        'ticket_sequences' => ['vendor_id' => 'company_id'],
        'rate_cards' => [
            'vendor_id' => 'company_id',
            'vendor_agreement_id' => 'company_agreement_id',
        ],
        'ticket_charges' => ['vendor_agreement_id' => 'company_agreement_id'],
        // The technician's cut is expressed as a percentage of what the
        // company pays for the job, so the word in the column name is the
        // counterparty, not the payee.
        'technician_rates' => ['pct_of_vendor' => 'pct_of_company'],
        'ticket_holds' => [
            'vendor_notified_at' => 'company_notified_at',
            'vendor_notification_message_id' => 'company_notification_message_id',
        ],
        'tickets' => [
            'vendor_id' => 'company_id',
            'vendor_agreement_id' => 'company_agreement_id',
            // Their ticket number (DN1407260024), kept beside ours so the
            // desk can answer a phone call about either.
            'vendor_ticket_ref' => 'company_ticket_ref',
            'vendor_submitted_at' => 'company_submitted_at',
            'vendor_approved_at' => 'company_approved_at',
            'vendor_rejected_at' => 'company_rejected_at',
            'vendor_rejection_reason' => 'company_rejection_reason',
            'vendor_branch_label' => 'company_branch_label',
            'vendor_complaint_type' => 'company_complaint_type',
            'vendor_payload' => 'company_payload',
        ],
        'company_agreements' => ['vendor_id' => 'company_id'],
        'company_settings' => ['vendor_id' => 'company_id'],
        'company_job_type_aliases' => [
            'vendor_id' => 'company_id',
            'vendor_label' => 'company_label',
        ],
        'company_invoices' => [
            'vendor_id' => 'company_id',
            'vendor_agreement_id' => 'company_agreement_id',
        ],
        'company_invoice_lines' => ['vendor_invoice_id' => 'company_invoice_id'],
    ];

    /**
     * Index renames, keyed by table name AFTER the table rename. Indexes
     * that never said vendor are left alone.
     *
     * @var array<string, array<string, string>>
     */
    private const INDEXES = [
        'brands' => ['uq_brands_vendor_code' => 'uq_brands_company_code'],
        'hold_reasons' => ['idx_hold_reasons_vendor' => 'idx_hold_reasons_company'],
        'job_types' => ['idx_job_types_vendor' => 'idx_job_types_company'],
        'product_categories' => ['idx_product_categories_vendor' => 'idx_product_categories_company'],
        'products' => ['uq_products_vendor_model' => 'uq_products_company_model'],
        'resolutions' => ['idx_resolutions_vendor' => 'idx_resolutions_company'],
        'spare_parts' => ['uq_spare_parts_vendor_part' => 'uq_spare_parts_company_part'],
        'symptoms' => ['idx_symptoms_vendor' => 'idx_symptoms_company'],
        'rate_cards' => ['uq_rate_cards_vendor_version' => 'uq_rate_cards_company_version'],
        'tickets' => [
            'idx_tickets_vendor_period' => 'idx_tickets_company_period',
            'uq_tickets_vendor_ref' => 'uq_tickets_company_ref',
        ],
        'companies' => [
            'uq_vendors_code' => 'uq_companies_code',
            'idx_vendors_active' => 'idx_companies_active',
        ],
        'company_agreements' => [
            'uq_vendor_agreements_no' => 'uq_company_agreements_no',
            'idx_vendor_agreements_lookup' => 'idx_company_agreements_lookup',
        ],
        'company_settings' => ['idx_vendor_settings_lookup' => 'idx_company_settings_lookup'],
        'company_job_type_aliases' => [
            'uq_vendor_aliases_label' => 'uq_company_aliases_label',
            // Left behind when the constraint of the same name is dropped:
            // MySQL keeps the index it auto-created for a foreign key, so
            // renaming only the constraint would leave the old word on the
            // index. Renaming it here also means the re-added constraint
            // reuses this index instead of creating a second one.
            'fk_vendor_aliases_job_type' => 'fk_company_aliases_job_type',
        ],
        'company_invoices' => [
            'uq_vendor_invoices_no' => 'uq_company_invoices_no',
            'idx_vendor_invoices_period' => 'idx_company_invoices_period',
            'idx_vendor_invoices_status' => 'idx_company_invoices_status',
            'fk_vendor_invoices_agreement' => 'fk_company_invoices_agreement',
            'fk_vendor_invoices_creator' => 'fk_company_invoices_creator',
        ],
        'company_invoice_lines' => [
            'idx_vendor_invoice_lines_invoice' => 'idx_company_invoice_lines_invoice',
            'idx_vendor_invoice_lines_ticket' => 'idx_company_invoice_lines_ticket',
            'idx_vendor_invoice_lines_charge' => 'idx_company_invoice_lines_charge',
            'fk_vendor_invoice_lines_overrider' => 'fk_company_invoice_lines_overrider',
        ],
    ];

    /**
     * Columns whose DEFAULT or COMMENT said vendor, as
     * `table => column => [new definition, old definition]`.
     *
     * These are the ones a `SHOW CREATE TABLE` would still betray after
     * every table, column and index had been renamed. Two of them are not
     * cosmetic at all: `rate_card_items.payer` and
     * `ticket_spares.charged_to` both DEFAULT to 'vendor', so an insert
     * that omits the column would keep writing a value the new enum
     * cannot load.
     *
     * The comments themselves matter more here than usual, because they
     * are the only place the permitted values of these varchar-backed
     * enums are written down in the database.
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    private const MODIFY_COLUMNS = [
        'customers' => [
            'source' => [
                "varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'desk' COMMENT 'company_email|company_api|csv_import|desk|phone'",
                "varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'desk' COMMENT 'vendor_email|vendor_api|csv_import|desk|phone'",
            ],
        ],
        'tickets' => [
            'source' => [
                "varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'desk' COMMENT 'company_email|company_api|csv_import|desk|phone'",
                "varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'desk' COMMENT 'vendor_email|vendor_api|csv_import|desk|phone'",
            ],
            'company_complaint_type' => [
                "varchar(96) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'as the company worded it, before alias mapping'",
                "varchar(96) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'the vendor''s wording before alias mapping'",
            ],
        ],
        'rate_card_items' => [
            'payer' => [
                "varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'company' COMMENT 'company|customer'",
                "varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'vendor' COMMENT 'vendor|customer'",
            ],
        ],
        'ticket_spares' => [
            'charged_to' => [
                "varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'company' COMMENT 'company|customer'",
                "varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'vendor' COMMENT 'vendor|customer'",
            ],
        ],
        'spare_stock_movements' => [
            'movement_type' => [
                "varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'received|issued|consumed|returned_good|returned_defective|sent_to_company|written_off|adjustment'",
                "varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'received|issued|consumed|returned_good|returned_defective|sent_to_vendor|written_off|adjustment'",
            ],
            'reference' => [
                "varchar(96) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'company challan or courier docket'",
                "varchar(96) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'vendor challan or courier docket'",
            ],
        ],
        'technician_rates' => [
            'model' => [
                "varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'flat_per_job' COMMENT 'flat_per_job|pct_of_company|salaried'",
                "varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'flat_per_job' COMMENT 'flat_per_job|pct_of_vendor|salaried'",
            ],
        ],
        'ticket_charges' => [
            'line_type' => [
                "varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'base|sla_bonus|sla_penalty|travel|spare_cost|spare_margin|company_royalty|technician_payout|technician_bonus_share|technician_penalty_recovery|adjustment'",
                "varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'base|sla_bonus|sla_penalty|travel|spare_cost|spare_margin|vendor_royalty|technician_payout|technician_bonus_share|technician_penalty_recovery|adjustment'",
            ],
            'ledger' => [
                "varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'company_receivable|customer_collection|company_payable|technician_payable'",
                "varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'vendor_receivable|customer_collection|vendor_payable|technician_payable'",
            ],
        ],
        'ticket_events' => [
            'event_type' => [
                "varchar(48) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'created|assigned|contacted|checked_in|hold_started|hold_ended|photo_uploaded|otp_sent|otp_verified|closed|charges_computed|charges_frozen|reopened|company_notified'",
                "varchar(48) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'created|assigned|contacted|checked_in|hold_started|hold_ended|photo_uploaded|otp_sent|otp_verified|closed|charges_computed|charges_frozen|reopened|vendor_notified'",
            ],
        ],
        'ticket_holds' => [
            'reason_code' => [
                "varchar(48) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'customer_unavailable|customer_postponed|address_wrong|spare_awaited|access_denied|company_approval_pending|other'",
                "varchar(48) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'customer_unavailable|customer_postponed|address_wrong|spare_awaited|access_denied|vendor_approval_pending|other'",
            ],
        ],
        'company_agreements' => [
            'invoice_cycle_day' => [
                "int unsigned NOT NULL DEFAULT '10' COMMENT 'day of month the company settles invoices'",
                "int unsigned NOT NULL DEFAULT '10' COMMENT 'day of month the vendor settles invoices'",
            ],
        ],
        'company_invoices' => [
            'cycle_date' => [
                "date DEFAULT NULL COMMENT 'the company settlement date'",
                "date DEFAULT NULL COMMENT 'the vendor settlement date'",
            ],
        ],
        'company_job_type_aliases' => [
            'company_label' => [
                "varchar(96) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'as the company words it, e.g. \"Service\"'",
                "varchar(96) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'the vendor''s own wording, e.g. \"Service\"'",
            ],
        ],
    ];

    /**
     * Stored enum values, as `table => column => [old => new]`.
     *
     * Every one of these is a varchar backing a PHP enum, so each row
     * holding an old value is a row the new enum would refuse to load.
     * `tickets.source` is the one with teeth on day one — half our
     * tickets arrived as 'vendor_email'.
     *
     * @var array<string, array<string, array<string, string>>>
     */
    private const VALUES = [
        'rate_card_items' => [
            'payer' => ['vendor' => 'company'],
        ],
        'ticket_spares' => [
            'charged_to' => ['vendor' => 'company'],
        ],
        'ticket_charges' => [
            'ledger' => [
                'vendor_receivable' => 'company_receivable',
                'vendor_payable' => 'company_payable',
            ],
            'line_type' => ['vendor_royalty' => 'company_royalty'],
        ],
        'company_invoice_lines' => [
            'ledger' => [
                'vendor_receivable' => 'company_receivable',
                'vendor_payable' => 'company_payable',
            ],
            'line_type' => ['vendor_royalty' => 'company_royalty'],
        ],
        'technician_payout_lines' => [
            'line_type' => ['vendor_royalty' => 'company_royalty'],
        ],
        'technician_rates' => [
            'model' => ['pct_of_vendor' => 'pct_of_company'],
        ],
        'spare_stock_movements' => [
            'movement_type' => ['sent_to_vendor' => 'sent_to_company'],
        ],
        'ticket_events' => [
            'event_type' => ['vendor_notified' => 'company_notified'],
        ],
        'ticket_holds' => [
            'reason_code' => ['vendor_approval_pending' => 'company_approval_pending'],
        ],
        'customers' => [
            'source' => [
                'vendor_email' => 'company_email',
                'vendor_api' => 'company_api',
            ],
        ],
        'tickets' => [
            'source' => [
                'vendor_email' => 'company_email',
                'vendor_api' => 'company_api',
            ],
        ],
        'company_settings' => [
            'setting_key' => ['notice.email_vendor_on_hold' => 'notice.email_company_on_hold'],
        ],
    ];

    /**
     * Display text already written onto frozen rows, as
     * `table => column => [old => new]`.
     *
     * A frozen charge line is never edited — that rule is what stops an
     * invoice quietly changing after it was sent. This is the one
     * exception, and it is a narrow one: the words move, the money does
     * not. `ChargeBuilder` stamps the royalty line with a sentence at
     * freeze time, so without this every ticket closed before today would
     * keep explaining itself as a "Vendor royalty" forever — on exactly
     * the document most likely to be forwarded to Dianora.
     *
     * Matched on the full phrase rather than the bare word, so a customer
     * name or a note that happens to contain "vendor" is left alone.
     *
     * @var array<string, array<string, array<string, string>>>
     */
    private const TEXT = [
        'ticket_charges' => [
            'description' => ['Vendor royalty ' => 'Company royalty '],
        ],
        'company_invoice_lines' => [
            'description' => ['Vendor royalty ' => 'Company royalty '],
        ],
    ];

    /**
     * Foreign keys and generated columns come off first, then the names
     * move, then the constraints go back on. See the class docblock for
     * why that order is forced rather than chosen.
     */
    public function up(): void
    {
        $this->dropForeignKeys(self::FOREIGN_KEYS, old: true);
        $this->dropOwnerKeys(self::OWNER_KEYS, old: true);
        $this->renameTables(self::TABLES);
        $this->renameColumns(self::COLUMNS);
        $this->modifyColumns(self::MODIFY_COLUMNS, reverse: false);
        $this->renameIndexes(self::INDEXES);
        $this->addOwnerKeys(self::OWNER_KEYS, 'company_key');
        $this->addForeignKeys(self::FOREIGN_KEYS, old: false);
        $this->rewriteValues(self::VALUES, reverse: false);
        $this->rewriteText(self::TEXT, reverse: false);
    }

    /**
     * The exact inverse of up(), which matters more than it looks.
     *
     * Steps 1-4 run while the tables are still under their NEW names —
     * `RENAME TABLE` is step 5, not step 1 — so they address
     * `company_invoices`, not `vendor_invoices`. Only the last two steps,
     * which run after the tables have gone back, use the old names.
     */
    public function down(): void
    {
        $this->rewriteText(self::TEXT, reverse: true);
        $this->rewriteValues(self::VALUES, reverse: true);
        $this->dropForeignKeys(self::FOREIGN_KEYS, old: false);
        $this->dropOwnerKeys(self::OWNER_KEYS, old: false);
        $this->modifyColumns(self::MODIFY_COLUMNS, reverse: true);
        $this->renameIndexes(self::INDEXES, reverse: true);
        $this->renameColumns(self::COLUMNS, reverse: true);
        $this->renameTables(array_flip(self::TABLES));
        $this->addOwnerKeys(self::OWNER_KEYS, 'vendor_key', reverse: true);
        $this->addForeignKeys(self::FOREIGN_KEYS, old: true, reverse: true);
    }

    // -----------------------------------------------------------------
    // The steps. Each is driven entirely by the maps above, so adding a
    // column to the rename is editing data, not writing SQL.
    // -----------------------------------------------------------------

    /**
     * @param array<string, array<string, array{0: string, 1: string, 2: string, 3: string}>> $map
     */
    private function dropForeignKeys(array $map, bool $old): void
    {
        foreach ($map as $newTable => $keys) {
            $table = $old ? $this->oldTableName($newTable) : $newTable;
            foreach ($keys as $newName => [, , , $oldName]) {
                $name = $old ? $oldName : $newName;
                $this->execute(sprintf(
                    'ALTER TABLE `%s` DROP FOREIGN KEY `%s`',
                    $table,
                    $name,
                ));
            }
        }
    }

    /**
     * @param array<string, array<string, array{0: string, 1: string, 2: string, 3: string}>> $map
     */
    private function addForeignKeys(array $map, bool $old, bool $reverse = false): void
    {
        foreach ($map as $newTable => $keys) {
            $table = $old ? $this->oldTableName($newTable) : $newTable;
            foreach ($keys as $newName => [$column, $references, $onDelete, $oldName]) {
                $this->execute(sprintf(
                    'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) '
                        . 'REFERENCES `%s` (`id`) ON DELETE %s ON UPDATE CASCADE',
                    $table,
                    $old ? $oldName : $newName,
                    $reverse ? $this->oldColumnName($table, $column) : $column,
                    $old ? $this->oldTableName($references) : $references,
                    $onDelete,
                ));
            }
        }
    }

    /**
     * The unique index has to go before the column it is built on, or
     * MySQL keeps the index and quietly drops only the one column out of
     * it — leaving `UNIQUE (code)` behind, which would reject the second
     * company's copy of a shared code.
     *
     * @param array<string, array{0: string, 1: string, 2: string, 3: string}> $map
     */
    private function dropOwnerKeys(array $map, bool $old): void
    {
        foreach ($map as $newTable => [$newIndex, , $oldIndex]) {
            $table = $old ? $this->oldTableName($newTable) : $newTable;
            $this->execute(sprintf(
                'ALTER TABLE `%s` DROP INDEX `%s`, DROP COLUMN `%s`',
                $table,
                $old ? $oldIndex : $newIndex,
                $old ? 'vendor_key' : 'company_key',
            ));
        }
    }

    /**
     * @param array<string, array{0: string, 1: string, 2: string, 3: string}> $map
     */
    private function addOwnerKeys(array $map, string $column, bool $reverse = false): void
    {
        $owner = $reverse ? 'vendor_id' : 'company_id';

        foreach ($map as $newTable => [$newIndex, $second, $oldIndex, $comment]) {
            $table = $reverse ? $this->oldTableName($newTable) : $newTable;
            $this->execute(sprintf(
                'ALTER TABLE `%s` ADD COLUMN `%s` BIGINT UNSIGNED '
                    . 'GENERATED ALWAYS AS (CAST(COALESCE(`%s`, 0) AS UNSIGNED)) VIRTUAL '
                    . "COMMENT '%s', ADD UNIQUE INDEX `%s` (`%s`, `%s`)",
                $table,
                $column,
                $owner,
                $comment,
                $reverse ? $oldIndex : $newIndex,
                $column,
                $second,
            ));
        }
    }

    /**
     * @param array<string, string> $map
     */
    private function renameTables(array $map): void
    {
        foreach ($map as $from => $to) {
            $this->execute(sprintf('RENAME TABLE `%s` TO `%s`', $from, $to));
        }
    }

    /**
     * @param array<string, array<string, string>> $map
     */
    private function renameColumns(array $map, bool $reverse = false): void
    {
        foreach ($map as $table => $columns) {
            foreach ($columns as $from => $to) {
                $this->execute(sprintf(
                    'ALTER TABLE `%s` RENAME COLUMN `%s` TO `%s`',
                    $table,
                    $reverse ? $to : $from,
                    $reverse ? $from : $to,
                ));
            }
        }
    }

    /**
     * Rewrites a column's DEFAULT and COMMENT in place.
     *
     * MySQL has no "alter just the comment", so each one carries its full
     * definition. Both directions are spelled out rather than derived,
     * because a MODIFY built from a half-remembered type is how a
     * varchar(96) quietly becomes a varchar(255).
     *
     * @param array<string, array<string, array{0: string, 1: string}>> $map
     */
    private function modifyColumns(array $map, bool $reverse): void
    {
        foreach ($map as $table => $columns) {
            foreach ($columns as $column => [$new, $old]) {
                $this->execute(sprintf(
                    'ALTER TABLE `%s` MODIFY COLUMN `%s` %s',
                    $table,
                    $column,
                    $reverse ? $old : $new,
                ));
            }
        }
    }

    /**
     * @param array<string, array<string, string>> $map
     */
    private function renameIndexes(array $map, bool $reverse = false): void
    {
        foreach ($map as $table => $indexes) {
            foreach ($indexes as $from => $to) {
                $this->execute(sprintf(
                    'ALTER TABLE `%s` RENAME INDEX `%s` TO `%s`',
                    $table,
                    $reverse ? $to : $from,
                    $reverse ? $from : $to,
                ));
            }
        }
    }

    /**
     * @param array<string, array<string, array<string, string>>> $map
     */
    private function rewriteValues(array $map, bool $reverse): void
    {
        foreach ($map as $table => $columns) {
            foreach ($columns as $column => $values) {
                foreach ($values as $from => $to) {
                    $this->execute(sprintf(
                        "UPDATE `%s` SET `%s` = '%s' WHERE `%s` = '%s'",
                        $table,
                        $column,
                        $reverse ? $from : $to,
                        $column,
                        $reverse ? $to : $from,
                    ));
                }
            }
        }
    }

    /**
     * Substring replacement inside a text column, as opposed to the
     * whole-value swap `rewriteValues` does.
     *
     * @param array<string, array<string, array<string, string>>> $map
     */
    private function rewriteText(array $map, bool $reverse): void
    {
        foreach ($map as $table => $columns) {
            foreach ($columns as $column => $phrases) {
                foreach ($phrases as $from => $to) {
                    [$search, $replace] = $reverse ? [$to, $from] : [$from, $to];
                    $this->execute(sprintf(
                        "UPDATE `%s` SET `%s` = REPLACE(`%s`, '%s', '%s') WHERE `%s` LIKE '%%%s%%'",
                        $table,
                        $column,
                        $column,
                        $search,
                        $replace,
                        $column,
                        $search,
                    ));
                }
            }
        }
    }

    /**
     * The pre-rename name of a table, for the steps that run either side
     * of `RENAME TABLE`. Tables that were never renamed answer for
     * themselves.
     */
    private function oldTableName(string $new): string
    {
        return array_search($new, self::TABLES, true) ?: $new;
    }

    /**
     * Only used on the way down, where a foreign key is re-added before
     * the column it constrains has been renamed back.
     */
    private function oldColumnName(string $table, string $new): string
    {
        foreach (self::COLUMNS as $candidate => $columns) {
            if ($this->oldTableName($candidate) !== $table && $candidate !== $table) {
                continue;
            }

            $old = array_search($new, $columns, true);
            if ($old !== false) {
                return $old;
            }
        }

        return $new;
    }
}
