<?php

namespace App\Services\Migration;

use App\Models\Shop;
use App\Models\ShopifyIdMapping;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use App\Services\Shopware\ShopwareClient;
use App\Support\ShopwareStateResolver;

class OrderPayloadMapper
{
    /**
     * @var array<int, array<string, array<string, mixed>>>
     */
    private array $variantCache = [];

    /**
     * @return array{order: array<string, mixed>, metafields: array<int, array<string, mixed>>, shopware_raw: array<string, mixed>}
     */
    public function mapOrder(Shop $shop, array $order, ?string $shopifyLocationGid = null): array
    {
        $assignments = app(StateAssignmentMapper::class);
        $currency = $this->resolveCurrencyCode($shop, $order);

        $orderNumber = (string) (data_get($order, 'orderNumber') ?: data_get($order, 'id') ?: '');
        $email = (string) (data_get($order, 'orderCustomer.email') ?: data_get($order, 'orderCustomer.customer.email') ?: data_get($order, 'customer.email') ?: '');
        $rawPhone = (string) (data_get($order, 'billingAddress.phoneNumber') ?: data_get($order, 'addresses.0.phoneNumber') ?: '');
        $phone = $this->normalizeE164Phone($rawPhone);

        $billing = $this->finalizeMailingAddressForShopify($this->resolveBillingAddress($order));
        $shipping = $this->finalizeMailingAddressForShopify($this->resolveShippingAddress($order), $billing);

        $lineItems = $this->mapLineItems($shop, $order, $currency);
        if (count($lineItems) === 0) {
            $lineItems[] = [
                'title' => 'Imported order',
                'quantity' => 1,
                'priceSet' => [
                    'shopMoney' => [
                        'amount' => $this->formatAmount((float) (data_get($order, 'amountTotal') ?: 0)),
                        'currencyCode' => $currency,
                    ],
                ],
            ];
        }

        $shippingLines = $this->mapShippingLines($shop, $order, $currency, $assignments);
        $shippingLines = $this->ensureShippingLinesPresent($shop, $order, $currency, $assignments, $shippingLines);

        $financialStatus = $this->mapFinancialStatus($shop, $order, $assignments);
        $fulfillmentStatus = $this->mapFulfillmentStatus($shop, $order, $assignments);

        $paymentCapture = null;
        if ($financialStatus === 'PAID') {
            $paymentCapture = $this->buildManualPaymentCapture($shop, $order, $currency, $assignments);
            if ($paymentCapture !== null) {
                // Phase 2: record payment via orderCreateManualPayment for a proper timeline entry.
                $transactions = [];
                $financialStatus = 'PENDING';
            } else {
                $transactions = $this->mapTransactions($shop, $order, $currency, $assignments, 'PAID');
            }
        } else {
            $transactions = $this->mapTransactions($shop, $order, $currency, $assignments, $financialStatus);
        }

        $lineItems = $this->applyRequiresShippingToLineItems(
            $lineItems,
            $order,
            $shippingLines,
            $shipping,
            $fulfillmentStatus
        );

        $paymentMethodName = $this->paymentMethodName($shop, $order);
        $shippingMethodName = $this->effectiveShippingMethodName($shop, $order);

        $tags = [];
        $tags[] = 'shopware';
        if ($orderNumber !== '') {
            $tags[] = 'SW_ORDER_'.$orderNumber;
        }
        $tags[] = 'SW_ORDER_ID_'.(string) data_get($order, 'id');

        if ($paymentMethodName !== '') {
            $tags[] = 'SW_PAYMENT_'.strtoupper($paymentMethodName);
            $mappedPaymentMethod = $assignments->mappedValue($shop, 'payment_methods', $paymentMethodName);
            if (is_string($mappedPaymentMethod) && $mappedPaymentMethod !== '') {
                $tags[] = 'SW_PAYMENT_MAPPED_'.$assignments->optionLabel('payment_methods', $mappedPaymentMethod);
            }
        }

        if ($shippingMethodName !== '') {
            $tags[] = 'SW_SHIPPING_'.strtoupper($shippingMethodName);
            $mappedShippingMethod = $assignments->mappedValue($shop, 'shipping_methods', $shippingMethodName);
            if (is_string($mappedShippingMethod) && $mappedShippingMethod !== '') {
                $tags[] = 'SW_SHIPPING_MAPPED_'.$assignments->optionLabel('shipping_methods', $mappedShippingMethod);
            }
        }

        $tags = $this->sanitizeTags($tags);

        $noteParts = [];
        if ($orderNumber !== '') {
            $noteParts[] = 'Shopware orderNumber: '.$orderNumber;
        }
        $noteParts[] = 'Shopware orderId: '.(string) data_get($order, 'id');
        $orderStateTechnical = ShopwareStateResolver::technicalName($order);
        if ($orderStateTechnical !== '') {
            $noteParts[] = 'Shopware state: '.$orderStateTechnical;
        }

        if ($paymentMethodName !== '') {
            $noteParts[] = 'Shopware payment method: '.$paymentMethodName;
            $mappedPaymentMethod = $assignments->mappedValue($shop, 'payment_methods', $paymentMethodName);
            if (is_string($mappedPaymentMethod) && $mappedPaymentMethod !== '') {
                $noteParts[] = 'Mapped payment method: '.$assignments->optionLabel('payment_methods', $mappedPaymentMethod);
            }
        }

        if ($shippingMethodName !== '') {
            $noteParts[] = 'Shopware shipping method: '.$shippingMethodName;
            $mappedShippingMethod = $assignments->mappedValue($shop, 'shipping_methods', $shippingMethodName);
            if (is_string($mappedShippingMethod) && $mappedShippingMethod !== '') {
                $noteParts[] = 'Mapped shipping method: '.$assignments->optionLabel('shipping_methods', $mappedShippingMethod);
            }
        }

        if ($phone === null && trim($rawPhone) !== '') {
            $noteParts[] = 'Shopware phone: '.trim($rawPhone);
        }

        $customerComment = trim((string) data_get($order, 'customerComment', ''));
        if ($customerComment !== '') {
            $noteParts[] = 'Customer comment: '.$customerComment;
        }

        $internalComment = trim((string) (
            data_get($order, 'internalComment')
            ?: data_get($order, 'comments.internal')
            ?: data_get($order, 'customFields.internal_comment')
            ?: data_get($order, 'customFields.internalComment')
            ?: ''
        ));
        if ($internalComment !== '') {
            $noteParts[] = 'Internal note: '.$internalComment;
        }

        // Append document summary to note — clean format, no URLs (URLs go in custom attributes)
        $documents = $this->extractDocuments($order, $shop);
        if (count($documents) > 0) {
            $docLines = ['Documents:'];
            foreach ($documents as $doc) {
                $typeName = (string) ($doc['typeName'] ?? $doc['typeKey'] ?? 'Document');
                $docNum   = (string) ($doc['documentNumber'] ?? '');
                $date     = (string) ($doc['createdAt'] ?? '');
                $datePart = $date !== '' ? ' (' . substr($date, 0, 10) . ')' : '';
                $docLines[] = '- ' . $typeName . ($docNum !== '' ? ' #' . $docNum : '') . $datePart;
            }
            $noteParts[] = implode("\n", $docLines);
        }

        $note = implode("\n", array_values(array_filter($noteParts)));

        $processedAt = (string) (data_get($order, 'orderDateTime') ?: data_get($order, 'createdAt') ?: '');

        $customerGid = $this->resolveShopifyCustomerGid($shop, $order);

        $payload = [
            'currency' => $currency,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone,
            'billingAddress' => $billing,
            'shippingAddress' => $shipping,
            'lineItems' => $lineItems,
            'shippingLines' => $shippingLines,
            'transactions' => $transactions,
            'financialStatus' => $financialStatus,
            'processedAt' => $processedAt !== '' ? $processedAt : null,
            'sourceName' => 'shopware',
            'sourceIdentifier' => (string) data_get($order, 'id'),
            'name' => $orderNumber !== '' ? $orderNumber : null,
            'note' => $note !== '' ? $note : null,
            'tags' => $tags,
        ];

        if (is_string($fulfillmentStatus) && $fulfillmentStatus !== '') {
            $payload['fulfillmentStatus'] = $fulfillmentStatus;
        }

        $fulfillmentInput = $this->buildFulfillmentInput($fulfillmentStatus, $shopifyLocationGid);
        if ($fulfillmentInput !== null) {
            $payload['fulfillment'] = $fulfillmentInput;
        }

        $customAttributes = $this->mapCustomAttributes($order);
        foreach ($this->mapAssignmentCustomAttributes($shop, $order, $assignments) as $attr) {
            $customAttributes[] = $attr;
        }
        if ($customerComment !== '') {
            $customAttributes[] = [
                'key' => 'shopware_customer_comment',
                'value' => $this->limitCustomAttributeValue($customerComment),
            ];
        }
        if ($internalComment !== '') {
            $customAttributes[] = [
                'key' => 'shopware_internal_comment',
                'value' => $this->limitCustomAttributeValue($internalComment),
            ];
        }

        // Document custom attributes — invoice numbers, delivery note numbers, download URLs
        foreach ($this->buildDocumentCustomAttributes($order, $shop) as $attr) {
            $customAttributes[] = $attr;
        }

        if (count($customAttributes) > 0) {
            $payload['customAttributes'] = $customAttributes;
        }

        $discountCode = $this->mapOrderDiscountCode($order, $currency);
        if ($discountCode !== null) {
            $payload['discountCode'] = $discountCode;
        }

        if ($customerGid !== '') {
            $payload['customerId'] = $customerGid;
        }

        $payload = $this->removeEmpty($payload);

        $metafields = $this->mapShopwareMetafields($shop, $order);

        return [
            'order' => $payload,
            'metafields' => $metafields,
            'shopware_raw' => $order,
            'payment_capture' => $paymentCapture,
        ];
    }

    /**
     * @param array<int, string> $tags
     * @return array<int, string>
     */
    private function sanitizeTags(array $tags): array
    {
        $out = [];
        foreach ($tags as $t) {
            if (!is_string($t)) {
                continue;
            }

            $t = trim($t);
            if ($t === '') {
                continue;
            }

            $t = preg_replace('/[^A-Za-z0-9 _\-]/', '_', $t);
            $t = is_string($t) ? $t : '';
            $t = preg_replace('/\s+/', ' ', $t);
            $t = is_string($t) ? trim($t) : '';
            if ($t === '') {
                continue;
            }

            if (strlen($t) > 255) {
                $t = substr($t, 0, 255);
            }

            $out[$t] = true;
        }

        return array_keys($out);
    }

    private function resolveCurrencyCode(Shop $shop, array $order): string
    {
        $candidate = (string) (data_get($order, 'currency.isoCode') ?: data_get($order, 'currency.shortName') ?: '');
        $candidate = strtoupper(trim($candidate));
        if ($candidate !== '' && preg_match('/^[A-Z]{3}$/', $candidate) === 1) {
            return $candidate;
        }

        $currencyId = (string) data_get($order, 'currencyId');
        if ($currencyId !== '' && $shop->shopwareConnection) {
            $shopware = app(ShopwareClient::class);
            $resolved = $shopware->resolveCurrencyIsoCode($shop->shopwareConnection, $currencyId);
            if (is_string($resolved) && $resolved !== '' && preg_match('/^[A-Z]{3}$/', $resolved) === 1) {
                return $resolved;
            }
        }

        return 'USD';
    }

    /**
     * @return array<int, array{key: string, value: string}>
     */
    private function mapCustomAttributes(array $order): array
    {
        $customFields = data_get($order, 'customFields');
        if (!is_array($customFields) || count($customFields) === 0) {
            return [];
        }

        $out = [];
        foreach ($customFields as $k => $v) {
            if (!is_string($k) || $k === '') {
                continue;
            }

            foreach ($this->flattenCustomAttributeValue($k, $v) as $attr) {
                $out[] = $attr;
            }
        }

        return $out;
    }

    /**
     * @return array<int, array{key: string, value: string}>
     */
    private function mapAssignmentCustomAttributes(Shop $shop, array $order, StateAssignmentMapper $assignments): array
    {
        $out = [];

        $orderState = ShopwareStateResolver::technicalName($order);

        $txState = $this->transactionStateTechnicalName($order);
        if ($txState === '' && $orderState !== '') {
            $txState = $orderState;
        }

        $deliveryState = $this->deliveryStateTechnicalName($order);
        if ($deliveryState === '' && $orderState !== '') {
            $deliveryState = $orderState;
        }

        $this->appendFinancialStateMapping($out, $shop, $assignments, 'order', 'order_state', $orderState);
        $this->appendFinancialStateMapping($out, $shop, $assignments, 'transaction', 'transaction_state', $txState);
        $this->appendFulfillmentStateMapping($out, $shop, $assignments, 'delivery', $deliveryState);

        $paymentMethodName = $this->paymentMethodName($shop, $order);
        $this->appendMethodMapping(
            $out,
            $shop,
            $assignments,
            'payment',
            'payment_methods',
            $paymentMethodName
        );

        $shippingMethodName = $this->effectiveShippingMethodName($shop, $order);
        $this->appendMethodMapping(
            $out,
            $shop,
            $assignments,
            'shipping',
            'shipping_methods',
            $shippingMethodName
        );

        return $out;
    }

    /**
     * @param array<int, array{key: string, value: string}> $out
     */
    private function appendFinancialStateMapping(
        array &$out,
        Shop $shop,
        StateAssignmentMapper $assignments,
        string $label,
        string $mappingType,
        string $shopwareState
    ): void {
        $shopwareState = strtolower(trim($shopwareState));
        if ($shopwareState === '') {
            return;
        }

        $mapped = $assignments->mappedValue($shop, $mappingType, $shopwareState);
        $shopifyLabel = is_string($mapped) && $mapped !== ''
            ? $assignments->optionLabel('order_financial', $mapped)
            : '';

        $out[] = [
            'key' => 'Shopware '.$label.' state',
            'value' => $this->humanizeStateLabel($shopwareState),
        ];

        if ($shopifyLabel !== '') {
            $out[] = [
                'key' => 'Shopify '.$label.' state',
                'value' => $shopifyLabel,
            ];
        }
    }

    /**
     * @param array<int, array{key: string, value: string}> $out
     */
    private function appendFulfillmentStateMapping(
        array &$out,
        Shop $shop,
        StateAssignmentMapper $assignments,
        string $label,
        string $shopwareState
    ): void {
        $shopwareState = strtolower(trim($shopwareState));
        if ($shopwareState === '') {
            return;
        }

        $mapped = $assignments->mappedValue($shop, 'delivery_state', $shopwareState);
        $shopifyLabel = is_string($mapped) && $mapped !== ''
            ? $assignments->fulfillmentOptionLabel($mapped)
            : '';

        $out[] = [
            'key' => 'Shopware '.$label.' state',
            'value' => $this->humanizeStateLabel($shopwareState),
        ];

        if ($shopifyLabel !== '') {
            $out[] = [
                'key' => 'Shopify '.$label.' state',
                'value' => $shopifyLabel,
            ];
        }
    }

    /**
     * @param array<int, array{key: string, value: string}> $out
     */
    private function appendMethodMapping(
        array &$out,
        Shop $shop,
        StateAssignmentMapper $assignments,
        string $label,
        string $mappingType,
        string $shopwareName
    ): void {
        $shopwareName = trim($shopwareName);
        if ($shopwareName === '') {
            return;
        }

        $mapped = $assignments->mappedValue($shop, $mappingType, $shopwareName);
        $shopifyLabel = is_string($mapped) && $mapped !== ''
            ? $assignments->optionLabel($mappingType, $mapped)
            : '';

        $out[] = [
            'key' => 'Shopware '.$label.' method',
            'value' => $shopwareName,
        ];

        $shopifyDisplay = $shopifyLabel !== '' ? $shopifyLabel : $shopwareName;
        if ($shopifyDisplay !== '') {
            $out[] = [
                'key' => 'Shopify '.$label.' method',
                'value' => $shopifyDisplay,
            ];
        }
    }

    private function humanizeStateLabel(string $state): string
    {
        $state = trim(str_replace(['_', '-'], ' ', $state));
        if ($state === '') {
            return '';
        }

        return mb_convert_case($state, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function mapShopwareMetafields(mixed $shop, array $order = []): array
    {
        if (is_array($shop) && $order === []) {
            $order = $shop;
            $shop = null;
        }

        $rawJson = json_encode($order, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($rawJson)) {
            $rawJson = '{}';
        }

        $out = [];

        $orderId = (string) data_get($order, 'id');
        if ($orderId !== '') {
            $out[] = [
                'namespace' => 'shopware',
                'key' => 'order_id',
                'type' => 'single_line_text_field',
                'value' => $orderId,
            ];
        }

        $orderNumber = (string) data_get($order, 'orderNumber');
        if ($orderNumber !== '') {
            $out[] = [
                'namespace' => 'shopware',
                'key' => 'order_number',
                'type' => 'single_line_text_field',
                'value' => $orderNumber,
            ];
        }

        $paymentMethodName = (string) (data_get($order, 'transactions.0.paymentMethod.translated.name')
            ?: data_get($order, 'transactions.0.paymentMethod.name')
            ?: '');
        if ($paymentMethodName !== '') {
            $out[] = [
                'namespace' => 'shopware',
                'key' => 'payment_method_name',
                'type' => 'single_line_text_field',
                'value' => $paymentMethodName,
            ];

            if ($shop) {
                $assignments = app(StateAssignmentMapper::class);
                $mapped = $assignments->mappedValue($shop, 'payment_methods', $paymentMethodName);
                if (is_string($mapped) && $mapped !== '') {
                    $out[] = [
                        'namespace' => 'shopware',
                        'key' => 'payment_method_mapped',
                        'type' => 'single_line_text_field',
                        'value' => $assignments->optionLabel('payment_methods', $mapped),
                    ];
                }
            }
        }

        $paymentMethodId = (string) (data_get($order, 'transactions.0.paymentMethodId') ?: data_get($order, 'transactions.0.paymentMethod.id') ?: '');
        if ($paymentMethodId !== '') {
            $out[] = [
                'namespace' => 'shopware',
                'key' => 'payment_method_id',
                'type' => 'single_line_text_field',
                'value' => $paymentMethodId,
            ];
        }

        $shippingMethodName = (string) (data_get($order, 'deliveries.0.shippingMethod.translated.name')
            ?: data_get($order, 'deliveries.0.shippingMethod.name')
            ?: '');
        if ($shippingMethodName !== '') {
            $out[] = [
                'namespace' => 'shopware',
                'key' => 'shipping_method_name',
                'type' => 'single_line_text_field',
                'value' => $shippingMethodName,
            ];

            if ($shop) {
                $assignments = app(StateAssignmentMapper::class);
                $mapped = $assignments->mappedValue($shop, 'shipping_methods', $shippingMethodName);
                if (is_string($mapped) && $mapped !== '') {
                    $out[] = [
                        'namespace' => 'shopware',
                        'key' => 'shipping_method_mapped',
                        'type' => 'single_line_text_field',
                        'value' => $assignments->optionLabel('shipping_methods', $mapped),
                    ];
                }
            }
        }

        $shippingMethodId = (string) (data_get($order, 'deliveries.0.shippingMethodId') ?: data_get($order, 'deliveries.0.shippingMethod.id') ?: '');
        if ($shippingMethodId !== '') {
            $out[] = [
                'namespace' => 'shopware',
                'key' => 'shipping_method_id',
                'type' => 'single_line_text_field',
                'value' => $shippingMethodId,
            ];
        }

        $orderState = ShopwareStateResolver::technicalName($order);
        if ($orderState !== '') {
            $out[] = [
                'namespace' => 'shopware',
                'key' => 'order_state',
                'type' => 'single_line_text_field',
                'value' => $orderState,
            ];

            if ($shop) {
                $assignments = app(StateAssignmentMapper::class);
                $mapped = $assignments->mappedValue($shop, 'order_state', $orderState);
                if (is_string($mapped) && $mapped !== '') {
                    $out[] = [
                        'namespace' => 'shopware',
                        'key' => 'order_state_mapped',
                        'type' => 'single_line_text_field',
                        'value' => $assignments->optionLabel('order_financial', $mapped),
                    ];
                }
            }
        }

        $transactionState = $this->transactionStateTechnicalName($order);
        if ($transactionState !== '') {
            $out[] = [
                'namespace' => 'shopware',
                'key' => 'transaction_state',
                'type' => 'single_line_text_field',
                'value' => $transactionState,
            ];

            if ($shop) {
                $assignments = app(StateAssignmentMapper::class);
                $mapped = $assignments->mappedValue($shop, 'transaction_state', $transactionState);
                if (is_string($mapped) && $mapped !== '') {
                    $out[] = [
                        'namespace' => 'shopware',
                        'key' => 'transaction_state_mapped',
                        'type' => 'single_line_text_field',
                        'value' => $assignments->optionLabel('order_financial', $mapped),
                    ];
                }
            }
        }

        $deliveryState = $this->deliveryStateTechnicalName($order);
        if ($deliveryState !== '') {
            $out[] = [
                'namespace' => 'shopware',
                'key' => 'delivery_state',
                'type' => 'single_line_text_field',
                'value' => $deliveryState,
            ];

            if ($shop) {
                $assignments = app(StateAssignmentMapper::class);
                $mapped = $assignments->mappedValue($shop, 'delivery_state', $deliveryState);
                if (is_string($mapped) && $mapped !== '') {
                    $out[] = [
                        'namespace' => 'shopware',
                        'key' => 'delivery_state_mapped',
                        'type' => 'single_line_text_field',
                        'value' => $assignments->fulfillmentOptionLabel($mapped),
                    ];
                }
            }
        }

        $out[] = [
            'namespace' => 'shopware',
            'key' => 'raw_json',
            'type' => 'json',
            'value' => $rawJson,
        ];

        // Documents metafield — structured JSON of all order documents
        $documents = $this->extractDocuments($order);
        if (count($documents) > 0) {
            $docsJson = json_encode($documents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($docsJson) && strlen($docsJson) <= 65536) {
                $out[] = [
                    'namespace' => 'shopware',
                    'key' => 'documents_json',
                    'type' => 'json',
                    'value' => $docsJson,
                ];
            }
        }

        return $out;
    }

    /**
     * Public wrapper for extractDocuments — used by ProcessOrderMigrationItemJob
     * to pass documents to ShopifyOrderDocumentSyncService.
     *
     * @param array<string, mixed> $order
     * @return array<int, array{id: string, typeKey: string, typeName: string, documentNumber: string, createdAt: string, downloadUrl: string}>
     */
    public function extractDocumentsPublic(array $order): array
    {
        return $this->extractDocuments($order);
    }

    /**
     * Extract and normalise order documents from the Shopware order payload.
     * Returns a structured array ready for metafields and note building.
     *
     * @param array<string, mixed> $order
     * @return array<int, array{id: string, typeKey: string, typeName: string, documentNumber: string, createdAt: string, downloadUrl: string}>
     */
    private function extractDocuments(array $order, ?Shop $shop = null): array
    {
        $raw = data_get($order, 'documents', []);
        if (!is_array($raw) || count($raw) === 0) {
            return [];
        }

        $typeLabels = [
            'invoice'               => 'Invoice',
            'delivery_note'         => 'Delivery Note',
            'credit_note'           => 'Credit Note',
            'cancellation_invoice'  => 'Cancellation Invoice',
            'storno_bill'           => 'Storno Bill',
        ];

        $out = [];
        foreach ($raw as $doc) {
            if (!is_array($doc)) {
                continue;
            }

            $id           = (string) ($doc['id'] ?? '');
            $deepLinkCode = (string) ($doc['deepLinkCode'] ?? '');
            $docNumber    = (string) (
                data_get($doc, 'config.documentNumber')
                ?: data_get($doc, 'documentNumber')
                ?: ''
            );
            $typeKey  = (string) data_get($doc, 'documentType.technicalName', '');
            $typeName = (string) (
                data_get($doc, 'documentType.translated.name')
                ?: data_get($doc, 'documentType.name')
                ?: ($typeLabels[$typeKey] ?? ucwords(str_replace('_', ' ', $typeKey)))
            );
            $createdAt = (string) ($doc['createdAt'] ?? '');

            $apiBase = (string) ($doc['_api_base'] ?? '');
            if ($apiBase === '' && $shop && $shop->shopwareConnection) {
                $apiBase = (string) $shop->shopwareConnection->api_url;
            }

            // Build the Shopware document download URL
            // api_url is e.g. "http://localhost/SW6771/public" — document endpoint is at /api/_action/document/
            $downloadUrl = '';
            if ($id !== '' && $deepLinkCode !== '' && $apiBase !== '') {
                $downloadUrl = rtrim($apiBase, '/') . '/api/_action/document/' . $id . '/' . $deepLinkCode;
            }

            $out[] = [
                'id'             => $id,
                'typeKey'        => $typeKey,
                'typeName'       => $typeName,
                'documentNumber' => $docNumber,
                'createdAt'      => $createdAt,
                'downloadUrl'    => $downloadUrl,
            ];
        }

        return $out;
    }

    /**
     * Build Shopify custom attributes for order documents.
     * Stores document type + number + date. The Shopify CDN URL is added later
     * by ProcessOrderMigrationItemJob after the PDF is uploaded to Shopify Files.
     *
     * @param array<string, mixed> $order
     * @return array<int, array{key: string, value: string}>
     */
    private function buildDocumentCustomAttributes(array $order, Shop $shop): array
    {
        $documents = $this->extractDocuments($order, $shop);
        if (count($documents) === 0) {
            return [];
        }

        $out = [];
        $byType = [];

        foreach ($documents as $doc) {
            $typeKey = $doc['typeKey'] ?: 'document';
            if (!isset($byType[$typeKey])) {
                $byType[$typeKey] = [];
            }
            $byType[$typeKey][] = $doc;
        }

        foreach ($byType as $typeKey => $docs) {
            foreach ($docs as $i => $doc) {
                $typeName  = (string) ($doc['typeName'] ?? ucwords(str_replace('_', ' ', $typeKey)));
                $suffix    = count($docs) > 1 ? ' ' . ($i + 1) : '';
                $baseLabel = 'Shopware ' . $typeName . $suffix;

                $docNum = $doc['documentNumber'];
                $date   = $doc['createdAt'] !== '' ? substr($doc['createdAt'], 0, 10) : '';

                // 1. Add descriptive info (Number + Date) — no URL here, CDN URL set by job after upload
                $descriptionValue = '';
                if ($docNum !== '') {
                    $descriptionValue .= '#' . $docNum;
                }
                if ($date !== '') {
                    $descriptionValue .= ($descriptionValue !== '' ? ' ' : '') . '(' . $date . ')';
                }

                if ($descriptionValue !== '') {
                    $out[] = [
                        'key'   => $this->limitCustomAttributeValue($baseLabel),
                        'value' => $this->limitCustomAttributeValue($descriptionValue),
                    ];
                }

                // 2. Emit empty value for "Download" key to remove any old localhost URL entries
                $out[] = [
                    'key'   => $this->limitCustomAttributeValue($baseLabel . ' Download'),
                    'value' => '',
                ];
            }
        }

        return $out;
    }

    private function resolveShopifyCustomerGid(Shop $shop, array $order): string
    {
        $customerId = (string) (data_get($order, 'orderCustomer.customerId') ?: data_get($order, 'orderCustomer.customer.id') ?: data_get($order, 'orderCustomer.customer.id') ?: '');
        if ($customerId === '') {
            return '';
        }

        $mapping = ShopifyIdMapping::query()
            ->where('shop_id', $shop->id)
            ->where('entity_type', 'customer')
            ->where('source_id', $customerId)
            ->first();

        $gid = $mapping ? (string) $mapping->shopify_gid : '';
        return $gid;
    }

    private function mapFinancialStatus(Shop $shop, array $order, ?StateAssignmentMapper $assignments = null): string
    {
        $assignments = $assignments ?? app(StateAssignmentMapper::class);

        return $assignments->resolveFinancialStatus($shop, $order);
    }

    private function mapFulfillmentStatus(Shop $shop, array $order, ?StateAssignmentMapper $assignments = null): ?string
    {
        $assignments = $assignments ?? app(StateAssignmentMapper::class);

        return $assignments->resolveFulfillmentStatus($shop, $order);
    }

    private function transactionStateTechnicalName(array $order): string
    {
        $tx = data_get($order, 'transactions', []);
        if (!is_array($tx) || !isset($tx[0]) || !is_array($tx[0])) {
            return '';
        }

        return ShopwareStateResolver::technicalName($tx[0]);
    }

    private function deliveryStateTechnicalName(array $order): string
    {
        $deliveries = data_get($order, 'deliveries', []);
        if (!is_array($deliveries) || !isset($deliveries[0]) || !is_array($deliveries[0])) {
            return '';
        }

        return ShopwareStateResolver::technicalName($deliveries[0]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapLineItems(Shop $shop, array $order, string $currency): array
    {
        $items = data_get($order, 'lineItems', []);
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $li) {
            if (!is_array($li)) {
                continue;
            }

            $qty = (int) (data_get($li, 'quantity') ?: 0);
            if ($qty <= 0) {
                $qty = 1;
            }

            $label = $this->lineItemTitleWithOptions($li);

            $unit = data_get($li, 'unitPrice');
            $unitNum = is_numeric($unit) ? (float) $unit : null;
            if ($unitNum === null) {
                $price = data_get($li, 'price.totalPrice');
                $priceNum = is_numeric($price) ? (float) $price : 0.0;
                $unitNum = $qty > 0 ? ($priceNum / $qty) : $priceNum;
            }

            $payload = [
                'title' => $label,
                'quantity' => $qty,
                'priceSet' => [
                    'shopMoney' => [
                        'amount' => $this->formatAmount($unitNum),
                        'currencyCode' => $currency,
                    ],
                ],
            ];

            $sku = (string) (data_get($li, 'payload.productNumber') ?: data_get($li, 'payload.productNumber') ?: data_get($li, 'identifier') ?: '');
            if ($sku !== '') {
                $payload['sku'] = $sku;
            }

            $shopifyProduct = $this->resolveShopifyProductForLineItem($shop, $li, $sku);
            $shopifyProductGid = (string) ($shopifyProduct['product_gid'] ?? '');
            $shopifyVariantGid = (string) ($shopifyProduct['variant_gid'] ?? '');
            if ($shopifyProductGid !== '') {
                $payload['productId'] = $shopifyProductGid;
            }
            if ($shopifyVariantGid !== '') {
                $payload['variantId'] = $shopifyVariantGid;
            }

            $taxRate = data_get($li, 'price.calculatedTaxes.0.taxRate');
            $taxPrice = data_get($li, 'price.calculatedTaxes.0.tax');
            if (is_numeric($taxRate) && is_numeric($taxPrice)) {
                $payload['taxLines'] = [[
                    'title' => 'Tax',
                    'rate' => ((float) $taxRate) / 100,
                    'priceSet' => [
                        'shopMoney' => [
                            'amount' => $this->formatAmount((float) $taxPrice),
                            'currencyCode' => $currency,
                        ],
                    ],
                ]];
            }

            $properties = $this->lineItemOptionProperties($li);
            if (count($properties) > 0) {
                $payload['properties'] = $properties;
            }

            $out[] = $payload;
        }

        return $out;
    }

    /**
     * @return array<int, array{key: string, value: string}>
     */
    private function flattenCustomAttributeValue(string $key, mixed $value): array
    {
        $key = trim($key);
        if ($key === '') {
            return [];
        }

        if (is_scalar($value) || $value === null) {
            $stringValue = $value === null ? '' : trim((string) $value);
            if ($stringValue === '') {
                return [];
            }

            $decoded = json_decode($stringValue, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->flattenCustomAttributeValue($key, $decoded);
            }

            return [[
                'key' => $key,
                'value' => $this->limitCustomAttributeValue($stringValue),
            ]];
        }

        if ($this->isPluginInputFieldPayload($value)) {
            return $this->flattenPluginInputFields($value);
        }

        if (is_array($value) && $this->isAssoc($value)) {
            if ($this->isPluginInputFieldPayload([$value])) {
                return $this->flattenPluginInputFields([$value]);
            }

            $out = [];
            foreach ($value as $childKey => $childValue) {
                if (!is_string($childKey) || $childKey === '') {
                    continue;
                }

                foreach ($this->flattenCustomAttributeValue($key.'_'.$childKey, $childValue) as $attr) {
                    $out[] = $attr;
                }
            }

            return $out;
        }

        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $part = trim((string) $item);
                    if ($part !== '') {
                        $parts[] = $part;
                    }
                }
            }

            if (count($parts) > 0) {
                return [[
                    'key' => $key,
                    'value' => $this->limitCustomAttributeValue(implode(', ', $parts)),
                ]];
            }
        }

        return [];
    }

    private function isPluginInputFieldPayload(mixed $value): bool
    {
        if (!is_array($value) || count($value) === 0) {
            return false;
        }

        foreach ($value as $field) {
            if (!is_array($field)) {
                return false;
            }

            if (!array_key_exists('value', $field)) {
                return false;
            }

            if (!array_key_exists('label', $field) && !array_key_exists('name', $field) && !array_key_exists('type', $field)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, array{key: string, value: string}>
     */
    private function flattenPluginInputFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $label = (string) (data_get($field, 'label') ?: data_get($field, 'name') ?: data_get($field, 'type') ?: '');
            $label = $this->normalizeAttributeKey($label);
            if ($label === '') {
                continue;
            }

            $value = data_get($field, 'value');
            if (is_array($value)) {
                $value = implode(', ', array_values(array_filter(array_map(
                    fn ($v) => is_scalar($v) ? trim((string) $v) : '',
                    $value
                ))));
            }

            $value = is_scalar($value) || $value === null ? trim((string) $value) : '';
            if ($value === '') {
                continue;
            }

            $out[] = [
                'key' => $label,
                'value' => $this->limitCustomAttributeValue($value),
            ];
        }

        return $out;
    }

    /**
     * @return array{product_gid?: string, variant_gid?: string}
     */
    private function resolveShopifyProductForLineItem(Shop $shop, array $lineItem, string $sku): array
    {
        $sourceIds = array_values(array_unique(array_filter([
            (string) data_get($lineItem, 'product.parentId', ''),
            (string) data_get($lineItem, 'payload.parentId', ''),
            (string) data_get($lineItem, 'productId', ''),
            (string) data_get($lineItem, 'referencedId', ''),
            (string) data_get($lineItem, 'product.id', ''),
            (string) data_get($lineItem, 'payload.productId', ''),
        ], fn ($id) => is_string($id) && trim($id) !== '')));

        $productGid = '';
        foreach ($sourceIds as $sourceId) {
            $mapping = ShopifyIdMapping::query()
                ->where('shop_id', $shop->id)
                ->where('entity_type', 'product')
                ->where('source_id', $sourceId)
                ->first();

            if ($mapping && is_string($mapping->shopify_gid) && $mapping->shopify_gid !== '') {
                $productGid = $mapping->shopify_gid;
                break;
            }
        }

        if ($productGid === '') {
            return [];
        }

        $variantGid = $this->resolveVariantGid($shop, $productGid, $sku);

        return array_filter([
            'product_gid' => $productGid,
            'variant_gid' => $variantGid,
        ], fn ($v) => is_string($v) && $v !== '');
    }

    private function resolveVariantGid(Shop $shop, string $productGid, string $sku): string
    {
        $variants = $this->fetchShopifyVariants($shop, $productGid);
        if (count($variants) === 0) {
            return '';
        }

        $sku = trim($sku);
        if ($sku !== '') {
            foreach ($variants as $variant) {
                if (strcasecmp((string) ($variant['sku'] ?? ''), $sku) === 0) {
                    return (string) ($variant['id'] ?? '');
                }
            }
        }

        if (count($variants) === 1) {
            return (string) ($variants[0]['id'] ?? '');
        }

        return '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchShopifyVariants(Shop $shop, string $productGid): array
    {
        $shopId = (int) $shop->id;
        if (isset($this->variantCache[$shopId][$productGid])) {
            return $this->variantCache[$shopId][$productGid];
        }

        $query = <<<'GQL'
query ProductVariantsForOrder($id: ID!) {
  product(id: $id) {
    variants(first: 100) {
      nodes {
        id
        sku
      }
    }
  }
}
GQL;

        try {
            $client = app(ShopifyAdminGraphqlClient::class);
            $res = $client->query($shop, $query, ['id' => $productGid]);
            if (isset($res['errors'])) {
                $this->variantCache[$shopId][$productGid] = [];
                return [];
            }

            $nodes = data_get($res, 'data.product.variants.nodes', []);
            $variants = is_array($nodes) ? array_values(array_filter($nodes, fn ($n) => is_array($n))) : [];
            $this->variantCache[$shopId][$productGid] = $variants;
            return $variants;
        } catch (\Throwable $e) {
            $this->variantCache[$shopId][$productGid] = [];
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapShippingLines(Shop $shop, array $order, string $currency, ?StateAssignmentMapper $assignments = null): array
    {
        $assignments = $assignments ?? app(StateAssignmentMapper::class);
        $deliveries = data_get($order, 'deliveries', []);
        if (!is_array($deliveries) || count($deliveries) === 0) {
            return [];
        }

        $out = [];
        foreach ($deliveries as $d) {
            if (!is_array($d)) {
                continue;
            }

            $name = (string) (data_get($d, 'shippingMethod.translated.name') ?: data_get($d, 'shippingMethod.name') ?: 'Shipping');
            $mapped = $assignments->mappedValue($shop, 'shipping_methods', $name);
            if (is_string($mapped) && $mapped !== '') {
                $name = $assignments->optionLabel('shipping_methods', $mapped);
            }

            $cost = data_get($d, 'shippingCosts.totalPrice');
            $costNum = is_numeric($cost) ? (float) $cost : 0.0;

            $out[] = [
                'title' => $name,
                'priceSet' => [
                    'shopMoney' => [
                        'amount' => $this->formatAmount($costNum),
                        'currencyCode' => $currency,
                    ],
                ],
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapTransactions(
        Shop $shop,
        array $order,
        string $currency,
        ?StateAssignmentMapper $assignments = null,
        ?string $financialStatus = null
    ): array {
        $assignments = $assignments ?? app(StateAssignmentMapper::class);
        $financialStatus = $financialStatus ?? $assignments->resolveFinancialStatus($shop, $order);

        $amount = data_get($order, 'amountTotal');
        $amountNum = is_numeric($amount) ? (float) $amount : 0.0;
        if ($amountNum <= 0) {
            return [];
        }

        $paymentMethodName = $this->paymentMethodName($shop, $order);
        $status = $assignments->transactionStatusForFinancialStatus($financialStatus);

        return [$this->buildSaleTransaction($shop, $order, $currency, $assignments, $amountNum, $status, $paymentMethodName)];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSaleTransaction(
        Shop $shop,
        array $order,
        string $currency,
        StateAssignmentMapper $assignments,
        float $amountNum,
        string $status,
        string $paymentMethodName
    ): array {
        $transaction = [
            'kind' => 'SALE',
            'status' => $status,
            'amountSet' => [
                'shopMoney' => [
                    'amount' => $this->formatAmount($amountNum),
                    'currencyCode' => $currency,
                ],
            ],
        ];

        $processedAt = $this->orderProcessedAt($order);
        if ($processedAt !== '') {
            $transaction['processedAt'] = $processedAt;
        }

        $gatewayLabel = $this->paymentGatewayDisplayName($shop, $assignments, $paymentMethodName);
        if ($gatewayLabel !== '') {
            $transaction['gateway'] = $gatewayLabel;
        }

        $authorizationCode = $this->transactionAuthorizationCode($order);
        if ($authorizationCode !== '') {
            $transaction['authorizationCode'] = $authorizationCode;
        }

        return $transaction;
    }

    /**
     * Manual payment recorded after orderCreate for PAID Shopware orders (Shopify payment timeline).
     *
     * @return array{amount: string, currencyCode: string, paymentMethodName: string, processedAt: string}|null
     */
    private function buildManualPaymentCapture(
        Shop $shop,
        array $order,
        string $currency,
        StateAssignmentMapper $assignments
    ): ?array {
        $amount = data_get($order, 'amountTotal');
        $amountNum = is_numeric($amount) ? (float) $amount : 0.0;
        if ($amountNum <= 0) {
            return null;
        }

        $paymentMethodName = $this->paymentMethodName($shop, $order);
        $displayName = $this->paymentGatewayDisplayName($shop, $assignments, $paymentMethodName);
        if ($displayName === '') {
            $displayName = 'Shopware import';
        }

        $processedAt = $this->orderProcessedAt($order);

        return [
            'amount' => $this->formatAmount($amountNum),
            'currencyCode' => $currency,
            'paymentMethodName' => $displayName,
            'processedAt' => $processedAt,
        ];
    }

    private function paymentGatewayDisplayName(
        Shop $shop,
        StateAssignmentMapper $assignments,
        string $shopwarePaymentMethodName
    ): string {
        $shopwarePaymentMethodName = trim($shopwarePaymentMethodName);
        if ($shopwarePaymentMethodName === '') {
            return '';
        }

        $mapped = $assignments->mappedValue($shop, 'payment_methods', $shopwarePaymentMethodName);
        if (is_string($mapped) && $mapped !== '') {
            $label = $assignments->optionLabel('payment_methods', $mapped);
            if ($label !== '' && $label !== '— Not mapped —') {
                return $label;
            }
        }

        return $shopwarePaymentMethodName;
    }

    private function orderProcessedAt(array $order): string
    {
        return trim((string) (data_get($order, 'orderDateTime') ?: data_get($order, 'createdAt') ?: ''));
    }

    private function transactionAuthorizationCode(array $order): string
    {
        $transactions = data_get($order, 'transactions', []);
        if (!is_array($transactions)) {
            return '';
        }

        foreach ($transactions as $tx) {
            if (!is_array($tx)) {
                continue;
            }

            $id = trim((string) (data_get($tx, 'id') ?: ''));
            if ($id !== '') {
                return $id;
            }
        }

        return '';
    }

    private function paymentMethodName(Shop $shop, array $order): string
    {
        $transactions = data_get($order, 'transactions', []);
        if (!is_array($transactions)) {
            $transactions = [];
        }

        foreach ($transactions as $tx) {
            if (!is_array($tx)) {
                continue;
            }

            $name = trim((string) (data_get($tx, 'paymentMethod.translated.name')
                ?: data_get($tx, 'paymentMethod.name')
                ?: ''));
            if ($name !== '') {
                return $name;
            }
        }

        $conn = $shop->shopwareConnection;
        if (!$conn) {
            return '';
        }

        $shopware = app(ShopwareClient::class);
        foreach ($transactions as $tx) {
            if (!is_array($tx)) {
                continue;
            }

            $paymentMethodId = trim((string) (data_get($tx, 'paymentMethodId') ?: data_get($tx, 'paymentMethod.id') ?: ''));
            if ($paymentMethodId === '') {
                continue;
            }

            $resolved = $shopware->resolvePaymentMethodName($conn, $paymentMethodId);
            if (is_string($resolved) && trim($resolved) !== '') {
                return trim($resolved);
            }
        }

        return '';
    }

    private function shippingMethodName(Shop $shop, array $order): string
    {
        $deliveries = data_get($order, 'deliveries', []);
        if (!is_array($deliveries)) {
            return '';
        }

        foreach ($deliveries as $delivery) {
            if (!is_array($delivery)) {
                continue;
            }

            $name = trim((string) (data_get($delivery, 'shippingMethod.translated.name')
                ?: data_get($delivery, 'shippingMethod.name')
                ?: ''));
            if ($name !== '') {
                return $name;
            }
        }

        $conn = $shop->shopwareConnection;
        if (!$conn) {
            return '';
        }

        $shopware = app(ShopwareClient::class);
        foreach ($deliveries as $delivery) {
            if (!is_array($delivery)) {
                continue;
            }

            $shippingMethodId = trim((string) (data_get($delivery, 'shippingMethodId') ?: data_get($delivery, 'shippingMethod.id') ?: ''));
            if ($shippingMethodId === '') {
                continue;
            }

            $resolved = $shopware->resolveShippingMethodName($conn, $shippingMethodId);
            if (is_string($resolved) && trim($resolved) !== '') {
                return trim($resolved);
            }
        }

        return '';
    }

    /**
     * Shopware shipping method for display/mapping when deliveries are missing from the API payload.
     */
    private function effectiveShippingMethodName(Shop $shop, array $order): string
    {
        $name = $this->shippingMethodName($shop, $order);

        return $name !== '' ? $name : 'Standard';
    }

    /**
     * @param array<int, array<string, mixed>> $lineItems
     * @param array<int, array<string, mixed>> $shippingLines
     * @return array<int, array<string, mixed>>
     */
    private function applyRequiresShippingToLineItems(
        array $lineItems,
        array $order,
        array $shippingLines,
        ?array $shippingAddress,
        ?string $fulfillmentStatus
    ): array {
        // Imported orders default to requiresShipping=false in Shopify → "Shipping not required" + unfulfillable.
        $needsShipping = count($shippingLines) > 0
            || $this->hasMailingAddress($shippingAddress)
            || in_array($fulfillmentStatus, ['FULFILLED', 'PARTIAL'], true)
            || ShopwareStateResolver::technicalName($order) !== '';

        if (!$needsShipping) {
            return $lineItems;
        }

        foreach ($lineItems as $idx => $lineItem) {
            if (!is_array($lineItem)) {
                continue;
            }
            $lineItem['requiresShipping'] = true;
            $lineItems[$idx] = $lineItem;
        }

        return $lineItems;
    }

    /**
     * @param array<int, array<string, mixed>> $shippingLines
     * @return array<int, array<string, mixed>>
     */
    private function ensureShippingLinesPresent(
        Shop $shop,
        array $order,
        string $currency,
        StateAssignmentMapper $assignments,
        array $shippingLines
    ): array {
        if (count($shippingLines) > 0) {
            return $shippingLines;
        }

        $name = $this->effectiveShippingMethodName($shop, $order);

        $mapped = $assignments->mappedValue($shop, 'shipping_methods', $name);
        if (is_string($mapped) && $mapped !== '') {
            $name = $assignments->optionLabel('shipping_methods', $mapped);
        }

        $shippingTotal = data_get($order, 'shippingTotal');
        $amountNum = is_numeric($shippingTotal) ? (float) $shippingTotal : 0.0;

        return [[
            'title' => $name,
            'priceSet' => [
                'shopMoney' => [
                    'amount' => $this->formatAmount($amountNum),
                    'currencyCode' => $currency,
                ],
            ],
        ]];
    }

    /**
     * @return array{locationId: string, notifyCustomer: bool}|null
     */
    private function buildFulfillmentInput(?string $fulfillmentStatus, ?string $shopifyLocationGid): ?array
    {
        $shopifyLocationGid = trim((string) $shopifyLocationGid);
        if ($shopifyLocationGid === '') {
            return null;
        }

        if (!in_array($fulfillmentStatus, ['FULFILLED', 'PARTIAL'], true)) {
            return null;
        }

        return [
            'locationId' => $shopifyLocationGid,
            'notifyCustomer' => false,
        ];
    }

    /**
     * @param array<string, mixed>|null $address
     */
    private function hasMailingAddress(?array $address): bool
    {
        if (!is_array($address)) {
            return false;
        }

        foreach (['address1', 'city', 'zip', 'countryCode'] as $field) {
            $value = trim((string) ($address[$field] ?? ''));
            if ($value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Shopify orderCreate requires countryCode on mailing addresses; partial Shopware rows are otherwise dropped.
     *
     * @param  array<string, mixed>|null  $address
     * @param  array<string, mixed>|null  $fallback
     * @return array<string, mixed>|null
     */
    private function finalizeMailingAddressForShopify(?array $address, ?array $fallback = null): ?array
    {
        if (! is_array($address)) {
            if ($this->isShopifyReadyMailingAddress($fallback)) {
                return $fallback;
            }

            return null;
        }

        if (is_array($fallback)) {
            foreach (['countryCode', 'country', 'provinceCode', 'province', 'firstName', 'lastName', 'company', 'address2', 'phone'] as $field) {
                $value = trim((string) ($address[$field] ?? ''));
                if ($value === '' && trim((string) ($fallback[$field] ?? '')) !== '') {
                    $address[$field] = $fallback[$field];
                }
            }
        }

        if ($this->isShopifyReadyMailingAddress($address)) {
            return $address;
        }

        if ($this->isShopifyReadyMailingAddress($fallback)) {
            return $fallback;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $address
     */
    private function isShopifyReadyMailingAddress(?array $address): bool
    {
        if (! is_array($address)) {
            return false;
        }

        return trim((string) ($address['address1'] ?? '')) !== ''
            && trim((string) ($address['countryCode'] ?? '')) !== '';
    }

    private function mapAddress(mixed $addr): ?array
    {
        if (!is_array($addr)) {
            return null;
        }

        $firstName = (string) (data_get($addr, 'firstName') ?: '');
        $lastName = (string) (data_get($addr, 'lastName') ?: '');
        $company = (string) (data_get($addr, 'company') ?: '');

        $street = (string) (data_get($addr, 'street') ?: '');
        $address2 = trim((string) (data_get($addr, 'additionalAddressLine1') ?: ''));
        $address2Line2 = trim((string) (data_get($addr, 'additionalAddressLine2') ?: ''));
        if ($address2Line2 !== '') {
            $address2 = $address2 !== '' ? $address2.', '.$address2Line2 : $address2Line2;
        }
        $zipcode = (string) (data_get($addr, 'zipcode') ?: '');
        $city = (string) (data_get($addr, 'city') ?: '');

        $countryCode = $this->resolveCountryCode($addr);
        $countryName = (string) (data_get($addr, 'country.translated.name') ?: data_get($addr, 'country.name') ?: '');
        $provinceCode = (string) (data_get($addr, 'countryState.shortCode') ?: '');
        $province = (string) (data_get($addr, 'countryState.translated.name') ?: data_get($addr, 'countryState.name') ?: '');

        $rawPhone = (string) (data_get($addr, 'phoneNumber') ?: data_get($addr, 'phone') ?: '');
        $phone = $this->normalizeE164Phone($rawPhone);

        $payload = [
            'firstName' => $firstName !== '' ? $firstName : null,
            'lastName' => $lastName !== '' ? $lastName : null,
            'company' => $company !== '' ? $company : null,
            'address1' => $street !== '' ? $street : null,
            'address2' => $address2 !== '' ? $address2 : null,
            'city' => $city !== '' ? $city : null,
            'zip' => $zipcode !== '' ? $zipcode : null,
            'countryCode' => $countryCode !== '' ? strtoupper($countryCode) : null,
            'country' => $countryName !== '' ? $countryName : null,
            'provinceCode' => $provinceCode !== '' ? strtoupper($provinceCode) : null,
            'province' => $province !== '' ? $province : null,
            'phone' => $phone,
        ];

        return $this->removeEmpty($payload);
    }

    private function normalizeE164Phone(string $phone): ?string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        // Accept only E.164 format: +[1-9][0-9]{7,14}
        if (preg_match('/^\+[1-9]\d{7,14}$/', $phone) === 1) {
            return $phone;
        }

        return null;
    }

    private function resolveBillingAddress(array $order): ?array
    {
        $mapped = $this->mapAddress(data_get($order, 'billingAddress'));
        if ($this->hasMailingAddress($mapped)) {
            return $mapped;
        }

        $billingAddressId = trim((string) data_get($order, 'billingAddressId', ''));
        $addresses = data_get($order, 'addresses', []);
        if (is_array($addresses)) {
            foreach ($addresses as $addr) {
                if (! is_array($addr)) {
                    continue;
                }
                $id = trim((string) data_get($addr, 'id', ''));
                if ($billingAddressId !== '') {
                    if ($id === '' || $id !== $billingAddressId) {
                        continue;
                    }
                } elseif (count($addresses) > 1) {
                    continue;
                }
                $candidate = $this->mapAddress($addr);
                if ($this->hasMailingAddress($candidate)) {
                    return $candidate;
                }
            }
        }

        $mapped = $this->mapAddress(data_get($order, 'orderCustomer.customer.defaultBillingAddress'));
        if ($this->hasMailingAddress($mapped)) {
            return $mapped;
        }

        return null;
    }

    private function resolveShippingAddress(array $order): ?array
    {
        $mapped = $this->mapShippingAddressFromDeliveries($order);
        if ($this->hasMailingAddress($mapped)) {
            return $mapped;
        }

        $deliveries = data_get($order, 'deliveries', []);
        if (is_array($deliveries)) {
            foreach ($deliveries as $delivery) {
                if (! is_array($delivery)) {
                    continue;
                }
                $candidate = $this->mapAddress(data_get($delivery, 'shippingOrderAddress'));
                if ($this->hasMailingAddress($candidate)) {
                    return $candidate;
                }

                $shippingAddressId = trim((string) data_get($delivery, 'shippingOrderAddressId', ''));
                if ($shippingAddressId !== '') {
                    $candidate = $this->resolveOrderAddressById($order, $shippingAddressId);
                    if ($this->hasMailingAddress($candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        $billingAddressId = trim((string) data_get($order, 'billingAddressId', ''));
        $addresses = data_get($order, 'addresses', []);
        if (is_array($addresses)) {
            foreach ($addresses as $addr) {
                if (! is_array($addr)) {
                    continue;
                }
                $id = trim((string) data_get($addr, 'id', ''));
                if ($billingAddressId !== '' && $id !== '' && $id === $billingAddressId) {
                    continue;
                }
                $candidate = $this->mapAddress($addr);
                if ($this->hasMailingAddress($candidate)) {
                    return $candidate;
                }
            }
        }

        $mapped = $this->mapAddress(data_get($order, 'orderCustomer.customer.defaultShippingAddress'));
        if ($this->hasMailingAddress($mapped)) {
            return $mapped;
        }

        $billing = $this->resolveBillingAddress($order);
        if ($this->hasMailingAddress($billing)) {
            return $billing;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveOrderAddressById(array $order, string $addressId): ?array
    {
        $addressId = trim($addressId);
        if ($addressId === '') {
            return null;
        }

        $addresses = data_get($order, 'addresses', []);
        if (! is_array($addresses)) {
            return null;
        }

        foreach ($addresses as $addr) {
            if (! is_array($addr)) {
                continue;
            }
            if (trim((string) data_get($addr, 'id', '')) !== $addressId) {
                continue;
            }

            return $this->mapAddress($addr);
        }

        return null;
    }

    private function mapShippingAddressFromDeliveries(array $order): ?array
    {
        $deliveries = data_get($order, 'deliveries', []);
        if (! is_array($deliveries) || count($deliveries) === 0) {
            return null;
        }

        $addr = data_get($deliveries, '0.shippingOrderAddress');

        return $this->mapAddress($addr);
    }

    /**
     * @param  array<string, mixed>  $addr
     */
    private function resolveCountryCode(array $addr): string
    {
        $iso = strtoupper(trim((string) (data_get($addr, 'country.iso') ?: '')));
        if ($iso !== '') {
            return $iso;
        }

        $iso3 = strtoupper(trim((string) (data_get($addr, 'country.iso3') ?: '')));
        $iso3Map = [
            'GBR' => 'GB',
            'DEU' => 'DE',
            'USA' => 'US',
            'FRA' => 'FR',
        ];
        if (isset($iso3Map[$iso3])) {
            return $iso3Map[$iso3];
        }

        $countryName = strtolower(trim((string) (data_get($addr, 'country.translated.name') ?: data_get($addr, 'country.name') ?: '')));
        $byName = [
            'united kingdom' => 'GB',
            'uk' => 'GB',
            'great britain' => 'GB',
            'germany' => 'DE',
            'united states' => 'US',
            'usa' => 'US',
        ];

        return $byName[$countryName] ?? '';
    }

    private function removeEmpty(array $payload): array
    {
        foreach ($payload as $k => $v) {
            if ($v === null) {
                unset($payload[$k]);
                continue;
            }

            if (is_string($v) && trim($v) === '') {
                unset($payload[$k]);
                continue;
            }

            if (is_array($v) && count($v) === 0) {
                unset($payload[$k]);
                continue;
            }
        }

        return $payload;
    }

    private function normalizeAttributeKey(string $key): string
    {
        $key = trim($key);
        $key = preg_replace('/[^A-Za-z0-9_ \-]/', '_', $key);
        $key = is_string($key) ? $key : '';
        $key = preg_replace('/\s+/', '_', $key);
        $key = is_string($key) ? trim($key, '_') : '';

        return substr($key, 0, 255);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapOrderDiscountCode(array $order, string $currency): ?array
    {
        $amount = $this->resolveOrderDiscountAmount($order);
        if ($amount <= 0) {
            return null;
        }

        return [
            'itemFixedDiscountCode' => [
                'amountSet' => [
                    'shopMoney' => [
                        'amount' => $this->formatAmount($amount),
                        'currencyCode' => $currency,
                    ],
                ],
                'code' => 'SHOPWARE_DISCOUNT',
            ],
        ];
    }

    private function resolveOrderDiscountAmount(array $order): float
    {
        $orderDiscount = data_get($order, 'price.discountPrice');
        if (is_numeric($orderDiscount)) {
            $value = abs((float) $orderDiscount);
            if ($value > 0) {
                return $value;
            }
        }

        $sum = 0.0;
        $items = data_get($order, 'lineItems', []);
        if (! is_array($items)) {
            return 0.0;
        }

        foreach ($items as $li) {
            if (! is_array($li)) {
                continue;
            }
            $lineDiscount = data_get($li, 'price.discountPrice');
            if (is_numeric($lineDiscount)) {
                $sum += abs((float) $lineDiscount);
            }
        }

        return $sum;
    }

    private function lineItemTitleWithOptions(array $li): string
    {
        $label = (string) (data_get($li, 'label') ?: data_get($li, 'product.name') ?: 'Item');
        $suffix = $this->formatLineItemOptionsSuffix($li);

        if ($suffix === '') {
            return $label;
        }

        return $label.' ('.$suffix.')';
    }

    private function formatLineItemOptionsSuffix(array $li): string
    {
        $parts = [];
        foreach ($this->extractLineItemOptions($li) as $opt) {
            $group = trim((string) ($opt['name'] ?? ''));
            $value = trim((string) ($opt['value'] ?? ''));
            if ($group !== '' && $value !== '') {
                $parts[] = $group.': '.$value;
            } elseif ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode(', ', $parts);
    }

    /**
     * @return array<int, array{name: string, value: string}>
     */
    private function lineItemOptionProperties(array $li): array
    {
        $out = [];
        foreach ($this->extractLineItemOptions($li) as $opt) {
            $name = trim((string) ($opt['name'] ?? ''));
            $value = trim((string) ($opt['value'] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }
            $out[] = ['name' => $name, 'value' => $value];
        }

        return $out;
    }

    /**
     * @return array<int, array{name: string, value: string}>
     */
    private function extractLineItemOptions(array $li): array
    {
        $payload = $this->decodeLineItemPayload($li);
        $options = data_get($payload, 'options');
        if (! is_array($options)) {
            $options = data_get($li, 'payload.options');
        }
        if (! is_array($options)) {
            return [];
        }

        $out = [];
        foreach ($options as $opt) {
            if (! is_array($opt)) {
                continue;
            }
            $name = (string) (data_get($opt, 'group') ?: data_get($opt, 'name') ?: '');
            $value = (string) (data_get($opt, 'option') ?: data_get($opt, 'value') ?: '');
            if ($name === '' && $value === '') {
                continue;
            }
            $out[] = ['name' => $name !== '' ? $name : 'Option', 'value' => $value];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeLineItemPayload(array $li): array
    {
        $payload = data_get($li, 'payload');
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            return [];
        }

        return is_array($payload) ? $payload : [];
    }

    private function limitCustomAttributeValue(string $value): string
    {
        $value = trim($value);
        if (strlen($value) <= 255) {
            return $value;
        }

        return substr($value, 0, 252).'...';
    }

    private function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function formatAmount(float $n): string
    {
        return number_format($n, 2, '.', '');
    }
}