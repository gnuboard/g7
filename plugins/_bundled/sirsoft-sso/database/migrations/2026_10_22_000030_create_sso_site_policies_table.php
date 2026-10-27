<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * SSO 사이트 정책 1행(강제 단위, 비상 계정, 자동 이동, 세션 상한, 보존일, 알림 임계).
     * 코어 설정 저장 경로가 아니라 플러그인 API 로만 저장한다 — 최고관리자 전용 항목의 검사를 우회해 저장할 수 없게.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('sso_site_policies')) {
            return;
        }

        Schema::create('sso_site_policies', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->json('policy')->comment('정책 값 — 강제 단위, 비상 계정, 자동 이동, 세션 상한, 보존일, 알림 임계');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('마지막으로 저장한 관리자 회원 ID (FK 없음 — 회원이 지워져도 정책은 남는다)');
            $table->timestamp('created_at')->nullable()->comment('생성 시각');
            $table->timestamp('updated_at')->nullable()->comment('수정 시각');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('sso_site_policies', function (Blueprint $table) {
                $table->comment('SSO 사이트 정책 (행 1개)');
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
        Schema::dropIfExists('sso_site_policies');
    }
};
