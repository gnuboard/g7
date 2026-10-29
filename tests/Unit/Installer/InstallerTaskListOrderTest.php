<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * 설치 작업 목록 조립 — 해석기 설치 순서(종류 횡단 의존 반영)를 그대로 작업 순서로 쓰는지 잠근다(#153).
 *
 * 종전 runInstallationTasks() 는 종류별 고정 foreach(관리자 템플릿 → 모듈 → 플러그인 → 사용자 템플릿)로 작업을 만들어
 * 모듈이 플러그인에 의존하면 사전 검증을 통과한 뒤 모듈 설치에서 멈췄다.
 */
class InstallerTaskListOrderTest extends TestCase
{
    /**
     * 해석기 함수를 로드한다.
     *
     * @return void 반환값 없음
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3).'/public/install/includes/extension-selection.php';
    }

    /**
     * 공용 픽스처의 스캔 결과.
     *
     * @return array<string, mixed> 스캔 결과
     */
    private static function scan(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/installer/extension-resolver-cases.json'), true)['scan'];
    }

    /**
     * 작업 목록의 `{id}:{target}` 키 — 이어 하기(completed_tasks) 키와 같은 형식.
     *
     * @param  array<int, array<string, mixed>>  $tasks  작업 목록
     * @return array<int, string> 키 목록
     */
    private static function keys(array $tasks): array
    {
        return array_map(fn (array $t): string => $t['id'].':'.$t['target'], $tasks);
    }

    /**
     * 모듈이 플러그인에 의존하면 플러그인 설치·활성화 작업이 모듈 설치보다 먼저 온다.
     *
     * @scenario surface=installer, edge=module_to_plugin
     *
     * @effects installer_task_list_follows_cross_type_order
     *
     * @return void 반환값 없음
     */
    public function test_plugin_tasks_precede_dependent_module(): void
    {
        $resolution = installer_resolve_extension_selection(self::scan(), ['admin_templates' => ['sirsoft-admin_basic'], 'modules' => ['x-learning']]);
        $tasks = installer_extension_install_tasks($resolution['install_order']);

        $this->assertSame([
            'template_install:sirsoft-admin_basic',
            'template_activate:sirsoft-admin_basic',
            'plugin_install:sirsoft-ckeditor5',
            'plugin_activate:sirsoft-ckeditor5',
            'module_install:x-learning',
            'module_activate:x-learning',
        ], self::keys($tasks));
    }

    /**
     * 관리자 템플릿이 모듈에 의존하면 모듈 작업이 관리자 템플릿보다 먼저 온다.
     *
     * @scenario surface=installer, edge=admin_template_to_module
     *
     * @effects installer_task_list_follows_cross_type_order
     *
     * @return void 반환값 없음
     */
    public function test_module_tasks_precede_dependent_admin_template(): void
    {
        $resolution = installer_resolve_extension_selection(self::scan(), ['admin_templates' => ['x-admin']]);

        $this->assertSame(
            ['module_install:sirsoft-board', 'module_activate:sirsoft-board', 'template_install:x-admin', 'template_activate:x-admin'],
            self::keys(installer_extension_install_tasks($resolution['install_order']))
        );
    }

    /**
     * 작업 id·함수 이름·인자는 종류별로 고정이고 언어팩만 best-effort 다 — 이어 하기 키 형식이 바뀌지 않는다.
     *
     * @scenario surface=installer, edge=chain
     *
     * @effects installer_task_list_keeps_resume_keys
     *
     * @return void 반환값 없음
     */
    public function test_task_shape_and_resume_keys_are_unchanged(): void
    {
        $tasks = installer_extension_install_tasks([
            'admin_templates:a', 'modules:m', 'plugins:p', 'user_templates:u', 'language_packs:g7-core-ja',
        ]);

        $this->assertSame([
            ['id' => 'template_install', 'target' => 'a', 'function' => 'installAdminTemplateSSE', 'args' => ['a']],
            ['id' => 'template_activate', 'target' => 'a', 'function' => 'activateAdminTemplateSSE', 'args' => ['a']],
            ['id' => 'module_install', 'target' => 'm', 'function' => 'installModuleSSE', 'args' => ['m']],
            ['id' => 'module_activate', 'target' => 'm', 'function' => 'activateModuleSSE', 'args' => ['m']],
            ['id' => 'plugin_install', 'target' => 'p', 'function' => 'installPluginSSE', 'args' => ['p']],
            ['id' => 'plugin_activate', 'target' => 'p', 'function' => 'activatePluginSSE', 'args' => ['p']],
            ['id' => 'user_template_install', 'target' => 'u', 'function' => 'installUserTemplateSSE', 'args' => ['u']],
            ['id' => 'user_template_activate', 'target' => 'u', 'function' => 'activateUserTemplateSSE', 'args' => ['u']],
            ['id' => 'language_pack_install', 'target' => 'g7-core-ja', 'function' => 'installLanguagePackSSE', 'args' => ['g7-core-ja'], 'best_effort' => true],
        ], $tasks);
    }

    /**
     * 해석에 실패하면(순환·자동 포함 필요) 작업 목록은 종전 종류 순서로 만든다 — 사전 검증이 사유와 함께 멈춘다.
     *
     * @scenario surface=installer, edge=cycle
     *
     * @effects installer_task_list_falls_back_to_category_order
     *
     * @return void 반환값 없음
     */
    public function test_failed_resolution_falls_back_to_category_order(): void
    {
        $this->assertSame(
            ['admin_templates:a', 'modules:m1', 'modules:m2', 'plugins:p', 'language_packs:l'],
            installer_category_install_order(['plugins' => ['p'], 'modules' => ['m1', 'm2'], 'admin_templates' => ['a'], 'language_packs' => ['l']])
        );
    }

    /**
     * runInstallationTasks() 가 해석기 install_order 로 확장 작업을 만들고, 해석 실패 시에만 종류 순서로 물러난다.
     *
     * task-runner.php 는 진입과 함께 작업을 실행하는 파일이라 소스 배선을 대조한다.
     *
     * @scenario surface=installer, edge=module_to_plugin
     *
     * @effects installer_task_runner_uses_install_order
     *
     * @return void 반환값 없음
     */
    public function test_task_runner_builds_extension_tasks_from_install_order(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/includes/task-runner.php');
        $body = substr($source, (int) strpos($source, 'function runInstallationTasks()'));

        $this->assertStringContainsString("? \$ordered['install_order']", $body);
        $this->assertStringContainsString(': installer_category_install_order($selectedExtensions);', $body);
        $this->assertStringContainsString('foreach (installer_extension_install_tasks($installOrder) as $extensionTask)', $body);
        $this->assertStringNotContainsString("foreach (\$selectedExtensions['modules']", $body);
        $this->assertStringNotContainsString("foreach (\$selectedExtensions['plugins']", $body);
        $this->assertLessThan(
            strpos($body, "'id' => 'create_settings_json'"),
            strpos($body, 'installer_extension_install_tasks($installOrder)'),
            '확장 작업은 마무리 작업보다 먼저 와야 합니다.'
        );
    }

    /**
     * 의존성 사전 검증 실패(순환 등)는 DB 작업 전이라 되돌릴 것이 없다 — 롤백을 시도해 「롤백 실패 — DB 수동 정리」 안내를 붙이지 않는다.
     *
     * @scenario surface=installer, edge=cycle
     *
     * @effects installer_precheck_failure_skips_rollback_notice
     *
     * @return void 반환값 없음
     */
    public function test_precheck_failure_is_marked_rolled_back(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/includes/task-runner.php');
        $body = substr($source, (int) strpos($source, 'function dependencyPrecheckSSE()'));
        $body = substr($body, 0, (int) strpos($body, "markTaskCompleted('dependency_precheck');"));

        $this->assertMatchesRegularExpression("/'message_key' => 'dependency_precheck_failed',.*'rollback_done' => true,/s", $body);
    }

    /**
     * 「Step 4 로 돌아가기」로 돌아오면 이전 선택이 다시 체크된다 — Step 4 화면이 보존된 selected_extensions 를 넘기고
     * 상태 초기화(resetState)는 그 값을 지우지 않는다.
     *
     * @scenario surface=installer, edge=cycle
     *
     * @effects installer_step4_restores_selection_after_back
     *
     * @return void 반환값 없음
     */
    public function test_step4_restores_saved_selection_after_going_back(): void
    {
        $root = dirname(__DIR__, 3);
        $view = (string) file_get_contents($root.'/public/install/views/4-extension-selection.php');
        $this->assertStringContainsString("window.INSTALLER_SELECTED_EXTENSIONS = <?= installer_json_encode((object) (\$state['selected_extensions'] ?? [])) ?>;", $view);

        $reset = (string) file_get_contents($root.'/public/install/api/state-management.php');
        $body = substr($reset, (int) strpos($reset, 'private function resetState(): void'));
        $body = substr($body, 0, (int) strpos($body, 'saveInstallationStateCas('));
        $this->assertStringNotContainsString("selected_extensions", $body, '초기화가 고른 확장을 지우면 돌아가서 다시 고를 것이 없다');
    }
}
