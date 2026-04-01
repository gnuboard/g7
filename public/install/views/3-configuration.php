<?php
/**
 * Step 3: 데이터베이스 및 사이트 설정
 *
 * 데이터베이스 설정, 사이트 설정, 관리자 계정을 입력받습니다.
 * $formData는 index.php에서 준비됨
 */

if (!isset($errors)) {
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

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul class="alert-list">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" id="config-form" class="installer-form">
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
                               aria-invalid="false"
                               aria-describedby="db_prefix-error db_prefix-hint">
                        <small id="db_prefix-hint" class="form-hint">
                            <?= htmlspecialchars(lang('db_prefix_hint')) ?>
                        </small>
                        <div id="db_prefix-error" class="field-error" role="alert"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(lang('db_username')) ?> *</label>
                        <input type="text" name="db_write_username" value="<?= htmlspecialchars($formData['db_write_username']) ?>"
                               class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(lang('db_password')) ?></label>
                        <input type="password" name="db_write_password" value="<?= htmlspecialchars($formData['db_write_password']) ?>"
                               class="form-input">
                    </div>
                </div>

                <div class="test-db-wrapper">
                    <button type="button" onclick="testDatabaseConnection('write')" class="btn btn-success">
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
                       <?= !empty($formData['use_read_db']) ? 'checked' : '' ?>
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
                        <input type="text" name="db_read_username" value="<?= htmlspecialchars($formData['db_read_username']) ?>"
                               class="form-input">
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(lang('db_password')) ?></label>
                        <input type="password" name="db_read_password" value="<?= htmlspecialchars($formData['db_read_password']) ?>"
                               class="form-input">
                    </div>
                </div>

                <div class="test-db-wrapper">
                    <button type="button" onclick="testDatabaseConnection('read')" class="btn btn-success">
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
                            foreach (SUPPORTED_LANGUAGES as $code => $label): ?>
                            <option value="<?= $code ?>" <?= $selectedAdminLang === $code ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                            <?php endforeach; ?>
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
                    <button type="button" onclick="checkCorePendingPath()" class="btn btn-success">
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
                       <?= (!empty($formData['php_binary']) && $formData['php_binary'] !== 'php') || !empty($formData['composer_binary']) ? 'checked' : '' ?>
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
                            <button type="button" onclick="testPhpBinary()" class="btn btn-success" style="white-space: nowrap;">
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
                            <button type="button" onclick="testComposer()" class="btn btn-success" style="white-space: nowrap;">
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
                    <button type="button" onclick="detectPhpBinaries()" class="btn btn-secondary">
                        <?= htmlspecialchars(lang('auto_detect_php')) ?>
                    </button>
                </div>
                <div id="php-detect-result" class="test-result hidden"></div>
            </div>
        </div>

        <!-- 네비게이션 -->
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
    writeHash: <?= json_encode($dbWriteHash) ?>,
    readHash: <?= json_encode($dbReadHash) ?>
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
        return this.phpVerified && this.composerVerified;
    },

    updateStatusDisplay() {
        const summary = document.getElementById('cli-status-summary');
        const phpStatus = document.getElementById('cli-php-status');
        const composerStatus = document.getElementById('cli-composer-status');
        if (!summary || !phpStatus || !composerStatus) return;

        summary.style.display = '';

        phpStatus.textContent = this.phpVerified
            ? '<?= lang("cli_status_verified") ?>'
            : '<?= lang("cli_status_not_verified") ?>';
        phpStatus.style.color = this.phpVerified ? 'var(--success-color)' : 'var(--error-color)';

        composerStatus.textContent = this.composerVerified
            ? '<?= lang("cli_status_verified") ?>'
            : '<?= lang("cli_status_not_verified") ?>';
        composerStatus.style.color = this.composerVerified ? 'var(--success-color)' : 'var(--error-color)';
    },

    updateSubmitButton() {
        // submit 버튼 상태는 validateStep3Form에서 최종 체크하므로 여기서는 시각적 힌트만
    }
};

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
    if (phpStatus) { phpStatus.textContent = '<?= lang("cli_status_checking") ?>'; phpStatus.style.color = ''; }
    if (composerStatus) { composerStatus.textContent = '<?= lang("cli_status_checking") ?>'; composerStatus.style.color = ''; }

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
        const data = await response.json();

        if (!data.default_php_available) {
            // 기본 php 사용 불가 → 필수 모드로 전환
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
    if (title) title.textContent = '<?= lang("php_cli_settings_required") ?>';

    const helpText = document.getElementById('php-cli-help-text');
    if (helpText) helpText.textContent = '<?= lang("php_cli_settings_help_required") ?>';

    // 섹션 강제 오픈 + 토글 비활성화
    const section = document.getElementById('php-cli-section');
    if (section) section.classList.remove('hidden');

    const toggle = document.getElementById('show-php-cli-settings');
    if (toggle) {
        toggle.checked = true;
        toggle.disabled = true;
        toggle.style.opacity = '0.5';
        toggle.style.cursor = 'not-allowed';
    }
}

// PHP 바이너리 버전 확인
async function testPhpBinary() {
    const path = document.getElementById('php_binary').value.trim() || 'php';
    const resultDiv = document.getElementById('php-binary-test-result');

    resultDiv.className = 'test-result';
    resultDiv.innerHTML = '<span class="loading-spinner"></span> ' + '<?= lang("checking") ?>';

    try {
        const response = await fetch('api/check-configuration.php?action=test-php-binary', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ path: path })
        });
        const data = await response.json();

        if (data.success) {
            resultDiv.className = 'test-result test-success';
            resultDiv.innerHTML = data.message;
            window.CliValidator.setPhpVerified(true);
        } else {
            resultDiv.className = 'test-result test-error';
            resultDiv.innerHTML = data.message;
            window.CliValidator.setPhpVerified(false);
        }
    } catch (e) {
        resultDiv.className = 'test-result test-error';
        resultDiv.innerHTML = '<?= lang("error_check_failed") ?>';
        window.CliValidator.setPhpVerified(false);
    }
}

// Composer 버전 확인
async function testComposer() {
    const composerPath = document.getElementById('composer_binary').value.trim();
    const phpPath = document.getElementById('php_binary').value.trim() || 'php';
    const resultDiv = document.getElementById('composer-test-result');

    resultDiv.className = 'test-result';
    resultDiv.innerHTML = '<span class="loading-spinner"></span> ' + '<?= lang("composer_checking") ?>';

    try {
        const response = await fetch('api/check-configuration.php?action=test-composer', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ path: composerPath, php_path: phpPath })
        });
        const data = await response.json();

        if (data.success) {
            resultDiv.className = 'test-result test-success';
            resultDiv.innerHTML = data.message;
            window.CliValidator.setComposerVerified(true);
            // 설치 안내 숨기기
            const guide = document.getElementById('composer-install-guide');
            if (guide) guide.style.display = 'none';
        } else {
            resultDiv.className = 'test-result test-error';
            resultDiv.innerHTML = data.message;
            window.CliValidator.setComposerVerified(false);
            showComposerInstallGuide();
        }
    } catch (e) {
        resultDiv.className = 'test-result test-error';
        resultDiv.innerHTML = '<?= lang("error_check_failed") ?>';
        window.CliValidator.setComposerVerified(false);
        showComposerInstallGuide();
    }
}

/**
 * Composer 설치 안내를 PHP 경로 반영하여 동적 생성
 */
function showComposerInstallGuide() {
    const guide = document.getElementById('composer-install-guide');
    if (!guide) return;

    const phpPath = document.getElementById('php_binary').value.trim() || 'php';
    const commandsDiv = document.getElementById('composer-install-commands');
    if (!commandsDiv) return;

    let html = '';

    // 표준 설치 안내 (항상 표시)
    html += '<p class="fix-guide-label"><?= lang("composer_install_guide_global") ?></p>';
    html += '<div class="code-box">';
    html += '<pre class="fix-command">curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer</pre>';
    html += '<button type="button" class="btn-copy" onclick="copyCliCommand(this)"><?= lang("copy_command") ?></button>';
    html += '</div>';

    html += '<p class="fix-guide-label" style="margin-top: var(--spacing-sm);"><?= lang("composer_install_guide_local") ?></p>';
    html += '<div class="code-box">';
    html += '<pre class="fix-command">curl -sS https://getcomposer.org/installer | php</pre>';
    html += '<button type="button" class="btn-copy" onclick="copyCliCommand(this)"><?= lang("copy_command") ?></button>';
    html += '</div>';

    // 호스팅 환경 안내 (항상 표시 — 표준 방법 실패 시 대안)
    html += '<p class="fix-guide-label" style="margin-top: var(--spacing-md);"><?= lang("composer_install_guide_hosting") ?></p>';

    html += '<div class="code-box">';
    html += '<pre class="fix-command">curl -o composer-setup.php https://getcomposer.org/installer</pre>';
    html += '<button type="button" class="btn-copy" onclick="copyCliCommand(this)"><?= lang("copy_command") ?></button>';
    html += '</div>';

    html += '<div class="code-box" style="margin-top: var(--spacing-xs);">';
    html += '<pre class="fix-command">' + escapeHtml(phpPath) + ' -d allow_url_fopen=On composer-setup.php</pre>';
    html += '<button type="button" class="btn-copy" onclick="copyCliCommand(this)"><?= lang("copy_command") ?></button>';
    html += '</div>';

    html += '<p class="fix-guide-hint" style="margin-top: var(--spacing-sm);"><?= lang("composer_install_guide_pwd_hint") ?></p>';
    html += '<div class="code-box">';
    html += '<pre class="fix-command">pwd</pre>';
    html += '<button type="button" class="btn-copy" onclick="copyCliCommand(this)"><?= lang("copy_command") ?></button>';
    html += '</div>';

    html += '<p class="fix-guide-hint" style="margin-top: var(--spacing-sm);"><?= lang("composer_install_guide_phar_hint") ?></p>';

    html += '<p class="fix-guide-hint" style="margin-top: var(--spacing-sm);"><?= lang("composer_install_guide_link") ?></p>';

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
        let html = '<strong>' + '<?= lang("detected_php_binaries") ?>' + '</strong><ul style="margin: var(--spacing-xs) 0; padding-left: var(--spacing-lg);">';
        data.binaries.forEach(function(bin) {
            html += '<li><a href="#" onclick="selectPhpBinary(\'' + bin.path.replace(/'/g, "\\'") + '\'); return false;" style="cursor: pointer;">'
                + bin.path + '</a> — PHP ' + bin.version + '</li>';
        });
        html += '</ul>';
        resultDiv.className = 'test-result test-success';
        resultDiv.innerHTML = html;
    } else {
        resultDiv.className = 'test-result test-error';
        resultDiv.innerHTML = data.message || '<?= lang("no_php_detected") ?>';
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
    resultDiv.innerHTML = '<span class="loading-spinner"></span> ' + '<?= lang("detecting_php") ?>';

    try {
        const response = await fetch('api/check-configuration.php?action=detect-php');
        const data = await response.json();
        showDetectResult(data);

        if (!data.default_php_available) {
            switchToRequiredMode();
        }
    } catch (e) {
        resultDiv.className = 'test-result test-error';
        resultDiv.innerHTML = '<?= lang("error_check_failed") ?>';
    }
}

/**
 * 명령어 복사 (Composer 설치 안내)
 */
function copyCliCommand(btn) {
    const command = btn.previousElementSibling.textContent;
    navigator.clipboard.writeText(command).then(function() {
        const originalText = btn.textContent;
        btn.textContent = '<?= lang("copied") ?>';
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
    resultDiv.innerHTML = '<span class="loading-spinner"></span> ' + '<?= lang("checking") ?>';

    try {
        const response = await fetch('api/check-configuration.php?action=check-core-pending-path&path=' + encodeURIComponent(path));
        const data = await response.json();

        if (data.success) {
            resultDiv.className = 'test-result test-success';
            resultDiv.innerHTML = data.message;
        } else {
            resultDiv.className = 'test-result test-error';
            resultDiv.innerHTML = data.message;
        }
    } catch (e) {
        resultDiv.className = 'test-result test-error';
        resultDiv.innerHTML = '<?= lang("error_check_failed") ?>';
    }
}
</script>
