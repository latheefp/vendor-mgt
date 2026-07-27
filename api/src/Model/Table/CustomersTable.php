<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Customers Model
 *
 * @property \App\Model\Table\StatesTable&\Cake\ORM\Association\BelongsTo $States
 * @property \App\Model\Table\DistrictsTable&\Cake\ORM\Association\BelongsTo $Districts
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\HasMany $Tickets
 *
 * @method \App\Model\Entity\Customer newEmptyEntity()
 * @method \App\Model\Entity\Customer newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\Customer> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\Customer get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\Customer findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\Customer patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\Customer> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\Customer|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\Customer saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\Customer>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Customer>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Customer>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Customer> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Customer>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Customer>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Customer>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Customer> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class CustomersTable extends Table
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

        $this->setTable('customers');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        // `customers` carries both the free-text `state`/`district` the desk
        // typed and the FK to the master row. The default property names for
        // these associations would be `state`/`district` and collide with
        // those columns — a collision Cake only reports as an E_USER_WARNING,
        // which in debug mode is echoed mid-request and corrupts the
        // response. Name the associated entities explicitly.
        $this->belongsTo('States', [
            'foreignKey' => 'state_id',
            'propertyName' => 'state_ref',
        ]);
        $this->belongsTo('Districts', [
            'foreignKey' => 'district_id',
            'propertyName' => 'district_ref',
        ]);
        $this->hasMany('Tickets', [
            'foreignKey' => 'customer_id',
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
            ->scalar('name')
            ->maxLength('name', 128)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->scalar('phone')
            ->maxLength('phone', 24)
            ->requirePresence('phone', 'create')
            ->notEmptyString('phone');

        $validator
            ->scalar('alt_phone')
            ->maxLength('alt_phone', 24)
            ->allowEmptyString('alt_phone');

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
            ->scalar('landmark')
            ->maxLength('landmark', 190)
            ->allowEmptyString('landmark');

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
            ->scalar('source')
            ->maxLength('source', 24)
            ->notEmptyString('source');

        $validator
            ->scalar('notes')
            ->allowEmptyString('notes');

        $validator
            ->allowEmptyString('state_id');

        $validator
            ->allowEmptyString('district_id');

        $validator
            ->scalar('place')
            ->maxLength('place', 128)
            ->allowEmptyString('place');

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
        $rules->add($rules->existsIn(['state_id'], 'States'), ['errorField' => 'state_id']);
        $rules->add($rules->existsIn(['district_id'], 'Districts'), ['errorField' => 'district_id']);

        return $rules;
    }
}
