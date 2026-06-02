<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\Migration\DiscountFingerprint;
use App\Services\Migration\DiscountMapper;
use App\Services\Migration\DiscountMigrationService;
use App\Services\QueueHealthService;
use App\Services\Shopware\ShopwareClient;
use Illuminate\Http\Request;

class DiscountMigrationController extends Controller
{
    private DiscountMigrationService $service;

    public function __construct(DiscountMigrationService $service)
    {
        $this->service = $service;
    }

    public function preview(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        $conn = $shop ? $shop->shopwareConnection : null;

        if (! $shop || ! $conn) {
            return response()->json(['error' => 'Missing Shopware connection'], 422);
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'page'  => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $limit = (int) ($validated['limit'] ?? 10);
        $page  = (int) ($validated['page'] ?? 1);

        $shopware     = app(ShopwareClient::class);
        $mapper       = app(DiscountMapper::class);
        $fingerprints = app(DiscountFingerprint::class);

        $res        = $shopware->fetchPromotions($conn, 50, $page);
        $promotions = $res['promotions'] ?? [];
        $total      = (int) ($res['total'] ?? 0);

        $items = [];
        foreach (array_slice(is_array($promotions) ? $promotions : [], 0, $limit) as $promotion) {
            if (! is_array($promotion)) {
                continue;
            }

            $sourceId = trim((string) data_get($promotion, 'id', ''));
            if ($sourceId === '') {
                continue;
            }

            $mapped = $mapper->map($promotion);

            $discountType = null;
            $isAutomatic  = false;
            if (! $mapped['skipped'] && $mapped['mutation'] !== null) {
                $mut          = $mapped['mutation'];
                $discountType = str_contains($mut, 'FreeShipping') ? 'free_shipping' : 'basic';
                $isAutomatic  = str_starts_with($mut, 'discountAutomatic');
            }

            $primaryDiscount = data_get($promotion, 'discounts.0');
            $value           = is_array($primaryDiscount) ? (float) ($primaryDiscount['value'] ?? 0) : 0;
            $valueType       = is_array($primaryDiscount) ? (string) ($primaryDiscount['type'] ?? '') : '';

            $codes     = data_get($promotion, 'individualCodes') ?? data_get($promotion, 'codes');
            $codeCount = is_array($codes) ? count($codes) : 0;

            $items[] = [
                'source_id'            => $sourceId,
                'name'                 => trim((string) (data_get($promotion, 'name') ?: '')),
                'shopify_discount_type' => $discountType,
                'is_automatic'         => $isAutomatic,
                'code_count'           => $codeCount,
                'value'                => $value,
                'value_type'           => $valueType,
                'valid_from'           => data_get($promotion, 'validFrom'),
                'valid_until'          => data_get($promotion, 'validUntil'),
                'is_active'            => (bool) (data_get($promotion, 'active') ?? true),
                'issues'               => $mapped['issues'],
                'fingerprint'          => $fingerprints->make($promotion),
            ];
        }

        return response()->json([
            'page'  => $page,
            'total' => $total,
            'items' => $items,
        ]);
    }

    public function status(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        $run  = $this->service->status($shop);

        if (! $run) {
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
                'id'            => $it->id,
                'source_id'     => $it->source_id,
                'error_message' => $it->error_message,
                'error_context' => $it->error_context,
                'finished_at'   => $it->finished_at,
            ];
        }

        $durationSeconds = null;
        if ($run->started_at) {
            $end             = $run->finished_at ?: now();
            $durationSeconds = max(0, $run->started_at->diffInSeconds($end));
        }

        return response()->json([
            'run' => [
                'id'                  => $run->id,
                'type'                => $run->type,
                'status'              => $run->status,
                'processed'           => $run->processed,
                'succeeded'           => $run->succeeded,
                'failed'              => $run->failed,
                'started_at'          => $run->started_at,
                'finished_at'         => $run->finished_at,
                'duration_seconds'    => $durationSeconds,
                'report_available'    => is_string($run->report_path) && trim((string) $run->report_path) !== '' && is_file((string) $run->report_path),
                'report_download_url' => '/api/migration/runs/'.$run->id.'/report',
                'pdf_available'       => in_array((string) $run->status, ['finished', 'cancelled'], true) && is_string($run->report_path) && trim((string) $run->report_path) !== '' && is_file((string) $run->report_path),
                'pdf_download_url'    => '/api/migration/runs/'.$run->id.'/report-pdf',
            ],
            'recent_failed_items' => $recentFailedOut,
        ]);
    }

    public function start(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $queueHealth = app(QueueHealthService::class);
        if (! $queueHealth->probe()) {
            return response()->json([
                'error' => 'Queue worker is not running. Migration cannot start until the worker process is online.',
            ], 409);
        }

        $run = $this->service->start($shop);

        return response()->json([
            'run_id' => $run->id,
            'status' => $run->status,
        ], 202);
    }

    public function cancel(Request $request)
    {
        /** @var Shop $shop */
        $shop      = $request->attributes->get('shop');
        $cancelled = $this->service->cancel($shop);

        return response()->json(['cancelled' => $cancelled]);
    }
}
