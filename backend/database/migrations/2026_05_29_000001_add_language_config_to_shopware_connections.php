<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopware_connections', function (Blueprint $table) {
            // Stores the list of Shopware languages the user wants to migrate.
            // Format: [{"id": "...", "locale": "de-DE", "name": "Deutsch", "enabled": true}, ...]
            $table->json('language_config')->nullable()->after('api_url');
        });
    }

    public function down(): void
    {
        Schema::table('shopware_connections', function (Blueprint $table) {
            $table->dropColumn('language_config');
        });
    }
};
