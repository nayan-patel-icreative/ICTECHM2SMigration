<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StartProductMigrationRequest;
use App\Models\Shop;
use App\Services\Migration\ProductFingerprint;
use App\Services\Migration\ProductMigrationService;
use App\Services\Migration\ProductPayloadMapper;
use App\Services\Migration\ShopifyRedirectSyncService;
use App\Services\QueueHealthService;
use App\Services\Shopware\ShopwareClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class MigrationController extends Controller
{
    private ProductMigrationService $service;

    public function __construct(ProductMigrationService $service)
    {
        $this->service = $service;
    }

    public function preview(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        $conn = $shop ? $shop->shopwareConnection : null;

        if (!$shop || !$conn) {
            return response()->json(['error' => 'Missing Shopware connection'], 422);
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'location_gid' => ['required', 'string', 'max:255'],
            'include_payload' => ['nullable', 'boolean'],
        ]);

        $limit = (int) ($validated['limit'] ?? 5);
        $page = (int) ($validated['page'] ?? 1);
        $locationGid = (string) $validated['location_gid'];
        $includePayload = (bool) ($validated['include_payload'] ?? false);

        $shopware = app(ShopwareClient::class);
        $mapper = app(ProductPayloadMapper::class);
        $fingerprints = app(ProductFingerprint::class);

        $res = $shopware->searchProducts($conn, 50, $page);
        $products = $res['products'] ?? [];
        if (!is_array($products) || count($products) === 0) {
            return response()->json([
                'page' => $page,
                'total' => (int) ($res['total'] ?? 0),
                'items' => [],
            ]);
        }

        $parents = array_values(array_filter($products, function ($p) {
            return empty($p['parentId']);
        }));

        $items = [];
        foreach (array_slice($parents, 0, $limit) as $p) {
            $sourceId = (string) ($p['id'] ?? '');
            if ($sourceId === '') {
                continue;
            }

            $children = $shopware->fetchVariantChildren($conn, $sourceId);
            $payload = $mapper->mapParentWithVariants($p, $children, $locationGid, $shop->id);
            $fp = $fingerprints->make($payload);

            $childCount = is_array($children) ? count($children) : 0;
            $expectedVariantCount = $childCount > 0 ? $childCount : 1;

            $childSample = [];
            if (is_array($children) && count($children) > 0) {
                foreach (array_slice($children, 0, 10) as $c) {
                    $childSample[] = [
                        'id' => $c['id'] ?? null,
                        'sku' => $c['productNumber'] ?? null,
                    ];
                }
            }

            $variants = data_get($payload, 'variants', []);
            $productOptions = data_get($payload, 'productOptions', []);
            $variantCount = is_array($variants) ? count($variants) : 0;
            $optionCount = is_array($productOptions) ? count($productOptions) : 0;

            $vendor = (string) data_get($payload, 'vendor', '');
            $productType = (string) data_get($payload, 'productType', '');
            $seoTitle = (string) data_get($payload, 'seo.title', '');
            $seoDescription = (string) data_get($payload, 'seo.description', '');
            $seoHandle = (string) data_get($payload, 'handle', '');
            $metafields = $mapper->mapShopwareMetafields($p, $children, $shop);
            $hasSeoKeywords = false;
            foreach ($metafields as $mf) {
                if ((string) data_get($mf, 'key', '') === 'seo_keywords' && trim((string) data_get($mf, 'value', '')) !== '') {
                    $hasSeoKeywords = true;
                    break;
                }
            }

            $mediaCount = 0;
            $media = data_get($p, 'media', []);
            if (is_array($media)) {
                $mediaCount = count($media);
            }
            $hasCover = !empty(data_get($p, 'cover.media.url'));

            $categorySummaries = [];
            $categories = data_get($p, 'categories', []);
            if (is_array($categories)) {
                foreach ($categories as $c) {
                    $cid = (string) data_get($c, 'id', '');
                    $cname = (string) (data_get($c, 'translated.name') ?: data_get($c, 'name') ?: '');
                    if ($cid !== '' || $cname !== '') {
                        $categorySummaries[] = [
                            'id' => $cid !== '' ? $cid : null,
                            'name' => $cname !== '' ? $cname : null,
                        ];
                    }
                }
            }

            $variantSample = null;
            if (is_array($variants) && count($variants) > 0 && is_array($variants[0])) {
                $variantSample = [
                    'sku' => data_get($variants[0], 'sku'),
                    'price' => data_get($variants[0], 'price'),
                    'compare_at' => data_get($variants[0], 'compareAtPrice'),
                    'qty' => data_get($variants[0], 'inventoryQuantities.0.quantity'),
                    'weight' => data_get($variants[0], 'weight'),
                ];
            }

            $issues = [];
            if ($variantCount > 100) {
                $issues[] = 'Variant count exceeds Shopify limit (100). This product needs splitting or pruning.';
            }
            if ($optionCount > 3) {
                $issues[] = 'Option count exceeds Shopify limit (3).';
            }
            if ($variantCount === 0) {
                $issues[] = 'No variants generated (unexpected).';
            }
            if ($variantCount !== $expectedVariantCount) {
                $issues[] = 'Variant audit mismatch: mapped variant count does not match Shopware child count.';
            }

            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $payloadBytes = is_string($json) ? strlen($json) : null;

            $out = [
                'source_id' => $sourceId,
                'title' => (string) data_get($payload, 'title', ''),
                'vendor' => $vendor,
                'product_type' => $productType,
                'seo_present' => ($seoTitle !== '' || $seoDescription !== ''),
                'seo_handle' => $seoHandle !== '' ? $seoHandle : null,
                'seo_keywords_present' => $hasSeoKeywords,
                'media_count' => $mediaCount,
                'has_cover' => $hasCover,
                'categories' => $categorySummaries,
                'variant_sample' => $variantSample,
                'shopware_child_count' => $childCount,
                'expected_variant_count' => $expectedVariantCount,
                'variant_count' => $variantCount,
                'option_count' => $optionCount,
                'fingerprint' => $fp,
                'payload_bytes' => $payloadBytes,
                'child_sample' => $childSample,
                'issues' => $issues,
            ];

            if ($includePayload) {
                $out['payload'] = $payload;
            }

            $items[] = $out;
        }

        return response()->json([
            'page' => $page,
            'total' => (int) ($res['total'] ?? 0),
            'items' => $items,
        ]);
    }

    public function status(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        $run = $this->service->status($shop);

        if (!$run) {
            return response()->json(['run' => null]);
        }

        $recentFailed = $run->items()
            ->where('status', 'failed')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'source_id', 'error_message', 'error_context', 'finished_at']);

        $recentFailedOut = [];
        foreach ($recentFailed as $it) {
            $recentFailedOut[] = [
                'id' => $it->id,
                'source_id' => $it->source_id,
                'error_message' => $it->error_message,
                'error_context' => $it->error_context,
                'finished_at' => $it->finished_at,
            ];
        }

        $durationSeconds = null;
        if ($run->started_at) {
            $end = $run->finished_at ?: now();
            $durationSeconds = max(0, $run->started_at->diffInSeconds($end));
        }

        return response()->json([
            'run' => [
                'id' => $run->id,
                'type' => $run->type,
                'status' => $run->status,
                'processed' => $run->processed,
                'succeeded' => $run->succeeded,
                'failed' => $run->failed,
                'shopify_location_gid' => $run->shopify_location_gid,
                'started_at' => $run->started_at,
                'finished_at' => $run->finished_at,
                'duration_seconds' => $durationSeconds,
                'report_available' => is_string($run->report_path) && trim((string) $run->report_path) !== '' && is_file((string) $run->report_path),
                'report_download_url' => '/api/migration/runs/' . $run->id . '/report',
                'pdf_available' => in_array((string) $run->status, ['finished', 'cancelled'], true) && is_string($run->report_path) && trim((string) $run->report_path) !== '' && is_file((string) $run->report_path),
                'pdf_download_url' => '/api/migration/runs/' . $run->id . '/report-pdf',
            ],
            'recent_failed_items' => $recentFailedOut,
        ]);
    }

    public function start(StartProductMigrationRequest $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $queueHealth = app(QueueHealthService::class);
        $workerOnline = $queueHealth->probe();
        if (!$workerOnline) {
            return response()->json([
                'error' => 'Queue worker is not running. Migration cannot start until the worker process is online.',
            ], 409);
        }

        $run = $this->service->start($shop, (string) $request->validated('location_gid'));

        return response()->json([
            'run_id' => $run->id,
            'status' => $run->status,
        ], 202);
    }

    public function previewRedirects(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        $conn = $shop ? $shop->shopwareConnection : null;

        if (!$shop || !$conn) {
            return response()->json(['error' => 'Missing Shopware connection'], 422);
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $limit = (int) ($validated['limit'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);

        $shopware = app(ShopwareClient::class);
        $mapper = app(ProductPayloadMapper::class);

        $res = $shopware->searchProducts($conn, 100, $page);
        $products = $res['products'] ?? [];
        $parents = array_values(array_filter(is_array($products) ? $products : [], function ($p) {
            return empty($p['parentId']);
        }));

        $items = [];
        foreach (array_slice($parents, 0, $limit) as $p) {
            $sourceId = (string) data_get($p, 'id', '');
            if ($sourceId === '') {
                continue;
            }

            $seo = $mapper->mapSeoFields($p);
            $fromPath = $this->normalizeRedirectPath((string) ($seo['seo_path'] ?? ''));
            $handle = trim((string) ($seo['handle'] ?? ''));
            if ($fromPath === '' || $handle === '') {
                continue;
            }

            $toPath = '/products/'.$handle;
            $items[] = [
                'source_id' => $sourceId,
                'product_name' => (string) (data_get($p, 'translated.name') ?: data_get($p, 'name') ?: ''),
                'old_path' => $fromPath,
                'new_path' => $toPath,
                'handle' => $handle,
            ];
        }

        return response()->json([
            'page' => $page,
            'total' => (int) ($res['total'] ?? 0),
            'items' => $items,
        ]);
    }

    public function importRedirects(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        $conn = $shop ? $shop->shopwareConnection : null;

        if (!$shop || !$conn) {
            return response()->json(['error' => 'Missing Shopware connection'], 422);
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $limit = (int) ($validated['limit'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);

        $shopware = app(ShopwareClient::class);
        $mapper = app(ProductPayloadMapper::class);
        $redirects = app(ShopifyRedirectSyncService::class);

        $res = $shopware->searchProducts($conn, 100, $page);
        $products = $res['products'] ?? [];
        $parents = array_values(array_filter(is_array($products) ? $products : [], function ($p) {
            return empty($p['parentId']);
        }));

        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $errors = [];

        foreach (array_slice($parents, 0, $limit) as $p) {
            $sourceId = (string) data_get($p, 'id', '');
            if ($sourceId === '') {
                continue;
            }

            $seo = $mapper->mapSeoFields($p);
            $fromPath = $this->normalizeRedirectPath((string) ($seo['seo_path'] ?? ''));
            $handle = trim((string) ($seo['handle'] ?? ''));
            if ($fromPath === '' || $handle === '') {
                continue;
            }

            $processed++;
            $toPath = '/products/'.$handle;
            $result = $redirects->upsertRedirect($shop, $fromPath, $toPath);
            if (!empty($result['errors']) || !empty($result['userErrors'])) {
                $failed++;
                $errors[] = [
                    'source_id' => $sourceId,
                    'old_path' => $fromPath,
                    'new_path' => $toPath,
                    'errors' => $result['errors'] ?? null,
                    'userErrors' => $result['userErrors'] ?? null,
                ];
                continue;
            }

            $succeeded++;
        }

        return response()->json([
            'page' => $page,
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'errors' => $errors,
        ]);
    }

    private function normalizeRedirectPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $path = preg_replace('~^https?://[^/]+~i', '', $path) ?? $path;
        if (!str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return '/'.trim($path, '/');
    }

    public function cancel(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        $cancelled = $this->service->cancel($shop);

        return response()->json(['cancelled' => $cancelled]);
    }

    public function previewFiltered(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        $conn = $shop ? $shop->shopwareConnection : null;

        if (!$shop || !$conn) {
            return response()->json(['error' => 'Missing Shopware connection'], 422);
        }

        $validated = $request->validate([
            'mode'            => ['required', 'string', 'in:after,between'],
            'after'           => ['nullable', 'date_format:Y-m-d'],
            'before'          => ['nullable', 'date_format:Y-m-d'],
            'location_gid'    => ['required', 'string', 'max:255'],
            'limit'           => ['nullable', 'integer', 'min:1', 'max:20'],
            'page'            => ['nullable', 'integer', 'min:1', 'max:100000'],
            'include_payload' => ['nullable', 'boolean'],
        ]);

        $mode   = (string) $validated['mode'];
        $after  = isset($validated['after'])  ? (string) $validated['after']  : null;
        $before = isset($validated['before']) ? (string) $validated['before'] : null;
        $filter = $this->buildCreatedAtFilter($mode, $after, $before);
        if (isset($filter['error'])) {
            return response()->json(['error' => $filter['error']], 422);
        }

        $limit      = (int) ($validated['limit'] ?? 5);
        $page       = (int) ($validated['page'] ?? 1);
        $locationGid = (string) $validated['location_gid'];

        $shopware = app(ShopwareClient::class);

        $res      = $shopware->searchProducts($conn, 50, $page, $filter);
        $products = $res['products'] ?? [];
        $total    = (int) ($res['total'] ?? 0);

        return response()->json([
            'page'  => $page,
            'total' => $total,
            'items' => [],
        ]);
    }

    public function startFiltered(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $queueHealth = app(QueueHealthService::class);
        if (!$queueHealth->probe()) {
            return response()->json([
                'error' => 'Queue worker is not running. Migration cannot start until the worker process is online.',
            ], 409);
        }

        $validated = $request->validate([
            'mode'         => ['required', 'string', 'in:after,between'],
            'after'        => ['nullable', 'date_format:Y-m-d'],
            'before'       => ['nullable', 'date_format:Y-m-d'],
            'location_gid' => ['required', 'string', 'max:255', 'regex:/^gid:\/\/shopify\/Location\/[0-9]+$/'],
        ]);

        $mode   = (string) $validated['mode'];
        $after  = isset($validated['after'])  ? (string) $validated['after']  : null;
        $before = isset($validated['before']) ? (string) $validated['before'] : null;
        $filter = $this->buildCreatedAtFilter($mode, $after, $before);
        if (isset($filter['error'])) {
            return response()->json(['error' => $filter['error']], 422);
        }

        $locationGid = (string) $validated['location_gid'];
        $run = $this->service->startFiltered($shop, $locationGid, $filter);

        return response()->json([
            'run_id' => $run->id,
            'status' => $run->status,
        ], 202);
    }

    /**
     * @return array<int, mixed>|array{error: string}
     */
    private function buildCreatedAtFilter(string $mode, ?string $after, ?string $before): array
    {
        if ($mode === 'after') {
            if (!$after) {
                return ['error' => 'The after date is required for mode=after'];
            }
            $gte = CarbonImmutable::createFromFormat('Y-m-d', $after)
                ->startOfDay()
                ->toIso8601String();
            return [[
                'type'       => 'range',
                'field'      => 'createdAt',
                'parameters' => ['gte' => $gte],
            ]];
        }

        if ($mode === 'between') {
            if (!$after || !$before) {
                return ['error' => 'Both after and before dates are required for mode=between'];
            }
            $from = CarbonImmutable::createFromFormat('Y-m-d', $after)->startOfDay();
            $to   = CarbonImmutable::createFromFormat('Y-m-d', $before)->endOfDay();
            if ($from->greaterThan($to)) {
                return ['error' => 'The after date must be before or equal to the before date'];
            }
            return [[
                'type'       => 'range',
                'field'      => 'createdAt',
                'parameters' => [
                    'gte' => $from->toIso8601String(),
                    'lte' => $to->toIso8601String(),
                ],
            ]];
        }

        return ['error' => 'Invalid mode'];
    }
}
