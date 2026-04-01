<?php
/**
 * Step 0: 환영 화면 (언어 선택)
 *
 * POST 처리는 index.php에서 수행됩니다.
 *
 */

$selectedLang = getCurrentLanguage();

// Storage 폴더 권한 체크 (재귀 체크 포함)
$storageCheck = checkDirectoryPermissions(['storage' => false]);
$storagePermissionPassed = $storageCheck['all_passed'];
$storageResult = $storageCheck['results']['storage'] ?? null;
?>

<div class="welcome-wrapper">
    <div class="installer-container" id="welcome-step">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h1 class="installer-title" style="margin-bottom: 0;">
                <?= lang('welcome_title') ?>
            </h1>
            <button
                id="theme-toggle-btn"
                class="theme-toggle"
                type="button"
                aria-label="<?= lang('toggle_theme') ?>"
                title="<?= lang('toggle_theme') ?>"
                style="margin: 0;"
            >
                <span id="theme-toggle-icon">🌙</span>
            </button>
        </div>

        <p class="installer-description">
            <?= lang('welcome_desc') ?>
        </p>

        <!-- Storage 권한 체크 경고 (권한 없을 시만 표시) -->
        <?php if (!$storagePermissionPassed && $storageResult): ?>
        <div class="alert alert-error" id="permission-warning">
            <div class="alert-header">
                <div class="alert-icon">
                    <?= getSvgIcon('error') ?>
                </div>
                <h3 class="alert-title">
                    <?php
                    // 에러 타입에 따른 제목 표시
                    if ($storageResult['error_type'] === 'not_exists') {
                        echo lang('storage_not_exists');
                    } elseif ($storageResult['error_type'] === 'not_writable') {
                        echo lang('storage_not_writable');
                    } else {
                        echo lang('storage_permission_required');
                    }
                    ?>
                </h3>
            </div>
            <p class="alert-message">
                <?php
                // 에러 타입에 따른 메시지 표시
                if ($storageResult['error_type'] === 'not_exists') {
                    echo lang('storage_not_exists_message');
                } elseif ($storageResult['error_type'] === 'not_writable') {
                    echo lang('storage_not_writable_message');
                } else {
                    echo lang('storage_permission_failed');
                }
                ?>
            </p>
            <div class="alert-details">
                <?php
                    $detectedUser = getWebServerUser();
                    $webGroup = getWebServerGroup() ?? getWebServerUser() ?? 'www-data';
                    $storagePath = BASE_PATH . '/storage';
                ?>
                <?php $relativeStorage = './storage'; ?>
                <?php if ($storageResult['error_type'] === 'not_exists'): ?>
                    <!-- 디렉토리 미존재: mkdir 명령어 안내 -->
                    <p class="fix-guide-label"><?= lang('directory_create_guide') ?></p>
                    <div class="code-box">
                        <?php if (isWindows()): ?>
                            <pre class="fix-command"><?= htmlspecialchars('mkdir "' . str_replace('/', '\\', $storagePath) . '"') ?></pre>
                        <?php else: ?>
                            <pre class="fix-command"><?= htmlspecialchars('mkdir -p ' . $storagePath) ?></pre>
                        <?php endif; ?>
                        <button class="btn-copy" onclick="copyWelcomeCommand(this)"><?= lang('copy_command') ?></button>
                    </div>
                    <p class="fix-guide-hint"><?= lang('or_relative_path') ?></p>
                    <div class="code-box">
                        <?php if (isWindows()): ?>
                            <pre class="fix-command"><?= htmlspecialchars('mkdir "' . str_replace('/', '\\', $relativeStorage) . '"') ?></pre>
                        <?php else: ?>
                            <pre class="fix-command"><?= htmlspecialchars('mkdir -p ' . $relativeStorage) ?></pre>
                        <?php endif; ?>
                        <button class="btn-copy" onclick="copyWelcomeCommand(this)"><?= lang('copy_command') ?></button>
                    </div>
                <?php elseif (in_array($storageResult['error_type'], ['not_writable', 'permission_too_low', 'permission_low_but_writable', 'subdirectory_issues'])): ?>
                    <?php if (isWindows()): ?>
                        <!-- Windows: icacls 명령어 안내 -->
                        <p class="fix-guide-label"><?= lang('permission_fix_guide') ?></p>
                        <div class="code-box">
                            <pre class="fix-command"><?= htmlspecialchars('icacls "' . str_replace('/', '\\', $storagePath) . '" /grant Everyone:(OI)(CI)F /T') ?></pre>
                            <button class="btn-copy" onclick="copyWelcomeCommand(this)"><?= lang('copy_command') ?></button>
                        </div>
                        <p class="fix-guide-hint"><?= lang('or_relative_path') ?></p>
                        <div class="code-box">
                            <pre class="fix-command"><?= htmlspecialchars('icacls "' . str_replace('/', '\\', $relativeStorage) . '" /grant Everyone:(OI)(CI)F /T') ?></pre>
                            <button class="btn-copy" onclick="copyWelcomeCommand(this)"><?= lang('copy_command') ?></button>
                        </div>
                        <p class="fix-guide-hint"><?= lang('permission_windows_hint') ?></p>
                    <?php else: ?>
                        <!-- Linux/Mac: chmod (+ chown if owner ≠ web user) 명령어 안내 -->
                        <?php $ownerIsWebUser = $detectedUser && ($storageResult['owner'] ?? null) === $detectedUser; ?>
                        <?php if ($ownerIsWebUser): ?>
                            <p class="fix-guide-label"><?= lang('permission_fix_guide') ?></p>
                            <div class="code-box">
                                <pre class="fix-command"><?= htmlspecialchars('chmod -R 2770 ' . $storagePath) ?></pre>
                                <button class="btn-copy" onclick="copyWelcomeCommand(this)"><?= lang('copy_command') ?></button>
                            </div>
                            <p class="fix-guide-hint"><?= lang('or_relative_path') ?></p>
                            <div class="code-box">
                                <pre class="fix-command"><?= htmlspecialchars('chmod -R 2770 ' . $relativeStorage) ?></pre>
                                <button class="btn-copy" onclick="copyWelcomeCommand(this)"><?= lang('copy_command') ?></button>
                            </div>
                        <?php else: ?>
                            <p class="fix-guide-label"><?= lang('ownership_fix_guide') ?></p>
                            <div class="code-box">
                                <pre class="fix-command"><?= htmlspecialchars('sudo chown -R ' . $webGroup . ':' . $webGroup . ' ' . $storagePath . ' && sudo chmod -R 2770 ' . $storagePath) ?></pre>
                                <button class="btn-copy" onclick="copyWelcomeCommand(this)"><?= lang('copy_command') ?></button>
                            </div>
                            <p class="fix-guide-hint"><?= lang('or_relative_path') ?></p>
                            <div class="code-box">
                                <pre class="fix-command"><?= htmlspecialchars('sudo chown -R ' . $webGroup . ':' . $webGroup . ' ' . $relativeStorage . ' && sudo chmod -R 2770 ' . $relativeStorage) ?></pre>
                                <button class="btn-copy" onclick="copyWelcomeCommand(this)"><?= lang('copy_command') ?></button>
                            </div>
                            <?php if ($detectedUser): ?>
                                <p class="fix-guide-hint"><?= lang('ownership_detected_hint', ['user' => htmlspecialchars($detectedUser)]) ?></p>
                            <?php else: ?>
                                <p class="fix-guide-hint"><?= lang('ownership_fix_hint') ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="permission-details">
                    <span class="permission-label"><?= lang('current_owner') ?>:</span>
                    <span class="permission-badge badge-current"><?= htmlspecialchars($storageResult['owner'] ?? lang('unknown')) ?></span>
                    <span class="permission-label"><?= lang('current_group') ?>:</span>
                    <span class="permission-badge badge-current"><?= htmlspecialchars($storageResult['group'] ?? lang('unknown')) ?></span>
                    <span class="permission-label"><?= lang('current_permissions') ?>:</span>
                    <span class="permission-badge badge-current"><?= $storageResult['permissions'] ?></span>
                    <?php if (in_array($storageResult['error_type'], ['permission_too_low', 'permission_low_but_writable'])): ?>
                        <span class="permission-arrow">→</span>
                        <span class="permission-label"><?= lang('required_permissions') ?>:</span>
                        <span class="permission-badge badge-required"><?= $storageResult['required_permissions'] ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 언어 선택 및 다음 버튼 폼 -->
        <form method="POST" id="language-form">
            <div class="form-group mb-4">
                <label class="form-label">
                    <?= lang('select_language') ?>
                </label>
                <div class="form-field">
                    <select name="language"
                            id="language-select"
                            class="form-select">
                        <?php foreach (SUPPORTED_LANGUAGES as $code => $label): ?>
                        <option value="<?= $code ?>" <?= $selectedLang === $code ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="btn-group">
                <?php if (!$storagePermissionPassed): ?>
                    <!-- 권한 없을 때: 다시 확인 버튼 -->
                    <button type="button" onclick="recheckPermission()" class="btn btn-secondary" id="recheck-btn">
                        <?= lang('recheck_permission') ?>
                    </button>
                <?php else: ?>
                    <!-- 권한 있을 때: 설치하기 버튼 -->
                    <button type="submit" class="btn btn-primary" id="install-btn">
                        <?= lang('install') ?>
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
/**
 * 언어 선택 변경 시 localStorage 저장 후 자동 제출
 */
document.addEventListener('DOMContentLoaded', function() {
    const languageSelect = document.getElementById('language-select');
    const form = languageSelect.closest('form');

    if (languageSelect && form) {
        const serverLocale = languageSelect.value; // PHP에서 렌더링된 값

        // 페이지 로드 시 localStorage와 서버 값 동기화
        if (window.installerLocale) {
            const savedLocale = window.installerLocale.get();

            if (savedLocale && savedLocale !== serverLocale) {
                // localStorage 값으로 select 업데이트
                languageSelect.value = savedLocale;

                // 서버와 동기화 (한 번만 실행)
                const syncKey = 'installer_locale_synced';
                const alreadySynced = sessionStorage.getItem(syncKey);

                if (!alreadySynced) {
                    sessionStorage.setItem(syncKey, '1');

                    // 언어 변경만 하고 다음 단계로 이동하지 않음을 표시
                    let hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'language_change_only';
                    hiddenInput.value = '1';
                    form.appendChild(hiddenInput);

                    // 자동 제출하여 세션 및 state.json 동기화
                    form.submit();
                    return;
                }
            }
        }

        // 언어 선택 변경 이벤트
        languageSelect.addEventListener('change', function() {
            const selectedLang = this.value;

            // localStorage에 저장
            if (window.installerLocale) {
                window.installerLocale.set(selectedLang);
            }

            // 언어 변경만 하고 다음 단계로 이동하지 않음을 표시
            let hiddenInput = form.querySelector('input[name="language_change_only"]');
            if (!hiddenInput) {
                hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'language_change_only';
                form.appendChild(hiddenInput);
            }
            hiddenInput.value = '1';

            // 폼 자동 제출 (세션 및 state.json 동기화)
            form.submit();
        });
    }
});

/**
 * Storage 권한 재확인
 */
function recheckPermission() {
    const btn = document.getElementById('recheck-btn');
    const originalText = btn.textContent;

    btn.disabled = true;
    btn.textContent = '<?= lang('permission_checking') ?>';

    // 페이지 새로고침 (권한이 변경되었을 수 있으므로)
    setTimeout(function() {
        window.location.reload();
    }, 500);
}

/**
 * chmod 명령어 복사
 */
function copyWelcomeCommand(btn) {
    const command = btn.previousElementSibling.textContent;
    navigator.clipboard.writeText(command).then(function() {
        const originalText = btn.textContent;
        btn.textContent = '<?= lang('copied') ?>';
        setTimeout(function() { btn.textContent = originalText; }, 2000);
    });
}
</script>
