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
                    } elseif ($storageResult['error_type'] === 'ownership_mismatch') {
                        echo lang('storage_ownership_mismatch');
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
                } elseif ($storageResult['error_type'] === 'ownership_mismatch') {
                    echo lang('storage_ownership_mismatch_message', [
                        'owner' => $storageResult['owner'] ?? '?',
                        'web_user' => $storageResult['web_server_user'] ?? 'www-data',
                        'permissions' => $storageResult['permissions'] ?? '?',
                    ]);
                } elseif ($storageResult['error_type'] === 'not_writable') {
                    echo lang('storage_not_writable_message');
                } else {
                    echo lang('storage_permission_failed');
                }
                ?>
            </p>
            <div class="alert-details">
                <?php
                    $storagePath = BASE_PATH . '/storage';
                    $relativeStorage = './storage';
                ?>
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
                <?php elseif ($storageResult['error_type'] === 'ownership_mismatch'): ?>
                    <?php
                        $webUser = $storageResult['web_server_user'] ?? 'www-data';
                        $owner = $storageResult['owner'] ?? 'unknown';
                        // 그룹 쓰기 비트(020) 이미 설정되어 있으면 chmod 생략 (예: 775 → chgrp만)
                        $storagePermsOctal = octdec($storageResult['permissions'] ?? '755');
                        $storageGroupWritable = ($storagePermsOctal & 0020) !== 0;
                        $groupCommand = $storageGroupWritable
                            ? "sudo chgrp -R {$webUser} {$storagePath}"
                            : "sudo chgrp -R {$webUser} {$storagePath} && sudo chmod -R 775 {$storagePath}";
                    ?>
                    <!-- 소유권 불일치: 3가지 해결 옵션 제시 (공유 그룹 권장 > 소유자 변경 > 777) -->
                    <p class="fix-guide-label"><?= lang('ownership_mismatch_option_group') ?></p>
                    <div class="code-box">
                        <pre class="fix-command"><?= htmlspecialchars($groupCommand) ?></pre>
                        <button class="btn-copy" onclick="copyWelcomeCommand(this)"><?= lang('copy_command') ?></button>
                    </div>
                    <p class="fix-guide-label" style="margin-top: 1rem;"><?= lang('ownership_mismatch_option_chown') ?></p>
                    <div class="code-box">
                        <pre class="fix-command"><?= htmlspecialchars("sudo chown -R {$webUser}:{$webUser} {$storagePath}") ?></pre>
                        <button class="btn-copy" onclick="copyWelcomeCommand(this)"><?= lang('copy_command') ?></button>
                    </div>
                    <p class="fix-guide-label" style="margin-top: 1rem;"><?= lang('ownership_mismatch_option_777') ?></p>
                    <div class="code-box">
                        <pre class="fix-command"><?= htmlspecialchars("chmod -R 777 {$storagePath}") ?></pre>
                        <button class="btn-copy" onclick="copyWelcomeCommand(this)"><?= lang('copy_command') ?></button>
                    </div>
                    <p class="fix-guide-hint" style="margin-top: 0.75rem;"><?= lang('ownership_mismatch_hint', ['owner' => htmlspecialchars($owner), 'web_user' => htmlspecialchars($webUser)]) ?></p>
                <?php elseif (in_array($storageResult['error_type'], ['not_writable', 'not_readable', 'subdirectory_issues'])): ?>
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
                        <!-- Linux/Mac: chmod 755 단일 명령어 (chown 의존성 제거) -->
                        <p class="fix-guide-label"><?= lang('permission_fix_guide') ?></p>
                        <div class="code-box">
                            <pre class="fix-command"><?= htmlspecialchars('chmod -R 755 ' . $storagePath) ?></pre>
                            <button class="btn-copy" onclick="copyWelcomeCommand(this)"><?= lang('copy_command') ?></button>
                        </div>
                        <p class="fix-guide-hint"><?= lang('or_relative_path') ?></p>
                        <div class="code-box">
                            <pre class="fix-command"><?= htmlspecialchars('chmod -R 755 ' . $relativeStorage) ?></pre>
                            <button class="btn-copy" onclick="copyWelcomeCommand(this)"><?= lang('copy_command') ?></button>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="permission-details">
                    <span class="permission-label"><?= lang('current_owner') ?>:</span>
                    <span class="permission-badge badge-current"><?= htmlspecialchars($storageResult['owner'] ?? lang('unknown')) ?></span>
                    <span class="permission-label"><?= lang('current_group') ?>:</span>
                    <span class="permission-badge badge-current"><?= htmlspecialchars($storageResult['group'] ?? lang('unknown')) ?></span>
                    <span class="permission-label"><?= lang('current_permissions') ?>:</span>
                    <span class="permission-badge badge-current"><?= $storageResult['permissions'] ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 세션 쿠키 차단 경고 배너 (round-trip 검증 실패 시 JS 가 표시) -->
        <div id="session-cookie-blocked-banner"
             class="alert alert-warning"
             role="alert"
             style="display:none;margin-bottom:1rem;">
            <div class="alert-header" style="display:flex;justify-content:space-between;align-items:center;">
                <strong><?= lang('session_cookie_blocked_title') ?></strong>
                <button type="button"
                        onclick="document.getElementById('session-cookie-blocked-banner').style.display='none';"
                        class="btn-close"
                        aria-label="<?= lang('session_cookie_blocked_dismiss') ?>"
                        style="background:transparent;border:0;cursor:pointer;font-size:1.25rem;">×</button>
            </div>
            <p><?= lang('session_cookie_blocked_description') ?></p>
            <ol>
                <li><?= lang('session_cookie_blocked_remedy_1') ?></li>
                <li><?= lang('session_cookie_blocked_remedy_2') ?></li>
                <li><?= lang('session_cookie_blocked_remedy_3') ?></li>
            </ol>
            <p style="font-size:0.85rem;opacity:0.75;"><?= lang('session_cookie_blocked_technical_detail') ?></p>
        </div>

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

/**
 * 세션 쿠키 round-trip 사전 진단
 *
 * Step 0 진입 시 1회 실행. set 으로 세션에 nonce 저장 → verify 로 같은 세션의
 * nonce 복원 여부 확인. 실패 시 경고 배너 표시 (설치 진행은 차단하지 않음).
 *
 * `installation_status` 가 `not_started` 일 때만 검증 — 이미 설치 진행 중인
 * 화면 새로고침 시 nonce 가 verify 단계에서 소비되면 후속 검증이 영향 받지
 * 않도록.
 */
document.addEventListener('DOMContentLoaded', async function() {
    try {
        const baseUrl = window.INSTALLER_BASE_URL || '/install';
        const setResp = await fetch(baseUrl + '/api/session-probe.php?action=set', {
            credentials: 'same-origin',
            cache: 'no-store',
        });
        if (!setResp.ok) return;

        const verifyResp = await fetch(baseUrl + '/api/session-probe.php?action=verify', {
            credentials: 'same-origin',
            cache: 'no-store',
        });
        if (!verifyResp.ok) return;

        const verifyData = await verifyResp.json();
        if (verifyData && verifyData.matched === false) {
            const banner = document.getElementById('session-cookie-blocked-banner');
            if (banner) {
                banner.style.display = '';
            }
        }
    } catch (e) {
        // 네트워크 오류 / endpoint 미존재 — silent (Step 0 진행 자체에는 영향 없음)
    }
});
</script>
