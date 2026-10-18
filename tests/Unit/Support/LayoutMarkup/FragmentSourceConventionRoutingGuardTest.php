<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Extension\Traits\CollectsFragmentSources;
use Illuminate\Support\Facades\File;
use Tests\Support\GateTestPopulation;
use Tests\Support\InjectsGuardSamples;
use Tests\Support\LayoutSourceConvention;

/**
 * "규약을 **한 곳**에 뒀다" 는 선언을 호출 그래프로 대조한다 (30차 근본 조치 C).
 *
 * 이 트랙이 세 회차 연속 같은 문장에서 무너졌다:
 *
 * - 27차 — "우선순위 규약은 `layoutSourceCandidates()` 한 곳에 두고 셋이 호출한다" 고 적었으나
 *   실 호출은 둘이었고 디렉토리 스캔 두 벌이 짝 판별 18줄을 각자 복제하고 있었다(29차 검출).
 * - 28차 — "확장이 자기 확장점을 직접 읽는 자리는 코어 진입점 하나로 모았다" 고 적었으나 사본
 *   셋이 남아 있었다(그 사본을 막는다던 가드가 표기 하나 때문에 못 봤다).
 * - 29차 — "다섯을 한 헬퍼로 모았다" 고 적었으나 한 자리는 **선택만** 규약을 타고 읽기는 인라인
 *   사본이었고, 30차에 **네 번째 갈래**(`TemplateManager`)에서 같은 형태가 또 나왔다.
 *
 * 세 번 다 **선언은 독블록 산문**이었고 그것을 실제 호출과 맞춰 보는 장치가 없었다. 산문은
 * 낡아도 아무것도 깨뜨리지 않으므로 사람의 스윕만이 잡고, 스윕은 표집이다.
 *
 * 그래서 두 명제를 기계가 검사한다:
 *
 * 1. **fragment 정의 목록을 받는 자리는 읽기까지 규약에 위임한다** — `getLayoutExtensions()` 를
 *    부르는 **메서드**는 같은 본문에서 `loadFragmentDefinitions()` 를 함께 불러야 한다.
 *    (33차 — 파일 단위 공존 판정은 위임 파일 안의 새 인라인 소비를 못 봤다. 32차 ⓑ 가
 *    하한 의무를 파일→메서드로 내린 것과 같은 입도 정정이다. 같은 메서드 안에 위임과 인라인
 *    파싱이 공존하는 형태는 명제 2 의 파싱 신호 축이 담당한다.)
 * 2. **선택만 규약을 타고 읽기를 자기가 하는 자리가 없다** — `collectFragmentSources()` 를 부르는
 *    파일(트레이트 자신 제외)은 파싱을 직접 하면 안 된다. 30차의 `TemplateManager` 가 정확히
 *    이 형태였고, 그 사본은 트레이트보다 약해서(배열 검증 부재 · `\Exception` 포착) 조각 하나가
 *    템플릿 활성화 전체를 세울 수 있었다.
 */
class FragmentSourceConventionRoutingGuardTest extends LayoutMarkupTestCase
{
    use InjectsGuardSamples;

    /**
     * 검사 모집단 — 코어 전량에서 도출한다 (파일 열거 금지).
     */
    private const SOURCE_ROOT = 'app';

    /**
     * 규약 트레이트 자신 — 정의처는 검사 대상이 아니다.
     */
    private const CONVENTION_TRAIT = 'app/Extension/Traits/CollectsFragmentSources.php';

    /**
     * 조각 경로 규약의 소유자 — 정의처는 사본이 아니다.
     */
    private const PARTIAL_CONVENTION_OWNER = 'app/Extension/Traits/ValidatesLayoutFiles.php';

    /**
     * 소스 선택 규약에 위임하는 표기 — 이 중 하나라도 있으면 `.json` 전용 소비처가 아니다.
     *
     * 34차 — 목록의 정본은 {@see LayoutSourceConvention::DELEGATIONS} 다. 오라클 독립성 가드가
     * 같은 표면을 **다른 값**으로 따로 들고 있었고(진입점 셋이 빠져 있었다), 목록이 둘이면 다음
     * 진입점이 생길 때 한쪽만 갱신된다. 규약을 한 곳에 모은 27차 교훈을 그 규약을 **아는 목록**
     * 에도 적용한다.
     */
    private const LAYOUT_SOURCE_DELEGATIONS = LayoutSourceConvention::DELEGATIONS;

    /**
     * 알려진 `.json` 전용 레이아웃 소비처 → 사유·소유 (32차 신설 축).
     *
     * @var array<string, string>
     */
    private const JSON_ONLY_LAYOUT_CONSUMER_DEBT = [
        // **S18.5 해소 — `AddLayoutPermissionsCommand` 가 여기서 빠졌다.** 32차가 이 자리를 등재하며
        // 적은 사유가 *"S18 P2 가 `.json` 을 기계 산출로 바꾸면 이 커맨드의 편집은 다음 굽기에
        // 소실된다 — 착수 시 소스를 고치도록 전환해야 하는 자리"* 였고, 그 전환을 S18.5 가
        // 이행했다: 모집단을 `LayoutCorpus`(번들 트리)로 옮기고 소스 선택을
        // `mergeLayoutSourcesByPriority()` 에 위임해, 이긴 소스가 소스면 `<permissions>` 를
        // 고쳤다. 그 뒤 2026-09-28 에 커맨드 자체가 삭제됐다 — 권한은 각 레이아웃 소스에 이미 들어 있고,
        // 코어가 확장 레이아웃→권한 표를 들고 있는 것이 코어·확장 책임 경계 위반이었다.
        'app/Console/Commands/PlaywrightSeedLayout.php' => 'Playwright E2E 전용 시드 화면 '
            .'(`e2e_sandbox`)을 활성 템플릿에 깔고 지우는 커맨드. 원본은 `tests/Playwright/fixtures/'
            .'seed-layouts/*.json` 이고 **소스 짝이 없으며, 소스 코퍼스 스캔 대상도 아니다** '
            .'(`LayoutCorpus` 는 번들 확장·템플릿 트리만 훑는다). 커맨드는 그 파일을 디코드해 '
            .'시드 DB 행 `content` 에 그대로 싣는 것이 전부라, 소스로 옮겨도 곧바로 같은 배열로 '
            .'되돌려야 한다 — 규약 위반이 아니라 **테스트 자료의 의도된 예외**이며, 등재 목적은 '
            .'"다음 사람이 이것을 사본으로 오인해 고치지 않게" 다 (설계 판정, 5차 병합 후속).',
        // ── #525(`221beb3a4`) 에서 `app/Extension/AbstractPlugin.php` 가 이 목록에서 빠졌다.
        //
        // 등재 사유는 "`getSettingsLayout()` 의 `plugin_settings.json` 경로 하드코딩 — 플러그인 설정 UI 는
        // 파일에서 직접 읽으므로 짝 `.json` 이 디스크에 있어야 한다" 는 명문 설계였다. 반응 레이아웃 복원
        // 작업이 그 메서드를 `layoutSourceCandidates()` 규약으로 옮겨 `.g7.html` 소스를 먼저 찾게 했고,
        // 그때 원장에서 빼지 않아 이 게이트가 4라운드 뒤(develop-7.1.0 2차 병합 전수 보존 검사, 2026-09-17)
        // red 로 드러났다. 아래 `PluginSettingsService` 항목은 여전히 `.json` 을 직접 읽는 소비 측이라 남는다.
        'app/Services/PluginSettingsService.php' => '`AbstractPlugin::getSettingsLayout()` 예외의 소비 측. 명문 '
            .'설계(AGENTS.md "확장 시스템 빠른 참조")에 따라 `plugin_settings.json` 과 템플릿 오버라이드본을 직접 읽는다. '
            .'31·32차가 각각 이 자리를 사본으로 오검출했으므로, 오검출이 재발하지 않도록 사유를 '
            .'여기 못박는다.',
        // ── S19 ⑦ (D66 ⓒ) 에서 `app/Services/TemplateService.php` 가 이 목록에서 빠졌다.
        //
        // 등재 사유는 "트리 스캔이 `getExtension() !== 'json'` 으로 걸러 마크업 전용 레이아웃이
        // 트리에 안 보인다" 였고, R1·R16 이 사용자 가시 변경이라 설계 판정 대기 중이었다. D66 ⓒ 가
        // S19 편입으로 확정해 그 전환을 이행했다 — 열거는 재귀로 하되 짝 판별·우선순위·읽기를
        // `mergeLayoutSourcesByPriority()` / `loadFragmentSource()` 에 위임한다.
        //
        // 이 가드가 그 완료를 알린 방식을 남겨 둔다: 전환 직후 **이 단언이 red 였다**
        // ("등재분을 고쳤다면 목록에서 제거해야 한다"). 부채 목록이 스스로 낡음을 신고하는 형태가
        // 이 저장소에서 실제로 작동한 자리다.
    ];

    /**
     * 알려진 조각 경로 규약 사본 → 사유·소유. 늘지 않게 잠그고, 고쳐지면 목록에서 뺀다.
     *
     * (34차 — 이 독블록은 종전에 `LAYOUT_SOURCE_DELEGATIONS` 앞에 붙어 있었다. PHP 는 마지막
     * 독블록만 상수에 귀속하므로 동작에는 영향이 없으나, 사유 문서가 엉뚱한 상수를 설명하고 있어
     * 다음 사람이 위임 표기 목록을 "사본 대장" 으로 오독할 수 있었다.)
     *
     * @var array<string, string>
     */
    private const PARTIAL_CONVENTION_DEBT = [
        // ── S19 ⑦ (D66 ⓒ) 에서 비었다.
        //
        // 등재분은 `TemplateService::resolveModalPartial()` 이었다. 규약을 자기 코드로 복제하고
        // `.json` 만 읽었으며, "짝이 전건 병존해 현재 화면 결함은 없다" 를 근거로 잠가 두었다.
        // 그 근거가 위태로운 종류였다는 것이 이번 전환의 이유다 — 결함이 없는 것이 아니라
        // **짝이 있는 동안 가려져 있었을 뿐**이고, 소스만 고친 조각은 반영되지 않았다.
        //
        // 이제 확장자 후보는 `layoutSourceCandidates()`, 읽기는 `loadFragmentSource()` 가 정한다.
    ];

    /**
     * fragment 정의 목록을 받는 자리는 읽기까지 규약에 위임한다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_definition_consumers_delegate_reading_to_convention
     *
     * @return void 반환값 없음
     */
    public function test_fragment_definition_consumers_delegate_reading(): void
    {
        $consumers = $this->coreFilesCalling('getLayoutExtensions(');

        $this->assertNotEmpty($consumers,
            'fragment 정의 목록을 받는 자리가 0건이다 — 계약 이름이 바뀌어 판정식이 구조적으로 '
            .'아무것도 보지 못하는 상태인지 먼저 확인해야 한다');

        $violations = [];

        foreach ($consumers as $file => $code) {
            // 33차 — 판정 입도는 메서드 본문이다. 파일 단위 공존은 위임 파일 안의 새 인라인
            // 소비를 가린다 (이웃 메서드의 위임이 알리바이가 된다).
            foreach ($this->methodBodies($code) as $method => $body) {
                if (! str_contains($body, 'getLayoutExtensions(')) {
                    continue;
                }

                if (! str_contains($body, 'loadFragmentDefinitions(')) {
                    $violations[] = $file.'::'.$method;
                }
            }
        }

        $this->assertSame([], $violations,
            'fragment 정의 목록을 받아 놓고 같은 메서드에서 읽기를 규약에 위임하지 않는 자리가 '
            .'있습니다 — `.g7.html` 소스가 그 경로에서만 조용히 무시된다.');
    }

    /**
     * 33차 자기시험 — 위임 파일 **안**의 인라인 소비를 잡는다 (판정 입도의 사각).
     *
     * 32차까지 명제 1 의 판정은 파일 단위 문자열 공존이었다 — 파일 어딘가에
     * `loadFragmentDefinitions(` 가 한 번이라도 있으면, 같은 파일의 **다른 메서드**가
     * `getLayoutExtensions()` 를 받아 `json_decode` 로 직접 읽어도 통과했다. 30차 ❌3 의
     * `TemplateManager` 가 정확히 "이미 위임 중인 파일에 남은 인라인 사본" 이었는데, 그 형태가
     * 재발하면 이 가드가 못 보는 상태였다. 표본은 파이프라인 입구(모집단)로 주입한다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects fragment_delegation_guard_detects_method_level_inline_consumption
     *
     * @return void 반환값 없음
     */
    public function test_self_rejects_inline_consumption_hidden_in_delegating_file(): void
    {
        $this->assertGuardRejects(
            'inline_fragment_read_in_delegating_file.php',
            <<<'PHP'
            <?php

            class InjectedInlineFragmentReadInDelegatingFileSample
            {
                public function register(object $module): void
                {
                    $definitions = $this->loadFragmentDefinitions(
                        $module->getExtensionsPath(),
                        $module->getLayoutExtensions(),
                        []
                    );
                }

                public function sneakyRead(object $module): array
                {
                    $raw = [];

                    foreach ($module->getLayoutExtensions() as $file) {
                        $raw[] = json_decode(file_get_contents($file), true);
                    }

                    return $raw;
                }
            }
            PHP,
            fn () => $this->test_fragment_definition_consumers_delegate_reading(),
            '위임 메서드 옆에 숨은 인라인 소비 (30차 TemplateManager 형태의 재발판)',
        );
    }

    /**
     * 33차 자기시험 — 중간 변수 경유 위임은 준수 형태다 (과탐 반례).
     *
     * `ModuleManager::registerLayoutExtensions()` 실물이 이 형태다 — 반환값을 변수에 받아
     * 두 줄 뒤 `loadFragmentDefinitions()` 로 넘긴다. 호출지점 중첩(인자 안에서 직접 호출)을
     * 요구하는 판정이면 실물 다섯 자리 중 둘이 과탐된다. 판정 입도는 **메서드 본문**이다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_delegation_guard_accepts_intermediate_variable_delegation
     *
     * @return void 반환값 없음
     */
    public function test_self_accepts_intermediate_variable_delegation(): void
    {
        $this->assertGuardAccepts(
            'intermediate_variable_delegation.php',
            <<<'PHP'
            <?php

            class InjectedIntermediateVariableDelegationSample
            {
                public function register(object $module): void
                {
                    $extensionFiles = $module->getLayoutExtensions();

                    $definitions = $this->loadFragmentDefinitions(
                        $module->getExtensionsPath(),
                        $extensionFiles,
                        []
                    );
                }
            }
            PHP,
            fn () => $this->test_fragment_definition_consumers_delegate_reading(),
            'ModuleManager 실물 형태 (중간 변수 경유 위임)',
        );
    }

    /**
     * 선택만 규약을 타고 읽기는 자기가 하는 자리가 없다.
     *
     * 사본은 반드시 원본보다 약해진다 — 30차 실측: `TemplateManager` 사본은 `is_array` 검사가
     * 없어 스칼라 JSON 이 등록에 닿았고, 포착이 `\Exception` 이라 거기서 나는 `TypeError` 를
     * 못 잡아 나머지 확장점까지 함께 사라졌다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects convention_selection_callers_do_not_reimplement_reading
     *
     * @return void 반환값 없음
     */
    public function test_selection_callers_do_not_reimplement_reading(): void
    {
        $callers = $this->coreFilesCalling('collectFragmentSources(');
        $violations = [];

        foreach ($callers as $file => $code) {
            if ($file === self::CONVENTION_TRAIT) {
                continue;
            }

            $parsing = array_values(array_filter(
                ['json_decode(', 'file_get_contents(', 'File::get(', 'File::json('],
                static fn (string $signal): bool => str_contains($code, $signal)
            ));

            if ($parsing !== []) {
                $violations[$file] = $parsing;
            }
        }

        $this->assertSame([], $violations,
            '소스 선택만 규약을 타고 읽기를 다시 구현한 자리가 있습니다 — 사본은 원본보다 약해지고, '
            .'그 약함이 사용자에게 도달한다. `loadFragmentDefinitions()`/`loadFragmentSource()` 로 '
            .'위임해야 한다.');

        // 검사 0건과 위반 0건은 다른 명제다. 이 축은 "위임이 끝나 호출처가 없는" 상태가 정상
        // 종착지일 수 있어 호출처 수에는 하한을 걸 수 없다. 그래서 **모집단을 도출하는 이름 자체**를
        // 잠근다 — 31차까지 이 자리는 `assertGreaterThanOrEqual(0, $inspected)` 로 끝났는데 정수는
        // 항상 0 이상이라 red 가 될 수 없었고, 보완 단언이 확인하던 이름(`loadFragmentDefinitions`)은
        // 모집단 도출에 쓰이는 이름(`collectFragmentSources`)이 아니었다. 즉 후자를 리네임하면
        // 호출처가 0건이 된 채 세 단언 전부 초록이었다. 두 이름 모두 실재를 요구한다.
        $conventionHolder = new class
        {
            use CollectsFragmentSources;
        };

        foreach (['loadFragmentDefinitions', 'collectFragmentSources'] as $entryPoint) {
            $this->assertTrue(method_exists($conventionHolder, $entryPoint),
                "규약 진입점이 사라졌습니다: {$entryPoint}() — 이 이름으로 모집단을 도출하므로, "
                .'이름이 바뀌면 이 가드는 검사 0건인 채 조용히 통과한다');
        }
    }

    /**
     * 조각(partial) 경로 규약을 자기 힘으로 다시 구현한 자리가 늘지 않는다 (31차 신설 — 근본 E).
     *
     * `ValidatesLayoutFiles::resolvePartialPath()` 는 "호스트 디렉토리 → layouts 루트 폴백 →
     * layouts 밖 거부" 라는 규약을 갖는다. 이 규약을 자기 코드로 다시 쓰면 사본이 되고, 사본은
     * 반드시 원본보다 약해진다(28~30차가 fragment 축에서 세 번 확인한 사실).
     *
     * 31차 실측으로 **`app/` 에 사본 한 벌이 실재**한다 —
     * `TemplateService::resolveModalPartial()`. 독블록이 스스로 "경로 규약은 레이아웃 partial 해석과
     * 동형" 이라 적어 두고 `.json` 만 읽으며 실패는 조용히 `null` 이다. 다만 이 자리는 **레이아웃
     * 편집기 표면**이고, 계획서 잔여 대장 R1 이 같은 표면의 `.json` 결합을 "사용자 가시 동작 변경"
     * 으로 분류해 설계 판정(S20 또는 S18 P3)으로 미뤄 둔 상태다. 그래서 이 회차는 고치지 않고
     * **늘지 않게 잠근다** — 알려진 부채는 사유와 함께 등재하고, 등재 밖의 새 사본은 red 다.
     *
     * 래칫이 거짓말로 굳지 않도록 등재분이 **실제로 사본 형태인지** 함께 확인한다(고쳐졌으면 목록
     * 에서 빠지도록 강제).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects partial_path_convention_copies_do_not_increase_beyond_registered_debt
     *
     * @return void 반환값 없음
     */
    public function test_partial_path_convention_copies_do_not_increase(): void
    {
        $copies = [];
        $scanned = 0;

        foreach (File::allFiles(base_path(self::SOURCE_ROOT)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $scanned++;

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

            if ($relative === self::PARTIAL_CONVENTION_OWNER) {
                continue;
            }

            $code = $this->codeWithoutComments(File::get($file->getPathname()));

            if ($this->reimplementsPartialPathConvention($code)) {
                $copies[] = $relative;
            }
        }

        sort($copies);

        // 32차 ⚠️ — 이 단언의 검출력은 등재 부채가 비어 있지 않다는 우연에 기대고 있었다.
        // R16 이 해소돼 목록이 비면 스캔이 0건이어도 `[] === []` 로 조용히 통과한다.
        $this->assertGreaterThan(0, $scanned,
            self::SOURCE_ROOT.' 하위 PHP 소스가 0건 스캔됐다 — 도출이 죽으면 사본이 몇 벌이든 '
            .'이 단언은 통과한다');

        $registered = array_keys(self::PARTIAL_CONVENTION_DEBT);
        sort($registered);

        $this->assertSame($registered, $copies,
            '조각 경로 규약 사본 집합이 등재된 부채와 다릅니다. 새로 생겼다면 '
            .'`resolvePartialSource()`/`resolvePartialPath()` 로 위임해야 하고, 등재분을 고쳤다면 '
            .'PARTIAL_CONVENTION_DEBT 에서 제거해야 한다 (남겨 두면 그 자리는 영구히 검사 밖이다).');
    }

    /**
     * 레이아웃 소스를 `.json` 전용으로 소비하는 코어 코드가 늘지 않는다 (32차 ⚠️ 신설 축).
     *
     * 종전 두 명제는 **fragment 축**(확장점 정의)과 **조각 경로 규약 축**만 봤다. 그런데 작성
     * SSoT 를 무너뜨리는 자리는 그 둘만이 아니다 — 레이아웃 파일을 열거하거나 읽으면서 `.json`
     * 을 하드코딩하면, 그 소비처에서만 소스가 없는 것처럼 보인다. 32차 실측으로 `app/` 에
     * 그런 자리가 넷 있었고 어느 가드의 모집단에도 없었다.
     *
     * 짝이 전건 병존하는 지금은 화면 결함이 0 이지만, S18 P2 가 `.json` 을 **기계 산출**로
     * 바꾸는 순간 성격이 달라진다 — 특히 `.json` 에 in-place 로 기록하는 자리는 그 편집이 다음
     * 굽기에 소실된다. 그래서 고치지 않고 **늘지 않게 잠근다**(등재 밖 새 소비처는 red).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects json_only_layout_consumers_do_not_increase_beyond_registered_debt
     *
     * @return void 반환값 없음
     */
    public function test_json_only_layout_consumers_do_not_increase(): void
    {
        $consumers = [];
        $scanned = 0;

        foreach (File::allFiles(base_path(self::SOURCE_ROOT)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $scanned++;

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            $code = $this->codeWithoutComments(File::get($file->getPathname()));

            if ($this->consumesLayoutSourcesAsJsonOnly($code)) {
                $consumers[] = $relative;
            }
        }

        sort($consumers);

        $this->assertGreaterThan(0, $scanned,
            self::SOURCE_ROOT.' 하위 PHP 소스가 0건 스캔됐다 — 도출이 죽으면 소비처가 몇 곳이든 '
            .'이 단언은 통과한다');

        $registered = array_keys(self::JSON_ONLY_LAYOUT_CONSUMER_DEBT);
        sort($registered);

        $this->assertSame($registered, $consumers,
            '레이아웃 소스를 `.json` 전용으로 소비하는 자리가 등재된 부채와 다릅니다. 새로 '
            .'생겼다면 `layoutSourceCandidates()` 규약을 타야 하고, 등재분을 고쳤다면 '
            .'JSON_ONLY_LAYOUT_CONSUMER_DEBT 에서 제거해야 한다.');
    }

    /**
     * 코드가 레이아웃 소스를 `.json` 전용으로 소비하는지 판정합니다.
     *
     * 세 조건을 함께 갖출 때만 소비처로 본다 — ⓐ 레이아웃 경로를 안다 ⓑ `.json` 을 하드코딩해
     * 고르거나 만든다 ⓒ 소스 선택 규약에 위임하지 않는다.
     *
     * @param  string  $code  주석이 제거된 PHP 코드
     * @return bool `.json` 전용 소비처면 true
     */
    private function consumesLayoutSourcesAsJsonOnly(string $code): bool
    {
        // 34차 — 판정 입도는 **메서드 본문**이다. 33차가 명제 1 에서 고친 사각(이웃 메서드의
        // 위임이 알리바이가 된다)이 이 축에는 그대로 남아 있었고, 그 사각에 실물이 있었다 —
        // `TemplateManager::validateErrorLayouts()` 가 에러 레이아웃을 `.json` 전용으로 찾는데,
        // 같은 파일 다른 메서드의 `loadFragmentDefinitions(` 하나가 파일 전체를 검사 밖으로
        // 밀어냈다. 30차 ❌3 과 같은 파일·같은 형태의 재발이다.
        // 레이아웃 경로를 **아는가**는 클래스 맥락이다 — 당시 실물 두 자리(삭제된 `AddLayoutPermissionsCommand`·
        // `TemplateService`)가 경로 조립과 `.json` 선별을 서로 다른 메서드에 두고 있으므로, 두
        // 신호를 같은 메서드에서 요구하면 이미 등재된 부채를 놓친다. 반면 **`.json` 을 고르는
        // 행위**와 **규약 위임**은 같은 자리에서 일어나므로 메서드 단위로 본다. 알리바이는 이
        // 비대칭에서 사라진다 — 이웃 메서드가 위임한다고 해서 이 메서드의 `.json` 선별이 면제되지
        // 않는다.
        if (! $this->knowsLayoutPaths($code)) {
            return false;
        }

        foreach ($this->judgementUnits($code) as $unit) {
            if ($this->unitSelectsJsonWithoutDelegating($unit)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 코드가 레이아웃 경로를 아는지 판정합니다 (클래스 맥락).
     *
     * @param  string  $code  주석이 제거된 PHP 코드
     * @return bool 레이아웃 경로를 알면 true
     */
    private function knowsLayoutPaths(string $code): bool
    {
        foreach (['resources/layouts', "'layouts'", '/layouts', 'plugin_settings'] as $signal) {
            if (str_contains($code, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 판정 단위 하나가 규약에 위임하지 않은 채 `.json` 을 고르는지 판정합니다.
     *
     * @param  string  $unit  메서드 본문 또는 클래스 본문 잔여
     * @return bool 위임 없이 `.json` 을 고르면 true
     */
    private function unitSelectsJsonWithoutDelegating(string $unit): bool
    {
        foreach (self::LAYOUT_SOURCE_DELEGATIONS as $delegation) {
            if (str_contains($unit, $delegation)) {
                return false;
            }
        }

        // 이 단위가 다루는 것이 **레이아웃**인지 확인한다. 파일 맥락만 보면 같은 클래스의 무관한
        // `.json` 조립이 함께 잡힌다(실측 과탐: `ModuleManager` 의 `settings/{category}.json`).
        // 변수·경로·메서드 어디에 나타나든 `layout` 이라는 어휘가 이 단위의 주제를 알려 준다.
        if (stripos($unit, 'layout') === false && ! str_contains($unit, 'plugin_settings')) {
            return false;
        }

        foreach (["!== 'json'", "!= 'json'", ".'.json'", '.".json"', "_settings.json'"] as $signal) {
            if (str_contains($unit, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 코드를 판정 단위로 분해합니다 — 메서드 본문 전량 + 클래스 본문 머리(상수·프로퍼티 선언).
     *
     * 머리를 함께 내는 이유: 소비처가 경로를 클래스 상수로 들고 있으면 메서드 본문만 보는 판정은
     * 그것을 놓친다. 머리는 첫 메서드 본문이 시작되기 전까지의 코드이므로 본문과 겹치지 않고,
     * 잘라내기가 문자열 치환이 아니라 위치 계산이라 대형 파일에서도 선형이다.
     *
     * @param  string  $code  주석이 제거된 PHP 코드
     * @return array<int, string> 판정 단위
     */
    private function judgementUnits(string $code): array
    {
        $bodies = array_values($this->methodBodies($code));

        $head = $code;

        if ($bodies !== []) {
            $offset = strpos($code, $bodies[0]);

            if ($offset !== false) {
                $head = substr($code, 0, $offset);
            }
        }

        $bodies[] = $head;

        return $bodies;
    }

    /**
     * 코드가 조각 경로 규약을 자기 힘으로 다시 구현하는지 판정합니다.
     *
     * 규약의 세 성질을 함께 갖출 때만 사본으로 본다 — ⓐ `partials` 를 아는 경로 조립 ⓑ 후보를
     * 순서대로 시도하는 폴백 ⓒ layouts 경계 밖 거부. 하나만 있으면 무관한 경로 코드일 수 있다.
     *
     * @param  string  $code  주석이 제거된 PHP 코드
     * @return bool 사본이면 true
     */
    private function reimplementsPartialPathConvention(string $code): bool
    {
        // 34차 — 이 축도 판정 입도가 파일이었다(위 `consumesLayoutSourcesAsJsonOnly` 와 같은 사각).
        foreach ($this->judgementUnits($code) as $unit) {
            if ($this->unitReimplementsPartialPathConvention($unit)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 판정 단위 하나가 조각 경로 규약을 다시 구현하는지 판정합니다.
     *
     * @param  string  $unit  메서드 본문 또는 클래스 본문 잔여
     * @return bool 사본이면 true
     */
    private function unitReimplementsPartialPathConvention(string $unit): bool
    {
        // 규약에 위임하는 자리는 사본이 아니다 — 이 조건이 없으면 `resolveAllPartials()` 를 태우는
        // 개발자 도구(`GenerateLayoutTestFixture`)가 사본으로 잡힌다.
        //
        // 35차 — 이 세 이름은 종전에 **여기 인라인으로** 적혀 있었다. 34차가 "목록이 둘이면 한쪽만
        // 갱신된다" 며 두 목록을 규약 정본으로 통합했는데, 같은 파일 안에 **세 번째 목록**이 남아
        // 있었고 그 목록에만 있는 이름(`resolvePartialPath(`)이 정본에는 없었다. 정본으로 흡수한다.
        foreach (self::LAYOUT_SOURCE_DELEGATIONS as $delegation) {
            if (str_contains($unit, $delegation)) {
                return false;
            }
        }

        // 조각 **참조를 소비**하는가. 경로에서 `partials/` 디렉토리를 걸러내기만 하는 코드
        // (당시의 `AddLayoutPermissionsCommand`)와 참조를 파일로 해석하는 코드를 갈라내는 지점이다 —
        // 후자만 규약을 필요로 한다.
        if (preg_match('/\$partialPath|\[[\'"]partial[\'"]\]/', $unit) !== 1) {
            return false;
        }

        $knowsLayoutBoundary = str_contains($unit, 'layoutDir') || str_contains($unit, 'layouts');
        $resolvesCandidates = str_contains($unit, 'realpath(') || str_contains($unit, 'File::exists(');
        $rejectsOutside = str_contains($unit, 'str_starts_with(');

        return $knowsLayoutBoundary && $resolvesCandidates && $rejectsOutside;
    }

    /**
     * 코어에서 주어진 호출을 **코드로** 포함하는 파일을 도출합니다 (주석 제외).
     *
     * 주석을 세면 `{@see …}` 참조가 호출로 잡혀 판정이 무너진다 — 실제로 이 저장소의 독블록은
     * 규약 메서드를 이름으로 여러 번 참조한다.
     *
     * @param  string  $needle  찾을 호출 문자열
     * @return array<string, string> 상대 경로 => 주석이 제거된 코드
     */
    private function coreFilesCalling(string $needle): array
    {
        $found = [];

        $paths = [];

        foreach (File::allFiles(base_path(self::SOURCE_ROOT)) as $file) {
            if ($file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        // 합성 표본 합류 (32차 근본 H) — 자기시험 표본은 판정식이 아니라 **모집단**으로 들어와야
        // 문지기·모집단 단이 함께 시험받는다. 주입 레지스트리는 GateTestPopulation 한 곳이다.
        foreach (GateTestPopulation::injectedFiles() as $absolute) {
            if (str_ends_with($absolute, '.php')) {
                $paths[] = $absolute;
            }
        }

        foreach ($paths as $pathname) {
            $code = $this->codeWithoutComments(File::get($pathname));

            if (! str_contains($code, $needle)) {
                continue;
            }

            // 정의 자체는 호출이 아니다.
            $method = rtrim($needle, '(');

            if (preg_match('/function\s+'.preg_quote($method, '/').'\s*\(/', $code) === 1
                && substr_count($code, $needle) <= 1) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($pathname, strlen(base_path()) + 1));

            $found[$relative] = $code;
        }

        ksort($found);

        return $found;
    }

    /**
     * PHP 코드를 이름 있는 함수/메서드의 본문으로 분해합니다 (33차 — 판정 입도 강하용).
     *
     * 토큰 기반 중괄호 대응이므로 문자열 리터럴 안의 중괄호에 흔들리지 않는다. 익명 함수는
     * 건너뛴다 — 어차피 그것을 감싼 이름 있는 메서드의 본문에 포함된다. 시그니처는 본문에
     * 넣지 않으므로 메서드 **정의** 자체는 그 이름의 소비처로 잡히지 않는다.
     *
     * @param  string  $code  주석이 제거된 PHP 코드
     * @return array<string, string> 메서드 이름 => 본문 코드
     */
    private function methodBodies(string $code): array
    {
        $tokens = token_get_all($code);
        $bodies = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            // 함수 이름 — `function` 다음의 첫 유효 토큰. `(` 이 먼저 오면 익명 함수다.
            $name = null;

            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];

                if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if ((is_array($next) ? $next[1] : $next) === '&') {
                    continue;
                }

                if (is_array($next) && $next[0] === T_STRING) {
                    $name = $next[1];
                }

                break;
            }

            if ($name === null) {
                continue;
            }

            // 본문 시작 `{` 를 찾는다 — 그 전에 `;` 가 오면 추상/인터페이스 선언이다.
            $depth = 0;
            $body = '';
            $started = false;

            for ($j = $j + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                $text = is_array($next) ? $next[1] : $next;

                if (! $started) {
                    if ($text === ';') {
                        break;
                    }

                    if ($text === '{') {
                        $started = true;
                        $depth = 1;
                    }

                    continue;
                }

                if ($text === '{'
                    || (is_array($next) && in_array($next[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($text === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $bodies[$name] = $body;
                        $i = $j;

                        break;
                    }
                }

                $body .= $text;
            }
        }

        return $bodies;
    }

    /**
     * PHP 소스에서 주석만 제거합니다 (문자열·정규식 리터럴 보존).
     *
     * @param  string  $source  PHP 소스
     * @return string 주석이 제거된 소스
     */
    private function codeWithoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }
}
