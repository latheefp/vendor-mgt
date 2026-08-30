<?php
declare(strict_types=1);

namespace App\Domain\Payout;

use App\Domain\Charge\AgreementTerms;
use App\Domain\Enum\PayoutModel;
use App\Domain\Money;

/**
 * What one technician is paid, in force on a given date.
 *
 * The two share percentages are the important dials and the reason this
 * is data rather than a constant:
 *
 *   bonusSharePct        how much of an SLA incentive reaches the person
 *                        who earned it. Set it to zero and nobody has any
 *                        reason to close a job inside 24 hours.
 *
 *   penaltyRecoveryPct   how much of an SLA deduction is recovered from
 *                        the person who caused it. Set it to zero and we
 *                        absorb the cost of avoidable delay; set it to 100
 *                        and a technician pays for a customer who was out.
 *
 * Neither has an obviously correct value, which is exactly why neither
 * belongs in code.
 *
 * Both are nullable, and null is a third state that matters: it means
 * "follow this company's policy". The incentive being shared out came from
 * a particular company's rate card, so the sensible default lives on that
 * company's agreement, not on forty technician rows that all have to be
 * edited when the policy changes. An explicit figure here is an individual
 * override and survives a policy change untouched.
 */
final readonly class TechnicianRateData
{
    public function __construct(
        public int $id,
        public PayoutModel $model = PayoutModel::FlatPerJob,
        public ?Money $flatAmount = null,
        /** Decimal percent string, used when model is PctOfCompany. */
        public ?string $pctOfCompany = null,
        public ?Money $monthlySalary = null,
        /** Kilometres we do not pay the technician for. */
        public float $travelFreeKm = 0.0,
        public ?Money $travelRatePerKm = null,
        /** null = follow company policy. */
        public ?string $bonusSharePct = null,
        /** null = follow company policy. */
        public ?string $penaltyRecoveryPct = null,
        /** null = every job type @var list<string>|null */
        public ?array $appliesToJobTypes = null,
        public bool $isActive = true,
        /**
         * The company's defaults, used wherever this technician has not
         * been given an individual figure. Carried on the DTO so the payout
         * engine stays a pure function of its inputs rather than reaching
         * for an agreement mid-calculation.
         */
        public string $companyBonusSharePct = '100.00',
        public string $companyPenaltyRecoveryPct = '0.00',
    ) {
    }

    /**
     * Bind this rate to the company whose work is being paid for.
     *
     * Called once by the repository, before the engine runs. Individual
     * overrides on this row are left exactly as they are.
     */
    public function underTerms(AgreementTerms $terms): self
    {
        return new self(
            id: $this->id,
            model: $this->model,
            flatAmount: $this->flatAmount,
            pctOfCompany: $this->pctOfCompany,
            monthlySalary: $this->monthlySalary,
            travelFreeKm: $this->travelFreeKm,
            travelRatePerKm: $this->travelRatePerKm,
            bonusSharePct: $this->bonusSharePct,
            penaltyRecoveryPct: $this->penaltyRecoveryPct,
            appliesToJobTypes: $this->appliesToJobTypes,
            isActive: $this->isActive,
            companyBonusSharePct: $terms->technicianBonusSharePct,
            companyPenaltyRecoveryPct: $terms->technicianPenaltyRecoveryPct,
        );
    }

    /**
     * The share of an SLA incentive this technician actually receives.
     */
    public function bonusShare(): string
    {
        return $this->bonusSharePct ?? $this->companyBonusSharePct;
    }

    /**
     * The share of an SLA deduction actually recovered from them.
     */
    public function penaltyRecovery(): string
    {
        return $this->penaltyRecoveryPct ?? $this->companyPenaltyRecoveryPct;
    }

    public function appliesToJobType(string $jobTypeCode): bool
    {
        return $this->appliesToJobTypes === null
            || in_array($jobTypeCode, $this->appliesToJobTypes, true);
    }

    public function travelRate(): Money
    {
        return $this->travelRatePerKm ?? Money::zero();
    }

    /**
     * The per-job base pay, given what the job's service charge was.
     */
    public function baseFor(Money $serviceCharge): Money
    {
        return match ($this->model) {
            PayoutModel::FlatPerJob => $this->flatAmount ?? Money::zero(),
            PayoutModel::PctOfCompany => $serviceCharge->percentage($this->pctOfCompany ?? '0'),
            PayoutModel::Salaried => Money::zero(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'technician_rate_id' => $this->id,
            'model' => $this->model->value,
            'flat_amount_paise' => $this->flatAmount?->paise,
            'pct_of_company' => $this->pctOfCompany,
            'monthly_salary_paise' => $this->monthlySalary?->paise,
            'travel_free_km' => $this->travelFreeKm,
            'travel_rate_per_km_paise' => $this->travelRate()->paise,
            'bonus_share_pct' => $this->bonusShare(),
            'penalty_recovery_pct' => $this->penaltyRecovery(),
            // Recorded on every frozen payout line so a settled amount can
            // be explained later without re-reading the agreement: was this
            // an individual arrangement, or the company policy of the day?
            'bonus_share_source' => $this->bonusSharePct === null ? 'company_policy' : 'technician_override',
            'penalty_recovery_source' => $this->penaltyRecoveryPct === null
                ? 'company_policy'
                : 'technician_override',
        ];
    }
}
