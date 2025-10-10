<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Standardize table name to plural: knowledge_bases
        try {
            $hasSingular = Schema::hasTable('knowledge_base');
            $hasPlural = Schema::hasTable('knowledge_bases');

            if ($hasSingular && !$hasPlural) {
                // Use schema rename when possible
                Schema::rename('knowledge_base', 'knowledge_bases');
            }
        } catch (\Throwable $e) {
            // Fallback with raw SQL if needed (some hosts have limited permissions)
            try {
                DB::statement('RENAME TABLE knowledge_base TO knowledge_bases');
            } catch (\Throwable $ignored) {
                // If both attempts fail, leave it; model may still be adjusted separately
            }
        }
    }

    public function down(): void
    {
        try {
            $hasPlural = Schema::hasTable('knowledge_bases');
            $hasSingular = Schema::hasTable('knowledge_base');
            if ($hasPlural && !$hasSingular) {
                Schema::rename('knowledge_bases', 'knowledge_base');
            }
        } catch (\Throwable $e) {
            try {
                DB::statement('RENAME TABLE knowledge_bases TO knowledge_base');
            } catch (\Throwable $ignored) {
                // ignore
            }
        }
    }
};
