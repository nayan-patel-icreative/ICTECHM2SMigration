<?php

namespace App\Support;

/**
 * Resolves Magento 2 order status strings into normalised technical names
 * that the StateAssignmentMapper can compare against configured mappings.
 *
 * Magento 2 stores status as a plain string field: the `status` field on an
 * order is a plain string (e.g. "complete", "pending", "processing") and the
 * `state` field is the higher-level state group.
 *
 * Both are extracted so callers can use whichever is configured.
 */
class MagentoStateResolver
{
    /**
     * Extract the order state technical name from a Magento order entity.
     * Returns the `state` field (e.g. "new", "processing", "complete").
     */
    public static function technicalName(mixed $entity): string
    {
        if (!is_array($entity)) {
            return '';
        }

        $candidates = [
            // Magento REST API order state field
            data_get($entity, 'state'),
            // Magento REST API order status field (more granular)
            data_get($entity, 'status'),
        ];

        foreach ($candidates as $candidate) {
            $name = strtolower(trim((string) $candidate));
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    /**
     * Extract the order status (more granular than state) from a Magento order entity.
     * Returns values like "pending", "processing", "complete", "canceled", "closed".
     */
    public static function orderStatus(mixed $entity): string
    {
        if (!is_array($entity)) {
            return '';
        }

        return strtolower(trim((string) data_get($entity, 'status', '')));
    }

    /**
     * Extract a numeric entity ID for logging/reference.
     */
    public static function entityId(mixed $entity): string
    {
        if (!is_array($entity)) {
            return '';
        }

        return trim((string) (
            data_get($entity, 'entity_id')
            ?: data_get($entity, 'increment_id')
            ?: ''
        ));
    }
}
