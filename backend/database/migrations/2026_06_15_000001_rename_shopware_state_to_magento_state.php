<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rename the `shopware_state` column to `magento_state` in the state_mappings table.
 * This reflects that the migrator is now Magento-to-Shopify (not Shopware-to-Shopify).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('state_mappings', function (Blueprint $table) {
            $table->renameColumn('shopware_state', 'magento_state');
        });
    }

    public function down(): void
    {
        Schema::table('state_mappings', function (Blueprint $table) {
            $table->renameColumn('magento_state', 'shopware_state');
        });
    }
};
