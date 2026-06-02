<?php

namespace App\Services\Migration;

use App\Models\Shop;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use Illuminate\Support\Facades\Cache;

class ShopifyProductSyncService
{
    private ShopifyAdminGraphqlClient $client;

    private const CUSTOM_ID_NAMESPACE = 'shopware';

    private const CUSTOM_ID_KEY = 'custom_id';

    public function __construct(ShopifyAdminGraphqlClient $client)
    {
        $this->client = $client;
    }

    /**
     * @return array{productGid?: string, variantIdByShopwareId?: array<string, string>, userErrors?: array<int, mixed>, errors?: mixed}
     */
    public function upsertByCustomId(Shop $shop, string $sourceId, array $productSetPayload): array
    {
        $ensure = $this->ensureShopwareIdMetafieldDefinition($shop);
        if (! empty($ensure['errors']) || ! empty($ensure['userErrors'])) {
            return $ensure;
        }
        $ensureProductDefs = $this->ensureCommonProductMetafieldDefinitions($shop);
        if (! empty($ensureProductDefs['errors']) || ! empty($ensureProductDefs['userErrors'])) {
            return $ensureProductDefs;
        }

        $mutation = <<<'GQL'
mutation UpsertProduct($input: ProductSetInput!, $identifier: ProductSetIdentifiers) {
  productSet(synchronous: true, input: $input, identifier: $identifier) {
    product {
      id
      title
      variants(first: 100) {
        nodes {
          id
          metafield(namespace: "shopware", key: "variant_id") {
            value
          }
        }
      }
    }
    userErrors {
      field
      message
    }
  }
}
GQL;

        $shopwareId = trim($sourceId);
        if ($shopwareId === '') {
            return ['userErrors' => [['message' => 'Missing Shopware sourceId for identifier']]];
        }

        $res = $this->client->query($shop, $mutation, [
            'input' => $productSetPayload,
            'identifier' => [
                'customId' => [
                    'namespace' => self::CUSTOM_ID_NAMESPACE,
                    'key' => self::CUSTOM_ID_KEY,
                    'value' => $shopwareId,
                ],
            ],
        ]);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.productSet.userErrors', []);
        if (is_array($userErrors) && count($userErrors) > 0) {
            return ['userErrors' => $userErrors];
        }

        $productId = data_get($res, 'data.productSet.product.id');
        if (is_string($productId) && $productId !== '') {
            return [
                'productGid' => $productId,
                'variantIdByShopwareId' => $this->variantMapFromProductSetResponse($res),
                'allVariantGids' => $this->allVariantGidsFromProductSetResponse($res),
            ];
        }

        return ['userErrors' => [['message' => 'Shopify productSet did not return a product id']]];
    }

    /**
     * @return array{ok?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    public function setupAndPinCommonProductMetafields(Shop $shop): array
    {
        return $this->ensureCommonProductMetafieldDefinitions($shop);
    }

    /**
     * One-time warmup so product workers don't block on first-wave definition setup.
     *
     * @return array{ok?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    public function warmupProductDefinitions(Shop $shop): array
    {
        $custom = $this->ensureShopwareIdMetafieldDefinition($shop);
        if (!empty($custom['errors']) || !empty($custom['userErrors'])) {
            return $custom;
        }

        return $this->ensureCommonProductMetafieldDefinitions($shop);
    }

    /**
     * Shopify requires the metafield definition to exist when using ProductSetIdentifiers.customId.
     *
     * @return array{ok?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function ensureShopwareIdMetafieldDefinition(Shop $shop): array
    {
        $cacheKey = 'shopify:product_custom_id_definition_ensured:'.$shop->id;
        if (Cache::get($cacheKey)) {
            return ['ok' => true];
        }

        $lock = Cache::lock('shopify:product_custom_id_definition_lock:'.$shop->id, 60);
        return $lock->block(20, function () use ($shop, $cacheKey) {
            if (Cache::get($cacheKey)) {
                return ['ok' => true];
            }

            $query = <<<'GQL'
query FindDef {
  metafieldDefinitions(first: 1, ownerType: PRODUCT, namespace: "shopware", key: "custom_id") {
    nodes { id name namespace key type { name } }
  }
}
GQL;

            $res = $this->client->query($shop, $query, []);
            if (isset($res['errors'])) {
                return ['errors' => $res['errors']];
            }

            $existingId = (string) data_get($res, 'data.metafieldDefinitions.nodes.0.id', '');
            if ($existingId !== '') {
                Cache::put($cacheKey, 1, now()->addDays(7));

                return ['ok' => true];
            }

            $mutation = <<<'GQL'
mutation CreateDef($definition: MetafieldDefinitionInput!) {
  metafieldDefinitionCreate(definition: $definition) {
    createdDefinition { id }
    userErrors { field message }
  }
}
GQL;

            $create = $this->client->query($shop, $mutation, [
                'definition' => [
                    'name' => 'Shopware Custom ID',
                    'namespace' => self::CUSTOM_ID_NAMESPACE,
                    'key' => self::CUSTOM_ID_KEY,
                    'ownerType' => 'PRODUCT',
                    'type' => 'single_line_text_field',
                    'pin' => true,
                ],
            ]);

            if (isset($create['errors'])) {
                return ['errors' => $create['errors']];
            }

            $userErrors = data_get($create, 'data.metafieldDefinitionCreate.userErrors', []);
            if (is_array($userErrors) && count($userErrors) > 0) {
                // "Key is in use" means the definition already exists — treat as success.
                $nonFatal = array_filter($userErrors, function ($e) {
                    $msg = strtolower((string) data_get($e, 'message', ''));
                    return str_contains($msg, 'key is in use') || str_contains($msg, 'already exists');
                });
                if (count($nonFatal) === count($userErrors)) {
                    Cache::put($cacheKey, 1, now()->addDays(7));
                    return ['ok' => true];
                }
                return ['userErrors' => $userErrors];
            }

            Cache::put($cacheKey, 1, now()->addDays(7));

            return ['ok' => true];
        });
    }

    /**
     * Ensure SEO support metafields are visible in Shopify Admin Metafields section.
     *
     * Uses a single batch query to find which definitions already exist, then only
     * creates the missing ones. The `pin: true` flag in the create input is sufficient
     * — no separate pin API calls are needed.
     *
     * @return array{ok?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function ensureCommonProductMetafieldDefinitions(Shop $shop): array
    {
        $cacheKey = 'shopify:product_common_metafields_ensured:'.$shop->id;
        if (Cache::get($cacheKey)) {
            return ['ok' => true];
        }

        $lock = Cache::lock('shopify:product_common_metafields_lock:'.$shop->id, 120);
        return $lock->block(30, function () use ($shop, $cacheKey) {
            // Double-check after acquiring lock — another worker may have finished first.
            if (Cache::get($cacheKey)) {
                return ['ok' => true];
            }

            $definitions = [
                ['name' => 'SEO Keywords',           'namespace' => 'shopware', 'key' => 'seo_keywords',         'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'SEO Source Path',         'namespace' => 'shopware', 'key' => 'seo_path_source',      'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Shopware Product ID',     'namespace' => 'shopware', 'key' => 'product_id',           'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Shopware Product Number', 'namespace' => 'shopware', 'key' => 'product_number',       'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Shopware Active',         'namespace' => 'shopware', 'key' => 'active',               'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Shopware Weight (kg)',    'namespace' => 'shopware', 'key' => 'weight_kg',            'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Spec Width',              'namespace' => 'shopware', 'key' => 'spec_width',           'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Spec Height',             'namespace' => 'shopware', 'key' => 'spec_height',          'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Spec Length',             'namespace' => 'shopware', 'key' => 'spec_length',          'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Spec Weight',             'namespace' => 'shopware', 'key' => 'spec_weight',          'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Spec Purchase Unit',      'namespace' => 'shopware', 'key' => 'spec_purchase_unit',   'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Spec Reference Unit',     'namespace' => 'shopware', 'key' => 'spec_reference_unit',  'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Spec Pack Unit',          'namespace' => 'shopware', 'key' => 'spec_pack_unit',       'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Spec Pack Unit Plural',   'namespace' => 'shopware', 'key' => 'spec_pack_unit_plural','ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Spec Unit',               'namespace' => 'shopware', 'key' => 'spec_unit',            'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Spec Properties',         'namespace' => 'shopware', 'key' => 'spec_properties',      'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Specification JSON',      'namespace' => 'shopware', 'key' => 'specification_json',   'ownerType' => 'PRODUCT', 'type' => 'json',                   'pin' => true],
                ['name' => 'Price Currency',          'namespace' => 'shopware', 'key' => 'price_currency',        'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Tax Rate',               'namespace' => 'shopware', 'key' => 'tax_rate',               'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Tax Name',               'namespace' => 'shopware', 'key' => 'tax_name',               'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Advanced Price Count',   'namespace' => 'shopware', 'key' => 'advanced_price_count',   'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
                ['name' => 'Advanced Prices JSON',   'namespace' => 'shopware', 'key' => 'advanced_prices_json',   'ownerType' => 'PRODUCT', 'type' => 'json',                   'pin' => true],
                ['name' => 'Price Mode',             'namespace' => 'shopware', 'key' => 'price_mode',             'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
            ];

            // --- Step 1: Fetch all existing shopware-namespace definitions in one API call ---
            $existingKeys = $this->fetchExistingProductMetafieldKeys($shop, 'shopware');
            if ($existingKeys === null) {
                // API error — fall back to attempting all creates (safe, "key is in use" is handled below)
                $existingKeys = [];
            }

            // --- Step 2: Only create definitions that don't exist yet ---
            $mutation = <<<'GQL'
mutation CreateDef($definition: MetafieldDefinitionInput!) {
  metafieldDefinitionCreate(definition: $definition) {
    createdDefinition { id }
    userErrors { field message }
  }
}
GQL;

            foreach ($definitions as $definition) {
                // Skip if already exists — pin: true in the create input handles pinning on creation
                if (in_array($definition['key'], $existingKeys, true)) {
                    continue;
                }

                $create = $this->client->query($shop, $mutation, [
                    'definition' => $definition,
                ]);

                if (isset($create['errors'])) {
                    return ['errors' => $create['errors']];
                }

                $userErrors = data_get($create, 'data.metafieldDefinitionCreate.userErrors', []);
                if (is_array($userErrors) && count($userErrors) > 0) {
                    $nonFatal = array_filter($userErrors, function ($e) {
                        $msg = strtolower((string) data_get($e, 'message', ''));
                        return str_contains($msg, 'key is in use') || str_contains($msg, 'already exists');
                    });
                    if (count($nonFatal) !== count($userErrors)) {
                        return ['userErrors' => $userErrors];
                    }
                    // "key is in use" — definition already exists, that's fine
                }
                // Note: pin: true in the definition input pins it on creation.
                // No separate pinProductMetafieldDefinition call needed.
            }

            Cache::put($cacheKey, 1, now()->addDays(7));
            return ['ok' => true];
        });
    }

    /**
     * Fetch all existing metafield definition keys for a given namespace and owner type
     * in a single paginated API call. Returns null on API error.
     *
     * @return array<int, string>|null
     */
    private function fetchExistingProductMetafieldKeys(Shop $shop, string $namespace): ?array
    {
        $query = <<<'GQL'
query ExistingDefs($namespace: String!) {
  metafieldDefinitions(first: 50, ownerType: PRODUCT, namespace: $namespace) {
    nodes { key }
  }
}
GQL;

        $res = $this->client->query($shop, $query, ['namespace' => $namespace]);
        if (isset($res['errors'])) {
            return null;
        }

        $nodes = data_get($res, 'data.metafieldDefinitions.nodes', []);
        if (!is_array($nodes)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($n) => is_array($n) ? (string) ($n['key'] ?? '') : '',
            $nodes
        ), fn ($k) => $k !== ''));
    }

    /**
     * @param  array<string, mixed>  $res
     * @return array<string, string>
     */
    private function variantMapFromProductSetResponse(array $res): array
    {
        $nodes = data_get($res, 'data.productSet.product.variants.nodes', []);
        $nodes = is_array($nodes) ? $nodes : [];

        $map = [];
        foreach ($nodes as $n) {
            $variantId = (string) data_get($n, 'id', '');
            $swId = (string) data_get($n, 'metafield.value', '');
            if ($variantId !== '' && $swId !== '') {
                $map[$swId] = $variantId;
            }
        }

        return $map;
    }

    /**
     * Return all variant GIDs from the productSet response (used for simple products
     * that have no Shopware variant ID metafield but still need price list sync).
     *
     * @param  array<string, mixed>  $res
     * @return array<int, string>
     */
    private function allVariantGidsFromProductSetResponse(array $res): array
    {
        $nodes = data_get($res, 'data.productSet.product.variants.nodes', []);
        $nodes = is_array($nodes) ? $nodes : [];

        $gids = [];
        foreach ($nodes as $n) {
            $variantId = (string) data_get($n, 'id', '');
            if ($variantId !== '') {
                $gids[] = $variantId;
            }
        }

        return $gids;
    }

    /**
     * @param  array<int, array{namespace: string, key: string, type: string, value: string}>  $metafields
     * @return array{ok?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    public function setProductMetafields(Shop $shop, string $productGid, array $metafields): array
    {
        $mutation = <<<'GQL'
mutation SetMetafields($metafields: [MetafieldsSetInput!]!) {
  metafieldsSet(metafields: $metafields) {
    userErrors { field message }
    metafields { id namespace key }
  }
}
GQL;

        $inputs = [];
        foreach ($metafields as $m) {
            if (! is_array($m)) {
                continue;
            }

            $ns = (string) ($m['namespace'] ?? '');
            $key = (string) ($m['key'] ?? '');
            $type = (string) ($m['type'] ?? '');
            $value = $m['value'] ?? null;

            if ($ns === '' || $key === '' || $type === '' || $value === null) {
                continue;
            }

            $inputs[] = [
                'ownerId' => $productGid,
                'namespace' => $ns,
                'key' => $key,
                'type' => $type,
                'value' => is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }

        if (count($inputs) === 0) {
            return ['ok' => true];
        }

        $res = $this->client->query($shop, $mutation, [
            'metafields' => $inputs,
        ]);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.metafieldsSet.userErrors', []);
        $userErrors = is_array($userErrors) ? $userErrors : [];
        if (count($userErrors) > 0) {
            return ['userErrors' => $userErrors];
        }

        return ['ok' => true];
    }

    /**
     * Set variant-specific metafields (e.g., variant digital file links).
     * Requires mapping of Shopware variant IDs to Shopify variant GIDs.
     *
     * @param Shop $shop
     * @param array<string, string> $variantIdByShopwareId Map of Shopware variant ID => Shopify variant GID
     * @param array<int, array{namespace: string, key: string, type: string, value: string}> $metafields
     * @return array{ok?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    public function setVariantMetafields(Shop $shop, array $variantIdByShopwareId, array $metafields): array
    {
        if (count($variantIdByShopwareId) === 0 || count($metafields) === 0) {
            return ['ok' => true];
        }

        $mutation = <<<'GQL'
mutation SetMetafields($metafields: [MetafieldsSetInput!]!) {
  metafieldsSet(metafields: $metafields) {
    userErrors { field message }
    metafields { id namespace key }
  }
}
GQL;

        $inputs = [];

        // Group metafields by variant prefix to associate them with variant GIDs
        $metafieldsByVariantPrefix = [];
        foreach ($metafields as $m) {
            if (!is_array($m)) {
                continue;
            }

            $key = (string) ($m['key'] ?? '');
            // Extract variant prefix from key (e.g., "variant_digital_12345678_file_1" -> "variant_digital_12345678")
            if (str_starts_with($key, 'variant_digital_')) {
                $parts = explode('_', $key);
                // Format: variant_digital_<8_char_id>_file_<n> or variant_digital_<8_char_id>_files_metadata
                if (count($parts) >= 3) {
                    $variantPrefix = $parts[0] . '_' . $parts[1] . '_' . $parts[2];  // "variant_digital_<id>"
                    $metafieldsByVariantPrefix[$variantPrefix][] = $m;
                }
            }
        }

        // Map variant prefixes to Shopify GIDs
        foreach ($metafieldsByVariantPrefix as $variantPrefix => $variantMetafields) {
            // Find matching Shopware variant ID that starts with this prefix
            $matchedVariantGid = null;
            foreach ($variantIdByShopwareId as $swVariantId => $shopifyGid) {
                // Extract 8-char prefix from Shopware ID
                if (str_starts_with($swVariantId, substr($variantPrefix, 17))) {  // 17 = strlen("variant_digital_")
                    $matchedVariantGid = $shopifyGid;
                    break;
                }
            }

            if ($matchedVariantGid === null) {
                continue;  // Variant not found, skip
            }

            // Add metafields with matched variant GID
            foreach ($variantMetafields as $m) {
                $ns = (string) ($m['namespace'] ?? '');
                $key = (string) ($m['key'] ?? '');
                $type = (string) ($m['type'] ?? '');
                $value = $m['value'] ?? null;

                if ($ns === '' || $key === '' || $type === '' || $value === null) {
                    continue;
                }

                $inputs[] = [
                    'ownerId' => $matchedVariantGid,
                    'namespace' => $ns,
                    'key' => $key,
                    'type' => $type,
                    'value' => is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        if (count($inputs) === 0) {
            return ['ok' => true];
        }

        $res = $this->client->query($shop, $mutation, [
            'metafields' => $inputs,
        ]);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.metafieldsSet.userErrors', []);
        $userErrors = is_array($userErrors) ? $userErrors : [];
        if (count($userErrors) > 0) {
            return ['userErrors' => $userErrors];
        }

        return ['ok' => true];
    }
}
