<?php

namespace Tests\Feature\Api\Public;

use App\Contracts\Extension\ModuleInterface;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\ModuleManager;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 공개 레이아웃 미리보기 API 테스트
 *
 * GET /api/layouts/preview/{token}.json 엔드포인트 테스트
 */
class LayoutPreviewControllerTest extends TestCase
{
    use RefreshDatabase;

    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->template = Template::factory()->create();
    }

    /**
     * 유효한 토큰으로 미리보기 레이아웃 조회 성공
     */
    public function test_can_serve_preview_with_valid_token(): void
    {
        // Arrange
        $content = [
            'version' => '1.0.0',
            'layout_name' => 'main',
            'components' => [['id' => 'root', 'type' => 'layout', 'name' => 'Container']],
            'data_sources' => [],
        ];

        $preview = TemplateLayoutPreview::create([
            'token' => (string) Str::uuid(),
            'template_id' => $this->template->id,
            'layout_name' => 'main',
            'content' => $content,
            'admin_id' => 1,
            'expires_at' => now()->addMinutes(30),
        ]);

        // Act
        $response = $this->getJson("/api/layouts/preview/{$preview->token}.json");

        // Assert
        $response->assertStatus(200);
        $response->assertJsonFragment(['layout_name' => 'main']);
    }

    /**
     * 만료된 토큰으로 조회 시 404
     */
    public function test_expired_preview_returns_404(): void
    {
        // Arrange
        $preview = TemplateLayoutPreview::create([
            'token' => (string) Str::uuid(),
            'template_id' => $this->template->id,
            'layout_name' => 'main',
            'content' => ['version' => '1.0.0', 'components' => []],
            'admin_id' => 1,
            'expires_at' => now()->subMinutes(1), // 이미 만료
        ]);

        // Act
        $response = $this->getJson("/api/layouts/preview/{$preview->token}.json");

        // Assert
        $response->assertStatus(404);
    }

    /**
     * 존재하지 않는 토큰으로 조회 시 404
     */
    public function test_nonexistent_token_returns_404(): void
    {
        // Arrange
        $fakeToken = (string) Str::uuid();

        // Act
        $response = $this->getJson("/api/layouts/preview/{$fakeToken}.json");

        // Assert
        $response->assertStatus(404);
    }

    /**
     * 인증 없이도 미리보기 조회 가능 (토큰이 보안 메커니즘)
     */
    public function test_preview_does_not_require_authentication(): void
    {
        // Arrange
        $preview = TemplateLayoutPreview::create([
            'token' => (string) Str::uuid(),
            'template_id' => $this->template->id,
            'layout_name' => 'dashboard',
            'content' => ['version' => '1.0.0', 'components' => [['id' => 'test']]],
            'admin_id' => 1,
            'expires_at' => now()->addMinutes(30),
        ]);

        // Act - 인증 헤더 없이 요청
        $response = $this->getJson("/api/layouts/preview/{$preview->token}.json");

        // Assert
        $response->assertStatus(200);
    }

    /**
     * 미리보기 응답에서도 개발자 주석이 제거되어야 합니다 — 편집 중인 본문과 확장 주입 조각 양쪽.
     *
     * 미리보기는 편집기가 여는 **화면 렌더** 경로다(브라우저가 이 JSON 으로 실제 화면을 그린다).
     * 그래서 공개 서빙과 같은 계약을 진다 — 개발자 주석은 브라우저에 닿지 않는다.
     *
     * 이 경로가 그 계약을 절반만 지키고 있었다: 부모 레이아웃은 `loadAndMergeLayout` 이 이미
     * 제거하지만, 편집 중인 본문과 그 뒤(`applyExtensions`)에 얹히는 확장 조각은 제거를 지나쳐
     * **한 응답 안에서 주석 유무가 갈렸다**. 무인증 토큰 엔드포인트라 노출면도 공개 서빙과 같다.
     *
     * @scenario compile_unit=layout,source_health=valid
     *
     * @effects injected_comments_in_extension_fragments_never_reach_the_public_serving_response
     */
    public function test_strips_developer_comments_from_layout_preview_response(): void
    {
        // 확장 주입은 활성 모듈에서 온 것만 병합된다 — 모킹하지 않으면 조각이 붙지 않아
        // 아래 주석 부재 단언이 아무것도 검사하지 않는다.
        $this->mockActiveEcommerceModule();

        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'preview_host',
            'content' => [
                'layout_name' => 'preview_host',
                'components' => [['id' => 'host_anchor', 'type' => 'basic', 'name' => 'Div', 'children' => []]],
            ],
            'is_active' => true,
        ]);

        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'preview_host',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'content' => [
                'target_layout' => 'preview_host',
                'comment' => '미리보기 확장 조각 문서 주석',
                'injections' => [[
                    'target_id' => 'host_anchor',
                    'position' => 'append_child',
                    'components' => [[
                        'id' => 'ext_injected',
                        'type' => 'basic',
                        'name' => 'Div',
                        'comment' => '미리보기 확장 조각 노드 주석',
                        'actions' => [['handler' => 'navigate', 'comment' => '미리보기 확장 조각 액션 주석']],
                    ]],
                ]],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $preview = TemplateLayoutPreview::create([
            'token' => (string) Str::uuid(),
            'template_id' => $this->template->id,
            'layout_name' => 'preview_host',
            'content' => [
                'version' => '1.0.0',
                // overlay 매칭은 병합 결과의 `layout_name` 을 본다 — 없으면 조각이 붙지 않는다.
                'layout_name' => 'preview_host',
                'comment' => '편집 중 본문 문서 주석',
                'components' => [[
                    'id' => 'host_anchor',
                    'type' => 'basic',
                    'name' => 'Div',
                    'comment' => '편집 중 본문 노드 주석',
                    'children' => [],
                ]],
                'data_sources' => [],
            ],
            'admin_id' => 1,
            'expires_at' => now()->addMinutes(30),
        ]);

        $response = $this->getJson("/api/layouts/preview/{$preview->token}.json");

        $response->assertStatus(200);

        $body = $response->getContent();

        // 부재를 단언하기 전에 존재를 확정한다 — 조각이 병합되지 않은 상태에서는 「주석 0」이
        // 공허하게 참이라, 이 단언이 없으면 검사기가 죽어도 초록이 난다.
        $this->assertStringContainsString('host_anchor', $body,
            '편집 중 본문이 응답에 없다 — 아래 주석 부재 단언이 아무것도 검사하지 않는다. 응답: '.$body);
        $this->assertStringContainsString('ext_injected', $body,
            '확장 조각이 병합되지 않았다 — 아래 주석 부재 단언이 아무것도 검사하지 않는다. 응답: '.$body);

        $this->assertStringNotContainsString('"comment"', $body);
        $this->assertStringNotContainsString('편집 중 본문 문서 주석', $body);
        $this->assertStringNotContainsString('편집 중 본문 노드 주석', $body);
        $this->assertStringNotContainsString('미리보기 확장 조각 문서 주석', $body);
        $this->assertStringNotContainsString('미리보기 확장 조각 노드 주석', $body);
        $this->assertStringNotContainsString('미리보기 확장 조각 액션 주석', $body);
    }

    /**
     * 확장 미리보기(`preview_type=extension`)에서도 개발자 주석이 제거되어야 합니다.
     *
     * 이 분기는 편집 중인 **확장 정의**를 대표 레이아웃에 임시 치환해 그린다. 치환된 content 는
     * 소스에서 온 그대로라 주석을 품고 있고, 레이아웃 미리보기와는 다른 호출 지점이므로
     * 제거를 한 곳에만 걸면 이 분기가 남는다.
     *
     * @scenario compile_unit=layout,source_health=valid
     *
     * @effects injected_comments_in_extension_fragments_never_reach_the_public_serving_response
     */
    public function test_strips_developer_comments_from_extension_preview_response(): void
    {
        $this->mockActiveEcommerceModule();

        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'ext_preview_host',
            'content' => [
                'layout_name' => 'ext_preview_host',
                'components' => [['id' => 'host_anchor', 'type' => 'basic', 'name' => 'Div', 'children' => []]],
            ],
            'is_active' => true,
        ]);

        $extension = LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'ext_preview_host',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'content' => ['target_layout' => 'ext_preview_host', 'injections' => []],
            'priority' => 100,
            'is_active' => true,
        ]);

        // 편집 중인 확장 정의 — 이것이 위 저장분을 임시 치환한다.
        $preview = TemplateLayoutPreview::create([
            'token' => (string) Str::uuid(),
            'template_id' => $this->template->id,
            'layout_name' => 'ext_preview_host',
            'preview_type' => 'extension',
            'extension_id' => $extension->id,
            'content' => [
                'target_layout' => 'ext_preview_host',
                'comment' => '편집 중 확장 문서 주석',
                'injections' => [[
                    'target_id' => 'host_anchor',
                    'position' => 'append_child',
                    'components' => [[
                        'id' => 'ext_preview_injected',
                        'type' => 'basic',
                        'name' => 'Div',
                        'comment' => '편집 중 확장 노드 주석',
                    ]],
                ]],
            ],
            'admin_id' => 1,
            'expires_at' => now()->addMinutes(30),
        ]);

        $response = $this->getJson("/api/layouts/preview/{$preview->token}.json");

        $response->assertStatus(200);

        $body = $response->getContent();

        $this->assertStringContainsString('ext_preview_injected', $body,
            '편집 중 확장 조각이 치환·병합되지 않았다 — 아래 주석 부재 단언이 아무것도 검사하지 않는다. 응답: '.$body);

        $this->assertStringNotContainsString('"comment"', $body);
        $this->assertStringNotContainsString('편집 중 확장 문서 주석', $body);
        $this->assertStringNotContainsString('편집 중 확장 노드 주석', $body);
    }

    /**
     * 활성 이커머스 모듈을 모킹합니다 — 확장 주입 병합의 전제 조건.
     */
    private function mockActiveEcommerceModule(): void
    {
        $activeModule = $this->createMock(ModuleInterface::class);
        $activeModule->method('getIdentifier')->willReturn('sirsoft-ecommerce');
        $mockModuleManager = $this->createMock(ModuleManager::class);
        $mockModuleManager->method('getActiveModules')->willReturn(['sirsoft-ecommerce' => $activeModule]);
        $this->app->instance(ModuleManager::class, $mockModuleManager);
    }
}
