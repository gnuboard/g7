<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 서버 태스크 목록 ↔ 진행 화면 목록 정합성.
 *
 * `buildInstallationTaskList()` 이 설치 단계의 SSoT 다. 화면(`public/install/views/5-installation.php`)이 그
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
     * 진행 화면을 렌더해 화면 데이터 블록의 `task_groups`(화면이 `window.INSTALLER_TASK_GROUPS` 로 쓰는 값)를 꺼낸다.
     *
     * strict CSP 에서 인라인 스크립트가 실행되지 않으므로 Step 5 는 그룹 정의를 `#installer-step-data`
     * JSON 블록의 `task_groups` 키로 싣고, step-5-installation.js 가 그 값을 전역으로 옮긴다. 그 키가
     * 비면 단계 스크립트는 손으로 적은 옛 그룹 사본으로 떨어진다 — 이 검사는 서버가 파생한 목록이
     * 실제로 실리는지와 **무엇이 빠졌는지**를 함께 드러낸다.
     *
     * @return array<int, array<string, mixed>> 화면 그룹 목록 (표시 순서)
     */
    private function renderTaskGroups(): array
    {
        self::bootInstaller();

        $installRoot = dirname(__DIR__, 3).'/public/install';

        // 뷰는 index.php 가 넘겨주는 $state 를 읽는다. 확장 목록은 서버 목록 쪽도
        // getInstallationState() 로 같은 원본을 읽으므로 여기서 맞출 필요가 없다.
        $state = getInstallationState();

        ob_start();
        require $installRoot.'/views/5-installation.php';
        $html = (string) ob_get_clean();

        $matched = preg_match('/<script\b[^>]*\bid="installer-step-data"[^>]*>(.*?)<\/script>/s', $html, $m);
        $this->assertSame(
            1,
            $matched,
            'views/5-installation.php 가 화면 데이터 블록(#installer-step-data)을 내보내지 않는다 — 진행 목록이 통째로 비었다는 뜻이다',
        );

        $data = json_decode(trim($m[1]), true);
        $this->assertIsArray($data, 'Step 5 화면 데이터 블록이 JSON 으로 해석되지 않는다');

        $groups = $data['task_groups'] ?? null;
        $this->assertIsArray($groups, 'views/5-installation.php 가 task_groups 를 내보내지 않는다 — 화면이 손으로 적은 옛 그룹 사본으로 떨어진다');
        $this->assertNotEmpty($groups, 'views/5-installation.php 의 task_groups 가 비었다 — 화면이 손으로 적은 옛 그룹 사본으로 떨어진다');

        $seen = [];
        foreach ($groups as $group) {
            $this->assertIsArray($group);
            $groupId = (string) ($group['id'] ?? '');
            // 같은 묶음이 실행 순서상 다시 나타나면 화면은 새 그룹을 연다(`{id}-2`) — 묶음 이름은 접미사 앞이다.
            $this->assertArrayHasKey(
                (string) preg_replace('/-\d+$/', '', $groupId),
                $this->groupIds,
                '화면이 알려지지 않은 그룹을 내보낸다: '.json_encode($group['id'] ?? null),
            );
            // 그룹 id 는 DOM id(task-group-items-{id})·접기 대상이라 겹치면 다른 그룹이 접힌다.
            $this->assertArrayNotHasKey($groupId, $seen, '화면 그룹 id 가 겹친다: '.$groupId);
            $seen[$groupId] = true;
        }

        return $groups;
    }

    /**
     * 화면 그룹을 펼쳐 완료 마커 키를 표시 순서대로 꺼낸다.
     *
     * @param  array<int, array<string, mixed>>  $groups  화면 그룹 목록
     * @return array<int, string> 완료 마커 키 ("id" 또는 "id:target")
     */
    private static function flattenTaskKeys(array $groups): array
    {
        $keys = [];
        foreach ($groups as $group) {
            foreach ((array) ($group['tasks'] ?? []) as $task) {
                $id = (string) ($task['id'] ?? '');
                $target = (string) ($task['target'] ?? '');
                $keys[] = $target !== '' ? $id.':'.$target : $id;
            }
        }

        return $keys;
    }

    /**
     * 진행 화면을 렌더해 완료 마커 키를 표시 순서대로 꺼낸다.
     *
     * @return array<int, string> 완료 마커 키 ("id" 또는 "id:target")
     */
    private function renderTaskKeys(): array
    {
        return self::flattenTaskKeys($this->renderTaskGroups());
    }

    /**
     * 그룹 ID 집합 — 화면이 내보내는 그룹이 진행 화면이 아는 묶음인지 대조하는 기준.
     *
     * @var array<string, true>
     */
    private array $groupIds = [
        'environment' => true,
        'database' => true,
        'extensions' => true,
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
     * 확장 작업이 종류를 넘는 의존 순서로 실행될 때 화면이 그 순서 그대로 그린다.
     *
     * 종전 화면은 확장 작업을 종류별 묶음(관리자 템플릿 → 모듈 → 플러그인 → 사용자 템플릿)에 나눠 담아,
     * 플러그인이 모듈보다 먼저 실행되는 구성에서 진행 표시가 아래 묶음부터 채워졌다. 렌더 상태에는
     * 확장 선택이 없을 수 있어 위 전수 검사는 이 경우를 보지 못한다 — 여기서는 설치 순서 함수를
     * 공용 픽스처 스캔으로 대신해 실제 해석기 순서를 만든다.
     *
     * @scenario surface=installer, edge=module_to_plugin, edge=admin_template_to_module
     *
     * @effects installer_view_task_list_follows_execution_order
     *
     * @return void 반환값 없음
     */
    public function test_확장_작업이_종류를_넘는_실행_순서대로_화면에_그려진다(): void
    {
        require_once dirname(__DIR__, 2).'/Fixtures/installer/stub-extension-install-order.php';
        $GLOBALS['installer_test_selection'] = [
            'admin_templates' => ['x-admin'],
            'user_templates' => ['sirsoft-basic'],
            'modules' => ['sirsoft-board', 'sirsoft-ecommerce', 'sirsoft-page', 'x-learning'],
            'plugins' => ['sirsoft-ckeditor5', 'sirsoft-daum_postcode', 'sirsoft-tosspayments'],
            'language_packs' => ['g7-core-ja'],
        ];

        $groups = $this->renderTaskGroups();
        $viewKeys = self::flattenTaskKeys($groups);
        $serverKeys = array_map(
            static fn (array $task): string => (string) $task['key'],
            buildInstallationTaskList(),
        );

        // 전제 — 서버 순서가 실제로 종류를 넘는다(모듈이 관리자 템플릿보다, 플러그인이 모듈보다 먼저 오는 자리가 있다).
        // 이 전제가 깨지면 아래 검사는 종류별 묶음 화면도 통과시킨다.
        $position = array_flip($serverKeys);
        $this->assertLessThan($position['template_install:x-admin'], $position['module_install:sirsoft-board']);
        $this->assertLessThan($position['module_install:x-learning'], $position['plugin_install:sirsoft-ckeditor5']);
        $this->assertLessThan($position['plugin_install:sirsoft-tosspayments'], $position['module_install:x-learning']);

        $this->assertSame($serverKeys, $viewKeys, '화면 목록의 순서가 서버 실행 순서와 다르다 — 진행 표시가 위에서 아래로 차례로 채워지지 않는다');

        // 확장 작업은 한 묶음 안에서 실행 순서로 — 종류는 항목마다 kind 로 싣는다(「…에 필요」 배지가 이 값으로 찾는다).
        $this->assertSame(
            ['environment', 'database', 'extensions', 'language_packs', 'finalize'],
            array_column($groups, 'id'),
        );
        // 그룹 머리 이름 — 키가 없으면 화면은 키 문자열을 그대로 그린다.
        foreach (['ko', 'en'] as $locale) {
            $translations = require dirname(__DIR__, 3)."/public/install/lang/{$locale}.php";
            foreach (array_column($groups, 'labelKey') as $labelKey) {
                $this->assertArrayHasKey($labelKey, $translations, "{$locale} 에 그룹 이름 키가 없다: {$labelKey}");
            }
        }
        $kindByTaskId = [
            'template_install' => 'admin_templates', 'template_activate' => 'admin_templates',
            'module_install' => 'modules', 'module_activate' => 'modules',
            'plugin_install' => 'plugins', 'plugin_activate' => 'plugins',
            'user_template_install' => 'user_templates', 'user_template_activate' => 'user_templates',
            'language_pack_install' => 'language_packs',
        ];
        foreach ($groups as $group) {
            foreach ($group['tasks'] as $task) {
                if (isset($kindByTaskId[$task['id']])) {
                    $this->assertSame($kindByTaskId[$task['id']], $task['kind'] ?? null, '확장 작업 항목에 종류(kind)가 없다: '.json_encode($task));
                } else {
                    $this->assertArrayNotHasKey('kind', $task, '확장이 아닌 작업에 종류가 붙었다: '.json_encode($task));
                }
            }
        }
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
        // 콘솔에도 아무것도 남지 않아 무엇이 잘못됐는지 알 방법이 없었다.
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
