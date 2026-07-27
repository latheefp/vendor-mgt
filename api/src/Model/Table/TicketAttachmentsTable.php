<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * TicketAttachments Model
 *
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\BelongsTo $Tickets
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $UploadedByUsers
 *
 * @method \App\Model\Entity\TicketAttachment newEmptyEntity()
 * @method \App\Model\Entity\TicketAttachment newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\TicketAttachment> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\TicketAttachment get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\TicketAttachment findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\TicketAttachment patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\TicketAttachment> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\TicketAttachment|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\TicketAttachment saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\TicketAttachment>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketAttachment>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketAttachment>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketAttachment> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketAttachment>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketAttachment>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketAttachment>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketAttachment> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class TicketAttachmentsTable extends Table
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

        $this->setTable('ticket_attachments');
        $this->setDisplayField('kind');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Tickets', [
            'foreignKey' => 'ticket_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('UploadedByUsers', [
            'foreignKey' => 'uploaded_by_user_id',
            'className' => 'Users',
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
            ->notEmptyString('ticket_id');

        $validator
            ->scalar('kind')
            ->maxLength('kind', 32)
            ->requirePresence('kind', 'create')
            ->notEmptyString('kind');

        $validator
            ->scalar('storage_disk')
            ->maxLength('storage_disk', 24)
            ->notEmptyString('storage_disk');

        $validator
            ->scalar('storage_path')
            ->maxLength('storage_path', 512)
            ->requirePresence('storage_path', 'create')
            ->notEmptyString('storage_path');

        $validator
            ->scalar('original_name')
            ->maxLength('original_name', 255)
            ->allowEmptyString('original_name');

        $validator
            ->scalar('mime_type')
            ->maxLength('mime_type', 96)
            ->allowEmptyString('mime_type');

        $validator
            ->nonNegativeInteger('size_bytes')
            ->allowEmptyString('size_bytes');

        $validator
            ->nonNegativeInteger('width')
            ->allowEmptyString('width');

        $validator
            ->nonNegativeInteger('height')
            ->allowEmptyString('height');

        $validator
            ->scalar('sha256')
            ->maxLength('sha256', 64)
            ->allowEmptyString('sha256');

        $validator
            ->dateTime('exif_taken_at')
            ->allowEmptyDateTime('exif_taken_at');

        $validator
            ->decimal('captured_latitude')
            ->allowEmptyString('captured_latitude');

        $validator
            ->decimal('captured_longitude')
            ->allowEmptyString('captured_longitude');

        $validator
            ->allowEmptyString('uploaded_by_user_id');

        $validator
            ->dateTime('uploaded_at')
            ->requirePresence('uploaded_at', 'create')
            ->notEmptyDateTime('uploaded_at');

        $validator
            ->boolean('is_verified')
            ->notEmptyString('is_verified');

        $validator
            ->scalar('verification_note')
            ->maxLength('verification_note', 255)
            ->allowEmptyString('verification_note');

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
        $rules->add($rules->existsIn(['ticket_id'], 'Tickets'), ['errorField' => 'ticket_id']);
        $rules->add($rules->existsIn(['uploaded_by_user_id'], 'UploadedByUsers'), ['errorField' => 'uploaded_by_user_id']);

        return $rules;
    }
}
