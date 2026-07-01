<?php

namespace Tests\Unit\Seo;

use App\Seo\Contracts\SeoRendererInterface;
use App\Seo\Editor\SeoBotPreviewService;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * SeoBotPreviewService 단위 테스트.
 *
 * 봇 미리보기는 검색엔진 탭·레이아웃·모듈/코어 SEO 설정이 **산출한 데이터만** 보여준다.
 * 종전 `extractSeoMarkup`(완성 HTML 을 태그/속성 휴리스틱으로 가지치기)은 폐기 — 대신 렌더 파이프
 * 라인을 `seoOnly=true` 로 호출해 SEO 산출물 전용 블레이드(seo-preview)로 조립한 HTML 을 그대로
 * 돌려준다. 본 단위 테스트는 서비스가 ① seoOnly=true 로 호출하는지 ② 결과를 가공 없이 통과시키는지
 * ③ null 분기를 검증한다. SEO 산출물만 담기는지는 seo-preview 블레이드 Feature 테스트가 SSoT.
 */
class SeoBotPreviewServiceTest extends TestCase
{
    /** renderer mock — 호출 인자를 캡처하고 주어진 HTML 을 반환한다. */
    private function recordingRenderer(?string $html): object
    {
        return new class($html) implements SeoRendererInterface
        {
            public ?bool $lastSeoOnly = null;

            public int $calls = 0;

            public function __construct(private readonly ?string $html) {}

            public function render(Request $request): ?string
            {
                return $this->html;
            }

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
                $this->calls++;
                $this->lastSeoOnly = $seoOnly;

                return $this->html;
            }
        };
    }

    private function render(SeoBotPreviewService $svc): ?string
    {
        return $svc->render([], [], '/x', 'ko', 'sirsoft-admin_basic', null, null, []);
    }

    public function test_seo_only_true_로_렌더_파이프라인을_호출한다(): void
    {
        // 봇 미리보기는 SEO 설정 산출물만 받아야 하므로 seoOnly=true 로 호출한다.
        $renderer = $this->recordingRenderer('<head><title>T</title></head>');
        $svc = new SeoBotPreviewService($renderer);

        $this->render($svc);

        $this->assertSame(1, $renderer->calls);
        $this->assertTrue($renderer->lastSeoOnly, '봇 미리보기는 seoOnly=true 로 호출');
    }

    public function test_렌더_결과를_가공_없이_그대로_통과시킨다(): void
    {
        // 종전 extractSeoMarkup 후처리 폐기 — 파이프라인이 돌려준 SEO 전용 HTML 을 그대로 반환.
        $html = '<head><title>봇 제목</title><meta property="og:title" content="봇"></head>';
        $renderer = $this->recordingRenderer($html);
        $svc = new SeoBotPreviewService($renderer);

        $this->assertSame($html, $this->render($svc));
    }

    public function test_seo_비활성_render_null_은_null_로_전달된다(): void
    {
        // seoOnly 경로에서도 meta.seo.enabled=false → renderFromResolved 가 null → null 통과.
        $renderer = $this->recordingRenderer(null);
        $svc = new SeoBotPreviewService($renderer);

        $this->assertNull($this->render($svc));
    }
}
