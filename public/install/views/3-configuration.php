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
                    <button type="button" onclick="testDatabaseConnection('write')" class="btn btn-success">
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
                    <button type="button" onclick="testDatabaseConnection('read')" class="btn btn-success">
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
                    <button type="button" onclick="checkCorePendingPath()" class="btn btn-success">
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
                            <button type="button" onclick="testPhpBinary()" class="btn btn-success" style="white-space: nowrap;">
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
                            <button type="button" onclick="testComposer()" class="btn btn-success" style="white-space: nowrap;">
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
                    <button type="button" onclick="detectPhpBinaries()" class="btn btn-secondary">
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
                <script>
                    // 카드형 라디오 — 변경 시 같은 name 그룹 안에서만 .selected 동기화
                    (function () {
                        const cards = document.querySelectorAll('.vendor-mode-card');
                        cards.forEach(function (card) {
                            const input = card.querySelector('.vendor-mode-card-input');
                            if (!input) return;
                            input.addEventListener('change', function () {
                                if (!input.checked) return;
                                cards.forEach(function (c) {
                                    const other = c.querySelector('.vendor-mode-card-input');
                                    if (other && other.name === input.name) c.classList.remove('selected');
                                });
                                card.classList.add('selected');
                            });
                        });
                    })();
                </script>
            </div>
        </div>

        <!-- 네비게이션 -->
        <?php /* 자산 URL 방식 (이슈 #486) — 아래 스크립트가 브라우저에서 프로브를 던져 채운다.
                 서버측에서 자기 APP_URL 로 curl 하면 loopback 이 vhost·프록시 체인을 우회해
                 오판하므로 반드시 브라우저가 판정해야 한다. 판정 실패 시 빈 값으로 남고
                 설치는 defaults.json 기본값(extension)으로 진행된다. */ ?>
        <input type="hidden" name="asset_url_mode" id="asset_url_mode" value="">

        <div class="btn-group btn-group-spread">
            <button type="button" onclick="goToStep(2)" class="btn btn-secondary">
                <?= htmlspecialchars(lang('previous')) ?>
            </button>

            <button type="submit" id="submit-btn" class="btn btn-primary">
                <?= htmlspecialchars(lang('next')) ?>
            </button>
        </div>
    </form>
</div>

<script>
// Step 3 전용 데이터 전달 (DB 테스트 상태)
window.DB_TEST_FLAGS = {
    write: <?= $dbWriteTested ? 'true' : 'false' ?>,
    read: <?= $dbReadTested ? 'true' : 'false' ?>,
    writeHash: <?= installerJsString($dbWriteHash) ?>,
    readHash: <?= installerJsString($dbReadHash) ?>
};

// ========================================================================
// PHP CLI / Composer 검증 상태 관리
// ========================================================================

/**
 * CLI 검증 상태 추적 객체
 */
window.CliValidator = {
    phpVerified: false,
    composerVerified: false,
    cliRequired: false, // 기본 php 사용 불가 시 true

    /**
     * Composer 검증이 필수인지 여부 — vendor_mode='bundled' 에서는 선택사항.
     */
    isComposerRequired() {
        const mode = this.getSelectedVendorMode();
        if (window.ExecutionModeUi && window.ExecutionModeUi.effectiveMode() === 'inprocess') return false;
        return mode !== 'bundled';
    },

    /**
     * PHP CLI 경로 검증이 필수인지 여부 — inprocess 에서는 선택사항 (19차 S-1).
     *
     * `detect-php` 는 「php 를 못 찾았다」와 「probe 자체를 돌릴 수 없다」를 한 값
     * (`default_php_available=false`)으로 접는다. exec 이 막힌 서버 — 이 안이 지원하려는 바로
     * 그 서버 — 에서는 후자가 참이고, 그때 검증을 필수로 걸면 **통과할 방법이 없다**:
     * [버전 확인] 도 같은 `validatePhpPath()` 를 거쳐 언제나 실패한다. 그 상태에서 Step 3 은
     * 막다른 길이 되고, 같은 화면이 「비워 두어도 됩니다」와 「검증되지 않았습니다」를
     * 동시에 말한다.
     *
     * inprocess 는 정의상 하위 프로세스를 띄우지 않으므로 PHP 실행 경로가 쓰이지 않는다.
     * 형제 축(`isComposerRequired`)이 이미 같은 이유로 같은 면제를 갖는다.
     */
    isPhpCliRequired() {
        if (window.ExecutionModeUi && window.ExecutionModeUi.effectiveMode() === 'inprocess') return false;
        return true;
    },

    /**
     * 현재 선택된 vendor_mode 값 (auto/composer/bundled).
     */
    getSelectedVendorMode() {
        const input = document.querySelector('input[name="vendor_mode"]:checked');
        return input ? input.value : 'auto';
    },

    setPhpVerified(verified) {
        this.phpVerified = verified;
        this.updateStatusDisplay();
        this.updateSubmitButton();
    },

    setComposerVerified(verified) {
        this.composerVerified = verified;
        this.updateStatusDisplay();
        this.updateSubmitButton();
    },

    isValid() {
        // CLI 섹션이 열려 있으면 (필수이든 선택이든) 검증 필요
        const section = document.getElementById('php-cli-section');
        if (section && section.classList.contains('hidden')) {
            // 섹션이 닫혀 있고 필수가 아니면 검증 불필요
            if (!this.cliRequired) return true;
        }

        if (!this.phpVerified) return false;

        // Composer 는 vendor_mode='bundled' 에서는 검증 불필요
        if (this.isComposerRequired() && !this.composerVerified) return false;

        return true;
    },

    updateStatusDisplay() {
        const summary = document.getElementById('cli-status-summary');
        const phpStatus = document.getElementById('cli-php-status');
        const composerStatus = document.getElementById('cli-composer-status');
        if (!summary || !phpStatus || !composerStatus) return;

        summary.style.display = '';

        phpStatus.textContent = this.phpVerified
            ? <?= installerJsString(lang('cli_status_verified')) ?>
            : <?= installerJsString(lang('cli_status_not_verified')) ?>;
        // 문구 색은 문구 전용 토큰을 쓴다 — 아이콘용 밝은 값은 alert-info 배경 위에서
        // 라이트 2.1:1 까지 내려간다 (9차 #L25).
        phpStatus.style.color = this.phpVerified ? 'var(--success-text)' : 'var(--error-text)';

        const composerOptional = !this.isComposerRequired();
        if (composerOptional) {
            composerStatus.textContent = <?= installerJsString(lang('cli_status_optional_bundled')) ?>;
            // 선언된 토큰만 쓴다 — `--text-muted-color` 는 이 저장소에 없어 폴백 #888 로
            // 고정됐고, 그러면 다크에서 이 문단만 팔레트를 벗어난다 (D16).
            composerStatus.style.color = 'var(--text-muted)';
        } else {
            composerStatus.textContent = this.composerVerified
                ? <?= installerJsString(lang('cli_status_verified')) ?>
                : <?= installerJsString(lang('cli_status_not_verified')) ?>;
            composerStatus.style.color = this.composerVerified ? 'var(--success-text)' : 'var(--error-text)';
        }
    },

    updateSubmitButton() {
        // submit 버튼 상태는 validateStep3Form에서 최종 체크하므로 여기서는 시각적 힌트만
    }
};

// vendor_mode 라디오 변경 시 CLI 검증 상태 표시 갱신 (Composer 검증 필수/선택 전환)
document.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'vendor_mode') {
        window.CliValidator.updateStatusDisplay();

        // bundled 모드 전환 시 Composer 설치 안내 숨김
        if (e.target.value === 'bundled') {
            const guide = document.getElementById('composer-install-guide');
            if (guide) guide.style.display = 'none';
        }
    }
});

// ========================================================================
// 실행 방식 선택 ↔ Vendor 설치 방식 동조 (D33 · A-4)
// ========================================================================

/**
 * 라디오의 잠김 상태를 native `disabled` 와 `aria-disabled` 양쪽에 반영한다.
 *
 * 서버 렌더는 두 속성을 함께 붙이는데(잠긴 카드 4종), JS 가 동적으로 잠그거나 풀 때
 * `disabled` 만 만지면 보조기술에 전달되는 상태가 초기 렌더 시점에 고정된다.
 *
 * @param {HTMLInputElement|null} input    대상 입력 (라디오·체크박스)
 * @param {boolean}               disabled 잠글지 여부
 * @returns {void}
 */
function setInputDisabled(input, disabled) {
    if (!input) return;
    input.disabled = !!disabled;
    if (disabled) {
        input.setAttribute('aria-disabled', 'true');
    } else {
        input.removeAttribute('aria-disabled');
    }
}

/**
 * 실행 방식 카드 상태 관리.
 *
 * 유효 모드 = 선택값(auto 면 감지 결과). inprocess 가 유효하면 composer 카드를 비활성하고
 * 번들로 고정한다 (W2 — 숨기지 않고 사유 배지와 함께 남긴다). 판정 본체는 서버
 * (execution-mode.php)가 SSoT 이며, 여기서는 서버가 내려준 감지 결과를 화면에 반영만 한다.
 */
window.ExecutionModeUi = {
    card: document.getElementById('execution-mode-card'),

    detectedMode() {
        return this.card ? (this.card.dataset.detectedMode || 'subprocess') : 'subprocess';
    },

    selectedMode() {
        const input = document.querySelector('input[name="execution_mode"]:checked');
        return input ? input.value : 'auto';
    },

    effectiveMode() {
        const selected = this.selectedMode();
        return selected === 'auto' ? this.detectedMode() : selected;
    },

    /**
     * 감지 결과를 카드에 반영한다 (페이지 로드 시 서버 렌더 + PHP 경로 검증 후 재감지).
     */
    applyDetection(data) {
        if (!this.card || !data) return;
        this.card.dataset.detectedMode = data.detected_mode || 'subprocess';
        this.card.dataset.subprocessSelectable = data.subprocess_selectable ? '1' : '0';

        const autoStatus = document.getElementById('execution-mode-auto-status');
        if (autoStatus) {
            const label = data.detected_mode === 'inprocess'
                ? autoStatus.dataset.labelInprocess
                : autoStatus.dataset.labelSubprocess;
            const text = autoStatus.querySelector('.execution-mode-status-text');
            if (text) {
                let status = (autoStatus.dataset.template || ':mode').replace(':mode', label || '');
                // 서버가 형태 위반 PHP 경로를 기본 php 로 되돌려 판정했다면 그 사실을 배지에 붙인다 —
                // 붙이지 않으면 이 배지가 어느 바이너리 기준인지가 화면 어디에도 남지 않는다.
                if (data.php_binary_substituted && autoStatus.dataset.substitutedNote) {
                    status += ' ' + autoStatus.dataset.substitutedNote;
                }
                text.textContent = status;
            }
        }

        const subCard = this.card.querySelector('.execution-mode-card[data-mode="subprocess"]');
        const subInput = subCard ? subCard.querySelector('input[name="execution_mode"]') : null;
        const subStatus = document.getElementById('execution-mode-subprocess-status');
        if (subCard && subInput && subStatus) {
            const selectable = !!data.subprocess_selectable;
            setInputDisabled(subInput, !selectable);
            subCard.classList.toggle('disabled', !selectable);
            subStatus.classList.toggle('vendor-mode-card-status-ok', selectable);
            subStatus.classList.toggle('vendor-mode-card-status-error', !selectable);
            const text = subStatus.querySelector('.execution-mode-status-text');
            if (text) {
                const reasons = Array.isArray(data.reasons) && data.reasons.length ? ' — ' + data.reasons.join(' · ') : '';
                text.textContent = selectable ? subStatus.dataset.ok : (subStatus.dataset.blocked + reasons);
            }
            if (!selectable && subInput.checked) {
                const autoInput = this.card.querySelector('input[name="execution_mode"][value="auto"]');
                if (autoInput) {
                    autoInput.checked = true;
                    autoInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        }

        this.syncVendorCards();
    },

    /**
     * 유효 실행 모드에 맞춰 Vendor 설치 방식 카드를 고정/해제한다 (A-4).
     */
    syncVendorCards() {
        const inprocess = this.effectiveMode() === 'inprocess';
        const procOpenAvailable = this.card ? this.card.dataset.procOpenAvailable === '1' : true;
        const composerInput = document.querySelector('input[name="vendor_mode"][value="composer"]');
        const composerCard = composerInput ? composerInput.closest('.vendor-mode-card') : null;
        const composerStatus = document.getElementById('vendor-mode-composer-status');
        const bundledInput = document.querySelector('input[name="vendor_mode"][value="bundled"]');
        const notice = document.getElementById('execution-mode-vendor-notice');

        const composerAvailable = procOpenAvailable && !inprocess;
        if (composerInput && composerCard) {
            setInputDisabled(composerInput, !composerAvailable);
            composerCard.classList.toggle('disabled', !composerAvailable);
        }

        // 자동 선택도 함께 잠근다 — 서버가 bundled 로 보정하므로, 잠그지 않으면 화면과
        // 실제 동작이 갈린다 (9차 #L26). 상태 줄 문구도 사유로 갈아 끼운다.
        const autoInput = document.querySelector('input[name="vendor_mode"][value="auto"]');
        const autoCard = autoInput ? autoInput.closest('.vendor-mode-card') : null;
        const autoStatus = document.getElementById('vendor-mode-auto-status');
        if (autoInput && autoCard) {
            setInputDisabled(autoInput, inprocess);
            autoCard.classList.toggle('disabled', inprocess);
        }
        if (autoStatus) {
            autoStatus.classList.toggle('vendor-mode-card-status-ok', !inprocess);
            autoStatus.classList.toggle('vendor-mode-card-status-error', inprocess);
            const autoText = autoStatus.querySelector('.vendor-mode-status-text');
            if (autoText) {
                autoText.textContent = inprocess ? autoStatus.dataset.inprocess : autoStatus.dataset.ok;
            }
        }
        if (composerStatus) {
            composerStatus.classList.toggle('vendor-mode-card-status-ok', composerAvailable);
            composerStatus.classList.toggle('vendor-mode-card-status-error', !composerAvailable);
            const text = composerStatus.querySelector('.vendor-mode-status-text');
            if (text) {
                text.textContent = !procOpenAvailable
                    ? composerStatus.dataset.blocked
                    : (inprocess ? composerStatus.dataset.inprocess : composerStatus.dataset.ok);
            }
        }
        if (inprocess && bundledInput && !bundledInput.disabled && !bundledInput.checked) {
            bundledInput.checked = true;
            bundledInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (notice) notice.style.display = inprocess ? '' : 'none';

        if (window.CliValidator) window.CliValidator.updateStatusDisplay();
    },

    /**
     * PHP 경로 기준으로 서버에 재감지를 요청한다 (PHP 경로 검증 직후).
     */
    async refresh(phpBinary) {
        if (!this.card) return;
        try {
            const response = await fetch('api/check-configuration.php?action=detect-execution-mode', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ php_binary: phpBinary || 'php' })
            });
            const data = await response.json();
            if (data && data.success) this.applyDetection(data);
        } catch (e) {
            // 감지 실패 시 서버 렌더 값 유지 — 설치 시작 시 다시 판정한다
        }
    }
};

document.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'execution_mode') {
        window.ExecutionModeUi.syncVendorCards();
    }
});

// PHP CLI 설정 토글
document.getElementById('show-php-cli-settings')?.addEventListener('change', function() {
    const section = document.getElementById('php-cli-section');
    if (this.checked) {
        section.classList.remove('hidden');
        // 토글을 켰을 때 자동 감지 실행
        initCliDetection();
    } else {
        section.classList.add('hidden');
    }
});

// PHP CLI / Composer 입력 변경 시 검증 상태 무효화
document.getElementById('php_binary')?.addEventListener('input', function() {
    window.CliValidator.setPhpVerified(false);
    // Composer도 PHP 경로에 의존하므로 (.phar 실행) 재검증 필요
    window.CliValidator.setComposerVerified(false);
    const phpResult = document.getElementById('php-binary-test-result');
    if (phpResult) { phpResult.classList.add('hidden'); phpResult.innerHTML = ''; }
    const composerResult = document.getElementById('composer-test-result');
    if (composerResult) { composerResult.classList.add('hidden'); composerResult.innerHTML = ''; }
});

document.getElementById('composer_binary')?.addEventListener('input', function() {
    window.CliValidator.setComposerVerified(false);
    const composerResult = document.getElementById('composer-test-result');
    if (composerResult) { composerResult.classList.add('hidden'); composerResult.innerHTML = ''; }
    // Composer 설치 안내 숨기기
    const guide = document.getElementById('composer-install-guide');
    if (guide) guide.style.display = 'none';
});

/**
 * 페이지 로드 시 PHP CLI / Composer 자동 감지
 */
async function initCliDetection() {
    const phpStatus = document.getElementById('cli-php-status');
    const composerStatus = document.getElementById('cli-composer-status');
    const summary = document.getElementById('cli-status-summary');

    if (summary) summary.style.display = '';
    if (phpStatus) { phpStatus.textContent = <?= installerJsString(lang('cli_status_checking')) ?>; phpStatus.style.color = ''; }
    if (composerStatus) { composerStatus.textContent = <?= installerJsString(lang('cli_status_checking')) ?>; composerStatus.style.color = ''; }

    // PHP 감지 먼저 실행 (composer 감지 결과 포함) → Composer 검증
    const composerData = await detectAndVerifyPhp();
    await detectAndVerifyComposer(composerData);
}

/**
 * PHP CLI 자동 감지 및 검증
 */
async function detectAndVerifyPhp() {
    try {
        const response = await fetch('api/check-configuration.php?action=detect-php');

        // 상한 초과(429)는 「php 가 없다」가 아니다 (19차 S-10).
        //
        // `probe_binary` 버킷은 4개 액션이 공유하고 60회/300초다. 경로를 고쳐 가며 Step 3 을
        // 여러 번 새로고침하면 정상 서버에서도 넘는데, 종전에는 그 응답에 없는
        // `default_php_available`(undefined)을 falsy 로 읽어 필수 모드로 전환했다 —
        // 사유 없이 카드가 「(필수)」로 잠기고 [다음] 이 막힌다.
        if (response.status === 429) {
            return null;
        }

        const data = await response.json();

        if (!data.default_php_available && window.CliValidator.isPhpCliRequired()) {
            // 기본 php 사용 불가 → 필수 모드로 전환
            //
            // inprocess 에서는 전환하지 않는다 (19차 S-1). 그 환경의 `default_php_available=false`
            // 는 「php 가 없다」가 아니라 「probe 를 돌릴 수 없다」이고, 서버 자신이 그 사유를
            // `error_binary_probe_unavailable` 로 갈라 두었다. 필수로 바꾸면 제목이 「(필수)」가
            // 되고 토글이 잠기는데, 그 항목은 이 실행 방식에서 쓰이지 않는다.
            switchToRequiredMode();

            // 감지된 바이너리가 있으면 첫 번째로 자동 선택
            if (data.success && data.binaries && data.binaries.length > 0) {
                const phpInput = document.getElementById('php_binary');
                if (phpInput && (phpInput.value === 'php' || phpInput.value === '')) {
                    phpInput.value = data.binaries[0].path;
                }
                // 자동 감지 결과 표시
                showDetectResult(data);
                // 자동 선택된 경로로 검증
                await testPhpBinary();
            }
        } else {
            // 기본 php 사용 가능
            if (data.success && data.binaries) {
                showDetectResult(data);
            }

            // 현재 입력된 경로로 검증
            await testPhpBinary();
        }

        return data.composer || null;
    } catch (e) {
        // 감지 실패 시 수동 입력 유도
        switchToRequiredMode();
        return null;
    }
}

/**
 * Composer 자동 감지 및 검증
 * detect-php API 응답의 composer 결과를 사용하여 사전 입력
 */
async function detectAndVerifyComposer(composerData) {
    const composerInput = document.getElementById('composer_binary');

    // detect-php 응답에 composer 감지 결과가 있으면 사전 입력
    if (composerData && composerData.found && composerInput) {
        if (!composerInput.value.trim()) {
            composerInput.value = composerData.path === 'composer' ? '' : composerData.path;
        }
    }

    await testComposer();
}

/**
 * 기본 php 미감지 시 필수 모드로 전환
 */
function switchToRequiredMode() {
    window.CliValidator.cliRequired = true;

    const title = document.getElementById('php-cli-title');
    if (title) title.textContent = <?= installerJsString(lang('php_cli_settings_required')) ?>;

    const helpText = document.getElementById('php-cli-help-text');
    if (helpText) helpText.textContent = <?= installerJsString(lang('php_cli_settings_help_required')) ?>;

    // 섹션 강제 오픈 + 토글 비활성화
    const section = document.getElementById('php-cli-section');
    if (section) section.classList.remove('hidden');

    const toggle = document.getElementById('show-php-cli-settings');
    if (toggle) {
        toggle.checked = true;
        // 라디오와 같은 규칙 — disabled 만 바꾸면 보조기술에 잠김이 안내되지 않는다
        setInputDisabled(toggle, true);
        toggle.style.opacity = '0.5';
        toggle.style.cursor = 'not-allowed';
    }
}

/**
 * 검사 결과를 알림 상자로 그린다.
 *
 * 종전에는 컨테이너에 `test-success`/`test-error` 를 붙였으나 그 두 클래스는 CSS 에
 * 선언이 없어 Step 3 의 성공과 실패가 라이트·다크 양쪽에서 같은 색으로 나왔다
 * (§11.5 「상태색이 두 모드에서 모두 구분되어야 한다」). `.test-result .alert-success`
 * / `.alert-error` 는 이미 선언돼 있고 DB 검사(installer.js)가 쓰는 형태이므로 그쪽에 맞춘다.
 *
 * @param {HTMLElement} resultDiv 결과 컨테이너
 * @param {boolean} ok 성공 여부
 * @param {string} html 본문 — 호출부가 만든 HTML (종전 innerHTML 대입과 노출 폭이 같다)
 */
function setTestResult(resultDiv, ok, html) {
    resultDiv.className = 'test-result';
    resultDiv.innerHTML = '<div class="alert alert-' + (ok ? 'success' : 'error') + '">' + html + '</div>';
}

// PHP 바이너리 버전 확인
async function testPhpBinary() {
    const path = document.getElementById('php_binary').value.trim() || 'php';
    const resultDiv = document.getElementById('php-binary-test-result');

    resultDiv.className = 'test-result';
    resultDiv.innerHTML = '<span class="loading-spinner"></span> ' + <?= installerJsString(lang('checking')) ?>;

    try {
        const response = await fetch('api/check-configuration.php?action=test-php-binary', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ path: path })
        });
        const data = await response.json();

        if (data.success) {
            setTestResult(resultDiv, true, data.message);
            window.CliValidator.setPhpVerified(true);
        } else {
            setTestResult(resultDiv, false, data.message);
            window.CliValidator.setPhpVerified(false);
        }
        // 실행 방식 감지는 PHP 경로에 따라 달라진다 — 검증 결과와 함께 다시 본다.
        // 형태 위반 경로를 서버가 기본 php 로 되돌렸다면 그 사실이 응답에 실려 오고,
        // applyDetection 이 배지 옆에 그 사유를 붙인다.
        await window.ExecutionModeUi.refresh(path);
    } catch (e) {
        setTestResult(resultDiv, false, <?= installerJsString(lang('error_check_failed')) ?>);
        window.CliValidator.setPhpVerified(false);
    }
}

// Composer 버전 확인
async function testComposer() {
    const composerPath = document.getElementById('composer_binary').value.trim();
    const phpPath = document.getElementById('php_binary').value.trim() || 'php';
    const resultDiv = document.getElementById('composer-test-result');

    resultDiv.className = 'test-result';
    resultDiv.innerHTML = '<span class="loading-spinner"></span> ' + <?= installerJsString(lang('composer_checking')) ?>;

    try {
        const response = await fetch('api/check-configuration.php?action=test-composer', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ path: composerPath, php_path: phpPath })
        });
        const data = await response.json();

        if (data.success) {
            setTestResult(resultDiv, true, data.message);
            window.CliValidator.setComposerVerified(true);
            // 설치 안내 숨기기
            const guide = document.getElementById('composer-install-guide');
            if (guide) guide.style.display = 'none';
        } else {
            setTestResult(resultDiv, false, data.message);
            window.CliValidator.setComposerVerified(false);
            showComposerInstallGuide();
        }
    } catch (e) {
        setTestResult(resultDiv, false, <?= installerJsString(lang('error_check_failed')) ?>);
        window.CliValidator.setComposerVerified(false);
        showComposerInstallGuide();
    }
}

/**
 * Composer 설치 안내를 PHP 경로 반영하여 동적 생성.
 *
 * vendor_mode='bundled' 에서는 Composer 가 불필요하므로 안내를 띄우지 않는다.
 */
function showComposerInstallGuide() {
    // 번들 모드에서는 Composer 가 불필요 → 안내 생략
    if (window.CliValidator && !window.CliValidator.isComposerRequired()) {
        const g = document.getElementById('composer-install-guide');
        if (g) g.style.display = 'none';
        return;
    }

    const guide = document.getElementById('composer-install-guide');
    if (!guide) return;

    const phpPath = document.getElementById('php_binary').value.trim() || 'php';
    const commandsDiv = document.getElementById('composer-install-commands');
    if (!commandsDiv) return;

    let html = '';

    // 표준 설치 안내 (항상 표시)
    html += '<p class="fix-guide-label">' + <?= installerJsString(lang('composer_install_guide_global')) ?> + '</p>';
    html += '<div class="code-box">';
    html += '<pre class="fix-command">curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer</pre>';
    html += '<button type="button" class="btn-copy" onclick="copyCliCommand(this)">' + <?= installerJsString(lang('copy_command')) ?> + '</button>';
    html += '</div>';

    html += '<p class="fix-guide-label" style="margin-top: var(--spacing-sm);">' + <?= installerJsString(lang('composer_install_guide_local')) ?> + '</p>';
    html += '<div class="code-box">';
    html += '<pre class="fix-command">curl -sS https://getcomposer.org/installer | php</pre>';
    html += '<button type="button" class="btn-copy" onclick="copyCliCommand(this)">' + <?= installerJsString(lang('copy_command')) ?> + '</button>';
    html += '</div>';

    // 호스팅 환경 안내 (항상 표시 — 표준 방법 실패 시 대안)
    html += '<p class="fix-guide-label" style="margin-top: var(--spacing-md);">' + <?= installerJsString(lang('composer_install_guide_hosting')) ?> + '</p>';

    html += '<div class="code-box">';
    html += '<pre class="fix-command">curl -o composer-setup.php https://getcomposer.org/installer</pre>';
    html += '<button type="button" class="btn-copy" onclick="copyCliCommand(this)">' + <?= installerJsString(lang('copy_command')) ?> + '</button>';
    html += '</div>';

    html += '<div class="code-box" style="margin-top: var(--spacing-xs);">';
    html += '<pre class="fix-command">' + escapeHtml(phpPath) + ' -d allow_url_fopen=On composer-setup.php</pre>';
    html += '<button type="button" class="btn-copy" onclick="copyCliCommand(this)">' + <?= installerJsString(lang('copy_command')) ?> + '</button>';
    html += '</div>';

    html += '<p class="fix-guide-hint" style="margin-top: var(--spacing-sm);">' + <?= installerJsString(lang('composer_install_guide_pwd_hint')) ?> + '</p>';
    html += '<div class="code-box">';
    html += '<pre class="fix-command">pwd</pre>';
    html += '<button type="button" class="btn-copy" onclick="copyCliCommand(this)">' + <?= installerJsString(lang('copy_command')) ?> + '</button>';
    html += '</div>';

    html += '<p class="fix-guide-hint" style="margin-top: var(--spacing-sm);">' + <?= installerJsString(lang('composer_install_guide_phar_hint')) ?> + '</p>';

    html += '<p class="fix-guide-hint" style="margin-top: var(--spacing-sm);">' + <?= installerJsString(lang('composer_install_guide_link')) ?> + '</p>';

    commandsDiv.innerHTML = html;
    guide.style.display = '';
}

/**
 * HTML 이스케이프
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * PHP 감지 결과 표시 (감지 결과 영역)
 */
function showDetectResult(data) {
    const resultDiv = document.getElementById('php-detect-result');
    if (!resultDiv) return;

    if (data.success && data.binaries && data.binaries.length > 0) {
        let html = '<strong>' + <?= installerJsString(lang('detected_php_binaries')) ?> + '</strong><ul style="margin: var(--spacing-xs) 0; padding-left: var(--spacing-lg);">';
        data.binaries.forEach(function(bin) {
            // 같은 값의 두 sink 가 규칙이 달랐다 — onclick 인자만 치환하고 링크 텍스트는
            // 무가공이었다 (9차 #L35). 한 값은 한 규칙으로 다룬다.
            // SAPI 도 함께 보여 준다 — CLI 가 아닌 후보를 고르면 설치 태스크가 전부 실패한다.
            const safePath = escapeHtml(bin.path);
            const sapi = bin.sapi ? ' (' + escapeHtml(String(bin.sapi)) + ')' : '';
            html += '<li><a href="#" onclick="selectPhpBinary(\'' + bin.path.replace(/'/g, "\\'") + '\'); return false;" style="cursor: pointer;">'
                + safePath + '</a> — PHP ' + escapeHtml(String(bin.version || '')) + sapi + '</li>';
        });
        html += '</ul>';
        setTestResult(resultDiv, true, html);
    } else {
        setTestResult(resultDiv, false, data.message || <?= installerJsString(lang('no_php_detected')) ?>);
    }
}

/**
 * 감지된 PHP 바이너리 선택 시 자동 검증
 */
async function selectPhpBinary(path) {
    document.getElementById('php_binary').value = path;
    // PHP 검증 상태 초기화 후 재검증
    window.CliValidator.setPhpVerified(false);
    window.CliValidator.setComposerVerified(false);
    await testPhpBinary();
    // Composer도 PHP 경로 의존이므로 재검증
    await testComposer();
}

// PHP 바이너리 자동 감지 (수동 버튼)
async function detectPhpBinaries() {
    const resultDiv = document.getElementById('php-detect-result');

    resultDiv.className = 'test-result';
    resultDiv.innerHTML = '<span class="loading-spinner"></span> ' + <?= installerJsString(lang('detecting_php')) ?>;

    try {
        const response = await fetch('api/check-configuration.php?action=detect-php');

        // 상한 초과는 감지 실패가 아니다 (19차 S-10) — 사유를 그대로 알리고 상태는 건드리지 않는다.
        if (response.status === 429) {
            setTestResult(resultDiv, false, <?= installerJsString(lang('error_probe_rate_limited')) ?>);

            return;
        }

        const data = await response.json();
        showDetectResult(data);

        if (!data.default_php_available && window.CliValidator.isPhpCliRequired()) {
            switchToRequiredMode();
        }
    } catch (e) {
        setTestResult(resultDiv, false, <?= installerJsString(lang('error_check_failed')) ?>);
    }
}

/**
 * 명령어 복사 (Composer 설치 안내)
 */
function copyCliCommand(btn) {
    const command = btn.previousElementSibling.textContent;
    navigator.clipboard.writeText(command).then(function() {
        const originalText = btn.textContent;
        btn.textContent = <?= installerJsString(lang('copied')) ?>;
        setTimeout(function() { btn.textContent = originalText; }, 2000);
    });
}

// 페이지 로드 시 CLI 섹션이 열려 있으면 자동 감지 실행
document.addEventListener('DOMContentLoaded', function() {
    const section = document.getElementById('php-cli-section');
    const toggle = document.getElementById('show-php-cli-settings');
    if (section && !section.classList.contains('hidden')) {
        initCliDetection();
    } else {
        // 섹션이 닫혀 있어도 기본 php/composer 사용 가능 여부는 확인해야 함
        // 사용 불가 시 필수 모드로 전환되면서 섹션이 열림
        initCliDetection();
    }
});

// 코어 업데이트 _pending 경로 입력 시 체크 버튼 표시
document.querySelector('input[name="core_update_pending_path"]')?.addEventListener('input', function() {
    const wrapper = document.getElementById('core-pending-check-wrapper');
    wrapper.style.display = this.value.trim() ? 'block' : 'none';
});

// 코어 _pending 경로 퍼미션/소유자 체크
async function checkCorePendingPath() {
    const path = document.querySelector('input[name="core_update_pending_path"]').value.trim();
    const resultDiv = document.getElementById('core-pending-check-result');

    if (!path) return;

    resultDiv.className = 'test-result';
    resultDiv.innerHTML = '<span class="loading-spinner"></span> ' + <?= installerJsString(lang('checking')) ?>;

    try {
        const response = await fetch('api/check-configuration.php?action=check-core-pending-path&path=' + encodeURIComponent(path));
        const data = await response.json();

        setTestResult(resultDiv, !!data.success, data.message);
    } catch (e) {
        setTestResult(resultDiv, false, <?= installerJsString(lang('error_check_failed')) ?>);
    }
}
</script>
