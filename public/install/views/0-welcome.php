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
                    } elseif ($storageResult['error_type'] === 'windows_readonly') {
                        echo lang('storage_windows_readonly');
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
                } elseif ($storageResult['error_type'] === 'windows_readonly') {
                    echo lang('storage_windows_readonly_message');
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
                        <button class="btn-copy" data-action="copyWelcomeCommand"><?= lang('copy_command') ?></button>
                    </div>
                    <p class="fix-guide-hint"><?= lang('or_relative_path') ?></p>
                    <div class="code-box">
                        <?php if (isWindows()): ?>
                            <pre class="fix-command"><?= htmlspecialchars('mkdir "' . str_replace('/', '\\', $relativeStorage) . '"') ?></pre>
                        <?php else: ?>
                            <pre class="fix-command"><?= htmlspecialchars('mkdir -p ' . $relativeStorage) ?></pre>
                        <?php endif; ?>
                        <button class="btn-copy" data-action="copyWelcomeCommand"><?= lang('copy_command') ?></button>
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
                        <button class="btn-copy" data-action="copyWelcomeCommand"><?= lang('copy_command') ?></button>
                    </div>
                    <p class="fix-guide-label" style="margin-top: 1rem;"><?= lang('ownership_mismatch_option_chown') ?></p>
                    <div class="code-box">
                        <pre class="fix-command"><?= htmlspecialchars("sudo chown -R {$webUser}:{$webUser} {$storagePath}") ?></pre>
                        <button class="btn-copy" data-action="copyWelcomeCommand"><?= lang('copy_command') ?></button>
                    </div>
                    <p class="fix-guide-label" style="margin-top: 1rem;"><?= lang('ownership_mismatch_option_777') ?></p>
                    <div class="code-box">
                        <pre class="fix-command"><?= htmlspecialchars("chmod -R 777 {$storagePath}") ?></pre>
                        <button class="btn-copy" data-action="copyWelcomeCommand"><?= lang('copy_command') ?></button>
                    </div>
                    <p class="fix-guide-hint" style="margin-top: 0.75rem;"><?= lang('ownership_mismatch_hint', ['owner' => htmlspecialchars($owner), 'web_user' => htmlspecialchars($webUser)]) ?></p>
                <?php elseif (in_array($storageResult['error_type'], ['not_writable', 'not_readable', 'subdirectory_issues', 'windows_readonly'])): ?>
                    <?php if (isWindows()): ?>
                        <?php if ($storageResult['error_type'] === 'windows_readonly'): ?>
                            <!-- Windows ReadOnly 속성: ACL 이 아니라 파일 속성이라 icacls 로 해소되지 않는다 -->
                            <p class="fix-guide-label"><?= lang('permission_readonly_fix_guide') ?></p>
                            <div class="code-box">
                                <pre class="fix-command"><?= htmlspecialchars(getPermissionFixCommand($storagePath, 'windows_readonly')) ?></pre>
                                <button class="btn-copy" data-action="copyWelcomeCommand"><?= lang('copy_command') ?></button>
                            </div>
                            <p class="fix-guide-hint"><?= lang('permission_readonly_hint') ?></p>
                        <?php endif; ?>
                        <!-- Windows: icacls 명령어 안내 -->
                        <p class="fix-guide-label"><?= lang('permission_fix_guide') ?></p>
                        <div class="code-box">
                            <pre class="fix-command"><?= htmlspecialchars('icacls "' . str_replace('/', '\\', $storagePath) . '" /grant Everyone:(OI)(CI)F /T') ?></pre>
                            <button class="btn-copy" data-action="copyWelcomeCommand"><?= lang('copy_command') ?></button>
                        </div>
                        <p class="fix-guide-hint"><?= lang('or_relative_path') ?></p>
                        <div class="code-box">
                            <pre class="fix-command"><?= htmlspecialchars('icacls "' . str_replace('/', '\\', $relativeStorage) . '" /grant Everyone:(OI)(CI)F /T') ?></pre>
                            <button class="btn-copy" data-action="copyWelcomeCommand"><?= lang('copy_command') ?></button>
                        </div>
                        <p class="fix-guide-hint"><?= lang('permission_windows_hint') ?></p>
                    <?php else: ?>
                        <!-- Linux/Mac: chmod 755 단일 명령어 (chown 의존성 제거) -->
                        <p class="fix-guide-label"><?= lang('permission_fix_guide') ?></p>
                        <div class="code-box">
                            <pre class="fix-command"><?= htmlspecialchars('chmod -R 755 ' . $storagePath) ?></pre>
                            <button class="btn-copy" data-action="copyWelcomeCommand"><?= lang('copy_command') ?></button>
                        </div>
                        <p class="fix-guide-hint"><?= lang('or_relative_path') ?></p>
                        <div class="code-box">
                            <pre class="fix-command"><?= htmlspecialchars('chmod -R 755 ' . $relativeStorage) ?></pre>
                            <button class="btn-copy" data-action="copyWelcomeCommand"><?= lang('copy_command') ?></button>
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
                        data-action="hideElement" data-arg="session-cookie-blocked-banner"
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
                    <button type="button" data-action="recheckPermission" class="btn btn-secondary" id="recheck-btn">
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

<script src="<?= INSTALLER_BASE_URL ?>/assets/js/step-0-welcome.js?v=<?= time() ?>"></script>
