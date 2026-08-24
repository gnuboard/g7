<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Upgrade;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Modules\Sirsoft\Board\Upgrades\Upgrade_1_1_0;
use PHPUnit\Framework\Attributes\Test;

/**
 * 1.1.0 본문 썸네일 백필 업그레이드 스텝 테스트 (공개 이슈 #22)
 *
 * 검증 목적:
 * - 1.1.0 이전에 작성된 게시글(캐시 null)이 본문 첫 내부 이미지로 백필된다
 * - 이미 값이 있는 행은 건드리지 않는다
 * - 외부 이미지만 있는 행은 null 유지
 * - 재실행해도 결과가 동일하다 (멱등)
 *
 * @group board
 * @group upgrade
 */
class ContentThumbnailBackfillTest extends BoardTestCase
{
    protected function getTestBoardSlug(): string
    {
        return 'content-thumbnail-backfill';
    }

    /**
     * 1.1.0 백필 스텝을 실행합니다.
     */
    private function runBackfill(): void
    {
        $context = new UpgradeContext('1.0.5', '1.1.0', '1.1.0', 'extension-upgrade');

        (new Upgrade_1_1_0)->run($context);
    }

    /**
     * @scenario backfill=legacy_row
     *
     * @effects backfill_fills_legacy_rows
     */
    #[Test]
    public function legacy_rows_are_backfilled_with_first_internal_image(): void
    {
        // createTestPost 는 DB 직접 insert — 모델 saving 이벤트를 타지 않아
        // 업그레이드 전 작성 글(캐시 null)과 동일한 상태다
        $legacy = $this->createTestPost([
            'content_mode' => 'html',
            'content' => '<p>글</p><img src="https://evil.example.org/skip.jpg"><img src="/storage/uploads/legacy.jpg">',
        ]);

        $this->runBackfill();

        $this->assertSame(
            '/storage/uploads/legacy.jpg',
            DB::table('board_posts')->where('id', $legacy)->value('content_thumbnail_url'),
            '첫 외부 후보는 건너뛰고 첫 내부 이미지가 백필되어야 합니다.'
        );
    }

    /**
     * @scenario backfill=already_filled
     *
     * @effects backfill_fills_legacy_rows
     */
    #[Test]
    public function already_filled_rows_are_untouched(): void
    {
        $filled = $this->createTestPost([
            'content' => '<img src="/storage/uploads/other.jpg">',
            'content_thumbnail_url' => '/storage/uploads/kept.jpg',
        ]);

        $this->runBackfill();

        $this->assertSame(
            '/storage/uploads/kept.jpg',
            DB::table('board_posts')->where('id', $filled)->value('content_thumbnail_url'),
            '이미 값이 있는 행은 백필 대상이 아니어야 합니다.'
        );
    }

    /**
     * @scenario backfill=external_only
     *
     * @effects backfill_keeps_external_only_null
     */
    #[Test]
    public function external_only_rows_stay_null(): void
    {
        $external = $this->createTestPost([
            'content_mode' => 'html',
            'content' => '<img src="https://evil.example.org/x.jpg"><img src="//cdn.example.org/y.jpg">',
        ]);
        $noImage = $this->createTestPost(['content_mode' => 'html', 'content' => '<p>이미지 없음</p>']);

        $this->runBackfill();

        $this->assertNull(DB::table('board_posts')->where('id', $external)->value('content_thumbnail_url'));
        $this->assertNull(DB::table('board_posts')->where('id', $noImage)->value('content_thumbnail_url'));
    }

    /**
     * text 모드 레거시 행은 리터럴 img 마크업이 있어도 백필되지 않아야 합니다.
     *
     * text 모드 본문은 이스케이프 렌더라 상세에 이미지가 표시되지 않는다 —
     * 목록 썸네일만 뜨면 상세와 어긋난다 (content_mode 기본값이 text 라 모집단이 크다).
     *
     * @scenario backfill=text_mode
     *
     * @effects text_mode_content_never_caches
     */
    #[Test]
    public function text_mode_rows_are_not_backfilled(): void
    {
        $textMode = $this->createTestPost([
            'content_mode' => 'text',
            'content' => '텍스트 본문의 <img src="/storage/uploads/literal.jpg"> 마크업',
        ]);

        $this->runBackfill();

        $this->assertNull(
            DB::table('board_posts')->where('id', $textMode)->value('content_thumbnail_url'),
            'text 모드 글은 백필 대상이 아니어야 합니다.'
        );
    }

    /**
     * @scenario backfill=idempotent
     *
     * @effects backfill_idempotent_second_run
     */
    #[Test]
    public function second_run_is_idempotent(): void
    {
        $this->createTestPost(['content_mode' => 'html', 'content' => '<img src="/storage/uploads/a.jpg">']);
        $this->createTestPost(['content_mode' => 'html', 'content' => '<img src="https://evil.example.org/b.jpg">']);
        $this->createTestPost(['content_mode' => 'html', 'content' => '<p>이미지 없음</p>']);

        $this->runBackfill();
        $first = $this->snapshot();

        $this->runBackfill();
        $second = $this->snapshot();

        $this->assertSame($first, $second, '재실행 결과가 동일해야 합니다.');
    }

    /**
     * 이 게시판 게시글의 (id, content_thumbnail_url) 스냅샷을 반환합니다.
     *
     * @return array<int, array{id: int, content_thumbnail_url: string|null}> 스냅샷
     */
    private function snapshot(): array
    {
        return DB::table('board_posts')
            ->where('board_id', $this->board->id)
            ->orderBy('id')
            ->get(['id', 'content_thumbnail_url'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'content_thumbnail_url' => $row->content_thumbnail_url])
            ->all();
    }
}
