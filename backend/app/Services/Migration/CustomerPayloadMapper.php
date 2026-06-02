<?php

namespace App\Services\Migration;

use App\Models\Shop;

class CustomerPayloadMapper
{
    public function mapCustomer(array $customer, ?Shop $shop = null): array
    {
        $email = (string) ($customer['email'] ?? '');
        $firstName = (string) ($customer['firstName'] ?? '');
        $lastName = (string) ($customer['lastName'] ?? '');
        $rawPhone = (string) ($customer['defaultBillingAddress']['phoneNumber'] ?? '');
        $assignments = $shop ? app(StateAssignmentMapper::class) : null;
        $salutationKey = $this->salutationKey($customer);
        $salutationDisplayName = (string) data_get($customer, 'salutation.translated.displayName',
            data_get($customer, 'salutation.displayName', ''));
        $mappedSalutation = ($shop && $assignments) ? $assignments->mappedValue($shop, 'salutations', $salutationKey) : null;

        $noteParts = [];
        $customerNumber = (string) ($customer['customerNumber'] ?? '');
        if ($customerNumber !== '') {
            $noteParts[] = 'Shopware customerNumber: '.$customerNumber;
        }
        $birthday = (string) ($customer['birthday'] ?? '');
        if ($birthday !== '') {
            $noteParts[] = 'Shopware birthday: '.$birthday;
        }
        $vatIds = $customer['vatIds'] ?? null;
        if (is_array($vatIds) && count($vatIds) > 0) {
            $vatIdsFiltered = array_values(array_filter($vatIds, function ($v) {
                return is_string($v) && trim($v) !== '';
            }));
            if (count($vatIdsFiltered) > 0) {
                $noteParts[] = 'Shopware VAT IDs: '.implode(', ', $vatIdsFiltered);
            }
        }

        $phone = $this->normalizeE164Phone($rawPhone);
        if ($phone === null && trim($rawPhone) !== '') {
            $noteParts[] = 'Shopware phone: '.trim($rawPhone);
        }
        // Always store the original Shopware salutation in the note
        if ($salutationDisplayName !== '') {
            $noteParts[] = 'Shopware salutation: '.$salutationDisplayName;
        }
        // Store the mapped Shopify salutation separately if it differs or is set
        if (is_string($mappedSalutation) && $mappedSalutation !== '' && $assignments) {
            $mappedLabel = $assignments->optionLabel('salutations', $mappedSalutation);
            if ($mappedLabel !== $salutationDisplayName) {
                $noteParts[] = 'Mapped salutation: '.$mappedLabel;
            }
        }

        $payload = [
            'email' => $email !== '' ? $email : null,
            'firstName' => $firstName !== '' ? $firstName : null,
            'lastName' => $lastName !== '' ? $lastName : null,
            'phone' => $phone,
            'note' => count($noteParts) > 0 ? implode("\n", $noteParts) : null,
        ];

        $addresses = $this->mapAddresses($customer);
        if (count($addresses) > 0) {
            $payload['addresses'] = $addresses;
        }

        $tags = [];
        $groupName = (string) data_get($customer, 'group.translated.name', data_get($customer, 'group.name', ''));
        if ($groupName !== '') {
            $tags[] = 'shopware_group:'.$groupName;
        }
        // Tag with original Shopware salutation display name
        if ($salutationDisplayName !== '') {
            $tags[] = 'shopware_salutation:'.$salutationDisplayName;
        }
        // Tag with mapped Shopify salutation if different from original
        if (is_string($mappedSalutation) && $mappedSalutation !== '' && $assignments) {
            $mappedLabel = $assignments->optionLabel('salutations', $mappedSalutation);
            if ($mappedLabel !== '' && $mappedLabel !== $salutationDisplayName) {
                $tags[] = 'shopify_salutation:'.$mappedLabel;
            }
        }
        if (count($tags) > 0) {
            $payload['tags'] = implode(', ', $tags);
        }

        return array_filter($payload, function ($v) {
            return $v !== null;
        });
    }

    /**
     * @return array<int, array{namespace: string, key: string, type: string, value: string}>
     */
    public function mapShopwareMetafields(array $customer, ?Shop $shop = null): array
    {
        $out = [];
        $assignments = $shop ? app(StateAssignmentMapper::class) : null;

        $this->pushMetafield($out, 'customer_id', (string) ($customer['id'] ?? ''));
        $this->pushMetafield($out, 'customer_number', (string) ($customer['customerNumber'] ?? ''));
        $this->pushMetafield($out, 'active', array_key_exists('active', $customer) ? ((bool) $customer['active'] ? 'true' : 'false') : '');

        $this->pushMetafield($out, 'guest', array_key_exists('guest', $customer) ? ((bool) $customer['guest'] ? 'true' : 'false') : '');
        $this->pushMetafield($out, 'account_type', (string) ($customer['accountType'] ?? ''));
        $this->pushMetafield($out, 'birthday', (string) ($customer['birthday'] ?? ''));
        $this->pushMetafield($out, 'affiliate_code', (string) ($customer['affiliateCode'] ?? ''));
        $this->pushMetafield($out, 'campaign_code', (string) ($customer['campaignCode'] ?? ''));
        $this->pushMetafield($out, 'double_opt_in_registration', array_key_exists('doubleOptInRegistration', $customer) ? ((bool) $customer['doubleOptInRegistration'] ? 'true' : 'false') : '');
        $this->pushMetafield($out, 'double_opt_in_email_sent_date', (string) ($customer['doubleOptInEmailSentDate'] ?? ''));
        $this->pushMetafield($out, 'double_opt_in_confirm_date', (string) ($customer['doubleOptInConfirmDate'] ?? ''));

        $vatIds = $customer['vatIds'] ?? null;
        if (is_array($vatIds)) {
            $vatIdsJson = json_encode($vatIds, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($vatIdsJson) && $vatIdsJson !== '' && $vatIdsJson !== 'null') {
                $this->pushMetafield($out, 'vat_ids', $vatIdsJson, 'json');
            }
        }

        $this->pushMetafield($out, 'created_at', (string) ($customer['createdAt'] ?? ''));
        $this->pushMetafield($out, 'updated_at', (string) ($customer['updatedAt'] ?? ''));
        $this->pushMetafield($out, 'last_login', (string) ($customer['lastLogin'] ?? ''));

        $groupId = (string) data_get($customer, 'groupId', '');
        $groupName = (string) data_get($customer, 'group.translated.name', data_get($customer, 'group.name', ''));
        $this->pushMetafield($out, 'group_id', $groupId);
        $this->pushMetafield($out, 'group_name', $groupName);

        $salutationName = (string) data_get($customer, 'salutation.translated.displayName', data_get($customer, 'salutation.displayName', ''));
        $this->pushMetafield($out, 'salutation', $salutationName);

        $salutationKey = $this->salutationKey($customer);
        $mappedSalutation = ($shop && $assignments) ? $assignments->mappedValue($shop, 'salutations', $salutationKey) : null;
        if (is_string($mappedSalutation) && $mappedSalutation !== '') {
            $this->pushMetafield($out, 'salutation_mapped', $assignments->optionLabel('salutations', $mappedSalutation));
        }

        $this->pushMetafield($out, 'language_id', (string) ($customer['languageId'] ?? ''));
        $this->pushMetafield($out, 'sales_channel_id', (string) ($customer['salesChannelId'] ?? ''));

        $raw = json_encode($customer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($raw) && $raw !== '') {
            $this->pushMetafield($out, 'raw', $raw, 'json');
        }

        return $out;
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

    private function salutationKey(array $customer): string
    {
        $key = (string) (data_get($customer, 'salutation.salutationKey')
            ?: data_get($customer, 'salutation.technicalName')
            ?: data_get($customer, 'salutation.translated.letterName')
            ?: data_get($customer, 'salutation.displayName')
            ?: data_get($customer, 'salutation.translated.displayName')
            ?: '');

        return strtolower(trim($key));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapAddresses(array $customer): array
    {
        $out = [];

        $ordered = [];
        $seen = [];

        $defaults = [
            data_get($customer, 'defaultBillingAddress'),
            data_get($customer, 'defaultShippingAddress'),
        ];

        foreach ($defaults as $d) {
            if (!is_array($d)) {
                continue;
            }
            $id = (string) ($d['id'] ?? '');
            if ($id !== '' && isset($seen[$id])) {
                continue;
            }
            if ($id !== '') {
                $seen[$id] = true;
            }
            $ordered[] = $d;
        }

        $addresses = $customer['addresses'] ?? [];
        if (is_array($addresses)) {
            foreach ($addresses as $a) {
                if (!is_array($a)) {
                    continue;
                }
                $id = (string) ($a['id'] ?? '');
                if ($id !== '' && isset($seen[$id])) {
                    continue;
                }
                if ($id !== '') {
                    $seen[$id] = true;
                }
                $ordered[] = $a;
            }
        }

        foreach ($ordered as $a) {
            if (!is_array($a)) {
                continue;
            }

            $countryIso = (string) data_get($a, 'country.iso', '');
            if ($countryIso === '') {
                $countryIso = (string) data_get($a, 'country.iso3', '');
            }

            $province = (string) data_get($a, 'countryState.translated.name', data_get($a, 'countryState.name', ''));
            if ($province === '') {
                $province = (string) ($a['countryStateName'] ?? '');
            }

            $address = [
                'firstName' => (string) ($a['firstName'] ?? ''),
                'lastName' => (string) ($a['lastName'] ?? ''),
                'company' => (string) ($a['company'] ?? ''),
                'address1' => (string) ($a['street'] ?? ''),
                'address2' => (string) ($a['additionalAddressLine1'] ?? ''),
                'zip' => (string) ($a['zipcode'] ?? ''),
                'city' => (string) ($a['city'] ?? ''),
                'phone' => $this->normalizeE164Phone((string) ($a['phoneNumber'] ?? '')),
                'province' => $province,
                'countryCode' => $countryIso,
            ];

            $address = array_filter($address, function ($v) {
                return is_string($v) && $v !== '';
            });

            if (count($address) === 0) {
                continue;
            }

            $out[] = $address;

            if (count($out) >= 10) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<int, array{namespace: string, key: string, type: string, value: string}> $out
     */
    private function pushMetafield(array &$out, string $key, string $value, string $type = 'single_line_text_field'): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }

        $out[] = [
            'namespace' => 'shopware',
            'key' => $key,
            'type' => $type,
            'value' => $value,
        ];
    }
}
