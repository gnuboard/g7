<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use App\Support\LayoutMarkup\CompileContext;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\LayoutValidator;

/**
 * LayoutValidator 단위 테스트 (`.g7.html` 저작 포맷 S6 — 강검증).
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new LayoutValidator;
        $this->registry = ComponentRegistry::fromFile(base_path('templates/_bundled/sirsoft-admin_basic/components.json'));
    }

    /**
     * @scenario compile_unit=validator,source_health=valid
     *
     * @effects valid_layout_passes_all_strong_checks
     */
    public function test_valid_layout_passes(): void
    {
        $this->validate($this->layout());

        $this->addToAssertionCount(1); // 예외 없이 통과하면 성공.
    }

    /**
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects layout_missing_required_top_level_field_is_rejected
     */
    public function test_missing_version_is_structure_invalid(): void
    {
        $json = $this->layout();
        unset($json['version']);

        $this->assertSame(LayoutMarkupErrorCode::LayoutStructureInvalid, $this->error($json));
    }

    /**
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects endpoint_outside_whitelist_is_rejected
     */
    public function test_non_whitelisted_endpoint_is_rejected(): void
    {
        $json = $this->layout();
        $json['data_sources'] = [['id' => 'x', 'type' => 'api', 'endpoint' => '/not/allowed/path']];

        $this->assertSame(LayoutMarkupErrorCode::EndpointNotWhitelisted, $this->error($json));
    }

    /**
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects external_url_or_dangerous_scheme_is_rejected
     */
    public function test_dangerous_scheme_in_props_is_rejected(): void
    {
        $json = $this->layout();
        $json['components'] = [['type' => 'basic', 'name' => 'A', 'props' => ['href' => 'javascript:alert(1)']]];

        $this->assertSame(LayoutMarkupErrorCode::ExternalUrlForbidden, $this->error($json));
    }

    /**
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects component_name_not_in_registry_is_rejected
     */
    public function test_component_not_in_registry_is_rejected(): void
    {
        $json = $this->layout();
        $json['components'] = [['type' => 'basic', 'name' => 'TotallyMadeUpComponent']];

        $this->assertSame(LayoutMarkupErrorCode::ComponentNotInRegistry, $this->error($json));
    }

    /**
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects wait_for_pointing_at_never_resolving_source_is_rejected
     */
    public function test_wait_for_websocket_source_is_rejected(): void
    {
        $json = $this->layout();
        $json['data_sources'] = [['id' => 'live', 'type' => 'websocket', 'channel' => 'core.x']];
        $json['transition_overlay'] = ['enabled' => true, 'wait_for' => ['live']];

        $this->assertSame(LayoutMarkupErrorCode::WaitForSourceNeverResolves, $this->error($json));
    }

    /**
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects wait_for_pointing_at_never_resolving_source_is_rejected
     */
    public function test_wait_for_background_source_is_rejected(): void
    {
        $json = $this->layout();
        $json['data_sources'] = [['id' => 'bg', 'type' => 'api', 'endpoint' => '/api/admin/x', 'loading_strategy' => 'background']];
        $json['transition_overlay'] = ['enabled' => true, 'wait_for' => ['bg']];

        $this->assertSame(LayoutMarkupErrorCode::WaitForSourceNeverResolves, $this->error($json));
    }

    /**
     * @scenario compile_unit=validator,source_health=valid
     *
     * @effects wait_for_pointing_at_missing_id_is_ignored
     */
    public function test_wait_for_missing_id_passes(): void
    {
        $json = $this->layout();
        $json['transition_overlay'] = ['enabled' => true, 'wait_for' => ['nonexistent']];

        $this->validate($json);

        $this->addToAssertionCount(1);
    }

    /**
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects script_src_outside_allowlist_is_rejected
     */
    public function test_non_allowlisted_script_src_is_rejected(): void
    {
        $json = $this->layout();
        $json['scripts'] = [['src' => 'https://evil.cdn.example/pwn.js', 'id' => 'x']];

        $this->assertSame(LayoutMarkupErrorCode::ScriptSrcNotAllowed, $this->error($json));
    }

    /**
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects script_src_outside_allowlist_is_rejected
     */
    public function test_http_script_src_is_rejected_even_if_host_allowlisted(): void
    {
        $json = $this->layout();
        // 허용 호스트라도 http(비암호)는 거부.
        $json['scripts'] = [['src' => 'http://t1.daumcdn.net/x.js', 'id' => 'x']];

        $this->assertSame(LayoutMarkupErrorCode::ScriptSrcNotAllowed, $this->error($json));
    }

    /**
     * @scenario compile_unit=validator,source_health=valid
     *
     * @effects allowlisted_protocol_relative_script_src_passes
     */
    public function test_allowlisted_protocol_relative_script_src_passes(): void
    {
        $json = $this->layout();
        $json['scripts'] = [['src' => '//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js', 'id' => 'daum']];

        $this->validate($json);

        $this->addToAssertionCount(1);
    }

    /**
     * @scenario compile_unit=validator,source_health=valid
     *
     * @effects same_origin_script_src_is_not_treated_as_external
     */
    public function test_same_origin_script_src_passes(): void
    {
        $json = $this->layout();
        $json['scripts'] = [['src' => '/js/local.js', 'id' => 'local']];

        $this->validate($json);

        $this->addToAssertionCount(1);
    }

    /**
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects fragment_structure_violation_is_rejected
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
     */
    private function validate(array $json, string $rootKind = 'layout'): void
    {
        $this->validator->validate($json, $rootKind, $this->registry, new CompileContext);
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
}
