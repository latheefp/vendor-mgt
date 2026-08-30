<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Companies Model
 *
 * @property \App\Model\Table\BrandsTable&\Cake\ORM\Association\HasMany $Brands
 * @property \App\Model\Table\ProductsTable&\Cake\ORM\Association\HasMany $Products
 * @property \App\Model\Table\RateCardsTable&\Cake\ORM\Association\HasMany $RateCards
 * @property \App\Model\Table\SparePartsTable&\Cake\ORM\Association\HasMany $SpareParts
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\HasMany $Tickets
 * @property \App\Model\Table\CompanyAgreementsTable&\Cake\ORM\Association\HasMany $CompanyAgreements
 * @property \App\Model\Table\CompanyInvoicesTable&\Cake\ORM\Association\HasMany $CompanyInvoices
 * @property \App\Model\Table\CompanyJobTypeAliasesTable&\Cake\ORM\Association\HasMany $CompanyJobTypeAliases
 *
 * @method \App\Model\Entity\Company newEmptyEntity()
 * @method \App\Model\Entity\Company newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\Company> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\Company get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\Company findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\Company patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\Company> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\Company|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\Company saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\Company>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Company>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Company>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Company> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Company>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Company>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Company>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Company> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class CompaniesTable extends Table
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

        $this->setTable('companies');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('Brands', [
            'foreignKey' => 'company_id',
        ]);
        $this->hasMany('Products', [
            'foreignKey' => 'company_id',
        ]);
        $this->hasMany('RateCards', [
            'foreignKey' => 'company_id',
        ]);
        $this->hasMany('SpareParts', [
            'foreignKey' => 'company_id',
        ]);
        $this->hasMany('Tickets', [
            'foreignKey' => 'company_id',
        ]);
        $this->hasMany('CompanyAgreements', [
            'foreignKey' => 'company_id',
        ]);
        $this->hasMany('CompanyInvoices', [
            'foreignKey' => 'company_id',
        ]);
        $this->hasMany('CompanyJobTypeAliases', [
            'foreignKey' => 'company_id',
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
            ->scalar('legal_name')
            ->maxLength('legal_name', 190)
            ->allowEmptyString('legal_name');

        $validator
            ->scalar('gstin')
            ->maxLength('gstin', 20)
            ->allowEmptyString('gstin');

        $validator
            ->scalar('contact_person')
            ->maxLength('contact_person', 128)
            ->allowEmptyString('contact_person');

        $validator
            ->scalar('phone')
            ->maxLength('phone', 24)
            ->allowEmptyString('phone');

        $validator
            ->scalar('support_phone')
            ->maxLength('support_phone', 24)
            ->allowEmptyString('support_phone');

        $validator
            ->scalar('communication_email')
            ->maxLength('communication_email', 190)
            ->allowEmptyString('communication_email');

        $validator
            ->scalar('accounts_email')
            ->maxLength('accounts_email', 190)
            ->allowEmptyString('accounts_email');

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
            ->scalar('state')
            ->maxLength('state', 96)
            ->allowEmptyString('state');

        $validator
            ->scalar('pincode')
            ->maxLength('pincode', 12)
            ->allowEmptyString('pincode');

        $validator
            ->scalar('website')
            ->maxLength('website', 190)
            ->allowEmptyString('website');

        $validator
            ->scalar('logo_path')
            ->maxLength('logo_path', 255)
            ->allowEmptyString('logo_path');

        $validator
            ->boolean('is_active')
            ->notEmptyString('is_active');

        $validator
            ->date('onboarded_on')
            ->allowEmptyDate('onboarded_on');

        $validator
            ->scalar('notes')
            ->allowEmptyString('notes');

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
