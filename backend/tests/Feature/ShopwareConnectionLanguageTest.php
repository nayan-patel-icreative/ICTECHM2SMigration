<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopwareConnection;
use App\Services\Shopware\ShopwareClient;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ShopwareConnectionLanguageTest extends TestCase
{
    use DatabaseTransactions;
    private function getAuthHeader(Shop $shop): array
    {
        config()->set('shopify.api_key', 'test-key');
        config()->set('shopify.api_secret', 'test-secret');

        $token = JWT::encode([
            'aud' => 'test-key',
            'dest' => 'https://' . $shop->shop_domain,
            'exp' => now()->addMinutes(5)->timestamp,
        ], 'test-secret', 'HS256');

        return ['Authorization' => 'Bearer ' . $token];
    }

    public function test_can_retrieve_and_save_language_config(): void
    {
        $domain = 'test-shop-' . uniqid() . '.myshopify.com';
        Shop::query()->where('shop_domain', $domain)->delete();

        $shop = Shop::query()->create([
            'shop_domain' => $domain,
            'access_token' => 'token-test',
        ]);

        $headers = $this->getAuthHeader($shop);

        // 1. Get connection when none exists
        $response = $this->withHeaders($headers)->get('/api/shopware-connection');
        $response->assertOk();
        $response->assertJson(['connected' => false]);

        // 2. Save connection details with language config
        $langConfig = [
            ['id' => 'de-1', 'name' => 'German', 'locale' => 'de-DE', 'enabled' => true],
            ['id' => 'fr-1', 'name' => 'French', 'locale' => 'fr-FR', 'enabled' => false]
        ];

        $payload = [
            'api_url' => 'https://shopware-test.com',
            'client_id' => 'client-id-xyz',
            'client_secret' => 'secret-key-123',
            'language_config' => $langConfig
        ];

        $response = $this->withHeaders($headers)->postJson('/api/shopware-connection', $payload);
        $response->assertOk();
        $response->assertJson([
            'connected' => true,
            'api_url' => 'https://shopware-test.com',
            'client_id' => 'client-id-xyz',
            'language_config' => $langConfig
        ]);

        // Verify DB update
        $conn = ShopwareConnection::query()->where('shop_id', $shop->id)->first();
        $this->assertNotNull($conn);
        $this->assertSame($langConfig, $conn->language_config);

        // 3. Fetch connection again, verify language config is returned
        $response = $this->withHeaders($headers)->get('/api/shopware-connection');
        $response->assertOk();
        $response->assertJson([
            'connected' => true,
            'language_config' => $langConfig
        ]);
    }

    public function test_can_fetch_languages_from_shopware_connection(): void
    {
        $domain = 'test-shop-2-' . uniqid() . '.myshopify.com';
        Shop::query()->where('shop_domain', $domain)->delete();

        $shop = Shop::query()->create([
            'shop_domain' => $domain,
            'access_token' => 'token-test-2',
        ]);

        $conn = ShopwareConnection::query()->create([
            'shop_id' => $shop->id,
            'api_url' => 'https://shopware-test.com',
            'client_id' => 'client-id-xyz',
            'client_secret' => 'secret-key-123',
        ]);

        $headers = $this->getAuthHeader($shop);

        $mockLanguages = [
            ['id' => 'lang-de', 'name' => 'Deutsch', 'locale' => 'de-DE'],
            ['id' => 'lang-en', 'name' => 'English', 'locale' => 'en-US'],
        ];

        // Mock ShopwareClient
        $mockClient = $this->createMock(ShopwareClient::class);
        $mockClient->expects($this->once())
            ->method('fetchLanguages')
            ->with($this->callback(function ($passedConn) use ($conn) {
                return $passedConn->id === $conn->id;
            }))
            ->willReturn($mockLanguages);

        $this->app->instance(ShopwareClient::class, $mockClient);

        $response = $this->withHeaders($headers)->get('/api/shopware-languages');
        $response->assertOk();
        $response->assertJson([
            'languages' => $mockLanguages
        ]);
    }
}
