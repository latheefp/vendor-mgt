<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Symptoms Model
 *
 * @property \App\Model\Table\ProductCategoriesTable&\Cake\ORM\Association\BelongsTo $ProductCategories
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\HasMany $Tickets
 *
 * @method \App\Model\Entity\Symptom newEmptyEntity()
 * @method \App\Model\Entity\Symptom newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\Symptom> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\Symptom get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\Symptom findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\Symptom patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\Symptom> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\Symptom|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\Symptom saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\Symptom>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Symptom>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Symptom>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Symptom> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Symptom>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Symptom>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Symptom>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Symptom> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class SymptomsTable extends Table
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

        $this->setTable('symptoms');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        // company_id null = shared baseline; set = this company's own entry.
        $this->addCompanyScope();

        $this->belongsTo('ProductCategories', [
            'foreignKey' => 'product_category_id',
        ]);
        $this->hasMany('Tickets', [
            'foreignKey' => 'symptom_id',
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
            ->allowEmptyString('product_category_id');

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
            ->maxLength('name', 128)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->scalar('description')
            ->maxLength('description', 255)
            ->allowEmptyString('description');

        $validator
            ->allowEmptyString('aliases');

        $validator
            ->boolean('is_panel_related')
            ->notEmptyString('is_panel_related');

        $validator
            ->boolean('requires_video_proof')
            ->notEmptyString('requires_video_proof');

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
        $rules->add($rules->existsIn(['product_category_id'], 'ProductCategories'), ['errorField' => 'product_category_id']);

        return $rules;
    }
}
