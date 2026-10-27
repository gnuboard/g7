# 터미널 설치·운영 명령 (installer CLI)

> 대상: 설치기·운영 명령을 고치는 개발자. 사용법은 [INSTALL.md 「방법 5」](../../INSTALL.md#방법-5-터미널-설치-브라우저-없이) 를 본다.

## TL;DR (5초 요약)

```text
1. php g7-install(루트 스크립트) = php artisan core:install — 같은 본체(public/install/includes/cli/app.php)를 부른다. 본체는 순수 PHP 다(vendor·Laravel 없이 동작)
2. 검증·요구사항·DB 검사·확장 해석·설치 계획·.env 확정은 웹 엔드포인트와 같은 공유 함수를 부른다 — 규칙은 공유 함수에서 고치고, 엔드포인트나 CLI 파일에 사본 규칙을 두지 않는다
3. 설치 작업은 웹 워커와 같은 runInstallationTasks() 를 송출기만 바꿔 돌린다(CliEmitter·JsonLinesEmitter)
4. 종료 코드 0~7 은 자동화 계약이다 — 바꾸지 않는다. 새 실패 원인은 기존 코드 중 하나에 넣는다
5. 비밀값은 명령줄 인자·--config 로 받지 않는다(환경변수·파일·숨김 입력만). 거부 안내에도 값을 싣지 않는다
```

## 파일 배치

| 파일 | 역할 |
|------|------|
| `g7-install` | 진입 스크립트 — CLI SAPI 가드, `BASE_PATH` 정의, `g7_install_cli_main($argv)` |
| `app/Console/Commands/Core/InstallCoreCommand.php` | `core:install` — 같은 PHP 바이너리로 `g7-install` 을 자식 프로세스로 실행(인자·표준 입출력·종료 코드 그대로) |
| `public/install/includes/cli/app.php` | 옵션 명세·인자 해석·입력 병합·종료 코드 상수·진입점 |
| `public/install/includes/cli/install-flow.php` | 단계 0~5 — 웹 마법사 단계와 1:1 |
| `public/install/includes/cli/ownership.php` | 터미널 소유(`owner_kind=cli`)·넘겨받기·`cli.lock` |
| `public/install/includes/cli/emitters.php` | 사람용·JSON 송출기 (`ProgressEmitter` 구현) |
| `public/install/includes/cli/reinstall.php` | `--reinstall` 지울 대상·삭제 |
| `public/install/includes/cli/web-user.php` | root 판정·`--web-user` 소유권 이전 |
| `public/install/includes/cli/prompt.php`·`public/install/includes/cli/signals.php` | 대화형 입력·숨김 입력, Ctrl+C·SIGTERM |

공유 함수(웹·CLI 공용): `public/install/includes/config-validation.php`(`installer_validate_config`) · `public/install/includes/requirements.php`(`installer_collect_requirements`) · `public/install/includes/db-check.php`(`installer_test_database`) · `public/install/includes/extension-selection.php`(`installer_resolve_extension_selection`, 브라우저 쪽 같은 규칙은 `public/install/assets/js/installer-extension-resolver.js`) · `public/install/includes/install-plan.php`(`installer_begin_installation`) · `public/install/includes/env-finalize.php`(`installer_finalize_env`).

## 입력

우선순위: 명령줄 옵션 > 환경변수 `G7_INSTALL_*` > `--config=<json>` > 웹 기본값(`DEFAULT_INSTALL_CONFIG`). 옵션 명세 `g7_cli_option_spec()` 의 config 키는 웹 Step 3 입력 이름과 같다 — 웹 입력을 추가하면 이 명세에도 넣는다(`InstallerCliValidationParityTest` 가 같은 값 묶음에 같은 오류 항목이 나오는지 본다).

열거값 옵션(`--locale`·`--site-language`·`--admin-language`·`--asset-url-mode`·`--vendor-mode`·`--output`)의 지원 밖 값은 어떤 상태도 바꾸기 전에 종료 2 — 공유 검증이 웹 숨김 값을 기본값으로 맞추는 항목(사이트 언어·자산 URL 방식)도 터미널 입력은 기본값으로 바꾸지 않는다.

요구사항 검사는 CLI PHP 설정 기준이다. 웹서버 PHP(FPM 등)의 확장·`disable_functions` 는 판정할 수 없으므로 [2] 단계에 안내 줄(`cli_requirements_cli_php_note`)을 내고, 공개 설치 문서(INSTALL.md 방법 5)에도 같은 주의를 둔다.

## 소유권·동시성

- 터미널 각인: 무작위 `owner_hash` + `owner_kind=cli`, 복구 해시 없음 → 브라우저 reclaim 은 403(`recovery_cli_owned`).
- 웹이 소유한 진행 중 설치는 종료 5. `--takeover` 는 웹 워커가 15초 안에 심장박동하지 않았을 때만 넘겨받고, 그때 웹 세션의 `runtime.php`·삭제 확인·계획 서명을 폐기한다. 넘겨받은 사실은 새 설치 시작이 `installation.log` 를 새로 만든 뒤에 다시 적는다.
- `--reset-state` 는 이 소유 판정을 통과한 뒤에만 지운다 — 웹 소유 state 는 `--takeover` 와 같은 규칙을 거친다.
- PHP·Composer 경로를 주지 않으면 웹 Step 3 와 같이 탐지한다(`installer_detect_php_binaries()`): 기본 `php` 를 쓸 수 없을 때만 찾은 첫 PHP, Composer 는 PATH 의 `composer` 가 아닌 찾은 경로(`composer.phar`).
- 같은 사이트의 터미널 설치 동시 실행은 `storage/installer/cli.lock`(flock)으로 막는다(종료 5). 워커 잠금은 웹과 같은 `acquireWorkerLock()`.

## JSON 출력 (`--output=json`)

`JsonLinesEmitter` 가 한 줄에 `{"event": 이름, …데이터}` 하나를 쓴다. 설치 단계 이벤트는 웹 SSE 와 같은 작업 실행기(`task-runner.php`)가 내므로 이름·필드가 SSE 와 같다(`InstallerCliEmitterTest` 가 잠금). 웹에서는 화면 전환인 [0]~[4] 단계·재설치 초기화·마무리는 터미널 전용 이벤트로 나온다.

| 이벤트 | 내는 곳 | 데이터 |
|------|------|------|
| `step` | 단계 머리 | `step`·`title` |
| `info`·`warning` | 안내·경고 한 줄 | `message` |
| `requirements` | [2] 요구사항 | `blocking`·`all_required_passed` |
| `extensions` | [4] 확장 선택 | `install_order`·`auto_added` |
| `reinstall` | [R] 재설치 초기화 | `rows`·`keep_app_key` |
| `summary` | 끝 | `exit_code` (+ 완료 시 `version`·`admin_login_url`·`warnings`, 이미 설치 건너뜀이면 `already_installed`) |

## 재설치 (`--reinstall`)

지우는 시점은 **[3] 환경설정·DB 검사를 통과한 뒤**다 — 먼저 지우면 입력 오류 하나로 표식만 지워지고 테이블이 남는다. 옵션 대상(vendor·확장·설정·업로드)을 먼저 지우고, 하나라도 실패하면 설치 표식을 남긴 채 종료 1. 설정을 남기면 기존 `APP_KEY` 를 runtime 으로 이어 쓴다.

항상 지우는 대상에 애플리케이션 파일 캐시(`storage/framework/cache/data`, `.gitignore` 보존)가 들어 있다 — 확장 상태 목록 캐시(`ext.modules.active_identifiers` 등)는 G7 설정의 캐시 드라이버(기본 file)에 있어 DB 를 비워도 남는다. 남으면 이번 설치에 넣지 않은 확장이 켜진 것으로 보여 그 라우트를 오토로드 없이 싣고 artisan 부팅이 죽는다(설치 단계의 롤백도 같은 오류로 실패).

## 운영 명령 (설치 뒤)

| 명령 | 저장 경로 (웹과 같음) |
|------|------|
| `user:reset-password` | `PasswordResetService::resetByOperator()`(메일 재설정과 공용). 웹 재설정 직전 가드 훅 `core.auth.before_reset_password` 는 발화하지 않는다(브라우저 본인인증을 마칠 수 없다). 확장 안내는 filter `core.auth.console_password_reset_notices` |
| `settings:get`·`settings:set` | `SettingsConsoleService` — 코어는 탭 경로(`SaveSettingsRequest` → `saveSettings`) 또는 단건 경로(`UpdateSettingRequest` → `setSetting`), 플러그인은 `UpdatePluginSettingsRequest` → `PluginSettingsService::save`, 모듈은 선택 메서드 `settingsSaveRequestClass()` 가 돌려주는 FormRequest → 모듈 `saveSettings` + `core.module_settings.after_save` |
| `module:install`·`plugin:install`·`template:install --with-dependencies` | `CascadeInstallOrchestrator`(관리자 설치 창과 공용) |
| 같은 세 명령 `--with-language-packs[=all\|none\|id,...]` | 후보는 `ExtensionInstallPreviewBuilder` 의 `language_packs`(설치 창과 같은 목록)를 **본 확장 설치 전에** 계산해 둔다(설치 뒤에는 미리보기가 후보를 다시 내지 못한다). 설치는 본 확장 설치 뒤 `CascadeInstallOrchestrator::installLanguagePacks()` — 자동 활성화, best-effort(실패는 경고). 후보에 없는 식별자는 설치 전에 오류. 옵션 처리는 trait `App\Console\Commands\Concerns\RunsCascadeInstall` |

관리자 화면 FormRequest 를 터미널에서 재사용할 때는 `App\Support\FormRequestRunner` 로 실행한다 — `prepareForValidation`·`withValidator` 는 그대로, `authorize()` 는 부르지 않는다(셸 접근이 곧 권한).

## 테스트

인스톨러 단위 테스트는 임시 `BASE_PATH` 서브프로세스(`tests/Unit/Installer/Concerns/RunsInstallerCli.php`)로만 돌린다. CLI 파일의 함수는 `function_exists` 로 감싸 있으므로, 환경에 묶인 단계(요구사항·DB 연결·설치 작업·root 판정)는 같은 이름의 함수를 먼저 정의해 주입한다. 실제 chown·root 실행·도커는 WSL 실측으로 본다.

## 관련 문서

- [INSTALL.md](../../INSTALL.md) — 사용자 안내
- [core-update-system.md](core-update-system.md) — 설치 후 코어 업데이트
- [console-confirm.md](console-confirm.md) — Artisan 확인 프롬프트 규칙
