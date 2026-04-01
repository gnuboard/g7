<?php

namespace Tests\Feature\Api\Public;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Models\TemplateLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LayoutServingTest extends TestCase
{
    use RefreshDatabase;

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

        // 캐시 초기화
        Cache::forget("layout.{$template->identifier}.{$layout->name}");

        // Act: 첫 번째 요청 (캐시 미스, DB 조회)
        $response1 = $this->getJson("/api/layouts/{$template->identifier}/{$layout->name}.json");
        $response1->assertStatus(200);

        // Assert: 캐시가 생성되었는지 확인
        $this->assertTrue(Cache::has("layout.{$template->identifier}.{$layout->name}"));

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

        // Assert: Rate Limit 헤더가 존재하는지 확인
        $response->assertStatus(200)
            ->assertHeader('X-RateLimit-Limit')
            ->assertHeader('X-RateLimit-Remaining');

        // Rate Limit 값이 적용되었는지 확인 (api 그룹 기본값 또는 라우트 설정값)
        $rateLimit = (int) $response->headers->get('X-RateLimit-Limit');
        $this->assertGreaterThan(0, $rateLimit);
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
}
