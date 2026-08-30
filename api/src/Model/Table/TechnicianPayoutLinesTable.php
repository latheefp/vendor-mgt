<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * TechnicianPayoutLines Model
 *
 * @property \App\Model\Table\TechnicianPayoutsTable&\Cake\ORM\Association\BelongsTo $TechnicianPayouts
 * @property \App\Model\Table\TicketChargesTable&\Cake\ORM\Association\BelongsTo $TicketCharges
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\BelongsTo $Tickets
 *
 * @method \App\Model\Entity\TechnicianPayoutLine newEmptyEntity()
 * @method \App\Model\Entity\TechnicianPayoutLine newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\TechnicianPayoutLine> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\TechnicianPayoutLine get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\TechnicianPayoutLine findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\TechnicianPayoutLine patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\TechnicianPayoutLine> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\TechnicianPayoutLine|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\TechnicianPayoutLine saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\TechnicianPayoutLine>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TechnicianPayoutLine>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TechnicianPayoutLine>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TechnicianPayoutLine> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TechnicianPayoutLine>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TechnicianPayoutLine>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TechnicianPayoutLine>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TechnicianPayoutLine> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class TechnicianPayoutLinesTable extends Table
{
    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('technician_payout_lines');
        $this->setDisplayField('description');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('TechnicianPayouts', [
            'foreignKey' => 'technician_payout_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('TicketCharges', [
            'foreignKey' => 'ticket_charge_id',
        ]);
        $this->belongsTo('Tickets', [
            'foreignKey' => 'ticket_id',
        ]);
        $this->belongsTo('OverriddenByUsers', [
            'foreignKey' => 'overridden_by_user_id',
            'className' => 'Users',
        ]);
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->notEmptyString('technician_payout_id');

        $validator
            ->allowEmptyString('ticket_charge_id');

        $validator
            ->allowEmptyString('ticket_id');

        $validator
            ->scalar('line_type')
            ->maxLength('line_type', 32)
            ->notEmptyString('line_type');

        $validator
            ->scalar('description')
            ->maxLength('description', 255)
            ->requirePresence('description', 'create')
            ->notEmptyString('description');

        $validator
            ->notEmptyString('amount_paise');

        $validator
            ->allowEmptyString('original_amount_paise');

        // See CompanyInvoiceLinesTable: the reason is enforced by the service
        // that restates a line, not by every line a normal run writes.
        $validator
            ->scalar('override_reason')
            ->maxLength('override_reason', 255)
            ->allowEmptyString('override_reason');

        $validator
            ->dateTime('overridden_at')
            ->allowEmptyDateTime('overridden_at');

        $validator
            ->allowEmptyString('overridden_by_user_id');

        $validator
            ->nonNegativeInteger('sort_order')
            ->notEmptyString('sort_order');

        return $validator;
    }

    /**
     * Returns a rules checker object that will be used for validating
     * application integrity.
     *
     * @param \Cake\ORM\RulesChecker $rules The rules object to be modified.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn(['technician_payout_id'], 'TechnicianPayouts'), ['errorField' => 'technician_payout_id']);
        $rules->add($rules->existsIn(['ticket_charge_id'], 'TicketCharges'), ['errorField' => 'ticket_charge_id']);
        $rules->add($rules->existsIn(['ticket_id'], 'Tickets'), ['errorField' => 'ticket_id']);
        $rules->add(
            $rules->existsIn(['overridden_by_user_id'], 'OverriddenByUsers'),
            ['errorField' => 'overridden_by_user_id'],
        );

        return $rules;
    }
}
