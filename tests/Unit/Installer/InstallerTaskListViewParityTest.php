<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 서버 태스크 목록 ↔ 진행 화면 목록 정합성 (12차 #B22 · #B23 · #B24).
 *
 * `buildInstallationTaskList()` 이 설치 단계의 SSoT 다. 화면(`views/5-installation.php`)이 그
 * 목록을 손으로 다시 적으면 두 사본이 갈라지는데, 갈라진 결과는 **오류로 나타나지 않는다**:
 *
 *  - 진행률 분모가 화면 항목 수라 「10 / 10 · 100%」 를 지나서도 서버가 계속 돈다 (#B23)
 *  - 화면에 없는 단계는 DOM id 가 없어 `setTaskStatus()` 가 조용히 되돌아간다 — 그 단계가
 *    실패해도 빨강이 되지 않는다 (#B24)
 *
 * 실제로 세 단계(dependency_precheck · db_cleanup · config_cache)가 그 사각에 있었다. 서버는
 * 셋을 **조건 없이** 실행하고 다국어 라벨도 ko/en 양쪽에 이미 있었는데 화면 그룹 정의에서만
 * 빠져 있었다.
 *
 * 화면을 실제로 렌더해 그 결과를 검사한다 — 소스 문자열 대조는 파생 로직이 잘못돼도 통과한다.
 *
 * 커버 축: task_source server·view / task_visibility rendered·orphaned / label_locale ko·en
 *
 * 효과 요약(마커 아님 — 평문): 화면 진행 목록이 서버 태스크 목록과 같고, 모든 태스크가 표시 이름을 가진다.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerTaskListViewParityTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /** 화면이 그룹 소속을 정하지 못한 태스크가 떨어지는 그룹 (적어도 보이기는 해야 한다). */
    private const FALLBACK_GROUP = 'finalize';

    /**
     * 인스톨러 런타임을 세션·출력 부작용 없이 적재한다.
     *
     * @return void 반환값 없음
     */
    private static function bootInstaller(): void
    {
        if (! defined('BASE_PATH')) {
            $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/install/';
        }

        $installRoot = dirname(__DIR__, 3).'/public/install';

        require_once $installRoot.'/includes/config.php';
        require_once $installRoot.'/includes/functions.php';
        require_once $installRoot.'/includes/installer-state.php';
        require_once $installRoot.'/includes/task-runner.php';
    }

    /**
     * 진행 화면을 렌더해 `window.INSTALLER_TASK_GROUPS` 가 담은 완료 마커 키를 순서대로 꺼낸다.
     *
     * JSON(파생) 형태와 손으로 적은 JS 리터럴 형태를 모두 읽는다. 형태만 보고 실패하면
     * 「목록을 다시 손으로 적었다」는 것만 알 뿐, **무엇이 빠졌는지**는 드러나지 않는다 —
     * 이 검사가 잡아야 하는 것은 후자다.
     *
     * @return array<int, string> 완료 마커 키 ("id" 또는 "id:target")
     */
    private function renderTaskKeys(): array
    {
        self::bootInstaller();

        $installRoot = dirname(__DIR__, 3).'/public/install';

        // 뷰는 index.php 가 넘겨주는 $state 를 읽는다. 확장 목록은 서버 목록 쪽도
        // getInstallationState() 로 같은 원본을 읽으므로 여기서 맞출 필요가 없다.
        $state = getInstallationState();

        ob_start();
        require $installRoot.'/views/5-installation.php';
        $html = (string) ob_get_clean();

        $matched = preg_match('/window\.INSTALLER_TASK_GROUPS\s*=\s*(\[.*?\]);/s', $html, $m);
        $this->assertSame(
            1,
            $matched,
            'views/5-installation.php 가 window.INSTALLER_TASK_GROUPS 를 내보내지 않는다 — 진행 목록이 통째로 비었다는 뜻이다',
        );

        // `{ id: 'x' }` (JS 리터럴) 와 `{"id":"x","target":"y"}` (JSON) 를 함께 읽는다.
        preg_match_all(
            '/["\']?id["\']?\s*:\s*["\']([a-z0-9_]+)["\']\s*(?:,\s*["\']?target["\']?\s*:\s*["\']([^"\']+)["\'])?/',
            $m[1],
            $entries,
            PREG_SET_ORDER,
        );

        $keys = [];
        foreach ($entries as $entry) {
            // 그룹 자신의 `id: 'environment'` 는 태스크가 아니다 — labelKey 가 뒤따르면 그룹이다.
            if (isset($this->groupIds[$entry[1]])) {
                continue;
            }
            $keys[] = ($entry[2] ?? '') !== '' ? $entry[1].':'.$entry[2] : $entry[1];
        }

        return $keys;
    }

    /**
     * 그룹 ID 집합 — 평탄화 시 그룹 헤더의 `id` 를 태스크로 오인하지 않기 위한 제외 목록.
     *
     * @var array<string, true>
     */
    private array $groupIds = [
        'environment' => true,
        'database' => true,
        'admin_templates' => true,
        'modules' => true,
        'plugins' => true,
        'user_templates' => true,
        'language_packs' => true,
        'finalize' => true,
    ];

    /**
     * 진행 화면을 렌더해 얻은 태스크 키가 서버 태스크 목록과 빠짐도 남음도 없이 같은 순서인지 확인한다.
     *
     * @scenario task=dependency_precheck, task=db_cleanup, task=config_cache
     *
     * @effects installer_view_task_list_matches_server_task_list
     *
     * @return void 반환값 없음
     */
    public function test_화면_진행_목록이_서버_태스크_목록_전수를_담는다(): void
    {
        $viewKeys = $this->renderTaskKeys();

        self::bootInstaller();
        $serverKeys = array_map(
            static fn (array $task): string => (string) $task['key'],
            buildInstallationTaskList(),
        );

        $missing = array_values(array_diff($serverKeys, $viewKeys));
        $this->assertSame(
            [],
            $missing,
            '서버가 실행하는데 화면에 없는 단계: '.implode(', ', $missing)
            ."\n그 단계는 진행률 분모에서 빠지고(100% 뒤에도 설치가 계속된다) 실패해도 빨강이 되지 않는다.",
        );

        $extra = array_values(array_diff($viewKeys, $serverKeys));
        $this->assertSame(
            [],
            $extra,
            '화면에 있는데 서버가 실행하지 않는 단계: '.implode(', ', $extra)
            ."\n영원히 「대기」로 남아 진행률이 100% 에 도달하지 못한다.",
        );

        // 순서까지 같아야 한다 — 다르면 운영자가 보는 순서와 실제 실행 순서가 어긋난다.
        $this->assertSame($serverKeys, $viewKeys, '화면 목록의 순서가 서버 실행 순서와 다르다');
    }

    /**
     * 화면의 태스크 ID 그룹 소속표가 서버 목록 함수가 만들 수 있는 모든 태스크 ID 를 담는지 원문에서 대조한다.
     *
     * @scenario task=dependency_precheck, task=db_cleanup, task=config_cache, task=module, task=plugin
     *
     * @effects installer_view_task_list_matches_server_task_list
     *
     * @return void 반환값 없음
     */
    public function test_그룹_소속표가_서버_태스크_i_d_전수를_안다(): void
    {
        // 렌더 시점의 상태에는 확장이 선택돼 있지 않을 수 있다. 소속표 자체를 소스에서 읽어
        // 서버가 만들 수 있는 **모든** 태스크 ID 와 대조한다 — 그래야 확장 태스크가 조용히
        // 폴백 그룹으로 떨어지는 경우도 잡힌다.
        $viewPath = dirname(__DIR__, 3).'/public/install/views/5-installation.php';
        $this->assertFileExists($viewPath);

        // 주석을 지운 뒤 대조한다 — 주석 처리된 소속표도 원문에는 글자로 남는다.
        $viewSource = $this->sourceWithoutComments($viewPath);

        $mapMatched = preg_match('/\$taskGroupByTaskId\s*=\s*\[(.*?)\n\];/s', $viewSource, $m);
        $this->assertSame(1, $mapMatched, '화면에 태스크 ID → 그룹 소속표가 없다 — 목록을 다시 손으로 적었다는 뜻이다');

        preg_match_all("/'([a-z0-9_]+)'\s*=>/", $m[1], $mapKeys);
        $mapped = $mapKeys[1];

        $runnerPath = dirname(__DIR__, 3).'/public/install/includes/task-runner.php';
        $this->assertFileExists($runnerPath);

        $runnerSource = $this->sourceWithoutComments($runnerPath);

        $listMatched = preg_match(
            "/function buildInstallationTaskList\(\): array\s*\{(.*?)\n    \}/s",
            $runnerSource,
            $body,
        );
        $this->assertSame(1, $listMatched, 'buildInstallationTaskList() 본문을 찾지 못했다');

        preg_match_all("/'id'\s*=>\s*'([a-z0-9_]+)'/", $body[1], $serverIds);
        $declared = array_values(array_unique($serverIds[1]));
        $this->assertNotEmpty($declared, '서버 태스크 ID 를 하나도 읽지 못했다 — 이 검사가 통째로 사각이 된다');

        $unmapped = array_values(array_diff($declared, $mapped));
        $this->assertSame(
            [],
            $unmapped,
            '그룹 소속이 정해지지 않은 서버 태스크: '.implode(', ', $unmapped)
            ."\n폴백 그룹('".self::FALLBACK_GROUP."')으로 떨어져 보이기는 하지만, 운영자에게는 엉뚱한 묶음에 나타난다.",
        );
    }

    /**
     * 화면 스크립트의 상태 갱신 함수가 DOM 항목이 없을 때 콘솔 경고를 남기는지 확인한다.
     *
     * @scenario task=config_cache
     *
     * @effects installer_view_task_list_matches_server_task_list
     *
     * @return void 반환값 없음
     */
    public function test_목록에_없는_단계의_상태_갱신이_조용히_사라지지_않는다(): void
    {
        // 목록이 갈라지면 서버가 도는 단계의 DOM 항목이 없다. `setTaskStatus()` 가 그때
        // 아무 흔적도 없이 되돌아가면 **그 단계는 실패해도 빨강이 되지 않는다** — 화면에도
        // 콘솔에도 아무것도 남지 않아 무엇이 잘못됐는지 알 방법이 없었다 (12차 #B24).
        // 목록 자체는 위 검사가 지키고, 여기서는 그 방어가 뚫렸을 때의 흔적을 지킨다.
        // JS 도 같은 규칙이다 — 주석 처리된 console.warn 은 흔적을 남기지 않는다.
        $installer = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/assets/js/installer.js');

        $matched = preg_match(
            '/function setTaskStatus\(.*?\n    \}/s',
            $installer,
            $body,
        );
        $this->assertSame(1, $matched, 'setTaskStatus() 를 찾지 못했다');

        $this->assertMatchesRegularExpression(
            '/if \(!taskEl\) \{.*?console\.warn/s',
            $body[0],
            'DOM 항목이 없을 때 조용히 되돌아간다 — 서버가 도는 단계가 화면에 없다는 사실이 어디에도 남지 않는다',
        );
    }

    /**
     * 그룹 소속표에 오른 모든 태스크 ID 가 ko 와 en 다국어 파일에 단계 이름 키를 가지는지 확인한다.
     *
     * @scenario locale=ko, locale=en
     *
     * @effects installer_every_task_has_a_display_label
     *
     * @return void 반환값 없음
     */
    public function test_모든_태스크가_ko_en_양쪽에_표시_이름을_가진다(): void
    {
        $installRoot = dirname(__DIR__, 3).'/public/install';

        $viewSource = $this->sourceWithoutComments($installRoot.'/views/5-installation.php');
        preg_match('/\$taskGroupByTaskId\s*=\s*\[(.*?)\n\];/s', (string) $viewSource, $m);
        preg_match_all("/'([a-z0-9_]+)'\s*=>/", $m[1] ?? '', $mapKeys);

        foreach (['ko', 'en'] as $locale) {
            $translations = require $installRoot."/lang/{$locale}.php";

            $missing = [];
            foreach ($mapKeys[1] as $taskId) {
                if (! isset($translations["task_{$taskId}"])) {
                    $missing[] = "task_{$taskId}";
                }
            }

            $this->assertSame(
                [],
                $missing,
                "{$locale} 에 없는 단계 이름 키: ".implode(', ', $missing)
                ."\n화면은 키가 없으면 키 문자열을 그대로 그린다.",
            );
        }
    }
}
