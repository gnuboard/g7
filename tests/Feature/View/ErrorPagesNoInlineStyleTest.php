<?php

namespace Tests\Feature\View;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * [case:network-strict-style-csp-inline-style-blocked] 코어에 전용 뷰가 없던 오류 응답도 인라인 스타일 없이 그린다.
 *
 * 프레임워크는 오류 화면을 `errors::{코드}` → `errors::4xx`·`errors::5xx` 순으로 찾고, 앱에 없으면 프레임워크가
 * 들고 있는 뷰(402·419·429)나 대체 페이지(405 등)를 낸다. 둘 다 `<style>` 블록으로 모양을 실어, 운영자가
 * `style-src 'self'` 를 걸면 요청 한도 초과·세션 만료 같은 화면이 무스타일로 나왔다. 코어가 402·419·429 와
 * 묶음 뷰(4xx·5xx)를 직접 두어 다른 오류 화면과 같은 정적 CSS(`g7-standalone.css`)로 그린다.
 *
 * @scenario case=standalone_pages_no_inline_style
 *
 * @effects fallback_error_pages_use_core_views
 */
class ErrorPagesNoInlineStyleTest extends TestCase
{
    /** 시험용 오류 라우트 — 화면 폴백 라우트(`/{any?}`)가 잡지 않는 예약 프리픽스 아래에 둔다 */
    private const PROBE = '/plugins/__error-page-probe';

    /**
     * 시험용 오류 라우트를 등록합니다 — 운영 화면과 같이 디버그를 끈다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.debug', false);
        Route::middleware('web')->get(self::PROBE.'/{code}', fn (string $code) => abort((int) $code));
    }

    /**
     * 응답 HTML 이 코어 오류 화면(정적 CSS 1개, 인라인 스타일 0)인지 검사합니다.
     *
     * @param  string  $html  응답 HTML
     * @param  string  $label  실패 메시지에 붙일 이름
     * @param  int  $status  기대 상태 코드
     * @return void 반환값 없음
     */
    private function assertCoreErrorPage(string $html, string $label, int $status): void
    {
        $this->assertSame(0, preg_match('/<style\b/i', $html), "[{$label}] <style> 요소가 남아 있다");
        $this->assertSame(0, preg_match('/<[a-z][^>]*\sstyle\s*=/i', $html), "[{$label}] style 속성이 남아 있다");
        $this->assertSame(1, substr_count($html, 'data-g7-core-css="standalone"'), "[{$label}] 독립 화면 CSS 링크가 정확히 1개여야 한다");
        $this->assertMatchesRegularExpression('#assets/core/css/g7-standalone\.css\?v=\d+#', $html);
        $this->assertStringContainsString('<div class="error-code">'.$status.'</div>', $html, "[{$label}] 상태 코드가 화면에 없다");
        $this->assertStringContainsString('class="error-card"', $html);
        $this->assertStringContainsString(__('errors.back_home'), $html);
    }

    /**
     * 전용 뷰가 없던 상태 코드 목록 — 코드, 기대 제목 키.
     *
     * @return array<string, array{0: int, 1: string}> 상태 코드와 제목 키
     */
    public static function statusProvider(): array
    {
        return [
            '402 (프레임워크 뷰가 있던 코드)' => [402, 'errors.4xx.title'],
            '419 (프레임워크 뷰가 있던 코드)' => [419, 'errors.4xx.title'],
            '429 (프레임워크 뷰가 있던 코드)' => [429, 'errors.4xx.title'],
            '405 (대체 페이지가 나오던 코드)' => [405, 'errors.4xx.title'],
            '418 (대체 페이지가 나오던 코드)' => [418, 'errors.4xx.title'],
            '502 (대체 페이지가 나오던 코드)' => [502, 'errors.5xx.title'],
            '504 (대체 페이지가 나오던 코드)' => [504, 'errors.5xx.title'],
        ];
    }

    /**
     * 전용 뷰가 없던 상태 코드도 코어 오류 화면으로 나온다.
     *
     * @scenario case=standalone_pages_no_inline_style
     *
     * @effects fallback_error_pages_use_core_views
     *
     * @param  int  $status  상태 코드
     * @param  string  $titleKey  기대 제목 번역 키
     * @return void 반환값 없음
     */
    #[DataProvider('statusProvider')]
    public function test_전용_뷰가_없던_오류도_코어_화면으로_그린다(int $status, string $titleKey): void
    {
        $response = $this->get(self::PROBE.'/'.$status);

        $response->assertStatus($status);
        $html = (string) $response->getContent();
        $this->assertCoreErrorPage($html, (string) $status, $status);
        $this->assertStringContainsString(e(__($titleKey)), $html);
        $this->assertStringNotContainsString($titleKey, $html, '번역 키가 그대로 노출됐다');
    }

    /**
     * 허용하지 않는 메서드 요청(405)도 코어 오류 화면으로 나온다 — 임시 라우트가 아닌 실제 경로.
     *
     * @scenario case=standalone_pages_no_inline_style
     *
     * @effects fallback_error_pages_use_core_views
     *
     * @return void 반환값 없음
     */
    public function test_허용하지_않는_메서드_요청은_코어_405_화면이다(): void
    {
        $response = $this->delete(self::PROBE.'/404');

        $response->assertStatus(405);
        $this->assertCoreErrorPage((string) $response->getContent(), 'DELETE 405', 405);
    }

    /**
     * 영어 로케일에서도 묶음 뷰 문구가 번역돼 나온다.
     *
     * @scenario case=standalone_pages_no_inline_style
     *
     * @effects fallback_error_pages_use_core_views
     *
     * @return void 반환값 없음
     */
    public function test_묶음_뷰_문구는_로케일을_따른다(): void
    {
        foreach (['ko', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach (['4xx' => 429, '5xx' => 502] as $group => $status) {
                // 요청을 거치면 로케일 미들웨어가 다시 정한다 — 뷰를 직접 그려 문구만 본다
                $html = view("errors.{$group}", ['exception' => new HttpException($status)])->render();
                $this->assertStringContainsString('<div class="error-code">'.$status.'</div>', $html);

                $this->assertStringContainsString(e(trans("errors.{$group}.title", [], $locale)), $html, "[{$locale}] {$group} 제목");
                $this->assertStringContainsString(e(trans("errors.{$group}.message", [], $locale)), $html, "[{$locale}] {$group} 문구");
            }
        }

        $this->assertNotSame(trans('errors.4xx.title', [], 'ko'), trans('errors.4xx.title', [], 'en'));
    }
}
