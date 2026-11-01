<?php
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
                        <label class="form-label"><?= htmlspecialchars(lang('database_type')) ?></label>
                        <select name="db_type" class="form-select" disabled>
                            <option value="mysql" selected>MySQL</option>
                        </select>
                        <input type="hidden" name="db_type" value="mysql">
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(lang('db_host')) ?> *</label>
                        <input type="text" name="db_write_host" value="<?= htmlspecialchars($formData['db_write_host']) ?>"
                               class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(lang('db_port')) ?></label>
                        <input type="text" name="db_write_port" value="<?= htmlspecialchars($formData['db_write_port']) ?>"
                               class="form-input">
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(lang('db_name')) ?> *</label>
                        <input type="text" name="db_write_database" value="<?= htmlspecialchars($formData['db_write_database']) ?>"
                               class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(lang('db_prefix')) ?></label>
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
                        <label class="form-label"><?= htmlspecialchars(lang('db_username')) ?> *</label>
                        <input type="text" name="db_write_username" id="db_write_username"
                               value="<?= htmlspecialchars($formData['db_write_username']) ?>"
                               class="form-input" required
                               aria-invalid="false"
                               aria-describedby="db_write_username-error">
                        <div id="db_write_username-error" class="field-error" role="alert"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(lang('db_password')) ?></label>
                        <input type="password" name="db_write_password" value="<?= htmlspecialchars($formData['db_write_password']) ?>"
                               class="form-input">
                    </div>
                </div>

                <div class="test-db-wrapper">
                    <button type="button" data-action="testDatabaseConnection" data-arg="write" class="btn btn-success">
                        <?= htmlspecialchars(lang('test_write_db_connection')) ?>
                    </button>
                </div>

                <div id="db-write-test-result" class="test-result hidden"></div>
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
                        <label class="form-label"><?= htmlspecialchars(lang('db_host')) ?> *</label>
                        <input type="text" name="db_read_host" value="<?= htmlspecialchars($formData['db_read_host']) ?>"
                               class="form-input">
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(lang('db_port')) ?></label>
                        <input type="text" name="db_read_port" value="<?= htmlspecialchars($formData['db_read_port']) ?>"
                               class="form-input">
                    </div>

                    <div class="form-group form-group-full">
                        <label class="form-label"><?= htmlspecialchars(lang('db_name')) ?> *</label>
                        <input type="text" name="db_read_database" value="<?= htmlspecialchars($formData['db_read_database']) ?>"
                               class="form-input">
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(lang('db_username')) ?> *</label>
                        <input type="text" name="db_read_username" id="db_read_username"
                               value="<?= htmlspecialchars($formData['db_read_username']) ?>"
                               class="form-input"
                               aria-invalid="false"
                               aria-describedby="db_read_username-error">
                        <div id="db_read_username-error" class="field-error" role="alert"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(lang('db_password')) ?></label>
                        <input type="password" name="db_read_password" value="<?= htmlspecialchars($formData['db_read_password']) ?>"
                               class="form-input">
                    </div>
                </div>

                <div class="test-db-wrapper">
                    <button type="button" data-action="testDatabaseConnection" data-arg="read" class="btn btn-success">
                        <?= htmlspecialchars(lang('test_read_db_connection')) ?>
                    </button>
                </div>

                <div id="db-read-test-result" class="test-result hidden"></div>
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
                        <label class="form-label"><?= htmlspecialchars(lang('app_name')) ?> *</label>
                        <input type="text" name="app_name" id="app_name" value="<?= htmlspecialchars($formData['app_name']) ?>"
                               class="form-input" required
                               aria-invalid="false" aria-describedby="app_name-error">
                        <div id="app_name-error" class="field-error" role="alert"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(lang('app_url')) ?> *</label>
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
                        <label class="form-label"><?= htmlspecialchars(lang('admin_email')) ?> *</label>
                        <input type="email" name="admin_email" id="admin_email" value="<?= htmlspecialchars($formData['admin_email']) ?>"
                               class="form-input" required
                               aria-invalid="false" aria-describedby="admin_email-error">
                        <div id="admin_email-error" class="field-error" role="alert"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(lang('admin_name')) ?> *</label>
                        <input type="text" name="admin_name" id="admin_name" value="<?= htmlspecialchars($formData['admin_name']) ?>"
                               class="form-input" required
                               aria-invalid="false" aria-describedby="admin_name-error">
                        <div id="admin_name-error" class="field-error" role="alert"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(lang('admin_language')) ?> *</label>
                        <select name="admin_language" id="admin_language" class="form-select" required>
                            <?php
                            $selectedAdminLang = $formData['admin_language'] ?? getCurrentLanguage();
foreach (SUPPORTED_LANGUAGES as $code => $label) { ?>
                            <option value="<?= $code ?>" <?= $selectedAdminLang === $code ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(lang('admin_password')) ?> *</label>
                        <input type="password" name="admin_password" id="admin_password"
                               class="form-input" required minlength="8"
                               aria-invalid="false" aria-describedby="admin_password-error">
                        <div id="admin_password-error" class="field-error" role="alert"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(lang('admin_password_confirm')) ?> *</label>
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
                        <label class="form-label"><?= htmlspecialchars(lang('core_update_pending_path')) ?></label>
                        <input type="text" name="core_update_pending_path"
                               value="<?= htmlspecialchars($formData['core_update_pending_path'] ?? '') ?>"
                               class="form-input"
                               placeholder="storage/app/core_pending"
                               aria-describedby="core_update_pending_path-help">
                        <p id="core_update_pending_path-help" class="form-help">
                            <?= htmlspecialchars(lang('core_update_pending_path_help')) ?>
                        </p>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(lang('asset_url_mode')) ?></label>
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
                        <label class="form-label"><?= htmlspecialchars(lang('core_update_github_url')) ?></label>
                        <input type="url" name="core_update_github_url"
                               value="<?= htmlspecialchars($formData['core_update_github_url'] ?? 'https://github.com/gnuboard/g7') ?>"
                               class="form-input"
                               placeholder="https://github.com/gnuboard/g7">
                        <p class="form-help">
                            <?= htmlspecialchars(lang('core_update_github_url_help')) ?>
                        </p>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(lang('core_update_github_token')) ?></label>
                        <input type="password" name="core_update_github_token"
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
                <div id="core-pending-check-result" class="test-result hidden"></div>
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
                        <label class="form-label"><?= htmlspecialchars(lang('php_binary_path')) ?></label>
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
                        <div id="php-binary-test-result" class="test-result hidden"></div>
                    </div>

                    <!-- Composer 경로 -->
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(lang('composer_binary_path')) ?></label>
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
                        <div id="composer-test-result" class="test-result hidden"></div>
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
                <div id="php-detect-result" class="test-result hidden"></div>
            </div>
        </div>

        <!-- ========== Vendor 설치 방식 ========== -->
        <?php
            $bundleZipExists = file_exists(BASE_PATH.'/vendor-bundle.zip');
$zipArchiveAvailable = class_exists('ZipArchive');
$procOpenAvailable = function_exists('proc_open')
    && ! in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
$bundleAvailable = $bundleZipExists && $zipArchiveAvailable;
$composerAvailable = $procOpenAvailable;
$currentVendorMode = $formData['vendor_mode'] ?? 'auto';
?>
        <div class="requirement-card">
            <div class="requirement-card-header">
                <h2 class="card-title"><?= htmlspecialchars(lang('vendor_mode_title')) ?></h2>
                <p class="card-description">
                    <?= htmlspecialchars(lang('vendor_mode_description')) ?>
                </p>
            </div>
            <div class="requirement-card-body">
                <div class="vendor-mode-cards">
                    <!-- 자동 (권장) -->
                    <label class="vendor-mode-card <?= $currentVendorMode === 'auto' ? 'selected' : '' ?>">
                        <input type="radio" name="vendor_mode" value="auto" class="vendor-mode-card-input"
                               <?= $currentVendorMode === 'auto' ? 'checked' : '' ?>>
                        <div class="vendor-mode-card-header">
                            <div class="vendor-mode-card-icon"><?= getSvgIcon('magic') ?: '✨' ?></div>
                            <div class="vendor-mode-card-title-wrapper">
                                <h3 class="vendor-mode-card-title"><?= htmlspecialchars(lang('vendor_mode_auto_title')) ?></h3>
                                <span class="vendor-mode-card-badge vendor-mode-card-badge-recommended"><?= htmlspecialchars(lang('vendor_mode_badge_recommended')) ?></span>
                            </div>
                            <div class="vendor-mode-card-check"><?= getSvgIcon('check-circle') ?: '◉' ?></div>
                        </div>
                        <p class="vendor-mode-card-description">
                            <?= htmlspecialchars(lang('vendor_mode_auto_description')) ?>
                        </p>
                        <p class="vendor-mode-card-status vendor-mode-card-status-ok">
                            <?= getSvgIcon('check') ?: '✓' ?> <?= htmlspecialchars(lang('vendor_mode_auto_status_ok')) ?>
                        </p>
                    </label>

                    <!-- Composer 실행 -->
                    <label class="vendor-mode-card <?= $currentVendorMode === 'composer' ? 'selected' : '' ?> <?= $composerAvailable ? '' : 'disabled' ?>">
                        <input type="radio" name="vendor_mode" value="composer" class="vendor-mode-card-input"
                               <?= $currentVendorMode === 'composer' ? 'checked' : '' ?>
                               <?= $composerAvailable ? '' : 'disabled' ?>>
                        <div class="vendor-mode-card-header">
                            <div class="vendor-mode-card-icon"><?= getSvgIcon('terminal') ?: '⚙' ?></div>
                            <div class="vendor-mode-card-title-wrapper">
                                <h3 class="vendor-mode-card-title"><?= htmlspecialchars(lang('vendor_mode_composer_title')) ?></h3>
                                <span class="vendor-mode-card-badge vendor-mode-card-badge-dev"><?= htmlspecialchars(lang('vendor_mode_badge_dev')) ?></span>
                            </div>
                            <div class="vendor-mode-card-check"><?= getSvgIcon('check-circle') ?: '◉' ?></div>
                        </div>
                        <p class="vendor-mode-card-description">
                            <?= htmlspecialchars(lang('vendor_mode_composer_description')) ?>
                        </p>
                        <?php if (! $composerAvailable) { ?>
                            <p class="vendor-mode-card-status vendor-mode-card-status-error">
                                <?= getSvgIcon('warning') ?: '⚠' ?> <?= htmlspecialchars(lang('vendor_mode_composer_status_blocked')) ?>
                            </p>
                        <?php } else { ?>
                            <p class="vendor-mode-card-status vendor-mode-card-status-ok">
                                <?= getSvgIcon('check') ?: '✓' ?> <?= htmlspecialchars(lang('vendor_mode_composer_status_ok')) ?>
                            </p>
                        <?php } ?>
                    </label>

                    <!-- 번들 Vendor 사용 -->
                    <label class="vendor-mode-card <?= $currentVendorMode === 'bundled' ? 'selected' : '' ?> <?= $bundleAvailable ? '' : 'disabled' ?>">
                        <input type="radio" name="vendor_mode" value="bundled" class="vendor-mode-card-input"
                               <?= $currentVendorMode === 'bundled' ? 'checked' : '' ?>
                               <?= $bundleAvailable ? '' : 'disabled' ?>>
                        <div class="vendor-mode-card-header">
                            <div class="vendor-mode-card-icon"><?= getSvgIcon('package') ?: '📦' ?></div>
                            <div class="vendor-mode-card-title-wrapper">
                                <h3 class="vendor-mode-card-title"><?= htmlspecialchars(lang('vendor_mode_bundled_title')) ?></h3>
                                <span class="vendor-mode-card-badge vendor-mode-card-badge-shared"><?= htmlspecialchars(lang('vendor_mode_badge_shared')) ?></span>
                            </div>
                            <div class="vendor-mode-card-check"><?= getSvgIcon('check-circle') ?: '◉' ?></div>
                        </div>
                        <p class="vendor-mode-card-description">
                            <?= htmlspecialchars(lang('vendor_mode_bundled_description')) ?>
                        </p>
                        <?php if (! $zipArchiveAvailable) { ?>
                            <p class="vendor-mode-card-status vendor-mode-card-status-error">
                                <?= getSvgIcon('warning') ?: '⚠' ?> <?= htmlspecialchars(lang('vendor_mode_bundled_status_no_ziparchive')) ?>
                            </p>
                        <?php } elseif (! $bundleZipExists) { ?>
                            <p class="vendor-mode-card-status vendor-mode-card-status-error">
                                <?= getSvgIcon('warning') ?: '⚠' ?> <?= htmlspecialchars(lang('vendor_mode_bundled_status_no_zip')) ?>
                            </p>
                        <?php } else { ?>
                            <p class="vendor-mode-card-status vendor-mode-card-status-ok">
                                <?= getSvgIcon('check') ?: '✓' ?> <?= htmlspecialchars(lang('vendor_mode_bundled_status_ok')) ?>
                            </p>
                        <?php } ?>
                    </label>
                </div>
                <p class="form-help" style="margin-top: var(--spacing-md);">
                    <?= lang('vendor_mode_change_later_html') ?>
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
<script src="<?= INSTALLER_BASE_URL ?>/assets/js/step-3-configuration.js?v=<?= time() ?>"></script>
