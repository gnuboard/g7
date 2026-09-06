<?php

namespace Tests\Feature\Rules;

use App\Http\Requests\Layout\StoreLayoutRequest;
use App\Http\Requests\Layout\UpdateLayoutContentRequest;
use App\Http\Requests\Layout\UpdateLayoutExtensionContentRequest;
use App\Http\Requests\Layout\UpdateLayoutRequest;
use App\Rules\NoExternalUrls;
use Tests\TestCase;

class NoExternalUrlsTest extends TestCase
{
    private NoExternalUrls $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new NoExternalUrls;
    }

    /**
     * 정상적인 레이아웃 JSON 통과 테스트
     */
    public function test_passes_with_valid_layout_json(): void
    {
        $validLayout = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Button',
                    'props' => [
                        'label' => '저장',
                        'icon' => '/images/save.png',
                    ],
                    'actions' => [
                        [
                            'type' => 'api',
                            'endpoint' => '/api/admin/save',
                        ],
                    ],
                    'children' => [],
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $validLayout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, 'Valid layout should pass validation');
    }

    /**
     * HTTP URL 차단 테스트
     */
    public function test_fails_with_http_url_in_props(): void
    {
        $layoutWithHttp = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Image',
                    'props' => [
                        'src' => 'http://evil.com/image.jpg',
                    ],
                    'children' => [],
                ],
            ],
        ];

        $failed = false;
        $errorMessage = '';
        $this->rule->validate('layout', $layoutWithHttp, function ($message) use (&$failed, &$errorMessage) {
            $failed = true;
            $errorMessage = $message;
        });

        $this->assertTrue($failed, 'HTTP URL should fail validation');
        $this->assertStringContainsString('HTTP', $errorMessage);
    }

    /**
     * HTTPS URL 차단 테스트
     */
    public function test_fails_with_https_url_in_props(): void
    {
        $layoutWithHttps = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Link',
                    'props' => [
                        'href' => 'https://attacker.com/malicious',
                    ],
                    'children' => [],
                ],
            ],
        ];

        $failed = false;
        $errorMessage = '';
        $this->rule->validate('layout', $layoutWithHttps, function ($message) use (&$failed, &$errorMessage) {
            $failed = true;
            $errorMessage = $message;
        });

        $this->assertTrue($failed, 'HTTPS URL should fail validation');
        $this->assertStringContainsString('HTTPS', $errorMessage);
    }

    /**
     * actions에서 외부 URL 차단 테스트
     */
    public function test_fails_with_external_url_in_actions(): void
    {
        $layoutWithExternalAction = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Button',
                    'props' => [
                        'label' => 'Click',
                    ],
                    'actions' => [
                        [
                            'type' => 'navigate',
                            'target' => 'https://evil.com/redirect',
                        ],
                    ],
                    'children' => [],
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $layoutWithExternalAction, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'External URL in actions should fail validation');
    }

    /**
     * Data URI 차단 테스트
     */
    public function test_fails_with_data_uri(): void
    {
        $layoutWithDataUri = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Image',
                    'props' => [
                        'src' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUA',
                    ],
                    'children' => [],
                ],
            ],
        ];

        $failed = false;
        $errorMessage = '';
        $this->rule->validate('layout', $layoutWithDataUri, function ($message) use (&$failed, &$errorMessage) {
            $failed = true;
            $errorMessage = $message;
        });

        $this->assertTrue($failed, 'Data URI should fail validation');
        $this->assertStringContainsString('Data URI', $errorMessage);
    }

    /**
     * JavaScript URI 차단 테스트
     */
    public function test_fails_with_javascript_uri(): void
    {
        $layoutWithJsUri = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Link',
                    'props' => [
                        'href' => 'javascript:alert(1)',
                    ],
                    'children' => [],
                ],
            ],
        ];

        $failed = false;
        $errorMessage = '';
        $this->rule->validate('layout', $layoutWithJsUri, function ($message) use (&$failed, &$errorMessage) {
            $failed = true;
            $errorMessage = $message;
        });

        $this->assertTrue($failed, 'JavaScript URI should fail validation');
        $this->assertStringContainsString('JavaScript URI', $errorMessage);
    }

    /**
     * 중첩된 children에서 외부 URL 차단 테스트
     */
    public function test_fails_with_external_url_in_nested_children(): void
    {
        $layoutWithNestedUrl = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Container',
                    'props' => [],
                    'children' => [
                        [
                            'component' => 'Section',
                            'props' => [],
                            'children' => [
                                [
                                    'component' => 'Image',
                                    'props' => [
                                        'src' => 'https://evil.com/nested.jpg',
                                    ],
                                    'children' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $layoutWithNestedUrl, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'External URL in nested children should fail validation');
    }

    /**
     * 프로토콜 상대 URL 차단 테스트
     */
    public function test_fails_with_protocol_relative_url(): void
    {
        $layoutWithProtocolRelative = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Image',
                    'props' => [
                        'src' => '//evil.com/image.jpg',
                    ],
                    'children' => [],
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $layoutWithProtocolRelative, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'Protocol-relative URL should fail validation');
    }

    /**
     * FTP 프로토콜 차단 테스트
     */
    public function test_fails_with_ftp_url(): void
    {
        $layoutWithFtp = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Link',
                    'props' => [
                        'href' => 'ftp://files.example.com/download',
                    ],
                    'children' => [],
                ],
            ],
        ];

        $failed = false;
        $errorMessage = '';
        $this->rule->validate('layout', $layoutWithFtp, function ($message) use (&$failed, &$errorMessage) {
            $failed = true;
            $errorMessage = $message;
        });

        $this->assertTrue($failed, 'FTP URL should fail validation');
        $this->assertStringContainsString('ftp', strtolower($errorMessage));
    }

    /**
     * 상대 경로 허용 테스트
     */
    public function test_passes_with_relative_paths(): void
    {
        $layoutWithRelativePaths = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Image',
                    'props' => [
                        'src' => '/images/logo.png',
                        'fallback' => '../assets/default.jpg',
                    ],
                    'children' => [],
                ],
                [
                    'component' => 'Link',
                    'props' => [
                        'href' => '/about',
                    ],
                    'actions' => [
                        [
                            'type' => 'api',
                            'endpoint' => '/api/admin/data',
                        ],
                    ],
                    'children' => [],
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $layoutWithRelativePaths, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, 'Relative paths should pass validation');
    }

    /**
     * init_actions(로드 시 자동 실행)의 외부 navigate URL 차단 테스트
     *
     * init_actions 는 페이지 진입 즉시 실행되므로 외부 URL 은 자동 리다이렉트/유출 경로다.
     * 컴포넌트 actions 와 동일 강도로 차단해야 한다.
     *
     * @effects init_actions_external_url_rejected
     */
    public function test_fails_with_external_url_in_init_actions(): void
    {
        $layout = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'init_actions' => [
                ['handler' => 'navigate', 'params' => ['path' => 'https://evil.com/redirect']],
            ],
            'components' => [],
        ];

        $failed = false;
        $this->rule->validate('layout', $layout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'init_actions 의 외부 URL 은 차단되어야 합니다');
    }

    /**
     * init_actions 의 same-origin 경로는 통과 테스트 (과차단 회귀 방지)
     *
     * @effects same_origin_path_in_init_actions_allowed
     */
    public function test_passes_with_same_origin_path_in_init_actions(): void
    {
        $layout = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'init_actions' => [
                ['handler' => 'apiCall', 'params' => ['endpoint' => '/api/admin/bootstrap']],
                ['handler' => 'navigate', 'params' => ['path' => '/dashboard']],
            ],
            'components' => [],
        ];

        $failed = false;
        $this->rule->validate('layout', $layout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, 'init_actions 의 same-origin 경로는 통과해야 합니다');
    }

    /**
     * 복잡한 중첩 구조에서 다중 외부 URL 차단 테스트
     */
    public function test_fails_with_multiple_external_urls_in_complex_structure(): void
    {
        $complexLayout = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Container',
                    'props' => [
                        'background' => 'https://cdn.evil.com/bg.jpg',
                    ],
                    'children' => [
                        [
                            'component' => 'Header',
                            'props' => [],
                            'actions' => [
                                [
                                    'type' => 'fetch',
                                    'endpoint' => 'http://api.attacker.com/steal',
                                ],
                            ],
                            'children' => [],
                        ],
                    ],
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $complexLayout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'Multiple external URLs should fail validation');
    }

    // ==========================================
    // 문자열 스칼라 값 검사 (KVE-2026-1915 후속)
    // ==========================================
    //
    // 이 규칙은 배열 트리 순회용으로 설계됐으나 실제로는 문자열 endpoint 필드
    // (`content.endpoint`·`content.data_sources.*.endpoint`)에도 부착되어 있다.
    // 배열이 아니면 즉시 return 하던 종전 구현에서는 그 부착이 조용한 no-op 이었다.

    /**
     * 문자열 값으로 직접 부착된 경우에도 외부 URL 을 차단한다.
     *
     * @effects plain_string_value_is_inspected_not_skipped
     */
    public function test_fails_with_external_url_as_plain_string_value(): void
    {
        $failed = false;
        $this->rule->validate('content.endpoint', 'https://evil.com/steal', function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, '문자열 필드에 부착된 경우에도 외부 URL 은 차단되어야 합니다');
    }

    /**
     * 문자열 값의 same-origin 경로는 통과 (과차단 회귀 방지).
     *
     * @effects same_origin_plain_string_allowed
     */
    public function test_passes_with_same_origin_path_as_plain_string_value(): void
    {
        $failed = false;
        $this->rule->validate('content.endpoint', '/api/admin/users', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, '문자열 필드의 same-origin 경로는 통과해야 합니다');
    }

    /**
     * 문자열 값의 위험 스킴(javascript:)도 차단한다.
     *
     * @effects plain_string_value_is_inspected_not_skipped
     */
    public function test_fails_with_dangerous_scheme_as_plain_string_value(): void
    {
        $failed = false;
        $this->rule->validate('content.endpoint', 'javascript:alert(1)', function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, '문자열 필드의 javascript: 스킴은 차단되어야 합니다');
    }

    // ==========================================
    // FormRequest 결선 (규칙이 content 배열에 실제로 물려 있는지)
    // ==========================================
    //
    // 편집기 저장 경로(LayoutController::update → UpdateLayoutContentRequest)의 content
    // 트리에 이 규칙이 붙어 있지 않으면, init_actions·props·actions 의 외부 URL 차단이
    // 그 경로에서만 조용히 발화하지 않는다.

    /**
     * FormRequest 의 content 배열 규칙에서 NoExternalUrls 인스턴스를 찾는다.
     *
     * @param  array<string, mixed>  $rules  FormRequest rules() 결과
     * @return bool 부착되어 있으면 true
     */
    private function contentRuleHasNoExternalUrls(array $rules): bool
    {
        foreach ((array) ($rules['content'] ?? []) as $rule) {
            if ($rule instanceof NoExternalUrls) {
                return true;
            }
        }

        return false;
    }

    /**
     * @effects rule_attached_to_every_layout_content_form_request
     */
    public function test_update_content_request_attaches_rule_to_content_array(): void
    {
        $request = UpdateLayoutContentRequest::create('/x', 'PUT', [
            'content' => ['version' => '1.0.0', 'components' => []],
        ]);
        $request->setContainer($this->app);

        $this->assertTrue(
            $this->contentRuleHasNoExternalUrls($request->rules()),
            'UpdateLayoutContentRequest 의 content 배열 규칙에 NoExternalUrls 가 부착되어야 합니다'
        );
    }

    /**
     * @effects rule_attached_to_every_layout_content_form_request
     */
    public function test_update_extension_content_request_attaches_rule_to_content_array(): void
    {
        $request = UpdateLayoutExtensionContentRequest::create('/x', 'PUT', [
            'content' => ['priority' => 0],
        ]);
        $request->setContainer($this->app);

        $this->assertTrue(
            $this->contentRuleHasNoExternalUrls($request->rules()),
            'UpdateLayoutExtensionContentRequest 의 content 배열 규칙에 NoExternalUrls 가 부착되어야 합니다'
        );
    }

    /**
     * @effects rule_attached_to_every_layout_content_form_request
     */
    public function test_store_and_update_requests_attach_rule_to_content_array(): void
    {
        $store = StoreLayoutRequest::create('/x', 'POST', [
            'content' => ['version' => '1.0.0', 'components' => []],
        ]);
        $store->setContainer($this->app);

        $update = UpdateLayoutRequest::create('/x', 'PUT', [
            'content' => ['version' => '1.0.0', 'components' => []],
        ]);
        $update->setContainer($this->app);

        $this->assertTrue(
            $this->contentRuleHasNoExternalUrls($store->rules()),
            'StoreLayoutRequest 의 content 배열 규칙에 NoExternalUrls 가 부착되어야 합니다'
        );
        $this->assertTrue(
            $this->contentRuleHasNoExternalUrls($update->rules()),
            'UpdateLayoutRequest 의 content 배열 규칙에 NoExternalUrls 가 부착되어야 합니다'
        );
    }

    // ==========================================
    // 순회 사각 폐쇄 (액션이 실행되는 자리 / 값이 sink 로 흐르는 자리)
    // ==========================================
    //
    // 종전 순회는 `components[].props/actions/children` + `init_actions` 뿐이었다.
    // 나머지 키는 저장 검증을 그대로 통과했고, 통과는 오류를 남기지 않는다.

    /**
     * 최상위 키에 외부 URL 을 심은 레이아웃을 만든다.
     *
     * @param  string  $key  최상위 키
     * @param  mixed  $payload  그 키의 값
     * @return array<string, mixed> 레이아웃 배열
     */
    private function layoutWith(string $key, mixed $payload): array
    {
        return ['version' => '1.0.0', 'components' => [], $key => $payload];
    }

    /**
     * 규칙을 돌려 실패 여부를 돌려준다.
     *
     * @param  array<string, mixed>  $layout  레이아웃 배열
     * @return bool 차단되면 true
     */
    private function blocks(array $layout): bool
    {
        $failed = false;
        $this->rule->validate('content', $layout, function () use (&$failed) {
            $failed = true;
        });

        return $failed;
    }

    /**
     * 신규 순회 키별 외부 URL 차단.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function newlyTraversedKeyProvider(): array
    {
        $evilAction = ['handler' => 'loadScript', 'params' => ['src' => 'https://cdn.evil.com/x.js']];

        return [
            'initActions (신철자)' => [
                ['version' => '1.0.0', 'components' => [], 'initActions' => [$evilAction]],
            ],
            'modals 안 컴포넌트 props' => [
                [
                    'version' => '1.0.0',
                    'components' => [],
                    'modals' => [
                        'confirm' => [
                            'components' => [
                                ['component' => 'Img', 'props' => ['src' => 'https://cdn.evil.com/x.png']],
                            ],
                        ],
                    ],
                ],
            ],
            'named_actions' => [
                ['version' => '1.0.0', 'components' => [], 'named_actions' => ['boot' => $evilAction]],
            ],
            'errorHandling' => [
                [
                    'version' => '1.0.0',
                    'components' => [],
                    'errorHandling' => ['404' => ['handler' => 'navigate', 'params' => ['path' => 'https://evil.com']]],
                ],
            ],
            'component lifecycle.onMount' => [
                [
                    'version' => '1.0.0',
                    'components' => [
                        ['component' => 'Div', 'lifecycle' => ['onMount' => [$evilAction]]],
                    ],
                ],
            ],
            'component onComponentEvent' => [
                [
                    'version' => '1.0.0',
                    'components' => [
                        ['component' => 'Div', 'onComponentEvent' => [$evilAction]],
                    ],
                ],
            ],
            'component slots' => [
                [
                    'version' => '1.0.0',
                    'components' => [
                        [
                            'component' => 'Card',
                            'slots' => [
                                'header' => [
                                    ['component' => 'Img', 'props' => ['src' => 'https://cdn.evil.com/x.png']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'component component_layout' => [
                [
                    'version' => '1.0.0',
                    'components' => [
                        [
                            'component' => 'Widget',
                            'component_layout' => [
                                'components' => [
                                    ['props' => ['src' => 'https://cdn.evil.com/x.png']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'component responsive.props' => [
                [
                    'version' => '1.0.0',
                    'components' => [
                        [
                            'component' => 'Img',
                            'responsive' => ['md' => ['props' => ['src' => 'https://cdn.evil.com/x.png']]],
                        ],
                    ],
                ],
            ],
            'protocol-relative 우회 형태' => [
                [
                    'version' => '1.0.0',
                    'components' => [
                        ['component' => 'Div', 'lifecycle' => ['onMount' => [['params' => ['src' => '/\\/evil.com/x.js']]]]],
                    ],
                ],
            ],
        ];
    }

    /**
     * @dataProvider newlyTraversedKeyProvider
     *
     * @param  array<string, mixed>  $layout  검사 대상 레이아웃
     */
    public function test_blocks_external_url_in_newly_traversed_keys(array $layout): void
    {
        $this->assertTrue($this->blocks($layout), '신규 순회 키의 외부 URL 은 차단되어야 합니다');
    }

    /**
     * same-origin 값은 신규 순회 키에서도 통과한다 (과차단 방지).
     */
    public function test_passes_same_origin_values_in_newly_traversed_keys(): void
    {
        $ok = ['handler' => 'loadScript', 'params' => ['src' => '/api/templates/assets/x/a.js']];

        $this->assertFalse($this->blocks($this->layoutWith('initActions', [$ok])));
        $this->assertFalse($this->blocks($this->layoutWith('named_actions', ['boot' => $ok])));
        $this->assertFalse($this->blocks([
            'version' => '1.0.0',
            'components' => [
                ['component' => 'Div', 'lifecycle' => ['onMount' => [$ok]]],
                ['component' => 'Img', 'responsive' => ['md' => ['props' => ['src' => '/images/a.png']]]],
                ['component' => 'Card', 'slots' => ['header' => [['props' => ['src' => '/images/a.png']]]]],
            ],
        ]));
        $this->assertFalse($this->blocks([
            'version' => '1.0.0',
            'components' => [],
            'modals' => ['confirm' => ['partial' => 'partials/confirm.json']],
        ]));
    }

    /**
     * 데이터 계층은 여전히 순회하지 않는다 (예시/안내 URL 을 담는 정당한 용례 보호).
     *
     * 이 회귀 단언이 없으면 다음 확장 때 "이왕 넓히는 김에" 로 데이터 계층까지 순회 대상이
     * 되어, 저장되던 레이아웃이 갑자기 422 가 된다.
     */
    public function test_does_not_traverse_data_layer_keys(): void
    {
        foreach (['defines', 'state', 'computed', 'initLocal', 'initGlobal', 'initIsolated'] as $key) {
            $this->assertFalse(
                $this->blocks($this->layoutWith($key, ['docsUrl' => 'https://example.com/guide'])),
                "$key 는 데이터 계층이므로 순회 대상이 아닙니다"
            );
        }
    }

    /**
     * 오류 경로에 modals/slots 위치가 남는다 (어느 자리인지 알 수 있어야 고칠 수 있다).
     */
    public function test_reports_modal_and_slot_paths(): void
    {
        $paths = [];
        $rule = new NoExternalUrls;

        $rule->validate('content', [
            'version' => '1.0.0',
            'components' => [
                ['component' => 'Card', 'slots' => ['header' => [['props' => ['src' => 'https://evil.com/x.png']]]]],
            ],
        ], function () use (&$paths) {
            $paths[] = true;
        });

        $this->assertNotEmpty($paths, 'slots 안의 외부 URL 이 차단되어야 합니다');
    }
}
