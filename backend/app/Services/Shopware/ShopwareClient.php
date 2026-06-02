<?php

namespace App\Services\Shopware;

use App\Models\ShopwareConnection;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ShopwareClient
{
    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'timeout' => 60,
        ]);
    }

    public function getAccessToken(ShopwareConnection $conn): string
    {
        $safetySeconds = 15;

        if ($conn->access_token && $conn->token_expires_at && now()->lt($conn->token_expires_at->copy()->subSeconds($safetySeconds))) {
            return (string) $conn->access_token;
        }

        $url = rtrim($conn->api_url, '/').'/api/oauth/token';

        $res = $this->requestWithRetry('POST', $url, [
            'json' => [
                'grant_type' => 'client_credentials',
                'client_id' => $conn->client_id,
                'client_secret' => $conn->client_secret,
            ],
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        $payload = json_decode((string) $res->getBody(), true);

        $token = is_array($payload) ? ($payload['access_token'] ?? null) : null;
        $expiresIn = is_array($payload) ? ($payload['expires_in'] ?? null) : null;

        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Shopware OAuth token response missing access_token');
        }

        $ttlSeconds = is_numeric($expiresIn) ? (int) $expiresIn : 0;

        $conn->access_token = $token;
        $conn->token_expires_at = now()->addSeconds(max(0, $ttlSeconds));
        $conn->save();

        return $token;
    }

    public function resolveCurrencyIsoCode(ShopwareConnection $conn, string $currencyId): ?string
    {
        $currencyId = trim($currencyId);
        if ($currencyId === '') {
            return null;
        }

        $cacheKey = 'shopware_currency_iso:'.$conn->id.':'.$currencyId;
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        if ($cached === '__missing__') {
            return null;
        }

        $token = $this->getAccessToken($conn);

        $body = [
            'limit' => 1,
            'page' => 1,
            'filter' => [
                ['type' => 'equals', 'field' => 'id', 'value' => $currencyId],
            ],
        ];

        $url = rtrim($conn->api_url, '/').'/api/search/currency';

        try {
            $res = $this->requestWithRetry('POST', $url, [
                'json' => $body,
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            $status = null;
            $responseBody = null;
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $status = $e->getResponse()->getStatusCode();
                $responseBody = (string) $e->getResponse()->getBody();
            }

            if ($status === 401) {
                $conn->access_token = null;
                $conn->token_expires_at = null;
                $conn->save();

                $token = $this->getAccessToken($conn);
                try {
                    $res = $this->requestWithRetry('POST', $url, [
                        'json' => $body,
                        'headers' => [
                            'Authorization' => 'Bearer '.$token,
                            'Accept' => 'application/json',
                            'Content-Type' => 'application/json',
                        ],
                    ]);
                } catch (GuzzleException $retryException) {
                    $retryStatus = null;
                    $retryResponseBody = null;
                    if (method_exists($retryException, 'getResponse') && $retryException->getResponse()) {
                        $retryStatus = $retryException->getResponse()->getStatusCode();
                        $retryResponseBody = (string) $retryException->getResponse()->getBody();
                    }

                    if (($retryStatus === 400 || $retryStatus === 500) && is_string($retryResponseBody) && $retryResponseBody !== '' && str_contains($retryResponseBody, 'FRAMEWORK__ASSOCIATION_NOT_FOUND')) {
                        unset($body['associations']['salutation']);
                        $res = $this->requestWithRetry('POST', $url, [
                            'json' => $body,
                            'headers' => [
                                'Authorization' => 'Bearer '.$token,
                                'Accept' => 'application/json',
                                'Content-Type' => 'application/json',
                            ],
                        ]);
                    } else {
                        throw $retryException;
                    }
                }
            } elseif (($status === 400 || $status === 500) && is_string($responseBody) && $responseBody !== '' && str_contains($responseBody, 'FRAMEWORK__ASSOCIATION_NOT_FOUND')) {
                unset($body['associations']['salutation']);
                $res = $this->requestWithRetry('POST', $url, [
                    'json' => $body,
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                ]);
            } else {
                throw $e;
            }
        }

        $payload = json_decode((string) $res->getBody(), true);
        $rows = is_array($payload) && isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
        $row = is_array($rows) && count($rows) > 0 ? $rows[0] : null;
        $iso = is_array($row) ? (string) ($row['isoCode'] ?? '') : '';
        $iso = strtoupper(trim($iso));

        if ($iso === '') {
            Cache::put($cacheKey, '__missing__', now()->addMinutes(10));
            return null;
        }

        Cache::put($cacheKey, $iso, now()->addDays(7));
        return $iso;
    }

    /**
     * Fetch all languages defined in Shopware, with their locale codes.
     * Returns an array of language entries for display in the UI.
     *
     * Each entry: ['id' => string, 'name' => string, 'locale' => string]
     *
     * @return array<int, array{id: string, name: string, locale: string}>
     */
    public function fetchLanguages(ShopwareConnection $conn): array
    {
        $cacheKey = 'shopware_languages:'.$conn->id;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $token = $this->getAccessToken($conn);

        $body = [
            'limit' => 100,
            'page'  => 1,
            'associations' => [
                'locale' => [],
            ],
        ];

        $url = rtrim($conn->api_url, '/').'/api/search/language';

        try {
            $res = $this->requestWithRetry('POST', $url, [
                'json'    => $body,
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            $status = null;
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $status = $e->getResponse()->getStatusCode();
            }
            if ($status === 401) {
                $conn->access_token    = null;
                $conn->token_expires_at = null;
                $conn->save();
                $token = $this->getAccessToken($conn);
                $res = $this->requestWithRetry('POST', $url, [
                    'json'    => $body,
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        'Accept'        => 'application/json',
                        'Content-Type'  => 'application/json',
                    ],
                ]);
            } else {
                throw $e;
            }
        }

        $payload = json_decode((string) $res->getBody(), true);
        $rows    = is_array($payload) && isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];

        $languages = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id     = trim((string) ($row['id'] ?? ''));
            $name   = trim((string) ($row['name'] ?? ''));
            $locale = trim((string) ($row['locale']['code'] ?? $row['localeCode'] ?? ''));

            if ($id === '') {
                continue;
            }

            $languages[] = [
                'id'     => $id,
                'name'   => $name !== '' ? $name : $locale,
                'locale' => $locale,
            ];
        }

        // Sort by name for consistent display
        usort($languages, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        Cache::put($cacheKey, $languages, now()->addHour());

        return $languages;
    }

    /**
     * Return only the enabled language entries from a connection's language_config.
     * Each entry: ['id' => string, 'name' => string, 'locale' => string]
     *
     * @return array<int, array{id: string, name: string, locale: string}>
     */
    public static function enabledLanguages(ShopwareConnection $conn): array
    {
        $config = $conn->language_config;
        if (! is_array($config)) {
            return [];
        }

        $enabled = [];
        foreach ($config as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $id     = trim((string) ($entry['id'] ?? ''));
            $locale = trim((string) ($entry['locale'] ?? ''));
            $name   = trim((string) ($entry['name'] ?? ''));

            if ($id === '' || $locale === '') {
                continue;
            }
            if (! (bool) ($entry['enabled'] ?? false)) {
                continue;
            }

            $enabled[] = [
                'id'     => $id,
                'name'   => $name,
                'locale' => $locale,
            ];
        }

        return $enabled;
    }

    // -----------------------------------------------------------------------
    // Well-known Shopware 6 Sales Channel type UUIDs (stable across versions)
    // -----------------------------------------------------------------------
    private const SALES_CHANNEL_TYPE_STOREFRONT = '8a243080f92e4c719546314b577cf82b';
    private const SALES_CHANNEL_TYPE_API        = 'f183ee5650cf4bdb8a774337575067a6'; // Headless / API
    private const SALES_CHANNEL_TYPE_COMPARISON = '7fceb18a9d9b4c05929d43b07b01f185'; // Product Comparison

    /**
     * Map a Shopware typeId to a human-readable label.
     */
    private function salesChannelTypeLabel(string $typeId): string
    {
        return match (strtolower(trim($typeId))) {
            self::SALES_CHANNEL_TYPE_STOREFRONT => 'Storefront',
            self::SALES_CHANNEL_TYPE_API        => 'Headless',
            self::SALES_CHANNEL_TYPE_COMPARISON => 'Product Comparison',
            default                             => '',
        };
    }

    /**
     * Fetch ALL Sales Channels from Shopware dynamically.
     *
     * Strategy:
     *  • First attempt: no associations at all (100% compatible with all SW6 versions).
     *  • This guarantees every channel is returned — association errors cannot cause
     *    channels to be silently omitted.
     *  • Channel type is resolved from the well-known `typeId` UUID constants, so no
     *    `type` association is required.
     *  • Paginates automatically so stores with > 100 channels are fully covered.
     *  • Cache TTL is intentionally short (2 min) so newly created channels appear quickly.
     *
     * Each entry: ['id' => string, 'name' => string, 'navigation_category_id' => string|null, 'type' => string]
     *
     * @return array<int, array{id: string, name: string, navigation_category_id: string|null, type: string}>
     */
    public function getSalesChannels(ShopwareConnection $conn): array
    {
        // Short cache — 2 minutes so newly created channels appear quickly in the UI.
        $cacheKey = 'shopware_sales_channels:'.$conn->id;
        $cached   = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $token   = $this->getAccessToken($conn);
        $url     = rtrim($conn->api_url, '/').'/api/search/sales-channel';
        $limit   = 100;
        $page    = 1;
        $all     = [];

        do {
            // Bare body — no associations required.
            // typeId is always present as a root-level field in every SW6 version.
            $body = [
                'limit'            => $limit,
                'page'             => $page,
                'total-count-mode' => 1,
            ];

            try {
                $res = $this->requestWithRetry('POST', $url, [
                    'json'    => $body,
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        'Accept'        => 'application/json',
                        'Content-Type'  => 'application/json',
                    ],
                ]);
            } catch (GuzzleException $e) {
                $status = null;
                if (method_exists($e, 'getResponse') && $e->getResponse()) {
                    $status = $e->getResponse()->getStatusCode();
                }
                if ($status === 401) {
                    // Refresh token once and retry this page
                    $conn->access_token    = null;
                    $conn->token_expires_at = null;
                    $conn->save();
                    $token = $this->getAccessToken($conn);
                    $res   = $this->requestWithRetry('POST', $url, [
                        'json'    => $body,
                        'headers' => [
                            'Authorization' => 'Bearer '.$token,
                            'Accept'        => 'application/json',
                            'Content-Type'  => 'application/json',
                        ],
                    ]);
                } else {
                    throw $e;
                }
            }

            $payload = json_decode((string) $res->getBody(), true);
            $rows    = is_array($payload) && isset($payload['data']) && is_array($payload['data'])
                       ? $payload['data'] : [];
            $total   = is_int($payload['total'] ?? null) ? (int) $payload['total'] : count($rows);

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $id   = trim((string) ($row['id'] ?? ''));
                $name = trim((string) ($row['name'] ?? ''));

                if ($id === '') {
                    continue;
                }

                // Resolve type label: prefer typeId constant mapping, fall back to type.name from
                // embedded relation if Shopware happens to include it, then empty string.
                $typeId    = trim((string) ($row['typeId'] ?? ''));
                $typeLabel = $typeId !== '' ? $this->salesChannelTypeLabel($typeId)
                                           : trim((string) ($row['type']['name'] ?? $row['typeName'] ?? ''));

                $navCatId = trim((string) ($row['navigationCategoryId'] ?? ''));

                $all[] = [
                    'id'                     => $id,
                    'name'                   => $name !== '' ? $name : $id,
                    'navigation_category_id' => $navCatId !== '' ? $navCatId : null,
                    'type'                   => $typeLabel,
                ];
            }

            $fetched = count($rows);
            $page++;

        } while ($fetched >= $limit && ($page - 1) * $limit < $total);

        // Sort by name for a consistent, predictable dropdown order
        usort($all, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        Log::info('Fetched Shopware sales channels', [
            'connection_id' => $conn->id,
            'count'         => count($all),
        ]);

        Cache::put($cacheKey, $all, now()->addMinutes(2));

        return $all;
    }

    /**
     * Fetch ALL storefront Sales Channels with their associated domains, countries, and default locales.
     * Used for the Sales Channels / Markets migration step.
     *
     * @return array<int, array{id: string, name: string, navigation_category_id: string|null, type: string, default_country_iso: string|null, default_locale: string|null, countries: array<int, string>, domains: array<int, array{url: string, locale: string|null, currency: string|null}>}>
     */
    public function getSalesChannelsWithDetails(ShopwareConnection $conn): array
    {
        $token = $this->getAccessToken($conn);
        $url   = rtrim($conn->api_url, '/').'/api/search/sales-channel';
        $limit = 100;
        $page  = 1;
        $all   = [];

        do {
            $body = [
                'limit'            => $limit,
                'page'             => $page,
                'total-count-mode' => 1,
                'associations'     => [
                    'domains' => [
                        'associations' => [
                            'language' => [
                                'associations' => [
                                    'locale' => []
                                ]
                            ],
                            'currency' => []
                        ]
                    ],
                    'countries' => [],
                    'country' => [],
                    'language' => [
                        'associations' => [
                            'locale' => []
                        ]
                    ]
                ]
            ];

            try {
                $res = $this->requestWithRetry('POST', $url, [
                    'json'    => $body,
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        'Accept'        => 'application/json',
                        'Content-Type'  => 'application/json',
                    ],
                ]);
            } catch (GuzzleException $e) {
                $status = null;
                if (method_exists($e, 'getResponse') && $e->getResponse()) {
                    $status = $e->getResponse()->getStatusCode();
                }
                if ($status === 401) {
                    $conn->access_token    = null;
                    $conn->token_expires_at = null;
                    $conn->save();
                    $token = $this->getAccessToken($conn);
                    $res   = $this->requestWithRetry('POST', $url, [
                        'json'    => $body,
                        'headers' => [
                            'Authorization' => 'Bearer '.$token,
                            'Accept'        => 'application/json',
                            'Content-Type'  => 'application/json',
                        ],
                    ]);
                } else {
                    throw $e;
                }
            }

            $payload = json_decode((string) $res->getBody(), true);
            $rows    = is_array($payload) && isset($payload['data']) && is_array($payload['data'])
                       ? $payload['data'] : [];
            $total   = is_int($payload['total'] ?? null) ? (int) $payload['total'] : count($rows);

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $id     = trim((string) ($row['id'] ?? ''));
                $name   = trim((string) ($row['name'] ?? ''));
                $typeId = trim((string) ($row['typeId'] ?? ''));

                if ($id === '') {
                    continue;
                }

                // Filter out non-storefront channels for Market migrations
                if (strtolower($typeId) !== self::SALES_CHANNEL_TYPE_STOREFRONT) {
                    continue;
                }

                $navCatId = trim((string) ($row['navigationCategoryId'] ?? ''));
                
                // Parse default country ISO code
                $defaultCountryIso = trim((string) (data_get($row, 'country.iso') ?: ''));

                // Parse default language locale
                $defaultLocale = trim((string) (data_get($row, 'language.locale.code') ?: ''));

                // Parse allowed countries
                $countries = [];
                $countriesData = data_get($row, 'countries', []);
                if (is_array($countriesData)) {
                    foreach ($countriesData as $c) {
                        $cIso = trim((string) ($c['iso'] ?? ''));
                        if ($cIso !== '') {
                            $countries[] = strtoupper($cIso);
                        }
                    }
                }

                // Parse domains
                $domains = [];
                $domainsData = data_get($row, 'domains', []);
                if (is_array($domainsData)) {
                    foreach ($domainsData as $d) {
                        $dUrl = trim((string) ($d['url'] ?? ''));
                        if ($dUrl !== '') {
                            $dLocale = trim((string) (data_get($d, 'language.locale.code') ?: ''));
                            $dCurrency = trim((string) (data_get($d, 'currency.isoCode') ?: ''));
                            $domains[] = [
                                'url'      => $dUrl,
                                'locale'   => $dLocale !== '' ? $dLocale : null,
                                'currency' => $dCurrency !== '' ? $dCurrency : null,
                            ];
                        }
                    }
                }

                $all[] = [
                    'id'                     => $id,
                    'name'                   => $name !== '' ? $name : $id,
                    'navigation_category_id' => $navCatId !== '' ? $navCatId : null,
                    'type'                   => 'Storefront',
                    'default_country_iso'    => $defaultCountryIso !== '' ? strtoupper($defaultCountryIso) : null,
                    'default_locale'         => $defaultLocale !== '' ? $defaultLocale : null,
                    'countries'              => $countries,
                    'domains'                => $domains,
                ];
            }

            $fetched = count($rows);
            $page++;

        } while ($fetched >= $limit && ($page - 1) * $limit < $total);

        // Sort by name
        usort($all, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        return $all;
    }

    /**

     * @return array{products: array<int, mixed>, total: int}

     */
    public function searchProducts(ShopwareConnection $conn, int $limit = 50, int $page = 1, array $filter = [], ?array $associations = null, bool $includeTotalCount = true): array
    {
        $token = $this->getAccessToken($conn);

        $body = [
            'limit' => $limit,
            'page' => $page,
            'total-count-mode' => $includeTotalCount ? 1 : 0,
        ];

        if (! empty($filter)) {
            $body['filter'] = $filter;
        }

        $url = rtrim($conn->api_url, '/').'/api/search/product';
        $includeChildren = is_array($associations) && isset($associations['children']);
        $associationCandidates = $this->productAssociationCandidates($associations, $includeChildren);

        $lastException = null;
        foreach ($associationCandidates as $index => $assoc) {
            $attemptBody = $body;
            $attemptBody['associations'] = $assoc;

            try {
                $res = $this->postProductSearch($conn, $url, $attemptBody, $token);

                if ($index > 0) {
                    Log::warning('Shopware product search succeeded with fallback associations', [
                        'url' => $url,
                        'attempt' => $index + 1,
                    ]);
                }

                $payload = json_decode((string) $res->getBody(), true);
                $products = is_array($payload) && isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
                $total = is_array($payload) && isset($payload['total']) ? (int) $payload['total'] : 0;

                return ['products' => $products, 'total' => $total];
            } catch (GuzzleException $e) {
                $lastException = $e;
                $status = null;
                $responseBody = null;
                if (method_exists($e, 'getResponse') && $e->getResponse()) {
                    $status = $e->getResponse()->getStatusCode();
                    $responseBody = (string) $e->getResponse()->getBody();
                }

                if ($this->isAssociationNotFoundError($status, $responseBody)) {
                    $missing = $this->extractMissingAssociationName((string) $responseBody);
                    Log::warning('Shopware product search rejected association; trying next association set', [
                        'url' => $url,
                        'status' => $status,
                        'missing_association' => $missing,
                        'attempt' => $index + 1,
                    ]);

                    continue;
                }

                throw $e;
            }
        }

        if ($lastException !== null) {
            throw $lastException;
        }

        return ['products' => [], 'total' => 0];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function productAssociationCandidates(?array $associations, bool $includeChildren): array
    {
        if ($associations !== null) {
            return array_values(array_filter([
                $associations,
                $this->reducedProductAssociations($includeChildren),
                $this->minimalProductAssociations($includeChildren),
            ], fn ($set) => is_array($set) && count($set) > 0));
        }

        return [
            $this->defaultProductAssociations($includeChildren),
            $this->reducedProductAssociations($includeChildren),
            $this->minimalProductAssociations($includeChildren),
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function postProductSearch(ShopwareConnection $conn, string $url, array $body, string $token)
    {
        try {
            return $this->withShopwareSearchThrottle($conn, $url, $body, function () use ($token, $url, $body) {
                return $this->requestWithRetry('POST', $url, [
                    'json' => $body,
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                ]);
            });
        } catch (GuzzleException $e) {
            if (method_exists($e, 'getResponse') && $e->getResponse() && $e->getResponse()->getStatusCode() === 401) {
                $conn->access_token = null;
                $conn->token_expires_at = null;
                $conn->save();

                $token = $this->getAccessToken($conn);

                return $this->withShopwareSearchThrottle($conn, $url, $body, function () use ($token, $url, $body) {
                    return $this->requestWithRetry('POST', $url, [
                        'json' => $body,
                        'headers' => [
                            'Authorization' => 'Bearer '.$token,
                            'Accept' => 'application/json',
                            'Content-Type' => 'application/json',
                        ],
                    ]);
                });
            }

            throw $e;
        }
    }

    private function isAssociationNotFoundError(?int $status, ?string $responseBody): bool
    {
        if (! is_string($responseBody) || $responseBody === '') {
            return false;
        }
        // Accept status 400, 500, or null (when body comes from exception message)
        if ($status !== null && $status !== 400 && $status !== 500) {
            return false;
        }
        return str_contains($responseBody, 'FRAMEWORK__ASSOCIATION_NOT_FOUND');
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withShopwareSearchThrottle(ShopwareConnection $conn, string $url, array $body, callable $fn)
    {
        // Shopware can become the bottleneck under many parallel queue workers.
        // Use a fixed sharded lock to limit concurrent product search requests per connection.
        $shards = (int) env('SHOPWARE_PRODUCT_SEARCH_SHARDS', 8);
        if ($shards < 1) {
            $shards = 1;
        }

        $seed = $url.'|'.($body['page'] ?? '').'|'.($body['limit'] ?? '').'|'.md5(json_encode($body['filter'] ?? []));
        $shard = (int) (abs(crc32($seed)) % $shards);
        $lock = Cache::lock('shopware:product_search:'.$conn->id.':'.$shard, 30);

        return $lock->block(20, $fn);
    }

    /**
     * @return array<int, mixed>
     */
    public function fetchVariantChildren(ShopwareConnection $conn, string $parentId): array
    {
        $filter = [
            ['type' => 'equals', 'field' => 'parentId', 'value' => $parentId],
        ];

        $page = 1;
        $limit = 100;
        $out = [];
        $total = null;

        while (true) {
            $res = $this->searchProducts($conn, $limit, $page, $filter, null, false);
            $products = $res['products'] ?? [];
            $total = $total ?? ($res['total'] ?? 0);

            if (!is_array($products) || count($products) === 0) {
                break;
            }

            foreach ($products as $p) {
                $out[] = $p;
            }

            if ($total !== null && ($page * $limit) >= (int) $total) {
                break;
            }

            $page++;
        }

        return $out;
    }

    public function fetchProductWithChildren(ShopwareConnection $conn, string $sourceId): array
    {
        $filter = [
            ['type' => 'equals', 'field' => 'id', 'value' => $sourceId],
        ];

        try {
            $res = $this->searchProducts($conn, 1, 1, $filter, $this->defaultProductAssociations(true), false);
            $products = $res['products'] ?? [];
            $parent = (is_array($products) && count($products) > 0 && is_array($products[0])) ? $products[0] : null;
            $children = [];
            if (is_array($parent) && isset($parent['children']) && is_array($parent['children'])) {
                $children = $parent['children'];
            }

            if (!is_array($children) && is_array($parent)) {
                $children = $this->fetchVariantChildren($conn, $sourceId);
            }

            return ['parent' => $parent, 'children' => $children];
        } catch (\Throwable $e) {
            $products = $this->searchProducts($conn, 1, 1, $filter, null, false);
            $rows = is_array($products) ? ($products['products'] ?? []) : [];
            $parent = (is_array($rows) && count($rows) > 0 && is_array($rows[0])) ? $rows[0] : null;
            $children = $parent ? $this->fetchVariantChildren($conn, $sourceId) : [];
            return ['parent' => $parent, 'children' => $children];
        }
    }

    private function defaultProductAssociations(bool $includeChildren = false): array
    {
        $pricesAssoc = ['associations' => ['rule' => []]];

        $associations = [
            'cover'               => ['associations' => ['media' => []]],
            'media'               => ['associations' => ['media' => []]],
            'categories'          => ['associations' => ['translations' => []]],
            'seoUrls'             => [],
            'properties'          => ['associations' => ['group' => []]],
            'options'             => ['associations' => ['group' => []]],
            'prices'              => $pricesAssoc,
            'manufacturer'        => [],
            'tax'                 => [],
            'translations'        => [],  // Used for multi-language migration
            'visibilities'        => [],  // Used for Sales Channel scoping / market publishing
            'configuratorSettings' => [
                'associations' => [
                    'option' => ['associations' => ['group' => []]],
                ],
            ],
            'downloads'           => [],  // For digital products: fetch CDN file links
        ];

        if ($includeChildren) {
            $associations['children'] = [
                'associations' => [
                    'cover' => ['associations' => ['media' => []]],
                    'media' => ['associations' => ['media' => []]],
                    'options' => ['associations' => ['group' => []]],
                    'prices' => $pricesAssoc,
                    'manufacturer' => [],
                    'tax' => [],
                    'downloads' => [],  // For variant-level digital files
                ],
            ];
        }

        return $associations;
    }

    /**
     * @return array<string, mixed>
     */
    private function reducedProductAssociations(bool $includeChildren = false): array
    {
        $pricesAssoc = ['associations' => ['rule' => []]];

        $associations = [
            'cover' => ['associations' => ['media' => []]],
            'manufacturer' => [],
            'prices' => $pricesAssoc,
            'options' => ['associations' => ['group' => []]],
            'categories' => ['associations' => ['translations' => []]],
            'seoUrls' => [],
            'tax' => [],
            'visibilities' => [], // Used for Sales Channel scoping / market publishing
            'downloads' => [],  // For digital products
        ];

        if ($includeChildren) {
            $associations['children'] = [
                'associations' => [
                    'cover' => ['associations' => ['media' => []]],
                    'options' => ['associations' => ['group' => []]],
                    'prices' => $pricesAssoc,
                    'manufacturer' => [],
                    'tax' => [],
                    'downloads' => [],  // For variant-level digital files
                ],
            ];
        }

        return $associations;
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalProductAssociations(bool $includeChildren = false): array
    {
        $associations = [
            'cover' => ['associations' => ['media' => []]],
            'manufacturer' => [],
            'prices' => [],
        ];

        if ($includeChildren) {
            $associations['children'] = [
                'associations' => [
                    'prices' => [],
                    'manufacturer' => [],
                ],
            ];
        }

        return $associations;
    }

    /**
     * @return array{customers: array<int, mixed>, total: int}
     */
    public function searchCustomers(ShopwareConnection $conn, int $limit = 50, int $page = 1, array $filter = []): array
    {
        $token = $this->getAccessToken($conn);

        $body = [
            'limit' => $limit,
            'page' => $page,
            'associations' => [
                'defaultBillingAddress' => [
                    'associations' => [
                        'country' => [],
                        'countryState' => [],
                    ],
                ],
                'defaultShippingAddress' => [
                    'associations' => [
                        'country' => [],
                        'countryState' => [],
                    ],
                ],
                'addresses' => [
                    'associations' => [
                        'country' => [],
                        'countryState' => [],
                    ],
                ],
                'salutation' => [],
                'group' => [],
                'language' => [
                    'associations' => [
                        'locale' => [],
                    ],
                ],
                'salesChannel' => [],
            ],
        ];

        if (!empty($filter)) {
            $body['filter'] = $filter;
        }

        $url = rtrim($conn->api_url, '/').'/api/search/customer';

        try {
            $res = $this->requestWithRetry('POST', $url, [
                'json' => $body,
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            $status = null;
            $responseBody = null;
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $status = $e->getResponse()->getStatusCode();
                $responseBody = (string) $e->getResponse()->getBody();
            }

            if ($status === 401) {
                $conn->access_token = null;
                $conn->token_expires_at = null;
                $conn->save();

                $token = $this->getAccessToken($conn);
                try {
                    $res = $this->requestWithRetry('POST', $url, [
                        'json' => $body,
                        'headers' => [
                            'Authorization' => 'Bearer '.$token,
                            'Accept' => 'application/json',
                            'Content-Type' => 'application/json',
                        ],
                    ]);
                } catch (GuzzleException $retryException) {
                    $retryStatus = null;
                    $retryResponseBody = null;
                    if (method_exists($retryException, 'getResponse') && $retryException->getResponse()) {
                        $retryStatus = $retryException->getResponse()->getStatusCode();
                        $retryResponseBody = (string) $retryException->getResponse()->getBody();
                    }

                    if (($retryStatus === 400 || $retryStatus === 500) && is_string($retryResponseBody) && $retryResponseBody !== '' && str_contains($retryResponseBody, 'FRAMEWORK__ASSOCIATION_NOT_FOUND')) {
                        unset($body['associations']['salutation']);
                        $res = $this->requestWithRetry('POST', $url, [
                            'json' => $body,
                            'headers' => [
                                'Authorization' => 'Bearer '.$token,
                                'Accept' => 'application/json',
                                'Content-Type' => 'application/json',
                            ],
                        ]);
                    } else {
                        throw $retryException;
                    }
                }
            } elseif (($status === 400 || $status === 500) && is_string($responseBody) && $responseBody !== '' && str_contains($responseBody, 'FRAMEWORK__ASSOCIATION_NOT_FOUND')) {
                unset($body['associations']['salutation']);
                $res = $this->requestWithRetry('POST', $url, [
                    'json' => $body,
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                ]);
            } else {
                throw $e;
            }
        }

        $payload = json_decode((string) $res->getBody(), true);
        $customers = is_array($payload) && isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
        $total = is_array($payload) && isset($payload['total']) ? (int) $payload['total'] : 0;

        return ['customers' => $customers, 'total' => $total];
    }

    /**
     * @return array{manufacturers: array<int, mixed>, total: int}
     */
    public function searchManufacturers(ShopwareConnection $conn, int $limit = 50, int $page = 1, array $filter = [], ?array $associations = null): array
    {
        $token = $this->getAccessToken($conn);

        $body = [
            'limit' => $limit,
            'page'  => $page,
        ];

        if (! empty($filter)) {
            $body['filter'] = $filter;
        }

        if (is_array($associations) && count($associations) > 0) {
            $body['associations'] = $associations;
        }
        $url = rtrim($conn->api_url, '/').'/api/search/product-manufacturer';

        try {
            $res = $this->requestWithRetry('POST', $url, [
                'json' => $body,
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            $status = null;
            $responseBody = null;
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $status = $e->getResponse()->getStatusCode();
                $responseBody = (string) $e->getResponse()->getBody();
            }

            if ($status === 401) {
                $conn->access_token = null;
                $conn->token_expires_at = null;
                $conn->save();

                $token = $this->getAccessToken($conn);
                $res = $this->requestWithRetry('POST', $url, [
                    'json' => $body,
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                ]);
            } elseif (
                ($status === 400 || $status === 500)
                && is_string($responseBody)
                && $responseBody !== ''
                && str_contains($responseBody, 'FRAMEWORK__ASSOCIATION_NOT_FOUND')
            ) {
                unset($body['associations']);
                $res = $this->requestWithRetry('POST', $url, [
                    'json' => $body,
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                ]);
            } else {
                throw $e;
            }
        }

        $payload = json_decode((string) $res->getBody(), true);
        $manufacturers = is_array($payload) && isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
        $total = is_array($payload) && isset($payload['total']) ? (int) $payload['total'] : 0;

        return ['manufacturers' => $manufacturers, 'total' => $total];
    }

    /**
     * @return array{recipients: array<int, mixed>, total: int}
     */
    public function searchNewsletterRecipients(ShopwareConnection $conn, int $limit = 100, int $page = 1, array $filter = []): array
    {
        $token = $this->getAccessToken($conn);

        $body = [
            'limit' => $limit,
            'page' => $page,
            'associations' => [
                'salutation' => [],
            ],
        ];

        if (!empty($filter)) {
            $body['filter'] = $filter;
        }

        $url = rtrim($conn->api_url, '/').'/api/search/newsletter-recipient';

        try {
            $res = $this->requestWithRetry('POST', $url, [
                'json' => $body,
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            $status = null;
            $responseBody = null;
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $status = $e->getResponse()->getStatusCode();
                $responseBody = (string) $e->getResponse()->getBody();
            }

            if ($status === 401) {
                $conn->access_token = null;
                $conn->token_expires_at = null;
                $conn->save();

                $token = $this->getAccessToken($conn);
                $res = $this->requestWithRetry('POST', $url, [
                    'json' => $body,
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                ]);
            } elseif (($status === 400 || $status === 500) && is_string($responseBody) && $responseBody !== '' && str_contains($responseBody, 'FRAMEWORK__ASSOCIATION_NOT_FOUND')) {
                unset($body['associations']['salutation']);
                $res = $this->requestWithRetry('POST', $url, [
                    'json' => $body,
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                ]);
            } else {
                throw $e;
            }
        }

        $payload = json_decode((string) $res->getBody(), true);
        $rows = is_array($payload) && isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
        $total = is_array($payload) && isset($payload['total']) ? (int) $payload['total'] : 0;

        return ['recipients' => $rows, 'total' => $total];
    }

    /**
     * @return array{orders: array<int, mixed>, total: int}
     */
    public function searchOrders(ShopwareConnection $conn, int $limit = 50, int $page = 1, array $filter = []): array
    {
        $token = $this->getAccessToken($conn);

        $associationsFull = [
            'currency' => [],
            'language' => [],
            'salesChannel' => [],
            'state' => [],
            'billingAddress' => [
                'associations' => [
                    'country' => [],
                    'countryState' => [],
                ],
            ],
            'addresses' => [
                'associations' => [
                    'country' => [],
                    'countryState' => [],
                ],
            ],
            'deliveries' => [
                'associations' => [
                    'state' => [],
                    'shippingOrderAddress' => [
                        'associations' => [
                            'country' => [],
                            'countryState' => [],
                        ],
                    ],
                    'shippingMethod' => [],
                    'positions' => [
                        'associations' => [
                            'orderLineItem' => [],
                        ],
                    ],
                ],
            ],
            'lineItems' => [
                'associations' => [
                    'product' => [
                        'associations' => [
                            'manufacturer' => [],
                        ],
                    ],
                ],
            ],
            'transactions' => [
                'associations' => [
                    'paymentMethod' => [],
                    'state' => [],
                ],
            ],
            'orderCustomer' => [
                'associations' => [
                    'customer' => [
                        'associations' => [
                            'defaultBillingAddress' => [
                                'associations' => [
                                    'country' => [],
                                    'countryState' => [],
                                ],
                            ],
                            'defaultShippingAddress' => [
                                'associations' => [
                                    'country' => [],
                                    'countryState' => [],
                                ],
                            ],
                            'group' => [],
                            'language' => [],
                            'salesChannel' => [],
                        ],
                    ],
                ],
            ],
        ];

        $associationsReduced = [
            'currency' => [],
            'state' => [],
            'billingAddress' => [
                'associations' => [
                    'country' => [],
                    'countryState' => [],
                ],
            ],
            'addresses' => [
                'associations' => [
                    'country' => [],
                    'countryState' => [],
                ],
            ],
            'deliveries' => [
                'associations' => [
                    'state' => [],
                    'shippingOrderAddress' => [
                        'associations' => [
                            'country' => [],
                            'countryState' => [],
                        ],
                    ],
                    'shippingMethod' => [],
                ],
            ],
            'lineItems' => [
                'associations' => [
                    'product' => [
                        'associations' => [
                            'manufacturer' => [],
                        ],
                    ],
                ],
            ],
            'transactions' => [
                'associations' => [
                    'paymentMethod' => [],
                    'state' => [],
                ],
            ],
            'orderCustomer' => [
                'associations' => [
                    'customer' => [],
                ],
            ],
        ];

        $body = [
            'limit' => $limit,
            'page' => $page,
            'associations' => $associationsFull,
        ];

        if (!empty($filter)) {
            $body['filter'] = $filter;
        }

        $url = rtrim($conn->api_url, '/').'/api/search/order';

        try {
            $res = $this->requestWithRetry('POST', $url, [
                'json' => $body,
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            $status = null;
            $responseBody = null;
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $status = $e->getResponse()->getStatusCode();
                $responseBody = (string) $e->getResponse()->getBody();
            }

            if ($status === 401) {
                $conn->access_token = null;
                $conn->token_expires_at = null;
                $conn->save();

                $token = $this->getAccessToken($conn);
                $res = $this->requestWithRetry('POST', $url, [
                    'json' => $body,
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                ]);
            } elseif (($status === 400 || $status === 500) && is_string($responseBody) && $responseBody !== '' && str_contains($responseBody, 'FRAMEWORK__ASSOCIATION_NOT_FOUND')) {
                $missing = $this->extractMissingAssociationName($responseBody);
                if (is_string($missing) && $missing !== '') {
                    Log::warning('Shopware order search rejected association; retrying after removing association', [
                        'url' => $url,
                        'status' => $status,
                        'missing_association' => $missing,
                    ]);

                    try {
                        $body['associations'] = $this->removeAssociationRecursive($body['associations'] ?? [], $missing);
                        $res = $this->requestWithRetry('POST', $url, [
                            'json' => $body,
                            'headers' => [
                                'Authorization' => 'Bearer '.$token,
                                'Accept' => 'application/json',
                                'Content-Type' => 'application/json',
                            ],
                        ]);

                        $payload = json_decode((string) $res->getBody(), true);
                        $orders = is_array($payload) && isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
                        $total = is_array($payload) && isset($payload['total']) ? (int) $payload['total'] : 0;
                        $orders = $this->enrichOrderPayload($conn, $orders);

                        return ['orders' => $orders, 'total' => $total];
                    } catch (GuzzleException $e3) {
                        // fall through to reduced/minimal fallback below
                    }
                }

                Log::warning('Shopware order search rejected associations; retrying with reduced association set', [
                    'url' => $url,
                    'status' => $status,
                    'response' => $responseBody,
                ]);

                try {
                    $body['associations'] = $associationsReduced;
                    $res = $this->requestWithRetry('POST', $url, [
                        'json' => $body,
                        'headers' => [
                            'Authorization' => 'Bearer '.$token,
                            'Accept' => 'application/json',
                            'Content-Type' => 'application/json',
                        ],
                    ]);
                } catch (GuzzleException $e2) {
                    $status2 = null;
                    $responseBody2 = null;
                    if (method_exists($e2, 'getResponse') && $e2->getResponse()) {
                        $status2 = $e2->getResponse()->getStatusCode();
                        $responseBody2 = (string) $e2->getResponse()->getBody();
                    }

                    $assocError2 = ($status2 === 400 || $status2 === 500)
                        && is_string($responseBody2)
                        && $responseBody2 !== ''
                        && str_contains($responseBody2, 'FRAMEWORK__ASSOCIATION_NOT_FOUND');

                    if (!$assocError2) {
                        throw $e2;
                    }

                    Log::warning('Shopware order search still rejected associations; retrying with minimal association set', [
                        'url' => $url,
                        'status' => $status2,
                        'response' => $responseBody2,
                    ]);

                    $body['associations'] = [];
                    $res = $this->requestWithRetry('POST', $url, [
                        'json' => $body,
                        'headers' => [
                            'Authorization' => 'Bearer '.$token,
                            'Accept' => 'application/json',
                            'Content-Type' => 'application/json',
                        ],
                    ]);
                }
            } else {
                throw $e;
            }
        }

        $payload = json_decode((string) $res->getBody(), true);
        $orders = is_array($payload) && isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
        $total = is_array($payload) && isset($payload['total']) ? (int) $payload['total'] : 0;

        $orders = $this->enrichOrderPayload($conn, $orders);

        return ['orders' => $orders, 'total' => $total];
    }

    /**
     * Enrich a single order payload before mapping (states, payment/shipping method names).
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function enrichOrderForMigration(ShopwareConnection $conn, array $order): array
    {
        $orderId = trim((string) data_get($order, 'id', ''));
        if ($orderId !== '' && $this->orderPayloadNeedsDetailRefetch($order)) {
            $fetched = $this->fetchOrderForMigration($conn, $orderId);
            if (is_array($fetched)) {
                $order = $this->mergeOrderDetailFields($order, $fetched);
            }
        }

        $orders = $this->enrichOrderPayload($conn, [$order]);
        $order = is_array($orders[0] ?? null) ? $orders[0] : $order;

        // Fetch and attach order documents (invoices, delivery notes, credit notes, etc.)
        if ($orderId !== '' && !isset($order['documents'])) {
            $documents = $this->fetchOrderDocuments($conn, $orderId);
            $order['documents'] = $documents;
        }

        return $order;
    }

    /**
     * Fetch all documents associated with a Shopware order.
     * Documents include invoices, delivery notes, credit notes, cancellation invoices.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrderDocuments(ShopwareConnection $conn, string $orderId): array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return [];
        }

        $token = $this->getAccessToken($conn);

        $body = [
            'limit' => 50,
            'page'  => 1,
            'filter' => [
                ['type' => 'equals', 'field' => 'orderId', 'value' => $orderId],
            ],
            'associations' => [
                'documentType' => [],
            ],
        ];

        $url = rtrim($conn->api_url, '/').'/api/search/document';

        try {
            $res = $this->requestWithRetry('POST', $url, [
                'json' => $body,
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            $status = null;
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $status = $e->getResponse()->getStatusCode();
            }

            if ($status === 401) {
                $conn->access_token = null;
                $conn->token_expires_at = null;
                $conn->save();

                try {
                    $token = $this->getAccessToken($conn);
                    $res = $this->requestWithRetry('POST', $url, [
                        'json' => $body,
                        'headers' => [
                            'Authorization' => 'Bearer '.$token,
                            'Accept' => 'application/json',
                            'Content-Type' => 'application/json',
                        ],
                    ]);
                } catch (GuzzleException $retryEx) {
                    Log::warning('Failed to fetch order documents after token refresh', [
                        'order_id' => $orderId,
                        'error'    => $retryEx->getMessage(),
                    ]);
                    return [];
                }
            } else {
                Log::warning('Failed to fetch order documents', [
                    'order_id' => $orderId,
                    'status'   => $status,
                    'error'    => $e->getMessage(),
                ]);
                return [];
            }
        }

        $payload = json_decode((string) $res->getBody(), true);
        $rows = is_array($payload) && isset($payload['data']) && is_array($payload['data'])
            ? $payload['data']
            : [];

        // Attach the base API URL so callers can build download URLs
        $apiBase = rtrim($conn->api_url, '/');
        foreach ($rows as &$row) {
            if (is_array($row)) {
                $row['_api_base'] = $apiBase;
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Load a single order with address/payment associations (list search may use minimal associations).
     *
     * @return array<string, mixed>|null
     */
    public function fetchOrderForMigration(ShopwareConnection $conn, string $orderId): ?array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return null;
        }

        $filter = [
            ['type' => 'equals', 'field' => 'id', 'value' => $orderId],
        ];

        $associationSets = [
            [
                'billingAddress' => [
                    'associations' => [
                        'country' => [],
                        'countryState' => [],
                    ],
                ],
                'addresses' => [
                    'associations' => [
                        'country' => [],
                        'countryState' => [],
                    ],
                ],
                'deliveries' => [
                    'associations' => [
                        'shippingOrderAddress' => [
                            'associations' => [
                                'country' => [],
                                'countryState' => [],
                            ],
                        ],
                        'shippingMethod' => [],
                    ],
                ],
                'transactions' => [
                    'associations' => [
                        'paymentMethod' => [],
                        'state' => [],
                    ],
                ],
                'orderCustomer' => [
                    'associations' => [
                        'customer' => [
                            'associations' => [
                                'defaultBillingAddress' => [
                                    'associations' => [
                                        'country' => [],
                                        'countryState' => [],
                                    ],
                                ],
                                'defaultShippingAddress' => [
                                    'associations' => [
                                        'country' => [],
                                        'countryState' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'state' => [],
                'currency' => [],
            ],
            [
                'billingAddress' => ['associations' => ['country' => []]],
                'addresses' => ['associations' => ['country' => []]],
                'deliveries' => ['associations' => ['shippingOrderAddress' => []]],
                'transactions' => ['associations' => ['paymentMethod' => []]],
                'orderCustomer' => ['associations' => ['customer' => []]],
            ],
            [],
        ];

        $token = $this->getAccessToken($conn);
        $url = rtrim($conn->api_url, '/').'/api/search/order';

        foreach ($associationSets as $associations) {
            $body = [
                'limit' => 1,
                'page' => 1,
                'filter' => $filter,
            ];
            if (count($associations) > 0) {
                $body['associations'] = $associations;
            }

            try {
                $res = $this->requestWithRetry('POST', $url, [
                    'json' => $body,
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                ]);
            } catch (GuzzleException $e) {
                $status = null;
                $responseBody = null;
                if (method_exists($e, 'getResponse') && $e->getResponse()) {
                    $status = $e->getResponse()->getStatusCode();
                    $responseBody = (string) $e->getResponse()->getBody();
                }

                if ($this->isAssociationNotFoundError($status, $responseBody)) {
                    continue;
                }

                throw $e;
            }

            $payload = json_decode((string) $res->getBody(), true);
            $order = data_get($payload, 'data.0');
            if (is_array($order)) {
                return $order;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function orderPayloadNeedsDetailRefetch(array $order): bool
    {
        if ($this->orderNeedsBillingAddressRefetch($order)) {
            return true;
        }

        if ($this->orderNeedsShippingAddressRefetch($order)) {
            return true;
        }

        return $this->orderNeedsPaymentMethodRefetch($order);
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function orderNeedsBillingAddressRefetch(array $order): bool
    {
        $billingAddressId = trim((string) data_get($order, 'billingAddressId', ''));
        if ($billingAddressId !== '' && ! is_array(data_get($order, 'billingAddress'))) {
            return true;
        }

        if (is_array(data_get($order, 'billingAddress')) && count(data_get($order, 'billingAddress')) > 0) {
            return false;
        }

        $addresses = data_get($order, 'addresses', []);
        if (is_array($addresses) && count($addresses) > 0) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function orderNeedsShippingAddressRefetch(array $order): bool
    {
        if ($this->orderHasNestedShippingAddress($order)) {
            return false;
        }

        $deliveries = data_get($order, 'deliveries', []);
        if (is_array($deliveries) && count($deliveries) > 0) {
            return true;
        }

        $defaultShipping = data_get($order, 'orderCustomer.customer.defaultShippingAddress');
        if (is_array($defaultShipping) && count($defaultShipping) > 0) {
            return false;
        }

        $addresses = data_get($order, 'addresses', []);
        if (is_array($addresses) && count($addresses) > 1) {
            return false;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function orderHasNestedShippingAddress(array $order): bool
    {
        $deliveries = data_get($order, 'deliveries', []);
        if (! is_array($deliveries)) {
            return false;
        }

        foreach ($deliveries as $delivery) {
            if (is_array($delivery) && is_array(data_get($delivery, 'shippingOrderAddress'))
                && count(data_get($delivery, 'shippingOrderAddress')) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function orderNeedsPaymentMethodRefetch(array $order): bool
    {
        $transactions = data_get($order, 'transactions', []);
        if (! is_array($transactions) || count($transactions) === 0) {
            return true;
        }

        foreach ($transactions as $tx) {
            if (! is_array($tx)) {
                continue;
            }
            $name = trim((string) (data_get($tx, 'paymentMethod.translated.name') ?: data_get($tx, 'paymentMethod.name') ?: ''));
            if ($name !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $fetched
     * @return array<string, mixed>
     */
    private function mergeOrderDetailFields(array $base, array $fetched): array
    {
        if (is_array($fetched['deliveries'] ?? null) && count($fetched['deliveries']) > 0
            && (! is_array($base['deliveries'] ?? null) || count($base['deliveries']) === 0
                || ! $this->orderHasNestedShippingAddress($base))) {
            $base['deliveries'] = $fetched['deliveries'];
        }

        foreach ([
            'billingAddress',
            'billingAddressId',
            'addresses',
            'transactions',
            'orderCustomer',
            'shippingTotal',
            'amountTotal',
            'orderNumber',
            'orderDateTime',
            'customerComment',
            'internalComment',
        ] as $key) {
            if (! array_key_exists($key, $base) || $base[$key] === null || $base[$key] === [] || $base[$key] === '') {
                if (array_key_exists($key, $fetched)) {
                    $base[$key] = $fetched[$key];
                }
            }
        }

        return $base;
    }

    /**
     * Enrich Shopware order payloads with state names and payment/shipping method labels.
     *
     * @param array<int, mixed> $orders
     * @return array<int, mixed>
     */
    private function enrichOrderPayload(ShopwareConnection $conn, array $orders): array
    {
        foreach ($orders as $idx => $order) {
            if (!is_array($order)) {
                continue;
            }

            $this->enrichEntityStateTechnicalName($conn, $order);

            $transactions = data_get($order, 'transactions', []);
            if (is_array($transactions)) {
                foreach ($transactions as $txIdx => $tx) {
                    if (!is_array($tx)) {
                        continue;
                    }
                    $this->enrichEntityStateTechnicalName($conn, $tx);
                    $this->enrichTransactionPaymentMethod($conn, $tx);
                    $transactions[$txIdx] = $tx;
                }
                $order['transactions'] = $transactions;
            }

            $deliveries = data_get($order, 'deliveries', []);
            if (is_array($deliveries)) {
                foreach ($deliveries as $delIdx => $delivery) {
                    if (!is_array($delivery)) {
                        continue;
                    }
                    $this->enrichEntityStateTechnicalName($conn, $delivery);
                    $this->enrichDeliveryShippingMethod($conn, $delivery);
                    $deliveries[$delIdx] = $delivery;
                }
                $order['deliveries'] = $deliveries;
            }

            $orders[$idx] = $order;
        }

        return $orders;
    }

    /**
     * @deprecated Use enrichOrderPayload()
     *
     * @param array<int, mixed> $orders
     * @return array<int, mixed>
     */
    private function enrichOrderStateTechnicalNames(ShopwareConnection $conn, array $orders): array
    {
        return $this->enrichOrderPayload($conn, $orders);
    }

    /**
     * @param array<string, mixed> $entity
     */
    private function enrichEntityStateTechnicalName(ShopwareConnection $conn, array &$entity): void
    {
        if (\App\Support\ShopwareStateResolver::technicalName($entity) !== '') {
            return;
        }

        $stateId = \App\Support\ShopwareStateResolver::stateId($entity);
        if ($stateId === '') {
            return;
        }

        $technicalName = $this->resolveStateTechnicalName($conn, $stateId);
        if (!is_string($technicalName) || $technicalName === '') {
            return;
        }

        $entity['state'] = array_merge(
            is_array($entity['state'] ?? null) ? $entity['state'] : [],
            ['technicalName' => $technicalName]
        );
    }

    public function resolveStateTechnicalName(ShopwareConnection $conn, string $stateId): ?string
    {
        $stateId = trim($stateId);
        if ($stateId === '') {
            return null;
        }

        $cacheKey = 'shopware_state_tech:'.$conn->id.':'.$stateId;
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '' && $cached !== '__missing__') {
            return $cached;
        }
        if ($cached === '__missing__') {
            return null;
        }

        $token = $this->getAccessToken($conn);
        $url = rtrim($conn->api_url, '/').'/api/search/state-machine-state';

        try {
            $res = $this->requestWithRetry('POST', $url, [
                'json' => [
                    'limit' => 1,
                    'page' => 1,
                    'filter' => [
                        ['type' => 'equals', 'field' => 'id', 'value' => $stateId],
                    ],
                ],
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            Log::warning('Failed to resolve Shopware state technical name', [
                'state_id' => $stateId,
                'error' => $e->getMessage(),
            ]);
            Cache::put($cacheKey, '__missing__', now()->addHours(6));

            return null;
        }

        $payload = json_decode((string) $res->getBody(), true);
        $row = is_array($payload) && isset($payload['data'][0]) && is_array($payload['data'][0])
            ? $payload['data'][0]
            : null;
        $technicalName = is_array($row)
            ? strtolower(trim((string) (data_get($row, 'technicalName') ?: data_get($row, 'attributes.technicalName') ?: '')))
            : '';

        if ($technicalName === '') {
            Cache::put($cacheKey, '__missing__', now()->addHours(6));

            return null;
        }

        Cache::put($cacheKey, $technicalName, now()->addDay());

        return $technicalName;
    }

    /**
     * @param array<string, mixed> $tx
     */
    private function enrichTransactionPaymentMethod(ShopwareConnection $conn, array &$tx): void
    {
        $existing = trim((string) (data_get($tx, 'paymentMethod.translated.name')
            ?: data_get($tx, 'paymentMethod.name')
            ?: ''));
        if ($existing !== '') {
            return;
        }

        $paymentMethodId = trim((string) (data_get($tx, 'paymentMethodId') ?: data_get($tx, 'paymentMethod.id') ?: ''));
        if ($paymentMethodId === '') {
            return;
        }

        $name = $this->resolvePaymentMethodName($conn, $paymentMethodId);
        if (!is_string($name) || $name === '') {
            return;
        }

        $tx['paymentMethod'] = array_merge(
            is_array($tx['paymentMethod'] ?? null) ? $tx['paymentMethod'] : [],
            [
                'id' => $paymentMethodId,
                'name' => $name,
                'translated' => ['name' => $name],
            ]
        );
    }

    /**
     * @param array<string, mixed> $delivery
     */
    private function enrichDeliveryShippingMethod(ShopwareConnection $conn, array &$delivery): void
    {
        $existing = trim((string) (data_get($delivery, 'shippingMethod.translated.name')
            ?: data_get($delivery, 'shippingMethod.name')
            ?: ''));
        if ($existing !== '') {
            return;
        }

        $shippingMethodId = trim((string) (data_get($delivery, 'shippingMethodId') ?: data_get($delivery, 'shippingMethod.id') ?: ''));
        if ($shippingMethodId === '') {
            return;
        }

        $name = $this->resolveShippingMethodName($conn, $shippingMethodId);
        if (!is_string($name) || $name === '') {
            return;
        }

        $delivery['shippingMethod'] = array_merge(
            is_array($delivery['shippingMethod'] ?? null) ? $delivery['shippingMethod'] : [],
            [
                'id' => $shippingMethodId,
                'name' => $name,
                'translated' => ['name' => $name],
            ]
        );
    }

    public function resolvePaymentMethodName(ShopwareConnection $conn, string $paymentMethodId): ?string
    {
        return $this->resolveEntityDisplayName($conn, 'payment-method', 'shopware_payment_method_name', $paymentMethodId);
    }

    public function resolveShippingMethodName(ShopwareConnection $conn, string $shippingMethodId): ?string
    {
        return $this->resolveEntityDisplayName($conn, 'shipping-method', 'shopware_shipping_method_name', $shippingMethodId);
    }

    private function resolveEntityDisplayName(
        ShopwareConnection $conn,
        string $entity,
        string $cachePrefix,
        string $entityId
    ): ?string {
        $entityId = trim($entityId);
        if ($entityId === '') {
            return null;
        }

        $cacheKey = $cachePrefix.':'.$conn->id.':'.$entityId;
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '' && $cached !== '__missing__') {
            return $cached;
        }
        if ($cached === '__missing__') {
            return null;
        }

        $token = $this->getAccessToken($conn);
        $url = rtrim($conn->api_url, '/').'/api/search/'.$entity;

        try {
            $res = $this->requestWithRetry('POST', $url, [
                'json' => [
                    'limit' => 1,
                    'page' => 1,
                    'filter' => [
                        ['type' => 'equals', 'field' => 'id', 'value' => $entityId],
                    ],
                ],
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            Log::warning('Failed to resolve Shopware entity display name', [
                'entity' => $entity,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
            Cache::put($cacheKey, '__missing__', now()->addHours(6));

            return null;
        }

        $payload = json_decode((string) $res->getBody(), true);
        $row = is_array($payload) && isset($payload['data'][0]) && is_array($payload['data'][0])
            ? $payload['data'][0]
            : null;
        $name = is_array($row)
            ? trim((string) (data_get($row, 'translated.name') ?: data_get($row, 'name') ?: data_get($row, 'attributes.name') ?: ''))
            : '';

        if ($name === '') {
            Cache::put($cacheKey, '__missing__', now()->addHours(6));

            return null;
        }

        Cache::put($cacheKey, $name, now()->addDay());

        return $name;
    }

    private function extractMissingAssociationName(string $responseBody): ?string
    {
        $payload = json_decode($responseBody, true);
        if (!is_array($payload)) {
            return null;
        }

        $detail = $payload['errors'][0]['detail'] ?? null;
        $association = $payload['errors'][0]['meta']['parameters']['association'] ?? null;
        if (is_string($association) && $association !== '') {
            return $association;
        }

        if (!is_string($detail) || $detail === '') {
            return null;
        }

        if (preg_match('/association\s+"([^"]+)"/i', $detail, $m) === 1) {
            return (string) ($m[1] ?? '');
        }

        if (preg_match('/association\s+`([^`]+)`/i', $detail, $m) === 1) {
            return (string) ($m[1] ?? '');
        }

        if (preg_match('/association\s+by\s+name\s+([A-Za-z0-9_]+)/i', $detail, $m) === 1) {
            return (string) ($m[1] ?? '');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $associations
     * @return array<string, mixed>
     */
    private function removeAssociationRecursive(array $associations, string $missing): array
    {
        if (array_key_exists($missing, $associations)) {
            unset($associations[$missing]);
            return $associations;
        }

        foreach ($associations as $key => $val) {
            if (is_array($val) && isset($val['associations']) && is_array($val['associations'])) {
                $val['associations'] = $this->removeAssociationRecursive($val['associations'], $missing);
                $associations[$key] = $val;
            }
        }

        return $associations;
    }

    /**
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function requestWithRetry(string $method, string $url, array $options)
    {
        $attempts = 4;
        $baseBackoffMs = 400;
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->http->request($method, $url, $options);
            } catch (GuzzleException $e) {
                $lastException = $e;

                $status = null;
                if (method_exists($e, 'getResponse') && $e->getResponse()) {
                    $status = $e->getResponse()->getStatusCode();
                }

                $responseBody = null;
                if (method_exists($e, 'getResponse') && $e->getResponse()) {
                    try {
                        $responseBody = (string) $e->getResponse()->getBody();
                    } catch (\Throwable) {
                        $responseBody = null;
                    }
                }

                if (
                    ($status === 400 || ($status !== null && $status >= 500 && $status <= 599))
                    && is_string($responseBody)
                    && $responseBody !== ''
                    && str_contains($responseBody, 'FRAMEWORK__ASSOCIATION_NOT_FOUND')
                ) {
                    // Re-throw immediately so the caller can try a reduced association set.
                    // Wrap in a runtime exception that preserves the full response body so
                    // callers can still inspect it after the Guzzle body stream is exhausted.
                    throw new \App\Exceptions\ShopwareAssociationException($responseBody, $status, $e);
                }

                $retryable = $status === null || $status === 429 || ($status >= 500 && $status <= 599);
                if (!$retryable || $attempt === $attempts) {
                    throw $e;
                }

                $backoffMs = min(8000, $baseBackoffMs * (2 ** ($attempt - 1)));

                Log::warning('Shopware request failed; retrying', [
                    'attempt' => $attempt,
                    'max_attempts' => $attempts,
                    'url' => $url,
                    'status' => $status,
                    'sleep_ms' => $backoffMs,
                    'error' => $e->getMessage(),
                ]);

                usleep($backoffMs * 1000);
            }
        }

        throw $lastException ?? new \RuntimeException('Unknown Shopware request failure');
    }

    /**
     * Fetch all salutations from Shopware.
     *
     * @return array<int, array{id: string, key: string, displayName: string}>
     */
    public function fetchSalutations(ShopwareConnection $conn): array
    {
        $token = $this->getAccessToken($conn);
        $url = rtrim($conn->api_url, '/').'/api/salutation';

        try {
            $res = $this->requestWithRetry('GET', $url, [
                'query' => ['limit' => 100],
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            Log::warning('Failed to fetch Shopware salutations', ['error' => $e->getMessage()]);
            return [];
        }

        $payload = json_decode((string) $res->getBody(), true);
        $data = is_array($payload) && isset($payload['data']) ? $payload['data'] : [];

        $out = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = (string) (data_get($item, 'salutationKey') ?: data_get($item, 'technicalName') ?: '');
            $displayName = (string) (data_get($item, 'translated.displayName') ?: data_get($item, 'displayName') ?: $key);
            $id = (string) (data_get($item, 'id') ?: '');
            if ($key !== '') {
                $out[] = ['id' => $id, 'key' => $key, 'displayName' => $displayName];
            }
        }

        return $out;
    }

    /**
     * Fetch all payment methods from Shopware.
     *
     * @return array<int, array{id: string, name: string, technicalName: string}>
     */
    public function fetchPaymentMethods(ShopwareConnection $conn): array
    {
        return $this->fetchNamedShopwareEntities($conn, 'payment-method', 'payment methods');
    }

    /**
     * Fetch all shipping methods from Shopware.
     *
     * @return array<int, array{id: string, name: string, technicalName: string}>
     */
    public function fetchShippingMethods(ShopwareConnection $conn): array
    {
        return $this->fetchNamedShopwareEntities($conn, 'shipping-method', 'shipping methods');
    }

    /**
     * @return array<int, array{id: string, name: string, technicalName: string}>
     */
    private function fetchNamedShopwareEntities(ShopwareConnection $conn, string $entity, string $logLabel): array
    {
        $token = $this->getAccessToken($conn);
        $url = rtrim($conn->api_url, '/').'/api/search/'.$entity;
        $limit = 100;
        $page = 1;
        $out = [];
        $seen = [];

        while (true) {
            $body = [
                'limit' => $limit,
                'page' => $page,
                'total-count-mode' => 1,
                'sort' => [
                    ['field' => 'name', 'order' => 'ASC', 'naturalSorting' => true],
                ],
            ];

            try {
                $res = $this->requestWithRetry('POST', $url, [
                    'json' => $body,
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                ]);
            } catch (GuzzleException $e) {
                if (method_exists($e, 'getResponse') && $e->getResponse() && $e->getResponse()->getStatusCode() === 401) {
                    $conn->access_token = null;
                    $conn->token_expires_at = null;
                    $conn->save();

                    $token = $this->getAccessToken($conn);
                    try {
                        $res = $this->requestWithRetry('POST', $url, [
                            'json' => $body,
                            'headers' => [
                                'Authorization' => 'Bearer '.$token,
                                'Accept' => 'application/json',
                                'Content-Type' => 'application/json',
                            ],
                        ]);
                    } catch (GuzzleException $retryException) {
                        Log::warning('Failed to fetch Shopware '.$logLabel.' after token refresh', ['error' => $retryException->getMessage()]);
                        return [];
                    }
                } else {
                    Log::warning('Failed to fetch Shopware '.$logLabel, ['error' => $e->getMessage()]);
                    return [];
                }
            }

            $payload = json_decode((string) $res->getBody(), true);
            $data = is_array($payload) && isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
            $total = is_array($payload) && isset($payload['total']) ? (int) $payload['total'] : 0;

            foreach ($data as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $name = trim((string) (data_get($item, 'translated.name') ?: data_get($item, 'name') ?: data_get($item, 'attributes.translated.name') ?: data_get($item, 'attributes.name') ?: ''));
                $technicalName = trim((string) (data_get($item, 'technicalName') ?: data_get($item, 'attributes.technicalName') ?: ''));
                $id = trim((string) (data_get($item, 'id') ?: ''));
                if ($name === '') {
                    continue;
                }

                $dedupeKey = mb_strtolower($name);
                if (isset($seen[$dedupeKey])) {
                    continue;
                }

                $seen[$dedupeKey] = true;
                $out[] = ['id' => $id, 'name' => $name, 'technicalName' => $technicalName];
            }

            if (count($data) < $limit || ($total > 0 && ($page * $limit) >= $total)) {
                break;
            }

            $page++;
        }

        return $out;
    }

    /**
     * Fetch a page of Shopware promotions with all required associations.
     * Uses a progressive fallback strategy: tries full associations first,
     * then reduced, then minimal — matching the product search pattern.
     *
     * @return array{promotions: array<int, mixed>, total: int}
     */
    public function fetchPromotions(ShopwareConnection $conn, int $perPage = 100, int $page = 1, array $filter = []): array
    {
        $token = $this->getAccessToken($conn);
        $url   = rtrim($conn->api_url, '/').'/api/search/promotion';

        $baseBody = [
            'limit' => $perPage,
            'page'  => $page,
        ];

        if (! empty($filter)) {
            $baseBody['filter'] = $filter;
        }

        // Progressive association candidates — most complete first, minimal last.
        // Shopware 6.7+ uses 'individualCodes' for promotion codes (not 'codes').
        // We try multiple naming conventions to handle different SW6 versions.
        $associationCandidates = [
            // Full: SW6.7+ naming with individualCodes + discounts + salesChannels + cartRules
            [
                'individualCodes' => [],
                'discounts'       => [],
                'salesChannels'   => [],
                'cartRules'       => [
                    'associations' => [
                        'conditions' => [],
                    ],
                ],
            ],
            // SW6.4-6.6 naming: codes + discounts + salesChannels + cartRules
            [
                'codes'         => [],
                'discounts'     => [],
                'salesChannels' => [],
                'cartRules'     => [
                    'associations' => [
                        'conditions' => [],
                    ],
                ],
            ],
            // Reduced: discounts only (most important for type mapping)
            [
                'discounts' => [],
            ],
            // Bare: no associations — last resort, promotions fetched without discount details
            [],
        ];

        $lastException = null;

        foreach ($associationCandidates as $index => $associations) {
            $body = empty($associations)
                ? $baseBody
                : array_merge($baseBody, ['associations' => $associations]);

            try {
                $res = $this->requestWithRetry('POST', $url, [
                    'json'    => $body,
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        'Accept'        => 'application/json',
                        'Content-Type'  => 'application/json',
                    ],
                ]);

                if ($index > 0) {
                    Log::warning('Shopware promotion search succeeded with fallback associations', [
                        'url'     => $url,
                        'attempt' => $index + 1,
                    ]);
                }

                $payload    = json_decode((string) $res->getBody(), true);
                $promotions = is_array($payload) && isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
                $total      = is_array($payload) && isset($payload['total']) ? (int) $payload['total'] : 0;

                return ['promotions' => $promotions, 'total' => $total];

            } catch (\Throwable $e) {
                // Catch both GuzzleException and any other throwable.
                // GuzzleException hierarchy: ServerException → BadResponseException
                //   → RequestException → TransferException → GuzzleException
                $lastException = $e instanceof \Exception ? $e : new \RuntimeException($e->getMessage(), 0, $e);
                $status        = null;
                $responseBody  = null;

                if ($e instanceof \App\Exceptions\ShopwareAssociationException) {
                    // ShopwareAssociationException carries the preserved response body so we
                    // don't need to re-read the already-consumed Guzzle stream.
                    $status       = $e->getHttpStatus();
                    $responseBody = $e->getResponseBody();
                } elseif (method_exists($e, 'getResponse') && $e->getResponse()) {
                    $status = $e->getResponse()->getStatusCode();
                    // The response body stream may have been consumed by requestWithRetry.
                    // Fall back to the exception message which Guzzle includes the body in.
                    try {
                        $rawBody = (string) $e->getResponse()->getBody();
                        if ($rawBody === '') {
                            $rawBody = $e->getMessage();
                        }
                    } catch (\Throwable) {
                        $rawBody = $e->getMessage();
                    }
                    $responseBody = $rawBody;
                } else {
                    $responseBody = $e->getMessage();
                }

                // Re-authenticate and retry the same association set once on 401
                if ($status === 401) {
                    $conn->access_token     = null;
                    $conn->token_expires_at = null;
                    $conn->save();
                    $token = $this->getAccessToken($conn);

                    try {
                        $res = $this->requestWithRetry('POST', $url, [
                            'json'    => $body,
                            'headers' => [
                                'Authorization' => 'Bearer '.$token,
                                'Accept'        => 'application/json',
                                'Content-Type'  => 'application/json',
                            ],
                        ]);

                        $payload    = json_decode((string) $res->getBody(), true);
                        $promotions = is_array($payload) && isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
                        $total      = is_array($payload) && isset($payload['total']) ? (int) $payload['total'] : 0;

                        return ['promotions' => $promotions, 'total' => $total];
                    } catch (\Throwable $retryEx) {
                        $lastException = $retryEx instanceof \Exception ? $retryEx : new \RuntimeException($retryEx->getMessage(), 0, $retryEx);
                        $status        = null;
                        $responseBody  = null;
                        if (method_exists($retryEx, 'getResponse') && $retryEx->getResponse()) {
                            $status = $retryEx->getResponse()->getStatusCode();
                            try {
                                $rawBody = (string) $retryEx->getResponse()->getBody();
                                $responseBody = $rawBody !== '' ? $rawBody : $retryEx->getMessage();
                            } catch (\Throwable) {
                                $responseBody = $retryEx->getMessage();
                            }
                        } else {
                            $responseBody = $retryEx->getMessage();
                        }
                    }
                }

                // If it's an association-not-found error, try the next (reduced) candidate
                if ($this->isAssociationNotFoundError($status, $responseBody)) {
                    $missing = $this->extractMissingAssociationName((string) $responseBody);
                    Log::warning('Shopware promotion search rejected association; trying next association set', [
                        'url'                 => $url,
                        'status'              => $status,
                        'missing_association' => $missing,
                        'attempt'             => $index + 1,
                    ]);
                    continue;
                }

                // Any other error — stop immediately
                throw $e;
            }
        }

        if ($lastException !== null) {
            throw $lastException;
        }

        return ['promotions' => [], 'total' => 0];
    }
}
