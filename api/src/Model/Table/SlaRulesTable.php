<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * SlaRules Model
 *
 * @property \App\Model\Table\RateCardsTable&\Cake\ORM\Association\BelongsTo $RateCards
 * @property \App\Model\Table\TicketChargesTable&\Cake\ORM\Association\HasMany $TicketCharges
 *
 * @method \App\Model\Entity\SlaRule newEmptyEntity()
 * @method \App\Model\Entity\SlaRule newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\SlaRule> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\SlaRule get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\SlaRule findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\SlaRule patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\SlaRule> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\SlaRule|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\SlaRule saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\SlaRule>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\SlaRule>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\SlaRule>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\SlaRule> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\SlaRule>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\SlaRule>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\SlaRule>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\SlaRule> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class SlaRulesTable extends Table
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

        $this->setTable('sla_rules');
        $this->setDisplayField('label');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('RateCards', [
            'foreignKey' => 'rate_card_id',
            'joinType' => 'INNER',
        ]);
        $this->hasMany('TicketCharges', [
            'foreignKey' => 'sla_rule_id',
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
            ->notEmptyString('rate_card_id');

        $validator
            ->scalar('code')
            ->maxLength('code', 64)
            ->requirePresence('code', 'create')
            ->notEmptyString('code');

        $validator
            ->scalar('label')
            ->maxLength('label', 190)
            ->requirePresence('label', 'create')
            ->notEmptyString('label');

        $validator
            ->scalar('kind')
            ->maxLength('kind', 16)
            ->requirePresence('kind', 'create')
            ->notEmptyString('kind');

        $validator
            ->scalar('metric')
            ->maxLength('metric', 32)
            ->requirePresence('metric', 'create')
            ->notEmptyString('metric');

        $validator
            ->scalar('comparator')
            ->maxLength('comparator', 16)
            ->requirePresence('comparator', 'create')
            ->notEmptyString('comparator');

        $validator
            ->decimal('threshold_from_hours')
            ->allowEmptyString('threshold_from_hours');

        $validator
            ->decimal('threshold_to_hours')
            ->allowEmptyString('threshold_to_hours');

        $validator
            ->notEmptyString('amount_paise');

        $validator
            ->allowEmptyString('applies_to_job_types');

        $validator
            ->scalar('warranty_scope')
            ->maxLength('warranty_scope', 24)
            ->allowEmptyString('warranty_scope');

        $validator
            ->nonNegativeInteger('priority')
            ->notEmptyString('priority');

        $validator
            ->boolean('is_stackable')
            ->notEmptyString('is_stackable');

        $validator
            ->boolean('is_active')
            ->notEmptyString('is_active');

        $validator
            ->scalar('notes')
            ->allowEmptyString('notes');

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
        $rules->add($rules->isUnique(['rate_card_id', 'code']), ['errorField' => 'rate_card_id', 'message' => __('This combination of rate_card_id and code already exists')]);
        $rules->add($rules->existsIn(['rate_card_id'], 'RateCards'), ['errorField' => 'rate_card_id']);

        return $rules;
    }
}
