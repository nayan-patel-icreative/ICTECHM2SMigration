<?php

namespace App\Services\Migration;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class DiscountMapper
{
    /**
     * Map a Shopware promotion to a Shopify discount mutation payload.
     *
     * @param  array<string, mixed>  $promotion
     * @return array{
     *   mutation: string|null,
     *   variables: array<string, mixed>,
     *   issues: string[],
     *   skipped: bool,
     *   skip_reason: string|null
     * }
     */
    public function map(array $promotion): array
    {
        $issues = [];

        $mutation = $this->resolveMutation($promotion, $issues);

        if ($mutation === null) {
            $discounts    = data_get($promotion, 'discounts');
            $hasDiscounts = is_array($discounts) && count($discounts) > 0;

            if (! $hasDiscounts) {
                $reason = "Promotion discounts data not available (association not loaded) — skipping";
            } else {
                // Check if all types are unmappable
                $unmappable = ['fixed_unit_price', 'free_item'];
                $allUnmappable = true;
                $types = [];
                foreach ((array) $discounts as $d) {
                    if (is_array($d)) {
                        $t = strtolower(trim((string) ($d['type'] ?? '')));
                        $types[] = $t;
                        if (! in_array($t, $unmappable, true)) {
                            $allUnmappable = false;
                        }
                    }
                }
                $reason = $allUnmappable
                    ? "All discount types (" . implode(', ', array_unique($types)) . ") have no Shopify equivalent and will be skipped"
                    : "Promotion discounts data not available — skipping";
            }

            return [
                'mutation'    => null,
                'variables'   => [],
                'issues'      => [$reason],
                'skipped'     => true,
                'skip_reason' => $reason,
            ];
        }

        $variables = $this->buildInput($promotion, $mutation, $issues);

        return [
            'mutation'    => $mutation,
            'variables'   => $variables,
            'issues'      => $issues,
            'skipped'     => false,
            'skip_reason' => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Mutation resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve the Shopify mutation name. Returns null for unmappable types.
     *
     * @param  string[]  &$issues
     */
    private function resolveMutation(array $promotion, array &$issues): ?string
    {
        $hasCodes = $this->hasCodes($promotion);
        $discounts = data_get($promotion, 'discounts');

        // If discounts association wasn't loaded, we can't determine the type — skip
        if (! is_array($discounts) || count($discounts) === 0) {
            return null;
        }

        // Find the first mappable discount entry (don't record issues here — buildInput does that)
        $mappable = $this->firstMappableDiscount($discounts, $issues);
        if ($mappable === null) {
            return null;
        }

        $discountType = strtolower(trim((string) ($mappable['type'] ?? '')));
        $scope        = strtolower(trim((string) ($mappable['scope'] ?? 'cart')));

        // Free shipping — scope or type driven
        $isFreeShipping = ($discountType === 'free_shipping' || $scope === 'delivery');

        if ($isFreeShipping) {
            return $hasCodes
                ? 'discountCodeFreeShippingCreate'
                : 'discountAutomaticFreeShippingCreate';
        }

        return $hasCodes
            ? 'discountCodeBasicCreate'
            : 'discountAutomaticBasicCreate';
    }

    /**
     * Find the first mappable discount entry from the discounts array.
     * Records skipped unmappable entries as issues (only when $recordIssues is true).
     *
     * @param  array<int, mixed>  $discounts
     * @param  string[]  &$issues
     * @param  bool  $recordIssues  Whether to append issues for skipped types
     * @return array<string, mixed>|null
     */
    private function firstMappableDiscount(array $discounts, array &$issues, bool $recordIssues = false): ?array
    {
        $unmappableTypes = ['fixed_unit_price', 'free_item'];
        $skipped = 0;

        foreach ($discounts as $discount) {
            if (! is_array($discount)) {
                continue;
            }
            $type = strtolower(trim((string) ($discount['type'] ?? '')));
            if (in_array($type, $unmappableTypes, true)) {
                $skipped++;
                if ($recordIssues) {
                    $issues[] = "Discount type '{$type}' has no Shopify equivalent and was skipped";
                }
                continue;
            }
            return $discount;
        }

        // All discounts were unmappable
        if ($recordIssues && $skipped > 0) {
            $issues[] = "All {$skipped} discount rule(s) on this promotion are unmappable types";
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Input builder
    // -------------------------------------------------------------------------

    /**
     * @param  string[]  &$issues
     * @return array<string, mixed>
     */
    private function buildInput(array $promotion, string $mutation, array &$issues): array
    {
        $isCodeBased   = str_starts_with($mutation, 'discountCode');
        $isFreeShipping = str_contains($mutation, 'FreeShipping');

        // Title
        $name  = trim((string) (data_get($promotion, 'name') ?: ''));
        $title = $name !== '' ? $name : 'Unnamed Promotion';
        if (mb_strlen($title) > 255) {
            $title = mb_substr($title, 0, 255);
        }

        // Dates
        [$startsAt, $endsAt] = $this->resolveStartsAt($promotion, $issues);

        $input = ['title' => $title];

        if ($startsAt !== null) {
            $input['startsAt'] = $startsAt;
        }
        if ($endsAt !== null) {
            $input['endsAt'] = $endsAt;
        }

        // Discount value (not needed for free-shipping mutations)
        if (! $isFreeShipping) {
            $discounts      = data_get($promotion, 'discounts', []);
            $activeDiscount = $this->firstMappableDiscount(is_array($discounts) ? $discounts : [], $issues, true);
            $discountType   = $activeDiscount ? strtolower(trim((string) ($activeDiscount['type'] ?? ''))) : '';
            $value          = $activeDiscount ? (float) ($activeDiscount['value'] ?? 0) : 0.0;
            $scope          = $activeDiscount ? strtolower(trim((string) ($activeDiscount['scope'] ?? 'cart'))) : 'cart';

            // Inform user if multiple discount rules existed (only first mappable is used)
            $totalDiscounts = is_array($discounts) ? count($discounts) : 0;
            if ($totalDiscounts > 1) {
                $issues[] = "Promotion has {$totalDiscounts} discount rules — only the first mappable rule was migrated";
            }

            if ($discountType === 'percentage') {
                if ($value > 100) {
                    $issues[] = "Percentage value {$value} exceeds 100 and has been capped at 100";
                    $value = 100.0;
                }
                // Shopify DiscountPercentageInput.percentage expects a positive decimal
                // between 0.0 and 1.0 (e.g. 0.10 for 10% off).
                $pct = round($value / 100, 10);
                $input['customerGets'] = [
                    'value' => ['percentage' => $pct],
                    'items' => ['all' => true],
                ];
            } else {
                // absolute
                $input['customerGets'] = [
                    'value' => [
                        'discountAmount' => [
                            'amount'             => number_format($value, 2, '.', ''),
                            'appliesOnEachItem'  => false,
                        ],
                    ],
                    'items' => ['all' => true],
                ];
            }

            // Scope: set → flag for manual review, still apply to all
            if ($scope === 'set') {
                $issues[] = 'Product-scoped discount requires manual review after migration';
            }
        }

        // Minimum requirement
        $minReq = $this->resolveMinimumRequirement($promotion, $issues);
        if ($minReq !== null) {
            $input['minimumRequirement'] = $minReq;
        }

        // Usage limits & per-customer cap — only supported on code-based discounts.
        // Automatic discount inputs (DiscountAutomaticBasicInput, etc.) do not
        // expose these fields in the Shopify GraphQL schema.
        if ($isCodeBased) {
            // customerSelection is required for code-based discounts.
            // Using the deprecated 'customerSelection' field (available in all API versions
            // including 2025-01) rather than the newer 'context' field (2025-10+).
            $input['customerSelection'] = ['all' => true];

            $maxGlobal = (int) (data_get($promotion, 'maxRedemptionsGlobal') ?? 0);
            if ($maxGlobal > 0) {
                $input['usageLimit'] = $maxGlobal;
            }

            $maxPerCustomer = (int) (data_get($promotion, 'maxRedemptionsPerCustomer') ?? 0);
            if ($maxPerCustomer === 1) {
                $input['appliesOncePerCustomer'] = true;
            } elseif ($maxPerCustomer > 1) {
                $input['appliesOncePerCustomer'] = false;
                $issues[] = "Per-customer limit of {$maxPerCustomer} cannot be fully mapped (Shopify only supports once-per-customer)";
            }

            // DiscountCodeBasicInput uses a single 'code' field (not 'codes' array).
            // Additional codes are added via discountRedeemCodeBulkAdd after creation.
            [$codes, $truncated, $totalCount] = $this->filterCodes($promotion);
            if ($truncated) {
                $issues[] = "Promotion codes truncated to 1,000 (total: {$totalCount})";
            }
            // Set the primary code on the input; remaining codes stored for bulk-add
            if (count($codes) > 0) {
                $input['code'] = $codes[0];
            }
            // Store extra codes in issues context for the job to pick up
            if (count($codes) > 1) {
                $input['_extra_codes'] = array_slice($codes, 1);
            }

            // Note: metafields are NOT a field on DiscountCodeBasicInput.
            // They must be set via metafieldsSet mutation after creation.
        } else {
            // For automatic discounts, note any unmappable limits as info only.
            $maxPerCustomer = (int) (data_get($promotion, 'maxRedemptionsPerCustomer') ?? 0);
            if ($maxPerCustomer > 0) {
                $issues[] = "Per-customer limit of {$maxPerCustomer} cannot be mapped to an automatic discount (Shopify does not support usage limits on automatic discounts)";
            }
            $maxGlobal = (int) (data_get($promotion, 'maxRedemptionsGlobal') ?? 0);
            if ($maxGlobal > 0) {
                $issues[] = "Global usage limit of {$maxGlobal} cannot be mapped to an automatic discount (Shopify does not support usage limits on automatic discounts)";
            }
        }

        // Extract _extra_codes from input before wrapping (not a real Shopify field)
        $extraCodes = $input['_extra_codes'] ?? [];
        unset($input['_extra_codes']);

        // Build metafields separately (not inline on the input)
        $metafields = $this->buildMetafields($promotion, $issues);

        // Wrap in the correct input key expected by each mutation
        $inputKey = $this->mutationInputKey($mutation);

        return [
            $inputKey      => $input,
            '_extra_codes' => $extraCodes,
            '_metafields'  => $metafields,
        ];
    }

    // -------------------------------------------------------------------------
    // Dates
    // -------------------------------------------------------------------------

    /**
     * @param  string[]  &$issues
     * @return array{string|null, string|null}  [startsAt, endsAt]
     */
    private function resolveStartsAt(array $promotion, array &$issues): array
    {
        $active     = (bool) (data_get($promotion, 'active') ?? true);
        $validFrom  = data_get($promotion, 'validFrom');
        $validUntil = data_get($promotion, 'validUntil');

        // Inactive promotion — push start far into the future, omit end
        if (! $active) {
            $startsAt = Carbon::now()->addYears(100)->startOfDay()->utc()->toIso8601String();
            return [$startsAt, null];
        }

        $startsAt = null;
        $endsAt   = null;

        if ($validFrom !== null && $validFrom !== '') {
            try {
                $startsAt = Carbon::parse((string) $validFrom)->utc()->toIso8601String();
            } catch (\Throwable) {
                $issues[] = "Could not parse validFrom date: {$validFrom}";
            }
        }

        // startsAt is required by Shopify — default to now() if not set
        if ($startsAt === null) {
            $startsAt = Carbon::now()->utc()->toIso8601String();
        }

        if ($validUntil !== null && $validUntil !== '') {
            try {
                $endsAt = Carbon::parse((string) $validUntil)->utc()->toIso8601String();
            } catch (\Throwable) {
                $issues[] = "Could not parse validUntil date: {$validUntil}";
            }
        }

        // Clamp: startsAt must not be after endsAt
        if ($startsAt !== null && $endsAt !== null && $startsAt > $endsAt) {
            $issues[] = 'validFrom is after validUntil — endsAt has been clamped to startsAt';
            $endsAt = $startsAt;
        }

        return [$startsAt, $endsAt];
    }

    // -------------------------------------------------------------------------
    // Minimum requirement
    // -------------------------------------------------------------------------

    /**
     * @param  string[]  &$issues
     * @return array<string, mixed>|null
     */
    private function resolveMinimumRequirement(array $promotion, array &$issues): ?array
    {
        $cartRules = data_get($promotion, 'cartRules');
        if (! is_array($cartRules) || count($cartRules) === 0) {
            return null;
        }

        $subtotalAmount = null;
        $quantityCount  = null;

        foreach ($cartRules as $rule) {
            $conditions = data_get($rule, 'conditions');
            if (! is_array($conditions)) {
                continue;
            }
            foreach ($conditions as $condition) {
                $type  = (string) (data_get($condition, 'type') ?? '');
                $value = data_get($condition, 'value');

                if ($type === 'cart-minimum-order-value' && $subtotalAmount === null) {
                    $amount = is_array($value) ? (float) ($value['amount'] ?? 0) : (float) $value;
                    if ($amount > 0) {
                        $subtotalAmount = number_format($amount, 2, '.', '');
                    }
                }

                if ($type === 'cart-minimum-order-quantity' && $quantityCount === null) {
                    $qty = is_array($value) ? (int) ($value['count'] ?? 0) : (int) $value;
                    if ($qty > 0) {
                        $quantityCount = $qty;
                    }
                }
            }
        }

        if ($subtotalAmount !== null) {
            if ($quantityCount !== null) {
                $issues[] = 'Minimum quantity condition dropped in favour of minimum subtotal condition';
            }
            return ['subtotal' => ['greaterThanOrEqualToSubtotal' => $subtotalAmount]];
        }

        if ($quantityCount !== null) {
            return ['quantity' => ['greaterThanOrEqualToQuantity' => $quantityCount]];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Codes
    // -------------------------------------------------------------------------

    /**
     * Filter and truncate codes to 1,000 valid entries.
     *
     * @return array{string[], bool, int}  [codes, truncated, totalCount]
     */
    private function filterCodes(array $promotion): array
    {
        // Shopware 6.7+ uses 'individualCodes'; older versions use 'codes'
        $raw = data_get($promotion, 'individualCodes') ?? data_get($promotion, 'codes');
        $raw = is_array($raw) ? $raw : [];
        $valid = [];

        foreach ($raw as $index => $entry) {
            $code = is_array($entry) ? (string) ($entry['code'] ?? '') : (string) $entry;
            $code = trim($code);
            if ($code === '') {
                Log::warning('Discount migration: skipping empty promotion code', [
                    'promotion_id' => data_get($promotion, 'id'),
                    'code_index'   => $index,
                ]);
                continue;
            }
            $valid[] = $code;
        }

        $total     = count($valid);
        $truncated = $total > 1000;
        $codes     = array_slice($valid, 0, 1000);

        return [$codes, $truncated, $total];
    }

    // -------------------------------------------------------------------------
    // Metafields
    // -------------------------------------------------------------------------

    /**
     * @param  string[]  &$issues
     * @return array<int, array<string, string>>
     */
    private function buildMetafields(array $promotion, array &$issues): array
    {
        $metafields = [];

        // Always written
        $promotionId = (string) (data_get($promotion, 'id') ?? '');
        if ($promotionId !== '') {
            $metafields[] = [
                'namespace' => 'shopware',
                'key'       => 'promotion_id',
                'type'      => 'single_line_text_field',
                'value'     => $promotionId,
            ];
        }

        $priority = data_get($promotion, 'priority');
        if ($priority !== null) {
            $metafields[] = [
                'namespace' => 'shopware',
                'key'       => 'priority',
                'type'      => 'single_line_text_field',
                'value'     => (string) $priority,
            ];
        }

        // preventCombination
        if ((bool) data_get($promotion, 'preventCombination')) {
            $metafields[] = [
                'namespace' => 'shopware',
                'key'       => 'prevent_combination',
                'type'      => 'single_line_text_field',
                'value'     => 'true',
            ];
        }

        // salesChannels
        $salesChannels = data_get($promotion, 'salesChannels');
        if (is_array($salesChannels) && count($salesChannels) > 0) {
            $names = array_values(array_filter(array_map(
                fn ($sc) => is_array($sc) ? (string) ($sc['name'] ?? '') : '',
                $salesChannels
            ), fn ($n) => $n !== ''));

            if (count($names) > 0) {
                $json = json_encode($names, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($json)) {
                    $metafields[] = [
                        'namespace' => 'shopware',
                        'key'       => 'sales_channels',
                        'type'      => 'json',
                        'value'     => $json,
                    ];
                }
            }
        }

        // product_scope_json (scope = set)
        $scope = $this->primaryScope($promotion);
        if ($scope === 'set') {
            $discounts = data_get($promotion, 'discounts');
            if (is_array($discounts)) {
                $json = json_encode($discounts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($json)) {
                    $metafields[] = [
                        'namespace' => 'shopware',
                        'key'       => 'product_scope_json',
                        'type'      => 'json',
                        'value'     => $json,
                    ];
                }
            }
        }

        // max_redemptions_per_customer > 1
        $maxPerCustomer = (int) (data_get($promotion, 'maxRedemptionsPerCustomer') ?? 0);
        if ($maxPerCustomer > 1) {
            $metafields[] = [
                'namespace' => 'shopware',
                'key'       => 'max_redemptions_per_customer',
                'type'      => 'single_line_text_field',
                'value'     => (string) $maxPerCustomer,
            ];
        }

        return $metafields;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function hasCodes(array $promotion): bool
    {
        // Shopware 6.7+ uses 'individualCodes'; older versions use 'codes'
        $codes = data_get($promotion, 'individualCodes') ?? data_get($promotion, 'codes');
        return is_array($codes) && count($codes) > 0;
    }

    private function primaryDiscountType(array $promotion): string
    {
        return strtolower(trim((string) (data_get($promotion, 'discounts.0.type') ?? '')));
    }

    private function primaryScope(array $promotion): string
    {
        return strtolower(trim((string) (data_get($promotion, 'discounts.0.scope') ?? 'cart')));
    }

    /**
     * Map mutation name to its GraphQL input variable key.
     */
    private function mutationInputKey(string $mutation): string
    {
        return match ($mutation) {
            'discountAutomaticBasicCreate'        => 'automaticBasicDiscount',
            'discountAutomaticBasicUpdate'        => 'automaticBasicDiscount',
            'discountAutomaticFreeShippingCreate' => 'freeShippingAutomaticDiscount',
            'discountAutomaticFreeShippingUpdate' => 'freeShippingAutomaticDiscount',
            'discountCodeBasicCreate'             => 'basicCodeDiscount',
            'discountCodeBasicUpdate'             => 'basicCodeDiscount',
            'discountCodeFreeShippingCreate'      => 'freeShippingCodeDiscount',
            'discountCodeFreeShippingUpdate'      => 'freeShippingCodeDiscount',
            default                               => 'discount',
        };
    }

    /**
     * Return the corresponding update mutation for a given create mutation.
     */
    public function updateMutation(string $createMutation): string
    {
        return str_replace('Create', 'Update', $createMutation);
    }
}
