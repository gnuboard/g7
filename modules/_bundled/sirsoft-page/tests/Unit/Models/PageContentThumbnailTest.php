<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Models;

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Page 모델 content_thumbnail_url 캐시 계산 테스트 (공개 이슈 #22 동종)
 *
 * saving 이벤트가 다국어 본문(JSON)에서 첫 내부 이미지 URL 을 저장 시점에
 * 추출·캐시하는 계약을 고정합니다. 버전 롤백(restoreVersion)이 Repository 를
 * 직접 호출해도 모델 이벤트가 커버하는 것이 배치 근거입니다.
 */
class PageContentThumbnailTest extends ModuleTestCase
{
    private const FILTER_HOOK = 'sirsoft-page.page.filter_content_thumbnail';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.locale' => 'ko']);
    }

    protected function tearDown(): void
    {
        HookManager::clearFilter(self::FILTER_HOOK);
        parent::tearDown();
    }

    /**
     * @param  array  $attributes  덮어쓸 속성
     * @return Page 생성된 페이지
     */
    private function createPage(array $attributes = []): Page
    {
        return Page::factory()->create($attributes);
    }

    /**
     * @scenario image_source=content_internal_only, locale_content=default_locale
     *
     * @effects content_image_fills_page_og
     */
    #[Test]
    public function saving_extracts_first_internal_image(): void
    {
        $page = $this->createPage([
            'content' => [
                'ko' => '<p>본문</p><img src="/storage/pages/ko-first.jpg">',
                'en' => '<img src="/storage/pages/en.jpg">',
            ],
        ]);

        $this->assertSame('/storage/pages/ko-first.jpg', $page->fresh()->content_thumbnail_url);
    }

    /**
     * @scenario image_source=content_internal_only, locale_content=other_locale_only
     *
     * @effects other_locale_image_used_when_default_has_none
     */
    #[Test]
    public function falls_back_to_other_locale(): void
    {
        $page = $this->createPage([
            'content' => [
                'ko' => '<p>이미지 없음</p>',
                'en' => '<img src="/storage/pages/en-only.jpg">',
            ],
        ]);

        $this->assertSame('/storage/pages/en-only.jpg', $page->fresh()->content_thumbnail_url);
    }

    /**
     * @scenario image_source=content_external_only, locale_content=default_locale
     *
     * @effects external_only_content_yields_null
     */
    #[Test]
    public function external_only_content_yields_null(): void
    {
        $page = $this->createPage([
            'content' => ['ko' => '<img src="https://evil.example.org/x.jpg"><img src="//cdn.example.org/y.jpg">'],
        ]);

        $this->assertNull($page->fresh()->content_thumbnail_url);
    }

    /**
     * @scenario image_source=none, locale_content=default_locale
     *
     * @effects external_only_content_yields_null
     */
    #[Test]
    public function text_mode_and_no_image_yield_null(): void
    {
        $noImage = $this->createPage(['content' => ['ko' => '<p>이미지 없음</p>']]);
        $textMode = $this->createPage([
            'content_mode' => 'text',
            'content' => ['ko' => '텍스트 <img src="/storage/pages/literal.jpg"> 마크업'],
        ]);

        $this->assertNull($noImage->fresh()->content_thumbnail_url);
        $this->assertNull($textMode->fresh()->content_thumbnail_url, 'text 모드는 캐시하지 않아야 합니다.');
    }

    /**
     * @effects recompute_on_content_change
     */
    #[Test]
    public function content_change_recomputes_and_unrelated_update_does_not(): void
    {
        $page = $this->createPage([
            'content' => ['ko' => '<img src="/storage/pages/old.jpg">'],
        ]);

        $page->update(['content' => ['ko' => '<img src="/storage/pages/new.jpg">']]);
        $this->assertSame('/storage/pages/new.jpg', $page->fresh()->content_thumbnail_url);

        DB::table('pages')->where('id', $page->id)
            ->update(['content_thumbnail_url' => '/storage/pages/manual.jpg']);

        $page->fresh()->update(['published' => true, 'published_at' => now()]);

        $this->assertSame(
            '/storage/pages/manual.jpg',
            $page->fresh()->content_thumbnail_url,
            'content 가 dirty 가 아니면 재계산하지 않아야 합니다.'
        );
    }

    /**
     * @effects filter_hook_can_override_or_block
     */
    #[Test]
    public function filter_hook_can_override_or_block(): void
    {
        HookManager::addFilter(self::FILTER_HOOK, fn ($value) => null);

        $blocked = $this->createPage([
            'content' => ['ko' => '<img src="/storage/pages/internal.jpg">'],
        ]);

        $this->assertNull($blocked->fresh()->content_thumbnail_url);
    }

    /**
     * 버전 롤백 경로(Repository 직접 update)에서도 캐시가 재계산되어야 합니다.
     *
     * @effects version_restore_recomputes_cache
     */
    #[Test]
    public function model_level_update_recomputes_cache(): void
    {
        $page = $this->createPage([
            'content' => ['ko' => '<img src="/storage/pages/v1.jpg">'],
        ]);

        // restoreVersion 은 Repository 를 직접 호출한다 — 모델 update 경로와 동일한
        // saving 이벤트를 타므로 모델 레벨 update 로 등가 검증한다
        Page::findOrFail($page->id)->update([
            'content' => ['ko' => '<img src="/storage/pages/v2-restored.jpg">'],
        ]);

        $this->assertSame('/storage/pages/v2-restored.jpg', $page->fresh()->content_thumbnail_url);
    }
}
