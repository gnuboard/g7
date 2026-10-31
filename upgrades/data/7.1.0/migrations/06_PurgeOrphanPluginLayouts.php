<?php

namespace App\Upgrades\Data\V7_1_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 삭제된 플러그인이 남긴 레이아웃 행을 지웁니다 (#712).
 *
 * 7.0.x 의 플러그인 비활성화·재활성화·삭제는 레이아웃 정리에 모듈용 조회(`source_type = module` 조건)를 써서, 플러그인
 * 레이아웃(설정 화면 등)이 플러그인을 삭제한 뒤에도 `template_layouts` 에 그대로 남았습니다. 7.1.0 은 출처 타입을 갈라
 * 정리하므로 새로 생기지는 않지만, 이미 남은 행은 여기서 한 번 지웁니다.
 *
 * 규칙: `source_type = plugin` 이면서 `plugins` 테이블에 그 식별자의 행이 없는 레이아웃만 영구 삭제합니다. 설치된
 * 플러그인(활성·비활성 무관)의 레이아웃은 그 플러그인의 설정 화면일 수 있으므로 건드리지 않습니다. 판본 이력
 * (`template_layout_versions`)은 외래 키 cascade 에 기대지 않고 먼저 지웁니다.
 *
 * V-1 안전: DB·Schema 파사드와 리터럴만 씁니다 — 모델·Enum·Repository 를 참조하지 않습니다.
 *
 * idempotent: 두 번째 실행에서는 대상 행이 없어 아무것도 바꾸지 않습니다.
 */
class PurgeOrphanPluginLayouts implements DataMigration
{
    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'PurgeOrphanPluginLayouts';
    }

    /**
     * 설치 기록이 없는 플러그인의 레이아웃 행을 영구 삭제합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable('template_layouts') || ! Schema::hasTable('plugins')) {
            $context->logger->info('[core:7.1.0] template_layouts 또는 plugins 테이블 부재 — 삭제된 플러그인 레이아웃 정리 스킵');

            return;
        }

        $installed = DB::table('plugins')->pluck('identifier')->all();

        $orphans = DB::table('template_layouts')
            ->where('source_type', 'plugin')
            ->whereNotNull('source_identifier')
            ->when($installed !== [], fn ($query) => $query->whereNotIn('source_identifier', $installed))
            ->get(['id', 'name', 'source_identifier']);

        if ($orphans->isEmpty()) {
            $context->logger->info('[core:7.1.0] 삭제된 플러그인의 레이아웃 잔존 0건');

            return;
        }

        $ids = $orphans->pluck('id')->all();

        DB::transaction(function () use ($ids): void {
            if (Schema::hasTable('template_layout_versions')) {
                DB::table('template_layout_versions')->whereIn('layout_id', $ids)->delete();
            }

            DB::table('template_layouts')->whereIn('id', $ids)->delete();
        });

        $context->logger->info(sprintf(
            '[core:7.1.0] 삭제된 플러그인의 레이아웃 %d건 정리 (%s)',
            count($ids),
            $orphans->map(fn ($row) => $row->source_identifier.':'.$row->name)->implode(', ')
        ));
    }
}
