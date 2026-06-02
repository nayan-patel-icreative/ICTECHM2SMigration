<?php

namespace App\Jobs;

use App\Models\MigrationRun;
use App\Services\Shopware\ShopwareClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunMarketMigrationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1200;

    private int $runId;

    public function __construct(int $runId)
    {
        $this->runId = $runId;
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

        try {
            $shop = $run->shop;
            $conn = $shop ? $shop->shopwareConnection : null;

            if (! $shop || ! $conn) {
                $run->status = 'failed';
                $run->finished_at = now();
                $run->save();

                return;
            }

            $run->status = 'running';
            $run->save();

            $shopware = app(ShopwareClient::class);

            $run->refresh();
            if ($run->status === 'cancelled') {
                return;
            }

            $channels = $shopware->getSalesChannelsWithDetails($conn);

            if (empty($channels)) {
                FinalizeMarketMigrationRunJob::dispatch($run->id)->delay(now()->addSeconds(2));

                return;
            }

            foreach ($channels as $c) {
                $sourceId = trim((string) ($c['id'] ?? ''));
                if ($sourceId === '') {
                    continue;
                }
                ProcessMarketMigrationItemJob::dispatch($run->id, $sourceId);
            }

            FinalizeMarketMigrationRunJob::dispatch($run->id)->delay(now()->addSeconds(2));

        } catch (\Throwable $e) {
            Log::error('Market migration run failed', [
                'run_id' => $this->runId,
                'error' => $e->getMessage(),
            ]);
            $run->status = 'failed';
            $run->finished_at = now();
            $run->save();
        }
    }
}
