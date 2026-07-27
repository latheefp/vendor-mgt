<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\Company\SettingCatalog;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Allocates the next ticket number for a company.
 *
 * The number is per-company by design — prefix and width both come from
 * that company's settings — because the desk works several companies'
 * tickets on one screen and reads our reference alongside the company's
 * own. Dianora's look like DN1407260024; if ours looked the same they
 * would be misread on the phone daily.
 *
 * Allocation is a single atomic statement rather than read-then-write. Two
 * intakes a millisecond apart are ordinary at the start of a shift, and a
 * SELECT followed by an UPDATE hands both the same number: one intake then
 * fails on the unique index while the operator is still on the call with
 * the customer.
 */
class TicketNumberAllocator
{
    use LocatorAwareTrait;

    public function __construct(
        private readonly CompanyConfigRepository $config = new CompanyConfigRepository(),
    ) {
    }

    /**
     * The next number for a company, e.g. DIN-202607-000042.
     *
     * @param string|null $period  reset bucket; defaults to the current month
     */
    public function next(int $vendorId, ?string $period = null): string
    {
        $period ??= date('Ym');

        $settings = $this->config->settings($vendorId);
        $prefix = $settings->string(SettingCatalog::TICKET_PREFIX, 'TKT');
        $width = max(3, min(10, $settings->int(SettingCatalog::TICKET_SEQUENCE_WIDTH, 6)));

        $sequence = $this->increment($vendorId, $period);

        return sprintf('%s-%s-%0' . $width . 'd', $prefix, $period, $sequence);
    }

    /**
     * Claim the next value in one round trip.
     *
     * INSERT .. ON DUPLICATE KEY UPDATE takes a row lock for the duration
     * of the statement, so concurrent callers queue rather than collide.
     * LAST_INSERT_ID(expr) is the documented way to read back the value
     * this connection just wrote — it is connection-scoped, so another
     * request incrementing the same row cannot be observed here.
     *
     * `last_value` is backquoted because MySQL 8.0 made LAST_VALUE a
     * reserved word (it is a window function); unquoted, the whole
     * statement is a syntax error.
     */
    private function increment(int $vendorId, string $period): int
    {
        $connection = $this->fetchTable('TicketSequences')->getConnection();
        $now = date('Y-m-d H:i:s');

        $statement = $connection->execute(
            'INSERT INTO ticket_sequences (vendor_id, period, `last_value`, created, modified)
                 VALUES (:vendor_id, :period, 1, :now, :now)
             ON DUPLICATE KEY UPDATE
                 `last_value` = LAST_INSERT_ID(`last_value` + 1),
                 modified = :now',
            ['vendor_id' => $vendorId, 'period' => $period, 'now' => $now],
        );

        // MySQL reports 1 affected row when the INSERT ran and 2 when the
        // ON DUPLICATE KEY UPDATE branch did. Read from the statement, not
        // from a later ROW_COUNT() — that would report on the SELECT.
        if ($statement->rowCount() === 1) {
            // Fresh row. LAST_INSERT_ID() holds this row's auto-increment
            // id, which is not the counter, so do not read it back.
            return 1;
        }

        $row = $connection->execute('SELECT LAST_INSERT_ID() AS value')->fetch('assoc');

        return (int)($row['value'] ?? 1);
    }
}
