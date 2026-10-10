<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Upgrade;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\ModuleManager;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Modules\Sirsoft\Board\Upgrades\Upgrade_1_2_0;
use PHPUnit\Framework\Attributes\Group;

/**
 * 1.1.2(7.0.11 배포본)에서 1.2.0 으로 올릴 때 반응 기능이 실제로 켜지는지 검증한다.
 *
 * 반응 유형 시드가 1.0.3 스텝에만 있어 1.1.2 출발이면 스텝 선택(`> from && <= to`)에서 빠졌고,
 * 기존 게시판의 active_reaction_types 가 NULL 로 남아 반응 게이트가 반응을 껐다(BU-1·Q-5).
 * ModuleManager 의 스텝 선택을 그대로 거쳐 1.2.0 스텝이 둘 다 메우는지 확인한다.
 * 같은 결함군: 새로 설치할 때도 반응 유형 시더가 설치 시더 목록에 없었다.
 */
#[Group('board')]
#[Group('upgrade')]
class ReactionUpgradeFrom112Test extends BoardTestCase
{
    /**
     * 테스트 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'reaction-upgrade-from-112';
    }

    /**
     * 1.1.2 설치본 상태(반응 유형 표 비어 있음, 게시판 반응 유형 NULL)를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // board_reactions 가 FK 로 참조하므로 자식 행을 먼저 지운다.
        DB::table('board_reactions')->delete();
        DB::table('board_reaction_types')->delete();
        DB::table('boards')->where('id', $this->board->id)->update(['active_reaction_types' => null]);
    }

    /**
     * 1.1.2 출발 업데이트가 ModuleManager 스텝 선택을 거쳐 반응 유형을 채우고 게시판 반응을 켠다.
     *
     * @scenario case=update_from_1_1_2_enables_reactions
     *
     * @effects reaction_types_seeded_from_1_1_2, existing_board_active_reaction_types_backfilled
     *
     * @return void 반환값 없음
     */
    public function test_update_from_1_1_2_seeds_types_and_enables_board_reactions(): void
    {
        $this->runModuleUpgrade('1.1.2', '1.2.0');

        $this->assertSame(
            ['like', 'dislike'],
            DB::table('board_reaction_types')->orderBy('display_order')->pluck('code')->all(),
            '1.1.2 에서 올린 사이트에도 추천·비추천이 채워져야 합니다.',
        );

        $dislike = json_decode(DB::table('board_reaction_types')->where('code', 'dislike')->value('name'), true);
        $this->assertSame('よくない', $dislike['ja'] ?? null, '비추천의 일본어 이름은 「よくない」여야 합니다.');

        $board = DB::table('boards')->where('id', $this->board->id)->first();
        $this->assertSame(['like'], json_decode($board->active_reaction_types, true), '기존 게시판의 사용할 반응 유형이 추천으로 채워져야 합니다.');
        $this->assertTrue((bool) $board->use_reaction, '기존 게시판의 반응 사용은 켜져 있어야 합니다.');
        $this->assertTrue((bool) $board->use_scrap, '기존 게시판의 스크랩 사용은 켜져 있어야 합니다.');
    }

    /**
     * 운영자가 반응 유형을 모두 빼고 저장한 게시판(빈 배열)은 다시 실행해도 덮지 않는다.
     *
     * @scenario case=update_from_1_1_2_enables_reactions
     *
     * @effects backfill_keeps_operator_empty_selection
     *
     * @return void 반환값 없음
     */
    public function test_rerun_keeps_operator_empty_selection(): void
    {
        $this->runModuleUpgrade('1.1.2', '1.2.0');
        DB::table('boards')->where('id', $this->board->id)->update(['active_reaction_types' => '[]']);

        $this->runStep();

        $this->assertSame('[]', DB::table('boards')->where('id', $this->board->id)->value('active_reaction_types'));
        $this->assertSame(2, DB::table('board_reaction_types')->count(), '재실행해도 유형은 2건이어야 합니다.');
    }

    /**
     * 추천 유형이 꺼져 있으면 게시판에 채우지 않는다.
     *
     * @scenario case=update_from_1_1_2_enables_reactions
     *
     * @effects backfill_skips_inactive_default_type
     *
     * @return void 반환값 없음
     */
    public function test_backfill_skips_when_like_type_is_inactive(): void
    {
        DB::table('board_reaction_types')->insert([
            'code' => 'like',
            'name' => json_encode(['ko' => '추천', 'en' => 'Recommend'], JSON_UNESCAPED_UNICODE),
            'icon' => 'fas fa-thumbs-up',
            'display_order' => 1,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runStep();

        $this->assertNull(DB::table('boards')->where('id', $this->board->id)->value('active_reaction_types'));
    }

    /**
     * 옛 일본어 비추천 이름(「ひどい」)은 운영자가 고치지 않았을 때만 「よくない」로 바뀐다.
     *
     * @scenario case=update_from_1_1_2_enables_reactions
     *
     * @effects legacy_ja_dislike_name_renamed_unless_overridden
     *
     * @return void 반환값 없음
     */
    public function test_legacy_japanese_dislike_name_is_renamed_unless_operator_edited(): void
    {
        $this->insertDislike(['ko' => '비추천', 'en' => 'Not Recommend', 'ja' => 'ひどい'], null);

        $this->runStep();

        $name = json_decode(DB::table('board_reaction_types')->where('code', 'dislike')->value('name'), true);
        $this->assertSame(['ko' => '비추천', 'en' => 'Not Recommend', 'ja' => 'よくない'], $name);

        DB::table('board_reactions')->delete();
        DB::table('board_reaction_types')->delete();
        $this->insertDislike(['ko' => '비추천', 'en' => 'Not Recommend', 'ja' => 'ひどい'], ['name.ja']);

        $this->runStep();

        $name = json_decode(DB::table('board_reaction_types')->where('code', 'dislike')->value('name'), true);
        $this->assertSame('ひどい', $name['ja'], '운영자가 고친 일본어 이름은 보존되어야 합니다.');
    }

    /**
     * 새로 설치할 때 실행되는 시더 목록에 반응 유형 시더가 들어 있다.
     *
     * @scenario case=fresh_install_seeds_reaction_types
     *
     * @effects install_seeders_include_reaction_types
     *
     * @return void 반환값 없음
     */
    public function test_install_seeders_include_reaction_type_seeder(): void
    {
        $module = app(ModuleManager::class)->getModule('sirsoft-board');

        $this->assertNotNull($module, '게시판 모듈 인스턴스가 등록돼 있어야 합니다.');
        $this->assertContains(BoardReactionTypeSeeder::class, $module->getSeeders());
    }

    /**
     * ModuleManager 의 스텝 선택을 거쳐 업그레이드 스텝을 실행합니다.
     *
     * @param  string  $from  출발 버전
     * @param  string  $to  목표 버전
     * @return void 반환값 없음
     */
    private function runModuleUpgrade(string $from, string $to): void
    {
        $manager = app(ModuleManager::class);
        $module = $manager->getModule('sirsoft-board');
        $this->assertNotNull($module, '게시판 모듈 인스턴스가 등록돼 있어야 합니다.');

        $executed = [];
        $method = new \ReflectionMethod($manager, 'runUpgradeSteps');
        $method->invoke($manager, $module, $from, $to, false, function (string $version) use (&$executed): void {
            $executed[] = $version;
        });

        $this->assertContains('1.2.0', $executed, '1.1.2 출발이면 1.2.0 스텝이 선택돼야 합니다.');
        $this->assertNotContains('1.0.3', $executed, '1.1.2 출발이면 1.0.3 스텝은 선택되지 않아야 합니다.');
    }

    /**
     * 1.2.0 스텝을 직접 실행합니다(data/1.2.0/migrations 전체).
     *
     * @return void 반환값 없음
     */
    private function runStep(): void
    {
        (new Upgrade_1_2_0)->run(new UpgradeContext('1.1.2', '1.2.0', '1.2.0', 'extension-upgrade'));
    }

    /**
     * 비추천 유형 한 건을 넣습니다.
     *
     * @param  array<string, string>  $name  언어별 이름
     * @param  array<int, string>|null  $overrides  운영자가 고친 항목 목록 (없으면 null)
     * @return void 반환값 없음
     */
    private function insertDislike(array $name, ?array $overrides): void
    {
        DB::table('board_reaction_types')->insert([
            'code' => 'dislike',
            'name' => json_encode($name, JSON_UNESCAPED_UNICODE),
            'icon' => 'fas fa-thumbs-down',
            'display_order' => 2,
            'is_active' => true,
            'user_overrides' => $overrides === null ? null : json_encode($overrides),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
