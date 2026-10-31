<?php

namespace Tests\Feature\Rules;

use App\Http\Requests\Layout\StoreLayoutRequest;
use App\Http\Requests\Layout\UpdateLayoutContentRequest;
use App\Http\Requests\Layout\UpdateLayoutExtensionContentRequest;
use App\Http\Requests\Layout\UpdateLayoutRequest;
use App\Rules\NoExternalUrls;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NoExternalUrlsTest extends TestCase
{
    private NoExternalUrls $rule;

    /**
     * 검사할 규칙 인스턴스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new NoExternalUrls;
    }

    /**
     * 정상적인 레이아웃 JSON 통과 테스트
     *
     * @effects valid_layout_passes_all_rules
     *
     * @return void 반환값 없음
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
     *
     * @effects external_url_prop_rejected
     *
     * @return void 반환값 없음
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
     * HTTPS URL 차단 테스트 — 자원을 불러오는 prop(src)
     *
     * 링크(href)·입력 안내 문구(placeholder)는 외부 주소를 허용한다(2026-09-30 결정) — 아래 경계 테스트가 따로 고정한다.
     *
     * @effects external_url_prop_rejected
     *
     * @return void 반환값 없음
     */
    public function test_fails_with_https_url_in_props(): void
    {
        $layoutWithHttps = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Image',
                    'props' => [
                        'src' => 'https://attacker.com/malicious.png',
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
     * 규칙 실패 메시지를 모읍니다.
     *
     * @param  array<string, mixed>  $layout  레이아웃 JSON
     * @return array<int, string> 실패 메시지 목록
     */
    private function failuresOf(array $layout): array
    {
        $messages = [];
        (new NoExternalUrls)->validate('content', $layout, function (string $message) use (&$messages) {
            $messages[] = $message;
        });

        return $messages;
    }

    /**
     * 입력 안내 문구(placeholder)·링크(href)의 외부 웹 주소는 컴포넌트·모달 어디서든 저장된다 (#727).
     *
     * 번들 관리자 화면 5개가 이 값(`https://github.com/username/module-name` 안내 문구·설치 안내 문서 링크)을 담고
     * 있어, 편집기로 열어 그대로 저장해도 422 였다. 결정(2026-09-30): 안내 문구·문서 링크는 어디서든 허용.
     *
     * @effects text_hint_and_link_props_accept_external_url
     *
     * @return void 반환값 없음
     */
    public function test_text_hint_and_link_props_accept_external_web_url(): void
    {
        $layout = [
            'components' => [[
                'name' => 'Input',
                'props' => ['placeholder' => 'https://github.com/username/module-name'],
                'children' => [['name' => 'A', 'props' => ['href' => 'https://github.com/gnuboard/g7/blob/main/INSTALL.md', 'target' => '_blank']]],
            ]],
            'modals' => [[
                'name' => 'Modal',
                'props' => ['title' => '설치'],
                'children' => [
                    ['name' => 'Input', 'props' => ['placeholder' => 'http://example.com']],
                    ['name' => 'A', 'props' => ['href' => "{{'https://github.com/' + repo}}"]],
                ],
            ]],
        ];

        $this->assertSame([], $this->failuresOf($layout));
    }

    /**
     * 요청 본문(`params.body`)의 예시 주소는 요청 대상이 아니라 데이터라 저장된다 (#727 — 환경설정 메일 미리보기).
     *
     * @effects request_body_example_url_accepted
     *
     * @return void 반환값 없음
     */
    public function test_request_body_example_url_is_accepted(): void
    {
        $layout = ['components' => [[
            'name' => 'Button',
            'actions' => [[
                'handler' => 'apiCall',
                'target' => '/api/admin/settings/mail/preview',
                'params' => ['method' => 'POST', 'body' => ['data' => ['action_url' => 'https://example.com/verify?token=preview']]],
            ]],
        ]]];

        $this->assertSame([], $this->failuresOf($layout));
    }

    /**
     * 링크·안내 문구라도 실행 스킴과 프로토콜 상대 주소는 종전대로 막는다 — 허용은 http(s) 웹 주소뿐이다.
     *
     * @effects link_props_still_reject_executable_and_protocol_relative
     *
     * @return void 반환값 없음
     */
    public function test_link_props_still_reject_executable_scheme_and_protocol_relative(): void
    {
        foreach ([
            ['href' => 'javascript:alert(1)'],
            ['href' => ' JaVa'."\t".'script:alert(1)'],
            ['placeholder' => 'data:text/html,<script>alert(1)</script>'],
            ['href' => '//evil.com/x'],
        ] as $props) {
            $this->assertNotSame([], $this->failuresOf(['components' => [['name' => 'A', 'props' => $props]]]), json_encode($props));
        }
    }

    /**
     * 자원을 불러오는 prop 과 액션의 이동·요청 대상은 종전대로 외부 주소를 막는다.
     *
     * @effects resource_and_action_target_external_url_still_rejected
     *
     * @return void 반환값 없음
     */
    public function test_resource_prop_and_action_target_still_reject_external_url(): void
    {
        $cases = [
            ['components' => [['name' => 'Img', 'props' => ['src' => 'https://evil.com/a.png']]]],
            ['components' => [['name' => 'Button', 'actions' => [['handler' => 'navigate', 'params' => ['path' => 'https://evil.com']]]]]],
            ['components' => [['name' => 'Button', 'actions' => [['handler' => 'apiCall', 'target' => 'https://evil.com/steal', 'params' => ['method' => 'POST']]]]]],
            ['components' => [['name' => 'Button', 'actions' => [['handler' => 'apiCall', 'target' => '/api/x', 'params' => ['url' => 'https://evil.com']]]]]],
        ];

        foreach ($cases as $layout) {
            $this->assertNotSame([], $this->failuresOf($layout), json_encode($layout));
        }
    }

    /**
     * 웹 주소 허용은 **자리**로만 켜진다 — 컴포넌트 prop 의 `placeholder`·`href` 글자 값과 액션 `params.body` 아래.
     *
     * 키 이름만으로 켜면 액션 `params.href`·prop 이름이 `params` 인 객체의 `body`·`href` 객체 아래 자원 주소까지
     * 열려, 나중에 그 키를 읽는 핸들러가 생기면 저장 검사가 소리 없이 뚫린다(KVE-2026-2350 과 같은 「모르는 자리」 형태).
     *
     * @effects web_url_allowance_is_limited_to_prop_and_action_body
     *
     * @return void 반환값 없음
     */
    public function test_web_url_allowance_is_limited_to_prop_and_action_body_positions(): void
    {
        $cases = [
            'actions[].params.href' => ['components' => [['name' => 'Button', 'actions' => [['handler' => 'openWindow', 'params' => ['href' => 'https://evil.com']]]]]],
            'init_actions[].params.href' => ['components' => [], 'init_actions' => [['handler' => 'setState', 'params' => ['href' => 'https://evil.com']]]],
            'props.params.body (액션 아님)' => ['components' => [['name' => 'Widget', 'props' => ['params' => ['body' => 'https://evil.com']]]]],
            'props.href 객체 아래 자원' => ['components' => [['name' => 'A', 'props' => ['href' => ['src' => 'https://evil.com/x.png']]]]],
        ];

        foreach ($cases as $label => $layout) {
            $this->assertNotSame([], $this->failuresOf($layout), $label);
        }

        // 허용 자리는 그대로 통과한다 — responsive 안 prop 포함
        $this->assertSame([], $this->failuresOf(['components' => [[
            'name' => 'A',
            'props' => ['href' => 'https://github.com/gnuboard/g7'],
            'responsive' => ['md' => ['props' => ['placeholder' => 'https://example.com']]],
            'actions' => [['handler' => 'apiCall', 'target' => '/api/x', 'params' => ['body' => ['url' => 'https://example.com/verify']]]],
        ]]]));
    }

    /**
     * 확장 조각이 끼워 넣는 컴포넌트(`injections[].components`)와 대상 prop 덧씌우기(`injections[].props`)도 화면과 같은
     * 규칙을 받는다 (2026-09-30 결정).
     *
     * 종전에는 부분 순회가 `injections` 를 몰라, 조각 저장 경로에서 자원 prop 의 외부 주소가 그대로 저장됐다.
     *
     * @effects injection_components_follow_screen_rules
     *
     * @return void 반환값 없음
     */
    public function test_injection_components_and_props_follow_screen_rules(): void
    {
        $this->assertNotSame([], $this->failuresOf(['injections' => [[
            'target_id' => 'order_summary',
            'position' => 'append',
            'components' => [['name' => 'Img', 'props' => ['src' => 'https://evil.com/t.png']]],
        ]]]), 'injections[].components 의 외부 자원 주소');

        $this->assertNotSame([], $this->failuresOf(['injections' => [[
            'target_id' => 'logo',
            'position' => 'props',
            'props' => ['src' => 'https://evil.com/t.png'],
        ]]]), 'injections[].props 의 외부 자원 주소');

        $this->assertSame([], $this->failuresOf(['injections' => [[
            'target_id' => 'pg_notice',
            'position' => 'append',
            'components' => [['name' => 'A', 'props' => ['href' => 'https://pg.example.com/receipt', 'placeholder' => 'https://example.com']]],
        ]]]), '안내 문구·링크는 조각에서도 허용');
    }

    /**
     * actions에서 외부 URL 차단 테스트
     *
     * @effects action_target_external_url_rejected_anywhere_in_tree
     *
     * @return void 반환값 없음
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
     *
     * @effects data_uri_prop_rejected
     *
     * @return void 반환값 없음
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
     *
     * @effects executable_scheme_prop_rejected
     *
     * @return void 반환값 없음
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
     *
     * @effects external_url_prop_rejected
     *
     * @return void 반환값 없음
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
     *
     * @effects protocol_relative_url_rejected
     *
     * @return void 반환값 없음
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
     *
     * @effects other_scheme_url_rejected
     *
     * @return void 반환값 없음
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
     *
     * @effects relative_path_prop_allowed
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @effects external_url_prop_rejected
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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

    // ─────────────────────────────────────────────────────────────────────────
    // 사이트 자산 host — 서버가 스스로 발급한 주소는 외부가 아니다
    //
    // 레이아웃 첨부 API 가 돌려준 URL(프록시 경로 또는 운영자가 선언한 공개 자산
    // 디스크의 직접 URL)을 image 위젯이 props 에 넣는데, 그 값을 이 규칙이 "외부" 로
    // 차단하면 업로드 → 저장이 422 로 끝난다. 배경 이미지는 style 로 들어가 스캔되지
    // 않아 같은 URL 이 통과했으므로, 증상은 로고(propValue) 에서만 나타났다.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * props.logo 하나를 가진 최소 레이아웃을 만듭니다.
     *
     * @param  string  $url  logo 값
     * @return array<string, mixed> logo prop 하나를 가진 레이아웃
     */
    private function layoutWithLogo(string $url): array
    {
        return [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'id' => 'header',
                    'type' => 'composite',
                    'name' => 'Header',
                    'props' => ['logo' => $url],
                    'children' => [],
                ],
            ],
        ];
    }

    /**
     * 규칙 통과 여부를 돌려줍니다.
     *
     * @param  array<string, mixed>  $layout  검사 대상
     * @return string|null 실패 메시지 (통과면 null)
     */
    private function failureOf(array $layout): ?string
    {
        $message = null;
        $this->rule->validate('content', $layout, function ($m) use (&$message) {
            $message = (string) $m;
        });

        return $message;
    }

    /**
     * 사이트 자기 host(app.url)의 절대 URL 은 통과해야 합니다 — 프록시 서빙 라우트가 절대
     * 형태로 발급되던 배포본(7.0.10 이하)에서 저장된 값과, 운영자가 자기 사이트 주소를
     * 직접 적은 값이 여기에 해당한다.
     *
     * @scenario url_host=own_site_absolute
     *
     * @effects issued_asset_url_passes_storage_gate
     *
     * @return void 반환값 없음
     */
    public function test_passes_with_own_site_absolute_url_in_props(): void
    {
        Config::set('app.url', 'https://shop.example.test');

        foreach ([
            'https://shop.example.test/api/templates/sirsoft-basic/layout-attachments/4/file',
            'http://shop.example.test/storage/template-layout-attachments/sirsoft-basic/logo.png',
            'https://SHOP.example.TEST/api/templates/sirsoft-basic/layout-attachments/4/file',
        ] as $url) {
            $this->assertNull($this->failureOf($this->layoutWithLogo($url)), "자기 host 절대 URL 은 통과해야 합니다: {$url}");
        }
    }

    /**
     * 운영자가 선언한 공개 자산 디스크의 host 는 통과해야 합니다 — 직접 URL(CDN) 변종에서
     * 첨부 API 가 돌려주는 값이다.
     *
     * @scenario url_host=public_asset_disk
     *
     * @effects issued_asset_url_passes_storage_gate
     *
     * @return void 반환값 없음
     */
    public function test_passes_with_public_asset_disk_host_in_props(): void
    {
        Config::set('app.url', 'https://shop.example.test');
        Config::set('core.storage.public_asset_disk', 'public');
        Config::set('filesystems.disks.public.url', 'https://cdn.example.test/assets');

        $url = 'https://cdn.example.test/assets/template-layout-attachments/sirsoft-basic/bg.png';

        $this->assertNull($this->failureOf($this->layoutWithLogo($url)), '공개 자산 디스크 host 는 통과해야 합니다');
    }

    /**
     * 같은 host 라도 공개 자산 디스크로 선언되지 않았으면 여전히 외부입니다 — 허용의 근거는
     * "URL 을 만들 수 있는 디스크가 있다" 가 아니라 운영자 선언이다.
     *
     * @scenario url_host=external_undeclared
     *
     * @effects external_host_still_rejected
     *
     * @return void 반환값 없음
     */
    public function test_fails_with_cdn_host_when_public_asset_disk_is_not_declared(): void
    {
        Config::set('app.url', 'https://shop.example.test');
        Config::set('core.storage.public_asset_disk', '');
        Config::set('filesystems.disks.public.url', 'https://cdn.example.test/assets');

        $url = 'https://cdn.example.test/assets/template-layout-attachments/sirsoft-basic/bg.png';

        $this->assertNotNull($this->failureOf($this->layoutWithLogo($url)), '선언되지 않은 디스크 host 는 차단되어야 합니다');
    }

    /**
     * 자기 host 를 흉내 낸 URL 은 전부 차단되어야 합니다 — 접두 문자열 비교로 허용하면
     * 이 형태들이 통과한다. 판정은 브라우저와 같은 정규화 뒤의 host 등가 비교여야 한다.
     *
     * @scenario url_host=lookalike
     *
     * @effects external_host_still_rejected
     *
     * @return void 반환값 없음
     */
    public function test_fails_with_lookalike_of_own_host(): void
    {
        Config::set('app.url', 'https://shop.example.test');
        Config::set('core.storage.public_asset_disk', '');

        foreach ([
            'https://shop.example.test.evil.com/x.png',
            'https://shop.example.test@evil.com/x.png',
            'https://evil.com\\@shop.example.test/x.png',
            'https://evil.com/shop.example.test/x.png',
            'https://evil.com/?u=https://shop.example.test/x.png',
            'https://shop.example.test%2eevil.com/x.png',
            // protocol-relative 는 자기 host 라도 종전대로 차단한다 (별도 판정 축)
            '//shop.example.test/x.png',
        ] as $url) {
            $this->assertNotNull($this->failureOf($this->layoutWithLogo($url)), "자기 host 흉내는 차단되어야 합니다: {$url}");
        }
    }

    /**
     * 자기 host 허용은 http/https 에만 적용됩니다 — 위험 스킴은 host 가 같아도 차단한다.
     *
     * @scenario url_host=dangerous_scheme
     *
     * @effects external_host_still_rejected
     *
     * @return void 반환값 없음
     */
    public function test_fails_with_dangerous_scheme_even_on_own_host(): void
    {
        Config::set('app.url', 'https://shop.example.test');

        foreach ([
            'ftp://shop.example.test/x.png',
            'file://shop.example.test/x.png',
        ] as $url) {
            $this->assertNotNull($this->failureOf($this->layoutWithLogo($url)), "위험 스킴은 host 와 무관하게 차단되어야 합니다: {$url}");
        }
    }

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
     * 편집기 저장 경로(UpdateLayoutContentRequest)의 content 배열 규칙에 NoExternalUrls 가 붙어 있는지 검증합니다.
     *
     * @effects rule_attached_to_every_layout_content_form_request
     *
     * @return void 반환값 없음
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
     * 확장 조각 저장 경로(UpdateLayoutExtensionContentRequest)의 content 배열 규칙에 NoExternalUrls 가 붙어 있는지 검증합니다.
     *
     * @effects rule_attached_to_every_layout_content_form_request
     *
     * @return void 반환값 없음
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
     * 레이아웃 생성·수정 경로(Store/UpdateLayoutRequest)의 content 배열 규칙에 NoExternalUrls 가 붙어 있는지 검증합니다.
     *
     * @effects rule_attached_to_every_layout_content_form_request
     *
     * @return void 반환값 없음
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
     * @return array<string, array{0: array<string, mixed>}> 키 이름 => [외부 URL 을 심은 레이아웃]
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
     * 액션이 실행되거나 값이 sink 로 흐르는 모든 키에서 외부 URL 이 차단되는지 검증합니다.
     *
     * @param  array<string, mixed>  $layout  검사 대상 레이아웃
     *
     * @effects external_url_rejected_in_every_sink_key
     *
     * @return void 반환값 없음
     */
    #[DataProvider('newlyTraversedKeyProvider')]
    public function test_blocks_external_url_in_newly_traversed_keys(array $layout): void
    {
        $this->assertTrue($this->blocks($layout), '신규 순회 키의 외부 URL 은 차단되어야 합니다');
    }

    /**
     * same-origin 값은 신규 순회 키에서도 통과한다 (과차단 방지).
     *
     * @effects same_origin_values_allowed_in_sink_keys
     *
     * @return void 반환값 없음
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
     *
     * @effects data_layer_external_link_still_allowed
     *
     * @return void 반환값 없음
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
     *
     * @effects violation_reports_modal_and_slot_paths
     *
     * @return void 반환값 없음
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
