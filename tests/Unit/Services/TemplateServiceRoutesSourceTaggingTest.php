<?php

namespace Tests\Unit\Services;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TemplateService::getRoutesDataWithModules source 태깅 Unit 테스트
 *
 * 각 라우트에 source { kind, identifier } 메타가 정확히 부여되는지 검증한다.
 * 모듈/플러그인 라우트는 실제 활성 확장이 없으면 검증 불가하므로
 * 본 테스트는 템플릿 자체 라우트의 source 태깅에 집중한다(병합 충돌 회귀 가드).
 */
class TemplateServiceRoutesSourceTaggingTest extends TestCase
{
    use RefreshDatabase;

    private TemplateService $service;

    /**
     * 시험마다 컨테이너에서 TemplateService 를 꺼내 둔다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TemplateService::class);
    }

    /**
     * getRoutesDataWithModules 가 템플릿 자체 라우트에 source.kind=template, identifier=null 을 붙이고
     * 템플릿 설정 화면 라우트에는 그 템플릿 식별자를 붙이는지 검증한다.
     *
     * @scenario route_source=template, target_template=active
     *
     * @effects get_routes_data_with_modules_attaches_source_kind_and_identifier_to_template_routes
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function template_self_routes_carry_source_kind_template_with_null_identifier(): void
    {
        Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '관리자 템플릿', 'en' => 'Admin Template'],
        ]);

        $result = $this->service->getRoutesDataWithModules('sirsoft-admin_basic');

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['data']['routes']);

        $templateRoutes = array_filter(
            $result['data']['routes'],
            fn ($r) => isset($r['source']['kind']) && $r['source']['kind'] === 'template'
        );

        $this->assertNotEmpty($templateRoutes, '템플릿 자체 라우트 최소 1건이 source.kind=template 으로 부여되어야 함');

        // 템플릿 설정 화면 라우트(`*/admin/templates/{id}/settings`)는 그 설정의 템플릿을 가리키도록
        // source.identifier 에 템플릿 식별자를 싣는다(TemplateService::loadTemplateSettingsRoutesData) — 공개 라우트
        // 응답 시험(PublicTemplateRoutesSourceTaggingTest)과 같은 기준이다. routes.json 에서 온 자체 라우트만 null 이다.
        $settingsPattern = '#^\*/admin/templates/([^/]+)/settings$#';
        $ownRoutes = array_filter($templateRoutes, fn ($r) => preg_match($settingsPattern, (string) ($r['path'] ?? '')) !== 1);
        $settingsRoutes = array_filter($templateRoutes, fn ($r) => preg_match($settingsPattern, (string) ($r['path'] ?? '')) === 1);

        $this->assertNotEmpty($ownRoutes, 'routes.json 에서 온 템플릿 자체 라우트가 있어야 함');

        foreach ($ownRoutes as $route) {
            $this->assertSame('template', $route['source']['kind']);
            $this->assertNull($route['source']['identifier']);
        }

        foreach ($settingsRoutes as $route) {
            preg_match($settingsPattern, $route['path'], $m);
            $this->assertSame($m[1], $route['source']['identifier'], '템플릿 설정 화면 라우트의 source.identifier 는 그 설정의 템플릿 식별자');
        }
    }

    /**
     * 병합된 응답의 모든 라우트가 source 키를 갖고 그 kind 가 template, module, plugin, core 중 하나인지 검증한다.
     *
     * @scenario route_source=template, target_template=active
     *
     * @effects every_merged_route_carries_source_kind_and_identifier
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function every_route_in_response_has_source_field_after_merge(): void
    {
        Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '관리자', 'en' => 'Admin'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '관리자', 'en' => 'Admin'],
        ]);

        $result = $this->service->getRoutesDataWithModules('sirsoft-admin_basic');

        $this->assertTrue($result['success']);

        foreach ($result['data']['routes'] as $route) {
            $this->assertArrayHasKey('source', $route, '병합 후 모든 라우트는 source 키를 보유');
            $this->assertArrayHasKey('kind', $route['source']);
            $this->assertContains(
                $route['source']['kind'],
                ['template', 'module', 'plugin', 'core'],
                'source.kind 는 template/module/plugin/core(시스템) 중 하나'
            );
            $this->assertArrayHasKey('identifier', $route['source']);
        }
    }

    /**
     * 비활성 템플릿은 source 태깅과 무관하게 success=false, error=template_not_found 로 돌아오는지 검증한다.
     *
     * @scenario route_source=template, target_template=inactive
     *
     * @effects routes_request_for_unavailable_template_fails_unchanged_by_source_tagging
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function inactive_template_returns_error_unchanged_by_source_tagging(): void
    {
        Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '관리자', 'en' => 'Admin'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Inactive->value,
            'description' => ['ko' => '관리자', 'en' => 'Admin'],
        ]);

        $result = $this->service->getRoutesDataWithModules('sirsoft-admin_basic');

        $this->assertFalse($result['success']);
        $this->assertSame('template_not_found', $result['error']);
    }
}
