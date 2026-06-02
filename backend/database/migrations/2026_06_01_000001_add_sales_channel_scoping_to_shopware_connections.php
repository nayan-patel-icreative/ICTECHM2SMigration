<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopware_connections', function (Blueprint $table) {
            // Sales Channel scoping — when set, migrations are filtered to this channel only.
            // Enables multi-shop use-cases where each Shopify store maps to one Shopware Storefront.
            $table->string('sales_channel_id', 64)->nullable()->after('language_config');
            $table->string('sales_channel_name', 255)->nullable()->after('sales_channel_id');
            // Navigation category root of the chosen sales channel (used to scope category/collection migration).
            $table->string('navigation_category_id', 64)->nullable()->after('sales_channel_name');
        });
    }

    public function down(): void
    {
        Schema::table('shopware_connections', function (Blueprint $table) {
            $table->dropColumn(['sales_channel_id', 'sales_channel_name', 'navigation_category_id']);
        });
    }
};
