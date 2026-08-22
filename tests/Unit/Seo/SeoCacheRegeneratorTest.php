<?php

namespace Tests\Unit\Seo;

use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\Contracts\SeoRendererInterface;
use App\Seo\SeoCacheRegenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * SeoCacheRegenerator 테스트
 *
 * 단건 SEO 캐시 재생성 서비스의 동작을 검증합니다.
 * - 렌더링 성공 시 putWithLayout 호출
 * - 렌더링 실패(null) 시 캐시 저장 스킵
 * - 예외 발생 시 graceful 처리
 * - 다국어 로케일별 재생성
 */
class SeoCacheRegeneratorTest extends TestCase
{
    private SeoRendererInterface $rendererMock;

    private SeoCacheManagerInterface $cacheMock;

    private SeoCacheRegenerator $regenerator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rendererMock = Mockery::mock(SeoRendererInterface::class);
        $this->cacheMock = Mockery::mock(SeoCacheManagerInterface::class);

        $this->regenerator = new SeoCacheRegenerator(
            $this->rendererMock,
            $this->cacheMock,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─── 기본 재생성 ──────────────────────────────────────

    /**
     * 렌더링 성공 시 캐시에 저장되는지 확인
     */
    public function test_render_and_cache_stores_html_on_success(): void
    {
        config(['app.locale' => 'ko']);
        config(['app.supported_locales' => ['ko']]);

        $this->rendererMock->shouldReceive('render')
            ->once()
            ->with(Mockery::on(function (Request $request) {
                return $request->getPathInfo() === '/shop/products/1';
            }))
            ->andReturnUsing(function (Request $request) {
                $request->attributes->set('seo_layout_name', 'shop/show');

                return '<html>Product 1</html>';
            });

        $this->cacheMock->shouldReceive('putWithLayout')
            ->once()
            ->with('/shop/products/1', 'ko', '<html>Product 1</html>', 'shop/show');

        Log::shouldReceive('debug')->atLeast()->once();

        $result = $this->regenerator->renderAndCache('/shop/products/1');

        $this->assertTrue($result);
    }

    /**
     * 렌더링 결과가 null이면 캐시 저장을 건너뛰는지 확인
     */
    public function test_render_and_cache_skips_when_render_returns_null(): void
    {
        config(['app.locale' => 'ko']);
        config(['app.supported_locales' => ['ko']]);

        $this->rendererMock->shouldReceive('render')
            ->once()
            ->andReturn(null);

        $this->cacheMock->shouldNotReceive('putWithLayout');

        $result = $this->regenerator->renderAndCache('/nonexistent');

        $this->assertFalse($result);
    }

    // ─── 다국어 재생성 ──────────────────────────────────────

    /**
     * 지원 로케일별로 각각 렌더링 + 캐시 저장이 수행되는지 확인
     */
    public function test_render_and_cache_iterates_supported_locales(): void
    {
        config(['app.locale' => 'ko']);
        config(['app.supported_locales' => ['ko', 'en']]);

        $this->rendererMock->shouldReceive('render')
            ->twice()
            ->andReturnUsing(function (Request $request) {
                $locale = app()->getLocale();
                $request->attributes->set('seo_layout_name', 'shop/show');

                return "<html>Product ({$locale})</html>";
            });

        $this->cacheMock->shouldReceive('putWithLayout')
            ->once()
            ->with('/shop/products/1', 'ko', Mockery::type('string'), 'shop/show');

        $this->cacheMock->shouldReceive('putWithLayout')
            ->once()
            ->with('/shop/products/1', 'en', Mockery::type('string'), 'shop/show');

        Log::shouldReceive('debug')->atLeast()->once();

        $result = $this->regenerator->renderAndCache('/shop/products/1');

        $this->assertTrue($result);
    }

    // ─── 예외 처리 ──────────────────────────────────────

    /**
     * 렌더링 중 예외 발생 시 graceful하게 처리되는지 확인
     */
    public function test_render_and_cache_handles_exceptions_gracefully(): void
    {
        config(['app.locale' => 'ko']);
        config(['app.supported_locales' => ['ko']]);

        $this->rendererMock->shouldReceive('render')
            ->once()
            ->andThrow(new \RuntimeException('Renderer failed'));

        $this->cacheMock->shouldNotReceive('putWithLayout');

        Log::shouldReceive('warning')
            ->once()
            ->with('[SEO] Cache regeneration failed', Mockery::on(function ($context) {
                return $context['url'] === '/test'
                    && $context['error'] === 'Renderer failed';
            }));

        $result = $this->regenerator->renderAndCache('/test');

        $this->assertFalse($result);
    }

    /**
     * 일부 로케일만 실패해도 나머지는 정상 처리되는지 확인
     */
    public function test_render_and_cache_continues_on_partial_locale_failure(): void
    {
        config(['app.locale' => 'ko']);
        config(['app.supported_locales' => ['ko', 'en']]);

        $callCount = 0;
        $this->rendererMock->shouldReceive('render')
            ->twice()
            ->andReturnUsing(function (Request $request) use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new \RuntimeException('ko render failed');
                }
                $request->attributes->set('seo_layout_name', 'shop/show');

                return '<html>EN</html>';
            });

        $this->cacheMock->shouldReceive('putWithLayout')
            ->once()
            ->with('/shop/products/1', 'en', '<html>EN</html>', 'shop/show');

        Log::shouldReceive('warning')->once();
        Log::shouldReceive('debug')->atLeast()->once();

        $result = $this->regenerator->renderAndCache('/shop/products/1');

        $this->assertTrue($result);
    }

    /**
     * 로케일이 렌더링 후 원래 값으로 복원되는지 확인
     */
    public function test_render_and_cache_restores_locale(): void
    {
        config(['app.locale' => 'ko']);
        config(['app.supported_locales' => ['en']]);

        app()->setLocale('ko');

        $this->rendererMock->shouldReceive('render')
            ->once()
            ->andReturnUsing(function (Request $request) {
                $request->attributes->set('seo_layout_name', 'test');

                return '<html>EN</html>';
            });

        $this->cacheMock->shouldReceive('putWithLayout')->once();
        Log::shouldReceive('debug')->atLeast()->once();

        $this->regenerator->renderAndCache('/test');

        $this->assertEquals('ko', app()->getLocale());
    }
}
