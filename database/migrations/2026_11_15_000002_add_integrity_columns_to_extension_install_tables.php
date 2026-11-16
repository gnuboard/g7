<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 확장·언어팩 설치 행에 패키지 무결성 검증 결과 칸을 추가합니다.
 *
 * 설치·업데이트 때 받은 패키지의 서명 검증 결과를 행에 남겨 확장 상세 화면이 「서명 확인됨: {제작자}」·「서명 없음」을
 * 보여 준다. 이 칸 전에 설치된 행은 null(기록 없음)이다 — 옛 설치는 무엇으로 들어왔는지 알 수 없으므로 백필하지 않는다
 * (업그레이드 스텝 없음). 다음 업데이트 때 그 판의 결과가 적힌다.
 */
return new class extends Migration
{
    /**
     * 칸을 추가할 설치 표 (모듈·플러그인·템플릿·언어팩)
     *
     * @var array<int, string>
     */
    private array $tables = ['modules', 'plugins', 'templates', 'language_packs'];

    /**
     * 설치 표마다 무결성 칸 2개를 추가합니다 (표가 없거나 칸이 이미 있으면 그 표는 건너뜀).
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'integrity_status')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->string('integrity_status', 20)->nullable()
                    ->comment('패키지 무결성 검증 결과 (verified: 서명 확인, unsigned: 서명 없음, unverifiable: 서명은 있으나 이 서버에서 검증 불가, bundled: 번들 원본, null: 기록 없음 — 이 칸 전 설치)');
                $table->string('integrity_key_id', 64)->nullable()
                    ->comment('서명을 확인한 키 식별자 (verified 일 때만, 그 밖은 null)');
            });
        }
    }

    /**
     * 추가한 무결성 칸을 지웁니다 (칸이 있는 표만).
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'integrity_status')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['integrity_status', 'integrity_key_id']);
            });
        }
    }
};
