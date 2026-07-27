<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Rate;

use App\Domain\Enum\Payer;
use App\Domain\Enum\WarrantyScope;
use App\Domain\Exception\RateNotFoundException;
use App\Domain\Exception\UnrateableTicketException;
use App\Domain\Money;
use App\Domain\Rate\RateCardItemData;
use App\Domain\Rate\RateContext;
use App\Domain\Rate\RateResolver;
use App\Test\TestCase\Domain\DianoraRateCard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every priced line on the Dianora card, plus the edges where the card
 * does not say anything.
 */
final class RateResolverTest extends TestCase
{
    private RateResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new RateResolver();
    }

    /**
     * The full card, walked line by line.
     */
    #[DataProvider('agreementRates')]
    public function testResolvesEveryRateOnTheCard(
        string $jobType,
        WarrantyScope $scope,
        ?float $sizeInch,
        int $expectedRupees,
        Payer $expectedPayer,
    ): void {
        $resolved = $this->resolver->resolve(
            new RateContext($jobType, $scope, sizeInch: $sizeInch),
            DianoraRateCard::items(),
        );

        $this->assertTrue(
            $resolved->amount()->equals(Money::fromRupees($expectedRupees)),
            sprintf(
                'Expected %s for %s but resolver returned %s via "%s"',
                Money::fromRupees($expectedRupees)->format(),
                (new RateContext($jobType, $scope, sizeInch: $sizeInch))->describe(),
                $resolved->amount()->format(),
                $resolved->description(),
            ),
        );

        $this->assertSame($expectedPayer, $resolved->payer());
    }

    /**
     * @return list<array{string, WarrantyScope, float|null, int, Payer}>
     */
    public static function agreementRates(): array
    {
        $na = WarrantyScope::NotApplicable;
        $iw = WarrantyScope::InWarranty;
        $oow = WarrantyScope::OutOfWarranty;

        return [
            // installation
            'install 24"'          => [DianoraRateCard::JOB_INSTALLATION, $na, 24.0, 350, Payer::Vendor],
            'install 32"'          => [DianoraRateCard::JOB_INSTALLATION, $na, 32.0, 350, Payer::Vendor],
            'install 43" boundary' => [DianoraRateCard::JOB_INSTALLATION, $na, 43.0, 350, Payer::Vendor],
            'install 45" boundary' => [DianoraRateCard::JOB_INSTALLATION, $na, 45.0, 500, Payer::Vendor],
            'install 55"'          => [DianoraRateCard::JOB_INSTALLATION, $na, 55.0, 500, Payer::Vendor],
            'install 65" boundary' => [DianoraRateCard::JOB_INSTALLATION, $na, 65.0, 500, Payer::Vendor],

            // demo has no size dimension at all
            'demo, size known'     => [DianoraRateCard::JOB_DEMO, $na, 43.0, 250, Payer::Vendor],
            'demo, size unknown'   => [DianoraRateCard::JOB_DEMO, $na, null, 250, Payer::Vendor],

            // service in warranty — billed to the vendor
            'iw service 24"'       => [DianoraRateCard::JOB_SERVICE, $iw, 24.0, 400, Payer::Vendor],
            'iw service 43"'       => [DianoraRateCard::JOB_SERVICE, $iw, 43.0, 400, Payer::Vendor],
            'iw service 45"'       => [DianoraRateCard::JOB_SERVICE, $iw, 45.0, 500, Payer::Vendor],
            'iw service 85"'       => [DianoraRateCard::JOB_SERVICE, $iw, 85.0, 500, Payer::Vendor],
            'iw exchange'          => [DianoraRateCard::JOB_EXCHANGE, $iw, 55.0, 700, Payer::Vendor],
            'iw panel'             => [DianoraRateCard::JOB_PANEL, $iw, 55.0, 1000, Payer::Vendor],

            // service out of warranty — collected from the customer
            'oow service 32"'      => [DianoraRateCard::JOB_SERVICE, $oow, 32.0, 500, Payer::Customer],
            'oow service 50"'      => [DianoraRateCard::JOB_SERVICE, $oow, 50.0, 1000, Payer::Customer],
            'oow service 55"'      => [DianoraRateCard::JOB_SERVICE, $oow, 55.0, 1000, Payer::Customer],
            'oow service 65"'      => [DianoraRateCard::JOB_SERVICE, $oow, 65.0, 1500, Payer::Customer],
            'oow service 85"'      => [DianoraRateCard::JOB_SERVICE, $oow, 85.0, 1500, Payer::Customer],
            'oow panel'            => [DianoraRateCard::JOB_PANEL, $oow, 75.0, 1500, Payer::Customer],
        ];
    }

    /**
     * Warranty scope selects a different rate AND a different payer for
     * the same physical job. Getting it wrong bills the wrong party, so it
     * is asserted directly rather than left implied by the table above.
     */
    public function testScopeChangesBothRateAndPayer(): void
    {
        $items = DianoraRateCard::items();

        $inWarranty = $this->resolver->resolve(
            new RateContext(DianoraRateCard::JOB_SERVICE, WarrantyScope::InWarranty, sizeInch: 32),
            $items,
        );
        $outOfWarranty = $this->resolver->resolve(
            new RateContext(DianoraRateCard::JOB_SERVICE, WarrantyScope::OutOfWarranty, sizeInch: 32),
            $items,
        );

        $this->assertSame(40_000, $inWarranty->amount()->paise);
        $this->assertSame(Payer::Vendor, $inWarranty->payer());

        $this->assertSame(50_000, $outOfWarranty->amount()->paise);
        $this->assertSame(Payer::Customer, $outOfWarranty->payer());
    }

    /**
     * A REAL GAP IN THE SIGNED AGREEMENT.
     *
     * Out-of-warranty service is banded 24"-43", 45"-55" and 65"-85".
     * Nothing covers 56"-64", so a 60" out-of-warranty job has no agreed
     * price. A 58" panel is an ordinary size and this will happen in the
     * field.
     *
     * The resolver refuses rather than reaching for the nearest band,
     * because guessing here means charging a customer a number nobody
     * agreed to. The desk gets the rate confirmed by email — the only
     * confirmation clause 11 recognises — and adds it to the card.
     */
    public function testOutOfWarrantyGapBetween56And64InchesFailsLoudly(): void
    {
        $this->expectException(RateNotFoundException::class);
        $this->expectExceptionMessageMatches('/gap in the vendor agreement/');

        $this->resolver->resolve(
            new RateContext(DianoraRateCard::JOB_SERVICE, WarrantyScope::OutOfWarranty, sizeInch: 60),
            DianoraRateCard::items(),
            rateCardId: 1,
        );
    }

    /**
     * The same gap exists at 44" across every section of the card: the
     * bands stop at 43 and restart at 45.
     */
    public function testFortyFourInchGapExistsInEverySection(): void
    {
        foreach ([WarrantyScope::NotApplicable, WarrantyScope::InWarranty, WarrantyScope::OutOfWarranty] as $scope) {
            $jobType = $scope === WarrantyScope::NotApplicable
                ? DianoraRateCard::JOB_INSTALLATION
                : DianoraRateCard::JOB_SERVICE;

            try {
                $this->resolver->resolve(
                    new RateContext($jobType, $scope, sizeInch: 44),
                    DianoraRateCard::items(),
                );
                $this->fail(sprintf('Expected 44" to be unpriced for scope %s', $scope->value));
            } catch (RateNotFoundException $e) {
                $this->assertStringContainsString('44"', $e->getMessage());
            }
        }
    }

    /**
     * A size-banded rate cannot be applied to a ticket whose size nobody
     * recorded. Rs.350 and Rs.500 are both plausible and only one is right.
     */
    public function testSizeBandedJobWithUnknownSizeIsRefused(): void
    {
        $this->expectException(RateNotFoundException::class);

        $this->resolver->resolve(
            new RateContext(DianoraRateCard::JOB_INSTALLATION, WarrantyScope::NotApplicable, sizeInch: null),
            DianoraRateCard::items(),
        );
    }

    /**
     * Scope decides who gets billed, so an unverified warranty status is
     * a hard stop rather than something to default.
     */
    public function testUnknownWarrantyScopeIsRefused(): void
    {
        $this->expectException(UnrateableTicketException::class);
        $this->expectExceptionMessageMatches('/warranty status/i');

        $this->resolver->resolve(
            new RateContext(DianoraRateCard::JOB_SERVICE, WarrantyScope::Unknown, sizeInch: 32),
            DianoraRateCard::items(),
        );
    }

    public function testInactiveItemsAreIgnored(): void
    {
        $items = array_map(
            static fn (RateCardItemData $i): RateCardItemData => new RateCardItemData(
                id: $i->id,
                jobTypeCode: $i->jobTypeCode,
                warrantyScope: $i->warrantyScope,
                amount: $i->amount,
                payer: $i->payer,
                label: $i->label,
                productCategoryCode: $i->productCategoryCode,
                sizeMinInch: $i->sizeMinInch,
                sizeMaxInch: $i->sizeMaxInch,
                priority: $i->priority,
                isActive: false,
            ),
            DianoraRateCard::items(),
        );

        $this->expectException(RateNotFoundException::class);
        $this->resolver->resolve(
            new RateContext(DianoraRateCard::JOB_INSTALLATION, WarrantyScope::NotApplicable, sizeInch: 32),
            $items,
        );
    }

    /**
     * A rate naming a specific product category beats a generic one, so a
     * vendor can add "washing machine service" without disturbing the
     * catch-all that already prices televisions.
     */
    public function testCategorySpecificItemBeatsCatchAll(): void
    {
        $items = [
            new RateCardItemData(
                id: 100,
                jobTypeCode: 'service',
                warrantyScope: WarrantyScope::InWarranty,
                amount: Money::fromRupees(400),
                payer: Payer::Vendor,
                label: 'Any product',
            ),
            new RateCardItemData(
                id: 101,
                jobTypeCode: 'service',
                warrantyScope: WarrantyScope::InWarranty,
                amount: Money::fromRupees(650),
                payer: Payer::Vendor,
                label: 'Washing machine',
                productCategoryCode: 'washing_machine',
            ),
        ];

        $resolved = $this->resolver->resolve(
            new RateContext('service', WarrantyScope::InWarranty, productCategoryCode: 'washing_machine'),
            $items,
        );

        $this->assertSame(65_000, $resolved->amount()->paise);
        $this->assertSame('Washing machine', $resolved->description());

        // ...and the catch-all is still reported as the runner-up.
        $this->assertCount(1, $resolved->alternatives);
        $this->assertSame(100, $resolved->alternatives[0]->id);
    }

    /**
     * Where two bands overlap, the narrower one is the more deliberate
     * rate and wins.
     */
    public function testNarrowerBandWinsOnOverlap(): void
    {
        $items = [
            new RateCardItemData(
                id: 200,
                jobTypeCode: 'service',
                warrantyScope: WarrantyScope::InWarranty,
                amount: Money::fromRupees(500),
                payer: Payer::Vendor,
                label: 'Broad 45-85',
                sizeMinInch: 45,
                sizeMaxInch: 85,
            ),
            new RateCardItemData(
                id: 201,
                jobTypeCode: 'service',
                warrantyScope: WarrantyScope::InWarranty,
                amount: Money::fromRupees(900),
                payer: Payer::Vendor,
                label: 'Narrow 75-85',
                sizeMinInch: 75,
                sizeMaxInch: 85,
            ),
        ];

        $resolved = $this->resolver->resolve(
            new RateContext('service', WarrantyScope::InWarranty, sizeInch: 80),
            $items,
        );

        $this->assertSame('Narrow 75-85', $resolved->description());
        $this->assertSame(90_000, $resolved->amount()->paise);
    }

    /**
     * Same context, same card, same answer — every time. An invoice that
     * cannot be reproduced is an invoice that cannot be defended.
     */
    public function testResolutionIsDeterministic(): void
    {
        $context = new RateContext(DianoraRateCard::JOB_SERVICE, WarrantyScope::InWarranty, sizeInch: 50);

        $first = $this->resolver->resolve($context, DianoraRateCard::items());

        for ($i = 0; $i < 25; $i++) {
            $shuffled = DianoraRateCard::items();
            shuffle($shuffled);

            $again = $this->resolver->resolve($context, $shuffled);

            $this->assertSame($first->item->id, $again->item->id);
            $this->assertSame($first->amount()->paise, $again->amount()->paise);
        }
    }

    /**
     * The snapshot has to explain the decision on its own, without the
     * reader needing access to the rate card as it stands today.
     */
    public function testSnapshotExplainsTheDecision(): void
    {
        $resolved = $this->resolver->resolve(
            new RateContext(DianoraRateCard::JOB_SERVICE, WarrantyScope::OutOfWarranty, sizeInch: 70),
            DianoraRateCard::items(),
        );

        $snapshot = $resolved->toSnapshot();

        $this->assertSame(10, $snapshot['matched_item']['id']);
        $this->assertSame(150_000, $snapshot['matched_item']['amount_paise']);
        $this->assertSame('65"-85"', $snapshot['matched_item']['size_band']);
        $this->assertSame('customer', $snapshot['matched_item']['payer']);
        $this->assertSame('out_of_warranty', $snapshot['context']['warranty_scope']);
        $this->assertSame(70.0, $snapshot['context']['size_inch']);
    }
}
