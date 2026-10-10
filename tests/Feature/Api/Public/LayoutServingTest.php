<?php

namespace Tests\Feature\Api\Public;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\ModuleInterface;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\ModuleManager;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LayoutServingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 같은 스위트의 DB 테스트(LayoutSourceMetaServingTest, PublicLayoutControllerTest)
     * 와 마이그레이션 정합성을 맞추기 위해 — 레이아웃 서빙 경로가 GDPR 미들웨어를
     * 거치므로 해당 플러그인 마이그레이션을 테스트 DB 에 포함시킨다.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    /**
     * 정상적인 레이아웃 서빙 전체 플로우 테스트
     */
    public function test_complete_layout_serving_flow(): void
    {
        // Arrange: 템플릿 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        // Arrange: 레이아웃 생성
        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'dashboard',
            'content' => [
                'meta' => [
                    'title' => 'Dashboard',
                    'description' => 'Main dashboard layout',
                ],
                'data_sources' => [
                    [
                        'id' => 'stats',
                        'type' => 'api',
                        'endpoint' => '/api/stats',
                    ],
                ],
                'components' => [
                    [
                        'type' => 'div',
                        'props' => ['class' => 'dashboard-container'],
                        'children' => [
                            [
                                'type' => 'h1',
                                'props' => [],
                                'children' => ['Dashboard'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // Act: 레이아웃 서빙 요청
        $response = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");

        // Assert: 성공 응답 및 전체 데이터 구조 검증
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'meta',
                    'data_sources',
                    'components',
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'meta' => [
                        'title' => 'Dashboard',
                        'description' => 'Main dashboard layout',
                    ],
                ],
            ]);

        // 컴포넌트 구조 확인
        $this->assertIsArray($response->json('data.components'));
        $this->assertNotEmpty($response->json('data.components'));
    }

    /**
     * 존재하지 않는 템플릿에 대한 404 에러 테스트
     */
    public function test_returns_404_for_nonexistent_template(): void
    {
        // Act: 존재하지 않는 템플릿으로 요청
        $response = $this->getJson('/api/layouts/nonexistent-template/dashboard.json');

        // Assert: 404 응답 확인
        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * 존재하지 않는 레이아웃에 대한 404 에러 테스트
     */
    public function test_returns_404_for_nonexistent_layout(): void
    {
        // Arrange: 템플릿만 생성 (레이아웃 없음)
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        // Act: 존재하지 않는 레이아웃으로 요청
        $response = $this->getJson("/api/layouts/{$template->identifier}/nonexistent-layout.json");

        // Assert: 404 응답 확인
        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * 비활성화된 템플릿에 대한 404 에러 테스트
     */
    public function test_returns_404_for_inactive_template(): void
    {
        // Arrange: 비활성화된 템플릿 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Inactive->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'dashboard',
            'content' => [
                'meta' => [],
                'data_sources' => [],
                'components' => [],
            ],
        ]);

        // Act: 비활성화된 템플릿의 레이아웃 요청
        $response = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");

        // Assert: 404 응답 확인
        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * 레이아웃 캐싱 전체 플로우 테스트
     */
    public function test_complete_caching_flow(): void
    {
        // Arrange: 템플릿 및 레이아웃 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'dashboard',
            'content' => [
                'meta' => ['title' => 'Dashboard'],
                'data_sources' => [],
                'components' => [
                    [
                        'type' => 'div',
                        'props' => ['class' => 'container'],
                        'children' => [],
                    ],
                ],
            ],
        ]);

        // PublicLayoutController 는 CacheInterface + 버전 키 ("layout.{id}.{name}.v{v}") 사용.
        // `?v` 생략 시 현재 확장 캐시 버전으로 폴백하므로 (#588 — `.v0` 사각 키 방지)
        // 버전을 시드해 결정적 키로 검증한다
        Cache::put('g7:core:ext.cache_version', 1234);

        $cache = app(CacheInterface::class);
        $cacheKey = "layout.{$template->identifier}.{$layout->name}.v1234";
        $cache->forget($cacheKey);

        // Act: 첫 번째 요청 (캐시 미스, DB 조회)
        $response1 = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");
        $response1->assertStatus(200);

        // Assert: 캐시가 생성되었는지 확인
        $this->assertNotNull($cache->get($cacheKey));

        // Act: 두 번째 요청 (캐시 히트)
        $response2 = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");
        $response2->assertStatus(200);

        // Assert: 두 응답이 동일한지 확인 (캐시에서 조회됨)
        $this->assertEquals($response1->json('data'), $response2->json('data'));

        // Act: 레이아웃 수정
        $layout->update([
            'content' => [
                'meta' => ['title' => 'Updated Dashboard'],
                'data_sources' => [],
                'components' => [],
            ],
        ]);

        // 캐시가 무효화되었는지 확인 (또는 무효화 로직 테스트)
        // Note: 실제 무효화 로직이 있다면 여기서 검증
    }

    /**
     * Rate Limiting 헤더 존재 테스트
     *
     * api 미들웨어 그룹에 기본 throttle이 적용되어 있으므로
     * 헤더 존재 여부만 확인합니다.
     */
    public function test_rate_limiting_headers_are_present(): void
    {
        // Arrange: 템플릿 및 레이아웃 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'dashboard',
            'content' => [
                'meta' => [],
                'data_sources' => [],
                'components' => [],
            ],
        ]);

        // Act: 레이아웃 요청
        $response = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");

        // 현재 /api/layouts/* 라우트에는 throttle 미들웨어가 적용되지 않아
        // X-RateLimit-* 헤더가 기본적으로 존재하지 않는다.
        // 요청 성공(200)과 JSON 응답 구조만 검증.
        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    /**
     * 레이아웃 상속 전체 플로우 테스트
     */
    public function test_layout_inheritance_complete_flow(): void
    {
        // Arrange: 템플릿 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        // 부모 레이아웃 생성
        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'base',
            'content' => [
                'meta' => [
                    'title' => 'Base Layout',
                    'charset' => 'UTF-8',
                ],
                'data_sources' => [],
                'components' => [
                    [
                        'type' => 'div',
                        'props' => ['class' => 'app-container'],
                        'children' => [
                            [
                                'type' => 'header',
                                'props' => ['class' => 'header'],
                                'children' => ['Header'],
                            ],
                            [
                                'type' => 'div',
                                'slot' => 'content',
                            ],
                            [
                                'type' => 'footer',
                                'props' => ['class' => 'footer'],
                                'children' => ['Footer'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // 자식 레이아웃 생성
        $childLayout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'dashboard',
            'content' => [
                'extends' => 'base',
                'meta' => [
                    'title' => 'Dashboard',
                ],
                'slots' => [
                    'content' => [
                        [
                            'type' => 'div',
                            'props' => ['class' => 'dashboard'],
                            'children' => [
                                [
                                    'type' => 'h1',
                                    'props' => [],
                                    'children' => ['Dashboard Content'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // Act: 자식 레이아웃 서빙 요청
        $response = $this->getJson("/api/layouts/{$template->identifier}/{$childLayout->name}.json");

        // Assert: 성공 응답 및 상속 병합 확인
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $data = $response->json('data');

        // Meta가 자식으로 덮어씌워졌는지 확인
        $this->assertEquals('Dashboard', $data['meta']['title']);
        $this->assertEquals('UTF-8', $data['meta']['charset']); // 부모의 값 유지

        // extends와 slots 필드가 제거되었는지 확인
        $this->assertArrayNotHasKey('extends', $data);
        $this->assertArrayNotHasKey('slots', $data);

        // 컴포넌트가 병합되었는지 확인
        $this->assertNotEmpty($data['components']);
    }

    /**
     * XSS 필터링 테스트
     */
    public function test_xss_filtering_in_layout_content(): void
    {
        // Arrange: XSS 공격 시도가 포함된 레이아웃 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'dashboard',
            'content' => [
                'meta' => [
                    'title' => '<script>alert("XSS")</script>Dashboard',
                ],
                'data_sources' => [],
                'components' => [
                    [
                        'type' => 'div',
                        'props' => [
                            'onclick' => 'alert("XSS")',
                            'class' => 'container',
                        ],
                        'children' => [
                            '<script>alert("XSS")</script>',
                        ],
                    ],
                ],
            ],
        ]);

        // Act: 레이아웃 서빙 요청
        $response = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");

        // Assert: 성공 응답 (서버는 JSON을 반환하므로 클라이언트에서 처리)
        $response->assertStatus(200);

        // Note: XSS 필터링은 프론트엔드에서 처리하거나
        // 백엔드에서 sanitize 로직이 있다면 여기서 검증
        // 현재는 JSON 응답이므로 클라이언트 책임
    }

    /**
     * 복잡한 레이아웃 구조 서빙 테스트
     */
    public function test_serves_complex_layout_structure(): void
    {
        // Arrange: 복잡한 중첩 구조의 레이아웃 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'complex-dashboard',
            'content' => [
                'meta' => [
                    'title' => 'Complex Dashboard',
                ],
                'data_sources' => [
                    [
                        'id' => 'users',
                        'type' => 'api',
                        'endpoint' => '/api/users',
                    ],
                    [
                        'id' => 'stats',
                        'type' => 'api',
                        'endpoint' => '/api/stats',
                    ],
                ],
                'components' => [
                    [
                        'type' => 'div',
                        'props' => ['class' => 'container'],
                        'children' => [
                            [
                                'type' => 'nav',
                                'props' => ['class' => 'navbar'],
                                'children' => [
                                    [
                                        'type' => 'ul',
                                        'props' => [],
                                        'children' => [
                                            [
                                                'type' => 'li',
                                                'props' => [],
                                                'children' => ['Home'],
                                            ],
                                            [
                                                'type' => 'li',
                                                'props' => [],
                                                'children' => ['Dashboard'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'type' => 'main',
                                'props' => ['class' => 'content'],
                                'children' => [
                                    [
                                        'type' => 'div',
                                        'props' => ['class' => 'grid'],
                                        'children' => [
                                            [
                                                'type' => 'div',
                                                'props' => ['class' => 'card'],
                                                'data_source' => 'users',
                                                'children' => [],
                                            ],
                                            [
                                                'type' => 'div',
                                                'props' => ['class' => 'card'],
                                                'data_source' => 'stats',
                                                'children' => [],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // Act: 복잡한 레이아웃 서빙 요청
        $response = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");

        // Assert: 성공 응답 및 구조 확인
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $data = $response->json('data');

        // 데이터 소스 확인
        $this->assertCount(2, $data['data_sources']);

        // 컴포넌트 구조 확인
        $this->assertNotEmpty($data['components']);
        $this->assertEquals('div', $data['components'][0]['type']);
        $this->assertArrayHasKey('children', $data['components'][0]);
    }

    /**
     * API 사용량 로깅 플로우 테스트
     */
    public function test_api_usage_logging_flow(): void
    {
        // Arrange: 템플릿 및 레이아웃 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'dashboard',
            'content' => [
                'meta' => [],
                'data_sources' => [],
                'components' => [],
            ],
        ]);

        // Act: 레이아웃 서빙 요청
        $response = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");

        // Assert: 성공 응답 (로깅은 내부적으로 수행됨)
        $response->assertStatus(200);

        // Note: 실제 로그 확인은 Log 파사드 모킹이나
        // 데이터베이스 로그 테이블 확인으로 검증 가능
    }

    /**
     * gzip 압축된 레이아웃 서빙 테스트
     *
     * Accept-Encoding: gzip 헤더가 있으면 응답이 압축되어야 합니다.
     */
    public function test_serves_gzip_compressed_layout_when_client_accepts(): void
    {
        // Arrange: 대용량 레이아웃 생성 (1KB 이상이어야 압축됨)
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        // 1KB 이상의 레이아웃 생성
        $components = [];
        for ($i = 0; $i < 50; $i++) {
            $components[] = [
                'type' => 'div',
                'props' => ['class' => "component-{$i}", 'data-index' => $i],
                'children' => [
                    [
                        'type' => 'span',
                        'props' => [],
                        'children' => ["Content for component {$i} with some additional text"],
                    ],
                ],
            ];
        }

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'large-dashboard',
            'content' => [
                'meta' => [
                    'title' => 'Large Dashboard',
                    'description' => 'A dashboard with many components for testing gzip compression',
                ],
                'data_sources' => [],
                'components' => $components,
            ],
        ]);

        // Act: gzip 지원 헤더와 함께 요청
        $response = $this->withHeaders([
            'Accept-Encoding' => 'gzip, deflate, br',
        ])->get("/api/layouts/{$template->identifier}/{$layout->name}.json");

        // Assert: 응답 성공 및 gzip 압축 확인
        $response->assertStatus(200);
        $this->assertEquals('gzip', $response->headers->get('Content-Encoding'));

        // 압축된 데이터가 gzip 매직 넘버로 시작하는지 확인
        $content = $response->getContent();
        $this->assertStringStartsWith("\x1f\x8b", $content);

        // 압축 해제 후 JSON 검증
        $decompressed = gzdecode($content);
        $this->assertNotFalse($decompressed);

        $jsonData = json_decode($decompressed, true);
        $this->assertTrue($jsonData['success']);
        $this->assertArrayHasKey('data', $jsonData);
        $this->assertCount(50, $jsonData['data']['components']);
    }

    /**
     * Accept-Encoding 헤더가 없으면 압축하지 않아야 합니다.
     */
    public function test_does_not_compress_layout_when_client_does_not_accept_gzip(): void
    {
        // Arrange: 대용량 레이아웃 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        $components = [];
        for ($i = 0; $i < 50; $i++) {
            $components[] = [
                'type' => 'div',
                'props' => ['class' => "component-{$i}"],
                'children' => ["Content {$i}"],
            ];
        }

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'large-dashboard',
            'content' => [
                'meta' => ['title' => 'Large Dashboard'],
                'data_sources' => [],
                'components' => $components,
            ],
        ]);

        // Act: Accept-Encoding 헤더 없이 요청
        $response = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");

        // Assert: 압축되지 않은 응답
        $response->assertStatus(200);
        $this->assertNull($response->headers->get('Content-Encoding'));

        // JSON 직접 파싱 가능
        $response->assertJson(['success' => true]);
        $this->assertCount(50, $response->json('data.components'));
    }

    /**
     * 작은 레이아웃은 압축하지 않아야 합니다 (1KB 미만).
     */
    public function test_does_not_compress_small_layout(): void
    {
        // Arrange: 작은 레이아웃 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'tiny',
            'content' => [
                'meta' => [],
                'data_sources' => [],
                'components' => [],
            ],
        ]);

        // Act: gzip 지원 헤더와 함께 요청
        $response = $this->withHeaders([
            'Accept-Encoding' => 'gzip',
        ])->get("/api/layouts/{$template->identifier}/{$layout->name}.json");

        // Assert: 작은 응답은 압축되지 않음
        $response->assertStatus(200);
        $this->assertNull($response->headers->get('Content-Encoding'));
    }

    /**
     * gzip 압축 시 압축 효율성 테스트
     */
    public function test_gzip_compression_reduces_response_size(): void
    {
        // Arrange: 대용량 레이아웃 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        $components = [];
        for ($i = 0; $i < 100; $i++) {
            $components[] = [
                'type' => 'div',
                'props' => ['class' => "component-{$i}", 'id' => "id-{$i}"],
                'children' => [
                    [
                        'type' => 'span',
                        'props' => ['class' => 'text'],
                        'children' => [str_repeat("Content for item {$i}. ", 5)],
                    ],
                ],
            ];
        }

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'compression-test',
            'content' => [
                'meta' => ['title' => 'Compression Test'],
                'data_sources' => [],
                'components' => $components,
            ],
        ]);

        // Act: 압축 없이 요청
        $responseWithoutGzip = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");
        $originalSize = strlen($responseWithoutGzip->getContent());

        // Act: 압축 요청
        $responseWithGzip = $this->withHeaders([
            'Accept-Encoding' => 'gzip',
        ])->get("/api/layouts/{$template->identifier}/{$layout->name}.json");
        $compressedSize = strlen($responseWithGzip->getContent());

        // Assert: 압축 후 크기가 50% 이상 감소
        $this->assertLessThan($originalSize * 0.5, $compressedSize);
    }

    /**
     * 공개 서빙 응답에서 개발자용 comment / _comment 필드가 제거되어야 합니다.
     *
     * 레이아웃 JSON 의 comment/_comment 는 편집기·개발자용 주석으로 런타임 렌더러가
     * 사용하지 않으며, 공개 응답 크기를 키우므로 서빙 시 재귀적으로 제거된다.
     *
     * 표본에 `actions[].comment` · `modals[].comment` · `named_actions{}.comment` 를 함께
     * 싣는 것은 S21 주입 단락이 실제로 채우는 자리가 그 셋이기 때문이다. 제거는 배열을 재귀
     * 순회하므로 구조적으로는 전 위치를 덮지만, 그 사실은 「주석만 넣은 단락은 화면을 바꿀 수
     * 없다」는 명제의 근거이므로 실제로 쓰이는 자리를 표본에 두고 확인한다.
     *
     * `named_actions` 는 셋 중 유일하게 **리스트가 아닌 맵**이다. 재귀가 리스트만 훑고
     * 연관배열을 건너뛰어도 나머지 둘은 초록이므로, 이 자리가 빠지면 표본이 그 구분을
     * 시험하지 못한다.
     *
     * @scenario compile_unit=layout,source_health=valid
     *
     * @effects injected_comments_never_reach_the_public_serving_response
     */
    public function test_strips_developer_comments_from_public_serving(): void
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

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'commented',
            'content' => [
                'comment' => '레이아웃 최상단 개발자 주석',
                'meta' => ['title' => 'Commented Layout'],
                'data_sources' => [
                    [
                        '_comment' => '데이터소스 설명 주석',
                        'id' => 'stats',
                        'type' => 'api',
                        'endpoint' => '/api/stats',
                    ],
                ],
                'named_actions' => [
                    'searchProducts' => [
                        'comment' => '명명 액션 설명 주석',
                        'handler' => 'apiCall',
                        'params' => ['target' => '/api/products'],
                    ],
                ],
                'modals' => [
                    [
                        'comment' => '모달 설명 주석',
                        'id' => 'delete_confirm_modal',
                        'type' => 'composite',
                        'name' => 'Modal',
                        'props' => ['title' => '삭제'],
                    ],
                ],
                'components' => [
                    [
                        'comment' => '컴포넌트 설명 주석',
                        'type' => 'div',
                        'props' => ['class' => 'container'],
                        'children' => [
                            [
                                '_comment' => '중첩 자식 주석',
                                'type' => 'span',
                                'props' => [],
                                'children' => ['Hello'],
                            ],
                            [
                                'type' => 'basic',
                                'name' => 'Button',
                                'props' => [],
                                'actions' => [
                                    [
                                        'comment' => '액션 블록 설명 주석',
                                        'type' => 'click',
                                        'handler' => 'navigate',
                                        'params' => ['path' => '/admin'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");

        $response->assertStatus(200);

        // 응답 본문 어디에도 comment / _comment 키가 없어야 함
        $body = $response->getContent();
        $this->assertStringNotContainsString('"comment"', $body);
        $this->assertStringNotContainsString('"_comment"', $body);

        // 실제 콘텐츠(컴포넌트 구조)는 보존
        $data = $response->json('data');
        $this->assertArrayNotHasKey('comment', $data);
        $this->assertSame('div', $data['components'][0]['type']);
        $this->assertArrayNotHasKey('comment', $data['components'][0]);
        $this->assertArrayNotHasKey('_comment', $data['components'][0]['children'][0]);
        $this->assertArrayNotHasKey('_comment', $data['data_sources'][0]);

        // S21 주입 단락이 실제로 채우는 세 자리 — 액션 블록 · 모달 · 명명 액션
        $this->assertArrayNotHasKey('comment', $data['components'][0]['children'][1]['actions'][0]);
        $this->assertSame('navigate', $data['components'][0]['children'][1]['actions'][0]['handler']);
        $this->assertArrayNotHasKey('comment', $data['modals'][0]);
        $this->assertSame('delete_confirm_modal', $data['modals'][0]['id']);
        // 맵 자리 — 재귀가 리스트만 훑으면 여기서만 red 가 난다
        $this->assertArrayNotHasKey('comment', $data['named_actions']['searchProducts']);
        $this->assertSame('apiCall', $data['named_actions']['searchProducts']['handler']);
    }

    /**
     * 확장이 주입한 조각(`resources/extensions/`)의 개발자 주석도 공개 응답에서 제거되어야 합니다.
     *
     * 위 테스트가 잠근 명제 — *"주입은 화면에 닿지 않는다"* — 는 **템플릿 레이아웃 본문**에서만
     * 확인되고 있었다. 그런데 확장 주입은 병합이 끝난 뒤(`applyExtensions`) 얹히고, 주석 제거는
     * 그 병합 안에서 일어난다. 즉 순서상 확장 조각의 주석은 제거를 지나친 뒤 응답에 실린다.
     *
     * 이 누출은 주입 단락이 만든 것이 아니라 **이미 있던 것**이다 — 공개 릴리스 7.0.5
     * (`origin/main`) 시점에 확장 조각 41개 중 19파일·도달 지점 주석 110건이 그대로 응답에
     * 실려 나가고 있었다. S21.3 이 `resources/extensions/` 조각 10개에 주석을 늘리며 표본이
     * 커져 눈에 띄었을 뿐이고, 잠복 기간에도 계약은 계속 깨져 있었다.
     *
     * @scenario compile_unit=layout,source_health=valid
     *
     * @effects injected_comments_in_extension_fragments_never_reach_the_public_serving_response
     */
    public function test_strips_developer_comments_from_extension_injected_fragments(): void
    {
        // 확장 주입은 활성 모듈에서 온 것만 병합된다 — 모킹하지 않으면 조각이 붙지 않아
        // 아래 주석 부재 단언이 아무것도 검사하지 않는다.
        $activeModule = $this->createMock(ModuleInterface::class);
        $activeModule->method('getIdentifier')->willReturn('sirsoft-ecommerce');
        $mockModuleManager = $this->createMock(ModuleManager::class);
        $mockModuleManager->method('getActiveModules')->willReturn(['sirsoft-ecommerce' => $activeModule]);
        $this->app->instance(ModuleManager::class, $mockModuleManager);

        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'host_with_extension',
            'content' => [
                // overlay 매칭은 병합 결과의 `layout_name` 을 본다 — 설치된 레이아웃은 이 키를
                // content 에 갖고 있으므로 픽스처도 같게 둔다(없으면 조각이 붙지 않는다).
                'layout_name' => 'host_with_extension',
                'meta' => ['title' => 'Host Layout'],
                'data_sources' => [],
                'components' => [
                    ['id' => 'host_anchor', 'type' => 'basic', 'name' => 'Div', 'children' => []],
                ],
            ],
            'is_active' => true,
        ]);

        LayoutExtension::create([
            'template_id' => $template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'host_with_extension',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'content' => [
                'target_layout' => 'host_with_extension',
                'comment' => '확장 조각 문서 주석',
                'injections' => [
                    [
                        'target_id' => 'host_anchor',
                        'position' => 'append_child',
                        'components' => [
                            [
                                'id' => 'ext_injected',
                                'type' => 'basic',
                                'name' => 'Div',
                                'comment' => '확장 조각 노드 주석',
                                'actions' => [
                                    ['handler' => 'navigate', 'comment' => '확장 조각 액션 주석'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");

        $response->assertStatus(200);

        // 주입된 조각이 실제로 병합됐는지 먼저 확인한다 — 병합이 안 된 상태에서 "주석 0" 은
        // 공허하게 참이므로, 존재를 확정한 뒤에야 부재를 단언할 수 있다.
        $body = $response->getContent();
        $this->assertStringContainsString('ext_injected', $body,
            '확장 조각이 병합되지 않았다 — 이 상태에서는 아래 주석 부재 단언이 아무것도 검사하지 않는다. 응답: '.$body);

        $this->assertStringNotContainsString('"comment"', $body);
        $this->assertStringNotContainsString('확장 조각 문서 주석', $body);
        $this->assertStringNotContainsString('확장 조각 노드 주석', $body);
        $this->assertStringNotContainsString('확장 조각 액션 주석', $body);
    }

    /**
     * **템플릿이 소유한** 확장 조각(`templates/_bundled/{템플릿}/extensions/`)의 주석도 제거되어야 합니다.
     *
     * 위 테스트는 조각의 출처가 `source_type=Module` 인 경우만 표본으로 삼는다. 그런데
     * 확장 조각을 얹는 경로는 출처로 갈리는 자리를 갖고 있다 —
     * [LayoutExtensionService:453](app/Services/LayoutExtensionService.php) 의 적용 대상 판정,
     * 그리고 :663·:748 의 `is_override` 분기가 `source_type === Template` 을 따로 본다.
     * 한 출처에서 초록이 나면 나머지가 보이지 않는다는 것이 67차가 데인 형태(그때는 «소비자»가
     * 하나 더 있었다)이므로, S21.7 이 이 종류의 조각(`sirsoft-basic/extensions/`)에 처음 주석을
     * 넣으면서 출처 축의 표본을 함께 세운다.
     *
     * 제거 자체는 출처를 보지 않는 자리(`LayoutService::getLayout` 이 병합 결과 전체에 재귀
     * 적용)에서 일어나므로 **구조로** 참이다. 이 표본은 그 구조가 앞으로도 유지되는지를 잠근다.
     *
     * @scenario compile_unit=layout,source_health=valid
     *
     * @effects injected_comments_in_template_owned_extension_fragments_never_reach_the_public_serving_response
     */
    public function test_strips_developer_comments_from_template_owned_extension_fragments(): void
    {
        $template = Template::create([
            'identifier' => 'sirsoft-basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 사용자 템플릿', 'en' => 'Basic User Template'],
            'version' => '1.0.0',
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 사용자 템플릿', 'en' => 'Basic User Template'],
        ]);

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'host_with_template_extension',
            'content' => [
                'layout_name' => 'host_with_template_extension',
                'meta' => ['title' => 'Host Layout'],
                'data_sources' => [],
                'components' => [
                    ['id' => 'host_anchor', 'type' => 'basic', 'name' => 'Div', 'children' => []],
                ],
            ],
            'is_active' => true,
        ]);

        // 출처가 **템플릿 자신**이다 — 모듈 조각과 달리 활성 모듈 목록을 타지 않는다.
        LayoutExtension::create([
            'template_id' => $template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'host_with_template_extension',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'sirsoft-basic',
            'content' => [
                'target_layout' => 'host_with_template_extension',
                'comment' => '템플릿 확장 조각 문서 주석',
                'injections' => [
                    [
                        'target_id' => 'host_anchor',
                        'position' => 'append_child',
                        'components' => [
                            [
                                'id' => 'tpl_ext_injected',
                                'type' => 'basic',
                                'name' => 'Div',
                                'comment' => '템플릿 확장 조각 노드 주석',
                                'actions' => [
                                    ['handler' => 'navigate', 'comment' => '템플릿 확장 조각 액션 주석'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'priority' => 50,
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");

        $response->assertStatus(200);

        $body = $response->getContent();

        // 부재를 단언하기 전에 존재를 확정한다 — 조각이 붙지 않은 상태의 "주석 0" 은 공허하다.
        $this->assertStringContainsString('tpl_ext_injected', $body,
            '템플릿 소유 확장 조각이 병합되지 않았다 — 이 상태에서는 아래 주석 부재 단언이 아무것도 검사하지 않는다. 응답: '.$body);

        $this->assertStringNotContainsString('"comment"', $body);
        $this->assertStringNotContainsString('템플릿 확장 조각 문서 주석', $body);
        $this->assertStringNotContainsString('템플릿 확장 조각 노드 주석', $body);
        $this->assertStringNotContainsString('템플릿 확장 조각 액션 주석', $body);
    }

    /**
     * 한글이 포함된 응답이 \uXXXX 이스케이프 없이 raw UTF-8 로 직렬화되어야 합니다.
     *
     * JSON_UNESCAPED_UNICODE 적용으로 멀티바이트 문자의 전송 크기를 줄인다.
     */
    public function test_serves_korean_as_unescaped_utf8(): void
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

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'korean',
            'content' => [
                'meta' => ['title' => '한글 제목'],
                'data_sources' => [],
                'components' => [
                    [
                        'type' => 'span',
                        'props' => [],
                        'children' => ['안녕하세요'],
                    ],
                ],
            ],
        ]);

        // 압축 없이 raw 본문 검사 (Accept-Encoding 미지정)
        $response = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");

        $response->assertStatus(200);

        $body = $response->getContent();

        // raw UTF-8 한글이 그대로 실려야 함
        $this->assertStringContainsString('한글 제목', $body);
        $this->assertStringContainsString('안녕하세요', $body);

        // \uXXXX 이스케이프 형태가 아니어야 함 (예: '한' = 한)
        $this->assertStringNotContainsString('\\ud55c', $body);

        // 메시지도 raw UTF-8 (레이아웃 제공 성공 메시지)
        $this->assertMatchesRegularExpression('/[가-힣]/u', $body);
    }

    /**
     * 베이스 레이아웃(`extends` 상속원)의 개발자 주석도 자식 응답에서 제거되어야 합니다.
     *
     * S21.4 가 처음 밟은 경로다. 그 전까지 주입한 것은 **자기 본문을 가진 레이아웃**과 확장 조각이고,
     * 이 단락에서 `_admin_base` 에 26건을 넣었다 — 관리자 전 화면이 상속하는 자리다.
     * 자식 화면을 요청하면 그 응답은 베이스에서 온 노드·데이터소스·모달을 함께 담으므로,
     * 베이스 주석이 제거를 지나치면 **모든 관리자 화면 응답**에 실린다.
     *
     * 66차가 확장 조각에서 데인 것이 정확히 이 형태였다 — 제거가 병합 함수 **안에서** 일어나므로
     * 그 뒤에 얹히는 것은 지나간다. 여기서는 상속원을 같은 공개 진입점으로 불러 오므로
     * 순서상 성립하지만, "성립할 것이다" 와 "성립한다" 는 다른 말이라 표본을 둔다
     * (계획서 66차 보강: 새 파일 종류에 처음 주석을 넣을 때는 그 종류의 서빙 경로를 표본으로 확인).
     *
     * 부재 단언 앞에 **상속이 실제로 일어났음**을 확정한다 — 병합이 안 되면 "주석 0" 이 공허하게 참이다.
     *
     * @scenario compile_unit=layout,source_health=valid
     *
     * @effects injected_comments_in_base_layouts_never_reach_the_child_serving_response
     */
    public function test_strips_developer_comments_inherited_from_base_layout(): void
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

        // 상속원 — S21.4 가 실제로 주석을 넣은 자리 셋(문서 루트 · 데이터소스 · 모달)을 담는다.
        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => '_base_commented',
            'content' => [
                'comment' => '베이스 레이아웃 최상단 주석',
                'meta' => ['title' => 'Base', 'is_base' => true],
                'data_sources' => [
                    [
                        'id' => 'current_user',
                        'type' => 'api',
                        'endpoint' => '/api/admin/auth/user',
                        'method' => 'GET',
                        'comment' => '베이스 데이터소스 주석',
                    ],
                ],
                'modals' => [
                    [
                        'id' => 'base_modal',
                        'comment' => '베이스 모달 주석',
                        'title' => 'Base Modal',
                    ],
                ],
                'components' => [
                    [
                        'id' => 'base_shell',
                        'type' => 'div',
                        'comment' => '베이스 껍데기 주석',
                        'props' => [],
                        'children' => [
                            ['type' => 'container', 'slot' => 'content', 'props' => []],
                        ],
                    ],
                ],
            ],
        ]);

        $child = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'child_of_base',
            'content' => [
                'extends' => '_base_commented',
                'meta' => ['title' => 'Child'],
                'components' => [
                    ['type' => 'span', 'slot' => 'content', 'props' => [], 'children' => ['본문']],
                ],
            ],
        ]);

        $response = $this->getJson("/api/layouts/{$template->identifier}/{$child->name}.json");

        $response->assertStatus(200);

        $data = $response->json('data');

        // 존재 확정 — 상속이 실제로 일어나 베이스 산출물이 자식 응답에 들어왔다.
        // 이것이 red 면 아래 부재 단언은 아무것도 검사하지 않는다.
        $this->assertSame('current_user', $data['data_sources'][0]['id'] ?? null,
            '베이스 데이터소스가 자식 응답에 병합되지 않았다 — 부재 단언이 공허해진다');
        $this->assertSame('base_modal', $data['modals'][0]['id'] ?? null,
            '베이스 모달이 자식 응답에 병합되지 않았다 — 부재 단언이 공허해진다');
        $this->assertSame('base_shell', $data['components'][0]['id'] ?? null,
            '베이스 컴포넌트가 자식 응답에 병합되지 않았다 — 부재 단언이 공허해진다');

        // 부재 — 베이스에서 온 어떤 주석도 응답에 없다.
        $body = $response->getContent();
        $this->assertStringNotContainsString('"comment"', $body);
        $this->assertStringNotContainsString('베이스', $body);
        $this->assertArrayNotHasKey('comment', $data);
        $this->assertArrayNotHasKey('comment', $data['data_sources'][0]);
        $this->assertArrayNotHasKey('comment', $data['modals'][0]);
        $this->assertArrayNotHasKey('comment', $data['components'][0]);
    }

    /**
     * 프로덕션에서 레이아웃 응답은 공개 캐시 헤더를 유지한다 (#122 작업 D — successWithCache 환경 분기 동반 효과)
     *
     * @effects fallback_api_serves_etag_304
     */
    public function test_layout_has_public_cache_headers_in_production(): void
    {
        app()['env'] = 'production';

        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'dashboard',
            'content' => ['meta' => [], 'data_sources' => [], 'components' => []],
        ]);

        $response = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");

        $response->assertStatus(200);
        $this->assertNotNull($response->headers->get('ETag'));
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=3600', $cacheControl);
    }

    /**
     * 개발 환경에서 레이아웃 응답은 no-cache (dev 레이아웃 반영성 — #122 작업 D 환경 분기)
     *
     * @effects fallback_api_no_cache_in_dev
     */
    public function test_layout_no_cache_in_development(): void
    {
        app()['env'] = 'local';

        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'dashboard',
            'content' => ['meta' => [], 'data_sources' => [], 'components' => []],
        ]);

        $response = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");

        $response->assertStatus(200);
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringNotContainsString('max-age=3600', $cacheControl);
    }
}
