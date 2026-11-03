<?php

namespace App\Support;

/**
 * 실행 환경 판정 (인스톨러·코어 공용 SSoT).
 *
 * "이 환경에서 서브프로세스(`php artisan …` 스폰)가 성립하는가" 를 판정합니다.
 * 인스톨러 Step 2 요구사항 게이트와 코어의 실행 모드 해석기(CommandRunnerResolver)가
 * 같은 판정을 공유해, 설치 시 판정과 운영 시 판정이 어긋나는 것을 구조적으로 차단합니다.
 *
 * 인스톨러(`public/install/`)는 Laravel 오토로드 없이 동작하는 순수 PHP 이므로
 * 이 클래스는 파사드·헬퍼 등 프레임워크 의존성을 일절 참조하지 않는다.
 * 인스톨러는 `require_once` 로 이 파일을 직접 로드해 사용한다 (OpcacheStatus 와 동일).
 *
 * fail-safe 방향: 판정이 불확실하면 `subprocess`(현행 경로) 로 떨어뜨린다.
 * `inprocess` 로 가는 것은 서브프로세스가 **명확히 불가능**할 때뿐이다 —
 * 함수 차단(모드 판정 대상 함수 중 하나라도) 또는 PHP 바이너리 probe 의 명확한 실패.
 */
final class ExecutionEnvironment
{
    /**
     * 서브프로세스 실행 모드가 요구하는 PHP 함수 목록.
     *
     * 인스톨러 요구사항 게이트(checkDisabledFunctions)가 검사해 온 현행 집합과 동일하다 —
     * 집합을 바꾸면 기존 설치 게이트의 판정이 함께 바뀌므로 기본값 불변 테스트가 지킨다.
     *
     * @var array<int, string>
     */
    public const SUBPROCESS_FUNCTIONS = ['exec', 'proc_open', 'shell_exec'];

    /** 실행 모드: 서브프로세스 (현행 — `php artisan …` 스폰) */
    public const MODE_SUBPROCESS = 'subprocess';

    /** 실행 모드: in-process (`Artisan::call()` — CLI 없는 환경) */
    public const MODE_INPROCESS = 'inprocess';

    /**
     * 지정 함수가 현재 환경에서 호출 가능한지 판정합니다.
     *
     * PHP 8 에서 `disable_functions` 차단 함수는 `function_exists()` 가 false 를
     * 반환하지만, 방어적으로 `disable_functions` 목록 대조도 함께 수행한다
     * (EnvironmentDetector::hasProcOpen 이 써 온 판정과 동일).
     *
     * 두 축 모두 **대소문자를 정규화**한다. PHP 함수명은 대소문자를 가리지 않으므로
     * `disable_functions = Proc_Open` 같은 표기도 실제로는 차단이고, 원문 그대로
     * 비교하면 그 대조는 어떤 입력에도 참이 되지 않아 "방어적으로 함께 수행" 이라는
     * 선언이 사문이 된다.
     *
     * @param  string  $function  판정할 함수명 (예: 'shell_exec')
     * @param  string|null  $disabledList  `disable_functions` 문자열 (테스트 주입용, 기본: ini 값).
     *                                     `disable_functions` 는 PHP_INI_SYSTEM 이라 런타임에
     *                                     바꿀 수 없다 — 목록 대조 규칙을 실제로 잠그려면
     *                                     이 자리로 넣는 수밖에 없다.
     * @return bool 호출 가능 여부
     */
    public static function hasFunction(string $function, ?string $disabledList = null): bool
    {
        if ($disabledList === null && ! function_exists($function)) {
            return false;
        }

        $normalized = strtolower(trim($function));

        // `ini_get` 자신이 차단된 환경이 있다 (`check-configuration.php` 의 판정이 이미 그
        // 전제 위에 서 있다). 그 환경에서 이 줄을 무가드로 부르면 **가드 판정 SSoT 가 첫
        // 호출에서 치명 종료**하고, 그것을 쓰는 인스톨러는 첫 화면조차 뜨지 않는다 — 즉
        // 「가드가 있어서 안전하다」고 선언한 모든 지점이 동시에 무너진다.
        // 목록을 못 읽으면 빈 목록으로 본다: 위의 `function_exists` 가 이미 주 판정이고
        // (PHP 8 은 차단 함수를 함수 테이블에서 제거한다) 이 대조는 그 뒤의 방어일 뿐이다.
        if ($disabledList === null) {
            $disabledList = function_exists('ini_get') ? (string) ini_get('disable_functions') : '';
        }

        $disabled = array_map(
            static fn (string $fn): string => strtolower(trim($fn)),
            explode(',', $disabledList),
        );

        return ! in_array($normalized, $disabled, true);
    }

    /**
     * 서브프로세스 필수 함수 중 차단된 것의 목록을 반환합니다.
     *
     * @return array<int, string> 차단된 함수명 목록 (전부 가용하면 빈 배열)
     */
    public static function blockedFunctions(): array
    {
        return array_values(array_filter(
            self::SUBPROCESS_FUNCTIONS,
            static fn (string $fn): bool => ! self::hasFunction($fn),
        ));
    }

    /**
     * PHP 바이너리 실행 probe.
     *
     * 실제로 `{binary} --version` 을 실행해 본다 (PhpBinaryResolver::validate 위임 —
     * 판정 로직을 이중화하지 않는다). `exec` 자체가 차단되어 probe 를 수행할 수 없으면
     * `unavailable`(판정 불확실) 로 구분해 돌려준다 — 이 상태는 "명확한 실패" 가 아니다.
     *
     * 두 콜백 파라미터는 테스트에서 환경을 주입하기 위한 것으로,
     * 운영 코드는 앞의 인수만 채워 호출한다.
     *
     * @param  string  $binary  PHP 바이너리 경로 (기본 'php')
     * @param  callable|null  $validator  바이너리 검증 콜백 (기본: PhpBinaryResolver::validate)
     * @param  callable|null  $hasFunction  함수 가용성 판정 콜백 (기본: self::hasFunction)
     * @return array{status: string, version: string|null, sapi: string|null, message: string, message_key: string, message_params: array<string, string>} status 는 'valid'|'invalid'|'unavailable'
     */
    public static function probePhpBinary(
        string $binary = 'php',
        ?callable $validator = null,
        ?callable $hasFunction = null,
    ): array {
        $validator ??= static fn (string $path): array => PhpBinaryResolver::validate($path);
        $hasFunction ??= static fn (string $fn): bool => self::hasFunction($fn);

        // probe 는 exec 로 수행된다 — exec 차단 환경에서는 "판정 불확실" 로 구분
        if (! $hasFunction('exec')) {
            return [
                'status' => 'unavailable',
                'version' => null,
                'sapi' => null,
                'message' => 'exec unavailable — PHP binary probe skipped',
                'message_key' => 'php_probe_exec_unavailable',
                'message_params' => [],
            ];
        }

        try {
            $result = $validator($binary);
        } catch (\Throwable $e) {
            // probe 자체가 예외로 죽으면 판정 불확실 — fail-safe 는 subprocess 쪽이다
            return [
                'status' => 'unavailable',
                'version' => null,
                'sapi' => null,
                'message' => 'PHP binary probe failed: '.$e->getMessage(),
                'message_key' => 'php_probe_failed',
                'message_params' => [],
            ];
        }

        // `message` 는 하위호환(로그·개발 편의)으로 남기고, 화면에 노출되는 사유는
        // `message_key` 로 넘긴다 — 소비처가 자기 로케일로 번역한다. 키가 없는 주입 스텁도
        // 계약을 유지하도록 빈 문자열로 채운다.
        //
        // 사전은 `lang/{ko,en}/settings.php` 의 `php_probe_*` 다. 이 키들은 한동안 **어떤
        // 언어팩에도 없었고** 번역하는 소비처도 없어, 계약이 선언만 남고 사문이었다 —
        // 화면이 붙는 순간 원시 키가 그대로 노출됐을 것이다. 소비처는 자기 네임스페이스를
        // 붙여 쓴다: `__('settings.'.$key, $params)` (7차 G3 라운드 D-5).
        return [
            'status' => ($result['valid'] ?? false) ? 'valid' : 'invalid',
            'version' => $result['version'] ?? null,
            'sapi' => $result['sapi'] ?? null,
            'message' => $result['message'] ?? '',
            'message_key' => (string) ($result['message_key'] ?? ''),
            'message_params' => is_array($result['message_params'] ?? null) ? $result['message_params'] : [],
        ];
    }

    /**
     * 실행 모드를 종합 판정합니다.
     *
     * 판정 규칙 (fail-safe 방향 포함):
     * - 필수 함수 중 하나라도 차단 → 서브프로세스가 명확히 불가 → `inprocess`
     * - 함수 전부 가용 + PHP probe `invalid` → 공통 경로 자동 탐색(autoDetect) 폴백.
     *   후보 발견 → `subprocess` (그 바이너리를 판정 결과에 기록) / 전부 실패 → `inprocess`
     *   (PATH 에 php 가 없고 비표준 경로에만 있는 호스팅을 in-process 로 오판하지 않는다)
     * - 함수 전부 가용 + PHP probe `valid` 인데 SAPI 가 비-CLI(fpm·cgi) → 같은 자동 탐색으로
     *   CLI 후보를 먼저 찾는다. 찾으면 그 바이너리로 `subprocess`, CLI 후보가 없으면 지정
     *   바이너리를 그대로 쓴다 (php-cgi 로만 동작하던 환경을 새로 막지 않는다)
     * - 함수 전부 가용 + PHP probe `valid`(CLI) 또는 `unavailable`(불확실) → `subprocess`
     *
     * @param  string  $phpBinary  probe 대상 PHP 바이너리 (Laravel 측은 config('process.php_binary') 전달)
     * @param  callable|null  $validator  바이너리 검증 콜백 (테스트 주입용)
     * @param  callable|null  $hasFunction  함수 가용성 판정 콜백 (테스트 주입용)
     * @param  callable|null  $autoDetector  대체 바이너리 탐색 콜백 (테스트 주입용, 기본: PhpBinaryResolver::autoDetect)
     * @return array{mode: string, blocked_functions: array<int, string>, php_binary: string, php_probe: array{status: string, version: string|null, sapi: string|null, message: string, message_key: string, message_params: array<string, string>}} 판정 결과와 근거
     */
    public static function detect(
        string $phpBinary = 'php',
        ?callable $validator = null,
        ?callable $hasFunction = null,
        ?callable $autoDetector = null,
    ): array {
        $hasFunction ??= static fn (string $fn): bool => self::hasFunction($fn);
        $autoDetector ??= static fn (): array => PhpBinaryResolver::autoDetect();

        $blocked = array_values(array_filter(
            self::SUBPROCESS_FUNCTIONS,
            static fn (string $fn): bool => ! $hasFunction($fn),
        ));

        if ($blocked !== []) {
            return [
                'mode' => self::MODE_INPROCESS,
                'blocked_functions' => $blocked,
                'php_binary' => $phpBinary,
                'php_probe' => [
                    'status' => 'unavailable',
                    'version' => null,
                    'sapi' => null,
                    'message' => 'subprocess functions blocked — probe skipped',
                    'message_key' => 'php_probe_functions_blocked',
                    'message_params' => [],
                ],
            ];
        }

        $probe = self::probePhpBinary($phpBinary, $validator, $hasFunction);

        // `php-fpm --version` · `php-cgi --version` 은 CLI 와 똑같이 정상 종료하고 같은 형태로
        // 버전을 찍으므로 probe 는 valid 로 본다. 그러나 그 바이너리는 artisan 을 받지 못해
        // 판정(subprocess)과 실행(전 태스크 실패)이 어긋난다 — 자동 탐색 경로는 CLI 우선 정렬로
        // 닫혔지만 운영자가 **명시**한 경로는 그대로였다. 같은 폴백으로 CLI 후보를 먼저 찾는다.
        $configuredIsNotCli = $probe['status'] === 'valid'
            && $probe['sapi'] !== null
            && $probe['sapi'] !== 'cli';

        // 지정 바이너리가 명확히 실패해도 다른 경로의 PHP 가 성립할 수 있다 —
        // 공통 경로 자동 탐색으로 한 번 더 확인한 뒤에만 inprocess 로 판정한다.
        if ($probe['status'] === 'invalid' || $configuredIsNotCli) {
            try {
                $found = $autoDetector();
            } catch (\Throwable) {
                $found = [];
            }

            if (is_array($found) && $found !== []) {
                $first = $found[0];

                // 비-CLI 를 대체하려는 경우에만 후보의 SAPI 를 따진다 — CLI 후보가 하나도 없으면
                // 운영자가 지정한 바이너리를 그대로 둔다(php-cgi 로만 동작하던 환경을 새로 막지 않는다).
                // autoDetect() 는 CLI 우선 정렬이므로 첫 후보가 비-CLI 면 CLI 는 없는 것이다.
                $candidateIsCli = ($first['sapi'] ?? null) === 'cli';

                if (! $configuredIsNotCli || $candidateIsCli) {
                    return [
                        'mode' => self::MODE_SUBPROCESS,
                        'blocked_functions' => [],
                        'php_binary' => (string) ($first['path'] ?? $phpBinary),
                        'php_probe' => [
                            'status' => 'valid',
                            'version' => $first['version'] ?? null,
                            'sapi' => $first['sapi'] ?? null,
                            'message' => 'auto-detected PHP binary: '.($first['path'] ?? ''),
                            'message_key' => 'php_probe_auto_detected',
                            'message_params' => ['path' => (string) ($first['path'] ?? '')],
                        ],
                    ];
                }
            }
        }

        return [
            'mode' => $probe['status'] === 'invalid' ? self::MODE_INPROCESS : self::MODE_SUBPROCESS,
            'blocked_functions' => [],
            'php_binary' => $phpBinary,
            'php_probe' => $probe,
        ];
    }
}
