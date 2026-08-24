<?php

namespace Modules\Sirsoft\Page\Tests\Feature\Upgrade;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;
use Modules\Sirsoft\Page\Upgrades\Upgrade_1_1_0;
use PHPUnit\Framework\Attributes\Test;

/**
 * 1.1.0 페이지 본문 썸네일 백필 업그레이드 스텝 테스트 (공개 이슈 #22 동종)
 *
 * @group page
 * @group upgrade
 */
class PageContentThumbnailBackfillTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.locale' => 'ko']);
    }

    protected function tearDown(): void
    {
        DB::table('pages')->where('slug', 'like', 'backfill-%')->delete();
        parent::tearDown();
    }

    /**
     * 모델 이벤트를 우회해 레거시 상태 페이지 행을 만듭니다.
     *
     * @param  array  $attributes  덮어쓸 속성
     * @return int 페이지 ID
     */
    private function createLegacyRow(array $attributes = []): int
    {
        return DB::table('pages')->insertGetId(array_merge([
            'slug' => 'backfill-'.uniqid(),
            'title' => json_encode(['ko' => '레거시 페이지'], JSON_UNESCAPED_UNICODE),
            'content' => json_encode(['ko' => '<p>본문</p>'], JSON_UNESCAPED_UNICODE),
            'content_mode' => 'html',
            'content_thumbnail_url' => null,
            'published' => 1,
            'published_at' => now(),
            'current_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function runBackfill(): void
    {
        $context = new UpgradeContext('1.0.3', '1.1.0', '1.1.0', 'extension-upgrade');

        (new Upgrade_1_1_0)->run($context);
    }

    /**
     * @scenario backfill=legacy_row
     *
     * @effects backfill_fills_legacy_pages
     */
    #[Test]
    public function legacy_rows_are_backfilled_and_gated(): void
    {
        $legacy = $this->createLegacyRow([
            'content' => json_encode([
                'ko' => '<img src="https://evil.example.org/skip.jpg"><img src="/storage/pages/legacy.jpg">',
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $textMode = $this->createLegacyRow([
            'content_mode' => 'text',
            'content' => json_encode(['ko' => '텍스트 <img src="/storage/pages/literal.jpg">'], JSON_UNESCAPED_UNICODE),
        ]);
        $filled = $this->createLegacyRow([
            'content' => json_encode(['ko' => '<img src="/storage/pages/other.jpg">'], JSON_UNESCAPED_UNICODE),
            'content_thumbnail_url' => '/storage/pages/kept.jpg',
        ]);

        $this->runBackfill();

        $this->assertSame('/storage/pages/legacy.jpg', DB::table('pages')->where('id', $legacy)->value('content_thumbnail_url'));
        $this->assertNull(DB::table('pages')->where('id', $textMode)->value('content_thumbnail_url'));
        $this->assertSame('/storage/pages/kept.jpg', DB::table('pages')->where('id', $filled)->value('content_thumbnail_url'));
    }

    /**
     * @scenario backfill=idempotent
     *
     * @effects backfill_idempotent_second_run
     */
    #[Test]
    public function second_run_is_idempotent(): void
    {
        $this->createLegacyRow([
            'content' => json_encode(['ko' => '<img src="/storage/pages/a.jpg">'], JSON_UNESCAPED_UNICODE),
        ]);
        $this->createLegacyRow([
            'content' => json_encode(['ko' => '<p>이미지 없음</p>'], JSON_UNESCAPED_UNICODE),
        ]);

        $this->runBackfill();
        $first = $this->snapshot();

        $this->runBackfill();
        $second = $this->snapshot();

        $this->assertSame($first, $second, '재실행 결과가 동일해야 합니다.');
    }

    /**
     * @return array<int, array{id: int, content_thumbnail_url: string|null}> 스냅샷
     */
    private function snapshot(): array
    {
        return DB::table('pages')
            ->where('slug', 'like', 'backfill-%')
            ->orderBy('id')
            ->get(['id', 'content_thumbnail_url'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'content_thumbnail_url' => $row->content_thumbnail_url])
            ->all();
    }
}
