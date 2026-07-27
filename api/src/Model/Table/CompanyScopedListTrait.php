<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;

/**
 * Shared behaviour for a master list that a company may override.
 *
 * Five tables carry the same convention — job types, product categories,
 * symptoms, resolutions and hold reasons — and the convention is easy to
 * get subtly wrong in one of them. Notably the uniqueness rule: `code` is
 * NOT unique any more, only unique per owner, and a table that kept the
 * old `isUnique(['code'])` would reject a company's override as a
 * duplicate of the shared row it is meant to shadow.
 *
 * The database enforces this too, on COALESCE(vendor_id, 0). The rule here
 * exists so the failure arrives as a field error on a form rather than as
 * a driver exception.
 */
trait CompanyScopedListTrait
{
    /**
     * Call from initialize() after the table is configured.
     */
    protected function addCompanyScope(): void
    {
        $this->belongsTo('Vendors', [
            'foreignKey' => 'vendor_id',
            // Optional: a null owner is the shared baseline, not a missing
            // relationship, and marking it required would reject every
            // shared row on save.
            'joinType' => 'LEFT',
        ]);
    }

    /**
     * Uniqueness scoped to the owning company.
     */
    protected function addCompanyScopedRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->isUnique(
                ['code', 'vendor_id'],
                // Without this, two shared rows both coded 'service' pass
                // the rule: Cake follows SQL and treats NULL vendor_id as
                // never equal to itself. The database index uses COALESCE
                // for the same reason.
                ['allowMultipleNulls' => false],
            ),
            'uniqueCodePerCompany',
            [
                'errorField' => 'code',
                'message' => __('This code is already used by this company.'),
            ],
        );

        $rules->add($rules->existsIn(['vendor_id'], 'Vendors'), ['errorField' => 'vendor_id']);

        return $rules;
    }

    /**
     * Rows visible to one company: its own, plus the shared baseline.
     *
     * Returns both layers rather than the resolved list — shadowing needs
     * to compare them, so collapsing here would throw away what the caller
     * needs. Use CompanyConfigRepository::masterList() for the resolved
     * answer.
     *
     * @param array{vendor_id?: int|null} $options
     */
    public function findForCompany(SelectQuery $query, array $options = []): SelectQuery
    {
        $vendorId = $options['vendor_id'] ?? null;

        if ($vendorId === null) {
            return $query->where([$this->getAlias() . '.vendor_id IS' => null]);
        }

        return $query->where([
            'OR' => [
                $this->getAlias() . '.vendor_id IS' => null,
                $this->getAlias() . '.vendor_id' => (int)$vendorId,
            ],
        ]);
    }

    /**
     * Only the shared baseline.
     */
    public function findShared(SelectQuery $query): SelectQuery
    {
        return $query->where([$this->getAlias() . '.vendor_id IS' => null]);
    }
}
