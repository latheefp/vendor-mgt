<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\Exception\RateCardLockedException;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;

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
    public function createDraft(int $vendorId, array $data = []): int
    {
        $cards = $this->fetchTable('RateCards');

        $latest = $cards->find()
            ->select(['version'])
            ->where(['vendor_id' => $vendorId])
            ->orderByDesc('version')
            ->disableHydration()
            ->first();

        $version = ((int)($latest['version'] ?? 0)) + 1;

        $card = $cards->newEntity([
            'vendor_id' => $vendorId,
            'vendor_agreement_id' => $data['vendor_agreement_id'] ?? null,
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
     * @param int $targetVendorId  the company the new draft belongs to,
     *                             which need not be the source's company
     */
    public function cloneCard(int $sourceCardId, int $targetVendorId, array $data = []): int
    {
        $source = $this->fetchTable('RateCards')->get($sourceCardId);

        $newCardId = $this->createDraft($targetVendorId, $data + [
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

        $items = $this->fetchTable('RateCardItems');
        $item = $items->newEntity(['rate_card_id' => $cardId] + $data);
        $items->saveOrFail($item);

        return (int)$item->id;
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
                    'vendor_id' => $card->vendor_id,
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
