<?php
declare(strict_types=1);

namespace App\Domain\Charge;

use App\Domain\Enum\ChargeLineType;
use App\Domain\Enum\Ledger;
use App\Domain\Enum\Payer;
use App\Domain\Money;
use App\Domain\Rate\ResolvedRate;
use App\Domain\Sla\MatchedSlaRule;
use App\Domain\Sla\SlaTiming;
use InvalidArgumentException;

/**
 * Turns a priced, measured, completed ticket into frozen charge lines.
 *
 * This runs ONCE, at closure. Everything downstream — the company invoice,
 * the technician payout, every margin report — reads the rows it wrote
 * and never re-derives a rate. Recomputing later against live rate cards
 * would silently rewrite invoices that have already been sent, which is
 * the single worst failure mode available to a system like this.
 *
 * Corrections are new adjustment lines, never edits.
 *
 * Two manual seams exist, and both are recorded rather than silent: a
 * BaseOverride replaces the card amount at closure for work the card does
 * not price, and adjustment() appends a correction afterwards.
 */
final readonly class ChargeBuilder
{
    /**
     * @param list<MatchedSlaRule> $matchedRules
     * @param list<SpareUsage> $spares
     * @throws \InvalidArgumentException when neither a resolved rate nor an
     *         override is supplied, or when nothing establishes who pays.
     */
    public function build(
        ?ResolvedRate $rate,
        array $matchedRules,
        AgreementTerms $terms,
        SlaTiming $timing,
        ?float $travelKm = null,
        array $spares = [],
        ?BaseOverride $override = null,
    ): ChargeSet {
        if ($rate === null && $override === null) {
            throw new InvalidArgumentException(
                'A job needs either a resolved rate card item or a manual override to be priced.',
            );
        }

        // Who pays is what decides the ledger, the royalty and whether cash
        // is collected. An override may inherit it from the card item, but
        // when there is no item it has to carry it itself.
        $basePayer = $override?->payer ?? $rate?->payer();
        if ($basePayer === null) {
            throw new InvalidArgumentException(
                'A manual base charge on a job with no rate card item must state who pays it.',
            );
        }

        $baseAmount = $override?->amount ?? $rate->amount();

        $lines = [];

        $lines[] = $this->baseLine($rate, $timing, $override, $basePayer);

        foreach ($matchedRules as $matched) {
            $lines[] = $this->slaLine($matched);
        }

        $travel = $this->travelLine($terms, $travelKm);
        if ($travel !== null) {
            $lines[] = $travel;
        }

        foreach ($spares as $spare) {
            array_push($lines, ...$this->spareLines($spare));
        }

        $royalty = $this->royaltyLine($basePayer, $baseAmount, $terms, $spares);
        if ($royalty !== null) {
            $lines[] = $royalty;
        }

        return new ChargeSet($lines);
    }

    /**
     * The amount for the job itself. Lands on the company ledger for
     * in-warranty and installation work, and on the customer-collection
     * ledger for out-of-warranty work.
     *
     * When an override is present the manual figure wins, but the resolved
     * item — if there was one — is still recorded on both the source ref
     * and the snapshot. That is what lets a report answer "what would the
     * card have charged?" without anyone reconstructing it by hand.
     */
    private function baseLine(
        ?ResolvedRate $rate,
        SlaTiming $timing,
        ?BaseOverride $override,
        Payer $payer,
    ): ChargeLine {
        if ($override === null) {
            return new ChargeLine(
                type: ChargeLineType::Base,
                ledger: $payer->ledger(),
                description: $rate->description(),
                amount: $rate->amount(),
                sourceRefs: ['rate_card_item_id' => $rate->item->id],
                snapshot: [
                    'rate' => $rate->toSnapshot(),
                    'sla' => $timing->toArray(),
                ],
            );
        }

        return new ChargeLine(
            type: ChargeLineType::Base,
            ledger: $payer->ledger(),
            description: $override->description(),
            amount: $override->amount,
            sourceRefs: ['rate_card_item_id' => $rate?->item->id],
            snapshot: [
                'override' => $override->toSnapshot(),
                // Null when the card had no line at all, which is the
                // commonest reason for an override in the first place.
                'rate' => $rate?->toSnapshot(),
                'card_amount_paise' => $rate?->amount()->paise,
                'sla' => $timing->toArray(),
            ],
        );
    }

    /**
     * An incentive or deduction. Always settles with the company, even on a
     * job the customer paid for — an SLA term is between us and them.
     *
     * Penalties are negative here by design; see ChargeLine for why.
     */
    private function slaLine(MatchedSlaRule $matched): ChargeLine
    {
        return new ChargeLine(
            type: $matched->rule->kind->chargeLineType(),
            ledger: Ledger::CompanyReceivable,
            description: $matched->description(),
            amount: $matched->amount(),
            sourceRefs: ['sla_rule_id' => $matched->rule->id],
            snapshot: $matched->toSnapshot(),
        );
    }

    /**
     * Important note 5: Rs.3 per kilometre beyond 15km from the designated
     * service centre. Only the excess distance is payable, so a 12km job
     * produces no line at all rather than a zero one.
     */
    private function travelLine(AgreementTerms $terms, ?float $travelKm): ?ChargeLine
    {
        if ($travelKm === null || $travelKm <= $terms->travelFreeKm) {
            return null;
        }

        $billableKm = round($travelKm - $terms->travelFreeKm, 2);
        $quantity = number_format($billableKm, 2, '.', '');
        $rate = $terms->travelRate();

        return new ChargeLine(
            type: ChargeLineType::Travel,
            ledger: Ledger::CompanyReceivable,
            description: sprintf(
                'Travel %s km beyond %g km free radius @ %s/km',
                $quantity,
                $terms->travelFreeKm,
                $rate->format(),
            ),
            amount: $rate->timesQuantity($quantity),
            quantity: $quantity,
            unitAmount: $rate,
            snapshot: [
                'travel_km' => $travelKm,
                'free_km' => $terms->travelFreeKm,
                'billable_km' => $billableKm,
                'rate_per_km_paise' => $rate->paise,
            ],
        );
    }

    /**
     * Clause 6: out-of-warranty spares are billed at cost plus 10-15%.
     *
     * Cost and margin are separate lines so the margin is visible rather
     * than buried in a single total — both for the customer's receipt and
     * for our own reporting.
     *
     * In-warranty parts are supplied by the company and produce no charge
     * line; what they produce is a return obligation, tracked on the
     * ticket_spares row.
     *
     * @return list<ChargeLine>
     */
    private function spareLines(SpareUsage $spare): array
    {
        if (!$spare->isBilledToCustomer()) {
            return [];
        }

        $lines = [
            new ChargeLine(
                type: ChargeLineType::SpareCost,
                ledger: Ledger::CustomerCollection,
                description: sprintf('%s (%s) x%d', $spare->name, $spare->partNo, $spare->quantity),
                amount: $spare->costTotal(),
                quantity: (string)$spare->quantity,
                unitAmount: $spare->unitCost,
                sourceRefs: ['ticket_spare_id' => $spare->id],
                snapshot: $spare->toArray(),
            ),
        ];

        $margin = $spare->marginTotal();
        if (!$margin->isZero()) {
            $lines[] = new ChargeLine(
                type: ChargeLineType::SpareMargin,
                ledger: Ledger::CustomerCollection,
                description: sprintf('Margin %s%% on %s', $spare->marginPct, $spare->partNo),
                amount: $margin,
                sourceRefs: ['ticket_spare_id' => $spare->id],
                snapshot: $spare->toArray(),
            );
        }

        return $lines;
    }

    /**
     * Clause 8: "DIANORA ELECTRONICS PVT. LTD. shall be entitled to receive
     * a 10% royalty on the service charges collected for all out-of-warranty
     * service provided."
     *
     * The narrow reading — royalty on the SERVICE charge only, not on
     * spares or their margin — is the default, because the clause says
     * "service charges" and spares are dealt with separately in clause 6.
     *
     * But it is a reading of ambiguous wording, not a fact, and the next
     * company will negotiate its own. `royaltyAppliesToSpares` on the
     * agreement is that dial: settling the argument once per company, in
     * data, is what stops one company's interpretation quietly becoming
     * everyone's. Whichever basis was used is recorded in the snapshot, so
     * a royalty queried in a year explains which reading produced it.
     *
     * Takes the payer and the base amount rather than the ResolvedRate,
     * because a manually agreed charge is still a collection the company is
     * owed a royalty on. Reading the rate here instead would quietly hand
     * us the royalty on a job we overrode.
     *
     * @param list<SpareUsage> $spares
     */
    private function royaltyLine(
        Payer $payer,
        Money $baseAmount,
        AgreementTerms $terms,
        array $spares = [],
    ): ?ChargeLine {
        if ($payer !== Payer::Customer) {
            return null;
        }

        $basis = $baseAmount;
        $spareBasis = Money::zero();

        if ($terms->royaltyAppliesToSpares) {
            foreach ($spares as $spare) {
                if ($spare->isBilledToCustomer()) {
                    $spareBasis = $spareBasis->plus($spare->priceTotal());
                }
            }
            $basis = $basis->plus($spareBasis);
        }

        $royalty = $basis->percentage($terms->oowRoyaltyPct);
        if ($royalty->isZero()) {
            return null;
        }

        return new ChargeLine(
            type: ChargeLineType::CompanyRoyalty,
            ledger: Ledger::CompanyPayable,
            description: sprintf(
                'Company royalty %s%% on %s of %s',
                $terms->oowRoyaltyPct,
                $terms->royaltyAppliesToSpares ? 'out-of-warranty collection' : 'out-of-warranty service charge',
                $basis->format(),
            ),
            amount: $royalty,
            snapshot: [
                'basis' => $terms->royaltyAppliesToSpares ? 'service_charge_and_spares' : 'service_charge_only',
                'basis_paise' => $basis->paise,
                'service_charge_paise' => $baseAmount->paise,
                'spare_basis_paise' => $spareBasis->paise,
                'royalty_pct' => $terms->oowRoyaltyPct,
                'excludes' => $terms->royaltyAppliesToSpares ? [] : ['spare_cost', 'spare_margin'],
                'agreement' => $terms->toArray(),
            ],
        );
    }

    /**
     * A correction to an already frozen ticket.
     *
     * The only sanctioned way to change a ticket's money after the fact.
     * Requires a reason, because an adjustment with no explanation is
     * indistinguishable from a mistake when it surfaces in an audit.
     */
    /**
     * Additional service agreed on an open job — a BOQ line.
     *
     * Kept apart from adjustment() because the two answer different
     * questions at audit time: a BOQ line says "this work was agreed and
     * billed", an adjustment says "the bill we already sent was wrong".
     * Collapsing them would make every corrected invoice indistinguishable
     * from a job that simply had extra work on it.
     */
    public function boqLine(
        Ledger $ledger,
        Money $amount,
        string $description,
        ?int $agreedByUserId = null,
    ): ChargeLine {
        return new ChargeLine(
            type: ChargeLineType::Boq,
            ledger: $ledger,
            description: $description,
            amount: $amount,
            snapshot: [
                'description' => $description,
                'agreed_by_user_id' => $agreedByUserId,
                'agreed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function adjustment(
        Ledger $ledger,
        Money $amount,
        string $reason,
        ?int $authorisedByUserId = null,
    ): ChargeLine {
        return new ChargeLine(
            type: ChargeLineType::Adjustment,
            ledger: $ledger,
            description: $reason,
            amount: $amount,
            snapshot: [
                'reason' => $reason,
                'authorised_by_user_id' => $authorisedByUserId,
                'adjusted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );
    }
}
