<?php
/**
 * Step 5: 설치 진행
 *
 * Composer 의존성 설치, .env 파일 생성, Artisan 명령 실행, 관리자 계정 생성,
 * 선택한 확장 기능(템플릿, 모듈, 플러그인) 설치를 수행합니다.
 * 진행률 표시와 실시간 로그를 통해 사용자에게 피드백을 제공합니다.
 */

// 설정 정보 가져오기 (state.json에서 로드 - 다른 브라우저에서도 접근 가능)
$config = $state['config'] ?? $_SESSION['install_config'] ?? [];
?>

<!-- 필수 파일 생성 안내 섹션 (JS에서 제어, 기본 숨김) -->
<div id="env-setup-section" class="installer-container installer-container-wide hidden">
    <div class="requirement-card">
        <div class="requirement-card-header">
            <h3 class="requirement-card-title"><?= htmlspecialchars(lang('file_setup_title')) ?></h3>
        </div>
        <div class="requirement-card-body">
            <p><?= htmlspecialchars(lang('file_setup_description')) ?></p>

            <!-- 누락 파일 목록 (JS에서 동적 표시) -->
            <ul id="missing-files-list" class="missing-files"></ul>

            <!-- 명령어 안내 (절대경로) -->
            <p><?= htmlspecialchars(lang('file_setup_guide')) ?></p>
            <div class="code-box">
                <pre id="file-setup-command"></pre>
                <button class="btn-copy" onclick="copySetupCommand()"><?= htmlspecialchars(lang('copy_command')) ?></button>
            </div>

            <!-- 상대경로 명령어 -->
            <p class="fix-guide-hint" id="file-setup-relative-hint" style="display:none;"><?= htmlspecialchars(lang('or_relative_path')) ?></p>
            <div class="code-box" id="file-setup-relative-box" style="display:none;">
                <pre id="file-setup-command-relative"></pre>
                <button class="btn-copy" onclick="copySetupCommandRelative()"><?= htmlspecialchars(lang('copy_command')) ?></button>
            </div>

            <!-- 확인 버튼 -->
            <div class="env-check-actions">
                <button id="env-recheck-btn" class="btn btn-primary" onclick="recheckFiles()"><?= htmlspecialchars(lang('file_check_button')) ?></button>
                <span id="env-check-status" class="hidden"></span>
            </div>
        </div>
    </div>
</div>

<div class="installer-container installer-container-wide">
    <div class="installer-header-with-abort">
        <h1 id="installer-title" class="installer-title"><?= htmlspecialchars(lang('installation_title')) ?></h1>
        <button id="abort-installation-btn" class="btn-abort" onclick="abortInstallation()" style="display: none;">
            <svg class="abort-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <?= htmlspecialchars(lang('abort_installation')) ?>
        </button>
    </div>

    <!-- 완료 섹션 -->
    <?php
    $completionButtons = '<a href="../admin/login" class="btn btn-success">' . htmlspecialchars(lang('go_to_admin_login')) . '</a>';
    echo renderInstallResultSection('completion', 'success', 'installation_completed', 'installation_complete_message', $completionButtons);
    ?>

    <!-- 중단 섹션 -->
    <?php
    $abortedButtons = '<button onclick="resumeInstallationFromAborted()" class="btn btn-primary">' . htmlspecialchars(lang('resume_continue')) . '</button>
                <button onclick="goToSettingsWithConfirm()" class="btn btn-secondary">' . htmlspecialchars(lang('back_to_settings')) . '</button>';
    echo renderInstallResultSection('aborted', 'warning', 'installation_aborted', 'installation_aborted_message', $abortedButtons);
    ?>

    <!-- 실패 섹션 -->
    <?php
    $failureButtons = '<button onclick="retryInstallation()" class="btn btn-primary">' . htmlspecialchars(lang('retry_installation')) . '</button>
                <button onclick="goToSettingsWithConfirm()" class="btn btn-secondary">' . htmlspecialchars(lang('back_to_settings')) . '</button>';
    echo renderInstallResultSection('failure', 'error', 'installation_failed', '', $failureButtons);
    ?>

    <div class="alert alert-warning" id="install-warning">
        ⚠ <?= htmlspecialchars(lang('do_not_close_page')) ?>
    </div>

    <!-- 설치 진행 상황 카드 -->
    <div class="requirement-card">
        <div class="requirement-card-header" id="installation-card-header" onclick="toggleInstallationCard()">
            <h3 class="requirement-card-title">
                <?= htmlspecialchars(lang('progress_status')) ?>
                <span id="install-toggle-icon" class="toggle-icon-inline hidden">▼</span>
            </h3>
        </div>

        <div id="installation-card-body" class="requirement-card-body">
            <!-- 전체 진행률 -->
            <div class="progress-section">
                <div class="progress-header">
                    <span class="progress-label"><?= htmlspecialchars(lang('overall_progress')) ?></span>
                    <span id="overall-percentage" class="progress-percentage">0%</span>
                </div>
                <div class="progress-bar-bg">
                    <div id="overall-progress-bar" class="progress-bar-fill" style="width: 0%"></div>
                </div>
                <p id="current-task" class="progress-status"><?= htmlspecialchars(lang('preparing')) ?></p>
            </div>

            <!-- 작업 목록 -->
            <div id="task-list" class="task-list">
                <!-- JavaScript로 동적 생성 -->
            </div>

            <!-- 로그 섹션 헤더 -->
            <h4 class="log-section-title"><?= htmlspecialchars(lang('installation_log')) ?></h4>

            <!-- 로그 출력 -->
            <div class="install-log" id="install-log">
                <div class="log-placeholder"><?= htmlspecialchars(lang('waiting_installation')) ?></div>
            </div>
        </div>
    </div>

</div>

<?php
// 선택된 확장 기능 가져오기
$selectedExtensions = $state['selected_extensions'] ?? [];
$adminTemplates = $selectedExtensions['admin_templates'] ?? [];
$userTemplates = $selectedExtensions['user_templates'] ?? [];
$modules = $selectedExtensions['modules'] ?? [];
$plugins = $selectedExtensions['plugins'] ?? [];
?>

<script>
// Step 5 전용 데이터 전달 (INSTALLER_BASE_URL과 INSTALLER_LANG는 footer.php에서 처리)
window.INSTALLER_CONFIG = <?= json_encode($config) ?>;
window.INSTALLER_SELECTED_EXTENSIONS = <?= json_encode($selectedExtensions) ?>;
window.INSTALLER_EXTENSION_NAMES = <?= json_encode($state['extension_names'] ?? []) ?>;

// 작업 그룹 정의
window.INSTALLER_TASK_GROUPS = [
    {
        id: 'environment',
        labelKey: 'task_group_environment',
        tasks: [
            { id: 'composer_check' },
            { id: 'composer_install' },
            { id: 'env_update' },
            { id: 'key_generate' }
        ]
    },
    {
        id: 'database',
        labelKey: 'task_group_database',
        tasks: [
            { id: 'db_migrate' },
            { id: 'db_seed' }
        ]
    },
    {
        id: 'admin_templates',
        labelKey: 'task_group_admin_templates',
        tasks: <?= json_encode(array_map(function($tpl) {
            return [
                ['id' => 'template_install', 'target' => $tpl],
                ['id' => 'template_activate', 'target' => $tpl]
            ];
        }, $adminTemplates)) ?>
    },
    {
        id: 'modules',
        labelKey: 'task_group_modules',
        tasks: <?= json_encode(array_map(function($mod) {
            return [
                ['id' => 'module_install', 'target' => $mod],
                ['id' => 'module_activate', 'target' => $mod]
            ];
        }, $modules)) ?>
    },
    {
        id: 'plugins',
        labelKey: 'task_group_plugins',
        tasks: <?= json_encode(array_map(function($plg) {
            return [
                ['id' => 'plugin_install', 'target' => $plg],
                ['id' => 'plugin_activate', 'target' => $plg]
            ];
        }, $plugins)) ?>
    },
    {
        id: 'user_templates',
        labelKey: 'task_group_user_templates',
        tasks: <?= json_encode(array_map(function($tpl) {
            return [
                ['id' => 'user_template_install', 'target' => $tpl],
                ['id' => 'user_template_activate', 'target' => $tpl]
            ];
        }, $userTemplates)) ?>
    },
    {
        id: 'finalize',
        labelKey: 'task_group_finalize',
        tasks: [
            { id: 'create_settings_json' },
            { id: 'cache_clear' },
            { id: 'complete_flag' }
        ]
    }
];

// 평탄화된 작업 목록 생성 (하위 호환성 유지)
window.INSTALLER_TASKS = (function() {
    const tasks = [];
    INSTALLER_TASK_GROUPS.forEach(group => {
        if (!group.tasks || group.tasks.length === 0) return;
        group.tasks.forEach(taskOrArray => {
            if (Array.isArray(taskOrArray)) {
                taskOrArray.forEach(t => tasks.push({ ...t, group: group.id }));
            } else {
                tasks.push({ ...taskOrArray, group: group.id });
            }
        });
    });
    return tasks;
})();
</script>
