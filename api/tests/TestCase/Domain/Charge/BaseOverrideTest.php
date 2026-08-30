<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Charge;

use App\Domain\Charge\BaseOverride;
use App\Domain\Charge\ChargeBuilder;
use App\Domain\Enum\ChargeLineType;
use App\Domain\Enum\Ledger;
use App\Domain\Enum\Payer;
use App\Domain\Enum\WarrantyScope;
use App\Domain\Exception\RateNotFoundException;
use App\Domain\Money;
use App\Domain\Rate\RateContext;
use App\Domain\Rate\RateResolver;
use App\Domain\Sla\SlaCalculator;
use App\Domain\Sla\SlaEvaluator;
use App\Domain\Sla\SlaTiming;
use App\Domain\Sla\SlaWindows;
use App\Test\TestCase\Domain\DianoraRateCard;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Pricing a job the rate card cannot price.
 *
 * The gap these cover is real and permanent rather than a data-entry
 * mistake: the Dianora card prices out-of-warranty service for 24-43",
 * 45-55" and 65-85", so a 60" set has no agreed line and will not have one
 * until the agreement is renegotiated. A 44" set has none anywhere.
 *
 * What matters is that overriding stays visible. Every assertion below is
 * as much about the trail — the reason on the description, the card figure
 * kept in the snapshot — as it is about the amount.
 */
final class BaseOverrideTest extends TestCase
{
    private ChargeBuilder $builder;
    private RateResolver $resolver;
    private DateTimeImmutable $received;

    protected function setUp(): void
    {
        $this->builder = new ChargeBuilder();
        $this->resolver = new RateResolver();
        $this->received = new DateTimeImmutable('2026-07-01 09:00:00');
    }

    private function timingClosedAfter(float $hours): SlaTiming
    {
        $closed = $this->received->modify(sprintf('+%d minutes', (int)round($hours * 60)));

        return (new SlaCalculator())->calculate(
            receivedAt: $this->received,
            firstContactAt: $this->received->modify('+30 minutes'),
            visitedAt: $closed,
            closedAt: $closed,
            holds: [],
            windows: new SlaWindows(2, 48, 48),
            now: $closed->modify('+1 hour'),
        );
    }

    /**
     * The 60" hole in the card is genuinely unpriceable.
     */
    public function testSixtyInchOutOfWarrantyHasNoRate(): void
    {
        $this->expectException(RateNotFoundException::class);

        $this->resolver->resolve(
            new RateContext(DianoraRateCard::JOB_SERVICE, WarrantyScope::OutOfWarranty, sizeInch: 60),
            DianoraRateCard::items(),
        );
    }

    /**
     * A 60" out-of-warranty job agreed by phone at Rs.1200.
     *
     * With no card item the override carries the payer itself, and the
     * royalty still follows: clause 8 is 10% of what was collected for
     * out-of-warranty service, and a manually agreed figure is still a
     * collection.
     */
    public function testOverridePricesAJobTheCardCannot(): void
    {
        $timing = $this->timingClosedAfter(20);

        $charges = $this->builder->build(
            null,
            [],
            DianoraRateCard::terms(),
            $timing,
            null,
            [],
            new BaseOverride(
                amount: Money::fromRupees(1200),
                reason: 'Agreed with Dianora by email 2026-07-01, 60" not on the card',
                payer: Payer::Customer,
                authorisedByUserId: 7,
            ),
        );

        $this->assertSame(120000, $charges->customerCollection()->paise);
        // 10% of Rs.1200 back to the company.
        $this->assertSame(12000, $charges->companyPayable()->paise);
        $this->assertSame(108000, $charges->grossMargin()->paise);
    }

    /**
     * The manual figure wins over a card item that did match, and the card's
     * own number survives in the snapshot.
     */
    public function testOverrideBeatsTheCardAndKeepsWhatTheCardSaid(): void
    {
        $timing = $this->timingClosedAfter(20);

        // 50" out of warranty resolves at Rs.1000 on the card.
        $rate = $this->resolver->resolve(
            new RateContext(DianoraRateCard::JOB_SERVICE, WarrantyScope::OutOfWarranty, sizeInch: 50),
            DianoraRateCard::items(),
        );
        $this->assertSame(100000, $rate->amount()->paise);

        $charges = $this->builder->build(
            $rate,
            [],
            DianoraRateCard::terms(),
            $timing,
            null,
            [],
            new BaseOverride(
                amount: Money::fromRupees(750),
                reason: 'Goodwill on a repeat visit, approved by the branch manager',
            ),
        );

        $base = $charges->ofType(ChargeLineType::Base)[0];

        $this->assertSame(75000, $base->amount->paise);
        // Payer inherited from the item, so the ledger is unchanged.
        $this->assertSame(Ledger::CustomerCollection, $base->ledger);
        // Provenance: which item it replaced, and what that item charged.
        $this->assertSame(9, $base->rateCardItemId());
        $this->assertSame(100000, $base->snapshot['card_amount_paise']);
        $this->assertTrue($base->snapshot['override']['manual']);
        $this->assertSame(
            'Goodwill on a repeat visit, approved by the branch manager',
            $base->snapshot['override']['reason'],
        );
        // Royalty follows the charge actually made, not the card's Rs.1000.
        $this->assertSame(7500, $charges->companyPayable()->paise);
    }

    /**
     * The reason is on the invoice line, not buried in a JSON column. A
     * company querying an unfamiliar amount reads the document, not our
     * database.
     */
    public function testTheReasonPrintsOnTheLine(): void
    {
        $charges = $this->builder->build(
            null,
            [],
            DianoraRateCard::terms(),
            $this->timingClosedAfter(20),
            null,
            [],
            new BaseOverride(
                amount: Money::fromRupees(1200),
                reason: 'Agreed by email 2026-07-01',
                payer: Payer::Company,
            ),
        );

        $base = $charges->ofType(ChargeLineType::Base)[0];

        $this->assertStringContainsString('agreed manually', $base->description);
        $this->assertStringContainsString('Agreed by email 2026-07-01', $base->description);
        $this->assertSame(Ledger::CompanyReceivable, $base->ledger);
        // Billed to the company, so no out-of-warranty royalty arises.
        $this->assertTrue($charges->companyPayable()->isZero());
    }

    /**
     * SLA rules are unaffected by an override. The bonus is a term between
     * us and the company about time taken; it does not care how the base
     * amount was arrived at.
     */
    public function testSlaStillAppliesToAnOverriddenJob(): void
    {
        $timing = $this->timingClosedAfter(20);

        $matched = (new SlaEvaluator())->evaluate(
            $timing,
            DianoraRateCard::slaRules(),
            DianoraRateCard::JOB_SERVICE,
            WarrantyScope::InWarranty,
        );

        $charges = $this->builder->build(
            null,
            $matched,
            DianoraRateCard::terms(),
            $timing,
            null,
            [],
            new BaseOverride(
                amount: Money::fromRupees(600),
                reason: '44" set, no band covers it',
                payer: Payer::Company,
            ),
        );

        // Rs.600 manual + Rs.75 for closing inside 48 hours.
        $this->assertSame(67500, $charges->companyReceivable()->paise);
    }

    /**
     * Neither a rate nor an override is not a priceable job, and it must
     * fail loudly rather than produce a zero line.
     */
    public function testNeitherRateNorOverrideIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder->build(null, [], DianoraRateCard::terms(), $this->timingClosedAfter(20));
    }

    /**
     * With no card item to inherit from, an override that does not say who
     * pays cannot be turned into a charge line — the ledger would be a
     * guess, and guessing wrong bills a warranty customer.
     */
    public function testOverrideWithoutAPayerAndWithoutARateIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder->build(
            null,
            [],
            DianoraRateCard::terms(),
            $this->timingClosedAfter(20),
            null,
            [],
            new BaseOverride(amount: Money::fromRupees(1200), reason: 'No payer given'),
        );
    }
}
