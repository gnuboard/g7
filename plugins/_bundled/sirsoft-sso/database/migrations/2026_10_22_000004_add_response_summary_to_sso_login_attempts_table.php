<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 실패한 시도의 IdP 응답 요약(서명 여부, 유효 시각, 대상). 진단 화면이 「서명 검증 실패」·「시계 차이」를 가려 보게 한다 — 응답 원문은
     * 저장하지 않는다(§7 L-3).
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasColumn('sso_login_attempts', 'response_summary')) {
            Schema::table('sso_login_attempts', function (Blueprint $table) {
                $table->json('response_summary')->nullable()->after('reason_code')->comment('실패한 IdP 응답 요약(서명 여부, NotOnOrAfter, Audience — 원문 없음)');
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
        if (Schema::hasColumn('sso_login_attempts', 'response_summary')) {
            Schema::table('sso_login_attempts', function (Blueprint $table) {
                $table->dropColumn('response_summary');
            });
        }
    }
};
