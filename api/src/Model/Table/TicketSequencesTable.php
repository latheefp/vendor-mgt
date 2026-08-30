<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * TicketSequences Model
 *
 * Exists so TicketNumberAllocator has a connection and a table to read
 * back from. Allocation itself is raw SQL by necessity — see the allocator
 * for why a SELECT-then-UPDATE is not safe here.
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class TicketSequencesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('ticket_sequences');
        $this->setDisplayField('period');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Companies', ['foreignKey' => 'company_id', 'joinType' => 'INNER']);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('company_id')
            ->notEmptyString('company_id');

        $validator
            ->scalar('period')
            ->maxLength('period', 16)
            ->notEmptyString('period');

        return $validator;
    }
}
