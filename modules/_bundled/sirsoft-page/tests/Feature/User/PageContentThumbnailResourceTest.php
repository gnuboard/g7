<?php

namespace Modules\Sirsoft\Page\Tests\Feature\User;

// FeatureTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../FeatureTestCase.php';

use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 공개 페이지 응답의 content_thumbnail_url 방출 테스트 (공개 이슈 #22 동종)
 *
 * PublicPageResource 가 본문 첫 내부 이미지 캐시를 방출해 페이지 표시 레이아웃의
 * og:image 바인딩(page.data.content_thumbnail_url)이 값을 받는지 API 경계에서
 * 고정합니다.
 */
class PageContentThumbnailResourceTest extends FeatureTestCase
{
    protected function tearDown(): void
    {
        Page::where('slug', 'like', 'thumb-emit-%')->forceDelete();
        parent::tearDown();
    }

    /**
     * @scenario image_source=content_internal_only, locale_content=default_locale
     *
     * @effects public_resource_emits_thumbnail_key
     */
    #[Test]
    public function public_response_emits_content_thumbnail_url(): void
    {
        config(['app.locale' => 'ko']);

        Page::factory()->create([
            'slug' => 'thumb-emit-filled',
            'published' => true,
            'published_at' => now(),
            'content' => ['ko' => '<p>본문</p><img src="/storage/pages/og-image.jpg">'],
        ]);

        $response = $this->getJson('/api/modules/sirsoft-page/pages/thumb-emit-filled');

        $response->assertStatus(200);
        $this->assertSame('/storage/pages/og-image.jpg', $response->json('data.content_thumbnail_url'));
    }

    /**
     * @scenario image_source=none, locale_content=default_locale
     *
     * @effects public_resource_emits_thumbnail_key
     */
    #[Test]
    public function public_response_emits_null_when_no_image(): void
    {
        Page::factory()->create([
            'slug' => 'thumb-emit-empty',
            'published' => true,
            'published_at' => now(),
            'content' => ['ko' => '<p>이미지 없는 본문</p>'],
        ]);

        $response = $this->getJson('/api/modules/sirsoft-page/pages/thumb-emit-empty');

        $response->assertStatus(200);
        $this->assertArrayHasKey('content_thumbnail_url', $response->json('data'));
        $this->assertNull($response->json('data.content_thumbnail_url'));
    }
}
