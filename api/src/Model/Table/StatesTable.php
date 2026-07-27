<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * States Model
 *
 * @property \App\Model\Table\CustomersTable&\Cake\ORM\Association\HasMany $Customers
 * @property \App\Model\Table\DistrictsTable&\Cake\ORM\Association\HasMany $Districts
 *
 * @method \App\Model\Entity\State newEmptyEntity()
 * @method \App\Model\Entity\State newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\State> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\State get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\State findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\State patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\State> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\State|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\State saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\State>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\State>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\State>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\State> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\State>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\State>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\State>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\State> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class StatesTable extends Table
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

        $this->setTable('states');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('Customers', [
            'foreignKey' => 'state_id',
        ]);
        $this->hasMany('Districts', [
            'foreignKey' => 'state_id',
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
            ->scalar('code')
            ->maxLength('code', 8)
            ->requirePresence('code', 'create')
            ->notEmptyString('code')
            ->add('code', 'unique', ['rule' => 'validateUnique', 'provider' => 'table']);

        $validator
            ->scalar('name')
            ->maxLength('name', 96)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

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
        $rules->add($rules->isUnique(['code']), ['errorField' => 'code']);

        return $rules;
    }
}
