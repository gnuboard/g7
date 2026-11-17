<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 검수 서버(실행 역할 stage)용 레이아웃 테이블 4개와 미리보기 역할 컬럼을 신설합니다.
 *
 * 검수 서버는 운영 서버와 DB 를 공유한다. 검수 서버에서 레이아웃을 갱신·편집하면 운영 화면이 바뀌었다. stage 역할의 레이아웃
 * 모델은 `stage_` 접두 테이블을 쓴다(원 테이블과 같은 스키마·인덱스). 스테이지 테이블의 ID 는 10억부터 시작해 다음 복사
 * (`stage-layouts:reset-from-live` 가 운영 행을 ID 그대로 복사)와 충돌하지 않는다. 미리보기 행은 만든 서버의 역할을 기록한다.
 * 외래키의 연쇄 삭제는 안전망이다 — 템플릿 제거는 스테이지 행을 명시적으로 지운다.
 *
 * @since 7.2.0
 */
return new class extends Migration
{
    /**
     * 원 테이블 → 스테이지 테이블.
     */
    private const TABLES = [
        'template_layouts' => 'stage_template_layouts',
        'template_layout_extensions' => 'stage_template_layout_extensions',
        'template_layout_versions' => 'stage_template_layout_versions',
        'template_layout_extension_versions' => 'stage_template_layout_extension_versions',
    ];

    /**
     * 스테이지 테이블 ID 시작값.
     */
    private const STAGE_AUTO_INCREMENT = 1000000000;

    /**
     * 테이블을 만듭니다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        // 스테이지 테이블은 `CREATE TABLE … LIKE`(원 테이블의 컬럼·코멘트·인덱스·생성 컬럼 그대로 복제)와
        // `ALTER TABLE … AUTO_INCREMENT`(ID 시작값) 로 만든다 — 둘 다 MySQL·MariaDB 문법이다. 다른 드라이버(테스트용 SQLite 등)는
        // 스테이지 테이블을 만들지 않고 건너뛴다. 그 경우 검수 서버 기능은 테이블 부재로 판정되어(`stage-layouts:reset-from-live` 가 안내)
        // 쓰이지 않고, 아래 미리보기 역할 컬럼은 드라이버와 무관하게 추가한다.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->createStageTables();
        } else {
            Log::warning('검수용 레이아웃 테이블 생성 건너뜀 — MySQL·MariaDB 전용 DDL', ['driver' => DB::getDriverName()]);
        }

        if (Schema::hasTable('template_layout_previews') && ! Schema::hasColumn('template_layout_previews', 'runtime_role')) {
            Schema::table('template_layout_previews', function (Blueprint $table) {
                $table->string('runtime_role', 10)->default('live')->comment('미리보기를 만든 서버의 실행 역할 (live|stage)');
                $table->index('runtime_role', 'idx_layout_previews_runtime_role');
            });
        }
    }

    /**
     * 스테이지 테이블을 만들고 외래키·ID 시작값을 맞춥니다 (MySQL·MariaDB 전용, 재실행 안전).
     *
     * @return void 반환값 없음
     */
    private function createStageTables(): void
    {
        foreach (self::TABLES as $live => $stage) {
            if (Schema::hasTable($stage) || ! Schema::hasTable($live)) {
                continue;
            }

            // 원 테이블과 같은 컬럼·코멘트·인덱스 (외래키는 아래에서 스테이지 테이블 기준으로 다시 건다)
            DB::statement('CREATE TABLE '.DB::getTablePrefix().$stage.' LIKE '.DB::getTablePrefix().$live);
        }

        $this->addForeign('stage_template_layouts', 'template_id', 'templates', 'stg_tl_template_fk');
        $this->addForeign('stage_template_layout_extensions', 'template_id', 'templates', 'stg_tle_template_fk');
        $this->addForeign('stage_template_layout_versions', 'layout_id', 'stage_template_layouts', 'stg_tlv_layout_fk');
        $this->addForeign('stage_template_layout_extension_versions', 'extension_id', 'stage_template_layout_extensions', 'stg_tlev_extension_fk');

        // ID 시작값은 방금 만든 테이블만이 아니라, 이전 실행이 생성 직후 중단됐거나 외부에서 TRUNCATE 해 하한 아래로 내려간
        // 기존 테이블에도 맞춘다 — 하한 이상이면 건드리지 않는다(DDL 은 필요할 때만).
        foreach (self::TABLES as $stage) {
            if (Schema::hasTable($stage)) {
                $this->raiseAutoIncrement($stage);
            }
        }
    }

    /**
     * 스테이지 테이블의 AUTO_INCREMENT 가 하한(또는 기존 최대 ID + 1)보다 낮을 때만 올립니다.
     *
     * @param  string  $stage  스테이지 테이블
     * @return void 반환값 없음
     */
    private function raiseAutoIncrement(string $stage): void
    {
        $table = DB::getTablePrefix().$stage;
        $current = (int) (DB::selectOne(
            'SELECT AUTO_INCREMENT AS ai FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        )->ai ?? 0);
        $target = max(self::STAGE_AUTO_INCREMENT, (int) DB::table($stage)->max('id') + 1);

        if ($current < $target) {
            DB::statement('ALTER TABLE '.$table.' AUTO_INCREMENT = '.$target);
        }
    }

    /**
     * 외래키를 겁니다 (이미 있으면 건너뜀).
     *
     * @param  string  $table  자식 테이블
     * @param  string  $column  컬럼
     * @param  string  $parent  부모 테이블
     * @param  string  $name  외래키 이름
     * @return void 반환값 없음
     */
    private function addForeign(string $table, string $column, string $parent, string $name): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasTable($parent)) {
            return;
        }
        foreach (Schema::getForeignKeys($table) as $fk) {
            if (($fk['name'] ?? null) === $name) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $parent, $name) {
            $blueprint->foreign($column, $name)->references('id')->on($parent)->cascadeOnDelete();
        });
    }

    /**
     * 테이블을 지웁니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        if (Schema::hasTable('template_layout_previews') && Schema::hasColumn('template_layout_previews', 'runtime_role')) {
            Schema::table('template_layout_previews', function (Blueprint $table) {
                $table->dropIndex('idx_layout_previews_runtime_role');
                $table->dropColumn('runtime_role');
            });
        }

        foreach (array_reverse(self::TABLES) as $stage) {
            Schema::dropIfExists($stage);
        }
    }
};
