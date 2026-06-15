<?php

namespace Tests\Unit;

use App\Models\Shop;
use App\Services\Migration\StateAssignmentMapper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for StateAssignmentMapper using Magento 2 order structure.
 *
 * Magento order key fields:
 *   - status:  granular string e.g. "pending", "processing", "complete", "canceled"
 *   - state:   broad string  e.g. "new", "processing", "complete", "closed", "canceled"
 *   - payment: ['method' => 'checkmo'] (payment method = transaction state proxy)
 *   - extension_attributes.shipping_assignments: [] (delivery proxy)
 */
class StateAssignmentMapperTest extends TestCase
{
    private function mapperWithMappings(array $mappings): StateAssignmentMapper
    {
        $mapper = new StateAssignmentMapper();
        $shop = new Shop();
        $shop->id = 1;
        $shop->shop_domain = 'test.myshopify.com';

        $reflection = new ReflectionClass($mapper);
        $cache = $reflection->getProperty('cache');
        $cache->setAccessible(true);
        $cache->setValue($mapper, [1 => $mappings]);

        return $mapper;
    }

    // ─── Financial status tests ──────────────────────────────────────────────

    public function test_payment_method_mapped_to_paid_financial_status(): void
    {
        // "transaction_state" in our mapper = Magento payment method name
        $mapper = $this->mapperWithMappings([
            'transaction_state' => ['checkmo' => 'paid'],
        ]);

        $shop = new Shop();
        $shop->id = 1;

        $order = [
            'status'  => 'processing',
            'state'   => 'processing',
            'payment' => ['method' => 'checkmo'],
        ];

        $this->assertSame('PAID', $mapper->resolveFinancialStatus($shop, $order));
        $this->assertSame('SUCCESS', $mapper->transactionStatusForFinancialStatus('PAID'));
    }

    public function test_order_state_paid_used_when_payment_method_maps_to_pending(): void
    {
        $mapper = $this->mapperWithMappings([
            'transaction_state' => ['checkmo' => 'pending'],
            'order_state'       => ['processing' => 'paid'],
        ]);

        $shop = new Shop();
        $shop->id = 1;

        $order = [
            'status'  => 'processing',
            'state'   => 'processing',
            'payment' => ['method' => 'checkmo'],
        ];

        $this->assertSame('PAID', $mapper->resolveFinancialStatus($shop, $order));
        $this->assertSame('SUCCESS', $mapper->transactionStatusForFinancialStatus('PAID'));
    }

    public function test_payment_method_paid_takes_priority_over_order_pending(): void
    {
        $mapper = $this->mapperWithMappings([
            'transaction_state' => ['paypal_express' => 'paid'],
            'order_state'       => ['new' => 'pending'],
        ]);

        $shop = new Shop();
        $shop->id = 1;

        $order = [
            'status'  => 'pending',
            'state'   => 'new',
            'payment' => ['method' => 'paypal_express'],
        ];

        $this->assertSame('PAID', $mapper->resolveFinancialStatus($shop, $order));
    }

    public function test_order_state_used_when_no_payment_method(): void
    {
        $mapper = $this->mapperWithMappings([
            'order_state' => ['complete' => 'paid'],
        ]);

        $shop = new Shop();
        $shop->id = 1;

        // Magento order with no payment method (e.g. free order)
        $order = [
            'status'  => 'complete',
            'state'   => 'complete',
            'payment' => [],
        ];

        $this->assertSame('PAID', $mapper->resolveFinancialStatus($shop, $order));
    }

    // ─── Fulfillment status tests ────────────────────────────────────────────

    public function test_delivery_state_processing_to_fulfilled(): void
    {
        $mapper = $this->mapperWithMappings([
            'delivery_state' => ['processing' => 'fulfilled'],
        ]);

        $shop = new Shop();
        $shop->id = 1;

        // Magento order with a shipping assignment (means it has been shipped)
        $order = [
            'status' => 'processing',
            'state'  => 'processing',
            'extension_attributes' => [
                'shipping_assignments' => [
                    ['shipping' => ['address' => [], 'total' => []]],
                ],
            ],
        ];

        $this->assertSame('FULFILLED', $mapper->resolveFulfillmentStatus($shop, $order));
    }

    public function test_delivery_state_when_no_shipments_falls_back_to_order_state(): void
    {
        $mapper = $this->mapperWithMappings([
            'delivery_state' => ['complete' => 'fulfilled'],
        ]);

        $shop = new Shop();
        $shop->id = 1;

        // No shipping assignments — uses order status as delivery proxy
        $order = [
            'status' => 'complete',
            'state'  => 'complete',
            'extension_attributes' => [
                'shipping_assignments' => [],
            ],
        ];

        $this->assertSame('FULFILLED', $mapper->resolveFulfillmentStatus($shop, $order));
    }

    // ─── Fallback heuristic tests ────────────────────────────────────────────

    public function test_fallback_complete_status_returns_paid(): void
    {
        $mapper = $this->mapperWithMappings([]);
        $shop = new Shop();
        $shop->id = 1;

        $order = [
            'status'  => 'complete',
            'state'   => 'complete',
            'payment' => ['method' => 'checkmo'],
        ];

        $this->assertSame('PAID', $mapper->resolveFinancialStatus($shop, $order));
    }

    public function test_fallback_canceled_status_returns_voided(): void
    {
        $mapper = $this->mapperWithMappings([]);
        $shop = new Shop();
        $shop->id = 1;

        $order = [
            'status'  => 'canceled',
            'state'   => 'canceled',
            'payment' => ['method' => 'checkmo'],
        ];

        $this->assertSame('VOIDED', $mapper->resolveFinancialStatus($shop, $order));
    }

    public function test_fallback_pending_status_returns_pending(): void
    {
        $mapper = $this->mapperWithMappings([]);
        $shop = new Shop();
        $shop->id = 1;

        $order = [
            'status'  => 'pending',
            'state'   => 'new',
            'payment' => ['method' => 'checkmo'],
        ];

        $this->assertSame('PENDING', $mapper->resolveFinancialStatus($shop, $order));
    }
}
