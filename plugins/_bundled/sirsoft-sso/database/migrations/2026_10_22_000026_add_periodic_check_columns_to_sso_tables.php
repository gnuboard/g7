<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 주기 점검 시각. IdP 의 값은 진단 화면의 「마지막 점검 시각」, 연결의 값은 한 회차 처리 상한(500건)을 넘을 때 다음 회차가
     * 이어서 볼 연결을 고르는 기준이다.
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasColumn('sso_identity_providers', 'periodic_checked_at')) {
            Schema::table('sso_identity_providers', function (Blueprint $table) {
                $table->timestamp('periodic_checked_at')->nullable()->after('last_error_code')->comment('마지막 주기 점검(재직 여부 확인) 시각');
            });
        }

        if (! Schema::hasColumn('sso_accounts', 'periodic_checked_at')) {
            Schema::table('sso_accounts', function (Blueprint $table) {
                $table->timestamp('periodic_checked_at')->nullable()->after('last_login_at')->comment('이 연결의 마지막 주기 점검 시각');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        if (Schema::hasColumn('sso_accounts', 'periodic_checked_at')) {
            Schema::table('sso_accounts', function (Blueprint $table) {
                $table->dropColumn('periodic_checked_at');
            });
        }

        if (Schema::hasColumn('sso_identity_providers', 'periodic_checked_at')) {
            Schema::table('sso_identity_providers', function (Blueprint $table) {
                $table->dropColumn('periodic_checked_at');
            });
        }
    }
};
