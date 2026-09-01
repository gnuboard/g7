# 코어 업데이트 시스템 (Core Update System)

> 코어 버전 업데이트의 감지, 다운로드, 적용, 롤백, 업그레이드 스텝 실행을 관리하는 시스템

## TL;DR (5초 요약)

```text
1. 코어 업그레이드 스텝: upgrades/ 디렉토리 (프로젝트 루트), 네임스페이스 App\Upgrades
2. 마이그레이션 = 스키마 변경만, 데이터 백필/변환 = 업그레이드 스텝 (역할 분리 필수)
3. 업데이트 실행 흐름: 11단계 (감지 → 다운로드 → 백업 → 적용 → 마이그레이션 → 동기화 → 업그레이드 → 마무리)
4. 롤백: CoreBackupHelper로 백업 생성, 실패 시 자동 복원
5. 부트스트랩 호환성 검증: .env의 APP_VERSION < 확장 g7_version 시 자동 비활성화 (1시간 캐시)
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

### Step 1: 업데이트 확인

| 모드 | 동작 |
|------|------|
| 일반 | `checkForUpdates()` → GitHub API 호출 → 사용자 확인 프롬프트 |
| `--source={path}` | 지정된 디렉토리를 소스로 사용 (GitHub 스킵) |
| `--zip={path}` | 지정된 ZIP 파일을 추출하여 소스로 사용 (GitHub 스킵). 추출 후 `config/app.php` 에서 버전 자동 판별. ZIP 구조가 GitHub zipball 의 `owner-repo-hash/` 래퍼이든 평탄 루트이든 모두 지원 |
| `--local` | 현재 코드베이스를 소스로 사용 (GitHub 스킵) |
| `--force` | 버전 비교 스킵, 동일 버전이어도 강제 실행 |
| `--vendor-mode=auto\|composer\|bundled` | vendor 설치 모드 지정 (기본 `auto` — composer 가능 시 composer, 불가 시 vendor-bundle.zip 추출). `bundled` 강제 시 공유 호스팅에서도 설치 가능 |

> `--source` / `--zip` / `--local` 은 **상호 배타적**입니다. 동시 지정 시 커맨드는 시작 전에 FAILURE(1) 로 종료됩니다.

### Step 4: 다운로드 및 추출

```text
추출 전략 (폴백 체인):
1. ZipArchive (PHP zip 확장) → class_exists(ZipArchive::class)
2. unzip CLI 명령어 → ZipArchive 미사용 시 폴백

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

> **protected_paths 오버라이드 (공개 #64 / 내부 #452)**: `protected_paths` 에는 확장 부모(`modules`·`plugins`·`templates`·`lang-packs`)가 포함되지만, `targets` 에는 `{domain}/_bundled` 가 명시된다. 3-way 산출(`computeApplyList`)과 신규 파일 manifest(`writeNewFilesManifest`)는 "targets 에 더 구체적(하위)으로 명시된 경로가 상위 protected 를 오버라이드"하도록 판정한다. 이로써 코어 배포본에 포함된 번들 확장의 갱신 파일(`_bundled/{id}/composer.json`·`vendor-bundle.json` 등)이 코어 업데이트로 정상 반영된다. 오버라이드는 target 이 protected 보다 **더 깊을 때만** 적용되므로, `storage`(target) == `storage`(protected) 같은 동일 경로는 여전히 제외된다. 확장 부모를 protected 에 둔 원래 의도(자동 발견 폴백이 활성 서브디렉토리 `modules/sirsoft-*` 를 삭제하는 #347 방어)는 그대로 유지된다 — 자동 발견 폴백은 targets 순회가 아니므로 오버라이드 영향을 받지 않는다.

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
  `preserveLinkPaths: ['storage']` 화이트리스트로 링크/junction 을 보존 (#43, 아래 §참조)

공통:
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

> **`public/storage` symlink 보존 + 종료 시 복구 (#43)**: 심층 방어 2층 구조로 `--prune` 실행 후에도 `public/storage` symlink 가 정상 유지된다.
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
> **원장이 없는 설치본은 씨앗한다.** 설치 완료 시점(인스톨러)과 7.1.0 업그레이드 스텝(`upgrades/data/7.1.0/migrations/02_SeedDeployFragmentLedger.php`)이 `CoreUpdateService::seedDeployFragmentLedger($projectRoot)` 와 같은 로직으로 현재 디스크의 해시를 원장에 쓴다 — 그 시점의 디스크는 **사이드카(`.dist`·`.dist.new`)가 없는 조각에 한해** 릴리즈 판본과 같다. 사이드카가 있는 조각은 씨앗하지 않는다(씨앗하면 운영자 수정본이 「코어가 쓴 그대로」로 읽혀 다음 업데이트가 덮어쓴다). 원장이 이미 있으면 손대지 않는다. 스텝은 업그레이드 경로 규율(기존 클래스의 신규 메서드 호출 금지)로 로컬 로직을 갖고, 서비스 씨앗기와의 결과 일치는 `tests/Unit/Upgrades/SeedDeployFragmentLedgerTest` 가 대조한다.
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

> **변경 이력 (7.0.0-beta.2)**: `syncCoreMailTemplates()` 단계는 알림 시스템 통합(#146)으로 제거되었습니다. 메일 템플릿은 `notification_definitions` + `notification_templates` 로 통합되었으며, `Upgrade_7_0_0_beta_2` 가 운영 환경 데이터 이관과 알림 정의 시드를 처리합니다.

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

> **spawn 자식은 이전 버전의 config 캐시로 부팅한다**: 부모는 Step 10(spawn) 전에 config 캐시를 비우지 않는다 — `clearAllCaches()` 는 Step 11 이다. 그래서 이전 버전 설치본에 `bootstrap/cache/config.php` 가 있으면(설치 마법사·설정 저장·확장 업데이트가 만든다) 자식은 그 캐시로 부팅하고, 자식의 `config('app.version')` 은 부모가 env 로 넘긴 `APP_VERSION={toVersion}` 이 아니라 캐시에 박힌 fromVersion 이다. 업데이트 흐름 안에서 "지금 프로세스의 코어 버전" 을 판정하는 코드는 `config('app.version')` 을 직접 읽지 않고 `CoreVersionChecker::getCoreVersion()`(env 우선, config 폴백)을 쓴다. `runUpgradeSteps()` 의 stale 메모리 가드가 config 만 읽던 시절에는 정상 spawn 자식을 stale 부모로 오판해 스텝이 0건인 릴리즈에서도 핸드오프로 중단됐다(7.0.9→7.0.10). 부모 in-process fallback 에서는 env 가 `.env` 의 fromVersion 이므로 가드는 그대로 발동한다.
>
> 같은 이유로 자식이 `config('app.update.*')` 로 읽는 목록(쓰기 권한 디렉토리 등)도 캐시에 박힌 옛 목록이다 — 신버전이 항목을 추가해도 자식에게 보이지 않는다. 방어는 두 겹이다: ① 부모(7.0.10+)는 `spawnUpgradeStepsProcess()` 가 `proc_open` 직전에 `ConfigCacheHelper::clear()` 로 캐시를 비워 자식이 디스크 config + `.env` + spawn env 로 부팅하게 한다(캐시는 Step 11 이 다시 만든다). ② 자식(7.0.10+)은 이전 버전 부모가 캐시를 남겨 둔 경우를 위해, 캐시 파일이 있으면 `CoreUpdateService::freshDiskUpdateConfig()` 로 디스크의 `config/app.php` 를 직접 읽는다 — 캐시 부팅에서는 `.env` 도 로드되지 않으므로 그 안에서 `.env` 를 먼저 불변 로드한다(프로세스 env 의 `APP_VERSION` 은 덮어쓰지 않는다).

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
2. 캐시 클리어: config, cache, route, view
3. bootstrap/cache 파일 삭제 (services.php, packages.php)
4. php artisan package:discover 재실행
5. php artisan extension:update-autoload (코어 업데이트로 _bundled 변경 가능)
6. _pending 격리 디렉토리(core_{ts}) 루트째 삭제
7. 성공 시 백업 삭제 (이후 `hotfix:rollback-stale-files` 는 대상이 없다)
8. 유지보수 모드 해제
```

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

--force 플래그가 코어 업데이트에 지정된 경우: 프롬프트 스킵 + 전역 overwrite 자동 적용 (CI 대응)
```

> **spawn 위임의 배경 (beta.4 이후)**: 부모(`core:update`) 프로세스는 코어 업그레이드 *직전* 버전의 메모리 상태를 보유한다. 부모가 직접 `ModuleManager::updateModule()` 등을 호출하면 신버전 코어가 도입한 sync 메서드(예: `syncModuleIdentityPolicies` `@since beta.4`)가 메모리에 부재해 호출 자체가 누락된다. `executeBulkUpdate` 는 사용자 선택만 매니페스트로 직렬화한 뒤 `proc_open` 으로 `core:execute-bundled-updates` 를 spawn — 자식은 fresh PHP 프로세스라 디스크의 신버전 코어 코드를 메모리에 로드한 상태에서 update 메서드 호출. proc_open 미지원 환경에서는 in-process fallback 으로 안전 전환. 자식 stdout 은 부모 콘솔로 forwarding 하며, 종료 직전 `[BUNDLED-RESULT]` 표식 라인으로 결과 카운트를 회신한다.

> **선언형 산출물 일괄 sync — `syncDeclarativeArtifacts`**: `ModuleManager::syncDeclarativeArtifacts(ModuleInterface)` / `PluginManager::syncDeclarativeArtifacts(PluginInterface)` 는 모듈/플러그인의 모든 declarative 데이터(역할·권한·메뉴·cleanup·IDV 정책·IDV 메시지·알림 정의) 를 한 묶음으로 동기화하는 public 진입점이다. `installModule` / `updateModule` 트랜잭션이 내부적으로 호출하며, 코어 업그레이드 사후 보정(`Upgrade_7_0_0_beta_4` 등) 도 활성 모듈/플러그인을 순회하며 이 메서드를 호출해 일회성 회복을 수행한다. 각 sync 메서드는 helper 의 user_overrides 보존 패턴을 따르므로 정상 환경 재호출 무해 (멱등).

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

### 롤백 실패 시

```text
- 복원 실패 자체는 예외를 전파하지 않음 (에러 로그만 기록)
- 관리자에게 안내: "이전 버전으로 사이트를 운영하려면: php artisan up"
- 유지보수 모드 bypass secret 출력
```

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

> 파일: `app/Providers/CoreServiceProvider.php` — `validateAndDeactivateIncompatibleExtensions()`, `validateAndDeactivateIncompatibleTemplates()`

**애플리케이션 부트(boot) 시마다** CoreServiceProvider가 모든 활성 확장의 `g7_version` 요구사항을 검증합니다.
`config('app.version')`이 확장의 요구 버전을 충족하지 않으면 **해당 확장을 자동으로 비활성화**합니다.

#### 검증 흐름

```text
1. CoreServiceProvider::boot() 실행
2. CoreVersionChecker::getCacheKey($type) 캐시 확인 (TTL: 1시간)
3. 캐시 없으면 → 모든 활성 확장 순회
4. 각 확장의 g7_version (예: ">=7.0.0-beta.1") 확인
5. Semver::satisfies(config('app.version'), $constraint) 실행
6. false 반환 시 → Manager::deactivateModule/Plugin/Template() 호출
7. 캐시 저장 (1시간 유효)
```

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
| `checkSystemRequirements()` | `(): array` | 추출 도구 검증 (ZipArchive/unzip) |
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
| `CoreBackupHelper::computeApplyList()` | `(string $backupPath, string $sourcePath, array $targets, array $protectedPaths, array $excludes): array` | 3-way 판정으로 증분 적용 대상(added/changed) 산출 (`apply`, `added_count`, `changed_count`, `has_symlink`). targets 에 명시된 `{domain}/_bundled` 는 상위 protected(`modules` 등)를 오버라이드해 목록에 포함 (공개 #64 / 내부 #452) |

### 적용 및 설치

| 메서드 | 시그니처 | 설명 |
|--------|---------|------|
| `applyUpdate()` | `(string $sourcePath, ?Closure $onProgress, bool $prune = false, ?array $applyList = null): void` | _pending → base_path 적용. `$applyList` 지정 + `!$prune` 이면 증분(코어 변경분만), 그 외 전체 덮어쓰기 + orphan 삭제. `public` 타깃은 `copyDirectory(..., preserveLinkPaths: ['storage'])` 로 `public/storage` symlink/junction 을 orphan 삭제에서 보호 (#43) |
| `StorageLinkHelper::ensurePublicStorageLink()` | `(?\Psr\Log\LoggerInterface $logger = null): void` | `public/storage` 멱등 복구. 정상 링크면 no-op, 부재/손상이면 `storage/app/public` 링크 재생성(부재 시 신규, 손상 디렉토리는 `.broken.{YmdHis}` 백업 후). 재생성 링크는 부모 `public/` 의 소유자/그룹을 `lchown`/`lchgrp` 상속(sudo 후 root:root 잔존 차단). Windows junction 폴백. `CoreUpdateCommand` 종료 시점 + migration 05 가 호출 (#43) |
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
| `clearAllCaches()` | `(): void` | config/cache/route/view 클리어 + package:discover |

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
php artisan core:update [--force] [--no-backup] [--prune] [--no-maintenance] [--local] [--source={path}]
```

| 옵션 | 설명 |
|------|------|
| `--force` | 버전 비교 스킵, 동일 버전이어도 강제 업데이트 |
| `--no-backup` | 백업 생성 스킵 (Step 5). 증분 적용 불가 → 전체 덮어쓰기로 회귀 |
| `--prune` | 코어가 제거한 파일 정리 + targets 전체 덮어쓰기(기존 방식). 미지정 시 코어가 실제 변경/추가한 파일만 적용(3-way)하고 나머지는 보존 |
| `--no-maintenance` | 유지보수 모드 스킵 (Step 3) |
| `--local` | 현재 코드베이스를 소스로 사용 (GitHub 스킵) |
| `--source={path}` | 지정 디렉토리를 소스로 사용 (GitHub 스킵) |

**종료 코드**:
- `0` (SUCCESS): 업데이트 성공 또는 이미 최신 버전
- `1` (FAILURE): 요건 미충족 또는 업데이트 실패

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
| POST | `/api/admin/core-update/check` | `core.settings.update` | 코어 업데이트 확인 (422 시 check_failed) |
| GET | `/api/admin/core-update/changelog` | `core.settings.read` | 코어 변경 로그 조회 (from_version, to_version) |
| GET | `/api/admin/core-update/status` | `core.system.update` | 웹 실행 상태 (W6 4상태의 근거) |
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

`.env` 에서 `G7_UPDATE_EXCLUDES` · `G7_UPDATE_TARGETS` · `G7_UPDATE_PROTECTED_PATHS` · `G7_UPDATE_RESTORE_OWNERSHIP` · `G7_UPDATE_RESTORE_OWNERSHIP_GROUP_WRITABLE` 로 재정의할 수 있다. 재정의 값은 기본 목록을 **통째로 대체**하므로 전체 목록을 다시 적는다 — 예를 들어 `G7_UPDATE_EXCLUDES` 에서 `build/ext` 가 빠지면 `--prune` 업데이트가 정적 게시본을 지운다. 기본값은 `.env.example` 에 주석으로 실려 있다.

---

## 12. 에러 처리 및 로깅

### 시스템 요건 검증

```text
- ZipArchive 또는 unzip 명령어 중 하나 이상 필요
- 미충족 시 커맨드 실패 (EXIT 1) + 사용 가능 방법 목록 출력
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
| **추출** | 폴백 체인 (ZipArchive → unzip) | ExtensionPendingHelper | ExtensionPendingHelper |
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

### 권한과 활동 로그

`core.system.update` 는 설정 수정과 분리된 권한이며 최고관리자 역할에만 기본
부여됩니다. 이 권한이 없는 관리자에게 화면은 업데이트 영역을 **비활성이 아니라
미표시**로 다룹니다.

실행·성공·실패는 훅(`core.core_update.started` · `.completed` · `.failed`)으로 발행되고
`CoreActivityLogListener` 가 활동 로그에 남깁니다. 실패 로그에는 되돌리기 결과가 함께
실립니다 — 「실패했다」와 「사이트가 지금 어떤 상태인가」는 다른 사실입니다.

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
