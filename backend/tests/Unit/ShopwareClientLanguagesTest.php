<?php

namespace Tests\Unit;

use App\Models\ShopwareConnection;
use App\Services\Shopware\ShopwareClient;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ShopwareClientLanguagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
    }

    public function test_enabledLanguages_returns_only_active_languages(): void
    {
        $conn = new ShopwareConnection();
        $conn->language_config = [
            [
                'id' => 'de-1',
                'locale' => 'de-DE',
                'name' => 'German',
                'enabled' => true,
            ],
            [
                'id' => 'fr-1',
                'locale' => 'fr-FR',
                'name' => 'French',
                'enabled' => false,
            ],
            [
                'id' => 'en-1',
                'locale' => 'en-US',
                'name' => 'English',
                // enabled missing, should default to false
            ]
        ];

        $enabled = ShopwareClient::enabledLanguages($conn);

        $this->assertCount(1, $enabled);
        $this->assertSame('de-1', $enabled[0]['id']);
        $this->assertSame('de-DE', $enabled[0]['locale']);
        $this->assertSame('German', $enabled[0]['name']);
    }

    public function test_enabledLanguages_returns_empty_when_no_config(): void
    {
        $conn = new ShopwareConnection();
        $conn->language_config = null;

        $enabled = ShopwareClient::enabledLanguages($conn);
        $this->assertEmpty($enabled);
    }

    public function test_fetchLanguages_returns_list_of_languages(): void
    {
        $mockHttp = $this->createMock(Client::class);

        $conn = new ShopwareConnection();
        $conn->api_url = 'https://shopware.test';
        $conn->client_id = 'client123';
        $conn->client_secret = 'secret123';
        // Mock token expiration to avoid oauth call
        $conn->access_token = 'token123';
        $conn->token_expires_at = now()->addHour();

        $languagesResponse = [
            'data' => [
                [
                    'id' => 'lang-de',
                    'name' => 'Deutsch',
                    'locale' => [
                        'code' => 'de-DE'
                    ]
                ],
                [
                    'id' => 'lang-en',
                    'name' => 'English',
                    'localeCode' => 'en-US'
                ],
            ]
        ];

        $mockHttp->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://shopware.test/api/search/language',
                $this->callback(function ($options) {
                    return $options['headers']['Authorization'] === 'Bearer token123';
                })
            )
            ->willReturn(new Response(200, [], json_encode($languagesResponse)));

        $client = new ShopwareClient($mockHttp);
        $languages = $client->fetchLanguages($conn);

        $this->assertCount(2, $languages);
        $this->assertSame('lang-de', $languages[0]['id']);
        $this->assertSame('Deutsch', $languages[0]['name']);
        $this->assertSame('de-DE', $languages[0]['locale']);

        $this->assertSame('lang-en', $languages[1]['id']);
        $this->assertSame('English', $languages[1]['name']);
        $this->assertSame('en-US', $languages[1]['locale']);
    }
}
