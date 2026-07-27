<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * TicketSequence Entity
 *
 * The counter behind ticket numbers, one row per company per period.
 *
 * Written to only by TicketNumberAllocator, and only through a single
 * atomic INSERT .. ON DUPLICATE KEY UPDATE. Patching `last_value` through
 * the ORM would reintroduce the read-then-write race the table exists to
 * remove, so it is not mass assignable.
 *
 * @property int $id
 * @property int $vendor_id
 * @property string $period
 * @property int $last_value
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class TicketSequence extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'vendor_id' => true,
        'period' => true,
    ];
}
