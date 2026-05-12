<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\UpgradeContext;
use App\Services\CoreUpdateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * 코어 7.0.0-beta.4 업그레이드 스텝
 *
 * beta.4 신규 인프라(IDV / 언어팩) 의 데이터 정합성을 확보한다.
 *
 * 본 step 의 책임:
 *   1. user_overrides dot-path sub-key 인프라 마이그레이션 — beta.4 신규 다국어 sub-key
 *      저장 패턴으로 기존 컬럼명 단위 항목을 활성 locale dot-path 로 일괄 변환.
 *   2. fresh config 기반 코어 데이터 재동기화 — beta.3 출시본의 CoreUpdateCommand 가
 *      Step 9 에서 stale 부모 메모리 config 로 syncCore* 를 호출하던 박제 결함 사후 보정.
 *      `CoreUpdateService::reloadCoreConfigAndResync()` 1 회 호출로 권한·메뉴·알림 정의·
 *      IDV 정책·IDV 메시지 정의를 fresh config 로 일괄 upsert.
 *   3. IDV 캐시 무효화 — 재시딩 결과가 즉시 관리자 UI 에 반영되도록 캐시 키 4종 forget.
 *
 * 멱등성:
 *   - reloadCoreConfigAndResync 내부의 모든 시더가 SyncHelper / user_overrides 보존 패턴이라
 *     재실행해도 운영자 편집값을 덮어쓰지 않음. 재실행 무해.
 *   - dot-path 마이그레이션은 idempotent (이미 변환된 항목은 그대로 유지).
 *
 * @upgrade-path B
 *
 * 경로 B (CoreUpdateCommand 의 spawn 자식 프로세스 = 최신 코드 메모리) 로 실행된다.
 * beta.3 부터 spawnUpgradeStepsProcess 가 정식 도입되어 본 스텝은 항상 beta.4 메모리에서 동작.
 * 따라서 SyncHelper / Seeder / Model 등 신규 클래스를 직접 호출 가능.
 *
 * 상세: docs/extension/upgrade-step-guide.md 섹션 9 (업그레이드 경로).
 */
class Upgrade_7_0_0_beta_4 implements UpgradeStepInterface
{
    /** @var array<string, array<int, string>> 다국어 컬럼 — 각 모델 translatableTrackableFields 와 일치 */
    private const TRANSLATABLE_COLUMNS = [
        'roles' => ['name', 'description'],
        'menus' => ['name'],
        'notification_definitions' => ['name'],
        'identity_message_definitions' => ['name'],
    ];

    /**
     * 업그레이드 스텝을 실행합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        $context->logger->info('[beta.4] 업그레이드 스텝 시작');

        $this->recoverLangPacksBundled($context);
        $this->ensureLangPacksPermissions($context);
        $this->migrateUserOverridesToDotPath($context);
        $this->resyncCoreDataWithFreshConfig($context);
        $this->resyncBundledExtensionDeclarativeArtifacts($context);
        $this->normalizeStorageAppPermissionsForLegacyParent($context);
        $this->writePreservationMarkersForExistingExtensionStorage($context);
        $this->invalidateCaches($context);

        $context->logger->info('[beta.4] 업그레이드 스텝 완료');
    }

    /**
     * 활성 번들 모듈/플러그인의 선언형 산출물(IDV 정책 + IDV 메시지 + 알림 정의) 강제 재동기화.
     *
     * beta.3 → beta.4 결함의 두 축:
     *
     *   1. 부모 메모리 stale — `BundledExtensionUpdatePrompt` 가 `core:update` 부모(beta.3)
     *      프로세스에서 실행되어 `$moduleManager->updateModule(...)` 호출 시 beta.3 메모리의
     *      ModuleManager 코드 사용. beta.3 의 ModuleManager 에는 `syncModuleIdentityPolicies` /
     *      `syncModuleIdentityMessages` / `syncModuleNotificationDefinitions` 메서드 자체가
     *      부재 (모두 `@since 7.0.0-beta.4`) → 사용자가 yes 를 선택해도 시드되지 않음.
     *      향후 차단은 별도 구조 fix(번들 일괄 업데이트의 spawn 자식 위임) 로 처리.
     *
     *   2. 활성 dir 에 신규 declaration 부재 — beta.3 출시본의 활성 dir module.php/plugin.php
     *      에는 IDV/메시지/알림 declaration 메서드(getIdentityPolicies 등) 자체가 도입되지
     *      않았음. 따라서 활성 dir 을 fresh-load 해도 declaration count 0 → 시드 안 됨.
     *      production 검증: `SELECT source_type, COUNT(*) FROM identity_policies
     *      GROUP BY source_type;` → core/9 만 존재, module/plugin 0건.
     *
     * 본 step 은 spawn 자식(beta.4 메모리) 에서 실행되어 ModuleManager / PluginManager 의
     * 신규 public API `resyncAllActiveDeclarativeArtifacts()` 호출. 해당 메서드는 _bundled
     * 디렉토리(이번 코어 업그레이드로 출하된 beta.4 코드) 의 module.php/plugin.php 를
     * fresh-load 하므로 활성 dir 의 OLD 코드(declaration 부재) 와 무관하게 NEW declaration
     * 을 시드. helper 의 user_overrides 보존 패턴이 적용되어 정상 환경 재실행 무해 (멱등).
     *
     * 사용자 선택과의 양립: 신규 인프라 도입 시점의 활성 dir 에는 사용자가 거부할 수 있는
     * OLD declaration 자체가 없으므로 본 보정은 의지 위반 영역 외 (인프라 초기화 성격).
     * 미래 release(beta.4 → beta.5+) 의 신규 declaration 은 정상 흐름(번들 일괄 업데이트의
     * spawn 자식 → updateModule → syncDeclarativeArtifacts) 이 사용자 선택에 따라 처리.
     */
    private function resyncBundledExtensionDeclarativeArtifacts(UpgradeContext $context): void
    {
        $context->logger->info('[beta.4] 번들 모듈/플러그인 선언형 산출물 재동기화 시작');

        // 주: PSR-4 autoload 갱신은 `ExecuteUpgradeStepsCommand::handle` 진입 시점에
        // 1회 처리되어 본 메서드 호출 시점에는 fresh 상태가 보장된다.
        //
        // `resyncAllActiveDeclarativeArtifacts` 는 _bundled 디렉토리의 module.php/plugin.php
        // 를 fresh-load (evalFresh*) 하여 syncDeclarativeArtifacts 를 호출. 활성 dir 의 OLD
        // 코드(declaration 메서드 부재) 와 무관하게 _bundled 의 NEW 코드 기준으로 시드 +
        // 외부 설치 (_bundled 에 진입점 부재 — GitHub 직접 설치 등) 항목 자동 skip.

        try {
            $moduleResult = app(ModuleManager::class)->resyncAllActiveDeclarativeArtifacts();
            $context->logger->info(sprintf(
                '[beta.4] 모듈 재동기화 — synced %d, skipped %d, failed %d',
                count($moduleResult['synced']),
                count($moduleResult['skipped']),
                count($moduleResult['failed']),
            ));
            foreach ($moduleResult['failed'] as $identifier => $reason) {
                $context->logger->warning(sprintf('[beta.4] 모듈 재동기화 실패 — %s: %s', $identifier, $reason));
            }
        } catch (\Throwable $e) {
            $context->logger->warning('[beta.4] 모듈 매니저 접근 실패 — '.$e->getMessage());
        }

        try {
            $pluginResult = app(PluginManager::class)->resyncAllActiveDeclarativeArtifacts();
            $context->logger->info(sprintf(
                '[beta.4] 플러그인 재동기화 — synced %d, skipped %d, failed %d',
                count($pluginResult['synced']),
                count($pluginResult['skipped']),
                count($pluginResult['failed']),
            ));
            foreach ($pluginResult['failed'] as $identifier => $reason) {
                $context->logger->warning(sprintf('[beta.4] 플러그인 재동기화 실패 — %s: %s', $identifier, $reason));
            }
        } catch (\Throwable $e) {
            $context->logger->warning('[beta.4] 플러그인 매니저 접근 실패 — '.$e->getMessage());
        }

        $context->logger->info('[beta.4] 번들 모듈/플러그인 선언형 산출물 재동기화 완료');
    }

    /**
     * lang-packs 디렉토리의 그룹 소유권/그룹 쓰기 비트 사후 보정 (Stage 1 일회성).
     *
     * beta.4 신규 도입 디렉토리 `lang-packs/` `lang-packs/_pending` 가 beta.3 의
     * `update.restore_ownership` / `restore_ownership_group_writable` 목록에 부재 →
     * 신규 zip 추출 후 root 소유 + g-w 비트 부재 상태로 활성 디렉토리에 안착.
     * 결과: 언어팩 설치 시도 시 "쓰기 권한 없음".
     *
     * 본 메서드는 base_path() 의 owner/group 을 기준으로 lang-packs 트리를 재귀적으로
     * 정상화. 윈도우 / chown 미지원 환경은 helper 가 자동 skip 하므로 무해.
     *
     * 향후 회귀 차단: Stage 2 에서 config/app.php 의 restore_ownership 기본값에
     * lang-packs 추가됨 → beta.4→beta.5+ 에서는 본 메서드 호출이 멱등 무해.
     */
    private function ensureLangPacksPermissions(UpgradeContext $context): void
    {
        // beta.3→beta.4 일회성 보정. 부모(beta.3) 의 stale config 는 lang-packs 트리를
        // 인식하지 못하므로, spawn 자식의 fresh 코드/config 환경에서 직접 보장.
        //
        // CoreUpdateService::ensureWritableDirectories 일반 헬퍼에 위임 — 미래 release
        // 가 새 쓰기 권한 디렉토리를 도입할 때는 본 step 같은 단발 호출 없이
        // ExecuteUpgradeStepsCommand 진입 시 fresh config 의
        // restore_ownership_group_writable 항목을 자동 처리한다.

        $service = app(CoreUpdateService::class);
        $result = $service->ensureWritableDirectories(
            ['lang-packs', 'lang-packs/_pending', 'lang-packs/_bundled'],
            fn (string $level, string $msg) => $context->logger->$level('[beta.4] '.$msg),
        );

        if (! empty($result['warnings'])) {
            $context->logger->warning(sprintf(
                '[beta.4] lang-packs 권한 정상화 — 경고 %d 건. 수동 복구가 필요할 수 있음.',
                count($result['warnings']),
            ));
        }
    }

    /**
     * beta.4 신규 도입 디렉토리 `lang-packs/_bundled/` 누락 보정.
     *
     * beta.3 의 CoreUpdateCommand Step 7(applyUpdate) 는 부모 프로세스(beta.3 메모리) 에 로드된
     * `app.update.targets` 를 사용하는데, beta.3 의 targets 에는 `lang-packs/_bundled` 가
     * 없어 신버전 zip 안에 포함된 본 디렉토리가 활성 디렉토리로 복사되지 않는다.
     * 결과적으로 `LanguagePackService::collectBundledLangPackUpdates()` 가 빈 결과 →
     * BundledExtensionUpdatePrompt 의 언어팩 일괄 설치가 0건 → 관리자 UI "설치된 언어팩 없음".
     *
     * 본 step 은 spawn 자식(beta.4 코드, Step 11 cleanup 이전) 에서 실행되어 _pending 디렉토리가
     * 아직 살아있다. 누락 시 `_pending/extracted/<root>/lang-packs/_bundled/` 에서 활성 디렉토리로
     * 복사하여 즉시 보정한다. _pending 도 사라진 사후 복구 시점이라면 명시 경고 + 수동 복구 안내.
     *
     * 향후 회귀 차단: beta.4 의 `app.update.targets` 기본값에 `lang-packs/_bundled` 추가됨 +
     * `applyUpdate` 가 신규 최상위 디렉토리 자동 발견 폴백을 수행하므로 beta.4→beta.5 이후로는
     * 본 메서드의 사후 보정이 트리거될 일이 없다 (멱등 무해).
     */
    private function recoverLangPacksBundled(UpgradeContext $context): void
    {
        // 1) _bundled 트리 복구 (기존 흐름)
        $activeBundled = base_path('lang-packs/_bundled');
        if (File::isDirectory($activeBundled) && count(File::directories($activeBundled)) > 0) {
            $context->logger->info('[beta.4] lang-packs/_bundled 정상 — 보정 스킵');
        } else {
            $context->logger->warning('[beta.4] lang-packs/_bundled 누락 감지 — _pending 에서 복구 시도');

            $pendingSource = $this->locatePendingLangPacksSource($context);
            if ($pendingSource === null) {
                $context->logger->warning(
                    '[beta.4] _pending 의 lang-packs/_bundled 소스 미발견 — 수동 복구 필요. '
                    .'`php artisan core:update --force` 재실행 (beta.4 의 applyUpdate 가 자동 발견 폴백으로 복사).'
                );
            } else {
                try {
                    File::ensureDirectoryExists($activeBundled);
                    FilePermissionHelper::copyDirectory($pendingSource, $activeBundled);
                    $count = count(File::directories($activeBundled));
                    $context->logger->info("[beta.4] lang-packs/_bundled 복구 완료 — {$count} 개 패키지 디렉토리 복원 ({$pendingSource} → {$activeBundled})");
                } catch (\Throwable $e) {
                    $context->logger->warning('[beta.4] lang-packs/_bundled 복구 실패 — '.$e->getMessage());
                }
            }
        }

        // 2) _pending 디렉토리 + .gitkeep / .gitignore 복구
        // beta.3 의 stale targets 가 lang-packs/ 전체를 인식하지 못해 _pending 도 누락.
        // beta.4 의 _pending 은 빈 디렉토리지만 .gitkeep / .gitignore 만 있으면 됨 (런타임 산출물 격리용).
        // 이 디렉토리는 LanguagePackService::assertInstallDirectoriesWritable 가 lazy 생성하기는 하나,
        // lang-packs 권한 정상화 후 즉시 가용해야 다음 단계 (ensureLangPacksPermissions, 추후 운영자 언어팩 설치) 가
        // 일관된 권한 환경에서 동작. 따라서 본 step 에서 명시 보정.
        $activePending = base_path('lang-packs/_pending');
        $context->logger->info('[beta.4] lang-packs/_pending 보정 시작');

        if (! File::isDirectory($activePending)) {
            $context->logger->warning('[beta.4] lang-packs/_pending 누락 감지 — 디렉토리 생성 + .gitkeep/.gitignore 복원');

            // 디렉토리 생성
            try {
                File::ensureDirectoryExists($activePending);
            } catch (\Throwable $e) {
                $context->logger->warning('[beta.4] lang-packs/_pending mkdir 실패 — '.$e->getMessage());

                return;
            }

            // _pending source 가 _bundled 와 동일 위치 (_bundled 와 형제) 라 동일 탐색 로직 재활용.
            // locatePendingLangPacksSource 는 lang-packs/_bundled 소스를 반환하므로 부모(lang-packs/) 로
            // 변환하여 _pending 자식을 찾는다.
            $bundledSource = $this->locatePendingLangPacksSource($context);
            if ($bundledSource !== null) {
                $sourceLangPacks = dirname($bundledSource); // .../lang-packs
                $sourcePending = $sourceLangPacks.DIRECTORY_SEPARATOR.'_pending';
                if (File::isDirectory($sourcePending)) {
                    try {
                        FilePermissionHelper::copyDirectory($sourcePending, $activePending);
                        $context->logger->info("[beta.4] lang-packs/_pending 복구 완료 — {$sourcePending} → {$activePending}");
                    } catch (\Throwable $e) {
                        $context->logger->warning('[beta.4] lang-packs/_pending copy 실패 — '.$e->getMessage());
                    }
                } else {
                    // source 의 _pending 도 부재 — .gitkeep / .gitignore 만 직접 생성
                    @file_put_contents($activePending.DIRECTORY_SEPARATOR.'.gitkeep', '');
                    @file_put_contents($activePending.DIRECTORY_SEPARATOR.'.gitignore', "*\n!.gitignore\n!.gitkeep\n");
                    $context->logger->info('[beta.4] lang-packs/_pending source 부재 — 빈 디렉토리 + .gitkeep/.gitignore 직접 생성');
                }
            } else {
                @file_put_contents($activePending.DIRECTORY_SEPARATOR.'.gitkeep', '');
                @file_put_contents($activePending.DIRECTORY_SEPARATOR.'.gitignore', "*\n!.gitignore\n!.gitkeep\n");
                $context->logger->info('[beta.4] lang-packs source root 부재 — _pending 만 직접 생성');
            }
        } else {
            $context->logger->info('[beta.4] lang-packs/_pending 정상 — 보정 스킵');
        }
    }

    /**
     * `_pending` 디렉토리에서 `lang-packs/_bundled` 소스를 탐색해 첫 매치를 반환합니다.
     *
     * 실제 _pending 구조 (CoreUpdateService::createPendingDirectory + extractZipToPending):
     *
     *     {pending_path}/                       ← config('app.update.pending_path')
     *       core_{Ymd_His}/                     ← 타임스탬프 격리 디렉토리 (중복 실행 방지)
     *         extracted/
     *           {wrapper}/                      ← ZIP 안의 최상위 디렉토리 (releases/repo-{tag}/ 등)
     *             lang-packs/_bundled/         ← 실제 소스 위치
     *
     * GitHub release / `--zip=` / `--source=` 모드 모두 위 4단계 깊이를 거치므로
     * 직속(legacy) + 1단계(부 경우) + 2단계(대부분) + 3단계(예외) 까지 모두 탐색한다.
     * 가장 최근 타임스탬프 디렉토리 우선.
     *
     * @return string|null 발견된 절대 경로, 미발견 시 null
     */
    private function locatePendingLangPacksSource(?UpgradeContext $context = null): ?string
    {
        $pendingPath = config('app.update.pending_path');
        if (! is_string($pendingPath) || $pendingPath === '' || ! File::isDirectory($pendingPath)) {
            return null;
        }

        $candidates = [];

        // legacy: <base>/(extracted/)?lang-packs/_bundled — 구버전 흐름 호환
        $candidates[] = $pendingPath.DIRECTORY_SEPARATOR.'lang-packs'.DIRECTORY_SEPARATOR.'_bundled';
        $candidates[] = $pendingPath.DIRECTORY_SEPARATOR.'extracted'.DIRECTORY_SEPARATOR.'lang-packs'.DIRECTORY_SEPARATOR.'_bundled';

        // 1depth: <base>/<dir>/lang-packs/_bundled (예: <base>/extracted/<wrapper>/...)
        // 2depth: <base>/<dir>/<sub>/lang-packs/_bundled (예: <base>/core_<TS>/extracted/...)
        // 3depth: <base>/core_<TS>/extracted/<wrapper>/lang-packs/_bundled — 표준 경로
        $level1 = $this->sortDirectoriesNewestFirst(File::directories($pendingPath));
        foreach ($level1 as $l1) {
            $candidates[] = $l1.DIRECTORY_SEPARATOR.'lang-packs'.DIRECTORY_SEPARATOR.'_bundled';

            $level2 = $this->sortDirectoriesNewestFirst(File::directories($l1));
            foreach ($level2 as $l2) {
                $candidates[] = $l2.DIRECTORY_SEPARATOR.'lang-packs'.DIRECTORY_SEPARATOR.'_bundled';

                $level3 = $this->sortDirectoriesNewestFirst(File::directories($l2));
                foreach ($level3 as $l3) {
                    $candidates[] = $l3.DIRECTORY_SEPARATOR.'lang-packs'.DIRECTORY_SEPARATOR.'_bundled';
                }
            }
        }

        $tried = [];
        foreach ($candidates as $candidate) {
            $tried[] = $candidate;
            if (File::isDirectory($candidate) && count(File::directories($candidate)) > 0) {
                $context?->logger->info('[beta.4] lang-packs 소스 발견: '.$candidate);

                return $candidate;
            }
        }

        // 진단 로깅 — PO/운영자가 _pending 실제 구조를 확인할 수 있도록
        $context?->logger->warning('[beta.4] lang-packs 소스 미발견. 검색한 경로 ('.count($tried).'개): '.implode(' | ', array_slice($tried, 0, 10)).(count($tried) > 10 ? ' …' : ''));

        return null;
    }

    /**
     * 디렉토리 배열을 가장 최근 디렉토리 우선으로 정렬합니다.
     *
     * 1차 정렬: basename 사전 역순 (코어 업데이트 _pending 디렉토리는 `core_<Ymd_His>`
     * 패턴이라 사전 역순 = 시간 역순으로 일치 + 일부 파일시스템에서 mtime 신뢰성이
     * 낮은 환경에서도 안정적).
     * 동일 basename 일 때만 mtime 으로 보조 정렬.
     *
     * @param  array<int, string>  $dirs
     * @return array<int, string>
     */
    private function sortDirectoriesNewestFirst(array $dirs): array
    {
        usort($dirs, function (string $a, string $b): int {
            $cmp = strcmp(basename($b), basename($a));
            if ($cmp !== 0) {
                return $cmp;
            }

            return (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0);
        });

        return $dirs;
    }

    /**
     * fresh config 기반 코어 데이터 재동기화 — beta.3 출시본 stale 부모 sync 결함 사후 보정.
     *
     * beta.3 의 CoreUpdateCommand Step 9 는 부모 프로세스에서 syncCoreRolesAndPermissions /
     * syncCoreMenus 를 직접 호출하나, 그 시점 부모 메모리는 부팅 당시의 beta.3 config 를
     * 보유하고 있어 beta.4 가 도입한 신규 권한·메뉴가 누락된다.
     *
     * 본 step 은 spawn 자식(Path B) 에서 실행되어 자기 메모리에 fresh beta.4 config 를
     * 로드한 상태이므로, `CoreUpdateService::reloadCoreConfigAndResync()` 1 회 호출로
     * 권한·메뉴·알림 정의·IDV 정책·IDV 메시지 정의를 일괄 upsert. 모든 도메인이 멱등이라
     * 정상 환경 재실행 무해.
     *
     * beta.4 의 CoreUpdateCommand 는 Step 9 자체가 reloadCoreConfigAndResync 로 교체되어
     * 향후 업그레이드(beta.4 → beta.5+) 에서 본 호출은 멱등 재실행이 된다. 즉 beta.3 →
     * beta.4 트랜지션 한정 의미를 가지지만, 멱등성으로 향후 무해.
     */
    private function resyncCoreDataWithFreshConfig(UpgradeContext $context): void
    {
        $context->logger->info('[beta.4] fresh config 기반 코어 데이터 재동기화 (권한·메뉴·알림·IDV)');

        try {
            app(CoreUpdateService::class)->reloadCoreConfigAndResync();
            $context->logger->info('[beta.4] 코어 데이터 재동기화 완료');
        } catch (\Throwable $e) {
            $context->logger->warning('[beta.4] 코어 데이터 재동기화 실패 — 수동 복구 필요: '.$e->getMessage());
        }
    }

    /**
     * user_overrides 인프라가 컬럼 단위에서 sub-key dot-path 단위로 확장됨에 따라,
     * 기존 row 의 user_overrides 항목 중 다국어 JSON 컬럼명(`'name'`/`'description'`) 을
     * 활성 locale 별 dot-path 항목(`'name.ko', 'name.en', 'name.ja'`) 으로 일괄 변환한다.
     *
     * 변환 규칙:
     *   - user_overrides=null/[] : 변환 없음
     *   - 항목이 이미 dot-path 포함 ('.' 존재) : 그대로 유지 (idempotent)
     *   - 항목이 다국어 컬럼명과 일치 : 활성 locale 별 dot-path 로 확장
     *   - 항목이 scalar 컬럼/외부 식별자 : 그대로 유지
     */
    private function migrateUserOverridesToDotPath(UpgradeContext $context): void
    {
        $context->logger->info('[beta.4] user_overrides dot-path sub-key 마이그레이션 시작');

        $locales = $this->resolveSupportedLocales();
        $context->logger->info('[beta.4] 활성 locale: '.implode(', ', $locales));

        foreach (self::TRANSLATABLE_COLUMNS as $table => $translatableColumns) {
            $this->migrateTableUserOverrides($context, $table, $translatableColumns, $locales);
        }
    }

    /**
     * 활성 supported_locales 를 반환합니다.
     *
     * @return array<int, string>
     */
    private function resolveSupportedLocales(): array
    {
        $locales = config('app.supported_locales', ['ko', 'en']);
        if (! is_array($locales) || empty($locales)) {
            return ['ko', 'en'];
        }

        return array_values(array_filter($locales, 'is_string'));
    }

    /**
     * 단일 테이블의 user_overrides 컬럼을 dot-path 로 변환합니다.
     *
     * @param  array<int, string>  $translatableColumns
     * @param  array<int, string>  $locales
     */
    private function migrateTableUserOverrides(UpgradeContext $context, string $table, array $translatableColumns, array $locales): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_overrides')) {
            $context->logger->warning("[beta.4] {$table}.user_overrides 미존재 — 스킵");

            return;
        }

        $rows = DB::table($table)
            ->whereNotNull('user_overrides')
            ->where('user_overrides', '!=', '')
            ->where('user_overrides', '!=', '[]')
            ->where('user_overrides', '!=', 'null')
            ->get(['id', 'user_overrides']);

        $converted = 0;
        foreach ($rows as $row) {
            $existing = json_decode((string) $row->user_overrides, true);
            if (! is_array($existing) || empty($existing)) {
                continue;
            }
            $migrated = $this->expandColumnNamesToDotPaths($existing, $translatableColumns, $locales);
            if ($migrated === $existing) {
                continue;
            }
            DB::table($table)->where('id', $row->id)->update([
                'user_overrides' => json_encode(array_values(array_unique($migrated))),
            ]);
            $converted++;
        }

        $context->logger->info("[beta.4] {$table}: {$converted} 건 변환");
    }

    /**
     * 기존 user_overrides 배열의 컬럼명 항목을 활성 locale dot-path 로 확장합니다.
     *
     * @param  array<int, string>  $existing
     * @param  array<int, string>  $translatableColumns
     * @param  array<int, string>  $locales
     * @return array<int, string>
     */
    private function expandColumnNamesToDotPaths(array $existing, array $translatableColumns, array $locales): array
    {
        $result = [];
        foreach ($existing as $entry) {
            if (! is_string($entry) || $entry === '') {
                continue;
            }
            if (str_contains($entry, '.')) {
                $result[] = $entry;

                continue;
            }
            if (in_array($entry, $translatableColumns, true)) {
                foreach ($locales as $locale) {
                    $result[] = "{$entry}.{$locale}";
                }

                continue;
            }
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * beta3 → beta4+ 한정 — 부모(beta3) 의 결함 있는 restoreOwnership 으로 storage/app 의
     * 시드 시점 owner 가 손실되어 PHP-FPM access 가 막히는 회귀 차단.
     *
     * 결함 배경:
     *   beta3 의 `CoreUpdateService::restoreOwnership` 은 storage 트리 전체를 루트의
     *   owner/group 으로 일괄 chown 한다. storage/app/{modules,plugins,attachments,...}
     *   의 시드 시점 owner (PHP-FPM 사용자) 가 storage 루트 owner (운영자 사용자) 로 변경되어
     *   PHP-FPM 이 owner 도 group 도 아닌 0700 디렉토리에 traversal/read 불가 → 404.
     *   부모는 메모리에 beta3 코드를 가지고 있어 어떤 코드 변경으로도 차단 불가.
     *
     * 우회 메커니즘:
     *   spawn 자식(beta4 코드, sudo update 컨텍스트 = root) 이 storage/app 사용자 데이터
     *   트리에 직접 chmod 0755 (디렉토리) / 0644 (파일) 적용. owner 무변경 (시드 시점 그대로).
     *
     * 부모 Step 11 결과:
     *   - chownRecursive: storage 통째 → owner=example
     *   - syncGroupWritability: g+w OR (0755 → 0775, 0644 → 0664)
     *   - 최종: example:www-data 0775 (drwxrwxr-x) → PHP-FPM other 비트 r-x → access OK
     *
     * 자가 무력화:
     *   부모가 fix 된 코드 (snapshotOwnershipDetailed 메서드 보유 + storage/app 비-chown
     *   대상) 인 경우 부모는 storage/app 을 건드리지 않음 → chmod 정상화도 멱등 무해.
     *   미래 transition 에서는 트랙 2-A 의 마커 + chownRecursive 가드가 영구 차단.
     *
     * 한계 (운영 정합):
     *   - 시드 시점 perms (예: 0700 private visibility) 가 0755 로 변경 — 100% 원본 보존
     *     아님. 정책 결정으로 자동화 우선 (수동 sudo 명령 부재).
     *   - 즉시 PHP-FPM access 보장이 본 fix 의 책임.
     */
    private function normalizeStorageAppPermissionsForLegacyParent(UpgradeContext $context): void
    {
        // 가드 1: chmod 미지원 환경 (Windows) — skip
        if (! function_exists('chmod')) {
            $context->logger->info('[beta.4] chmod 미지원 환경 — storage/app 권한 정상화 skip');

            return;
        }

        // 보정 대상: storage/app 의 사용자 데이터 영역
        $candidatePaths = [
            'storage/app/modules',
            'storage/app/plugins',
            'storage/app/attachments',
            'storage/app/public',
            'storage/app/settings',
        ];

        $existingPaths = array_filter($candidatePaths, fn (string $rel) => File::isDirectory(base_path($rel)));

        if (empty($existingPaths)) {
            $context->logger->info('[beta.4] storage/app 사용자 데이터 디렉토리 부재 — 권한 정상화 skip');

            return;
        }

        $totalDirs = 0;
        $totalFiles = 0;
        $totalFailed = 0;
        $firstFailedPath = null;
        $failedByPath = [];

        foreach ($existingPaths as $rel) {
            $absolute = base_path($rel);
            $stats = $this->normalizeTreePermissions($absolute);
            $totalDirs += $stats['dirs_chmoded'];
            $totalFiles += $stats['files_chmoded'];
            $totalFailed += $stats['failed'];
            if ($firstFailedPath === null && ! empty($stats['failed_paths'])) {
                $firstFailedPath = $stats['failed_paths'][0];
            }
            if ($stats['failed'] > 0) {
                $failedByPath[$rel] = $stats['failed'];
            }
        }

        // 정상 운영 시 종합 로그 1건만 출력. 실패 발생 시에만 경로별 상세 + warning.
        $context->logger->info(sprintf(
            '[beta.4] storage/app 권한 정상화 — 디렉토리 %d 건, 파일 %d 건 (실패 %d 건)',
            $totalDirs,
            $totalFiles,
            $totalFailed,
        ));

        if ($totalFailed > 0) {
            $context->logger->warning(sprintf(
                '[beta.4] storage/app 권한 정상화 부분 실패 — %d 건 (첫 실패: %s, 경로별: %s). '
                .'sudo 환경 결함(파일시스템 ACL · NFS 권한 거부 등) 가능성. 수동 chmod 필요할 수 있음.',
                $totalFailed,
                $firstFailedPath ?? '?',
                json_encode($failedByPath, JSON_UNESCAPED_UNICODE),
            ));
        }
    }

    /**
     * 트리를 재귀 순회하여 디렉토리는 0755, 파일은 0644 로 chmod.
     *
     * - symbolic link 는 무시 (target 추적 안 함)
     * - silent fail (silent fail 카운트)
     *
     * @return array{dirs_chmoded:int, files_chmoded:int, failed:int, failed_paths:array<int,string>}
     */
    private function normalizeTreePermissions(string $root): array
    {
        $stats = ['dirs_chmoded' => 0, 'files_chmoded' => 0, 'failed' => 0, 'failed_paths' => []];

        if (! is_dir($root)) {
            return $stats;
        }

        // 루트 자체
        if (@chmod($root, 0755)) {
            $stats['dirs_chmoded']++;
        } else {
            $stats['failed']++;
            $stats['failed_paths'][] = $root;
        }

        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
        } catch (\Throwable $e) {
            $stats['failed']++;
            $stats['failed_paths'][] = $root.' (iterator: '.$e->getMessage().')';

            return $stats;
        }

        foreach ($items as $item) {
            $path = $item->getPathname();
            if (is_link($path)) {
                continue; // symlink 는 perms 무관
            }

            $targetPerms = $item->isDir() ? 0755 : 0644;
            if (@chmod($path, $targetPerms)) {
                if ($item->isDir()) {
                    $stats['dirs_chmoded']++;
                } else {
                    $stats['files_chmoded']++;
                }
            } else {
                $stats['failed']++;
                if (count($stats['failed_paths']) < 50) {
                    $stats['failed_paths'][] = $path;
                }
            }
        }

        return $stats;
    }

    /**
     * 기존 환경의 모듈/플러그인 storage 디렉토리에 `.preserve-ownership` 마커 파일을 작성.
     *
     * 트랙 2-A — `ModuleStorageDriver` 의 자동 마커 작성은 신규 디렉토리 생성 시점에만
     * 트리거되므로, 본 step 실행 시점에 이미 존재하는 storage/app/{modules,plugins}/{id}/
     * 디렉토리에는 마커가 부재할 수 있다. 본 메서드가 1회성으로 보정.
     *
     * 마커가 작성되면 미래 코어 update 의 `chownRecursiveDetailed::respectPreservationMarker`
     * 가 그 서브트리 chown 을 자동 skip → 시드 시점 owner/perms 영구 보존.
     *
     * 멱등: 마커가 이미 있으면 덮어쓰지 않음.
     */
    private function writePreservationMarkersForExistingExtensionStorage(UpgradeContext $context): void
    {
        $candidateRoots = [
            'storage/app/modules',
            'storage/app/plugins',
        ];

        $written = 0;
        $skipped = 0;

        foreach ($candidateRoots as $rel) {
            $absolute = base_path($rel);
            if (! File::isDirectory($absolute)) {
                continue;
            }

            foreach (File::directories($absolute) as $extensionDir) {
                $markerPath = $extensionDir.DIRECTORY_SEPARATOR.'.preserve-ownership';
                if (File::exists($markerPath)) {
                    $skipped++;
                    continue;
                }

                try {
                    File::put(
                        $markerPath,
                        "# G7 preservation marker\n# 코어 update 의 chownRecursive 가 본 디렉토리 트리를 자동 skip 합니다.\n"
                        ."# ModuleStorageDriver/PluginStorageDriver 가 자동 작성하지만, beta.4 업그레이드 1회성으로 보정.\n"
                    );
                    $written++;
                } catch (\Throwable $e) {
                    $context->logger->warning(sprintf(
                        '[beta.4] preservation marker 작성 실패 — %s: %s',
                        $extensionDir,
                        $e->getMessage(),
                    ));
                }
            }
        }

        $context->logger->info(sprintf(
            '[beta.4] preservation marker 보정 완료 — 신규 작성 %d 건, 기존 보존 %d 건',
            $written,
            $skipped,
        ));
    }

    /**
     * IDV 정책/메시지 관련 캐시를 무효화합니다.
     *
     * 재시딩(reloadCoreConfigAndResync) 결과가 관리자 UI 에 즉시 반영되도록 fresh seed 후
     * 캐시 키 4종을 forget. 본 메서드는 resyncCoreDataWithFreshConfig 이후에 호출되어야 한다.
     */
    private function invalidateCaches(UpgradeContext $context): void
    {
        $keys = [
            'identity_policies:resolved',
            'identity_policies:active',
            'identity_message_definitions:active',
            'identity_message_templates:resolved',
        ];

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        $context->logger->info('[beta.4] IDV 캐시 무효화 완료');
    }
}
