<?php

namespace App\Jobs;

use App\Models\MigrationRun;
use App\Services\Migration\ShopifyProductSyncService;
use App\Services\Shopware\ShopwareClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunProductMigrationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1200;

    private int $runId;

    private int $page;

    /** @var array<int, mixed> */
    private array $filter;

    /**
     * Create a new job instance.
     */
    public function __construct(int $runId, int $page = 1, array $filter = [])
    {
        $this->runId = $runId;
        $this->page = max(1, $page);
        $this->filter = $filter;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $run = MigrationRun::query()->with('shop.shopwareConnection')->find($this->runId);
        if (! $run) {
            return;
        }

        if (in_array($run->status, ['cancelled', 'finished', 'failed'], true)) {
            return;
        }

        try {
            $shop = $run->shop;
            $conn = $shop ? $shop->shopwareConnection : null;

            if (! $shop || ! $conn) {
                $run->status = 'failed';
                $run->finished_at = now();
                $run->save();

                return;
            }

            if (! $run->shopify_location_gid) {
                $run->status = 'failed';
                $run->finished_at = now();
                $run->save();

                return;
            }

            $run->status = 'running';
            $run->save();

            if ($this->page === 1) {
                $shopifySync = app(ShopifyProductSyncService::class);
                $warmup = $shopifySync->warmupProductDefinitions($shop);
                if (!empty($warmup['errors']) || !empty($warmup['userErrors'])) {
                    Log::warning('Product definition warmup failed; item workers will retry per-product setup', [
                        'run_id' => $run->id,
                        'shop' => $shop->shop_domain,
                        'result' => $warmup,
                    ]);
                } else {
                    Log::info('Product definition warmup completed', [
                        'run_id' => $run->id,
                        'shop' => $shop->shop_domain,
                    ]);
                }
            }

            $shopware = app(ShopwareClient::class);

            // Increase page size to dispatch more product work items per run page.
            $perPage = 200;

            $run->refresh();
            if ($run->status === 'cancelled') {
                return;
            }

            // --- Sales Channel scoping (multi-store support) ---
            // If the connection is scoped to a specific Sales Channel, restrict the product search
            // to only products that have a visibility entry for that channel.
            $scopedFilter = $this->filter;
            $salesChannelId = trim((string) ($conn->sales_channel_id ?? ''));
            if ($salesChannelId !== '') {
                $scopedFilter[] = [
                    'type'  => 'equals',
                    'field' => 'visibilities.salesChannelId',
                    'value' => $salesChannelId,
                ];
            }

            $res = $shopware->searchProducts($conn, $perPage, $this->page, $scopedFilter);
            $products = $res['products'] ?? [];
            $total = (int) ($res['total'] ?? 0);

            if (! is_array($products) || count($products) === 0) {
                // No more pages. Finalize once all queued/running items finish.
                FinalizeProductMigrationRunJob::dispatch($run->id)->delay(now()->addSeconds(2));

                return;
            }

            $parents = array_values(array_filter($products, function ($p) {
                return empty($p['parentId']);
            }));

            foreach ($parents as $p) {
                $sourceId = (string) ($p['id'] ?? '');
                $sourceId = trim($sourceId);
                if ($sourceId === '') {
                    continue;
                }

                // SAFE CHANGE: fan-out per parent product to enable controlled parallel processing.
                ProcessProductMigrationItemJob::dispatch($run->id, $sourceId);
            }

            $run->refresh();
            if ($run->status === 'cancelled') {
                return;
            }

            $hasMore = ($this->page * $perPage) < $total;
            if ($hasMore) {
                self::dispatch($run->id, $this->page + 1, $this->filter);

                return;
            }

            // Last page dispatched. Finalize once all items finish.
            FinalizeProductMigrationRunJob::dispatch($run->id)->delay(now()->addSeconds(2));
        } catch (\Throwable $e) {
            Log::error('Product migration run failed', [
                'run_id' => $run->id,
                'shop' => optional($run->shop)->shop_domain,
                'error' => $e->getMessage(),
            ]);

            $run->status = 'failed';
            $run->finished_at = now();
            $run->save();
        }
    }
}
