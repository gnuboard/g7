# LanguagePackService (백엔드 Service 레이어)

## TL;DR (5초 요약)

```text
1. LanguagePackService 가 install/activate/deactivate/uninstall 도메인 로직 단일 진입점 — 컨트롤러는 Service 만 주입
2. 슬롯(scope, target_identifier, locale) 당 active 1개는 application-level 트랜잭션이 보장 (functional unique index 미사용)
3. ZIP/GitHub/URL 3가지 설치 소스 — 모두 finalizeInstall() 로 합류 (manifest 검증 → 보안 검사 → 의존성 → 디렉토리 이동 → DB 등록)
4. 활성/비활성 시 HookManager::doAction('core.language_packs.after_activate' / 'after_deactivate', $pack) 발행 — Listener 가 DB JSON 컬럼 동기화
5. 보안: backend/ 외 PHP 차단 + eval/include/exec 정적 분석 거부 + 다운그레이드 차단 + 체크섬 검증 (URL 설치)
```

## 위치 및 의존성

- **Service**: `app/Services/LanguagePackService.php`
- **Repository**: `app/Repositories/LanguagePackRepository.php` (인터페이스: `app/Contracts/Repositories/LanguagePackRepositoryInterface.php`)
- **Validator**: `app/Services/LanguagePack/LanguagePackManifestValidator.php`
- **Registry**: `app/Services/LanguagePack/LanguagePackRegistry.php` (런타임 싱글톤)
- **SeedInjector**: `app/Services/LanguagePack/LanguagePackSeedInjector.php` (HookManager 필터 리스너)
- **Translator**: `app/Services/LanguagePack/LanguagePackTranslator.php` (Laravel Translator override)
- **BundledRegistrar**: `app/Services/LanguagePack/LanguagePackBundledRegistrar.php` (확장 내장 가상 등록)

## 공개 API

```php
class LanguagePackService
{
    // 조회 — list() 는 DB 행 + 가상 보호 행 + 미설치 번들 가상 행을 병합하며,
    //         각 행에 드리프트 파생 플래그 files_missing(active 인데 설치본 부재) 을 부여
    public function list(array $filters = [], int $perPage = 20): LengthAwarePaginator;
    public function find(int $id): ?LanguagePack;
    public function getUninstalledBundledPacks(array $filters = []): Collection;   // lang-packs/_bundled/ 미설치 번들 가상 행
    public function getDriftedInstalledPacks(array $filters = []): Collection;     // 설치 행 존재 + 설치본 파일 부재 (복구 대상)

    // 설치 (4가지 소스)
    public function installFromBundled(string $identifier, bool $autoActivate = false, ?int $installedBy = null, bool $force = false): LanguagePack;
    public function installFromFile(UploadedFile $file, bool $autoActivate = true, ?int $installedBy = null): LanguagePack;
    public function installFromGithub(string $githubUrl, bool $autoActivate = true, ?int $installedBy = null): LanguagePack;
    public function installFromUrl(string $url, ?string $checksum, bool $autoActivate = true, ?int $installedBy = null): LanguagePack;

    // 상태 전환
    public function activate(LanguagePack $pack): LanguagePack;
    public function deactivate(LanguagePack $pack): LanguagePack;
    public function uninstall(LanguagePack $pack, bool $cascade = false): void;
}
```

## 설치 흐름 (finalizeInstall)

```
ZIP/GitHub/URL → _pending/{tmp-uuid}/ 추출
  ↓
ZipInstallHelper::findManifest('language-pack.json')
  ↓
LanguagePackManifestValidator::validate($manifest, $packageRoot)
  ↓
assertSecurityRules($packageRoot, $manifest)
  - backend/ 외의 .php 파일 → RuntimeException
  - eval/include/require/exec/system/popen/proc_open 패턴 발견 → RuntimeException
  ↓
assertDependencies($manifest)
  - scope=core 면 통과
  - scope ∈ {module, plugin, template} + requires.depends_on_core_locale=true (기본)
    → registry->hasActiveCoreLocale($manifest['locale']) 확인 → false 면 거부
  ↓
assertTargetExtensionExists($manifest)
  - scope ∈ {module, plugin, template}
    → modules/plugins/templates 테이블에서 target_identifier 존재 확인
  ↓
assertNotDowngrade($existing, $manifest)
  - identifier 가 이미 설치되어 있으면 version_compare 로 다운그레이드 차단
  ↓
checkTargetVersionMismatch($manifest)
  - target_version_constraint vs 대상 확장의 현재 version semver 비교
  - 불일치 시 target_version_mismatch=true 플래그만 저장 (차단하지 않음, 경고만)
  ↓
File::moveDirectory($packageRoot, lang-packs/{identifier}/)
  ↓
DB::transaction:
  - 슬롯 비어있고 autoActivate=true → 신규 레코드 status=active + activated_at=now
  - 그 외 → status=installed
  - Repository::create or update
  ↓
LanguagePackRegistry::invalidate()
  ↓
HookManager::doAction('core.language_packs.after_activate', $pack)  (active 진입한 경우만)
```

## 프로비저닝 커맨드 (`language-pack:provision`)

fresh install · 복구 · 시더가 공유하는 멱등 프로비저닝 SSoT 입니다.

```bash
php artisan language-pack:provision                # supported_locales 의 비-base 로케일 대상
php artisan language-pack:provision --locale=ja     # 특정 로케일 한정
php artisan language-pack:provision --scope=core    # 스코프 한정
php artisan language-pack:provision --no-activate   # 설치만, 자동 활성화 생략
```

- 대상 로케일 = `--locale` 지정값, 미지정 시 `config('app.supported_locales')` ∖ base locale(ko/en).
- 대상 후보는 두 갈래입니다 — `getUninstalledBundledPacks()` (신규 프로비저닝) + `getDriftedInstalledPacks()` (수동 복구). 각각 `installFromBundled($id, autoActivate: true)` 를 호출하며, 정상 설치된 팩은 두 집합 어디에도 속하지 않으므로 **재실행 시 신규 설치 0 건으로 수렴(멱등)**.
- 드리프트 팩은 슬롯이 점유돼 있어 미설치 목록에 잡히지 않습니다. 이 커맨드가 복구까지 겸하지 않으면 드리프트는 화면에서 보이기만 하고 CLI 로는 고칠 수 없습니다. 재설치는 `finalizeInstall` 의 update 경로를 타므로 동일 버전 재설치도 다운그레이드로 차단되지 않습니다.
- 설치 차단 사유(`install_blocked_reason`: 대상 확장 미설치/미활성 등)가 있는 팩은 skip + 경고(best-effort). 신규 도메인 로직 없이 기존 Service 메서드만 조합합니다.

## 드리프트 표면화 (files_missing / bundled_source_available)

`list()` 와 `getPacksForExtension()` 은 각 팩에 파생 플래그 두 개를 부여합니다.

| 플래그 | 판정 | 용도 |
| --- | --- | --- |
| `files_missing` | 실제 DB 설치 행(`exists=true`) + `status=active` 인데 설치본 디렉토리(`resolveDirectory()`) 부재 | 드리프트 발견 — 가상 보호 행/미설치 가상 행은 대상 아님 |
| `bundled_source_available` | `lang-packs/_bundled/{identifier}` 실재 | 복구 가능 여부 — 재설치 버튼 노출 판정 |

`bundled_source_available` 은 설치 경로(`source_type`)가 아니라 **번들 소스의 실재 여부**로 판정합니다. 판정을 `source_type` 에 걸면 zip/url 로 설치된 팩이 드리프트됐을 때 배지만 뜨고 복구 수단이 없는 상태로 남습니다. 반대로 번들 소스가 없는 서드파티 팩에는 재설치 버튼이 뜨지 않습니다 — 복구할 소스 자체가 없기 때문입니다.

이 플래그로 `language-pack:list` 는 `active (파일 없음)` 을 표기하고, 관리자 화면은 배지 + 원클릭 재설치를 노출합니다.

## 슬롯 스위칭 (activate / deactivate)

**activate**: 동일 슬롯의 기존 active 팩을 inactive 로 강등 + 대상 팩을 active 로 승격. 단일 트랜잭션 내에서 원자적으로 수행. application-level 보장이므로 DB functional unique index 불필요.

```php
DB::transaction(function () use ($pack) {
    $current = repository->findActiveForSlot($pack->scope, $pack->target_identifier, $pack->locale);

    if ($current && $current->id !== $pack->id) {
        repository->update($current, ['status' => 'inactive']);
        HookManager::doAction('core.language_packs.after_deactivate', $current);
    }

    repository->update($pack, ['status' => 'active', 'activated_at' => now()]);
});

registry->invalidate();
HookManager::doAction('core.language_packs.after_activate', $pack);
```

**deactivate**: 비활성화 후 슬롯에 다른 후보(`inactive`/`installed`)가 있으면 자동 active 로 승격(`promoteSlotSuccessor`). 보호된 팩(번들 ko/en 등 `is_protected=true`)은 비활성화 거부.

## 우회 불가 규칙

| 규칙 | 위반 시 |
|---|---|
| backend/ 외에 .php 파일 포함 | 설치 거부 (`RuntimeException`) |
| backend/*.php 에 `eval/include/require/exec/system/popen/proc_open` 패턴 | 설치 거부 |
| ZIP 경로 이탈 (`..` 포함) | manifest validator 가 거부 (`contents.*` 체크) |
| 의존성 미충족 (모듈/플러그인/템플릿 + 코어 언어팩 없음) | 설치 거부 |
| 대상 확장 미설치 | 설치 거부 |
| 다운그레이드 시도 | 설치 거부 |
| protected 팩 비활성화/제거 | `RuntimeException` |
| 슬롯당 active 2개 이상 | application 트랜잭션이 자동으로 기존 active 강등 |

## 이벤트 (HookManager 액션 훅)

| 액션 이름 | 페이로드 | 발행 시점 |
|---|---|---|
| `core.language_packs.after_activate` | `LanguagePack $pack` | 활성 진입 직후 (install + activate) |
| `core.language_packs.after_deactivate` | `LanguagePack $pack` | 비활성 진입 직후 (deactivate + 슬롯 스위칭 시 강등) |

확장은 `HookManager::addAction('core.language_packs.after_activate', fn ($pack) => ...)` 로 자유롭게 구독 가능.

## 가상 등록 (LanguagePackBundledRegistrar)

확장(modules/plugins/templates) install/uninstall/update 후크에 자동 연결되어 확장의 `lang/` 또는 `resources/lang/` 디렉토리를 스캔, 발견된 locale 별로 `bundled_with_extension` 가상 레코드를 `language_packs` 테이블에 등록합니다.

| 후크 | 동작 |
|---|---|
| `core.modules.after_install` / `after_update` | `syncFromExtension('module', identifier, vendor, version, langDir)` |
| `core.modules.after_uninstall` | `cleanupForExtension('module', identifier)` — 가상 레코드 삭제 + 외부 벤더 레코드는 `error` 상태 전환 |
| (plugin/template 동일 패턴) | 9개 후크 리스너 등록 |

## 캐시 무효화

`LanguagePackRegistry::invalidate()` 호출 시점:
- `installFromFile` / `Github` / `Url` 의 finalizeInstall 종료 직전
- `activate` / `deactivate` / `uninstall` 트랜잭션 종료 직후
- `BundledRegistrar::syncFromExtension` / `cleanupForExtension` 종료 직후

invalidate 가 무효화하는 캐시:
- `activePacksCache` (활성 언어팩 컬렉션)
- `activeCoreLocalesCache` (코어 활성 locale 배열)

## 트러블슈팅

**Q. 활성화 후에도 trans() 가 새 locale 키를 못 찾습니다.**
- A. `LanguagePackTranslator` 의 `addCoreFallbackPath` 가 호출되었는지 확인. `LanguagePackServiceProvider::boot()` 가 부팅 시 1회 등록. install/activate 후 폴백 경로가 추가되도록 ServiceProvider 부팅을 다시 실행해야 함 (다음 요청부터 자동 반영).

**Q. 동일 슬롯에 active 2개가 동시에 존재합니다.**
- A. application 트랜잭션 외부에서 직접 DB 조작한 경우. `LanguagePackService::activate` / `deactivate` 외 경로로 status 변경 금지. 복구: `php artisan tinker` 에서 `LanguagePackRepository::getPacksForSlot()` 호출 후 1개만 active 유지.

**Q. 사용자가 직접 수정한 다국어 키를 언어팩이 덮어썼습니다.**
- A. `HasUserOverrides` trait 미사용 모델일 가능성. `Permission` 등은 trait 미적용 → 본 보존 정책은 `Role/Menu/NotificationDefinition/NotificationTemplate/Module/Plugin/Template` 만 적용됩니다. `language_pack` 채널의 감사 로그에서 `action: skipped/preserved` 기록 확인.

**Q. 활성 언어팩 ja 가 설치돼 있는데 비인증 API(로그인 실패 등) 응답만 영문으로 옵니다.**
- A. 응답 메시지 생성 경로가 `App::getLocale()` 을 신뢰하는지 점검. SetLocale 미들웨어가 `config('app.supported_locales')` 동적 화이트리스트(활성 코어 언어팩 포함)로 이미 정확히 set 했으므로, 모든 응답 헬퍼/미들웨어/예외 핸들러는 그 결과만 사용해야 합니다. 자체 화이트리스트(`['ko', 'en']` 등)로 ja 를 거부하면 fallback 으로 떨어져 영문이 노출됩니다. 점검 위치: `ResponseHelper::getUserLocale()`, `EnsureTokenIsValid::handle()`, `MaintenanceModePage::handle()`, FormRequest `failedValidation()` 오버라이드, 플러그인의 `__()` 직접 호출 분기.

**Q. 메인터넌스 모드(503) 응답이 활성 언어팩 로케일로 안 나옵니다.**
- A. `MaintenanceModePage` 는 SetLocale 미들웨어보다 먼저(prepend) 실행되므로 자체 `detectLocale()` 후 `app()->setLocale()` 을 호출해야 합니다. API 분기와 HTML 분기 모두 `setLocale()` 이 `__()` 호출보다 앞서야 합니다.

## 관련 문서

- 시스템 개요: `docs/extension/language-packs.md`
- 데이터 동기화 헬퍼: `docs/backend/data-sync-helpers.md`
- 사용자 수정 보존: `docs/backend/user-overrides.md`
- 알림 시스템 (3-tier): `docs/backend/notification-system.md`
