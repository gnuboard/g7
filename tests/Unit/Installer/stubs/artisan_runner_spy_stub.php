<?php

/**
 * `installerRunArtisan` 의 호출 기록 스텁 (19차 R1).
 *
 * 고정하려는 계약은 「무엇을 실행했는가」가 아니라 **「실행했는가」** 다 — 마이그레이션 롤백의
 * 범위를 확정하지 못한 상태에서 `migrate:rollback` 이 한 번이라도 나가면, 배치 지정이 없으므로
 * 마지막 배치(= 운영자의 것)가 사라진다. 소스에 `'--step'` 리터럴이 있는지 보는 검사는 그
 * 리터럴을 그대로 둔 채 **분기 하나만 빠져나가는** 형태(19차 R1 의 실제 모습)를 잡지 못한다.
 *
 * 소비부(`executeMigrateRollback`)는 전역 함수를 부르므로 이 파일은 **전역 네임스페이스**여야
 * 한다 — 테스트 클래스 파일 안에서 선언하면 그 파일의 네임스페이스에 묶여 소비부가 영영 찾지
 * 못하고, 그 상태에서는 실제 러너가 돌아 검사가 의미를 잃는다
 * (`extension_installed_verdict_stub.php` 와 같은 이유·같은 형태).
 *
 * `artisan-executor.php` 의 정의는 `function_exists` 가드 뒤에 있으므로, 이 파일을 **먼저**
 * require 하면 그 정의가 건너뛰어진다.
 *
 * 기록은 `$GLOBALS['g7_test_artisan_runs']` 에 쌓인다. 각 테스트가 사용 전에 비운다.
 */
if (! function_exists('installerRunArtisan')) {
    /**
     * @param  string  $command  아티즌 커맨드
     * @param  array<string, mixed>  $parameters  파라미터
     * @return array{output: array<int, string>, exit_code: int}
     */
    function installerRunArtisan(string $command, array $parameters = []): array
    {
        if (! isset($GLOBALS['g7_test_artisan_runs']) || ! is_array($GLOBALS['g7_test_artisan_runs'])) {
            $GLOBALS['g7_test_artisan_runs'] = [];
        }

        $GLOBALS['g7_test_artisan_runs'][] = ['command' => $command, 'parameters' => $parameters];

        return ['output' => [], 'exit_code' => 0];
    }
}
