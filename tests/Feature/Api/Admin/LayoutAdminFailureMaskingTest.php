<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Models\LayoutExtension;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateCustomTranslation;
use App\Models\TemplateLayout;
use App\Models\User;
use App\Services\LayoutExtensionService;
use App\Services\LayoutFileService;
use App\Services\LayoutPreviewService;
use App\Services\LayoutService;
use App\Services\TemplateCustomTranslationService;
use App\Support\SafeExceptionMessage;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * 레이아웃·확장 조각·커스텀 다국어 관리 API 가 실패할 때 DB 내부 정보를 응답에 싣지 않는다
 * (7.1.0 전수 실측 영역 I1, 넘어온 보안 관측).
 *
 * 세 컨트롤러는 실패를 `$this->error('common.failed', 500, ['error' => $e->getMessage()])` 로 돌려줬다.
 * 응답 도우미의 운영 모드 500 차단은 **문자열** 오류만 거르므로 배열로 감싼 원문은 그대로 `errors.error`
 * 로 나갔다 — DB 예외라면 SQL·DB 호스트·DB 이름이 관리자 화면 응답에 실린다. 이제 사유는
 * {@see SafeExceptionMessage} 를 거쳐, 운영 모드의 내부 예외는 일반 문구로 바꾸고 원문은
 * 서버 로그에만 남긴다. 그 계약을 예외 주입으로 잠근다.
 */
class LayoutAdminFailureMaskingTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;

    private Template $template;

    /**
     * 권한을 시딩하고 슈퍼 관리자 토큰·테스트 템플릿을 만들며, 운영 모드로 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['app.debug' => false]);

        $admin = User::factory()->create(['is_super' => true]);
        $admin->roles()->attach(Role::where('identifier', 'admin')->value('id'), ['assigned_at' => now(), 'assigned_by' => null]);
        $this->token = $admin->createToken('t')->plainTextToken;

        $this->template = Template::factory()->create(['type' => 'admin']);
    }

    /**
     * 실측 사례와 같은 모양의 DB 예외(SQL·호스트·DB 이름을 담음)를 만듭니다.
     *
     * @return QueryException DB 예외
     */
    private function dbException(): QueryException
    {
        return new QueryException(
            'mysql',
            'update `g7_template_layouts` set `secret_column` = ?',
            ['x'],
            new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'secret_column'"),
            ['driver' => 'mysql', 'host' => 'db-internal.example', 'port' => '3306', 'database' => 'g7_secret_db'],
        );
    }

    /**
     * 응답에 DB 내부 정보가 없고 500 일반 문구인지 확인합니다.
     *
     * @param  TestResponse  $response  응답
     * @param  string  $label  실패 메시지에 붙일 경로 이름
     * @return void 반환값 없음
     */
    private function assertMasked(TestResponse $response, string $label): void
    {
        $response->assertStatus(500);
        $body = $response->getContent();

        foreach (['SQLSTATE', 'db-internal.example', 'g7_secret_db', 'secret_column', 'g7_template_layouts'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "{$label}: 응답에 내부 정보({$needle})가 실리면 안 된다");
        }
        $this->assertNotEmpty($response->json('message'), "{$label}: 일반 실패 문구는 있어야 한다");
    }

    /**
     * 인증 헤더가 붙은 요청 빌더를 돌려줍니다.
     *
     * @return static 요청 빌더
     */
    private function api(): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json']);
    }

    /**
     * 저장 경로를 지나는 최소 레이아웃 본문을 돌려줍니다.
     *
     * @param  string  $name  레이아웃 이름
     * @return array<string, mixed> 레이아웃 본문
     */
    private function minimalContent(string $name): array
    {
        return [
            'version' => '1.0.0',
            'layout_name' => $name,
            'endpoint' => '/api/admin/test',
            'components' => [['id' => 'root', 'name' => 'Div', 'type' => 'basic', 'props' => ['className' => 'p-4']]],
            'data_sources' => [],
        ];
    }

    /**
     * 레이아웃 파일 생성·이름변경·삭제·저장·미리보기 실패가 DB 정보를 싣지 않는다.
     *
     * @scenario surface=admin_api
     *
     * @effects layout_admin_failure_masks_db_detail
     *
     * @return void 반환값 없음
     */
    public function test_layout_failures_do_not_expose_db_detail(): void
    {
        $e = $this->dbException();
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'masking_probe',
            'source_type' => LayoutSourceType::Template,
            'lock_version' => 0,
        ]);

        $files = Mockery::mock(LayoutFileService::class)->makePartial();
        foreach (['create', 'rename', 'delete'] as $method) {
            $files->shouldReceive($method)->andThrow($e);
        }
        $this->app->instance(LayoutFileService::class, $files);

        $layouts = Mockery::mock(LayoutService::class)->makePartial();
        $layouts->shouldReceive('getLayoutByName')->andReturn($layout);
        $layouts->shouldReceive('updateLayout')->andThrow($e);
        $this->app->instance(LayoutService::class, $layouts);

        $previews = Mockery::mock(LayoutPreviewService::class)->makePartial();
        $previews->shouldReceive('createPreview')->andThrow($e);
        $this->app->instance(LayoutPreviewService::class, $previews);

        $base = "/api/admin/templates/{$this->template->identifier}/layouts";

        $this->assertMasked($this->api()->postJson($base, ['name' => 'masking_new']), 'POST layouts');
        $this->assertMasked(
            $this->api()->patchJson("{$base}/masking_probe/rename", ['new_name' => 'masking_moved', 'expected_lock_version' => 0]),
            'PATCH rename'
        );
        $this->assertMasked($this->api()->deleteJson("{$base}/masking_probe"), 'DELETE layout');
        $this->assertMasked(
            $this->api()->putJson("{$base}/masking_probe", ['expected_lock_version' => 0, 'content' => $this->minimalContent('masking_probe')]),
            'PUT layout'
        );
        $this->assertMasked(
            $this->api()->postJson("{$base}/masking_probe/preview", ['content' => $this->minimalContent('masking_probe')]),
            'POST preview'
        );
    }

    /**
     * 확장 조각 판본 복원 실패가 DB 정보를 싣지 않는다.
     *
     * @scenario surface=admin_api
     *
     * @effects layout_admin_failure_masks_db_detail
     *
     * @return void 반환값 없음
     */
    public function test_layout_extension_failures_do_not_expose_db_detail(): void
    {
        $extension = LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'masking_probe',
        ]);

        $service = Mockery::mock(LayoutExtensionService::class)->makePartial();
        $service->shouldReceive('getExtensionById')->andReturn($extension);
        $service->shouldReceive('restoreExtensionVersion')->andThrow($this->dbException());
        $this->app->instance(LayoutExtensionService::class, $service);

        $this->assertMasked(
            $this->api()->postJson("/api/admin/templates/{$this->template->identifier}/layout-extensions/{$extension->id}/versions/1/restore"),
            'POST extension restore'
        );
    }

    /**
     * 커스텀 다국어 키 생성·삭제·일괄 삭제 실패가 DB 정보를 싣지 않는다.
     *
     * @scenario surface=admin_api
     *
     * @effects layout_admin_failure_masks_db_detail
     *
     * @return void 반환값 없음
     */
    public function test_custom_translation_failures_do_not_expose_db_detail(): void
    {
        $e = $this->dbException();
        // 일괄 삭제 요청은 ids 가 실제 행이어야 검증을 지난다.
        $owned = TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'masking_probe',
            'translation_key' => 'custom.masking_probe',
            'values' => ['ko' => '값'],
        ]);

        $service = Mockery::mock(TemplateCustomTranslationService::class)->makePartial();
        $service->shouldReceive('find')->andReturn($owned);
        foreach (['createKey', 'deleteKey', 'deleteKeys'] as $method) {
            $service->shouldReceive($method)->andThrow($e);
        }
        $this->app->instance(TemplateCustomTranslationService::class, $service);

        $base = "/api/admin/templates/{$this->template->identifier}/custom-translations";

        $this->assertMasked(
            $this->api()->postJson($base, ['layout_name' => 'masking_probe', 'locale' => 'ko', 'value' => '값']),
            'POST custom translation'
        );
        $this->assertMasked($this->api()->deleteJson("{$base}/{$owned->id}"), 'DELETE custom translation');
        $this->assertMasked($this->api()->deleteJson($base, ['ids' => [$owned->id]]), 'DELETE custom translations (bulk)');
    }

    /**
     * 세 컨트롤러의 어떤 실패 응답도 예외 원문을 그대로 싣지 않는다(위 주입이 닿지 않는 경로 포함).
     *
     * @scenario surface=admin_api
     *
     * @effects layout_admin_failure_masks_db_detail
     *
     * @return void 반환값 없음
     */
    public function test_controllers_never_put_raw_exception_message_into_error_response(): void
    {
        foreach (['LayoutController', 'LayoutExtensionController', 'TemplateCustomTranslationController'] as $class) {
            $source = (string) file_get_contents(app_path("Http/Controllers/Api/Admin/{$class}.php"));

            $this->assertDoesNotMatchRegularExpression(
                "/'error'\s*=>\s*\\\$e->getMessage\(\)/",
                $source,
                "{$class}: 실패 응답에 예외 원문을 싣지 말고 SafeExceptionMessage::for() 를 거쳐야 한다"
            );
        }
    }
}
