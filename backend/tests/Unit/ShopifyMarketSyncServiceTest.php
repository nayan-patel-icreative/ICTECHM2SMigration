<?php

namespace Tests\Unit;

use App\Models\Shop;
use App\Services\Migration\ShopifyMarketSyncService;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use Tests\TestCase;

class ShopifyMarketSyncServiceTest extends TestCase
{
    public function test_get_markets_and_domains_success(): void
    {
        $mockClient = $this->createMock(ShopifyAdminGraphqlClient::class);
        $shop = new Shop();
        $shop->shop_domain = 'test.myshopify.com';

        $graphqlResponse = [
            'data' => [
                'markets' => [
                    'edges' => [
                        [
                            'node' => [
                                'id' => 'gid://shopify/Market/1',
                                'name' => 'Europe Market',
                                'handle' => 'europe-market',
                                'enabled' => true,
                                'regions' => [
                                    'edges' => [
                                        ['node' => ['code' => 'DE', 'name' => 'Germany']],
                                        ['node' => ['code' => 'FR', 'name' => 'France']],
                                    ]
                                ],
                                'webPresences' => [
                                    'edges' => [
                                        [
                                            'node' => [
                                                'id' => 'gid://shopify/WebPresence/1',
                                                'subfolderSuffix' => 'eur',
                                                'defaultLocale' => ['locale' => 'en'],
                                                'domain' => [
                                                    'id' => 'gid://shopify/Domain/1',
                                                    'host' => 'test.myshopify.com',
                                                    'url' => 'https://test.myshopify.com/eur',
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                'shop' => [
                    'domains' => [
                        [
                            'id' => 'gid://shopify/Domain/1',
                            'host' => 'test.myshopify.com',
                            'url' => 'https://test.myshopify.com',
                        ],
                        [
                            'id' => 'gid://shopify/Domain/2',
                            'host' => 'custom-domain.com',
                            'url' => 'https://custom-domain.com',
                        ]
                    ],
                    'primaryDomain' => [
                        'id' => 'gid://shopify/Domain/1',
                        'host' => 'test.myshopify.com',
                        'url' => 'https://test.myshopify.com',
                    ]
                ]
            ]
        ];

        $mockClient->expects($this->once())
            ->method('query')
            ->willReturn($graphqlResponse);

        $service = new ShopifyMarketSyncService($mockClient);
        $result = $service->getMarketsAndDomains($shop);

        $this->assertCount(1, $result['markets']);
        $this->assertSame('gid://shopify/Market/1', $result['markets'][0]['id']);
        $this->assertSame('Europe Market', $result['markets'][0]['name']);
        $this->assertTrue($result['markets'][0]['enabled']);
        $this->assertSame(['DE', 'FR'], $result['markets'][0]['regions']);
        $this->assertCount(1, $result['markets'][0]['webPresences']);
        $this->assertSame('eur', $result['markets'][0]['webPresences'][0]['subfolderSuffix']);

        $this->assertCount(2, $result['domains']);
        $this->assertSame('custom-domain.com', $result['domains'][1]['host']);
        $this->assertSame('gid://shopify/Domain/1', $result['primaryDomain']['id']);
    }

    public function test_sync_market_creates_new_market_and_web_presence_subfolder(): void
    {
        $mockClient = $this->createMock(ShopifyAdminGraphqlClient::class);
        $shop = new Shop();
        $shop->shop_domain = 'test.myshopify.com';

        // 1. getMarketsAndDomains will return empty markets and domains
        // 2. marketCreate will be called
        // 3. webPresenceCreate will be called
        // 4. marketUpdate (enable) will be called
        $mockClient->expects($this->exactly(4))
            ->method('query')
            ->willReturnCallback(function ($passedShop, $query, $variables = []) {
                if (str_contains($query, 'query GetMarketsAndDomains')) {
                    return [
                        'data' => [
                            'markets' => ['edges' => []],
                            'shop' => [
                                'domains' => [
                                    [
                                        'id' => 'gid://shopify/Domain/1',
                                        'host' => 'test.myshopify.com',
                                        'url' => 'https://test.myshopify.com',
                                    ]
                                ],
                                'primaryDomain' => [
                                    'id' => 'gid://shopify/Domain/1',
                                    'host' => 'test.myshopify.com',
                                ]
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketCreate')) {
                    $this->assertSame('German Storefront', $variables['input']['name']);
                    $this->assertSame('DE', $variables['input']['conditions']['regionsCondition']['regions'][0]['countryCode']);
                    return [
                        'data' => [
                            'marketCreate' => [
                                'market' => ['id' => 'gid://shopify/Market/new123', 'name' => 'German Storefront'],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketWebPresenceCreate')) {
                    $this->assertSame('gid://shopify/Market/new123', $variables['marketId']);
                    $this->assertSame('de-de', $variables['webPresence']['defaultLocale']);
                    // default_locale de_DE normalized to de-de
                    // subfolder suffix should fallback to slugified name: german-storefront
                    $this->assertSame('german-storefront', $variables['webPresence']['subfolderSuffix']);
                    $this->assertArrayNotHasKey('domainId', $variables['webPresence']);

                    return [
                        'data' => [
                            'marketWebPresenceCreate' => [
                                'market' => ['id' => 'gid://shopify/Market/new123'],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketUpdate') && isset($variables['input']['status']) && $variables['input']['status'] === 'ACTIVE') {
                    $this->assertSame('gid://shopify/Market/new123', $variables['id']);
                    return [
                        'data' => [
                            'marketUpdate' => [
                                'market' => ['id' => 'gid://shopify/Market/new123', 'enabled' => true],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                return [];
            });

        $service = new ShopifyMarketSyncService($mockClient);
        $salesChannel = [
            'name' => 'German Storefront',
            'default_country_iso' => 'DE',
            'default_locale' => 'de_DE',
            'domains' => []
        ];

        $res = $service->syncMarket($shop, $salesChannel);
        $this->assertTrue($res['ok']);
        $this->assertSame('gid://shopify/Market/new123', $res['market_id']);
        $this->assertArrayNotHasKey('warning', $res);
    }

    public function test_sync_market_country_collision_resolution(): void
    {
        $mockClient = $this->createMock(ShopifyAdminGraphqlClient::class);
        $shop = new Shop();
        $shop->shop_domain = 'test.myshopify.com';

        // 1. getMarketsAndDomains returns market "Europe Market" owning "DE" region
        // 2. MarketUpdate to remove "DE" from "Europe Market" is called
        // 3. marketCreate is called
        // 4. webPresenceCreate is called
        // 5. marketUpdate (enable) is called
        $mockClient->expects($this->exactly(5))
            ->method('query')
            ->willReturnCallback(function ($passedShop, $query, $variables = []) {
                if (str_contains($query, 'query GetMarketsAndDomains')) {
                    return [
                        'data' => [
                            'markets' => [
                                'edges' => [
                                    [
                                        'node' => [
                                            'id' => 'gid://shopify/Market/existing_eur',
                                            'name' => 'Europe Market',
                                            'handle' => 'europe-market',
                                            'enabled' => true,
                                            'regions' => [
                                                'edges' => [
                                                    ['node' => ['code' => 'DE', 'name' => 'Germany']],
                                                    ['node' => ['code' => 'FR', 'name' => 'France']],
                                                ]
                                            ],
                                            'webPresences' => ['edges' => []]
                                        ]
                                    ]
                                ]
                            ],
                            'shop' => [
                                'domains' => [],
                                'primaryDomain' => ['id' => 'gid://shopify/Domain/1', 'host' => 'test.myshopify.com']
                            ]
                        ]
                    ];
                }

                // First MarketUpdate: remove DE from existing_eur, leaving only FR
                if (str_contains($query, 'mutation MarketUpdate') && isset($variables['input']['conditions']['regionsCondition']['regions'])) {
                    $this->assertSame('gid://shopify/Market/existing_eur', $variables['id']);
                    $regions = $variables['input']['conditions']['regionsCondition']['regions'];
                    $this->assertCount(1, $regions);
                    $this->assertSame('FR', $regions[0]['countryCode']);
                    return [
                        'data' => [
                            'marketUpdate' => [
                                'market' => ['id' => 'gid://shopify/Market/existing_eur'],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketCreate')) {
                    $this->assertSame('DE Storefront', $variables['input']['name']);
                    return [
                        'data' => [
                            'marketCreate' => [
                                'market' => ['id' => 'gid://shopify/Market/new_de', 'name' => 'DE Storefront'],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation WebPresenceCreate')) {
                    return [
                        'data' => [
                            'webPresenceCreate' => [
                                'webPresence' => ['id' => 'gid://shopify/WebPresence/wp_de'],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketUpdate') && isset($variables['input']['status']) && $variables['input']['status'] === 'ACTIVE') {
                    $this->assertSame('gid://shopify/Market/new_de', $variables['id']);
                    return [
                        'data' => [
                            'marketUpdate' => [
                                'market' => ['id' => 'gid://shopify/Market/new_de', 'enabled' => true],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                return [];
            });

        $service = new ShopifyMarketSyncService($mockClient);
        $salesChannel = [
            'name' => 'DE Storefront',
            'default_country_iso' => 'DE',
            'default_locale' => 'de_DE',
            'domains' => []
        ];

        $res = $service->syncMarket($shop, $salesChannel);
        $this->assertTrue($res['ok']);
        $this->assertSame('gid://shopify/Market/new_de', $res['market_id']);
    }

    public function test_sync_market_existing_by_name_does_not_recreate(): void
    {
        $mockClient = $this->createMock(ShopifyAdminGraphqlClient::class);
        $shop = new Shop();
        $shop->shop_domain = 'test.myshopify.com';

        // 1. getMarketsAndDomains returns market "DE Storefront"
        // 2. marketCreate is NOT called
        // 3. webPresenceCreate is called
        // 4. marketUpdate (enable) is called
        $mockClient->expects($this->exactly(3))
            ->method('query')
            ->willReturnCallback(function ($passedShop, $query, $variables = []) {
                if (str_contains($query, 'query GetMarketsAndDomains')) {
                    return [
                        'data' => [
                            'markets' => [
                                'edges' => [
                                    [
                                        'node' => [
                                            'id' => 'gid://shopify/Market/existing_de',
                                            'name' => 'DE Storefront',
                                            'handle' => 'de-storefront',
                                            'enabled' => false,
                                            'regions' => [
                                                'edges' => [
                                                    ['node' => ['code' => 'DE', 'name' => 'Germany']]
                                                ]
                                            ],
                                            'webPresences' => ['edges' => []]
                                        ]
                                    ]
                                ]
                            ],
                            'shop' => [
                                'domains' => [],
                                'primaryDomain' => ['id' => 'gid://shopify/Domain/1', 'host' => 'test.myshopify.com']
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketWebPresenceCreate')) {
                    $this->assertSame('gid://shopify/Market/existing_de', $variables['marketId']);
                    return [
                        'data' => [
                            'marketWebPresenceCreate' => [
                                'market' => ['id' => 'gid://shopify/Market/existing_de'],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketUpdate') && isset($variables['input']['status']) && $variables['input']['status'] === 'ACTIVE') {
                    $this->assertSame('gid://shopify/Market/existing_de', $variables['id']);
                    return [
                        'data' => [
                            'marketUpdate' => [
                                'market' => ['id' => 'gid://shopify/Market/existing_de', 'enabled' => true],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                return [];
            });

        $service = new ShopifyMarketSyncService($mockClient);
        $salesChannel = [
            'name' => 'DE Storefront',
            'default_country_iso' => 'DE',
            'default_locale' => 'de_DE',
            'domains' => []
        ];

        $res = $service->syncMarket($shop, $salesChannel);
        $this->assertTrue($res['ok']);
        $this->assertSame('gid://shopify/Market/existing_de', $res['market_id']);
    }

    public function test_sync_market_domain_matching_custom_domain(): void
    {
        $mockClient = $this->createMock(ShopifyAdminGraphqlClient::class);
        $shop = new Shop();
        $shop->shop_domain = 'test.myshopify.com';

        // Shopware domain uses custom-domain.com
        // Shopify pre-registered domains includes custom-domain.com with ID gid://shopify/Domain/2
        $mockClient->expects($this->exactly(4))
            ->method('query')
            ->willReturnCallback(function ($passedShop, $query, $variables = []) {
                if (str_contains($query, 'query GetMarketsAndDomains')) {
                    return [
                        'data' => [
                            'markets' => ['edges' => []],
                            'shop' => [
                                'domains' => [
                                    [
                                        'id' => 'gid://shopify/Domain/1',
                                        'host' => 'test.myshopify.com',
                                        'url' => 'https://test.myshopify.com',
                                    ],
                                    [
                                        'id' => 'gid://shopify/Domain/2',
                                        'host' => 'custom-domain.com',
                                        'url' => 'https://custom-domain.com',
                                    ]
                                ],
                                'primaryDomain' => [
                                    'id' => 'gid://shopify/Domain/1',
                                    'host' => 'test.myshopify.com',
                                ]
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketCreate')) {
                    return [
                        'data' => [
                            'marketCreate' => [
                                'market' => ['id' => 'gid://shopify/Market/new_custom', 'name' => 'Custom Storefront'],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketWebPresenceCreate')) {
                    $this->assertSame('gid://shopify/Market/new_custom', $variables['marketId']);
                    $this->assertSame('gid://shopify/Domain/2', $variables['webPresence']['domainId']);
                    $this->assertArrayNotHasKey('subfolderSuffix', $variables['webPresence']);

                    return [
                        'data' => [
                            'marketWebPresenceCreate' => [
                                'market' => ['id' => 'gid://shopify/Market/new_custom'],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketUpdate') && isset($variables['input']['status']) && $variables['input']['status'] === 'ACTIVE') {
                    $this->assertSame('gid://shopify/Market/new_custom', $variables['id']);
                    return [
                        'data' => [
                            'marketUpdate' => [
                                'market' => ['id' => 'gid://shopify/Market/new_custom', 'enabled' => true],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                return [];
            });

        $service = new ShopifyMarketSyncService($mockClient);
        $salesChannel = [
            'name' => 'Custom Storefront',
            'default_country_iso' => 'US',
            'default_locale' => 'en_US',
            'domains' => [
                [
                    'url' => 'https://custom-domain.com/public/demo',
                    'locale' => 'en_US',
                    'currency' => 'USD'
                ]
            ]
        ];

        $res = $service->syncMarket($shop, $salesChannel);
        $this->assertTrue($res['ok']);
        $this->assertSame('gid://shopify/Market/new_custom', $res['market_id']);
    }

    public function test_sync_market_domain_matching_subfolder_suffix_extraction(): void
    {
        $mockClient = $this->createMock(ShopifyAdminGraphqlClient::class);
        $shop = new Shop();
        $shop->shop_domain = 'test.myshopify.com';

        // Shopware domain does not match any Shopify pre-registered domains (except test.myshopify.com if we check host)
        // But path is http://localhost/SW6771/public/de-de -> last segment after public/ is de-de -> suffix de-de
        $mockClient->expects($this->exactly(4))
            ->method('query')
            ->willReturnCallback(function ($passedShop, $query, $variables = []) {
                if (str_contains($query, 'query GetMarketsAndDomains')) {
                    return [
                        'data' => [
                            'markets' => ['edges' => []],
                            'shop' => [
                                'domains' => [
                                    ['id' => 'gid://shopify/Domain/1', 'host' => 'test.myshopify.com', 'url' => 'https://test.myshopify.com']
                                ],
                                'primaryDomain' => ['id' => 'gid://shopify/Domain/1', 'host' => 'test.myshopify.com']
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketCreate')) {
                    return [
                        'data' => [
                            'marketCreate' => [
                                'market' => ['id' => 'gid://shopify/Market/new_suffix', 'name' => 'Suffix Storefront'],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketWebPresenceCreate')) {
                    $this->assertSame('gid://shopify/Market/new_suffix', $variables['marketId']);
                    // Extracted from path: de-de
                    $this->assertSame('de-de', $variables['webPresence']['subfolderSuffix']);
                    $this->assertArrayNotHasKey('domainId', $variables['webPresence']);

                    return [
                        'data' => [
                            'marketWebPresenceCreate' => [
                                'market' => ['id' => 'gid://shopify/Market/new_suffix'],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketUpdate') && isset($variables['input']['status']) && $variables['input']['status'] === 'ACTIVE') {
                    $this->assertSame('gid://shopify/Market/new_suffix', $variables['id']);
                    return [
                        'data' => [
                            'marketUpdate' => [
                                'market' => ['id' => 'gid://shopify/Market/new_suffix', 'enabled' => true],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                return [];
            });

        $service = new ShopifyMarketSyncService($mockClient);
        $salesChannel = [
            'name' => 'Suffix Storefront',
            'default_country_iso' => 'DE',
            'default_locale' => 'de_DE',
            'domains' => [
                [
                    'url' => 'http://localhost/SW6771/public/de-de',
                    'locale' => 'de_DE',
                    'currency' => 'EUR'
                ]
            ]
        ];

        $res = $service->syncMarket($shop, $salesChannel);
        $this->assertTrue($res['ok']);
    }

    public function test_sync_market_error_creating_market(): void
    {
        $mockClient = $this->createMock(ShopifyAdminGraphqlClient::class);
        $shop = new Shop();
        $shop->shop_domain = 'test.myshopify.com';

        $mockClient->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function ($passedShop, $query, $variables = []) {
                if (str_contains($query, 'query GetMarketsAndDomains')) {
                    return [
                        'data' => [
                            'markets' => ['edges' => []],
                            'shop' => [
                                'domains' => [],
                                'primaryDomain' => ['id' => 'gid://shopify/Domain/1', 'host' => 'test.myshopify.com']
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketCreate')) {
                    return [
                        'data' => [
                            'marketCreate' => [
                                'market' => null,
                                'userErrors' => [
                                    [
                                        'field' => ['conditions'],
                                        'message' => 'Region DE already assigned to another market',
                                        'code' => 'TAKEN'
                                    ]
                                ]
                            ]
                        ]
                    ];
                }

                return [];
            });

        $service = new ShopifyMarketSyncService($mockClient);
        $salesChannel = [
            'name' => 'DE Storefront',
            'default_country_iso' => 'DE',
            'default_locale' => 'de_DE',
            'domains' => []
        ];

        $res = $service->syncMarket($shop, $salesChannel);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('Shopify error creating market', $res['error']);
        $this->assertCount(1, $res['userErrors']);
    }
}
