<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * VendorJobTypeAliases Model
 *
 * @property \App\Model\Table\VendorsTable&\Cake\ORM\Association\BelongsTo $Vendors
 * @property \App\Model\Table\JobTypesTable&\Cake\ORM\Association\BelongsTo $JobTypes
 *
 * @method \App\Model\Entity\VendorJobTypeAlias newEmptyEntity()
 * @method \App\Model\Entity\VendorJobTypeAlias newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\VendorJobTypeAlias> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\VendorJobTypeAlias get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\VendorJobTypeAlias findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\VendorJobTypeAlias patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\VendorJobTypeAlias> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\VendorJobTypeAlias|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\VendorJobTypeAlias saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\VendorJobTypeAlias>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\VendorJobTypeAlias>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\VendorJobTypeAlias>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\VendorJobTypeAlias> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\VendorJobTypeAlias>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\VendorJobTypeAlias>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\VendorJobTypeAlias>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\VendorJobTypeAlias> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class VendorJobTypeAliasesTable extends Table
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

        $this->setTable('vendor_job_type_aliases');
        $this->setDisplayField('vendor_label');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Vendors', [
            'foreignKey' => 'vendor_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('JobTypes', [
            'foreignKey' => 'job_type_id',
            'joinType' => 'INNER',
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
            ->notEmptyString('vendor_id');

        $validator
            ->notEmptyString('job_type_id');

        $validator
            ->scalar('vendor_label')
            ->maxLength('vendor_label', 96)
            ->requirePresence('vendor_label', 'create')
            ->notEmptyString('vendor_label');

        $validator
            ->scalar('warranty_scope')
            ->maxLength('warranty_scope', 24)
            ->allowEmptyString('warranty_scope');

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
        $rules->add($rules->isUnique(['vendor_id', 'vendor_label']), ['errorField' => 'vendor_id', 'message' => __('This combination of vendor_id and vendor_label already exists')]);
        $rules->add($rules->existsIn(['vendor_id'], 'Vendors'), ['errorField' => 'vendor_id']);
        $rules->add($rules->existsIn(['job_type_id'], 'JobTypes'), ['errorField' => 'job_type_id']);

        return $rules;
    }
}
