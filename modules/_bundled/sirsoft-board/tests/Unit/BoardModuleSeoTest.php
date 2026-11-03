<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

use App\Extension\ModuleManager;
use App\Support\AssetUrl;
use Illuminate\Support\Facades\URL;
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

    /**
     * 설치된 게시판 모듈 인스턴스를 꺼내고, 없으면 모듈 경로로 직접 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->module = app(ModuleManager::class)->getModule('sirsoft-board')
            ?? new Module(base_path('modules/sirsoft-board'));
    }

    /**
     * 회귀: PostResource toArray() 의 'thumbnail' 키에서 og:image URL 추출.
     *
     * @effects og_defaults_read_image_from_thumbnail_key
     *
     * @return void 반환값 없음
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
     *
     * @effects og_defaults_omit_image_without_thumbnail
     *
     * @return void 반환값 없음
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
     * 회귀: 하위 폴더 마운트에서 og:image · schema.image 에 접두사가 두 번 붙지 않는다.
     *
     * `Attachment::previewUrlForSlug()` 가 `AssetUrl::basePrefix()` 를 거치므로 그 값은
     * 이미 마운트 접두사를 품는다(`/g7/api/…`). `PostResource` 의 `thumbnail` 이 바로 그
     * 값이고, 그 값에 `url()` 을 쓰면 요청 루트(접두사 포함)가 앞에 다시 붙어
     * `https://host/g7/g7/api/…` 가 된다 — 크롤러만 받는 값이라 화면에는 아무 표시가
     * 없고 미리보기 이미지만 404 다. 오리진만 붙이는 `AssetUrl::absolute()` 가 SSoT 이며,
     * 그 독블록이 이 실패를 이름으로 적고 있다.
     *
     * 형제 모듈(이커머스)은 같은 결함을 이미 닫았는데 이 모듈만 남아 있었고, 그 사실을
     * 알려 줄 검사가 없었다 — 이 테스트가 그 축을 소유한다.
     *
     * @scenario mount=subfolder, surface=seo_bot
     *
     * @effects seo_image_carries_the_mount_prefix_exactly_once
     *
     * @return void 반환값 없음
     */
    public function test_seo_image_does_not_double_apply_the_mount_prefix(): void
    {
        // 마운트를 **실제로** 재현한다. `AssetUrl::forceBasePrefix()` 만으로는 부족하다 —
        // 이중 부착을 만드는 것은 `url()` 이고 그 헬퍼는 요청 루트를 보기 때문에, 루트를
        // 함께 옮기지 않으면 이 결함은 재현되지 않고 검사가 조용히 초록이 된다.
        AssetUrl::forceBasePrefix('/g7');
        URL::forceRootUrl('http://localhost/g7');

        try {
            $context = [
                'post' => [
                    'data' => [
                        'subject' => '접두사 회귀 게시글',
                        'thumbnail' => '/g7/api/modules/sirsoft-board/boards/free/attachment/abc123/preview',
                    ],
                ],
            ];

            $og = $this->module->seoOgDefaults('post', $context);
            $this->assertStringNotContainsString('/g7/g7/', $og['image'], 'og:image 에 마운트 접두사가 두 번 붙었다');
            $this->assertStringEndsWith(
                '/g7/api/modules/sirsoft-board/boards/free/attachment/abc123/preview',
                $og['image']
            );

            // `schema.image` 축은 og 축과 **같은 강도**로 잰다.
            //
            // 종전에는 이 자리에 이중접두 단언 하나뿐이었다. 그런데 이 결함의 반대편
            // 실패(절대화 자체를 지움)는 `/g7/g7/` 를 만들지 않으므로, 그 상태에서도
            // 이 테스트가 통과했다 — `AssetUrl::absolute()` 를 항등으로 되돌려도
            // 6/6 초록이었고 저장소 어디에도 그 축을 재는 단언이 없었다
            // (10차 G3 라운드 X-3).
            $schema = $this->module->seoStructuredData('post', $context);
            $this->assertStringNotContainsString('/g7/g7/', $schema['image'], 'schema.image 에 마운트 접두사가 두 번 붙었다');
            $this->assertStringStartsWith('http', $schema['image'], 'schema.image 는 절대 URL 이어야 크롤러가 인식한다');
            $this->assertStringEndsWith(
                '/g7/api/modules/sirsoft-board/boards/free/attachment/abc123/preview',
                $schema['image']
            );
        } finally {
            AssetUrl::forceBasePrefix(null);
            URL::forceRootUrl(null);
        }
    }

    /**
     * 접두사가 없는 표준 배치에서는 종전과 동일하다 — 위 수정이 그 배치를 건드리지 않는다.
     *
     * @effects seo_image_is_absolute_without_mount_prefix
     *
     * @return void 반환값 없음
     */
    public function test_seo_image_is_unchanged_without_a_mount_prefix(): void
    {
        $context = [
            'post' => [
                'data' => [
                    'subject' => '표준 배치 게시글',
                    'thumbnail' => '/api/modules/sirsoft-board/boards/free/attachment/abc123/preview',
                ],
            ],
        ];

        $og = $this->module->seoOgDefaults('post', $context);
        $this->assertStringStartsWith('http', $og['image'], 'og:image 는 절대 URL 이어야 크롤러가 인식한다');
        $this->assertStringEndsWith('/api/modules/sirsoft-board/boards/free/attachment/abc123/preview', $og['image']);

        // 무접두사 배치에서도 두 축을 나란히 잰다 — 종전에는 og 축만 있어서
        // schema 축의 표준 배치 동작이 어느 검사에도 걸리지 않았다.
        $schema = $this->module->seoStructuredData('post', $context);
        $this->assertStringStartsWith('http', $schema['image'], 'schema.image 는 절대 URL 이어야 크롤러가 인식한다');
        $this->assertStringEndsWith('/api/modules/sirsoft-board/boards/free/attachment/abc123/preview', $schema['image']);
    }

    /**
     * seoOgDefaultMeta / seoStructuredDataMeta 가 게시글 데이터 경로(연결 칩) + 라벨을 선언한다.
     *
     * @effects module_declares_seo_default_meta_data_path_and_label_for_og_and_structured
     *
     * @return void 반환값 없음
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
     *
     * @effects meta_method_keys_are_subset_of_operational_declaration_keys
     *
     * @return void 반환값 없음
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
