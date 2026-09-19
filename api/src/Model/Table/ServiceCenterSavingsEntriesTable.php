<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * ServiceCenterSavingsEntries Model
 *
 * @property \App\Model\Table\ServiceCentersTable&\Cake\ORM\Association\BelongsTo $ServiceCenters
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $CreatedByUsers
 *
 * @method \App\Model\Entity\ServiceCenterSavingsEntry newEmptyEntity()
 * @method \App\Model\Entity\ServiceCenterSavingsEntry newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\ServiceCenterSavingsEntry> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\ServiceCenterSavingsEntry get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\ServiceCenterSavingsEntry findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\ServiceCenterSavingsEntry patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\ServiceCenterSavingsEntry> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\ServiceCenterSavingsEntry|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\ServiceCenterSavingsEntry saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\ServiceCenterSavingsEntry>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\ServiceCenterSavingsEntry>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\ServiceCenterSavingsEntry>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\ServiceCenterSavingsEntry> saveManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class ServiceCenterSavingsEntriesTable extends Table
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

        $this->setTable('service_center_savings_entries');
        $this->setDisplayField('description');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('ServiceCenters', [
            'foreignKey' => 'service_center_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('CreatedByUsers', [
            'foreignKey' => 'created_by_user_id',
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
            ->notEmptyString('service_center_id');

        $validator
            ->scalar('entry_type')
            ->inList('entry_type', ['credit', 'debit'])
            ->notEmptyString('entry_type');

        $validator
            ->scalar('source_type')
            ->maxLength('source_type', 32)
            ->notEmptyString('source_type');

        $validator
            ->allowEmptyString('source_id');

        $validator
            ->notEmptyString('amount_paise');

        $validator
            ->notEmptyString('balance_after_paise');

        $validator
            ->scalar('description')
            ->maxLength('description', 255)
            ->notEmptyString('description');

        $validator
            ->allowEmptyString('created_by_user_id');

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
        $rules->add($rules->existsIn(['service_center_id'], 'ServiceCenters'), ['errorField' => 'service_center_id']);
        $rules->add($rules->existsIn(['created_by_user_id'], 'CreatedByUsers'), ['errorField' => 'created_by_user_id']);

        return $rules;
    }
}
