<?php

/**
 * 그누보드7 터미널 설치 - 설치 소유권·동시 실행 방지
 *
 * 웹 설치는 Step 3 를 처음 통과한 브라우저 세션을 소유자로 각인한다(owner_hash). 터미널 설치도 같은 필드에
 * 각인하되 세션 토큰 대신 프로세스 무작위 토큰의 해시를 쓰고 `owner_kind = cli` 를 남긴다. 복구 토큰은 만들지
 * 않는다 — 브라우저가 터미널 설치를 넘겨받는 경로를 두지 않기 위해서다(브라우저는 「터미널에서 설치 중」 안내만 본다).
 *
 * 설치 계획 서명(installer_plan_signature)·DROP 직전 재검증(installer_plan_verify)은 owner_hash 를 그대로 쓰므로
 * 웹과 같은 검증을 통과한다.
 */

if (! defined('G7_CLI_HEARTBEAT_ACTIVE_SECONDS')) {
    // 웹 워커가 이 시간 안에 심장박동했으면 활동 중으로 보고 넘겨받지 않는다 (acquireWorkerLock 판정과 같은 15초)
    define('G7_CLI_HEARTBEAT_ACTIVE_SECONDS', 15);
}

if (! function_exists('installer_state_owner_kind')) {
    /**
     * state 의 소유 주체.
     *
     * @param  array<string, mixed>  $state  인스톨러 state
     * @return string none | cli | web
     */
    function installer_state_owner_kind(array $state): string
    {
        if (! installer_state_is_claimed($state)) {
            return 'none';
        }

        return ($state['owner_kind'] ?? 'web') === 'cli' ? 'cli' : 'web';
    }
}

if (! function_exists('installer_worker_recently_active')) {
    /**
     * 설치 워커가 최근(15초 안)에 심장박동했는지.
     *
     * @param  array<string, mixed>  $state  인스톨러 state
     * @param  int|null  $now  기준 시각
     * @return bool 활동 중이면 true
     */
    function installer_worker_recently_active(array $state, ?int $now = null): bool
    {
        $now ??= time();

        return ($state['active_worker_id'] ?? null) !== null
            && ($now - (int) ($state['last_heartbeat'] ?? 0)) < G7_CLI_HEARTBEAT_ACTIVE_SECONDS;
    }
}

if (! function_exists('installer_claim_cli_owner')) {
    /**
     * state 에 터미널 설치를 소유자로 각인한다 (복구 토큰 없음).
     *
     * @param  array<string, mixed>  $state  인스톨러 state
     * @return array<string, mixed> 각인된 state
     */
    function installer_claim_cli_owner(array $state): array
    {
        $state['owner_hash'] = hash('sha256', bin2hex(random_bytes(32)));
        $state['owner_kind'] = 'cli';
        unset($state['recovery_hash']);
        $state['cli_claimed_at'] = date('c');

        return $state;
    }
}

if (! function_exists('installer_cli_takeover')) {
    /**
     * 웹 소유 설치를 터미널이 넘겨받는다 — 소유·복구 해시와 이전 소유자의 runtime.php(비밀)를 폐기한다.
     *
     * 웹 첫 소유 주장 때의 자격증명 혼합 차단과 같은 이유로 runtime 을 지운다. 호출자가 활동 중 판정을 먼저 한다.
     *
     * @param  array<string, mixed>  $state  인스톨러 state
     * @return array<string, mixed> 터미널이 각인된 state
     */
    function installer_cli_takeover(array $state): array
    {
        unset($state['owner_hash'], $state['recovery_hash'], $state['drop_confirm'], $state['install_plan_sig']);
        deleteInstallerRuntime();
        addLog(lang('log_cli_takeover'));

        return installer_claim_cli_owner($state);
    }
}

if (! function_exists('g7_cli_acquire_process_lock')) {
    /**
     * 터미널 설치 동시 실행 방지 잠금 — 프로세스가 살아 있는 동안 유지된다 (storage/installer/cli.lock).
     *
     * @return resource|null 잠금 핸들 (다른 터미널 설치가 실행 중이면 null)
     */
    function g7_cli_acquire_process_lock()
    {
        $dir = BASE_PATH.'/storage/installer';
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $handle = @fopen($dir.'/cli.lock', 'c');
        if ($handle === false) {
            return null;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        return $handle;
    }
}

if (! function_exists('g7_cli_state_summary')) {
    /**
     * 기존 설치 state 의 요약 (충돌 안내용).
     *
     * @param  array<string, mixed>  $state  인스톨러 state
     * @return array<string, string> 항목 → 값
     */
    function g7_cli_state_summary(array $state): array
    {
        $heartbeat = (int) ($state['last_heartbeat'] ?? 0);

        return [
            'owner' => installer_state_owner_kind($state),
            'status' => (string) ($state['installation_status'] ?? 'not_started'),
            'step' => (string) ($state['current_step'] ?? 0),
            'current_task' => (string) ($state['current_task'] ?? '-'),
            'last_heartbeat' => $heartbeat > 0 ? date('Y-m-d H:i:s', $heartbeat) : '-',
            'updated' => (string) ($state['last_updated'] ?? '-'),
        ];
    }
}
