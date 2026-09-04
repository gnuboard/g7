<?php

namespace Tests\Feature\LayoutMarkup;

use App\Enums\ExtensionStatus;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\Traits\CollectsFragmentSources;
use App\Extension\Traits\ComputesLayoutContentHash;
use App\Extension\Traits\RefreshesLayoutExtensions;
use App\Extension\Traits\ResolvesLayoutInstallSource;
use App\Extension\Traits\ValidatesLayoutFiles;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Services\LayoutExtensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 설치 소스 × 수집 경로 매트릭스 봉인 (S18.5 축 B).
 *
 * ## 무엇을 봉인하는가
 *
 * 소스 선택 규약({@see ResolvesLayoutInstallSource})은 한 곳에 있지만, 그것을
 * 부르는 수집 경로는 둘이고(본 레이아웃 · fragment) 그 위에 얹힌 확장 동작은 일곱이다. 종전 테스트는
 * 소스 축의 **세 조합만** 관측했고(`MarkupInstallSourceOverrideTest` — both auto · both forced-json ·
 * markup-only forced-json), `json-only × forced-json` 과 **경고 로그**는 어느 테스트에도 없었다.
 * 경고가 없으면 JSON 강제가 마크업 전용 레이아웃을 만났을 때 그 사실이 어디에도 남지 않는다 —
 * 계획서가 이 칸을 "빈 화면 방지" 로 표시한 이유다.
 *
 * ## 축 둘
 *
 * - **소스 4**: `markup-only` · `json-only` · `both(auto)` · `both(--layout-source=json)`
 * - **동작 7**: `install` · `update(overwrite)` · `update(keep)` · `refresh-layout` ·
 *   `template:activate` · `deactivate` · `uninstall`
 *
 * ## 동작 축을 수집 경로로 접는 근거
 *
 * 일곱 동작이 소스를 각자 고르지 않는다 — **셋 중 하나로 수렴한다**:
 *
 * | 동작 | 소스 수집 |
 * | --- | --- |
 * | install · update(overwrite) · update(keep) · refresh-layout | 본 레이아웃 `validateLayoutFiles()` |
 * | template:activate | fragment `loadFragmentDefinitions()` |
 * | deactivate · uninstall | **읽지 않는다** (DB 행 필터로 지운다 — 소스 무관) |
 *
 * 그래서 이 파일은 수집 경로 둘을 소스 4 전량에 대해 **행동으로** 관측하고, 일곱 동작이 그 셋으로
 * 실제로 수렴하는지를 {@see test_every_operation_routes_to_a_declared_collection_path()} 가 코드에서
 * 확인한다. 동작마다 같은 단언을 네 벌씩 복제하면 표면상 28칸이 되지만 관측하는 명제는 같고,
 * 복제된 단언은 한쪽이 낡을 때 조용히 갈린다(이 트랙이 규약 사본에서 반복해 확인한 형태다).
 *
 * ## 매니페스트 표현 — 축이 아니라 `sub_flows` 인 이유
 *
 * 계획서 축 B 는 이 매트릭스를 시나리오 매니페스트의 **axis 추가**로 적으라고 했다. 착수 실측으로
 * 그것이 실행 불가임을 확인했다 — `test-scenario-coverage` 는 axes 의 **전 곱집합**을 전개하고
 * `@scenario` 마킹과 **정확 일치**로만 커버를 인정한다. `layout_source`(4) 와 `install_operation`(7)
 * 을 최상위 축으로 얹으면 곱집합이 `14 × 2` 에서 `14 × 2 × 4 × 7 = 784` 로 커지고, 기존 마킹은
 * 전부 키 개수가 달라져 **한 칸도 매칭되지 않는다**(그 매니페스트의 커버리지가 통째로 0 이 된다).
 * 그래서 매트릭스는 `sub_flows.layout_source_matrix` 로 등재하고, 곱집합 전수 선언은 이 파일의
 * {@see OPERATION_COLLECTION}·{@see SOURCES} 가 지고 {@see test_operation_axis_covers_all_seven_operations()}
 * 가 잠근다.
 */
class LayoutSourceMatrixTest extends TestCase
{
    use ComputesLayoutContentHash;
    use RefreshDatabase;

    /**
     * keep 보존 축이 `layout_extensions` 실행을 요구하므로, 같은 스위트의 레이아웃 의존
     * 테스트와 `migrate:fresh` 정합을 맞추기 위해 GDPR 마이그레이션을 일관 선언한다.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private Template $template;

    /**
     * 소스 축 — 계획서 축 B 의 4종.
     */
    private const SOURCES = ['markup_only', 'json_only', 'both_auto', 'both_forced_json'];

    /**
     * 동작 축 → 소스 수집 경로. `null` 은 소스를 읽지 않는 동작이다.
     *
     * 이 표가 위 독블록의 산문과 같은 값을 두 곳에 적는 것이 아니라, **판정의 정본**이다 —
     * {@see test_every_operation_routes_to_a_declared_collection_path()} 가 이 표를 코드와 대조한다.
     *
     * @var array<string, string|null>
     */
    private const OPERATION_COLLECTION = [
        'install' => 'validateLayoutFiles',
        'update_overwrite' => 'validateLayoutFiles',
        'update_keep' => 'validateLayoutFiles',
        'refresh_layout' => 'validateLayoutFiles',
        // fragment 수집의 공개 진입점은 `loadFragmentDefinitions()` 다 — 그 안에서
        // `collectFragmentSources()` 가 소스 선택을, `loadFragmentSource()` 가 읽기를 맡는다.
        // 관리자·서비스가 실제로 부르는 이름을 선언해야 이 축이 코드와 대조된다.
        'template_activate' => 'loadFragmentDefinitions',
        'deactivate' => null,
        'uninstall' => null,
    ];

    /**
     * 위 수집 경로를 호출하는 코어 표면 — 선언한 이름이 실재하는지 대조할 모집단.
     *
     * @var array<int, string>
     */
    private const COLLECTION_CALLERS = [
        'app/Extension/ModuleManager.php',
        'app/Extension/PluginManager.php',
        'app/Extension/TemplateManager.php',
        'app/Services/LayoutExtensionService.php',
    ];

    private object $harness;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->setLocale('en');

        $this->harness = new class
        {
            use CollectsFragmentSources;
            use ValidatesLayoutFiles;

            /**
             * @return array<int, array<string, mixed>> 검증된 레이아웃
             */
            public function layouts(string $path, string $id, string $type): array
            {
                return $this->validateLayoutFiles($path, $id, $type, true);
            }

            /**
             * @return array<int, array{path: string, data: array<string, mixed>}> fragment 정의
             */
            public function fragments(string $path): array
            {
                return $this->loadFragmentDefinitions($path, [], ['fragment_dir' => $path]);
            }
        };

        $this->template = Template::factory()->create([
            'identifier' => 'test-admin',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);

        $this->tmp = base_path('storage/framework/testing/layout_source_matrix_'.uniqid());
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    /**
     * 소스 조합별 **등록 집합**과 **content** 가 기대와 같다 (본 레이아웃 수집 경로).
     *
     * 등록 집합을 함께 보는 이유는 content 만 보면 "한 논리 레이아웃이 두 번 등록되는" 회귀를
     * 놓치기 때문이다 — 짝 병존에서 접기가 깨지면 같은 이름이 둘 생긴다.
     *
     * @param  string  $source  소스 조합
     * @param  string  $expected  이겨야 하는 컴포넌트 이름
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects layout_registration_set_and_content_match_the_source_matrix
     */
    #[DataProvider('layoutSourceExpectations')]
    public function test_layout_registration_set_and_content_per_source(string $source, string $expected): void
    {
        $this->seedLayoutSources($source);

        $layouts = $this->harness->layouts($this->tmp, 'gnuboard7-hello_module', 'module');

        $this->assertSame(['demo'], array_column($layouts, 'layout_name'),
            "등록 집합이 기대와 다릅니다 (소스: {$source}) — 짝 접기가 깨지면 같은 이름이 둘 등록된다");

        $this->assertSame($expected, $layouts[0]['data']['components'][0]['name'],
            "이긴 소스의 content 가 기대와 다릅니다 (소스: {$source})");
    }

    /**
     * 소스 조합별 기대값 — 계획서 축 B "기대 결과" 표 그대로.
     *
     * `Div` 는 소스(`.g7.html`)이 이겼다는 뜻이고 `Span` 은 원본 `.json` 이 이겼다는 뜻이다.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function layoutSourceExpectations(): array
    {
        return [
            'markup-only → 소스 등록' => ['markup_only', 'Div'],
            'json-only → JSON 등록' => ['json_only', 'Span'],
            'both(auto) → 마크업 승' => ['both_auto', 'Div'],
            'both(forced-json) → JSON 승' => ['both_forced_json', 'Span'],
        ];
    }

    /**
     * 소스 조합별 **fragment 수집**도 같은 규약을 탄다 (`template:activate` 갈래).
     *
     * @param  string  $source  소스 조합
     * @param  bool  $markupWins  소스가 이겨야 하면 true
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_collection_follows_the_same_source_matrix
     */
    #[DataProvider('fragmentSourceExpectations')]
    public function test_fragment_collection_per_source(string $source, bool $markupWins): void
    {
        $this->seedFragmentSources($source);

        $definitions = $this->harness->fragments($this->tmp);

        $this->assertCount(1, $definitions,
            "fragment 등록 집합이 기대와 다릅니다 (소스: {$source}) — 짝 접기가 깨지면 하나가 둘이 된다");

        // 경로가 아니라 **내용**으로 승자를 본다 — 소스는 `Div`, 원본 `.json` 은 `Span` 을 낸다.
        // 경로 접미사만 보면 "골랐지만 다른 것을 읽는" 형태(29차가 실물로 겪은 자리)를 놓친다.
        $this->assertSame($markupWins ? 'Div' : 'Span',
            $definitions[0]['data']['components'][0]['name'] ?? null,
            "fragment 소스 선택이 기대와 다릅니다 (소스: {$source})");

        $this->assertSame($markupWins, str_ends_with($definitions[0]['path'], '.g7.html'),
            "고른 소스 경로가 읽은 내용과 갈립니다 (소스: {$source})");
    }

    /**
     * fragment 소스 조합별 기대값.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function fragmentSourceExpectations(): array
    {
        return [
            'markup-only → 소스' => ['markup_only', true],
            'json-only → JSON' => ['json_only', false],
            'both(auto) → 마크업 승' => ['both_auto', true],
            // 짝이 있으면 강제가 이행되므로 `.json` 이 이긴다.
            'both(forced-json) → JSON 승' => ['both_forced_json', false],
        ];
    }

    /**
     * `markup-only + forced-json` 은 **누락 0** 이고 그 사실이 경고 로그로 남는다 (빈 화면 방지).
     *
     * 대안이 없어 강제를 이행하지 못했다는 것은 운영자가 알아야 하는 상태다. 조용히 마크업으로
     * 떨어지면 "JSON 으로 되돌려 설치했다" 고 믿은 채 진단이 계속된다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects forced_json_on_markup_only_keeps_the_layout_and_logs_a_warning
     */
    public function test_forced_json_on_markup_only_keeps_the_layout_and_warns(): void
    {
        $this->seedLayoutSources('markup_only');
        config(['template.layout_markup.install_source' => 'json']);

        Log::spy();

        $layouts = $this->harness->layouts($this->tmp, 'gnuboard7-hello_module', 'module');

        $this->assertSame(['demo'], array_column($layouts, 'layout_name'),
            'JSON 강제에서 마크업 전용 레이아웃이 누락됐다 — 등록이 사라지면 빈 화면이 된다');

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []): bool {
                return str_contains($message, 'JSON 강제')
                    && str_contains(json_encode($context, JSON_UNESCAPED_UNICODE) ?: '', 'demo.g7.html');
            })
            ->atLeast()->once();
    }

    /**
     * 짝이 병존하면 JSON 강제는 **이행되므로 경고가 없다** (과탐 방지).
     *
     * 경고가 항상 뜨면 그것은 신호가 아니라 잡음이 되고, 운영자는 진짜 미이행을 구별하지 못한다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects forced_json_does_not_warn_when_the_json_twin_exists
     */
    public function test_forced_json_does_not_warn_when_the_twin_exists(): void
    {
        $this->seedLayoutSources('both_forced_json');

        Log::spy();

        $this->harness->layouts($this->tmp, 'gnuboard7-hello_module', 'module');

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * 고아 정리 목록은 **마크업 기준**이다 — 마크업 전용 레이아웃이 "파일 없음" 으로 분류되지 않는다.
     *
     * `refreshModuleLayouts()` 는 DB 행 중 "수집된 레이아웃 이름 목록에 없는 것" 을 지운다. 그 목록이
     * `.json` 만 세면 마크업 전용 레이아웃은 매 갱신마다 고아로 판정돼 **지워졌다 다시 등록되기를
     * 반복**한다. 목록의 출처가 소스 선택 결과임을 여기서 못박는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects orphan_cleanup_list_is_derived_from_the_selected_sources
     */
    public function test_orphan_cleanup_list_is_derived_from_selected_sources(): void
    {
        File::put("{$this->tmp}/markup_only.g7.html",
            "<layout name=\"markup_only\" version=\"1.0.0\">\n  <div/>\n</layout>\n");
        File::put("{$this->tmp}/json_only.json", json_encode([
            'version' => '1.0.0',
            'layout_name' => 'json_only',
            'components' => [['type' => 'basic', 'name' => 'Span']],
        ]));

        $names = array_column($this->harness->layouts($this->tmp, 'gnuboard7-hello_module', 'module'), 'layout_name');
        sort($names);

        $this->assertSame(['json_only', 'markup_only'], $names,
            '고아 정리 목록의 출처인 수집 결과에 마크업 전용 레이아웃이 빠졌다 — 그러면 갱신마다 '
            .'삭제 후 재등록을 반복한다');
    }

    /**
     * `update(keep)` 의 **관리자 편집분 보존이 소스 선택과 독립**이다 — 소스 4 전량 관측.
     *
     * 이 칸은 다른 여섯 칸과 성질이 다르다. 나머지는 "어느 소스를 읽는가" 라 수집 경로 축으로
     * 접히지만, 보존 판정은 **수집이 끝난 뒤** 일어난다
     * ({@see RefreshesLayoutExtensions::refreshExtensionLayoutExtensions()} 가
     * `loadFragmentDefinitions()` 로 모으고 `registerExtension($…, $preserveModified)` 로 등록한다).
     * 그래서 "같은 수집 경로를 탄다" 는 이 명제를 증명하지 않는다 — 보존이 소스에 따라 갈리는
     * 회귀는 수집 축이 전건 green 인 채로도 성립한다.
     *
     * 대조군을 같은 provider 로 함께 돌리는 이유는 **이 단언이 공허하게 참이 되는 길이 있기
     * 때문**이다: 수집이 0건이면 등록 자체가 일어나지 않아 사용자 content 는 당연히 남는다.
     * {@see test_keep_overwrites_with_the_source_appropriate_content_when_not_preserving()} 가
     * 같은 하네스에서 소스별로 **다른** content 가 실제로 들어오는 것을 보이므로, 위 보존 단언은
     * "들어올 것이 있었는데 막았다" 를 뜻하게 된다.
     *
     * @param  string  $source  소스 조합
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects keep_strategy_preserves_admin_edits_regardless_of_source_selection
     */
    #[DataProvider('keepStrategySources')]
    public function test_keep_preserves_admin_edits_for_every_source(string $source): void
    {
        $this->seedFragmentSources($source);

        $adminEdit = ['extension_point' => 'probe_point', 'mode' => 'append', 'priority' => 50,
            'components' => [['type' => 'basic', 'name' => 'Section']]];
        $extension = $this->seedAdminEditedExtension($adminEdit);

        $definitions = $this->harness->fragments($this->tmp);
        $this->assertCount(1, $definitions, "수집이 0건이면 보존 단언이 공허하다 (소스: {$source})");

        $result = app(LayoutExtensionService::class)->registerExtension(
            $definitions[0]['data'],
            LayoutSourceType::Module,
            'gnuboard7-hello_module',
            $this->template->id,
            true,
        );

        $this->assertSame('skipped', $result,
            "관리자 편집분이 보존되지 않았다 (소스: {$source}) — keep 판정이 소스에 따라 갈린다");

        $extension->refresh();
        $this->assertSame($adminEdit['components'], $extension->content['components'],
            "보존은 선언됐는데 content 가 덮였다 (소스: {$source})");
    }

    /**
     * 대조군 — `preserveModified=false` 면 **소스에 맞는** content 로 덮인다.
     *
     * 위 보존 단언의 관측력을 보증한다. 여기서 소스별로 서로 다른 값이 들어오는 것이 확인되므로,
     * 보존 쪽의 "안 덮였다" 는 "덮을 것이 없었다" 가 아니다.
     *
     * @param  string  $source  소스 조합
     * @param  string  $expected  덮어쓴 뒤 남아야 하는 컴포넌트 이름
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects keep_strategy_preserves_admin_edits_regardless_of_source_selection
     */
    #[DataProvider('fragmentSourceExpectations')]
    public function test_keep_overwrites_with_the_source_appropriate_content_when_not_preserving(
        string $source,
        bool $markupWins,
    ): void {
        $this->seedFragmentSources($source);

        $extension = $this->seedAdminEditedExtension(['extension_point' => 'probe_point', 'mode' => 'append',
            'priority' => 50, 'components' => [['type' => 'basic', 'name' => 'Section']]]);

        $definitions = $this->harness->fragments($this->tmp);

        $result = app(LayoutExtensionService::class)->registerExtension(
            $definitions[0]['data'],
            LayoutSourceType::Module,
            'gnuboard7-hello_module',
            $this->template->id,
            false,
        );

        $this->assertSame('updated', $result, "덮어쓰기 전략이 등록을 건너뛰었다 (소스: {$source})");

        $extension->refresh();
        $this->assertSame($markupWins ? 'Div' : 'Span', $extension->content['components'][0]['name'] ?? null,
            "덮어쓴 content 가 이긴 소스와 다르다 (소스: {$source}) — 소스 축이 이 하네스에서 죽어 있다");
    }

    /**
     * keep 보존 축이 도는 소스 — 선언 축 그대로다(사본이 아니라 인용).
     *
     * @return array<string, array{0: string}>
     */
    public static function keepStrategySources(): array
    {
        return array_combine(
            array_map(static fn (string $s): string => "{$s} → 관리자 편집분 보존", self::SOURCES),
            array_map(static fn (string $s): array => [$s], self::SOURCES),
        );
    }

    /**
     * 관리자가 편집한(= 현재 content 가 `original_content_hash` 와 어긋나는) 확장 행을 심는다.
     *
     * @param  array<string, mixed>  $adminEdit  관리자가 만든 현재 content
     * @return LayoutExtension 심은 행
     */
    private function seedAdminEditedExtension(array $adminEdit): LayoutExtension
    {
        // 모듈이 처음 심은 원본과 다른 content 를 현재값으로 둬 해시 불일치를 만든다.
        $original = ['extension_point' => 'probe_point', 'mode' => 'append', 'priority' => 50, 'components' => []];

        $extension = LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'probe_point',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'gnuboard7-hello_module',
            'content' => $original,
            'original_content_hash' => $this->computeContentHash($original),
        ]);

        $extension->update(['content' => $adminEdit]);

        return $extension;
    }

    /**
     * 일곱 동작이 선언된 수집 경로로 실제로 수렴한다 (동작 축 봉인).
     *
     * 동작마다 소스 4벌 단언을 복제하는 대신, **동작이 소스를 어디서 얻는가**를 코드에서 확인한다.
     * 복제된 단언은 한쪽이 낡을 때 조용히 갈리지만, 이 축은 동작이 규약 밖으로 나가는 순간 red 다.
     *
     * 소스를 읽지 않는 동작(`deactivate`·`uninstall`)은 **읽지 않음**을 단언한다 — 그것이 "소스 무관
     * DB 행 제거" 의 기계적 표현이다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects every_extension_operation_routes_layout_sources_through_the_convention
     */
    public function test_every_operation_routes_to_a_declared_collection_path(): void
    {
        $managers = [];

        foreach (self::COLLECTION_CALLERS as $relative) {
            $this->assertFileExists(base_path($relative), "수집 경로 호출자가 없습니다: {$relative}");

            $managers[$relative] = File::get(base_path($relative));
        }

        $this->assertNotEmpty($managers, '확장 관리자 도출이 죽었다');

        $collections = array_values(array_unique(array_filter(self::OPERATION_COLLECTION)));

        $this->assertNotEmpty($collections, '수집 경로 선언이 비었다 — 이 축이 공허해진다');

        // 선언된 수집 경로가 코어에 실재해야 한다 (낡은 이름으로 초록이 되는 것을 막는다).
        foreach ($collections as $method) {
            $found = false;

            foreach ($managers as $source) {
                $found = $found || str_contains($source, $method.'(');
            }

            $this->assertTrue($found,
                "선언한 수집 경로가 확장 관리자 어디에서도 호출되지 않습니다: {$method}() — "
                .'이름이 바뀌었다면 이 축은 이미 죽은 상태다');
        }

        // 소스를 읽지 않는 동작은 삭제 경로가 파일이 아니라 DB 필터를 쓴다.
        $deletionMethods = ['softDeleteModuleLayouts', 'deleteModuleLayouts'];

        foreach ($deletionMethods as $method) {
            $body = $this->methodBody($managers['app/Extension/ModuleManager.php'], $method);

            $this->assertNotNull($body, "삭제 경로 메서드를 찾지 못했습니다: {$method}()");

            foreach ($collections as $collection) {
                $this->assertStringNotContainsString($collection.'(', $body,
                    "{$method}() 가 레이아웃 소스를 수집합니다 — deactivate/uninstall 은 소스 무관 "
                    .'DB 행 제거여야 한다 (파일이 사라진 뒤에도 행이 지워져야 하므로).');
            }
        }
    }

    /**
     * 동작 축 선언이 계획서의 일곱 동작을 전부 담는다 (누락되면 그 동작은 매트릭스 밖이다).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects operation_axis_declaration_covers_every_planned_operation
     */
    public function test_operation_axis_covers_all_seven_operations(): void
    {
        $this->assertSame([
            'install',
            'update_overwrite',
            'update_keep',
            'refresh_layout',
            'template_activate',
            'deactivate',
            'uninstall',
        ], array_keys(self::OPERATION_COLLECTION),
            '동작 축 선언이 계획서 축 B 의 일곱 동작과 다릅니다 — 빠진 동작은 매트릭스 밖에 남는다');

        $this->assertSame(['markup_only', 'json_only', 'both_auto', 'both_forced_json'], self::SOURCES,
            '소스 축 선언이 계획서 축 B 의 4종과 다릅니다');
    }

    /**
     * 소스 조합에 맞춰 본 레이아웃 파일을 씁니다.
     *
     * @param  string  $source  소스 조합
     */
    private function seedLayoutSources(string $source): void
    {
        $this->assertContains($source, self::SOURCES, "선언되지 않은 소스 조합: {$source}");

        if ($source !== 'json_only') {
            File::put("{$this->tmp}/demo.g7.html", "<layout name=\"demo\" version=\"1.0.0\">\n  <div/>\n</layout>\n");
        }

        if ($source !== 'markup_only') {
            File::put("{$this->tmp}/demo.json", json_encode([
                'version' => '1.0.0',
                'layout_name' => 'demo',
                'components' => [['type' => 'basic', 'name' => 'Span', 'text' => 'json']],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        config(['template.layout_markup.install_source' => $source === 'both_forced_json' ? 'json' : 'auto']);
    }

    /**
     * 소스 조합에 맞춰 fragment 파일을 씁니다.
     *
     * @param  string  $source  소스 조합
     */
    private function seedFragmentSources(string $source): void
    {
        $this->assertContains($source, self::SOURCES, "선언되지 않은 소스 조합: {$source}");

        if ($source !== 'json_only') {
            File::put("{$this->tmp}/widget.g7.html",
                "<extension extension-point=\"probe_point\" mode=\"append\" priority=\"50\">\n  <div/>\n</extension>\n");
        }

        if ($source !== 'markup_only') {
            File::put("{$this->tmp}/widget.json", json_encode([
                'extension_point' => 'probe_point',
                'mode' => 'append',
                'priority' => 50,
                'components' => [['type' => 'basic', 'name' => 'Span']],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        config(['template.layout_markup.install_source' => $source === 'both_forced_json' ? 'json' : 'auto']);
    }

    /**
     * 소스에서 메서드 하나의 본문을 잘라냅니다 (다음 함수 선언까지).
     *
     * @param  string  $source  클래스 소스
     * @param  string  $method  메서드 이름
     * @return string|null 본문 (찾지 못하면 null)
     */
    private function methodBody(string $source, string $method): ?string
    {
        if (preg_match('/function\s+'.preg_quote($method, '/').'\s*\(/', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $start = $match[0][1];
        $next = preg_match('/function\s+\w+\s*\(/', $source, $after, PREG_OFFSET_CAPTURE, $start + 1) === 1
            ? $after[0][1]
            : strlen($source);

        return substr($source, $start, $next - $start);
    }
}
