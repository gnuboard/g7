<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 조각 식별자 `fragment_key` 를 고유 키에 넣는다 (#476 C13).
 *
 * 종전 고유 조합 (template, type, target_name, source_type, source_identifier) 은 **같은 확장이 같은 대상에
 * 조각 둘**을 보내면 뒤가 앞을 덮었다 — `sirsoft-ecommerce` 의 `admin-user-currency-field` 가
 * `admin-user-shipping-country-field` 에 덮여 관리자 회원 폼·회원가입에 통화 필드가 사라졌다.
 * `fragment_key` = 소스 파일 기본 이름(`.g7.html`/`.json` 제외)이고, 기존 행은 `target_name` 으로
 * 백필해 재설치 전까지 키를 갖게 한다.
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

        // 재실행 가드 — MySQL 은 DDL 을 되돌리지 않으므로 앞선 실행이 고유 키까지 만든 뒤 멈췄다면
        // 다음 실행은 여기서 `Duplicate key name` 으로 매번 실패한다(컬럼 가드만으로는 막히지 않는다).
        if (Schema::hasIndex('template_layout_extensions', 'uk_layout_extension_fragment')) {
            return;
        }

        // utf8mb4 에서 여섯 열 전체 길이는 InnoDB 키 상한(3072 바이트)을 넘는다 — 가변 길이 세 열은 191자
        // 접두 인덱스로 건다(실제 값은 모두 그보다 짧다). 접두 길이는 MySQL 문법이라 그 드라이버에서만 쓴다.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD UNIQUE `uk_layout_extension_fragment` (`template_id`, `extension_type`, `target_name`(191), `source_type`, `source_identifier`(191), `fragment_key`(191))',
                DB::getTablePrefix().'template_layout_extensions'
            ));

            return;
        }

        Schema::table('template_layout_extensions', function (Blueprint $table) {
            $table->unique(
                ['template_id', 'extension_type', 'target_name', 'source_type', 'source_identifier', 'fragment_key'],
                'uk_layout_extension_fragment'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
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
