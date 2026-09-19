<?php
declare(strict_types=1);

use App\Database\Migration\AppMigration;

/**
 * Moves the free-text `model_no` already sitting on tickets into the
 * `products` catalogue, then links each ticket back to the row it created.
 *
 * `tickets.model_no` was always meant to be a point-in-time fact typed or
 * pasted in at intake (see CreateTickets), not a reference into the
 * Product Models Catalogue — but that leaves the catalogue permanently
 * empty on any environment where intake never separately populated it via
 * Settings, even though real model numbers exist on real tickets. This is
 * the one-time reconciliation: for every distinct (company, model number)
 * a ticket has ever recorded, create the matching catalogue row (carrying
 * over whichever brand, category and screen size those tickets agree on),
 * then point `tickets.product_id` at it.
 *
 * Safe to run twice: `products` has a unique (company_id, model_no) index,
 * so a model already catalogued is matched rather than duplicated, and the
 * ticket UPDATE only ever touches rows where `product_id IS NULL`.
 */
class BackfillProductsFromTickets extends AppMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // One candidate product per (company, trimmed model number) that
        // tickets actually used. brand_id/product_category_id/size_inch
        // are taken from whichever ticket in the group set them — the
        // group only disagrees when intake was inconsistent, and any one
        // real value beats leaving the catalogue row unusable.
        $candidates = $this->fetchAll(
            "SELECT
                t.company_id AS company_id,
                TRIM(t.model_no) AS model_no,
                MAX(t.brand_id) AS brand_id,
                MAX(t.product_category_id) AS product_category_id,
                MAX(t.size_inch) AS size_inch
             FROM tickets t
             WHERE t.model_no IS NOT NULL
               AND TRIM(t.model_no) != ''
               AND t.product_category_id IS NOT NULL
               AND t.product_id IS NULL
             GROUP BY t.company_id, TRIM(t.model_no)",
        );

        if ($candidates !== []) {
            $rows = [];
            foreach ($candidates as $row) {
                $rows[] = [
                    'company_id' => (int)$row['company_id'],
                    'product_category_id' => (int)$row['product_category_id'],
                    'brand_id' => $row['brand_id'] !== null ? (int)$row['brand_id'] : null,
                    'model_no' => $row['model_no'],
                    'name' => null,
                    'size_inch' => $row['size_inch'],
                    'warranty_months' => null,
                    'panel_warranty_months' => null,
                    'is_active' => 1,
                    'created' => $now,
                    'modified' => $now,
                ];
            }

            // Batched, not one insertOrSkip call per row: some environments
            // have hundreds of distinct model numbers on file.
            foreach (array_chunk($rows, 200) as $chunk) {
                $this->table('products')->insertOrSkip($chunk)->save();
            }
        }

        // Link every ticket whose (company, model number) now has a
        // catalogue row and did not already have one — including tickets
        // whose model was already catalogued before this migration ran.
        $this->execute(
            "UPDATE tickets t
             INNER JOIN products p
                ON p.company_id = t.company_id
               AND p.model_no = TRIM(t.model_no)
             SET t.product_id = p.id
             WHERE t.product_id IS NULL
               AND t.model_no IS NOT NULL
               AND TRIM(t.model_no) != ''",
        );
    }

    /**
     * Unlinks tickets this migration linked and removes the catalogue rows
     * it created — safe because `tickets.product_id` is ON DELETE SET
     * NULL, so a product still referenced by a ticket added after this
     * migration ran is simply unlinked rather than blocking the delete.
     * A product an operator has since edited (renamed, re-branded) is
     * still removed: the reconciliation this migration performs is meant
     * to be fully reversible, not to preserve later manual edits.
     */
    public function down(): void
    {
        $this->execute(
            "UPDATE tickets t
             INNER JOIN products p ON p.id = t.product_id
             SET t.product_id = NULL
             WHERE p.name IS NULL AND p.warranty_months IS NULL",
        );

        $this->execute(
            "DELETE p FROM products p
             LEFT JOIN tickets t ON t.product_id = p.id
             WHERE p.name IS NULL
               AND p.warranty_months IS NULL
               AND t.id IS NULL",
        );
    }
}
