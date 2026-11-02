<?php

namespace Tests\Unit\Installer;

use App\Execution\ExecutionMode;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 무CLI 축 두 매니페스트의 마커 정합을 이 공정 범위에서 직접 고정한다 (10차 #G5).
 *
 * ## 왜 별도 테스트인가
 *
 * 두 매니페스트는 머리에 `audit:allow test-scenario-coverage` 를 달고 있다. 사유는 정당하다 —
 * `disable_functions` 는 INI 값이라 PHPUnit·Playwright 런타임에서 재현할 수 없어, 조합 커버리지는
 * P0 격리 환경의 브라우저 실측이 담당한다(계획서 §8).
 *
 * 그런데 그 면제는 **조합 커버리지**만이 아니라 「선언한 파일이 실제로 마킹을 갖는가」 검사까지
 * 함께 끈다(정적 검사 룰이 두 면제를 묶어 두었고, 그 결합은 저장소 전체 234개 매니페스트가
 * 의존하는 기존 계약이다). 그 사각에서 실제로 두 건이 쌓였다:
 *
 *  - `InstallerStateSchemaTest` — test_files 로 선언돼 있는데 마커가 0개였다.
 *  - `ShellExecGuardCoverageTest` 의 `@effects` — 어느 매니페스트에도 등재되지 않은 죽은 마커였다.
 *
 * 룰의 결합을 저장소 전체에서 끊으면 이 공정 밖 232개가 한꺼번에 red 가 된다. 그래서 **이 두
 * 매니페스트에 한해** 같은 검사를 여기서 돌린다 — 면제는 원래 의도한 축(조합)에만 남는다.
 *
 * 커버 축: manifest installer_stepwise·execution_mode / direction declared_file_has_marker·marker_is_declared·declared_effect_is_marked·axis_matches_source
 */
class InstallerScenarioMarkerCoverageTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /**
     * `task` 축 값 → 그 값이 대표하는 실제 태스크 id (SSoT: buildInstallationTaskList()).
     *
     * 축은 관심사 단위로 접혀 있다 — `composer` 하나가 `composer_check`·`composer_install` 둘을,
     * `template` 하나가 `template_install`·`template_activate` 둘을 대표한다. 그 접기 규칙을
     * **테스트가 소유**하고, 양쪽 끝(축 값·실제 태스크 id)은 전수 대조한다.
     *
     * @var array<string, array<int, string>>
     */
    private const TASK_AXIS_MAPPING = [
        'composer' => ['composer_check', 'composer_install'],
        'key_generate' => ['key_generate'],
        'env' => ['env_update'],
        'dependency_precheck' => ['dependency_precheck'],
        'db_cleanup' => ['db_cleanup'],
        'db_migrate' => ['db_migrate'],
        'db_seed' => ['db_seed'],
        'template' => ['template_install', 'template_activate'],
        'user_template' => ['user_template_install', 'user_template_activate'],
        'module' => ['module_install', 'module_activate'],
        'plugin' => ['plugin_install', 'plugin_activate'],
        'language_pack' => ['language_pack_install'],
        'settings' => ['create_settings_json'],
        'cache' => ['cache_clear'],
        'complete_flag' => ['complete_flag'],
        'config_cache' => ['config_cache'],
        'route_cache' => ['route_cache'],
        'static_publish' => ['static_publish'],
    ];

    /** 이 공정이 소유한 매니페스트 — 면제를 좁히는 대상이다. */
    private const MANIFESTS = [
        'tests/scenarios/no-cli-installer-stepwise.yaml',
        'tests/scenarios/no-cli-execution-mode.yaml',
    ];

    /**
     * 테스트 파일 위치에서 세 단계 올라간 저장소 루트 절대 경로를 돌려준다.
     *
     * @return string 저장소 루트 절대 경로
     */
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * 매니페스트의 `test_files` 목록을 읽는다 (yaml 파서 없이 — 이 블록은 단순 목록이다).
     *
     * @param  string  $manifest  저장소 상대 매니페스트 경로
     * @return array<int, string> 선언된 테스트 파일 상대 경로 (꼬리 주석 제거)
     */
    private function declaredTestFiles(string $manifest): array
    {
        // meta:allow raw-source-read 생산 소스가 아니라 시나리오 매니페스트 YAML 이다
        $lines = file(self::root().'/'.$manifest, FILE_IGNORE_NEW_LINES) ?: [];
        $files = [];
        $inBlock = false;

        foreach ($lines as $line) {
            if (preg_match('/^test_files:\s*$/', $line) === 1) {
                $inBlock = true;

                continue;
            }
            if (! $inBlock) {
                continue;
            }
            if (preg_match('/^\s*#/', $line) === 1 || trim($line) === '') {
                continue;
            }
            // YAML 꼬리 주석(`- name   # 설명`)은 값이 아니다 — 지우지 않으면 그 줄에서 목록이
            // 끝난 것으로 읽혀 뒤 항목이 통째로 빠진다(7.2.0 매니페스트가 이 표기를 쓴다).
            $line = (string) preg_replace('/\s+#.*$/u', '', $line);
            if (preg_match('/^\s*-\s*(\S+)\s*$/', $line, $m) !== 1) {
                break; // 목록 끝 (다음 최상위 키)
            }
            $files[] = $m[1];
        }

        return $files;
    }

    /**
     * 매니페스트의 `effects` 목록을 읽는다.
     *
     * @param  string  $manifest  저장소 상대 매니페스트 경로
     * @return array<int, string> 선언된 효과 이름 (꼬리 주석 제거)
     */
    private function declaredEffects(string $manifest): array
    {
        // meta:allow raw-source-read 생산 소스가 아니라 시나리오 매니페스트 YAML 이다
        $lines = file(self::root().'/'.$manifest, FILE_IGNORE_NEW_LINES) ?: [];
        $effects = [];
        $inBlock = false;

        foreach ($lines as $line) {
            if (preg_match('/^effects:\s*$/', $line) === 1) {
                $inBlock = true;

                continue;
            }
            if (! $inBlock) {
                continue;
            }
            if (preg_match('/^\s*#/', $line) === 1 || trim($line) === '') {
                continue;
            }
            // YAML 꼬리 주석(`- name   # 설명`)은 값이 아니다 — 지우지 않으면 그 줄에서 목록이
            // 끝난 것으로 읽혀 뒤 항목이 통째로 빠진다(7.2.0 매니페스트가 이 표기를 쓴다).
            $line = (string) preg_replace('/\s+#.*$/u', '', $line);
            if (preg_match('/^\s*-\s*(\S+)\s*$/', $line, $m) !== 1) {
                break;
            }
            $effects[] = $m[1];
        }

        return $effects;
    }

    /**
     * 파일이 실제로 들고 있는 `@effects` 항목 (쉼표 분리 — 파서와 같은 규칙).
     *
     * @param  string  $relative  저장소 상대 테스트 파일 경로
     * @return array<int, string> 그 파일이 표시한 효과 이름 (중복 제거)
     */
    private function markedEffects(string $relative): array
    {
        // meta:allow raw-source-read — 시나리오 표식(scenario·effects 태그)은 독블록 안에 있어 원문이 필요하다
        $source = (string) file_get_contents(self::root().'/'.$relative);
        preg_match_all('/@effects\s+(.+)$/m', $source, $matches);

        $effects = [];
        foreach ($matches[1] as $line) {
            foreach (explode(',', $line) as $token) {
                $token = trim($token, " \t*/\r\n");
                if ($token !== '') {
                    $effects[] = $token;
                }
            }
        }

        return array_values(array_unique($effects));
    }

    /**
     * 저장소 전 시나리오 매니페스트가 선언한 효과 이름의 합집합.
     *
     * @return array<int, string> 효과 이름
     */
    private function allDeclaredEffects(): array
    {
        $names = [];
        foreach (glob(self::root().'/tests/scenarios/*.yaml') ?: [] as $path) {
            $relative = 'tests/scenarios/'.basename($path);
            $names = array_merge($names, $this->declaredEffects($relative));
        }

        return array_values(array_unique($names));
    }

    /**
     * 두 매니페스트 파일이 존재하고, 줄 단위 파서가 각각에서 test_files 와 effects 를 하나 이상 읽어 내는지 확인한다.
     *
     * 파서가 빈 배열을 돌려주면 아래 방향 검사가 모두 빈 집합으로 초록이 된다.
     *
     * @scenario manifest_direction=parser_reads_blocks
     *
     * @effects no_cli_manifest_parser_reads_declared_blocks
     *
     * @return void 반환값 없음
     */
    public function test_population_is_not_empty(): void
    {
        foreach (self::MANIFESTS as $manifest) {
            $this->assertFileExists(self::root().'/'.$manifest);
            $this->assertNotEmpty($this->declaredTestFiles($manifest), $manifest.' 의 test_files 를 읽지 못했다 — 파서 규칙이 어긋났다');
            $this->assertNotEmpty($this->declaredEffects($manifest), $manifest.' 의 effects 를 읽지 못했다');
        }
    }

    /**
     * 선언한 파일은 전부 마킹을 하나 이상 갖는다.
     *
     * 매니페스트가 「이 파일도 이 기능을 검증한다」고 선언해 두었는데 마킹이 없으면, 그 파일이
     * 무엇을 떠받치는지 알 수 없고 지워도 아무 게이트가 울지 않는다.
     *
     * @scenario manifest_direction=declared_file_has_marker
     *
     * @effects no_cli_manifest_files_all_carry_markers
     *
     * @return void 반환값 없음
     */
    public function test_every_declared_file_carries_a_marker(): void
    {
        $unmarked = [];

        foreach (self::MANIFESTS as $manifest) {
            foreach ($this->declaredTestFiles($manifest) as $relative) {
                $path = self::root().'/'.$relative;
                if (! is_file($path)) {
                    continue; // 파일 부재는 매니페스트 검증기 소관
                }
                // meta:allow raw-source-read — 시나리오 표식(scenario·effects 태그)은 독블록 안에 있어 원문이 필요하다
                $source = (string) file_get_contents($path);
                if (! preg_match('/@(scenario|effects)\b/', $source)) {
                    $unmarked[] = $manifest.' → '.$relative;
                }
            }
        }

        $this->assertSame([], $unmarked, '선언만 하고 마킹이 없는 파일은 어떤 조합도 떠받치지 않는다');
    }

    /**
     * 선언한 파일의 `@effects` 는 **어느 매니페스트엔가** 등재된 이름이어야 한다 (역방향).
     *
     * 매니페스트 → 마커 단방향만 보면 마커 쪽 쓰레기 항목이 무시된다. 죽은 이름은 다음 사람에게
     * 「이 축은 이미 덮여 있다」로 읽히므로 양방향으로 고정한다.
     *
     * 기준 집합은 저장소 **전 매니페스트**의 합집합이다 (12차 L-7). 이 두 매니페스트만 기준으로
     * 삼으면, 그 `test_files` 에 실린 공정 밖 파일(대시보드 테스트 2건)이 자기 공정의 효과를
     * 하나만 더해도 이 게이트가 red 가 된다 — 이 게이트가 소유하지도 않은 파일의 변경에
     * 결합되는 상태다.
     *
     * @scenario manifest_direction=marker_is_declared
     *
     * @effects no_cli_manifest_effects_are_bidirectional
     *
     * @return void 반환값 없음
     */
    public function test_markers_reference_declared_effects(): void
    {
        $known = array_flip($this->allDeclaredEffects());

        $orphans = [];
        foreach (self::MANIFESTS as $manifest) {
            foreach ($this->declaredTestFiles($manifest) as $relative) {
                if (! is_file(self::root().'/'.$relative)) {
                    continue;
                }
                foreach ($this->markedEffects($relative) as $effect) {
                    if (! isset($known[$effect])) {
                        $orphans[] = $relative.' → '.$effect;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($orphans)),
            '어느 매니페스트도 모르는 효과 이름은 죽은 마커다 — 등재하거나 이름을 맞출 것'
        );
    }

    /**
     * 선언한 효과는 전부 어느 파일엔가 마킹돼 있다 (세 번째 방향 — 11차 #D8).
     *
     * 10차 #G5 가 좁힌 것은 「선언 파일 → 마커」와 「마커 → 선언 효과」 두 방향뿐이다.
     * 남은 방향(선언 효과 → 마커)은 룰 면제 안에 있어 검사되지 않았고, 두 매니페스트의
     * `effects:` 에 아무 이름이나 한 줄 더해도 초록이었다 — 「이 축은 덮여 있다」는 선언이
     * 사실 확인 없이 통과하는 상태다. 지금은 미마킹 0 이지만 그것을 지키는 것이 없었다.
     *
     * @scenario manifest_direction=declared_effect_is_marked
     *
     * @effects no_cli_manifest_effects_are_bidirectional
     *
     * @return void 반환값 없음
     */
    public function test_every_declared_effect_is_marked_somewhere(): void
    {
        // 선언 파일은 두 매니페스트가 일부 공유하므로 마커도 합집합으로 본다.
        $marked = [];
        foreach (self::MANIFESTS as $manifest) {
            foreach ($this->declaredTestFiles($manifest) as $relative) {
                if (! is_file(self::root().'/'.$relative)) {
                    continue;
                }
                foreach ($this->markedEffects($relative) as $effect) {
                    $marked[$effect] = true;
                }
            }
        }

        $unmarked = [];
        foreach (self::MANIFESTS as $manifest) {
            foreach ($this->declaredEffects($manifest) as $effect) {
                if (! isset($marked[$effect])) {
                    $unmarked[] = $manifest.' → '.$effect;
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($unmarked)),
            '선언만 하고 아무 파일도 마킹하지 않은 효과다 — 그 축은 실제로 덮여 있지 않다'
        );
    }

    /**
     * 매니페스트의 `axes:` 목록을 읽는다.
     *
     * @param  string  $manifest  매니페스트 상대 경로
     * @return array<string, array<int, string>> 축 이름 → 값 목록
     */
    private function declaredAxes(string $manifest): array
    {
        // meta:allow raw-source-read 생산 소스가 아니라 시나리오 매니페스트 YAML 이다
        $lines = file(self::root().'/'.$manifest, FILE_IGNORE_NEW_LINES) ?: [];
        $axes = [];
        $inBlock = false;

        foreach ($lines as $line) {
            if (preg_match('/^axes:\s*$/', $line) === 1) {
                $inBlock = true;

                continue;
            }
            if (! $inBlock) {
                continue;
            }
            if (preg_match('/^\s*#/', $line) === 1 || trim($line) === '') {
                continue;
            }
            if (preg_match('/^\s{2,}([a-z_]+):\s*\[([^\]]*)\]/', $line, $m) !== 1) {
                break;   // 목록 끝 (다음 최상위 키)
            }
            $values = array_values(array_filter(array_map(
                static fn (string $v): string => trim($v),
                explode(',', $m[2]),
            ), static fn (string $v): bool => $v !== ''));
            $axes[$m[1]] = $values;
        }

        return $axes;
    }

    /**
     * `buildInstallationTaskList()` 이 만드는 태스크 id 전수 (소스에서 도출).
     *
     * 함수를 실행하려면 인스톨러 런타임을 부팅해야 하므로 본문을 파싱한다 — 목록을 손으로
     * 적으면 그 사본이 곧 드리프트의 출처가 된다.
     *
     * 확장 작업(템플릿·모듈·플러그인·사용자 템플릿·언어팩)은 종류를 넘는 의존 순서로 만들기 위해
     * `installer_extension_install_tasks()`(includes/extension-selection.php)가 만든다. 목록이 그 함수를
     * 부르면 그 함수의 종류별 작업 표(`[작업 id, 실행 함수]` 쌍)도 모집단에 넣는다 — 빼면 확장 작업이
     * 축 대조에서 통째로 사라진다.
     *
     * @return array<int, string> 태스크 id (정렬됨)
     */
    private function taskIdsFromSource(): array
    {
        $source = $this->sourceWithoutComments(self::root().'/public/install/includes/task-runner.php');
        $body = $this->functionBody($source, 'buildInstallationTaskList');

        $this->assertNotSame('', $body, 'buildInstallationTaskList() 을 찾지 못했다 — SSoT 이름이 바뀌었다');

        preg_match_all("/'id'\s*=>\s*'([a-z0-9_]+)'/", $body, $m);
        $ids = $m[1];

        if (str_contains($body, 'installer_extension_install_tasks(')) {
            $selection = $this->sourceWithoutComments(self::root().'/public/install/includes/extension-selection.php');
            $extensionBody = $this->functionBody($selection, 'installer_extension_install_tasks');
            $this->assertNotSame('', $extensionBody, 'installer_extension_install_tasks() 를 찾지 못했다 — 확장 작업 모집단의 기준점이 사라졌다');

            preg_match_all("/\[\s*'([a-z0-9_]+)'\s*,\s*'[A-Za-z0-9_]+SSE'\s*\]/", $extensionBody, $pairs);
            $this->assertNotEmpty($pairs[1], '확장 작업 표([작업 id, 실행 함수] 쌍)를 읽지 못했다 — 표 형태가 바뀌었다');
            $ids = array_merge($ids, $pairs[1]);
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * 마커를 단 인스톨러 테스트는 **어느 매니페스트엔가 등재**돼 있다 (네 번째 방향 — 14차 #6).
     *
     * 앞의 세 방향은 전부 `declaredTestFiles($manifest)` 를 기점으로 돈다 — 즉 **매니페스트가
     * 아는 파일**만 본다. 그래서 반대 방향이 통째로 사각이었다: 존재하는 테스트가 `@effects`
     * 를 달아도 그 파일이 `test_files` 에 없으면 어떤 검사도 그 마커를 보지 않는다. 정적 룰
     * (`test-scenario-coverage`)도 `manifest.test_files` 를 순회하므로 같은 사각을 공유한다.
     *
     * 실제로 12·13차가 만든 회귀 장치 25개 / 효과 이름 78개가 그 사각에 쌓여 있었고, 그중에는
     * **13차의 치명 결함 D2(무CLI 환경에서 설치가 완료되지 않음)를 닫은 게이트**도 있었다 —
     * 지워도 우는 것이 없는 상태였다. 커버리지 SSoT 를 자처하는 매니페스트가 자기 커버리지를
     * 과소 선언하면, 다음 사람은 「이 축은 아직 안 덮였다」로 읽고 같은 것을 다시 만든다.
     *
     * 모집단은 이 공정이 소유한 `tests/Unit/Installer` 로 한정한다 — 저장소 전체로 넓히면
     * 이 게이트가 다른 공정의 파일 추가에 결합된다(11차가 세 번째 방향에서 정한 것과 같은 이유).
     *
     * @scenario manifest_direction=marked_file_is_declared
     *
     * @effects no_cli_marked_installer_tests_are_declared_by_a_manifest
     *
     * @return void 반환값 없음
     */
    public function test_every_marked_installer_test_is_declared_by_some_manifest(): void
    {
        $root = self::root();
        $dir = $root.'/tests/Unit/Installer';
        $files = glob($dir.'/*.php') ?: [];
        $this->assertNotEmpty($files, '인스톨러 테스트를 하나도 찾지 못했다 — 모집단이 비었다');

        // 저장소 **전 매니페스트**가 선언한 파일의 합집합이 기준이다.
        $declared = [];
        foreach (glob($root.'/tests/scenarios/*.yaml') ?: [] as $manifest) {
            // meta:allow raw-source-read — 매니페스트 YAML 원문
            $body = (string) file_get_contents($manifest);
            if (preg_match_all('#tests/Unit/Installer/([A-Za-z0-9_]+\.php)#', $body, $m)) {
                foreach ($m[1] as $name) {
                    $declared[$name] = true;
                }
            }
        }

        $orphans = [];
        foreach ($files as $path) {
            // meta:allow raw-source-read — 시나리오 표식(scenario·effects 태그)은 독블록 안에 있어 원문이 필요하다
            $source = (string) file_get_contents($path);
            if (! preg_match('/@(scenario|effects)\b/', $source)) {
                continue; // 마커가 없는 파일은 이 방향의 대상이 아니다
            }

            $name = basename($path);
            if (! isset($declared[$name])) {
                $orphans[] = $name;
            }
        }

        sort($orphans);

        $this->assertSame(
            [],
            $orphans,
            "마커를 달았는데 어느 매니페스트도 이 파일을 모른다 — 그 축은 「덮여 있다」로 읽히지만\n"
            .'실제로는 커버리지 모델 밖이고, 파일을 지워도 우는 게이트가 없다.',
        );
    }

    /**
     * `task` 축이 실제 태스크 목록과 전수 대응한다 (네 번째 방향 — 12차 M-1).
     *
     * 종전에는 `axes:` 를 검증하는 장치가 **하나도 없었다**. 두 매니페스트가 머리에
     * `audit:allow test-scenario-coverage` 를 달아 조합 커버리지 룰이 꺼져 있고, 10·11차가
     * 좁힌 것은 `effects`/`test_files` 세 방향뿐이다. 11차 #C2 가 「살아 있는 태스크 4종이
     * 축에 없다」를 **손으로** 고쳤지만, 그 드리프트를 만든 메커니즘은 그대로 남아 있었다 —
     * 새 태스크가 추가되면 다시 조용히 어긋난다. 어긋난 결과는 롤백 디스패치·수동 복구
     * 안내의 모집단에서 그 태스크가 빠지는 것이고, 오류로는 나타나지 않는다.
     *
     * @scenario manifest_direction=axis_matches_source
     *
     * @effects no_cli_manifest_axes_match_their_source_of_truth
     *
     * @return void 반환값 없음
     */
    public function test_task_axis_covers_every_live_task(): void
    {
        $axes = $this->declaredAxes('tests/scenarios/no-cli-installer-stepwise.yaml');
        $this->assertArrayHasKey('task', $axes, 'task 축이 사라졌다 — 매니페스트 파서를 의심할 것');

        $axisValues = $axes['task'];
        $mapped = self::TASK_AXIS_MAPPING;

        // ① 축 값 ↔ 접기 표 (양방향) — 표에 없는 축 값도, 축에 없는 표 항목도 드리프트다
        sort($axisValues);
        $mappedKeys = array_keys($mapped);
        sort($mappedKeys);
        $this->assertSame($mappedKeys, $axisValues, 'task 축 값과 접기 표가 어긋난다 — 한쪽만 고치면 대조가 무의미해진다');

        // ② 접기 표 ↔ 실제 태스크 id (양방향)
        $covered = [];
        foreach ($mapped as $ids) {
            foreach ($ids as $id) {
                $covered[$id] = true;
            }
        }
        $coveredIds = array_keys($covered);
        sort($coveredIds);

        $this->assertSame(
            $this->taskIdsFromSource(),
            $coveredIds,
            'buildInstallationTaskList() 의 태스크와 축 접기 표가 어긋난다 — 새 태스크가 시나리오 모집단 밖에 있다'
        );
    }

    /**
     * 실행 모드 축의 어휘가 코어 열거형에서 온다 (12차 M-1).
     *
     * 축에 열거형 밖의 값이 들어가면 그 조합은 실제로 만들어질 수 없고, 열거형에 값이 하나
     * 늘어도 축은 조용히 그대로다 — 어느 쪽도 오류가 되지 않는다.
     *
     * @scenario manifest_direction=axis_matches_source
     *
     * @effects no_cli_manifest_axes_match_their_source_of_truth
     *
     * @return void 반환값 없음
     */
    public function test_execution_mode_axes_come_from_the_core_enum(): void
    {
        $axes = $this->declaredAxes('tests/scenarios/no-cli-installer-stepwise.yaml');
        $enum = array_map(static fn (ExecutionMode $m): string => $m->value, ExecutionMode::cases());

        $this->assertArrayHasKey('execution_mode_choice', $axes);
        $this->assertArrayHasKey('execution_mode_resolved', $axes);

        // 선택값 축 = 열거형 전수 + 어휘 밖 입력을 나타내는 표식 하나
        $choice = $axes['execution_mode_choice'];
        foreach ($enum as $value) {
            $this->assertContains($value, $choice, "execution_mode_choice 축에 열거형 값 {$value} 가 없다");
        }
        $this->assertSame(
            ['invalid'],
            array_values(array_diff($choice, $enum)),
            '선택값 축의 열거형 밖 값은 「어휘 밖 입력」 표식 하나뿐이어야 한다'
        );

        // 확정값 축 = 열거형에서 auto 를 뺀 값 + 구 상태 파일을 나타내는 표식 하나
        $resolved = $axes['execution_mode_resolved'];
        $this->assertNotContains(ExecutionMode::Auto->value, $resolved, 'auto 는 확정값이 아니다 — 확정 시점에 해석된다 (R1)');
        foreach (array_diff($enum, [ExecutionMode::Auto->value]) as $value) {
            $this->assertContains($value, $resolved, "execution_mode_resolved 축에 {$value} 가 없다");
        }
        $this->assertSame(
            ['absent'],
            array_values(array_diff($resolved, $enum)),
            '확정값 축의 열거형 밖 값은 「구 상태 파일」 표식 하나뿐이어야 한다'
        );
    }

    /**
     * 진행 모드 축의 어휘가 인스톨러 소스와 일치한다 (12차 M-1).
     *
     * @scenario manifest_direction=axis_matches_source
     *
     * @effects no_cli_manifest_axes_match_their_source_of_truth
     *
     * @return void 반환값 없음
     */
    public function test_installation_mode_axis_matches_the_installer_vocabulary(): void
    {
        $axes = $this->declaredAxes('tests/scenarios/no-cli-installer-stepwise.yaml');
        $this->assertArrayHasKey('installation_mode', $axes);

        $source = $this->sourceWithoutComments(self::root().'/public/install/includes/execution-mode.php');

        $this->assertSame(
            1,
            preg_match("/in_array\(\s*\\\$requested\s*,\s*\[([^\]]*)\]/", $source, $m),
            '진행 모드 어휘를 소스에서 찾지 못했다 — 판정 지점이 옮겨졌다'
        );

        $vocabulary = array_values(array_filter(array_map(
            static fn (string $v): string => trim($v, " '\""),
            explode(',', $m[1]),
        ), static fn (string $v): bool => $v !== ''));

        sort($vocabulary);
        $axisValues = $axes['installation_mode'];
        sort($axisValues);

        $this->assertSame($vocabulary, $axisValues, '진행 모드 축이 인스톨러가 받는 어휘와 어긋난다');
    }

    /**
     * 축 파서 자신이 실제로 값을 읽는가 (부정 픽스처).
     *
     * 파서가 죽으면 위 세 검사가 「축이 없다」로 red 가 나야 하는데, 빈 배열을 돌려주는
     * 형태로 망가지면 `assertArrayHasKey` 가 그것을 잡는다 — 그 경로를 실제로 확인한다.
     *
     * @scenario manifest_direction=parser_reads_blocks
     *
     * @effects no_cli_manifest_parser_reads_declared_blocks
     *
     * @return void 반환값 없음
     */
    public function test_axis_parser_reads_declared_values(): void
    {
        foreach (self::MANIFESTS as $manifest) {
            $axes = $this->declaredAxes($manifest);
            $this->assertNotEmpty($axes, $manifest.' 의 axes 를 읽지 못했다 — 파서 규칙이 어긋났다');

            foreach ($axes as $name => $values) {
                $this->assertNotEmpty($values, $manifest.' 의 '.$name.' 축이 비어 있다');
            }
        }
    }

    /**
     * 효과 이름이 한 매니페스트 안에서 중복 선언되지 않는가 (11차 #C1).
     *
     * 중복은 개수를 부풀려 라운드 수치와 실제 고정 계약 수를 어긋나게 만들고, 한쪽을 지워도
     * 아무 게이트가 울지 않는다.
     *
     * @scenario manifest_direction=no_duplicate_effects
     *
     * @effects no_cli_manifest_effects_have_no_duplicates
     *
     * @return void 반환값 없음
     */
    public function test_declared_effects_have_no_duplicates(): void
    {
        foreach (self::MANIFESTS as $manifest) {
            $effects = $this->declaredEffects($manifest);
            $duplicates = array_values(array_unique(array_diff_assoc($effects, array_unique($effects))));

            $this->assertSame([], $duplicates, $manifest.' 에 중복 선언된 효과가 있다');
        }
    }
}
