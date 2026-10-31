<?php

/**
 * 그누보드7 웹 인스톨러 - 실행 모드(Execution Mode) 판정 헬퍼
 *
 * 설치 중 Artisan 커맨드를 어떤 방식으로 실행할지(subprocess / inprocess)를 정한다.
 * 판정 본체는 코어와 공유하는 App\Support\ExecutionEnvironment 가 SSoT 이고,
 * 이 파일은 인스톨러 고유의 두 입력 — Step 3 에서 운영자가 고른 `execution_mode`
 * (auto|subprocess|inprocess, 기본 auto) 와 `php_binary` — 를 그 판정에 연결한다.
 *
 * 규칙 (계획서 A-0):
 *   - 저장되는 것은 "선택" 이고 기본값은 auto 다. auto 는 매 판정마다 감지한다.
 *   - 운영자가 subprocess/inprocess 를 명시하면 감지를 건너뛰고 그 값을 쓴다.
 *   - inprocess 가 유효하면 Vendor 설치 방식은 bundled 로 고정된다 (A-4) —
 *     composer 는 proc_open 을 요구하므로 inprocess 환경에서 성립하지 않는다.
 *
 * 인스톨러는 Laravel 오토로드 밖의 순수 PHP 이므로 ExecutionEnvironment 는 config.php
 * 가 require_once 로 미리 로드해 둔다. 모든 함수는 function_exists 가드를 둔다 —
 * 어떤 엔드포인트가 어떤 순서로 include 해도 재선언 fatal 이 나지 않아야 한다 (R4).
 */

use App\Execution\ExecutionMode;
use App\Support\ExecutionEnvironment;

if (! function_exists('installerEnsureExecutionEnvironmentLoaded')) {
    /**
     * 판정 SSoT(App\Support\ExecutionEnvironment)를 이 파일이 직접 보장한다 — config.php 의 로드에 기대지 않는다.
     *
     * 파일 최상단이 아니라 사용 시점에 로드한다: check-configuration.php 처럼 config.php 보다 먼저
     * 이 파일을 include 하는 진입점이 있어 BASE_PATH 가 아직 없을 수 있다. 그 경우 이 파일의
     * 위치(public/install/includes)에서 프로젝트 루트를 역산한다.
     */
    function installerEnsureExecutionEnvironmentLoaded(): void
    {
        // 판정 클래스는 이 인스톨러와 같은 코드 트리에 있다 — BASE_PATH(설치 대상 루트, 테스트에서는 temp)가
        // 아니라 이 파일의 위치에서 찾는다. 트리 밖에 복제된 경우만 BASE_PATH 로 폴백.
        $root = dirname(__DIR__, 3);
        if (! is_file($root.'/app/Support/ExecutionEnvironment.php') && defined('BASE_PATH')) {
            $root = (string) BASE_PATH;
        }

        // 클래스마다 따로 판정한다 — config.php 가 ExecutionEnvironment 만 먼저 로드하는 진입점이 있어,
        // 하나로 묶어 단락하면 나머지가 로드되지 않은 채 남는다.
        foreach ([
            'App\\Support\\PhpBinaryResolver' => '/app/Support/PhpBinaryResolver.php',
            'App\\Support\\ExecutionEnvironment' => '/app/Support/ExecutionEnvironment.php',
            'App\\Execution\\ExecutionMode' => '/app/Execution/ExecutionMode.php',
        ] as $class => $relativePath) {
            if (! class_exists($class, false) && ! enum_exists($class, false)) {
                require_once $root.$relativePath;
            }
        }
    }
}

if (! function_exists('installerExecutionModes')) {
    /**
     * 운영자가 고를 수 있는 실행 모드 값.
     *
     * 어휘의 SSoT 는 코어 `App\Execution\ExecutionMode` 다 — 인스톨러가 정규화한 값은
     * 설치본의 `settings/system.execution_mode` 로 그대로 기록되고, 코어는 그 값을 같은
     * 열거형에서 파생한 허용값 표(`EnumeratedSettingValues`)로 검증한다. 여기서 어휘를
     * 리터럴로 복제하면 인스톨러가 코어가 거부할 값을 남길 수 있다.
     *
     * @return list<string> 허용 모드 문자열 목록
     */
    function installerExecutionModes(): array
    {
        installerEnsureExecutionEnvironmentLoaded();

        return ExecutionMode::values();
    }
}

if (! function_exists('installerNormalizeExecutionMode')) {
    /**
     * 입력값을 허용 어휘로 정규화한다 (미지원 값·빈 값은 auto).
     *
     * @param  mixed  $value  폼/상태에서 온 값
     * @return string auto|subprocess|inprocess
     */
    function installerNormalizeExecutionMode(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return in_array($value, installerExecutionModes(), true) ? $value : 'auto';
    }
}

if (! function_exists('installerDetectExecutionMode')) {
    /**
     * 환경을 감지해 실행 모드와 그 근거를 돌려준다.
     *
     * 판정 본체는 ExecutionEnvironment::detect() (코어 해석기와 동일). 여기에
     * 인스톨러가 추가로 보는 "동봉 의존성 묶음(vendor-bundle.zip) 존재 여부" 를 얹는다 —
     * inprocess 는 composer 를 돌릴 수 없어 묶음이 있어야만 설치가 성립한다 (A-1).
     *
     * 감지에는 PHP 바이너리 실행 probe 가 포함되므로 한 요청 안에서는 같은 바이너리에
     * 대해 한 번만 수행한다 (정적 캐시).
     *
     * @param  string  $phpBinary  probe 대상 PHP 바이너리 (Step 3 입력값, 기본 'php')
     * @return array{mode: string, blocked_functions: array<int, string>, php_binary: string, php_probe: array{status: string, version: string|null, sapi: string|null, message: string}, bundle_available: bool} 감지 결과
     */
    function installerDetectExecutionMode(string $phpBinary = 'php'): array
    {
        installerEnsureExecutionEnvironmentLoaded();

        static $cache = [];

        $phpBinary = $phpBinary !== '' ? $phpBinary : 'php';

        if (! isset($cache[$phpBinary])) {
            $detection = ExecutionEnvironment::detect($phpBinary);
            // 「번들이 쓸 수 있는가」는 파일 존재만으로 결정되지 않는다 — 푸는 데 ZipArchive 가
            // 필요하다 (13차 환경#4). 판정은 installerVendorBundleUsable() 단일 지점이 소유한다.
            $detection['bundle_available'] = installerVendorBundleUsable();
            $cache[$phpBinary] = $detection;
        }

        return $cache[$phpBinary];
    }
}

if (! function_exists('installerResolveExecutionMode')) {
    /**
     * 운영자 선택과 환경 감지를 합쳐 유효 실행 모드를 결정한다.
     *
     * 우선순위는 코어 해석기(CommandRunnerResolver)와 같다 — 명시 선택 > 감지.
     * (인스톨러에는 "저장 설정" 단계가 없다 — 설치 중에는 설정 파일이 아직 없다.)
     *
     * @param  array<string, mixed>  $config  설치 config (execution_mode · php_binary 를 읽는다)
     * @return array{mode: string, source: string, detection: array<string, mixed>|null} mode 는 subprocess|inprocess,
     *                                                                                   source 는 explicit|detected
     */
    function installerResolveExecutionMode(array $config): array
    {
        installerEnsureExecutionEnvironmentLoaded();

        $selected = installerNormalizeExecutionMode($config['execution_mode'] ?? 'auto');

        if ($selected !== 'auto') {
            return ['mode' => $selected, 'source' => 'explicit', 'detection' => null];
        }

        $phpBinary = (string) ($config['php_binary'] ?? 'php');
        $detection = installerDetectExecutionMode($phpBinary !== '' ? $phpBinary : 'php');

        return [
            'mode' => $detection['mode'] === ExecutionEnvironment::MODE_INPROCESS
                ? ExecutionEnvironment::MODE_INPROCESS
                : ExecutionEnvironment::MODE_SUBPROCESS,
            'source' => 'detected',
            'detection' => $detection,
        ];
    }
}

if (! function_exists('installerResolvedPhpBinary')) {
    /**
     * 설치 시작 시 확정하는 PHP 바이너리 — 운영자 명시 > 자동 탐색 결과 > 'php' (D35).
     *
     * 자동 감지는 PATH 의 php 가 없거나 버전 미달이면 공통 경로를 탐색해 다른 바이너리를 찾는데,
     * 그 경로가 실행에 쓰이지 않으면 판정(subprocess 가능)과 실행('php' 스폰 실패)이 어긋난다.
     *
     * @param  array<string, mixed>  $config  설치 config (php_binary)
     * @param  array{mode: string, source: string, detection: array<string, mixed>|null}  $resolution  installerResolveExecutionMode() 결과
     * @return string 실행에 쓸 PHP 바이너리 경로 또는 'php'
     */
    function installerResolvedPhpBinary(array $config, array $resolution): string
    {
        $explicit = trim((string) ($config['php_binary'] ?? ''));
        if ($explicit !== '' && $explicit !== 'php') {
            return $explicit;
        }

        $detected = trim((string) (($resolution['detection'] ?? [])['php_binary'] ?? ''));

        return $detected !== '' ? $detected : 'php';
    }
}

if (! function_exists('installerEffectiveExecutionMode')) {
    /**
     * 설치 상태(state.json)에 기록된 config 로 유효 실행 모드를 돌려준다.
     *
     * 설치 시작 시점에 install-process.php 가 판정을 한 번 내려 `execution_mode_resolved`
     * 로 박아 두면 그 값을 그대로 쓴다 — 모드는 진입 1회 결정, 실행 중 전환 금지 (R1).
     * 박힌 값이 없으면(레거시 state / 설치 전 화면) 그 자리에서 판정한다.
     *
     * @param  array<string, mixed>|null  $state  설치 상태 (null 이면 getInstallationState())
     * @return string subprocess|inprocess
     */
    function installerEffectiveExecutionMode(?array $state = null): string
    {
        installerEnsureExecutionEnvironmentLoaded();

        $state ??= function_exists('getInstallationState') ? getInstallationState() : [];

        $resolved = $state['execution_mode_resolved'] ?? null;
        if (in_array($resolved, [ExecutionEnvironment::MODE_SUBPROCESS, ExecutionEnvironment::MODE_INPROCESS], true)) {
            return $resolved;
        }

        $config = is_array($state['config'] ?? null) ? $state['config'] : [];

        return installerResolveExecutionMode($config)['mode'];
    }
}

if (! function_exists('installerCoerceVendorModeForExecution')) {
    /**
     * 유효 실행 모드에 맞게 Vendor 설치 방식을 보정한다 (A-4).
     *
     * inprocess 에서는 composer 실행 자체가 성립하지 않으므로 bundled 로 고정한다.
     * auto 도 bundled 로 내린다 — auto 는 "composer 가능하면 composer" 인데 그 판정을
     * 설치 도중에 다시 하면 한 설치 안에서 두 판정이 어긋날 수 있다.
     *
     * @param  string  $vendorMode  운영자가 고른 값 (auto|composer|bundled)
     * @param  string  $executionMode  유효 실행 모드 (subprocess|inprocess)
     * @return string 보정된 Vendor 설치 방식
     */
    function installerCoerceVendorModeForExecution(string $vendorMode, string $executionMode): string
    {
        installerEnsureExecutionEnvironmentLoaded();

        if ($executionMode === ExecutionEnvironment::MODE_INPROCESS) {
            return 'bundled';
        }

        return in_array($vendorMode, ['auto', 'composer', 'bundled'], true) ? $vendorMode : 'auto';
    }
}

if (! function_exists('installerStepwiseBlockedByVendorMode')) {
    /**
     * 이 조합에서 단계별 실행을 고를 수 없는가 (10차 #M3).
     *
     * 단계별 실행은 요청 하나가 태스크 하나만큼 산다 — `run-task.php` 가 자기 수명을
     * `STEPWISE_LOCK_STALE_SECONDS`(180초)로 잡고 들어온다. 그런데 `composer install` 은
     * 그보다 오래 걸리는 것이 정상이고, 그 구간은 러너를 거치지 않아 시간 예산이 올라가지도
     * 않는다. 그대로 두면 요청이 도중에 죽어 의존성이 반쯤 설치된 채 남는다.
     *
     * inprocess 는 해당 없다 — 그 모드는 Vendor 방식이 bundled 로 고정되므로(A-4) composer 가
     * 아예 돌지 않고, 단계별 실행이 유일하게 성립하는 진행 방식이다.
     *
     * 판정을 뷰의 `if` 로 두지 않는 이유는 화면과 서버가 같은 답을 써야 하기 때문이다 —
     * 화면만 막으면 저장된 선택이 그대로 흘러 R9(모드 분기 누락)의 재현이 된다.
     *
     * @param  string  $vendorMode  유효 Vendor 설치 방식 (installerCoerceVendorModeForExecution 결과)
     * @param  string  $executionMode  유효 실행 모드 (subprocess|inprocess)
     * @return bool 고를 수 없으면 true
     */
    function installerStepwiseBlockedByVendorMode(string $vendorMode, string $executionMode): bool
    {
        installerEnsureExecutionEnvironmentLoaded();

        if ($executionMode === ExecutionEnvironment::MODE_INPROCESS) {
            return false;
        }

        return $vendorMode !== 'bundled';
    }
}

if (! function_exists('installerCoerceInstallationModeForVendor')) {
    /**
     * 유효 Vendor 방식에 맞게 진행 방식을 보정한다 (10차 #M3).
     *
     * 화면이 비활성으로 막아도 저장된 값·직접 요청은 그대로 들어올 수 있으므로 서버가 같은
     * 판정을 다시 적용한다. 막힌 조합이면 기본 진행 방식(sse)으로 되돌린다.
     *
     * @param  string  $installationMode  요청된 진행 방식 (sse|polling|stepwise)
     * @param  string  $vendorMode  유효 Vendor 설치 방식
     * @param  string  $executionMode  유효 실행 모드
     * @return string 보정된 진행 방식
     */
    function installerCoerceInstallationModeForVendor(string $installationMode, string $vendorMode, string $executionMode): string
    {
        if ($installationMode !== 'stepwise') {
            return $installationMode;
        }

        return installerStepwiseBlockedByVendorMode($vendorMode, $executionMode) ? 'sse' : 'stepwise';
    }
}

if (! function_exists('installerResolveInstallationMode')) {
    /**
     * 요청된 진행 방식을 유효 실행 모드·Vendor 방식에 맞게 확정한다 (A-2 · R1 · 10차 #M3).
     *
     * 두 규칙이 한 지점에서 적용된다:
     *
     *  - inprocess 는 **단계별 실행으로 강제**한다. 한 요청 안에서 `Artisan::call` 을 연속
     *    호출하면 직전 설치가 바꾼 오토로드·서비스 프로바이더가 반영되지 않은 컨테이너로
     *    다음 태스크가 돈다 (A-2). 이 강제가 빠지면 SSE 워커 쪽 2차 방어(`installerSseWorkerRejected`)
     *    도 발화하지 않는다 — 그 판정의 조건이 「stepwise 인가」이기 때문이다.
     *  - subprocess + composer 조합에서는 단계별 실행을 내린다. 요청 하나가 180초를 사는데
     *    `composer install` 은 그보다 오래 걸리는 것이 정상이다 (#M3).
     *
     * 판정을 엔드포인트의 `if` 로 두면 그 조건이 뒤집혀도 어떤 테스트도 red 가 되지 않는다 —
     * 실제로 강제 분기는 저장소 전체에서 참조 0 이었다 (10차 #G1).
     *
     * @param  string  $requested  요청된 진행 방식 (sse|polling|stepwise, 그 밖은 sse)
     * @param  string  $executionMode  유효 실행 모드 (subprocess|inprocess)
     * @param  string  $vendorMode  유효 Vendor 설치 방식
     * @return array{mode: string, forced: bool, downgraded: bool} 확정 결과
     */
    function installerResolveInstallationMode(string $requested, string $executionMode, string $vendorMode): array
    {
        installerEnsureExecutionEnvironmentLoaded();

        $mode = in_array($requested, ['sse', 'polling', 'stepwise'], true) ? $requested : 'sse';

        if ($executionMode === ExecutionEnvironment::MODE_INPROCESS) {
            return ['mode' => 'stepwise', 'forced' => $mode !== 'stepwise', 'downgraded' => false];
        }

        $coerced = installerCoerceInstallationModeForVendor($mode, $vendorMode, $executionMode);

        return ['mode' => $coerced, 'forced' => false, 'downgraded' => $coerced !== $mode];
    }
}

if (! function_exists('installerExecutionModeRejection')) {
    /**
     * 명시 선택한 실행 방식이 이 서버에서 성립하는지 판정한다 (Step 3 저장 게이트 · A-4 대칭).
     *
     * A-4 는 Vendor 설치 방식에서 「지금은 CLI 가 없어도 composer 를 선택할 수 있고, 선택하면
     * 설치 도중에 실패한다」를 닫았다. 실행 방식 축에는 같은 게이트가 없어, 화면만 고를 수
     * 없게 막고 서버는 그대로 받았다 — 그러면 실패가 설치 중반(태스크 실행)에야 드러난다.
     * 저장 시점에 판정해 그 자리에서 사유를 보여 준다.
     *
     * `auto` 는 판정 대상이 아니다 — 감지가 정하므로 성립하지 않는 값이 될 수 없다.
     *
     * @param  string  $selected  정규화된 선택값 (auto|subprocess|inprocess)
     * @param  array<string, mixed>  $detection  installerDetectExecutionMode() 결과
     * @return string|null 불가 사유 lang 키, 성립하면 null
     */
    function installerExecutionModeRejection(string $selected, array $detection): ?string
    {
        if ($selected === ExecutionEnvironment::MODE_SUBPROCESS) {
            // 필수 함수가 하나라도 막혀 있으면 별도 프로세스를 띄울 방법이 없다.
            // PHP probe 실패는 여기서 막지 않는다 — 자동 탐색 폴백이 남아 있고, 그
            // 불확실은 계획 A-0 ③ 이 subprocess 로 두기로 한 축이다.
            return ($detection['blocked_functions'] ?? []) !== []
                ? 'error_execution_mode_subprocess_unavailable'
                : null;
        }

        if ($selected === ExecutionEnvironment::MODE_INPROCESS) {
            // inprocess 는 composer 를 돌릴 수 없어 동봉 묶음이 있어야만 설치가 성립한다.
            // 「있다」가 아니라 「쓸 수 있다」를 본다 — 푸는 데 ZipArchive 가 필요하다.
            return ($detection['bundle_available'] ?? false)
                ? null
                : 'error_execution_mode_inprocess_unavailable';
        }

        return null;
    }
}

if (! function_exists('installerExecutionModeReasons')) {
    /**
     * 감지 결과를 사람이 읽는 판정 근거 문장 목록으로 바꾼다 (W1·W2 의 배지/안내).
     *
     * @param  array<string, mixed>  $detection  installerDetectExecutionMode() 결과
     * @return array<int, string> 근거 문장 목록 (근거가 없으면 빈 배열)
     */
    function installerExecutionModeReasons(array $detection): array
    {
        $reasons = [];
        $blocked = $detection['blocked_functions'] ?? [];

        if ($blocked !== []) {
            $reasons[] = lang('execution_mode_reason_functions', ['functions' => implode(', ', $blocked)]);
        }
        if ($blocked === [] && (($detection['php_probe']['status'] ?? '') === 'invalid')) {
            $reasons[] = lang('execution_mode_reason_php_probe');
        }

        return $reasons;
    }
}

if (! function_exists('installerDetectionPhpBinary')) {
    /**
     * 재감지(Step 3)에 쓸 PHP 바이너리를 정하고, 되돌렸다면 그 사실을 함께 돌려준다.
     *
     * 형태 규칙을 어긴 경로는 설치 시점의 `getPhpBinary()` 도 기본 `php` 로 되돌리므로
     * 그 기준으로 판정하는 것이 옳다. 다만 **되돌렸다는 사실을 알리지 않으면** 위쪽 입력
     * 상자는 「허용되지 않는 경로」 빨강인데 아래 실행 방식 배지는 파랑 「일반 실행」 이 되어,
     * 한 화면이 서로 반대되는 두 판정을 동시에 보여 준다. 판정을 엔드포인트 안에 두면
     * 그 사실 전달이 지워져도 어떤 테스트도 red 가 되지 않으므로 이 파일이 소유한다.
     *
     * @param  string  $requested  운영자가 입력한 경로 (Step 3)
     * @return array{binary: string, substituted: bool} 판정에 쓸 경로와 대체 여부
     */
    function installerDetectionPhpBinary(string $requested): array
    {
        // 형태 규칙은 binary-path-policy 가 소유한다 — 이 파일을 단독으로 include 하는
        // 진입점(check-configuration.php)이 있어 로드를 여기서 보장한다.
        require_once __DIR__.'/binary-path-policy.php';

        $requested = trim($requested);
        $binary = $requested;

        if ($binary === '' || ($binary !== 'php' && ! installer_binary_path_shape_ok($binary))) {
            $binary = 'php';
        }

        return [
            'binary' => $binary,
            // 빈 입력은 "고르지 않았다" 이지 "되돌렸다" 가 아니다 — 알릴 것이 없다.
            'substituted' => $requested !== '' && $requested !== $binary,
        ];
    }
}

if (! function_exists('installerRequiresStepwiseToken')) {
    /**
     * 이 설치가 stepwise 토큰을 요구하는가.
     *
     * 단계별 실행은 세션이 아니라 발급 토큰으로 진행한다(R8) — [건너뛰고 계속]도 같은 토큰을
     * 요구해야 한다. 판정을 엔드포인트 안의 `if` 로 두면 그 조건이 뒤집혀도(예: `!==`) 어떤
     * 테스트도 red 가 되지 않는다: 토큰 **판정 함수**는 행위 테스트가 덮지만 그 함수를 부를지
     * 말지를 정하는 **조건**은 아무도 보지 않기 때문이다. 그 상태의 결과는 stepwise 설치의
     * 건너뛰기가 무인증이 되는 것이다 (9차 #G2).
     *
     * @param  array<string, mixed>  $state  설치 상태
     * @return bool 토큰 요구 여부
     */
    function installerRequiresStepwiseToken(array $state): bool
    {
        return ($state['installation_mode'] ?? null) === 'stepwise';
    }
}

if (! function_exists('installerStepwiseFailurePayload')) {
    /**
     * 단계별 실행이 잡히지 않은 예외로 끝났을 때의 응답 본문을 만든다.
     *
     * 이 봉투도 엔드포인트의 응답 계약(`outcome`)을 지켜야 한다 — 지키지 않으면 클라이언트가
     * 「알 수 없는 응답」 분기로 떨어지고, 같이 실린 예외 원문을 **실패 화면 본문**으로 그린다.
     * 개발자 표기(HTTP 코드·예외 원문)는 제목이 아니라 진단 상세에만 싣는다 (§12.0 W3).
     *
     * @param  string  $detail  예외 원문 (진단 상세)
     * @param  array<string, mixed>  $stateSummary  상태 요약 (읽지 못했으면 빈 배열)
     * @return array<string, mixed> 응답 본문
     */
    function installerStepwiseFailurePayload(string $detail, array $stateSummary = []): array
    {
        return [
            'success' => false,
            'outcome' => 'failed',
            'message_key' => 'error_unexpected_exception',
            'error_detail' => $detail,
            'state' => $stateSummary,
        ];
    }
}

if (! function_exists('installerStepwiseStateSummary')) {
    /**
     * 단계별 실행 응답이 싣는 상태 요약 (10차 #G9).
     *
     * 폴링 조회(`state-management.php?action=get`)와 **같은 실패·건너뜀 필드 집합**을 내보내야
     * 한다 — 각자 배열 리터럴을 두면 한쪽에만 추가된 필드가 그 진행 모드에서만 조용히 빠진다.
     * 집합 자체는 `installerClientFailureFields()` 가 소유하고, 이 함수는 그 집합에 진행 상태를
     * 두른다.
     *
     * 엔드포인트 안에 두면 형태·값이 무검사로 남는다 — run-task.php 는 최상위 스크립트라
     * require 로 부를 수 없어, 소스 대조로 「부른다」만 확인할 수 있었다.
     *
     * @param  array<string, mixed>  $state  설치 상태
     * @return array<string, mixed> 상태 요약
     */
    function installerStepwiseStateSummary(array $state): array
    {
        $failureFields = function_exists('installerClientFailureFields')
            ? installerClientFailureFields($state)
            : [];

        return array_merge(
            [
                'status' => $state['installation_status'] ?? 'not_started',
                'installation_mode' => $state['installation_mode'] ?? null,
                'execution_mode' => $state['execution_mode_resolved'] ?? null,
            ],
            $failureFields,
            ['last_updated' => $state['last_updated'] ?? null],
        );
    }
}

if (! function_exists('installerStepwiseTokenAccepted')) {
    /**
     * 단계별 실행 토큰이 유효한지 판정한다.
     *
     * run-task.php 는 인스톨러에서 Artisan 커맨드를 실행하는 유일한 창구다 — 이 판정이
     * 그 창구의 인증 전부이므로, 엔드포인트 안에 인라인으로 두면 판정이 뒤집혀도
     * 테스트가 알 수 없다(호출 여부를 보는 소스 대조는 `hash_equals` 를 `==` 로 바꾸는
     * 회귀를 잡지 못한다). 진행 모드 가드(installerStepwiseEndpointRejection)와 같은
     * 이유로 판정을 이 파일이 소유한다.
     *
     * 빈 값은 양쪽 다 거부한다 — 토큰이 아직 발급되지 않은 상태(`$expected === ''`)에서
     * 빈 토큰을 제시하면 통과하는 구멍이 생긴다.
     *
     * @param  string  $presented  요청이 제시한 토큰
     * @param  string  $expected  runtime.php 가 보관한 토큰
     * @return bool 통과하면 true
     */
    function installerStepwiseTokenAccepted(string $presented, string $expected): bool
    {
        if ($expected === '' || $presented === '') {
            return false;
        }

        return hash_equals($expected, $presented);
    }
}

if (! function_exists('installerStepwiseEndpointRejection')) {
    /**
     * 단계별 실행 엔드포인트(run-task.php)가 이 상태를 거부해야 하는지 판정한다.
     *
     * 거부 규칙은 SSE 워커 쪽(installerSseWorkerRejected)과 대칭이다 — 모드는 진입 1회
     * 결정이고 실행 중 전환하지 않는다 (R1). 한쪽만 고치면 그 입구가 우회로가 되므로
     * 두 판정을 같은 파일에 나란히 둔다.
     *
     * @param  array<string, mixed>  $state  설치 상태
     * @return array{outcome: string, message_key: string, message_params: array<string, scalar>|null, status: int}|null 거부 사유 (통과면 null)
     */
    function installerStepwiseEndpointRejection(array $state): ?array
    {
        if (($state['installation_mode'] ?? null) !== 'stepwise') {
            return ['outcome' => 'rejected', 'message_key' => 'error_stepwise_mode_mismatch', 'status' => 409];
        }

        $status = $state['installation_status'] ?? null;

        // completed 는 complete_flag 직후 config_cache 재개용으로 허용한다
        if (in_array($status, ['running', 'completed'], true)) {
            return null;
        }

        return [
            'outcome' => $status === 'aborted' ? 'aborted' : ($status === 'failed' ? 'failed' : 'rejected'),
            'message_key' => 'error_stepwise_not_running',
            // 자리표시자 치환값은 키를 정한 곳이 함께 돌려준다 — 호출부가 키 이름을 보고
            // 치환값을 되짚으면, 자리표시자를 가진 키가 하나 더 생길 때 그 특례가 조용히 새다.
            'message_params' => ['status' => (string) ($status ?? '')],
            'status' => 409,
        ];
    }
}

if (! function_exists('installerSseWorkerRejected')) {
    /**
     * SSE 워커(install-worker.php)가 이 상태를 거부해야 하는지 판정한다.
     *
     * 단계별 실행으로 확정된 설치는 run-task.php 만 태스크를 돌린다 — 이 입구로 들어오면
     * runInstallationTasks() 가 한 요청 안에서 전 태스크를 연속 실행해 A-2 가 피하려던
     * 컨테이너 오염·실행 시간 상한 문제가 재현된다.
     *
     * @param  array<string, mixed>  $state  설치 상태
     * @return bool 거부해야 하면 true
     */
    function installerSseWorkerRejected(array $state): bool
    {
        return ($state['installation_mode'] ?? 'sse') === 'stepwise';
    }
}

if (! function_exists('installerStepwiseTokenRejection')) {
    /**
     * 토큰 거부의 **사유**를 나눈다 (11차 #A10).
     *
     * 종전에는 「보관된 토큰이 없다」와 「제시한 토큰이 틀렸다」가 같은 문구로 끝났는데,
     * 두 상황의 조치가 정반대다 — 후자는 새로고침으로 복구되지만 전자(runtime.php 가
     * 지워졌거나 읽히지 않음)는 몇 번을 새로고침해도 같은 403 이다. 서버는 그 차이를
     * 알고 있으면서 버려 왔다.
     *
     * @param  string  $presented  요청이 제시한 토큰
     * @param  string  $expected  runtime.php 가 보관한 토큰
     * @return array{message_key: string, status: int}|null 거부 사유 (통과면 null)
     */
    function installerStepwiseTokenRejection(string $presented, string $expected): ?array
    {
        if ($expected === '') {
            // 보관본이 없다 — 새로고침이 아니라 설치를 다시 시작해야 한다.
            return ['message_key' => 'error_stepwise_token_missing', 'status' => 403];
        }

        if (installerStepwiseTokenAccepted($presented, $expected)) {
            return null;
        }

        return ['message_key' => 'error_stepwise_token_invalid', 'status' => 403];
    }
}

if (! function_exists('installerFinalizeRejection')) {
    /**
     * 설치 마무리 엔드포인트(finalize-env.php)가 이 요청을 거부해야 하는지 판정한다 (11차 #A1).
     *
     * 이 엔드포인트는 `.env` 에 완료 표식을 박고 runtime.php(DB 비밀번호·APP_KEY·stepwise
     * 토큰)와 상태 파일을 지운다 — 인스톨러에서 **가장 되돌릴 수 없는 동작**이다. 그런데
     * 진입 조건이 `installer_guard_finalize_or_410()` 하나뿐이라 메서드 제한도, 토큰 요구도,
     * 「설치가 끝났는가」 검사도 없었다. 그 상태에서 설치 진행 중에 이 주소가 한 번 열리면
     * (prefetch·스캐너·이전 탭) 마이그레이션이 반쯤 된 DB + 인스톨러 진입 불가가 남는다.
     *
     * 판정을 엔드포인트 밖에 두는 이유는 형제들과 같다 — finalize-env.php 는 최상위
     * 스크립트라 require 로 부를 수 없어, 안에 인라인으로 두면 조건이 뒤집혀도 소스 대조
     * 밖에서는 아무도 모른다.
     *
     * 완료 판정은 `complete_flag` 마커로 한다. `installation_status` 는 config_cache 재시도
     * 경로에서 갱신되지 않을 수 있어(11차 #A6 이 그 자체를 고치지만) 마커가 더 직접적이다.
     *
     * @param  array<string, mixed>  $state  설치 상태 (읽지 못했으면 빈 배열)
     * @param  string  $method  요청 메서드
     * @param  string  $presented  요청이 제시한 토큰
     * @param  string  $expected  runtime.php 가 보관한 토큰
     * @param  bool|null  $stateFileExists  상태 파일이 실제로 있는지 (null 이면 판정하지 않는다)
     * @return array{message_key: string, status: int}|null 거부 사유 (통과면 null)
     */
    function installerFinalizeRejection(array $state, string $method, string $presented, string $expected, ?bool $stateFileExists = null): ?array
    {
        if (strtoupper($method) !== 'POST') {
            return ['message_key' => 'error_finalize_method_not_allowed', 'status' => 405];
        }

        // 상태 파일이 이미 사라졌으면 finalize 가 한 번 끝난 것이다 — 멱등 재호출을 막지
        // 않는다(막으면 .env 머지가 실패한 뒤의 재시도 경로가 함께 닫힌다).
        //
        // 「사라졌다」는 호출자가 파일을 직접 보고 알려 준다 (13차 D10). 종전에는 `$state === []`
        // 로 판정했는데 `getInstallationState()` 는 파일 부재 시 **DEFAULT_INSTALLATION_STATE**
        // (비어 있지 않다)를 돌려주므로 이 분기는 실제 호출 경로에서 한 번도 참이 되지 않았다 —
        // 그 결과 finalize 를 다시 부르면 멱등 통과 대신 409(미완료)가 나갔다.
        if ($stateFileExists === false || $state === []) {
            return null;
        }

        $completed = in_array('complete_flag', (array) ($state['completed_tasks'] ?? []), true);

        if (! $completed) {
            return ['message_key' => 'error_finalize_not_completed', 'status' => 409];
        }

        if (! installerRequiresStepwiseToken($state)) {
            return null;
        }

        return installerStepwiseTokenRejection($presented, $expected);
    }
}

if (! function_exists('installerStartRejection')) {
    /**
     * 설치 시작(install-process.php)이 이 요청을 거부해야 하는지 판정한다 (11차 #A2·#A4).
     *
     * 두 가지를 함께 본다.
     *
     * ① **살아 있는 워커가 있으면 거절한다.** 종전에는 두 번째 탭이 상태를 통째로 덮어쓰고
     *    `started` 를 받은 뒤, 폴링 분기가 lock 획득에 실패해 **아무 신호 없이** 종료했다.
     *    결과는 두 탭 모두 정지 — 첫 탭은 모드가 바뀌어 409, 두 번째 탭은 아무도 진행시키지
     *    않는 `running` 을 무한 폴링한다. 거절하면 첫 탭의 설치가 그대로 이어진다.
     *
     * ② **진행 중인 stepwise 설치는 기존 토큰을 요구한다.** 이 엔드포인트는 토큰을
     *    **발급**하면서 자신은 어떤 토큰도 요구하지 않아, 무인증 요청 하나로 새 토큰을 받아
     *    [중단]·[초기화]·[건너뛰고 계속]의 토큰 게이트를 통째로 우회할 수 있었다. 동시에
     *    진행 중이던 탭의 토큰이 무효화돼 정상 설치가 403 으로 죽는다.
     *    첫 발급(아직 stepwise 가 아니거나 진행 중이 아닌 상태)은 종전대로 무토큰이다.
     *
     * @param  array<string, mixed>  $state  설치 상태
     * @param  bool  $workerAlive  활성 워커 생존 여부
     * @param  string  $presented  요청이 제시한 토큰
     * @param  string  $expected  runtime.php 가 보관한 토큰
     * @param  int  $lockRemaining  점유가 자동으로 풀리기까지 남은 초 (0이면 모름 — 종전 동작)
     * @return array{message_key: string, status: int, retry_after?: int}|null 거부 사유 (통과면 null)
     */
    function installerStartRejection(
        array $state,
        bool $workerAlive,
        string $presented,
        string $expected,
        int $lockRemaining = 0
    ): ?array {
        if ($workerAlive) {
            // 「다른 창에서 진행 중」이라고 **단정하지 않는다** (D7 · 현장 2026-09-12).
            //
            // 점유가 살아 있다는 것과 그 창이 존재한다는 것은 다른 사실이다. 치명 종료
            // (디스크 풀·OOM·시그널)는 해제 기록을 남기지 못하므로, 죽은 워커의 점유가
            // 예산이 끝날 때까지 남는다 — 현장에서는 그 예산이 600초였고, 그동안 화면은
            // 존재하지 않는 창을 가리키며 운영자에게 「그 창에서 이어서 하라」고 했다.
            // 유일한 출구는 [처음부터 다시](전부 폐기)였다.
            //
            // 우리가 아는 것은 「아직 예산 안이다」와 「언제 풀린다」뿐이므로 그것만 말한다.
            // 남은 시간을 함께 주면 화면이 기다렸다가 스스로 이어갈 수 있다.
            // 문구가 `:seconds` 를 가진다 — 매개변수 없이 내보내면 화면에 자리표시자가 그대로 나간다.
            $rejection = [
                'message_key' => 'error_installation_lock_held',
                'message_params' => ['seconds' => max(0, (int) $lockRemaining)],
                'status' => 409,
            ];
            if ($lockRemaining > 0) {
                $rejection['retry_after'] = $lockRemaining;
            }

            return $rejection;
        }

        if (! installerRequiresStepwiseToken($state)) {
            return null;
        }

        // 끝났거나 멈춘 설치의 재시작은 새 토큰을 발급받는 정상 경로다 — running 만 지킨다.
        if (($state['installation_status'] ?? null) !== 'running') {
            return null;
        }

        return installerStepwiseTokenRejection($presented, $expected);
    }
}

if (! function_exists('installerHasFunction')) {
    /**
     * 이 환경에서 그 PHP 함수를 호출할 수 있는가 (13차 환경#1).
     *
     * PHP 8 부터 `disable_functions` 로 막힌 함수는 함수 테이블에서 제거되므로, 호출하면
     * `Error: Call to undefined function` 이 난다 — `@` 접두는 이 오류를 막지 못한다
     * (`@` 는 진단 메시지를 억제할 뿐 Error 를 삼키지 않는다). 그래서 호스팅이 흔히 막는
     * 함수(`disk_free_space`·`set_time_limit`·`ini_set`·`putenv` …)는 호출 전에 이 판정을
     * 통과해야 한다.
     *
     * 판정 본체는 코어와 공유하는 `App\Support\ExecutionEnvironment::hasFunction()` 이다
     * (`function_exists` + `disable_functions` 목록을 함께 본다). 그 클래스를 로드할 수
     * 없는 극단적 배치에서만 `function_exists` 로 폴백한다.
     *
     * @param  string  $function  판정할 함수명
     * @return bool 호출 가능하면 true
     */
    function installerHasFunction(string $function): bool
    {
        try {
            installerEnsureExecutionEnvironmentLoaded();

            if (class_exists(ExecutionEnvironment::class, false)) {
                return ExecutionEnvironment::hasFunction($function);
            }
        } catch (Throwable) {
            // 판정 SSoT 를 로드하지 못했다 — 아래 폴백으로 내려간다.
        }

        // audit:allow execution-function-availability-via-ssot SSoT 로드 실패 시의 최후 폴백 (위 분기가 정상 경로)
        return function_exists($function);
    }
}

if (! function_exists('installerVendorBundleUsable')) {
    /**
     * 동봉 의존성 묶음(vendor-bundle.zip)으로 설치가 성립하는가 (13차 환경#4).
     *
     * 「파일이 있다」만으로는 부족하다 — 묶음을 푸는 데 `ZipArchive` 가 필요하고, 공식
     * `php:8.3-apache` / `php:8.3-fpm` 도커 이미지에는 `ext-zip` 이 기본으로 없다.
     * 파일 존재만 보면 Step 2 가 「CLI 없이 설치 진행」 파랑으로 통과시킨 뒤, inprocess 가
     * Vendor 방식을 bundled 로 고정하고(A-4) 번들 설치기가 그 자리에서 멈춘다 — 운영자는
     * 요구사항을 전부 통과하고서 설치 불가에 도달한다.
     *
     * 판정을 한 지점이 소유한다 — 소비처가 각자 `file_exists` 를 쓰면 한 곳만 고쳐도
     * 나머지가 그대로 낙관 판정을 낸다.
     *
     * @return bool 묶음이 있고 풀 수 있으면 true
     */
    function installerVendorBundleUsable(): bool
    {
        return file_exists(BASE_PATH.'/vendor-bundle.zip') && class_exists('ZipArchive');
    }
}

if (! function_exists('installerRequestIsHttps')) {
    /**
     * 이 요청이 HTTPS 로 도달했는가 (13차 환경#17).
     *
     * 판정이 두 벌이면 한쪽만 프록시를 고려하게 된다 — 실제로 요구사항 화면은
     * `X-Forwarded-Proto` 를 보는데 세션 쿠키 설정은 `$_SERVER['HTTPS']` 만 봐서, TLS 종단이
     * 앞단에 있는 표준 배치(로드밸런서·리버스 프록시·CDN)에서 secure 쿠키가 붙지 않았다.
     *
     * @param  array<string, mixed>  $server  $_SERVER 스냅샷
     * @return bool HTTPS 면 true
     */
    function installerRequestIsHttps(array $server): bool
    {
        $https = strtolower(trim((string) ($server['HTTPS'] ?? '')));
        if ($https !== '' && $https !== 'off') {
            return true;
        }

        $forwardedProto = strtolower(trim((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($forwardedProto !== '') {
            // 프록시 체인은 값을 쉼표로 잇는다 (`https, http`) — 맨 앞이 클라이언트 쪽이다.
            if (trim(explode(',', $forwardedProto)[0]) === 'https') {
                return true;
            }
        }

        return strtolower(trim((string) ($server['HTTP_X_FORWARDED_SSL'] ?? ''))) === 'on';
    }
}

if (! function_exists('installerCrossOriginRejection')) {
    /**
     * 상태를 바꾸는 요청이 다른 출처에서 왔는지 판정한다 (13차 보안#4).
     *
     * 인스톨러에는 CSRF 토큰 검증이 없다 — `verifyCsrfToken()` 은 정의만 있고 호출부가
     * 저장소 전체에 0건이었다. 그런데 세션 쿠키는 `SameSite=Lax` 라 top-level 폼 제출에는
     * 동반 전송되고, 엔드포인트가 읽는 커스텀 헤더(`X-Installer-Token` 등)는 단순 폼
     * 제출을 막지 못한다. 그래서 상태 변경 엔드포인트는 출처를 직접 확인한다.
     *
     * `Origin` 이 있으면 그것만 본다(브라우저가 위조할 수 없다). 없으면 `Referer` 로
     * 내려간다. 둘 다 없으면 통과시킨다 — 일부 프록시·보안 제품이 두 헤더를 모두
     * 제거하는데, 그 환경의 설치를 새로 막지 않는다.
     *
     * 포트는 비교하지 않는다 — 리버스 프록시가 Host 를 다시 쓰면서 포트만 어긋나는 배치가
     * 흔하다. 막으려는 것은 다른 호스트(evil.com)에서 온 요청이다.
     *
     * @param  array<string, mixed>  $server  $_SERVER 스냅샷
     * @return array{message_key: string, status: int}|null 거부 사유 (통과면 null)
     */
    function installerCrossOriginRejection(array $server): ?array
    {
        $host = strtolower(trim((string) ($server['HTTP_HOST'] ?? '')));
        if ($host === '') {
            return null;
        }
        $host = explode(':', $host)[0];

        $stated = trim((string) ($server['HTTP_ORIGIN'] ?? ''));
        if ($stated === '') {
            $stated = trim((string) ($server['HTTP_REFERER'] ?? ''));
        }

        if ($stated === '') {
            return null;
        }

        // `null` 은 불투명 출처(sandboxed iframe·data: 문서)다 — 동일 출처가 아니다.
        if (strtolower($stated) === 'null') {
            return ['message_key' => 'error_cross_origin_request', 'status' => 403];
        }

        $statedHost = parse_url($stated, PHP_URL_HOST);
        if (! is_string($statedHost) || $statedHost === '') {
            return ['message_key' => 'error_cross_origin_request', 'status' => 403];
        }

        if (strtolower($statedHost) === $host) {
            return null;
        }

        return ['message_key' => 'error_cross_origin_request', 'status' => 403];
    }
}

if (! function_exists('installerSseWorkerStateRejection')) {
    /**
     * 스트리밍 워커(install-worker.php)의 진입 전제를 판정한다 (13차 보안#5·F6).
     *
     * 형제 입구 `run-task.php` 는 `installerStepwiseEndpointRejection()` 으로 「이 입구의
     * 모드인가」와 「진행 중인가」를 함께 본다. 스트리밍 워커는 앞의 절반(stepwise 거부)만
     * 있었고 뒤의 절반이 통째로 없었다 — 그래서 상태가 `not_started` 여도, 설치 설정이
     * 저장되기도 전에도, 크로스오리진 `<img src=".../install-worker.php">` 한 번이
     * `runInstallationTasks()` 를 실행할 수 있었다(토큰 없음·CSRF 없음·GET 허용).
     *
     * 모드 축도 형제와 대칭으로 맞춘다 — 종전에는 「stepwise 이면 거부」라서
     * `installation_mode='polling'` 으로 확정된 설치를 이 입구가 몰 수 있었다. 이 입구의
     * 모드는 sse 이므로 그와 다른 모드는 전부 거부한다. 진행 모드 미기록(레거시 state)은
     * 종전대로 sse 로 본다.
     *
     * @param  array<string, mixed>  $state  설치 상태
     * @return array{message_key: string, message_params: array<string, scalar>|null, status: int}|null 거부 사유 (통과면 null)
     */
    function installerSseWorkerStateRejection(array $state): ?array
    {
        if (($state['installation_mode'] ?? 'sse') !== 'sse') {
            return ['message_key' => 'error_sse_mode_mismatch', 'message_params' => null, 'status' => 409];
        }

        $status = $state['installation_status'] ?? null;

        // completed 는 마지막 태스크 직후의 재연결 창이다 — 형제 판정과 같은 허용 집합.
        if (in_array($status, ['running', 'completed'], true)) {
            return null;
        }

        return [
            'message_key' => 'error_sse_not_running',
            'message_params' => ['status' => (string) ($status ?? '')],
            'status' => 409,
        ];
    }
}

if (! function_exists('installerSessionOwnsStepwiseInstall')) {
    /**
     * 이 세션이 진행 중인 설치를 시작한 세션인가 (13차 보안#3 — 절반).
     *
     * 단계별 실행 토큰은 Step 5 화면 HTML 에 실린다. 그런데 진행 중 설치가 있으면 세션이
     * 없는 요청도 Step 5 로 안내되므로(index.php 가 state 의 current_step 을 채택),
     * 무인증 GET 한 번으로 토큰이 유출된다. 그 통로 하나로 9~11차가 건 토큰 게이트 전부가
     * 우회된다.
     *
     * 소유자 표식은 설치 시작 지점(install-process.php)이 runtime.php(0600)와 세션 양쪽에
     * 심는다. 이 판정은 그 둘을 대조할 뿐이다 — 판정을 화면 안에 인라인으로 두면 조건이
     * 뒤집혀도 어떤 테스트도 red 가 되지 않는다.
     *
     * @param  array<string, mixed>  $session  $_SESSION 스냅샷
     * @param  array<string, mixed>|null  $runtime  readInstallerRuntime() 결과
     * @return bool 이 세션이 소유자면 true
     */
    function installerSessionOwnsStepwiseInstall(array $session, ?array $runtime): bool
    {
        $expected = (string) (($runtime['installer']['session_owner'] ?? null) ?? '');
        $presented = (string) ($session['installer_session_owner'] ?? '');

        if ($expected === '' || $presented === '') {
            return false;
        }

        return hash_equals($expected, $presented);
    }
}

if (! function_exists('installerProbeRateLimitExceeded')) {
    /**
     * 무인증 probe 엔드포인트의 호출 횟수 상한 (13차 보안#6·보안#7).
     *
     * 설치 창에는 인증이 없다. 그 사이 `?action=test-db` 는 임의 host:port 로 나가는 접속
     * 프록시이고 `?action=test-php-binary` 는 임의 절대경로를 `--version` 으로 실행한다 —
     * 둘 다 정상 설치에는 몇 번이면 충분한데, 상한이 없으면 내부망 스캐너·바이너리 열거기로
     * 쓸 수 있다.
     *
     * 카운터는 `storage/` 밑 파일에 둔다(세션은 쿠키를 버리면 초기화되므로 상한이 되지
     * 못한다). 기록에 실패하면 통과시킨다 — 상한 때문에 정상 설치가 막히는 쪽이 더 나쁘다
     * (요구사항 화면이 아직 통과하지 않아 storage 가 쓰기 불가일 수 있다).
     *
     * @param  string  $bucket  상한을 따로 세는 이름
     * @param  int  $limit  창 안에서 허용할 호출 수
     * @param  int  $windowSeconds  창 길이(초)
     * @param  bool  $clientScopeOnly  true 면 **전역** 창을 보지 않는다 — 무인증 원격자가
     *                                 공유 창을 소진해 운영자의 판정을 강등시키는 것을
     *                                 막아야 하는 버킷에 쓴다. 클라이언트별 창과 원격
     *                                 주소 창은 이때도 함께 본다 (총량 방어)
     * @return bool 상한을 넘었으면 true
     */
    function installerProbeRateLimitExceeded(string $bucket, int $limit = 60, int $windowSeconds = 300, bool $clientScopeOnly = false): bool
    {
        // 상한은 **클라이언트별**로 센다. 카운터 하나를 전 클라이언트가 공유하면 그 상한
        // 자체가 공격 수단이 된다 — 무인증 원격자가 `probe_exposure_sentinel` 창을 소진하면
        // 운영자의 storage 계열이 상시 `rate_limited` 로 떨어지고, 그러면 노출 자동 차단(D3)이
        // 운영자 수동 확인(D21)으로 **강등**된다. 화면상 이상이 없어 알아챌 방법도 없다.
        //
        // 클라이언트 식별은 세션 > 원격 주소 순이다. 신원을 갈아 끼우면 이 카운터는 새
        // 창을 얻으므로, 총량은 아래 전역 상한(넉넉한 배수)이 따로 막는다. 두 축이 각각
        // 「남의 창을 못 뺏는다」와 「전체가 무제한이 되지 않는다」를 맡는다.
        $clientKey = installerProbeRateLimitClientKey();

        if ($clientKey !== '') {
            $clientExceeded = installerProbeRateLimitCount($bucket.'|'.$clientKey, $limit, $windowSeconds);

            // ── 두 통을 함께 본다 (12차 G3 라운드) ────────────────────────────────
            //
            // 11차는 클라이언트 식별을 세션 기반으로 바꾸고 이 분기에서 전역 창을
            // 뺐다. 앞의 절반은 옳다 — 전역 창은 **남이 채울 수 있고**, 채워지면
            // 운영자의 storage 계열이 상시 `rate_limited` 로 떨어져 노출 자동
            // 차단(D3)이 수동 확인(D21)으로 강등된다.
            //
            // 그러나 뒤의 절반이 새 구멍을 냈다. `session_id()` 는 **요청하는 쪽이
            // 정하는 값**이다(쿠키를 안 보내면 매 요청이 새 세션, 아무 값이나 보내도
            // 그 값). 그래서 이 버킷들은 쿠키를 갈아 끼우는 것만으로 상한이 통째로
            // 사라졌다 — 11차 이전의 IP 키는 적어도 그 우회를 허용하지 않았으므로,
            // **외부 공격에 대해서는 오히려 약해진 상태**였다. 종전 주석이 적은
            // 「그 창은 브라우저 하나 단위로 성립한다」는 성립하지 않는다.
            //
            // 그래서 총량 방어를 **전역이 아니라 원격 주소로** 되돌린다. 세 성질이
            // 동시에 선다:
            //   · 운영자는 자기 세션 창(`$limit`)을 쓴다 — 남이 뺏지 못한다.
            //   · 쿠키를 갈아 끼우는 쪽은 같은 주소의 창(`$limit * 10`)에 걸린다.
            //   · 그 주소 창을 채워 남을 강등시키려면 **그 사람과 같은 egress IP**
            //     여야 한다. 전역 창처럼 지구 반대편에서 채울 수 없다.
            $addressKey = installerProbeRateLimitAddressKey();

            $addressExceeded = $addressKey !== ''
                && $addressKey !== $clientKey
                && installerProbeRateLimitCount($bucket.'|addr|'.$addressKey, $limit * 10, $windowSeconds);

            if ($clientScopeOnly) {
                return $clientExceeded || $addressExceeded;
            }

            $globalExceeded = installerProbeRateLimitCount($bucket, $limit * 10, $windowSeconds);

            return $globalExceeded || $clientExceeded || $addressExceeded;
        }

        return installerProbeRateLimitCount($bucket, $limit, $windowSeconds);
    }
}

if (! function_exists('installerProbeRateLimitClientKey')) {
    /**
     * 상한을 나눌 클라이언트 식별자를 돌려줍니다.
     *
     * 식별에 실패하면 빈 문자열 — 그때는 전역 카운터 하나로 되돌아간다(종전 동작).
     *
     * @return string 식별자 해시 (없으면 빈 문자열)
     */
    function installerProbeRateLimitClientKey(): string
    {
        // `session_status()` 로 가드하지 않는다. 이 함수를 실제로 부르는 액션
        // (`?action=requirements`)은 그 전에 `session_write_close()` 를 하므로 상태가
        // `PHP_SESSION_NONE` 이고, 종전 가드는 **언제나 거짓**이었다 — 즉 세션 축이 죽어
        // 클라이언트 식별이 사실상 `REMOTE_ADDR` 단독이었다. 앞단 프록시·CDN·LB 뒤
        // (이 점검이 대상으로 삼는 호스팅 계층)에서는 전 방문자가 프록시 IP 하나를
        // 공유하므로, 무인증 원격자가 창 안에서 상한만큼 호출하면 **운영자의 계열이
        // 상한 초과로 빠져** 노출 자동 차단이 수동 확인으로 강등된다.
        //
        // `session_id()` 는 write-close 뒤에도 같은 값을 돌려준다(실측). 그러므로 상태가
        // 아니라 **식별자 자체**를 본다 (11차 G3 라운드).
        $raw = (string) session_id();

        if ($raw === '') {
            $raw = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        }

        return $raw === '' ? '' : substr(hash('sha256', $raw), 0, 16);
    }
}

if (! function_exists('installerProbeRateLimitAddressKey')) {
    /**
     * 총량 방어를 나눌 **원격 주소** 식별자를 돌려줍니다.
     *
     * 형제 `installerProbeRateLimitClientKey()` 는 세션을 먼저 보는데, 그 값은 요청하는
     * 쪽이 고르는 값이라 신원을 갈아 끼우면 새 창을 얻는다. 이 함수는 그 축을 막으려고
     * **갈아 끼울 수 없는 것**만 본다.
     *
     * 프록시 헤더(`X-Forwarded-For` 등)는 보지 않는다 — 그것도 요청자가 정하는 값이라
     * 여기서 읽으면 이 함수의 존재 이유가 사라진다. 앞단 프록시 뒤에서 이 값이 모든
     * 방문자에게 같아지는 것은 **의도한 동작**이다: 그 배수 창(`$limit * 10`)은 같은
     * egress 를 쓰는 사람들끼리만 공유되고, 개별 운영자는 자기 세션 창을 따로 갖는다.
     *
     * @return string 식별자 해시 (없으면 빈 문자열)
     */
    function installerProbeRateLimitAddressKey(): string
    {
        $raw = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return $raw === '' ? '' : substr(hash('sha256', $raw), 0, 16);
    }
}

if (! function_exists('installerProbeRateLimitCount')) {
    /**
     * 버킷 하나의 호출 수를 세고 상한 초과 여부를 판정합니다.
     *
     * @param  string  $bucket  상한을 따로 세는 이름
     * @param  int  $limit  창 안에서 허용할 호출 수
     * @param  int  $windowSeconds  창 길이(초)
     * @return bool 상한을 넘었으면 true
     */
    function installerProbeRateLimitCount(string $bucket, int $limit, int $windowSeconds): bool
    {
        $path = installerProbeRateLimitStatePath();
        $now = time();

        // 읽기도 잠금을 잡는다. `file_put_contents` 는 `LOCK_EX` 를 얻기 **전에** 파일을
        // 자르므로, 무잠금 리더가 그 창에 들어오면 빈 내용을 읽고 카운터를 통째로 초기화한다
        // (상한이 조용히 사라진다). 형제 상태 파일들이 tmp+rename 을 쓰는 것과 같은 이유다.
        // 읽기와 쓰기는 **하나의 임계 구역**이어야 한다. 종전에는 공유 잠금으로 읽고
        // 잠금을 놓은 뒤 별도의 `LOCK_EX` 로 썼다 — 그 사이에 다른 프로세스가 끼어들면
        // 같은 값을 읽은 N 개 요청이 각자 `k+1` 을 써서 증가분이 유실된다. 그러면 상한이
        // **병렬화만으로 우회**되고, 상한이 지키려던 것은 무인증 파일 생성이므로 결과는
        // `storage/installer` 에 sentinel 이 무제한으로 쌓이는 것이다.
        $buckets = [];
        $handle = @fopen($path, 'c+b');
        $locked = false;

        if ($handle !== false) {
            $locked = (bool) @flock($handle, LOCK_EX);

            if ($locked) {
                $raw = (string) stream_get_contents($handle);
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $buckets = $decoded;
                }
            }
        }

        $entry = is_array($buckets[$bucket] ?? null) ? $buckets[$bucket] : [];
        $startedAt = (int) ($entry['started_at'] ?? 0);
        $count = (int) ($entry['count'] ?? 0);

        if ($startedAt <= 0 || ($now - $startedAt) >= $windowSeconds) {
            $startedAt = $now;
            $count = 0;
        }

        $count++;

        // 만료된 다른 버킷은 함께 정리한다 — 파일이 무한히 자라지 않게.
        foreach ($buckets as $name => $row) {
            if (! is_array($row) || ($now - (int) ($row['started_at'] ?? 0)) >= $windowSeconds * 4) {
                unset($buckets[$name]);
            }
        }
        $buckets[$bucket] = ['started_at' => $startedAt, 'count' => $count];

        // json-encode:allow — HTTP 응답이 아니라 내부 상한 카운터 파일이다(값은 정수뿐).
        // 이 파일은 config 보다 먼저 로드될 수 있어야 하므로 인스톨러 헬퍼에 의존하지 않는다.
        $encoded = (string) json_encode($buckets);
        $written = false;

        if ($handle !== false && $locked) {
            if (@rewind($handle) && @ftruncate($handle, 0)) {
                $written = @fwrite($handle, $encoded);
                @fflush($handle);
            }
        }

        if ($handle !== false) {
            if ($locked) {
                @flock($handle, LOCK_UN);
            }
            @fclose($handle);
        }

        if ($written === false || $written !== strlen($encoded)) {
            // 기록하지 못했다. 이 자리를 통과시키면 상한이 **가장 필요한 환경에서만** 사라진다
            // — `storage/` 권한을 아직 주지 않은 창(= 요구사항 화면이 빨간 그 상태)에서
            // 무인증 `test-db`(임의 host:port 접속)·`test-php-binary`(임의 절대경로 실행)가
            // 무제한이 된다. 그렇다고 막으면 정상 설치가 상한 때문에 멈춘다.
            //
            // 그래서 세션으로 내려간다. 세션은 쿠키를 버리면 초기화되므로 완전한 상한은 되지
            // 못하지만, 쿠키를 유지한 채 도는 단순 반복은 여기서 끊긴다 — 아무 상한도 없는
            // 것과는 다르다. 세션조차 없으면 그때는 통과시킨다(설치를 막지 않는다).
            return installerProbeRateLimitExceededInSession($bucket, $limit, $windowSeconds);
        }

        return $count > $limit;
    }
}
if (! function_exists('installerProbeRateLimitExceededInSession')) {
    /**
     * 파일에 기록하지 못했을 때의 상한 폴백 — 세션 카운터.
     *
     * 완전한 상한은 아니다(쿠키를 버리면 초기화된다). 그러나 파일 기록이 막힌 환경에서
     * 아무 상한도 두지 않으면 무인증 probe 엔드포인트가 그 창 동안 무제한이 되므로,
     * 「끊기지 않는 반복」만이라도 여기서 끊는다. 세션 자체가 없으면 통과시킨다 — 상한
     * 때문에 정상 설치가 막히는 쪽이 더 나쁘다는 판단은 그대로다.
     *
     * @param  string  $bucket  상한을 따로 세는 이름
     * @param  int  $limit  창 안에서 허용할 호출 수
     * @param  int  $windowSeconds  창 길이(초)
     * @return bool 상한을 넘었으면 true
     */
    function installerProbeRateLimitExceededInSession(string $bucket, int $limit, int $windowSeconds): bool
    {
        // 세션이 **닫혀 있으면 잠깐 다시 연다.**
        //
        // 종전에는 닫혀 있으면 곧장 통과시켰는데, 이 폴백을 가장 필요로 하는 경로가 바로
        // 세션을 닫고 들어온다 — 노출 점검(`?action=requirements`)은 느린 프로브 동안
        // 세션 잠금을 쥐지 않으려고 `session_write_close()` 를 먼저 부른다. 그래서 이
        // 폴백은 그 경로에서 **한 번도 세어 본 적이 없었고**, 「끊기지 않는 반복만이라도
        // 끊는다」는 선언이 사실이 아니었다 (15차 G3 라운드).
        //
        // 다시 여는 비용(짧은 잠금)은 이 폴백이 도는 조건 자체가 예외적(storage 쓰기 불가)
        // 이라 감수한다. 응답이 이미 나가기 시작했으면 열 수 없으므로 그때는 종전대로
        // 통과시킨다 — 상한 때문에 정상 설치가 막히는 쪽이 더 나쁘다는 판단은 그대로다.
        $reopened = false;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (headers_sent() || session_status() === PHP_SESSION_DISABLED) {
                return false;
            }

            if (@session_start() !== true) {
                return false;
            }

            $reopened = true;
        }

        $now = time();
        $key = 'installer_probe_rate';
        $buckets = is_array($_SESSION[$key] ?? null) ? $_SESSION[$key] : [];

        $entry = is_array($buckets[$bucket] ?? null) ? $buckets[$bucket] : [];
        $startedAt = (int) ($entry['started_at'] ?? 0);
        $count = (int) ($entry['count'] ?? 0);

        if ($startedAt <= 0 || ($now - $startedAt) >= $windowSeconds) {
            $startedAt = $now;
            $count = 0;
        }

        $count++;
        $buckets[$bucket] = ['started_at' => $startedAt, 'count' => $count];
        $_SESSION[$key] = $buckets;

        // 우리가 연 것이면 곧바로 되돌린다 — 호출부가 잠금을 쥐지 않으려고 닫아 둔
        // 상태였으므로, 세느라 연 잠금을 그대로 두면 그 회피가 무효가 된다.
        if ($reopened) {
            @session_write_close();
        }

        return $count > $limit;
    }
}

if (! function_exists('installerProbeRateLimitStatePath')) {
    /**
     * 레이트리밋 상태 파일 경로 (정리 주체가 이 경로를 공유한다).
     *
     * @return string 절대 경로
     */
    function installerProbeRateLimitStatePath(): string
    {
        return BASE_PATH.'/storage/installer-probe-rate.json';
    }
}

if (! function_exists('installerIniSet')) {
    /**
     * `ini_set()` 을 가용할 때만 호출한다 (13차 환경#1·환경#18).
     *
     * `ini_set` 은 cPanel/CloudLinux 계열 호스팅의 표준 차단 목록에 있고, 인스톨러는
     * 세션 설정·출력 버퍼·오류 표시를 이 함수로 조정한다 — 그 호출들은 대부분 응답 봉투가
     * 서기 전에 있어, 막힌 환경에서 무가드로 부르면 첫 화면부터 백지가 된다.
     *
     * 진입점(api/*.php)은 이 래퍼 대신 `if (installerHasFunction('ini_set')) { @ini_set(…); }`
     * 형태를 쓴다 — 그 파일들의 호출 **리터럴**을 고정하는 소스 게이트가 이미 있어서, 래퍼로
     * 감싸면 그 게이트가 배선을 못 보고 조용히 통과한다.
     *
     * @param  string  $option  ini 키
     * @param  string|bool|int|null  $value  설정값
     */
    function installerIniSet(string $option, string|bool|int|null $value): void
    {
        if (! installerHasFunction('ini_set')) {
            return;
        }

        @ini_set($option, $value);
    }
}

if (! function_exists('installerEffectiveTimeLimit')) {
    /**
     * 요청한 실행 시간과 **실제로 적용된 값** 중 작은 쪽을 돌려준다 (제품 결정 13차 환경#9).
     *
     * `set_time_limit()` 은 요청한 값을 항상 얻어내지 못한다 — `disable_functions` 로 막혀
     * 있거나, `Suhosin` 계열이 상한을 고정하거나, 호스팅이 ini 를 잠근 경우 호출은 조용히
     * 무시된다. 그런데 워커 lock 은 **선언한 값**을 그대로 시간 예산으로 기록하므로, 30초에
     * 끊기는 호스팅에서 600초를 선언하면 이미 죽은 워커의 예산이 최대 10분 남아 다음 요청이
     * 그동안 거짓 busy 를 받는다. 운영자에게는 화면이 멈춘 채 「다른 창에서 진행 중」만 보인다.
     * 그래서 선언은 언제나 「실제 적용치 이하」여야 한다.
     *
     * ini 값은 **인자로 받는다** — 함수 안에서 `ini_get()` 을 부르면 그 분기는 어떤 CI 에서도
     * 원하는 값으로 평가되지 않아 검사할 방법이 사라진다 (`installerNormalizeLogMessage` 와 같은 규율).
     *
     * 남은 한계: `php-fpm` 의 `request_terminate_timeout` 과 LiteSpeed 의
     * `lsapi_max_process_time` 은 **PHP 에서 읽을 수 없다**. 이 함수는 「`ini_get` 이 말하는
     * 실제 적용치」까지만 좁히며, FPM 이 그보다 먼저 프로세스를 죽이는 경우는 여전히 남는다
     * (계획서 §16.19.4). 이것을 완전한 해결로 읽지 말 것.
     *
     * @param  int  $requested  요청한 실행 시간(초)
     * @param  string|int|false|null  $iniValue  `ini_get('max_execution_time')` 의 결과
     * @return int 선언해도 되는 시간(초)
     */
    function installerEffectiveTimeLimit(int $requested, string|int|false|null $iniValue): int
    {
        // 값을 읽을 수 없으면 좁히지 않는다 — 「확인 불가」를 「0초」로 접으면 lock 선언이
        // 사라져 살아 있는 워커가 곧바로 stale 로 탈취된다.
        if ($iniValue === false || $iniValue === null || trim((string) $iniValue) === '') {
            return $requested;
        }

        $effective = (int) trim((string) $iniValue);

        // 0(무제한)·음수(일부 SAPI 표기)는 상한이 없다는 뜻이다 — CLI 가 그렇다.
        if ($effective <= 0) {
            return $requested;
        }

        return min($requested, $effective);
    }
}

if (! function_exists('installerSetTimeLimit')) {
    /**
     * `set_time_limit()` 을 가용할 때만 호출하고, **실제로 적용된 시간**을 돌려준다.
     *
     * 막힌 환경에서도 설치는 진행되어야 한다 (13차 환경#1) — 시간 상한을 못 늘리는 것과
     * 그 자리에서 치명 종료하는 것은 전혀 다른 결과다.
     *
     * 반환값은 워커 lock 에 선언할 시간 예산이다 (제품 결정 13차 환경#9). 호출부는 요청값이
     * 아니라 **이 반환값**을 선언해야 한다 — 근거는 `installerEffectiveTimeLimit()` 이 소유한다.
     *
     * @param  int  $seconds  허용을 요청할 실행 시간(초)
     * @return int 실제로 적용된 실행 시간(초) — lock 에 선언해도 되는 값
     */
    function installerSetTimeLimit(int $seconds): int
    {
        if (installerHasFunction('set_time_limit')) {
            @set_time_limit($seconds);
        }

        // 호출 뒤에 다시 읽는다 — 호출이 먹었는지 아닌지를 반환값으로 알 수 없기 때문이다.
        // `ini_get` 도 차단 대상이다 (바로 위 `set_time_limit` 과 같은 축인데 이 줄만 무가드였다).
        // 못 읽으면 `false` 를 넘긴다 — `installerEffectiveTimeLimit` 이 이미 「확인 불가」로
        // 다루어 요청값을 좁히지 않는다. 여기서 죽으면 stepwise 태스크가 **한 개도** 돌지 않는다.
        $iniValue = installerHasFunction('ini_get') ? ini_get('max_execution_time') : false;

        return installerEffectiveTimeLimit($seconds, $iniValue);
    }
}

if (! function_exists('installerParseByteSize')) {
    /**
     * php.ini 크기 표기(`256M`·`1G`·`-1`)를 바이트로 바꾼다 (13차 환경#8).
     *
     * `-1`(무제한)은 그대로 -1 로 돌려준다 — 0 으로 접으면 "무제한" 과 "확인 불가" 가
     * 구분되지 않는다. 해석할 수 없는 값은 null 이다.
     *
     * @param  string  $value  ini 표기 값
     * @return int|null 바이트 수 (무제한은 -1, 해석 불가는 null)
     */
    function installerParseByteSize(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMGkmg])$/', $value, $matches) !== 1) {
            return null;
        }

        $multipliers = ['k' => 1024, 'm' => 1024 * 1024, 'g' => 1024 * 1024 * 1024];

        return (int) ((float) $matches[1] * $multipliers[strtolower($matches[2])]);
    }
}

if (! function_exists('installerOutputConfirmsAlreadyApplied')) {
    /**
     * 확장 커맨드 출력이 「이미 설치/활성화됨」을 확인하는지 판정한다 (21차 #21-3).
     *
     * exit code ≠ 0 인 출력에서 이 판정이 참이면 태스크는 성공(건너뜀)으로 접힌다 —
     * 재시도 멱등성의 근거다. 그래서 판정은 좁아야 한다: 코어 커맨드의 실제 문구
     * (lang/{ko,en}/modules·plugins·templates.php)는 전부 그 구로 **문장이 끝나므로**
     * 줄 끝 앵커로 좁힌다. 무경계 부분문자열이면 의존성 충돌 실패(`… is already
     * installed at version 1.2, but … Aborting.`)까지 성공으로 뒤집힌다.
     *
     * @param  string  $outputText  커맨드 stdout+stderr 전문
     * @return bool 이미 적용된 상태의 확인이면 true
     */
    function installerOutputConfirmsAlreadyApplied(string $outputText): bool
    {
        if ($outputText === '') {
            return false;
        }

        // 두 계열을 모두 덮는다 (22차 N-1) — 커맨드 자체 선검사(`…이(가) 이미 설치되어
        // 있습니다.`)와 Manager 예외 경로(`이미 활성화된 템플릿입니다.`). 후자를 놓치면 KO
        // 로케일(기본값)에서 템플릿 재시도가 하드 실패가 된다. 모집단은
        // InstallerAlreadyAppliedJudgmentTest 가 lang 파일에서 도출해 전수 대조한다.
        // 중간 구간은 유한하게 (23차 1b) — 실제 문구의 중간은 「템플릿」·「모듈」 같은 짧은
        // 명사뿐이다. 무제한이면 서드파티 확장의 「이미 설치된 버전보다 낮은 버전입니다.」류
        // 실패 문장이 통과할 여지가 남는다.
        return preg_match(
            '/(?:already installed|already active|이미 설치되어 있습니다|이미 활성화되어 있습니다|이미 (?:설치|활성화)된 [^\n]{0,30}입니다)\.?\s*$/imu',
            $outputText
        ) === 1;
    }
}

if (! function_exists('installerExtensionSelectionRejection')) {
    /**
     * 확장 선택 저장(save-extensions)의 상태 게이트 (21차 #21-8).
     *
     * 태스크 목록은 요청마다 `selected_extensions` 에서 재계산된다(stepwise 는 태스크 1개당
     * 1요청). 진행 이력이 있는 설치에서 선택이 교체되면 — 실행 중이든, 실패해 [다시 시도]
     * 대기 중이든 — 이미 지나간 의존성 사전 검증 없이 새 확장이 편입되고, 빠진 확장은
     * 실패·건너뜀 기록도 없이 소멸하며, 진행률 분모가 도중에 바뀐다. 형제 엔드포인트
     * (run-task·install-worker·install-process)는 전부 상태를 본다 — 이 저장만 규율 밖이었다.
     *
     * 선택을 바꾸려면 먼저 [처음부터 다시](reset)로 이력을 정리해야 한다.
     *
     * @param  array<string, mixed>  $state  설치 상태
     * @return array{status: int, message_key: string}|null 거부면 상태 코드+사유 키, 허용이면 null
     */
    function installerExtensionSelectionRejection(array $state): ?array
    {
        if (in_array($state['installation_status'] ?? null, ['running', 'failed', 'aborted'], true)) {
            return ['status' => 409, 'message_key' => 'error_extension_selection_locked'];
        }

        return null;
    }
}
