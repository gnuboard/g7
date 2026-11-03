# 실행 모드 (Execution Mode) — 웹 요청 안의 커맨드 실행

웹 요청 안에서 Artisan 커맨드를 실행해야 하는 지점(인스톨러·코어/확장 업데이트·예약 작업)이 어떤 실행기를 쓸지 정하는 규약이다. `php artisan …` 을 자식 프로세스로 띄울 수 없는 호스팅(`disable_functions` 로 `exec`/`proc_open`/`shell_exec` 차단)에서도 같은 코드가 동작하도록, 실행 방식을 호출부가 아니라 **러너**가 정한다.

## TL;DR (5초 요약)

```text
1. 웹 도달 코드가 Artisan 을 실행할 때는 App\Execution\CommandRunner 를 경유한다 — php artisan 을 proc_open/exec 로 직접 스폰하지 않는다
2. 모드 결정 순서: 명시 인자 > 설정 system.execution_mode(auto/subprocess/inprocess) > ExecutionEnvironment::detect() 환경 감지
3. 실행 함수 가용성 판정은 ExecutionEnvironment::hasFunction() 하나다 (function_exists + disable_functions) — 판정 SSoT 와 호출부 가드는 다른 축이다: 차단될 수 있는 함수는 부르는 자리마다 가드가 필요하고 `@` 는 가드가 아니다 (5.1)
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
   - exec / proc_open / shell_exec 중 하나라도 차단 → inprocess (probe 는 수행하지 않는다)
   - PHP 실행 파일 probe 실패 → 공통 경로 자동 탐색
       · 후보를 찾으면  → subprocess (그 바이너리를 러너에 넘긴다)
       · 후보가 없으면  → inprocess
   - probe 성공 + SAPI 가 CLI 가 아님(fpm·cgi) → CLI 후보 재탐색
       · CLI 후보를 찾으면 → subprocess (그 바이너리로 교체)
       · 못 찾으면        → subprocess (probe 가 준 바이너리 유지)
   - 그 외(불확실 포함) → subprocess   ← fail-safe: 현행 경로를 기본값으로 유지한다
```

이 요약 블록은 §4 본문과 같은 규칙을 말한다 — 「probe 실패 = inprocess」로 줄여 적으면
자동 탐색 경로가 통째로 빠져, 여기만 읽고 분기를 짜는 후속 작업이 실제 판정과
어긋난 코드를 만든다.

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

판정이 환경 감지로 내려진 subprocess 면 `resolve()` 가 감지가 확인한 PHP 바이너리(자동 탐색 폴백이 찾은 경로 포함)를 `SubprocessCommandRunner` 생성자에 넘긴다 — PATH 의 `php` 는 실행 불가하고 비표준 경로에만 PHP 가 있는 호스팅에서 판정과 실행이 같은 바이너리를 본다. 명시·설정으로 고정된 subprocess 는 설정값 `process.php_binary` 를 그대로 쓴다.

### 4.0 소비처 — 예약 작업 러너

예약 작업 러너(`App\Scheduling\ScheduleRunner`)는 이 계약의 소비처이면서 **모드 판정에 기대지 않는** 유일한 지점이다. 웹 크론의 존재 이유가 "CLI 스폰 불가" 이므로 그 경로는 언제나 in-process 여야 한다 — 해석기에 모드를 명시해 그 사실을 코드에 남긴다.

```php
$this->runners->resolve(ExecutionMode::Inprocess)->run($command, $parameters);
```

러너가 넘기는 파라미터는 shell 형태 스케줄 이벤트에서 되뽑은 것이다. 프레임워크는 `Schedule::command()` 를 `"{php} {artisan} 커맨드 --옵션=값"` 문자열로 조립해 두는데, 그 문자열을 스폰할 수 없는 환경에서는 접두사를 걷어 내고 커맨드명 + 파라미터로 되돌려야 한다 (`ArtisanCommandExtractor`). 되뽑기가 만드는 파라미터 표기는 위 §4 의 `Artisan::call()` 규약과 같으므로, 두 러너 어느 쪽에 넘겨도 같은 의미로 실행된다.

되뽑기는 프레임워크 내부 표현에 의존하므로 **실패를 조용히 건너뛰지 않는다** — 건너뛰면 그 예약이 오류 없이 사라진다. 등록된 전 이벤트가 되뽑기에 성공하는지를 계약 테스트가 고정하고, Laravel 업그레이드로 표현이 바뀌면 그 테스트가 먼저 red 가 된다.

서버 크론(`schedule:run`)과 웹 러너를 함께 쓰는 실행 방식(`auto`·`both`)에서 두 경로는 **같은 실행 기록**을 쓴다. 웹 러너의 소급 창은 이벤트별 마지막 실행 시각을 하한으로 삼는데, 서버 크론이 그 시각을 남기지 않으면 크론이 이미 돌린 작업을 창이 「놓친 것」으로 보고 다시 돌린다 — 이벤트 자물쇠(R18)는 **동시** 실행만 막으므로 이 순차 중복을 막지 못하고, 정리 배치가 아닌 작업(정기 리포트 발송·마일리지 적립 등)은 두 번 돌면 결과가 달라진다. 그래서 `schedule:run` 이 내는 작업 완료·실패 이벤트(`ScheduledTaskFinished`·`ScheduledTaskFailed`)로 같은 기록을 남긴다(`App\Listeners\Scheduling\RecordServerCronEventRun`). 웹 러너 자신은 이 이벤트를 내지 않고 직접 기록한다.

웹 크론 주소와 방문 트리거의 최소 호출 간격은 판정과 호출 기록을 **한 캐시 잠금 안에서** 한다(`WebCronPolicy::claimInvocation()`). 따로 두면 같은 순간에 도착한 두 호출이 둘 다 통과한다. 잠금을 얻지 못한 호출은 간격 미도달로 답한다. 잠금은 `Cache::lock` 이라 프로세스·셸 함수를 쓰지 않는다(§5.1 의 차단 함수 축과 무관).

예약 작업의 호출 규약·응답 형태·소급 실행은 [api/system.md](api/system.md) 의 `GET /api/system/cron/run` 항목이 소유한다.

### 4.1 인스톨러 (Laravel 부팅 전)

인스톨러(`public/install/`)는 Laravel 오토로드 밖의 순수 PHP 라 코어 러너를 직접 쓰지 못한다. 대신 같은 두 전략을 한 파일에 둔 **인스톨러 러너** `public/install/includes/artisan-executor.php` 를 경유한다.

```php
$run = installerRunArtisan('module:install', ['sirsoft-board', '--vendor-mode' => 'bundled']);
// ['exit_code' => int, 'output' => string[], 'mode' => 'subprocess'|'inprocess']
```

| 전략 | 동작 |
|---|---|
| subprocess | 종전 `executeArtisanCommandSSE` 의 스폰 본문을 그대로 옮긴 것 — `php -d memory_limit=512M artisan …` 을 `exec()` 로 돌리고 출력을 파일로 받는다. Step 3 의 `php_binary` 입력을 쓴다 |
| inprocess | `vendor/autoload.php` + `bootstrap/app.php` 로 Laravel 을 부팅(요청당 1회, 콘솔 커널)한 뒤 코어 `InProcessCommandRunner` 로 `Artisan::call` |

실행 모드는 `public/install/includes/execution-mode.php` 가 정한다 — Step 3 선택 `execution_mode`(auto|subprocess|inprocess, 기본 auto) > 감지(`ExecutionEnvironment::detect`). 설치 시작(`install-process.php`) 시 한 번 판정해 `state.execution_mode_resolved` 에 박고 그 설치 동안 바꾸지 않는다. inprocess 가 유효하면 Vendor 설치 방식은 bundled 로 고정되고 진행 모드는 **stepwise** (요청 1개 = 태스크 1개, `public/install/api/run-task.php`)로 강제된다 — 한 요청 안에서 `Artisan::call` 을 연속 호출하면 직전 설치가 바꾼 오토로드·서비스 프로바이더가 반영되지 않은 컨테이너로 다음 태스크가 돈다.

subprocess 전략의 PHP 바이너리는 **운영자 명시(Step 3 `php_binary`) > 설치 시작 시 자동 탐색된 경로(`state.php_binary_resolved`) > `php`** 순이다. 자동 감지는 PATH 의 `php` 가 없거나 버전 미달이면 공통 경로를 탐색해 다른 바이너리를 고르는데, 그 경로를 실행이 쓰지 않으면 판정(subprocess 가능)과 실행(`php` 스폰 실패)이 어긋난다 — 코어 러너(`CommandRunnerResolver`)와 인스톨러(`getPhpBinary()`)가 같은 우선순위를 쓴다.

best-effort 태스크(번들 언어팩 설치·`config:cache`)의 실패는 `state.best_effort_failed_tasks` 에 기록되어 **지나간 태스크**로 셈한다 — completed 에만 의존하면 단계별 실행이 같은 태스크를 영원히 다시 뽑아 설치가 끝나지 않는다(`config_cache` 는 목록 최후미라 finalize 자체가 일어나지 않는다). 재시도(`install-process.php`)는 이 목록을 비워 다시 시도하고, 화면은 경고 표시로 지나간다.

단계별 실행의 워커 lock 은 파일 잠금(`flock`) 안에서 판정·점유해 새로고침 직후의 두 요청이 같은 태스크를 겹쳐 돌리지 못하게 하고, 태스크 도중 점유자가 바뀐 요청은 `InstallerWorkerLockLostException` 으로 물러나 `busy` 응답을 낸다(빈 본문으로 끊기면 브라우저가 네트워크 실패로 오분류한다). stale 기준은 180초 — 무CLI 실측 최대 태스크(vendor 추출 ≈15s)의 10배를 넘는 여유다.

인스톨러 태스크 함수·롤백(`rollback-functions.php`)은 전부 `installerRunArtisan()` 을 경유한다. 단위 테스트는 `$GLOBALS['g7_installer_artisan_executor_override']` seam 으로 스폰 없이 태스크 흐름(순서·재개·롤백·ENV 주입·모드 전파)을 검증한다.

진행 방식은 한 판정(`installerResolveInstallationMode()`)이 확정한다 — inprocess 는 stepwise 로 **강제**하고, subprocess + composer 조합에서는 stepwise 를 내린다. 단계별 실행은 요청 하나가 `STEPWISE_LOCK_STALE_SECONDS`(180초)를 사는데 `composer install` 은 그보다 오래 걸리는 것이 정상이라, 그 조합으로 시작하면 의존성이 반쯤 설치된 채 요청이 죽는다. 화면(Step 5 진행 방식 라디오)이 그 조합을 사유와 함께 비활성으로 막고, 저장된 값·직접 요청을 위해 서버가 같은 함수로 다시 적용한다.

러너를 거치지 않는 블로킹 구간도 워커 lock 에 「살아 있음」을 알려야 한다. 번들 추출(`extractVendorBundle`)은 설치에서 가장 오래 걸리는 태스크인데 한 번의 `extractTo` 안에는 emit 이 없어 heartbeat 가 멈춘다 — SSE·폴링 워커는 lock 에 15초만 선언하므로 그 사이 새로고침한 요청이 살아 있는 워커를 stale 로 보고 탈취해 **두 요청이 같은 `vendor/` 를 동시에 옮기고 푼다.** 그래서 추출은 항목을 묶음으로 나눠 풀고 묶음마다 진행 콜백을 부른다(호출부가 `heartbeat` 이벤트를 낸다 — 화면 변화 없이 점유만 갱신된다).

상태를 바꾸는 요청은 전부 같은 인증을 거친다. 단계별 실행은 세션이 아니라 발급 토큰으로 진행하므로, `state-management.php` 의 건너뛰기·**중단·초기화** 세 액션이 한 게이트(`requireStepwiseTokenOrReject()`)를 공유한다 — 건너뛰기 하나에만 걸어 두면 그보다 파괴적인 중단(진행 중 태스크 롤백)과 초기화(완료 기록 전소거)가 무인증으로 남는다.

진행 모드는 양쪽 입구가 서로를 거부한다 — `run-task.php` 는 stepwise 가 아닌 설치를, `install-worker.php`(SSE) 는 stepwise 로 확정된 설치를 409 로 돌려보낸다. 한쪽만 막으면 stepwise 설치에서 스트리밍 워커를 직접 열어 전 태스크를 한 요청 안에서 in-process 로 돌리는 우회로가 남는다.

설치 완료 표식은 두 개다 — `complete_flag` 태스크가 만드는 `storage/app/g7_installed` 락 파일과, 그 뒤 `.env` 에 머지되는 `INSTALLER_COMPLETED`. 인스톨러 흐름이 실제로 끝나는 시점은 뒤쪽이므로, 그 사이 창에서도 돌아야 하는 엔드포인트는 완화 가드(`installer_guard_finalize_or_410()`)를 쓴다. 소비자는 여섯이다: 마무리 3곳(`finalize-env` · `state-management` · `run-task`)과 **복구 진입점 3곳**(`install-process`(재시작·재시도) · `install-worker`(SSE 재연결) · `index.php`(새로고침)). 복구 쪽을 엄격 가드로 두면 그 창에서 무언가 어긋났을 때 세 입구가 전부 410 이라 운영자가 실패 화면에서 나갈 수 없다.

조회 응답의 실패·건너뜀 필드는 `installerClientFailureFields()`(`installer-state.php`)가 만든다 — 폴링(`state-management.php?action=get`)과 단계별 실행(`run-task.php` 의 상태 요약)이 같은 집합을 내보내야 하고, 각자 배열 리터럴을 두면 한쪽에만 추가된 필드가 그 진행 모드에서만 조용히 빠진다(치환값 누락 → `:errors` 원문 노출이 그 형태였다).

상태 전이에는 우선순위가 있다. **중단이 확정된 설치는 뒤이은 태스크 실패로 「실패」가 되지 않는다** — 중단은 도는 태스크가 있을 때만 가능하고 그 태스크는 `ignore_user_abort(true)` 로 끝까지 가므로, 마지막 태스크가 실패하면 상태만 뒤집혀 [중단] 을 누른 운영자에게 실패 화면과 [다시 시도] 가 뜬다. 같은 이유로 **best-effort 계약은 예외 경로에도 적용된다** — `complete_flag` 뒤에 도는 `config_cache` 의 예외가 이미 기록된 완료를 실패로 덮으면 안 된다(`installerTaskIsBestEffort()` 가 태스크 목록에서 판정한다).

롤백 디스패치(`rollbackTask()`)와 수동 복구 안내(`getManualCommands()`)의 모집단은 `buildInstallationTaskList()` 전수다. 빠진 태스크는 「알 수 없는 작업」으로 떨어져, 롤백할 것이 없는 단계의 실패·중단에 거짓 「수동 정리 필요」가 표시된다. DB 태스크 실패의 롤백은 **실패한 그 태스크의 롤백**으로 디스패치한다 — `db_seed` 실패를 `migrate:rollback` 으로 되돌리면 같은 실패가 「중단으로 끝났을 때」와 다른 DB 상태를 남긴다.

「다른 워커가 살아 있는가」는 `installerActiveWorkerIsAlive()` 한 지점이 판정한다(점유자가 선언한 `worker_stale_after` 존중). 이 판정을 두 곳에서 다르게 하면 한쪽은 물러나고 다른 쪽은 그 워커의 완료 마커를 지워, 다음 요청이 `db_cleanup` 을 다시 뽑아 **방금 만든 테이블을 DROP** 한다. 선언과 요청 수명도 함께 움직인다 — 스폰 구간은 수명을 먼저 올린 뒤 그 값을 lock 에 알린다(선언만 올리면 죽은 점유가 그만큼 남아 다음 요청이 busy 를 받는다).

`auto` Vendor 모드의 번들 폴백은 `composer_check` 가 내린 판정(`state.composer_available`)을 그대로 쓴다 — 경로 문자열이 비어 있지 않은지만 보면 그 판정은 언제나 참이라, exec 는 열려 있고 composer 만 없는 호스팅에서 폴백이 발동하지 않는다. 그 태스크는 best-effort 도 skippable 도 아니라 재시도해도 같은 자리에서 막힌다.

[건너뛰고 계속]은 건너뛴 확장에 **의존하는** 확장까지 함께 지나간다(아직 실행 전인 것). 의존성 사전 검증은 설치 시작 전 한 번만 판정하므로, 그 뒤의 건너뛰기는 그 판정을 무효로 만든다. 이미 설치가 끝난 의존 확장은 되돌릴 수 없으므로 `state.dependency_skip_warnings` 와 진행 기록에 남긴다.

#### 인스톨러 다국어는 별개 채널이다

인스톨러 문구는 `public/install/lang/{ko,en}.php` 에 산다. 이름이 닮았을 뿐 코어 `lang/**` 과 **다른 채널**이며, 코어 다국어의 어느 절차도 여기에 적용되지 않는다.

| 축 | 인스톨러 lang |
|---|---|
| 로더 | Laravel 부팅 **전**이라 `__()` 가 아니라 인스톨러 자체 `lang()` |
| 번들 언어팩 | **대상 아님** — 일본어 등 번들 팩 빌드는 이 디렉토리를 보지 않는다 |
| 다국어 검증 절차 | 스코프가 `**/lang/ko/*.php` 라 이 파일들을 매칭하지 않는다 (돌려도 대상 0 건으로 통과한다) |
| 게이트 | `php vendor/bin/phpunit tests/Unit/Installer --filter=InstallerLanguageParityTest` |

그 게이트가 고정하는 것: 로케일 간 키 집합·자리표시자 일치, 중괄호 표기 금지, 코드가 리터럴로 부르는 키의 정의 여부, 점 경로 키의 중첩 해석, 그리고 **지원 언어 상수(`SUPPORTED_LANGUAGES`)와 실제 배포되는 `lang/*.php` 목록의 대조**다.

서버 `lang()` 은 평면 조회이고 화면 스크립트는 점 경로를 따라 내려간다 — 두 층의 규칙이 다르므로 **점 경로 키는 화면 스크립트에서만** 부른다. PHP 에서 부르면 번역 대신 키 문자열이 그대로 출력된다. 자리표시자 치환도 한 지점(`lang(key, replace)`)을 지난다 — 문구를 받아 온 뒤 문자열 치환으로 채우면 같은 자리표시자가 두 번 나오는 문구에서 한쪽만 채워진다.

로케일을 늘릴 때는 `lang/<code>.php` 파일과 `public/install/includes/config.php` 의 `SUPPORTED_LANGUAGES` 를 **함께** 갱신한다. 한쪽만 바꾸면 번역 배열이 비어 화면 전체가 키 문자열이 되거나, 파일이 있어도 아무도 그 언어를 고를 수 없다.

실행 모드가 관여하는 지점(`vendor_mode`·`execution_mode` 를 읽는 파일)은 손으로 열거하지 않고 `InstallerExecutionModeTouchpointCoverageTest` 가 소스 스캔으로 도출한다 — 새 지점이 보정 함수(`installerCoerceVendorModeForExecution`·`vendorModeFromState`·`installerNormalizeExecutionMode` 계열)를 거치지 않으면 red 가 된다. Step 3 의 "bundled 를 골랐는데 Step 5 가 composer 를 검사해 설치가 멈춘" 과거 사고가 그 형태였다.

### 4.2 소비처 — 코어 업데이트 웹 오케스트레이터

관리자 화면의 코어 업데이트(`App\CoreUpdate\CoreUpdateOrchestrator`)는 해석기를 주입받아
업그레이드 스텝·번들 확장 단계를 러너로 돌린다. 모드 자체는 해석기의 판정을 그대로 따르지만,
**스폰 실패 모드만은 그 단계에 한해 `fallback` 으로 강제한다**(`SPAWN_FAILURE_MODE`).

이유는 판정의 전제가 웹에서 성립하지 않기 때문이다. `CoreUpdateService::runUpgradeSteps()` 의
stale 메모리 가드는 `config('app.version')` 이 목표 버전보다 낮으면 「부모가 구버전 코드를 들고
있다」로 보고 배포 기본값(`abort`)에서 핸드오프를 던진다. CLI 는 자식 프로세스 env 에
`APP_VERSION` 을 실어 그 판정을 피한다. 그런데 웹에서는 이 단계가 **파일 적용과 다른 요청**이라
이미 신버전 코드로 부팅된 프로세스이고, `.env` 의 `APP_VERSION` 은 마무리 단계까지 옛 값으로
남는다 — 강제하지 않으면 매 요청이 같은 판정을 반복해 핸드오프가 상한까지 소진되고
**정상 적용된 코어가 되돌려진다.**

강제 채널은 둘이다.

| 채널 | 대상 |
|---|---|
| `config(['app.update.spawn_failure_mode' => …])` | in-process 러너 (같은 프로세스가 그 설정을 읽는다) |
| `$_ENV` · `$_SERVER` | 서브프로세스 러너가 띄우는 자식 |

`putenv` 는 쓰지 않는다 — 그 값은 프로세스 수명에 남아 같은 워커의 **다음 요청**까지 모드를
바꾼다.

## 5. 규칙

| 금지 | 올바른 사용 |
|------|------------|
| 웹 도달 코드(컨트롤러·서비스·인스톨러·예약 작업)에서 `proc_open`/`exec` 등으로 `php artisan …` 을 직접 스폰 | `CommandRunnerResolver::resolve()->run($command, $parameters)` — 러너 경유. 인스톨러 안에서는 `installerRunArtisan($command, $parameters)` (§4.1) |
| `function_exists('proc_open')` 등으로 실행 함수 가용성을 직접 판정 | `ExecutionEnvironment::hasFunction('proc_open')` — `disable_functions` 까지 함께 본다 |
| `php artisan schema:dump` 산출물(`database/schema/*.sql`, `*.dump`)을 커밋 | 마이그레이션만 커밋한다. `migrate` 는 덤프가 있으면 `mysql` 바이너리를 스폰한다 |
| 허용값이 닫힌 설정 키(`system.execution_mode` 등)의 허용값 규칙을 한쪽 FormRequest 에만 부착 | `EnumeratedSettingValues` 표 하나를 벌크·단건 저장 경로가 공유 (§8) |
| `app/Console/**` 의 CLI 커맨드 안에서 러너를 경유 | 불필요 — 이미 CLI 안이므로 모드와 무관하다. 러너는 웹 요청 안의 실행만 다룬다 |

직접 스폰·`schema:dump` 산출물·`function_exists` 직접 판정은 정적 검사가 차단한다. 허용값 표 공유는 의미 판정이라 코드 리뷰에서 확인한다.

아직 SSoT 로 옮기지 못한 `function_exists` 직접 판정은 그 자리에 사유를 주석으로 남긴다 — 그 주석이 곧 이관 목록이다(§6 과 같은 규약). 코어 업데이트 CLI·인스톨러의 이관은 끝났고, 남은 두 자리는 **원리상 이관할 수 없는 자리**다: 판정 SSoT 를 `require` 하지 않는 인스톨러 파일과, 그 SSoT 로드 자체가 실패했을 때의 최후 폴백이다. 그 두 줄을 지우면 SSoT 가 없는 환경에서 인스톨러가 첫 화면부터 뜨지 않는다.

### 5.1 차단 함수는 호출부마다 가드가 필요하다

앞 절의 규칙("가용성 판정은 한 지점이 소유한다")과 **다른 축**이다. 판정을 어디서 하느냐가 아니라, **차단될 수 있는 함수를 부르는 자리**마다 그 판정을 거치느냐를 본다. 판정 SSoT 가 완벽해도 호출부 하나가 그것을 지나치지 않으면 그 요청은 그 자리에서 죽는다.

호스팅이 흔히 막는 함수는 `exec`·`proc_open`·`shell_exec`·`popen`·`ini_get`·`ini_set`·`set_time_limit`·`putenv`·`error_log`·`posix_*` 처럼 실행·환경 제어 계열이다. 웹 요청 안에서 이들을 부르는 지점은 코어와 인스톨러 양쪽에 있으며, 판정 없이 부르면 그 엔드포인트가 통째로 치명 종료한다.

**짝을 함께 본다.** 가드 축을 여는 함수에 짝이 있으면 한쪽만 다루는 것이 곧 사각이다 — `ini_set` 은 오래 가드 대상이었는데 짝인 `ini_get` 은 빠져 있었고, 그 무가드 호출이 하필 **가용성 판정 자신**(`disable_functions` 목록을 읽는 자리)에 있었다. 판정이 죽으면 「가드가 있으니 안전하다」고 선언한 지점이 전부 동시에 무너지므로, 첫 화면조차 뜨지 않아 사유를 알릴 방법도 사라진다.

**진단 채널도 대상이다.** `error_log` 는 상태 저장이 실패했을 때 사유가 남는 마지막 통로이자 세션 부팅의 최상위 코드에서 불린다. 무가드로 두면 그것이 막힌 서버에서 첫 요청이 백지가 되고, 그 사유를 남길 채널이 바로 그것이라 흔적조차 남지 않는다.

| 금지 | 올바른 사용 |
|------|------------|
| 차단될 수 있는 함수를 판정 없이 직접 호출 | 호출 직전에 가용성 판정을 거치고, 불가하면 그 기능만 건너뛰거나 사유를 담은 응답으로 끝낸다 |
| `@` 를 붙여 두고 「막혀 있어도 지나간다」고 가정 | `@` 는 가드가 아니다 (아래) |
| 가용성 판정 결과를 한 번 계산해 두고 다른 함수의 호출에 재사용 | 함수마다 따로 판정한다 — `disable_functions` 는 함수 단위 목록이다 |
| 대체 경로 없이 판정만 붙여 조용히 아무 일도 하지 않게 두기 | 대체 경로가 없으면 그 사실을 운영자가 읽을 수 있는 형태로 남긴다 |

`@` 가 가드가 아닌 이유는 PHP 8 의 동작 때문이다. `disable_functions` 로 막힌 함수는 **함수 테이블에서 제거**되므로 호출은 경고가 아니라 `Error`(`Call to undefined function …`)가 되고, `@` 는 진단 억제일 뿐 예외를 삼키지 않는다. 실측:

```text
php -d disable_functions=exec -r 'var_dump(function_exists("exec")); @exec("echo hi");'
→ bool(false)
→ Error: Call to undefined function exec()
```

즉 `@exec(...)` 는 「막혀 있으면 조용히 넘어간다」가 아니라 「막혀 있으면 이 요청을 끝낸다」이다. 이 오류는 그 호스팅에서만 나므로 개발 환경에서는 영원히 재현되지 않는다.

정적 검사는 판정 SSoT 축과 **무가드 호출 축**을 각각 본다. 두 축은 겹치지 않는다 — 판정문을 보는 검사는 판정이 아예 없는 호출부를 볼 수 없고, `php artisan` 스폰을 보는 검사는 명령줄에 `artisan` 이 없는 스폰(`exec('which unzip')` · `exec('cmd /c mklink …')` · composer 바이너리 탐색)을 보지 않는다. 그래서 무가드 호출 축의 대상에는 **실행 프리미티브 자신**(`exec`·`proc_open`·`shell_exec`·`passthru`·`popen`·`system`)과 **런타임 ini 접근·진단 채널**(`ini_get`·`ini_set`·`error_log`)이 포함되고, 검사 범위는 인스톨러뿐 아니라 `app/**` 전역이다 — 인스톨러가 in-process 로 `module:install` 을 실행하면 그 코어 코드가 **같은 요청 안에서** 돌기 때문에, 범위를 인스톨러로 좁히면 그 자체가 사각이 된다.

호출부가 `catch (\Throwable)` 로 감싸 「확인 불가」 폴백을 갖는 형태도 정상이다 — 정적 검사는 `try`/`catch` 를 보지 못하므로 그 자리에는 면제 표식으로 어느 폴백이 받는지 적는다.

가드가 호출 지점이 아니라 **진입점**에 있는 형태는 정상이다(예: PHP 바이너리 probe 는 `ExecutionEnvironment::probePhpBinary()` 와 인스톨러의 경로 검증이 각각 먼저 판정한다). 파일 단위로 보는 검사에는 그 가드가 보이지 않으므로, 그 자리에는 면제 표식으로 어디에 가드가 있는지 적는다. 인스톨러 진입점의 가드 배선은 `tests/Unit/Installer/InstallerEntryGateWiringTest.php` 가 전수로 잠근다.

### 5.2 확장 vendor 는 설치·업데이트가 같은 통로를 쓴다

무CLI 환경에서 확장의 외부 패키지 의존성을 구성하는 통로는 동봉 `vendor-bundle.zip` 이고, 그 통로의 진입점은 `VendorResolver` 다. 그런데 그 resolver 를 **업데이트 경로만** 경유하면, 설치는 남은 통로인 raw composer 로 떨어진다. 그 결과는 오류로 드러나지 않는다 — composer 를 부를 수 없으면 경고 로그만 남고 설치는 성공으로 표시되며, DB 의 vendor 설치 모드에는 운영자가 고른 `bundled` 가 기록된다. 이후 업데이트만 그 모드를 상속해 번들로 vendor 를 채우므로, **설치 직후에만 vendor 가 비어 있는** 상태가 된다.

| 금지 | 올바른 사용 |
|------|------------|
| `_pending` 스테이징 사본이 있을 때만 resolver 를 경유 | `_bundled` 소스 설치(웹 인스톨러의 정상 경로)와 `--force` 재설치도 같은 resolver 를 경유한다 |
| 확장 vendor 를 확장 부팅(ServiceProvider·시더) **뒤**에 구성 | 부팅 전에 구성한다 — 그 확장의 코드가 자기 의존성을 필요로 한다 |
| raw composer 폴백을 vendor 해결 여부와 무관하게 실행 | resolver 가 성공했으면 건너뛴다 |
| 모듈 쪽만 통로를 두고 플러그인 쪽은 두지 않기 | 두 확장 타입의 vendor 처리는 대칭을 유지한다 |

이 축은 「어느 분기가 어느 소스에 열려 있는가」라는 의미 판정이라 정적 검사로 고정할 수 없다. 대신 `tests/Unit/Extension/BundledSourceVendorInstallTest.php` 가 소스 계약으로 잠근다. 상세: [vendor-bundle.md](../extension/vendor-bundle.md) §5.

## 6. 면제 표식

러너로 아직 옮기지 못한 기존 스폰 지점은 그 호출 바로 위에 옮기지 못한 사유를 주석으로 남긴다.

```php
// 현행 subprocess 경로 — 후속 공정에서 러너로 이관
$process = proc_open($commandLine, $descriptors, $pipes, BASE_PATH, $env);
```

그 주석은 면제이자 **이관 대상 목록**이다 — 이관이 끝나면 주석을 지운다. 사유 없이 남은 직접 스폰은 정적 검사에서 오류로 멈춘다.

## 7. 두 러너의 등가 계약

같은 커맨드·같은 파라미터에 대해 두 러너는 **성공/실패 판정이 일치한다**. `tests/Feature/Execution/CommandRunnerEquivalenceTest.php` 가 존재하는 커맨드(`list`)에 대해서는 양쪽 모두 종료 코드 0 을, 존재하지 않는 커맨드에 대해서는 양쪽 모두 실패를 고정한다. 다만 실패의 **표현 방식까지 같지는 않다** — in-process 는 미존재 커맨드에서 `CommandNotFoundException` 을 던질 수 있고 subprocess 는 비-0 종료 코드로 끝난다. 그래서 계약은 "같은 종료 코드" 가 아니라 "실패를 성공으로 뭉개는 러너가 없다" 이다. 인스톨러 태스크 목록 단위(DB 상태·생성 파일 포함)의 대조는 `disable_functions` 가 INI 값이라 PHPUnit 안에서 inprocess 환경을 재현할 수 없어, 무CLI 격리 환경에서 같은 태스크 목록으로 설치를 완주하는 브라우저 실측이 담당한다 — 단위 수준에서는 태스크 함수가 두 전략에 같은 `(command, parameters)` 를 넘기는 것을 `InstallerStepwiseRunnerTest` 가 고정한다.

등가를 깨는 흔한 원인:

- 한 러너만 `--no-interaction` 을 붙인다 → 프롬프트가 있는 커맨드가 한쪽에서만 멈춘다
- subprocess 러너의 env 를 `$_ENV` 만으로 구성한다 → `variables_order` 에 `E` 가 없는 호스팅에서 `.env` 값이 자식에게 전달되지 않는다 (`getenv()` 를 기반으로 `$_ENV` 로 보완한다)
- 작업 디렉토리를 넘기지 않는다 → `base_path()` 상대 경로가 자식에서 어긋난다

## 8. 설정 키와 검증

| 키 | 기본값 | 허용값 | 비고 |
|---|---|---|---|
| `system.execution_mode` | `auto` | `auto` / `subprocess` / `inprocess` | `config/settings/defaults.json` `system` 카테고리. 관리자 「시스템」 탭이 노출·저장한다 |
| `system.cron_mode` | `auto` | `auto` / `web` / `cron` / `both` | 예약 작업 실행 방식. `cron` 이면 주소 호출이 인증을 통과해도 실행하지 않는다 |
| `system.cron_allowed_ips` | `''` | IP·CIDR 목록 (줄바꿈/쉼표) | 비우면 제한 없음. 형태 규칙은 `SettingShapeRules` 가 두 저장 경로에 공유한다 |
| `system.cron_min_interval` | `5` | 0 ~ `core.settings_limits.system_cron_min_interval_max` | 분. 0 이면 제한 없음 |
| `system.cron_visit_fallback` | `false` | boolean | 방문 트리거 폴백 (기본 꺼짐) |

**호출 번호(토큰)는 이 카탈로그에 없다.** 인증 없이 예약 작업을 실행할 수 있는 자격증명이라, 설정 조회 응답에 실리면 "읽기만" 가능한 계정이 실행 권한을 얻는다. 보관은 전용 파일(`App\Scheduling\WebCronTokenStore`), 노출은 권한을 확인하는 전용 API 한 곳뿐이다.

허용값은 `App\Http\Requests\Settings\EnumeratedSettingValues` 한 곳에서 파생한다(`ExecutionMode::values()`). 설정 저장 경로는 둘이라 — 벌크 `POST /api/admin/settings` (`SaveSettingsRequest`) 와 단건 `PUT /api/admin/settings/{key}` (`UpdateSettingRequest`) — 한쪽에만 규칙을 두면 다른 쪽이 우회로가 되어 임의 문자열이 `system.json` 에 남는다. 런타임은 `fromStringOrAuto()` 가 `Auto` 로 폴백해 파손되지는 않지만, 저장 단계에서 막아 설정 파일에 뜻 모를 값이 기록되지 않게 한다. 같은 표에 `general.asset_url_mode` 도 올라 있다.

허용값이 닫힌 설정 키를 새로 만들면 이 표에 추가한다 — 두 FormRequest 를 따로 고치지 않는다.

허용값을 **열거할 수는 없지만 형태가 정해진** 키(IP 목록 등)는 형제 표 `App\Http\Requests\Settings\SettingShapeRules` 가 소유한다. 두 표 모두 벌크·단건 양쪽이 공유한다 — 단건 경로는 값의 타입·길이만 보므로, 그 경로에서 형태 규칙이 빠지면 검증이 통째로 사라지고 판정기가 조용히 매칭에 실패하는 값이 저장된다.

## 9. 측정 불가 계약 (시스템 리소스)

CPU·메모리·디스크 사용률을 OS 명령(`wmic`·PowerShell·`nproc`·`free` 등)으로 수집하는 지점은 전부 `ExecutionEnvironment::hasFunction('exec')` 가드를 거친다. 수집 경로가 없으면 응답의 `percentage` 는 **`null`** 이다 — `0` 으로 채우면 실측 0% 와 구분되지 않는다. `GET /api/admin/dashboard/resources` · `GET /api/admin/settings/system-info` 가 이 계약을 따르며, 화면은 `null` 을 "측정 불가" 로 표기한다.

## 10. 테스트

| 파일 | 고정하는 것 |
|---|---|
| `tests/Unit/Support/ExecutionEnvironmentTest.php` | 판정 분기(함수 차단·probe 실패·불확실 폴백), 함수 집합 불변 |
| `tests/Unit/Execution/CommandRunnerResolverTest.php` (`test_detected_php_binary_*`) | 감지된 바이너리가 subprocess 러너에 도달 |
| `tests/Unit/Execution/ExecutionModeTest.php` | 열거·안전 변환 |
| `tests/Unit/Support/PhpBinaryResolverTest.php` | 후보 탐색·shape 정책·`--version` 파싱 · **SAPI 판정과 CLI 우선 정렬**(FPM/CGI 바이너리를 실행용으로 승격하지 않는다) |
| `tests/Unit/Execution/CommandRunnerResolverTest.php` | 결정 순서(명시 > 설정 > 감지), 기본값 `auto` 불변 |
| `tests/Feature/Execution/CommandRunnerEquivalenceTest.php` | 두 러너 종료 상태 등가 · 주입 바이너리 경로가 스폰에 도달 |
| `tests/Unit/Installer/InstallerExecutionModeHelperTest.php` | 인스톨러 판정(명시 > 감지) · inprocess → bundled 고정 · 설치 시작 확정값 우선 · 단계별 실행 토큰 판정 · 두 진행 입구의 거부 규칙 대칭 |
| `tests/Unit/Installer/InstallerStep3ExecutionModeTest.php` | Step 3 저장 시점 — 허용 어휘 밖 값은 `auto` 로, inprocess 선택 시 Vendor 방식 bundled 고정 |
| `tests/Unit/Installer/InstallerUngatedHelperContractTest.php` | 실행 모드 어휘가 코어 Enum 에서 온다 · 러너 전략이 확정 상태를 따르고 기본값은 subprocess · exec 가용 시 실제 스폰 경로 |
| `tests/Unit/Installer/InstallerArtisanExecutorTest.php` | 인스톨러 러너 — 서브프로세스 인자 조립 형태 · 전략 분기 · vendor 부재 시 명확한 실패 |
| `tests/Unit/Installer/InstallerStepwiseRunnerTest.php` | 단계별 실행 — 첫 미완료 태스크만 실행 · 관리자 ENV 같은 요청 주입 · DB 실패 롤백 후 마커 제거 · **실패 후 재시도가 같은 태스크를 재실행** · **요청 반복으로 목록 전수 완주** · 건너뛰기 · `--vendor-mode` 전파. 메서드마다 새 프로세스(temp `BASE_PATH` 상수 충돌 회피) |
| `tests/Unit/Installer/InstallerExecutionModeTouchpointCoverageTest.php` | 실행 모드 관여 지점 전수 — `vendor_mode`·`execution_mode` 를 읽는 인스톨러 파일을 스캔해 보정 함수 경유를 강제 |
| `tests/Unit/Installer/InstallerEndpointGuardCoverageTest.php` | 인스톨러 API 전 엔드포인트 가드 경유 · 토큰 보관처 |
| `tests/Unit/Installer/InstallerWorkerLockRecoveryTest.php` | 롤백 디스패치가 확장 태스크의 합성 키(`module_install:foo`)를 해석 · 점유자 선언 시간 예산은 stale 판정의 하한 · 재시작·초기화가 죽은 워커의 점유를 비워 재점유를 허용 · 태스크 본문 도중의 lock 손실이 태스크 실패로 변환되지 않음 |
| `tests/Unit/Installer/InstallerIncludeOrderTest.php` | 엔드포인트 require 순서·필수 헬퍼 로드 |
| `tests/Unit/Installer/InstallerRuntimeHelperTest.php` | `runtime.php` 읽기·쓰기와 비밀 보존 |
| `tests/Unit/Installer/InstallerRollbackDispatchTest.php` | 롤백 디스패치·수동 복구 안내가 태스크 목록 전수를 덮는다 |
| `tests/Unit/Installer/InstallerSkipDependencyCascadeTest.php` | 건너뛰기가 의존 확장까지 함께 지나간다 |
| `tests/Unit/Installer/InstallerVendorBundleGuardTest.php` | composer 가용 판정의 출처 · 번들 백업 전부-아니면-전무 · **추출 중 진행 알림(묶음 분할)** |
| `tests/Unit/Installer/InstallerPhpBinaryDetectionTest.php` | 인스톨러 PHP 경로 검증·SAPI 판정 |
| `tests/Unit/Installer/InstallerMonitorContractTest.php` | 진행 모니터 구조 계약 (정지 감지·취소 확인·아이콘 텍스트 대체) |
| `tests/Unit/Installer/InstallerStateSchemaTest.php` | 상태 스키마 · 조회 응답 실패 필드 집합 · **단계별 실행 상태 요약** |
| `tests/Unit/Installer/InstallerTaskDisplayNameTest.php` | 태스크 표시 이름(합성 키 포함) |
| `tests/Unit/Installer/InstallerTestIsolationCoverageTest.php` | temp `BASE_PATH` 격리 선언 전수 |
| `tests/Unit/Installer/InstallerStyleClassCoverageTest.php` | 마크업 클래스의 CSS 규칙 존재 · **상태 문구의 토큰 층** |
| `tests/Unit/Installer/InstallerLanguageParityTest.php` | 인스톨러 다국어 — ko/en 키·자리표시자 정합, 중괄호 표기 금지, 리터럴 키 참조, **지원 언어 상수 ↔ lang 파일 대조**, **PHP 채널의 점 경로 키 금지**, **치환은 `lang(key, replace)` 한 지점** |
| `tests/Unit/Installer/InstallerScenarioMarkerCoverageTest.php` | 무CLI 두 매니페스트의 마커 양방향 정합 |
| `tests/Unit/Concerns/SkipsWhenExtensionTablesAreMissingTest.php` | 건너뛰기 안전장치 자신의 `.env` 파싱 계약 |
| `tests/Unit/Support/ShellExecGuardCoverageTest.php` | 설정 화면 자원 조회의 실행 함수 가드 |
| `tests/Unit/Services/DashboardServiceTest.php` · `tests/Feature/Api/Admin/DashboardControllerTest.php` | 측정 불가는 0% 가 아니라 null |
| `resources/js/installer/__tests__/installation-monitor.test.ts` | 진행 모니터 **행위** — 봉투 실패 사유 전달 · 이벤트 재생 · 중단 뒤 응답 폐기 |
| `tests/scenarios/no-cli-installer-stepwise.yaml` | 단계별 실행 시나리오 매니페스트 |
| `tests/Unit/Http/Requests/Settings/EnumeratedSettingValuesTest.php` | 허용값 표 ↔ Enum/상수 ↔ 카탈로그 기본값 정합 |
| `tests/Feature/Api/Admin/SettingsControllerTest.php` (`test_update_*_execution_mode`) | 단건 저장 경로의 허용값 게이트 |
| `tests/Unit/Scheduling/ArtisanCommandExtractorTest.php` | shell 이벤트 되뽑기 — 옵션·플래그·배열 옵션·위치 인자·인용부호, 비-Artisan 이벤트는 예외로 드러남 |
| `tests/Feature/Scheduling/ScheduleRunnerContractTest.php` | 등록된 전 이벤트 되뽑기 성공(실패 0) · 대상은 등록에서 도출 · 식별 키 안정성·무충돌 |
| `tests/Feature/Scheduling/ScheduleRunnerCatchUpTest.php` | 실행 창 소급 · 중복 0 · 최소 간격 스킵 후 무유실 · 창 상한 · 조건부 등록 · 상한 이월과 재개 |
| `tests/Feature/Scheduling/ScheduleRunEntryPointParityTest.php` | 진입점 둘(웹·CLI)이 같은 본체·같은 기록을 쓴다 · CLI 종료 코드가 실패를 반영 |
| `tests/Unit/CoreUpdate/CoreUpdateStepContractTest.php` | 웹 단계 순서 불변식 — 파일 적용과 업그레이드 스텝이 **다른 요청**이라는 격리(스폰이 막힌 호스팅에서 요청 경계가 `proc_open` 스폰을 대신한다) |
| `tests/Unit/CoreUpdate/CoreUpdatePipelineParityTest.php` | CLI 와 웹이 같은 서비스를 같은 순서로 부른다 — 한쪽에만 단계가 늘어난 상태를 차단 |
| `tests/Unit/CoreUpdate/CoreUpdateSafeDefaultsTest.php` | 웹 착수의 입력 표면 부재(`force`·`prune`·`no-backup`…)와 CLI 옵션 존치 |
| `tests/Feature/Api/Admin/CoreUpdateWebFlowTest.php` | 웹 단계 실행의 종단 — 착수·진행·재개·되돌리기 |
| `tests/scenarios/no-cli-execution-mode.yaml` | 시나리오 매니페스트 (`disable_functions` 는 INI 라 E2E 재현 불가 — 종단 검증은 전용 환경 실측) |

## 11. 관련 문서

- [admin-settings-access.md](admin-settings-access.md) — 설정 값 접근 (`g7_core_settings()` 미러)
- [validation.md](validation.md) — FormRequest 규칙
- [console-confirm.md](console-confirm.md) — 콘솔 프롬프트 (웹 요청 안에서는 `--no-interaction` 으로 차단된다)
- [core-update-system.md](core-update-system.md) — 코어 업데이트의 스폰 경로
- [api/dashboard.md](api/dashboard.md) · [api/settings.md](api/settings.md) — 측정 불가 계약의 응답 필드
