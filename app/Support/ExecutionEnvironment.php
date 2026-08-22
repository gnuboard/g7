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
     * @param  string  $function  판정할 함수명 (예: 'shell_exec')
     * @return bool 호출 가능 여부
     */
    public static function hasFunction(string $function): bool
    {
        if (! function_exists($function)) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array($function, $disabled, true);
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
     * @return array{status: string, version: string|null, message: string} status 는 'valid'|'invalid'|'unavailable'
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
                'message' => 'exec unavailable — PHP binary probe skipped',
            ];
        }

        try {
            $result = $validator($binary);
        } catch (\Throwable $e) {
            // probe 자체가 예외로 죽으면 판정 불확실 — fail-safe 는 subprocess 쪽이다
            return [
                'status' => 'unavailable',
                'version' => null,
                'message' => 'PHP binary probe failed: '.$e->getMessage(),
            ];
        }

        return [
            'status' => ($result['valid'] ?? false) ? 'valid' : 'invalid',
            'version' => $result['version'] ?? null,
            'message' => $result['message'] ?? '',
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
     * - 함수 전부 가용 + PHP probe `valid` 또는 `unavailable`(불확실) → `subprocess`
     *
     * @param  string  $phpBinary  probe 대상 PHP 바이너리 (Laravel 측은 config('process.php_binary') 전달)
     * @param  callable|null  $validator  바이너리 검증 콜백 (테스트 주입용)
     * @param  callable|null  $hasFunction  함수 가용성 판정 콜백 (테스트 주입용)
     * @param  callable|null  $autoDetector  대체 바이너리 탐색 콜백 (테스트 주입용, 기본: PhpBinaryResolver::autoDetect)
     * @return array{mode: string, blocked_functions: array<int, string>, php_binary: string, php_probe: array{status: string, version: string|null, message: string}} 판정 결과와 근거
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
                    'message' => 'subprocess functions blocked — probe skipped',
                ],
            ];
        }

        $probe = self::probePhpBinary($phpBinary, $validator, $hasFunction);

        // 지정 바이너리가 명확히 실패해도 다른 경로의 PHP 가 성립할 수 있다 —
        // 공통 경로 자동 탐색으로 한 번 더 확인한 뒤에만 inprocess 로 판정한다.
        if ($probe['status'] === 'invalid') {
            try {
                $found = $autoDetector();
            } catch (\Throwable) {
                $found = [];
            }

            if (is_array($found) && $found !== []) {
                $first = $found[0];

                return [
                    'mode' => self::MODE_SUBPROCESS,
                    'blocked_functions' => [],
                    'php_binary' => (string) ($first['path'] ?? $phpBinary),
                    'php_probe' => [
                        'status' => 'valid',
                        'version' => $first['version'] ?? null,
                        'message' => 'auto-detected PHP binary: '.($first['path'] ?? ''),
                    ],
                ];
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
