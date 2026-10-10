<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftBoard\V1_2_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 기존 게시판의 「사용할 반응 유형」을 추천(like)으로 채웁니다.
 *
 * 1.2.0 마이그레이션은 boards 에 use_reaction·use_comment_reaction·use_scrap 을 기본값 true 로,
 * active_reaction_types 를 기본값 없는 nullable 로 더한다. 그래서 업데이트한 사이트의 기존 게시판은
 * 반응·스크랩이 켜진 것으로 보이지만 active_reaction_types 가 비어(NULL) 반응 게이트
 * (BoardResource::reactionsEnabled)가 반응을 끈다 — 설정 화면에는 켜져 있는데 반응 버튼이 나오지
 * 않는다. 새로 만든 게시판이 받는 기본 설정(defaults.json basic_defaults.active_reaction_types)과
 * 같게 추천을 채운다. 스크랩은 마이그레이션 기본값(true)으로 이미 켜져 있어 손대지 않는다.
 *
 * 운영자가 관리 화면에서 반응 유형을 모두 빼고 저장한 게시판은 빈 배열(`[]`)이라 대상이 아니다 —
 * 이 스텝을 다시 실행해도(module:update --force) 운영자 선택을 덮지 않는다.
 * 채울 반응 유형이 표에 없거나(운영자가 지움) 꺼져 있으면 건너뛴다.
 *
 * V-1 안전: Facades\DB/Schema 만 사용하고 Board·ReactionType 모델을 참조하지 않는다.
 */
class BackfillBoardActiveReactionTypes implements DataMigration
{
    /** defaults.json basic_defaults.active_reaction_types 의 1.2.0 시점 값 동결본 */
    private const DEFAULT_CODES = ['like'];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'BackfillBoardActiveReactionTypes';
    }

    /**
     * active_reaction_types 가 NULL 인 게시판에 기본 반응 유형을 채웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable('boards') || ! Schema::hasColumn('boards', 'active_reaction_types')
            || ! Schema::hasTable('board_reaction_types')) {
            $context->logger->info('[board:1.2.0] 게시판 반응 유형 백필 — 대상 스키마 부재로 스킵');

            return;
        }

        $codes = DB::table('board_reaction_types')
            ->whereIn('code', self::DEFAULT_CODES)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->pluck('code')
            ->all();

        if ($codes === []) {
            $context->logger->warning('[board:1.2.0] 게시판 반응 유형 백필 — 채울 반응 유형이 없거나 꺼져 있어 스킵');

            return;
        }

        $updated = DB::table('boards')
            ->whereNull('active_reaction_types')
            ->update(['active_reaction_types' => json_encode(array_values($codes))]);

        $context->logger->info("[board:1.2.0] 게시판 반응 유형 백필 완료 — 게시판 {$updated}개에 ".implode(',', $codes));
    }
}
