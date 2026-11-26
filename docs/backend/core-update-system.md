# 코어 업데이트 시스템 (Core Update System)

> 코어 버전 업데이트의 감지, 다운로드, 적용, 롤백, 업그레이드 스텝 실행을 관리하는 시스템

## TL;DR (5초 요약)

```text
1. 코어 업그레이드 스텝: upgrades/ 디렉토리 (프로젝트 루트), 네임스페이스 App\Upgrades
2. 마이그레이션 = 스키마 변경만, 데이터 백필/변환 = 업그레이드 스텝 (역할 분리 필수)
3. 업데이트 실행 흐름: 11단계 (감지 → 다운로드 → 백업 → 적용 → 마이그레이션 → 동기화 → 업그레이드 → 마무리)
4. 롤백: CoreBackupHelper로 백업 생성, 실패 시 자동 복원
5. 부트스트랩 호환성 검증: 코어 버전 < 확장 g7_version 시 자동 비활성화 (1시간 캐시)
6. vendor 를 교체한 뒤 새 프로세스를 띄우기 전에는 패키지 매니페스트를 비운다 (3계층 — 부모 선정리 / 자식 자가 치유 / 버전 판독 범위)
```

---

## 실행 환경 전제 (CRITICAL)

```text
⚠️ core:update **커맨드** 는 SSH/CLI 환경에서 코어 파일 소유자(보통 FTP/SSH 사용자)가
   직접 실행한다고 가정합니다.

   SSH 가 없는 호스팅을 위한 웹 단계 실행은 §15 가 다룹니다 — 같은 서비스를 같은 순서로
   부르되, 오케스트레이션만 다릅니다(한 요청 = 한 단계).
```

### 권한 모델

| 실행 환경 | 권한 가정 | 권장 사용 |
|----------|----------|----------|
| **SSH/CLI 직접 실행** | 사용자 = 파일 소유자 → 모든 코어 파일 쓰기 가능 | ✅ 정상 사용 (권장) |
| **웹 인스톨러 (`public/install/`)** | PHP-FPM 사용자 (www-data 등) — 권한 제한적 | ⚠️ vendor 번들 모드로만 신규 설치 가능 |
| **터미널 설치 (`php g7-install`, 7.1.4)** | 실행한 셸 사용자 — root 면 `--web-user` 필수(끝나면 쓰기 폴더 소유권을 웹서버 계정으로 넘김) | ✅ 최초 설치·재설치(`--reinstall`). 설치 뒤 업데이트는 `core:update` |
| **관리자 화면의 웹 단계 실행** (§15) | PHP-FPM 사용자 | ✅ 서버 명령을 쓸 수 없는 호스팅용. 착수 전 쓰기 가능성을 진단해 불가하면 시작하지 않는다 |
| **www-data 로 CLI 실행** | 코어 파일 소유자와 다를 수 있음 | ⚠️ 비권장 — 권한 거부 가능 |

### 권한 거부 발생 시 대처

`core:update` 시작 시 현재 실행 사용자 UID와 코어 파일(`composer.json`) 소유자 UID를 비교하여 불일치 시 경고 로그를 남깁니다. 다음과 같은 경우 발생할 수 있습니다:

1. **vendor/ 가 다른 사용자 소유**: 과거에 웹 인스톨러로 설치되어 vendor/ 가 www-data 소유인 상태에서 SSH 사용자로 `core:update` 실행
   - **해결**: `chown -R $(whoami) vendor/` 후 재시도

2. **코어 파일이 다른 사용자 소유**: FTP 사용자와 SSH 사용자가 다른 환경
   - **해결**: 호스팅 제공자에게 권한 정리 요청 또는 수동 업데이트 수행

### 웹 인스톨러는 별개

웹 인스톨러(`public/install/`)는 PHP-FPM 사용자로 실행되므로 권한 제약이 큽니다. vendor 번들 시스템(244 이슈)으로 신규 설치 시점의 vendor/ 권한 이슈는 해소되었으나, **코어 업데이트는 웹에서 지원하지 않으며 SSH/CLI 로만 수행해야 합니다**.

---

## 목차

1. [업데이트 감지](#1-업데이트-감지)
2. [업데이트 실행 흐름 (11단계)](#2-업데이트-실행-흐름-11단계)
3. [백업 및 롤백](#3-백업-및-롤백)
4. [마이그레이션 vs 업그레이드 스텝](#4-마이그레이션-vs-업그레이드-스텝)
5. [업그레이드 스텝 작성](#5-업그레이드-스텝-작성)
6. [자동 발견 및 실행 규칙](#6-자동-발견-및-실행-규칙)
7. [버전 관리](#7-버전-관리)
8. [CoreUpdateService 주요 메서드](#8-coreupdateservice-주요-메서드)
9. [Artisan 커맨드](#9-artisan-커맨드)
10. [API 엔드포인트](#10-api-엔드포인트)
11. [설정 (config/app.php)](#11-설정-configappphp)
12. [에러 처리 및 로깅](#12-에러-처리-및-로깅)
13. [유지보수 모드](#13-유지보수-모드)
14. [코어 vs 확장 업데이트 비교](#14-코어-vs-확장-업데이트-비교)
15. [웹 단계 실행 (관리자 화면)](#15-웹-단계-실행-관리자-화면)

---

## 1. 업데이트 감지

> 파일: `app/Extension/CoreVersionChecker.php`, `app/Services/CoreUpdateService.php`

### 감지 흐름

```text
1. CoreVersionChecker::getCoreVersion() → config('app.version') 읽기
   (코어 업데이트 트리 안에서만 env APP_VERSION 우선 — CoreUpdateContext::isInProgress())
2. CoreUpdateService::checkForUpdates() → GitHub API로 최신 릴리스 조회
3. version_compare(current, latest) → 업데이트 가용 여부 판단
4. 원격 CHANGELOG 캐시 → storage/app/temp/core_remote_changelog.md
```

### GitHub API 통합

```text
- config('app.update.github_url') 에서 리포지토리 URL 읽기
- config('app.update.github_token') 으로 인증 (선택)
- GitHub Releases API 호출 → 최신 릴리스 태그에서 버전 추출
- 모든 원격 HTTP 호출은 GithubHelper (Laravel Http 파사드 기반) 사용
  → file_get_contents + stream_context_create 금지 (allow_url_fopen=Off 환경 대응)
```

### 감지 결과

```php
[
    'update_available' => true,
    'current_version'  => '7.0.0-alpha.14',
    'latest_version'   => '7.0.0-alpha.15',
    'check_failed'     => false,        // GitHub API 실패 시 true
]
```

---

## 2. 업데이트 실행 흐름 (11단계)

> 파일: `app/Console/Commands/Core/CoreUpdateCommand.php`

```text
┌─ Step 1:  업데이트 확인 (GitHub API 또는 --source/--local 모드)
├─ Step 2:  _pending 경로 검증 (디렉토리 존재/쓰기 권한)
├─ Step 3:  유지보수 모드 활성화 (--no-maintenance 시 스킵)
├─ Step 4:  다운로드 (GitHub zipball 또는 --source/--local에서 복사)
├─ Step 5:  백업 생성 (--no-backup 시 스킵)
├─ Step 6:  Composer install (_pending에서 실행, 변경 없으면 스킵)
├─ Step 6.5: 신규 파일 manifest 생성 + 증분 적용 대상(3-way) 산출 (백업 있을 때)
├─ Step 7:  파일 적용 (_pending → base_path, 기본=코어 변경분만 / --prune=전체 덮어쓰기)
├─ Step 8:  vendor 복사 (_pending/vendor → base_path/vendor, Step 6 스킵 시 함께 스킵)
├─ Step 9:  마이그레이션 + 동기화 (migrate, roles, permissions, menus, mail templates)
├─ Step 10: 업그레이드 스텝 실행 (upgrades/Upgrade_X_Y_Z.php)
└─ Step 11: 마무리 (.env 버전 갱신, 캐시 클리어, _pending 삭제, 유지보수 해제)
```

### 동시 실행 차단 (7.1.5+)

`core:update` 는 시작하자마자(옵션 검사·유지보수 진입보다 앞) 코어 작업 잠금(`core-update`, 30분)을 잡는다. 두 곳에서 동시에 돌리면 먼저 끝난 쪽이 유지보수 모드를 풀어 교체 중인 사이트가 열리고 스텝이 두 번 돌던 문제를 막는다.

| 상황 | 동작 |
|---|---|
| 다른 `core:update` 가 진행 중 | 「다른 코어 업데이트가 진행 중」 안내 후 실패 종료 — 직전 실행이 비정상 종료됐다면 잠금 만료(30분) 뒤 다시 실행 |
| 잠금 저장소(캐시) 오류 | 「잠금 저장소를 쓸 수 없다」 안내 후 실패 종료 — 원본 오류는 error 로그에만 |
| 진행 중 | 진행 콜백(30초 간격)·진행 막대 단계마다 잠금을 늘리고, 캐시 비우기 직후와 번들 일괄 업데이트 직전에는 바로 늘리거나 다시 잡는다(redis·memcached 의 flush 는 잠금까지 지운다) |

- 자식 프로세스(`core:execute-upgrade-steps`, `core:execute-bundled-updates`)는 코어 잠금을 잡지 않는다 — 부모와 소유자가 달라 스스로 막힌다.
- 운영자가 **단독으로** 실행한 `core:execute-upgrade-steps`(spawn 표식 `G7_UPDATE_IN_PROGRESS` 없음)는 코어 업데이트 실행 잠금(`CoreUpdateService::acquireRunLock()`)을 잡는다. 다른 `core:update`·단독 실행이 쥐고 있으면 아무 단계도 돌리지 않고 안내와 함께 종료 코드 1 이다 (7.2.0).
- file 캐시의 잠금은 `storage/framework/cache/locks` 에 있어 `cache:clear` 가 지우지 않는다.
- 번들 일괄 업데이트는 잠금 저장소 장애를 만나면 첫 항목에서 멈추고 남은 항목 수를 한 번 알린다(docs/extension/extension-update-system.md 「작업 잠금」).
- 적용 시점: 업데이트를 실행하는 것은 **설치된(이전) 판의 명령**이다. 7.1.4 → 7.1.5 업데이트에는 이 차단이 없고, 7.1.5 에서 그다음 판으로 올릴 때부터 적용된다.

### Step 1: 업데이트 확인

| 모드 | 동작 |
|------|------|
| 일반 | `checkForUpdates()` → GitHub API 호출 → 사용자 확인 프롬프트 |
| `--source={path}` | 지정된 디렉토리를 소스로 사용 (GitHub 스킵) |
| `--zip={path}` | 지정된 ZIP 파일을 추출하여 소스로 사용 (GitHub 스킵). 추출 후 `config/app.php` 에서 버전 자동 판별. ZIP 구조가 GitHub zipball 의 `owner-repo-hash/` 래퍼이든 평탄 루트이든 모두 지원 |
| `--local` | 현재 코드베이스를 소스로 사용 (GitHub 스킵) |
| `--force` | 버전 비교 스킵, 동일 버전이어도 강제 실행. 진행 확인은 건너뛰지 않는다 |
| `--yes`(`-y`) | 진행 확인(「코어를 업데이트하시겠습니까?」)을 「예」로 처리한다(7.1.6+). cron·CI·원격 명령·배포 스크립트처럼 확인에 답할 수 없는 실행용 — 이 옵션이 없으면 업데이트를 시작하지 않고 종료 코드 1 로 끝난다. 대화형에서 붙이면 묻지 않는다. 번들 확장 일괄 업데이트의 선택 질문(레이아웃 전략·확장별 전략·검색 색인 재생성)은 기본값으로 진행한다. `--force` 와 뜻이 다르다 — `--force` 는 버전 비교를 건너뛰는 옵션이고 확인을 대신하지 않는다 |
| `--vendor-mode=auto\|composer\|bundled` | vendor 설치 모드 지정 (기본 `auto` — composer 가능 시 composer, 불가 시 vendor-bundle.zip 추출). `bundled` 강제 시 공유 호스팅에서도 설치 가능 |
| `--bundled-sources=none\|all\|식별자,…` | 설치되지 않았고 이 프로젝트에도 없는 공식 번들을 어떻게 할지 미리 정한다(7.1.1+). `none` 배치하지 않음, `all` 모두 배치, 식별자 목록은 그것만 배치(유형이 겹치면 `plugin:식별자`). 미지정 시 대화형은 묻고, 비대화형(`--force`·`--no-interaction`·응답 불가)은 `none`. 배포본에 없는 식별자·알 수 없는 유형은 유지보수 모드 전에 실패 |

> `--source` / `--zip` / `--local` 은 **상호 배타적**입니다. 동시 지정 시 커맨드는 시작 전에 FAILURE(1) 로 종료됩니다.

> **확인에 답할 수 없는 실행 (7.2.0+)**: `--no-interaction`, 터미널이 없는 실행(cron, CI, `ssh host 'php artisan core:update'`)은 진행 확인에 답할 수 없다. `--yes` 없이 그렇게 실행하면 사유와 `--yes` 안내를 출력하고 **종료 코드 1** 로 끝나며, 유지보수 모드·다운로드·백업 어느 것도 시작하지 않는다. 7.1.x 까지는 이 경우 확인이 기본값 「아니오」로 처리돼 아무 출력 없이 `0` 으로 끝났고, 자동화는 업데이트가 끝난 줄 알았다. 대화형에서 운영자가 직접 「아니오」를 답한 취소는 종전처럼 `0` 이다.

### 공식 번들 배치 판정 (7.1.1+)

새 배포본에는 공식 번들(모듈·플러그인·템플릿과 그 언어팩)이 `{modules,plugins,templates,lang-packs}/_bundled/` 로 들어 있다. 7.1.0 까지는 Step 7 이 이 트리를 통째로 배치해, 설치하지 않은 번들과 운영자가 지운 번들이 업데이트마다 다시 깔렸다(공개 #146). 7.1.1 부터는 아래 규칙으로 **배치할 번들(P)** 과 **건너뛸 번들(U)** 을 먼저 정한다.

| 구분 | 규칙 |
| --- | --- |
| 항상 갱신 | 설치된 번들(DB 행 존재, 상태 무관), 디스크에 이미 있는 번들, 그리고 이들의 **새 판 manifest** `dependencies` 추이 폐포 중 배포본에 있는 번들(의존성으로 배치) |
| 운영자 선택 | 나머지(디스크에도 없고 설치되지도 않은 번들)는 [배치하지 않음 / 모두 배치 / 골라서 배치] 로 묻는다. 고른 번들의 의존 번들은 자동으로 함께 배치한다 |
| 언어팩 | `scope=core` 팩은 항상 배치. 확장 팩은 대상 확장이 배치·설치되거나 팩 자신이 설치·디스크에 있을 때만 배치 |
| 판정 밖 | 배포본 목록에 없는 사용자 자체 `_bundled/{id}` 는 판정 대상이 아니며 그대로 보존 |

묻는 시점은 소스별로 가장 이르다. `--source`·`--local`·`--zip` 은 시작 확인 직후, **유지보수 모드를 켜기 전**에 묻는다(`--zip` 은 압축을 풀지 않고 목록만 읽으며, PHP zip 확장이 없으면 추출 직후로 미룬다). GitHub 에서 받는 경우만 Step 4 다운로드 직후 유지보수 중에 묻고, 질문 앞에 「사이트가 유지보수 중입니다」를 표시한다. 그 시점에 `--bundled-sources` 의 식별자가 배포본에 없으면 백업·파일 적용 전이므로 받은 배포본을 지우고 유지보수를 풀고 실패로 끝낸다.

판정 결과는 `storage/app/core_update/bundled_sources.json` 에 남는다(미배치 목록·의존성으로 배치한 번들·고른 번들·해시). 관리자 목록 화면 안내와 `core:bundled-sources` 가 이 기록을 쓰며, 업데이트가 롤백되면 이전 기록으로 되돌린다. 완료 요약은 기존 설치 확장 업데이트, 의존성으로 배치한 번들(필요로 한 확장 표시), 고른 번들, 배치하지 않은 번들(나중에 배치하는 명령 안내)의 4구분으로 출력하고, 디스크에 있던 미설치 번들 갱신 수를 한 줄 덧붙인다.

**관리자 화면(웹 단계) 업데이트**도 같은 판정을 거친다. 웹에는 운영자에게 물을 자리가 없으므로 운영자 선택 없이(`--bundled-sources` 를 주지 않은 비대화 기본값과 같다) 항상 갱신 대상만 배치하고 나머지는 건너뛴다. 계획 단계가 판정의 건너뛸 경로를 신규 파일 기록과 증분 적용 목록에, 적용 단계가 파일 적용에 넘기고, 적용 직후 같은 기록 파일에 결과를 남긴다(되돌리기는 이전 기록으로 복원). 배포본이 custom 예약 자리(아래 「custom 자리」)에 파일을 싣거나 개발자 패키지가 새 코어와 맞지 않으면 내려받기 단계에서 멈춘다 — 아직 백업과 파일 적용 전이라 되돌리기는 받은 배포본 정리와 유지보수 해제뿐이고, 중단 사유는 CLI 와 같은 기록 파일에 남아 업데이트 화면이 보여 준다.

> **효력 시점**: Step 7 은 업데이트를 실행하는 쪽(이전 버전)의 코드로 돈다. 그래서 이 판정은 **7.1.1 로 올린 뒤의 업데이트부터** 적용되고, 7.1.0 → 7.1.1 업데이트는 7.1.0 코드가 돌려 지금까지처럼 모두 배치한다. 이미 깔린 쓰지 않는 번들은 `php artisan core:bundled-sources prune` 으로 정리한다(아래 「사후 관리 명령」).

### PHP 확장 사전점검 (7.1.4+)

새 배포본의 코어 요구 목록(`app/Support/Requirements/php-extensions.json` — 필수·선택 확장의 SSoT)을 읽어, 새 코어의 **필수**
확장이 이 서버에 없으면 업데이트를 시작하지 않는다(공개 #151). 파일을 적용한 뒤에야 부팅이 깨지는 것보다 시작 전에 멈추는 편이 사이트가 온전하다.

| 소스 | 판정 시점 | 미충족 시 |
|------|----------|----------|
| `--source` | 공식 번들 배치 판정 직후, 유지보수 모드 전 | 오류 출력 + FAILURE. 사이트·파일 변화 없음 |
| `--zip` | 같은 시점 — zip 을 풀지 않고 목록 파일만 읽는다(읽지 못하면 추출 직후로 미룬다) | 같음 |
| GitHub 다운로드 | Step 4 다운로드 직후(유지보수 중), 백업 전 | 받은 `_pending` 삭제 → 유지보수 해제 → 업데이트 로그 저장 → FAILURE (공식 번들 선택 오류와 같은 모양) |
| `--local` | 판정하지 않음 (현재 코드 그대로) | — |

- 새 배포본에 목록 파일이 없으면(이 체계 도입 전 판) 코어 판정은 건너뛰고 로그만 남긴다.
- 같은 시점에 「이번에 함께 갱신될 설치된 번들 확장」의 새 `composer.json` `require.ext-*` 도 계산해 **경고만** 출력한다 — 확장 업데이트는 막지 않는다(번들 일괄 업데이트 단계도 `php_extension_missing` 생명주기 경고만 남긴다).
- 판정은 JSON 만 읽는다. 새 배포본의 PHP 클래스를 `require` 하면 이미 로드된 같은 이름 클래스와 충돌하기 때문이다.
- 서비스: `App\Services\Core\PhpRequirementPreflight`(`inspectDirectory`·`inspectZip`). 회귀: `tests/Feature/Console/CoreUpdatePhpRequirementPreflightTest.php`.

### 사후 관리 명령: core:bundled-sources (7.1.1+)

```bash
php artisan core:bundled-sources list                         # 배포본 번들 × 디스크 × 설치 상태 표 + 기록 갱신
php artisan core:bundled-sources place sirsoft-totp           # 그 번들 + 의존 번들 + 대상 언어팩을 _bundled 로 배치 (설치는 별도)
php artisan core:bundled-sources prune                        # 정리 대상 미리보기 (삭제 없음)
php artisan core:bundled-sources prune --force                # 실제 삭제
php artisan core:bundled-sources prune --force --include-modified  # 배포본과 다른 파일이 있는 폴더도 삭제
```

- 배포본은 `--source`(디렉토리) > `--zip` > GitHub 의 현재 코어 버전 태그 순으로 고른다. 배포본의 코어 버전이 현재와 다르면 거부한다(다른 판 번들을 섞지 않음). GitHub 다운로드는 격리 디렉토리에 받고 끝나면 지운다.
- `list` 는 배포본을 못 읽으면 같은 버전의 기록 파일로, 그것도 없으면 디스크·설치 상태만 경고와 함께 보인다.
- `prune` 대상 = 배포본 번들 ∩ 디스크 − 설치됨 − 설치된 확장의 의존 대상 − 남는 번들의 의존 대상(반복 축소). 배포본과 md5 가 다르거나 배포본에 없는 파일이 있는 폴더는 「수정됨, 보존」과 다른 파일 목록을 출력하고 `--include-modified` 일 때만 지운다. 확장 언어팩은 팩이 미설치이고 대상 확장 소스가 정리되거나 이미 없을 때 함께 정리하며 core 팩은 대상이 아니다. 배포본 목록에 없는 사용자 자체 `_bundled/{id}` 는 어떤 경우에도 지우지 않는다. 정리한 번들은 미배치 안내에 다시 올리지 않는다.
- 웹 인스톨러로 새로 설치한 사이트는 배포본의 번들을 그대로 두므로, 쓰지 않을 번들은 설치 뒤 `prune` 으로 정리한다.

### Step 4: 다운로드 및 추출

```text
추출 (SafeArchiveExtractor, 7.1.3+):
- ZipArchive (PHP zip 확장) 필수 — 없으면 추출 실패. unzip 명령 폴백 없음 (7.1.3 에서 제거)
- 항목 이름의 \ 를 / 로 정규화한 뒤, 압축 밖 경로(..)·절대 경로·드라이브·UNC·널 문자·심볼릭 링크
  항목이 하나라도 있으면 아무것도 풀지 않고 전체 거부
- __MACOSX/ 와 그 아래 항목(macOS Finder 압축의 부산물)은 풀지 않고 건너뜀 (7.2.1+)
- 풀린 결과가 폴더 하나뿐이고 파일이 없으면 그 폴더를 소스 루트로 사용
  (GitHub 소스 ZIP 의 owner-repo-hash/ 래퍼 — resolveExtractedRoot)

v접두사 자동 감지 (resolveGithubArchiveUrl):
1. "v{version}" 태그로 HEAD 요청 시도 (예: v7.0.0-alpha.15)
2. 실패 시 "{version}" 태그로 HEAD 요청 시도 (예: 7.0.0-alpha.15)
3. HTTP 200/302 → 유효한 URL 반환
4. 모두 실패 → null (업데이트 불가)
```

### Step 5: 백업

```text
- CoreBackupHelper::createBackup() 사용
- 백업 대상: config('app.update.targets') + config('app.update.backup_only') + config('app.update.backup_extra')
- 제외 패턴: config('app.update.excludes')
- 백업 위치: storage 경로에 타임스탬프 디렉토리
```

### Step 6: Vendor 설치 (Composer 또는 Bundled)

`CoreUpdateService::runVendorInstallInPending()` 가 `VendorResolver` 경유로 모드에 따라 분기합니다.

```text
1. --vendor-mode 결정:
   - composer 명시 / bundled 명시 → 그대로 사용
   - auto (기본) → EnvironmentDetector 로 composer 실행 가능 여부 자동 감지

2. Composer 모드 (기존 흐름):
   - composer.json + composer.lock 의 MD5 비교 (_pending vs base_path)
   - 동일 → "composer 의존성 변경 없음 — 스킵" (Step 6 + Step 8 모두 스킵)
   - 변경됨 → composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
   - 운영 vendor 의 개발용(require-dev) 패키지 감지 → 로그 기록
     · 재설치 분기: "--no-dev vendor 로 교체합니다" (정보)
     · 스킵 분기: "그대로 남습니다 … composer install --no-dev 실행 권장" (경고)

3. Bundled 모드 (신규, 공유 호스팅 대응):
   - _pending/vendor-bundle.zip 무결성 검증 (SHA256)
   - VendorBundleInstaller::install() 로 zip 추출 → _pending/vendor/ 생성
   - 기존 vendor/ 를 vendor.old.{timestamp} 로 rename 후 추출 → 실패 시 복구

4. --no-scripts: post-autoload-dump 방지 (Step 11에서 package:discover로 처리)
```

> Vendor 번들 시스템 상세: [docs/extension/vendor-bundle.md](../extension/vendor-bundle.md)

### Step 6.5: 신규 파일 manifest + 증분 적용 대상 산출

```text
백업이 있을 때(--no-backup 아님)만 수행:

1. writeNewFilesManifest() — 자동 롤백용 `_new_files_manifest.json` 기록
   (base=백업 vs theirs=_pending: 신 버전이 추가한 파일/디렉토리 목록)

2. computeApplyList() — 기본(증분) 모드의 3-way 적용 대상 산출 (--prune 시 스킵)
   · base   = 구버전 원본 = 백업 스냅샷
   · theirs = 신 버전     = _pending
   · base 없음 → added / size·md5 다름 → changed / 동일 → 제외(스킵)
   · size 선필터 후 size 동일할 때만 md5 (mtime 비교 안 함 — _pending 은 추출 시각)
   · symlink / excludes / protected_paths 하위 → 목록 제외
   · 단, targets 에 더 구체적으로 명시된 경로는 상위 protected 를 오버라이드(아래 주석)
```

> **protected_paths 오버라이드 (공개 #64)**: `protected_paths` 에는 확장 부모(`modules`·`plugins`·`templates`·`lang-packs`)가 포함되지만, `targets` 에는 `{domain}/_bundled` 가 명시된다. 3-way 산출(`computeApplyList`)과 신규 파일 manifest(`writeNewFilesManifest`)는 "targets 에 더 구체적(하위)으로 명시된 경로가 상위 protected 를 오버라이드"하도록 판정한다. 이로써 코어 배포본에 포함된 번들 확장의 갱신 파일(`_bundled/{id}/composer.json`·`vendor-bundle.json` 등)이 코어 업데이트로 정상 반영된다. 오버라이드는 target 이 protected 보다 **더 깊을 때만** 적용되므로, `storage`(target) == `storage`(protected) 같은 동일 경로는 여전히 제외된다. 확장 부모를 protected 에 둔 원래 의도(자동 발견 폴백이 활성 서브디렉토리 `modules/sirsoft-*` 를 삭제하는 사고 방어)는 그대로 유지된다 — 자동 발견 폴백은 targets 순회가 아니므로 오버라이드 영향을 받지 않는다.

### Step 7: 파일 적용

```text
기본(증분) 모드 — --prune 미지정 + 백업 있음:
- Step 6.5 의 applyList(코어가 실제 변경/추가한 파일)에 있는 파일만 적용
- 코어가 건드리지 않은 파일은 복사·chmod·chown·mtime 갱신을 전부 스킵 → 현재 디스크
  상태(사용자 수정 포함 가능)를 그대로 보존
- orphan(소스에 없는 대상 파일) 삭제 안 함 → 사용자가 추가한 신규 파일 보존

--prune 모드 (또는 백업 부재 fallback):
- targets 전체 무조건 덮어쓰기 + orphan 삭제 (기존 동작)
- 백업 부재 시 base 가 없어 3-way 불가 → 안전하게 전체 덮어쓰기로 회귀 + 안내 출력
- `public/storage` symlink 는 orphan 삭제에서 보호됨 — `public` 타깃 정리 시
  `preserveLinkPaths: ['storage']` 화이트리스트로 링크/junction 을 보존 (공개 #43, 아래 §참조)

공통:
- 미배치 공식 번들 건너뛰기(7.1.1+): 위 「공식 번들 배치 판정」의 U 경로(`plugins/_bundled/{id}` 등)를
  증분·--prune·백업 부재 세 모드 모두에서 복사와 orphan 정리 양쪽에서 건너뛴다(skipPaths).
  Step 6.5 의 3-way 산출과 롤백 manifest 도 같은 목록으로 건너뛰어 적용 목록과 일치시킨다.
  excludes 의 「이름 매치(모든 깊이)」와 달리 경로가 같거나 그 하위일 때만 건너뛴다.
- 운영자 파비콘 보존(7.2.0+): 사이트의 `public/favicon.ico` 가 비어 있지 않고 배포본의 같은 파일과 내용이
  다르면(운영자가 직접 넣은 아이콘) 그 경로 하나만 skipPaths 로 건너뛴다. 비어 있는 파일(7.1 이하 기본값)이나
  배포본과 같은 파일은 평소대로 갱신한다. 이름 기준 excludes 에 넣지 않는 이유 — 코어 번들 아이콘
  `public/assets/core/favicon/favicon.ico` 까지 갱신이 막힌다 (`CoreUpdateService::isOperatorFavicon`)
- 7.1.x → 7.2.0 전환 보정: 그 업데이트의 Step 7 은 7.1.x 코드가 돌므로 위 파비콘 보존·`g7-setup.php` 보호·루트
  `.htaccess` 열거가 적용되지 않고, 증분 적용이 번들 확장 내부에서 7.2.0 이 지운 파일도 남긴다. 7.2.0 업그레이드
  스텝(자식 프로세스 — 이 시점에 이번 백업과 배포본 압축 해제본이 아직 있다)이 결과를 바로잡는다: 번들 폴더의
  지워진 파일 정리(`11_PruneStaleBundledFiles`, 동결 목록·본문 해시 일치분만), 백업에서 운영자 파비콘 복원
  (`12_RestoreOperatorFaviconFromBackup`), 설치 완료 사이트의 루트 `g7-setup.php` 제거와 빠진 루트 `.htaccess` 배치
  (`13_ReconcileRootFilesPlacedByLegacyUpdate`), 번들 출하 목록 씨앗(`14_SeedBundledShippedManifest` — 배포본에서).
- 번들 출하 목록 대조(7.2.0+, `BundledShippedManifest`): 증분 모드는 번들 확장 디렉토리 **내부**에서 새 판이 지운 파일을
  지우지 않았다(--prune 만 지움). 그래서 업데이트·설치가 그때 놓은 번들 파일의 경로·본문 md5 를
  `storage/app/core-update/bundled-shipped.json` 에 남기고, 다음 업데이트의 적용이 끝날 때 「사이트의 직전 목록에는 있고
  이번 배포본 목록에는 없는」 파일만 지운다(이번 목록을 새로 기록). 규칙:
  · 지금 해시가 직전 목록과 같은 파일만 지운다 — 운영자가 고친 파일은 남기고 콘솔·화면에 경로를 알린다(지우면 되돌릴 수
    없고, 남겨도 그 확장의 다음 갱신이 옮길 뿐 코어 동작을 깨지 않는다). 직전 목록에 없는 파일(운영자가 넣은 파일)은 대상이 아니다.
  · 확장 소스 폴더의 PHP 클래스(`{type}/_bundled/{id}/src/**.php`)는 지우지 않는다 — 확장 갱신이 옮겨 심는 옛 클래스맵·훅 캐시가
    그 클래스를 가리키는 동안 파일이 없으면 요청이 500 이 된다. 이번 배포본에 확장 디렉토리째 없는 경로도 건드리지 않는다.
  · 여러 판을 건너뛰어도 직전 목록 대 이번 목록 한 번의 비교다. 직전 목록이 없으면(설치 씨앗 전·7.1.x 에서 올리는 업데이트)
    지우지 않고 기록만 한다 — 7.1.x → 7.2.0 의 잔존 파일은 위 스텝 11 의 동결 목록이 맡는다.
  · 이번 목록은 배포본(대기 폴더의 압축 해제본) 자체에서 만든다(배포본이 곧 출하물 — 별도 생성 단계·커밋 목록과 어긋날 일이
    없다). 명령줄은 적용 직전에 한 번에, 관리자 화면은 적용 대상 산출 단계(`plan`)가 나눠 만들어 `web-run.shipped.json` 에
    두고 적용 단계가 넘긴다. 이번에 놓지 않는 공식 번들(skipPaths)은 목록에서 뺀다.
  · 설치는 `core:deploy-ledger-seed`(설치기 마지막 단계)가 설치 직후 디스크로 목록을 씨앗한다(목록이 없을 때만).
  · 목록을 쓸 때 직전 목록을 `bundled-shipped.previous.json` 으로 남기고, 되돌리기(`restoreFromBackupChunk`)가 그것으로
    돌려놓는다. 지운 파일은 백업 복원이 되살린다. `--prune`·웹 고정값(증분)은 그대로다.
- 자동 발견 폴백: targets 에 미등재된 source 최상위 항목도 스캔하여 적용
  (config('app.update.protected_paths') 와 config('app.update.excludes') 매치 시 스킵)
- FilePermissionHelper::copyDirectory() 사용 → 원본 파일 권한 보존
- ExtensionPendingHelper::copyToActive() 미사용 (권한 유실 방지)
```

> **기본 동작 변경 배경 (공개 #64)**: 이전에는 Step 7 이 targets 전체를 무조건 재복사하고 orphan 을 삭제하여, 사용자가 수정한 `public/.htaccess` 커스텀 블록이나 `_bundled/` 아래 커스텀 확장이 소실되는 사고가 반복 제보되었다. 기본 동작을 "코어가 실제로 변경/추가한 파일만 적용(3-way)"으로 전환해 발생 표면을 제거했다. 전체 덮어쓰기 + 정리를 원하면 `--prune` 을 지정한다. "코어도 바꾸고 사용자도 바꾼" 파일은 코어 버전으로 갱신되지만 백업에 원본이 보존되어 복구 가능하다.

> **증분 모드 잔존 stale 파일 정리**: 기본(증분) 모드는 orphan 을 삭제하지 않으므로, 신 버전에서 제거된 파일이 활성 디렉토리에 잔존할 수 있다. 완료 요약이 잔존을 안내하며, 정리하려면 같은 업데이트를 `--prune` 으로 다시 실행한다. 단발성 정리 도구 `php artisan hotfix:rollback-stale-files --prune` 은 **자동 롤백 뒤**(백업 디렉토리와 `_new_files_manifest.json` 이 남아 있는 상태) 전용이다 — 성공한 업데이트는 Step 11 에서 백업을 지우므로 그 뒤에 실행하면 "사용 가능한 백업이 없습니다" 로 끝난다. 완료 안내문이 이 명령을 가리키던 것은 7.0.10 에서 걷어냈다. 상세 사용법: [docs/cheatsheet.md](../cheatsheet.md) "단발성 결함 보정 (hotfix)".

> **격리 디렉토리는 루트째 지우고, 이번 실행이 만든 것은 소유권 기준에서 뺀다 (7.0.10)**: 업데이트 소스는 `storage/app/core_pending/core_{Ymd_His}/` 격리 디렉토리 안에 놓이는데, ZIP·GitHub 경로는 그 안쪽 `extracted/{루트}/` 를, `--local` 은 `local_source/` 를 소스 경로로 돌려준다. 7.0.9 까지의 정리 단계는 그 소스 경로만 지워 `core_{ts}/extracted/` 껍데기가 업데이트마다 남았고, sudo 실행이면 root 소유(0770)라 운영자·웹서버 계정이 지울 수 없었다(같은 서버의 7.0.0 부터의 설치본마다 하나씩 실측). 세 층으로 닫았다.
>
> - **부모 정리**: `cleanupPending()` 이 `resolveStagingRoot()` 로 격리 디렉토리 루트까지 올라가 통째로 지운다. pending 기준 디렉토리 밖 경로(`--source` 외부 디렉토리)는 올라가지 않는다.
> - **자식 청소**: 부모는 구버전 클래스를 메모리에 들고 있어 이 수정이 다음 업데이트부터 효력이 있으므로, 신버전 코드로 도는 두 자식(`core:execute-upgrade-steps`, `core:execute-bundled-updates`)이 종료 직전 `sweepEmptyStagingDirectories()` 로 **파일이 하나도 없는** `core_*` 디렉토리만 치운다. 부모가 쓰는 중인 격리 디렉토리는 파일을 갖고 있어 술어상 제외된다. 구버전 부모에서 올라오는 업데이트(7.0.9→7.0.10)는 번들 일괄 업데이트 자식이 부모 정리 뒤에 돌므로 그 자리에서 껍데기가 사라진다.
> - **스냅샷 제외**: 항목별 소유권 스냅샷은 격리 디렉토리가 생긴 **뒤에** 찍힌다. 제외하지 않으면 root 가 만든 추출본이 "원본 소유권" 으로 기록되고 복원이 잔존물을 다시 root 로 되돌리므로, 부모는 `snapshotOwnershipDetailed(..., excludes: [격리 디렉토리 루트])` 로 이번 실행의 것을 뺀다. 그러면 잔존물이 생겨도 상위 `storage/app/core_pending` 의 재귀 chown 이 운영자 계정·웹서버 그룹으로 맞춰 지울 수 있다.

> **`public/storage` symlink 보존 + 종료 시 복구 (공개 #43)**: 심층 방어 2층 구조로 `--prune` 실행 후에도 `public/storage` symlink 가 정상 유지된다.
> - **층 1 (예방)**: `--prune` 은 `public` 타깃에서 orphan(릴리즈 소스에 없는 항목)을 삭제하는데, 런타임 symlink 인 `public/storage` 는 릴리즈 소스에 없어 orphan 으로 판정되어 삭제되던 결함이 있었다(업로드 파일 404). `applyUpdate` 가 `public` 타깃 처리 시 `FilePermissionHelper::copyDirectory(..., preserveLinkPaths: ['storage'])` 로 화이트리스트를 전달해, 매칭되는 orphan symlink/junction 만 삭제에서 제외한다. 화이트리스트 밖 orphan 링크는 기존대로 삭제된다(정밀 보호 — 무조건 보존 아님).
> - **층 2 (복구)**: 업데이트 종료 시점(정상 Step 11 + 핸드오프 catch)에 `StorageLinkHelper::ensurePublicStorageLink()` 를 호출해 `public/storage` 가 정상 링크인지 확인하고, 부재/손상이면 `storage/app/public` 을 가리키는 링크를 (필요 시 `.broken.{YmdHis}` rename 백업 후) 재생성한다. 버전 무관 매 업데이트 실행. Windows `SeCreateSymbolicLink` 권한 부족 시 junction(`mklink /J`) 폴백까지 시도하고, 그래도 실패하면 rename 원복 + 수동 `storage:link` 안내(데이터 손실 없음). 롤백 catch 경로는 백업 복원이 링크를 원상 회복하므로 대상 아님.
>   - **소유권 상속**: `symlink()` 은 소유권 인자가 없어 sudo 실행 시 링크가 root:root 로 생성된다. 재생성 직후 부모 `public/` 의 owner/group 을 기준으로 `lchown`/`lchgrp`(링크 자체 대상 — `chown` 은 target 을 따라감) 보정해 원래 앱 실행 유저 소유를 유지한다. 이 프로젝트의 "신규 항목은 부모 소유권 상속"(`FilePermissionHelper`) 컨벤션과 일치. 비-POSIX/함수 부재/권한 부족 시 무해하게 skip.
> - 이 복구 로직은 beta.5 DataMigration `RecoverPublicStorageSymlink` 와 `StorageLinkHelper` 로 일원화되어 있다(부재→재생성 케이스까지 상위호환).

> **루트 보호 파일(`/index.php`·`/.htaccess`)의 갱신 결과는 반드시 소비한다.** 루트 `.htaccess` 만은 통째 복사가 아니라 코어 관리 블록(`# BEGIN G7` ~ `# END G7`)만 교체한다 — 블록 밖의 운영자 규칙을 보존하기 위해서다. 그 갱신기(`RootHtaccessUpdater::apply()`)는 `bool` 이 아니라 `RESULT_*` 문자열을 돌려주며, 호출부는 아래 갈래를 **모두** 처리해야 한다.
>
> | 결과 | 뜻 | 호출부가 할 일 |
> |---|---|---|
> | `updated` · `unchanged` | 정상 | 없음 |
> | `preserved` | 대상에 코어 관리 블록이 없어 **손대지 않았다** | `noticeMessage()` 를 운영자에게 노출. 운영자가 일부러 지웠을 수도 있지만, 예전 사이트의 `.htaccess` 가 그대로 있거나 전송 중단으로 빈 파일이 된 경우도 같은 결과다 — 후자는 **보호 규칙이 미적용으로 남았다**는 뜻이고, 문서 루트가 프로젝트 루트인 배치에서 그것은 설정 파일 노출이다 |
> | `created` | 대상이 없어 **새로 만들었다** | `noticeMessage()` 를 운영자에게 노출. 프로젝트 루트가 웹에 열려 있던 배치라면 이제 보호되지만, 그 사이트가 `/public/…` 주소를 써 왔다면 **그 주소가 정본 주소로 301 이동한다** — 주소 체계가 바뀐다는 뜻이라 운영자가 알아야 한다 |
> | `duplicate_block` · `broken_block` · `source_invalid` · `destination_invalid` · `read_failed` | 실패 | `isFailure()` 로 판정하고 `failureMessage()` 를 노출. 업데이트 자체는 계속한다. `read_failed` 는 소스·대상을 **읽지 못한** 경우다 — 그것을 `preserved` 로 접으면 「운영자가 지웠다」로 오인된다. `broken_block` 은 마커가 **하나만** 있거나(BEGIN 만 · END 만) 역순인 경우다 — 지운 것이 아니라 **잘린** 것이라(전송 중단·부분 쓰기·손편집) 그 파일은 지금 보호 규칙이 온전하지 않으며, 이것도 `preserved` 로 접으면 안내가 원인을 가린다. 마커가 하나도 없는 파일만 `preserved` 다 |
> | `write_failed` | 쓰지 못했다 — 보호 규칙이 구버전으로 남았다 | 위 행과 같다 |
>
>
> **보호 대상은 루트 `.htaccess` 하나다.** `public/.htaccess` 는 마커 블록 방식이 아니라
> `public` 디렉토리 복사로 갱신되며, 운영자 수정본은 증분 판정에서 `changed` 로 분류되어
> 코어 판본으로 덮인다(백업에는 원본이 남는다). 레이아웃 ②에서는 요청이 두 파일을 차례로
> 지나가는데 그중 하나만 보존되는 구조이므로, 서버 규칙을 덧붙일 때는 **루트 `.htaccess` 의
> 블록 밖**에 적는다.
>
> 결과를 버리면 그 사이트는 「적용 완료」만 보고 무방비로 남는다. 콘솔 커맨드는 `CoreUpdateService::getLastRootFileWarnings()` 로 모아 한 번에 출력하며, **롤백 경로도 같은 통로를 쓴다** — 되돌리기가 그 두 파일을 지웠을 때 그 사실이 로그 파일에만 남으면 운영자는 알 수 없다. 웹 오케스트레이터를 만들 때도 같은 갈래를 같은 통로로 처리한다 — 통보 대상은 `preserved` 와 `created` **둘**이다.

> **루트 `web.config` 는 `.htaccess` 와 같은 마커 블록 규칙을 따른다.** `BEGIN G7` / `END G7` 을 담은 XML 주석 한 줄씩이 경계이고(`<configuration>` 바로 안쪽, 줄 시작), 코어는 그 블록(`system.webServer` 요소 전체)만 교체한다 — 운영자가 블록 밖에 둔 `<configuration>` 의 다른 자식 요소(`<system.web>`·`<location>`)는 보존되고 결과는 유효한 XML 로 남는다. 종전에는 이 파일이 「코어 소유, 통째 교체」였다 — 같은 사이트에서 두 파일이 정반대로 동작했다. 판정 로직(마커 없음·두 벌·손상·CRLF·읽기/쓰기 실패·링크 대상·부분 쓰기)은 `AbstractRootMarkerFileUpdater` 하나가 소유하고, `RootHtaccessUpdater`·`RootWebConfigUpdater` 는 마커 문자열·로그 태그·문구 키 접두만 정한다. 어느 루트 파일이 갱신기를 지나는지는 `CoreBackupHelper::ROOT_MARKER_FILE_UPDATERS`(보호 파일 SSoT 에서 도출)가 정하며, 정상 targets 분기와 자동 발견 폴백 분기 둘 다 그 표를 본다. `web.config` 의 통보 문구는 `settings.core_update.root_web_config_*` 다.
>
> **형제 파일 루트 `index.php` 도 같은 통로를 쓴다.** 그 파일은 마커 블록이 없어 통째로 교체되는데, 교체 대상이 코어 shim 이 아니면(레이아웃 ③ 사이트가 두던 자체 랜딩·리다이렉트) 그 내용은 백업에만 남는다. `CoreUpdateService::recordRootIndexReplacement()` 가 그 사실을 같은 경고 버퍼에 싣는다. 교체 직전 사본의 디렉토리 이름은 `BackupDirectoryName::stamp()` 가 만든다 — 잔존물 정리기의 판정 정규식이 같은 곳을 보므로 한쪽만 바뀌는 일이 없다.

> **`deploy/**` 서버 설정 조각은 운영자 수정본을 보존한다.** 조각 머리말이 운영자에게 그 파일을 직접 고치라고 지시하고(`fastcgi_pass`·하위 폴더 접두사), nginx 는 그 파일을 `include` 로 제자리에서 읽는다. 그래서 `deploy` 대상은 디렉토리 통째 복사가 아니라 `CoreUpdateService::applyDeployFragments()` 가 파일마다 판정한다 — 근거는 **코어가 마지막으로 쓴 내용의 해시 원장**(`storage/app/core-update/deploy-fragments.json`)이다. 백업 스냅샷은 근거가 못 된다(현재 디스크의 사본이라 언제나 현재 디스크와 같다).
>
> | 디스크 상태 | 동작 | 통보 |
> |---|---|---|
> | 새 판본과 같다 | 원장만 갱신, 잔존 `.dist` 제거 | 없음 |
> | 없다 | 신규 복사 | 없음 |
> | 원장의 해시와 같다(코어가 쓴 그대로) | 정상 갱신 + 잔존 `.dist` 제거 | 없음 |
> | 그 외 — 운영자 수정본, 또는 원장이 없어 판정 불가(이 기능 이전 설치본의 첫 업데이트) | 덮어쓰지 않고 새 판본을 `<이름>.dist` 로 옆에 둔다. **원장에는 새 판본의 해시를 기록한다** | `deploy_fragment_preserved` 를 같은 경고 버퍼에 싣는다 |
> | 위와 같은데 옛 `.dist` 가 이미 있고 그 내용이 새 판본과 다르다 | `.dist` 는 덮어쓰지 않고(운영자가 합치던 중간 작업일 수 있다) 새 판본을 `<이름>.dist.new` 로 둔다. `.dist.new` 는 코어가 만든 파일이라 다음 업데이트가 자유롭게 갱신한다 | `deploy_fragment_dist_conflict` |
>
> **원장의 의미는 「코어가 마지막으로 제시한 판본」이다** — 「코어가 쓴 것」이 아니다. 보존한 조각도 새 판본의 해시를 기록하기 때문에, 운영자가 안내대로 `.dist` 를 합쳐 파일이 새 판본과 같아지면 다음 업데이트에서 디스크 == 원장이라 정상 갱신으로 탈출한다. 합치지 않으면 디스크 ≠ 원장이라 보존이 유지된다. 기록하지 않으면 원장이 없던 사이트는 합친 뒤에도 **매 업데이트마다** 「판정 불가 → `.dist`」 가 반복된다(오류 없음).
>
> **원장이 없는 설치본은 씨앗한다.** 설치 완료 시점(인스톨러)과 7.2.0 업그레이드 스텝(`upgrades/data/7.2.0/migrations/05_SeedDeployFragmentLedger.php`)이 `CoreUpdateService::seedDeployFragmentLedger($projectRoot)` 와 같은 로직으로 현재 디스크의 해시를 원장에 쓴다 — 그 시점의 디스크는 **사이드카(`.dist`·`.dist.new`)가 없는 조각에 한해** 릴리즈 판본과 같다. 사이드카가 있는 조각은 씨앗하지 않는다(씨앗하면 운영자 수정본이 「코어가 쓴 그대로」로 읽혀 다음 업데이트가 덮어쓴다). 원장이 이미 있으면 손대지 않는다. 스텝은 업그레이드 경로 규율(기존 클래스의 신규 메서드 호출 금지)로 로컬 로직을 갖고, 서비스 씨앗기와의 결과 일치는 `tests/Unit/Upgrades/SeedDeployFragmentLedgerTest` 가 대조한다.
>
> **원장 쓰기 실패는 통보 통로에 실린다** (`deploy_fragment_ledger_write_failed`) — 원장은 다음 업데이트의 판정 근거라, 쓰지 못한 채 넘기면 그 사이트는 다음 업데이트에서 전 조각이 「판정 불가」로 떨어지고 운영자는 이유를 알 수 없다. 보존·충돌·쓰기 실패는 전부 업그레이드 로그 채널(`upgrade`)에도 함께 남는다.
>
> `.dist`·`.dist.new` 는 웹에서 열리지 않는다 — `deploy/` 디렉토리와 `dist` 확장자가 네 서버 조각의 차단 목록에 모두 있다(`ServerConfigParityTest`). 이 경로는 orphan 을 지우지 않는다(`--prune` 이어도) — 운영자가 둔 파일이 사라지는 것이 곧 이 규칙이 막는 결함이다. 롤백(`restoreFromBackup()`)은 `.dist`·`.dist.new` 와 원장을 함께 걷는다: 복원된 내용이 코어가 쓴 것인지 운영자 수정본인지 그 자리에서는 알 수 없으므로 다음 업데이트가 「판정 불가 → 보존 + 안내」 쪽으로 떨어지게 둔다 — 그리고 그 보존이 원장을 다시 씨앗하므로 한 번뿐이다.

> **경고는 반환값에도 실린다.** `applyUpdate()` 는 `['root_file_warnings' => list<string>]` 을, `restoreFromBackup()` 은 `['failed_targets' => list<string>, 'root_file_warnings' => list<string>]` 을 돌려준다 — `getLastRootFileWarnings()` 와 같은 값이다. 콘솔 커맨드도 정상 경로·롤백 경로에서는 **반환값**을 출력 통로에 넘기고, 게터는 적용이 예외로 끊겨 반환값이 없는 경로에서만 쓴다 — 커맨드마저 게터만 쓰면 반환값 축은 소비처가 0 이라 조용히 죽는다. 무CLI 웹 워커처럼 커맨드가 아닌 소비처에게는 반환값이 곧 계약이다.

> **자동 발견 폴백의 배경 (engine-v / beta.4 이후)**: Step 7 은 부모 프로세스의 `config('app.update.targets')` 를 사용한다. 부모는 업그레이드 *직전* 의 코드/메모리 상태이므로 신버전이 도입한 신규 최상위 디렉토리(예: beta.4 의 `lang-packs/`) 가 부모의 stale targets 에서 누락된다. 폴백은 이 결함을 안전망으로 차단하며, `config/app.php` 의 `update.protected_paths` 가 런타임 데이터(`storage`)·로컬 환경(`.env*`)·별도 파이프라인 산출물(`vendor`)·개발 메타(`.git`/`.claude`/`.serena` 등) 의 의도치 않은 덮어쓰기를 방지한다.

### Step 9: 마이그레이션 + 동기화

```text
1. php artisan migrate --force
2. syncCoreRolesAndPermissions() — config/core.php의 roles/permissions 동기화
3. syncCoreMenus() — config/core.php의 menus 동기화
```

> **변경 이력 (7.0.0-beta.2)**: `syncCoreMailTemplates()` 단계는 알림 시스템 통합으로 제거되었습니다. 메일 템플릿은 `notification_definitions` + `notification_templates` 로 통합되었으며, `Upgrade_7_0_0_beta_2` 가 운영 환경 데이터 이관과 알림 정의 시드를 처리합니다.

> 역할/권한/메뉴 동기화는 `user_overrides` 패턴을 사용하여 사용자 커스터마이징 보존.
> 상세: [extension-update-system.md § 13](../extension/extension-update-system.md#13-역할권한메뉴-동기화)

### Step 10: 업그레이드 스텝

```text
1. upgrades/ 디렉토리 스캔
2. Upgrade_X_Y_Z.php 패턴 매칭 → 버전 변환
3. fromVersion < stepVersion <= toVersion 범위 필터링
4. version_compare 자연 정렬 (오름차순)
5. 각 스텝 순차 실행 (UpgradeContext 전달)
```

> **spawn 자식 진입 시 PSR-4 autoload 갱신 (engine-v / beta.4 이후)**: `core:execute-upgrade-steps` (Step 10) 와 `core:execute-bundled-updates` (Step 12) 의 spawn 자식은 `handle()` 진입 직후 `app(ExtensionManager::class)->updateComposerAutoload()` 를 1회 호출한다. 부모 프로세스의 `bootstrap/cache/autoload-extensions.php` 가 stale 한 경우 자식이 그 매핑을 그대로 로드 → upgrade step 또는 bundled update 안에서 모듈/플러그인의 `Models`/`Services` 같은 다른 클래스를 lazy autoload 시 "Class not found" 발생. 진입 시점 1회 호출로 모든 후속 작업이 fresh autoload 환경에서 실행됨을 보장한다 (개별 step 마다 호출할 필요 없음). 본 진입점들은 자체가 spawn 자식 (별개 PHP 프로세스) 이라 디스크의 fresh `ExtensionManager` 클래스를 메모리에 로드한 상태 — 직접 메서드 호출도 stale 가능성 없음.

> **단독 실행 안전성 (beta.6 이후)**: `core:execute-upgrade-steps` 는 HANDOFF 안내 또는 수동 복구 목적으로 운영자가 직접 호출되는 경로가 있다. 단독 실행 시 자식은 기본값으로 부모 Step 9 (`runMigrations` + `reloadCoreConfigAndResync`), Step 11 (`updateVersionInEnv` + `clearAllCaches`), Step 12 (번들 확장 일괄 업데이트) 를 자체적으로 수행해 단일 명령으로 업그레이드를 완결한다. 부모 `CoreUpdateCommand::spawnUpgradeStepsProcess()` 는 자식 명령 라인에 `--skip-migrations`, `--skip-resync`, `--skip-version-env`, `--skip-cache-clear`, `--skip-bundled-updates` 5개를 무조건 추가해 중복 회피한다 — 부모가 자식 종료 후 동일 단계를 직접 수행하기 때문이다.

> **spawn 자식은 이전 버전의 config 캐시로 부팅한다**: 7.0.9 이하 부모는 Step 10(spawn) 전에 config 캐시를 비우지 않았다 — `clearAllCaches()` 는 Step 11 이다. 그래서 이전 버전 설치본에 `bootstrap/cache/config.php` 가 있으면(설치 마법사·설정 저장·확장 업데이트가 만든다) 자식은 그 캐시로 부팅하고, 자식의 `config('app.version')` 은 부모가 env 로 넘긴 `APP_VERSION={toVersion}` 이 아니라 캐시에 박힌 fromVersion 이다. 업데이트 흐름 안에서 "지금 프로세스의 코어 버전" 을 판정하는 코드는 `config('app.version')` 을 직접 읽지 않고 `CoreVersionChecker::getCoreVersion()`(env 우선, config 폴백)을 쓴다. `runUpgradeSteps()` 의 stale 메모리 가드가 config 만 읽던 시절에는 정상 spawn 자식을 stale 부모로 오판해 스텝이 0건인 릴리즈에서도 핸드오프로 중단됐다(7.0.9→7.0.10). 부모 in-process fallback 에서는 env 가 `.env` 의 fromVersion 이므로 가드는 그대로 발동한다.

> **단독 실행은 가드 대상이 아니다 (7.2.0)**: 가드의 목적은 옛 코드를 메모리에 올린 `core:update` 부모가 in-process 로 새 스텝을 돌리는 것을 막는 것이다. `core:execute-upgrade-steps` 는 spawn 자식이든 운영자 단독 실행이든 새로 시작한 프로세스라 디스크의 새 코드를 읽으므로 `runUpgradeSteps(..., freshProcess: true)` 로 가드를 건너뛴다. 종전에는 단독 실행도 `.env` 의 옛 `APP_VERSION` 으로 판정돼, 핸드오프 안내대로 재개 명령을 실행해도 같은 가드가 다시 막았다(abort 모드에서 종료 코드 75). `.env` 의 판 번호는 종전대로 스텝이 성공한 뒤에만 갱신한다. 가드가 남은 경로의 재개 안내는 `APP_VERSION=<대상 판> php artisan core:execute-upgrade-steps --from=… --to=… --force` 와 PowerShell 형식(`$env:APP_VERSION='<대상 판>'; php artisan …`)을 함께 적는다.
>
> **spawn 실패 모드는 config 하나만 읽는다 (7.2.0)**: `CoreUpdateService::spawnFailureMode()` 가 `app.update.spawn_failure_mode`(기본 `abort`)만 읽는다. 종전에는 코드 두 곳의 폴백값이 `fallback` 이라 그 키가 없는 config 캐시로 부팅하면 같은 설치가 다르게 움직였다. 값이 없거나 모르는 값이면 `abort` 다.

### 점검 모드 통과 수단과 해제 시점 (7.2.0)

점검 화면은 Laravel 기본 점검 미들웨어 대신 `MaintenanceModePage` 가 만든다. 이 미들웨어가 `php artisan down` 옵션과 아래 서버 로컬 설정을 따른다.

| `down` 옵션·설정 | 동작 |
| --- | --- |
| `--secret=<값>` | `/<값>` 을 열면 우회 쿠키를 주고 `/` 로 보낸다 — 그 쿠키로 들어간다(7.2.0 전에는 쿠키를 발급하지 않아 503 이었다) |
| `G7_MAINTENANCE_EXCEPT` (`core.maintenance.except`) | 쉼표 구분 경로(`*` 와일드카드) — 그 경로는 통과. `down` 명령에는 경로 예외 옵션이 없어 서버 로컬 설정으로 둔다 |
| `G7_MAINTENANCE_ALLOW_IPS` (`core.maintenance.allow_ips`) | 쉼표 구분 IP·CIDR — 신뢰 프록시를 거친 요청 IP 가 맞으면 통과 |
| `--retry=<초>`·`--refresh=<초>` | `Retry-After`·`Refresh` 헤더 |
| `--status=<코드>`·`--redirect=<경로>` | 응답 상태 코드 · 다른 경로의 이동 대상 |

웹 코어 업데이트의 진행·되돌리기 경로 면제(`MAINTENANCE_EXEMPT_URIS`)는 그대로다.

점검 모드를 켜고 끄는 수단은 `php artisan down` / `php artisan up` 뿐이다. 환경설정에 있던 `general.maintenance_mode` 는 어떤
코드도 읽지 않아 켜 두어도 사이트가 막히지 않았다 — 7.2.0 에서 기본값·저장 검증에서 지웠고, 저장 파일에 남은 값은 7.2.0 업그레이드
데이터 스텝 `10_RemoveMaintenanceModeSetting` 이 지운다.

`core:update` 는 번들 확장 일괄 업데이트가 끝난 뒤에 점검 모드를 푼다 — 일괄 업데이트가 실패하거나 예외로 멈춰도 그 호출을 감싼 `finally` 에서 푼다.
종전에는 코어 마무리 직후 풀어, 새 코어와 옛 확장이 섞인 사이트가 일괄 업데이트 동안 열려 있었다. 웹 경로는 단계 순서(번들 → 마무리)가 이미 같다.

### 실행 전 계획 보기 — `g7:migrate-plan` (7.2.0)

```bash
php artisan g7:migrate-plan [--scope=all|core|extensions] [--json]
```

실행 대기 마이그레이션의 SQL 을 연결의 pretend 로 모아 보여 준다 — DB·파일은 바뀌지 않는다. 항목마다 출처(`source`: `core`·`custom`·`module:<id>`·`plugin:<id>`)와
파괴적 종류(`drop_table`·`drop_column`·`drop`·`rename`·`change`·`not_null`)를 적고, 컬럼 삭제·이름 변경·타입 변경·NOT NULL 전환은 `defer_recommended: true` 다
(옛 코드가 새 스키마와 함께 도는 기간에 깨질 수 있다). core 범위는 `database/migrations` 와 마이그레이터에 등록된 비확장 경로(`database/migrations/custom` 등) 전부다 —
`php artisan migrate` 가 실제로 돌릴 집합과 같다. 확장은 번들 폴더(새 판) 기준이다.

업그레이드 스텝은 현재 판보다 새로운 것을 나열하고, `AbstractUpgradeStep::describe()`(선택 구현, `['summary' => …, 'destructive' => bool]`)가 없으면 「설명 없음」이다.
마이그레이션 안에서 DB 를 조회해 분기하는 코드는 pretend 에서 조회가 실행되지 않아 실제와 다를 수 있다 — 출력 맨 위에 이 경고를 낸다.

>
> 같은 이유로 자식이 `config('app.update.*')` 로 읽는 목록(쓰기 권한 디렉토리 등)도 캐시에 박힌 옛 목록이다 — 신버전이 항목을 추가해도 자식에게 보이지 않는다. 방어는 두 겹이다: ① 부모(7.0.10+)는 `spawnUpgradeStepsProcess()` 가 `proc_open` 직전에 `ConfigCacheHelper::clear()` 로 캐시를 비워 자식이 디스크 config + `.env` + spawn env 로 부팅하게 한다(캐시는 Step 11 이 다시 만든다). ② 자식(7.0.10+)은 이전 버전 부모가 캐시를 남겨 둔 경우를 위해, 캐시 파일이 있으면 `CoreUpdateService::freshDiskUpdateConfig()` 로 디스크의 `config/app.php` 를 직접 읽는다 — 캐시 부팅에서는 `.env` 도 로드되지 않으므로 그 안에서 `.env` 를 먼저 불변 로드한다(프로세스 env 의 `APP_VERSION` 은 덮어쓰지 않는다).

#### spawn 전 캐시 정리 계약 (3계층)

config 캐시와 같은 문제가 **패키지 매니페스트**(`bootstrap/cache/packages.php` · `services.php`)에도 있고, 이쪽은 결과가 더 무겁다. Laravel 의 `PackageManifest` 는 `packages.php` 가 있으면 stale 여부를 검사하지 않고 그대로 읽고, `ProviderRepository` 가 거기 등재된 eager provider 를 `new` 한다. Step 6/8 이 vendor 를 `--no-dev` 로 교체해도 두 파일은 Step 11 까지 이전 설치본의 것이 남으므로, 이전 설치본이 `composer install`(옵션 없음)로 깔린 개발용 설치였다면 자식은 새 vendor 에 없는 provider 를 찾다 **부팅 단계에서** 죽는다. 예외는 앱 로그가 열리기 전이라 남지 않고, 부모에게는 자식의 비정상 종료로만 보인다 (7.0.9 → 7.0.10 실사례).

| 계층 | 위치 | 막는 실패 | 잠그는 테스트 |
|------|------|-----------|--------------|
| ① 부모 선정리 | `CoreUpdateCommand::spawnUpgradeStepsProcess()` 가 `proc_open` 직전 `PackageManifestCacheHelper::clear()`. 지우지 못한 파일이 있으면 그 경로를 업그레이드 로그에 경고로 남긴다 — 권한·소유권 불일치면 자식의 계층 ② 도 같은 이유로 실패해 증상은 제보와 같은 「Class not found」 인데, 이 경고가 원인을 가리키는 유일한 흔적이다 | 7.0.11+ 부모가 띄우는 자식의 부팅 실패 | `CoreUpdateCommandStalePackageManifestTest` |
| ② 자식 자가 치유 | `bootstrap/app.php` 가 `G7_UPDATE_IN_PROGRESS=1`(또는 명령줄 SAPI 에서의 업데이트 argv)이면 두 파일을 스스로 삭제 | **이미 배포된** 7.0.9·7.0.10 부모 아래에서 도는 신버전 자식 — 그 부모 코드는 고칠 수 없다 | 같은 테스트 (플래그 유·무 대조군 포함) |
| ③ 버전 판독 범위 | `CoreVersionChecker::getCoreVersion()` 의 env 우선은 `CoreUpdateContext::isInProgress()` 트리 안에서만 | 업데이트 **전에** 뜬 `php artisan serve`·큐 워커가 옛 `APP_VERSION` 을 물고 확장을 `incompatible_core` 로 끄는 것 | `CoreVersionCheckerEnvPriorityTest` · `CoreUpdateContextTest` |
| ④ APP_VERSION 정합 | `bootstrap/app.php` 가 계층 ②와 같은 조건에서 `bootstrap/g7-update-env.php` 의 `g7_reconcile_update_app_version()` 을 불러, 프로세스 env 의 `APP_VERSION` 이 디스크 `.env` 값보다 낮으면 `$_ENV`·`$_SERVER`·`putenv` 를 디스크 값으로 **올린다**(낮추지 않는다 — 업그레이드 스텝 자식은 `.env` 보다 높은 toVersion 을 받는다) | **이미 배포된** 7.0.0~7.0.10 부모는 `.env` 만 바꾸고 자기 env 는 옛 버전으로 둔다. 그 env 를 물려받은 자식과 부모 안의 `config:cache` 일회용 앱이 옛 버전을 config/hooks 캐시에 구워, 업데이트 뒤 첫 프로세스가 새 코어를 요구하는 확장을 `incompatible_core` 로 끄던 것(공개 #145·공개 #137) | `UpdateAppVersionReconcileTest` · `UpdateAppVersionReconcileProcessTest` |
| ⑤ 자식 env 고정 | 번들 일괄 업데이트 자식·언어팩 단계 새 프로세스·훅 캐시 새 프로세스의 env 를 `UpdateChildEnv::build()` 로 만든다 — 업데이트 트리 안이면 `APP_VERSION=getCoreVersion()`·`G7_UPDATE_IN_PROGRESS=1` 을 명시(트리 밖은 상속 그대로) | 부모 env 에만 기대 옛 버전이 다시 캐시에 구워지는 것 | `BundledSpawnPinsAppVersionTest` |

계층 ②·④는 `config:cache`/`route:cache` 가 만드는 in-process 일회용 앱에도 발동한다 — 그 부팅도 `bootstrap/app.php` 를 다시 require 하고 플래그를 상속하기 때문이다. 웹 요청·`queue:work`·운영자 셸은 플래그가 없어 no-op 이다.

argv 채널은 명령줄 SAPI(`cli`·`phpdbg`)에서만 읽는다. CGI/FPM 은 `register_argc_argv=On` 이면 `$_SERVER['argv']` 를 쿼리스트링을 `+` 로 쪼갠 값으로 채우므로(`GET /?x+core:update` → `argv[1] === 'core:update'`), 그 게이트가 없으면 비인증 웹 요청이 요청마다 매니페스트를 지우고 다시 만들게 된다. env 플래그 채널은 웹 요청으로 주입할 수 없어 그대로 두며, 웹 요청 안에서 시작되는 업데이트 흐름은 그 플래그를 프로세스 안에서 세워 판정된다.

계층 ③의 판정은 `App\Support\CoreUpdateContext` 가 단독으로 소유하고 `CoreServiceProvider::isCoreUpdateInProgress()` 가 그리로 위임한다. 자동 비활성화 로그의 `core_version` 도 같은 게터를 쓴다 — 로그가 `config('app.version')` 을 적고 판정은 env 로 하면 운영자가 보는 근거와 실제 판정이 어긋난다.

#### 재실행 안내의 권한 분기 (핸드오프 catch)

spawn 자식이 실패(`proc_open` 미지원 · 비정상 종료 · silent skip)하고 `spawn_failure_mode=abort`(기본값) 이면, 파일·버전은 이미 `toVersion` 으로 반영되지만 업그레이드 스텝이 미실행 상태로 남아 운영자에게 `core:execute-upgrade-steps` 재실행을 안내한다. 이때 **sudo(root) 로 `core:update` 를 실행한 경우**, 안내받은 명령을 root 로 그대로 재실행하면 스텝이 만드는 파일·캐시가 root 소유로 생성되어 이후 웹서버(php-fpm www-data 등) 요청이 그 경로에 쓰기 실패한다.

`CoreUpdateCommand::surfaceResumeCommandWithPermissionGuidance()` 는 실행 환경을 4가지로 분류(`classifyResumeExecutionContext()`)하여 안내를 분기한다:

| 모드 | 조건 | 안내 |
|------|------|------|
| `non_root` | root 아님(일반 SSH 사용자 = 파일 소유자) / posix 미지원(Windows) / 공유 호스팅(웹서버·PHP·실행 유저 동일) | 명령만 그대로 출력 |
| `root_web_known` | root 실행 + 웹서버 계정 식별 가능 + 실행 사용자와 다름 | `sudo -u {계정} {명령}` + 계정명 명시 경고 |
| `root_web_symmetric` | root 실행 + 웹서버 계정이 root 로 추정 (root 서비스 구성) | 명령만 그대로 출력 |
| `root_web_unknown` | root 실행 + 웹서버 계정 추정 실패 | `sudo -u <웹서버계정>` placeholder + 계정 확인 안내 |

웹서버 계정은 `FilePermissionHelper::inferWebServerOwnership()` 이 `storage/*`·`bootstrap/cache` 쓰기 영역 소유자로 추정한다.

### Step 11: 마무리

```text
1. .env의 APP_VERSION 갱신
2. 캐시 클리어: config, cache, route, view (spawn 직전에도 config + 패키지 매니페스트 선정리)
3. bootstrap/cache 파일 삭제 (services.php, packages.php)
4. php artisan package:discover 재실행
5. php artisan extension:update-autoload (코어 업데이트로 _bundled 변경 가능)
6. _pending 격리 디렉토리(core_{ts}) 루트째 삭제
7. 성공 시 백업 삭제 (이후 `hotfix:rollback-stale-files` 는 대상이 없다)
8. 유지보수 모드 해제
9. 큐 워커 재시작 신호 (queue:restart)
```

> 3~4 는 `PackageManifestCacheHelper::rebuild()` 한 호출이다 — spawn 직전 선정리(계층 ①)와 같은 삭제 로직을 공유한다.
>
> 9 는 상주 큐 워커가 부팅 시점의 코어 코드·config 를 계속 쓰는 것을 막는다. 워커는 옛 코드로도 잡을 정상 처리하므로 오류가 나지 않고, 운영자가 손수 재시작할 때까지 조용히 어긋난 채 돈다. 핸드오프 cleanup 과 `core:execute-upgrade-steps` 단독 실행의 사후 단계도 같은 신호를 보낸다. 롤백 catch 는 제외다 — 백업으로 되돌린 옛 코드가 다시 도는 자리라 재기동시킬 이유가 없다.

### Step 12: _bundled 확장 일괄 업데이트 프롬프트 (인터랙티브)

> Trait: `App\Console\Commands\Core\Concerns\BundledExtensionUpdatePrompt`

코어 업데이트 완료 후, 번들(`_bundled/`) 에 설치된 확장보다 새 버전이 포함된 경우 일괄 업데이트를 제안합니다.

```text
1. checkAllModulesForUpdates() / checkAllPluginsForUpdates() / checkAllTemplatesForUpdates() 호출
2. update_source === 'bundled' 항목만 수집
3. 감지 결과 없으면 "활성 확장이 최신 번들과 일치합니다" 출력 후 종료
4. 감지 결과 있으면 목록 표시 + 일괄 업데이트 여부 확인 (기본값 yes)
5. 동의 시:
   - 전역 레이아웃 전략 선택 (overwrite | keep)  ※ 섹션 10 참조
   - 예외 확장 지정 여부 확인, yes 이면 다중 선택으로 전략 오버라이드
   - 매니페스트 JSON 직렬화 → `core:execute-bundled-updates` spawn 자식 실행
6. 결과 요약 출력 (성공/실패 건수)
7. 실패가 1건 이상이면 후처리(훅 캐시 재생성·소유권 복원·로그 정리)를 마친 뒤 경고 1줄 + 종료 코드 1

--force 플래그가 코어 업데이트에 지정된 경우: 프롬프트 스킵 + 전역 overwrite 자동 적용 (CI 대응)
```

> **종료 코드 (7.1.1 이후)**: 번들 확장 일괄 업데이트가 하나라도 실패하면 `core:update` 는 코어가 새 버전으로 반영된 상태에서도 종료 코드 `1` 을 반환한다(단독 실행한 `core:execute-upgrade-steps` 도 같다). 자동화 스크립트는 종료 코드 `1` 을 받으면 출력의 실패 항목을 확인한 뒤 해당 확장을 다시 업데이트한다. spawn 자식이 결과 줄을 남기지 못하고 비정상 종료하면 실패 1건으로 센다. 업데이트할 번들 확장이 없거나 모두 성공하면 종전대로 `0` 이다.

> **spawn 위임의 배경 (beta.4 이후)**: 부모(`core:update`) 프로세스는 코어 업그레이드 *직전* 버전의 메모리 상태를 보유한다. 부모가 직접 `ModuleManager::updateModule()` 등을 호출하면 신버전 코어가 도입한 sync 메서드(예: `syncModuleIdentityPolicies` `@since beta.4`)가 메모리에 부재해 호출 자체가 누락된다. `executeBulkUpdate` 는 사용자 선택만 매니페스트로 직렬화한 뒤 `proc_open` 으로 `core:execute-bundled-updates` 를 spawn — 자식은 fresh PHP 프로세스라 디스크의 신버전 코어 코드를 메모리에 로드한 상태에서 update 메서드 호출. proc_open 미지원 환경에서는 in-process fallback 으로 안전 전환. 자식 stdout 은 부모 콘솔로 forwarding 하며, 종료 직전 `[BUNDLED-RESULT]` 표식 라인으로 결과 카운트를 회신한다.

> **항목마다 새 프로세스 (7.2.0 이후)**: 확장 하나를 갱신할 때마다 config·라우트 캐시 재굽기(`config:cache`·`route:cache`)가 일회용 애플리케이션을 부팅하는데, 그 앱은 같은 프로세스 안에서 회수되지 않는다. 자식 하나가 확장 19개를 차례로 갱신하면 그 몫이 쌓여 `memory_limit` 512 MB 에서도 마지막 템플릿 도중 죽고, 뒤의 템플릿·언어팩이 옛 판으로 남았다. 그래서 `core:execute-bundled-updates` 는 항목이 둘 이상이면 순서·집계만 맡고 항목(모듈·플러그인·템플릿·언어팩) 하나마다 `core:execute-bundled-updates --item-process` 를 새 프로세스로 띄운다. 한 프로세스의 메모리는 단독 `module:update` 한 번과 같고 확장 수에 비례하지 않는다.
>
> - **집계**: `success`·`failed` 는 항목 단위 수다. 결과 줄 없이 끝난 항목 프로세스(메모리 고갈 등)는 그 항목 1건만 실패로 세고 다음 항목으로 간다.
> - **실패 항목 안내**: 끝에 「업데이트하지 못한 항목」 목록과 항목별 다시 실행 명령(`php artisan module:update {id} --source=bundled --force --layout-strategy={전략}`, 언어팩은 `php artisan language-pack:update {id} --source=bundled`)을 출력한다. 결과 줄에는 실패가 있을 때만 `failed_items`(종류·식별자·전략·사유)를 덧붙인다 — 이전 판 `core:update` 는 `success`·`failed` 만 읽으므로 7.1.x 에서 올라오는 업데이트도 정확한 수를 받는다.
> - **proc_open 이 없는 서버**: 같은 프로세스에서 차례로 돌되 config·라우트 캐시 재굽기를 `ConfigCacheHelper::deferRebuilds()`·`RouteCacheHelper::deferRebuilds()` 로 끝에 한 번만 한다(그 사이 캐시를 읽는 새 프로세스는 뜰 수 없다). 부모의 in-process fallback 도 같다.
> - **부모가 결과를 못 받은 경우**: 자식 전체가 결과 줄 없이 끝나면 부모는 실패 1건으로 세고, 어느 항목까지 갱신됐는지 알 수 없다는 경고와 종료 코드를 출력한다. 자식은 대상이 0건이어도 정상 종료 경로에서 결과 줄을 늘 내므로, 정상 종료(0)인데 결과 줄이 없어도 실패 1건이다(7.2.0 — 종전에는 「성공 0 / 실패 0」으로 성공 처리).

> **선언형 산출물 일괄 sync — `syncDeclarativeArtifacts`**: `ModuleManager::syncDeclarativeArtifacts(ModuleInterface)` / `PluginManager::syncDeclarativeArtifacts(PluginInterface)` 는 모듈/플러그인의 모든 declarative 데이터(역할·권한·메뉴·cleanup·IDV 정책·알림 정의 — 본인인증 알림 포함) 를 한 묶음으로 동기화하는 public 진입점이다. `installModule` / `updateModule` 트랜잭션이 내부적으로 호출하며, 코어 업그레이드 사후 보정(`Upgrade_7_0_0_beta_4` 등) 도 활성 모듈/플러그인을 순회하며 이 메서드를 호출해 일회성 회복을 수행한다. 각 sync 메서드는 helper 의 user_overrides 보존 패턴을 따르므로 정상 환경 재호출 무해 (멱등).

---

## 3. 백업 및 롤백

> 파일: `app/Extension/Helpers/CoreBackupHelper.php`

### 백업 생성

```text
- CoreBackupHelper::createBackup() 호출
- 대상: config('app.update.targets') + config('app.update.backup_only') + config('app.update.backup_extra')
- 제외: config('app.update.excludes')
- 저장: storage 경로에 타임스탬프 디렉토리 (Ymd_His)
```

### 롤백 흐름 (실패 시)

```text
1. Exception 발생
2. 백업이 존재하면:
   ├─ CoreUpdateService::restoreFromBackup($backupPath)
   ├─ CoreBackupHelper::restoreFromBackup() → 파일 원복
   └─ _pending 디렉토리 삭제
3. 실패 보고서 생성: storage/logs/core_update_failure_YYYYMMDD_HHMMSS.log
4. 업데이트 로그 저장: storage/logs/core_update_failed_YYYYMMDD_HHMMSS.log
5. 유지보수 모드 유지 (수동으로 php artisan up 필요)
```

### 확정 뒤 실패 (복원하지 않는다, 7.1.6+)

Step 11 에서 새 버전을 기록하고 백업을 지운 시점부터는 「확정」이다. 그 뒤의 마무리 단계(유지보수 해제 · 설정/라우트/훅 캐시 재생성 · 업데이트 기록 저장 · 번들 확장 일괄 업데이트 · 소유권 정리)에서 난 오류는 되돌릴 것이 없으므로 위 롤백 흐름을 타지 않는다.

```text
1. Exception 발생 (확정 뒤)
2. 파일 복원 · 번들 배치 기록 되돌림 · 실패 보고서/실패 기록을 하지 않는다
3. 안내: 「업데이트는 {버전} 으로 적용됐습니다. 마무리 단계({단계})에서 오류가 나 나머지를 끝내지 못했습니다: {오류}」
4. 남은 일을 끝내는 명령 출력:
   php artisan config:cache / route:cache / hooks:cache
   php artisan {module|plugin|template}:update <identifier> --source=bundled
5. 유지보수 모드가 아직 켜져 있으면 해제 (새 버전으로 사이트를 연다)
6. 업데이트 기록을 아직 남기지 못했으면 성공 기록으로 남긴다 (실패 기록을 겹쳐 남기지 않는다)
7. 종료 코드 1
```

종전에는 이 구간의 오류도 롤백 흐름으로 가, 이미 지운 백업을 대상으로 「백업에서 복원되었습니다」 라고 안내하고(복원 대상 0건), 번들 배치 기록을 업데이트 전 상태로 되돌리고, 성공 기록과 실패 기록을 둘 다 남겼다. 진행 막대를 만들기 전(확인 질문 이전)에 난 오류가 정의되지 않은 막대를 건드려 원래 오류를 가리던 것도 함께 고쳤다.

확정 전 실패에서 백업 경로는 있는데 디렉토리가 없으면(직접 지웠거나 생성 도중 실패) 「복원할 백업이 없어 복원을 건너뜁니다」 로 알리고 복원을 시도하지 않는다.

### 롤백 실패 시

```text
- 복원 실패 자체는 예외를 전파하지 않음 (에러 로그만 기록)
- 관리자에게 안내: "이전 버전으로 사이트를 운영하려면: php artisan up"
- 유지보수 모드 bypass secret 출력
```

### 되돌리지 않는 것 — 적용된 마이그레이션 (앞으로 고친다)

롤백은 **파일**만 백업으로 되돌린다. 업데이트가 실패하기 전에 이미 적용된 마이그레이션(Step 9)과 끝난 업그레이드 스텝의 데이터 변경은 되돌리지 않는다 — 코어와 확장(모듈·플러그인·템플릿 업데이트의 실패 처리)이 같다.

- 원칙: 되돌리기보다 **앞으로 고친다(fix forward)**. 원인을 고친 판으로 같은 업데이트를 다시 실행한다. 마이그레이션은 이미 적용된 것을 건너뛰고, 업그레이드 스텝은 멱등으로 작성돼 있어 다시 돌아도 안전하다([업그레이드 스텝 작성 가이드](../extension/upgrade-step-guide.md) 원칙 4).
- 이유: `down()` 을 자동으로 돌리면 파괴적 변경(컬럼·테이블 삭제)에서 데이터가 사라지고, 실패 지점에 따라 스키마가 어느 판과도 맞지 않는 상태가 된다.
- 그래서 마이그레이션은 옛 코드와 함께 돌아도 깨지지 않게(추가형 우선) 쓴다. 파괴적 변경이 있는지는 실행 전에 `php artisan g7:migrate-plan` 으로 본다(「2. 업데이트 실행 흐름」의 「실행 전 계획 보기」).
- DB 를 이전 상태로 되돌려야 하면 업데이트 전에 받아 둔 DB 백업으로 복원한다 — 코어 업데이트는 DB 를 백업하지 않는다.

---

## 4. 마이그레이션 vs 업그레이드 스텝

```text
주의: 마이그레이션과 업그레이드 스텝의 역할을 혼동하지 않을 것
```

| 구분 | 마이그레이션 (`database/migrations/`) | 업그레이드 스텝 (`upgrades/`) |
|------|--------------------------------------|-------------------------------|
| **역할** | DB 스키마 변경 (컬럼 추가/삭제/변경, 인덱스, 테이블) | 데이터 백필/변환, 설정 마이그레이션 |
| **실행 시점** | `php artisan migrate` (설치/업데이트 모두) | `php artisan core:update` Step 10 (업데이트 시만) |
| **신규 설치** | 실행됨 | 실행되지 않음 (fromVersion == toVersion) |
| **버전 업그레이드** | 실행됨 | 실행됨 (범위 내 스텝만) |
| **예시** | `$table->uuid('uuid')->nullable()->after('id')` | 기존 레코드 UUID 백필 + NOT NULL 변환 |

### 역할 분리 원칙

```text
✅ 마이그레이션: 테이블/컬럼/인덱스 생성·변경·삭제 (스키마)
✅ 업그레이드 스텝: 기존 데이터 변환, 백필, 설정 구조 변경 (데이터)

❌ 마이그레이션에 데이터 백필 로직 포함 금지
❌ 업그레이드 스텝에 스키마 변경 포함 금지 (Schema::table 등)
   단, 백필 후 NOT NULL 제약 추가처럼 데이터 완결성에 필수인 경우는 예외
```

### 신규 설치 시 데이터 초기화

신규 설치 시 업그레이드 스텝은 실행되지 않으므로, 모델의 `boot()` 이벤트나 Seeder에서 초기 데이터를 생성해야 합니다.

```php
// 예: User 모델 — 신규 레코드 생성 시 UUID 자동 할당
protected static function boot(): void
{
    parent::boot();

    static::creating(function (self $user) {
        if (empty($user->uuid)) {
            $user->uuid = app(UniqueIdServiceInterface::class)->generateUuid();
        }
    });
}
```

### 업그레이드 스텝이 필요한 경우

| 변경 유형 | 업그레이드 스텝 필요 | 예시 |
|----------|-------------------|------|
| 기존 데이터 백필/변환 | ✅ | UUID 백필, 형식 변환 |
| NOT NULL 제약 추가 (백필 후) | ✅ (예외) | 스키마이지만 데이터 완결성 의존 |
| 권한/역할/메뉴 추가·수정 | ❌ (자동 동기화) | config/core.php 수정만으로 충분 |
| 정적 권한/메뉴 제거 | ✅ (cleanup 명시 호출) | 기존 메뉴/권한 삭제 |
| PHP 코드만 변경 | ❌ | 버그 수정, 성능 개선 |

---

## 5. 업그레이드 스텝 작성

### 디렉토리 구조

```text
upgrades/                             # 프로젝트 루트
├── .gitkeep
├── Upgrade_7_0_0_beta_4.php          # 7.0.0-alpha.4
└── Upgrade_7_0_0_beta_15.php         # 7.0.0-alpha.15
```

### 작성 예시

```php
<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 코어 7.0.0-alpha.15 업그레이드 스텝
 *
 * 기존 사용자 레코드에 UUID v7을 백필합니다.
 */
class Upgrade_7_0_0_beta_15 implements UpgradeStepInterface
{
    public function run(UpgradeContext $context): void
    {
        // 1. 사전 조건 확인
        if (! Schema::hasColumn('users', 'uuid')) {
            $context->logger->warning('uuid 컬럼 없음. 마이그레이션을 먼저 실행하세요.');
            return;
        }

        // 2. 데이터 백필
        $nullCount = DB::table('users')->whereNull('uuid')->count();
        if ($nullCount > 0) {
            $context->logger->info("UUID 백필 시작: {$nullCount}건");
            DB::table('users')->whereNull('uuid')->orderBy('id')->chunk(100, function ($users) {
                foreach ($users as $user) {
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['uuid' => Str::orderedUuid()->toString()]);
                }
            });
            $context->logger->info("UUID 백필 완료");
        }

        // 3. 백필 완료 후 NOT NULL 제약 (데이터 완결성 예외)
        // raw SQL에서는 $context->table()로 프리픽스 적용 필수
        $column = DB::selectOne("SHOW COLUMNS FROM {$context->table('users')} WHERE Field = 'uuid'");
        if ($column && $column->Null === 'YES') {
            Schema::table('users', function ($table) {
                $table->uuid('uuid')->nullable(false)->unique()->change();
            });
        }
    }
}
```

### 작성 체크리스트

```text
□ UpgradeStepInterface 구현
□ 네임스페이스: App\Upgrades (코어) / Modules\Vendor\Module\Upgrades (모듈)
□ 파일명: Upgrade_X_Y_Z.php (버전에 맞는 언더스코어 표기)
□ 사전 조건 확인 (테이블/컬럼 존재 여부)
□ 로거를 통한 진행 상황 기록
□ 멱등성 보장 — 모든 DB 조작에 방어 로직 필수 (아래 참조)
□ 대량 데이터는 chunk() 사용
□ raw SQL 사용 시 $context->table()로 테이블 프리픽스 적용
```

### 멱등성 방어 로직 (필수)

업그레이드 스텝은 재실행될 수 있다 (롤백 후 재시도, `--force` 등). 모든 DB 조작은 이미 완료된 상태에서 재실행해도 안전해야 한다.

| 조작 유형 | 방어 패턴 | 비고 |
|----------|----------|------|
| 레코드 삽입 | `firstOrCreate` 또는 `updateOrCreate` | `insert` 단독 사용 금지 |
| 대량 데이터 이관 | 이관 완료 마커 확인 후 스킵 | 예: `source` 컬럼에 마커 기록 → `exists()` 체크 |
| 식별자 rename (unique 컬럼) | 신규 존재 여부 확인 후 분기 | 양쪽 다 존재하면 역할 이관 후 구 레코드 삭제 |
| 코드/상태값 변환 (update WHERE) | WHERE 조건이 이미 변환된 건을 제외 | 대부분 자동 방어됨 |
| 캐시 무효화 | 항상 안전 | — |

---

## 6. 자동 발견 및 실행 규칙

> 파일: `app/Services/CoreUpdateService.php` — `runUpgradeSteps()`

### 발견 프로세스

```text
1. base_path('upgrades') 디렉토리 스캔
2. .php 확장자 파일만 대상
3. 정규식 매칭: /^Upgrade_(\d+)_(\d+)_(\d+)(?:_([a-zA-Z]\w*(?:_\d+)*))?$/
4. 버전 변환: 1_0_0 → 1.0.0, 7_0_0_beta_4 → 7.0.0-alpha.4
5. require_once 로딩 → App\Upgrades\{filename} 클래스 인스턴스화
6. UpgradeStepInterface 구현 확인
```

### 실행 규칙

- **필터 조건**: `fromVersion < stepVersion <= toVersion` (`version_compare` 사용)
- **정렬**: `uksort($steps, 'version_compare')` — 자연 정렬 오름차순
- **실행**: 각 스텝에 `UpgradeContext::withCurrentStep($version)` 불변 복제 전달

### 버전 네이밍 규칙

파일명의 언더스코어가 버전 구분자로 변환됩니다:

| 파일명 | 변환 버전 | 설명 |
|--------|----------|------|
| `Upgrade_1_0_0.php` | `1.0.0` | 숫자 3자리 |
| `Upgrade_7_0_0_beta_4.php` | `7.0.0-alpha.4` | pre-release 포함 |
| `Upgrade_7_0_0_beta_15.php` | `7.0.0-alpha.15` | pre-release 2자리 |

**규칙**: 숫자 3자리(`X_Y_Z`) 후 알파벳으로 시작하는 부분은 `-`로 연결, 이후 `_`는 `.`로 변환

### UpgradeStepInterface

> 파일: `app/Contracts/Extension/UpgradeStepInterface.php`

```php
interface UpgradeStepInterface
{
    public function run(UpgradeContext $context): void;
}
```

### UpgradeContext

> 파일: `app/Extension/UpgradeContext.php`

```php
// readonly 속성
public readonly LoggerInterface $logger;    // upgrade 로그 채널
public readonly string $fromVersion;        // 업그레이드 시작 버전
public readonly string $toVersion;          // 업그레이드 목표 버전
public readonly string $currentStep;        // 현재 실행 중인 스텝 버전

// 메서드
public function table(string $table): string                // DB 프리픽스 적용 (raw SQL용)
public function withCurrentStep(string $stepVersion): self  // 불변 복제
```

> **raw SQL에서 테이블명 사용 시 `$context->table()` 필수**
> - `Schema::hasColumn('users', ...)`, `DB::table('users')` → 프리픽스 자동 적용 (불필요)
> - `DB::selectOne("SHOW COLUMNS FROM ...")` 등 raw SQL → **`$context->table('users')` 필수**

---

## 7. 버전 관리

### 버전 소스

| 파일 | 역할 |
|------|------|
| `config/app.php` — `'version'` | SSoT (env 기본값 정의) |
| `.env` — `APP_VERSION` | 런타임 오버라이드 |
| `.env.example` | 배포 템플릿 |

### 버전 읽기

```php
CoreVersionChecker::getCoreVersion()  // → config('app.version')
                                      // (코어 업데이트 트리 안에서만 env APP_VERSION 우선
                                      //  — CoreUpdateContext::isInProgress())
```

### 버전 갱신 (Step 11)

```php
CoreUpdateService::updateVersionInEnv($version)
// .env에 APP_VERSION이 존재하면: 정규식으로 라인 교체
// .env에 APP_VERSION이 없으면: "APP_VERSION={version}\n" 추가
```

### 버전 변경 시 필수 동기화

```text
필수: 코어 버전 변경 시 3곳 동기화

1. config/app.php — env('APP_VERSION', '새버전')
2. .env.example — APP_VERSION=새버전
3. .env.testing — APP_VERSION=새버전 (테스트 환경)
4. CHANGELOG.md — 변경사항 기록
```

### 부트스트랩 호환성 자동 검증

> 파일: `app/Providers/CoreServiceProvider.php` — `validateAndDeactivateIncompatibleExtensions()`, `validateAndDeactivateIncompatibleTemplates()` (업데이트 트리 가드 뒤 `app/Extension/IncompatibleExtensionDeactivator.php` 에 위임)

**애플리케이션 부트(boot) 시마다** CoreServiceProvider가 모든 활성 확장의 `g7_version` 요구사항을 검증합니다.
`config('app.version')`이 확장의 요구 버전을 충족하지 않으면 **해당 확장을 자동으로 비활성화**합니다.

#### 검증 흐름

```text
1. CoreServiceProvider::boot() 실행
2. CoreVersionChecker::getCacheKey($type) 캐시 확인 (TTL: 1시간)
3. 캐시 없으면 → 모든 활성 확장 순회
4. 각 확장의 g7_version (예: ">=7.0.0-beta.1") 확인
5. Semver::satisfies(config('app.version'), $constraint) 실행
6. 비호환이 1건 이상이면 안전망 판정: 현재 버전 < 디스크 config/app.php 의 기본값(CoreVersionDiskReader::shippedVersion())
   → 아무것도 끄지 않고, config 캐시가 있으면 비우고, 경고(auto_deactivate_skipped_stale_version)를 남기고 캐시를 저장하지 않는다
7. 아니면 → Manager::deactivateModule/Plugin/Template() 호출 (config·라우트 캐시 재생성은 묶어서 끝에 한 번)
8. 실제로 꺼진 확장만 알림·경고(auto_deactivated)에 넣는다. 의존 확장 때문에 꺼지지 않은 확장은 auto_deactivate_blocked 경고만
9. 꺼지지 않은 확장이 없을 때만 캐시 저장 (1시간 유효) — 있으면 다음 부팅이 다시 시도
```

안전망이 막는 경우: 이전 버전 업데이터가 옛 버전 번호로 구운 config 캐시, 업데이트 전에 뜬 상주 프로세스의 옛 env. 다음 요청은 `.env` 로 부팅해 올바른 버전으로 다시 판정한다. 진짜 비호환(디스크 코드 버전으로도 맞지 않는 확장)은 그대로 끈다.

콘솔 프로세스(크론·큐 워커)는 성능을 위해 템플릿을 불러오지 않지만, 모듈·플러그인 비활성화의 역의존성 검사(`TemplateRepository::findActiveByModuleDependency()`·`findActiveByPluginDependency()`)는 `TemplateManager::ensureLoaded()` 로 템플릿을 불러온 뒤 판정한다 — 템플릿이 쓰는 확장을 콘솔이 끄지 않는다.

#### 주의사항

| 항목 | 설명 |
|------|------|
| **검증 주기** | 캐시 만료(1시간)마다 재검증 |
| **비활성화 대상** | 호환되지 않는 **모든** 활성 확장 (모듈, 플러그인, 템플릿) |
| **activity_log** | 기록되지 않음 (Service 레이어를 거치지 않고 Manager 직접 호출) |
| **로그** | `storage/logs/laravel.log`에 warning 기록 |

#### 버전 확인 방법

```bash
php artisan tinker
> \Composer\Semver\Semver::satisfies(config('app.version'), '>=7.0.0-beta.1');
```

#### 버전 우선순위

```text
config('app.version') = env('APP_VERSION', 'config/app.php 기본값')

.env에 APP_VERSION이 있으면 → .env 값 사용 (config/app.php 기본값 무시)
.env에 APP_VERSION이 없으면 → config/app.php의 기본값 사용
```

---

## 8. CoreUpdateService 주요 메서드

> 파일: `app/Services/CoreUpdateService.php`

### 업데이트 감지

| 메서드 | 시그니처 | 설명 |
|--------|---------|------|
| `checkForUpdates()` | `(): array` | GitHub API로 최신 릴리스 조회 |
| `getChangelog()` | `(?string $from, ?string $to): array` | CHANGELOG.md 파싱, 버전 범위 필터 |
| `checkSystemRequirements()` | `(): array` | 추출 도구 검증 (ZipArchive — 7.1.3 부터 unzip 폴백 없음) |
| `validatePendingPath()` | `(): array` | _pending 디렉토리 존재/권한 확인 |

### 다운로드 및 소스 준비

| 메서드 | 시그니처 | 설명 |
|--------|---------|------|
| `downloadUpdate()` | `(string $version, ?Closure $onProgress): string` | GitHub 다운로드 + 추출 (폴백 체인) |
| `copySourceToPending()` | `(string $sourceDir, ?Closure $onProgress): string` | --source 모드: 외부 디렉토리 → _pending 복사 |
| `prepareLocalSource()` | `(?Closure $onProgress): string` | --local 모드: 현재 코드 → _pending 복사 |
| `validatePendingUpdate()` | `(string $pendingPath): void` | 패키지 구조 검증 (composer.json, app/, config/app.php) |
| `createPendingDirectory()` | `(): string` | _pending/core_Ymd_His/ 디렉토리 생성 |
| `cleanupPending()` | `(string $pendingPath): void` | _pending 하위 디렉토리 삭제 |

### 백업 및 복원

| 메서드 | 시그니처 | 설명 |
|--------|---------|------|
| `createBackup()` | `(?Closure $onProgress): string` | CoreBackupHelper로 백업 생성 |
| `restoreFromBackup()` | `(string $backupPath, ?Closure $onProgress): void` | 백업에서 파일 복원 |
| `CoreBackupHelper::computeApplyList()` | `(string $backupPath, string $sourcePath, array $targets, array $protectedPaths, array $excludes): array` | 3-way 판정으로 증분 적용 대상(added/changed) 산출 (`apply`, `added_count`, `changed_count`, `has_symlink`). targets 에 명시된 `{domain}/_bundled` 는 상위 protected(`modules` 등)를 오버라이드해 목록에 포함 (공개 #64) |

### 적용 및 설치

| 메서드 | 시그니처 | 설명 |
|--------|---------|------|
| `applyUpdate()` | `(string $sourcePath, ?Closure $onProgress, bool $prune = false, ?array $applyList = null): void` | _pending → base_path 적용. `$applyList` 지정 + `!$prune` 이면 증분(코어 변경분만), 그 외 전체 덮어쓰기 + orphan 삭제. `public` 타깃은 `copyDirectory(..., preserveLinkPaths: ['storage'])` 로 `public/storage` symlink/junction 을 orphan 삭제에서 보호 (공개 #43) |
| `StorageLinkHelper::ensurePublicStorageLink()` | `(?\Psr\Log\LoggerInterface $logger = null): void` | `public/storage` 멱등 복구. 정상 링크면 no-op, 부재/손상이면 `storage/app/public` 링크 재생성(부재 시 신규, 손상 디렉토리는 `.broken.{YmdHis}` 백업 후). 재생성 링크는 부모 `public/` 의 소유자/그룹을 `lchown`/`lchgrp` 상속(sudo 후 root:root 잔존 차단). Windows junction 폴백. `CoreUpdateCommand` 종료 시점 + migration 05 가 호출 (공개 #43) |
| `runComposerInstallInPending()` | `(string $pendingPath, ?Closure $onProgress): void` | _pending에서 composer install (--no-scripts) |
| `isComposerUnchangedForCore()` | `(string $pendingPath): bool` | composer.json/lock MD5 비교 |
| `runComposerInstall()` | `(?Closure $onProgress): void` | base_path에서 composer install |
| `copyVendorFromPending()` | `(string $pendingPath, ?Closure $onProgress): void` | _pending/vendor → base_path/vendor |

### 마이그레이션 및 동기화

| 메서드 | 시그니처 | 설명 |
|--------|---------|------|
| `runMigrations()` | `(): void` | `php artisan migrate --force` |
| `syncCoreRolesAndPermissions()` | `(): void` | config/core.php roles/permissions 동기화 |
| `syncCoreMenus()` | `(): void` | config/core.php menus 동기화 |

### 업그레이드 및 마무리

| 메서드 | 시그니처 | 설명 |
|--------|---------|------|
| `runUpgradeSteps()` | `(string $from, string $to, ?Closure $onStep): void` | upgrades/ 자동 발견 + 실행 |
| `updateVersionInEnv()` | `(string $version): void` | .env의 APP_VERSION 갱신 |
| `clearAllCaches()` | `(): void` | config/cache/route/view 클리어 + 패키지 매니페스트 재생성(`PackageManifestCacheHelper::rebuild()`) |
| `signalQueueRestart()` | `(): void` | 상주 큐 워커에 재시작 신호 (실패는 경고만 — 업데이트를 되돌리지 않는다) |

### 유지보수 모드

| 메서드 | 시그니처 | 설명 |
|--------|---------|------|
| `enableMaintenanceMode()` | `(): string` | `php artisan down --secret` → bypass secret 반환 |
| `disableMaintenanceMode()` | `(): void` | `php artisan up` |

### 유틸리티

| 메서드 | 시그니처 | 설명 |
|--------|---------|------|
| `generateFailureReport()` | `(Throwable $e, string $from, string $to): string` | 에러 보고서 생성 → storage/logs/ |

---

## 9. Artisan 커맨드

### core:update

```bash
php artisan core:update [--yes] [--force] [--no-backup] [--prune] [--no-maintenance] [--local] [--source={path}]
```

| 옵션 | 설명 |
|------|------|
| `--yes`(`-y`) | 진행 확인을 「예」로 처리 (터미널 없는 실행용, 7.1.6+). 자동화(cron, CI, 원격 명령)는 이 옵션을 붙여야 업데이트가 진행된다 |
| `--force` | 버전 비교 스킵, 동일 버전이어도 강제 업데이트 (진행 확인은 건너뛰지 않음) |
| `--no-backup` | 백업 생성 스킵 (Step 5). 증분 적용 불가 → 전체 덮어쓰기로 회귀 |
| `--prune` | 코어가 제거한 파일 정리 + targets 전체 덮어쓰기(기존 방식). 미지정 시 코어가 실제 변경/추가한 파일만 적용(3-way)하고 나머지는 보존 |
| `--no-maintenance` | 유지보수 모드 스킵 (Step 3) |
| `--local` | 현재 코드베이스를 소스로 사용 (GitHub 스킵) |
| `--source={path}` | 지정 디렉토리를 소스로 사용 (GitHub 스킵) |

**종료 코드**:

| 코드 | 뜻 | 경우 |
| --- | --- | --- |
| `0` | 성공 | 업데이트 성공, 이미 최신 버전, 또는 대화형에서 운영자가 진행 확인에 「아니오」를 답함 |
| `1` | 실패 | 요건 미충족 또는 업데이트 실패. 7.1.1 이후에는 코어가 새 버전으로 반영됐어도 번들 확장 일괄 업데이트가 하나라도 실패하면 `1` (위 「종료 코드 (7.1.1 이후)」). 결과 줄을 남기지 못한 일괄 업데이트 자식은 정상 종료여도 실패 1건이다(7.2.0). 7.2.0 이후에는 `--yes` 없이 확인에 답할 수 없는 실행(`--no-interaction`, 터미널 없음)도 사유 출력과 함께 `1` |
| `75` | 재개 필요 | 업그레이드 스텝이 새 프로세스 재진입을 요청해 멈춤(핸드오프). 파일·설정은 새 버전이고 정리·점검 해제까지 끝났지만 스텝이 남았다 — 출력된 재개 명령(`php artisan core:execute-upgrade-steps --from=… --to=… --force`)을 이어서 실행한다. 단독 `core:execute-upgrade-steps` 의 핸드오프와 같은 값(`UpgradeHandoffException::EXIT_CODE`)이다. 7.1.x 까지는 이 경우 `0` 이라 스크립트가 성공으로 읽고 재개 명령을 건너뛰었다(7.2.0) |

웹 코어 업데이트(관리자 화면)는 `core:update` 를 부르지 않고 `core:execute-upgrade-steps` 를 직접 실행해 출력의 `[HANDOFF]` 줄로 재개를 판정하므로 이 종료 코드 변경의 영향을 받지 않는다.

**질문과 비대화형 동작**: 확인에 답할 수 없는 실행은 `--no-interaction`, 또는 입력이 터미널이 아닌 실행이다.

| 질문 | 나오는 시점 | 비대화형 동작 | `--yes` |
|------|------------|---------------|---------|
| 진행 확인 「코어를 업데이트하시겠습니까?」 | Step 1 직후 | 업데이트를 시작하지 않고 사유와 `--yes` 안내 출력, 종료 코드 `1` (7.2.0+) | 「예」로 처리, 묻지 않음 |
| 공식 번들 배치 [배치하지 않음 / 모두 배치 / 골라서 배치] | 진행 확인 직후 또는 Step 4 직후 | `--bundled-sources` 를 따르고, 없으면 배치하지 않음(`none`). `--force` 도 묻지 않음 | 영향 없음 |
| 번들 확장 일괄 업데이트 진행 (기본 yes) | 코어 반영 뒤 | 진행 | 영향 없음 |
| 전역 레이아웃 전략 (overwrite / keep) | 일괄 업데이트 진행 뒤 | `overwrite` | 영향 없음 |
| 예외 확장 지정 (기본 no) | 전략 선택 뒤 | 지정하지 않음 | 영향 없음 |
| 검색 인덱스 재생성 (기본 no) | 예외 지정 뒤 | 재생성하지 않음(`--rebuild-search-index` 로 켬) | 영향 없음 |

`--force` 를 주면 번들 관련 질문은 모두 묻지 않고 위 비대화형 동작을 따른다. 진행 확인은 `--force` 로 건너뛰지 않는다.

### core:check-updates

```bash
php artisan core:check-updates
```

- GitHub API 호출 → 현재/최신 버전 표시
- "새로운 업데이트가 있습니다!" 또는 "현재 최신 버전입니다."

---

## 10. API 엔드포인트

> 파일: `app/Http/Controllers/Api/Admin/CoreUpdateController.php`

| 메서드 | 경로 | 권한 | 설명 |
|--------|------|------|------|
| POST | `/api/admin/core-update/check` | `core.settings.read` | 코어 업데이트 확인 (422 시 check_failed) |
| GET | `/api/admin/core-update/changelog` | `core.settings.read` | 코어 변경 로그 조회 (from_version, to_version) |
| GET | `/api/admin/core-update/status` | `core.system.update` | 웹 실행 상태 (업데이트 화면이 그리는 상태의 근거) |
| POST | `/api/admin/core-update/start` | `core.system.update` | 실행 등록 (단계는 수행하지 않음) |
| POST | `/api/admin/core-update/advance` | `core.system.update` | 다음 단계 **하나**를 수행 |
| POST | `/api/admin/core-update/dismiss` | `core.system.update` | 끝난 실행의 기록 정리 |

적용 경로 셋은 설정 수정과 **다른 권한**을 요구합니다 — 설정을 고치는 것과 코어를 갈아
끼우는 것은 실패 시 되돌리는 비용이 다릅니다.

파라미터·응답 필드·예시는 [api/core-update.md](api/core-update.md) 가 소유합니다.

> ActivityLog: 확인은 `core_update.check`, 웹 실행은 `core_update.started` ·
> `core_update.completed` · `core_update.failed` 를 기록합니다.

---

## 11. 설정 (config/app.php)

### 버전

```php
'version' => env('APP_VERSION', '7.0.0-alpha.14'),
```

### 업데이트 설정

```php
'update' => [
    'github_url'    => '...',              // GitHub 리포지토리 URL
    'github_token'  => env('GITHUB_TOKEN'), // GitHub Personal Access Token (선택)
    'pending_path'  => '...',              // _pending 디렉토리 경로
    'targets'       => [...],              // 업데이트 적용 + 백업 대상 파일/디렉토리
    'backup_only'   => ['vendor'],         // 백업/복원 전용 (applyUpdate 제외)
    'backup_extra'  => [...],              // 추가 백업 대상
    'excludes'      => [...],              // 제외 패턴
    'restore_ownership'                => [...], // sudo 실행 후 소유권을 원상 복원할 경로 (Step 11)
    'restore_ownership_group_writable' => [...], // 복원 직후 그룹 쓰기(g+w)까지 동기화할 경로
],
```

`restore_ownership` 복원은 흐름 **중간**(Step 11)이므로 그 뒤에 만들어지는 런타임 산출물은 흐름 **마지막**의 런타임 소유권 정상화가 덮는다. 대상은 다섯 곳이다: `storage/framework/cache`(캐시 키 인덱스·락 샤드), `bootstrap/cache`, `storage/app/ext-bundles`(병합 번들), `storage/app/temp`(확장 업데이트 임시 폴더 — 부모가 root 로 최초 생성되면 이후 관리자 화면의 확장 업데이트가 실패한다), `storage/logs`(daily 롤오버·신규 로그 파일). `storage/app/{modules,plugins}` 는 사용자 데이터 영역이라 의도적으로 제외되어 있으므로, 그 아래에 파일·디렉토리를 만드는 코드(설정 시드·업그레이드 마이그레이션)는 스스로 부모 소유권을 상속시킨다.

위 두 장치는 **업데이트 전에 있던 경로**와 정해 둔 경로만 다룬다. 업데이트가 처음 만든 경로(예: 7.2.0 의 템플릿 환경설정 저장소 `storage/app/templates`, 코어 업데이트 기록 `storage/app/core-update`)까지 덮도록, root 로 돈 업데이트·설치 계열 명령(`core:update`·`core:execute-upgrade-steps`·`core:execute-bundled-updates`·`{module|plugin|template|language-pack}:{install|update|…}` 등)은 끝날 때 설치 트리의 **root 소유 항목만** 바로 위 폴더의 소유자·그룹으로 되돌리고, 위 폴더의 그룹·기타 권한을 더한다(빼지 않는다). 설치 루트가 root 소유인 서버, root 가 아닌 프로세스(관리자 화면 업데이트, 서버 명령을 쓸 수 없는 호스팅, 일반 계정 CLI)에서는 아무것도 하지 않는다. `.git`·`node_modules` 는 훑지 않고 링크는 따라가지 않는다. 그래서 업데이트 코드가 새 저장소 폴더를 만들 때 이 목록에 경로를 더할 필요가 없다.

`.env` 에서 `G7_UPDATE_EXCLUDES` · `G7_UPDATE_TARGETS` · `G7_UPDATE_PROTECTED_PATHS` · `G7_UPDATE_RESTORE_OWNERSHIP` · `G7_UPDATE_RESTORE_OWNERSHIP_GROUP_WRITABLE` 로 재정의할 수 있다. 재정의 값은 기본 목록을 **통째로 대체**하므로 전체 목록을 다시 적는다 — 예를 들어 `G7_UPDATE_EXCLUDES` 에서 `build/ext` 가 빠지면 `--prune` 업데이트가 정적 게시본을 지운다. 기본값은 `.env.example` 에 주석으로 실려 있다.

### 예약 경로 (custom, 7.2.0)

`update.reserved_paths`(`G7_UPDATE_RESERVED_PATHS`, 기본 `routes/custom.php,app/Custom,resources/views/custom,public/custom,lang/custom,config/custom.php,database/migrations/custom,custom`)는
업데이트 대상 폴더 **안쪽**이라도 코어 업데이트가 덮지도 지우지도 않는 경로다. 파일 복사·orphan 정리(`--prune`)·신규 파일 manifest·롤백 정리·자동 발견 폴백이 모두 건너뛴다(`UpdateReservedPaths`).
코어 배포본은 이 자리에 파일을 싣지 않는다 — 릴리스 준비(`prepare-release.sh` 의 `check-release-reserved-paths.cjs`)가 검사하고, 받은 패키지에 실려 있으면 업데이트를 시작하지 않는다.

### custom 패키지 사전 검사 (7.2.0)

`--source`·`--zip` 은 유지보수 모드 진입 전에, 그 밖의 모드는 새 코어를 `_pending` 에 받은 직후에 `CustomUpdatePreflight` 가 새 코어 `composer.lock` 과
`custom/composer.lock` 을 정적으로 비교한다(요구 제약 불만족 `constraint`, 같은 패키지 다른 버전 `duplicate`). 하나라도 걸리거나 예약 경로 파일이 실려 있으면
업데이트를 시작하지 않고 표를 출력하며, 결과를 `storage/app/core_update/custom_preflight.json` 에 남겨 관리자 업데이트 확인 창(`check-for-updates` 응답의 `custom_preflight`)이 같은 표를 보여 준다.
검사를 통과하면 기록을 지운다. 업데이트 마지막에는 `custom/vendor` 를 맞춘다(`CustomVendorService::ensureInstalled()` — Composer 또는 `custom/vendor-bundle.zip`, 실패는 경고만).
개발자 안내는 [개발자 화면 가이드](../custom-development.md#4-개발자-패키지custom-composer).

---

## 12. 에러 처리 및 로깅

### 시스템 요건 검증

```text
- ZipArchive (PHP zip 확장) 필요 — 7.1.3 부터 unzip 명령 폴백 없음
- 미충족 시 커맨드 실패 (EXIT 1) + 안내 출력
```

### _pending 검증

```text
- 디렉토리 미존재: File::ensureDirectoryExists() 시도
- 쓰기 불가: 경로, 소유자, 그룹, 권한 정보 포함 에러 출력
```

### 패키지 검증

```text
- 필수 파일: composer.json, app/ 디렉토리, config/app.php
- config/app.php에 'version' 키 필수
- 미충족 시 RuntimeException
```

### Composer 실패

```text
- exit code 비정상 → RuntimeException (마지막 5줄 출력 포함)
```

### 로그 위치

| 로그 | 경로 | 내용 |
|------|------|------|
| 성공 로그 | `storage/logs/core_update_success_YYYYMMDD_HHMMSS.log` | 전체 실행 타임라인 |
| 실패 로그 | `storage/logs/core_update_failed_YYYYMMDD_HHMMSS.log` | 실패까지의 실행 타임라인 |
| 실패 보고서 | `storage/logs/core_update_failure_YYYYMMDD_HHMMSS.log` | 예외 상세 + 시스템 정보 |
| 일반 로그 | `storage/logs/laravel.log` | Log 파사드 통한 기록 |

---

## 13. 유지보수 모드

### 활성화 (Step 3)

```php
Artisan::call('down', [
    '--secret' => $secret,    // UUID 토큰 (bypass 접근용)
    '--retry'  => 60,         // Retry-After 헤더 (초)
    '--refresh' => 15,        // 브라우저 자동 새로고침 (초)
]);
```

### Bypass 접근

```text
URL: https://example.com/{secret}
→ 쿠키 발급 → 유지보수 모드에서도 접근 가능
```

### vendor 교체 구간의 응답 (7.1.5+)

Step 8 은 운영 `vendor/` 를 비운 뒤 새 vendor 를 복사한다(수십 초). 유지보수 화면은 미들웨어(`MaintenanceModePage`)가 만드므로 이 구간에는 프레임워크를 실을 수 없다. `public/index.php` 가 vendor 를 싣기 전에 직접 최소 응답을 만든다.

| 상황 | 응답 |
|------|------|
| `vendor/autoload.php` 없음 + 유지보수 표식(`storage/framework/down`) 있음 | 503 + `Retry-After`(down 파일의 `retry`, 없으면 60) + `Refresh`(down 파일의 `refresh`). API·JSON 요청은 `{"success":false,"message":…}` |
| 유지보수 중 vendor 복사 도중 치명 오류(autoload 는 있으나 그것이 부르는 파일이 아직 없음) | 같은 503 — 유지보수 중에는 프레임워크 부팅 전 `display_errors` 를 끄고 종료 처리기가 치명 오류를 503 으로 바꾼다 |
| `vendor/autoload.php` 없음 + 유지보수 아님 | 500 (일시 장애가 아니라 설치 손상 — `composer install` 또는 vendor 복구 필요). 오류 경로·스택은 본문에 싣지 않는다 |
| 유지보수 중 + vendor 온전 | 진입점은 끼어들지 않는다 — 종전대로 유지보수 미들웨어의 다국어 화면·JSON |

bypass 쿠키가 있는 관리자도 vendor 교체 구간에는 503 을 받는다(프레임워크가 없어 쿠키를 검증할 수 없다). Step 7(파일 적용)이 Step 8 보다 먼저 새 `public/index.php` 를 놓으므로, 이 처리가 없는 판에서 올리는 업데이트에도 그 업데이트의 vendor 교체 구간부터 적용된다.

### 해제

```text
- 성공 시: Step 11에서 자동 해제 (php artisan up)
- 실패 시: 유지보수 모드 유지 → 관리자에게 수동 해제 안내
  "이전 버전으로 사이트를 운영하려면: php artisan up"
```

---

## 14. 코어 vs 확장 업데이트 비교

| 항목 | 코어 | 모듈/플러그인 | 템플릿 |
|------|------|-------------|--------|
| **소스** | GitHub releases (zipball) | GitHub 또는 _bundled | GitHub 또는 _bundled |
| **추출** | SafeArchiveExtractor (ZipArchive 필수, 위험 항목 전체 거부) | SafeArchiveExtractor (GitHub zip) / ExtensionPendingHelper | SafeArchiveExtractor (GitHub zip) / ExtensionPendingHelper |
| **백업** | CoreBackupHelper (선택) | ExtensionBackupHelper (선택) | ExtensionBackupHelper (선택) |
| **유지보수 모드** | 전체 앱 down (secret 토큰) | 없음 | 없음 |
| **Composer** | MD5 비교 → 변경 시만 실행 | 확장별 독립 vendor/ | 해당 없음 |
| **파일 적용** | FilePermissionHelper (권한 보존) | ExtensionPendingHelper::copyToActive() | ExtensionPendingHelper::copyToActive() |
| **vendor 처리** | 변경 시 전체 복사 | 확장별 composer install | 해당 없음 |
| **마이그레이션** | 필수 (Step 9) | 선택 (확장별) | 없음 |
| **동기화** | roles + permissions + menus + mail templates | roles + permissions + menus | 없음 |
| **업그레이드 스텝** | `upgrades/Upgrade_X_Y_Z.php` | `{ext}/upgrades/Upgrade_X_Y_Z.php` | 없음 |
| **네임스페이스** | `App\Upgrades` | `Modules\Vendor\Module\Upgrades` | 해당 없음 |
| **실행 주체** | `CoreUpdateService` | `AbstractModule` | `TemplateManager` |
| **커맨드** | `core:update` | `module:update {id}` | `template:update {id}` |
| **레이아웃** | 해당 없음 | 자동 갱신 | 충돌 전략 (apply_new/keep_current) |
| **롤백** | 전체 복원 + 유지보수 유지 | 확장별 복원 + 상태 복원 | 확장별 복원 |
| **로깅** | 타임스탬프 성공/실패 로그 + 실패 보고서 | laravel.log | laravel.log |

---

## 15. 웹 단계 실행 (관리자 화면)

> 파일: `app/CoreUpdate/{CoreUpdateOrchestrator,CoreUpdateRunState,CoreUpdatePreflight,CoreUpdateStep,CoreUpdateOptions}.php`

SSH 를 쓸 수 없는 호스팅을 위해 관리자 화면(환경설정 > 업데이트)에서 같은 업데이트를
수행합니다. **일은 신설하지 않습니다** — `CoreUpdateService` 와 기존 콘솔 커맨드
(`core:execute-upgrade-steps` · `core:execute-bundled-updates`)를 그대로 부르고,
오케스트레이터는 **순서·재개·되돌리기**만 맡습니다.

### 왜 한 요청에 한 단계인가

파일을 교체한 프로세스가 사라진 클래스를 참조하면 치명 오류가 납니다(실사례:
2026-04-17). 그래서 단계마다 요청을 끊고, 그 다음 요청은 **신버전 코드로 새로 부팅된
프로세스**가 처리합니다.

**순서 불변식**: 업그레이드 스텝(`upgrade_steps`)은 파일 적용(`apply`)과 **반드시 다른
요청**에서 실행합니다. CLI 는 그 격리를 `proc_open` 스폰으로 얻지만 스폰이 막힌 호스팅에는
그 수단이 없고, 요청 경계가 그것을 대신합니다. 계약 테스트
(`tests/Unit/CoreUpdate/CoreUpdateStepContractTest.php` ·
`CoreUpdatePipelineParityTest.php`)가 이 불변식을 고정합니다.

`apply` 단계는 마지막에 컴파일 캐시 파일(`config`·`routes`·`services`·`packages`·`events`)을
**삭제만** 합니다. 그 자리에서 `clearAllCaches()` 를 부르면 `package:discover` 등이 방금
교체된 파일을 구버전 프로세스에서 로드하게 되므로, 남은 정리는 다음 요청과 마무리 단계가
맡습니다.

### 단계

| 순서 | 키 | 내용 |
| --- | --- | --- |
| 1 | `preflight` | 시스템 요구사항 · `_pending` 경로 · **적용 대상 전 경로의 쓰기 가능성** · 여유 공간 |
| 2 | `maintenance` | 유지보수 모드 활성화 |
| 3 | `download` | 내려받기 또는 운영자 ZIP 추출 |
| 4 | `backup` | 백업 + 소유권 스냅샷(전체 · 항목별) |
| 5 | `vendor` | `_pending` vendor 구성 (변경 없으면 스킵) |
| 6 | `plan` | 신규 파일 manifest + 3-way 증분 적용 목록 |
| 7 | `apply` | 코어 파일 적용 (증분 고정) |
| 8 | `vendor_copy` | vendor 디렉토리 복사 |
| 9 | `migrate` | `runMigrations()` + `reloadCoreConfigAndResync()` |
| 10 | `upgrade_steps` | 러너 경유 `core:execute-upgrade-steps --steps-only` |
| 11 | `bundled` | 러너 경유 `core:execute-bundled-updates --manifest=…` (선택) |
| 12 | `finalize` | 버전 기록 · 캐시 · 소유권 복원 · 링크 복구 · 정리 · 유지보수 해제 |

### 안전 기본값 (웹 전용 고정)

`--force` · `--prune` · `--no-backup` · `--no-maintenance` · `--vendor-mode` · `--source` ·
`--local` 은 **웹 동선에 입력 표면 자체가 없습니다.** 검증에서 거르는 형태를 택하지 않은
이유는 규칙 한 줄만 풀면 켜지는 값이 남기 때문입니다 — `CoreUpdateOptions` 에는 그 필드가
없고, `StartCoreUpdateRequest` 에도 그 키가 없습니다. CLI 는 그 선택들을 그대로 유지합니다.

받는 선택은 넷뿐입니다: 번들 확장·언어팩 동시 업데이트(기본 켬) · 검색 인덱스 재생성
(기본 끔) · 전역 레이아웃 전략(기본 `overwrite`) · 수동 ZIP 파일명.

### 진행 상태와 재개

`storage/app/core-update/web-run.json` 이 요청 경계를 넘는 유일한 기억입니다. 증분 적용
목록은 커질 수 있어 사이드카 파일(`web-run-apply-list.json`)로 분리합니다.

`CoreUpdateService` 의 실행 잠금(`flock`)은 프로세스 수명에 묶여 요청 사이를 잇지
못하므로, 요청 경계를 넘는 직렬화는 상태 파일의 **점유(토큰 + 심박)** 가 맡습니다. 심박을
읽을 수 없으면 **보유 중으로 봅니다**(fail-closed) — 판정 불능을 「오래됨」으로 접으면
진행 중인 다른 요청의 점유를 빼앗기 때문입니다. `flock` 은 같은 요청 구간에서 **동시에
도는 CLI 실행**을 막는 데 계속 쓰입니다.

### 요청 시간 제한 안에서 끝나거나, 끊겨도 스스로 정리된다

단계 요청은 실행 시간 제한을 다시 걸고(`CoreUpdateTimeBudget` — 요청값 300초, 서버가 더 길면
그대로) **실제로 적용된 수명**을 점유에 선언합니다. 점유의 만료 창은 그 수명 + 30초입니다.
`set_time_limit()` 이 막혔거나 ini 가 잠긴 호스팅에서는 요청값이 아니라 그 서버의 실제 제한이
선언됩니다 — 선언이 실제보다 길면 시간 초과로 죽은 요청의 점유가 그만큼 남아 사이트가 점검
모드에 갇힙니다. 만료 창을 선언하지 않은 점유(이전 판의 상태 파일)는 15분 기본 창을 씁니다.

단계가 시간 제한을 넘기면 세 겹으로 정리합니다.

1. **종료 처리** — `advance()` 가 등록한 종료 처리가 치명 오류(시간 초과·메모리 초과)를 보면 그
   단계를 실패로 기록하고(`rolling_back`) 점유를 놓습니다. 다음 호출이 평소의 실패와 같이
   백업본으로 되돌립니다.
2. **다음 요청** — 프로세스가 신호로 죽어 종료 처리도 돌지 못했으면, 다음 요청이 만료된 점유를
   회수하면서 그 점유가 같은 구간을 돌던 중이었는지 봅니다. 그렇다면 같은 단계를 다시 돌리지
   않고 실패로 확정합니다(같은 자리에서 또 죽기 때문입니다). 이때 실행 잠금을 먼저 잡아, 앞
   요청이 실제로는 아직 살아 있으면(요청 수명보다 오래 도는 서버) 아무것도 기록하지 않고 409 로
   답합니다.
3. **단계별 끊김 규칙** — 끊긴 단계가 무엇이냐에 따라 다르게 정리합니다.
   - 되돌리기: 진행분(`rollback_progress`)에서 이어 갑니다. **같은 자리에서** 다시 끊길 때만 횟수를 세고,
     `MAX_ROLLBACK_ATTEMPTS`(3)에 닿으면 — 파일을 이미 되돌렸으면 남은 구간을 건너뛰고 사이트를 열고(`rolled_back`),
     복원이 끝나지 않았으면 `rollback_failed`(점검 모드 유지, 수동 복구 안내)로 확정합니다.
   - 번들 확장·언어팩: 끊긴 항목은 `interrupted` 로 적고 다음 항목으로 넘어갑니다(단계 끝에서 실제 판으로 성공·확인
     불가를 가름). 코어는 되돌리지 않습니다(진행분은 첫 항목을 돌리기 전에 저장합니다).
     **자식 기다리기(7.2.0)** — 끊긴 것은 기다리던 요청이지 자식이 아니므로, 항목을 돌리기 전에 실행 상태
     디렉토리의 표식 경로(`bundled-child_{무작위}.lock`)를 `context.bundled_child` 와 항목 매니페스트(`child_marker`)에
     싣습니다. 자식(`core:execute-bundled-updates`)은 그 파일을 배타 잠금한 채 돌고 옆 정보 파일(`….lock.json`)에
     PID·시작 시각·부팅 식별자(Linux)를 적으며, 끝나면(정상·예외) 완료를 적고 놓고 치웁니다(`BundledChildMarker`).
     다음 요청의 번들 단계는 항목을 돌리기 전에 그 표식을 봅니다 — 아직 잠겨 있으면 PID 와 기다리기 시작한 시각을
     실행 상태에 적고 기다림을 알린 뒤 몫의 절반까지 기다리고, 그래도 돌면 같은 단계를 다음 요청에서 다시 합니다.
     잠금이 1차 판정입니다(프로세스가 끝나면 OS 가 놓으므로 PID 재사용·재부팅과 무관). 잠금을 쓸 수 없는 파일
     시스템에서만 PID 생존 확인(Windows `tasklist`, 그 밖 `posix_kill`·`/proc`)과 부팅 식별자 비교로 내려갑니다.
     잡은 프로세스 없이 완료도 적히지 않은 표식은 고아(강제 종료·재부팅, 같은 프로세스에서 돈 in-process 실행)로
     보고 치운 뒤 진행합니다. 고아면 죽은 것이 확인됐으므로 그 확장의 작업 잠금을 풀고 `updating` 행을 시간 조건 없이
     정리합니다(`recoverOrphanedBundledItem`). 부모 요청이 살아 있는 동안 자식만 죽은 경우 — 러너가 돌아온 뒤
     표식에 완료가 없는 경우 — 도 같은 함수로 그 자리에서 정리합니다. 결과 줄을 남기고 실패를 보고한 자식은 스스로 잠금·상태를 정리했으므로 건드리지 않습니다. 잠금은 자식이
     잡을 때 표식 정보 파일에 적은 소유자 토큰(`locks`, `ExtensionOperationLock` 이 `BundledChildMarker::noteLock` 으로
     기록)으로만 풉니다(`releaseAbandonedKey` — 그새 다른 작업이 잡았으면 소유자가 달라 그대로 둠). 그 확장의 잠금 기록이
     없으면(표식을 잡기 전에 끝남·잠금을 잡기 전에 죽음·스스로 정리하고 끝남) 잠금·상태가 자식 것이라는 근거가 없어
     아무것도 하지 않습니다(만료에 맡김). 결과 줄 유무가 아니라 「표식에 완료 없이 잠금 기록이 남음」이 정리 조건입니다.
     표식 판정 가장자리: 정보는 잠금을 얻은 뒤 읽고, 자식은 끝날 때 정보 파일(완료 표지)을 남겨 「끝남」과 「아직 표식을
     잡기 전」을 가릅니다 — 띄운 뒤 `START_GRACE_SECONDS`(60초) 안에 표식·정보가 없으면 도는 중으로 봅니다. 잠금이
     비었어도 정보의 호스트가 다르거나(노드 사이에 잠금이 공유되지 않는 공유 저장소) 같은 호스트에서 그 PID 가 살아
     있으면(부모 자신의 PID 는 제외) 고아로 단정하지 않고 상한에 맡깁니다. 잠금을 쓸 수 없는 파일 시스템에서는 PID 를
     모르면 도는 중으로 둡니다. 상태 정리는
     (`StuckUpdateRecovery::recover(holderGone: true, restoreStatus: 항목 실행 전 기록한 prior_status)`) — 진행 기록상
     파일을 바꾸기 전이면 업데이트 전 상태로, 바꾼 뒤면 시작 판·「업데이트 있음」·비활성으로 둡니다(반쯤 바뀐 파일로 켜지
     않음). 언어팩은 `updating` 이면 업데이트 전 상태로 되돌립니다. 그래서 「개별로 다시 업데이트」 안내가 잠금 만료를
     기다리지 않고 바로 됩니다. 끝남·고아·상한 어느 쪽이든 끊긴 요청이 지우지 못한 항목 매니페스트
     (`core_pending/bundled-updates-manifest_*.json`, `context.bundled_child.manifest`)도 이때 지웁니다(자식은 시작할 때
     한 번 읽음). `BUNDLED_CHILD_WAIT_SECONDS`(900초)를 넘기면 경고를 남기고 진행합니다(그 항목은 단계
     끝 판 확인에 맡김). 마무리는 번들 단계가 끝나야 시작하므로 따로 기다리지 않습니다. 표식이 없으면(자식이 끝나
     치웠거나, 표식을 모르는 옛 자식) 종전처럼 진행합니다.
   - 마무리: 같은 자리에서 다시 시도합니다. 정리 구간(백업 지우기)에 들어간 뒤로는 되돌리지 않고, 상한에 닿으면
     남은 정리를 건너뛰고 점검 모드를 풀어 마무리를 끝냅니다(경고로 알림). 준비 구간에서 상한에 닿으면 되돌립니다.
   - 그 밖의 단계: 실패로 기록하고 되돌립니다.

나눌 수 있는 긴 단계는 요청 몫(요청 수명의 절반, 5~40초) 안에서 멈춥니다.

- **사전 점검**의 하위 항목 훑기는 몫 안에서 멈추고 거기까지의 판정을 씁니다(쓰기 훑기 6할,
  크기 추정 4할). 훑기의 목적은 전수 확인이 아니라 명백한 차단을 착수 전에 드러내는 것이고,
  확인하지 못한 경로가 적용에서 막히면 자동으로 되돌립니다.
- **적용 대상 산출**(`plan`)은 커서를 남기고 다음 요청이 이어 갑니다(`CoreBackupHelper::collectNewFilesChunk`
  · `computeApplyListChunk`). 진행분은 사이드카(`web-run-plan-progress.json`)에 둡니다. 대상 안은 이름 정렬
  깊이 우선 경로 커서(`FilePermissionHelper::walkTreeChunk`)라 이어 갈 때 앞쪽을 다시 읽지 않고, 나눠도 한 번에
  한 결과와 같습니다.
  명령줄 업데이트는 같은 함수를 멈춤 없이 부릅니다.

- **내려받기·ZIP 추출**은 내려받기와 풀기를 다른 요청에서 하고, 풀기는 항목 순번 커서로 이어 갑니다
  (`SafeArchiveExtractor::extractZipChunk` — 안전 검사는 매 요청 전 항목에 다시 합니다). 격리 디렉토리는
  처음에 `pending_path` 에 기록해, 도중에 실패하면 되돌리기가 정리합니다.
- **백업**은 target 순번 + target 안 경로 커서(`FilePermissionHelper::copyDirectoryChunk` — 이름 정렬 깊이
  우선, 커서는 「여기까지 끝났다」는 경로)로 이어 갑니다. 다 만든 뒤에만 `backup_path` 에 싣습니다 — 반쪽
  백업을 복원 원본으로 쓰지 않기 위해서이며, 도중에 실패한 반쪽 백업은 되돌리기가 지웁니다.
- **vendor 반영**은 파일 복사 대신 디렉토리를 통째로 바꿔 끼웁니다(`FilePermissionHelper::swapDirectory` —
  이름 바꾸기 두 번). 옛 vendor 는 격리 디렉토리에 옮겨 두고 되돌리기가 그대로 다시 끼웁니다. 수만 파일
  복사가 중간에 끊기면 다음 요청이 부팅할 vendor 가 반쪽이 되기 때문입니다. 이름 바꾸기가 안 되는 서버
  (다른 파일 시스템, 프로젝트 루트 쓰기 권한 없음)만 종전 복사로 갑니다.
- **번들 확장·언어팩 일괄 업데이트**는 한 요청에 한 항목씩 돕니다(명령줄의 「항목마다 새 프로세스」와 같은
  경계, 같은 정렬). 검색 인덱스 재생성은 마지막 항목에서 한 번만 합니다.
- **되돌리기**는 ① 바꿔 끼운 vendor 다시 끼우기 ② 이번 업데이트가 덮어쓴 파일(증분 적용 목록) 먼저 복원
  ③ target 전체 덮어쓰기 ④ 신규 파일 prune ⑤ 정리 순으로 이어 갑니다(`rollback_progress`). ②를 첫 조각에서
  끝내 두므로, 나머지 조각 사이에 요청이 끊겨도 사이트는 섞인 판이 아니라 이전 판으로 뜹니다. vendor 는
  이번 실행이 바꾼 채로 남은 경우에만 백업으로 덮어씁니다.
- **마무리·되돌리기의 정리**(전개본, 옮겨 둔 옛 vendor, 백업)는 나눠 지웁니다(`deleteDirectoryChunk` —
  링크·정션은 따라가지 않고 링크만 지웁니다).
- **소유권·그룹 쓰기 권한 복원**(마무리·되돌리기)은 대상 순번 + 대상 안 경로 커서로 이어 갑니다
  (`CoreUpdateService::restoreOwnershipChunk` · `FilePermissionHelper::walkTreeChunk`). 마무리는 준비(버전 기록·캐시·큐
  재시작 신호) → 정리 → 소유권 복원 → 링크 점검·점검 모드 해제 순서로, 소유권 복원이 훑을 백업·전개본 폴더를 먼저
  지웁니다. `restoreOwnership()` 은 같은 함수를 멈춤 없이 부르는 래퍼입니다.

조각의 마감은 몫에서 응답 마무리 몫(3초)을 뺀 자리입니다 — 멈춘 뒤의 진행분 저장·상태 응답까지 몫 안에 들게 합니다.
`set_time_limit()` 이 막혀 실행 제한을 다시 걸 수 없는 서버에서는 몫을 요청 시작 시각(`REQUEST_TIME_FLOAT`)부터 셉니다 —
서버의 시계가 부팅부터 돌고 있기 때문입니다.

되돌리기는 복원이 끝났다는 사실을 다음 구간보다 먼저 저장합니다. 끊긴 되돌리기의 재시도 횟수는 **같은 자리에서**
다시 끊길 때만 셉니다(진행분이 앞으로 갔으면 처음부터 셉니다). 파일을 이미 되돌린 뒤의 구간(소유권 복원·정리)만
끝나지 않아 상한에 닿으면, 그 구간을 건너뛰고 점검 모드를 풀어 `rolled_back` 으로 끝내며 건너뛴 사실을 경고로
남깁니다. 복원 자체가 끝나지 않은 경우만 `rollback_failed`(점검 모드 유지, 수동 복구 안내)입니다.

하위 명령 출력은 실패 사유·표식 줄 해석 전에 터미널 제어 코드(ANSI)를 벗깁니다.

명령줄 업데이트(`core:update`)는 같은 커서 함수를 멈춤 없이 부르는 래퍼(`createBackup`·`restoreFromBackup`·
`extractZip`)를 쓰므로 결과가 같습니다.

사전 점검은 링크·정션을 검사하지 않습니다. 업데이트·백업·되돌리기는 링크를 링크째 다루므로 그
대상의 쓰기 가능성은 질문이 아니고, Windows 정션(설치가 만든 `public/storage`)은 `is_writable()` 이
거짓으로 나와 권한을 바꿔도 풀리지 않는 차단이 됩니다. 디렉토리의 `is_writable()` 이 거짓이면 그
안에 시험 파일을 만들어 보고 판정합니다(Windows 의 읽기 전용 속성 폴더).

화면은 상태 응답의 `time_budget.client_wait_seconds`(요청 수명 + 30초)만큼 단계 응답을 기다립니다.
그래도 응답이 끊기면(프록시 응답 제한·통신 끊김) 상태를 다시 읽어 `run.claim.held` 가 풀릴 때까지
기다렸다가 스스로 다시 부릅니다. 400·401·403·404·405·419·422 는 기다려도 풀리지 않으므로 곧바로 멈추고,
그 밖의 실패(시간 초과, 409 점유 충돌, 5xx)는 한 번 쉰 뒤(5초) 상태를 다시 읽고 다시 부릅니다. 진척 판정은
상태·다음 단계·완료 단계 수·진행 기록 줄 수로 하며(갱신 시각은 점유만으로도 바뀌어 쓰지 않습니다), 진척 없이
5번 연달아 실패하면 [이어서 진행] 을 남기고 멈춥니다.

실행 제한을 늘려도 조각의 몫은 늘리기 전 제한과 지금 제한 중 짧은 쪽으로 잡습니다(`CoreUpdateTimeBudget::sliceBasis`) —
php-fpm `request_terminate_timeout`·프록시 응답 제한은 PHP 가 읽지 못하고 대개 원래 제한과 같기 때문입니다.

한 요청으로 도는 단계: 파일 적용(증분 목록이 없으면 전체 덮어쓰기), vendor 반영의 종전 복사 경로(바꿔 끼우기가 안 되는
서버), 마이그레이션, 업그레이드 스텝, GitHub 배포본 내려받기 자체, 번들 확장 한 항목. 이 단계가 요청 제한을 넘기면 위
끊김 규칙대로 정리됩니다.

점검 모드 우회 비밀값은 기록 파일(`upgrade` 채널, 명령줄 업데이트 기록)과 웹 실행 기록 어디에도
남기지 않습니다. 우회 쿠키는 점검 모드 파일에서 바로 읽고, 명령줄 실패 안내만 터미널에 값을
보여 줍니다.

### 사전 조건은 누르기 전에 보인다

착수 화면(`GET /api/admin/core-update/status`)은 응답의 `preflight` 로 쓰기 권한·여유 공간
판정을 함께 받습니다. 실행 1단계에서만 재면 쓰기 권한이 없는 호스팅의 운영자는 **사이트를
점검 모드로 보낸 뒤에야** 그 사실을 알게 됩니다.

화면용 요약은 **얕게** 잽니다 — 각 대상 경로의 쓰기 비트와 여유 공간까지입니다. 하위 전수
순회와 트리 크기 추정은 저장소 규모에 따라 십수 초가 걸려(실측) 화면을 여는 요청에 실을 수
없습니다. 전수 판정은 착수 1단계가 소유하며 **차단의 권위도 그쪽에 있습니다.**

진단 대상은 `update.targets` 만이 아니라 **웹 흐름이 실제로 쓰는 경로 전부**입니다 —
`backup_only`(vendor)는 targets 에 없지만 `vendor_copy` 단계가 덮어쓰고 되돌리기도 그리로
복원합니다. 빠뜨리면 「진단은 통과했는데 적용에서 실패」가 재현되고, 그때는 이미 파일 적용이
끝난 뒤라 되돌리기까지 부분 실패합니다.

### 진행은 화면이 스스로 이어간다

한 요청이 한 단계만 수행하므로 **반복 호출의 주체는 화면**입니다. 진행·되돌리기 상태를
그리는 지점이 마운트될 때 자동으로 다음 단계를 부르며, 그래서 브라우저를 닫았다 다시
들어와도 진행이 이어집니다. 이 배선이 없으면 진행률만 복원되고 아무도 `advance` 를 부르지
않아 **사이트가 점검 모드에 멈춘 채** 남습니다 — 실패 시의 「조작 없이 되돌린다」도 다음
호출이 복원을 수행하는 구조라 같은 배선에 기댑니다.

자동 경로에는 페이지 수명당 상한이 있습니다. 반복 호출 상한으로 멈춘 진행이 곧바로 자동
재개되면 같은 왕복이 무한히 돌기 때문이며, 그 뒤로는 [이어서 진행] 이 유일한 진행 수단이
됩니다(그 버튼은 이 상한을 쓰지 않습니다).

### 실패와 되돌리기

단계가 실패하면 상태가 `rolling_back` 으로 전이하고, 이어지는 `advance` 호출이 백업 복원을
수행합니다 — 운영자 조작이 필요 없습니다. 화면에서 가장 중요한 정보는 「실패했다」가 아니라
**「지금 사이트는 멀쩡하다」**이므로, 되돌리기 결과를 실패 사유와 분리해 표시합니다.
복원까지 실패한 경우(`rollback_failed`)에만 수동 복구 절차·백업 위치·실패 리포트 경로를
엽니다.

업그레이드 스텝이 새 프로세스를 요구하면(`UpgradeHandoffException`) CLI 는 재실행 명령을
안내하고 끝내지만, 웹에서는 **다음 요청이 곧 새 프로세스**이므로 같은 단계를 이어서
수행합니다(상한 `MAX_HANDOFF_CONTINUATIONS`).

**업그레이드 스텝 단계는 스폰 실패 모드를 `fallback` 으로 강제합니다.**
`CoreUpdateService::runUpgradeSteps()` 의 stale 메모리 가드는 `config('app.version')` 이
목표 버전보다 낮으면 「부모가 구버전 코드를 들고 있다」로 판정하고, 배포 기본값(`abort`)
에서는 그 자리에서 핸드오프를 던집니다. CLI 는 자식 프로세스 env 에 `APP_VERSION=toVersion`
을 실어 그 판정을 피합니다.

웹에서는 그 판정의 **전제 자체가 성립하지 않습니다** — 이 단계는 파일 적용과 다른 요청이라
이미 신버전 코드로 부팅된 프로세스이고, `.env` 의 `APP_VERSION` 은 마무리 단계까지 옛 값으로
남습니다. 강제하지 않으면 매 요청이 같은 판정을 반복해 핸드오프가 상한까지 소진되고,
**정상 적용된 코어가 되돌려집니다.** 강제 채널은 `config()`(in-process 러너)와
`$_ENV`/`$_SERVER`(서브프로세스 자식)이며, `putenv` 는 쓰지 않습니다 — 그 값은 프로세스
수명에 남아 같은 워커의 다음 요청까지 모드를 바꿉니다.

**되돌리기는 성공 경로와 같은 강도로 마무리합니다.** 파일을 제자리에 놓는 것으로 끝나지
않습니다 — 시작 전 스냅샷으로 소유권·퍼미션을 복원하고(복원 실패는 경고로 노출), 컴파일
캐시는 **파일만** 지우며(`clearAllCaches()` 를 부르면 되돌린 디스크를 신버전 클래스를 든
프로세스가 재발견합니다), 파일을 쓰는 구간이므로 단계 수행과 **같은 잠금** 안에서 돕니다.

### 유지보수 모드에 갇히지 않는다

업데이트는 스스로 유지보수 모드를 켭니다. 진행·되돌리기 경로가 그 안에 갇히면 사이트를
세워 놓고 이어 갈 수단이 사라지므로 두 겹으로 엽니다:

1. `MaintenanceModePage::MAINTENANCE_EXEMPT_URIS` — `status`·`advance`·`dismiss` 세 경로.
   실제 라우트 URI 와의 일치는 계약 테스트가 고정합니다(어긋나면 진행이 503 으로 멈춥니다).
2. `advance` 응답이 싣는 유지보수 우회 쿠키 — 관리자 화면(SPA 셸·다른 조회 API)까지 엽니다.

쿠키만으로는 부족합니다(만료·다른 기기·쿠키 차단). 목록만으로도 부족합니다(관리자 화면
자체가 열리지 않습니다).

3. 점검 화면의 관리자 확인 칸(`CoreUpdateMaintenanceAccess`, 경로 `core-update/maintenance-access`) — 진행·되돌리기
   도중 브라우저를 닫으면 다음 `advance` 를 부를 화면이 없고, 다른 브라우저·기기에는 우회 쿠키가 없어 `/admin/login`
   부터 503 입니다. 웹 실행이 `running`·`rolling_back`·`rollback_failed` 이고 점검 모드일 때만 점검 화면이 이 칸을
   그리고, `MaintenanceModePage` 가 그 POST 를 **직접** 처리합니다(라우트로 보내면 쿠키 암호화가 우회 쿠키를 감싸 복호화
   전의 점검 미들웨어가 읽지 못하고, 세션 전에 그린 화면은 CSRF 토큰을 실을 수 없습니다). 코어 업데이트 권한
   (`core.system.update`)·로그인 가능 상태의 계정이면 우회 쿠키만 주고 업데이트 탭으로 보냅니다 — 로그인 세션·토큰은
   만들지 않아 평소 로그인(2단계 인증 포함)을 그대로 거칩니다. 실패 사유는 가르지 않고 IP 마다 10분에 5회로 제한합니다.
   틀린 비밀번호는 일반 로그인 실패와 같은 계수기(`core.auth.login_failed` → 로그인 실패 리스너)에 세고, 잠긴 계정(가입되지
   않은 주소의 잠금 흉내 포함)은 비밀번호가 맞아도 통과하지 못합니다 — 잠긴 동안의 시도는 세지 않습니다(일반 로그인과 같음).
   웹 실행이 없는 점검(`php artisan down` 등)에서는 칸·통로가 닫혀 일반 점검의 우회 수단이 되지 않습니다.
   서버가 화면 없이 스스로 남은 조각을 이어 가는 구조(예약 작업·자기 호출)는 없습니다 — 화면이 진행을 모는 설계 그대로입니다.

### 권한과 활동 로그

`core.system.update` 는 설정 수정과 분리된 권한이며 최고관리자 역할에만 기본
부여됩니다. 이 권한이 없는 관리자에게 화면은 업데이트 영역을 **비활성이 아니라
미표시**로 다룹니다.

실행·성공·실패는 훅(`core.core_update.started` · `.completed` · `.failed`)으로 발행되고
`CoreActivityLogListener` 가 활동 로그에 남깁니다. 실패 로그에는 되돌리기 결과가 함께
실립니다 — 「실패했다」와 「사이트가 지금 어떤 상태인가」는 다른 사실입니다.

이 세 훅은 **웹 업데이트 경로(`CoreUpdateOrchestrator`)에서만 발행됩니다.** SSH/CLI 의
`php artisan core:update` 는 발행하지 않으므로, 이 훅을 구독하는 확장은 CLI 업데이트를
관측하지 못합니다 — 활동 로그에도 CLI 실행은 남지 않습니다.

### 수동 ZIP 소스

외부 접속이 막힌 서버는 릴리스 ZIP 을 `storage/app/core_pending` 에 올려 두고 이름으로
지정합니다. 디렉토리는 서버가 정하고 **이름만** 받으며, 해석 결과가 그 디렉토리 밖을
가리키면 거부합니다. 이후는 자동 내려받기와 같은 파이프라인이며, 버전 비교도 동일하게
적용됩니다(웹에는 `--force` 가 없습니다).

### 정리 주체

전개 디렉토리(`storage/app/core_pending/core_*`)는 `cleanupPending()` 이 정상·처리된 실패
경로에서만 지웁니다. 치명 오류·요청 시간 초과·강제 종료에서는 그 코드가 돌지 않으므로,
`StorageLeftoverPruneService` 의 `core_pending_staging` 대상군이 보존일이 지난 것을
회수합니다. 이름 규약은 `CoreUpdateService::PENDING_DIR_PREFIX` 가 소유하고 정리 쪽이 그
상수를 읽습니다.

## 참고 파일 위치

| 파일 | 경로 |
|------|------|
| CoreUpdateCommand | `app/Console/Commands/Core/CoreUpdateCommand.php` |
| CoreCheckUpdatesCommand | `app/Console/Commands/Core/CoreCheckUpdatesCommand.php` |
| CoreUpdateService | `app/Services/CoreUpdateService.php` |
| CoreUpdateController | `app/Http/Controllers/Api/Admin/CoreUpdateController.php` |
| CoreVersionChecker | `app/Extension/CoreVersionChecker.php` |
| CoreBackupHelper | `app/Extension/Helpers/CoreBackupHelper.php` |
| UpgradeStepInterface | `app/Contracts/Extension/UpgradeStepInterface.php` |
| UpgradeContext | `app/Extension/UpgradeContext.php` |
| 코어 업그레이드 디렉토리 | `upgrades/` |
| 코어 설정 | `config/app.php` (version, update 섹션) |
| 역할/권한/메뉴 정의 | `config/core.php` |
