<?php

namespace Tests\Unit;

use App\Services\Migration\ProductPayloadMapper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ProductPayloadMapperTest extends TestCase
{
    private function invokePrivate(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionClass($object);
        $callable = $reflection->getMethod($method);
        $callable->setAccessible(true);

        return $callable->invoke($object, ...$args);
    }

    public function test_description_falls_back_when_translated_empty(): void
    {
        $mapper = new ProductPayloadMapper();
        $payload = $mapper->mapParentWithVariants([
            'id' => 'p1',
            'productNumber' => 'SKU-1',
            'name' => 'Product',
            'translated' => ['description' => ''],
            'description' => '<p>Fallback description</p>',
            'active' => true,
            'weight' => 1.5,
        ], [], 'gid://shopify/Location/1');

        $this->assertSame('<p>Fallback description</p>', $payload['descriptionHtml']);
        $this->assertArrayNotHasKey('weight', $payload['variants'][0]);
        $this->assertSame('ACTIVE', $payload['status']);

        $metafields = $mapper->mapShopwareMetafields([
            'id' => 'p1',
            'productNumber' => 'SKU-1',
            'weight' => 1.5,
        ], []);
        $this->assertContains('weight_kg', array_column($metafields, 'key'));
    }

    public function test_inactive_product_maps_to_draft(): void
    {
        $mapper = new ProductPayloadMapper();
        $payload = $mapper->mapParentWithVariants([
            'id' => 'p2',
            'productNumber' => 'SKU-2',
            'name' => 'Draft product',
            'active' => false,
        ], [], 'gid://shopify/Location/1');

        $this->assertSame('DRAFT', $payload['status']);
    }

    public function test_archived_when_available_false(): void
    {
        $mapper = new ProductPayloadMapper();
        $payload = $mapper->mapParentWithVariants([
            'id' => 'p3',
            'productNumber' => 'SKU-3',
            'name' => 'Archived product',
            'active' => true,
            'available' => false,
        ], [], 'gid://shopify/Location/1');

        $this->assertSame('ARCHIVED', $payload['status']);
    }

    public function test_map_shopware_metafields_from_custom_fields(): void
    {
        $mapper = new ProductPayloadMapper();
        $fields = $mapper->mapShopwareMetafields([
            'id' => 'sw-1',
            'productNumber' => 'PN-1',
            'customFields' => [
                'legacy_code' => 'LC-99',
            ],
        ], []);

        $keys = array_column($fields, 'key');
        $this->assertContains('product_id', $keys);
        $this->assertContains('product_legacy_code', $keys);
    }

    public function test_maps_handle_from_seo_path_and_keywords_to_metafield(): void
    {
        $mapper = new ProductPayloadMapper();
        $payload = $mapper->mapParentWithVariants([
            'id' => 'p-seo-1',
            'name' => 'Fallback Name',
            'translated' => [
                'name' => 'Main product with properties T',
                'metaTitle' => 'SEO Title',
                'metaDescription' => 'SEO Description',
            ],
            'seoUrls' => [
                [
                    'seoPathInfo' => 'main-product-with-properties-t',
                    'isCanonical' => true,
                    'isDeleted' => false,
                ],
            ],
            'keywords' => 'alpha, beta, gamma',
            'active' => true,
        ], [], 'gid://shopify/Location/1');

        $this->assertSame('main-product-with-properties-t', $payload['handle']);
        $this->assertSame('SEO Title', $payload['seo']['title']);
        $this->assertSame('SEO Description', $payload['seo']['description']);

        $metafields = $mapper->mapShopwareMetafields([
            'id' => 'p-seo-1',
            'productNumber' => 'PN-SEO-1',
            'keywords' => 'alpha, beta, gamma',
            'seoUrls' => [
                [
                    'seoPathInfo' => 'main-product-with-properties-t',
                    'isCanonical' => true,
                    'isDeleted' => false,
                ],
            ],
        ], []);

        $byKey = [];
        foreach ($metafields as $row) {
            $byKey[$row['key']] = $row['value'] ?? null;
        }

        $this->assertArrayHasKey('seo_keywords', $byKey);
        $this->assertSame('alpha, beta, gamma', $byKey['seo_keywords']);
        $this->assertArrayHasKey('seo_path_source', $byKey);
        $this->assertSame('main-product-with-properties-t', $byKey['seo_path_source']);
    }

    public function test_maps_specification_fields_to_metafields(): void
    {
        $mapper = new ProductPayloadMapper();

        $metafields = $mapper->mapShopwareMetafields([
            'id' => 'spec-1',
            'productNumber' => 'SPEC-1',
            'width' => 10,
            'height' => 20,
            'length' => 30,
            'weight' => 0.5,
            'purchaseUnit' => 1,
            'referenceUnit' => 1,
            'packUnit' => 'Bottle',
            'packUnitPlural' => 'Bottles',
            'unit' => ['shortCode' => 'ml'],
            'properties' => [
                [
                    'group' => ['name' => 'Material'],
                    'name' => 'Cotton',
                ],
            ],
        ], []);

        $byKey = [];
        foreach ($metafields as $row) {
            $byKey[$row['key']] = $row['value'] ?? null;
        }

        $this->assertSame('10', $byKey['spec_width'] ?? null);
        $this->assertSame('20', $byKey['spec_height'] ?? null);
        $this->assertSame('30', $byKey['spec_length'] ?? null);
        $this->assertSame('1', $byKey['spec_purchase_unit'] ?? null);
        $this->assertSame('1', $byKey['spec_reference_unit'] ?? null);
        $this->assertSame('Bottle', $byKey['spec_pack_unit'] ?? null);
        $this->assertSame('Bottles', $byKey['spec_pack_unit_plural'] ?? null);
        $this->assertSame('ml', $byKey['spec_unit'] ?? null);
        $this->assertSame('Material: Cotton', $byKey['spec_properties'] ?? null);
        $this->assertArrayHasKey('specification_json', $byKey);
    }

    public function test_maps_tax_fields_to_metafields_and_payload(): void
    {
        $mapper = new ProductPayloadMapper();

        $parent = [
            'id' => 'p-tax-1',
            'productNumber' => 'PN-TAX-1',
            'name' => 'Taxable Product',
            'active' => true,
            'tax' => [
                'taxRate' => 19.0,
                'name' => 'Standard rate',
            ],
            'price' => [
                [
                    'gross' => 119.00,
                    'net' => 100.00,
                ],
            ],
        ];

        // 1. Verify mapping variant fields (taxable status)
        $payload = $mapper->mapParentWithVariants($parent, [], 'gid://shopify/Location/1', null, 'gross');
        $this->assertTrue($payload['variants'][0]['taxable']);
        $this->assertSame('119.00', $payload['variants'][0]['price']);

        // 2. Verify mapping metafields
        $metafields = $mapper->mapShopwareMetafields($parent, []);
        $byKey = [];
        foreach ($metafields as $row) {
            $byKey[$row['key']] = $row['value'] ?? null;
        }

        $this->assertSame('19%', $byKey['tax_rate'] ?? null);
        $this->assertSame('Standard rate', $byKey['tax_name'] ?? null);

        // 3. Verify non-taxable product (tax rate = 0)
        $parentNonTaxable = [
            'id' => 'p-tax-2',
            'productNumber' => 'PN-TAX-2',
            'name' => 'Non-Taxable Product',
            'active' => true,
            'tax' => [
                'taxRate' => 0.0,
                'name' => 'Exempt rate',
            ],
            'price' => [
                [
                    'gross' => 100.00,
                    'net' => 100.00,
                ],
            ],
        ];

        $payloadNonTaxable = $mapper->mapParentWithVariants($parentNonTaxable, [], 'gid://shopify/Location/1', null, 'gross');
        $this->assertFalse($payloadNonTaxable['variants'][0]['taxable']);
    }

    public function test_extracts_digital_download_urls(): void
    {
        $mapper = new ProductPayloadMapper();
        
        $productWithDownloads = [
            'id' => 'digital-1',
            'productNumber' => 'DIG-1',
            'downloads' => [
                ['url' => 'https://cdn.example.com/manual.pdf'],
                ['url' => 'https://cdn.example.com/guide.docx'],
                ['url' => 'https://cdn.example.com/support.zip'],
            ],
        ];

        $urls = $mapper->extractDigitalDownloadUrls($productWithDownloads);
        $this->assertCount(3, $urls);
        $this->assertContains('https://cdn.example.com/manual.pdf', $urls);
        $this->assertContains('https://cdn.example.com/guide.docx', $urls);
        $this->assertContains('https://cdn.example.com/support.zip', $urls);
    }

    public function test_generates_individual_digital_file_metafields(): void
    {
        $mapper = new ProductPayloadMapper();
        
        $fileUrls = [
            'https://cdn.example.com/manual.pdf',
            'https://cdn.example.com/guide.docx',
        ];

        $metafields = $mapper->generateDigitalFileMetafields($fileUrls, 'digital');

        $keys = array_column($metafields, 'key');
        
        // Should have individual file metafields
        $this->assertContains('digital_file_1', $keys);
        $this->assertContains('digital_file_2', $keys);
        
        // Should have count and metadata
        $this->assertContains('digital_file_count', $keys);
        $this->assertContains('digital_files_metadata', $keys);

        // Verify individual file values
        $byKey = array_combine($keys, array_column($metafields, 'value'));
        $this->assertSame('https://cdn.example.com/manual.pdf', $byKey['digital_file_1']);
        $this->assertSame('https://cdn.example.com/guide.docx', $byKey['digital_file_2']);
        $this->assertSame('2', $byKey['digital_file_count']);

        // Verify JSON metadata is valid
        $metadata = json_decode($byKey['digital_files_metadata'], true);
        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('files', $metadata);
        $this->assertCount(2, $metadata['files']);
        $this->assertSame('manual.pdf', $metadata['files'][0]['name']);
        $this->assertSame('pdf', $metadata['files'][0]['type']);
    }

    public function test_generates_variant_digital_file_metafields(): void
    {
        $mapper = new ProductPayloadMapper();
        
        $fileUrls = [
            'https://cdn.example.com/variant-v1-manual.pdf',
        ];

        $metafields = $mapper->generateDigitalFileMetafields($fileUrls, 'variant_digital_abc12345');

        $keys = array_column($metafields, 'key');
        
        // Should use variant prefix
        $this->assertContains('variant_digital_abc12345_file_1', $keys);
        $this->assertContains('variant_digital_abc12345_files_metadata', $keys);
    }

    public function test_maps_digital_files_in_product_metafields(): void
    {
        $mapper = new ProductPayloadMapper();
        
        $parentProduct = [
            'id' => 'digital-parent-1',
            'productNumber' => 'DIG-PARENT-1',
            'downloads' => [
                ['url' => 'https://cdn.example.com/parent.pdf'],
                ['url' => 'https://cdn.example.com/parent.zip'],
            ],
        ];

        $metafields = $mapper->mapShopwareMetafields($parentProduct, []);

        $keys = array_column($metafields, 'key');
        
        // Product digital files should be present
        $this->assertContains('digital_file_1', $keys);
        $this->assertContains('digital_file_2', $keys);
        $this->assertContains('digital_files_metadata', $keys);
    }

    public function test_maps_digital_files_in_variant_metafields(): void
    {
        $mapper = new ProductPayloadMapper();
        
        $parentProduct = [
            'id' => 'digital-parent-2',
            'productNumber' => 'DIG-PARENT-2',
        ];

        $children = [
            [
                'id' => 'var-digital-001-abc123456789',
                'productNumber' => 'VAR-1',
                'downloads' => [
                    ['url' => 'https://cdn.example.com/variant-v1.pdf'],
                ],
            ],
            [
                'id' => 'var-digital-002-xyz987654321',
                'productNumber' => 'VAR-2',
                'downloads' => [
                    ['url' => 'https://cdn.example.com/variant-v2.pdf'],
                    ['url' => 'https://cdn.example.com/variant-v2-guide.docx'],
                ],
            ],
        ];

        $metafields = $mapper->mapShopwareMetafields($parentProduct, $children);

        $keys = array_column($metafields, 'key');
        
        // Variant digital files should use prefix with variant ID
        $variantPrefixes = array_filter($keys, fn($k) => str_starts_with($k, 'variant_digital_'));
        $this->assertNotEmpty($variantPrefixes);
        
        // Should have file metafields for first variant
        $variantFileKeys = array_filter($variantPrefixes, fn($k) => str_contains($k, '_file_'));
        $this->assertNotEmpty($variantFileKeys);
    }
}
