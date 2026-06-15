<?php

namespace App\Services\Migration;

use App\Http\Controllers\Api\StateMappingController;
use App\Models\Shop;
use App\Support\MagentoStateResolver;

class StateAssignmentMapper
{
    /**
     * @var array<int, array<string, array<string, string>>>
     */
    private array $cache = [];

    /**
     * @return array<string, array<string, string>>
     */
    public function mappingsForShop(Shop $shop): array
    {
        if (!isset($this->cache[$shop->id])) {
            $this->cache[$shop->id] = StateMappingController::loadForShop($shop);
        }

        return $this->cache[$shop->id];
    }

    public function mappedValue(Shop $shop, string $type, string $source): ?string
    {
        $source = trim($source);
        if ($source === '') {
            return null;
        }

        $mappings = $this->mappingsForShop($shop);
        $map = isset($mappings[$type]) && is_array($mappings[$type]) ? $mappings[$type] : [];

        if (array_key_exists($source, $map)) {
            $value = trim((string) $map[$source]);
            return $value !== '' ? $value : null;
        }

        $normalized = mb_strtolower($source);
        foreach ($map as $key => $value) {
            if (mb_strtolower(trim((string) $key)) !== $normalized) {
                continue;
            }

            $value = trim((string) $value);
            return $value !== '' ? $value : null;
        }

        return null;
    }

    public function optionLabel(string $type, string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $options = StateMappingController::shopifyOptions();
        $rows = isset($options[$type]) && is_array($options[$type]) ? $options[$type] : [];
        foreach ($rows as $row) {
            if (!is_array($row) || (string) ($row['value'] ?? '') !== $value) {
                continue;
            }

            return trim((string) ($row['label'] ?? '')) ?: $this->humanize($value);
        }

        return $this->humanize($value);
    }

    public function financialStatusValue(string $value): ?string
    {
        $value = strtolower(trim($value));

        return match ($value) {
            'pending', 'open' => 'PENDING',
            'paid' => 'PAID',
            'partially_paid' => 'PARTIALLY_PAID',
            'refunded' => 'REFUNDED',
            'partially_refunded' => 'PARTIALLY_REFUNDED',
            'voided', 'cancelled' => 'VOIDED',
            default => null,
        };
    }

    /**
     * Resolve Shopify OrderCreate financialStatus from Magento order data.
     *
     * Magento 2 order structure (flat fields):
     *   - $order['status']  → e.g. "pending", "processing", "complete", "canceled"
     *   - $order['state']   → e.g. "new", "processing", "complete", "closed", "canceled"
     *   - $order['payment']['method'] → e.g. "checkmo", "paypal_express", "free"
     *
     * Priority:
     * 1. Mapped "transaction_state" using the Magento payment method name.
     * 2. Mapped "order_state" using the Magento order status string.
     * 3. Fallback heuristics on raw Magento status/payment values.
     */
    public function resolveFinancialStatus(Shop $shop, array $order): string
    {
        $fromTransaction = $this->financialStatusFromStateType(
            $shop,
            'transaction_state',
            $this->firstTransactionState($order)
        );
        $fromOrder = $this->financialStatusFromStateType(
            $shop,
            'order_state',
            $this->orderState($order)
        );

        if ($fromTransaction !== null && $fromTransaction !== '' && $fromTransaction !== 'PENDING') {
            return $fromTransaction;
        }

        if ($fromOrder !== null && $fromOrder !== '') {
            return $fromOrder;
        }

        if ($fromTransaction !== null && $fromTransaction !== '') {
            return $fromTransaction;
        }

        return $this->financialStatusFallbackFromRawStates($order);
    }

    private function financialStatusFromStateType(Shop $shop, string $type, string $state): ?string
    {
        $state = strtolower(trim($state));
        if ($state === '') {
            return null;
        }

        $mapped = $this->mappedValue($shop, $type, $state);
        if (!is_string($mapped) || $mapped === '') {
            return null;
        }

        return $this->financialStatusValue($mapped);
    }

    /**
     * Resolve Shopify OrderCreate fulfillmentStatus from saved delivery state assignments.
     */
    public function resolveFulfillmentStatus(Shop $shop, array $order): ?string
    {
        $fromDelivery = $this->fulfillmentFromStateType(
            $shop,
            'delivery_state',
            $this->firstDeliveryState($order)
        );
        if ($fromDelivery !== null && $fromDelivery !== '') {
            return $fromDelivery;
        }

        // Magento order has no separate delivery state[] — use order state as delivery proxy (e.g. open → fulfilled).
        $fromOrderState = $this->fulfillmentFromStateType(
            $shop,
            'delivery_state',
            $this->orderState($order)
        );
        if ($fromOrderState !== null && $fromOrderState !== '') {
            return $fromOrderState;
        }

        return $this->fulfillmentFallbackFromRawStates($order);
    }

    private function fulfillmentFromStateType(Shop $shop, string $type, string $state): ?string
    {
        $state = strtolower(trim($state));
        if ($state === '') {
            return null;
        }

        $mapped = $this->mappedValue($shop, $type, $state);
        if (!is_string($mapped) || $mapped === '') {
            return null;
        }

        return $this->fulfillmentStatusValue($mapped);
    }

    /**
     * For Magento, the "transaction state" is the payment method name
     * (e.g. "checkmo", "paypal_express", "free").
     * This allows store owners to map each payment method to a Shopify financial status.
     */
    private function firstTransactionState(array $order): string
    {
        // Magento: payment method is the closest equivalent to a transaction state.
        $method = strtolower(trim((string) data_get($order, 'payment.method', '')));
        if ($method !== '') {
            return $method;
        }

        // Fallback: use order status as payment proxy
        return MagentoStateResolver::orderStatus($order);
    }

    /**
     * For Magento, delivery state is derived from whether shipments exist.
     * Magento ships via shipments[] records; there is no separate delivery state string.
     */
    private function firstDeliveryState(array $order): string
    {
        $shipments = data_get($order, 'extension_attributes.shipping_assignments', []);
        if (!is_array($shipments) || count($shipments) === 0) {
            return '';
        }

        // Use order status as the delivery state value since Magento's status
        // encodes fulfillment info (e.g. "processing", "complete").
        return MagentoStateResolver::orderStatus($order);
    }

    /**
     * Magento order state: reads the flat `status` string (most granular),
     * falling back to the `state` string.
     */
    private function orderState(array $order): string
    {
        return MagentoStateResolver::technicalName($order);
    }

    /**
     * Fallback heuristics using Magento order status/state strings.
     */
    private function financialStatusFallbackFromRawStates(array $order): string
    {
        $status = strtolower(trim((string) data_get($order, 'status', '')));
        $state  = strtolower(trim((string) data_get($order, 'state', '')));
        $method = strtolower(trim((string) data_get($order, 'payment.method', '')));

        $joined = $status . '|' . $state . '|' . $method;

        if (str_contains($joined, 'complete') || str_contains($joined, 'paid')
            || str_contains($joined, 'processing') || str_contains($joined, 'authorize')) {
            return 'PAID';
        }

        if (str_contains($joined, 'free') && (str_contains($joined, 'complete') || str_contains($joined, 'processing'))) {
            return 'PAID';
        }

        if (str_contains($joined, 'cancel') || str_contains($joined, 'closed')) {
            return 'VOIDED';
        }

        if (str_contains($joined, 'refund')) {
            return 'REFUNDED';
        }

        return 'PENDING';
    }

    /**
     * Fallback heuristics for Magento fulfillment based on order status/state.
     */
    private function fulfillmentFallbackFromRawStates(array $order): ?string
    {
        $status = strtolower(trim((string) data_get($order, 'status', '')));
        $state  = strtolower(trim((string) data_get($order, 'state', '')));
        $joined = $status . '|' . $state;

        if (str_contains($joined, 'complete') || str_contains($joined, 'shipped')) {
            return 'FULFILLED';
        }

        if (str_contains($joined, 'partial')) {
            return 'PARTIAL';
        }

        if (str_contains($joined, 'cancel') || str_contains($joined, 'closed')) {
            return 'RESTOCKED';
        }

        return null;
    }

    public function fulfillmentOptionLabel(string $value): string
    {
        return $this->optionLabel('fulfillment', $value);
    }

    public function transactionStatusForFinancialStatus(?string $financialStatus): string
    {
        return match ($financialStatus) {
            'PAID', 'PARTIALLY_PAID', 'REFUNDED', 'PARTIALLY_REFUNDED' => 'SUCCESS',
            'VOIDED' => 'FAILURE',
            default => 'PENDING',
        };
    }

    public function fulfillmentStatusValue(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '' || $value === 'unfulfilled') {
            return null;
        }

        return match ($value) {
            'fulfilled' => 'FULFILLED',
            'partial' => 'PARTIAL',
            'restocked' => 'RESTOCKED',
            default => null,
        };
    }

    public function humanize(string $value): string
    {
        $value = trim(str_replace(['_', '-'], ' ', $value));
        if ($value === '') {
            return '';
        }

        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }
}
