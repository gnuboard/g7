<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 조각 식별자 `fragment_key` 컬럼을 추가합니다 (#476 C13).
 *
 * 종전 고유 조합 (template, type, target_name, source_type, source_identifier) 은 **같은 확장이 같은 대상에
 * 조각 둘**을 보내면 뒤가 앞을 덮었다 — `sirsoft-ecommerce` 의 `admin-user-currency-field` 가
 * `admin-user-shipping-country-field` 에 덮여 관리자 회원 폼·회원가입에 통화 필드가 사라졌다.
 * `fragment_key` = 소스 파일 기본 이름(`.g7.html`/`.json` 제외)이고, 기존 행은 `target_name` 으로
 * 백필해 재설치 전까지 키를 갖게 한다(등록 경로가 옛 키 행을 파일 키로 잇거나 정리한다).
 *
 * 고유 키는 다음 마이그레이션(`2026_09_29_000001_add_override_target_key_to_template_layout_extensions_table`)이
 * 건다 — 템플릿 오버라이드의 대상 확장(`override_target`)까지 넣어야 7.0.x 의 오버라이드 행이 백필 뒤 충돌하지
 * 않는다(여기서 걸면 같은 확장점을 두 확장에 대해 오버라이드한 설치의 코어 업그레이드가 중단된다, #703).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('template_layout_extensions', function (Blueprint $table) {
            if (! Schema::hasColumn('template_layout_extensions', 'fragment_key')) {
                $table->string('fragment_key', 255)->nullable()->after('target_name')
                    ->comment('조각 식별자 (소스 파일 기본 이름, 확장자 제외) — 같은 대상에 조각이 여럿일 때 행을 가른다');
            }
        });

        DB::table('template_layout_extensions')->whereNull('fragment_key')->update([
            'fragment_key' => DB::raw('target_name'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 앞선 판의 이 마이그레이션이 건 고유 키가 남아 있을 수 있다 (다음 마이그레이션 롤백이 되돌린 형태 포함).
        if (Schema::hasIndex('template_layout_extensions', 'uk_layout_extension_fragment')) {
            Schema::table('template_layout_extensions', function (Blueprint $table) {
                $table->dropUnique('uk_layout_extension_fragment');
            });
        }

        Schema::table('template_layout_extensions', function (Blueprint $table) {
            if (Schema::hasColumn('template_layout_extensions', 'fragment_key')) {
                $table->dropColumn('fragment_key');
            }
        });
    }
};
