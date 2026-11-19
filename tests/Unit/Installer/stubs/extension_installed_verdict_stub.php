<?php

/**
 * `installerExtensionIsInstalled` 의 테스트 스텁.
 *
 * 실제 판정기는 `public/install/api/rollback-functions.php` 소유이고 DB 를 조회한다.
 * `InstallerSkipPairedActivateTest` 가 고정하려는 계약은 **DB 조회 방법**이 아니라
 * 「판정 결과가 짝 처리를 가르는가」이므로, 세 갈래를 이 스텁으로 주입한다.
 *
 * 소비부(`installerApplyTaskSkip`)는 전역 `function_exists` 로 판정기를 찾으므로 이 파일은
 * **전역 네임스페이스**여야 한다 — 테스트 클래스 파일 안에서 선언하면 그 파일의 네임스페이스에
 * 묶여 소비부가 영영 찾지 못한다(그 상태에서는 검사가 조용히 종전 동작만 보게 된다).
 *
 * 판정값은 `$GLOBALS['g7_test_extension_installed_verdict']` 로 각 테스트가 세운다.
 */
if (! function_exists('installerExtensionIsInstalled')) {
    /**
     * @param  array<string, mixed>  $config
     */
    function installerExtensionIsInstalled(string $taskId, string $target, array $config, string $prefix): ?bool
    {
        $verdict = $GLOBALS['g7_test_extension_installed_verdict'] ?? null;

        // 확장별 상세 지정이 있으면 그쪽이 우선한다 — 파급 검사는 「건너뛴 확장」과
        // 「의존 확장」의 판정이 서로 달라야 의미가 있다.
        $perTarget = $GLOBALS['g7_test_extension_installed_by_target'] ?? [];
        if (is_array($perTarget) && array_key_exists($target, $perTarget)) {
            $verdict = $perTarget[$target];
        }

        return is_bool($verdict) ? $verdict : null;
    }
}

/**
 * `installerDependentsOfSkippedTarget` 의 테스트 스텁 (재귀).
 *
 * 실제 구현은 `task-runner.php` 소유인데, 그 파일은 `rollback-functions.php` 를 끌어와
 * 위 판정기와 재선언 충돌을 일으킨다. 여기서 고정하려는 것은 **파급 목록을 어떻게 만드는가**
 * (그쪽은 InstallerSkipDependencyCascadeTest 소관)가 아니라 「파급 목록에도 같은 설치 여부
 * 판정이 적용되는가」이므로, 목록을 주입한다.
 */
if (! function_exists('installerDependentsOfSkippedTarget')) {
    /**
     * @param  array<int, string>  $completedKeys
     * @return array{pending: array<int, string>, already: array<int, string>}
     */
    function installerDependentsOfSkippedTarget(string $skippedTarget, array $completedKeys): array
    {
        $stub = $GLOBALS['g7_test_skip_dependents'] ?? null;

        return is_array($stub)
            ? ['pending' => $stub['pending'] ?? [], 'already' => $stub['already'] ?? []]
            : ['pending' => [], 'already' => []];
    }
}
