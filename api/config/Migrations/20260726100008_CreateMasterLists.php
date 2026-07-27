<?php
declare(strict_types=1);

use App\Database\Migration\AppMigration;

/**
 * Master lists, derived from a real Dianora ticket.
 *
 *   Code             DN1407260024
 *   Complaint Type   Service
 *   Product          Dianox                 <- brand, not category
 *   Model            DX4325FHDSVK
 *   Serial No        DX4325FHDSVK082500453
 *   Bill Date        25/11/2025
 *   Assigned Branch  GRAND SERVICE THARASSERY
 *   Description      DISPLAY LINE NEED SYMPTOM VEDIO DOC..
 *   Customer         Yoonas
 *   State/District   Kerala / Kozhikode
 *   Place            Puduppadi-673586
 *
 * Everything in that ticket that repeats becomes a lookup rather than
 * free text. The reason is reporting, not tidiness: "how many display-line
 * failures did we see on 43" panels in Kozhikode last quarter" is a
 * question the vendor will eventually ask, and it is unanswerable against
 * a column full of "DISPLAY LINE", "display lines" and "DISPLY LINE".
 *
 * The other half of this migration is vendor vocabulary mapping. Dianora
 * says "Complaint Type: Service"; the next vendor will say "Job Type:
 * Repair" or "Call Category: Breakdown". `vendor_job_type_aliases` lets an
 * importer translate their word into ours without a code change — the same
 * principle as the rate cards.
 */
class CreateMasterLists extends AppMigration
{
    public function up(): void
    {
        $this->createGeography();
        $this->createBrands();
        $this->createSymptomsAndResolutions();
        $this->createHoldReasons();
        $this->createVendorAliases();
        $this->extendCustomers();
        $this->extendProducts();
        $this->extendTickets();
        $this->extendTicketHolds();
    }

    /**
     * States and districts. Kerala alone has 14 districts and the vendor
     * sends them as free text, so an import that cannot match one needs to
     * fail into a review queue rather than silently create "Kozhikkode"
     * alongside "Kozhikode".
     */
    private function createGeography(): void
    {
        $states = $this->newTable('states');
        $states
            ->addColumn('code', 'string', ['limit' => 8, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 96, 'null' => false])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true]);
        $this->timestamps($states)
            ->addIndex(['code'], ['unique' => true, 'name' => 'uq_states_code'])
            ->create();

        $districts = $this->newTable('districts');
        $districts
            ->addColumn('state_id', 'biginteger', self::KEY)
            ->addColumn('code', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 96, 'null' => false])
            // Alternate spellings the vendor might send, so the importer can
            // resolve "Kozhikkode" or "Calicut" to one district.
            ->addColumn('aliases', 'json', ['null' => true])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true]);
        $this->timestamps($districts)
            ->addIndex(['state_id', 'code'], ['unique' => true, 'name' => 'uq_districts_code'])
            ->addIndex(['name'], ['name' => 'idx_districts_name'])
            ->addForeignKey('state_id', 'states', 'id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_districts_state',
            ])
            ->create();
    }

    /**
     * "Product: Dianox" on the sample ticket is a brand, not a category.
     * Dianora sells under sub-brands, and a rate card may eventually differ
     * by brand, so it needs its own identity.
     */
    private function createBrands(): void
    {
        $brands = $this->newTable('brands');
        $brands
            ->addColumn('vendor_id', 'biginteger', self::KEY)
            ->addColumn('code', 'string', ['limit' => 48, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 96, 'null' => false])
            ->addColumn('aliases', 'json', ['null' => true])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true]);
        $this->timestamps($brands)
            ->addIndex(['vendor_id', 'code'], ['unique' => true, 'name' => 'uq_brands_vendor_code'])
            ->addForeignKey('vendor_id', 'vendors', 'id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_brands_vendor',
            ])
            ->create();
    }

    /**
     * What was wrong, and what we did about it.
     *
     * The sample description — "DISPLAY LINE NEED SYMPTOM VEDIO DOC.." —
     * is exactly why this is a list. The raw text is still kept on the
     * ticket, but the coded symptom is what reports and repeat-complaint
     * detection actually run on.
     */
    private function createSymptomsAndResolutions(): void
    {
        $symptoms = $this->newTable('symptoms');
        $symptoms
            ->addColumn('product_category_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('code', 'string', ['limit' => 48, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('aliases', 'json', ['null' => true])
            // Panel faults usually mean an expensive part and a specialist,
            // so the desk wants to see that at intake rather than at closure.
            ->addColumn('is_panel_related', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('requires_video_proof', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0, 'signed' => false])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true]);
        $this->timestamps($symptoms)
            ->addIndex(['code'], ['unique' => true, 'name' => 'uq_symptoms_code'])
            ->addForeignKey('product_category_id', 'product_categories', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_symptoms_category',
            ])
            ->create();

        $resolutions = $this->newTable('resolutions');
        $resolutions
            ->addColumn('code', 'string', ['limit' => 48, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            // Some outcomes must consume a part; the closure form enforces it.
            ->addColumn('requires_spare', 'boolean', ['null' => false, 'default' => false])
            // "No fault found" and "customer cancelled" are closures that
            // earn nothing, and the desk should be told so before the
            // technician leaves site rather than at invoice time.
            ->addColumn('is_billable', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0, 'signed' => false])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true]);
        $this->timestamps($resolutions)
            ->addIndex(['code'], ['unique' => true, 'name' => 'uq_resolutions_code'])
            ->create();
    }

    /**
     * Hold reasons were a free-text comment on ticket_holds. They decide
     * whether a pause is defensible in an SLA dispute, so they need to be
     * a controlled list with an explicit flag for which ones actually stop
     * the clock.
     */
    private function createHoldReasons(): void
    {
        $reasons = $this->newTable('hold_reasons');
        $reasons
            ->addColumn('code', 'string', ['limit' => 48, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            // The crucial column. A delay waiting on the customer stops the
            // clock; a delay because we were short-staffed does not, and
            // pretending otherwise is how an agreement gets terminated.
            ->addColumn('pauses_sla', 'boolean', ['null' => false, 'default' => true])
            // Clause 11: a pause the vendor was not told about by email will
            // not survive a dispute.
            ->addColumn('requires_vendor_notice', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0, 'signed' => false])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true]);
        $this->timestamps($reasons)
            ->addIndex(['code'], ['unique' => true, 'name' => 'uq_hold_reasons_code'])
            ->create();
    }

    /**
     * Vendor vocabulary -> our vocabulary.
     *
     * Dianora's "Complaint Type: Service" has to become our `service` job
     * type on import. The next vendor will use a different word for the
     * same thing, and that must be a row here rather than a branch in an
     * importer.
     */
    private function createVendorAliases(): void
    {
        $aliases = $this->newTable('vendor_job_type_aliases');
        $aliases
            ->addColumn('vendor_id', 'biginteger', self::KEY)
            ->addColumn('job_type_id', 'biginteger', self::KEY)
            ->addColumn('vendor_label', 'string', [
                'limit' => 96,
                'null' => false,
                'comment' => 'the vendor\'s own wording, e.g. "Service"',
            ])
            // Some vendors encode warranty status in the complaint type
            // itself ("Out of Warranty Repair"), so an alias may carry it.
            ->addColumn('warranty_scope', 'string', [
                'limit' => 24,
                'null' => true,
                'comment' => 'null = take scope from the ticket',
            ])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true]);
        $this->timestamps($aliases)
            ->addIndex(['vendor_id', 'vendor_label'], ['unique' => true, 'name' => 'uq_vendor_aliases_label'])
            ->addForeignKey('vendor_id', 'vendors', 'id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_vendor_aliases_vendor',
            ])
            ->addForeignKey('job_type_id', 'job_types', 'id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_vendor_aliases_job_type',
            ])
            ->create();
    }

    private function extendCustomers(): void
    {
        $this->table('customers')
            ->addColumn('state_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('district_id', 'biginteger', self::NULLABLE_KEY)
            // "Place: Puduppadi-673586" — the village or locality, which in
            // rural Kerala is what a technician actually navigates by.
            ->addColumn('place', 'string', ['limit' => 128, 'null' => true])
            ->addIndex(['district_id'], ['name' => 'idx_customers_district'])
            ->addForeignKey('state_id', 'states', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_customers_state',
            ])
            ->addForeignKey('district_id', 'districts', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_customers_district',
            ])
            ->update();
    }

    private function extendProducts(): void
    {
        $this->table('products')
            ->addColumn('brand_id', 'biginteger', self::NULLABLE_KEY)
            ->addIndex(['brand_id'], ['name' => 'idx_products_brand'])
            ->addForeignKey('brand_id', 'brands', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_products_brand',
            ])
            ->update();
    }

    private function extendTickets(): void
    {
        $this->table('tickets')
            ->addColumn('brand_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('symptom_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('resolution_id', 'biginteger', self::NULLABLE_KEY)
            // The vendor names the branch they assigned in their own words
            // ("GRAND SERVICE THARASSERY"). Kept verbatim next to our own
            // service_center_id so a mismatch is visible instead of lost.
            ->addColumn('vendor_branch_label', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('vendor_complaint_type', 'string', [
                'limit' => 96,
                'null' => true,
                'comment' => 'the vendor\'s wording before alias mapping',
            ])
            // Some symptoms cannot be assessed without a video from the
            // customer — the sample ticket literally says "NEED SYMPTOM
            // VEDIO DOC.. PENDING", and that wait is a legitimate SLA hold.
            ->addColumn('video_proof_required', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('video_proof_received_at', 'datetime', ['null' => true])
            // The untouched vendor record. Import mapping will get things
            // wrong occasionally, and without the original there is nothing
            // to re-derive from.
            ->addColumn('vendor_payload', 'json', ['null' => true])
            ->addIndex(['symptom_id'], ['name' => 'idx_tickets_symptom'])
            ->addIndex(['resolution_id'], ['name' => 'idx_tickets_resolution'])
            ->addIndex(['brand_id'], ['name' => 'idx_tickets_brand'])
            ->addForeignKey('brand_id', 'brands', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_tickets_brand',
            ])
            ->addForeignKey('symptom_id', 'symptoms', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_tickets_symptom',
            ])
            ->addForeignKey('resolution_id', 'resolutions', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_tickets_resolution',
            ])
            ->update();
    }

    private function extendTicketHolds(): void
    {
        $this->table('ticket_holds')
            ->addColumn('hold_reason_id', 'biginteger', self::NULLABLE_KEY)
            ->addIndex(['hold_reason_id'], ['name' => 'idx_ticket_holds_reason'])
            ->addForeignKey('hold_reason_id', 'hold_reasons', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_holds_reason',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('ticket_holds')
            ->dropForeignKey('hold_reason_id')
            ->removeIndexByName('idx_ticket_holds_reason')
            ->removeColumn('hold_reason_id')
            ->update();

        $this->table('tickets')
            ->dropForeignKey('brand_id')
            ->dropForeignKey('symptom_id')
            ->dropForeignKey('resolution_id')
            ->removeIndexByName('idx_tickets_symptom')
            ->removeIndexByName('idx_tickets_resolution')
            ->removeIndexByName('idx_tickets_brand')
            ->removeColumn('brand_id')
            ->removeColumn('symptom_id')
            ->removeColumn('resolution_id')
            ->removeColumn('vendor_branch_label')
            ->removeColumn('vendor_complaint_type')
            ->removeColumn('video_proof_required')
            ->removeColumn('video_proof_received_at')
            ->removeColumn('vendor_payload')
            ->update();

        $this->table('products')
            ->dropForeignKey('brand_id')
            ->removeIndexByName('idx_products_brand')
            ->removeColumn('brand_id')
            ->update();

        $this->table('customers')
            ->dropForeignKey('state_id')
            ->dropForeignKey('district_id')
            ->removeIndexByName('idx_customers_district')
            ->removeColumn('state_id')
            ->removeColumn('district_id')
            ->removeColumn('place')
            ->update();

        $this->table('vendor_job_type_aliases')->drop()->save();
        $this->table('hold_reasons')->drop()->save();
        $this->table('resolutions')->drop()->save();
        $this->table('symptoms')->drop()->save();
        $this->table('brands')->drop()->save();
        $this->table('districts')->drop()->save();
        $this->table('states')->drop()->save();
    }
}
