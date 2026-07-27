<?php
declare(strict_types=1);

namespace App\Domain\Company;

/**
 * Every per-company setting the application knows about.
 *
 * This is the boundary between "a setting" and "a column". Anything with
 * commercial weight — a rate, a royalty percentage, an SLA window — is a
 * column on `rate_card_items` or `vendor_agreements`, because it is
 * versioned, referenced by frozen charge lines and has to be explainable
 * against a signed document years later. What lands here is the rest: the
 * operational and presentational dials that differ per company but never
 * appear in an invoice dispute.
 *
 * The catalogue is code rather than a table on purpose. A setting only
 * matters because something reads it, and that something is code; a key
 * with no reader is a bug, and keeping the list next to the readers is
 * what makes an orphaned key visible in review.
 */
final class SettingCatalog
{
    public const TICKET_PREFIX = 'ticket.number_prefix';
    public const TICKET_SEQUENCE_WIDTH = 'ticket.sequence_width';
    public const TICKET_REQUIRE_SERIAL = 'ticket.require_serial_no';
    public const TICKET_REQUIRE_BILL_DATE = 'ticket.require_bill_date';

    public const CLOSURE_REQUIRE_PHOTO = 'closure.require_photo';
    public const CLOSURE_REQUIRE_SIGNATURE = 'closure.require_customer_signature';
    public const CLOSURE_REQUIRE_OTP = 'closure.require_customer_otp';

    public const IMPORT_UNKNOWN_JOB_TYPE = 'import.on_unknown_job_type';
    public const IMPORT_UNKNOWN_DISTRICT = 'import.on_unknown_district';

    public const NOTICE_EMAIL_ON_HOLD = 'notice.email_vendor_on_hold';
    public const NOTICE_SLA_BREACH_HOURS_BEFORE = 'notice.sla_breach_warning_hours';

    public const BRAND_PRIMARY_COLOR = 'brand.primary_color';
    public const BRAND_PORTAL_NAME = 'brand.portal_name';

    public const LEDGER_CURRENCY = 'ledger.currency';
    public const CASH_DEPOSIT_DAYS = 'cash.deposit_within_days';

    /**
     * @var array<string, SettingDefinition>|null
     */
    private static ?array $definitions = null;

    /**
     * @return array<string, SettingDefinition>
     */
    public static function all(): array
    {
        return self::$definitions ??= self::build();
    }

    public static function find(string $key): ?SettingDefinition
    {
        return self::all()[$key] ?? null;
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $defaults = [];
        foreach (self::all() as $key => $definition) {
            $defaults[$key] = $definition->default;
        }

        return $defaults;
    }

    /**
     * @return array<string, SettingDefinition>
     */
    private static function build(): array
    {
        $definitions = [
            new SettingDefinition(
                key: self::TICKET_PREFIX,
                type: 'string',
                label: 'Ticket number prefix',
                default: 'TKT',
                // Dianora's own tickets read DN1407260024. Our numbers are
                // separate from theirs, but the desk reads both side by
                // side all day, so making ours recognisably per-company is
                // what stops the two being confused on a phone call.
                description: 'Leading letters on ticket numbers we generate for this company.',
                group: 'tickets',
            ),
            new SettingDefinition(
                key: self::TICKET_SEQUENCE_WIDTH,
                type: 'integer',
                label: 'Ticket number digits',
                default: 6,
                description: 'Zero-padded width of the sequence portion.',
                group: 'tickets',
            ),
            new SettingDefinition(
                key: self::TICKET_REQUIRE_SERIAL,
                type: 'boolean',
                label: 'Serial number required at intake',
                default: true,
                // Warranty status is decided from the serial and bill date.
                // A company that reimburses in-warranty work without them
                // exists; assuming so for everyone does not.
                description: 'Block intake when no unit serial number is supplied.',
                group: 'tickets',
            ),
            new SettingDefinition(
                key: self::TICKET_REQUIRE_BILL_DATE,
                type: 'boolean',
                label: 'Bill date required at intake',
                default: true,
                description: 'Block intake when no purchase date is supplied.',
                group: 'tickets',
            ),

            new SettingDefinition(
                key: self::CLOSURE_REQUIRE_PHOTO,
                type: 'boolean',
                label: 'Photo required at closure',
                // Off by default. The photo is genuinely worth having in a
                // dispute, but making it mandatory blocks every closure the
                // moment a camera is unavailable, and a desk that cannot
                // close tickets stops using the system rather than finding
                // a camera. Turn it on per company once the field app is
                // reliably capturing them.
                default: false,
                description: 'The technician must attach at least one photo before closing.',
                group: 'closure',
            ),
            new SettingDefinition(
                key: self::CLOSURE_REQUIRE_SIGNATURE,
                type: 'boolean',
                label: 'Customer signature required at closure',
                default: false,
                group: 'closure',
            ),
            new SettingDefinition(
                key: self::CLOSURE_REQUIRE_OTP,
                type: 'boolean',
                label: 'Customer OTP required at closure',
                default: false,
                description: 'Strongest closure proof; also the slowest in poor coverage.',
                group: 'closure',
            ),

            new SettingDefinition(
                key: self::IMPORT_UNKNOWN_JOB_TYPE,
                type: 'string',
                label: 'Unrecognised complaint type',
                default: 'queue',
                // An unmapped complaint type is either a new word from the
                // company or a typo, and which one it usually is differs by
                // company. Guessing wrong either floods a review queue or
                // silently misprices work.
                description: 'What to do when this company sends a complaint type we have no alias for.',
                group: 'import',
                allowed: ['queue', 'reject', 'default_to_service'],
            ),
            new SettingDefinition(
                key: self::IMPORT_UNKNOWN_DISTRICT,
                type: 'string',
                label: 'Unrecognised district',
                default: 'queue',
                description: 'What to do when a district name matches nothing in the master list.',
                group: 'import',
                allowed: ['queue', 'reject', 'accept_as_text'],
            ),

            new SettingDefinition(
                key: self::NOTICE_EMAIL_ON_HOLD,
                type: 'boolean',
                label: 'Email the company when a ticket goes on hold',
                default: true,
                // Clause 11: communications outside email "may not be
                // considered valid". A pause nobody was told about by email
                // is a pause that does not survive an SLA dispute.
                description: 'Required for a hold to be defensible under an email-only communications clause.',
                group: 'notices',
            ),
            new SettingDefinition(
                key: self::NOTICE_SLA_BREACH_HOURS_BEFORE,
                type: 'integer',
                label: 'SLA warning lead time (hours)',
                default: 4,
                description: 'How long before a window expires the desk is warned.',
                group: 'notices',
            ),

            new SettingDefinition(
                key: self::BRAND_PORTAL_NAME,
                type: 'string',
                label: 'Portal name',
                default: 'Service Desk',
                description: 'Shown in the header when working this company\'s tickets.',
                group: 'branding',
            ),
            new SettingDefinition(
                key: self::BRAND_PRIMARY_COLOR,
                type: 'string',
                label: 'Primary colour',
                default: '#1d4ed8',
                description: 'Hex colour used to tint the shell for this company.',
                group: 'branding',
            ),

            new SettingDefinition(
                key: self::LEDGER_CURRENCY,
                type: 'string',
                label: 'Ledger currency',
                default: 'INR',
                // Not editable: every amount in the system is stored as
                // paise, and changing the currency label without changing
                // the minor-unit assumption would silently misstate money.
                description: 'Fixed. Amounts are stored in minor units of this currency.',
                group: 'ledger',
                isEditable: false,
            ),
            new SettingDefinition(
                key: self::CASH_DEPOSIT_DAYS,
                type: 'integer',
                label: 'Cash deposit window (days)',
                default: 2,
                // Out-of-warranty work is collected in cash at the door.
                // How long a technician may hold it is a control, and a
                // company doing high cash volume will want it tighter.
                description: 'Days a technician may hold cash collected on this company\'s jobs.',
                group: 'ledger',
            ),
        ];

        $keyed = [];
        foreach ($definitions as $definition) {
            $keyed[$definition->key] = $definition;
        }

        return $keyed;
    }
}
