<?php

namespace App\Services\Migration;

use App\Models\Shop;
use App\Models\ShopifyIdMapping;
use App\Services\Shopware\ShopwareClient;
use Illuminate\Support\Str;

class ProductPayloadMapper
{
    /**
     * Map a Shopware parent product with its variants to a Shopify ProductSet payload.
     * Uses the Shopware product's currency (same as orders).
     *
     * @param array<string, mixed> $parent The Shopware parent product
     * @param array<int, array<string, mixed>> $children The Shopware variant children
     * @param string $locationGid The Shopify location GID for inventory
     * @param int|null $shopId The Shopify shop ID (for vendor resolution)
     * @param string $priceMode "gross" or "net" — controls which Shopware price field is used
     * @return array<string, mixed>
     */
    public function mapParentWithVariants(array $parent, array $children, string $locationGid, ?int $shopId = null, string $priceMode = 'gross'): array
    {
        $title = $this->normalizeText(data_get($parent, 'translated.name'))
            ?: $this->normalizeText(data_get($parent, 'name'))
            ?: $this->normalizeText(data_get($parent, 'productNumber'))
            ?: 'Untitled';

        $descriptionHtml = $this->normalizeText(data_get($parent, 'translated.description'))
            ?: $this->normalizeText(data_get($parent, 'description'));

        $seoTitle = $this->normalizeText(data_get($parent, 'translated.metaTitle'))
            ?: $this->normalizeText(data_get($parent, 'metaTitle'));
        $seoDescription = $this->normalizeText(data_get($parent, 'translated.metaDescription'))
            ?: $this->normalizeText(data_get($parent, 'metaDescription'));
        $seoFields = $this->mapSeoFields($parent);
        $seoPath = $seoFields['seo_path'];
        $handle = $seoFields['handle'];

        $vendor = $this->resolveVendor($parent, $shopId);

        $tags = $this->buildTagsFromShopware($parent);

        $productType = '';
        $firstCategory = data_get($parent, 'categories.0');
        if (is_array($firstCategory)) {
            $productType = $this->normalizeText(data_get($firstCategory, 'translated.name')) ?: $this->normalizeText(data_get($firstCategory, 'name'));
        }

        $productOptions = $this->buildProductOptions($parent, $children);

        $variants = $this->buildVariants($parent, $children, $productOptions, $locationGid, $priceMode);

        $productSet = [
            'title' => $title,
            'descriptionHtml' => $descriptionHtml,
            'vendor' => $vendor,
            'tags' => $tags,
            'status' => $this->mapProductStatus($parent),
            'productType' => $productType,
            'handle' => $handle,
            'productOptions' => $productOptions,
            'variants' => $variants,
        ];

        if ($seoTitle !== '' || $seoDescription !== '') {
            $productSet['seo'] = $this->removeEmpty([
                'title' => $seoTitle,
                'description' => $seoDescription,
            ]);
        }

        return $productSet;
    }

    /**
     * @return array{seo_path: string, handle: string, seo_keywords: string}
     */
    public function mapSeoFields(array $parent): array
    {
        $title = $this->normalizeText(data_get($parent, 'translated.name'))
            ?: $this->normalizeText(data_get($parent, 'name'))
            ?: $this->normalizeText(data_get($parent, 'productNumber'))
            ?: 'Untitled';

        $seoPath = $this->extractSeoPath($parent);
        $handle = $this->toShopifyHandle($seoPath !== '' ? $seoPath : $title);
        $seoKeywords = $this->extractSeoKeywords($parent);

        return [
            'seo_path' => $seoPath,
            'handle' => $handle,
            'seo_keywords' => $seoKeywords,
        ];
    }

    /**
     * @param  array<string, mixed>  $parent
     */
    private function resolveVendor(array $parent, ?int $shopId): string
    {
        $manufacturerId = trim((string) data_get($parent, 'manufacturerId', ''));
        if ($shopId !== null && $manufacturerId !== '') {
            $mapped = ShopifyIdMapping::query()
                ->where('shop_id', $shopId)
                ->where('entity_type', 'manufacturer')
                ->where('source_id', $manufacturerId)
                ->value('shopify_gid');
            if (is_string($mapped) && trim($mapped) !== '') {
                return trim($mapped);
            }
        }

        $vendor = $this->normalizeText(data_get($parent, 'manufacturer.name'));
        if ($vendor === '') {
            $vendor = 'Unknown';
        }

        return $vendor;
    }

    private function buildVariants(array $parent, array $children, array $productOptions, string $locationGid, string $priceMode = 'gross'): array
    {
        $optionNames = array_map(fn ($o) => $o['name'], $productOptions);

        $variants = [];
        $seen = [];

        if (count($children) === 0) {
            $v = $this->variantFromShopware($parent, $optionNames, $locationGid, $parent, $priceMode);
            $sig = $this->variantSignature($v);
            if (!isset($seen[$sig])) {
                $seen[$sig] = true;
                $variants[] = $v;
            }
            return $variants;
        }

        foreach ($children as $child) {
            $v = $this->variantFromShopware($child, $optionNames, $locationGid, $parent, $priceMode);
            $sig = $this->variantSignature($v);
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            $variants[] = $v;
        }

        return $variants;
    }

    private function variantSignature(array $variantPayload): string
    {
        $parts = [];

        $opt = $variantPayload['optionValues'] ?? [];
        $pairs = [];
        if (is_array($opt)) {
            foreach ($opt as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $n = $this->normalizeText($row['optionName'] ?? '');
                $v = $this->normalizeText($row['name'] ?? '');
                if ($n === '' && $v === '') {
                    continue;
                }
                $pairs[] = [$n, $v];
            }
        }

        if (count($pairs) > 0) {
            usort($pairs, function ($a, $b) {
                return strcmp((string) $a[0], (string) $b[0]);
            });

            foreach ($pairs as [$n, $v]) {
                $parts[] = 'opt:'.$n.'='.$v;
            }

            return implode('|', $parts);
        }

        $sku = $this->normalizeText($variantPayload['sku'] ?? '');
        if ($sku !== '') {
            return 'sku:'.$sku;
        }

        return md5(json_encode($variantPayload));
    }

    private function variantFromShopware(array $variant, array $optionOrder, string $locationGid, array $fallbackParent, string $priceMode = 'gross'): array
    {
        $price = $this->moneyToPrice($variant, $fallbackParent, $priceMode);
        $compareAt = $this->moneyToCompareAtPrice($variant, $fallbackParent, $priceMode);

        // Enforce: compareAtPrice must be greater than price
        if ($compareAt !== null && (float) $compareAt <= (float) $price) {
            $compareAt = null;
        }

        $inventoryQty = $this->numericInt(data_get($variant, 'stock'));

        $shippingFree = data_get($variant, 'shippingFree');
        if ($shippingFree === null) {
            $shippingFree = data_get($fallbackParent, 'shippingFree');
        }

        $closeout = data_get($variant, 'isCloseout');
        if ($closeout === null) {
            $closeout = data_get($fallbackParent, 'isCloseout');
        }
        $inventoryPolicy = ($closeout === false) ? 'CONTINUE' : 'DENY';

        // Tax: use TaxMapper to determine taxable status from Shopware tax data
        $taxMapper = app(TaxMapper::class);
        $taxable = $taxMapper->isTaxable($variant, $fallbackParent);

        $optionValues = [];
        $pairs = $this->deriveVariantOptionPairs($variant);
        $byName = [];
        foreach ($pairs as $p) {
            $byName[$p['name']] = $p['value'];
        }

        foreach (array_slice($optionOrder, 0, 3) as $name) {
            $optionValues[] = [
                'optionName' => $name,
                'name' => $byName[$name] ?? 'Default',
            ];
        }

        $payload = [
            'price' => $price,
            'sku' => $this->normalizeText(data_get($variant, 'productNumber')),
            'barcode' => $this->normalizeText(data_get($variant, 'ean')),
            'taxable' => $taxable,
            'inventoryPolicy' => $inventoryPolicy,
            'optionValues' => $optionValues,
            'inventoryQuantities' => [
                [
                    'locationId' => $locationGid,
                    'name' => 'available',
                    'quantity' => $inventoryQty,
                ],
            ],
        ];

        if ($compareAt !== null) {
            $payload['compareAtPrice'] = $compareAt;
        }

        // Purchase cost → inventoryItem.cost
        $purchaseCost = $this->resolvePurchaseCost($variant, $fallbackParent);
        if ($purchaseCost !== null) {
            $payload['inventoryItem'] = ['cost' => $purchaseCost];
        }

        // Unit price measurement → Shopify "Unit price" (Additional display prices)
        // Maps Shopware referenceUnit + purchaseUnit + unit to Shopify unitPriceMeasurement
        $unitMeasurement = $this->resolveUnitPriceMeasurement($variant, $fallbackParent);
        if ($unitMeasurement !== null) {
            $payload['unitPriceMeasurement'] = $unitMeasurement;
        }

        return $this->removeEmpty($payload);
    }

    /**
     * Resolve purchase cost from variant or fallback parent.
     * Returns formatted string (e.g. "15.00") or null if not available/valid.
     */
    private function resolvePurchaseCost(array $variant, array $fallbackParent): ?string
    {
        $cost = data_get($variant, 'purchasePrice');
        if ($cost === null) {
            $cost = data_get($fallbackParent, 'purchasePrice');
        }
        if (!is_numeric($cost) || (float) $cost <= 0) {
            return null;
        }
        return number_format((float) $cost, 2, '.', '');
    }

    /**
     * Resolve Shopify unitPriceMeasurement from Shopware unit/referenceUnit/purchaseUnit fields.
     *
     * Shopify UnitPriceMeasurementInput:
     *   - referenceUnit: enum (ML, CL, L, M3, FLOZ, PT, QT, GAL, MG, G, KG, OZ, LB, MM, CM, M, IN, FT, YD, M2, FT2, ITEM, UNKNOWN)
     *   - quantityUnit:  enum (same list)
     *
     * Shopware fields:
     *   - unit.shortCode: the unit symbol (e.g. "ml", "kg") → maps to Shopify enum
     *   - referenceUnit:  numeric denominator (e.g. 100 for "per 100ml") — not used in enum field
     *   - purchaseUnit:   numeric quantity sold — not used in enum field
     *
     * We only set unitPriceMeasurement when the unit shortCode maps to a known Shopify enum.
     *
     * @return array{referenceUnit: string, quantityUnit: string}|null
     */
    private function resolveUnitPriceMeasurement(array $variant, array $fallbackParent): ?array
    {
        $unitCode = $this->normalizeText(
            data_get($variant, 'unit.shortCode')
            ?: data_get($fallbackParent, 'unit.shortCode')
            ?: data_get($variant, 'unit.symbol')
            ?: data_get($fallbackParent, 'unit.symbol')
            ?: ''
        );

        if ($unitCode === '') {
            return null;
        }

        $shopifyUnit = $this->unitCodeToShopifyEnum($unitCode);
        if ($shopifyUnit === null) {
            return null;
        }

        return [
            'referenceUnit' => $shopifyUnit,
            'quantityUnit'  => $shopifyUnit,
        ];
    }

    /**
     * Map a Shopware unit shortCode to a Shopify UnitPriceMeasurement enum value.
     * Returns null if the unit cannot be mapped to a known Shopify enum.
     *
     * Valid Shopify enums: ML, CL, L, M3, FLOZ, PT, QT, GAL, MG, G, KG, OZ, LB, MM, CM, M, IN, FT, YD, M2, FT2, ITEM, UNKNOWN
     */
    private function unitCodeToShopifyEnum(string $unitCode): ?string
    {
        $map = [
            // Volume
            'ml'   => 'ML',  'milliliter' => 'ML',  'millilitre' => 'ML',
            'cl'   => 'CL',  'centiliter' => 'CL',  'centilitre' => 'CL',
            'l'    => 'L',   'liter' => 'L',         'litre' => 'L',
            'm3'   => 'M3',  'm³' => 'M3',
            'floz' => 'FLOZ', 'fl oz' => 'FLOZ', 'fl. oz' => 'FLOZ',
            'pt'   => 'PT',  'pint' => 'PT',
            'qt'   => 'QT',  'quart' => 'QT',
            'gal'  => 'GAL', 'gallon' => 'GAL',
            // Weight
            'mg'   => 'MG',  'milligram' => 'MG',   'milligramm' => 'MG',
            'g'    => 'G',   'gram' => 'G',          'gramm' => 'G',
            'kg'   => 'KG',  'kilogram' => 'KG',     'kilogramm' => 'KG',
            'oz'   => 'OZ',  'ounce' => 'OZ',
            'lb'   => 'LB',  'lbs' => 'LB',          'pound' => 'LB',
            // Length
            'mm'   => 'MM',  'millimeter' => 'MM',   'millimetre' => 'MM',
            'cm'   => 'CM',  'centimeter' => 'CM',   'centimetre' => 'CM',
            'm'    => 'M',   'meter' => 'M',          'metre' => 'M',
            'in'   => 'IN',  'inch' => 'IN',          '"' => 'IN',
            'ft'   => 'FT',  'foot' => 'FT',          'feet' => 'FT',
            'yd'   => 'YD',  'yard' => 'YD',
            // Area
            'm2'   => 'M2',  'm²' => 'M2',
            'ft2'  => 'FT2', 'ft²' => 'FT2',
            // Other
            'item' => 'ITEM', 'stk' => 'ITEM', 'pcs' => 'ITEM', 'piece' => 'ITEM', 'stück' => 'ITEM',
        ];

        $key = strtolower(trim($unitCode));
        return $map[$key] ?? null;
    }

    /**
     * @return array<int, array{namespace: string, key: string, type: string, value: string}>
     */
    public function mapShopwareMetafields(array $parent, array $children = [], ?Shop $shop = null, string $priceMode = 'gross'): array
    {
        $out = [];

        $this->pushProductMetafield($out, 'product_id', (string) data_get($parent, 'id', ''));
        $this->pushProductMetafield($out, 'product_number', (string) data_get($parent, 'productNumber', ''));

        if (array_key_exists('active', $parent)) {
            $this->pushProductMetafield($out, 'active', (bool) $parent['active'] ? 'true' : 'false');
        }

        $seoKeywords = $this->extractSeoKeywords($parent);
        if ($seoKeywords !== '') {
            $this->pushProductMetafield($out, 'seo_keywords', $seoKeywords);
        }
        $seoPath = $this->extractSeoPath($parent);
        if ($seoPath !== '') {
            $this->pushProductMetafield($out, 'seo_path_source', $seoPath);
        }

        $weight = data_get($parent, 'weight');
        if (is_numeric($weight) && (float) $weight > 0) {
            $this->pushProductMetafield($out, 'weight_kg', (string) (float) $weight);
        }

        // Store the Shopware price currency as a metafield so it's visible in Shopify
        $priceCurrency = $this->resolvePriceCurrency($shop, $parent);
        if ($priceCurrency !== '') {
            $this->pushProductMetafield($out, 'price_currency', $priceCurrency);
        }

        // Tax metafields — store rate as percentage string e.g. "19%"
        $taxMapper = app(TaxMapper::class);
        $taxRate = $taxMapper->taxRate($parent);
        if ($taxRate !== null) {
            // Format as percentage: "19%" not "19"
            $taxRateStr = rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.') . '%';
            $this->pushProductMetafield($out, 'tax_rate', $taxRateStr);
        }
        $taxName = $taxMapper->taxName($parent);
        if ($taxName !== '') {
            $this->pushProductMetafield($out, 'tax_name', $taxName);
        }

        // Advanced price metafields
        $advancedPrices = data_get($parent, 'prices', []);
        $advancedPrices = is_array($advancedPrices) ? $advancedPrices : [];
        $this->pushProductMetafield($out, 'advanced_price_count', (string) count($advancedPrices));
        if (count($advancedPrices) > 0) {
            $summary = array_map(fn ($p) => [
                'ruleId'        => $p['ruleId'] ?? null,
                'quantityStart' => $p['quantityStart'] ?? null,
                'quantityEnd'   => $p['quantityEnd'] ?? null,
                'gross'         => $p['gross'] ?? null,
                'net'           => $p['net'] ?? null,
            ], $advancedPrices);
            $json = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($json) && strlen($json) <= 5000) {
                $this->pushProductMetafield($out, 'advanced_prices_json', $json, 'json');
            }
        }

        // Price mode used during migration
        $this->pushProductMetafield($out, 'price_mode', $priceMode);

        // Digital product media files (separate for product and each variant)
        $productFileUrls = $this->extractDigitalDownloadUrls($parent);
        if (count($productFileUrls) > 0) {
            $digitalMfs = $this->generateDigitalFileMetafields($productFileUrls, 'digital');
            $out = array_merge($out, $digitalMfs);
        }

        // Variant-level digital files (store separately for each variant)
        if (is_array($children) && count($children) > 0) {
            foreach ($children as $variantIndex => $variant) {
                if (!is_array($variant)) {
                    continue;
                }

                $variantFileUrls = $this->extractDigitalDownloadUrls($variant);
                if (count($variantFileUrls) > 0) {
                    $variantId = (string) ($variant['id'] ?? '');
                    if ($variantId !== '') {
                        // Use variant ID to distinguish between variants
                        $prefix = 'variant_digital_' . substr($variantId, 0, 8);  // Shorten ID for readability
                        $variantMfs = $this->generateDigitalFileMetafields($variantFileUrls, $prefix);
                        $out = array_merge($out, $variantMfs);
                    }
                }
            }
        }

        $this->appendSpecificationMetafields($out, $parent);

        $customFields = data_get($parent, 'customFields');
        if (is_array($customFields)) {
            $this->appendCustomFieldsMetafields($out, $customFields, 'product');
        }

        return $out;
    }

    /**
     * Extract variant prices and their Shopware currency for price list sync.
     * Returns ['variantPrices' => [variantGid => amount], 'variantComparePrices' => [...], 'currency' => 'GBP']
     *
     * @param array<string, string> $variantIdByShopwareId  Map of shopwareVariantId => shopifyVariantGid
     * @param array<int, string>    $allVariantGids         All variant GIDs (fallback for simple products)
     * @param array<string, mixed>  $parent
     * @param array<int, array<string, mixed>> $children
     * @param Shop|null $shop
     * @return array{currency: string, variantPrices: array<string, string>, variantComparePrices: array<string, string|null>}
     */
    public function extractVariantPricesForPriceList(
        array $variantIdByShopwareId,
        array $parent,
        array $children,
        ?Shop $shop = null,
        array $allVariantGids = [],
        string $priceMode = 'gross'
    ): array {
        $currency = $this->resolvePriceCurrency($shop, $parent);

        $variantPrices = [];
        $variantComparePrices = [];

        if (count($children) === 0) {
            $price = $this->moneyToPrice($parent, $parent, $priceMode);
            $compareAt = $this->moneyToCompareAtPrice($parent, $parent, $priceMode);
            if ($compareAt !== null && (float) $compareAt <= (float) $price) {
                $compareAt = null;
            }

            $gids = count($variantIdByShopwareId) > 0
                ? array_values($variantIdByShopwareId)
                : $allVariantGids;

            foreach ($gids as $variantGid) {
                if (is_string($variantGid) && $variantGid !== '') {
                    $variantPrices[$variantGid] = $price;
                    $variantComparePrices[$variantGid] = $compareAt;
                }
            }
        } else {
            foreach ($children as $child) {
                $swId = (string) ($child['id'] ?? '');
                if ($swId === '') {
                    continue;
                }
                $variantGid = $variantIdByShopwareId[$swId] ?? null;
                if (!is_string($variantGid) || $variantGid === '') {
                    continue;
                }
                $price = $this->moneyToPrice($child, $parent, $priceMode);
                $compareAt = $this->moneyToCompareAtPrice($child, $parent, $priceMode);
                if ($compareAt !== null && (float) $compareAt <= (float) $price) {
                    $compareAt = null;
                }
                $variantPrices[$variantGid] = $price;
                $variantComparePrices[$variantGid] = $compareAt;
            }
        }

        return [
            'currency' => $currency,
            'variantPrices' => $variantPrices,
            'variantComparePrices' => $variantComparePrices,
        ];
    }

    /**
     * Resolve the Shopware product's price currency ISO code.
     * Reads currencyId from price[0] and resolves it via ShopwareClient (same as OrderPayloadMapper).
     *
     * @param Shop|null $shop
     * @param array<string, mixed> $product
     * @return string ISO code (e.g. "GBP") or empty string if not resolvable
     */
    public function resolvePriceCurrency(?Shop $shop, array $product): string
    {
        // Try inline isoCode first (Shopware sometimes embeds it)
        $inline = (string) (
            data_get($product, 'price.0.currencyIsoCode')
            ?: data_get($product, 'price.0.currency.isoCode')
            ?: data_get($product, 'prices.0.currency.isoCode')
            ?: ''
        );
        $inline = strtoupper(trim($inline));
        if ($inline !== '' && preg_match('/^[A-Z]{3}$/', $inline) === 1) {
            return $inline;
        }

        // Resolve via currencyId (same pattern as OrderPayloadMapper::resolveCurrencyCode)
        $currencyId = (string) (
            data_get($product, 'price.0.currencyId')
            ?: data_get($product, 'prices.0.currencyId')
            ?: ''
        );
        if ($currencyId !== '' && $shop && $shop->shopwareConnection) {
            $shopware = app(ShopwareClient::class);
            $resolved = $shopware->resolveCurrencyIsoCode($shop->shopwareConnection, $currencyId);
            if (is_string($resolved) && $resolved !== '' && preg_match('/^[A-Z]{3}$/', $resolved) === 1) {
                return $resolved;
            }
        }

        return '';
    }

    /**
     * @param array<int, array{namespace: string, key: string, type: string, value: string}> $out
     */
    private function appendSpecificationMetafields(array &$out, array $parent): void
    {
        $spec = [
            'dimensions' => [
                'width' => $this->numericOrNull(data_get($parent, 'width')),
                'height' => $this->numericOrNull(data_get($parent, 'height')),
                'length' => $this->numericOrNull(data_get($parent, 'length')),
                'weight' => $this->numericOrNull(data_get($parent, 'weight')),
            ],
            'selling_packaging' => [
                'purchase_unit' => $this->numericOrNull(data_get($parent, 'purchaseUnit')),
                'reference_unit' => $this->numericOrNull(data_get($parent, 'referenceUnit')),
                'pack_unit' => $this->normalizeText(data_get($parent, 'packUnit')),
                'pack_unit_plural' => $this->normalizeText(data_get($parent, 'packUnitPlural')),
                'unit' => $this->normalizeText(data_get($parent, 'unit.translated.name'))
                    ?: $this->normalizeText(data_get($parent, 'unit.name'))
                    ?: $this->normalizeText(data_get($parent, 'unit.shortCode')),
            ],
            'properties' => $this->extractProperties($parent),
            'feature_set' => [
                'id' => $this->normalizeText((string) data_get($parent, 'featureSetId')),
                'name' => $this->normalizeText(data_get($parent, 'featureSet.name'))
                    ?: $this->normalizeText(data_get($parent, 'featureSet.translated.name')),
            ],
        ];

        $this->pushProductMetafieldIfValue($out, 'spec_width', $spec['dimensions']['width']);
        $this->pushProductMetafieldIfValue($out, 'spec_height', $spec['dimensions']['height']);
        $this->pushProductMetafieldIfValue($out, 'spec_length', $spec['dimensions']['length']);
        $this->pushProductMetafieldIfValue($out, 'spec_weight', $spec['dimensions']['weight']);
        $this->pushProductMetafieldIfValue($out, 'spec_purchase_unit', $spec['selling_packaging']['purchase_unit']);
        $this->pushProductMetafieldIfValue($out, 'spec_reference_unit', $spec['selling_packaging']['reference_unit']);
        $this->pushProductMetafieldIfValue($out, 'spec_pack_unit', $spec['selling_packaging']['pack_unit']);
        $this->pushProductMetafieldIfValue($out, 'spec_pack_unit_plural', $spec['selling_packaging']['pack_unit_plural']);
        $this->pushProductMetafieldIfValue($out, 'spec_unit', $spec['selling_packaging']['unit']);

        if (count($spec['properties']) > 0) {
            $flat = [];
            foreach ($spec['properties'] as $p) {
                $name = $this->normalizeText((string) ($p['name'] ?? ''));
                $value = $this->normalizeText((string) ($p['value'] ?? ''));
                if ($name !== '' && $value !== '') {
                    $flat[] = $name.': '.$value;
                }
            }
            if (count($flat) > 0) {
                $this->pushProductMetafield($out, 'spec_properties', implode(' | ', $flat));
            }
        }

        $specJson = json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($specJson) && strlen($specJson) <= 5000) {
            $this->pushProductMetafield($out, 'specification_json', $specJson, 'json');
        }
    }

    /**
     * @return array<int, array{name: string, value: string}>
     */
    private function extractProperties(array $parent): array
    {
        $out = [];
        $properties = data_get($parent, 'properties', []);
        if (!is_array($properties)) {
            return $out;
        }

        foreach ($properties as $p) {
            if (!is_array($p)) {
                continue;
            }
            $group = $this->normalizeText(data_get($p, 'group.translated.name'))
                ?: $this->normalizeText(data_get($p, 'group.name'));
            $value = $this->normalizeText(data_get($p, 'translated.name'))
                ?: $this->normalizeText(data_get($p, 'name'));
            if ($group === '' || $value === '') {
                continue;
            }
            $out[] = ['name' => $group, 'value' => $value];
        }

        return $out;
    }

    private function numericOrNull($v): ?string
    {
        if (!is_numeric($v)) {
            return null;
        }
        $num = (float) $v;
        return (string) $num;
    }

    /**
     * @param array<int, array{namespace: string, key: string, type: string, value: string}> $out
     */
    private function pushProductMetafieldIfValue(array &$out, string $key, $value): void
    {
        if ($value === null) {
            return;
        }
        $str = trim((string) $value);
        if ($str === '') {
            return;
        }
        $this->pushProductMetafield($out, $key, $str);
    }

    private function mapProductStatus(array $parent): string
    {
        $stateName = strtolower(trim((string) (
            data_get($parent, 'state.technicalName')
            ?: data_get($parent, 'extensions.state.technicalName')
            ?: data_get($parent, 'customFields.migration_status')
            ?: ''
        )));

        if (in_array($stateName, ['archived', 'inactive', 'deleted'], true)) {
            return 'ARCHIVED';
        }

        if (!array_key_exists('active', $parent)) {
            return 'ACTIVE';
        }

        if (! (bool) $parent['active']) {
            return 'DRAFT';
        }

        $available = data_get($parent, 'available');
        if ($available === false) {
            return 'ARCHIVED';
        }

        return 'ACTIVE';
    }

    /**
     * @param array<int, array{namespace: string, key: string, type: string, value: string}> $out
     */
    private function appendCustomFieldsMetafields(array &$out, array $customFields, string $prefix): void
    {
        $count = 0;
        foreach ($customFields as $key => $value) {
            if ($count >= 20) {
                break;
            }
            if (!is_string($key) || trim($key) === '') {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $stringValue = $value === null ? '' : trim((string) $value);
                if ($stringValue !== '') {
                    $this->pushProductMetafield($out, $prefix.'_'.preg_replace('/[^a-z0-9_]/i', '_', $key), $stringValue);
                    $count++;
                }
                continue;
            }
            if (is_array($value)) {
                $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($json) && strlen($json) <= 5000) {
                    $this->pushProductMetafield($out, $prefix.'_'.preg_replace('/[^a-z0-9_]/i', '_', $key), $json, 'json');
                    $count++;
                }
            }
        }
    }

    /**
     * @param array<int, array{namespace: string, key: string, type: string, value: string}> $out
     */
    private function pushProductMetafield(array &$out, string $key, string $value, string $type = 'single_line_text_field'): void
    {
        $key = strtolower(trim(preg_replace('/[^a-z0-9_]/i', '_', $key) ?? '', '_'));
        $value = trim($value);
        if ($key === '' || $value === '') {
            return;
        }

        $out[] = [
            'namespace' => 'shopware',
            'key' => $key,
            'type' => $type,
            'value' => $value,
        ];
    }

    private function buildProductOptions(array $parent, array $children): array
    {
        $defs = $this->buildOptionDefinitionsFromVariants($children);

        if (count($defs) === 0) {
            $defs = $this->deriveOptionsFromConfigurator($parent);
        }

        $defs = array_slice($defs, 0, 3);
        $options = [];
        $pos = 1;

        foreach ($defs as $def) {
            $name = $this->normalizeText($def['name'] ?? '');
            if ($name === '') {
                $name = 'Option';
            }

            $values = $def['values'] ?? [];
            $values = is_array($values) ? $values : [];
            $values = array_values(array_filter(array_map(fn ($v) => $this->normalizeText($v), $values)));

            if (count($values) === 0) {
                $values = ['Default'];
            }

            $options[] = [
                'name' => $name,
                'position' => $pos,
                'values' => array_map(fn ($v) => ['name' => $v], $values),
            ];

            $pos++;
        }

        if (count($options) === 0) {
            $options[] = [
                'name' => 'Title',
                'position' => 1,
                'values' => [
                    ['name' => 'Default'],
                ],
            ];
        }

        return $options;
    }

    private function buildTagsFromShopware(array $product): array
    {
        $tags = [];

        $categories = data_get($product, 'categories', []);
        if (is_array($categories)) {
            foreach ($categories as $c) {
                $name = $this->normalizeText(data_get($c, 'translated.name')) ?: $this->normalizeText(data_get($c, 'name'));
                if ($name !== '') {
                    $tags[$name] = true;
                }

                $cid = $this->normalizeText((string) data_get($c, 'id'));
                if ($cid !== '') {
                    $tags['SWCAT:'.$cid] = true;
                }
            }
        }

        $manufacturer = $this->normalizeText(data_get($product, 'manufacturer.name'));
        if ($manufacturer !== '' && $manufacturer !== 'Unknown') {
            $tags[$manufacturer] = true;
        }

        $pn = $this->normalizeText(data_get($product, 'productNumber'));
        if ($pn !== '') {
            $tags['SW:'.$pn] = true;
        }

        $properties = data_get($product, 'properties', []);
        if (is_array($properties)) {
            foreach ($properties as $p) {
                $groupName = $this->normalizeText(data_get($p, 'group.translated.name')) ?: $this->normalizeText(data_get($p, 'group.name'));
                $valueName = $this->normalizeText(data_get($p, 'translated.name')) ?: $this->normalizeText(data_get($p, 'name'));
                if ($groupName !== '' && $valueName !== '') {
                    $tags[$groupName.':'.$valueName] = true;
                }
            }
        }

        return array_values(array_keys($tags));
    }

    private function deriveOptionsFromConfigurator(array $product): array
    {
        $settings = data_get($product, 'configuratorSettings', []);
        if (!is_array($settings) || count($settings) === 0) {
            return [];
        }

        $groups = [];
        foreach ($settings as $s) {
            $groupName = $this->normalizeText(data_get($s, 'option.group.translated.name'))
                ?: $this->normalizeText(data_get($s, 'option.group.name'));
            $optionName = $this->normalizeText(data_get($s, 'option.translated.name'))
                ?: $this->normalizeText(data_get($s, 'option.name'));

            if ($groupName === '' || $optionName === '') {
                continue;
            }

            if (!isset($groups[$groupName])) {
                $groups[$groupName] = [];
            }

            $groups[$groupName][$optionName] = true;
        }

        $options = [];
        foreach ($groups as $name => $set) {
            $options[] = ['name' => $name, 'values' => array_values(array_keys($set))];
        }

        return $options;
    }

    private function buildOptionDefinitionsFromVariants(array $variants): array
    {
        $map = [];

        foreach ($variants as $v) {
            foreach ($this->deriveVariantOptionPairs($v) as $p) {
                $n = $p['name'];
                $val = $p['value'];
                if ($n === '' || $val === '') {
                    continue;
                }
                if (!isset($map[$n])) {
                    $map[$n] = [];
                }
                $map[$n][$val] = true;
            }
        }

        $defs = [];
        foreach ($map as $name => $set) {
            $defs[] = ['name' => $name, 'values' => array_values(array_keys($set))];
        }

        return $defs;
    }

    private function deriveVariantOptionPairs(array $variant): array
    {
        $opts = data_get($variant, 'options', []);
        if (!is_array($opts)) {
            return [];
        }

        $pairs = [];
        foreach ($opts as $o) {
            $name = $this->normalizeText(data_get($o, 'group.translated.name'))
                ?: $this->normalizeText(data_get($o, 'group.name'));
            $value = $this->normalizeText(data_get($o, 'translated.name'))
                ?: $this->normalizeText(data_get($o, 'name'));

            if ($name === '' || $value === '') {
                continue;
            }

            $pairs[] = ['name' => $name, 'value' => $value];
        }

        return $pairs;
    }

    private function moneyToPrice(array $product, array $fallbackProduct, string $priceMode = 'gross'): string
    {
        $field = $priceMode === 'net' ? 'net' : 'gross';

        // Read from price[] (base price) first — prices[] is the rule-based advanced pricing array
        $amount = data_get($product, "price.0.{$field}");

        if ($amount === null) {
            $amount = data_get($fallbackProduct, "price.0.{$field}");
        }

        $num = is_numeric($amount) ? (float) $amount : null;
        if ($num === null || $num <= 0) {
            return '0.00';
        }

        return number_format($num, 2, '.', '');
    }

    private function moneyToCompareAtPrice(array $product, array $fallbackProduct, string $priceMode = 'gross'): ?string
    {
        $field = $priceMode === 'net' ? 'net' : 'gross';

        // Only read from price[] (base price array), NOT prices[] (rule-based advanced prices)
        // prices[].listPrice is not the compare-at/RRP price
        $listAmount = data_get($product, "price.0.listPrice.{$field}");

        if ($listAmount === null) {
            $listAmount = data_get($fallbackProduct, "price.0.listPrice.{$field}");
        }

        if ($listAmount === null || !is_numeric($listAmount) || (float) $listAmount <= 0) {
            return null;
        }

        return number_format((float) $listAmount, 2, '.', '');
    }

    private function numericInt($v): int
    {
        if (!is_numeric($v)) {
            return 0;
        }
        return (int) floor((float) $v);
    }

    private function normalizeText($v): string
    {
        if ($v === null) {
            return '';
        }
        return trim((string) $v);
    }

    private function removeEmpty(array $payload): array
    {
        foreach (array_keys($payload) as $k) {
            if ($payload[$k] === '' || $payload[$k] === null) {
                unset($payload[$k]);
            }
        }

        return $payload;
    }

    private function extractSeoPath(array $parent): string
    {
        $seoUrls = data_get($parent, 'seoUrls');
        if (!is_array($seoUrls)) {
            $seoUrls = [];
        }

        $candidates = [];
        foreach ($seoUrls as $row) {
            if (!is_array($row)) {
                continue;
            }

            $path = $this->normalizeText(data_get($row, 'seoPathInfo'))
                ?: $this->normalizeText(data_get($row, 'seoPath'))
                ?: $this->normalizeText(data_get($row, 'pathInfo'));
            if ($path === '') {
                continue;
            }

            $isCanonical = (bool) data_get($row, 'isCanonical', false);
            $isDeleted = (bool) data_get($row, 'isDeleted', false);
            if ($isDeleted) {
                continue;
            }

            $score = $isCanonical ? 100 : 10;
            $salesChannelId = $this->normalizeText((string) data_get($row, 'salesChannelId'));
            if ($salesChannelId !== '') {
                $score += 5;
            }

            $candidates[] = ['path' => $path, 'score' => $score];
        }

        if (count($candidates) > 0) {
            usort($candidates, fn ($a, $b) => ($b['score'] <=> $a['score']));
            return $this->normalizeText((string) ($candidates[0]['path'] ?? ''));
        }

        return $this->normalizeText(data_get($parent, 'translated.seoPathInfo'))
            ?: $this->normalizeText(data_get($parent, 'seoPathInfo'))
            ?: $this->normalizeText(data_get($parent, 'translated.seoPath'))
            ?: $this->normalizeText(data_get($parent, 'seoPath'));
    }

    private function toShopifyHandle(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('~^https?://[^/]+/~i', '', $value) ?? $value;
        $value = trim($value, '/');
        if (str_contains($value, '/')) {
            $parts = array_values(array_filter(explode('/', $value), fn ($s) => trim((string) $s) !== ''));
            if (count($parts) > 0) {
                $value = (string) end($parts);
            }
        }

        $handle = Str::slug($value, '-');
        if ($handle === '') {
            $handle = Str::slug($this->normalizeText($value), '-');
        }

        return substr($handle, 0, 255);
    }

    private function extractSeoKeywords(array $parent): string
    {
        $candidates = [
            data_get($parent, 'translated.keywords'),
            data_get($parent, 'keywords'),
            data_get($parent, 'translated.metaKeywords'),
            data_get($parent, 'metaKeywords'),
            data_get($parent, 'translated.metaKeyword'),
            data_get($parent, 'metaKeyword'),
            data_get($parent, 'customFields.seo_keywords'),
            data_get($parent, 'customFields.meta_keywords'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value)) {
                $clean = trim($value);
                if ($clean !== '') {
                    return $clean;
                }
            }

            if (is_array($value)) {
                $parts = [];
                foreach ($value as $v) {
                    if (!is_scalar($v)) {
                        continue;
                    }
                    $piece = trim((string) $v);
                    if ($piece !== '') {
                        $parts[] = $piece;
                    }
                }
                if (count($parts) > 0) {
                    return implode(', ', array_values(array_unique($parts)));
                }
            }
        }

        return '';
    }

    /**
     * Detect if a product is digital type and extract download file URLs.
     * Returns array of file URLs from Shopware downloads association.
     *
     * @param array<string, mixed> $product
     * @return array<int, string> Array of CDN URLs
     */
    public function extractDigitalDownloadUrls(array $product): array
    {
        $urls = [];
        $downloads = data_get($product, 'downloads', []);
        
        if (!is_array($downloads)) {
            return $urls;
        }

        foreach ($downloads as $download) {
            if (!is_array($download)) {
                continue;
            }

            // Shopware provides direct URL in downloads
            $url = $this->normalizeText(
                data_get($download, 'url')
                ?: data_get($download, 'media.url')
                ?: data_get($download, 'accessUrl')
                ?: ''
            );

            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Generate individual and metadata metafields for digital product files.
     * Creates one metafield per file + one JSON metadata metafield.
     *
     * @param array<int, string> $fileUrls Array of CDN file URLs
     * @param string $prefix Either 'digital' for product or 'variant_digital' for variants
     * @return array<int, array{namespace: string, key: string, type: string, value: string}>
     */
    public function generateDigitalFileMetafields(array $fileUrls, string $prefix = 'digital'): array
    {
        $out = [];

        if (count($fileUrls) === 0) {
            return $out;
        }

        // Individual metafield for each file (direct CDN links for easy admin access)
        foreach ($fileUrls as $index => $url) {
            $fileIndex = $index + 1;  // 1-based indexing
            $this->pushProductMetafield($out, $prefix . '_file_' . $fileIndex, $url, 'string');
        }

        // Total file count metafield
        $this->pushProductMetafield($out, $prefix . '_file_count', (string) count($fileUrls), 'string');

        // Comprehensive JSON metadata metafield
        $metadata = [
            'file_count' => count($fileUrls),
            'product_type' => str_starts_with($prefix, 'variant') ? 'variant_digital' : 'digital',
            'files' => [],
        ];

        foreach ($fileUrls as $index => $url) {
            $fileIndex = $index + 1;
            $fileName = $this->extractFileNameFromUrl($url);
            $fileType = $this->extractFileExtension($url);

            $metadata['files'][] = [
                'index' => $fileIndex,
                'name' => $fileName,
                'url' => $url,
                'type' => $fileType,
            ];
        }

        $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($metadataJson) && strlen($metadataJson) <= 5000) {
            $this->pushProductMetafield($out, $prefix . '_files_metadata', $metadataJson, 'json');
        }

        return $out;
    }

    /**
     * Extract file name from URL (everything after last /).
     */
    private function extractFileNameFromUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = explode('/', $url);
        $last = end($parts);

        if (is_string($last)) {
            // Remove query string if present
            $last = explode('?', $last)[0] ?? $last;
            return $this->normalizeText($last);
        }

        return '';
    }

    /**
     * Extract file extension from URL/filename.
     */
    private function extractFileExtension(string $url): string
    {
        $fileName = $this->extractFileNameFromUrl($url);
        if ($fileName === '' || strpos($fileName, '.') === false) {
            return 'unknown';
        }

        $parts = explode('.', $fileName);
        $ext = strtolower(trim((string) end($parts)));

        return $ext !== '' ? $ext : 'unknown';
    }
}
