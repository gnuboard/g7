<?php

/**
 * 시험 전용 — installerExtensionInstallOrder() 를 디스크 스캔 대신 공용 픽스처 스캔으로 푼다.
 *
 * task-runner.php 의 정의는 `function_exists` 로 감싸여 있어, 이 파일을 먼저 적재하면 이 정의가 쓰인다.
 * 별도 프로세스에서 도는 시험(InstallerTaskListViewParityTest)만 적재한다 — 같은 프로세스의 다른 시험에 새지 않게.
 *
 * 선택은 `$GLOBALS['installer_test_selection']` 로 받는다(실제 함수의 인자는 상태 파일에서 오므로 시험이 정할 수 없다).
 */
if (! function_exists('installerExtensionInstallOrder')) {
    /**
     * 픽스처 스캔으로 해석기 설치 순서를 낸다.
     *
     * @param  array<string, mixed>  $selectedExtensions  상태 파일의 선택 (쓰지 않는다)
     * @return array<int, string> `카테고리:식별자` 목록
     */
    function installerExtensionInstallOrder(array $selectedExtensions): array
    {
        require_once dirname(__DIR__, 3).'/public/install/includes/extension-selection.php';

        $scan = json_decode((string) file_get_contents(__DIR__.'/extension-resolver-cases.json'), true)['scan'];
        $resolution = installer_resolve_extension_selection($scan, $GLOBALS['installer_test_selection'] ?? []);

        return $resolution['install_order'];
    }
}
