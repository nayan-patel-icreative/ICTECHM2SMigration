<?php

namespace App\Jobs;

use App\Models\MigrationItem;
use App\Models\MigrationRun;
use App\Models\ShopifyIdMapping;
use App\Services\Migration\ProductFingerprint;
use App\Services\Migration\ProductPayloadMapper;
use App\Services\Migration\AdvancedPriceMapper;
use App\Services\Migration\ShopifyCollectionSyncService;
use App\Services\Migration\ShopifyMediaSyncService;
use App\Services\Migration\ShopifyProductSyncService;
use App\Services\Migration\ShopifyTranslationSyncService;
use App\Services\Shopware\ShopwareClient;
use App\Services\Migration\ShopifyPriceListSyncService;
use App\Services\Migration\ShopifyPublicationService;
use App\Services\Migration\MigrationRunReportWriter;
use App\Services\Shopify\ShopifyAdminGraphqlClient;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessProductMigrationItemJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1200;

    private int $runId;

    private string $sourceId;

    /**
     * SAFE CHANGE: This job processes exactly one Shopware parent product ID.
     * It is idempotent via:
     * - migration_items unique key (run+entity+source)
     * - Shopify productSet customId upsert
     */
    public function __construct(int $runId, string $sourceId)
    {
        $this->runId = $runId;
        $this->sourceId = $sourceId;
    }

    public function handle(): void
    {
        $run = MigrationRun::query()->with('shop.shopwareConnection')->find($this->runId);
        if (! $run) {
            return;
        }

        if (in_array($run->status, ['cancelled', 'finished', 'failed'], true)) {
            return;
        }

        $shop = $run->shop;
        $conn = $shop ? $shop->shopwareConnection : null;

        if (! $shop || ! $conn) {
            return;
        }

        if (! $run->shopify_location_gid) {
            return;
        }

        $sourceId = trim((string) $this->sourceId);
        if ($sourceId === '') {
            return;
        }

        $item = MigrationItem::query()->firstOrCreate([
            'migration_run_id' => $run->id,
            'entity_type' => 'product',
            'source_id' => $sourceId,
        ], [
            'status' => 'queued',
        ]);

        // If already completed, do nothing (resume-safe).
        if (in_array($item->status, ['skipped', 'succeeded'], true)) {
            return;
        }

        $item->status = 'running';
        $item->started_at = now();
        $item->error_message = null;
        $item->error_context = null;
        $item->save();

        $shopware = app(ShopwareClient::class);
        $mapper = app(ProductPayloadMapper::class);
        $fingerprints = app(ProductFingerprint::class);
        $sync = app(ShopifyProductSyncService::class);
        $publication = app(ShopifyPublicationService::class);
        $mediaSync = app(ShopifyMediaSyncService::class);
        $collectionSync = app(ShopifyCollectionSyncService::class);
        $reportWriter = app(MigrationRunReportWriter::class);
        $priceListSync    = app(ShopifyPriceListSyncService::class);
        $translationSync = app(ShopifyTranslationSyncService::class);


        $t0 = microtime(true);
        $tShopwareParent = 0.0;
        $tShopwareChildren = 0.0;
        $tMap = 0.0;
        $tShopifyUpsert = 0.0;
        $tMedia = 0.0;
        $tCollections = 0.0;

        $fp = null;
        $payload = null;
        $productGidForReport = null;
        $variantCountForReport = 0;


        try {
            // Fetch the parent and its variant children from Shopware in a single request when possible.
            $tStep = microtime(true);
            $result = $shopware->fetchProductWithChildren($conn, $sourceId);
            $parent = $result['parent'] ?? null;
            $children = $result['children'] ?? [];
            $tShopwareParent += (microtime(true) - $tStep);

            if (! is_array($parent) || empty($parent['id'])) {
                $this->markFailed($run, $item, 'Shopware product not found', ['source_id' => $sourceId]);

                return;
            }

            if (! is_array($children)) {
                $children = [];
            }

            if (count($children) > 100) {
                $this->markFailed($run, $item, 'Variant count exceeds Shopify limit (100)', ['child_count' => count($children)]);

                return;
            }

            $tStep = microtime(true);
            $priceMode = is_string($shop->price_mode) && $shop->price_mode !== '' ? $shop->price_mode : 'gross';
            $payload = $mapper->mapParentWithVariants($parent, $children, (string) $run->shopify_location_gid, $shop->id, $priceMode);
            $fp = $fingerprints->make($payload);
            $tMap += (microtime(true) - $tStep);

            $variantCountForReport = count(is_array($children) ? $children : []);


            // Skip if identical to latest successful run and Shopify product still exists.
            $previousFp = $this->latestSucceededFingerprint($shop->id, $sourceId);
            if (is_string($previousFp) && $previousFp !== '' && is_string($fp) && $fp !== '' && hash_equals($previousFp, $fp)) {
                $mapping = ShopifyIdMapping::query()
                    ->where('shop_id', $shop->id)
                    ->where('entity_type', 'product')
                    ->where('source_id', $sourceId)
                    ->first();

                if ($mapping && is_string($mapping->shopify_gid) && $mapping->shopify_gid !== '') {
                    // Quick existence check to handle cases where product was deleted from Shopify
                    $exists = $this->shopifyProductExists($shop, $mapping->shopify_gid);
                    if ($exists) {
                        $pubRes = $publication->publishToOnlineStore($shop, $mapping->shopify_gid);
                        if (! empty($pubRes['errors']) || ! empty($pubRes['userErrors'])) {
                            $ctx = is_array($item->error_context) ? $item->error_context : [];
                            $ctx['publication_sync'] = $pubRes;
                            $item->error_context = $ctx;
                        }

                        // Sync product visibility across specific market publications based on Shopware visibilities
                        $visibilities = data_get($parent, 'visibilities', []);
                        if (is_array($visibilities)) {
                            $marketPubRes = $publication->syncProductToMarkets($shop, $mapping->shopify_gid, $visibilities);
                            foreach ($marketPubRes as $marketGid => $res) {
                                if (!$res['ok']) {
                                    $ctx = is_array($item->error_context) ? $item->error_context : [];
                                    $ctx['market_publication_sync'][$marketGid] = $res;
                                    $item->error_context = $ctx;
                                }
                            }
                        }

                        $item->status = 'skipped';
                        $item->fingerprint = $fp;
                        $item->finished_at = now();
                        $item->save();

                        $reportWriter->appendRow($run, [
                            'shopware_product_id' => $item->source_id,
                            'product_number' => (string) data_get($parent, 'productNumber', ''),
                            'product_name' => (string) (data_get($parent, 'translated.name') ?: data_get($parent, 'name') ?: ''),
                            'variant_count' => count(is_array($children) ? $children : []),
                            'status' => 'skipped',
                            'reason' => 'No changes detected (fingerprint matched existing Shopify product)',
                            'shopify_product_id' => (string) ($mapping->shopify_gid ?? ''),
                            'migrated_at_utc' => $item->finished_at ? $item->finished_at->toDateTimeString() : '',
                        ]);

                        $this->incrementRunCounters($run->id, [
                            'processed' => 1,
                        ]);

                        return;

                    }

                    // Mapping stale; remove so next upsert recreates properly.
                    $mapping->delete();
                }
            }

            // Serialize Shopify writes per shop with sharding to allow controlled parallelism.
            $lock = Cache::lock($this->productWriteLockKey($shop->id, $sourceId), 300);
            $tStep = microtime(true);
            $upsert = $lock->block(120, function () use ($sync, $shop, $sourceId, $payload) {
                return $sync->upsertByCustomId($shop, $sourceId, $payload);
            });
            $tShopifyUpsert += (microtime(true) - $tStep);

            if (! empty($upsert['errors'])) {
                $err0 = is_array($upsert['errors']) ? ($upsert['errors'][0] ?? null) : null;
                $status = is_array($err0) ? ($err0['status'] ?? null) : null;
                $msg = is_array($err0) ? (string) ($err0['message'] ?? '') : '';

                if ($this->isShopifyRateLimited($status, $msg)) {
                    $this->requeueRateLimited($item, $fp, ['errors' => $upsert['errors']]);
                    $this->release(60);

                    return;
                }

                $this->markFailed($run, $item, 'Shopify API error', ['errors' => $upsert['errors'], 'fingerprint' => $fp]);

                return;
            }

            if (! empty($upsert['userErrors'])) {
                $firstUserErr = is_array($upsert['userErrors']) ? ($upsert['userErrors'][0] ?? null) : null;
                $userMsg = is_array($firstUserErr) ? (string) ($firstUserErr['message'] ?? '') : '';

                if ($this->isShopifyRateLimited(null, $userMsg)) {
                    $this->requeueRateLimited($item, $fp, ['userErrors' => $upsert['userErrors']]);
                    $this->release(60);

                    return;
                }

                $this->markFailed($run, $item, 'Shopify userErrors', ['userErrors' => $upsert['userErrors'], 'fingerprint' => $fp]);

                return;
            }

            $productGidForReport = (string) ($upsert['productGid'] ?? '');
            $productGid = $productGidForReport;
            if ($productGid === '') {
                $this->markFailed($run, $item, 'Shopify did not return product id', ['fingerprint' => $fp]);

                return;
            }


            ShopifyIdMapping::query()->updateOrCreate([
                'shop_id' => $shop->id,
                'entity_type' => 'product',
                'source_id' => $sourceId,
            ], [
                'shopify_gid' => $productGid,
            ]);

            $pubRes = $publication->publishToOnlineStore($shop, $productGid);
            if (! empty($pubRes['errors']) || ! empty($pubRes['userErrors'])) {
                $ctx = is_array($item->error_context) ? $item->error_context : [];
                $ctx['publication_sync'] = $pubRes;
                $item->error_context = $ctx;
            }

            // Sync product visibility across specific market publications based on Shopware visibilities
            $visibilities = data_get($parent, 'visibilities', []);
            if (is_array($visibilities)) {
                $marketPubRes = $publication->syncProductToMarkets($shop, $productGid, $visibilities);
                foreach ($marketPubRes as $marketGid => $res) {
                    if (!$res['ok']) {
                        Log::warning('Product market publication sync failed', [
                            'run_id' => $run->id,
                            'shop' => $shop->shop_domain,
                            'source_id' => $sourceId,
                            'market_gid' => $marketGid,
                            'result' => $res
                        ]);
                        $ctx = is_array($item->error_context) ? $item->error_context : [];
                        $ctx['market_publication_sync'][$marketGid] = $res;
                        $item->error_context = $ctx;
                    }
                }
            }

            // Sync prices to the Shopware currency price list (same mechanism as orderCreate currency)
            $variantIdByShopwareId = $upsert['variantIdByShopwareId'] ?? [];
            $variantIdByShopwareId = is_array($variantIdByShopwareId) ? $variantIdByShopwareId : [];
            $allVariantGids = $upsert['allVariantGids'] ?? [];
            $allVariantGids = is_array($allVariantGids) ? $allVariantGids : [];

            if (count($variantIdByShopwareId) > 0 || count($allVariantGids) > 0) {
                $tStep = microtime(true);
                $priceData = $mapper->extractVariantPricesForPriceList(
                    $variantIdByShopwareId,
                    $parent,
                    $children,
                    $shop,
                    $allVariantGids,
                    $priceMode
                );
                if ($priceData['currency'] !== '' && count($priceData['variantPrices']) > 0) {
                    $plRes = $priceListSync->syncVariantPrices(
                        $shop,
                        $priceData['currency'],
                        $priceData['variantPrices'],
                        $priceData['variantComparePrices']
                    );
                    if (!empty($plRes['errors']) || !empty($plRes['userErrors'])) {
                        Log::warning('Price list sync failed (product still migrated)', [
                            'run_id' => $run->id,
                            'shop' => $shop->shop_domain,
                            'source_id' => $sourceId,
                            'currency' => $priceData['currency'],
                            'result' => $plRes,
                        ]);
                        $ctx = is_array($item->error_context) ? $item->error_context : [];
                        $ctx['price_list_sync'] = $plRes;
                        $item->error_context = $ctx;
                        $item->save();
                    }

                    // Advanced price sync (non-fatal) — must run after base price sync
                    $advancedPrices = data_get($parent, 'prices', []);
                    if (is_array($advancedPrices) && count($advancedPrices) > 0) {
                        $advMapper = app(AdvancedPriceMapper::class);
                        $grouped = $advMapper->map(
                            $advancedPrices,
                            $variantIdByShopwareId,
                            $allVariantGids,
                            $priceData['currency'],
                            $priceMode
                        );
                        if (count($grouped) > 0) {
                            $advResults = $priceListSync->syncAdvancedPrices($shop, $priceData['currency'], $grouped);
                            foreach ($advResults as $ruleId => $result) {
                                if (!empty($result['errors']) || !empty($result['userErrors'])) {
                                    Log::warning('Advanced price list sync failed (product still migrated)', [
                                        'run_id'    => $run->id,
                                        'shop'      => $shop->shop_domain,
                                        'source_id' => $sourceId,
                                        'rule_id'   => $ruleId,
                                        'result'    => $result,
                                    ]);
                                    $ctx = is_array($item->error_context) ? $item->error_context : [];
                                    $ctx['advanced_price_list_sync'][$ruleId] = $result;
                                    $item->error_context = $ctx;
                                    $item->save();
                                }
                            }
                        }
                    }
                }
                $tShopifyUpsert += (microtime(true) - $tStep);
            }

            $metafields = $mapper->mapShopwareMetafields($parent, $children, $shop, $priceMode);
            if (count($metafields) > 0) {
                $tStep = microtime(true);
                
                // Separate product and variant metafields
                $productMetafields = [];
                $variantMetafields = [];
                foreach ($metafields as $mf) {
                    $key = (string) ($mf['key'] ?? '');
                    if (str_starts_with($key, 'variant_digital_')) {
                        $variantMetafields[] = $mf;
                    } else {
                        $productMetafields[] = $mf;
                    }
                }

                // Set product-level metafields
                if (count($productMetafields) > 0) {
                    $mfRes = $sync->setProductMetafields($shop, $productGid, $productMetafields);
                    if (! empty($mfRes['errors']) || (! empty($mfRes['userErrors']) && is_array($mfRes['userErrors']))) {
                        $ctx = is_array($item->error_context) ? $item->error_context : [];
                        $ctx['metafields_sync'] = $mfRes;
                        $item->error_context = $ctx;
                        $item->save();
                    }
                }

                // Set variant-level metafields (digital files per variant)
                if (count($variantMetafields) > 0 && count($variantIdByShopwareId) > 0) {
                    $tStep = microtime(true);
                    $variantMfRes = $sync->setVariantMetafields($shop, $variantIdByShopwareId, $variantMetafields);
                    if (! empty($variantMfRes['errors']) || (! empty($variantMfRes['userErrors']) && is_array($variantMfRes['userErrors']))) {
                        Log::warning('Variant metafields sync failed (product still migrated)', [
                            'run_id' => $run->id,
                            'shop' => $shop->shop_domain,
                            'source_id' => $sourceId,
                            'result' => $variantMfRes,
                        ]);
                        $ctx = is_array($item->error_context) ? $item->error_context : [];
                        $ctx['variant_metafields_sync'] = $variantMfRes;
                        $item->error_context = $ctx;
                        $item->save();
                    }
                }

                $tShopifyUpsert += (microtime(true) - $tStep);
            }

            // --- Translation sync (non-blocking) ---
            // Translations are embedded in the already-fetched $parent data (no extra Shopware API call).
            // Errors here are logged as warnings and stored in error_context but never fail the item.
            $enabledLanguages = ShopwareClient::enabledLanguages($conn);
            try {
                if (count($enabledLanguages) > 0) {
                    $translationsByLocale = ShopifyTranslationSyncService::extractTranslationsFromEntity($parent, $enabledLanguages);
                    if (count($translationsByLocale) > 0) {
                        $transRes = $translationSync->syncTranslations($shop, $productGid, $translationsByLocale);
                        if (!empty($transRes['translation_api_errors']) || !empty($transRes['metafield_errors'])) {
                            $ctx = is_array($item->error_context) ? $item->error_context : [];
                            $ctx['translation_sync'] = $transRes;
                            $item->error_context = $ctx;
                            $item->save();
                        }
                    }
                }
            } catch (\Throwable $translationException) {
                Log::warning('Product translation sync failed (product still migrated)', [
                    'run_id'    => $run->id,
                    'shop'      => $shop->shop_domain,
                    'source_id' => $sourceId,
                    'error'     => $translationException->getMessage(),
                ]);
            }

            // Media + collections (media failures must not fail the product — productSet already succeeded)
            $tStep = microtime(true);
            $variantIdByShopwareIdForMedia = is_array($variantIdByShopwareId) && count($variantIdByShopwareId) > 0
                ? $variantIdByShopwareId
                : null;
            try {
                $mediaRes = $mediaSync->syncProductAndVariantImages($shop, $productGid, (string) $conn->api_url, $parent, $children, $variantIdByShopwareIdForMedia);
                if (! empty($mediaRes['errors']) || (! empty($mediaRes['userErrors']) && is_array($mediaRes['userErrors']))) {
                    $ctx = is_array($item->error_context) ? $item->error_context : [];
                    $ctx['media_sync'] = $mediaRes;
                    $item->error_context = $ctx;
                    $item->save();
                }
            } catch (\Throwable $mediaException) {
                Log::warning('Product media sync failed (product still migrated)', [
                    'run_id' => $run->id,
                    'shop' => $shop->shop_domain,
                    'source_id' => $sourceId,
                    'product_gid' => $productGid,
                    'error' => $mediaException->getMessage(),
                ]);
                $ctx = is_array($item->error_context) ? $item->error_context : [];
                $ctx['media_sync'] = [
                    'exception' => get_class($mediaException),
                    'message' => $mediaException->getMessage(),
                ];
                $item->error_context = $ctx;
                $item->save();
            }
            $tMedia += (microtime(true) - $tStep);

            $categories = data_get($parent, 'categories', []);
            if (is_array($categories) && count($categories) > 0) {
                foreach ($categories as $cat) {
                    $tCatStart = microtime(true);
                    $cid = (string) data_get($cat, 'id', '');
                    if ($cid === '') {
                        $tCollections += (microtime(true) - $tCatStart);

                        continue;
                    }

                    $catPayload = is_array($cat) ? $cat : ['name' => 'Category'];
                    $title = (string) (data_get($catPayload, 'translated.name') ?: data_get($catPayload, 'name') ?: 'Category');
                    $colRes = $collectionSync->upsertCollectionForCategoryAndAddProduct($shop, $cid, $catPayload, $productGid, $enabledLanguages);
                    $tCollections += (microtime(true) - $tCatStart);
                    if (! empty($colRes['errors']) || (! empty($colRes['userErrors']) && is_array($colRes['userErrors']))) {
                        $ctx = is_array($item->error_context) ? $item->error_context : [];
                        $ctx['collection_sync'] = $ctx['collection_sync'] ?? [];
                        $ctx['collection_sync'][] = [
                            'category_id' => $cid,
                            'title' => $title,
                            'result' => $colRes,
                        ];
                        $item->error_context = $ctx;
                        $item->save();
                    }
                }
            }

            $item->status = 'succeeded';
            $item->fingerprint = $fp;
            $item->shopify_gid = $productGid;
            $item->finished_at = now();
            $item->save();

            $reportWriter->appendRow($run, [
                'shopware_product_id' => $item->source_id,
                'product_number' => (string) data_get($parent, 'productNumber', ''),
                'product_name' => (string) (data_get($parent, 'translated.name') ?: data_get($parent, 'name') ?: ''),
                'variant_count' => $variantCountForReport,
                'status' => 'succeeded',
                'reason' => '',
                'shopify_product_id' => (string) $productGidForReport,
                'migrated_at_utc' => $item->finished_at ? $item->finished_at->toDateTimeString() : '',
            ]);


            $this->incrementRunCounters($run->id, [
                'processed' => 1,
                'succeeded' => 1,
            ]);

            $totalSec = microtime(true) - $t0;
            Log::info('Product migrated timing', [
                'run_id' => $run->id,
                'shop' => $shop->shop_domain,
                'source_id' => $sourceId,
                'shopify_gid' => $productGid,
                'sec_total' => round($totalSec, 3),
                'sec_shopware_parent' => round($tShopwareParent, 3),
                'sec_shopware_children' => round($tShopwareChildren, 3),
                'sec_map' => round($tMap, 3),
                'sec_shopify_upsert' => round($tShopifyUpsert, 3),
                'sec_media' => round($tMedia, 3),
                'sec_collections' => round($tCollections, 3),
            ]);

            Log::info('Product migrated', [
                'run_id' => $run->id,
                'shop' => $shop->shop_domain,
                'source_id' => $sourceId,
                'shopify_gid' => $productGid,
            ]);
        } catch (\Throwable $e) {
            Log::error('Product migration item failed', [
                'run_id' => $run->id,
                'shop' => $shop->shop_domain,
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
            ]);

            $this->markFailed($run, $item, $e->getMessage(), [
                'fingerprint' => $fp,
                'exception' => get_class($e),
                'payload_present' => is_array($payload),
            ]);
        }
    }

    private function productWriteLockKey(int $shopId, string $sourceId): string
    {
        $shards = (int) env('SHOPIFY_PRODUCT_WRITE_SHARDS', 50);
        if ($shards < 1) {
            $shards = 1;
        }

        $shard = 0;
        if ($shards > 1) {
            $shard = (int) (abs(crc32($sourceId)) % $shards);
        }

        return 'shopify:product_write:'.$shopId.':'.$shard;
    }

    private function markFailed(MigrationRun $run, MigrationItem $item, string $message, array $context): void
    {
        $item->status = 'failed';
        $item->error_message = $message;
        $item->error_context = $context;
        $item->finished_at = now();
        $item->save();

        try {
            $reportWriter = app(MigrationRunReportWriter::class);
            $runId = $run->id;

            $productNumber = '';
            $productName = '';
            $variantCountForReport = 0;
            $shopifyProductId = is_string($item->shopify_gid) ? $item->shopify_gid : '';

            $reportWriter->appendRow($runId, [
                'shopware_product_id' => $item->source_id,
                'product_number' => $productNumber,
                'product_name' => $productName,
                'variant_count' => $variantCountForReport,
                'status' => 'failed',
                'reason' => $reportWriter->humanizeFailureReason($item),
                'shopify_product_id' => (string) $shopifyProductId,
                'migrated_at_utc' => $item->finished_at ? $item->finished_at->toDateTimeString() : '',
            ]);
        } catch (\Throwable $e) {
            // Never affect migration.
        }


        if ($run->status !== 'cancelled') {
            $this->incrementRunCounters($run->id, [
                'processed' => 1,
                'failed' => 1,
            ]);
        }
    }

    /**
     * @param  array<string, int>  $counters
     */
    private function incrementRunCounters(int $runId, array $counters): void
    {
        $updates = ['updated_at' => now()];
        foreach ($counters as $column => $amount) {
            $amount = (int) $amount;
            if ($amount === 0) {
                continue;
            }

            $updates[$column] = DB::raw($column.' + '.$amount);
        }

        if (count($updates) <= 1) {
            return;
        }

        MigrationRun::query()
            ->whereKey($runId)
            ->where('status', '!=', 'cancelled')
            ->update($updates);
    }

    private function requeueRateLimited(MigrationItem $item, ?string $fingerprint, array $context): void
    {
        $prevRetries = (int) data_get($item->error_context, 'rate_limit_requeues', 0);

        $item->status = 'queued';
        $item->error_message = 'Shopify rate limited; will retry';
        $item->error_context = array_merge($context, [
            'fingerprint' => $fingerprint,
            'rate_limited' => true,
            'rate_limit_requeues' => $prevRetries + 1,
        ]);
        $item->started_at = null;
        $item->finished_at = null;
        $item->save();

        Log::warning('Product rate limited; requeueing', [
            'migration_run_id' => $item->migration_run_id,
            'entity_type' => $item->entity_type,
            'source_id' => $item->source_id,
            'requeues' => $prevRetries + 1,
        ]);
    }

    private function latestSucceededFingerprint(int $shopId, string $sourceId): ?string
    {
        $row = MigrationItem::query()
            ->select('migration_items.fingerprint')
            ->join('migration_runs', 'migration_runs.id', '=', 'migration_items.migration_run_id')
            ->where('migration_runs.shop_id', $shopId)
            ->where('migration_runs.type', 'products')
            ->where('migration_items.entity_type', 'product')
            ->where('migration_items.source_id', $sourceId)
            ->where('migration_items.status', 'succeeded')
            ->whereNotNull('migration_items.fingerprint')
            ->orderByDesc('migration_items.id')
            ->first();

        if (! $row) {
            return null;
        }

        $fp = $row->fingerprint;

        return is_string($fp) && $fp !== '' ? $fp : null;
    }

    private function shopifyProductExists($shop, string $productGid): bool
    {
        try {
            $client = app(ShopifyAdminGraphqlClient::class);
            $q = <<<'GQL'
query ProductExists($id: ID!) {
  product(id: $id) { id }
}
GQL;

            $res = $client->query($shop, $q, ['id' => $productGid]);
            if (isset($res['errors'])) {
                return false;
            }

            $id = (string) data_get($res, 'data.product.id', '');

            return $id !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function isShopifyRateLimited($status, string $message): bool
    {
        if ($status === 429) {
            return true;
        }

        $m = strtolower($message);

        return str_contains($m, 'too many attempts')
            || str_contains($m, 'throttled')
            || str_contains($m, 'throttle')
            || str_contains($m, 'rate limit');
    }
}
