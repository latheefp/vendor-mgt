<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * ServiceCenters Model
 *
 * @property \App\Model\Table\TechniciansTable&\Cake\ORM\Association\HasMany $Technicians
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\HasMany $Tickets
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\HasMany $Users
 *
 * @method \App\Model\Entity\ServiceCenter newEmptyEntity()
 * @method \App\Model\Entity\ServiceCenter newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\ServiceCenter> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\ServiceCenter get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\ServiceCenter findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\ServiceCenter patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\ServiceCenter> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\ServiceCenter|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\ServiceCenter saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\ServiceCenter>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\ServiceCenter>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\ServiceCenter>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\ServiceCenter> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\ServiceCenter>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\ServiceCenter>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\ServiceCenter>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\ServiceCenter> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class ServiceCentersTable extends Table
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

        $this->setTable('service_centers');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('Technicians', [
            'foreignKey' => 'service_center_id',
        ]);
        $this->hasMany('Tickets', [
            'foreignKey' => 'service_center_id',
        ]);
        $this->hasMany('Users', [
            'foreignKey' => 'service_center_id',
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
            ->maxLength('code', 32)
            ->requirePresence('code', 'create')
            ->notEmptyString('code')
            ->add('code', 'unique', ['rule' => 'validateUnique', 'provider' => 'table']);

        $validator
            ->scalar('name')
            ->maxLength('name', 128)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->scalar('contact_person')
            ->maxLength('contact_person', 128)
            ->allowEmptyString('contact_person');

        $validator
            ->scalar('phone')
            ->maxLength('phone', 24)
            ->allowEmptyString('phone');

        $validator
            ->email('email')
            ->allowEmptyString('email');

        $validator
            ->scalar('address_line1')
            ->maxLength('address_line1', 255)
            ->allowEmptyString('address_line1');

        $validator
            ->scalar('address_line2')
            ->maxLength('address_line2', 255)
            ->allowEmptyString('address_line2');

        $validator
            ->scalar('city')
            ->maxLength('city', 96)
            ->allowEmptyString('city');

        $validator
            ->scalar('district')
            ->maxLength('district', 96)
            ->allowEmptyString('district');

        $validator
            ->scalar('state')
            ->maxLength('state', 96)
            ->allowEmptyString('state');

        $validator
            ->scalar('pincode')
            ->maxLength('pincode', 12)
            ->allowEmptyString('pincode');

        $validator
            ->decimal('latitude')
            ->allowEmptyString('latitude');

        $validator
            ->decimal('longitude')
            ->allowEmptyString('longitude');

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
