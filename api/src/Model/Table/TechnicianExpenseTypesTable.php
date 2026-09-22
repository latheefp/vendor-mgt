<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * TechnicianExpenseTypes Model
 *
 * The editable catalogue of what a non-rate-card technician cost is
 * called — "Bata / Transport", "Service charge", "Lump sum payment" — so
 * the desk can add a new one from settings without a deploy. See the
 * migration for why this exists at all.
 *
 * @property \App\Model\Table\CompaniesTable&\Cake\ORM\Association\BelongsTo $Companies
 * @property \App\Model\Table\TicketChargesTable&\Cake\ORM\Association\HasMany $TicketCharges
 * @method \App\Model\Entity\TechnicianExpenseType newEmptyEntity()
 * @method \App\Model\Entity\TechnicianExpenseType newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\TechnicianExpenseType> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\TechnicianExpenseType get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\TechnicianExpenseType findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\TechnicianExpenseType patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\TechnicianExpenseType> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\TechnicianExpenseType|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\TechnicianExpenseType saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\TechnicianExpenseType>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TechnicianExpenseType>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TechnicianExpenseType>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TechnicianExpenseType> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TechnicianExpenseType>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TechnicianExpenseType>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TechnicianExpenseType>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TechnicianExpenseType> deleteManyOrFail(iterable $entities, array $options = [])
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class TechnicianExpenseTypesTable extends Table
{
    use CompanyScopedListTrait;

    /**
     * @param array<string, mixed> $config The configuration for the Table.
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('technician_expense_types');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        // company_id null = shared baseline; set = this company's own entry.
        $this->addCompanyScope();

        $this->hasMany('TicketCharges', [
            'foreignKey' => 'technician_expense_type_id',
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
            ->nonNegativeInteger('sort_order')
            ->allowEmptyString('sort_order');

        $validator
            ->boolean('is_active')
            ->allowEmptyString('is_active');

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
        return $this->addCompanyScopedRules($rules);
    }
}
