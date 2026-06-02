<?php

namespace Tests\Unit;

use App\Models\Shop;
use App\Models\ShopwareConnection;
use App\Services\Migration\OrderPayloadMapper;
use App\Services\Migration\StateAssignmentMapper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class OrderPayloadMapperMethodsTest extends TestCase
{
    private function invokePrivate(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionClass($object);
        $callable = $reflection->getMethod($method);
        $callable->setAccessible(true);

        return $callable->invoke($object, ...$args);
    }

    public function test_effective_shipping_method_falls_back_to_standard(): void
    {
        $shop = new Shop();
        $shop->id = 1;
        $shop->setRelation('shopwareConnection', null);

        $mapper = new OrderPayloadMapper();
        $order = [
            'state' => ['technicalName' => 'open'],
            'deliveries' => [],
        ];

        $this->assertSame(
            'Standard',
            $this->invokePrivate($mapper, 'effectiveShippingMethodName', $shop, $order)
        );
    }

    public function test_payment_method_name_reads_embedded_transaction_label(): void
    {
        $shop = new Shop();
        $shop->id = 1;
        $shop->setRelation('shopwareConnection', null);

        $mapper = new OrderPayloadMapper();
        $order = [
            'transactions' => [
                [
                    'paymentMethod' => [
                        'translated' => ['name' => 'Cash on delivery'],
                    ],
                ],
            ],
        ];

        $this->assertSame(
            'Cash on delivery',
            $this->invokePrivate($mapper, 'paymentMethodName', $shop, $order)
        );
    }

    public function test_map_address_includes_address2_from_additional_lines(): void
    {
        $mapper = new OrderPayloadMapper();
        $addr = $this->invokePrivate($mapper, 'mapAddress', [
            'firstName' => 'Jane',
            'lastName' => 'Doe',
            'street' => 'Main St 1',
            'additionalAddressLine1' => 'Apt 4',
            'additionalAddressLine2' => 'Building B',
            'zipcode' => '10115',
            'city' => 'Berlin',
            'country' => ['iso' => 'DE'],
        ]);

        $this->assertSame('Apt 4, Building B', $addr['address2']);
    }

    public function test_line_item_title_appends_variant_options(): void
    {
        $mapper = new OrderPayloadMapper();
        $title = $this->invokePrivate($mapper, 'lineItemTitleWithOptions', [
            'label' => 'T-Shirt',
            'payload' => [
                'options' => [
                    ['group' => 'Size', 'option' => 'L'],
                    ['group' => 'Color', 'option' => 'Blue'],
                ],
            ],
        ]);

        $this->assertSame('T-Shirt (Size: L, Color: Blue)', $title);
    }

    public function test_resolve_order_discount_amount_sums_line_discounts(): void
    {
        $mapper = new OrderPayloadMapper();
        $amount = $this->invokePrivate($mapper, 'resolveOrderDiscountAmount', [
            'price' => ['discountPrice' => 0],
            'lineItems' => [
                ['price' => ['discountPrice' => 5.5]],
                ['price' => ['discountPrice' => 2.5]],
            ],
        ]);

        $this->assertEqualsWithDelta(8.0, $amount, 0.001);
    }

    public function test_finalize_mailing_address_fills_missing_country_from_billing(): void
    {
        $mapper = new OrderPayloadMapper();
        $billing = [
            'firstName' => 'Test',
            'lastName' => 'Doe',
            'address1' => '4587 Test',
            'city' => 'Test',
            'zip' => '4587',
            'countryCode' => 'GB',
            'country' => 'United Kingdom',
        ];
        $shipping = [
            'firstName' => 'Test',
            'lastName' => 'Doe',
            'address1' => '4587 Test',
            'city' => 'Test',
            'zip' => '4587',
        ];

        $final = $this->invokePrivate($mapper, 'finalizeMailingAddressForShopify', $shipping, $billing);

        $this->assertIsArray($final);
        $this->assertSame('GB', $final['countryCode'] ?? null);
        $this->assertSame('United Kingdom', $final['country'] ?? null);
    }

    public function test_resolve_shipping_address_falls_back_to_billing(): void
    {
        $mapper = new OrderPayloadMapper();
        $order = [
            'billingAddress' => [
                'firstName' => 'Test',
                'lastName' => 'Doe',
                'street' => '4587 Test',
                'zipcode' => '4587',
                'city' => 'Test',
                'country' => ['iso' => 'GB'],
            ],
            'deliveries' => [],
        ];

        $shipping = $this->invokePrivate($mapper, 'resolveShippingAddress', $order);

        $this->assertIsArray($shipping);
        $this->assertSame('4587 Test', $shipping['address1'] ?? null);
    }

    public function test_resolve_shipping_address_from_delivery_address_id(): void
    {
        $mapper = new OrderPayloadMapper();
        $order = [
            'deliveries' => [
                ['shippingOrderAddressId' => 'ship-addr-1'],
            ],
            'addresses' => [
                [
                    'id' => 'ship-addr-1',
                    'firstName' => 'Ship',
                    'lastName' => 'To',
                    'street' => 'Ship Street',
                    'zipcode' => '9999',
                    'city' => 'Ship City',
                    'country' => ['iso' => 'GB'],
                ],
            ],
        ];

        $shipping = $this->invokePrivate($mapper, 'resolveShippingAddress', $order);

        $this->assertIsArray($shipping);
        $this->assertSame('Ship Street', $shipping['address1'] ?? null);
    }

    public function test_resolve_billing_address_from_order_customer_default(): void
    {
        $mapper = new OrderPayloadMapper();
        $order = [
            'billingAddress' => null,
            'orderCustomer' => [
                'customer' => [
                    'defaultBillingAddress' => [
                        'firstName' => 'Test',
                        'lastName' => 'Doe',
                        'street' => '4587 Test',
                        'zipcode' => '4587',
                        'city' => 'Test',
                        'country' => ['name' => 'United Kingdom'],
                    ],
                ],
            ],
        ];

        $billing = $this->invokePrivate($mapper, 'resolveBillingAddress', $order);

        $this->assertIsArray($billing);
        $this->assertSame('4587 Test', $billing['address1'] ?? null);
        $this->assertSame('GB', $billing['countryCode'] ?? null);
    }

    public function test_map_assignment_custom_attributes_shows_shopware_payment_when_unmapped(): void
    {
        $shop = new Shop();
        $shop->id = 1;
        $shop->shop_domain = 'test.myshopify.com';
        $shop->setRelation('shopwareConnection', new ShopwareConnection());

        $mapper = new OrderPayloadMapper();
        $assignments = new StateAssignmentMapper();
        $reflection = new ReflectionClass(StateAssignmentMapper::class);
        $cache = $reflection->getProperty('cache');
        $cache->setAccessible(true);
        $cache->setValue($assignments, [1 => []]);

        $order = [
            'state' => ['technicalName' => 'open'],
            'transactions' => [
                ['state' => ['technicalName' => 'open'], 'paymentMethod' => ['name' => 'Cash on delivery']],
            ],
            'deliveries' => [],
        ];

        $attrs = $this->invokePrivate($mapper, 'mapAssignmentCustomAttributes', $shop, $order, $assignments);
        $byKey = [];
        foreach ($attrs as $attr) {
            $byKey[$attr['key']] = $attr['value'];
        }

        $this->assertSame('Cash on delivery', $byKey['Shopware payment method'] ?? null);
        $this->assertSame('Cash on delivery', $byKey['Shopify payment method'] ?? null);
    }

    public function test_map_assignment_custom_attributes_includes_method_pairs(): void
    {
        $shop = new Shop();
        $shop->id = 1;
        $shop->shop_domain = 'test.myshopify.com';
        $shop->setRelation('shopwareConnection', new ShopwareConnection());

        $mapper = new OrderPayloadMapper();
        $assignments = \App\Services\Migration\StateAssignmentMapper::class;

        $reflection = new ReflectionClass($assignments);
        $mapperInstance = new $assignments();
        $cache = $reflection->getProperty('cache');
        $cache->setAccessible(true);
        $cache->setValue($mapperInstance, [
            1 => [
                'order_state' => ['open' => 'paid'],
                'transaction_state' => ['open' => 'paid'],
                'delivery_state' => ['open' => 'fulfilled'],
                'payment_methods' => ['Cash on delivery' => 'bank_transfer'],
                'shipping_methods' => ['Standard' => 'overnight'],
            ],
        ]);

        $order = [
            'state' => ['technicalName' => 'open'],
            'transactions' => [
                ['state' => ['technicalName' => 'open'], 'paymentMethod' => ['name' => 'Cash on delivery']],
            ],
            'deliveries' => [],
        ];

        $attrs = $this->invokePrivate($mapper, 'mapAssignmentCustomAttributes', $shop, $order, $mapperInstance);
        $byKey = [];
        foreach ($attrs as $attr) {
            $byKey[$attr['key']] = $attr['value'];
        }

        $this->assertSame('Cash on delivery', $byKey['Shopware payment method'] ?? null);
        $this->assertSame('Bank Transfer', $byKey['Shopify payment method'] ?? null);
        $this->assertSame('Standard', $byKey['Shopware shipping method'] ?? null);
        $this->assertSame('Overnight Shipping', $byKey['Shopify shipping method'] ?? null);
    }

    public function test_build_manual_payment_capture_uses_display_name_and_amount(): void
    {
        $shop = new Shop();
        $shop->id = 1;
        $shop->setRelation('shopwareConnection', null);

        $reflection = new ReflectionClass(StateAssignmentMapper::class);
        $assignments = new StateAssignmentMapper();
        $cache = $reflection->getProperty('cache');
        $cache->setAccessible(true);
        $cache->setValue($assignments, [
            1 => [
                'payment_methods' => ['Cash on delivery' => 'bank_transfer'],
            ],
        ]);

        $mapper = new OrderPayloadMapper();
        $order = [
            'amountTotal' => 19.99,
            'orderDateTime' => '2026-04-10T08:14:38.234+00:00',
            'transactions' => [
                ['paymentMethod' => ['name' => 'Cash on delivery']],
            ],
        ];

        $capture = $this->invokePrivate($mapper, 'buildManualPaymentCapture', $shop, $order, 'GBP', $assignments);

        $this->assertIsArray($capture);
        $this->assertSame('19.99', $capture['amount'] ?? null);
        $this->assertSame('GBP', $capture['currencyCode'] ?? null);
        $this->assertSame('Bank Transfer', $capture['paymentMethodName'] ?? null);
        $this->assertSame('2026-04-10T08:14:38.234+00:00', $capture['processedAt'] ?? null);
    }

    public function test_build_sale_transaction_includes_gateway_processed_at_and_auth_code(): void
    {
        $shop = new Shop();
        $shop->id = 1;
        $shop->setRelation('shopwareConnection', null);

        $reflection = new ReflectionClass(StateAssignmentMapper::class);
        $assignments = new StateAssignmentMapper();
        $cache = $reflection->getProperty('cache');
        $cache->setAccessible(true);
        $cache->setValue($assignments, [
            1 => [
                'payment_methods' => ['Cash on delivery' => 'bank_transfer'],
            ],
        ]);

        $mapper = new OrderPayloadMapper();
        $order = [
            'orderDateTime' => '2026-04-10T09:00:00+00:00',
            'transactions' => [
                [
                    'id' => 'sw-tx-999',
                    'paymentMethod' => ['name' => 'Cash on delivery'],
                ],
            ],
        ];

        $tx = $this->invokePrivate(
            $mapper,
            'buildSaleTransaction',
            $shop,
            $order,
            'GBP',
            $assignments,
            20.0,
            'PENDING',
            'Cash on delivery'
        );

        $this->assertSame('PENDING', $tx['status'] ?? null);
        $this->assertSame('Bank Transfer', $tx['gateway'] ?? null);
        $this->assertSame('2026-04-10T09:00:00+00:00', $tx['processedAt'] ?? null);
        $this->assertSame('sw-tx-999', $tx['authorizationCode'] ?? null);
    }
}
