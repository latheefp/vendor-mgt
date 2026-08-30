<?php
declare(strict_types=1);

namespace App\Domain\Payout;

use App\Domain\Charge\ChargeLine;
use App\Domain\Charge\ChargeSet;
use App\Domain\Enum\ChargeLineType;
use App\Domain\Enum\Ledger;
use App\Domain\Money;

/**
 * Works out what the technician earns from a job we have already priced.
 *
 * Runs against the frozen ChargeSet rather than against the ticket, which
 * guarantees the two sides of the ledger are derived from the same
 * numbers. If the company side later gets an adjustment, the payout side
 * gets its own adjustment line — it is never silently recomputed.
 *
 * All output lands on the TechnicianPayable ledger as positive amounts,
 * except penalty recovery, which is negative because it reduces what we
 * owe them.
 */
final readonly class PayoutBuilder
{
    /**
     * @return list<ChargeLine>
     */
    public function build(
        ChargeSet $charges,
        TechnicianRateData $rate,
        string $jobTypeCode,
        ?float $travelKm = null,
    ): array {
        if (!$rate->isActive || !$rate->appliesToJobType($jobTypeCode)) {
            return [];
        }

        $lines = [];

        $base = $this->baseLine($charges, $rate);
        if ($base !== null) {
            $lines[] = $base;
        }

        $bonus = $this->bonusShareLine($charges, $rate);
        if ($bonus !== null) {
            $lines[] = $bonus;
        }

        $recovery = $this->penaltyRecoveryLine($charges, $rate);
        if ($recovery !== null) {
            $lines[] = $recovery;
        }

        $travel = $this->travelLine($rate, $travelKm);
        if ($travel !== null) {
            $lines[] = $travel;
        }

        return $lines;
    }

    /**
     * The service charge this job earned, whoever paid it.
     *
     * Deliberately the Base line only. Percentage-paid technicians are
     * paid on the job, not on our travel reimbursement or on the margin we
     * made reselling a spare part.
     */
    public function serviceChargeBasis(ChargeSet $charges): Money
    {
        return Money::sum(array_map(
            static fn (ChargeLine $l): Money => $l->amount,
            $charges->ofType(ChargeLineType::Base),
        ));
    }

    private function baseLine(ChargeSet $charges, TechnicianRateData $rate): ?ChargeLine
    {
        if (!$rate->model->hasPerJobBase()) {
            return null;
        }

        $basis = $this->serviceChargeBasis($charges);
        $amount = $rate->baseFor($basis);

        if ($amount->isZero()) {
            return null;
        }

        return new ChargeLine(
            type: ChargeLineType::TechnicianPayout,
            ledger: Ledger::TechnicianPayable,
            description: sprintf('Technician payout (%s)', $rate->model->label()),
            amount: $amount,
            sourceRefs: ['technician_rate_id' => $rate->id],
            snapshot: [
                'rate' => $rate->toArray(),
                'basis_paise' => $basis->paise,
                'basis' => 'base_service_charge_only',
            ],
        );
    }

    /**
     * The technician's cut of any SLA incentive the job earned.
     */
    private function bonusShareLine(ChargeSet $charges, TechnicianRateData $rate): ?ChargeLine
    {
        $bonus = Money::sum(array_map(
            static fn (ChargeLine $l): Money => $l->amount,
            $charges->ofType(ChargeLineType::SlaBonus),
        ));

        if ($bonus->isZero()) {
            return null;
        }

        // bonusShare(), not bonusSharePct — the raw property is null when
        // the technician follows company policy rather than an individual
        // arrangement, and the accessor is what resolves the two.
        $sharePct = $rate->bonusShare();

        $share = $bonus->percentage($sharePct);
        if ($share->isZero()) {
            return null;
        }

        return new ChargeLine(
            type: ChargeLineType::TechnicianBonusShare,
            ledger: Ledger::TechnicianPayable,
            description: sprintf('SLA incentive share (%s%% of %s)', $sharePct, $bonus->format()),
            amount: $share,
            sourceRefs: ['technician_rate_id' => $rate->id],
            snapshot: [
                'bonus_total_paise' => $bonus->paise,
                'share_pct' => $sharePct,
            ],
        );
    }

    /**
     * The share of an SLA deduction recovered from the technician.
     *
     * SlaPenalty lines are already negative, so the recovery comes out
     * negative too and correctly reduces the payable.
     */
    private function penaltyRecoveryLine(ChargeSet $charges, TechnicianRateData $rate): ?ChargeLine
    {
        $penalty = Money::sum(array_map(
            static fn (ChargeLine $l): Money => $l->amount,
            $charges->ofType(ChargeLineType::SlaPenalty),
        ));

        if ($penalty->isZero()) {
            return null;
        }

        $recoveryPct = $rate->penaltyRecovery();

        $recovery = $penalty->percentage($recoveryPct);
        if ($recovery->isZero()) {
            return null;
        }

        return new ChargeLine(
            type: ChargeLineType::TechnicianPenaltyRecovery,
            ledger: Ledger::TechnicianPayable,
            description: sprintf(
                'SLA deduction recovery (%s%% of %s)',
                $recoveryPct,
                $penalty->absolute()->format(),
            ),
            amount: $recovery,
            sourceRefs: ['technician_rate_id' => $rate->id],
            snapshot: [
                'penalty_total_paise' => $penalty->paise,
                'recovery_pct' => $recoveryPct,
            ],
        );
    }

    /**
     * The technician's own travel allowance, which is not the same number
     * as the company's reimbursement. Keeping them separate is what makes
     * the spread between the two visible instead of accidental.
     */
    private function travelLine(TechnicianRateData $rate, ?float $travelKm): ?ChargeLine
    {
        if ($travelKm === null || $travelKm <= $rate->travelFreeKm) {
            return null;
        }

        $ratePerKm = $rate->travelRate();
        if ($ratePerKm->isZero()) {
            return null;
        }

        $billableKm = round($travelKm - $rate->travelFreeKm, 2);
        $quantity = number_format($billableKm, 2, '.', '');

        return new ChargeLine(
            type: ChargeLineType::Travel,
            ledger: Ledger::TechnicianPayable,
            description: sprintf('Technician travel %s km @ %s/km', $quantity, $ratePerKm->format()),
            amount: $ratePerKm->timesQuantity($quantity),
            quantity: $quantity,
            unitAmount: $ratePerKm,
            sourceRefs: ['technician_rate_id' => $rate->id],
            snapshot: [
                'travel_km' => $travelKm,
                'free_km' => $rate->travelFreeKm,
                'billable_km' => $billableKm,
                'rate_per_km_paise' => $ratePerKm->paise,
            ],
        );
    }
}
