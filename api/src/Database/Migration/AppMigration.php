<?php
declare(strict_types=1);

namespace App\Database\Migration;

use Migrations\BaseMigration;
use Migrations\Db\Table;

/**
 * Shared conventions for every migration in this application.
 *
 * Two decisions are enforced here rather than repeated per table:
 *
 * 1. Primary and foreign keys are UNSIGNED BIGINT. `ticket_events` is
 *    append-only and gets a row for every state change, contact attempt
 *    and photo upload on every ticket, so a signed INT ceiling is a real
 *    risk rather than a theoretical one. Mixed signedness is also the
 *    single most common cause of "incompatible foreign key" errors.
 *
 * 2. Money is BIGINT paise, never DECIMAL and never a float. MySQL hands
 *    DECIMAL back to PHP as a string, and the moment anyone writes
 *    `$a + $b` on two of those they are doing float arithmetic on money.
 *    Integer minor units make that class of bug impossible. Rendering
 *    divides by 100 once, at the edge.
 */
abstract class AppMigration extends BaseMigration
{
    /**
     * Column definition for a primary or foreign key.
     *
     * @var array<string, mixed>
     */
    protected const KEY = ['signed' => false, 'null' => false];

    /**
     * Same, but for an optional relationship.
     *
     * @var array<string, mixed>
     */
    protected const NULLABLE_KEY = ['signed' => false, 'null' => true];

    /**
     * Start a table with an unsigned BIGINT autoincrement `id`.
     */
    protected function newTable(string $name, array $options = []): Table
    {
        $table = $this->table($name, $options + [
            'id' => false,
            'primary_key' => ['id'],
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        return $table->addColumn('id', 'biginteger', [
            'autoIncrement' => true,
            'signed' => false,
            'null' => false,
        ]);
    }

    /**
     * A money column, in paise.
     *
     * Signed on purpose: SLA penalties and credit notes are negative
     * amounts on the same ledger as the earnings they offset.
     */
    protected function money(Table $table, string $column, array $options = []): Table
    {
        return $table->addColumn($column, 'biginteger', $options + [
            'null' => false,
            'default' => 0,
            'signed' => true,
            'comment' => 'paise (INR minor units)',
        ]);
    }

    /**
     * A percentage, e.g. the 10% out-of-warranty royalty or a 12.5%
     * spare-part margin. Two decimal places is enough for every rate
     * that appears in a company agreement.
     */
    protected function percent(Table $table, string $column, array $options = []): Table
    {
        return $table->addColumn($column, 'decimal', $options + [
            'precision' => 5,
            'scale' => 2,
            'null' => true,
        ]);
    }

    /**
     * Cake's created/modified pair.
     */
    protected function timestamps(Table $table): Table
    {
        return $table
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false]);
    }
}
