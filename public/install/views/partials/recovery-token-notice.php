<?php
/**
 * 복구 토큰 안내 (Step 4·5 공용)
 *
 * 설치 소유 세션에만 보인다. 브라우저를 닫아 세션이 사라지면 다른 세션에서 설치를 이어가는
 * 유일한 수단이므로, 소유 세션이 살아 있는 동안 계속 노출한다(새로고침에도 유지).
 * 토큰 원문은 소유 세션에만 있고 state.json 에는 해시만 남는다.
 */
$recoveryToken = $_SESSION['install_recovery_token'] ?? '';

if (is_string($recoveryToken) && $recoveryToken !== '' && installer_session_is_owner(getInstallationState())) { ?>
    <div class="alert alert-warning recovery-token-notice" id="recovery-token-notice">
        <p><strong><?= htmlspecialchars(lang('recovery_token_notice_title')) ?></strong></p>
        <p><?= htmlspecialchars(lang('recovery_token_notice_desc')) ?></p>
        <div class="code-box">
            <pre id="recovery-token-value"><?= htmlspecialchars(installer_format_recovery_token($recoveryToken)) ?></pre>
            <button type="button" class="btn-copy" onclick="copyPermissionCommand(this)"><?= htmlspecialchars(lang('recovery_token_copy')) ?></button>
        </div>
    </div>
<?php }
