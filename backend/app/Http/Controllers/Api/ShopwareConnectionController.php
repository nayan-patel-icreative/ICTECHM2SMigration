<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShopwareConnectionRequest;
use App\Models\Shop;
use App\Models\ShopwareConnection;
use App\Services\Shopware\ShopwareClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ShopwareConnectionController extends Controller
{
    public function show(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $conn = $shop->shopwareConnection;
        if (!$conn) {
            return response()->json(['connected' => false]);
        }

        return response()->json([
            'connected'              => true,
            'api_url'                => $conn->api_url,
            'client_id'              => $conn->client_id,
            'client_secret_saved'    => $conn->client_secret ? true : false,
            'language_config'        => $conn->language_config ?? [],
            'sales_channel_id'       => $conn->sales_channel_id,
            'sales_channel_name'     => $conn->sales_channel_name,
            'navigation_category_id' => $conn->navigation_category_id,
        ]);
    }

    public function store(StoreShopwareConnectionRequest $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $data   = $request->validated();
        $apiUrl = rtrim((string) $data['api_url'], '/');

        $conn           = ShopwareConnection::query()->firstOrNew(['shop_id' => $shop->id]);
        $conn->api_url  = $apiUrl;
        $conn->client_id = (string) $data['client_id'];

        $secret = $data['client_secret'] ?? null;

        if (!$conn->exists && (!is_string($secret) || trim($secret) === '')) {
            return response()->json([
                'message' => 'client_secret is required when creating a new Shopware connection',
                'errors'  => [
                    'client_secret' => ['The client_secret field is required.'],
                ],
            ], 422);
        }

        if (is_string($secret) && $secret !== '') {
            $conn->client_secret    = $secret;
            $conn->access_token     = null;
            $conn->token_expires_at = null;
        }

        // Persist language configuration if provided
        if (array_key_exists('language_config', $data)) {
            $langConfig         = $data['language_config'];
            $conn->language_config = is_array($langConfig) ? $langConfig : null;

            // Invalidate cached language list so UI re-fetches fresh data on next request
            Cache::forget('shopware_languages:'.$conn->id);
        }

        // Persist Sales Channel scoping if provided
        if (array_key_exists('sales_channel_id', $data)) {
            $scId             = $data['sales_channel_id'];
            $conn->sales_channel_id = is_string($scId) && $scId !== '' ? $scId : null;

            // Invalidate sales channel cache when scoping changes
            Cache::forget('shopware_sales_channels:'.$conn->id);
        }
        if (array_key_exists('sales_channel_name', $data)) {
            $scName                = $data['sales_channel_name'];
            $conn->sales_channel_name = is_string($scName) && $scName !== '' ? $scName : null;
        }
        if (array_key_exists('navigation_category_id', $data)) {
            $navId                      = $data['navigation_category_id'];
            $conn->navigation_category_id = is_string($navId) && $navId !== '' ? $navId : null;
        }

        $conn->save();

        return response()->json([
            'connected'              => true,
            'api_url'                => $apiUrl,
            'client_id'              => $conn->client_id,
            'client_secret_saved'    => $conn->client_secret ? true : false,
            'language_config'        => $conn->language_config ?? [],
            'sales_channel_id'       => $conn->sales_channel_id,
            'sales_channel_name'     => $conn->sales_channel_name,
            'navigation_category_id' => $conn->navigation_category_id,
        ], 200);
    }

    /**
     * Fetch available languages from the connected Shopware store.
     * Used by the frontend language-selection UI.
     */
    public function languages(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $conn = $shop->shopwareConnection;
        if (!$conn || !$conn->api_url || !$conn->client_secret) {
            return response()->json([
                'error'     => 'Shopware connection not configured',
                'languages' => [],
            ], 422);
        }

        try {
            $shopware  = app(ShopwareClient::class);
            $languages = $shopware->fetchLanguages($conn);

            return response()->json(['languages' => $languages]);
        } catch (\Throwable $e) {
            return response()->json([
                'error'     => $e->getMessage(),
                'languages' => [],
            ], 500);
        }
    }

    /**
     * Fetch available Sales Channels from the connected Shopware store.
     * Cache is busted on every explicit request so the UI always shows live data.
     */
    public function salesChannels(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $conn = $shop->shopwareConnection;
        if (!$conn || !$conn->api_url || !$conn->client_secret) {
            return response()->json([
                'error'          => 'Shopware connection not configured',
                'sales_channels' => [],
            ], 422);
        }

        try {
            // Always bust cache so the dropdown is 100% fresh on every UI fetch
            Cache::forget('shopware_sales_channels:'.$conn->id);

            $shopware      = app(ShopwareClient::class);
            $salesChannels = $shopware->getSalesChannels($conn);

            return response()->json(['sales_channels' => $salesChannels]);
        } catch (\Throwable $e) {
            return response()->json([
                'error'          => $e->getMessage(),
                'sales_channels' => [],
            ], 500);
        }
    }
}
