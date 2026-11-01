<?php

namespace Tests\Feature\Http;

use App\Http\Middleware\ContentSecurityPolicy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * 코어 CSP 헤더 미들웨어 (#158)
 *
 * web 그룹의 HTML 응답에만, 모드에 맞는 헤더 이름으로 붙는다. 개발 대시보드(`/dev`)·봇 SEO 응답·
 * 이미 CSP 를 단 응답·HTML 이 아닌 응답에는 붙이지 않는다. `.env` 탈출구가 저장값보다 우선한다.
 *
 * 제외 조건은 미들웨어를 직접 불러 응답 모양별로 잰다 — 시험 전용 라우트는 SPA catch-all 이 먼저
 * 가로채 HTML 셸이 되므로 응답 모양을 고정할 수 없다. web 그룹 등록 자체는 실제 셸 경로(`/login`)와
 * api 경로로 잰다.
 */
class ContentSecurityPolicyMiddlewareTest extends TestCase
{
    /**
     * 모드를 끔으로 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core.csp.mode_override' => null,
            'g7_settings.core.security.csp_mode' => 'off',
            'g7_settings.core.security.csp_extra_script_hosts' => [],
        ]);
    }

    /**
     * 미들웨어를 직접 통과시킵니다.
     *
     * @param  string  $path  요청 경로
     * @param  Response  $response  다음 단계가 돌려줄 응답
     * @param  bool  $bot  봇 SEO 응답 표식 여부
     * @return Response 미들웨어를 거친 응답
     */
    private function through(string $path, Response $response, bool $bot = false): Response
    {
        $request = Request::create($path, 'GET');

        return (new ContentSecurityPolicy)->handle($request, function (Request $request) use ($response, $bot) {
            if ($bot) {
                $request->attributes->set('g7.seo_bot_response', true);
            }

            return $response;
        });
    }

    /**
     * HTML 응답 하나를 만듭니다.
     *
     * @return Response HTML 응답
     */
    private function html(): Response
    {
        return response('<!doctype html><title>t</title>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * 모드 3종의 헤더 이름 — 끔은 헤더 없음, 점검만은 Report-Only, 강제는 Content-Security-Policy.
     *
     * @scenario case=mode_off
     *
     * @effects csp_header_name_follows_mode
     *
     * @return void 반환값 없음
     */
    public function test_모드별_헤더_이름(): void
    {
        $off = $this->through('/', $this->html());
        $this->assertFalse($off->headers->has('Content-Security-Policy'));
        $this->assertFalse($off->headers->has('Content-Security-Policy-Report-Only'));

        config(['g7_settings.core.security.csp_mode' => 'report_only']);
        $report = $this->through('/', $this->html());
        $this->assertFalse($report->headers->has('Content-Security-Policy'));
        $this->assertStringStartsWith("script-src 'self'", (string) $report->headers->get('Content-Security-Policy-Report-Only'));

        config(['g7_settings.core.security.csp_mode' => 'enforce']);
        $enforce = $this->through('/', $this->html());
        $this->assertFalse($enforce->headers->has('Content-Security-Policy-Report-Only'));
        $this->assertStringContainsString("worker-src 'self' blob:", (string) $enforce->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('report-uri /api/csp-report', (string) $enforce->headers->get('Content-Security-Policy'));
    }

    /**
     * JSON 응답·`/dev`·봇 SEO 응답·이미 CSP 를 단 응답에는 붙이지 않는다.
     *
     * @scenario case=mode_enforce
     *
     * @effects csp_header_skipped_for_excluded_responses
     *
     * @return void 반환값 없음
     */
    public function test_제외_응답에는_붙이지_않는다(): void
    {
        config(['g7_settings.core.security.csp_mode' => 'enforce']);

        $this->assertFalse($this->through('/x', response()->json(['ok' => true]))->headers->has('Content-Security-Policy'));
        $this->assertFalse($this->through('/dev', $this->html())->headers->has('Content-Security-Policy'));
        $this->assertFalse($this->through('/dev/layouts', $this->html())->headers->has('Content-Security-Policy'));
        $this->assertFalse($this->through('/board/free', $this->html(), bot: true)->headers->has('Content-Security-Policy'));

        $own = $this->through('/x', response('<svg/>', 200, ['Content-Type' => 'text/html', 'Content-Security-Policy' => "default-src 'none'"]));
        $this->assertSame("default-src 'none'", $own->headers->get('Content-Security-Policy'));
        $this->assertFalse($own->headers->has('Content-Security-Policy-Report-Only'));

        // `/developer` 처럼 dev 로 시작할 뿐인 경로는 제외 대상이 아니다
        $this->assertTrue($this->through('/developer', $this->html())->headers->has('Content-Security-Policy'));
    }

    /**
     * `.env G7_CSP_MODE`(config `core.csp.mode_override`)가 화면 저장값보다 우선한다.
     *
     * @scenario case=mode_enforce
     *
     * @effects csp_mode_override_precedence
     *
     * @return void 반환값 없음
     */
    public function test_탈출구가_저장값보다_우선한다(): void
    {
        config(['g7_settings.core.security.csp_mode' => 'enforce', 'core.csp.mode_override' => 'off']);
        $this->assertFalse($this->through('/', $this->html())->headers->has('Content-Security-Policy'));

        config(['g7_settings.core.security.csp_mode' => 'off', 'core.csp.mode_override' => 'report_only']);
        $this->assertTrue($this->through('/', $this->html())->headers->has('Content-Security-Policy-Report-Only'));
    }

    /**
     * web 그룹에 등록돼 실제 셸 응답에 붙고, api 그룹 JSON 에는 붙지 않는다.
     *
     * @scenario case=mode_enforce
     *
     * @effects csp_header_name_follows_mode
     *
     * @return void 반환값 없음
     */
    public function test_web_그룹_셸_응답에_붙는다(): void
    {
        config(['g7_settings.core.security.csp_mode' => 'enforce']);

        $shell = $this->get('/login');
        $this->assertStringContainsString('text/html', (string) $shell->headers->get('Content-Type'));
        $this->assertStringStartsWith("script-src 'self'", (string) $shell->headers->get('Content-Security-Policy'));

        $this->postJson('/api/csp-report', [])->assertHeaderMissing('Content-Security-Policy');
    }
}
