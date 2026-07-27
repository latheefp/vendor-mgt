<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * JobTypes Model
 *
 * @property \App\Model\Table\RateCardItemsTable&\Cake\ORM\Association\HasMany $RateCardItems
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\HasMany $Tickets
 * @property \App\Model\Table\VendorJobTypeAliasesTable&\Cake\ORM\Association\HasMany $VendorJobTypeAliases
 *
 * @method \App\Model\Entity\JobType newEmptyEntity()
 * @method \App\Model\Entity\JobType newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\JobType> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\JobType get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\JobType findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\JobType patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\JobType> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\JobType|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\JobType saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\JobType>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\JobType>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\JobType>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\JobType> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\JobType>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\JobType>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\JobType>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\JobType> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class JobTypesTable extends Table
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

        $this->setTable('job_types');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        // vendor_id null = shared baseline; set = this company's own entry.
        $this->addCompanyScope();

        $this->hasMany('RateCardItems', [
            'foreignKey' => 'job_type_id',
        ]);
        $this->hasMany('Tickets', [
            'foreignKey' => 'job_type_id',
        ]);
        $this->hasMany('VendorJobTypeAliases', [
            'foreignKey' => 'job_type_id',
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
        $validator->allowEmptyString('vendor_id');
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
                'rule' => ['validateUnique', ['scope' => ['vendor_id'], 'allowMultipleNulls' => false]],
                'provider' => 'table',
                'message' => 'This code is already used by this company.',
            ]);

        $validator
            ->scalar('name')
            ->maxLength('name', 96)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->scalar('description')
            ->maxLength('description', 255)
            ->allowEmptyString('description');

        $validator
            ->boolean('is_size_banded')
            ->notEmptyString('is_size_banded');

        $validator
            ->boolean('requires_warranty_scope')
            ->notEmptyString('requires_warranty_scope');

        $validator
            ->boolean('is_customer_billable')
            ->notEmptyString('is_customer_billable');

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
