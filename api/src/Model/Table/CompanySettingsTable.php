<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Domain\Company\SettingCatalog;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * CompanySettings Model
 *
 * The storage half of per-company settings. The meaning half — what a key
 * is, what type it holds, what it defaults to — lives in SettingCatalog,
 * and reads should go through CompanyConfigRepository rather than here so
 * the platform/company layering is applied.
 *
 * @property \App\Model\Table\CompaniesTable&\Cake\ORM\Association\BelongsTo $Companies
 *
 * @method \App\Model\Entity\CompanySetting newEmptyEntity()
 * @method \App\Model\Entity\CompanySetting newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\CompanySetting get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\CompanySetting patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\CompanySetting|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\CompanySetting saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class CompanySettingsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('company_settings');
        $this->setDisplayField('setting_key');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Companies', [
            'foreignKey' => 'company_id',
            // Null is the platform default, not a missing company.
            'joinType' => 'LEFT',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->allowEmptyString('company_id');

        $validator
            ->scalar('setting_key')
            ->maxLength('setting_key', 96)
            ->requirePresence('setting_key', 'create')
            ->notEmptyString('setting_key')
            // A key with no definition has no reader: the write would
            // succeed and the setting would never take effect, which is
            // worse than a rejection because nothing reports it.
            ->add('setting_key', 'known', [
                'rule' => fn (string $value): bool => SettingCatalog::has($value),
                'message' => 'This is not a setting the application reads.',
            ]);

        $validator
            ->scalar('value_type')
            ->inList('value_type', ['string', 'integer', 'decimal', 'boolean', 'json'])
            ->notEmptyString('value_type');

        $validator
            ->scalar('value')
            ->allowEmptyString('value');

        $validator
            ->scalar('label')
            ->maxLength('label', 190)
            ->allowEmptyString('label');

        $validator
            ->scalar('description')
            ->maxLength('description', 255)
            ->allowEmptyString('description');

        $validator
            ->boolean('is_editable')
            ->notEmptyString('is_editable');

        $validator
            ->boolean('is_active')
            ->notEmptyString('is_active');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->isUnique(
                ['company_id', 'setting_key'],
                // Mirrors the COALESCE index: without this, several
                // platform-default rows for one key would all be accepted.
                ['allowMultipleNulls' => false],
            ),
            'uniqueKeyPerCompany',
            [
                'errorField' => 'setting_key',
                'message' => __('This setting already has a value for this company.'),
            ],
        );

        $rules->add($rules->existsIn(['company_id'], 'Companies'), ['errorField' => 'company_id']);

        return $rules;
    }
}
