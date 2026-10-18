<?php

namespace Tests\Feature\Api\Public;

use App\Enums\ExtensionStatus;
use App\Helpers\ResponseHelper;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * 403 응답의 예외 스택 노출 경계 테스트 (7.1.0 실측 디버그 모드 403 스택)
 *
 * g7_2 실측(APP_DEBUG=true)에서 권한 없는 관리자가 레이아웃을 열면 「페이지 로딩 실패」 화면에
 * 「Stack Trace」 가 보였다. 그 스택은 서버 응답이 아니라 화면(엔진 ErrorDisplay)이 디버그 모드에서만
 * 그리는 클라이언트 스택이다. 서버 쪽 경계를 고정한다.
 *
 * - 레이아웃 권한 403 응답은 운영·디버그 어느 모드에서도 예외 정보를 싣지 않는다.
 * - Laravel 기본 예외 렌더링(abort(403) 등)은 디버그 모드에서만 스택을 싣고 운영 모드에서는 싣지 않는다.
 * - ResponseHelper::error 에 예외를 넘겨도 운영 모드에서는 예외 메시지·스택이 나가지 않는다.
 */
class ForbiddenResponseDebugTraceBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 레이아웃 서빙 경로가 GDPR 미들웨어를 거치므로 그 마이그레이션을 포함한다.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    /**
     * 권한이 필요한 레이아웃을 만들고 그 권한이 없는 회원으로 엽니다.
     *
     * @return TestResponse 레이아웃 응답
     */
    private function openForbiddenLayout()
    {
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'guarded_page',
            'content' => [
                'meta' => ['title' => 'Guarded'],
                'permissions' => ['qa.guarded.read'],
                'components' => [['id' => 'root', 'type' => 'basic', 'name' => 'Div']],
            ],
        ]);

        return $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/layouts/{$template->identifier}/guarded_page");
    }

    /**
     * 응답 본문에 예외 정보가 없는지 단언합니다.
     *
     * @param  string  $body  응답 본문
     * @return void 반환값 없음
     */
    private function assertNoExceptionDetail(string $body): void
    {
        foreach (['"trace"', '"exception"', '"debug"', '"file"', '.php'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "응답에 예외 정보({$needle})가 실렸다: {$body}");
        }
    }

    /**
     * 운영 모드의 레이아웃 권한 403 은 문구만 싣는다.
     *
     * @scenario mode=production
     *
     * @effects forbidden_layout_has_no_trace
     *
     * @return void 반환값 없음
     */
    public function test_forbidden_layout_has_no_trace_in_production_mode(): void
    {
        config(['app.debug' => false]);

        $response = $this->openForbiddenLayout();

        $response->assertStatus(403);
        $this->assertSame(['success', 'message'], array_keys($response->json()));
        $this->assertNoExceptionDetail($response->getContent());
    }

    /**
     * 디버그 모드에서도 레이아웃 권한 403 응답에는 서버 스택이 없다 — 화면의 스택은 클라이언트 쪽이다.
     *
     * @scenario mode=debug
     *
     * @effects forbidden_layout_has_no_trace
     *
     * @return void 반환값 없음
     */
    public function test_forbidden_layout_has_no_server_trace_even_in_debug_mode(): void
    {
        config(['app.debug' => true]);

        $response = $this->openForbiddenLayout();

        $response->assertStatus(403);
        $this->assertNoExceptionDetail($response->getContent());
    }

    /**
     * 기본 예외 렌더링의 403 은 운영 모드에서 스택을 싣지 않고, 디버그 모드에서만 싣는다(의도된 디버그 동작).
     *
     * @scenario mode=production
     *
     * @effects framework_forbidden_trace_only_in_debug
     *
     * @return void 반환값 없음
     */
    public function test_framework_forbidden_exposes_trace_only_in_debug_mode(): void
    {
        Route::middleware('api')->get('/api/__qa736_pi_abort_403', fn () => abort(403));

        config(['app.debug' => false]);
        $production = $this->getJson('/api/__qa736_pi_abort_403');
        $production->assertStatus(403);
        $this->assertNoExceptionDetail($production->getContent());

        config(['app.debug' => true]);
        $debug = $this->getJson('/api/__qa736_pi_abort_403');
        $debug->assertStatus(403);
        $this->assertArrayHasKey('trace', $debug->json(), '디버그 모드의 기본 렌더링은 스택을 싣는다(개발용)');
    }

    /**
     * ResponseHelper::error 에 예외를 넘겨도 운영 모드에서는 예외 메시지·스택을 싣지 않는다.
     *
     * @scenario mode=production
     *
     * @effects helper_error_hides_exception_in_production
     *
     * @return void 반환값 없음
     */
    public function test_response_helper_hides_exception_in_production_mode(): void
    {
        config(['app.debug' => false]);

        $response = ResponseHelper::error('common.failed', 403, new RuntimeException('internal secret detail'));
        $body = (string) $response->getContent();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('internal secret detail', $body);
        $this->assertNoExceptionDetail($body);
    }
}
