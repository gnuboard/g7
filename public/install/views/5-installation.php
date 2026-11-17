<?php

use App\Support\ExecutionEnvironment;

/**
 * Step 5: 설치 진행
 *
 * Composer 의존성 설치, .env 파일 생성, Artisan 명령 실행, 관리자 계정 생성,
 * 선택한 확장 기능(템플릿, 모듈, 플러그인) 설치를 수행합니다.
 * 진행률 표시와 실시간 로그를 통해 사용자에게 피드백을 제공합니다.
 */

// 설정 정보 가져오기 (state.json에서 로드 - 다른 브라우저에서도 접근 가능)
$config = $state['config'] ?? $_SESSION['install_config'] ?? [];

// 유효 실행 모드 — 설치 시작 전에는 Step 3 선택값(auto 면 감지)으로, 시작 후에는 state 에
// 박힌 확정값으로 판정한다. inprocess 면 진행 방식은 단계별 실행으로 고정된다 (A-2).
require_once __DIR__.'/../includes/execution-mode.php';
require_once __DIR__.'/../includes/installer-runtime.php';
// 진행 목록은 서버 태스크 목록에서 파생한다 (아래 $installerTaskGroups). 이 파일은 함수
// 정의만 담고 있어 include 부작용이 없다.
require_once __DIR__.'/../includes/task-runner.php';
$effectiveExecutionMode = installerEffectiveExecutionMode($state);
$forceStepwise = $effectiveExecutionMode === ExecutionEnvironment::MODE_INPROCESS;
$savedInstallationMode = $state['installation_mode'] ?? null;
$initialInstallationMode = $forceStepwise ? 'stepwise' : (in_array($savedInstallationMode, ['sse', 'polling', 'stepwise'], true) ? $savedInstallationMode : 'sse');

// 단계별 실행 + composer 조합은 고를 수 없다 — 한 요청 수명(180초)보다 `composer install` 이
// 오래 걸리는 것이 정상이라 의존성이 반쯤 설치된 채 요청이 죽는다 (10차 #M3). 판정은 서버가
// 소유하고(execution-mode.php) install-process.php 가 같은 함수로 다시 적용한다.
$effectiveVendorMode = installerCoerceVendorModeForExecution(
    (string) ($config['vendor_mode'] ?? 'auto'),
    $effectiveExecutionMode,
);
$stepwiseBlockedByVendor = installerStepwiseBlockedByVendorMode($effectiveVendorMode, $effectiveExecutionMode);
if ($stepwiseBlockedByVendor && $initialInstallationMode === 'stepwise') {
    $initialInstallationMode = 'sse';
}

// 단계별 실행 재개용 토큰 — 진행 중인 stepwise 설치를 **시작한 세션에만** 싣는다 (13차 보안#3).
//
// 이 페이지 자체는 세션 뒤에 있지 않다: 진행 중인 stepwise 설치가 있으면 세션 없이
// `/install/` 을 열어도 Step 5 가 렌더된다(index.php 가 상태 파일의 current_step 을 채택).
// 종전에는 그 화면이 토큰을 무조건 실었으므로, 무세션 GET 한 번이면 9~11차가 run-task ·
// abort · reset · skip_task · finalize 에 건 토큰 게이트 전부가 우회됐다.
//
// 막는 것은 페이지가 아니라 토큰이다. Step 5 렌더 자체를 막으면 showInstallationRunningAlert()
// (step=5 강제)와 무한 루프가 되고, 세션이 GC 로 만료된 정상 운영자가 영구 잠금된다. 토큰만
// 빼면 그 창에서 잃는 것은 「이 브라우저에서 이어서 진행하기」 하나뿐이다.
//
// 대조 판정은 installerSessionOwnsStepwiseInstall() 이 소유한다 — 여기에 인라인으로 두면
// 조건이 뒤집혀도 어떤 게이트도 red 가 되지 않는다.
$stepwiseToken = null;
if ($savedInstallationMode === 'stepwise'
    && in_array($state['installation_status'] ?? '', ['running', 'failed', 'aborted', 'completed'], true)) {
    $installerRuntime = readInstallerRuntime();
    if (installerSessionOwnsStepwiseInstall($_SESSION ?? [], $installerRuntime)) {
        $stepwiseToken = $installerRuntime['installer']['stepwise_token'] ?? null;
    }
}

// ============================================================================
// 진행 목록 (작업 그룹)
// ============================================================================
//
// 화면 목록은 **서버 태스크 목록에서 파생한다**. 종전에는 같은 목록을 이 파일에 손으로 다시
// 적어 두었고, 그 사본에서 세 단계(dependency_precheck · db_cleanup · config_cache)가 빠져
// 있었다. 서버는 그 셋을 조건 없이 실행하므로 결과는 이랬다 (12차 #B22~#B24):
//
//  - 진행률 분모가 화면 항목 수라 「10 / 10 · 100%」 뒤에도 서버가 계속 돌았다
//  - 그 세 단계는 DOM 항목이 없어 실패해도 빨강이 되지 않았다 (setTaskStatus 가 조용히 반환)
//
// 파생하면 서버에 단계가 늘어도 화면이 저절로 따라온다. 그룹 소속만 화면이 정한다 —
// 목록에 없는 새 태스크는 마무리 그룹으로 떨어져 **적어도 보이기는 한다**.
//
// 순서도 서버 목록 그대로다 (PO 2026-11-02). 확장 작업은 종류를 넘는 의존 순서로 실행되므로
// (installerExtensionInstallOrder — 플러그인이 모듈보다, 모듈이 관리자 템플릿보다 먼저 올 수 있다)
// 종류별 묶음에 나눠 담으면 진행 표시가 아래 묶음부터 채워진다. 그래서 관리자 템플릿·모듈·
// 플러그인·사용자 템플릿 작업은 한 묶음(extensions)에 실행 순서로 담고, 종류는 항목마다 kind 로
// 싣는다(「…에 필요」 배지가 `{kind}:{target}` 으로 찾는다). 그룹은 서버 목록을 앞에서부터 걸으며
// 소속이 바뀔 때마다 새로 연다 — 같은 소속이 떨어져 다시 나타나면 `{id}-2` 로 따로 연다. 그래서
// 화면 그룹을 펼친 순서는 어떤 경우에도 서버 실행 순서와 같다.
$taskGroupLabelKeys = [
    'environment' => 'task_group_environment',
    'database' => 'task_group_database',
    'extensions' => 'task_group_extensions',
    'language_packs' => 'task_group_language_packs',
    'finalize' => 'task_group_finalize',
];

/** 태스크 ID → 표시 그룹. 여기에 없는 ID 는 $taskGroupFallback 으로 떨어진다. */
$taskGroupByTaskId = [
    'composer_check' => 'environment',
    'composer_install' => 'environment',
    'env_update' => 'environment',
    'key_generate' => 'environment',
    'dependency_precheck' => 'environment',
    'db_cleanup' => 'database',
    'db_migrate' => 'database',
    'db_seed' => 'database',
    'template_install' => 'extensions',
    'template_activate' => 'extensions',
    'module_install' => 'extensions',
    'module_activate' => 'extensions',
    'plugin_install' => 'extensions',
    'plugin_activate' => 'extensions',
    'user_template_install' => 'extensions',
    'user_template_activate' => 'extensions',
    'language_pack_install' => 'language_packs',
    'create_settings_json' => 'finalize',
    'cache_clear' => 'finalize',
    'complete_flag' => 'finalize',
    'config_cache' => 'finalize',
    'route_cache' => 'finalize',
    'static_publish' => 'finalize',
];
$taskGroupFallback = 'finalize';

/** 확장 태스크 ID → 확장 종류 (설치 순서·pulled_by 의 `카테고리` 와 같은 값). */
$taskKindByTaskId = [
    'template_install' => 'admin_templates',
    'template_activate' => 'admin_templates',
    'module_install' => 'modules',
    'module_activate' => 'modules',
    'plugin_install' => 'plugins',
    'plugin_activate' => 'plugins',
    'user_template_install' => 'user_templates',
    'user_template_activate' => 'user_templates',
    'language_pack_install' => 'language_packs',
];

$installerTaskGroups = [];
$taskGroupOpenCount = [];
$currentTaskGroup = null;
foreach (buildInstallationTaskList() as $installerTask) {
    $groupId = $taskGroupByTaskId[$installerTask['id']] ?? $taskGroupFallback;
    $entry = ['id' => $installerTask['id']];
    if (($installerTask['target'] ?? null) !== null) {
        $entry['target'] = $installerTask['target'];
    }
    if (isset($taskKindByTaskId[$installerTask['id']])) {
        $entry['kind'] = $taskKindByTaskId[$installerTask['id']];
    }

    if ($currentTaskGroup !== $groupId) {
        $currentTaskGroup = $groupId;
        $taskGroupOpenCount[$groupId] = ($taskGroupOpenCount[$groupId] ?? 0) + 1;
        $installerTaskGroups[] = [
            // 그룹 id 는 DOM id(task-group-items-{id})·접기 대상이라 겹치면 안 된다
            'id' => $taskGroupOpenCount[$groupId] === 1 ? $groupId : $groupId.'-'.$taskGroupOpenCount[$groupId],
            'labelKey' => $taskGroupLabelKeys[$groupId],
            'tasks' => [],
        ];
    }
    $installerTaskGroups[count($installerTaskGroups) - 1]['tasks'][] = $entry;
}
?>

<!-- 필수 파일 생성 안내 섹션 (JS에서 제어, 기본 숨김) -->
<div id="env-setup-section" class="installer-container installer-container-wide hidden">
    <div class="requirement-card">
        <div class="requirement-card-header">
            <h3 class="requirement-card-title"><?= htmlspecialchars(lang('file_setup_title')) ?></h3>
        </div>
        <div class="requirement-card-body">
            <?php /* 사유는 두 가지다 — 파일이 없거나(생성 안내), 있는데 쓸 수 없거나(권한 안내).
                     19차 S-2 전에는 후자를 사전 점검이 통과시켜, 14단계가 전부 초록으로 끝난 뒤에도
                     `.env` 가 설치 전 그대로 남고 인스톨러가 영구히 열려 있었다. JS 가 이 문단을
                     사유에 맞게 바꾼다(기본값은 생성 안내). */ ?>
            <p id="env-setup-reason"><?= htmlspecialchars(lang('file_setup_description')) ?></p>

            <!-- 누락 파일 목록 (JS에서 동적 표시) -->
            <ul id="missing-files-list" class="missing-files"></ul>

            <!-- 명령어 안내 (절대경로) -->
            <p><?= htmlspecialchars(lang('file_setup_guide')) ?></p>
            <div class="code-box">
                <pre id="file-setup-command"></pre>
                <button class="btn-copy" data-action="copySetupCommand"><?= htmlspecialchars(lang('copy_command')) ?></button>
            </div>

            <!-- 상대경로 명령어 -->
            <p class="fix-guide-hint hidden" id="file-setup-relative-hint"><?= htmlspecialchars(lang('or_relative_path')) ?></p>
            <div class="code-box hidden" id="file-setup-relative-box">
                <pre id="file-setup-command-relative"></pre>
                <button class="btn-copy" data-action="copySetupCommandRelative"><?= htmlspecialchars(lang('copy_command')) ?></button>
            </div>

            <!-- 확인 버튼 -->
            <div class="env-check-actions">
                <button id="env-recheck-btn" class="btn btn-primary" data-action="recheckFiles"><?= htmlspecialchars(lang('file_check_button')) ?></button>
                <span id="env-check-status" class="hidden"></span>
            </div>
        </div>
    </div>
</div>

<div class="installer-container installer-container-wide">
    <div class="installer-header-with-abort">
        <h1 id="installer-title" class="installer-title"><?= htmlspecialchars(lang('installation_title')) ?></h1>
        <button id="abort-installation-btn" class="btn-abort hidden" data-action="abortInstallation">
            <?php /* 뜻은 옆의 텍스트 라벨이 전한다 — 장식 아이콘은 보조기술에서 감춘다.
                     JS 가 이 버튼을 다시 그릴 때 쓰는 abortButtonMarkup() 과 같은 형태여야
                     첫 렌더와 이후 렌더의 접근성 표면이 어긋나지 않는다. */ ?>
            <svg class="abort-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
                <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <?= htmlspecialchars(lang('abort_installation')) ?>
        </button>
    </div>

    <?php require __DIR__.'/partials/recovery-token-notice.php'; ?>

    <!-- 완료 섹션 -->
    <?php
    $completionButtons = '<a href="../admin/login" class="btn btn-success">'.htmlspecialchars(lang('go_to_admin_login')).'</a>';

$coreVersion = getCoreAppVersion();
$completionExtra = '<p class="result-version">'
    .htmlspecialchars(lang('installed_version_label')).' '
    .'<strong>'.htmlspecialchars($coreVersion).'</strong>'
    .'</p>';

// .env 권한 권장 강화 안내 — 0600 이 아니면 조건부 노출 (#371)
// finalize-env.php 는 chmod 시도를 수행하지 않으므로 인스톨러 안내 단계의 권한
// (예: 0664 + chgrp www-data) 이 그대로 유지됨. 운영자가 추가로 0600 적용을
// 원할 수 있도록 현재 권한이 0600 미만 (그룹/기타 비트가 있음) 이면 명령 예시 노출.
//
// OS 가드: chmod/chgrp 는 POSIX 권한 모델 (Linux/macOS/BSD) 에서 동작.
// Windows 만 명령이 무의미하므로 Windows 만 비노출.
$envPath = BASE_PATH.'/.env';
if (PHP_OS_FAMILY !== 'Windows' && is_file($envPath)) {
    $envPerms = fileperms($envPath) & 0777;
    if ($envPerms !== 0600) {
        $envOwnerUid = @fileowner($envPath);
        $envOwnerName = ($envOwnerUid !== false && function_exists('posix_getpwuid'))
            ? (posix_getpwuid($envOwnerUid)['name'] ?? (string) $envOwnerUid)
            : 'owner';
        $webGroupName = function_exists('posix_getegid') && function_exists('posix_getgrgid')
            ? (posix_getgrgid(posix_getegid())['name'] ?? 'www-data')
            : 'www-data';

        $hardeningCommandSameOwner = sprintf('chmod 600 %s', htmlspecialchars($envPath));
        $hardeningCommandDifferentOwner = sprintf(
            'sudo chgrp %s %s && sudo chmod 640 %s',
            htmlspecialchars($webGroupName),
            htmlspecialchars($envPath),
            htmlspecialchars($envPath)
        );

        $completionExtra .= '<div class="env-hardening-hint">'
            .'<p class="env-hardening-hint-title">'.htmlspecialchars(lang('env_hardening_hint_title')).'</p>'
            .'<p class="env-hardening-hint-status">'
            // 인스톨러 전체가 `:name` 규약이다 — 여기만 sprintf(%s) 였던 탓에 자리표시자
            // 게이트(인스톨러 PHP 전수 스캔)가 이 지점을 영원히 통과시켰다 (9차 #L34).
            .htmlspecialchars(lang('env_hardening_hint_current_status', [
                'permission' => decoct($envPerms),
                'owner' => $envOwnerName,
            ]))
            .'</p>'
            .'<p class="env-hardening-hint-option">'
            .htmlspecialchars(lang('env_hardening_hint_option_strict'))
            .'</p>'
            .'<div class="code-box"><pre>'.$hardeningCommandSameOwner.'</pre></div>'
            .'<p class="env-hardening-hint-option">'
            .htmlspecialchars(lang('env_hardening_hint_option_group'))
            .'</p>'
            .'<div class="code-box"><pre>'.$hardeningCommandDifferentOwner.'</pre></div>'
            .'<p class="env-hardening-hint-note">'.htmlspecialchars(lang('env_hardening_hint_note')).'</p>'
            .'</div>';
    }
}

// 예약 작업(cron) 등록 안내 — 스케줄러가 없으면 주문 자동취소·오래된 데이터 정리·메일 보고서 같은 예약 작업이
// 돌지 않는다. 방문자 통계만은 관리자가 화면을 열 때 집계되지만 그 밖은 조용히 멈추므로 설치 직후에 알린다.
$cronCommand = sprintf('* * * * * cd %s && php artisan schedule:run >> /dev/null 2>&1', htmlspecialchars(BASE_PATH));
$completionExtra .= '<div class="env-hardening-hint cron-hint">'
    .'<p class="env-hardening-hint-title">'.htmlspecialchars(lang('cron_hint_title')).'</p>'
    .'<p class="env-hardening-hint-status">'.htmlspecialchars(lang('cron_hint_body')).'</p>'
    .'<div class="code-box"><pre>'.$cronCommand.'</pre></div>'
    // 명령줄(crontab)을 쓸 수 없는 호스팅은 위 명령을 따라 할 수 없다 — 같은 예약 작업을 주소 호출(웹 크론)로
    // 돌리는 관리자 화면을 함께 알린다. 호출 주소에는 비밀값이 들어가므로 여기서 주소를 그리지 않고 화면만 가리킨다.
    .'<p class="env-hardening-hint-status">'.htmlspecialchars(lang('cron_hint_web_cron')).'</p>'
    .'</div>';

echo renderInstallResultSection('completion', 'success', 'installation_completed', 'installation_complete_message', $completionButtons, $completionExtra);
?>

    <!-- 중단 섹션 -->
    <?php
$abortedButtons = '<button id="resume-installation-btn" data-action="resumeInstallationFromAborted" class="btn btn-primary">'.htmlspecialchars(lang('resume_continue')).'</button>
                <button data-action="goToSettingsWithConfirm" class="btn btn-secondary">'.htmlspecialchars(lang('back_to_settings')).'</button>';
echo renderInstallResultSection('aborted', 'warning', 'installation_aborted', 'installation_aborted_message', $abortedButtons);
?>

    <!-- 실패 섹션 -->
    <?php
// 「Step 4 로 돌아가기」는 의존성 사전 검증 실패(순환·누락)일 때만 installer.js showError() 가 드러낸다 —
// 재시도로 풀리지 않는 실패라 주 버튼으로 앞세우고 재시도는 보조로 바꾼다(#153). 이동은 기존 초기화 경로(항상 Step 4)이고,
// DB 를 건드리기 전 실패라 확인 창에 DB 수동 정리 안내를 넣지 않는다(goBackToExtensionSelection).
// [건너뛰고 계속] 은 실패한 단계가 선택 확장일 때만 JS 가 노출한다 (W3 — 필수 단계에서는 숨김)
$failureButtons = '<button id="back-to-extensions-btn" data-action="goBackToExtensionSelection" class="btn btn-primary hidden">'.htmlspecialchars(lang('back_to_extension_selection')).'</button>
                <button id="retry-installation-btn" data-action="retryInstallation" class="btn btn-primary">'.htmlspecialchars(lang('retry_installation')).'</button>
                <button id="skip-task-btn" data-action="skipFailedTaskAndContinue" class="btn btn-warning hidden">'.htmlspecialchars(lang('skip_and_continue')).'</button>
                <button data-action="goToSettingsWithConfirm" class="btn btn-secondary">'.htmlspecialchars(lang('failure_go_back')).'</button>';
echo renderInstallResultSection('failure', 'error', 'installation_failed', '', $failureButtons);
?>

    <!-- 설치 진행 방식 선택 (SSE / 폴링 / 단계별) -->
    <?php /* data-force-stepwise 는 JS 가 읽지 않는다 — 잠금의 시각·의미 표현은 서버가 이 아래에서
             직접 렌더한다(disabled·aria-disabled·data-locked·불가 배지). 이 속성은 브라우저 실측(§9)이
             DOM 에서 「이 환경이 stepwise 로 강제됐는가」를 한 지점에서 읽기 위한 표식이며, 화면 동작에
             관여하지 않는다. 배선된 값으로 오해하고 여기에 의존하는 코드를 쓰지 않는다. */ ?>
    <div class="requirement-card installation-mode-card" id="installation-mode-card" data-force-stepwise="<?= $forceStepwise ? '1' : '0' ?>">
        <div class="requirement-card-header">
            <h3 class="requirement-card-title" id="installation-mode-group-label"><?= htmlspecialchars(lang('installation_mode_label')) ?></h3>
        </div>
        <div class="requirement-card-body">
            <?php if ($forceStepwise) { ?>
                <p class="alert alert-info installation-mode-forced-notice"><?= htmlspecialchars(lang('installation_mode_forced_notice')) ?></p>
            <?php } ?>
            <div class="installation-mode-options" role="radiogroup" aria-labelledby="installation-mode-group-label">
                <label class="installation-mode-option <?= $forceStepwise ? 'disabled' : '' ?>">
                    <input type="radio" name="installation_mode" value="sse" <?= $initialInstallationMode === 'sse' ? 'checked' : '' ?> <?= $forceStepwise ? 'disabled aria-disabled="true" data-locked="1"' : '' ?>>
                    <div class="installation-mode-text">
                        <strong><?= htmlspecialchars(lang('installation_mode_sse_title')) ?>
                            <?php if ($forceStepwise) { ?><span class="installation-mode-badge installation-mode-badge-unavailable" role="status"><?= htmlspecialchars(lang('installation_mode_unavailable_inprocess')) ?></span><?php } ?>
                        </strong>
                        <span class="installation-mode-desc"><?= htmlspecialchars(lang('installation_mode_sse_desc')) ?></span>
                    </div>
                </label>
                <label class="installation-mode-option <?= $forceStepwise ? 'disabled' : '' ?>">
                    <input type="radio" name="installation_mode" value="polling" <?= $initialInstallationMode === 'polling' ? 'checked' : '' ?> <?= $forceStepwise ? 'disabled aria-disabled="true" data-locked="1"' : '' ?>>
                    <div class="installation-mode-text">
                        <strong><?= htmlspecialchars(lang('installation_mode_polling_title')) ?>
                            <?php if ($forceStepwise) { ?><span class="installation-mode-badge installation-mode-badge-unavailable" role="status"><?= htmlspecialchars(lang('installation_mode_unavailable_inprocess')) ?></span><?php } ?>
                        </strong>
                        <span class="installation-mode-desc"><?= htmlspecialchars(lang('installation_mode_polling_desc')) ?></span>
                    </div>
                </label>
                <label class="installation-mode-option <?= $stepwiseBlockedByVendor ? 'disabled' : '' ?>">
                    <input type="radio" name="installation_mode" value="stepwise" <?= $initialInstallationMode === 'stepwise' ? 'checked' : '' ?> <?= $stepwiseBlockedByVendor ? 'disabled aria-disabled="true" data-locked="1"' : '' ?>>
                    <div class="installation-mode-text">
                        <strong><?= htmlspecialchars(lang('installation_mode_stepwise_title')) ?>
                            <?php if ($stepwiseBlockedByVendor) { ?><span class="installation-mode-badge installation-mode-badge-unavailable" role="status"><?= htmlspecialchars(lang('installation_mode_unavailable_composer')) ?></span><?php } ?>
                        </strong>
                        <span class="installation-mode-desc"><?= htmlspecialchars(lang('installation_mode_stepwise_desc')) ?></span>
                        <?php if ($stepwiseBlockedByVendor) { ?>
                            <span class="installation-mode-desc"><?= htmlspecialchars(lang('installation_mode_stepwise_composer_reason')) ?></span>
                        <?php } ?>
                    </div>
                </label>
            </div>
        </div>
    </div>

    <!-- 설치 시작 버튼 (초기 상태, 설치 시작 후 숨김) -->
    <div id="installation-start-section" class="installation-start-section hidden">
        <button id="start-installation-btn" class="btn btn-primary btn-lg" type="button" data-action="onStartInstallationClick">
            <?= htmlspecialchars(lang('start_installation_button')) ?>
        </button>
    </div>

    <div class="alert alert-warning hidden" id="install-warning">
        ⚠ <?= htmlspecialchars(lang('do_not_close_page')) ?>
    </div>

    <!-- 설치 진행 상황 카드 -->
    <div class="requirement-card hidden" id="installation-progress-card">
        <?php /* 접기/펼치기 트리거 — div + onclick 은 Tab 으로 도달하지 못하고 접힘 상태도
                 보조기술에 전달되지 않는다. 같은 파일군의 .error-detail-toggle 이 이미
                 button + aria-expanded 로 되어 있어 그 형태에 맞춘다. */ ?>
        <?php /* 설치가 끝나기 전에는 핸들러가 첫 줄에서 되돌아간다 — 그동안 이 헤더는 Tab
                 순서에 남아 있으면서 눌러도 아무 일이 없는 「죽은 버튼」이었다. 비활성이라는
                 사실을 처음부터 싣고, 완료·실패·중단 시 JS 가 해제한다 (12차 #B28).
                 클릭은 data-action 위임(installer-boot.js)으로, Enter·Space 는 installer.js 의
                 role="button" 키보드 위임으로 같은 클릭을 낸다 — 인라인 on* 은 strict CSP 에서 실행되지 않는다. */ ?>
        <div class="requirement-card-header" id="installation-card-header" role="button" tabindex="-1"
             aria-expanded="true" aria-disabled="true" aria-controls="installation-card-body"
             data-action="toggleInstallationCard">
            <h3 class="requirement-card-title">
                <?= htmlspecialchars(lang('progress_status')) ?>
                <span id="install-toggle-icon" class="toggle-icon-inline hidden" aria-hidden="true">▼</span>
            </h3>
        </div>

        <div id="installation-card-body" class="requirement-card-body">
            <!-- 전체 진행률 -->
            <div class="progress-section">
                <div class="progress-header">
                    <span class="progress-label"><?= htmlspecialchars(lang('overall_progress')) ?></span>
                    <span class="progress-percentage-wrap">
                        <span id="overall-steps" class="progress-steps hidden"></span>
                        <span id="overall-percentage" class="progress-percentage">0%</span>
                    </span>
                </div>
                <div class="progress-bar-bg" id="overall-progress-track" role="progressbar"
                     aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-labelledby="current-task">
                    <div id="overall-progress-bar" class="progress-bar-fill"></div>
                </div>
                <?php /* 갱신되는 문단이라 라이브 리전이다 — 선언이 없으면 14단계가 도는 동안
                         화면 판독기에 진행이 한 번도 읽히지 않는다. */ ?>
                <p id="current-task" class="progress-status" role="status" aria-live="polite"><?= htmlspecialchars(lang('preparing')) ?></p>
                <?php /* 단계별 실행의 **경과 표시** (D2 L9). 한 요청이 태스크 하나만큼 살고
                         실측에서 44 초가 걸린 태스크가 있었는데, 그 구간 내내 화면은 한 글자도
                         바뀌지 않았다 — 사용자에게 그것은 「멈춘 화면」이고 이번 제보의 증상이다.
                         1초에 한 번 이 한 곳의 텍스트만 바꾼다.
                         `aria-live` 는 두지 않는다 — 초마다 읽히면 형제 라이브 리전(`current-task`)의
                         실제 진행 안내가 묻힌다. 값은 눈으로 보는 보조 표시다. */ ?>
                <p id="task-elapsed" class="progress-elapsed hidden"></p>
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

            <?php /* 명령 상세 — 진행 기록은 사람 말만 담는다(§12.0 W3). 커맨드가 뱉은 원문은
                     표식이 붙어 와서 화면이 이 접기 영역으로 보낸다. 수동 복구 명령이 이미
                     같은 이유로 실패 화면의 접기로 옮겨져 있고, 이것이 그 형제 통로다.
                     첫 줄이 도착하기 전에는 숨어 있다 — 빈 접기 버튼을 남기지 않는다. */ ?>
            <div class="command-output-section hidden" id="command-output-section">
                <button
                    class="command-output-toggle"
                    type="button"
                    aria-expanded="false"
                    aria-controls="command-output-content"
                    data-action="toggleErrorDetail" data-arg="command-output-content"
                >
                    <span class="command-output-toggle-icon" aria-hidden="true">▶</span>
                    <strong><?= htmlspecialchars(lang('view_command_output')) ?></strong>
                </button>
                <div id="command-output-content" class="command-output-content">
                    <pre class="command-output-log" id="command-output-log"></pre>
                </div>
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
$languagePacks = $selectedExtensions['language_packs'] ?? [];
?>

<script type="application/json" id="installer-step-data"><?= installer_json_encode([
    // Step 5 전용 데이터 전달 (INSTALLER_BASE_URL 과 INSTALLER_LANG 은 <head> 의 installer-boot.js 가 채운다)
    'config' => $config,
    'selected_extensions' => $selectedExtensions,
    'extension_names' => $state['extension_names'] ?? [],
    // 의존 때문에 종류 경계를 넘어 앞당겨 설치하는 항목 → 요구한 확장 (작업 행의 「…에 필요」 표시, #153)
    'pulled_by' => (object) ($state['install_pulled_by'] ?? []),
    // 유효 실행 모드 (subprocess|inprocess). 화면 분기는 서버가 이미 마친 상태로 렌더하므로
    // JS 소비처는 없다 — 무CLI 환경 실측에서 콘솔로 확인하기 위한 진단용 노출이다.
    'execution_mode' => $effectiveExecutionMode,
    // 진행 중인 단계별 실행을 새로고침 뒤 이어가기 위한 토큰 (없으면 null)
    'stepwise_token' => $stepwiseToken,
    // 서버가 서브프로세스 스폰 동안 스스로에게 주는 창(초 → ms). 그 구간에는 emit 이 0 이므로
    // 화면의 정지 감지는 이 값보다 커야 한다 — 숫자를 여기에 다시 적지 않고 서버 상수에서
    // 파생한다. 세 모니터가 같은 근거를 쓰지 않으면 SSE·폴링만 정상 설치를 정지로 뒤집는다
    // (13차 E1). 상수는 이 파일이 이미 require 한 task-runner.php 가 artisan-executor.php 를
    // 통해 정의한다.
    'server_blocking_window_ms' => INSTALLER_SUBPROCESS_TIME_LIMIT * 1000,
    // 작업 그룹 정의 — 서버 태스크 목록(buildInstallationTaskList)에서 파생한다.
    // 이 파일 상단의 $installerTaskGroups 주석 참조 (12차 #B22).
    'task_groups' => $installerTaskGroups,
    // 확장 종류별 작업 — task_groups 가 없을 때(옛 캐시 화면) step-5-installation.js 의 기본 그룹 정의가 이 목록으로 채운다
    'admin_templates' => array_values($adminTemplates),
    'modules' => array_values($modules),
    'plugins' => array_values($plugins),
    'user_templates' => array_values($userTemplates),
    'language_packs' => array_values($languagePacks),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= e(INSTALLER_BASE_URL) ?>/assets/js/step-5-installation.js?v=<?= time() ?>"></script>
