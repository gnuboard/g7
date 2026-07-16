<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('gdpr_policy_versions');

        Schema::create('gdpr_policy_versions', function (Blueprint $table) {
            $table->id()->comment('행 ID');
            $table->unsignedInteger('version')->unique()->comment('단조 증가 정책 버전 (1, 2, 3, ...)');
            $table->string('change_type', 20)->comment('변경 종류 (material/non_material/initial)');
            $table->text('memo')->nullable()->comment('운영자 변경 사유 메모 (Material 시 필수)');
            $table->json('snapshot')->comment('발행 시점 settings 스냅샷 (cookie_categories + blocked_domains + privacy_policy_slug 등)');
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('발행 운영자 user_id (운영자 삭제 시 NULL — 이력은 영구 보존)');
            $table->timestamp('created_at')->nullable()->comment('발행 일시 (UPDATED_AT 없음 — 불변 레코드)');

            $table->index('created_at');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('gdpr_policy_versions', function (Blueprint $table) {
                $table->comment('GDPR 정책 버전 발행 이력 (불변 append-only) — Art.7(1) 동의 입증 + Art.30 처리 기록');
            });
        }

        // 신규 설치 시 initial 행 1개 자동 시드 (version=1, change_type=initial).
        // 운영자가 첫 카테고리 추가/삭제 시 v2 발행 → 단조 증가.
        // 회원 동의 시 policy_version = "1" 자동 기록.
        //
        // snapshot 은 plugin.php::getConfigValues() 의 settings 기본값 (cookie_categories /
        // blocked_domains / privacy_policy_slug 등) 을 그대로 시드 — Art.7(1) 입증 책임 충족.
        // 빈 snapshot 으로 두면 회원이 v1 에 동의했을 때 "어떤 정책에 동의했는지" 입증 불가.
        // cookie_categories 는 settings 컬럼이 string 이라 json_encode 된 형태이므로 snapshot
        // 에는 디코드된 배열로 정규화 (snapshot 스키마는 항상 객체/배열 — admin 측 발행 경로와 일치).
        $defaults = (new \Plugins\Sirsoft\Gdpr\Plugin())->getConfigValues();
        if (isset($defaults['cookie_categories']) && is_string($defaults['cookie_categories'])) {
            $decoded = json_decode($defaults['cookie_categories'], true);
            $defaults['cookie_categories'] = is_array($decoded) ? $decoded : [];
        }

        DB::table('gdpr_policy_versions')->insertOrIgnore([
            'version' => 1,
            'change_type' => 'initial',
            'memo' => null,
            'snapshot' => json_encode($defaults, JSON_UNESCAPED_UNICODE),
            'created_by' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('gdpr_policy_versions')) {
            Schema::dropIfExists('gdpr_policy_versions');
        }
    }
};
