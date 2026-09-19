<?php
declare(strict_types=1);

use App\Database\Migration\AppMigration;

/**
 * Gives every company a starting brand, so the Products & Appliances
 * catalogue and the ticket-intake dropdown are never empty for a company
 * that has not yet been given a real sub-brand.
 *
 * `brands` has no shared, company-independent row — see CreateMasterLists:
 * "a brand name only means something in the context of who sells under
 * it" — so there is no single generic list to seed once. What every
 * company DOES start from is one placeholder brand named after itself
 * (the same "<Company> Standard" shape SeedStandardMasterData already
 * gives Dianora, generalised to every company rather than hard-coded to
 * one). An operator renames or replaces it, or adds real sub-brands
 * alongside it, once the company's actual product lines are known.
 *
 * Company-agnostic and driven entirely by the `companies` table, so it
 * also covers every company onboarded after this migration ran — it is
 * meant to be re-run (or its logic reused) rather than a one-off list.
 */
class SeedDefaultBrandPerCompany extends AppMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $companies = $this->fetchAll(
            "SELECT c.id, c.code, c.name
             FROM companies c
             LEFT JOIN brands b ON b.company_id = c.id
             WHERE b.id IS NULL",
        );

        if ($companies === []) {
            return;
        }

        $rows = [];
        foreach ($companies as $company) {
            $rows[] = [
                'company_id' => (int)$company['id'],
                'code' => strtoupper((string)$company['code']) . '-STD',
                'name' => $company['name'] . ' Standard',
                'aliases' => null,
                'is_active' => 1,
                'created' => $now,
                'modified' => $now,
            ];
        }

        $this->table('brands')->insertOrSkip($rows)->save();
    }

    /**
     * Removes exactly the placeholder rows this migration would have
     * created — a brand named "<company> Standard" with the "-STD" code
     * this migration uses, and only when it is still that company's only
     * brand (an operator who has since added a real sub-brand alongside
     * it has made the placeholder a real, load-bearing choice, so it is
     * left alone rather than removed out from under them).
     */
    public function down(): void
    {
        // Two steps rather than one DELETE ... WHERE (SELECT COUNT(*) ...):
        // MySQL refuses to modify `brands` while a correlated subquery in
        // the same statement also reads it ("can't specify target table
        // for update in FROM clause").
        $ids = $this->fetchAll(
            "SELECT b.id
             FROM brands b
             INNER JOIN companies c ON c.id = b.company_id
             WHERE b.code = CONCAT(UPPER(c.code), '-STD')
               AND b.name = CONCAT(c.name, ' Standard')
               AND (SELECT COUNT(*) FROM brands b2 WHERE b2.company_id = b.company_id) = 1",
        );

        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_map(fn(array $r) => (int)$r['id'], $ids));
        $this->execute("DELETE FROM brands WHERE id IN ({$placeholders})");
    }
}
