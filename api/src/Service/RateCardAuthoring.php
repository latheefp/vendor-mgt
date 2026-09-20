<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\Exception\DuplicateRateCardRowException;
use App\Domain\Exception\RateCardLockedException;
use Cake\I18n\DateTime;
use Cake\ORM\Exception\PersistenceFailedException;
use Cake\ORM\Locator\LocatorAwareTrait;
use InvalidArgumentException;

/**
 * Creating, editing and publishing a company's rate card.
 *
 * Rates reached the database by seed until now, which was fine while
 * Dianora was the only company and its card came off a signed PDF. It
 * stops being fine the moment a second company is onboarded by someone who
 * is not a developer, or the moment Dianora revises a price mid-quarter.
 *
 * Two rules are enforced here and nowhere else, because they are what make
 * an old invoice explainable:
 *
 * 1. A PUBLISHED CARD IS IMMUTABLE. Not "discouraged from changing" —
 *    refused. Frozen charge lines hold a hard reference to the rate card
 *    item that priced them, so editing a published item silently rewrites
 *    the explanation of money that has already been invoiced and paid.
 *    Revising rates means a new version.
 *
 * 2. VERSIONS ARE CONTIGUOUS IN TIME. Publishing version n+1 closes
 *    version n the day before it starts. Without that, two cards are
 *    active on the same date and which one prices a ticket depends on
 *    query ordering — a bug that surfaces as a handful of mispriced jobs
 *    nobody can account for.
 */
class RateCardAuthoring
{
    use LocatorAwareTrait;

    /**
     * Start a new draft card for a company.
     *
     * @param array<string, mixed> $data
     */
    public function createDraft(int $companyId, array $data = []): int
    {
        $cards = $this->fetchTable('RateCards');

        $latest = $cards->find()
            ->select(['version'])
            ->where(['company_id' => $companyId])
            ->orderByDesc('version')
            ->disableHydration()
            ->first();

        $version = ((int)($latest['version'] ?? 0)) + 1;

        $card = $cards->newEntity([
            'company_id' => $companyId,
            'company_agreement_id' => $data['company_agreement_id'] ?? null,
            'name' => $data['name'] ?? sprintf('Rate card v%d', $version),
            'version' => $version,
            'status' => 'draft',
            'effective_from' => $data['effective_from'] ?? date('Y-m-d'),
            'effective_to' => $data['effective_to'] ?? null,
            'currency' => $data['currency'] ?? 'INR',
            'notes' => $data['notes'] ?? null,
        ]);

        $cards->saveOrFail($card);

        return (int)$card->id;
    }

    /**
     * Copy an existing card into a new draft.
     *
     * This is the onboarding path and the revision path at once. A second
     * company selling the same category of product has a card that is
     * mostly the first company's with different numbers, and starting from
     * a copy is the difference between an afternoon and a fortnight. It is
     * also how a company revises rates: clone the active card, edit the
     * three lines that moved, publish.
     *
     * @param int $targetCompanyId  the company the new draft belongs to,
     *                             which need not be the source's company
     */
    public function cloneCard(int $sourceCardId, int $targetCompanyId, array $data = []): int
    {
        $source = $this->fetchTable('RateCards')->get($sourceCardId);

        $newCardId = $this->createDraft($targetCompanyId, $data + [
            'name' => sprintf('%s (copy)', $source->name),
            'currency' => $source->currency,
        ]);

        $items = $this->fetchTable('RateCardItems')->find()
            ->where(['rate_card_id' => $sourceCardId])
            ->disableHydration()
            ->all();

        foreach ($items as $item) {
            unset($item['id'], $item['created'], $item['modified']);
            $item['rate_card_id'] = $newCardId;
            $this->addItem($newCardId, $item);
        }

        $rules = $this->fetchTable('SlaRules')->find()
            ->where(['rate_card_id' => $sourceCardId])
            ->disableHydration()
            ->all();

        foreach ($rules as $rule) {
            unset($rule['id'], $rule['created'], $rule['modified']);
            $rule['rate_card_id'] = $newCardId;
            $this->addSlaRule($newCardId, $rule);
        }

        return $newCardId;
    }

    /**
     * Add a priced line to a draft card.
     *
     * @param array<string, mixed> $data
     */
    public function addItem(int $cardId, array $data): int
    {
        $this->assertDraft($cardId);

        if (empty($data['label'])) {
            if (!empty($data['job_type_id'])) {
                $jobType = $this->fetchTable('JobTypes')->find()->where(['id' => (int)$data['job_type_id']])->first();
                $label = $jobType ? (string)$jobType->name : 'Basic Service Charge';
                if (!empty($data['warranty_scope']) && $data['warranty_scope'] !== 'not_applicable') {
                    $label .= ' (' . str_replace('_', ' ', (string)$data['warranty_scope']) . ')';
                }
                $data['label'] = $label;
            } else {
                $data['label'] = 'Basic Service Charge';
            }
        }

        $items = $this->fetchTable('RateCardItems');
        $item = $items->newEntity(['rate_card_id' => $cardId] + $data);
        $items->saveOrFail($item);

        return (int)$item->id;
    }

    /**
     * Bulk-add priced lines from parsed CSV rows.
     *
     * Each row is resolved and saved independently — one bad row (an
     * unrecognised job type code, a non-numeric amount) does not sink the
     * rows around it. That mirrors validateDraft()'s philosophy: surface
     * every problem at once rather than stop at the first.
     *
     * A row is a duplicate — and skipped rather than saved — when its job
     * type, appliance, warranty scope and size band match a line already on
     * the card, whether that line was there before the upload or came from
     * an earlier row in the same file. Those four fields are exactly what
     * RateResolver matches a ticket against, so two lines agreeing on all
     * four never coexist usefully: one just shadows the other.
     *
     * @param list<array<string, string>> $rows CSV rows keyed by lower-cased
     *                                          header: job_type_code,
     *                                          product_category_code,
     *                                          warranty_scope, label,
     *                                          size_min_inch, size_max_inch,
     *                                          amount_rupees, payer
     * @return array{created: list<int>, errors: list<array{row: int, message: string, duplicate: bool}>}
     */
    public function importItems(int $cardId, array $rows): array
    {
        $this->assertDraft($cardId);

        $card = $this->fetchTable('RateCards')->get($cardId);

        $jobTypesByCode = [];
        foreach ((new CompanyConfigRepository())->masterList('job_types', (int)$card->company_id) as $row) {
            $jobTypesByCode[(string)$row['code']] = (int)$row['id'];
        }

        $categoriesByCode = [];
        foreach ((new CompanyConfigRepository())->masterList('product_categories', (int)$card->company_id) as $row) {
            $categoriesByCode[(string)$row['code']] = (int)$row['id'];
        }

        $seenKeys = [];
        $existing = $this->fetchTable('RateCardItems')->find()
            ->select(['job_type_id', 'product_category_id', 'warranty_scope', 'size_min_inch', 'size_max_inch'])
            ->where(['rate_card_id' => $cardId, 'is_active' => true])
            ->disableHydration()
            ->all();
        foreach ($existing as $item) {
            $seenKeys[$this->itemKey(
                (int)$item['job_type_id'],
                $item['product_category_id'] !== null ? (int)$item['product_category_id'] : null,
                (string)$item['warranty_scope'],
                $item['size_min_inch'],
                $item['size_max_inch'],
            )] = true;
        }

        $created = [];
        $errors = [];

        foreach ($rows as $i => $row) {
            // Row 1 is the header, so the first data row is row 2 — the
            // number a spreadsheet-literate person actually sees.
            $rowNumber = $i + 2;

            try {
                $jobTypeCode = trim((string)($row['job_type_code'] ?? ''));
                if ($jobTypeCode === '') {
                    throw new InvalidArgumentException('job_type_code is required.');
                }
                if (!isset($jobTypesByCode[$jobTypeCode])) {
                    throw new InvalidArgumentException(sprintf('Unknown job_type_code "%s".', $jobTypeCode));
                }

                $categoryCode = trim((string)($row['product_category_code'] ?? ''));
                $categoryId = null;
                if ($categoryCode !== '') {
                    if (!isset($categoriesByCode[$categoryCode])) {
                        throw new InvalidArgumentException(
                            sprintf('Unknown product_category_code "%s".', $categoryCode),
                        );
                    }
                    $categoryId = $categoriesByCode[$categoryCode];
                }

                $amountRaw = trim((string)($row['amount_rupees'] ?? ''));
                if ($amountRaw === '' || !is_numeric($amountRaw)) {
                    throw new InvalidArgumentException('amount_rupees is required and must be a number.');
                }

                $sizeMin = trim((string)($row['size_min_inch'] ?? ''));
                $sizeMax = trim((string)($row['size_max_inch'] ?? ''));
                $label = trim((string)($row['label'] ?? ''));
                $scope = trim((string)($row['warranty_scope'] ?? '')) ?: 'not_applicable';

                $key = $this->itemKey(
                    $jobTypesByCode[$jobTypeCode],
                    $categoryId,
                    $scope,
                    $sizeMin !== '' ? $sizeMin : null,
                    $sizeMax !== '' ? $sizeMax : null,
                );
                if (isset($seenKeys[$key])) {
                    throw new DuplicateRateCardRowException(
                        'Duplicate of an existing line (same job type, appliance, warranty '
                        . 'scope and size band) — skipped.',
                    );
                }

                $itemId = $this->addItem($cardId, [
                    'job_type_id' => $jobTypesByCode[$jobTypeCode],
                    'product_category_id' => $categoryId,
                    'warranty_scope' => $scope,
                    'label' => $label !== '' ? $label : null,
                    'size_min_inch' => $sizeMin !== '' ? $sizeMin : null,
                    'size_max_inch' => $sizeMax !== '' ? $sizeMax : null,
                    'amount_paise' => (int)round(((float)$amountRaw) * 100),
                    'payer' => trim((string)($row['payer'] ?? '')) ?: 'company',
                ]);

                $seenKeys[$key] = true;
                $created[] = $itemId;
            } catch (DuplicateRateCardRowException $e) {
                $errors[] = ['row' => $rowNumber, 'message' => $e->getMessage(), 'duplicate' => true];
            } catch (PersistenceFailedException $e) {
                $errors[] = ['row' => $rowNumber, 'message' => $this->firstValidationError($e), 'duplicate' => false];
            } catch (InvalidArgumentException $e) {
                $errors[] = ['row' => $rowNumber, 'message' => $e->getMessage(), 'duplicate' => false];
            }
        }

        return ['created' => $created, 'errors' => $errors];
    }

    /**
     * Collapse lines that only differ by appliance category into one.
     *
     * validateDraft()'s overlap check groups by job type and warranty scope
     * alone — it does not know about category — so a line with no category
     * (applies to every appliance) and a category-specific line covering
     * the exact same size band are indistinguishable to it from two prices
     * quoted for the same job twice. That is usually exactly what they are:
     * the same CSV uploaded once before a category column was added and
     * again after, or the same line added by hand and then imported.
     *
     * A group only merges when every line in it agrees on amount and
     * payer — if they don't, there are genuinely two different asking
     * prices for the same job, and collapsing them would silently pick a
     * winner. Those groups are returned as conflicts instead, for a human
     * to resolve.
     *
     * Within a mergeable group, the surviving line is the most specific
     * one — non-null category over null — since a null-category line
     * asserts nothing a category-specific line doesn't already say more
     * precisely.
     *
     * @return array{merged_groups: int, removed: list<int>, conflicts: list<array{key: string, item_ids: list<int>}>}
     */
    public function mergeDuplicateItems(int $cardId): array
    {
        $this->assertDraft($cardId);

        $rows = $this->fetchTable('RateCardItems')->find()
            ->select([
                'id', 'job_type_id', 'product_category_id', 'warranty_scope',
                'size_min_inch', 'size_max_inch', 'amount_paise', 'payer',
            ])
            ->where(['rate_card_id' => $cardId, 'is_active' => true])
            ->disableHydration()
            ->all()
            ->toList();

        $groups = [];
        foreach ($rows as $item) {
            $key = implode('|', [
                $item['job_type_id'],
                $item['warranty_scope'],
                $item['size_min_inch'] ?? 'null',
                $item['size_max_inch'] ?? 'null',
            ]);
            $groups[$key][] = $item;
        }

        $removed = [];
        $conflicts = [];
        $mergedGroups = 0;

        foreach ($groups as $key => $group) {
            if (count($group) < 2) {
                continue;
            }

            $first = $group[0];
            $agrees = true;
            foreach ($group as $item) {
                if ((int)$item['amount_paise'] !== (int)$first['amount_paise'] || $item['payer'] !== $first['payer']) {
                    $agrees = false;
                    break;
                }
            }

            if (!$agrees) {
                $conflicts[] = [
                    'key' => $key,
                    'item_ids' => array_map(static fn(array $i): int => (int)$i['id'], $group),
                ];
                continue;
            }

            // The most specific line survives: a named category beats the
            // "applies to everything" null, and a tie keeps the oldest row.
            usort($group, static function (array $a, array $b): int {
                $aNamed = $a['product_category_id'] !== null ? 1 : 0;
                $bNamed = $b['product_category_id'] !== null ? 1 : 0;
                if ($aNamed !== $bNamed) {
                    return $bNamed <=> $aNamed;
                }

                return $a['id'] <=> $b['id'];
            });

            // The survivor (index 0 after the sort above) simply isn't
            // touched — merging here means deleting everything else in the
            // group, not writing a new row.
            array_shift($group);
            $items = $this->fetchTable('RateCardItems');
            foreach ($group as $duplicate) {
                $items->deleteOrFail($items->get($duplicate['id']));
                $removed[] = (int)$duplicate['id'];
            }
            $mergedGroups++;
        }

        return ['merged_groups' => $mergedGroups, 'removed' => $removed, 'conflicts' => $conflicts];
    }

    /**
     * The fields RateResolver actually matches a ticket against. Two lines
     * agreeing on all of them never coexist usefully — the later one just
     * shadows the earlier one at resolve time — so this is what "duplicate"
     * means for a rate card line.
     */
    private function itemKey(int $jobTypeId, ?int $categoryId, string $scope, ?string $min, ?string $max): string
    {
        return implode('|', [
            $jobTypeId,
            $categoryId ?? 'all',
            $scope,
            $min !== null ? number_format((float)$min, 2, '.', '') : 'null',
            $max !== null ? number_format((float)$max, 2, '.', '') : 'null',
        ]);
    }

    /**
     * The first field error off a failed save, formatted for a CSV row.
     */
    private function firstValidationError(PersistenceFailedException $e): string
    {
        foreach ($e->getEntity()->getErrors() as $field => $messages) {
            return sprintf('%s: %s', $field, (string)(is_array($messages) ? reset($messages) : $messages));
        }

        return 'The line could not be saved.';
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateItem(int $itemId, array $data): void
    {
        $items = $this->fetchTable('RateCardItems');
        $item = $items->get($itemId);

        $this->assertDraft((int)$item->rate_card_id);

        // rate_card_id is not patchable: moving a line between cards would
        // change what a published card contained without touching it.
        unset($data['rate_card_id'], $data['id']);

        $items->saveOrFail($items->patchEntity($item, $data));
    }

    public function removeItem(int $itemId): void
    {
        $items = $this->fetchTable('RateCardItems');
        $item = $items->get($itemId);

        $this->assertDraft((int)$item->rate_card_id);

        $items->deleteOrFail($item);
    }

    /**
     * Add an SLA bonus or penalty rule to a draft card.
     *
     * @param array<string, mixed> $data
     */
    public function addSlaRule(int $cardId, array $data): int
    {
        $this->assertDraft($cardId);

        $rules = $this->fetchTable('SlaRules');
        $rule = $rules->newEntity(['rate_card_id' => $cardId] + $data);
        $rules->saveOrFail($rule);

        return (int)$rule->id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateSlaRule(int $ruleId, array $data): void
    {
        $rules = $this->fetchTable('SlaRules');
        $rule = $rules->get($ruleId);

        $this->assertDraft((int)$rule->rate_card_id);

        unset($data['rate_card_id'], $data['id']);

        $rules->saveOrFail($rules->patchEntity($rule, $data));
    }

    public function removeSlaRule(int $ruleId): void
    {
        $rules = $this->fetchTable('SlaRules');
        $rule = $rules->get($ruleId);

        $this->assertDraft((int)$rule->rate_card_id);

        $rules->deleteOrFail($rule);
    }

    /**
     * Everything wrong with a draft, before anyone tries to publish it.
     *
     * Returned rather than thrown so an authoring screen can show all the
     * problems at once instead of one per attempt.
     *
     * @return list<string>
     */
    public function validateDraft(int $cardId): array
    {
        $card = $this->fetchTable('RateCards')->get($cardId);
        $problems = [];

        $items = $this->fetchTable('RateCardItems')->find()
            ->select([
                'RateCardItems.id',
                'RateCardItems.warranty_scope',
                'RateCardItems.size_min_inch',
                'RateCardItems.size_max_inch',
                'RateCardItems.amount_paise',
                'RateCardItems.label',
                'job_type_code' => 'JobTypes.code',
                'requires_scope' => 'JobTypes.requires_warranty_scope',
                'is_size_banded' => 'JobTypes.is_size_banded',
            ])
            ->join(['JobTypes' => [
                'table' => 'job_types',
                'type' => 'INNER',
                'conditions' => 'JobTypes.id = RateCardItems.job_type_id',
            ]])
            ->where(['RateCardItems.rate_card_id' => $cardId, 'RateCardItems.is_active' => true])
            ->disableHydration()
            ->all()
            ->toList();

        if ($items === []) {
            $problems[] = 'The card has no priced lines.';
        }

        foreach ($items as $item) {
            if ((int)$item['amount_paise'] === 0) {
                $problems[] = sprintf('"%s" is priced at zero.', $item['label']);
            }

            // A job type flagged as needing a scope but priced without one
            // resolves for every ticket, which is how in-warranty work ends
            // up billed at the out-of-warranty rate.
            if ($item['requires_scope'] && $item['warranty_scope'] === 'not_applicable') {
                $problems[] = sprintf(
                    '"%s" needs a warranty scope — %s is priced differently in and out of warranty.',
                    $item['label'],
                    $item['job_type_code'],
                );
            }

            if (
                $item['size_min_inch'] !== null && $item['size_max_inch'] !== null
                && (float)$item['size_min_inch'] > (float)$item['size_max_inch']
            ) {
                $problems[] = sprintf('"%s" has a size band that runs backwards.', $item['label']);
            }
        }

        $problems = array_merge($problems, $this->bandProblems($items));

        // A card with an SLA rule whose window is wider than the agreement's
        // own close window can never pay out.
        $problems = array_merge($problems, $this->slaProblems($cardId));

        if ($card->effective_to !== null && $card->effective_to <= $card->effective_from) {
            $problems[] = 'The card expires on or before the day it takes effect.';
        }

        return $problems;
    }

    /**
     * Gaps and overlaps in the size bands for one job type and scope.
     *
     * Gaps are the expensive one. The Dianora card prices out-of-warranty
     * service for 24-43", 45-55" and 65-85", which means a 44" set and a
     * 60" set have no agreed price at all — the resolver correctly refuses
     * to invent one, and the desk finds out at closure. Surfacing it at
     * authoring time is the whole point.
     *
     * @param list<array<string, mixed>> $items
     * @return list<string>
     */
    private function bandProblems(array $items): array
    {
        $problems = [];
        $groups = [];

        foreach ($items as $item) {
            if ($item['size_min_inch'] === null && $item['size_max_inch'] === null) {
                continue;
            }
            $groups[$item['job_type_code'] . '|' . $item['warranty_scope']][] = $item;
        }

        foreach ($groups as $key => $group) {
            [$jobType, $scope] = explode('|', $key);

            usort(
                $group,
                static fn ($a, $b): int => (float)$a['size_min_inch'] <=> (float)$b['size_min_inch'],
            );

            for ($i = 1, $n = count($group); $i < $n; $i++) {
                $previousMax = (float)$group[$i - 1]['size_max_inch'];
                $currentMin = (float)$group[$i]['size_min_inch'];

                if ($currentMin <= $previousMax) {
                    $problems[] = sprintf(
                        'Size bands overlap for %s (%s): %.0f"-%.0f" and %.0f"-%.0f".',
                        $jobType,
                        $scope,
                        (float)$group[$i - 1]['size_min_inch'],
                        $previousMax,
                        $currentMin,
                        (float)$group[$i]['size_max_inch'],
                    );
                    continue;
                }

                // Adjacent bands are expected to touch: 24-43 then 45-65
                // leaves 44 unpriced. Warned, not blocked — the gap may be
                // exactly what was negotiated.
                if ($currentMin - $previousMax > 1.0) {
                    $problems[] = sprintf(
                        'Size gap for %s (%s): nothing priced between %.0f" and %.0f".',
                        $jobType,
                        $scope,
                        $previousMax,
                        $currentMin,
                    );
                }
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function slaProblems(int $cardId): array
    {
        $problems = [];

        $rules = $this->fetchTable('SlaRules')->find()
            ->where(['rate_card_id' => $cardId, 'is_active' => true])
            ->disableHydration()
            ->all();

        foreach ($rules as $rule) {
            $comparator = (string)$rule['comparator'];
            $from = $rule['threshold_from_hours'];
            $to = $rule['threshold_to_hours'];

            $needsTo = in_array($comparator, ['lte', 'between'], true);
            $needsFrom = in_array($comparator, ['gt', 'between'], true);

            if ($needsTo && $to === null) {
                $problems[] = sprintf('SLA rule "%s" (%s) has no upper threshold.', $rule['code'], $comparator);
            }
            if ($needsFrom && $from === null) {
                $problems[] = sprintf('SLA rule "%s" (%s) has no lower threshold.', $rule['code'], $comparator);
            }
            if ($comparator === 'between' && $from !== null && $to !== null && (float)$from >= (float)$to) {
                $problems[] = sprintf('SLA rule "%s" has a window that runs backwards.', $rule['code']);
            }
            if ((int)$rule['amount_paise'] === 0) {
                $problems[] = sprintf('SLA rule "%s" pays nothing.', $rule['code']);
            }
        }

        return $problems;
    }

    /**
     * Publish a draft, closing whatever it supersedes.
     *
     * @param list<string> $ignoreWarnings  problems the publisher has
     *                                      explicitly accepted, e.g. a size
     *                                      gap that really was negotiated
     * @return array{ok: true, superseded: int|null}|array{ok: false, problems: list<string>}
     */
    public function publish(int $cardId, ?int $publishedByUserId = null, array $ignoreWarnings = []): array
    {
        $cards = $this->fetchTable('RateCards');
        $card = $cards->get($cardId);

        if ($card->status !== 'draft') {
            throw new RateCardLockedException(sprintf(
                'Rate card %d is %s, not a draft.',
                $cardId,
                $card->status,
            ));
        }

        $problems = array_values(array_diff($this->validateDraft($cardId), $ignoreWarnings));
        if ($problems !== []) {
            return ['ok' => false, 'problems' => $problems];
        }

        $connection = $cards->getConnection();

        /** @var int|null $supersededId */
        $supersededId = $connection->transactional(function () use ($cards, $card): ?int {
            $current = $cards->find()
                ->where([
                    'company_id' => $card->company_id,
                    'status' => 'active',
                    'id !=' => $card->id,
                ])
                ->orderByDesc('effective_from')
                ->first();

            if ($current !== null) {
                // Close the outgoing card the day before this one starts,
                // so exactly one card is active on any given date.
                $closesOn = (new DateTime($card->effective_from))->subDays(1);

                if ($current->effective_to === null || $current->effective_to > $closesOn) {
                    $current->set('effective_to', $closesOn->format('Y-m-d'));
                }
                $current->set('status', 'superseded');
                $cards->saveOrFail($current);
            }

            $card->set('status', 'active');
            $card->set('published_at', DateTime::now());
            $cards->saveOrFail($card);

            return $current !== null ? (int)$current->id : null;
        });

        if ($publishedByUserId !== null) {
            $card->set('published_by_user_id', $publishedByUserId);
            $cards->saveOrFail($card);
        }

        return ['ok' => true, 'superseded' => $supersededId];
    }

    /**
     * Refuse to touch anything already published.
     */
    private function assertDraft(int $cardId): void
    {
        $card = $this->fetchTable('RateCards')->find()
            ->select(['id', 'status'])
            ->where(['id' => $cardId])
            ->disableHydration()
            ->first();

        if ($card === null) {
            throw new RateCardLockedException(sprintf('Rate card %d does not exist.', $cardId));
        }

        if ($card['status'] !== 'draft') {
            throw new RateCardLockedException(sprintf(
                'Rate card %d is %s. Published rates are immutable — clone it to a new version instead.',
                $cardId,
                $card['status'],
            ));
        }
    }
}
