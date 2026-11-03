<?php

use App\Support\ExecutionEnvironment;

/**
 * Step 3: 데이터베이스 및 사이트 설정
 *
 * 데이터베이스 설정, 사이트 설정, 관리자 계정을 입력받습니다.
 * $formData는 index.php에서 준비됨
 */
if (! isset($errors)) {
    $errors = [];
}

// DB 테스트 플래그
$dbWriteTested = isset($_SESSION['db_write_tested']) && $_SESSION['db_write_tested'] === true;
$dbReadTested = isset($_SESSION['db_read_tested']) && $_SESSION['db_read_tested'] === true;

// DB 필드 해시값 계산 (변경 감지용)
$dbWriteHash = getDatabaseFieldHash($formData, 'db_write');
$dbReadHash = getDatabaseFieldHash($formData, 'db_read');
?>

<div class="installer-container installer-container-wide">
    <h1 class="installer-title"><?= htmlspecialchars(lang('step_3_configuration')) ?></h1>

    <?php if (! empty($errors)) { ?>
        <div class="alert alert-error">
            <ul class="alert-list">
                <?php foreach ($errors as $error) { ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php } ?>
            </ul>
        </div>
    <?php } ?>

    <form method="POST" id="config-form" class="installer-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(installer_csrf_token()) ?>">
        <!-- 사이트 기본 언어 — 설치 화면 언어로 고정(.env APP_LOCALE·설정 general.language 공통). 터미널 설치는 --site-language -->
        <input type="hidden" name="site_language" value="<?= htmlspecialchars(getCurrentLanguage()) ?>">
        <!-- 데이터베이스 설정 (Write DB) -->
        <div class="requirement-card">
            <div class="requirement-card-header">
                <h3 class="requirement-card-title"><?= htmlspecialchars(lang('database_settings')) ?> (Write Server)</h3>
            </div>
            <div class="requirement-card-body">
                <div class="form-grid">
                    <div class="form-group form-group-full">
                        <label class="form-label" for="db_type"><?= htmlspecialchars(lang('database_type')) ?></label>
                        <select name="db_type" id="db_type" class="form-select" disabled>
                            <option value="mysql" selected>MySQL</option>
                        </select>
                        <input type="hidden" name="db_type" value="mysql">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="db_write_host"><?= htmlspecialchars(lang('db_host')) ?> *</label>
                        <input type="text" name="db_write_host" id="db_write_host" value="<?= htmlspecialchars($formData['db_write_host']) ?>"
                               class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="db_write_port"><?= htmlspecialchars(lang('db_port')) ?></label>
                        <input type="text" name="db_write_port" id="db_write_port" value="<?= htmlspecialchars($formData['db_write_port']) ?>"
                               class="form-input">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="db_write_database"><?= htmlspecialchars(lang('db_name')) ?> *</label>
                        <input type="text" name="db_write_database" id="db_write_database" value="<?= htmlspecialchars($formData['db_write_database']) ?>"
                               class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="db_prefix"><?= htmlspecialchars(lang('db_prefix')) ?></label>
                        <input type="text" name="db_prefix" id="db_prefix"
                               value="<?= htmlspecialchars($formData['db_prefix']) ?>"
                               class="form-input"
                               placeholder="<?= htmlspecialchars(lang('db_prefix_placeholder')) ?>"
                               pattern="^[a-z][a-z0-9_]*$"
                               maxlength="<?= MAX_DB_PREFIX_LENGTH ?>"
                               aria-invalid="false"
                               aria-describedby="db_prefix-error db_prefix-hint">
                        <small id="db_prefix-hint" class="form-hint">
                            <?= htmlspecialchars(lang('db_prefix_hint')) ?>
                        </small>
                        <div id="db_prefix-error" class="field-error" role="alert"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="db_write_username"><?= htmlspecialchars(lang('db_username')) ?> *</label>
                        <input type="text" name="db_write_username" id="db_write_username"
                               value="<?= htmlspecialchars($formData['db_write_username']) ?>"
                               class="form-input" required
                               aria-invalid="false"
                               aria-describedby="db_write_username-error">
                        <div id="db_write_username-error" class="field-error" role="alert"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="db_write_password"><?= htmlspecialchars(lang('db_password')) ?></label>
                        <input type="password" name="db_write_password" id="db_write_password" value="<?= htmlspecialchars($formData['db_write_password']) ?>"
                               class="form-input">
                    </div>
                </div>

                <div class="test-db-wrapper">
                    <button type="button" data-action="testDatabaseConnection" data-arg="write" class="btn btn-success">
                        <?= htmlspecialchars(lang('test_write_db_connection')) ?>
                    </button>
                </div>

                <div id="db-write-test-result" class="test-result hidden" role="status" aria-live="polite"></div>
            </div>
        </div>

        <!-- 데이터베이스 설정 (Read DB) -->
        <div class="requirement-card">
            <div class="requirement-card-header" style="cursor: pointer;">
                <label for="use-read-db" style="cursor: pointer; display: flex; align-items: center; gap: var(--spacing-sm); flex: 1;">
                    <h3 class="requirement-card-title" style="margin: 0;"><?= htmlspecialchars(lang('use_read_db')) ?></h3>
                </label>
                <input type="checkbox" name="use_read_db" id="use-read-db" value="1"
                       <?= ! empty($formData['use_read_db']) ? 'checked' : '' ?>
                       class="toggle-switch">
            </div>
            <div id="read-db-section" class="requirement-card-body <?= empty($formData['use_read_db']) ? 'hidden' : '' ?>" style="transition: all 0.3s ease-out;">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="db_read_host"><?= htmlspecialchars(lang('db_host')) ?> *</label>
                        <input type="text" name="db_read_host" id="db_read_host" value="<?= htmlspecialchars($formData['db_read_host']) ?>"
                               class="form-input">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="db_read_port"><?= htmlspecialchars(lang('db_port')) ?></label>
                        <input type="text" name="db_read_port" id="db_read_port" value="<?= htmlspecialchars($formData['db_read_port']) ?>"
                               class="form-input">
                    </div>

                    <div class="form-group form-group-full">
                        <label class="form-label" for="db_read_database"><?= htmlspecialchars(lang('db_name')) ?> *</label>
                        <input type="text" name="db_read_database" id="db_read_database" value="<?= htmlspecialchars($formData['db_read_database']) ?>"
                               class="form-input">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="db_read_username"><?= htmlspecialchars(lang('db_username')) ?> *</label>
                        <input type="text" name="db_read_username" id="db_read_username"
                               value="<?= htmlspecialchars($formData['db_read_username']) ?>"
                               class="form-input"
                               aria-invalid="false"
                               aria-describedby="db_read_username-error">
                        <div id="db_read_username-error" class="field-error" role="alert"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="db_read_password"><?= htmlspecialchars(lang('db_password')) ?></label>
                        <input type="password" name="db_read_password" id="db_read_password" value="<?= htmlspecialchars($formData['db_read_password']) ?>"
                               class="form-input">
                    </div>
                </div>

                <div class="test-db-wrapper">
                    <button type="button" data-action="testDatabaseConnection" data-arg="read" class="btn btn-success">
                        <?= htmlspecialchars(lang('test_read_db_connection')) ?>
                    </button>
                </div>

                <div id="db-read-test-result" class="test-result hidden" role="status" aria-live="polite"></div>
            </div>
        </div>

        <!-- 사이트 설정 -->
        <div class="requirement-card">
            <div class="requirement-card-header">
                <h3 class="requirement-card-title"><?= htmlspecialchars(lang('site_settings')) ?></h3>
            </div>
            <div class="requirement-card-body">
                <div class="form-fields">
                    <div class="form-group">
                        <label class="form-label" for="app_name"><?= htmlspecialchars(lang('app_name')) ?> *</label>
                        <input type="text" name="app_name" id="app_name" value="<?= htmlspecialchars($formData['app_name']) ?>"
                               class="form-input" required
                               maxlength="<?= MAX_APP_NAME_LENGTH ?>"
                               aria-invalid="false" aria-describedby="app_name-error">
                        <div id="app_name-error" class="field-error" role="alert"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="app_url"><?= htmlspecialchars(lang('app_url')) ?> *</label>
                        <input type="url" name="app_url" id="app_url" value="<?= htmlspecialchars($formData['app_url']) ?>"
                               data-autofill="<?= ! empty($formData['app_url_autofill']) ? '1' : '0' ?>"
                               class="form-input" required
                               aria-invalid="false" aria-describedby="app_url-error">
                        <div id="app_url-error" class="field-error" role="alert"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 관리자 설정 -->
        <div class="requirement-card">
            <div class="requirement-card-header">
                <h3 class="requirement-card-title"><?= htmlspecialchars(lang('admin_account')) ?></h3>
            </div>
            <div class="requirement-card-body">
                <div class="form-grid">
                    <div class="form-group form-group-full">
                        <label class="form-label" for="admin_email"><?= htmlspecialchars(lang('admin_email')) ?> *</label>
                        <input type="email" name="admin_email" id="admin_email" value="<?= htmlspecialchars($formData['admin_email']) ?>"
                               class="form-input" required
                               aria-invalid="false" aria-describedby="admin_email-error">
                        <div id="admin_email-error" class="field-error" role="alert"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="admin_name"><?= htmlspecialchars(lang('admin_name')) ?> *</label>
                        <input type="text" name="admin_name" id="admin_name" value="<?= htmlspecialchars($formData['admin_name']) ?>"
                               class="form-input" required
                               aria-invalid="false" aria-describedby="admin_name-error">
                        <div id="admin_name-error" class="field-error" role="alert"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="admin_language"><?= htmlspecialchars(lang('admin_language')) ?> *</label>
                        <select name="admin_language" id="admin_language" class="form-select" required>
                            <?php
                            $selectedAdminLang = $formData['admin_language'] ?? getCurrentLanguage();
foreach (SUPPORTED_LANGUAGES as $code => $label) { ?>
                            <option value="<?= htmlspecialchars($code) ?>" <?= $selectedAdminLang === $code ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="admin_password"><?= htmlspecialchars(lang('admin_password')) ?> *</label>
                        <input type="password" name="admin_password" id="admin_password"
                               class="form-input" required minlength="8"
                               aria-invalid="false" aria-describedby="admin_password-error">
                        <div id="admin_password-error" class="field-error" role="alert"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="admin_password_confirm"><?= htmlspecialchars(lang('admin_password_confirm')) ?> *</label>
                        <input type="password" name="admin_password_confirm" id="admin_password_confirm"
                               class="form-input" required
                               aria-invalid="false" aria-describedby="admin_password_confirm-error">
                        <div id="admin_password_confirm-error" class="field-error" role="alert"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 코어 업데이트 설정 (선택) -->
        <div class="requirement-card">
            <div class="requirement-card-header">
                <h3 class="requirement-card-title"><?= htmlspecialchars(lang('core_update_settings')) ?></h3>
            </div>
            <div class="requirement-card-body">
                <div class="form-fields">
                    <div class="form-group">
                        <label class="form-label" for="core_update_pending_path"><?= htmlspecialchars(lang('core_update_pending_path')) ?></label>
                        <input type="text" name="core_update_pending_path" id="core_update_pending_path"
                               value="<?= htmlspecialchars($formData['core_update_pending_path'] ?? '') ?>"
                               class="form-input"
                               placeholder="storage/app/core_pending"
                               aria-describedby="core_update_pending_path-help">
                        <p id="core_update_pending_path-help" class="form-help">
                            <?= htmlspecialchars(lang('core_update_pending_path_help')) ?>
                        </p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="asset_url_mode_select"><?= htmlspecialchars(lang('asset_url_mode')) ?></label>
                        <select name="asset_url_mode_select" id="asset_url_mode_select" class="form-select">
                            <option value="extension"><?= htmlspecialchars(lang('asset_url_mode_extension')) ?></option>
                            <option value="extensionless"><?= htmlspecialchars(lang('asset_url_mode_extensionless')) ?></option>
                        </select>
                        <p id="asset_url_mode_status" class="form-help hidden"
                           data-msg-extension="<?= htmlspecialchars(lang('asset_url_mode_detected_extension')) ?>"
                           data-msg-extensionless="<?= htmlspecialchars(lang('asset_url_mode_detected_extensionless')) ?>"
                           data-msg-unavailable="<?= htmlspecialchars(lang('asset_url_mode_detected_unavailable')) ?>"></p>
                        <p class="form-help">
                            <?= htmlspecialchars(lang('asset_url_mode_help')) ?>
                        </p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="core_update_github_url"><?= htmlspecialchars(lang('core_update_github_url')) ?></label>
                        <input type="url" name="core_update_github_url" id="core_update_github_url"
                               value="<?= htmlspecialchars($formData['core_update_github_url'] ?? 'https://github.com/gnuboard/g7') ?>"
                               class="form-input"
                               placeholder="https://github.com/gnuboard/g7">
                        <p class="form-help">
                            <?= htmlspecialchars(lang('core_update_github_url_help')) ?>
                        </p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="core_update_github_token"><?= htmlspecialchars(lang('core_update_github_token')) ?></label>
                        <input type="password" name="core_update_github_token" id="core_update_github_token"
                               value="<?= htmlspecialchars($formData['core_update_github_token'] ?? '') ?>"
                               class="form-input"
                               placeholder="ghp_xxxxxxxxxxxxxxxxxxxx"
                               autocomplete="off">
                        <p class="form-help">
                            <?= htmlspecialchars(lang('core_update_github_token_help')) ?>
                        </p>
                    </div>
                </div>

                <!-- 커스텀 경로 입력 시 퍼미션/소유자 체크 버튼 -->
                <div id="core-pending-check-wrapper" class="test-db-wrapper" style="display:none;">
                    <button type="button" data-action="checkCorePendingPath" class="btn btn-success">
                        <?= htmlspecialchars(lang('check_core_pending_path')) ?>
                    </button>
                </div>
                <div id="core-pending-check-result" class="test-result hidden" role="status" aria-live="polite"></div>
            </div>
        </div>

        <!-- PHP CLI / Composer 설정 -->
        <div class="requirement-card" id="php-cli-card">
            <div class="requirement-card-header" style="cursor: pointer;">
                <label for="show-php-cli-settings" style="cursor: pointer; display: flex; align-items: center; gap: var(--spacing-sm); flex: 1;">
                    <h3 class="requirement-card-title" style="margin: 0;" id="php-cli-title"><?= htmlspecialchars(lang('php_cli_settings')) ?></h3>
                </label>
                <input type="checkbox" id="show-php-cli-settings" value="1"
                       <?= (! empty($formData['php_binary']) && $formData['php_binary'] !== 'php') || ! empty($formData['composer_binary']) ? 'checked' : '' ?>
                       class="toggle-switch">
            </div>
            <div id="php-cli-section" class="requirement-card-body <?= (empty($formData['php_binary']) || $formData['php_binary'] === 'php') && empty($formData['composer_binary']) ? 'hidden' : '' ?>" style="transition: all 0.3s ease-out;">
                <p class="form-help" style="margin-bottom: var(--spacing-md);" id="php-cli-help-text">
                    <?= htmlspecialchars(lang('php_cli_settings_help')) ?>
                </p>

                <!-- 검증 상태 요약 -->
                <div id="cli-status-summary" class="alert alert-info" style="display: none; margin-bottom: var(--spacing-md);">
                    <div style="display: flex; gap: var(--spacing-lg); flex-wrap: wrap;">
                        <span>PHP CLI: <strong id="cli-php-status"><?= htmlspecialchars(lang('cli_status_not_verified')) ?></strong></span>
                        <span>Composer: <strong id="cli-composer-status"><?= htmlspecialchars(lang('cli_status_not_verified')) ?></strong></span>
                    </div>
                </div>

                <div class="form-fields">
                    <!-- PHP CLI 경로 -->
                    <div class="form-group">
                        <label class="form-label" for="php_binary"><?= htmlspecialchars(lang('php_binary_path')) ?></label>
                        <div style="display: flex; gap: var(--spacing-sm);">
                            <input type="text" name="php_binary" id="php_binary"
                                   value="<?= htmlspecialchars($formData['php_binary'] ?? 'php') ?>"
                                   class="form-input" style="flex: 1;"
                                   placeholder="php">
                            <button type="button" data-action="testPhpBinary" class="btn btn-success" style="white-space: nowrap;">
                                <?= htmlspecialchars(lang('verify_version')) ?>
                            </button>
                        </div>
                        <p class="form-help">
                            <?= htmlspecialchars(lang('php_binary_path_help')) ?>
                        </p>
                        <div id="php-binary-test-result" class="test-result hidden" role="status" aria-live="polite"></div>
                    </div>

                    <!-- Composer 경로 -->
                    <div class="form-group">
                        <label class="form-label" for="composer_binary"><?= htmlspecialchars(lang('composer_binary_path')) ?></label>
                        <div style="display: flex; gap: var(--spacing-sm);">
                            <input type="text" name="composer_binary" id="composer_binary"
                                   value="<?= htmlspecialchars($formData['composer_binary'] ?? '') ?>"
                                   class="form-input" style="flex: 1;"
                                   placeholder="composer">
                            <button type="button" data-action="testComposer" class="btn btn-success" style="white-space: nowrap;">
                                <?= htmlspecialchars(lang('verify_version')) ?>
                            </button>
                        </div>
                        <p class="form-help">
                            <?= htmlspecialchars(lang('composer_binary_path_help')) ?>
                        </p>
                        <div id="composer-test-result" class="test-result hidden" role="status" aria-live="polite"></div>
                    </div>
                </div>

                <!-- Composer 미설치 시 설치 안내 -->
                <div id="composer-install-guide" class="alert alert-warning" style="display: none; margin-top: var(--spacing-md);">
                    <div class="alert-header">
                        <div class="alert-icon"><?= getSvgIcon('warning') ?></div>
                        <h3 class="alert-title"><?= htmlspecialchars(lang('composer_install_guide_title')) ?></h3>
                    </div>
                    <p class="alert-message"><?= htmlspecialchars(lang('composer_install_guide_message')) ?></p>
                    <div class="alert-details" id="composer-install-commands">
                        <!-- JS에서 PHP 경로 반영하여 동적 생성 -->
                    </div>
                </div>

                <div class="test-db-wrapper">
                    <button type="button" data-action="detectPhpBinaries" class="btn btn-secondary">
                        <?= htmlspecialchars(lang('auto_detect_php')) ?>
                    </button>
                </div>
                <div id="php-detect-result" class="test-result hidden" role="status" aria-live="polite"></div>
            </div>
        </div>

        <!-- ========== 실행 방식 (D33 · W2) ========== -->
        <?php
            require_once __DIR__.'/../includes/execution-mode.php';

// 번들 가용성은 「파일이 있다」가 아니라 「풀 수 있다」다 (13차 환경#4) — 판정은
// installerVendorBundleUsable() 단일 지점이 소유한다. 화면이 사본을 두면 요구사항 화면·감지·
// 태스크가 각자 다른 답을 낸다.
$bundleAvailable = installerVendorBundleUsable();
// 안내 문구만 두 사유를 가른다 — ZipArchive 부재와 zip 부재는 운영자가 할 일이 서로 다르다.
$zipArchiveAvailable = class_exists('ZipArchive');
// 실행 함수 가용성 판정은 코어와 공유하는 SSoT (function_exists + disable_functions)
$procOpenAvailable = ExecutionEnvironment::hasFunction('proc_open');
$currentVendorMode = $formData['vendor_mode'] ?? 'auto';

// 실행 방식 — 감지 결과는 "초기값" 의 근거일 뿐 저장되지 않는다 (A-0 ①). 기본 선택은 auto.
$currentExecutionMode = installerNormalizeExecutionMode($formData['execution_mode'] ?? 'auto');
// 최초 렌더도 재감지 경로와 **같은 판정기**를 지난다 (19차 S-7).
//
// 종전에는 `$formData['php_binary']` 원문을 그대로 넘겨, 경로 정규화와 「되돌렸는가」 판정을
// 소유한 `installerDetectionPhpBinary()`(§4.1 SSoT)를 건너뛰었다. 그래서 형태 위반 경로로
// 제출해 거부당한 뒤 다시 그려진 화면에서, 위쪽 입력 상자는 「허용되지 않는 경로」 빨강인데
// 아래 배지는 「감지 결과: 명령줄 실행」 파랑이었고 **어느 바이너리 기준인지** 화면 어디에도
// 없었다. 그 사실 전달은 JS 재감지 경로에만 배선돼 있었다.
$executionBinary = installerDetectionPhpBinary(
    ! empty($formData['php_binary']) ? (string) $formData['php_binary'] : 'php'
);
$executionBinarySubstituted = $executionBinary['substituted'];
$executionDetection = installerDetectExecutionMode($executionBinary['binary']);
$detectedExecutionMode = $executionDetection['mode'];
// 화면의 「고를 수 있는가」는 저장 게이트와 같은 판정을 쓴다 (19차 S-6) — check-configuration.php
// 의 `subprocess_selectable` 과 같은 규칙이어야 서버 렌더와 재감지 결과가 갈리지 않는다.
$subprocessSelectable = installerExecutionModeRejection(
    ExecutionEnvironment::MODE_SUBPROCESS,
    $executionDetection
) === null;
$inprocessSelectable = $bundleAvailable;
$executionReasons = installerExecutionModeReasons($executionDetection);
$effectiveExecutionMode = $currentExecutionMode === 'auto' ? $detectedExecutionMode : $currentExecutionMode;
$effectiveInprocess = $effectiveExecutionMode === ExecutionEnvironment::MODE_INPROCESS;

// inprocess 가 유효하면 composer 는 성립하지 않는다 (A-4) — 화면도 저장·실행 시점과 같은 보정 함수를 읽는다 (R9 SSoT)
$composerAvailable = $procOpenAvailable && ! $effectiveInprocess;
$currentVendorMode = installerCoerceVendorModeForExecution((string) $currentVendorMode, $effectiveExecutionMode);
?>
        <div class="requirement-card" id="execution-mode-card"
             data-detected-mode="<?= htmlspecialchars($detectedExecutionMode) ?>"
             data-subprocess-selectable="<?= $subprocessSelectable ? '1' : '0' ?>"
             data-proc-open-available="<?= $procOpenAvailable ? '1' : '0' ?>"
             data-bundle-available="<?= $bundleAvailable ? '1' : '0' ?>">
            <?php /* 제목·설명 배치는 Step 4 카드(4-extension-selection.php)의 선례를 따른다 —
                     헤더에는 requirement-card-title 하나만 두고 설명은 본문 첫 줄에 둔다.
                     종전 `card-title` 은 CSS 에 규칙이 없어 h2 기본값(30px)으로 그려졌고,
                     헤더가 space-between flex 라 설명이 제목 아래가 아니라 오른쪽 끝에 붙었다. */ ?>
            <div class="requirement-card-header">
                <h3 class="requirement-card-title" id="execution-mode-group-label"><?= htmlspecialchars(lang('execution_mode_card_title')) ?></h3>
            </div>
            <div class="requirement-card-body">
                <p class="card-description">
                    <?= htmlspecialchars(lang('execution_mode_card_description')) ?>
                </p>
                <?php /* 라디오 그룹에 이름을 준다 — 한 화면에 「자동」이라는 같은 이름의 선택지가
                         두 그룹(실행 방식·Vendor)에 있어, 그룹 이름이 없으면 보조기술이 어느 쪽의
                         「자동」인지 구분해 주지 못한다. */ ?>
                <div class="vendor-mode-cards execution-mode-cards" role="radiogroup" aria-labelledby="execution-mode-group-label">
                    <!-- 자동 (기본) -->
                    <label class="vendor-mode-card execution-mode-card <?= $currentExecutionMode === 'auto' ? 'selected' : '' ?>" data-mode="auto">
                        <input type="radio" name="execution_mode" value="auto" class="vendor-mode-card-input"
                               <?= $currentExecutionMode === 'auto' ? 'checked' : '' ?>>
                        <div class="vendor-mode-card-header">
                            <div class="vendor-mode-card-icon"><?= getSvgIcon('magic') ?></div>
                            <div class="vendor-mode-card-title-wrapper">
                                <h3 class="vendor-mode-card-title"><?= htmlspecialchars(lang('execution_mode_auto_title')) ?></h3>
                                <span class="vendor-mode-card-badge vendor-mode-card-badge-recommended"><?= htmlspecialchars(lang('execution_mode_badge_default')) ?></span>
                            </div>
                            <div class="vendor-mode-card-check"><?= getSvgIcon('check-circle') ?></div>
                        </div>
                        <p class="vendor-mode-card-description">
                            <?= htmlspecialchars(lang('execution_mode_auto_description')) ?>
                        </p>
                        <p class="vendor-mode-card-status vendor-mode-card-status-ok" id="execution-mode-auto-status" role="status"
                           data-template="<?= htmlspecialchars(lang('execution_mode_auto_status')) ?>"
                           data-label-subprocess="<?= htmlspecialchars(lang('execution_mode_label_subprocess')) ?>"
                           data-label-inprocess="<?= htmlspecialchars(lang('execution_mode_label_inprocess')) ?>"
                           data-substituted-note="<?= htmlspecialchars(lang('execution_mode_php_binary_substituted')) ?>">
                            <?= getSvgIcon('check') ?> <span class="execution-mode-status-text"><?= htmlspecialchars(
                                lang('execution_mode_auto_status', ['mode' => lang('execution_mode_label_'.$detectedExecutionMode)])
                                .($executionBinarySubstituted ? ' '.lang('execution_mode_php_binary_substituted') : '')
                            ) ?></span>
                        </p>
                    </label>

                    <!-- 명령줄 실행 -->
                    <label class="vendor-mode-card execution-mode-card <?= $currentExecutionMode === 'subprocess' ? 'selected' : '' ?> <?= $subprocessSelectable ? '' : 'disabled' ?>" data-mode="subprocess">
                        <input type="radio" name="execution_mode" value="subprocess" class="vendor-mode-card-input"
                               <?= $currentExecutionMode === 'subprocess' ? 'checked' : '' ?>
                               <?= $subprocessSelectable ? '' : 'disabled aria-disabled="true"' ?>>
                        <div class="vendor-mode-card-header">
                            <div class="vendor-mode-card-icon"><?= getSvgIcon('terminal') ?></div>
                            <div class="vendor-mode-card-title-wrapper">
                                <h3 class="vendor-mode-card-title"><?= htmlspecialchars(lang('execution_mode_subprocess_title')) ?></h3>
                                <span class="vendor-mode-card-badge vendor-mode-card-badge-dev"><?= htmlspecialchars(lang('execution_mode_badge_cli')) ?></span>
                            </div>
                            <div class="vendor-mode-card-check"><?= getSvgIcon('check-circle') ?></div>
                        </div>
                        <p class="vendor-mode-card-description">
                            <?= htmlspecialchars(lang('execution_mode_subprocess_description')) ?>
                        </p>
                        <p class="vendor-mode-card-status <?= $subprocessSelectable ? 'vendor-mode-card-status-ok' : 'vendor-mode-card-status-error' ?>" id="execution-mode-subprocess-status" role="status"
                           data-ok="<?= htmlspecialchars(lang('execution_mode_subprocess_status_ok')) ?>"
                           data-blocked="<?= htmlspecialchars(lang('execution_mode_subprocess_status_blocked')) ?>">
                            <?php /* 상태 줄의 경고는 글리프로 쓴다 — getSvgIcon('warning') 은 정의된 3키 중
                                      하나라 `?:` 폴백이 발화하지 않고, 48px 결과 아이콘(.result-icon,
                                      스트로크 애니메이션 포함)이 12px 글줄 안에 그대로 박힌다.
                                      통과 줄의 '✓'(getSvgIcon('check') 는 미정의라 폴백)와 높이가 어긋난다. */ ?>
                            <?php if ($subprocessSelectable) { ?>
                                <?= getSvgIcon('check') ?> <span class="execution-mode-status-text"><?= htmlspecialchars(lang('execution_mode_subprocess_status_ok')) ?></span>
                            <?php } else { ?>
                                ⚠ <span class="execution-mode-status-text"><?= htmlspecialchars(lang('execution_mode_subprocess_status_blocked')) ?><?= $executionReasons !== [] ? ' — '.htmlspecialchars(implode(' · ', $executionReasons)) : '' ?></span>
                            <?php } ?>
                        </p>
                    </label>

                    <!-- CLI 없이 실행 -->
                    <label class="vendor-mode-card execution-mode-card <?= $currentExecutionMode === 'inprocess' ? 'selected' : '' ?> <?= $inprocessSelectable ? '' : 'disabled' ?>" data-mode="inprocess">
                        <input type="radio" name="execution_mode" value="inprocess" class="vendor-mode-card-input"
                               <?= $currentExecutionMode === 'inprocess' ? 'checked' : '' ?>
                               <?= $inprocessSelectable ? '' : 'disabled aria-disabled="true"' ?>>
                        <div class="vendor-mode-card-header">
                            <div class="vendor-mode-card-icon"><?= getSvgIcon('package') ?></div>
                            <div class="vendor-mode-card-title-wrapper">
                                <h3 class="vendor-mode-card-title"><?= htmlspecialchars(lang('execution_mode_inprocess_title')) ?></h3>
                                <span class="vendor-mode-card-badge vendor-mode-card-badge-shared"><?= htmlspecialchars(lang('execution_mode_badge_no_cli')) ?></span>
                            </div>
                            <div class="vendor-mode-card-check"><?= getSvgIcon('check-circle') ?></div>
                        </div>
                        <p class="vendor-mode-card-description">
                            <?= htmlspecialchars(lang('execution_mode_inprocess_description')) ?>
                        </p>
                        <?php if ($inprocessSelectable) { ?>
                            <p class="vendor-mode-card-status vendor-mode-card-status-ok" role="status">
                                <?= getSvgIcon('check') ?> <?= htmlspecialchars(lang('execution_mode_inprocess_status_ok')) ?>
                            </p>
                        <?php } else { ?>
                            <p class="vendor-mode-card-status vendor-mode-card-status-error" role="status">
                                ⚠ <?= htmlspecialchars(lang('execution_mode_inprocess_status_no_bundle')) ?>
                            </p>
                        <?php } ?>
                    </label>
                </div>
            </div>
        </div>

        <!-- ========== Vendor 설치 방식 ========== -->
        <div class="requirement-card" id="vendor-mode-card-section">
            <div class="requirement-card-header">
                <h3 class="requirement-card-title" id="vendor-mode-group-label"><?= htmlspecialchars(lang('vendor_mode_title')) ?></h3>
            </div>
            <div class="requirement-card-body">
                <p class="card-description">
                    <?= htmlspecialchars(lang('vendor_mode_description')) ?>
                </p>
                <div class="vendor-mode-cards" role="radiogroup" aria-labelledby="vendor-mode-group-label">
                    <!-- 자동 (권장) -->
                    <?php /* inprocess 로 확정된 환경에서는 이 선택지도 잠근다 (9차 #L26).
                             서버는 auto 를 bundled 로 보정하므로(A-4), 잠그지 않으면 화면은 「자동」이
                             선택된 채인데 실제 설치는 번들로 돈다 — 바로 아래 안내가 「선택을 바꿀 수
                             없습니다」라고 단정하는 것과도 어긋난다. */ ?>
                    <label class="vendor-mode-card <?= $currentVendorMode === 'auto' ? 'selected' : '' ?> <?= $effectiveInprocess ? 'disabled' : '' ?>">
                        <input type="radio" name="vendor_mode" value="auto" class="vendor-mode-card-input"
                               <?= $currentVendorMode === 'auto' ? 'checked' : '' ?>
                               <?= $effectiveInprocess ? 'disabled aria-disabled="true" data-locked="1"' : '' ?>>
                        <div class="vendor-mode-card-header">
                            <div class="vendor-mode-card-icon"><?= getSvgIcon('magic') ?></div>
                            <div class="vendor-mode-card-title-wrapper">
                                <h3 class="vendor-mode-card-title"><?= htmlspecialchars(lang('vendor_mode_auto_title')) ?></h3>
                                <span class="vendor-mode-card-badge vendor-mode-card-badge-recommended"><?= htmlspecialchars(lang('vendor_mode_badge_recommended')) ?></span>
                            </div>
                            <div class="vendor-mode-card-check"><?= getSvgIcon('check-circle') ?></div>
                        </div>
                        <p class="vendor-mode-card-description">
                            <?= htmlspecialchars(lang('vendor_mode_auto_description')) ?>
                        </p>
                        <p class="vendor-mode-card-status <?= $effectiveInprocess ? 'vendor-mode-card-status-error' : 'vendor-mode-card-status-ok' ?>" id="vendor-mode-auto-status" role="status"
                           data-ok="<?= htmlspecialchars(lang('vendor_mode_auto_status_ok')) ?>"
                           data-inprocess="<?= htmlspecialchars(lang('vendor_mode_auto_status_inprocess')) ?>">
                            <?php if ($effectiveInprocess) { ?>
                                ⚠ <span class="vendor-mode-status-text"><?= htmlspecialchars(lang('vendor_mode_auto_status_inprocess')) ?></span>
                            <?php } else { ?>
                                <?= getSvgIcon('check') ?> <span class="vendor-mode-status-text"><?= htmlspecialchars(lang('vendor_mode_auto_status_ok')) ?></span>
                            <?php } ?>
                        </p>
                    </label>

                    <!-- Composer 실행 -->
                    <label class="vendor-mode-card <?= $currentVendorMode === 'composer' ? 'selected' : '' ?> <?= $composerAvailable ? '' : 'disabled' ?>">
                        <input type="radio" name="vendor_mode" value="composer" class="vendor-mode-card-input"
                               <?= $currentVendorMode === 'composer' ? 'checked' : '' ?>
                               <?= $composerAvailable ? '' : 'disabled aria-disabled="true"' ?>>
                        <div class="vendor-mode-card-header">
                            <div class="vendor-mode-card-icon"><?= getSvgIcon('terminal') ?></div>
                            <div class="vendor-mode-card-title-wrapper">
                                <h3 class="vendor-mode-card-title"><?= htmlspecialchars(lang('vendor_mode_composer_title')) ?></h3>
                                <span class="vendor-mode-card-badge vendor-mode-card-badge-dev"><?= htmlspecialchars(lang('vendor_mode_badge_dev')) ?></span>
                            </div>
                            <div class="vendor-mode-card-check"><?= getSvgIcon('check-circle') ?></div>
                        </div>
                        <p class="vendor-mode-card-description">
                            <?= htmlspecialchars(lang('vendor_mode_composer_description')) ?>
                        </p>
                        <p class="vendor-mode-card-status <?= $composerAvailable ? 'vendor-mode-card-status-ok' : 'vendor-mode-card-status-error' ?>" id="vendor-mode-composer-status" role="status"
                           data-ok="<?= htmlspecialchars(lang('vendor_mode_composer_status_ok')) ?>"
                           data-blocked="<?= htmlspecialchars(lang('vendor_mode_composer_status_blocked')) ?>"
                           data-inprocess="<?= htmlspecialchars(lang('vendor_mode_composer_status_inprocess')) ?>">
                            <?php if (! $procOpenAvailable) { ?>
                                ⚠ <span class="vendor-mode-status-text"><?= htmlspecialchars(lang('vendor_mode_composer_status_blocked')) ?></span>
                            <?php } elseif ($effectiveInprocess) { ?>
                                ⚠ <span class="vendor-mode-status-text"><?= htmlspecialchars(lang('vendor_mode_composer_status_inprocess')) ?></span>
                            <?php } else { ?>
                                <?= getSvgIcon('check') ?> <span class="vendor-mode-status-text"><?= htmlspecialchars(lang('vendor_mode_composer_status_ok')) ?></span>
                            <?php } ?>
                        </p>
                    </label>

                    <!-- 번들 Vendor 사용 -->
                    <label class="vendor-mode-card <?= $currentVendorMode === 'bundled' ? 'selected' : '' ?> <?= $bundleAvailable ? '' : 'disabled' ?>">
                        <input type="radio" name="vendor_mode" value="bundled" class="vendor-mode-card-input"
                               <?= $currentVendorMode === 'bundled' ? 'checked' : '' ?>
                               <?= $bundleAvailable ? '' : 'disabled aria-disabled="true"' ?>>
                        <div class="vendor-mode-card-header">
                            <div class="vendor-mode-card-icon"><?= getSvgIcon('package') ?></div>
                            <div class="vendor-mode-card-title-wrapper">
                                <h3 class="vendor-mode-card-title"><?= htmlspecialchars(lang('vendor_mode_bundled_title')) ?></h3>
                                <span class="vendor-mode-card-badge vendor-mode-card-badge-shared"><?= htmlspecialchars(lang('vendor_mode_badge_shared')) ?></span>
                            </div>
                            <div class="vendor-mode-card-check"><?= getSvgIcon('check-circle') ?></div>
                        </div>
                        <p class="vendor-mode-card-description">
                            <?= htmlspecialchars(lang('vendor_mode_bundled_description')) ?>
                        </p>
                        <?php if (! $zipArchiveAvailable) { ?>
                            <p class="vendor-mode-card-status vendor-mode-card-status-error" role="status">
                                ⚠ <?= htmlspecialchars(lang('vendor_mode_bundled_status_no_ziparchive')) ?>
                            </p>
                        <?php } elseif (! $bundleAvailable) { ?>
                            <p class="vendor-mode-card-status vendor-mode-card-status-error" role="status">
                                ⚠ <?= htmlspecialchars(lang('vendor_mode_bundled_status_no_zip')) ?>
                            </p>
                        <?php } else { ?>
                            <p class="vendor-mode-card-status vendor-mode-card-status-ok" role="status">
                                <?= getSvgIcon('check') ?> <?= htmlspecialchars(lang('vendor_mode_bundled_status_ok')) ?>
                            </p>
                        <?php } ?>
                    </label>
                </div>
                <p class="alert alert-info execution-mode-vendor-notice" id="execution-mode-vendor-notice" style="margin-top: var(--spacing-md); <?= $effectiveInprocess ? '' : 'display:none;' ?>">
                    <?= htmlspecialchars(lang('execution_mode_vendor_fixed_notice')) ?>
                </p>
                <?php /* 명령줄 안내는 그 명령을 실행할 수 있는 환경에서만 보여 준다 — 바로 위에
                         「이 서버는 Composer 를 실행할 수 없습니다」가 떠 있는 화면에서 `php artisan`
                         후속 조치를 제시하면, 운영자가 따를 수 없는 유일한 안내가 된다 (9차 #L27).
                         무CLI 환경의 변경 수단은 관리자 화면이므로 그 문구로 갈아 끼운다. */ ?>
                <p class="form-help" style="margin-top: var(--spacing-md);">
                    <?php if ($effectiveInprocess) { ?>
                        <?= htmlspecialchars(lang('vendor_mode_change_later_admin')) ?>
                    <?php } else { ?>
                        <?= lang('vendor_mode_change_later_html') ?>
                    <?php } ?>
                </p>
            </div>
        </div>

        <!-- 네비게이션 -->
        <?php /* 자산 URL 방식 (이슈 #486) — 아래 스크립트가 브라우저에서 프로브를 던져 채운다.
                 서버측에서 자기 APP_URL 로 curl 하면 loopback 이 vhost·프록시 체인을 우회해
                 오판하므로 반드시 브라우저가 판정해야 한다. 판정 실패 시 빈 값으로 남고
                 설치는 defaults.json 기본값(extension)으로 진행된다. */ ?>
        <input type="hidden" name="asset_url_mode" id="asset_url_mode" value="">

        <div class="btn-group btn-group-spread">
            <button type="button" data-action="goToStep" data-arg="2" class="btn btn-secondary">
                <?= htmlspecialchars(lang('previous')) ?>
            </button>

            <button type="submit" id="submit-btn" class="btn btn-primary">
                <?= htmlspecialchars(lang('next')) ?>
            </button>
        </div>
    </form>
</div>

<script type="application/json" id="installer-step-data"><?= installer_json_encode([
    // Step 3 전용 데이터 전달 (DB 테스트 상태)
    'db_test_flags' => [
        'write' => (bool) $dbWriteTested,
        'read' => (bool) $dbReadTested,
        'writeHash' => $dbWriteHash,
        'readHash' => $dbReadHash,
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= e(INSTALLER_BASE_URL) ?>/assets/js/step-3-configuration.js?v=<?= time() ?>"></script>
