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

class RunDiscountMigrationJob implements ShouldQueue
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
     * @param array<int, mixed> $filter
     */
    public function __construct(int $runId, int $page = 1, array $filter = [])
    {
        $this->runId  = $runId;
        $this->page   = max(1, $page);
        $this->filter = $filter;
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
                $run->status      = 'failed';
                $run->finished_at = now();
                $run->save();

                return;
            }

            if ($run->status === 'queued') {
                $run->status = 'running';
                $run->save();
            }

            $run->refresh();
            if ($run->status === 'cancelled') {
                return;
            }

            $shopware = app(ShopwareClient::class);
            $perPage  = 100;

            // --- Sales Channel scoping (multi-store support) ---
            // Promotions use a many-to-many relationship via salesChannels.
            // We filter using 'equalsAny' on salesChannels.id.
            $scopedFilter   = $this->filter;
            $salesChannelId = trim((string) ($conn->sales_channel_id ?? ''));
            if ($salesChannelId !== '') {
                $scopedFilter[] = [
                    'type'  => 'equalsAny',
                    'field' => 'salesChannels.id',
                    'value' => [$salesChannelId],
                ];
            }

            $res        = $shopware->fetchPromotions($conn, $perPage, $this->page, $scopedFilter);
            $promotions = $res['promotions'] ?? [];
            $total      = (int) ($res['total'] ?? 0);

            if (! is_array($promotions) || count($promotions) === 0) {
                FinalizeDiscountMigrationRunJob::dispatch($run->id)->delay(now()->addSeconds(2));

                return;
            }

            foreach ($promotions as $promotion) {
                if (! is_array($promotion)) {
                    continue;
                }
                $sourceId = trim((string) data_get($promotion, 'id', ''));
                if ($sourceId === '') {
                    continue;
                }
                ProcessDiscountMigrationItemJob::dispatch($run->id, $sourceId);
            }

            $hasMore = ($this->page * $perPage) < $total;
            if ($hasMore) {
                self::dispatch($run->id, $this->page + 1, $this->filter);
            } else {
                FinalizeDiscountMigrationRunJob::dispatch($run->id)->delay(now()->addSeconds(2));
            }
        } catch (\Throwable $e) {
            Log::error('Discount migration run page failed', [
                'run_id' => $this->runId,
                'page'   => $this->page,
                'error'  => $e->getMessage(),
            ]);
            $run->status      = 'failed';
            $run->finished_at = now();
            $run->save();
        }
    }
}
