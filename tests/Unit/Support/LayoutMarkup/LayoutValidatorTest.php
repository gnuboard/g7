<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use App\Rules\NoExternalUrls;
use App\Services\LayoutService;
use App\Support\LayoutMarkup\CompileContext;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\LayoutValidator;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;

/**
 * LayoutValidator 단위 테스트 (`.g7.html` 마크업 형식 S6 — 강검증).
 *
 * 검증 축은 여섯이다: ① 구조(ValidLayoutStructure/fragment 상당) ② 엔드포인트 화이트리스트
 * ③ 외부 URL·위험 스킴 ④ 컴포넌트 존재 그물 ⑤ wait_for cross-field ⑥ 외부 JS 로더 화이트리스트.
 *
 * 강검증은 컴파일 산출 JSON 을 대상으로 하므로, 여기서는 JSON 을 직접 지어 각 축의 위반이 정확한
 * CompileError 코드로 승격되는지 본다(파사드 종단은 LayoutMarkupCompilerTest 담당). 레지스트리는
 * 번들 admin_basic 실물을 쓴다 — 합성으로 대신하면 "우리끼리만 맞는" 컴포넌트 존재 판정이 된다.
 */
class LayoutValidatorTest extends LayoutMarkupTestCase
{
    private LayoutValidator $validator;

    private ComponentRegistry $registry;

    /**
     * 테스트 준비 — 필요한 서비스와 임시 자원을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new LayoutValidator;
        $this->registry = ComponentRegistry::fromFile(base_path('templates/_bundled/sirsoft-admin_basic/components.json'));
    }

    /**
     * 올바른 레이아웃은 강검증 전부를 통과한다.
     *
     * @scenario compile_unit=validator,source_health=valid
     *
     * @effects valid_layout_passes_all_strong_checks
     *
     * @return void 반환값 없음
     */
    public function test_valid_layout_passes(): void
    {
        $this->validate($this->layout());

        $this->addToAssertionCount(1); // 예외 없이 통과하면 성공.
    }

    /**
     * 최상위 version 이 없으면 구조 오류다.
     *
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects layout_missing_required_top_level_field_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_missing_version_is_structure_invalid(): void
    {
        $json = $this->layout();
        unset($json['version']);

        $this->assertSame(LayoutMarkupErrorCode::LayoutStructureInvalid, $this->error($json));
    }

    /**
     * 허용 목록 밖 엔드포인트는 거부된다.
     *
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects endpoint_outside_whitelist_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_non_whitelisted_endpoint_is_rejected(): void
    {
        $json = $this->layout();
        $json['data_sources'] = [['id' => 'x', 'type' => 'api', 'endpoint' => '/not/allowed/path']];

        $this->assertSame(LayoutMarkupErrorCode::EndpointNotWhitelisted, $this->error($json));
    }

    /**
     * 외부 URL·위험 스킴은 **이관 검사에서 통과한다** (설계 결정 2026-07-20 — `NoExternalUrls` 제외).
     *
     * 번들 결제 플러그인의 PG사 링크가 정상 출하물인데 파사드만 막고 있어 이관이 불가했다.
     * 설치 경로(`ValidatesLayoutFiles`)는 원래 이 규칙을 돌리지 않으므로 `.json` 설치와 패리티다.
     *
     * **작성 API 경로에는 규칙이 그대로 살아 있다** — 그 계약은 아래 대조군이 지킨다.
     *
     * @scenario compile_unit=validator,source_health=valid
     *
     * @effects external_url_and_dangerous_scheme_pass_migration_validation
     *
     * @return void 반환값 없음
     */
    public function test_external_url_and_dangerous_scheme_pass_migration_validation(): void
    {
        $json = $this->layout();
        $json['components'] = [
            ['type' => 'basic', 'name' => 'A', 'props' => ['href' => 'https://npg.nicepay.co.kr/issue/IssueLoader.do']],
            ['type' => 'basic', 'name' => 'A', 'props' => ['href' => 'javascript:alert(1)']],
        ];

        $this->assertNull($this->errorOrNull($json));
    }

    /**
     * 작성 API 경로는 종전대로 위험 스킴을 막는다 (대조군 — 규칙이 통째로 죽지 않았음).
     *
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects authoring_api_still_rejects_dangerous_scheme
     *
     * @return void 반환값 없음
     */
    public function test_authoring_api_still_rejects_external_url_and_dangerous_scheme(): void
    {
        $messages = [];
        $fail = function (string $message) use (&$messages): void {
            $messages[] = $message;
        };

        (new NoExternalUrls)->validate('content', [
            'components' => [['type' => 'basic', 'name' => 'A', 'props' => ['href' => 'javascript:alert(1)']]],
        ], $fail);

        $this->assertNotSame([], $messages);
    }

    /**
     * 레지스트리에 없는 컴포넌트 이름은 거부된다.
     *
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects component_name_not_in_registry_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_component_not_in_registry_is_rejected(): void
    {
        $json = $this->layout();
        $json['components'] = [['type' => 'basic', 'name' => 'TotallyMadeUpComponent']];

        $this->assertSame(LayoutMarkupErrorCode::ComponentNotInRegistry, $this->error($json));
    }

    /**
     * 목록에 없는 확장 컴포넌트(`식별자.이름`)는 강검증이 세우지 않는다 — 점 없는 템플릿 컴포넌트 오타만 거부한다.
     *
     * 확장은 편집 도중 지워지거나 꺼질 수 있어, 그 확장 하나 때문에 레이아웃을 저장하지 못하게 하면 안 된다
     * (2026-09-28 결정). 알림은 lint 경고(`extension_component_not_installed`)가 맡는다.
     *
     * @scenario compile_unit=validator,source_health=valid
     *
     * @effects missing_extension_component_is_not_a_strong_validation_error
     *
     * @return void 반환값 없음
     */
    public function test_missing_extension_component_is_not_rejected_but_template_typo_is(): void
    {
        $json = $this->layout();
        $json['components'] = [['type' => 'basic', 'name' => 'Div', 'children' => [
            ['type' => 'composite', 'name' => 'removed-module.MemoCard'],
        ]]];

        $this->assertNull($this->errorOrNull($json));

        $json['components'][0]['children'][] = ['type' => 'basic', 'name' => 'Dvi'];
        $this->assertSame(LayoutMarkupErrorCode::ComponentNotInRegistry, $this->error($json));
    }

    /**
     * `slot` 키가 붙은 노드도 컴포넌트다 — 그 노드와 하위 트리의 미등록 이름을 거부한다.
     *
     * G7 에서 `slot` 은 슬롯 래퍼 컴포넌트(`extends` 의 슬롯 자리, `data-slot` 등록 노드)에 붙고 이름·props 를
     * 그대로 가진다({@see LayoutService::replaceSlots()}). 예전 검사는 `slot` 키를 참조로 보고
     * 하위 트리를 통째로 건너뛰어, 슬롯 래퍼 아래의 오타가 저장·설치를 통과했다. 참조라서 건너뛸 것은
     * `partial` 뿐이다.
     *
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects slot_wrapper_subtree_is_checked_for_registry
     *
     * @return void 반환값 없음
     */
    public function test_slot_wrapper_subtree_is_checked_for_registry(): void
    {
        $json = $this->layout();
        $json['components'] = [['type' => 'basic', 'name' => 'Div', 'slot' => 'content', 'children' => [
            ['type' => 'basic', 'name' => 'Dvi'],
        ]]];

        $this->assertSame(LayoutMarkupErrorCode::ComponentNotInRegistry, $this->error($json), '슬롯 래퍼 하위의 오타');

        $json['components'] = [['type' => 'basic', 'name' => 'Dvi', 'slot' => 'content']];
        $this->assertSame(LayoutMarkupErrorCode::ComponentNotInRegistry, $this->error($json), '슬롯 래퍼 자신의 오타');

        $json['components'] = [['type' => 'basic', 'name' => 'Div', 'slot' => 'content', 'children' => [
            ['type' => 'basic', 'name' => 'Span'],
        ]]];
        $this->assertNull($this->errorOrNull($json), '등록된 이름만 있으면 통과');
    }

    /**
     * wait_for 가 웹소켓 데이터소스를 가리키면 거부된다.
     *
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects wait_for_pointing_at_never_resolving_source_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_wait_for_websocket_source_is_rejected(): void
    {
        $json = $this->layout();
        $json['data_sources'] = [['id' => 'live', 'type' => 'websocket', 'channel' => 'core.x']];
        $json['transition_overlay'] = ['enabled' => true, 'wait_for' => ['live']];

        $this->assertSame(LayoutMarkupErrorCode::WaitForSourceNeverResolves, $this->error($json));
    }

    /**
     * wait_for 가 백그라운드 데이터소스를 가리키면 거부된다.
     *
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects wait_for_pointing_at_never_resolving_source_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_wait_for_background_source_is_rejected(): void
    {
        $json = $this->layout();
        $json['data_sources'] = [['id' => 'bg', 'type' => 'api', 'endpoint' => '/api/admin/x', 'loading_strategy' => 'background']];
        $json['transition_overlay'] = ['enabled' => true, 'wait_for' => ['bg']];

        $this->assertSame(LayoutMarkupErrorCode::WaitForSourceNeverResolves, $this->error($json));
    }

    /**
     * wait_for 가 없는 id 를 가리키면 무시하고 통과한다.
     *
     * @scenario compile_unit=validator,source_health=valid
     *
     * @effects wait_for_pointing_at_missing_id_is_ignored
     *
     * @return void 반환값 없음
     */
    public function test_wait_for_missing_id_passes(): void
    {
        $json = $this->layout();
        $json['transition_overlay'] = ['enabled' => true, 'wait_for' => ['nonexistent']];

        $this->validate($json);

        $this->addToAssertionCount(1);
    }

    /**
     * 허용 목록 밖 외부 스크립트 호스트는 거부된다.
     *
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects script_src_outside_allowlist_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_non_allowlisted_script_src_is_rejected(): void
    {
        $json = $this->layout();
        $json['scripts'] = [['src' => 'https://evil.cdn.example/pwn.js', 'id' => 'x']];

        $this->assertSame(LayoutMarkupErrorCode::ScriptSrcNotAllowed, $this->error($json));
    }

    /**
     * 허용 호스트라도 http(비암호) 스크립트는 거부된다.
     *
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects script_src_outside_allowlist_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_http_script_src_is_rejected_even_if_host_allowlisted(): void
    {
        $json = $this->layout();
        // 허용 호스트라도 http(비암호)는 거부.
        $json['scripts'] = [['src' => 'http://t1.daumcdn.net/x.js', 'id' => 'x']];

        $this->assertSame(LayoutMarkupErrorCode::ScriptSrcNotAllowed, $this->error($json));
    }

    /**
     * 허용 호스트의 protocol-relative 스크립트는 통과한다.
     *
     * @scenario compile_unit=validator,source_health=valid
     *
     * @effects allowlisted_protocol_relative_script_src_passes
     *
     * @return void 반환값 없음
     */
    public function test_allowlisted_protocol_relative_script_src_passes(): void
    {
        $json = $this->layout();
        $json['scripts'] = [['src' => '//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js', 'id' => 'daum']];

        $this->validate($json, context: new CompileContext(declaredScriptHosts: ['t1.daumcdn.net']));

        $this->addToAssertionCount(1);
    }

    /**
     * 코어 설정은 확장 호스트를 담지 않고, 선언 없는 호스트는 거부된다.
     *
     * @scenario compile_unit=validator,source_health=valid
     *
     * @effects core_config_ships_no_extension_script_host
     *
     * @return void 반환값 없음
     */
    public function test_core_config_ships_no_extension_script_host(): void
    {
        // 코어 설정은 운영자 몫만 담는다 — 확장 호스트는 그 확장이 매니페스트로 선언한다.
        $this->assertSame([], (require base_path('config/template.php'))['layout_markup']['script_src_allowlist'] ?? null);

        $json = $this->layout();
        $json['scripts'] = [['src' => '//undeclared.cdn.example/x.js', 'id' => 'x']];

        $this->assertSame(LayoutMarkupErrorCode::ScriptSrcNotAllowed, $this->error($json));
    }

    /**
     * 운영자가 설정에 덧붙인 호스트는 허용된다.
     *
     * @scenario compile_unit=validator,source_health=valid
     *
     * @effects operator_configured_script_host_is_allowed
     *
     * @return void 반환값 없음
     */
    public function test_operator_configured_script_host_is_allowed(): void
    {
        config()->set('template.layout_markup.script_src_allowlist', ['Operator.CDN.example']);

        $json = $this->layout();
        $json['scripts'] = [['src' => 'https://operator.cdn.example/x.js', 'id' => 'x']];

        $this->validate($json);

        $this->addToAssertionCount(1);
    }

    /**
     * 설치 경로 문맥은 JSON 설치처럼 스크립트 호스트를 대조하지 않는다.
     *
     * @scenario compile_unit=validator,source_health=valid
     *
     * @effects install_context_does_not_check_script_host
     *
     * @return void 반환값 없음
     */
    public function test_install_context_does_not_check_script_host(): void
    {
        // JSON 설치와 같이 설치 경로는 호스트를 대조하지 않는다 — 오버라이드 대상 확장이 아직 없어도
        // 조각이 빠지지 않아야 한다. 판정은 런타임 게이트(TrustedScriptHosts)가 한다.
        $context = (new MarkupCompileContextResolver)->installContextFor(
            base_path('templates/_bundled/sirsoft-basic/extensions/any-extension/any.g7.html'),
        );

        $json = $this->layout();
        $json['scripts'] = [['src' => '//undeclared.cdn.example/x.js', 'id' => 'x']];

        $this->validator->validate($json, 'layout', null, $context);

        $this->assertFalse($context->checksScriptSrc);
    }

    /**
     * 소유 확장과 오버라이드 대상 확장의 매니페스트 선언 호스트를 읽는다.
     *
     * @scenario compile_unit=validator,source_health=valid
     *
     * @effects owner_and_override_target_manifest_hosts_are_declared
     *
     * @return void 반환값 없음
     */
    public function test_owner_and_override_target_manifest_hosts_are_declared(): void
    {
        $resolver = new MarkupCompileContextResolver;

        // 확장 자신의 레이아웃 — 위로 올라가 처음 만나는 매니페스트(plugin.json)의 선언.
        $own = $resolver->contextFor(base_path('plugins/_bundled/sirsoft-daum_postcode/resources/extensions/ecommerce-address-search.g7.html'));
        $this->assertSame(['t1.daumcdn.net'], $own->declaredScriptHosts);

        // 템플릿의 확장 오버라이드 — 같은 트리의 대상 확장 매니페스트 선언을 더한다.
        $override = $resolver->contextFor(base_path('templates/_bundled/sirsoft-basic/extensions/sirsoft-daum_postcode/user-address-search.g7.html'));
        $this->assertSame(['t1.daumcdn.net'], $override->declaredScriptHosts);

        // 선언 없는 확장 — 아무 호스트도 얻지 않는다.
        $none = $resolver->contextFor(base_path('modules/_bundled/sirsoft-board/resources/layouts/admin/any.g7.html'));
        $this->assertSame([], $none->declaredScriptHosts);
    }

    /**
     * 같은 origin 스크립트는 외부로 보지 않는다.
     *
     * @scenario compile_unit=validator,source_health=valid
     *
     * @effects same_origin_script_src_is_not_treated_as_external
     *
     * @return void 반환값 없음
     */
    public function test_same_origin_script_src_passes(): void
    {
        $json = $this->layout();
        $json['scripts'] = [['src' => '/js/local.js', 'id' => 'local']];

        $this->validate($json);

        $this->addToAssertionCount(1);
    }

    /**
     * 대상이 없는 조각은 구조 오류다.
     *
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects fragment_structure_violation_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_fragment_without_target_is_structure_invalid(): void
    {
        // extension_point·target_layout 둘 다 없는 fragment.
        $json = ['components' => [['type' => 'basic', 'name' => 'Div']]];

        $this->assertSame(
            LayoutMarkupErrorCode::FragmentStructureInvalid,
            $this->error($json, 'extension'),
        );
    }

    // ---------------------------------------------------------------------
    // 헬퍼
    // ---------------------------------------------------------------------

    /**
     * 최소 유효 완성 레이아웃 JSON 을 만듭니다.
     *
     * @return array<string, mixed> 레이아웃 JSON
     */
    private function layout(): array
    {
        return [
            'version' => '1.0.0',
            'layout_name' => 'sample',
            'components' => [['type' => 'basic', 'name' => 'Div', 'props' => []]],
        ];
    }

    /**
     * JSON 을 검증합니다 (예외가 나면 그대로 전파).
     *
     * @param  array<string, mixed>  $json  레이아웃 JSON
     * @param  string  $rootKind  문서 봉투
     * @param  CompileContext|null  $context  검증 문맥 (생략 시 기본 문맥)
     * @return void 반환값 없음
     */
    private function validate(array $json, string $rootKind = 'layout', ?CompileContext $context = null): void
    {
        $this->validator->validate($json, $rootKind, $this->registry, $context ?? new CompileContext);
    }

    /**
     * 검증이 실패할 것을 전제로 오류 코드를 잡아 반환합니다.
     *
     * @param  array<string, mixed>  $json  레이아웃 JSON
     * @param  string  $rootKind  문서 봉투
     * @return LayoutMarkupErrorCode 발생한 오류 코드
     */
    private function error(array $json, string $rootKind = 'layout'): LayoutMarkupErrorCode
    {
        try {
            $this->validate($json, $rootKind);
        } catch (CompileError $error) {
            return $error->getErrorCode();
        }

        $this->fail('CompileError 를 기대했으나 통과했습니다.');
    }

    /**
     * 검증을 돌려 오류 코드를 반환하되, 통과하면 null 을 반환합니다 (통과를 단언할 때 사용).
     *
     * @param  array<string, mixed>  $json  레이아웃 JSON
     * @param  string  $rootKind  루트 종류
     * @return LayoutMarkupErrorCode|null 오류 코드, 통과 시 null
     */
    private function errorOrNull(array $json, string $rootKind = 'layout'): ?LayoutMarkupErrorCode
    {
        try {
            $this->validate($json, $rootKind);
        } catch (CompileError $error) {
            return $error->getErrorCode();
        }

        return null;
    }
}
