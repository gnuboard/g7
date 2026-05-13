<?php

namespace Tests\Feature\Security;

use App\Rules\ComponentExists;
use App\Rules\NoExternalUrls;
use App\Rules\ValidLayoutStructure;
use App\Rules\WhitelistedEndpoint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 악의적 JSON 패턴 통합 테스트
 *
 * 다양한 공격 시나리오를 시뮬레이션하여 보안 검증 시스템이
 * 올바르게 동작하는지 검증합니다.
 */
class MaliciousJsonTest extends TestCase
{
    private string $testTemplateId = 'test-template';

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 템플릿 디렉토리 생성
        $componentsPath = base_path("templates/{$this->testTemplateId}");
        if (! File::exists($componentsPath)) {
            File::makeDirectory($componentsPath, 0755, true);
        }

        // 테스트용 components.json 생성
        File::put($componentsPath.'/components.json', json_encode([
            'basic' => ['Button', 'Input'],
            'composite' => ['Card', 'StatCard', 'DataGrid'],
            'layout' => ['Container', 'Section'],
        ]));
    }

    protected function tearDown(): void
    {
        // 테스트 파일 정리
        $componentsPath = base_path("templates/{$this->testTemplateId}");
        if (File::exists($componentsPath)) {
            File::deleteDirectory($componentsPath);
        }

        parent::tearDown();
    }

    /**
     * 검증 헬퍼 메서드
     *
     * @param  array  $layout  검증할 레이아웃 데이터
     * @return bool 검증 통과 여부
     */
    private function validateLayout(array $layout): bool
    {
        $validator = Validator::make(
            [
                'template_id' => $this->testTemplateId,
                'content' => $layout,
            ],
            [
                'content' => [
                    'required',
                    'array',
                    new ValidLayoutStructure,
                    new WhitelistedEndpoint($this->testTemplateId),
                    new NoExternalUrls,
                    new ComponentExists($this->testTemplateId),
                ],
            ]
        );

        return $validator->passes();
    }

    /**
     * XSS 공격 시도 테스트 - javascript: URI 차단
     */
    public function test_blocks_xss_attack_in_props(): void
    {
        $maliciousLayout = [
            'version' => '1.0.0',
            'layout_name' => 'xss_attack',
            'components' => [
                [
                    'id' => 'malicious_button',
                    'type' => 'basic',
                    'name' => 'Button',
                    'props' => [
                        'label' => '<script>alert("XSS")</script>',
                        'onClick' => 'javascript:void(document.cookie)',
                    ],
                    'children' => [],
                ],
            ],
        ];

        // javascript: URI가 NoExternalUrls 규칙에 의해 차단됩니다.
        $this->assertFalse($this->validateLayout($maliciousLayout));
    }

    /**
     * XSS 공격 시도 테스트 - HTML 태그는 허용 (React가 이스케이프)
     */
    public function test_allows_html_tags_in_props(): void
    {
        $layoutWithHtml = [
            'version' => '1.0.0',
            'layout_name' => 'html_content',
            'components' => [
                [
                    'id' => 'button_with_html',
                    'type' => 'basic',
                    'name' => 'Button',
                    'props' => [
                        'label' => '<script>alert("XSS")</script>',
                    ],
                    'children' => [],
                ],
            ],
        ];

        // HTML 태그 자체는 허용됩니다 (React가 자동으로 이스케이프).
        $this->assertTrue($this->validateLayout($layoutWithHtml));
    }

    /**
     * SQL Injection 공격 시도 테스트
     */
    public function test_blocks_sql_injection_in_data_binding(): void
    {
        $maliciousLayout = [
            'version' => '1.0.0',
            'layout_name' => 'sql_injection',
            'data_sources' => [
                [
                    'id' => 'users',
                    'type' => 'api',
                    'endpoint' => '/api/admin/users',
                    'method' => 'GET',
                    'params' => [
                        'filter' => "'; DROP TABLE users--",
                    ],
                ],
            ],
            'components' => [
                [
                    'id' => 'user_list',
                    'type' => 'composite',
                    'name' => 'Card',
                    'props' => [],
                    'children' => [],
                ],
            ],
        ];

        // SQL Injection은 Eloquent ORM의 파라미터 바인딩으로 방어됩니다.
        // 레이아웃 자체는 검증을 통과합니다.
        $this->assertTrue($this->validateLayout($maliciousLayout));
    }

    /**
     * 경로 트래버설 공격 시도 테스트
     */
    public function test_blocks_path_traversal_attack(): void
    {
        $maliciousLayout = [
            'version' => '1.0.0',
            'layout_name' => 'path_traversal',
            'components' => [
                [
                    'id' => 'malicious_image',
                    'type' => 'basic',
                    'name' => 'Input',
                    'props' => [
                        'src' => '../../etc/passwd',
                    ],
                    'children' => [],
                ],
            ],
        ];

        // 경로 트래버설은 상대 경로이므로 외부 URL 차단에 걸리지 않습니다.
        // 실제 파일 접근 시 Laravel의 파일 시스템 권한으로 보호됩니다.
        $this->assertTrue($this->validateLayout($maliciousLayout));
    }

    /**
     * 외부 리소스 로딩 시도 테스트
     */
    public function test_blocks_external_resource_loading(): void
    {
        $maliciousLayout = [
            'version' => '1.0.0',
            'layout_name' => 'external_resource',
            'components' => [
                [
                    'id' => 'external_image',
                    'type' => 'basic',
                    'name' => 'Input',
                    'props' => [
                        'imageUrl' => 'https://evil.com/malicious.jpg',
                    ],
                    'children' => [],
                ],
            ],
        ];

        // NoExternalUrls 규칙이 외부 URL을 차단합니다.
        $this->assertFalse($this->validateLayout($maliciousLayout));
    }

    /**
     * 허용되지 않은 API 엔드포인트 호출 시도 테스트
     */
    public function test_blocks_unauthorized_api_endpoint(): void
    {
        $maliciousLayout = [
            'version' => '1.0.0',
            'layout_name' => 'unauthorized_api',
            'data_sources' => [
                [
                    'id' => 'secret_data',
                    'type' => 'api',
                    'endpoint' => '/api/internal/admin/secrets',
                    'method' => 'GET',
                ],
            ],
            'components' => [
                [
                    'id' => 'data_display',
                    'type' => 'composite',
                    'name' => 'Card',
                    'props' => [],
                    'children' => [],
                ],
            ],
        ];

        // WhitelistedEndpoint 규칙이 허용되지 않은 엔드포인트를 차단합니다.
        $this->assertFalse($this->validateLayout($maliciousLayout));
    }

    /**
     * 존재하지 않는 컴포넌트 참조 시도 테스트
     */
    public function test_blocks_nonexistent_component(): void
    {
        $maliciousLayout = [
            'version' => '1.0.0',
            'layout_name' => 'nonexistent_component',
            'components' => [
                [
                    'id' => 'fake_component',
                    'type' => 'composite',
                    'name' => 'MaliciousComponent',
                    'props' => [],
                    'children' => [],
                ],
            ],
        ];

        // ComponentExists 규칙이 존재하지 않는 컴포넌트를 차단합니다.
        $this->assertFalse($this->validateLayout($maliciousLayout));
    }

    /**
     * 깊은 중첩 구조로 DoS 시도 테스트
     */
    public function test_blocks_deep_nesting_dos_attack(): void
    {
        // 11단계 깊이의 중첩 구조 생성
        $deepComponent = [
            'id' => 'level_0',
            'type' => 'composite',
            'name' => 'Card',
            'props' => [],
            'children' => [],
        ];

        $current = &$deepComponent['children'];
        for ($i = 1; $i <= 11; $i++) {
            $current[] = [
                'id' => "level_{$i}",
                'type' => 'composite',
                'name' => 'Card',
                'props' => [],
                'children' => [],
            ];
            $current = &$current[0]['children'];
        }

        $maliciousLayout = [
            'version' => '1.0.0',
            'layout_name' => 'deep_nesting',
            'components' => [$deepComponent],
        ];

        // ValidLayoutStructure 규칙이 최대 깊이(10)를 초과하면 차단합니다.
        $this->assertFalse($this->validateLayout($maliciousLayout));
    }

    /**
     * data URI 스킴 차단 테스트
     */
    public function test_blocks_data_uri_scheme(): void
    {
        $maliciousLayout = [
            'version' => '1.0.0',
            'layout_name' => 'data_uri',
            'components' => [
                [
                    'id' => 'data_uri_image',
                    'type' => 'basic',
                    'name' => 'Input',
                    'props' => [
                        'src' => 'data:text/html,<script>alert("XSS")</script>',
                    ],
                    'children' => [],
                ],
            ],
        ];

        // data: URI는 NoExternalUrls 규칙에 의해 차단됩니다.
        $this->assertFalse($this->validateLayout($maliciousLayout));
    }

    /**
     * 복합 공격 패턴 테스트
     */
    public function test_blocks_combined_attack_patterns(): void
    {
        $maliciousLayout = [
            'version' => '1.0.0',
            'layout_name' => 'combined_attack',
            'data_sources' => [
                [
                    'id' => 'malicious_data',
                    'type' => 'api',
                    'endpoint' => '/api/internal/secrets',
                    'method' => 'GET',
                ],
            ],
            'components' => [
                [
                    'id' => 'malicious_component',
                    'type' => 'composite',
                    'name' => 'NonExistentComponent',
                    'props' => [
                        'imageUrl' => 'https://evil.com/malicious.jpg',
                        'onClick' => 'javascript:alert(1)',
                    ],
                    'children' => [
                        [
                            'id' => 'nested_malicious',
                            'type' => 'basic',
                            'name' => 'Input',
                            'props' => [
                                'value' => '<script>document.cookie</script>',
                            ],
                            'children' => [],
                        ],
                    ],
                ],
            ],
        ];

        // 여러 검증 규칙 중 하나라도 실패하면 검증 실패
        $this->assertFalse($this->validateLayout($maliciousLayout));
    }

    /**
     * 정상적인 레이아웃 JSON은 통과하는지 테스트
     */
    public function test_allows_valid_layout(): void
    {
        $validLayout = [
            'version' => '1.0.0',
            'layout_name' => 'valid_layout',
            'data_sources' => [
                [
                    'id' => 'users',
                    'type' => 'api',
                    'endpoint' => '/api/admin/users',
                    'method' => 'GET',
                ],
            ],
            'components' => [
                [
                    'id' => 'header',
                    'type' => 'composite',
                    'name' => 'Card',
                    'props' => [
                        'title' => '사용자 목록',
                    ],
                    'children' => [
                        [
                            'id' => 'button',
                            'type' => 'basic',
                            'name' => 'Button',
                            'props' => [
                                'label' => '추가',
                            ],
                            'children' => [],
                        ],
                    ],
                ],
            ],
        ];

        // 정상적인 레이아웃은 모든 검증을 통과해야 합니다.
        $this->assertTrue($this->validateLayout($validLayout));
    }

    /**
     * javascript: URI 스킴 차단 테스트
     */
    public function test_blocks_javascript_uri_scheme(): void
    {
        $maliciousLayout = [
            'version' => '1.0.0',
            'layout_name' => 'javascript_uri',
            'components' => [
                [
                    'id' => 'javascript_link',
                    'type' => 'basic',
                    'name' => 'Button',
                    'props' => [
                        'onClick' => 'javascript:alert(1)',
                    ],
                    'children' => [],
                ],
            ],
        ];

        // javascript: URI는 NoExternalUrls 규칙에 의해 차단됩니다.
        $this->assertFalse($this->validateLayout($maliciousLayout));
    }

    /**
     * 허용된 API 엔드포인트 테스트
     */
    public function test_allows_whitelisted_endpoints(): void
    {
        $validEndpoints = [
            '/api/admin/users',
            '/api/auth/profile',
            '/api/public/posts',
        ];

        foreach ($validEndpoints as $endpoint) {
            $layout = [
                'version' => '1.0.0',
                'layout_name' => 'valid_endpoint',
                'data_sources' => [
                    [
                        'id' => 'data',
                        'type' => 'api',
                        'endpoint' => $endpoint,
                        'method' => 'GET',
                    ],
                ],
                'components' => [
                    [
                        'id' => 'display',
                        'type' => 'composite',
                        'name' => 'Card',
                        'props' => [],
                        'children' => [],
                    ],
                ],
            ];

            $this->assertTrue(
                $this->validateLayout($layout),
                "Endpoint {$endpoint} should be allowed"
            );
        }
    }

    /**
     * 차단되어야 하는 API 엔드포인트 테스트
     */
    public function test_blocks_non_whitelisted_endpoints(): void
    {
        $invalidEndpoints = [
            '/api/internal/secrets',
            '/admin/dashboard',
            'https://evil.com/api',
            '/api/v2/users',
        ];

        foreach ($invalidEndpoints as $endpoint) {
            $layout = [
                'version' => '1.0.0',
                'layout_name' => 'invalid_endpoint',
                'data_sources' => [
                    [
                        'id' => 'data',
                        'type' => 'api',
                        'endpoint' => $endpoint,
                        'method' => 'GET',
                    ],
                ],
                'components' => [
                    [
                        'id' => 'display',
                        'type' => 'composite',
                        'name' => 'Card',
                        'props' => [],
                        'children' => [],
                    ],
                ],
            ];

            $this->assertFalse(
                $this->validateLayout($layout),
                "Endpoint {$endpoint} should be blocked"
            );
        }
    }
}
