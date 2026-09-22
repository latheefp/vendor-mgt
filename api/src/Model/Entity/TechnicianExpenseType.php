<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * TechnicianExpenseType Entity
 *
 * @property int $id
 * @property int|null $company_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $override_note
 * @property int $sort_order
 * @property bool $is_active
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\Company $company
 * @property \App\Model\Entity\TicketCharge[] $ticket_charges
 */
class TechnicianExpenseType extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'company_id' => true,
        'code' => true,
        'name' => true,
        'description' => true,
        'override_note' => true,
        'sort_order' => true,
        'is_active' => true,
        'created' => true,
        'modified' => true,
        'company' => true,
        'ticket_charges' => true,
    ];
}
