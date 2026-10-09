<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Upgrade;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Modules\Sirsoft\Board\Upgrades\Upgrade_1_0_3;
use PHPUnit\Framework\Attributes\Group;

/**
 * 1.0.3 반응 유형(추천/비추천) 시드 백필 업그레이드 스텝 테스트
 *
 * 검증 목적:
 * - 반응 유형이 비어 있던 사이트(install 시더 미실행)에 추천·비추천이 채워진다
 * - 이미 유형이 있으면 사용자 수정(user_overrides·is_active)을 보존한다
 * - 재실행해도 중복 생성되지 않는다 (멱등)
 */
#[Group('board')]
#[Group('upgrade')]
class ReactionTypeSeedBackfillTest extends BoardTestCase
{
    /**
     * 각 테스트 전에 공통 픽스처를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // install 시더가 채운 기본 유형을 비워 "업데이트로 처음 받는 사이트" 상태를 재현한다.
        // board_reactions 가 FK 로 참조하므로 자식 행을 먼저 지운 뒤 유형을 비운다(truncate 는 FK 제약에 막힌다).
        DB::table('board_reactions')->delete();
        DB::table('board_reaction_types')->delete();
    }

    /**
     * 테스트를 돕는 헬퍼입니다.
     *
     * @return string 문자열
     */
    protected function getTestBoardSlug(): string
    {
        return 'reaction-seed-backfill';
    }

    /**
     * 빈 테이블에 추천·비추천 기본 유형이 백필된다
     *
     * @scenario case=existing_site_update_backfills_reaction_defaults
     *
     * @effects reaction_types_backfilled_on_update_when_empty
     *
     * @return void 반환값 없음
     */
    public function test_default_reaction_types_are_backfilled_when_empty(): void
    {
        $this->assertSame(0, DB::table('board_reaction_types')->count(), '사전 조건: 테이블이 비어 있어야 합니다.');

        $this->runUpgrade();

        $this->assertSame(2, DB::table('board_reaction_types')->count(), '추천·비추천 2건이 채워져야 합니다.');

        $like = DB::table('board_reaction_types')->where('code', 'like')->first();
        $dislike = DB::table('board_reaction_types')->where('code', 'dislike')->first();

        $this->assertNotNull($like, 'like 유형이 존재해야 합니다.');
        $this->assertNotNull($dislike, 'dislike 유형이 존재해야 합니다.');
        $this->assertTrue((bool) $like->is_active, 'like 는 활성 상태여야 합니다.');
        $this->assertSame('fas fa-thumbs-up', $like->icon, 'like 아이콘이 채워져야 합니다.');
        $this->assertStringContainsString('추천', $like->name, 'like 의 다국어 라벨(ko)이 채워져야 합니다.');
        $this->assertStringContainsString('Recommend', $like->name, 'like 의 다국어 라벨(en)이 채워져야 합니다.');
        $this->assertSame(1, (int) $like->display_order, 'like 표시 순서는 1 이어야 합니다.');
        $this->assertSame(2, (int) $dislike->display_order, 'dislike 표시 순서는 2 이어야 합니다.');
    }

    /**
     * 이미 존재하는 유형은 사용자 수정을 보존하고 건드리지 않는다
     *
     * @scenario case=existing_site_update_backfills_reaction_defaults
     *
     * @effects reaction_type_backfill_preserves_user_edits
     *
     * @return void 반환값 없음
     */
    public function test_existing_reaction_type_is_preserved(): void
    {
        // 운영자가 like 를 비활성화하고 아이콘을 바꿔 둔 상태를 재현한다.
        DB::table('board_reaction_types')->insert([
            'code' => 'like',
            'name' => json_encode(['ko' => '좋아요', 'en' => 'Like'], JSON_UNESCAPED_UNICODE),
            'icon' => 'fas fa-heart',
            'display_order' => 9,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runUpgrade();

        $like = DB::table('board_reaction_types')->where('code', 'like')->first();
        $this->assertSame('fas fa-heart', $like->icon, '운영자가 바꾼 아이콘이 보존되어야 합니다.');
        $this->assertFalse((bool) $like->is_active, '운영자가 끈 활성 상태가 보존되어야 합니다.');
        $this->assertSame(9, (int) $like->display_order, '운영자가 바꾼 표시 순서가 보존되어야 합니다.');

        // 없던 dislike 는 새로 채워진다.
        $this->assertSame(1, DB::table('board_reaction_types')->where('code', 'dislike')->count(), '없던 dislike 는 백필되어야 합니다.');
    }

    /**
     * 재실행해도 중복 생성되지 않는다 (멱등)
     *
     * @scenario case=existing_site_update_backfills_reaction_defaults
     *
     * @effects reaction_types_backfilled_on_update_when_empty
     *
     * @return void 반환값 없음
     */
    public function test_backfill_is_idempotent(): void
    {
        $this->runUpgrade();
        $this->runUpgrade();

        $this->assertSame(2, DB::table('board_reaction_types')->count(), '재실행해도 2건이어야 합니다.');
        $this->assertSame(1, DB::table('board_reaction_types')->where('code', 'like')->count(), 'like 는 1건이어야 합니다.');
        $this->assertSame(1, DB::table('board_reaction_types')->where('code', 'dislike')->count(), 'dislike 는 1건이어야 합니다.');
    }

    /**
     * 1.0.3 업그레이드 스텝을 실행합니다 (data/1.0.3/migrations/ 전체 순차 실행).
     *
     * @return void 반환값 없음
     */
    private function runUpgrade(): void
    {
        $context = new UpgradeContext('1.0.2', '1.0.3', '1.0.3', 'extension-upgrade');

        $step = new Upgrade_1_0_3;
        $step->run($context);
    }
}
