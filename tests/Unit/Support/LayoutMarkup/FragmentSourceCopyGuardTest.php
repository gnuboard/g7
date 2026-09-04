<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Extension\Helpers\LayoutFragmentSourceHelper;
use App\Support\LayoutMarkup\LayoutCorpus;
use Illuminate\Support\Facades\File;
use Tests\Support\CompilesFragmentMarkup;
use Tests\Support\GateTestPopulation;
use Tests\Support\InjectsGuardSamples;

/**
 * 확장이 자기 fragment 정의를 코어 규약 밖에서 직접 읽는 **사본**의 재발 봉인 (S18 P1).
 *
 * fragment 정의는 소스 SSoT 가 `.g7.html` 이고, 짝 판별·컴파일·읽기는 코어가 한 자리에서 끝낸다
 * ({@see LayoutFragmentSourceHelper}). 확장이 자기 `resources/extensions`
 * 를 스스로 globbing 하거나 `json_decode` 로 파싱하면 소스가 보이지 않으므로, `.g7.html` 만
 * 고쳤을 때 그 경로에서만 조용히 반영되지 않는다.
 *
 * **이 가드가 사는 자리가 `Tests\Unit\Support\LayoutMarkup` 인 이유**: S18 종료 검증 명령이
 * `--filter=LayoutMarkup` 이다. 가드를 그 밖에 두면 종료 게이트가 사본 재발을 보지 못한다 —
 * 실제로 최초 판본이 `Tests\Unit\Extension\Helpers` 에 있어 게이트 밖이었다.
 *
 * **판정식이 표기에 의존하면 안 된다**: 최초 판본은 `resources/extensions/`(후행 슬래시 포함)
 * 문자열만 봤는데, 디렉토리 단위로 읽는 사본은 `.'/resources/extensions'` 로 슬래시 없이 경로를
 * 만든다. 그래서 그 판본은 **이미 고친 파일 넷만 통과 확인**하고 남아 있던 사본 셋
 * (`RestoreLayoutExtensionsAfterUpdateListener` ×3)을 구조적으로 못 봤다. 열거를 피하려고 만든
 * 가드가 표기 하나 때문에 사실상 열거가 된 자리라, 슬래시에 의존하지 않는 형태로 판정한다.
 *
 * 모집단은 디스크에서 도출한다 — 특정 플러그인·특정 디렉토리를 열거하면 다음 사본이 관측 밖에
 * 남는다.
 */
class FragmentSourceCopyGuardTest extends LayoutMarkupTestCase
{
    use CompilesFragmentMarkup;
    use InjectsGuardSamples;

    /**
     * 모집단 글롭 — 확장 유형 셋을 모두 훑는다 (개별 확장은 열거하지 않는다).
     *
     * **범위는 `_bundled` 트리다** (37차 명시). 활성 설치 디렉토리(`plugins/{id}/src` 등)는 여기
     * 없다 — 확장 수정은 `_bundled` 에서만 하고 활성본은 `{type}:update` 산출물이라는 프로젝트
     * 규율 때문이다. 실측상 두 트리는 `diff -rq` 0 이라 오늘 사각은 없으나, "확장 3종 `src`
     * 재귀 전량" 이라는 이 가드의 명제가 **번들 트리 한정**임을 적어 둔다. 활성본이 손편집으로
     * 갈라지면 그것은 이 가드가 아니라 활성↔`_bundled` diff 검사가 잡을 축이다.
     */
    private const SOURCE_ROOT_PATTERNS = [
        'modules/_bundled/*/src',
        'plugins/_bundled/*/src',
        'templates/_bundled/*/src',
    ];

    /**
     * fragment 정의를 스스로 수집·파싱하는 신호.
     *
     * 열거이므로 좁아지면 그만큼 사각이 된다 — `glob(`·`json_decode(` 넷만 보던 판본은
     * `File::json(` 한 줄이면 전량 회피됐다. 수집(디렉토리 훑기)과 읽기(파싱) 양쪽을 담고,
     * 새 형태가 나오면 {@see test_predicate_self_check_on_synthetic_samples()} 에 표본을 함께 넣는다.
     * `glob(` 은 부분문자열이라 `File::glob(` 도 함께 잡는다.
     */
    private const READ_SIGNALS = [
        'glob(',
        'scandir(',
        'DirectoryIterator',
        'File::files(',
        'File::allFiles(',
        'json_decode(',
        'File::json(',
        'file_get_contents(',
        'File::get(',
        'Storage::get(',
    ];

    /**
     * 코어가 확장의 fragment 디렉토리를 돌려주는 접근자.
     *
     * 경로 리터럴 없이 이 접근자로만 디렉토리를 받는 사본은 문자열 판정에 걸리지 않는다.
     * 이름 열거이므로 {@see test_fragment_path_accessors_still_exist_in_core()} 가 실재를 잠근다.
     */
    private const FRAGMENT_PATH_ACCESSORS = ['getExtensionsPath'];

    /**
     * 확장 소스가 자기 fragment 정의를 직접 수집·파싱하지 않는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects extension_sources_read_fragments_through_core_helper
     */
    public function test_extension_sources_do_not_read_fragment_definitions_directly(): void
    {
        $sources = $this->bundledExtensionSources();

        $this->assertNotEmpty($sources,
            '검사 대상이 0건이다 — 모집단 도출이 죽었는지 먼저 확인해야 한다 ("위반 0" 과 "검사 0" 은 다른 명제다)');

        $candidates = 0;
        $violations = [];

        foreach ($sources as $file) {
            $source = File::get($file);

            // 주석은 판정에서 뺀다 — 규약을 **설명하는** 독블록이 위반으로 잡히면 작성자는 설명을
            // 지우게 되고, 그러면 다음 사람이 왜 이렇게 쓰는지 모른 채 사본을 되살린다.
            $code = $this->codeWithoutComments($source);

            // 자기 fragment 디렉토리를 가리키는 형태 — **경로 표기에 의존하지 않는다**.
            if (! $this->mentionsFragmentDirectory($source)) {
                continue;
            }

            $candidates++;

            $signals = $this->readSignalsIn($code);

            if ($signals !== []) {
                $violations[$this->relative($file)] = $signals;
            }
        }

        $this->assertGreaterThan(0, $candidates,
            'fragment 디렉토리를 가리키는 확장 소스가 0건이다 — 경로 규약이 바뀌어 판정식이 '
            .'구조적으로 아무것도 보지 못하는 상태인지 먼저 확인해야 한다');

        $this->assertSame([], $violations,
            'fragment 정의는 LayoutFragmentSourceHelper::read()/readDirectory() 로 읽는다 — '
            .'사본을 두면 그 경로에서만 소스(.g7.html)이 무시된다.');
    }

    /**
     * 실물 fragment 디렉토리가 코어 진입점을 통해 **소스로** 해석된다.
     *
     * 사본을 없앤 것만으로는 절반이다 — 코어 경로가 실제 코퍼스에서 소스를 고르는지까지 봐야
     * "소스만 고쳐도 반영된다" 가 성립한다. 짝이 병존하는 실물에서 `.json` 이 이기면 여기서 걸린다.
     *
     * **기대값은 검증 대상을 통과하면 안 된다**(30차 검출 — 이 자리가 두 번 무너진 이유):
     * 29차 판본은 기대값을 `LayoutFragmentSourceHelper::read($markupFile)` 로 만들었는데,
     * `read()` 는 입력이 `.g7.html` 이든 짝 `.json` 이든 {@see ResolvesLayoutInstallSource::layoutSourceCandidates()}
     * 로 **같은 후보 목록에 수렴**한다. 그래서 두 `read()` 결과는 어느 소스가 이기든 항상 같았고,
     * 짝 단위 단언 둘은 **실패할 수 없는 명제**였다(전역 하한 하나만 살아 있었다).
     * 지금은 기대값을 **선택 경로를 거치지 않는 직접 컴파일**로 만든다 — 소스 선택이 뒤집히면
     * 그 짝에서 바로 red 다.
     *
     * **순회는 재귀다**: 템플릿 오버라이드는 `extensions/{대상확장}/` 로 한 단계 더 깊다.
     * 비재귀 글롭이던 29차 판본은 저장소의 **유일한 템플릿 fragment 짝**을 통째로 건너뛰었고,
     * 전역 하한은 모듈·플러그인 표본이 채워 초록이었다 — 축 하나가 죽어도 침묵하는 형태다.
     * 그래서 하한을 **루트별로** 둔다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects real_fragment_directories_resolve_to_authoring_sources
     */
    public function test_real_fragment_directories_resolve_to_authoring_sources(): void
    {
        $pairsChecked = 0;
        $divergentPairs = 0;
        $checkedPerRoot = [];
        $pairsPerRoot = [];
        $divergentPerRoot = [];

        // 모집단은 코퍼스 SSoT 를 그대로 쓴다 — 루트 목록을 여기 다시 적으면 그것이 다음 사본이다.
        foreach (LayoutCorpus::fragmentRoots() as $rootGlob) {
            $checkedPerRoot[$rootGlob] = 0;
            $pairsPerRoot[$rootGlob] = 0;
            $divergentPerRoot[$rootGlob] = 0;

            foreach (File::glob($rootGlob) ?: [] as $root) {
                if (! File::isDirectory($root)) {
                    continue;
                }

                foreach ($this->fragmentDirectoriesIn($root) as $directory) {
                    $markupFiles = File::glob($directory.DIRECTORY_SEPARATOR.'*.g7.html') ?: [];

                    if ($markupFiles === []) {
                        continue;
                    }

                    // 논리 fragment = 확장자를 뗀 이름의 합집합 (짝은 1건)
                    $logical = [];
                    foreach (array_merge($markupFiles, File::glob($directory.DIRECTORY_SEPARATOR.'*.json') ?: []) as $file) {
                        $logical[preg_replace('/\.(g7\.html|json)$/i', '', basename($file))] = true;
                    }

                    $this->assertCount(count($logical), LayoutFragmentSourceHelper::readDirectory($directory),
                        "논리 fragment 수와 해석된 정의 수가 다릅니다 (중복 계상 또는 누락): {$this->relative($directory)}");

                    foreach ($markupFiles as $markupFile) {
                        $jsonSibling = preg_replace('/\.g7\.html$/i', '.json', $markupFile);

                        if (! File::exists($jsonSibling)) {
                            continue;
                        }

                        $pairsChecked++;
                        $pairsPerRoot[$rootGlob]++;

                        // 기대값 — **선택 경로를 타지 않는** 독립 오라클(직접 컴파일).
                        $compiled = $this->compileFragmentMarkupDirectly($markupFile);
                        $resolved = LayoutFragmentSourceHelper::read($jsonSibling);
                        $rawJson = json_decode(File::get($jsonSibling), true);

                        $this->assertSame($compiled, $resolved,
                            "짝 병존인데 `.json` 참조가 소스로 해석되지 않았습니다: {$this->relative($jsonSibling)}");

                        // **S18.5 — 값 비교는 굽기 이후 관측력을 잃는다.** 위 단언의 red 경로는
                        // "`.json` 이 이기고 그 `.json` 이 컴파일 산출과 다를 때" 하나뿐인데,
                        // `layout:decompile --all --sync` 가 짝을 기계로 수렴시키면 그 조건이 전건
                        // 사라진다. 그래서 값이 아니라 **선택 자체**를 함께 관측한다 — 기대값이
                        // 리터럴(`.g7.html` 접미사)이라 짝이 수렴해도 red 경로가 살아 있다
                        // (32차가 이 수렴을 예견하고 공유 오라클에 미리 넣어 둔 단언이다).
                        $this->assertFragmentSourceIsAuthoringMarkup($jsonSibling);

                        if ($rawJson !== $compiled) {
                            $divergentPairs++;
                            $divergentPerRoot[$rootGlob]++;
                        }
                    }

                    $checkedPerRoot[$rootGlob]++;
                }
            }
        }

        // 하한은 **루트별**이다 — 전역 하한 하나면 축 하나가 통째로 죽어도 나머지가 채워 침묵한다
        // (29차 판본에서 템플릿 축이 정확히 그렇게 사라졌다).
        foreach ($checkedPerRoot as $rootGlob => $checked) {
            $this->assertGreaterThan(0, $checked,
                "이 fragment 루트에서 소스를 가진 디렉토리가 0건이다: {$rootGlob} — "
                .'순회가 그 축에 도달하지 못하는 상태인지 먼저 확인해야 한다 (재귀 여부·경로 규약)');

            // 디렉토리에 도달했다는 것과 **짝을 실제로 비교했다**는 것은 다른 명제다.
            // 이 하한이 없으면 확장(결제 플러그인 등)의 짝 병존 축이 관측 밖으로 빠져도 침묵한다.
            $this->assertGreaterThan(0, $pairsPerRoot[$rootGlob],
                "이 fragment 루트에서 비교한 짝이 0건이다: {$rootGlob} — 소스만 있고 짝이 없는 상태인지, "
                .'아니면 순회가 짝을 못 찾는 상태인지 확인해야 한다');
        }

        $this->assertGreaterThan(0, $pairsChecked,
            '짝이 병존하는 fragment 가 0건이다 — 우선순위 단언이 공허하게 통과했는지 먼저 확인해야 한다');

        // **S18.5 — 종전의 divergence 하한(루트별 + 전역)을 여기서 걷어냈다.**
        //
        // 31차가 세운 그 하한은 *"컴파일 산출과 원본 `.json` 이 갈리는 짝이 0건이면 우선순위 단언이
        // 어느 소스가 이겨도 통과한다"* 는 정확한 진단이었고, 그 독블록은 굽기 착수 시 이 단언이
        // **정상적으로 red 로 뒤집힌다**는 것과 그때 할 일이 *"하한을 지우는 것이 아니라 합성 표본으로
        // 관측력을 옮기는 것"* 이라는 처방까지 함께 적어 두었다. S18.5 가 그 처방을 이행한다:
        //
        //  - 실물 짝의 관측은 값 비교에서 **선택 경로 단언**으로 옮겼다(위 `assertFragmentSourceIsAuthoringMarkup`).
        //    기대값이 리터럴이라 짝이 수렴해도 red 경로가 살아 있다.
        //  - 값 오라클이 `.json` 승리를 실제로 잡는다는 명제는 짝을 일부러 어긋나게 둔 합성 표본이
        //    시험한다 ({@see test_value_oracle_detects_a_json_win_on_a_divergent_pair()}).
        //
        // divergence 자체는 이제 정상 상태가 0 이므로 **수를 단언하지 않는다** — `>= 0` 같은 형태는
        // 어떤 변경으로도 red 가 되지 않아 초록이 아무것도 뜻하지 않는다(31차가 세운 축을 그대로
        // 유지하려다 이 함정에 빠지기 쉬운데, 게이트의 무효 단언 가드가 즉시 잡는다). 대신 순회가
        // 두 계량에서 **같은 루트 집합**을 돌았는지만 확인한다 — 이것은 갈리면 red 인 명제다.
        $this->assertSame(array_keys($pairsPerRoot), array_keys($divergentPerRoot),
            'divergence 계량이 짝 계량과 다른 루트 집합을 돌았다 — 순회가 갈렸는지 확인해야 한다');

        $this->assertGreaterThan(0, $pairsChecked,
            '짝이 병존하는 fragment 가 0건이다 — 우선순위 단언이 공허하게 통과했는지 먼저 확인해야 한다');
    }

    /**
     * 값 오라클이 **`.json` 승리**를 잡는다 — 짝을 일부러 어긋나게 둔 합성 표본으로 시험합니다 (S18.5).
     *
     * 굽기가 실물 짝을 전건 수렴시키면 코퍼스는 이 관측을 더 이상 제공하지 못한다(31차 독블록이
     * 예견한 상태). 관측을 합성 표본으로 옮기되, **판정에 쓰는 함수는 프로덕션의 것 그대로**다 —
     * 오라클을 다시 구현하면 그 사본이 다음 사각이 된다(이 트랙이 `app/` 에서 세 회차에 걸쳐 확인).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_value_oracle_detects_a_json_win_on_a_divergent_pair
     */
    public function test_value_oracle_detects_a_json_win_on_a_divergent_pair(): void
    {
        $directory = base_path('storage/framework/testing/fragment-divergent-pair');

        File::ensureDirectoryExists($directory);

        $markupFile = $directory.DIRECTORY_SEPARATOR.'divergent_probe.g7.html';
        $jsonFile = $directory.DIRECTORY_SEPARATOR.'divergent_probe.json';

        try {
            File::put($markupFile, <<<'HTML'
            <extension extension-point="probe_point" mode="append" priority="50">
              <div class="p-4"></div>
            </extension>
            HTML);

            // 짝을 일부러 어긋나게 둔다 — 소스가 이기면 이 값은 결과에 나타나지 않아야 한다.
            File::put($jsonFile, json_encode([
                'extension_point' => 'probe_point',
                'mode' => 'append',
                'priority' => 50,
                'components' => [],
                'stale_marker' => '이 키는 소스 산출에 없다',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

            $compiled = $this->compileFragmentMarkupDirectly($markupFile);
            $rawJson = json_decode(File::get($jsonFile), true);

            $this->assertNotSame($rawJson, $compiled,
                '표본이 수렴해 있다 — 어긋난 짝이 아니면 이 자기시험은 아무것도 관측하지 못한다');

            $this->assertSame($compiled, LayoutFragmentSourceHelper::read($jsonFile),
                '어긋난 짝에서 `.json` 이 이겼다 — 값 오라클의 red 경로가 살아 있는지 확인');

            $this->assertFragmentSourceIsAuthoringMarkup($jsonFile);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * fragment 루트 아래에서 정의 파일을 가진 디렉토리를 전부 찾습니다 (자기 자신 포함, 재귀).
     *
     * 모듈·플러그인은 `resources/extensions/` 바로 아래에 두지만 템플릿 오버라이드는
     * `extensions/{대상확장}/` 로 한 단계 더 깊다. 비재귀 글롭은 그 축을 통째로 놓친다.
     *
     * @param  string  $root  fragment 루트 디렉토리 절대 경로
     * @return array<int, string> 정의 파일을 가진 디렉토리 목록
     */
    private function fragmentDirectoriesIn(string $root): array
    {
        $directories = [$root];

        foreach (File::directories($root) as $child) {
            $directories = array_merge($directories, $this->fragmentDirectoriesIn($child));
        }

        return $directories;
    }

    /**
     * 모집단에 **주입한** 표본에 프로덕션 검사가 반응합니다 (32차 근본 조치 H).
     *
     * 아래 자기시험들은 판정식(`readSignalsIn()`·`mentionsFragmentDirectory()`)에 표본을 직접
     * 넘긴다 — 그것만으로는 "판정식이 그 형태를 안다" 만 증명되고, 표본이 **문지기를 지나
     * 판정식까지 도달하는지**는 증명되지 않는다. 32차가 오라클 독립성 가드에서 정확히 그
     * 차이(문지기가 표기 기반이라 6파일이 검사 밖) 때문에 실물 사각을 찾았다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_source_copy_guard_reacts_to_population_injected_samples
     */
    public function test_guard_reacts_to_samples_injected_into_the_population(): void
    {
        $copy = <<<'PHP'
        <?php
        class InjectedFragmentReadCopySample
        {
            public function orderPaymentQueryExtension(): array
            {
                $path = dirname(__DIR__, 2).'/resources/extensions/admin_order_payment_query.json';

                return json_decode(file_get_contents($path), true);
            }
        }
        PHP;

        $compliant = <<<'PHP'
        <?php
        class InjectedFragmentHelperSample
        {
            public function orderPaymentQueryExtension(): array
            {
                return LayoutFragmentSourceHelper::read(
                    dirname(__DIR__, 2).'/resources/extensions/admin_order_payment_query.json'
                );
            }
        }
        PHP;

        $production = function (): void {
            $this->test_extension_sources_do_not_read_fragment_definitions_directly();
        };

        $this->assertGuardRejects('fragment_read_copy_sample.php', $copy, $production,
            '확장이 자기 확장점을 직접 읽는 사본 — 이 경로에서만 소스가 무시된다');

        $this->assertGuardAccepts('fragment_helper_sample.php', $compliant, $production,
            '코어 진입점 경유 — 준수 형태');
    }

    /**
     * 판정식이 살아 있는지 합성 표본으로 시험합니다 (실트리 변형 금지 — 20차 자진신고 사유).
     *
     * 위반 표본이 통과하거나 준수 표본이 걸리면 이 가드의 초록은 아무것도 뜻하지 않는다.
     * 특히 ⓒ 는 **최초 판본이 놓쳤던 형태**다 — 디렉토리 단위 사본은 후행 슬래시 없이 경로를
     * 만든다. ⓓ 는 19차가 겪은 주석 제거기 사고의 재발 방지다.
     *
     * (35차 — 이 독블록은 종전에 앞 메서드에 **두 겹으로** 붙어 있어 이 메서드는 독블록이 없었다.
     * 34차가 라우팅 가드에서 지적한 고아 독블록과 같은 형태다.)
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_copy_guard_self_test_detects_copy_forms
     */
    public function test_predicate_self_check_on_synthetic_samples(): void
    {
        // ⓐ 코어 진입점 경유 = 준수
        $compliant = <<<'PHP'
        <?php
        $data = LayoutFragmentSourceHelper::read(dirname(__DIR__, 2).'/resources/extensions/point.json');
        PHP;

        // ⓑ 파일 하나를 직접 파싱 = 위반 (후행 슬래시 있는 형태)
        $singleCopy = <<<'PHP'
        <?php
        $data = json_decode(file_get_contents(__DIR__.'/../../resources/extensions/point.json'), true);
        PHP;

        // ⓒ 디렉토리를 직접 수집 = 위반 (후행 슬래시 **없는** 형태 — 최초 판본이 놓친 자리)
        $directoryCopy = <<<'PHP'
        <?php
        $paths = glob(dirname(__DIR__, 2).'/resources/extensions'.'/*.json');
        PHP;

        // ⓓ 규약을 설명하는 주석은 위반이 아니다 + 문자열 안의 `/*` 를 주석으로 먹지 않는다
        $documented = <<<'PHP'
        <?php
        // 여기서 glob('*.json') 하면 소스가 보이지 않는다.
        $pattern = '/resources/extensions/*.json';
        $data = LayoutFragmentSourceHelper::readDirectory(dirname(__DIR__, 2).'/resources/extensions');
        PHP;

        $this->assertSame([], $this->readSignalsIn($this->codeWithoutComments($compliant)),
            '코어 진입점 경유가 위반으로 잡힙니다 (판정식이 과탐 — 과탐 가드는 무시당한다)');

        $this->assertNotSame([], $this->readSignalsIn($this->codeWithoutComments($singleCopy)),
            '파일 단위 직접 파싱 사본을 놓칩니다');

        $this->assertNotSame([], $this->readSignalsIn($this->codeWithoutComments($directoryCopy)),
            '디렉토리 단위 사본을 놓칩니다 (후행 슬래시에 의존하는 판정으로 되돌아갔는지 확인)');

        $documentedCode = $this->codeWithoutComments($documented);

        $this->assertSame([], $this->readSignalsIn($documentedCode),
            '주석의 설명이 위반으로 잡힙니다 — 그러면 작성자가 설명을 지우게 된다');
        $this->assertStringContainsString('resources/extensions/*.json', $documentedCode,
            '주석 제거가 문자열 리터럴까지 먹었습니다 (19차 사고 형태)');
    }

    /**
     * 후보 판정이 경로 **표기**에 걸려 사본을 놓치지 않는지 합성 표본으로 시험합니다.
     *
     * 30차 검출: 판정식이 `resources/extensions` 리터럴이라 ⓐ 구분자 조립 ⓑ 템플릿(`resources/`
     * 세그먼트 없음) ⓒ 접근자 경유 세 형태가 후보에 **들어오지도 못했다**. 신호 판정이 아무리
     * 넓어도 후보 필터가 좁으면 그대로다 — 자기시험이 판정식의 **절반만** 시험하고 있었다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_copy_guard_candidate_filter_is_notation_independent
     */
    public function test_candidate_filter_does_not_depend_on_path_notation(): void
    {
        // ⓐ 구분자 조립 — 경로에 `resources/extensions` 문자열이 나타나지 않는다
        $assembled = <<<'PHP'
        <?php
        $dir = dirname(__DIR__, 2).'/resources'.DIRECTORY_SEPARATOR.'extensions';
        PHP;

        // ⓑ 템플릿 오버라이드 — `resources/` 세그먼트가 없다
        $templateForm = <<<'PHP'
        <?php
        $dir = base_path('templates/sirsoft-basic/extensions/sirsoft-daum_postcode');
        PHP;

        // ⓒ 접근자 경유 — 경로 리터럴이 아예 없다
        $viaAccessor = <<<'PHP'
        <?php
        $dir = $this->plugin->getExtensionsPath();
        PHP;

        // ⓓ fragment 와 무관한 소스는 후보가 아니다 (과탐 금지)
        $unrelated = <<<'PHP'
        <?php
        $data = json_decode(file_get_contents(storage_path('app/settings/order.json')), true);
        PHP;

        $this->assertTrue($this->mentionsFragmentDirectory($assembled),
            '구분자로 조립한 fragment 경로를 후보로 보지 못합니다 (표기 의존으로 되돌아갔는지 확인)');
        $this->assertTrue($this->mentionsFragmentDirectory($templateForm),
            '템플릿 오버라이드 경로를 후보로 보지 못합니다 (`resources/` 를 요구하는지 확인)');
        $this->assertTrue($this->mentionsFragmentDirectory($viaAccessor),
            '접근자로 fragment 디렉토리를 받는 형태를 후보로 보지 못합니다');
        $this->assertFalse($this->mentionsFragmentDirectory($unrelated),
            'fragment 와 무관한 소스가 후보로 잡힙니다 (과탐 가드는 무시당한다)');
    }

    /**
     * 신호 목록이 수집·읽기 양쪽의 흔한 형태를 담는지 시험합니다.
     *
     * 종전 넷(`glob(`·`json_decode(`·`file_get_contents(`·`File::get(`)은 `File::json(` 한 줄로
     * 전량 회피됐다. 열거를 넓힌 만큼 그 넓힘이 살아 있는지 표본으로 잠근다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_copy_guard_signals_cover_collect_and_read_forms
     */
    public function test_read_signals_cover_collect_and_parse_variants(): void
    {
        $variants = [
            'File::json' => '<?php $data = File::json($dir."/point.json");',
            'File::files' => '<?php $paths = File::files($dir);',
            'File::allFiles' => '<?php $paths = File::allFiles($dir);',
            'scandir' => '<?php $paths = scandir($dir);',
            'DirectoryIterator' => '<?php $it = new DirectoryIterator($dir);',
            'Storage::get' => '<?php $raw = Storage::get($relative);',
        ];

        foreach ($variants as $label => $sample) {
            $this->assertNotSame([], $this->readSignalsIn($this->codeWithoutComments($sample)),
                "수집·읽기 형태를 신호로 보지 못합니다: {$label}");
        }
    }

    /**
     * 신호·접근자 **열거**가 실재에 붙어 있는지 잠급니다.
     *
     * 접근자 이름은 코어 계약에서 온다. 코어가 이름을 바꾸면 이 가드는 조용히 그 축을 잃으므로,
     * 열거가 실재하는 코어 표면을 가리키는지 여기서 확인한다 (열거 자체를 없앨 수는 없지만,
     * 낡은 열거로 초록이 되는 것은 막는다).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_copy_guard_accessor_enumeration_is_anchored_to_core
     */
    public function test_fragment_path_accessors_still_exist_in_core(): void
    {
        foreach (self::FRAGMENT_PATH_ACCESSORS as $accessor) {
            $this->assertTrue(
                method_exists(ModuleInterface::class, $accessor)
                || method_exists(PluginInterface::class, $accessor),
                "코어 확장 계약에 없는 접근자를 열거하고 있습니다: {$accessor} — 이름이 바뀌었다면 "
                .'이 가드는 그 축을 이미 잃은 상태다'
            );
        }
    }

    /**
     * 소스가 fragment 정의를 스스로 수집·파싱하는 신호를 찾습니다.
     *
     * @param  string  $code  주석이 제거된 PHP 코드
     * @return array<int, string> 검출된 신호 (없으면 빈 배열)
     */
    private function readSignalsIn(string $code): array
    {
        return array_values(array_filter(
            self::READ_SIGNALS,
            static fn (string $signal): bool => str_contains($code, $signal)
        ));
    }

    /**
     * 소스가 자기 fragment 디렉토리를 가리키는지 판정합니다 — **경로 표기에 의존하지 않는다**.
     *
     * 최초 판본은 `resources/extensions/`(후행 슬래시), 2판은 `resources/extensions`(내부 슬래시)
     * 문자열이었다. 둘 다 표기 하나에 걸려 있어, ⓐ `'resources'.DIRECTORY_SEPARATOR.'extensions'`
     * 로 조립하는 사본(이 저장소의 지배적 관용구다 — 코어와 이 가드 자신도 그렇게 쓴다)
     * ⓑ 템플릿 오버라이드(`templates/{t}/extensions` — `resources/` 세그먼트가 **없다**)
     * ⓒ 접근자로 경로를 받는 사본(경로 리터럴이 아예 없다) 을 구조적으로 못 봤다.
     *
     * 그래서 판정을 **토큰**으로 옮긴다 — 문자열 리터럴 어딘가에 `extensions` 가 있거나, 코어가
     * fragment 디렉토리를 돌려주는 접근자를 부르면 후보다.
     *
     * @param  string  $source  원본 PHP 소스 (주석 포함 — 토크나이저가 걸러낸다)
     */
    private function mentionsFragmentDirectory(string $source): bool
    {
        foreach (token_get_all($source) as $token) {
            if (! is_array($token) || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            if ($token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_ENCAPSED_AND_WHITESPACE) {
                if (str_contains($token[1], 'extensions')) {
                    return true;
                }

                continue;
            }

            if ($token[0] === T_STRING && in_array($token[1], self::FRAGMENT_PATH_ACCESSORS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * PHP 소스에서 주석만 제거합니다 (문자열·정규식 리터럴 보존).
     *
     * 정규식 치환을 쓰지 않는 이유는 이 저장소가 이미 그 대가를 치렀기 때문이다 — 19차의 메타가드가
     * `/\/\*[\s\S]*?\*\//` 로 주석을 지우다 글롭 리터럴 사이를 통째로 먹어, 가드 전량이 후보 0건인
     * 채 초록으로 통과했다. 토크나이저는 문자열 안의 `/*` 를 주석으로 오인하지 않는다.
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

    /**
     * 모집단 도출이 세 확장 유형을 모두 훑는다.
     *
     * 사본은 플러그인에서 나왔지만 모듈·템플릿도 같은 구조를 가질 수 있다. 유형 하나가 조용히
     * 빠지면 그 유형의 사본은 영원히 관측 밖이므로, **훑는 대상**을 단언한다. 발견된 파일의
     * 유형 분포를 단언하면 "지금 PHP 를 가진 유형" 을 되풀이하는 동어반복이 된다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_copy_guard_population_covers_all_extension_types
     */
    public function test_population_scans_all_bundled_extension_types(): void
    {
        $this->assertSame([
            'modules/_bundled/*/src',
            'plugins/_bundled/*/src',
            'templates/_bundled/*/src',
        ], self::SOURCE_ROOT_PATTERNS,
            '모집단이 확장 유형 하나를 통째로 훑지 않습니다.');

        foreach (self::SOURCE_ROOT_PATTERNS as $pattern) {
            $this->assertNotSame([], File::glob(base_path($pattern)) ?: [],
                "패턴이 디스크에서 아무것도 잡지 못합니다: {$pattern} (경로 규약이 바뀌었는지 확인)");
        }
    }

    /**
     * 번들 확장의 PHP 소스를 디스크에서 전량 도출합니다.
     *
     * @return array<int, string> 절대 경로 목록
     */
    private function bundledExtensionSources(): array
    {
        $files = [];

        foreach (self::SOURCE_ROOT_PATTERNS as $pattern) {
            foreach ($this->existingRoots($pattern) as $root) {
                foreach (File::allFiles($root) as $file) {
                    if ($file->getExtension() === 'php') {
                        $files[] = $file->getPathname();
                    }
                }
            }
        }

        // 자기시험이 주입한 합성 표본 — 표본이 문지기(`mentionsFragmentDirectory`)와 판정식을
        // 실제로 통과하는지 확인하는 유일한 경로다 (32차 근본 조치 H). 주입 레지스트리는
        // 게이트 모집단과 공유해 사본을 만들지 않는다.
        foreach (GateTestPopulation::injectedFiles() as $absolute) {
            $files[] = $absolute;
        }

        return $files;
    }

    /**
     * 글롭 패턴 하나가 잡는 실재 디렉토리를 도출합니다.
     *
     * @param  string  $pattern  저장소 상대 글롭
     * @return array<int, string> 실재하는 소스 디렉토리
     */
    private function existingRoots(string $pattern): array
    {
        $roots = [];

        foreach (File::glob(base_path($pattern)) ?: [] as $root) {
            if (File::isDirectory($root)) {
                $roots[] = $root;
            }
        }

        return $roots;
    }

    /**
     * 절대 경로를 저장소 상대 경로로 바꿉니다.
     *
     * @param  string  $path  절대 경로
     * @return string 상대 경로 (슬래시 통일)
     */
    private function relative(string $path): string
    {
        return str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
    }
}
