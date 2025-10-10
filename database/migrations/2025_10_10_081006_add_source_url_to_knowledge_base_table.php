<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add source_url, metadata, and embedding columns to knowledge_base table
     */
    public function up(): void
    {
        // Add columns to knowledge_base table only
        if (Schema::hasTable('knowledge_base')) {
            Schema::table('knowledge_base', function (Blueprint $table) {
                if (!Schema::hasColumn('knowledge_base', 'source_url')) {
                    $table->string('source_url', 1024)->nullable()->after('source');
                    \Log::info('✅ Added source_url column to knowledge_base');
                } else {
                    \Log::info('ℹ️ source_url column already exists in knowledge_base');
                }
                
                if (!Schema::hasColumn('knowledge_base', 'metadata')) {
                    $table->json('metadata')->nullable()->after('language');
                    \Log::info('✅ Added metadata column to knowledge_base');
                }
                
                if (!Schema::hasColumn('knowledge_base', 'embedding')) {
                    $table->text('embedding')->nullable()->after('metadata');
                    \Log::info('✅ Added embedding column to knowledge_base');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('knowledge_base')) {
            Schema::table('knowledge_base', function (Blueprint $table) {
                if (Schema::hasColumn('knowledge_base', 'source_url')) {
                    $table->dropColumn('source_url');
                }
                if (Schema::hasColumn('knowledge_base', 'metadata')) {
                    $table->dropColumn('metadata');
                }
                if (Schema::hasColumn('knowledge_base', 'embedding')) {
                    $table->dropColumn('embedding');
                }
            });
        }
    }
};
