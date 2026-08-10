<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('gdpr_user_consents')) {
            return;
        }

        Schema::table('gdpr_user_consents', function (Blueprint $table) {
            if (! Schema::hasColumn('gdpr_user_consents', 'is_rejected')) {
                $table->boolean('is_rejected')
                    ->default(false)
                    ->after('is_consented')
                    ->comment('명시적 거부 여부 (선택형 카테고리를 사용자가 명시적으로 거부한 상태)');
            }

            if (! Schema::hasColumn('gdpr_user_consents', 'rejected_at')) {
                $table->timestamp('rejected_at')
                    ->nullable()
                    ->after('revoked_at')
                    ->comment('최근 명시적 거부 일시');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('gdpr_user_consents')) {
            return;
        }

        Schema::table('gdpr_user_consents', function (Blueprint $table) {
            if (Schema::hasColumn('gdpr_user_consents', 'rejected_at')) {
                $table->dropColumn('rejected_at');
            }

            if (Schema::hasColumn('gdpr_user_consents', 'is_rejected')) {
                $table->dropColumn('is_rejected');
            }
        });
    }
};
