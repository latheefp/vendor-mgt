<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * HoldReasons Model
 *
 * @property \App\Model\Table\TicketHoldsTable&\Cake\ORM\Association\HasMany $TicketHolds
 *
 * @method \App\Model\Entity\HoldReason newEmptyEntity()
 * @method \App\Model\Entity\HoldReason newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\HoldReason> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\HoldReason get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\HoldReason findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\HoldReason patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\HoldReason> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\HoldReason|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\HoldReason saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\HoldReason>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\HoldReason>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\HoldReason>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\HoldReason> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\HoldReason>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\HoldReason>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\HoldReason>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\HoldReason> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class HoldReasonsTable extends Table
{
    use CompanyScopedListTrait;

    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('hold_reasons');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        // company_id null = shared baseline; set = this company's own entry.
        $this->addCompanyScope();

        $this->hasMany('TicketHolds', [
            'foreignKey' => 'hold_reason_id',
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
        // null is meaningful here: it marks the shared baseline row.
        $validator->allowEmptyString('company_id');
        $validator->scalar('override_note')->maxLength('override_note', 255)
            ->allowEmptyString('override_note');

        $validator
            ->scalar('code')
            ->maxLength('code', 48)
            ->requirePresence('code', 'create')
            ->notEmptyString('code')
            ->add('code', 'unique', [
                // Scoped: a company's override deliberately reuses the
                // code of the shared row it shadows.
                'rule' => ['validateUnique', ['scope' => ['company_id'], 'allowMultipleNulls' => false]],
                'provider' => 'table',
                'message' => 'This code is already used by this company.',
            ]);

        $validator
            ->scalar('name')
            ->maxLength('name', 128)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->scalar('description')
            ->maxLength('description', 255)
            ->allowEmptyString('description');

        $validator
            ->boolean('pauses_sla')
            ->notEmptyString('pauses_sla');

        $validator
            ->boolean('requires_company_notice')
            ->notEmptyString('requires_company_notice');

        $validator
            ->nonNegativeInteger('sort_order')
            ->notEmptyString('sort_order');

        $validator
            ->boolean('is_active')
            ->notEmptyString('is_active');

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
        $rules = $this->addCompanyScopedRules($rules);

        return $rules;
    }
}
