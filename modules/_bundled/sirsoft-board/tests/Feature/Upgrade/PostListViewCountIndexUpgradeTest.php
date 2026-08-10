<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Upgrade;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Modules\Sirsoft\Board\Upgrades\Upgrade_1_0_3;

/**
 * 1.0.3 게시글 목록 조회수 정렬 색인 업그레이드 스텝 테스트
 *
 * 신규 설치는 마이그레이션(`2026_08_06_000002`)이 색인을 만들지만, 이미 설치를 마친
 * 사이트에도 확실히 도달하도록 업그레이드 데이터 스텝을 함께 둔다(같은 1.0.3 의
 * `04_AddTiebreakToPostListIndex` · `05_AddReportListSortIndex` 와 동일 규약).
 *
 * 검증 목적:
 * - 색인이 없는 상태(기설치본 모사)에서 스텝이 색인을 만든다
 * - 재실행해도 예외 없이 결과가 같다 (멱등)
 * - 인기글 조회가 쓰는 기존 색인을 건드리지 않는다
 *
 * @group board
 * @group upgrade
 */
class PostListViewCountIndexUpgradeTest extends BoardTestCase
{
    private const TABLE = 'board_posts';

    private const INDEX_NAME = 'idx_board_posts_list_views';

    /** 인기글 조회가 이름으로 의존하는 기존 색인 — 보존되어야 한다. */
    private const LEGACY_INDEX_NAME = 'idx_board_posts_board_view_count';

    protected function getTestBoardSlug(): string
    {
        return 'post-list-view-count-index';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '조회수 색인 게시판', 'en' => 'View Count Index Board'],
            'is_active' => true,
        ];
    }

    /**
     * 색인이 없는 기설치본에서 업그레이드 스텝이 색인을 만든다
     *
     * @scenario case=post_list_view_count_index_upgrade
     *
     * @effects view_count_list_index_created_on_existing_site
     */
    public function test_색인이_없으면_업그레이드_스텝이_생성한다(): void
    {
        $this->dropIndexIfExists();
        $this->assertFalse($this->hasIndex(self::INDEX_NAME), '사전 조건: 색인이 없어야 한다');

        $this->runUpgrade();

        $this->assertTrue(
            $this->hasIndex(self::INDEX_NAME),
            '업그레이드 스텝이 조회수 정렬 색인을 만들어야 한다'
        );
    }

    /**
     * 재실행해도 예외 없이 결과가 같다 (멱등)
     *
     * @scenario case=post_list_view_count_index_upgrade
     *
     * @effects view_count_list_index_upgrade_is_idempotent
     */
    public function test_이미_있으면_재실행해도_결과가_같다(): void
    {
        $this->dropIndexIfExists();
        $this->runUpgrade();
        $this->assertTrue($this->hasIndex(self::INDEX_NAME));

        // 색인이 이미 있는 상태에서 한 번 더 — 중복 생성 예외가 나면 실패한다.
        $this->runUpgrade();

        $this->assertTrue(
            $this->hasIndex(self::INDEX_NAME),
            '재실행 후에도 색인이 그대로 있어야 한다'
        );
    }

    /**
     * 인기글 조회가 쓰는 기존 색인을 건드리지 않는다
     *
     * @scenario case=post_list_view_count_index_upgrade
     *
     * @effects legacy_popular_posts_index_preserved
     */
    public function test_기존_인기글_색인을_보존한다(): void
    {
        $legacyBefore = $this->hasIndex(self::LEGACY_INDEX_NAME);

        $this->dropIndexIfExists();
        $this->runUpgrade();

        $this->assertSame(
            $legacyBefore,
            $this->hasIndex(self::LEGACY_INDEX_NAME),
            '조회수 목록 색인 추가가 인기글 색인의 존재 여부를 바꾸면 안 된다'
        );
    }

    /**
     * 1.0.3 업그레이드 스텝을 실행합니다.
     */
    private function runUpgrade(): void
    {
        $context = new UpgradeContext('1.0.2', '1.0.3', '1.0.3', 'extension-upgrade');

        (new Upgrade_1_0_3)->run($context);
    }

    /**
     * 대상 색인이 존재하면 제거합니다 (기설치본 모사).
     */
    private function dropIndexIfExists(): void
    {
        if (! $this->hasIndex(self::INDEX_NAME)) {
            return;
        }

        Schema::table(self::TABLE, function ($table) {
            $table->dropIndex(self::INDEX_NAME);
        });
    }

    /**
     * 색인 존재 여부를 조회합니다.
     *
     * @param  string  $name  색인 이름
     * @return bool 존재 여부
     */
    private function hasIndex(string $name): bool
    {
        if (! Schema::hasTable(self::TABLE)) {
            return false;
        }

        return in_array($name, array_column(Schema::getIndexes(self::TABLE), 'name'), true);
    }
}
