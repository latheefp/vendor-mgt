<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Districts Model
 *
 * @property \App\Model\Table\StatesTable&\Cake\ORM\Association\BelongsTo $States
 * @property \App\Model\Table\CustomersTable&\Cake\ORM\Association\HasMany $Customers
 *
 * @method \App\Model\Entity\District newEmptyEntity()
 * @method \App\Model\Entity\District newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\District> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\District get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\District findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\District patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\District> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\District|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\District saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\District>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\District>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\District>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\District> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\District>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\District>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\District>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\District> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class DistrictsTable extends Table
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

        $this->setTable('districts');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('States', [
            'foreignKey' => 'state_id',
            'joinType' => 'INNER',
        ]);
        $this->hasMany('Customers', [
            'foreignKey' => 'district_id',
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
            ->notEmptyString('state_id');

        $validator
            ->scalar('code')
            ->maxLength('code', 16)
            ->requirePresence('code', 'create')
            ->notEmptyString('code');

        $validator
            ->scalar('name')
            ->maxLength('name', 96)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->allowEmptyString('aliases');

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
        $rules->add($rules->isUnique(['state_id', 'code']), ['errorField' => 'state_id', 'message' => __('This combination of state_id and code already exists')]);
        $rules->add($rules->existsIn(['state_id'], 'States'), ['errorField' => 'state_id']);

        return $rules;
    }
}
