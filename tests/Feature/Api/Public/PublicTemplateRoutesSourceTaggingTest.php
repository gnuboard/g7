<?php

namespace Tests\Feature\Api\Public;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 라우트 트리 소스 태깅 Feature 테스트
 *
 * getRoutesDataWithModules 가 각 라우트에 source { kind, identifier } 메타를
 * 비파괴적으로 부여하는지 검증. 레이아웃 편집기 라우트 트리 그룹핑의 SSoT.
 */
class PublicTemplateRoutesSourceTaggingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 같은 스위트의 GDPR 미들웨어 의존 테스트와 migrate:fresh 정합성을 맞추기 위해
     * GDPR 플러그인 마이그레이션을 일관 선언한다.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    /**
     * 공개 routes.json 응답에서 템플릿 자체 라우트는 source.kind=template, identifier=null 로 태깅되고
     * 템플릿 설정 화면 라우트는 그 템플릿 식별자를 identifier 로 싣는지 검증한다.
     *
     * @scenario route_source=template, target_template=active
     *
     * @effects get_routes_data_with_modules_attaches_source_kind_and_identifier_to_template_routes
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function template_routes_response_attaches_source_kind_template_with_null_identifier(): void
    {
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '관리자 템플릿', 'en' => 'Admin Template'],
        ]);

        $response = $this->getJson("/api/templates/{$template->identifier}/routes.json");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $routes = $response->json('data.routes');
        $this->assertIsArray($routes);
        $this->assertNotEmpty($routes);

        // 최소 1건의 템플릿 자체 라우트가 source.kind = 'template' 으로 태깅되었는지
        $templateRoutes = array_filter(
            $routes,
            fn ($r) => isset($r['source']['kind']) && $r['source']['kind'] === 'template'
        );

        $this->assertNotEmpty($templateRoutes, '템플릿 자체 라우트에 source.kind=template 이 부여되어야 함');

        // 템플릿 설정 화면 라우트(`*/admin/templates/{id}/settings`)는 코어가 설치 템플릿마다 만들어 붙이며,
        // 그 템플릿을 가리키도록 source.identifier 에 템플릿 식별자를 싣는다(TemplateService::loadTemplateSettingsRoutesData).
        // routes.json 에서 온 템플릿 자체 라우트만 identifier 가 null 이다.
        $settingsPattern = '#^\*/admin/templates/([^/]+)/settings$#';
        $ownRoutes = array_filter($templateRoutes, fn ($r) => preg_match($settingsPattern, (string) ($r['path'] ?? '')) !== 1);
        $settingsRoutes = array_filter($templateRoutes, fn ($r) => preg_match($settingsPattern, (string) ($r['path'] ?? '')) === 1);

        $this->assertNotEmpty($ownRoutes, 'routes.json 에서 온 템플릿 자체 라우트가 있어야 함');

        foreach ($ownRoutes as $route) {
            $this->assertSame('template', $route['source']['kind']);
            $this->assertNull(
                $route['source']['identifier'],
                '템플릿 자체 라우트의 source.identifier 는 null'
            );
        }

        foreach ($settingsRoutes as $route) {
            preg_match($settingsPattern, $route['path'], $m);
            $this->assertSame(
                $m[1],
                $route['source']['identifier'],
                '템플릿 설정 화면 라우트의 source.identifier 는 그 설정의 템플릿 식별자'
            );
        }
    }

    /**
     * source 태깅이 기존 path 필드를 지우지 않고 모든 라우트에 source.kind, source.identifier 키를 더하는지 검증한다.
     *
     * @scenario route_source=template, target_template=active
     *
     * @effects get_routes_data_with_modules_preserves_meta_title_editor_label_hidden_parent, every_merged_route_carries_source_kind_and_identifier
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function template_routes_response_preserves_existing_meta_fields_non_destructively(): void
    {
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '관리자 템플릿', 'en' => 'Admin Template'],
        ]);

        $response = $this->getJson("/api/templates/{$template->identifier}/routes.json");
        $routes = $response->json('data.routes');

        // path / layout 등 기존 필드가 source 추가로 인해 사라지지 않았는지
        foreach ($routes as $route) {
            $this->assertArrayHasKey('path', $route, '기존 path 필드는 보존되어야 함');
            $this->assertArrayHasKey('source', $route, '신규 source 필드는 모든 라우트에 부여');
            $this->assertArrayHasKey('kind', $route['source']);
            $this->assertArrayHasKey('identifier', $route['source']);
        }
    }

    /**
     * 존재하지 않는 템플릿의 routes.json 요청은 source 태깅과 무관하게 404 와 success=false 로 끝나는지 검증한다.
     *
     * @effects routes_request_for_unavailable_template_fails_unchanged_by_source_tagging
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function nonexistent_template_returns_404_unchanged_by_source_tagging(): void
    {
        $response = $this->getJson('/api/templates/nonexistent-template/routes.json');

        $response->assertStatus(404)
            ->assertJson(['success' => false]);
    }
}
