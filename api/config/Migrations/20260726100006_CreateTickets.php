<?php
declare(strict_types=1);

use App\Database\Migration\AppMigration;

/**
 * Customers, tickets, the immutable event log, SLA holds and evidence.
 *
 * Design notes worth keeping in mind when extending this:
 *
 * - `tickets` freezes `vendor_agreement_id` and `rate_card_id`. A ticket
 *   is priced under the terms in force when it was worked, not the terms
 *   in force when someone happens to open the invoice.
 *
 * - `ticket_events` is append-only and has no `modified` column. It is
 *   the evidence trail for every SLA claim and every disputed rupee, and
 *   the application must never issue an UPDATE against it.
 *
 * - `sla_paused_minutes` exists because "close within 48 hrs" is not
 *   achievable when the customer is travelling or a spare is on order.
 *   Without pause accounting we absorb penalties we did not cause. Every
 *   pause is a `ticket_holds` row, and each one emails the vendor —
 *   clause 11 makes that email the only record that counts.
 *
 * - closure timestamps are set by the SERVER, never accepted from the
 *   client. The card pays a bonus for closing inside 24h, which is a
 *   standing incentive to backdate.
 */
class CreateTickets extends AppMigration
{
    public function up(): void
    {
        $customers = $this->newTable('customers');
        $customers
            ->addColumn('name', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('phone', 'string', ['limit' => 24, 'null' => false])
            ->addColumn('alt_phone', 'string', ['limit' => 24, 'null' => true])
            ->addColumn('email', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('address_line1', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('address_line2', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('landmark', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('city', 'string', ['limit' => 96, 'null' => true])
            ->addColumn('district', 'string', ['limit' => 96, 'null' => true])
            ->addColumn('state', 'string', ['limit' => 96, 'null' => true, 'default' => 'Kerala'])
            ->addColumn('pincode', 'string', ['limit' => 12, 'null' => true])
            ->addColumn('latitude', 'decimal', ['precision' => 10, 'scale' => 7, 'null' => true])
            ->addColumn('longitude', 'decimal', ['precision' => 10, 'scale' => 7, 'null' => true])
            ->addColumn('source', 'string', [
                'limit' => 24,
                'null' => false,
                'default' => 'desk',
                'comment' => 'vendor_email|vendor_api|csv_import|desk|phone',
            ])
            ->addColumn('notes', 'text', ['null' => true]);
        $this->timestamps($customers)
            // Repeat-complaint detection (clause 7) starts from the phone
            // number, so it has to be fast.
            ->addIndex(['phone'], ['name' => 'idx_customers_phone'])
            ->addIndex(['pincode'], ['name' => 'idx_customers_pincode'])
            ->create();

        // ---------------------------------------------------------------
        // tickets
        // ---------------------------------------------------------------
        $tickets = $this->newTable('tickets');
        $tickets
            ->addColumn('ticket_no', 'string', [
                'limit' => 32,
                'null' => false,
                'comment' => 'our human reference, e.g. GVS-2026-000123',
            ])
            ->addColumn('vendor_id', 'biginteger', self::KEY)
            // The vendor's own reference. Unique per vendor so re-importing
            // the same assignment mail cannot create a duplicate job.
            ->addColumn('vendor_ticket_ref', 'string', ['limit' => 64, 'null' => true])
            // Frozen commercial context.
            ->addColumn('vendor_agreement_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('rate_card_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('service_center_id', 'biginteger', self::KEY)
            ->addColumn('customer_id', 'biginteger', self::KEY)

            // ---- what we are working on -----------------------------
            ->addColumn('product_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('product_category_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('model_no', 'string', ['limit' => 96, 'null' => true])
            ->addColumn('serial_no', 'string', ['limit' => 96, 'null' => true])
            // Copied onto the ticket rather than read through the product,
            // because size drives the rate and the catalogue can be edited.
            ->addColumn('size_inch', 'decimal', ['precision' => 6, 'scale' => 2, 'null' => true])
            ->addColumn('purchase_date', 'date', ['null' => true])
            ->addColumn('warranty_scope', 'string', [
                'limit' => 24,
                'null' => false,
                'default' => 'unknown',
                'comment' => 'in_warranty|out_of_warranty|not_applicable|unknown',
            ])
            ->addColumn('job_type_id', 'biginteger', self::KEY)

            // ---- workflow -------------------------------------------
            ->addColumn('status', 'string', [
                'limit' => 32,
                'null' => false,
                'default' => 'new',
                'comment' => 'new|assigned|contacted|scheduled|visited|in_progress|'
                    . 'on_hold|awaiting_parts|closed|cancelled|rejected|reopened',
            ])
            ->addColumn('priority', 'string', [
                'limit' => 16,
                'null' => false,
                'default' => 'normal',
                'comment' => 'low|normal|high|urgent',
            ])
            ->addColumn('reported_issue', 'text', ['null' => true])
            ->addColumn('diagnosis', 'text', ['null' => true])
            ->addColumn('closure_notes', 'text', ['null' => true])

            // ---- assignment -----------------------------------------
            ->addColumn('assigned_technician_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('assigned_at', 'datetime', ['null' => true])
            ->addColumn('assigned_by_user_id', 'biginteger', self::NULLABLE_KEY)

            // ---- SLA clock ------------------------------------------
            // received_at is when the vendor handed us the job. Every SLA
            // window is measured from here, NOT from row creation, because
            // an import can lag the assignment by hours.
            ->addColumn('received_at', 'datetime', ['null' => false])
            ->addColumn('first_contact_at', 'datetime', ['null' => true])
            ->addColumn('contact_due_at', 'datetime', ['null' => true])
            ->addColumn('visit_due_at', 'datetime', ['null' => true])
            ->addColumn('close_due_at', 'datetime', ['null' => true])
            ->addColumn('visited_at', 'datetime', ['null' => true])
            ->addColumn('closed_at', 'datetime', ['null' => true])
            ->addColumn('sla_paused_minutes', 'integer', [
                'null' => false,
                'default' => 0,
                'signed' => false,
                'comment' => 'accumulated from ticket_holds',
            ])
            // Outcome of each window, decided once at closure and then left
            // alone so reports stay stable.
            ->addColumn('contact_sla_met', 'boolean', ['null' => true])
            ->addColumn('visit_sla_met', 'boolean', ['null' => true])
            ->addColumn('close_sla_met', 'boolean', ['null' => true])

            // ---- proof of visit -------------------------------------
            ->addColumn('checkin_at', 'datetime', ['null' => true])
            ->addColumn('checkin_latitude', 'decimal', ['precision' => 10, 'scale' => 7, 'null' => true])
            ->addColumn('checkin_longitude', 'decimal', ['precision' => 10, 'scale' => 7, 'null' => true])
            ->addColumn('checkin_accuracy_m', 'integer', ['null' => true, 'signed' => false])
            // Distance between the check-in fix and the customer's address.
            // Large values are not blocked, they are flagged for the desk.
            ->addColumn('checkin_distance_m', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('checkout_at', 'datetime', ['null' => true])
            // Distance from the service centre, for travel reimbursement.
            ->addColumn('travel_km', 'decimal', [
                'precision' => 8,
                'scale' => 2,
                'null' => true,
                'comment' => 'one way, from the designated service centre',
            ])

            // ---- customer sign-off ----------------------------------
            ->addColumn('closure_otp_hash', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('closure_otp_sent_at', 'datetime', ['null' => true])
            ->addColumn('closure_otp_attempts', 'integer', ['null' => false, 'default' => 0, 'signed' => false])
            ->addColumn('closure_otp_verified_at', 'datetime', ['null' => true])

            // ---- repeat complaints (clause 7) -----------------------
            ->addColumn('parent_ticket_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('is_repeat', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('reopened_count', 'integer', ['null' => false, 'default' => 0, 'signed' => false])

            // ---- vendor acceptance ----------------------------------
            ->addColumn('vendor_submitted_at', 'datetime', ['null' => true])
            ->addColumn('vendor_approved_at', 'datetime', ['null' => true])
            ->addColumn('vendor_rejected_at', 'datetime', ['null' => true])
            ->addColumn('vendor_rejection_reason', 'string', ['limit' => 255, 'null' => true])

            // ---- money lifecycle ------------------------------------
            ->addColumn('charges_computed_at', 'datetime', ['null' => true])
            // Once frozen, charge lines are immutable and any correction is
            // a new adjustment line rather than an edit.
            ->addColumn('charges_frozen_at', 'datetime', ['null' => true])

            ->addColumn('cancelled_at', 'datetime', ['null' => true])
            ->addColumn('cancellation_reason', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('source', 'string', [
                'limit' => 24,
                'null' => false,
                'default' => 'desk',
                'comment' => 'vendor_email|vendor_api|csv_import|desk|phone',
            ])
            ->addColumn('created_by_user_id', 'biginteger', self::NULLABLE_KEY);

        $this->timestamps($tickets)
            ->addIndex(['ticket_no'], ['unique' => true, 'name' => 'uq_tickets_no'])
            // The import dedupe key.
            ->addIndex(['vendor_id', 'vendor_ticket_ref'], ['unique' => true, 'name' => 'uq_tickets_vendor_ref'])
            // Dispatch board: open work by urgency.
            ->addIndex(['status', 'close_due_at'], ['name' => 'idx_tickets_board'])
            ->addIndex(['assigned_technician_id', 'status'], ['name' => 'idx_tickets_technician'])
            ->addIndex(['service_center_id', 'status'], ['name' => 'idx_tickets_center'])
            ->addIndex(['customer_id', 'closed_at'], ['name' => 'idx_tickets_customer_history'])
            ->addIndex(['vendor_id', 'closed_at'], ['name' => 'idx_tickets_vendor_period'])
            ->addIndex(['serial_no'], ['name' => 'idx_tickets_serial'])
            ->addIndex(['charges_frozen_at'], ['name' => 'idx_tickets_frozen'])
            ->addForeignKey('vendor_id', 'vendors', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_tickets_vendor',
            ])
            ->addForeignKey('vendor_agreement_id', 'vendor_agreements', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_tickets_agreement',
            ])
            ->addForeignKey('rate_card_id', 'rate_cards', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_tickets_rate_card',
            ])
            ->addForeignKey('service_center_id', 'service_centers', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_tickets_center',
            ])
            ->addForeignKey('customer_id', 'customers', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_tickets_customer',
            ])
            ->addForeignKey('product_id', 'products', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_tickets_product',
            ])
            ->addForeignKey('product_category_id', 'product_categories', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_tickets_category',
            ])
            ->addForeignKey('job_type_id', 'job_types', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_tickets_job_type',
            ])
            ->addForeignKey('assigned_technician_id', 'technicians', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_tickets_technician',
            ])
            ->addForeignKey('assigned_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_tickets_assigner',
            ])
            ->addForeignKey('parent_ticket_id', 'tickets', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_tickets_parent',
            ])
            ->addForeignKey('created_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_tickets_creator',
            ])
            ->create();

        // ---------------------------------------------------------------
        // ticket_events — append only, never updated
        // ---------------------------------------------------------------
        $events = $this->newTable('ticket_events');
        $events
            ->addColumn('ticket_id', 'biginteger', self::KEY)
            ->addColumn('event_type', 'string', [
                'limit' => 48,
                'null' => false,
                'comment' => 'created|assigned|contacted|checked_in|hold_started|'
                    . 'hold_ended|photo_uploaded|otp_sent|otp_verified|closed|'
                    . 'charges_computed|charges_frozen|reopened|vendor_notified',
            ])
            ->addColumn('from_status', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('to_status', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('actor_user_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('actor_role', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            // Full input payload. Verbose on purpose: this is what we read
            // back when a vendor queries an SLA claim six months later.
            ->addColumn('payload', 'json', ['null' => true])
            // Server clock, always.
            ->addColumn('occurred_at', 'datetime', ['null' => false])
            ->addColumn('latitude', 'decimal', ['precision' => 10, 'scale' => 7, 'null' => true])
            ->addColumn('longitude', 'decimal', ['precision' => 10, 'scale' => 7, 'null' => true])
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => true])
            // No `modified`: this table is immutable.
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['ticket_id', 'occurred_at'], ['name' => 'idx_ticket_events_timeline'])
            ->addIndex(['event_type', 'occurred_at'], ['name' => 'idx_ticket_events_type'])
            ->addIndex(['actor_user_id'], ['name' => 'idx_ticket_events_actor'])
            ->addForeignKey('ticket_id', 'tickets', 'id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_events_ticket',
            ])
            ->addForeignKey('actor_user_id', 'users', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_events_actor',
            ])
            ->create();

        // ---------------------------------------------------------------
        // ticket_holds — the SLA pause ledger
        // ---------------------------------------------------------------
        $holds = $this->newTable('ticket_holds');
        $holds
            ->addColumn('ticket_id', 'biginteger', self::KEY)
            ->addColumn('reason_code', 'string', [
                'limit' => 48,
                'null' => false,
                'comment' => 'customer_unavailable|customer_postponed|address_wrong|'
                    . 'spare_awaited|access_denied|vendor_approval_pending|other',
            ])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('started_at', 'datetime', ['null' => false])
            ->addColumn('ended_at', 'datetime', ['null' => true])
            ->addColumn('paused_minutes', 'integer', [
                'null' => true,
                'signed' => false,
                'comment' => 'computed when the hold ends',
            ])
            ->addColumn('started_by_user_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('ended_by_user_id', 'biginteger', self::NULLABLE_KEY)
            // Clause 11 again: unless the vendor was told by email, the pause
            // will not survive a dispute. Message-ID is our proof of service.
            ->addColumn('vendor_notified_at', 'datetime', ['null' => true])
            ->addColumn('vendor_notification_message_id', 'string', ['limit' => 190, 'null' => true]);
        $this->timestamps($holds)
            ->addIndex(['ticket_id', 'started_at'], ['name' => 'idx_ticket_holds_ticket'])
            // Finding holds nobody closed.
            ->addIndex(['ended_at'], ['name' => 'idx_ticket_holds_open'])
            ->addForeignKey('ticket_id', 'tickets', 'id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_holds_ticket',
            ])
            ->addForeignKey('started_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_holds_starter',
            ])
            ->addForeignKey('ended_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_holds_ender',
            ])
            ->create();

        // ---------------------------------------------------------------
        // ticket_attachments — evidence
        // ---------------------------------------------------------------
        $files = $this->newTable('ticket_attachments');
        $files
            ->addColumn('ticket_id', 'biginteger', self::KEY)
            ->addColumn('kind', 'string', [
                'limit' => 32,
                'null' => false,
                'comment' => 'serial_plate|before|after|signature|spare|invoice|other',
            ])
            ->addColumn('storage_disk', 'string', ['limit' => 24, 'null' => false, 'default' => 'local'])
            ->addColumn('storage_path', 'string', ['limit' => 512, 'null' => false])
            ->addColumn('original_name', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('mime_type', 'string', ['limit' => 96, 'null' => true])
            ->addColumn('size_bytes', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('width', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('height', 'integer', ['null' => true, 'signed' => false])
            // Detects the same photo being submitted for two different jobs.
            ->addColumn('sha256', 'string', ['limit' => 64, 'null' => true])
            // We cannot stop a technician picking an old photo from the
            // gallery in a mobile browser, but we can compare the EXIF
            // capture time against the check-in window and flag mismatches.
            ->addColumn('exif_taken_at', 'datetime', ['null' => true])
            ->addColumn('captured_latitude', 'decimal', ['precision' => 10, 'scale' => 7, 'null' => true])
            ->addColumn('captured_longitude', 'decimal', ['precision' => 10, 'scale' => 7, 'null' => true])
            ->addColumn('uploaded_by_user_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('uploaded_at', 'datetime', ['null' => false])
            ->addColumn('is_verified', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('verification_note', 'string', ['limit' => 255, 'null' => true]);
        $this->timestamps($files)
            ->addIndex(['ticket_id', 'kind'], ['name' => 'idx_ticket_attachments_ticket'])
            ->addIndex(['sha256'], ['name' => 'idx_ticket_attachments_hash'])
            ->addForeignKey('ticket_id', 'tickets', 'id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_attachments_ticket',
            ])
            ->addForeignKey('uploaded_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_ticket_attachments_uploader',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('ticket_attachments')->drop()->save();
        $this->table('ticket_holds')->drop()->save();
        $this->table('ticket_events')->drop()->save();
        $this->table('tickets')->drop()->save();
        $this->table('customers')->drop()->save();
    }
}
