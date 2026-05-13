<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 언어팩 테이블을 생성합니다.
     *
     * 슬롯(scope, target_identifier, locale) 단위로 여러 벤더의 언어팩이 공존할 수 있으며,
     * 슬롯당 active 상태는 1개만 허용됩니다 (functional unique index 로 강제).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('language_packs', function (Blueprint $table) {
            $table->id()->comment('언어팩 ID');
            $table->string('identifier', 200)->unique()->comment('언어팩 고유 식별자 ({vendor}-{scope}-{target?}-{locale})');
            $table->string('vendor', 100)->comment('언어팩 제작자 식별자');
            $table->enum('scope', ['core', 'module', 'plugin', 'template'])->comment('적용 대상 분류');
            $table->string('target_identifier', 150)->nullable()->comment('대상 확장 식별자 (scope=core일 때 null)');
            $table->string('locale', 20)->comment('IETF BCP-47 locale 태그');
            $table->string('locale_name', 100)->comment('영문 언어명');
            $table->string('locale_native_name', 100)->comment('원어 언어명');
            $table->enum('text_direction', ['ltr', 'rtl'])->default('ltr')->comment('텍스트 방향');
            $table->string('version', 50)->comment('언어팩 버전');
            $table->string('latest_version', 50)->nullable()->comment('최신 버전 (자동 업데이트 체크)');
            $table->string('target_version_constraint', 100)->nullable()->comment('대상 확장 버전 제약 (semver)');
            $table->boolean('target_version_mismatch')->default(false)->comment('대상 버전 불일치 경고 플래그');
            $table->string('license', 50)->nullable()->comment('라이선스');
            $table->json('description')->nullable()->comment('언어팩 설명 (다국어)');
            $table->enum('status', ['installed', 'active', 'inactive', 'updating', 'error'])
                ->default('installed')->comment('언어팩 상태');
            $table->boolean('is_protected')->default(false)->comment('제거 보호 플래그 (번들 팩)');
            $table->json('manifest')->comment('language-pack.json 전체 스냅샷');
            $table->string('source_type', 30)->nullable()->comment('설치 소스 유형 (zip/github/url/bundled/bundled_with_extension)');
            $table->string('source_url', 500)->nullable()->comment('설치 소스 URL 또는 경로');
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete()->comment('설치자 사용자 ID');
            $table->timestamp('installed_at')->nullable()->comment('설치 시각');
            $table->timestamp('activated_at')->nullable()->comment('활성화 시각');
            $table->timestamps();

            $table->index(['scope', 'target_identifier', 'locale'], 'language_packs_slot_index');
            $table->index('status', 'language_packs_status_index');
            $table->index('vendor', 'language_packs_vendor_index');
        });

        // 슬롯당 active 1개 제약은 LanguagePackService 의 activate/deactivate 트랜잭션에서 보장합니다
        // (DB functional unique index 는 MySQL 환경에 따라 schema 가시성 이슈가 발생할 수 있어
        //  application-level 일관성 보장 방식을 채택).
    }

    /**
     * 언어팩 테이블을 제거합니다.
     *
     * @return void
     */
    public function down(): void
    {
        if (Schema::hasTable('language_packs')) {
            Schema::drop('language_packs');
        }
    }
};
