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

    /**
     * seoOgDefaultMeta / seoStructuredDataMeta 가 게시글 데이터 경로(연결 칩) + 라벨을 선언한다.
     */
    public function test_post_seo_meta_declares_data_path_and_label(): void
    {
        $ogMeta = $this->module->seoOgDefaultMeta('post');
        $this->assertSame('{{post.data.thumbnail}}', $ogMeta['image']['expr']);
        $this->assertSame('{{post.data.subject}}', $ogMeta['image_alt']['expr']);
        // label 은 번역 키(언어팩 대응) — __() 로 해석.
        $this->assertSame('sirsoft-board::seo.auto_value.post_title', $ogMeta['image_alt']['label']);
        $this->assertSame('게시글 제목', __($ogMeta['image_alt']['label'], [], 'ko'));
        $this->assertSame('Post title', __($ogMeta['image_alt']['label'], [], 'en'));

        $sdMeta = $this->module->seoStructuredDataMeta('post');
        $this->assertSame('{{post.data.subject}}', $sdMeta['headline']['expr']);
        $this->assertSame('{{post.data.thumbnail}}', $sdMeta['image']['expr']);

        // 도메인 외 page_type 은 빈 배열(평문 폴백).
        $this->assertSame([], $this->module->seoOgDefaultMeta('boards'));
    }

    /**
     * 정합성: 메타가 선언한 키는 운영 declaration 산출 키의 부분집합이어야 한다(키 드리프트 차단).
     */
    public function test_post_meta_keys_are_subset_of_declaration_keys(): void
    {
        $context = [
            'post' => [
                'data' => [
                    'subject' => '키정합 게시글',
                    'thumbnail' => '/api/modules/sirsoft-board/x/preview',
                    'summary' => '요약',
                ],
            ],
        ];

        $ogKeys = array_keys($this->module->seoOgDefaults('post', $context));
        foreach (array_keys($this->module->seoOgDefaultMeta('post')) as $metaKey) {
            $this->assertContains($metaKey, $ogKeys, "og 메타 키 '{$metaKey}' 는 declaration 키여야 합니다");
        }

        $structuredKeys = array_keys($this->module->seoStructuredData('post', $context));
        foreach (array_keys($this->module->seoStructuredDataMeta('post')) as $metaKey) {
            // 점 경로 첫 세그먼트가 declaration 키에 존재(중첩은 평탄 비교 생략 — 게시판은 단일 레벨).
            $top = explode('.', $metaKey)[0];
            $this->assertContains($top, $structuredKeys, "structured 메타 키 '{$metaKey}' 는 declaration 키여야 합니다");
        }
    }
}
