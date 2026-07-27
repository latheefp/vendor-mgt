<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain;

use App\Domain\Charge\AgreementTerms;
use App\Domain\Enum\Payer;
use App\Domain\Enum\SlaComparator;
use App\Domain\Enum\SlaKind;
use App\Domain\Enum\SlaMetric;
use App\Domain\Enum\WarrantyScope;
use App\Domain\Money;
use App\Domain\Rate\RateCardItemData;
use App\Domain\Sla\SlaRuleData;
use App\Domain\Sla\SlaWindows;

/**
 * The Dianora Electronics rate card, transcribed from the signed
 * agreement (Service Agreement 2.pdf).
 *
 * The tests assert against these exact figures on purpose. If someone
 * changes the resolver and an installation stops costing Rs.350, a test
 * fails with the real number in the message rather than with an abstract
 * fixture value.
 *
 * Transcribed verbatim:
 *
 *   INSTALLATION           24"-43"  Rs.350   (+Rs.50 if closed within 24h)
 *                          45"-65"  Rs.500   (+Rs.50 if closed within 24h)
 *                          Demo / site inspection  Rs.250
 *                          24"-65" installed after 48h deducts Rs.50
 *
 *   SERVICE IN WARRANTY    24"-43"  Rs.400
 *                          45"-85"  Rs.500
 *                          TV set exchange or delivery       Rs.700
 *                          Open cell & backlight replacement Rs.1000
 *                          +Rs.75 closed within 48h
 *                          +Rs.50 closed after 48h within 72h
 *
 *   SERVICE OUT OF WARRANTY 24"-43"  Rs.500
 *                           45"-55"  Rs.1000
 *                           65"-85"  Rs.1500
 *                           Open cell & backlight            Rs.1500
 */
final class DianoraRateCard
{
    public const JOB_INSTALLATION = 'installation';
    public const JOB_DEMO = 'demo_inspection';
    public const JOB_SERVICE = 'service';
    public const JOB_EXCHANGE = 'exchange_delivery';
    public const JOB_PANEL = 'panel_backlight';

    /**
     * @return list<RateCardItemData>
     */
    public static function items(): array
    {
        return [
            // ---- installation (billed to the vendor) -----------------
            new RateCardItemData(
                id: 1,
                jobTypeCode: self::JOB_INSTALLATION,
                warrantyScope: WarrantyScope::NotApplicable,
                amount: Money::fromRupees(350),
                payer: Payer::Vendor,
                label: 'Installation 24"-43"',
                sizeMinInch: 24,
                sizeMaxInch: 43,
            ),
            new RateCardItemData(
                id: 2,
                jobTypeCode: self::JOB_INSTALLATION,
                warrantyScope: WarrantyScope::NotApplicable,
                amount: Money::fromRupees(500),
                payer: Payer::Vendor,
                label: 'Installation 45"-65"',
                sizeMinInch: 45,
                sizeMaxInch: 65,
            ),
            new RateCardItemData(
                id: 3,
                jobTypeCode: self::JOB_DEMO,
                warrantyScope: WarrantyScope::NotApplicable,
                amount: Money::fromRupees(250),
                payer: Payer::Vendor,
                label: 'Demo / site inspection',
            ),

            // ---- service in warranty (billed to the vendor) ----------
            new RateCardItemData(
                id: 4,
                jobTypeCode: self::JOB_SERVICE,
                warrantyScope: WarrantyScope::InWarranty,
                amount: Money::fromRupees(400),
                payer: Payer::Vendor,
                label: 'Service in warranty 24"-43"',
                sizeMinInch: 24,
                sizeMaxInch: 43,
            ),
            new RateCardItemData(
                id: 5,
                jobTypeCode: self::JOB_SERVICE,
                warrantyScope: WarrantyScope::InWarranty,
                amount: Money::fromRupees(500),
                payer: Payer::Vendor,
                label: 'Service in warranty 45"-85"',
                sizeMinInch: 45,
                sizeMaxInch: 85,
            ),
            new RateCardItemData(
                id: 6,
                jobTypeCode: self::JOB_EXCHANGE,
                warrantyScope: WarrantyScope::InWarranty,
                amount: Money::fromRupees(700),
                payer: Payer::Vendor,
                label: 'TV set exchange or delivery',
            ),
            new RateCardItemData(
                id: 7,
                jobTypeCode: self::JOB_PANEL,
                warrantyScope: WarrantyScope::InWarranty,
                amount: Money::fromRupees(1000),
                payer: Payer::Vendor,
                label: 'Open cell & backlight replacement and service',
            ),

            // ---- service out of warranty (collected from customer) ---
            new RateCardItemData(
                id: 8,
                jobTypeCode: self::JOB_SERVICE,
                warrantyScope: WarrantyScope::OutOfWarranty,
                amount: Money::fromRupees(500),
                payer: Payer::Customer,
                label: 'Service out of warranty 24"-43"',
                sizeMinInch: 24,
                sizeMaxInch: 43,
            ),
            new RateCardItemData(
                id: 9,
                jobTypeCode: self::JOB_SERVICE,
                warrantyScope: WarrantyScope::OutOfWarranty,
                amount: Money::fromRupees(1000),
                payer: Payer::Customer,
                label: 'Service out of warranty 45"-55"',
                sizeMinInch: 45,
                sizeMaxInch: 55,
            ),
            new RateCardItemData(
                id: 10,
                jobTypeCode: self::JOB_SERVICE,
                warrantyScope: WarrantyScope::OutOfWarranty,
                amount: Money::fromRupees(1500),
                payer: Payer::Customer,
                label: 'Service out of warranty 65"-85"',
                sizeMinInch: 65,
                sizeMaxInch: 85,
            ),
            new RateCardItemData(
                id: 11,
                jobTypeCode: self::JOB_PANEL,
                warrantyScope: WarrantyScope::OutOfWarranty,
                amount: Money::fromRupees(1500),
                payer: Payer::Customer,
                label: 'Open cell & backlight replacement and service (out of warranty)',
            ),
        ];
    }

    /**
     * The four SLA rules on the card.
     *
     * Priorities matter: within hours_to_close the tighter band is
     * evaluated first and, being non-stackable, stops the walk. That is
     * what stops the 48h and 72h service incentives both paying out.
     *
     * @return list<SlaRuleData>
     */
    public static function slaRules(): array
    {
        return [
            new SlaRuleData(
                id: 1,
                code: 'install_close_24',
                label: 'Installation closed within 24 hours',
                kind: SlaKind::Bonus,
                metric: SlaMetric::HoursToClose,
                comparator: SlaComparator::Lte,
                amount: Money::fromRupees(50),
                thresholdToHours: 24,
                appliesToJobTypes: [self::JOB_INSTALLATION],
                priority: 10,
            ),
            new SlaRuleData(
                id: 2,
                code: 'install_late_48',
                label: 'Installation completed after 48 hours',
                kind: SlaKind::Penalty,
                metric: SlaMetric::HoursToClose,
                comparator: SlaComparator::Gt,
                amount: Money::fromRupees(50),
                thresholdFromHours: 48,
                appliesToJobTypes: [self::JOB_INSTALLATION],
                priority: 20,
            ),
            new SlaRuleData(
                id: 3,
                code: 'service_close_48',
                label: 'Complaint closed within 48 hours',
                kind: SlaKind::Bonus,
                metric: SlaMetric::HoursToClose,
                comparator: SlaComparator::Lte,
                amount: Money::fromRupees(75),
                thresholdToHours: 48,
                appliesToJobTypes: [self::JOB_SERVICE],
                warrantyScope: WarrantyScope::InWarranty,
                priority: 10,
            ),
            new SlaRuleData(
                id: 4,
                code: 'service_close_72',
                label: 'Complaint closed after 48 and within 72 hours',
                kind: SlaKind::Bonus,
                metric: SlaMetric::HoursToClose,
                comparator: SlaComparator::Between,
                amount: Money::fromRupees(50),
                thresholdFromHours: 48,
                thresholdToHours: 72,
                appliesToJobTypes: [self::JOB_SERVICE],
                warrantyScope: WarrantyScope::InWarranty,
                priority: 20,
            ),
        ];
    }

    /**
     * The commercial terms from the same agreement.
     */
    public static function terms(): AgreementTerms
    {
        return new AgreementTerms(
            id: 1,
            oowRoyaltyPct: '10.00',
            spareMarginMinPct: '10.00',
            spareMarginMaxPct: '15.00',
            travelFreeKm: 15.0,
            travelRatePerKm: Money::fromRupees(3),
            slaWindows: new SlaWindows(contactHours: 2, visitHours: 48, closeHours: 48),
            creditLimit: Money::fromRupees(25_000),
            repeatComplaintWindowDays: 90,
            defectiveReturnDays: 7,
            spareBillingDays: 30,
        );
    }
}
