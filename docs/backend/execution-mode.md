# 실행 모드 (Execution Mode) — 웹 요청 안의 커맨드 실행

웹 요청 안에서 Artisan 커맨드를 실행해야 하는 지점(인스톨러·코어/확장 업데이트·예약 작업)이 어떤 실행기를 쓸지 정하는 규약이다. `php artisan …` 을 자식 프로세스로 띄울 수 없는 호스팅(`disable_functions` 로 `exec`/`proc_open`/`shell_exec` 차단)에서도 같은 코드가 동작하도록, 실행 방식을 호출부가 아니라 **러너**가 정한다.

## TL;DR (5초 요약)

```text
1. 웹 도달 코드가 Artisan 을 실행할 때는 App\Execution\CommandRunner 를 경유한다 — php artisan 을 proc_open/exec 로 직접 스폰하지 않는다
2. 모드 결정 순서: 명시 인자 > 설정 system.execution_mode(auto/subprocess/inprocess) > ExecutionEnvironment::detect() 환경 감지
3. 실행 함수 가용성 판정은 ExecutionEnvironment::hasFunction() 하나다 (function_exists + disable_functions) — 인스톨러도 같은 클래스를 require_once 로 공유한다
4. database/schema/*.sql (schema:dump 산출물) 을 커밋하지 않는다 — migrate 가 덤프를 발견하면 mysql 바이너리를 스폰해 무CLI 환경에서 설치·업데이트가 깨진다
5. system.execution_mode 허용값은 EnumeratedSettingValues 한 곳이다 — 벌크(SaveSettingsRequest)·단건(UpdateSettingRequest) 저장 경로가 같은 표를 쓴다
```

## 목차

1. [배경](#1-배경)
2. [구성 요소](#2-구성-요소)
3. [모드 결정 순서](#3-모드-결정-순서)
4. [사용법](#4-사용법)
5. [규칙](#5-규칙)
6. [면제 표식](#6-면제-표식)
7. [두 러너의 등가 계약](#7-두-러너의-등가-계약)
8. [설정 키와 검증](#8-설정-키와-검증)
9. [측정 불가 계약 (시스템 리소스)](#9-측정-불가-계약-시스템-리소스)
10. [테스트](#10-테스트)
11. [관련 문서](#11-관련-문서)

---

## 1. 배경

공유 호스팅의 상당수는 `disable_functions` 로 프로세스 생성 함수를 막는다. 그 환경에서 `php artisan …` 을 서브프로세스로 띄우는 코드는 예외 한 줄로 멈추고, 그 경로는 **무CLI 환경에서만** 터지므로 개발 환경에서는 발견되지 않는다. 그래서 실행 방식을 호출부마다 분기하지 않고, 실행기(러너)를 하나 고른 뒤 모든 호출부가 그 러너를 경유한다.

두 실행기는 결과가 같아야 한다. `Artisan::call()`(in-process)과 `php artisan` 스폰(subprocess)은 같은 커맨드·같은 인자에 대해 같은 종료 상태를 돌려주고, 부수효과(DB·파일)도 같아야 한다 — 이 등가가 깨지면 호스팅에 따라 설치 결과가 달라진다.

## 2. 구성 요소

| 구성 요소 | 위치 | 역할 |
|---|---|---|
| `ExecutionMode` | `app/Execution/ExecutionMode.php` | 열거형 `auto` / `subprocess` / `inprocess`. `fromStringOrAuto()` 는 미지원 값을 `Auto` 로 폴백, `values()` 는 허용값 목록 |
| `CommandRunnerResolver` | `app/Execution/CommandRunnerResolver.php` | 모드 결정(§3) + 러너 생성. `detectionReport()` 로 판정 근거를 돌려준다 |
| `CommandRunner` (계약) | `app/Execution/Contracts/CommandRunner.php` | `run(string $command, array $parameters = []): CommandRunResult` + `mode()` |
| `SubprocessCommandRunner` | `app/Execution/SubprocessCommandRunner.php` | `php artisan …` 을 `proc_open` 으로 스폰 (현행 경로). 스폰 구성은 코어 업데이트의 기존 스폰 본문과 동형 |
| `InProcessCommandRunner` | `app/Execution/InProcessCommandRunner.php` | `Artisan::call()` + `BufferedOutput`. 두 러너 모두 `--no-interaction` 을 강제한다 (웹 요청 안에는 응답할 TTY 가 없다) |
| `CommandRunResult` | `app/Execution/CommandRunResult.php` | 종료 코드·출력 |
| `CommandExecutionException` | `app/Execution/Exceptions/CommandExecutionException.php` | 스폰 불가·실패. 메시지 키 + 치환 파라미터를 들고 다닌다 (`exceptions.execution.*`) |
| `ExecutionEnvironment` | `app/Support/ExecutionEnvironment.php` | 판정 SSoT. 프레임워크 의존이 없어 인스톨러가 `require_once` 로 공유한다 |

`ExecutionEnvironment` 가 제공하는 것:

| 메서드 | 설명 |
|---|---|
| `hasFunction(string $function): bool` | `function_exists` 와 `disable_functions` 를 함께 본다. 실행 함수 가용성 판정은 이 메서드 하나다 |
| `blockedFunctions(): array` | `exec`·`proc_open`·`shell_exec` 중 차단된 것 |
| `probePhpBinary(string $binary = 'php', …): array` | PHP 실행 파일 확인 (`valid` / `invalid` / `unavailable`) |
| `detect(…): array` | `{mode, blocked_functions, php_binary, php_probe}` — 인스톨러 요구사항 화면과 해석기가 같은 결과를 본다 |

## 3. 모드 결정 순서

```text
1. 호출부가 명시한 모드 (CommandRunnerResolver::resolveMode($requested))
2. 설정 system.execution_mode (auto / subprocess / inprocess — 기본값 auto)
3. auto 이면 ExecutionEnvironment::detect() 환경 감지
   - exec / proc_open / shell_exec 중 하나라도 차단 → inprocess
   - PHP 실행 파일 probe 가 명확히 실패 → inprocess
   - 그 외(불확실 포함) → subprocess   ← fail-safe: 현행 경로를 기본값으로 유지한다
```

불확실할 때 subprocess 로 두는 이유는 기본값 불변이다 — 이 기능이 들어가기 전과 같은 환경에서는 같은 경로를 타야 한다. inprocess 는 "스폰이 불가능하다" 가 확인된 환경에서만 고른다.

## 4. 사용법

```php
use App\Execution\CommandRunnerResolver;

$runner = app(CommandRunnerResolver::class)->resolve();   // 모드 결정은 해석기가 한다
$result = $runner->run('migrate', ['--force' => true]);    // Artisan::call 규약의 파라미터

if ($result->exitCode !== 0) {
    // $result->output 에 커맨드 출력
}
```

파라미터는 `Artisan::call()` 규약을 그대로 쓴다 — `'--force' => true` 는 플래그, `'--from' => '1.0.0'` 은 값 옵션, 배열 값은 반복 옵션, 숫자 키는 위치 인자. subprocess 러너가 이를 argv 로 바꾼다(`SubprocessCommandRunner::formatParameters()`).

모드를 강제해야 하는 호출부(예: 환경 감지 결과를 화면에 보여 준 뒤 운영자가 고른 값으로 실행)는 `resolveMode(ExecutionMode::Inprocess)` 처럼 명시 인자를 넘긴다. 그 외에는 넘기지 않는다 — 호출부가 모드를 고르기 시작하면 분기가 다시 흩어진다.

## 5. 규칙

| 금지 | 올바른 사용 |
|------|------------|
| 웹 도달 코드(컨트롤러·서비스·인스톨러·예약 작업)에서 `proc_open`/`exec` 등으로 `php artisan …` 을 직접 스폰 | `CommandRunnerResolver::resolve()->run($command, $parameters)` — 러너 경유 |
| `function_exists('proc_open')` 등으로 실행 함수 가용성을 직접 판정 | `ExecutionEnvironment::hasFunction('proc_open')` — `disable_functions` 까지 함께 본다 |
| `php artisan schema:dump` 산출물(`database/schema/*.sql`, `*.dump`)을 커밋 | 마이그레이션만 커밋한다. `migrate` 는 덤프가 있으면 `mysql` 바이너리를 스폰한다 |
| 허용값이 닫힌 설정 키(`system.execution_mode` 등)의 허용값 규칙을 한쪽 FormRequest 에만 부착 | `EnumeratedSettingValues` 표 하나를 벌크·단건 저장 경로가 공유 (§8) |
| `app/Console/**` 의 CLI 커맨드 안에서 러너를 경유 | 불필요 — 이미 CLI 안이므로 모드와 무관하다. 러너는 웹 요청 안의 실행만 다룬다 |

직접 스폰·`schema:dump` 산출물·`function_exists` 직접 판정은 정적 검사가 차단한다. 허용값 표 공유는 의미 판정이라 코드 리뷰에서 확인한다.

아직 SSoT 로 옮기지 못한 `function_exists` 직접 판정(인스톨러 보조 함수·코어 업데이트 CLI)은 `// audit:allow execution-function-availability-via-ssot <사유>` 표식을 남긴다 — 표식이 곧 이관 목록이다(§6 과 같은 규약).

## 6. 면제 표식

러너로 아직 옮기지 못한 기존 스폰 지점은 그 호출 근처에 표식을 남긴다.

```php
// audit:allow web-artisan-spawn-requires-runner 현행 subprocess 경로 — 후속 공정에서 러너로 이관
$process = proc_open($commandLine, $descriptors, $pipes, BASE_PATH, $env);
```

표식은 면제이자 **이관 대상 목록**이다 — 이관이 끝나면 표식을 지운다. 표식 없이 남은 직접 스폰은 정적 검사에서 오류로 멈춘다.

## 7. 두 러너의 등가 계약

같은 커맨드·같은 파라미터에 대해 두 러너는 같은 종료 코드를 돌려준다. `tests/Feature/Execution/CommandRunnerEquivalenceTest.php` 가 존재하는 커맨드(`list`)와 존재하지 않는 커맨드에 대해 이를 고정한다. DB 상태·생성 파일까지의 대조는 러너를 소비하는 태스크 목록(인스톨러 단계)이 생길 때 그 단위로 확장한다.

등가를 깨는 흔한 원인:

- 한 러너만 `--no-interaction` 을 붙인다 → 프롬프트가 있는 커맨드가 한쪽에서만 멈춘다
- subprocess 러너의 env 를 `$_ENV` 만으로 구성한다 → `variables_order` 에 `E` 가 없는 호스팅에서 `.env` 값이 자식에게 전달되지 않는다 (`getenv()` 를 기반으로 `$_ENV` 로 보완한다)
- 작업 디렉토리를 넘기지 않는다 → `base_path()` 상대 경로가 자식에서 어긋난다

## 8. 설정 키와 검증

| 키 | 기본값 | 허용값 | 비고 |
|---|---|---|---|
| `system.execution_mode` | `auto` | `auto` / `subprocess` / `inprocess` | `config/settings/defaults.json` `system` 카테고리. 프론트 노출(`expose`)은 관리자 화면 공정이 선언한다 |

허용값은 `App\Http\Requests\Settings\EnumeratedSettingValues` 한 곳에서 파생한다(`ExecutionMode::values()`). 설정 저장 경로는 둘이라 — 벌크 `POST /api/admin/settings` (`SaveSettingsRequest`) 와 단건 `PUT /api/admin/settings/{key}` (`UpdateSettingRequest`) — 한쪽에만 규칙을 두면 다른 쪽이 우회로가 되어 임의 문자열이 `system.json` 에 남는다. 런타임은 `fromStringOrAuto()` 가 `Auto` 로 폴백해 파손되지는 않지만, 저장 단계에서 막아 설정 파일에 뜻 모를 값이 기록되지 않게 한다. 같은 표에 `general.asset_url_mode` 도 올라 있다.

허용값이 닫힌 설정 키를 새로 만들면 이 표에 추가한다 — 두 FormRequest 를 따로 고치지 않는다.

## 9. 측정 불가 계약 (시스템 리소스)

CPU·메모리·디스크 사용률을 OS 명령(`wmic`·PowerShell·`nproc`·`free` 등)으로 수집하는 지점은 전부 `ExecutionEnvironment::hasFunction('exec')` 가드를 거친다. 수집 경로가 없으면 응답의 `percentage` 는 **`null`** 이다 — `0` 으로 채우면 실측 0% 와 구분되지 않는다. `GET /api/admin/dashboard/resources` · `GET /api/admin/settings/system-info` 가 이 계약을 따르며, 화면은 `null` 을 "측정 불가" 로 표기한다.

## 10. 테스트

| 파일 | 고정하는 것 |
|---|---|
| `tests/Unit/Support/ExecutionEnvironmentTest.php` | 판정 분기(함수 차단·probe 실패·불확실 폴백), 함수 집합 불변 |
| `tests/Unit/Execution/ExecutionModeTest.php` | 열거·안전 변환 |
| `tests/Unit/Execution/CommandRunnerResolverTest.php` | 결정 순서(명시 > 설정 > 감지), 기본값 `auto` 불변 |
| `tests/Feature/Execution/CommandRunnerEquivalenceTest.php` | 두 러너 종료 상태 등가 |
| `tests/Unit/Http/Requests/Settings/EnumeratedSettingValuesTest.php` | 허용값 표 ↔ Enum/상수 ↔ 카탈로그 기본값 정합 |
| `tests/Feature/Api/Admin/SettingsControllerTest.php` (`test_update_*_execution_mode`) | 단건 저장 경로의 허용값 게이트 |
| `tests/scenarios/no-cli-execution-mode.yaml` | 시나리오 매니페스트 (`disable_functions` 는 INI 라 E2E 재현 불가 — 종단 검증은 전용 환경 실측) |

## 11. 관련 문서

- [admin-settings-access.md](admin-settings-access.md) — 설정 값 접근 (`g7_core_settings()` 미러)
- [validation.md](validation.md) — FormRequest 규칙
- [console-confirm.md](console-confirm.md) — 콘솔 프롬프트 (웹 요청 안에서는 `--no-interaction` 으로 차단된다)
- [core-update-system.md](core-update-system.md) — 코어 업데이트의 스폰 경로
- [api/dashboard.md](api/dashboard.md) · [api/settings.md](api/settings.md) — 측정 불가 계약의 응답 필드
