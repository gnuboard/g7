<?php
/**
 * 설치 소유권 잠금 화면
 *
 * 다른 브라우저 세션이 진행 중인 설치를 소유하고 있을 때 index.php 가 단계 화면 대신 렌더한다.
 * 이 화면에서는 어떤 설치 상태도 바꿀 수 없고, 소유자가 받아 둔 복구 토큰으로만 소유권을 되찾는다.
 */

// 터미널(php g7-install)이 소유한 설치는 브라우저가 넘겨받을 수 없다 — 복구 토큰 입력 대신 서버 안내만 보인다.
$lockedState = isset($state) && is_array($state) ? $state : getInstallationState();
if (($lockedState['owner_kind'] ?? null) === 'cli') {
    $cliRunning = ($lockedState['installation_status'] ?? null) === 'running'
        && ($lockedState['active_worker_id'] ?? null) !== null
        && (time() - (int) ($lockedState['last_heartbeat'] ?? 0)) < 15;
    ?>
<div class="installer-container" id="cli-owned-notice">
    <h1 class="installer-title"><?= htmlspecialchars(lang('cli_owned_notice_title')) ?></h1>
    <p class="installer-description"><?= htmlspecialchars(lang($cliRunning ? 'cli_owned_notice_running' : 'cli_owned_notice_stopped')) ?></p>
    <?php if (! $cliRunning) { // 이어서 설치 명령은 멈춘 경우에만 — 실행 중에는 「다른 터미널 설치가 실행 중」 으로 거부된다?>
    <div class="code-box"><pre>php g7-install --resume</pre></div>
    <?php } ?>
</div>
<?php
    return;
}
?>

<div class="installer-container">
    <h1 class="installer-title"><?= htmlspecialchars(lang('ownership_locked_title')) ?></h1>

    <p class="installer-description"><?= htmlspecialchars(lang('ownership_locked_desc')) ?></p>

    <div id="recovery-result" class="alert hidden" role="alert"></div>

    <form id="recovery-form" class="installer-form" data-action="submitRecoveryToken" data-event="submit">
        <div class="form-group form-group-full">
            <label class="form-label" for="recovery-token-input"><?= htmlspecialchars(lang('recovery_token_label')) ?></label>
            <input
                type="text"
                id="recovery-token-input"
                name="recovery_token"
                class="form-input"
                autocomplete="off"
                spellcheck="false"
                placeholder="<?= htmlspecialchars(lang('recovery_token_placeholder')) ?>"
                required
            >
        </div>

        <div class="btn-group">
            <button type="submit" id="recovery-submit-btn" class="btn btn-primary">
                <?= htmlspecialchars(lang('recovery_submit')) ?>
            </button>
        </div>
    </form>

    <p class="installer-description recovery-reset-hint"><?= htmlspecialchars(lang('ownership_reset_hint')) ?></p>
</div>
