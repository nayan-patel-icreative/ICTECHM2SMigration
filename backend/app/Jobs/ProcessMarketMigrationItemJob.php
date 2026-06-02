<?php

namespace App\Jobs;

use App\Models\MigrationItem;
use App\Models\MigrationRun;
use App\Models\ShopifyIdMapping;
use App\Services\Migration\MigrationRunReportWriter;
use App\Services\Migration\ShopifyMarketSyncService;
use App\Services\Shopware\ShopwareClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessMarketMigrationItemJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    private int $runId;

    private string $sourceId;

    public function __construct(int $runId, string $sourceId)
    {
        $this->runId = $runId;
        $this->sourceId = trim($sourceId);
    }

    public function handle(): void
    {
        $run = MigrationRun::query()->with('shop.shopwareConnection')->find($this->runId);
        if (! $run || in_array($run->status, ['cancelled', 'finished', 'failed'], true)) {
            return;
        }

        $shop = $run->shop;
        $conn = $shop ? $shop->shopwareConnection : null;
        if (! $shop || ! $conn || $this->sourceId === '') {
            return;
        }

        $item = MigrationItem::query()->firstOrCreate([
            'migration_run_id' => $run->id,
            'entity_type' => 'market',
            'source_id' => $this->sourceId,
        ], [
            'status' => 'queued',
        ]);

        if (in_array($item->status, ['skipped', 'succeeded'], true)) {
            return;
        }

        $item->status = 'running';
        $item->started_at = now();
        $item->error_message = null;
        $item->save();

        try {
            $shopware = app(ShopwareClient::class);
            $marketService = app(ShopifyMarketSyncService::class);

            // Fetch the details of the channels
            $channels = $shopware->getSalesChannelsWithDetails($conn);
            $channel = null;
            foreach ($channels as $c) {
                if ($c['id'] === $this->sourceId) {
                    $channel = $c;
                    break;
                }
            }

            if (! is_array($channel)) {
                $this->markFailed($run, $item, 'Shopware Sales Channel not found');
                return;
            }

            // Sync the market
            $res = $marketService->syncMarket($shop, $channel);

            if (!$res['ok']) {
                $this->markFailed($run, $item, $res['error']);
                return;
            }

            $shopifyGid = $res['market_id'];

            // Store ID mapping
            ShopifyIdMapping::query()->updateOrCreate([
                'shop_id'     => $shop->id,
                'entity_type' => 'market',
                'source_id'   => $this->sourceId,
            ], [
                'shopify_gid' => $shopifyGid,
            ]);

            // Save warnings if any
            $warning = $res['warning'] ?? '';

            $item->status = 'succeeded';
            $item->error_message = $warning !== '' ? $warning : null;
            $item->finished_at = now();
            $item->save();

            try {
                app(MigrationRunReportWriter::class)->appendRow($run, [
                    'shopware_sales_channel_id' => $item->source_id,
                    'sales_channel_name' => $channel['name'],
                    'status' => 'succeeded',
                    'reason' => $warning !== '' ? 'Web presence warning: ' . $warning : '',
                    'shopify_market_gid' => $shopifyGid,
                    'migrated_at_utc' => $item->finished_at ? $item->finished_at->toDateTimeString() : '',
                ]);
            } catch (\Throwable) {
                // ignore
            }

            $this->incrementRunCounters($run->id, [
                'processed' => 1,
                'succeeded' => 1,
            ]);

        } catch (\Throwable $e) {
            Log::error('Market migration item failed', [
                'run_id' => $run->id,
                'source_id' => $this->sourceId,
                'error' => $e->getMessage(),
            ]);
            $this->markFailed($run, $item, $e->getMessage());
        }
    }

    /**
     * @param  array{processed?: int, succeeded?: int, failed?: int}  $delta
     */
    private function incrementRunCounters(int $runId, array $delta): void
    {
        DB::transaction(function () use ($runId, $delta) {
            $run = MigrationRun::query()->lockForUpdate()->find($runId);
            if (! $run) {
                return;
            }
            $run->processed = (int) $run->processed + (int) ($delta['processed'] ?? 0);
            $run->succeeded = (int) $run->succeeded + (int) ($delta['succeeded'] ?? 0);
            $run->failed = (int) $run->failed + (int) ($delta['failed'] ?? 0);
            $run->save();
        });
    }

    private function markFailed(MigrationRun $run, MigrationItem $item, string $message): void
    {
        $item->status = 'failed';
        $item->error_message = $message;
        $item->finished_at = now();
        $item->save();
        try {
            $writer = app(MigrationRunReportWriter::class);
            $writer->appendRow($run, [
                'shopware_sales_channel_id' => $item->source_id,
                'sales_channel_name' => '',
                'status' => 'failed',
                'reason' => $writer->humanizeFailureReason($item),
                'shopify_market_gid' => '',
                'migrated_at_utc' => $item->finished_at ? $item->finished_at->toDateTimeString() : '',
            ]);
        } catch (\Throwable) {
            // ignore
        }

        $this->incrementRunCounters($run->id, [
            'processed' => 1,
            'failed' => 1,
        ]);
    }
}
