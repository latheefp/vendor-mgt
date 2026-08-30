<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * ProductCategories Model
 *
 * @property \App\Model\Table\ProductsTable&\Cake\ORM\Association\HasMany $Products
 * @property \App\Model\Table\RateCardItemsTable&\Cake\ORM\Association\HasMany $RateCardItems
 * @property \App\Model\Table\SparePartsTable&\Cake\ORM\Association\HasMany $SpareParts
 * @property \App\Model\Table\SymptomsTable&\Cake\ORM\Association\HasMany $Symptoms
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\HasMany $Tickets
 *
 * @method \App\Model\Entity\ProductCategory newEmptyEntity()
 * @method \App\Model\Entity\ProductCategory newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\ProductCategory> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\ProductCategory get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\ProductCategory findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\ProductCategory patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\ProductCategory> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\ProductCategory|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\ProductCategory saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\ProductCategory>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\ProductCategory>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\ProductCategory>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\ProductCategory> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\ProductCategory>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\ProductCategory>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\ProductCategory>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\ProductCategory> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class ProductCategoriesTable extends Table
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

        $this->setTable('product_categories');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        // company_id null = shared baseline; set = this company's own entry.
        $this->addCompanyScope();

        $this->hasMany('Products', [
            'foreignKey' => 'product_category_id',
        ]);
        $this->hasMany('RateCardItems', [
            'foreignKey' => 'product_category_id',
        ]);
        $this->hasMany('SpareParts', [
            'foreignKey' => 'product_category_id',
        ]);
        $this->hasMany('Symptoms', [
            'foreignKey' => 'product_category_id',
        ]);
        $this->hasMany('Tickets', [
            'foreignKey' => 'product_category_id',
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
        $validator->allowEmptyString('company_id');
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
                'rule' => ['validateUnique', ['scope' => ['company_id'], 'allowMultipleNulls' => false]],
                'provider' => 'table',
                'message' => 'This code is already used by this company.',
            ]);

        $validator
            ->scalar('name')
            ->maxLength('name', 96)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->boolean('is_sized')
            ->notEmptyString('is_sized');

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
