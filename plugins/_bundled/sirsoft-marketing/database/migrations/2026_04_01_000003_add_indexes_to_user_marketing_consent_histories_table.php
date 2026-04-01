<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * user_marketing_consent_histories 테이블 인덱스 추가.
 *
 * - [user_id, created_at]: 사용자별 시간순 이력 조회
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_marketing_consent_histories', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'idx_marketing_consent_histories_user_created');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_marketing_consent_histories')) {
            return;
        }

        $existingIndexes = array_column(Schema::getIndexes('user_marketing_consent_histories'), 'name');

        Schema::table('user_marketing_consent_histories', function (Blueprint $table) use ($existingIndexes) {
            if (in_array('idx_marketing_consent_histories_user_created', $existingIndexes)) {
                $table->dropIndex('idx_marketing_consent_histories_user_created');
            }
        });
    }
};
