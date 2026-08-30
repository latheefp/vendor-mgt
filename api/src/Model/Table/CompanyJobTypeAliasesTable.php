<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * CompanyJobTypeAliases Model
 *
 * @property \App\Model\Table\CompaniesTable&\Cake\ORM\Association\BelongsTo $Companies
 * @property \App\Model\Table\JobTypesTable&\Cake\ORM\Association\BelongsTo $JobTypes
 *
 * @method \App\Model\Entity\CompanyJobTypeAlias newEmptyEntity()
 * @method \App\Model\Entity\CompanyJobTypeAlias newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\CompanyJobTypeAlias> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\CompanyJobTypeAlias get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\CompanyJobTypeAlias findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\CompanyJobTypeAlias patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\CompanyJobTypeAlias> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\CompanyJobTypeAlias|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\CompanyJobTypeAlias saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\CompanyJobTypeAlias>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CompanyJobTypeAlias>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CompanyJobTypeAlias>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CompanyJobTypeAlias> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CompanyJobTypeAlias>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CompanyJobTypeAlias>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CompanyJobTypeAlias>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CompanyJobTypeAlias> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class CompanyJobTypeAliasesTable extends Table
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

        $this->setTable('company_job_type_aliases');
        $this->setDisplayField('company_label');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Companies', [
            'foreignKey' => 'company_id',
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
            ->notEmptyString('company_id');

        $validator
            ->notEmptyString('job_type_id');

        $validator
            ->scalar('company_label')
            ->maxLength('company_label', 96)
            ->requirePresence('company_label', 'create')
            ->notEmptyString('company_label');

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
        $rules->add($rules->isUnique(['company_id', 'company_label']), ['errorField' => 'company_id', 'message' => __('This combination of company_id and company_label already exists')]);
        $rules->add($rules->existsIn(['company_id'], 'Companies'), ['errorField' => 'company_id']);
        $rules->add($rules->existsIn(['job_type_id'], 'JobTypes'), ['errorField' => 'job_type_id']);

        return $rules;
    }
}
