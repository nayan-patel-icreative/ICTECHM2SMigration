<?php

namespace App\Services\Migration;

class DiscountFingerprint
{
    /**
     * @param  array<string, mixed>  $promotion
     */
    public function make(array $promotion): string
    {
        $payload = [
            'name'                      => data_get($promotion, 'name'),
            'active'                    => data_get($promotion, 'active'),
            'validFrom'                 => data_get($promotion, 'validFrom'),
            'validUntil'                => data_get($promotion, 'validUntil'),
            'priority'                  => data_get($promotion, 'priority'),
            'maxRedemptionsGlobal'      => data_get($promotion, 'maxRedemptionsGlobal'),
            'maxRedemptionsPerCustomer' => data_get($promotion, 'maxRedemptionsPerCustomer'),
            'preventCombination'        => data_get($promotion, 'preventCombination'),
            'discounts'                 => data_get($promotion, 'discounts'),
            // Support both SW6.7+ (individualCodes) and older (codes)
            'codes'                     => data_get($promotion, 'individualCodes') ?? data_get($promotion, 'codes'),
            'salesChannels'             => data_get($promotion, 'salesChannels'),
            'cartRules'                 => data_get($promotion, 'cartRules'),
        ];

        ksort($payload);
        $json = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return hash('sha256', is_string($json) ? $json : '');
    }
}
