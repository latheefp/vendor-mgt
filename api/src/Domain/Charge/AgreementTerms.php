<?php
declare(strict_types=1);

namespace App\Domain\Charge;

use App\Domain\Money;
use App\Domain\Sla\SlaWindows;

/**
 * The commercial terms in force for one ticket, lifted out of the vendor
 * agreement and frozen.
 *
 * Frozen is the operative word. A ticket worked in March is priced under
 * March's terms even if the royalty percentage changes in April, because
 * the invoice we already sent has to stay defensible.
 */
final readonly class AgreementTerms
{
    public function __construct(
        public int $id,
        /** Clause 8: vendor's cut of out-of-warranty service collected. */
        public string $oowRoyaltyPct = '10.00',
        /** Clause 6: permitted margin band on spares billed to the customer. */
        public string $spareMarginMinPct = '10.00',
        public string $spareMarginMaxPct = '15.00',
        /** Important note 5: first 15km from the service centre are unpaid. */
        public float $travelFreeKm = 15.0,
        public ?Money $travelRatePerKm = null,
        public ?SlaWindows $slaWindows = null,
        /** Clause 4: maximum outstanding exposure permitted. */
        public ?Money $creditLimit = null,
        /** Clause 7: window in which a repeat complaint is our liability. */
        public int $repeatComplaintWindowDays = 90,
        /** Clause 9: defective spares must go back within this many days. */
        public int $defectiveReturnDays = 7,
        /** Clause 10: spares held longer than this are treated as billed to us. */
        public int $spareBillingDays = 30,
        /**
         * Company policy on what reaches the field.
         *
         * An SLA incentive originates in this company's rate card, so how
         * much of it is passed on — and how much of a deduction is
         * recovered — is set once here rather than renegotiated with every
         * technician. A technician on individual terms still overrides it.
         */
        public string $technicianBonusSharePct = '100.00',
        public string $technicianPenaltyRecoveryPct = '0.00',
        /**
         * Clause 8 charges royalty on out-of-warranty service. Whether that
         * extends to the margin on spares sold at the same visit is a
         * per-company reading of the clause, so it is not assumed.
         */
        public bool $royaltyAppliesToSpares = false,
    ) {
    }

    public function travelRate(): Money
    {
        return $this->travelRatePerKm ?? Money::fromRupees(3);
    }

    public function windows(): SlaWindows
    {
        return $this->slaWindows ?? new SlaWindows();
    }

    public function creditLimitOrDefault(): Money
    {
        return $this->creditLimit ?? Money::fromRupees(25_000);
    }

    /**
     * Is a proposed spare margin inside the negotiated band?
     *
     * The desk chooses a figure within 10-15%; anything outside it is a
     * breach of clause 6 rather than a pricing preference.
     */
    public function isSpareMarginPermitted(string $marginPct): bool
    {
        $bp = Money::percentToBasisPoints($marginPct);

        return $bp >= Money::percentToBasisPoints($this->spareMarginMinPct)
            && $bp <= Money::percentToBasisPoints($this->spareMarginMaxPct);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'agreement_id' => $this->id,
            'oow_royalty_pct' => $this->oowRoyaltyPct,
            'spare_margin_min_pct' => $this->spareMarginMinPct,
            'spare_margin_max_pct' => $this->spareMarginMaxPct,
            'travel_free_km' => $this->travelFreeKm,
            'travel_rate_per_km_paise' => $this->travelRate()->paise,
            'credit_limit_paise' => $this->creditLimitOrDefault()->paise,
            'sla_windows' => $this->windows()->toArray(),
            'repeat_complaint_window_days' => $this->repeatComplaintWindowDays,
            'defective_return_days' => $this->defectiveReturnDays,
            'spare_billing_days' => $this->spareBillingDays,
            'technician_bonus_share_pct' => $this->technicianBonusSharePct,
            'technician_penalty_recovery_pct' => $this->technicianPenaltyRecoveryPct,
            'royalty_applies_to_spares' => $this->royaltyAppliesToSpares,
        ];
    }
}
