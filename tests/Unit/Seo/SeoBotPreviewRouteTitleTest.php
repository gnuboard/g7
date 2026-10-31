<?php

namespace Tests\Unit\Seo;

use App\Seo\Contracts\SeoRendererInterface;
use App\Seo\Editor\SeoBotPreviewService;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * 편집기 봇 미리보기가 라우트 제목과 레이아웃 전체 이름을 렌더 파이프라인에 넘기는지 테스트 (공개#154)
 *
 * 운영 봇 렌더는 다른 제목 단계가 모두 비면 라우트 `meta.title` 을 제목으로 쓰는데, 미리보기는 그 값을
 * 넘기지 않아 모듈 화면 미리보기의 제목이 운영 응답과 달랐다.
 */
class SeoBotPreviewRouteTitleTest extends TestCase
{
    /**
     * 호출 인자 전부를 기록하는 렌더러 대역을 만듭니다.
     *
     * @return object 마지막 호출 인자(`$args`)를 담는 렌더러
     */
    private function recordingRenderer(): object
    {
        return new class implements SeoRendererInterface
        {
            /** @var array<int, mixed> */
            public array $args = [];

            /**
             * 운영 렌더 진입점 — 이 테스트에서는 쓰지 않습니다.
             *
             * @param  Request  $request  요청
             * @return string|null 항상 null
             */
            public function render(Request $request): ?string
            {
                return null;
            }

            /**
             * 해석된 컨텍스트 렌더 — 받은 인자를 그대로 기록합니다.
             *
             * @param  array  $mergedLayout  레이아웃
             * @param  array  $routeParams  라우트 파라미터
             * @param  string  $url  요청 경로
             * @param  string  $locale  로케일
             * @param  string  $templateIdentifier  템플릿 식별자
             * @param  string  $layoutName  레이아웃 이름
             * @param  string|null  $moduleId  모듈 식별자
             * @param  string|null  $pluginId  플러그인 식별자
             * @param  array  $context  컨텍스트
             * @param  string|null  $defaultLocale  기본 로케일
             * @param  bool  $seoOnly  SEO 산출물만 렌더할지
             * @return string|null 고정 HTML
             */
            public function renderFromResolved(
                array $mergedLayout,
                array $routeParams,
                string $url,
                string $locale,
                string $templateIdentifier,
                string $layoutName,
                ?string $moduleId,
                ?string $pluginId,
                array $context,
                ?string $defaultLocale = null,
                bool $seoOnly = false,
            ): ?string {
                $this->args = func_get_args();

                return '<title>T</title>';
            }
        };
    }

    /**
     * 라우트 제목과 레이아웃 전체 이름을 렌더 파이프라인의 선택 인자로 그대로 넘깁니다.
     *
     * @scenario surface=preview_route_title
     *
     * @effects bot_preview_uses_route_meta_title_like_production
     *
     * @return void 반환값 없음
     */
    public function test_forwards_route_title_and_stored_layout_name(): void
    {
        $renderer = $this->recordingRenderer();
        $service = new SeoBotPreviewService($renderer);

        $service->render(
            ['layout_name' => 'user_memo_list', 'meta' => ['seo' => ['enabled' => true]]],
            [],
            '/memos',
            'ko',
            'sirsoft-basic',
            'gnuboard7-hello_module',
            null,
            [],
            '$t:gnuboard7-hello_module.user.memos.index.title',
            'gnuboard7-hello_module.user_memo_list',
        );

        $this->assertTrue($renderer->args[10], 'seoOnly=true');
        $this->assertSame('$t:gnuboard7-hello_module.user.memos.index.title', $renderer->args[11] ?? null, '라우트 제목');
        $this->assertSame('gnuboard7-hello_module.user_memo_list', $renderer->args[12] ?? null, '레이아웃 전체 이름');
    }

    /**
     * 라우트 정보가 없으면(베이스·모달 편집) 선택 인자를 null 로 넘겨 종전과 같게 렌더합니다.
     *
     * @scenario surface=preview_route_title
     *
     * @effects bot_preview_uses_route_meta_title_like_production
     *
     * @return void 반환값 없음
     */
    public function test_without_route_info_passes_nulls(): void
    {
        $renderer = $this->recordingRenderer();
        $service = new SeoBotPreviewService($renderer);

        $service->render(['meta' => ['seo' => ['enabled' => true]]], [], '/', 'ko', 'sirsoft-basic', null, null, []);

        $this->assertNull($renderer->args[11] ?? null);
        $this->assertNull($renderer->args[12] ?? null);
    }
}
