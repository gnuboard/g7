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
 * 스타일 정책(7.1.6)은 모드를 따로 가진다 — 스크립트와 모드가 같으면 한 헤더에 이어 붙고, 다르면
 * 강제·점검만 헤더가 하나씩 나간다. 스타일 탈출구(`core.csp.style_mode_override`)는 스크립트 탈출구와 독립이다.
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
            'core.csp.style_mode_override' => null,
            'g7_settings.core.security.csp_style_mode' => 'off',
            'g7_settings.core.security.csp_style_level' => 'basic',
            'g7_settings.core.security.csp_extra_style_hosts' => [],
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

    /**
     * 스크립트는 끄고 스타일만 켜면 스타일 지시어만 실린 헤더 하나가 스타일 모드의 이름으로 나간다.
     *
     * @scenario case=style_header_combination
     *
     * @effects csp_headers_combined_by_mode
     *
     * @return void 반환값 없음
     */
    public function test_스타일만_켜면_스타일_헤더만(): void
    {
        config(['g7_settings.core.security.csp_style_mode' => 'report_only']);
        $report = $this->through('/', $this->html());
        $this->assertFalse($report->headers->has('Content-Security-Policy'));
        $this->assertSame(
            "style-src-elem 'self'; report-uri /api/csp-report",
            $report->headers->get('Content-Security-Policy-Report-Only')
        );

        config([
            'g7_settings.core.security.csp_style_mode' => 'enforce',
            'g7_settings.core.security.csp_style_level' => 'strict',
            'g7_settings.core.security.csp_extra_style_hosts' => ['fonts.example.com'],
        ]);
        $enforce = $this->through('/', $this->html());
        $this->assertFalse($enforce->headers->has('Content-Security-Policy-Report-Only'));
        $this->assertSame(
            "style-src 'self' fonts.example.com; style-src-attr 'none'; report-uri /api/csp-report",
            $enforce->headers->get('Content-Security-Policy')
        );
        $this->assertStringNotContainsString('script-src', (string) $enforce->headers->get('Content-Security-Policy'));
    }

    /**
     * 두 모드가 다르면 헤더 두 개 — 각각 report-uri 로 끝나고, 스크립트 헤더에는 스타일 지시어가 없다.
     *
     * @scenario case=style_header_combination
     *
     * @effects csp_headers_combined_by_mode
     *
     * @return void 반환값 없음
     */
    public function test_모드가_다르면_헤더_두_개(): void
    {
        config([
            'g7_settings.core.security.csp_mode' => 'enforce',
            'g7_settings.core.security.csp_style_mode' => 'report_only',
            'g7_settings.core.security.csp_style_level' => 'strict',
        ]);

        $response = $this->through('/', $this->html());
        $script = (string) $response->headers->get('Content-Security-Policy');
        $style = (string) $response->headers->get('Content-Security-Policy-Report-Only');

        $this->assertSame(
            "script-src 'self'; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; report-uri /api/csp-report",
            $script
        );
        $this->assertStringNotContainsString('style', $script);
        $this->assertSame("style-src 'self'; style-src-attr 'none'; report-uri /api/csp-report", $style);
        $this->assertStringNotContainsString('script-src', $style);

        // 반대 조합 — 스크립트가 점검만, 스타일이 강제
        config([
            'g7_settings.core.security.csp_mode' => 'report_only',
            'g7_settings.core.security.csp_style_mode' => 'enforce',
        ]);

        $swapped = $this->through('/', $this->html());
        $this->assertSame($script, $swapped->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertSame($style, $swapped->headers->get('Content-Security-Policy'));
    }

    /**
     * 두 모드가 같으면 헤더 하나 — 스크립트 지시어 → 스타일 지시어 → report-uri 순서.
     *
     * @scenario case=style_header_combination
     *
     * @effects csp_headers_combined_by_mode
     *
     * @return void 반환값 없음
     */
    public function test_같은_모드면_한_헤더(): void
    {
        config([
            'g7_settings.core.security.csp_mode' => 'enforce',
            'g7_settings.core.security.csp_style_mode' => 'enforce',
        ]);

        $response = $this->through('/', $this->html());

        $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
        $this->assertSame(
            "script-src 'self'; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; style-src-elem 'self'; report-uri /api/csp-report",
            $response->headers->get('Content-Security-Policy')
        );
        $this->assertCount(1, $response->headers->all('content-security-policy'));
    }

    /**
     * `.env G7_CSP_STYLE_MODE`(config `core.csp.style_mode_override`)가 스타일 저장값보다 우선하고,
     * 스크립트 탈출구와 서로 영향을 주지 않는다.
     *
     * @scenario case=style_mode_override
     *
     * @effects csp_style_mode_override_independent
     *
     * @return void 반환값 없음
     */
    public function test_스타일_탈출구가_저장값보다_우선한다(): void
    {
        config([
            'g7_settings.core.security.csp_mode' => 'enforce',
            'g7_settings.core.security.csp_style_mode' => 'enforce',
            'core.csp.style_mode_override' => 'off',
        ]);
        $styleOff = $this->through('/', $this->html());
        $this->assertStringNotContainsString('style', (string) $styleOff->headers->get('Content-Security-Policy'));
        $this->assertStringStartsWith("script-src 'self'", (string) $styleOff->headers->get('Content-Security-Policy'));

        // 스크립트 탈출구 off 는 스타일 헤더를 끄지 않는다
        config(['core.csp.style_mode_override' => null, 'core.csp.mode_override' => 'off']);
        $scriptOff = $this->through('/', $this->html());
        $this->assertSame("style-src-elem 'self'; report-uri /api/csp-report", $scriptOff->headers->get('Content-Security-Policy'));

        // 저장값이 끔이어도 스타일 탈출구가 켠다
        config([
            'core.csp.mode_override' => null,
            'g7_settings.core.security.csp_mode' => 'off',
            'g7_settings.core.security.csp_style_mode' => 'off',
            'core.csp.style_mode_override' => 'report_only',
        ]);
        $forced = $this->through('/', $this->html());
        $this->assertFalse($forced->headers->has('Content-Security-Policy'));
        $this->assertTrue($forced->headers->has('Content-Security-Policy-Report-Only'));
    }

    /**
     * 스타일 정책만 켠 경우에도 제외 규칙 4종은 같다. 이미 CSP 헤더가 하나라도 있으면 둘 다 붙이지 않는다.
     *
     * @scenario case=style_header_combination
     *
     * @effects csp_header_skipped_for_excluded_responses
     *
     * @return void 반환값 없음
     */
    public function test_스타일_헤더도_제외_응답에는_붙이지_않는다(): void
    {
        config([
            'g7_settings.core.security.csp_mode' => 'enforce',
            'g7_settings.core.security.csp_style_mode' => 'report_only',
        ]);
        $names = ['Content-Security-Policy', 'Content-Security-Policy-Report-Only'];

        $excluded = [
            $this->through('/x', response()->json(['ok' => true])),
            $this->through('/dev', $this->html()),
            $this->through('/dev/layouts', $this->html()),
            $this->through('/board/free', $this->html(), bot: true),
        ];

        foreach ($excluded as $response) {
            foreach ($names as $name) {
                $this->assertFalse($response->headers->has($name));
            }
        }

        // 응답이 강제 헤더만 달았어도 점검만 헤더를 덧붙이지 않는다
        $own = $this->through('/x', response('<svg/>', 200, ['Content-Type' => 'text/html', 'Content-Security-Policy' => "default-src 'none'"]));
        $this->assertSame("default-src 'none'", $own->headers->get('Content-Security-Policy'));
        $this->assertFalse($own->headers->has('Content-Security-Policy-Report-Only'));

        // 응답이 점검만 헤더만 달았어도 강제 헤더를 덧붙이지 않는다
        $ownReport = $this->through('/x', response('<svg/>', 200, ['Content-Type' => 'text/html', 'Content-Security-Policy-Report-Only' => "default-src 'none'"]));
        $this->assertSame("default-src 'none'", $ownReport->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertFalse($ownReport->headers->has('Content-Security-Policy'));

        // 제외 대상이 아니면 두 헤더가 다 붙는다
        $normal = $this->through('/developer', $this->html());
        $this->assertTrue($normal->headers->has('Content-Security-Policy'));
        $this->assertTrue($normal->headers->has('Content-Security-Policy-Report-Only'));
    }
}
