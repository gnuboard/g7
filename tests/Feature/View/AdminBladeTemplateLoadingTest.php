<?php

namespace Tests\Feature\View;

use App\Models\Template;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBladeTemplateLoadingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 각 테스트 전에 실행 (템플릿 설치 및 활성화)
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 코어 렌더링 엔진이 빌드되어 있는지 확인
        $coreEnginePath = public_path('build/core/template-engine.min.js');
        if (! file_exists($coreEnginePath)) {
            $this->markTestSkipped('Core template engine not built. Run npm run build to generate build files.');
        }

        // sirsoft-admin_basic 템플릿 설치 및 활성화
        $templateService = app(TemplateService::class);

        try {
            // 템플릿 설치
            $templateService->installTemplate('sirsoft-admin_basic');

            // 설치된 템플릿 조회
            $template = Template::where('identifier', 'sirsoft-admin_basic')->first();

            // 템플릿 활성화
            if ($template) {
                $templateService->activateTemplate($template->id);
            }
        } catch (\Exception $e) {
            // 템플릿이 이미 설치되어 있거나 설치 실패 시 무시
            // 테스트는 계속 진행됨
        }
    }

    /**
     * 활성화된 템플릿이 있을 때 템플릿 번들이 로드되는지 테스트
     */
    public function test_admin_blade_loads_template_bundles_when_active_template_exists(): void
    {
        // Arrange: setUp()에서 이미 sirsoft-admin_basic가 설치 및 활성화됨
        // 추가 템플릿 생성 불필요

        // Act: admin view 렌더링
        $response = $this->get('/admin');

        // Assert: 템플릿 번들 로딩 확인
        $response->assertStatus(200);

        // CSS 번들 로드 확인 (API를 통한 에셋 서빙)
        $response->assertSee('/api/templates/assets/sirsoft-admin_basic/css/components.css?v=', false);

        // JS 번들 로드 확인 (API를 통한 에셋 서빙)
        $response->assertSee('/api/templates/assets/sirsoft-admin_basic/js/components.iife.js?v=', false);

        // 코어 렌더링 엔진 로드 확인
        $response->assertSee('/build/core/template-engine.min.js?v=', false);

        // data-template-id 속성 확인
        $response->assertSee('data-template-id="sirsoft-admin_basic"', false);

        // 템플릿 엔진 초기화 스크립트 확인
        $response->assertSee("templateId: 'sirsoft-admin_basic'", false);
    }

    /**
     * 활성화된 템플릿이 없을 때 기본 템플릿 번들이 로드되는지 테스트
     */
    public function test_admin_blade_loads_default_template_when_no_active_template(): void
    {
        // Arrange: 활성화된 템플릿 없음
        Template::factory()->create([
            'identifier' => 'inactive-template',
            'type' => 'admin',
            'status' => 'inactive',
        ]);

        // Act: admin view 렌더링
        $response = $this->get('/admin');

        // Assert: 기본 템플릿 번들 로딩 확인
        $response->assertStatus(200);

        // CSS 번들 로드 확인 (API를 통한 에셋 서빙)
        $response->assertSee('/api/templates/assets/sirsoft-admin_basic/css/components.css?v=', false);

        // JS 번들 로드 확인 (API를 통한 에셋 서빙)
        $response->assertSee('/api/templates/assets/sirsoft-admin_basic/js/components.iife.js?v=', false);

        // 코어 렌더링 엔진 로드 확인
        $response->assertSee('/build/core/template-engine.min.js?v=', false);

        // data-template-id 속성에 기본값 확인
        $response->assertSee('data-template-id="sirsoft-admin_basic"', false);

        // 템플릿 엔진 초기화 스크립트에 기본값 확인
        $response->assertSee("templateId: 'sirsoft-admin_basic'", false);
    }

    /**
     * 템플릿이 전혀 없을 때도 페이지가 정상 렌더링되는지 테스트
     *
     * 활성화된 템플릿이 없으면 Fallback UI가 표시됩니다.
     * Fallback UI에서는 코어 렌더링 엔진이 로드되지 않습니다.
     */
    public function test_admin_blade_renders_without_any_template(): void
    {
        // Arrange: 모든 템플릿 비활성화
        Template::query()->update(['status' => \App\Enums\ExtensionStatus::Inactive->value]);

        // Act: admin view 렌더링
        $response = $this->get('/admin');

        // Assert: 페이지가 정상적으로 렌더링됨
        $response->assertStatus(200);

        // 기본 구조 확인
        $response->assertSee('<div id="app"', false);

        // Fallback UI가 표시됨 (번역 키: templates.errors.no_active_template)
        $response->assertSee('error-container', false);
        // 활성 템플릿 없음 문구 — 한국어 또는 영어 번역 문자열 중 하나
        $content = $response->getContent();
        $this->assertTrue(
            str_contains($content, '활성화된 템플릿이 없습니다')
                || str_contains($content, 'No active template found'),
            'Fallback UI 에 no_active_template 번역 문자열이 포함되어야 함'
        );
    }

    /**
     * 코어 렌더링 엔진이 항상 로드되는지 테스트
     */
    public function test_admin_blade_always_loads_core_rendering_engine(): void
    {
        // Act: admin view 렌더링
        $response = $this->get('/admin');

        // Assert: 코어 렌더링 엔진 로드 확인
        $response->assertStatus(200);

        // 코어 렌더링 엔진 스크립트 확인
        $response->assertSee('/build/core/template-engine.min.js?v=', false);

        // 기본 구조 확인
        $response->assertSee('<div id="app"', false);
    }

    /**
     * Font Awesome CDN이 로드되는지 테스트
     */
    public function test_admin_blade_loads_font_awesome_cdn(): void
    {
        // Act: admin view 렌더링
        $response = $this->get('/admin');

        // Assert: Font Awesome CDN 확인
        $response->assertStatus(200);
        $response->assertSee('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css', false);
    }

    /**
     * 템플릿 엔진 초기화 설정이 올바르게 전달되는지 테스트
     */
    public function test_admin_blade_initializes_template_engine_with_correct_config(): void
    {
        // Arrange: setUp()에서 이미 sirsoft-admin_basic가 활성화됨
        // 추가 설정 없이 기본 템플릿 사용

        // Act: admin view 렌더링
        $response = $this->get('/admin');

        // Assert: 템플릿 엔진 초기화 설정 확인
        $response->assertStatus(200);
        $response->assertSee("templateId: 'sirsoft-admin_basic'", false);
        $response->assertSee('locale:', false); // locale 값은 테스트 환경에 따라 'en' 또는 'ko'일 수 있음
        $response->assertSee('debug:', false);
    }

    /**
     * 브라우저 캐시 버스팅을 위한 버전 쿼리 파라미터가 추가되는지 테스트
     */
    public function test_admin_blade_includes_cache_busting_version_parameters(): void
    {
        // Arrange: setUp()에서 이미 sirsoft-admin_basic가 설치 및 활성화됨
        // 추가 템플릿 생성 불필요

        // Act: admin view 렌더링
        $response = $this->get('/admin');

        // Assert: 모든 정적 파일에 버전 쿼리 파라미터가 있는지 확인
        $response->assertStatus(200);

        // 코어 엔진에 버전 파라미터
        $response->assertSee('/build/core/template-engine.min.js?v=', false);

        // 템플릿 CSS에 버전 파라미터 (API를 통한 에셋 서빙)
        $response->assertSee('/api/templates/assets/sirsoft-admin_basic/css/components.css?v=', false);

        // 템플릿 JS에 버전 파라미터 (API를 통한 에셋 서빙)
        $response->assertSee('/api/templates/assets/sirsoft-admin_basic/js/components.iife.js?v=', false);
    }
}
