<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\Migration\MarketMigrationService;
use App\Services\QueueHealthService;
use App\Services\Shopware\ShopwareClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MarketMigrationController extends Controller
{
    private MarketMigrationService $service;

    public function __construct(MarketMigrationService $service)
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
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $limit = (int) ($validated['limit'] ?? 10);
        $page = (int) ($validated['page'] ?? 1);

        $shopware = app(ShopwareClient::class);

        $channels = $shopware->getSalesChannelsWithDetails($conn);
        $total = count($channels);

        $items = [];
        $offset = ($page - 1) * $limit;
        $sliced = array_slice($channels, $offset, $limit);

        foreach ($sliced as $c) {
            // Find proposed subfolder suffix
            $extractedSuffix = null;
            $swDomains = $c['domains'] ?? [];
            foreach ($swDomains as $swd) {
                $parsed = parse_url($swd['url']);
                $path = trim($parsed['path'] ?? '', '/');
                if (str_contains($path, 'public/')) {
                    $parts = explode('public/', $path);
                    $path = trim(end($parts), '/');
                }
                if ($path !== '') {
                    $segments = explode('/', $path);
                    $extractedSuffix = Str::slug(end($segments));
                }
            }

            if (!$extractedSuffix) {
                $extractedSuffix = Str::slug($c['name']);
            }

            $extractedSuffix = preg_replace('/[^a-z0-9-]/i', '', $extractedSuffix);
            if ($extractedSuffix === '') {
                $extractedSuffix = 'market-' . substr(md5($c['name']), 0, 5);
            }

            $items[] = [
                'source_id' => $c['id'],
                'name' => $c['name'],
                'default_country' => $c['default_country_iso'] ?? 'None',
                'default_locale' => $c['default_locale'] ?? 'en',
                'countries' => count($c['countries']),
                'domains' => count($c['domains']),
                'proposed_subfolder' => '/' . $extractedSuffix,
            ];
        }

        return response()->json([
            'page' => $page,
            'total' => $total,
            'items' => $items,
        ]);
    }

    public function status(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        $run = $this->service->status($shop);

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
        $shop = $request->attributes->get('shop');
        $cancelled = $this->service->cancel($shop);

        return response()->json(['cancelled' => $cancelled]);
    }
}
