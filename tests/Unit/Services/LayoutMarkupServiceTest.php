<?php

namespace Tests\Unit\Services;

use App\Enums\LayoutMarkupErrorCode;
use App\Enums\LayoutMarkupLintCode;
use App\Models\Module;
use App\Models\Plugin;
use App\Services\LayoutMarkupService;
use App\Support\LayoutMarkup\CompileContext;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 설치본(DB 행) 변환 서비스 — 코드 편집기·점검 커맨드가 쓰는 JSON ↔ `.g7.html` 공용 진입점 (#476 4.1).
 *
 * 파일 경로가 없는 문서라 표·문맥을 템플릿 식별자로 고른다({@see MarkupCompileContextResolver::registryForTemplate()}·
 * {@see MarkupCompileContextResolver::contextForInstalled()}). 두 방향 모두 설치 경로와 같은 클래스를 탄다.
 */
class LayoutMarkupServiceTest extends TestCase
{
    use DatabaseTransactions;

    /** 설치 확장 목록 검사에 쓰는 임시 모듈 식별자 (설치 디렉토리에 매니페스트를 두고 끝나면 지운다) */
    private const PROBE_MODULE = 'zz-markup_probe_module';

    /** 설치 확장 목록 검사에 쓰는 임시 플러그인 식별자 */
    private const PROBE_PLUGIN = 'zz-markup_probe_plugin';

    private LayoutMarkupService $service;

    /**
     * 테스트 준비 — 필요한 서비스와 임시 자원을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LayoutMarkupService;
    }

    /**
     * 테스트 정리 — 만든 임시 자원을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('modules/'.self::PROBE_MODULE));
        File::deleteDirectory(base_path('plugins/'.self::PROBE_PLUGIN));
        DB::disconnect();

        parent::tearDown();
    }

    /**
     * 편집기의 JSON→마크업→JSON 왕복이 같은 결과를 낸다.
     *
     * @effects installed_json_round_trips_through_markup_with_the_template_registry
     *
     * @return void 반환값 없음
     */
    public function test_to_markup_and_to_json_round_trip_with_template_registry(): void
    {
        $json = [
            'layout_name' => 'admin_probe',
            'version' => '1.0.0',
            'named_actions' => ['probe' => ['comment' => '한 번만', 'handler' => 'setState', 'params' => ['target' => 'local', 'a' => 1]]],
            'components' => [['type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'p-4']]],
        ];

        ['markup' => $markup, 'escapes' => $escapes] = $this->service->toMarkup($json, 'sirsoft-admin_basic');

        $this->assertStringStartsWith('<layout name="admin_probe"', $markup);
        $this->assertSame([], $escapes);
        $this->assertSame(1, substr_count($markup, '한 번만'));

        $result = $this->service->toJson($markup, 'sirsoft-admin_basic', 'template', 'sirsoft-admin_basic', 'admin_probe.g7.html');

        $this->assertSame('p-4', $result->json['components'][0]['props']['className']);
        $this->assertSame('한 번만', $result->json['named_actions']['probe']['comment']);
        $this->assertSame([], $this->service->describeWarnings($result->warnings));
    }

    /**
     * 역변환 결과에 남은 `<json>` 탈출구를 함께 알린다.
     *
     * @effects unrepresentable_keys_are_reported_as_escapes
     *
     * @return void 반환값 없음
     */
    public function test_to_markup_reports_json_escapes(): void
    {
        $json = [
            'layout_name' => 'admin_probe',
            'version' => '1.0.0',
            'components' => [['type' => 'basic', 'name' => 'Div']],
            'scripts' => [['src' => '/js/x.js', 'comment' => '방출 자리가 없는 주석']],
        ];

        ['escapes' => $escapes] = $this->service->toMarkup($json, 'sirsoft-admin_basic');

        $this->assertNotEmpty($escapes, '표현 못 하는 키는 <json> 탈출로 계량돼야 한다');
    }

    /**
     * 마크업→JSON 실패는 컴파일 오류를 던지고, 오류 설명에 위치가 실린다.
     *
     * @effects compile_error_is_described_with_code_and_position
     *
     * @return void 반환값 없음
     */
    public function test_to_json_throws_compile_error_and_describe_error_carries_position(): void
    {
        try {
            $this->service->toJson("<layout name=\"x\" version=\"1.0.0\">\n  <Div/>\n</layout>", 'sirsoft-admin_basic', null, null, 'x.g7.html');
            $this->fail('CompileError 를 기대했습니다.');
        } catch (CompileError $error) {
            $described = $this->service->describeError($error);

            $this->assertSame(LayoutMarkupErrorCode::TagUppercase->value, $described['code']);
            $this->assertSame(2, $described['line']);
            $this->assertGreaterThan(0, $described['column']);
            $this->assertNotEmpty($described['message']);
            $this->assertStringNotContainsString('x.g7.html', $described['message'], '본문은 위치 봉투 없이 온다 (편집기가 라인·컬럼을 따로 받는다)');
        }
    }

    /**
     * 컴포넌트 목록이 없는 템플릿이어도 편집기는 검사 없이 역변환한다 — 산출은 템플릿과 무관하다
     * (2026-09-28 결정). 종전에는 예외(→ 편집기 500)였다.
     *
     * @scenario compile_unit=editor,source_health=valid
     *
     * @effects unknown_template_has_no_registry
     *
     * @return void 반환값 없음
     */
    public function test_unknown_template_still_decompiles_without_check(): void
    {
        $result = $this->service->toMarkup(['layout_name' => 'x', 'version' => '1.0.0', 'components' => [['type' => 'composite', 'name' => 'DataGrid']]], 'no-such-template');

        $this->assertStringContainsString('<data-grid data-type="composite"', $result['markup']);
    }

    /**
     * 해석기가 템플릿 식별자로 검사용 레지스트리와 설치본 문맥을 만든다.
     *
     * @effects resolver_builds_registry_and_context_from_template_identifier
     *
     * @return void 반환값 없음
     */
    public function test_resolver_exposes_registry_and_installed_context_by_template(): void
    {
        $resolver = new MarkupCompileContextResolver;

        $this->assertNotNull($resolver->registryForTemplate('sirsoft-admin_basic'));
        $this->assertNull($resolver->registryForTemplate('no-such-template'));

        $context = $resolver->contextForInstalled('sirsoft-admin_basic', 'plugin', 'sirsoft-gdpr');

        $this->assertInstanceOf(CompileContext::class, $context);
        $this->assertSame('plugin', $context->sourceKind);
        $this->assertSame('sirsoft-gdpr', $context->identifier);
        $this->assertNotEmpty($context->templateHandlers, '템플릿 핸들러 목록은 경로 해석과 같은 함수에서 온다');
    }

    /**
     * 편집기 검사 목록에는 편집 대상 템플릿과 함께 설치된 확장 전부(활성·비활성)의 컴포넌트가 실린다.
     *
     * 편집 도중 확장을 꺼도 그 확장 컴포넌트를 쓴 레이아웃을 계속 저장할 수 있어야 한다(2026-09-28 결정) —
     * 활성 모듈과 비활성 플러그인을 하나씩 설치 목록에 두고, 둘 다 경고·오류 없이 컴파일되는지 본다.
     *
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects editor_registry_includes_every_installed_extension_component
     *
     * @return void 반환값 없음
     */
    public function test_editor_registry_includes_active_and_inactive_installed_extension_components(): void
    {
        $this->installProbe('modules', self::PROBE_MODULE, 'MemoCard', 'active');
        $this->installProbe('plugins', self::PROBE_PLUGIN, 'PayButton', 'inactive');

        $owners = array_map(fn ($manifest) => $manifest->ownerId, $this->service->installedExtensionManifests());
        $this->assertContains(self::PROBE_MODULE, $owners);
        $this->assertContains(self::PROBE_PLUGIN, $owners);

        $result = $this->service->toJson(
            '<layout name="t" version="1.0.0"><div>'
            .'<'.self::PROBE_MODULE.'.memo-card data-type="composite" title="x"/>'
            .'<'.self::PROBE_PLUGIN.'.pay-button data-type="composite"/>'
            .'</div></layout>',
            'sirsoft-basic',
            'module',
            self::PROBE_MODULE,
            't.g7.html',
        );

        $this->assertSame(self::PROBE_MODULE.'.MemoCard', $result->json['components'][0]['children'][0]['name']);
        $this->assertSame([], $this->service->describeWarnings($result->warnings));
    }

    /**
     * 설치 목록에 없는 확장 컴포넌트는 경고만 내고 저장을 허용하지만, 점 없는 템플릿 컴포넌트 오타는 오류다.
     *
     * @scenario compile_unit=registry,source_health=invalid
     *
     * @effects missing_extension_component_is_warned_in_editor_but_template_typo_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_missing_extension_component_warns_while_template_typo_is_rejected(): void
    {
        $result = $this->service->toJson(
            '<layout name="t" version="1.0.0"><div><removed-module.memo-card data-type="composite"/></div></layout>',
            'sirsoft-basic',
            'module',
            'removed-module',
            't.g7.html',
        );

        $codes = array_column($this->service->describeWarnings($result->warnings), 'code');
        $this->assertContains(LayoutMarkupLintCode::ExtensionComponentNotInstalled->value, $codes);
        $this->assertSame('removed-module.MemoCard', $result->json['components'][0]['children'][0]['name']);

        try {
            $this->service->toJson('<layout name="t" version="1.0.0"><dvi/></layout>', 'sirsoft-basic', 'template', 'sirsoft-basic', 't.g7.html');
            $this->fail('점 없는 템플릿 컴포넌트 오타는 CompileError 여야 합니다.');
        } catch (CompileError $error) {
            $this->assertSame(LayoutMarkupErrorCode::ComponentNotInRegistry, $error->getErrorCode());
        }
    }

    /**
     * 설치되지 않은 확장 컴포넌트 경고는 편집기가 가리킬 수 있도록 그 태그의 소스 줄·칸을 싣는다.
     *
     * lint 는 산출 JSON 을 보므로 위치를 몰라 0:0 으로 나가던 것을, 소스 트리에서 태그를 찾아 채운다
     * (2026-09-28 결정).
     *
     * @scenario compile_unit=registry,source_health=invalid
     *
     * @effects missing_extension_component_warning_points_at_source_tag
     *
     * @return void 반환값 없음
     */
    public function test_missing_extension_component_warning_carries_source_position(): void
    {
        $result = $this->service->toJson(
            '<layout name="t" version="1.0.0">
  <div>
    <removed-module.memo-card data-type="composite"/>
  </div>
</layout>',
            'sirsoft-basic',
            'module',
            'removed-module',
            't.g7.html',
        );

        $warning = collect($this->service->describeWarnings($result->warnings))
            ->firstWhere('code', LayoutMarkupLintCode::ExtensionComponentNotInstalled->value);

        $this->assertNotNull($warning);
        $this->assertSame(3, $warning['line']);
        $this->assertSame(5, $warning['column']);
    }

    /**
     * 확장 하나를 설치 목록(DB 행)과 설치 디렉토리 매니페스트로 둡니다.
     *
     * @param  string  $kind  확장 종류 디렉토리 (`modules`·`plugins`)
     * @param  string  $identifier  확장 식별자
     * @param  string  $component  제공 컴포넌트 이름
     * @param  string  $status  확장 상태 (`active`·`inactive`)
     * @return void 반환값 없음
     */
    private function installProbe(string $kind, string $identifier, string $component, string $status): void
    {
        ($kind === 'modules' ? Module::factory() : Plugin::factory())->create(['identifier' => $identifier, 'status' => $status]);

        File::ensureDirectoryExists(base_path("{$kind}/{$identifier}"));
        File::put(base_path("{$kind}/{$identifier}/components.json"), (string) json_encode([
            'identifier' => $identifier,
            'version' => '1.0.0',
            'components' => [
                'basic' => [],
                'composite' => [['name' => $component, 'type' => 'composite', 'props' => []]],
                'layout' => [],
            ],
        ]));
    }
}
