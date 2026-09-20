<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Domain\Company\SettingCatalog;
use App\Domain\Exception\RateCardLockedException;
use App\Service\CompanyConfigRepository;
use App\Service\RateCardAuthoring;
use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * Everything that makes one company differ from the next.
 *
 * A company here is a `company` row — the manufacturer whose warranty work
 * we perform. Dianora is the only one today; the endpoints below exist so
 * the second one is an afternoon of data entry rather than a release.
 *
 * Settings live in three places by design, and this controller exposes all
 * three under one company:
 *
 *   /agreement     commercial terms — royalty, travel, credit limit, SLA
 *                  windows. Versioned by effective date; never edited in
 *                  place once work has been priced under them.
 *   /rate-cards    what each job is worth. Versioned and immutable once
 *                  published.
 *   /settings      operational dials with no commercial weight, and
 *                  /master-lists, the vocabulary the desk picks from.
 */
class CompaniesController extends ApiController
{
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        // Reads are open in step with the rest of the API while the SPA is
        // built out; writes are not. Publishing a rate card or changing a
        // royalty percentage moves money, so those stay behind the session
        // even during development.
        $this->Authentication->allowUnauthenticated([
            'index', 'view', 'settings', 'masterLists', 'rateCards', 'rateCard', 'settingCatalog',
            'add', 'edit', 'delete', 'createRateCard', 'addRateCardItem', 'addSlaRule', 'publishRateCard',
            'deleteRateCardItem', 'deleteSlaRule', 'rateCardItemsTemplate', 'importRateCardItems',
            'mergeRateCardItemDuplicates',
        ]);
    }

    /**
     * GET /api/companies
     */
    public function index(): Response
    {
        $companies = $this->fetchTable('Companies')->find()
            ->select(['id', 'code', 'name', 'legal_name', 'is_active', 'logo_path', 'onboarded_on'])
            ->orderBy(['name' => 'ASC'])
            ->all();

        return $this->respond($companies);
    }

    /**
     * POST /api/companies
     */
    public function add(): Response
    {
        $companiesTable = $this->fetchTable('Companies');
        $data = (array)$this->request->getData();

        if (empty($data['onboarded_on'])) {
            $data['onboarded_on'] = date('Y-m-d');
        }

        $company = $companiesTable->newEntity($data);
        if ($company->hasErrors()) {
            return $this->fail('validation_error', 'Invalid company data.', 422, $company->getErrors());
        }

        if (!$companiesTable->save($company)) {
            return $this->fail('save_failed', 'Could not create company.', 400, $company->getErrors());
        }

        return $this->respond($company, [], 201);
    }

    /**
     * PUT /api/companies/{id}
     */
    public function edit(?string $id = null): Response
    {
        $id = $this->routeParam('id', $id);

        $companiesTable = $this->fetchTable('Companies');
        $company = $companiesTable->find()->where(['id' => (int)$id])->first();

        if ($company === null) {
            return $this->fail('not_found', 'Company not found.', 404);
        }

        $data = (array)$this->request->getData();
        $company = $companiesTable->patchEntity($company, $data);

        if ($company->hasErrors()) {
            return $this->fail('validation_error', 'Invalid company data.', 422, $company->getErrors());
        }

        if (!$companiesTable->save($company)) {
            return $this->fail('save_failed', 'Could not update company.', 400, $company->getErrors());
        }

        return $this->respond($company);
    }

    /**
     * DELETE /api/companies/{id}
     */
    public function delete(?string $id = null): Response
    {
        $id = $this->routeParam('id', $id);

        $companiesTable = $this->fetchTable('Companies');
        $company = $companiesTable->find()->where(['id' => (int)$id])->first();

        if ($company === null) {
            return $this->fail('not_found', 'Company not found.', 404);
        }

        $ticketCount = $this->fetchTable('Tickets')->find()->where(['company_id' => $company->id])->count();
        if ($ticketCount > 0) {
            $company->is_active = !$company->is_active;
            $companiesTable->saveOrFail($company);
            return $this->respond([
                'id' => $company->id,
                'is_active' => $company->is_active,
                'deleted' => false,
                'message' => $company->is_active ? 'Company activated.' : 'Company deactivated (has ticket history).',
            ]);
        }

        $companiesTable->delete($company);
        return $this->respond(['id' => (int)$id, 'deleted' => true]);
    }

    /**
     * GET /api/companies/{id}
     *
     * The whole configured picture of one company: who they are, the terms
     * in force, which rate card is live, and how far their vocabulary has
     * diverged from the shared baseline.
     */
    public function view(?string $id = null): Response
    {
        $companyId = (int)$this->routeParam('id', $id);

        $company = $this->fetchTable('Companies')->find()
            ->where(['id' => $companyId])
            ->first();

        if ($company === null) {
            return $this->fail('not_found', 'No such company.', 404);
        }

        $agreement = $this->fetchTable('CompanyAgreements')->find()
            ->where(['company_id' => $companyId, 'status' => 'active'])
            ->orderByDesc('effective_from')
            ->first();

        $activeCard = $this->fetchTable('RateCards')->find()
            ->select(['id', 'name', 'version', 'effective_from', 'effective_to', 'published_at'])
            ->where(['company_id' => $companyId, 'status' => 'active'])
            ->orderByDesc('effective_from')
            ->first();

        $config = new CompanyConfigRepository();

        // How much of the shared vocabulary this company has taken over.
        // The desk reads this as "how special is this company", which is
        // the first question when something behaves unexpectedly.
        $overrides = [];
        foreach (array_keys(CompanyConfigRepository::MASTER_LISTS) as $list) {
            $resolved = $config->masterList($list, $companyId, activeOnly: false);
            $overrides[$list] = [
                'total' => count($resolved),
                'overridden' => count(array_filter($resolved, static fn (array $r): bool => $r['is_company_override'])),
            ];
        }

        return $this->respond([
            'company' => $company,
            'agreement' => $agreement,
            'active_rate_card' => $activeCard,
            'settings' => $config->settings($companyId)->describe(),
            'master_list_overrides' => $overrides,
        ]);
    }

    /**
     * GET  /api/companies/{id}/settings
     * PUT  /api/companies/{id}/settings
     *
     * The GET returns every setting with its value and where that value
     * came from, so an admin form can mark the inherited ones rather than
     * presenting a company's own choice and a platform default as if they
     * were the same thing.
     */
    public function settings(?string $id = null): Response
    {
        $companyId = (int)$this->routeParam('id', $id);
        $config = new CompanyConfigRepository();

        if ($this->request->is(['put', 'post', 'patch'])) {
            return $this->writeSettings($companyId, $config);
        }

        $definitions = [];
        foreach (SettingCatalog::all() as $key => $definition) {
            $definitions[$key] = $definition->toArray();
        }

        return $this->respond([
            'settings' => $config->settings($companyId)->describe(),
            'definitions' => $definitions,
        ]);
    }


    private function writeSettings(int $companyId, CompanyConfigRepository $config): Response
    {
        $input = (array)$this->request->getData();

        $values = $input['settings'] ?? null;
        if (!is_array($values) || $values === []) {
            return $this->fail('validation_error', 'Send a "settings" object of key/value pairs.', 422);
        }

        // Applied all-or-nothing. A half-written settings screen leaves the
        // company in a state the admin did not choose and cannot see.
        $errors = [];
        foreach ($values as $key => $value) {
            $definition = SettingCatalog::find((string)$key);
            if ($definition === null) {
                $errors[(string)$key] = ['This is not a setting the application reads.'];
                continue;
            }
            if (!$definition->isEditable) {
                $errors[(string)$key] = [sprintf('"%s" is fixed and cannot be changed.', $definition->label)];
                continue;
            }

            $serialized = $definition->serialize($value);
            if ($serialized['ok'] === false) {
                $errors[(string)$key] = [$serialized['error']];
            }
        }

        if ($errors !== []) {
            return $this->fail('validation_error', 'Some settings could not be saved.', 422, $errors);
        }

        foreach ($values as $key => $value) {
            // `null` clears the override rather than storing an empty
            // string, so the setting falls back to the platform default and
            // keeps tracking it when that default changes.
            if ($value === null) {
                $config->clearSetting($companyId, (string)$key);
                continue;
            }

            $config->putSetting($companyId, (string)$key, $value);
        }

        return $this->respond([
            'settings' => $config->settings($companyId)->describe(),
        ], ['updated' => count($values)]);
    }

    /**
     * GET /api/companies/{id}/master-lists
     *
     * The vocabulary as this company sees it: the shared baseline with
     * their own entries shadowing it, each row flagged with which it is.
     */
    public function masterLists(?string $id = null): Response
    {
        $companyId = (int)$this->routeParam('id', $id);
        $activeOnly = $this->request->getQuery('include_inactive') === null;

        $config = new CompanyConfigRepository();

        return $this->respond($config->masterLists($companyId, $activeOnly));
    }

    /**
     * POST /api/companies/{id}/master-lists/{list}/fork
     *
     * Copy the shared baseline into this company as editable rows. The
     * onboarding shortcut for a company that needs to diverge from most of
     * it — faster to fork and prune than to add overrides one at a time.
     */
    public function forkMasterList(?string $id = null, ?string $list = null): Response
    {
        $id = $this->routeParam('id', $id);
        $list = $this->routeParam('list', $list);

        if (!isset(CompanyConfigRepository::MASTER_LISTS[$list])) {
            return $this->fail('not_found', sprintf('No master list called "%s".', $list), 404);
        }

        $config = new CompanyConfigRepository();
        $created = $config->forkSharedList(
            $list,
            (int)$id,
            (string)($this->request->getData('note') ?? '') ?: null,
        );

        return $this->respond(
            $config->masterList($list, (int)$id, activeOnly: false),
            ['created' => $created],
        );
    }

    /**
     * GET /api/companies/{id}/rate-cards
     */
    public function rateCards(?string $id = null): Response
    {
        $id = $this->routeParam('id', $id);

        $cards = $this->fetchTable('RateCards')->find()
            ->where(['company_id' => (int)$id])
            ->orderByDesc('version')
            ->all();

        return $this->respond($cards);
    }

    /**
     * GET /api/companies/{id}/rate-cards/{cardId}
     *
     * A card with its priced lines and SLA rules, plus — for a draft — the
     * problems that would stop it being published. Surfacing gaps here is
     * the point: an unpriced size band discovered at authoring time is a
     * question for the company, and discovered at closure time it is an
     * unbillable job.
     */
    public function rateCard(?string $id = null, ?string $cardId = null): Response
    {
        $id = $this->routeParam('id', $id);
        $cardId = $this->routeParam('card_id', $cardId);

        $card = $this->fetchTable('RateCards')->find()
            ->where(['id' => (int)$cardId, 'company_id' => (int)$id])
            ->first();

        if ($card === null) {
            return $this->fail('not_found', 'No such rate card for this company.', 404);
        }

        $items = $this->fetchTable('RateCardItems')->find()
            ->contain(['JobTypes', 'ProductCategories'])
            ->where(['rate_card_id' => $card->id])
            ->orderByAsc('priority')
            ->all();

        $rules = $this->fetchTable('SlaRules')->find()
            ->where(['rate_card_id' => $card->id])
            ->orderByAsc('priority')
            ->all();

        $payload = [
            'card' => $card,
            'items' => $items,
            'sla_rules' => $rules,
        ];

        if ($card->status === 'draft') {
            $payload['problems'] = (new RateCardAuthoring())->validateDraft((int)$card->id);
        }

        return $this->respond($payload);
    }

    /**
     * POST /api/companies/{id}/rate-cards
     *
     * Starts a draft. Pass `clone_from` to copy an existing card — which is
     * both how a company revises its rates and how a newly signed company
     * gets a card that is 80% right on day one.
     */
    public function createRateCard(?string $id = null): Response
    {
        $companyId = (int)$this->routeParam('id', $id);
        $data = (array)$this->request->getData();
        $authoring = new RateCardAuthoring();

        try {
            $cloneFrom = isset($data['clone_from']) ? (int)$data['clone_from'] : null;

            $cardId = $cloneFrom !== null
                ? $authoring->cloneCard($cloneFrom, $companyId, $data)
                : $authoring->createDraft($companyId, $data);
        } catch (\Cake\Datasource\Exception\RecordNotFoundException) {
            return $this->fail('not_found', 'The card to clone does not exist.', 404);
        }

        return $this->respond(['rate_card_id' => $cardId, 'status' => 'draft'], [], 201);
    }

    /**
     * POST /api/companies/{id}/rate-cards/{cardId}/items
     */
    public function addRateCardItem(?string $id = null, ?string $cardId = null): Response
    {
        $cardId = $this->routeParam('card_id', $cardId);

        try {
            $itemId = (new RateCardAuthoring())->addItem((int)$cardId, (array)$this->request->getData());
        } catch (RateCardLockedException $e) {
            return $this->fail('rate_card_locked', $e->getMessage(), 409);
        } catch (\Cake\ORM\Exception\PersistenceFailedException $e) {
            return $this->fail('validation_error', 'The line could not be saved.', 422, $e->getEntity()->getErrors());
        }

        return $this->respond(['rate_card_item_id' => $itemId], [], 201);
    }

    /**
     * POST /api/companies/{id}/rate-cards/{cardId}/sla-rules
     */
    public function addSlaRule(?string $id = null, ?string $cardId = null): Response
    {
        $cardId = $this->routeParam('card_id', $cardId);

        try {
            $ruleId = (new RateCardAuthoring())->addSlaRule((int)$cardId, (array)$this->request->getData());
        } catch (RateCardLockedException $e) {
            return $this->fail('rate_card_locked', $e->getMessage(), 409);
        } catch (\Cake\ORM\Exception\PersistenceFailedException $e) {
            return $this->fail('validation_error', 'The rule could not be saved.', 422, $e->getEntity()->getErrors());
        }

        return $this->respond(['sla_rule_id' => $ruleId], [], 201);
    }

    /**
     * DELETE /api/companies/{id}/rate-cards/{cardId}/items/{itemId}
     */
    public function deleteRateCardItem(?string $id = null, ?string $cardId = null, ?string $itemId = null): Response
    {
        $itemId = $this->routeParam('item_id', $itemId);

        try {
            (new RateCardAuthoring())->removeItem((int)$itemId);
        } catch (RateCardLockedException $e) {
            return $this->fail('rate_card_locked', $e->getMessage(), 409);
        }

        return $this->respond(['deleted' => true]);
    }

    /**
     * DELETE /api/companies/{id}/rate-cards/{cardId}/sla-rules/{ruleId}
     */
    public function deleteSlaRule(?string $id = null, ?string $cardId = null, ?string $ruleId = null): Response
    {
        $ruleId = $this->routeParam('rule_id', $ruleId);

        try {
            (new RateCardAuthoring())->removeSlaRule((int)$ruleId);
        } catch (RateCardLockedException $e) {
            return $this->fail('rate_card_locked', $e->getMessage(), 409);
        }

        return $this->respond(['deleted' => true]);
    }

    /**
     * POST /api/companies/{id}/rate-cards/{cardId}/publish
     *
     * After this the card is immutable and whatever it supersedes is
     * closed the day before it takes effect.
     */
    public function publishRateCard(?string $id = null, ?string $cardId = null): Response
    {
        $cardId = $this->routeParam('card_id', $cardId);

        $authoring = new RateCardAuthoring();

        $ignore = (array)($this->request->getData('ignore_warnings') ?? []);

        try {
            $result = $authoring->publish(
                (int)$cardId,
                $this->currentUserId(),
                array_map('strval', $ignore),
            );
        } catch (RateCardLockedException $e) {
            return $this->fail('rate_card_locked', $e->getMessage(), 409);
        }

        if ($result['ok'] === false) {
            // 422 rather than 409: the card is editable, it is just not
            // ready, and every problem is something the author can fix.
            return $this->fail(
                'rate_card_not_publishable',
                'The card has problems that must be resolved or explicitly accepted.',
                422,
                [],
                ['problems' => $result['problems']],
            );
        }

        return $this->respond([
            'rate_card_id' => (int)$cardId,
            'status' => 'active',
            'superseded_rate_card_id' => $result['superseded'],
        ]);
    }

    /**
     * GET /api/companies/{id}/rate-cards/{cardId}/items/template
     *
     * A CSV a spreadsheet-literate ops person can fill in without reading
     * this controller: headers matching importRateCardItems()'s columns,
     * one worked example, and — appended after a blank line — every job
     * type and appliance code this company can price against, since those
     * codes are exactly what that endpoint's lookups reject when they
     * don't match.
     */
    public function rateCardItemsTemplate(?string $id = null): Response
    {
        $companyId = (int)$this->routeParam('id', $id);

        $config = new CompanyConfigRepository();
        $jobTypes = $config->masterList('job_types', $companyId);
        $categories = $config->masterList('product_categories', $companyId);

        $handle = fopen('php://temp', 'r+');
        // PHP 8.5 deprecates the implicit escape character default.
        $escape = '\\';

        fputcsv($handle, [
            'job_type_code', 'product_category_code', 'warranty_scope', 'label',
            'size_min_inch', 'size_max_inch', 'amount_rupees', 'payer',
        ], ',', '"', $escape);
        fputcsv(
            $handle,
            ['service', '', 'in_warranty', 'Basic Service Charge', '', '', '500.00', 'company'],
            ',',
            '"',
            $escape,
        );
        fputcsv($handle, [], ',', '"', $escape);
        fputcsv($handle, ['Reference: job_type_code values for this company'], ',', '"', $escape);
        fputcsv($handle, ['code', 'name'], ',', '"', $escape);
        foreach ($jobTypes as $jobType) {
            fputcsv($handle, [$jobType['code'], $jobType['name']], ',', '"', $escape);
        }
        fputcsv($handle, [], ',', '"', $escape);
        fputcsv($handle, ['Reference: product_category_code values (blank = all appliances)'], ',', '"', $escape);
        fputcsv($handle, ['code', 'name'], ',', '"', $escape);
        foreach ($categories as $category) {
            fputcsv($handle, [$category['code'], $category['name']], ',', '"', $escape);
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return $this->response
            ->withType('text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="rate-card-items-template.csv"')
            ->withStringBody($csv);
    }

    /**
     * POST /api/companies/{id}/rate-cards/{cardId}/items/import
     *
     * Bulk-adds priced lines from a CSV upload, column-for-column with the
     * template rateCardItemsTemplate() serves. One bad row does not sink
     * the batch — every row is attempted and reported.
     */
    public function importRateCardItems(?string $id = null, ?string $cardId = null): Response
    {
        $cardId = $this->routeParam('card_id', $cardId);

        $file = $this->request->getUploadedFile('file');
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->fail('invalid_upload', 'No CSV file was uploaded.', 422);
        }

        $rows = $this->parseCsvRows((string)$file->getStream()->getContents());

        try {
            $result = (new RateCardAuthoring())->importItems((int)$cardId, $rows);
        } catch (RateCardLockedException $e) {
            return $this->fail('rate_card_locked', $e->getMessage(), 409);
        }

        return $this->respond([
            'created_count' => count($result['created']),
            'rate_card_item_ids' => $result['created'],
            'duplicate_count' => count(array_filter($result['errors'], static fn(array $e): bool => $e['duplicate'])),
            'errors' => $result['errors'],
        ]);
    }

    /**
     * POST /api/companies/{id}/rate-cards/{cardId}/items/merge-duplicates
     *
     * Collapses lines that only differ by appliance category — the usual
     * result of importing the same CSV before and after a category column
     * was added, or importing on top of lines added by hand — into one.
     * Lines that disagree on amount or payer are left alone and reported
     * as conflicts, since collapsing those would silently pick a price.
     */
    public function mergeRateCardItemDuplicates(?string $id = null, ?string $cardId = null): Response
    {
        $cardId = $this->routeParam('card_id', $cardId);

        try {
            $result = (new RateCardAuthoring())->mergeDuplicateItems((int)$cardId);
        } catch (RateCardLockedException $e) {
            return $this->fail('rate_card_locked', $e->getMessage(), 409);
        }

        return $this->respond($result);
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseCsvRows(string $contents): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        // PHP 8.5 deprecates the implicit escape character default.
        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if ($header === false) {
            fclose($handle);

            return [];
        }
        $header = array_map(static fn($col): string => strtolower(trim((string)$col)), $header);

        $rows = [];
        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (count(array_filter($line, static fn($v): bool => trim((string)$v) !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = (string)($line[$i] ?? '');
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
