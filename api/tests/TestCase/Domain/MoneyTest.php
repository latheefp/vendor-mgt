<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain;

use App\Domain\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Money is the foundation everything else stands on, so it gets tested
 * harder than anything else here.
 */
final class MoneyTest extends TestCase
{
    public function testRupeesConvertToPaise(): void
    {
        $this->assertSame(35_000, Money::fromRupees(350)->paise);
        $this->assertSame(0, Money::zero()->paise);
    }

    public function testArithmeticStaysExact(): void
    {
        $base = Money::fromRupees(500);
        $bonus = Money::fromRupees(75);

        $this->assertSame(57_500, $base->plus($bonus)->paise);
        $this->assertSame(42_500, $base->minus(Money::fromRupees(75))->paise);
        $this->assertSame(150_000, $base->times(3)->paise);
        $this->assertSame(-50_000, $base->negate()->paise);
        $this->assertSame(50_000, $base->negate()->absolute()->paise);
    }

    /**
     * The out-of-warranty royalty from clause 8, at every rate on the card.
     */
    #[DataProvider('royaltyCases')]
    public function testTenPercentRoyaltyIsExact(int $rupees, int $expectedPaise): void
    {
        $this->assertSame(
            $expectedPaise,
            Money::fromRupees($rupees)->percentage('10.00')->paise,
        );
    }

    /**
     * @return list<array{int, int}>
     */
    public static function royaltyCases(): array
    {
        return [
            [500, 5_000],    // Rs.50 on the 24"-43" out-of-warranty rate
            [1000, 10_000],  // Rs.100 on 45"-55"
            [1500, 15_000],  // Rs.150 on 65"-85" and on panel work
        ];
    }

    /**
     * Clause 6 allows a margin anywhere in 10-15%, so fractional paise are
     * unavoidable. They must round predictably rather than drift.
     */
    public function testPercentageRoundsHalfUp(): void
    {
        // 12.5% of Rs.1234.00 = Rs.154.25 exactly
        $this->assertSame(15_425, Money::fromRupees(1234)->percentage('12.5')->paise);

        // 15% of Rs.333.33 = Rs.49.9995 -> rounds up to Rs.50.00
        $this->assertSame(5_000, Money::parse('333.33')->percentage('15')->paise);

        // 10% of Rs.0.05 = 0.5 paise -> rounds away from zero
        $this->assertSame(1, Money::parse('0.05')->percentage('10')->paise);
    }

    /**
     * A penalty and the bonus it offsets must round by the same magnitude,
     * otherwise repeated adjustments leak paise in one direction.
     */
    public function testRoundingIsSymmetricAroundZero(): void
    {
        $positive = Money::parse('0.05')->percentage('10');
        $negative = Money::parse('-0.05')->percentage('10');

        $this->assertSame(1, $positive->paise);
        $this->assertSame(-1, $negative->paise);
    }

    /**
     * Travel is Rs.3/km on a fractional distance (important note 5).
     */
    public function testTimesQuantityHandlesFractionalKilometres(): void
    {
        $rate = Money::fromRupees(3);

        // 7.4km beyond the free radius = Rs.22.20
        $this->assertSame(2_220, $rate->timesQuantity('7.40')->paise);
        // 0.5km = Rs.1.50
        $this->assertSame(150, $rate->timesQuantity('0.50')->paise);
        // 23.33km = Rs.69.99
        $this->assertSame(6_999, $rate->timesQuantity('23.33')->paise);
    }

    public function testParseAcceptsDecimalRupees(): void
    {
        $this->assertSame(123_450, Money::parse('1234.50')->paise);
        $this->assertSame(100, Money::parse('1')->paise);
        $this->assertSame(105, Money::parse('1.05')->paise);
        $this->assertSame(-2_550, Money::parse('-25.50')->paise);
    }

    public function testParseRejectsMorePrecisionThanItCanHold(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parse('10.005');
    }

    public function testParseRejectsGarbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parse('one thousand');
    }

    /**
     * Indian digit grouping is 3 then 2s, which number_format cannot do.
     */
    #[DataProvider('formatCases')]
    public function testIndianCurrencyGrouping(string $input, string $expected): void
    {
        $this->assertSame($expected, Money::parse($input)->format());
    }

    /**
     * @return list<array{string, string}>
     */
    public static function formatCases(): array
    {
        return [
            ['0', '₹0.00'],
            ['350', '₹350.00'],
            ['1500', '₹1,500.00'],
            ['25000', '₹25,000.00'],       // the clause 4 credit limit
            ['123456.50', '₹1,23,456.50'], // 3 then 2s
            ['10000000', '₹1,00,00,000.00'],
            ['-50', '-₹50.00'],
        ];
    }

    public function testSumOfMixedSignsNets(): void
    {
        $total = Money::sum([
            Money::fromRupees(500),   // base
            Money::fromRupees(75),    // SLA bonus
            Money::fromRupees(50)->negate(), // SLA penalty
        ]);

        $this->assertSame(52_500, $total->paise);
        $this->assertSame('525.00', $total->toRupeeString());
    }

    public function testSumOfNothingIsZero(): void
    {
        $this->assertTrue(Money::sum([])->isZero());
    }

    /**
     * The wire format carries both a computable value and a printable one,
     * so the client never has to do currency maths in JavaScript.
     */
    public function testJsonCarriesPaiseAndFormatted(): void
    {
        $this->assertSame(
            ['paise' => 57_500, 'rupees' => '575.00', 'formatted' => '₹575.00'],
            Money::fromRupees(575)->jsonSerialize(),
        );
    }

    public function testComparisons(): void
    {
        $a = Money::fromRupees(500);
        $b = Money::fromRupees(1000);

        $this->assertTrue($b->greaterThan($a));
        $this->assertTrue($a->lessThan($b));
        $this->assertTrue($a->equals(Money::fromRupees(500)));
        $this->assertTrue($a->negate()->isNegative());
        $this->assertTrue($a->isPositive());
        $this->assertTrue(Money::zero()->isZero());
    }
}
