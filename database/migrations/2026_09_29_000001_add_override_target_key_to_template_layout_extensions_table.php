<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 레이아웃 확장 행의 고유 키에 템플릿 오버라이드 대상(`override_target`)을 넣습니다 (#703).
 *
 * 7.0.x 는 템플릿 오버라이드 행을 대상 확장까지 여섯 축으로 갈랐다. 조각 식별자 마이그레이션이 대상 확장을 뺀
 * 고유 키를 걸자, 한 템플릿이 같은 확장점·레이아웃을 두 확장에 대해 오버라이드한 설치는 백필 뒤 두 행의 키가
 * 같아져 고유 키 추가가 실패했고(코어 업그레이드 중단), 새 등록에서도 같은 이름의 오버라이드 파일을 두 대상에
 * 둘 수 없었다(두 번째가 중복 키로 등록되지 않음).
 *
 * `override_target` 은 모듈·플러그인 행에서 NULL 이다. MySQL 고유 인덱스는 NULL 끼리 겹쳐도 막지 않으므로
 * 그대로 넣으면 모듈·플러그인 행의 고유성이 사라진다. 그래서 NULL 을 빈 문자열로 바꾼 생성 컬럼
 * `override_target_key` 를 두고 그 컬럼을 고유 키에 넣는다(`2026_08_21` 적립 lot 유니크와 같은 방식).
 *
 * 앞선 판의 조각 식별자 마이그레이션을 이미 실행한 설치(대상 확장 없는 고유 키 보유)도 이 마이그레이션이 그
 * 키를 지우고 새로 건다. 고유 키를 걸기 전에 같은 신원의 중복 행을 하나로 정리해, 어떤 기존 데이터에서도
 * 마이그레이션이 중단되지 않게 한다.
 */
return new class extends Migration
{
    /** 고유 키 이름 (앞선 판과 같은 이름을 쓴다) */
    private const INDEX = 'uk_layout_extension_fragment';

    /** 접두 인덱스 길이 — utf8mb4 에서 일곱 열 합이 InnoDB 키 상한(3072 바이트) 안에 들게 한다 */
    private const PREFIX_SOURCE = 128;

    private const PREFIX_FRAGMENT = 191;

    private const PREFIX_OVERRIDE = 128;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('template_layout_extensions')) {
            return;
        }

        // 앞선 마이그레이션의 백필을 거치지 않은 행이 있으면 같은 규칙(대상 이름)으로 채운다.
        DB::table('template_layout_extensions')->whereNull('fragment_key')->update([
            'fragment_key' => DB::raw('target_name'),
        ]);

        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);

        if ($mysql && ! Schema::hasColumn('template_layout_extensions', 'override_target_key')) {
            DB::statement(sprintf(
                "ALTER TABLE `%s` ADD COLUMN `override_target_key` VARCHAR(255) GENERATED ALWAYS AS (IFNULL(`override_target`, '')) VIRTUAL"
                ." COMMENT '고유 키용 오버라이드 대상 (override_target 의 NULL 을 빈 문자열로 — 모듈·플러그인 행 고유성 유지)' AFTER `override_target`",
                DB::getTablePrefix().'template_layout_extensions'
            ));
        }

        if ($this->indexIncludes($mysql ? 'override_target_key' : 'override_target')) {
            return;
        }

        if (Schema::hasIndex('template_layout_extensions', self::INDEX)) {
            Schema::table('template_layout_extensions', function (Blueprint $table) {
                $table->dropUnique(self::INDEX);
            });
        }

        $this->consolidateDuplicates();

        if ($mysql) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD UNIQUE `%s` (`template_id`, `extension_type`, `target_name`, `source_type`, `source_identifier`(%d), `fragment_key`(%d), `override_target_key`(%d))',
                DB::getTablePrefix().'template_layout_extensions',
                self::INDEX,
                self::PREFIX_SOURCE,
                self::PREFIX_FRAGMENT,
                self::PREFIX_OVERRIDE
            ));

            return;
        }

        // 그 밖의 드라이버(테스트용 SQLite 등)는 생성 컬럼 없이 원 컬럼으로 건다 — NULL 끼리의 고유성은 앱 계층
        // (withTrashed 조회 뒤 갱신)이 지킨다.
        Schema::table('template_layout_extensions', function (Blueprint $table) {
            $table->unique(
                ['template_id', 'extension_type', 'target_name', 'source_type', 'source_identifier', 'fragment_key', 'override_target'],
                self::INDEX
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('template_layout_extensions')) {
            return;
        }

        if (Schema::hasIndex('template_layout_extensions', self::INDEX)) {
            Schema::table('template_layout_extensions', function (Blueprint $table) {
                $table->dropUnique(self::INDEX);
            });
        }

        if (Schema::hasColumn('template_layout_extensions', 'override_target_key')) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` DROP COLUMN `override_target_key`',
                DB::getTablePrefix().'template_layout_extensions'
            ));
        }
    }

    /**
     * 고유 키가 이미 주어진 열을 포함하는지 봅니다 (재실행·부분 실행 멱등).
     *
     * @param  string  $column  확인할 열
     * @return bool 포함하면 true
     */
    private function indexIncludes(string $column): bool
    {
        foreach (Schema::getIndexes('template_layout_extensions') as $index) {
            if (($index['name'] ?? null) === self::INDEX && in_array($column, $index['columns'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 새 고유 키에서 겹치는 행을 하나만 남기고 나머지를 비켜 둡니다.
     *
     * 남기는 행은 살아 있는 행을 우선하고, 그 가운데 가장 최근(id 가 큰) 행이다. 나머지는 소스에 없는 키
     * (`{키}#dup-{id}`)로 바꾸고 soft delete 한다 — 영구 삭제하지 않으므로 필요하면 되살려 볼 수 있고, 등록
     * 경로의 고아 정리가 이후에도 그 키를 살아 있는 조각으로 보지 않는다.
     *
     * 반복 종료는 데이터에 맡기지 않는다 — 한 바퀴에서 비킨 행이 없으면 멈춘다.
     *
     * @return void
     */
    private function consolidateDuplicates(): void
    {
        $groupExpr = [
            'template_id',
            'extension_type',
            'target_name',
            'source_type',
            sprintf('LEFT(source_identifier, %d)', self::PREFIX_SOURCE),
            sprintf("LEFT(IFNULL(fragment_key, ''), %d)", self::PREFIX_FRAGMENT),
            sprintf("LEFT(IFNULL(override_target, ''), %d)", self::PREFIX_OVERRIDE),
        ];

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $groupExpr = ['template_id', 'extension_type', 'target_name', 'source_type', 'source_identifier', "IFNULL(fragment_key, '')", "IFNULL(override_target, '')"];
        }

        $groupBy = implode(', ', $groupExpr);

        while (true) {
            $groups = DB::table('template_layout_extensions')
                ->selectRaw("{$groupBy}, COUNT(*) AS dup_count")
                ->groupByRaw($groupBy)
                ->havingRaw('COUNT(*) > 1')
                ->limit(100)
                ->get();

            if ($groups->isEmpty()) {
                return;
            }

            $moved = 0;

            foreach ($groups as $group) {
                $rows = DB::table('template_layout_extensions')
                    ->where('template_id', $group->template_id)
                    ->where('extension_type', $group->extension_type)
                    ->where('target_name', $group->target_name)
                    ->where('source_type', $group->source_type)
                    ->get(['id', 'source_identifier', 'fragment_key', 'override_target', 'deleted_at'])
                    ->filter(fn ($row) => $this->sameIdentity($row, $group))
                    ->sortBy([
                        fn ($a, $b) => ($a->deleted_at === null ? 0 : 1) <=> ($b->deleted_at === null ? 0 : 1),
                        fn ($a, $b) => $b->id <=> $a->id,
                    ])
                    ->values();

                foreach ($rows->slice(1) as $row) {
                    DB::table('template_layout_extensions')->where('id', $row->id)->update([
                        'fragment_key' => mb_substr((string) $row->fragment_key, 0, 180).'#dup-'.$row->id,
                        'deleted_at' => $row->deleted_at ?? now(),
                    ]);
                    $moved++;
                }
            }

            if ($moved === 0) {
                return;
            }
        }
    }

    /**
     * 행이 중복 그룹과 같은 신원(접두 비교)인지 판정합니다.
     *
     * @param  object  $row  후보 행
     * @param  object  $group  그룹 대표 값 (GROUP BY 산출)
     * @return bool 같은 신원이면 true
     */
    private function sameIdentity(object $row, object $group): bool
    {
        $values = array_values((array) $group);

        [$source, $fragment, $override] = [$values[4], $values[5], $values[6]];

        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);

        $cut = static fn (?string $value, int $length): string => $mysql
            ? mb_substr((string) $value, 0, $length)
            : (string) $value;

        return mb_strtolower($cut($row->source_identifier, self::PREFIX_SOURCE)) === mb_strtolower((string) $source)
            && mb_strtolower($cut($row->fragment_key, self::PREFIX_FRAGMENT)) === mb_strtolower((string) $fragment)
            && mb_strtolower($cut($row->override_target, self::PREFIX_OVERRIDE)) === mb_strtolower((string) $override);
    }
};
