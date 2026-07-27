<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\Company\CompanySettings;
use App\Domain\Company\SettingCatalog;
use Cake\Cache\Cache;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Everything a company can be configured with, resolved into an answer.
 *
 * Two layers exist in the database and neither is the whole truth:
 *
 *   vendor_id IS NULL     the shared baseline — the vocabulary and defaults
 *                         every company starts from
 *   vendor_id = <id>      that company's own rows, which shadow the shared
 *                         ones sharing a code
 *
 * Callers want the resolved answer, not the layering, so the merge happens
 * here exactly once. Doing it in the caller is how two screens end up
 * disagreeing about whether a company has a hold reason.
 *
 * Results are cached because the merge runs on every ticket form load and
 * settings change perhaps monthly. Every write through this class clears
 * the company's entry, so a stale read is not possible through the normal
 * path — direct SQL against the tables is, which is why nothing else in
 * the application writes to them.
 */
class CompanyConfigRepository
{
    use LocatorAwareTrait;

    /**
     * Master list name => the table that backs it.
     *
     * Deliberately excludes states and districts: geography is shared by
     * everyone, and per-company copies of Kerala would fragment the one
     * dimension every cross-company report groups by.
     *
     * @var array<string, string>
     */
    public const MASTER_LISTS = [
        'job_types' => 'JobTypes',
        'product_categories' => 'ProductCategories',
        'symptoms' => 'Symptoms',
        'resolutions' => 'Resolutions',
        'hold_reasons' => 'HoldReasons',
    ];

    private const CACHE_CONFIG = 'default';

    /**
     * The settings in force for a company.
     *
     * Three layers, lowest first: the catalogue default in code, the
     * platform row with no owner, then the company's own row.
     */
    public function settings(int $vendorId): CompanySettings
    {
        $cached = Cache::read($this->settingsCacheKey($vendorId), self::CACHE_CONFIG);
        if (is_array($cached)) {
            return new CompanySettings($vendorId, $cached['values'], $cached['sources']);
        }

        $values = [];
        $sources = [];

        foreach (SettingCatalog::all() as $key => $definition) {
            $values[$key] = $definition->default;
            $sources[$key] = 'default';
        }

        $rows = $this->fetchTable('VendorSettings')->find()
            ->select(['vendor_id', 'setting_key', 'value'])
            ->where([
                'is_active' => true,
                'OR' => ['vendor_id IS' => null, 'vendor_id' => $vendorId],
            ])
            // Platform rows first so the company's own row overwrites them.
            // ORDER BY on a nullable column puts NULLs first in MySQL, which
            // is the order we want, but relying on that is the kind of thing
            // that breaks on an engine change — so it is explicit.
            ->orderByAsc('CASE WHEN vendor_id IS NULL THEN 0 ELSE 1 END')
            ->disableHydration()
            ->all();

        foreach ($rows as $row) {
            $key = (string)$row['setting_key'];

            // A key that is not in the catalogue has no reader. Skipping it
            // rather than surfacing it keeps a renamed setting from
            // reappearing in an admin screen as an editable orphan.
            $definition = SettingCatalog::find($key);
            if ($definition === null) {
                continue;
            }

            $values[$key] = $definition->cast(
                $row['value'] !== null ? (string)$row['value'] : null,
            );
            $sources[$key] = $row['vendor_id'] === null ? 'platform' : 'company';
        }

        Cache::write(
            $this->settingsCacheKey($vendorId),
            ['values' => $values, 'sources' => $sources],
            self::CACHE_CONFIG,
        );

        return new CompanySettings($vendorId, $values, $sources);
    }

    /**
     * Write one company's override for a setting.
     *
     * @return array{ok: true}|array{ok: false, error: string}
     */
    public function putSetting(int $vendorId, string $key, mixed $value): array
    {
        $definition = SettingCatalog::find($key);
        if ($definition === null) {
            return ['ok' => false, 'error' => sprintf('Unknown setting "%s".', $key)];
        }

        if (!$definition->isEditable) {
            return ['ok' => false, 'error' => sprintf('"%s" is fixed and cannot be changed.', $definition->label)];
        }

        $serialized = $definition->serialize($value);
        if ($serialized['ok'] === false) {
            return $serialized;
        }

        $table = $this->fetchTable('VendorSettings');

        $row = $table->find()
            ->where(['vendor_id' => $vendorId, 'setting_key' => $key])
            ->first();

        $row ??= $table->newEntity([
            'vendor_id' => $vendorId,
            'setting_key' => $key,
            'value_type' => $definition->type,
            'label' => $definition->label,
            'description' => $definition->description,
            'is_editable' => true,
            'is_active' => true,
        ]);

        $row->set('value', $serialized['value']);
        $row->set('value_type', $definition->type);
        $row->set('is_active', true);

        $table->saveOrFail($row);
        $this->forget($vendorId);

        return ['ok' => true];
    }

    /**
     * Drop a company's override so the setting falls back to the platform
     * value. Not the same as setting it to the default — an explicit row
     * holding the current default would survive a change to that default,
     * which is the opposite of what "reset" means.
     */
    public function clearSetting(int $vendorId, string $key): void
    {
        $table = $this->fetchTable('VendorSettings');
        $table->deleteAll(['vendor_id' => $vendorId, 'setting_key' => $key]);
        $this->forget($vendorId);
    }

    /**
     * One master list as this company sees it.
     *
     * Company rows shadow shared rows with the same code, and a company row
     * marked inactive suppresses the shared entry behind it — that is how a
     * company opts out of a hold reason its agreement does not recognise
     * without deleting it for everyone.
     *
     * @return list<array<string, mixed>>
     */
    public function masterList(string $list, int $vendorId, bool $activeOnly = true): array
    {
        $alias = self::MASTER_LISTS[$list] ?? null;
        if ($alias === null) {
            return [];
        }

        $rows = $this->fetchTable($alias)->find()
            ->where(['OR' => ['vendor_id IS' => null, 'vendor_id' => $vendorId]])
            ->orderByAsc('sort_order')
            ->orderByAsc('name')
            ->disableHydration()
            ->all();

        $shared = [];
        $owned = [];
        foreach ($rows as $row) {
            $code = (string)$row['code'];
            if ($row['vendor_id'] === null) {
                $shared[$code] = $row;
            } else {
                $owned[$code] = $row;
            }
        }

        $resolved = [];
        foreach (array_replace($shared, $owned) as $code => $row) {
            $row['is_company_override'] = isset($owned[$code]);
            $row['shadows_shared_entry'] = isset($owned[$code]) && isset($shared[$code]);

            if ($activeOnly && !$row['is_active']) {
                continue;
            }

            $resolved[] = $row;
        }

        return $resolved;
    }

    /**
     * Every master list at once, for a form that needs all of them.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function masterLists(int $vendorId, bool $activeOnly = true): array
    {
        $lists = [];
        foreach (array_keys(self::MASTER_LISTS) as $list) {
            $lists[$list] = $this->masterList($list, $vendorId, $activeOnly);
        }

        return $lists;
    }

    /**
     * Copy the shared baseline into a company as editable rows.
     *
     * Used at onboarding when a company needs to diverge from most of the
     * baseline: it is faster to fork the list and prune than to add
     * fourteen overrides one at a time. Codes are preserved, because the
     * rate resolver matches on them.
     *
     * @return int  how many rows were created
     */
    public function forkSharedList(string $list, int $vendorId, ?string $note = null): int
    {
        $alias = self::MASTER_LISTS[$list] ?? null;
        if ($alias === null) {
            return 0;
        }

        $table = $this->fetchTable($alias);

        $existing = $table->find()
            ->select(['code'])
            ->where(['vendor_id' => $vendorId])
            ->disableHydration()
            ->all()
            ->extract('code')
            ->toArray();

        $shared = $table->find()
            ->where(['vendor_id IS' => null])
            ->disableHydration()
            ->all();

        $created = 0;
        foreach ($shared as $row) {
            if (in_array((string)$row['code'], $existing, true)) {
                continue;
            }

            unset($row['id'], $row['created'], $row['modified'], $row['vendor_key']);
            $row['vendor_id'] = $vendorId;
            $row['override_note'] = $note ?? 'Forked from the shared baseline at onboarding.';

            $table->saveOrFail($table->newEntity($row));
            $created++;
        }

        return $created;
    }

    /**
     * Invalidate a company's cached settings.
     */
    public function forget(int $vendorId): void
    {
        Cache::delete($this->settingsCacheKey($vendorId), self::CACHE_CONFIG);
    }

    private function settingsCacheKey(int $vendorId): string
    {
        return sprintf('company_settings_%d', $vendorId);
    }
}
