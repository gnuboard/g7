<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

use Modules\Sirsoft\Board\Module;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * Module SEO declaration 회귀 테스트.
 *
 * 회귀: 게시물 상세 페이지의 og:image 가 출력되지 않아 페이스북·쓰레드 미리보기 카드가
 * 통째로 미표시 (Slack 은 텍스트 카드만 표시) — Module.seoOgDefaults('post') 가
 * 잘못된 키 `thumbnail_url` / `first_image_url` 을 참조했음. PostResource 의 실제 키는
 * `thumbnail`.
 */
class BoardModuleSeoTest extends ModuleTestCase
{
    private Module $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = app(\App\Extension\ModuleManager::class)->getModule('sirsoft-board')
            ?? new Module(base_path('modules/sirsoft-board'));
    }

    /**
     * 회귀: PostResource toArray() 의 'thumbnail' 키에서 og:image URL 추출.
     */
    public function test_post_seo_og_defaults_uses_thumbnail_key(): void
    {
        $context = [
            'post' => [
                'data' => [
                    'subject' => '회귀 테스트 게시글',
                    'thumbnail' => '/api/modules/sirsoft-board/boards/free/attachment/abc123/preview',
                ],
            ],
        ];

        $og = $this->module->seoOgDefaults('post', $context);

        $this->assertArrayHasKey('image', $og, 'thumbnail 키가 있으면 og:image 가 출력되어야 합니다');
        $this->assertStringContainsString('/api/modules/sirsoft-board/', $og['image']);
    }

    /**
     * 회귀: thumbnail 부재 시에도 throw 없이 image 키 생략.
     */
    public function test_post_seo_og_defaults_without_thumbnail(): void
    {
        $context = [
            'post' => ['data' => ['subject' => '이미지 없는 게시글']],
        ];

        $og = $this->module->seoOgDefaults('post', $context);

        $this->assertArrayNotHasKey('image', $og);
        $this->assertSame('article', $og['type']);
        $this->assertSame('이미지 없는 게시글', $og['image_alt']);
    }
}
