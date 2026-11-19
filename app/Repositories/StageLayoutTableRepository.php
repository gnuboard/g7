<?php

namespace App\Repositories;

use App\Contracts\Repositories\StageLayoutTableRepositoryInterface;
use App\Support\LayoutTable;
use App\Support\RuntimeRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * 검수 서버(stage)용 레이아웃 테이블 저장소 — 운영 행 복사(resetFromLive)와 템플릿 제거 시 다른 역할 행 정리.
 *
 * 레이아웃 모델은 역할별 테이블을 자동으로 쓰지만, 한 역할의 코드가 다른 역할의 테이블을 다뤄야 하는 두 곳이 있다. 이 저장소가
 * 그 두 곳만 맡는다(그 밖의 코드는 `LayoutTable::name()`·모델을 쓴다).
 *
 * @since 7.2.0
 */
class StageLayoutTableRepository implements StageLayoutTableRepositoryInterface
{
    /**
     * 스테이지 테이블 ID 하한 — 운영 행을 ID 그대로 복사하므로 스테이지에서 새로 생긴 행이 다음 복사와 겹치지 않게 한다.
     */
    public const STAGE_ID_FLOOR = 1000000000;

    /**
     * 스테이지 테이블이 모두 있는지.
     *
     * @return bool 있으면 true
     */
    public function available(): bool
    {
        foreach (LayoutTable::ROUTED as $base) {
            if (! Schema::hasTable(LayoutTable::stage($base))) {
                return false;
            }
        }

        return true;
    }

    /**
     * 운영 레이아웃·조각을 스테이지 테이블에 ID 그대로 복사합니다 — 한 트랜잭션.
     *
     * 스테이지 4개 테이블을 비우고(버전 → 본 행 순), 운영 `template_layouts`·`template_layout_extensions` 를 본문·원본·해시·
     * lock_version 까지 그대로 옮긴다. 버전 이력은 복사하지 않는다(검수 서버의 편집 이력만 남는다). 운영 테이블에는 쓰지 않는다.
     *
     * @return array{layouts: int, extensions: int, checksum_match: bool} 복사 결과
     *
     * @throws RuntimeException MySQL·MariaDB 가 아닌 DB 드라이버일 때
     */
    public function resetFromLive(): array
    {
        // 생성 컬럼 판별(information_schema.COLUMNS)·AUTO_INCREMENT 조회·조정(information_schema.TABLES, ALTER TABLE)이
        // MySQL·MariaDB 문법이다. 스테이지 테이블 자체도 그 드라이버에서만 만들어지므로(마이그레이션) 다른 드라이버는 시작 전에 멈춘다.
        if (! $this->supportsDriver()) {
            throw new RuntimeException(__('common.console.stage_layouts.unsupported_driver', ['driver' => DB::getDriverName()]));
        }

        $stage = fn (string $base) => DB::getTablePrefix().LayoutTable::stage($base);
        $live = fn (string $base) => DB::getTablePrefix().LayoutTable::live($base);

        $result = DB::transaction(function () use ($stage, $live): array {
            DB::table(LayoutTable::stage('template_layout_extension_versions'))->delete();
            DB::table(LayoutTable::stage('template_layout_versions'))->delete();
            DB::table(LayoutTable::stage('template_layout_extensions'))->delete();
            DB::table(LayoutTable::stage('template_layouts'))->delete();

            foreach (['template_layouts', 'template_layout_extensions'] as $base) {
                // 생성 컬럼(override_target_key 등)은 값을 넣을 수 없다 — 저장 컬럼만 ID 째 옮긴다
                $columns = implode(', ', array_map(fn (string $c) => '`'.$c.'`', $this->storedColumns($base)));
                DB::statement('INSERT INTO '.$stage($base).' ('.$columns.') SELECT '.$columns.' FROM '.$live($base));
            }

            return [
                'layouts' => DB::table(LayoutTable::stage('template_layouts'))->count(),
                'extensions' => DB::table(LayoutTable::stage('template_layout_extensions'))->count(),
            ];
        });

        // AUTO_INCREMENT 는 하한 이상으로 둔다. DELETE 는 값을 낮추지 않으므로 보통은 그대로이고, 하한 아래일 때만(외부 TRUNCATE 등)
        // DDL 로 올린다 — DDL 은 트랜잭션을 암묵 커밋하므로 위 트랜잭션 밖에서, 필요할 때만.
        foreach (LayoutTable::ROUTED as $base) {
            $current = (int) (DB::selectOne(
                'SELECT AUTO_INCREMENT AS ai FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [DB::getTablePrefix().LayoutTable::stage($base)],
            )->ai ?? 0);
            $max = (int) DB::table(LayoutTable::stage($base))->max('id');
            $target = max(self::STAGE_ID_FLOOR, $max + 1);
            if ($current < $target) {
                DB::statement('ALTER TABLE '.$stage($base).' AUTO_INCREMENT = '.$target);
            }
        }

        $result['checksum_match'] = $this->checksum('template_layouts', true) === $this->checksum('template_layouts', false)
            && $this->checksum('template_layout_extensions', true) === $this->checksum('template_layout_extensions', false);

        return $result;
    }

    /**
     * 스테이지 레이아웃 테이블의 (템플릿 ID, 레이아웃 이름) 목록 — 캐시를 버릴 대상을 고를 때 쓴다.
     *
     * @return array<int, array{template_id: int, name: string}> 레이아웃 키
     */
    public function stageLayoutKeys(): array
    {
        return DB::table(LayoutTable::stage('template_layouts'))
            ->select(['template_id', 'name'])
            ->distinct()
            ->get()
            ->map(fn ($row) => ['template_id' => (int) $row->template_id, 'name' => (string) $row->name])
            ->all();
    }

    /**
     * 운영 레이아웃으로 덮어쓰면 사라질 검수 서버 편집본 수.
     *
     * 레이아웃·조각은 같은 ID 의 운영 행이 없거나 본문·원본 해시가 다른 스테이지 행을 센다(비교 기준은 복사 뒤 체크섬과 같은 칸).
     * 버전 기록은 복사 때 통째로 비우므로(운영 이력은 옮기지 않는다) 스테이지 버전 행 전부를 센다.
     *
     * @return array{layouts: int, extensions: int, versions: int} 지워질 건수
     */
    public function stageDivergence(): array
    {
        $prefix = DB::getTablePrefix();
        $count = function (string $base) use ($prefix): int {
            return DB::table(LayoutTable::stage($base).' as s')
                ->leftJoin(LayoutTable::live($base).' as l', 'l.id', '=', 's.id')
                ->where(function ($query) use ($prefix): void {
                    $query->whereNull('l.id')
                        ->orWhereRaw("NOT ({$prefix}s.content <=> {$prefix}l.content)")
                        ->orWhereRaw("NOT ({$prefix}s.original_content_hash <=> {$prefix}l.original_content_hash)");
                })
                ->count();
        };

        return [
            'layouts' => $count('template_layouts'),
            'extensions' => $count('template_layout_extensions'),
            'versions' => DB::table(LayoutTable::stage('template_layout_versions'))->count()
                + DB::table(LayoutTable::stage('template_layout_extension_versions'))->count(),
        ];
    }

    /**
     * 지금 DB 드라이버가 스테이지 복사에 쓰는 MySQL 전용 문법(information_schema·AUTO_INCREMENT·백틱 식별자)을 지원하는지.
     *
     * @return bool MySQL·MariaDB 면 true
     */
    private function supportsDriver(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * 저장 컬럼 목록 (생성 컬럼 제외).
     *
     * @param  string  $base  원 테이블
     * @return array<int, string> 컬럼 이름
     */
    private function storedColumns(string $base): array
    {
        $rows = DB::select(
            "SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA NOT LIKE '%GENERATED%' ORDER BY ORDINAL_POSITION",
            [DB::getTablePrefix().LayoutTable::live($base)],
        );

        return array_map(fn ($r) => (string) $r->name, $rows);
    }

    /**
     * 운영·스테이지 테이블의 본문 체크섬 (ID·본문·원본 해시).
     *
     * @param  string  $base  원 테이블
     * @param  bool  $stage  스테이지 테이블이면 true
     * @return string 체크섬
     */
    public function checksum(string $base, bool $stage): string
    {
        $table = $stage ? LayoutTable::stage($base) : LayoutTable::live($base);
        $hash = hash_init('sha256');
        DB::table($table)->orderBy('id')->select(['id', 'content', 'original_content_hash'])->chunk(500, function ($rows) use ($hash): void {
            foreach ($rows as $row) {
                hash_update($hash, $row->id.'|'.md5((string) $row->content).'|'.(string) ($row->original_content_hash ?? '')."\n");
            }
        });

        return hash_final($hash);
    }

    /**
     * 템플릿을 제거할 때 다른 역할 테이블의 그 템플릿 행을 명시적으로 지웁니다(버전 → 본 행 순, DB CASCADE 비의존).
     *
     * 지금 역할의 행은 레이아웃 삭제 서비스가 스냅샷과 함께 지운다. 다른 역할(운영 서버라면 스테이지)의 행은 검수용 사본이라 스냅샷 없이 지운다.
     *
     * @param  int  $templateId  템플릿 ID
     * @return int 지운 레이아웃·조각 수
     */
    public function deleteOtherRoleRowsForTemplate(int $templateId): int
    {
        $name = RuntimeRole::isStage() ? fn (string $b) => LayoutTable::live($b) : fn (string $b) => LayoutTable::stage($b);
        if (! Schema::hasTable($name('template_layouts'))) {
            return 0;
        }

        $extensionIds = DB::table($name('template_layout_extensions'))->where('template_id', $templateId)->pluck('id');
        $layoutIds = DB::table($name('template_layouts'))->where('template_id', $templateId)->pluck('id');

        DB::table($name('template_layout_extension_versions'))->whereIn('extension_id', $extensionIds)->delete();
        DB::table($name('template_layout_versions'))->whereIn('layout_id', $layoutIds)->delete();
        $deleted = DB::table($name('template_layout_extensions'))->whereIn('id', $extensionIds)->delete();
        $deleted += DB::table($name('template_layouts'))->whereIn('id', $layoutIds)->delete();

        return $deleted;
    }
}
