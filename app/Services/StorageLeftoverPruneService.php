<?php

namespace App\Services;

use App\Extension\ExtensionManager;
use App\Extension\Helpers\AbstractRootMarkerFileUpdater;
use App\Extension\Helpers\BackupDirectoryName;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\Helpers\RootHtaccessUpdater;
use App\Extension\Helpers\RootWebConfigUpdater;
use App\Extension\Helpers\StorageLinkHelper;
use App\Extension\Support\CarriedClassFileLedger;
use App\Support\ExecutionEnvironment;
use Illuminate\Support\Facades\File;

/**
 * 스토리지 잔존물 정리 서비스 (`storage:prune-leftovers`)
 *
 * 확장/코어 업데이트·설치가 중단되며 남긴 임시 산출물과 오래된 백업본을 회수합니다.
 * 각 산출물의 생성 지점은 best-effort 정리(finally)만 가지고 있어, 강제 종료·파일
 * 잠금 환경에서 남은 잔존물은 어떤 경로도 회수하지 않았습니다 (공개이슈 #120 동형 —
 * 산출물 누적 + 회수 경로 부재). `CleanupExtensionBundlesCommand` →
 * `ExtensionBundleService` 위임 패턴을 미러합니다.
 *
 * 파괴 방지 가드:
 *  - `_pending` 직하위는 타임스탬프/교체 마커 패턴 매칭 디렉토리만 대상 —
 *    다운로드 대기 정상 확장 디렉토리(접미사 없음)는 불가침.
 *  - 백업은 identifier 별 최신 1개를 나이와 무관하게 상시 보존 (롤백 능력 유지).
 *  - 판정은 전부 mtime 기준 — 진행 중 작업의 산출물은 신선해서 걸리지 않는다.
 */
class StorageLeftoverPruneService
{
    /**
     * 업데이트 스테이징 타임스탬프 접미사 (`ExtensionPendingHelper::createUpdateStagingPath`
     * 의 `{identifier}_{Ymd_His}` 형식).
     */
    private const STAGING_TIMESTAMP_PATTERN = '/_\d{8}_\d{6}$/';

    /**
     * 디렉토리 교체 임시 마커 (`{identifier}_updating_{uniqid}` / `{identifier}_old_{uniqid}`).
     * `cleanupSwapLeftovers()` 는 다음 업데이트 시점에만 재시도하므로, 업데이트가 다시
     * 실행되지 않는 확장의 잔존물은 여기서만 회수된다.
     */
    private const SWAP_LEFTOVER_PATTERN = '/_(?:updating|old)_[0-9a-f]{13}$/';

    /**
     * 이름 판정은 **만드는 쪽과 같은 곳**(`BackupDirectoryName`)에서 가져온다.
     *
     * 리터럴로 다시 적으면 생산자가 이름 모양을 바꿀 때 이 쪽이 따라가지 못하고, 그
     * 상태는 오류가 아니라 **모집단이 조용히 비는 것**으로만 나타난다 — 실제로 코어
     * 백업이 그 상태였다. 그 사유는 `BackupDirectoryName` 독블록이 소유한다.
     */
    private const BACKUP_NAME_PATTERN = '/^(.+)_('.BackupDirectoryName::STAMP.')$/';

    private const CORE_BACKUP_NAME_PATTERN = '/^core_('.BackupDirectoryName::STAMP.')$/';

    /** @var string 교체 직전 루트 진입 파일 사본 디렉토리 이름 (`CoreUpdateService` 가 `BackupDirectoryName::stamp()` 로 만든다 — 접미사 없는 구형 이름도 인식) */
    private const CORE_UPDATE_PRESERVED_NAME_PATTERN = '/^'.BackupDirectoryName::STAMP.'$/';

    /**
     * 손상된 공개 스토리지 링크 백업 디렉토리 이름 패턴.
     *
     * 생산자(`StorageLinkHelper`)가 `public-storage-broken-<YmdHis>-<hex8>` 으로 만든다.
     * 형제 백업군과 같은 규약 — **이름 패턴을 통과한 것만** 집합에 넣어야 형식이 다른
     * 잔해가 「최신 1개」 자리를 영구히 차지하지 않는다.
     */
    /**
     * 인스톨러 점검 잔여물의 이름 접두사 — **생산자와 같은 값**이어야 한다.
     *
     * 생산자는 `public/install/includes/exposure-check.php` 의
     * `INSTALLER_EXPOSURE_PROBE_PREFIX` · `INSTALLER_SYMLINK_PROBE_PREFIX` 상수다.
     * 인스톨러는 Laravel 부팅 전 코드라 여기서 그 상수를 직접 참조할 수 없어 값이
     * 두 곳에 존재한다 — 그 일치는 계약 테스트가 잠근다(`StorageLeftoverPruneServiceTest`).
     *
     * 같은 파일이 40행 위에서 이 실패 모드를 이름 붙여 금지하고 있다: 이름 판정을
     * 리터럴로 다시 적으면 그 상태는 오류가 아니라 **모집단이 조용히 비는 것**으로만
     * 나타난다.
     */
    private const INSTALLER_PROBE_PREFIXES = ['exposure-probe-', 'symlink-probe-'];

    /**
     * 쓰기 가능 실측 프로브 이름 접두사 — 생산자는 `functions.php` 의 `INSTALLER_WRITE_PROBE_PREFIX`.
     *
     * 형제 두 접두사와 달리 **점 파일**이라 `listDirectoryFiles()` 통로로는 영원히 0건이다
     * (그 헬퍼는 점으로 시작하는 항목을 전부 건너뛴다) — 그래서 별도 스캔이 필요했고 종전에는
     * 그 스캔이 없어 어느 정리 주체의 모집단에도 없었다.
     */
    private const INSTALLER_WRITE_PROBE_PREFIX = '.installer-write-probe-';

    /**
     * 쓰기 프로브가 생길 수 있는 디렉토리 (프로젝트 루트 기준) — 인스톨러 `REQUIRED_DIRECTORIES` 와
     * 같은 목록이어야 하며 그 일치는 계약 테스트가 잠근다. `storage` 는 인스톨러가 두 단계 아래까지
     * 재귀 점검하므로 그 깊이까지 함께 훑는다.
     */
    private const INSTALLER_WRITE_PROBE_ROOTS = [
        'storage',
        'bootstrap/cache',
        'vendor',
        'modules',
        'modules/_pending',
        'plugins',
        'plugins/_pending',
        'templates',
        'templates/_pending',
        // 초기 화면 정적 파일 게시 루트(`public/build/ext`)의 상위 — 인스톨러가 권한을 점검하는
        // 대상이라 그 자리에도 쓰기 프로브가 남는다 (#651 이 인스톨러 목록에 들인 뒤 합류).
        'public/build',
        'lang-packs',
        'lang-packs/_pending',
        'storage/app/core_pending',
    ];

    /** 인스톨러가 `storage` 아래를 재귀 점검하는 깊이 (`checkSubdirectoriesRecursive` 의 `$maxDepth` + 1). */
    private const INSTALLER_WRITE_PROBE_STORAGE_DEPTH = 2;

    /**
     * 파손 상태 사본 이름 규칙 — 생산자 `installer-state.php` 의 `INSTALLER_STATE_CORRUPT_*` 와
     * 같은 값이어야 한다(인스톨러는 코어 상수를 참조할 수 없어 값이 두 곳에 있고, 그 일치는
     * 계약 테스트가 잠근다). 생산자는 상한 5 로 만들기만 하고 지우는 지점이 0 이었다.
     */
    private const INSTALLER_STATE_CORRUPT_INFIX = '.corrupt.';

    private const INSTALLER_STATE_CORRUPT_SUFFIX_PATTERN = '/^(\d{14})\.[0-9a-f]{8}$/';

    /**
     * 원자 교체 임시 파일이 생기는 루트 진입·보호 파일 → **그 파일을 갱신하는 클래스** 매핑.
     *
     * 이름 규칙(`TEMP_INFIX`·`TEMP_SUFFIX_PATTERN`)과 링크 대상 도출(`resolveWriteTarget`)을
     * **각 대상의 갱신기 클래스에서** 읽는다. 종전에는 세 대상 전부를
     * `RootHtaccessUpdater` 상수로 판정했는데, `web.config` 의 생산자는 `RootWebConfigUpdater` 다 —
     * 지금은 두 클래스가 그 규칙을 공통 추상화에서 상속해 값이 같지만, web.config 갱신기가 그 규칙을
     * 재정의하는 순간 프루너가 잘못된 규칙으로 그 잔해를 판정하게 된다(모집단이 조용히 어긋난다).
     *
     * 사실 관계: 임시 파일을 만드는 것은 마커 블록 갱신기 두 개(`.htaccess`·`web.config` —
     * 공통 추상화 `AbstractRootMarkerFileUpdater::writeAtomically()`)다. `index.php` 는 코어
     * 업데이트가 복사로 다루므로 이 스캔에서는 0건이다 — 전용 갱신기가 없어 임시 파일 이름의 SSoT 인
     * 추상화 자신에 매핑한다. 지우지 않고 남겨 두는 이유는, 그 파일이 나중에 같은 원자 교체 경로로
     * 옮겨질 때 이 목록에서 빠져 있으면 그때 생기는 잔해가 다시 무주체가 되기 때문이다
     * (G3 회차 16 W2 C-1 에서 `web.config` 가 실제로 그 경로에 합류했다).
     *
     * @var array<string, class-string<AbstractRootMarkerFileUpdater>>
     */
    private const ROOT_ENTRY_TEMP_TARGETS = [
        '.htaccess' => RootHtaccessUpdater::class,
        'web.config' => RootWebConfigUpdater::class,
        'index.php' => AbstractRootMarkerFileUpdater::class,
    ];

    /** 인스톨러 노출 점검 레이트리밋 상태 파일 (`storage/` 직하 — 생산자와 같은 경로). */
    private const INSTALLER_PROBE_RATE_FILE = 'installer-probe-rate.json';

    /**
     * 설치 진행 상태 파일 (`storage/` 직하 — 생산자 `installer-state.php` 의 `STATE_PATH`).
     */
    private const INSTALLER_STATE_FILE = 'installer-state.json';

    /**
     * 상태 파일 배타 구간의 잠금 파일 접미사 — 생산자 `installer-state.php` 의
     * `INSTALLER_STATE_LOCK_SUFFIX` 와 같다(계약 테스트가 대조). `installerWithStateLock` 이
     * `fopen(…, 'c')` 로 만들고 finalize 가 상태 파일과 함께 지우는데, 설치를 포기한 사이트에서는
     * finalize 가 돌지 않아 상태 파일과 같은 이유로 영구 잔존했다.
     */
    private const INSTALLER_STATE_LOCK_SUFFIX = '.lock';

    /**
     * 설치 런타임 설정 파일 (생산자 `installer-runtime.php` 의 `INSTALLER_RUNTIME_PATH`).
     *
     * **DB 접속 정보와 APP_KEY 를 담는다**(0600). 배포 레이아웃 ②(문서 루트 = 프로젝트
     * 루트)에서는 `storage/` 가 문서 루트 안이라, 이 파일의 누적은 저장 공간이 아니라
     * **노출** 문제다 — 같은 판단을 생산자 파일이 형제 tmp 스윕 주석에 이미 적어 두었다.
     */
    private const INSTALLER_RUNTIME_FILE = 'installer'.DIRECTORY_SEPARATOR.'runtime.php';

    /**
     * finalize 가 남기는 `.env` 원본 보존 사본의 접두사 (생산자 `installer-runtime.php`
     * 의 `backupExistingEnvFile()`).
     *
     * **`.env` 전문**(DB 자격증명 · APP_KEY · 서드파티 키)을 담는 프로젝트 루트 직하 파일이고,
     * finalize 를 다시 돌 때마다 한 벌씩 늘어나는데 **정리 주체가 없었다** — 저장소 전체에서
     * 이 이름을 지우는 지점이 0 이었다. 백업 자산이므로 임시 등급(3일)이 아니라 **백업
     * 등급**(30일 + 최신 1개 보존)으로 걷는다: 운영자가 되찾을 값을 담고 있어 공격적으로
     * 지우면 그 안전망 자체가 사라진다.
     */
    private const ENV_BACKUP_PREFIX = '.env.bak.';

    /** `.env` 보존 사본 이름 판정 — `.bak.<Ymd_His>` + 같은 초 충돌 회피 접미사 `_N`. */
    private const ENV_BACKUP_NAME_PATTERN = '/^\.env\.bak\.(\d{8}_\d{6})(?:_\d+)?$/';

    /** 인스톨러 명령 출력 임시 로그 이름 접두사 (생산자 `task-runner.php`). */
    private const INSTALLER_CMD_OUTPUT_PREFIX = 'installer-cmd-output-';

    /** 인스톨러 명령 출력 임시 로그가 놓이는 디렉토리 (`storage/` 기준, 생산자 후보 순서). */
    private const INSTALLER_CMD_OUTPUT_DIRS = ['logs', 'installer'];

    /**
     * 설치가 **아직 끝나지 않은** 사이트에서 설치 잔여물을 회수하기까지 요구하는 나이(일).
     *
     * 이 셋(상태 파일 · 런타임 설정 · 명령 출력 로그)은 설치를 재개하면 다시 읽히는
     * 파일이다. 임시 산출물 보존일(기본 3일)로 걷으면 며칠 뒤 재개하려는 운영자의 설치가
     * 처음으로 되돌아간다 — 그래서 「설치 미완」이면 훨씬 긴 나이를 요구한다.
     * 설치가 끝난 사이트에서는 순수 잔여물이므로 임시 등급 그대로 걷는다.
     */
    private const INSTALLER_RESIDUE_DAYS = 30;

    /**
     * 설치 완료 마커 (`storage/` 기준 상대 경로 — `RouteCacheHelper::isInstalled()` 와 같은 값).
     */
    private const INSTALLED_MARKER_FILE = 'app'.DIRECTORY_SEPARATOR.'g7_installed';

    /**
     * `.g7stale_` 잔해를 훑을 확장 루트 (프로젝트 루트 기준).
     *
     * `_bundled`·`_pending` 도 포함한다 — 제자리 동기화의 대상이 활성 디렉토리라도, 그
     * 잔해는 어느 트리에서든 같은 규칙으로 생긴다.
     */
    private const EXTENSION_ROOTS = ['modules', 'plugins', 'templates'];

    /**
     * `.g7stale_` 스캔에서 들어가지 않는 디렉토리 이름.
     *
     * 이 잔해는 제자리 파일 동기화가 만들므로 확장 소스 트리 어디에나 생길 수 있어 재귀
     * 스캔이 필요한데, 개발 산출물 트리까지 들어가면 비용이 실행 시간을 지배한다.
     * 코어의 동기화는 이 두 디렉토리를 대상으로 삼지 않는다.
     */
    private const EXTENSION_SCAN_SKIP_DIRS = ['node_modules', '.git'];

    /**
     * @param  string|null  $baseRoot  프로젝트 루트 재정의 (테스트 격리용, 기본 base_path())
     * @param  string|null  $storageRoot  스토리지 루트 재정의 (테스트 격리용, 기본 storage_path())
     * @param  string|null  $publicRoot  웹 트리 루트 재정의 (테스트 격리용, 기본 public_path())
     */
    public function __construct(
        private readonly ?string $baseRoot = null,
        private readonly ?string $storageRoot = null,
        private readonly ?string $publicRoot = null,
    ) {}

    /**
     * 잔존물을 대상군별로 정리하고 삭제(예정) 경로 목록을 돌려줍니다.
     *
     * @param  int  $days  임시 산출물 보존일 (mtime 기준, 경과분 삭제)
     * @param  int  $backupDays  백업 보존일 (최신 1개는 나이와 무관하게 보존)
     * @param  bool  $dryRun  true 면 삭제 없이 대상 목록만 수집
     * @return array<string, list<string>> 대상군별 삭제(예정) 경로 목록
     */
    public function prune(int $days = 3, int $backupDays = 30, bool $dryRun = false): array
    {
        $now = time();
        $cutoff = $now - $days * 86400;
        $backupCutoff = $now - $backupDays * 86400;

        return [
            'staging' => $this->pruneUpdateStaging($cutoff, $dryRun),
            'temp' => $this->pruneCoreTemp($cutoff, $dryRun),
            'storage_temp' => $this->pruneComposerTemp($cutoff, $dryRun),
            'core_pending' => $this->pruneCorePendingManifests($cutoff, $dryRun),
            'core_pending_staging' => $this->pruneCorePendingStaging($cutoff, $dryRun),
            'core_update_preserved' => $this->pruneCoreUpdatePreserved($backupCutoff, $dryRun),
            'core_update_reports' => $this->pruneCoreUpdateReports($backupCutoff, $dryRun),
            'public_storage_broken' => $this->prunePublicStorageBroken($backupCutoff, $dryRun),
            'public_storage_broken_docroot' => $this->prunePublicStorageBrokenInDocroot($backupCutoff, $dryRun),
            'installer_probes' => $this->pruneInstallerProbes($cutoff, $dryRun),
            'installer_state_corrupt' => $this->pruneInstallerStateCorruptCopies($backupCutoff, $dryRun),
            'vendor_bundle_staging' => $this->pruneVendorBundleStaging($cutoff, $dryRun),
            'extension_backups' => $this->pruneExtensionBackups($backupCutoff, $dryRun),
            'core_backups' => $this->pruneCoreBackups($backupCutoff, $dryRun),
            'root_entry_temp' => $this->pruneRootEntryTemp($cutoff, $dryRun),
            'stale_swap_files' => $this->pruneStaleSwapFiles($cutoff, $dryRun),
            'env_backups' => $this->pruneEnvBackups($backupCutoff, $dryRun),
            'legacy_browser_log' => $this->pruneLegacyBrowserLog($dryRun),
            'carried_class_files' => $this->pruneCarriedClassFiles($dryRun),
        ];
    }

    /**
     * `{modules,plugins,templates}/_pending` 의 스테이징/교체 잔존물을 정리합니다.
     *
     * @param  int  $cutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneUpdateStaging(int $cutoff, bool $dryRun): array
    {
        $pruned = [];

        // 언어팩 `_pending` 도 같은 대상군이다 — 추출 디렉토리(UUID · `preview-<UUID>`)의 정리
        // 주체가 생산자의 `catch`/`finally` 뿐이라 강제 종료 시 영구 잔존했고, 이 스캔은 확장
        // 세 종만 봤다. 이름 판정은 **생산자 상수**에서 도출한다.
        $targets = [
            'modules'.DIRECTORY_SEPARATOR.'_pending' => [self::STAGING_TIMESTAMP_PATTERN, self::SWAP_LEFTOVER_PATTERN],
            'plugins'.DIRECTORY_SEPARATOR.'_pending' => [self::STAGING_TIMESTAMP_PATTERN, self::SWAP_LEFTOVER_PATTERN],
            'templates'.DIRECTORY_SEPARATOR.'_pending' => [self::STAGING_TIMESTAMP_PATTERN, self::SWAP_LEFTOVER_PATTERN],
            str_replace('/', DIRECTORY_SEPARATOR, LanguagePackService::PENDING_DIR) => [LanguagePackService::PENDING_EXTRACT_NAME_PATTERN],
        ];

        foreach ($targets as $relative => $patterns) {
            $pendingRoot = $this->basePath($relative);

            if (! File::isDirectory($pendingRoot)) {
                continue;
            }

            foreach (File::directories($pendingRoot) as $dir) {
                $name = basename($dir);

                // 생성 규칙(타임스탬프/교체 마커/추출 UUID)이 없는 디렉토리는 다운로드 대기
                // 정상 확장·운영자가 둔 팩 — 불가침.
                $matched = false;
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $name) === 1) {
                        $matched = true;
                        break;
                    }
                }

                if (! $matched) {
                    continue;
                }

                if ($this->isFresh($dir, $cutoff)) {
                    continue;
                }

                $pruned[] = $dir;

                if (! $dryRun) {
                    File::deleteDirectory($dir);
                }
            }
        }

        return $pruned;
    }

    /**
     * `storage/app/temp` 직하위 전부(디렉토리·파일)를 보존일 기준으로 정리합니다.
     *
     * @param  int  $cutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneCoreTemp(int $cutoff, bool $dryRun): array
    {
        return $this->pruneEntriesByAge(
            $this->storagePath('app'.DIRECTORY_SEPARATOR.'temp'),
            '',
            $cutoff,
            $dryRun
        );
    }

    /**
     * composer 폴백 임시 디렉토리(`storage/temp`) 직하위를 정리합니다.
     *
     * Windows 에서 시스템 TEMP 를 쓸 수 없을 때 `ExtensionManager` 가 composer 에 넘기는
     * 자리다. composer 가 채우고 **아무도 비우지 않는다** — 형제 대상군(`storage/app/temp`)과
     * 경로가 한 단계 다를 뿐인데 그 차이로 스캔 모집단 밖이었다.
     *
     * @param  int  $cutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneComposerTemp(int $cutoff, bool $dryRun): array
    {
        return $this->pruneEntriesByAge(
            $this->storagePath(ExtensionManager::COMPOSER_FALLBACK_TEMP_DIR),
            '',
            $cutoff,
            $dryRun
        );
    }

    /**
     * 번들 업데이트 자식 프로세스에 넘긴 매니페스트 잔존물을 정리합니다.
     *
     * `storage/app/core_pending/bundled-updates-manifest_*.json` — 정리 주체가 생산자의
     * `finally` 하나뿐이라, FPM 타임아웃·OOM-kill 로 그 코드가 돌지 않으면 영구히 남는다.
     * 이 서비스의 독블록이 정의한 실패 모드가 정확히 그것이다.
     *
     * 접두사로 좁힌다 — 같은 디렉토리에는 코어 업데이트가 쓰는 다른 산출물이 있다.
     *
     * @param  int  $cutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneCorePendingManifests(int $cutoff, bool $dryRun): array
    {
        return $this->pruneEntriesByAge(
            $this->storagePath('app'.DIRECTORY_SEPARATOR.'core_pending'),
            'bundled-updates-manifest_',
            $cutoff,
            $dryRun
        );
    }

    /**
     * 코어 업데이트가 전개한 `_pending` 디렉토리를 정리합니다.
     *
     * `{pending_path}/core_{Ymd_His}/` — 릴리즈 한 벌을 통째로 푼 자리다. 정리 주체는
     * `CoreUpdateService::cleanupPending()` 하나였고 그것은 커맨드의 정상 경로와 **처리된**
     * 실패 경로에서만 불린다. 치명 오류·요청 시간 초과·OOM-kill 이면 그 코드가 돌지 않아
     * 수백 MB 가 영구히 남는데, 이 서비스의 어느 대상군도 그 자리를 보지 않았다
     * (`core_pending` 은 같은 디렉토리의 **매니페스트 파일**만 접두사로 좁혀 본다).
     * 무CLI 웹 워커는 요청 시간 상한 안에서 도므로 그 실패 모드가 특히 흔하다
     * (#609 G6 — G3 회차 6 이관).
     *
     * 나이 하한이 진행 중인 실행을 보호한다 — 기본 보존일(3일) 안의 디렉토리는 건드리지
     * 않으므로, 지금 내려받는 중인 페이로드가 지워질 수 없다.
     *
     * 위치는 리터럴이 아니라 **설정**(`app.update.pending_path`)에서 읽는다. 운영자가 그
     * 값을 옮긴 사이트에서 기본 경로만 훑으면 이 대상군의 모집단이 조용히 빈다.
     *
     * @param  int  $cutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneCorePendingStaging(int $cutoff, bool $dryRun): array
    {
        $pruned = [];

        foreach ($this->corePendingDirectories() as $directory) {
            // 이름 판정은 **생산자 상수**에서 도출한다 — 사본으로 적으면 한쪽이 모양을 바꿔도
            // 다른 쪽은 따라가지 못하고, 그 상태가 오류가 아니라 모집단 공백으로만 나타난다.
            foreach (ExtensionManager::listDirectories($directory, CoreUpdateService::PENDING_DIR_PREFIX) as $entry) {
                if ($this->isFresh($entry, $cutoff)) {
                    continue;
                }

                $pruned[] = $entry;

                if (! $dryRun) {
                    File::deleteDirectory($entry);
                }
            }
        }

        return $pruned;
    }

    /**
     * 코어 `_pending` 이 놓일 수 있는 디렉토리 목록.
     *
     * 기본 위치(형제 대상군과 같은 `storage/app/core_pending`)와 **운영자가 옮긴 위치**
     * (`app.update.pending_path`)를 둘 다 본다. 설정만 보면 이 서비스가 주입받은 storage
     * 루트를 벗어나고, 기본값만 보면 옮긴 사이트의 잔여물이 모집단에서 빠진다 —
     * 생산자와 정리자의 후보가 갈리는 것이 정확히 그 공백을 만든다.
     *
     * @return list<string> 절대 경로 (중복 제거)
     */
    private function corePendingDirectories(): array
    {
        $directories = [$this->storagePath('app'.DIRECTORY_SEPARATOR.'core_pending')];

        $configured = (string) config('app.update.pending_path');

        if ($configured !== '') {
            $directories[] = $configured;
        }

        $seen = [];
        $unique = [];

        foreach ($directories as $directory) {
            $key = realpath($directory) ?: $directory;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $directory;
        }

        return $unique;
    }

    /**
     * 코어 업데이트 리포트 로그(`storage/logs/core_update_*.log`)를 정리합니다.
     *
     * 이 파일들은 daily 로테이션 **밖**이라 프레임워크가 걷지 않고, 이 서비스의 10개
     * 대상군 어디에도 없어 **정리 주체가 0** 이었다 — 업데이트할 때마다 하나씩 영구히
     * 쌓인다. 같은 서비스가 형제 로그(`logs/browser.log`)는 대상으로 삼는다.
     *
     * 보존 등급은 **백업군과 같다**(보존일 + 최신 1개는 나이 무관 보존). 실패 리포트는
     * 진단 자산이라, 운영자가 며칠 뒤 원인을 물을 때 마지막 하나는 남아 있어야 한다.
     *
     * @param  int  $backupCutoff  이 시각(Unix time) 이전 mtime 만 삭제 (최신 1개는 예외)
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneCoreUpdateReports(int $backupCutoff, bool $dryRun): array
    {
        $entries = [];

        // 이름 판정은 **생산자 상수**(`CoreUpdateService`)에서 도출한다.
        foreach (ExtensionManager::listDirectoryFiles(
            $this->storagePath('logs'),
            '.log',
            CoreUpdateService::UPDATE_REPORT_PREFIX
        ) as $path) {
            if (preg_match(CoreUpdateService::UPDATE_REPORT_NAME_PATTERN, basename($path), $matches) !== 1) {
                continue;
            }

            $entries[] = ['path' => $path, 'stamp' => $matches[1]];
        }

        return $this->pruneBackupSet($entries, $backupCutoff, $dryRun);
    }

    /**
     * finalize 가 남긴 `.env` 원본 보존 사본(`.env.bak.<Ymd_His>[_N]`)을 정리합니다.
     *
     * 프로젝트 루트 직하이고 `.env` 전문을 담으므로, 누적은 저장 공간이 아니라 **노출**
     * 문제다 — 형제 두 군(런타임 설정 · 상태 파일)과 같은 판단이다. 다만 이것은 운영자가
     * 값을 되찾는 **백업 자산**이라 임시 등급이 아니라 백업 등급으로 걷는다: 보존일이
     * 지난 것만, 그리고 **최신 1개는 나이와 무관하게 남긴다**.
     *
     * @param  int  $backupCutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneEnvBackups(int $backupCutoff, bool $dryRun): array
    {
        $entries = [];

        // 형제 `listDirectoryFiles()` 는 쓸 수 없다 — 그 헬퍼는 **점으로 시작하는 항목을
        // 전부 건너뛴다**(`.`/`..` 를 거르는 조건이 그대로 dotfile 전체에 걸린다). 이 이름은
        // 점으로 시작하므로 그 통로로는 영원히 0건이다. 그래서 형제 `pruneRootEntryTemp`
        // 와 같은 수단(가드된 `glob`, 패턴 자신이 앞 점을 포함)을 쓴다 — 그쪽도 정확히
        // 같은 이유(`.htaccess.tmp.*`)로 `glob` 을 골랐다.
        if (! ExecutionEnvironment::hasFunction('glob')) {
            return [];
        }

        $prefix = ($this->baseRoot ?? base_path()).DIRECTORY_SEPARATOR.self::ENV_BACKUP_PREFIX;

        foreach ((array) glob($prefix.'*') as $path) {
            if (! is_string($path) || ! is_file($path)) {
                continue;
            }

            if (preg_match(self::ENV_BACKUP_NAME_PATTERN, basename($path), $matches) !== 1) {
                continue;
            }

            $entries[] = ['path' => $path, 'stamp' => $matches[1]];
        }

        return $this->pruneBackupSet($entries, $backupCutoff, $dryRun);
    }

    /**
     * 제자리 동기화가 옆으로 치운 잠긴 파일(`*.g7stale_<uniqid>`)을 정리합니다.
     *
     * 생산자(`ExtensionPendingHelper`)는 치운 직후 삭제를 시도하고, 실패하면 「다음 제자리
     * 동기화가 다시 지운다」에 기댄다 — 그 확장을 다시 업데이트하지 않는 사이트에서는
     * 영구 잔존한다. 그 잔해는 **활성 확장 디렉토리 안**에 생기고, 배포 레이아웃 ②에서는
     * 그 자리가 문서 루트 안이다.
     *
     * @param  int  $cutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneStaleSwapFiles(int $cutoff, bool $dryRun): array
    {
        $pruned = [];

        foreach (self::EXTENSION_ROOTS as $type) {
            foreach ($this->findStaleSwapFiles($this->basePath($type)) as $path) {
                if ($this->isFresh($path, $cutoff)) {
                    continue;
                }

                $pruned[] = $path;

                if (! $dryRun) {
                    File::delete($path);
                }
            }
        }

        return $pruned;
    }

    /**
     * 한 확장 루트 아래에서 `.g7stale_` 잔해를 재귀 수집합니다.
     *
     * 나열은 `glob` 을 쓰지 않는다 — 이 서비스는 무CLI 환경에서 **웹 요청 안**의 예약
     * 작업으로 도는데, 그 함수가 막힌 호스팅에서는 PHP 8 이 함수 테이블에서 지워 치명
     * 오류가 된다(형제 지점들과 같은 판단).
     *
     * @param  string  $root  확장 루트 절대 경로
     * @return list<string> 잔해 파일 절대 경로 목록
     */
    private function findStaleSwapFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        // 형제 수집기(`collectPrefixed`)와 **같은 가드**다. `scandir` 은 하드닝 프로필의
        // 표준 차단 목록에 있고, PHP 8 은 차단 함수를 함수 테이블에서 지우므로 그 호출은
        // 치명 오류다 — 이 서비스는 웹 요청 안의 예약 작업으로 도는데, 한 수집기만 무가드면
        // 그 호스팅에서 정리 작업 전체가 그 자리에서 죽는다. 같은 클래스 안에서 가드가
        // 갈려 있던 상태였다.
        if (! ExecutionEnvironment::hasFunction('scandir')) {
            return [];
        }

        $found = [];
        $stack = [$root];

        while ($stack !== []) {
            $current = array_pop($stack);

            foreach ((array) @scandir($current) as $entry) {
                if (! is_string($entry)) {
                    continue;
                }
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $current.DIRECTORY_SEPARATOR.$entry;

                if (is_dir($path)) {
                    if (! in_array($entry, self::EXTENSION_SCAN_SKIP_DIRS, true)) {
                        $stack[] = $path;
                    }

                    continue;
                }

                // 이름 판정은 **생산자 상수**에서 도출한다.
                if (preg_match(ExtensionPendingHelper::STALE_ASIDE_NAME_PATTERN, $entry) === 1) {
                    $found[] = $path;
                }
            }
        }

        sort($found);

        return $found;
    }

    /**
     * 코어 업데이트가 교체 직전에 남긴 루트 진입 파일 사본을 정리합니다.
     *
     * `storage/app/core-update/{ts}/` — 레이아웃 ③ 사이트의 자기 `index.php` 를 코어 shim
     * 으로 교체하기 전에 그 파일 하나를 사본해 두는 자리다. 되찾을 수단이므로 즉시 지우지
     * 않지만 **정리 주체는 있어야 한다** — 종전에는 어느 대상군에도 없어 영구 잔존했다.
     *
     * 보존 등급은 **백업군과 같다**(`$backupCutoff` + 최신 1개는 나이와 무관하게 보존).
     * 종전에는 임시 산출물 등급(기본 3일)이었는데, 이 사본은 임시물이 아니라 **유일한
     * 복구 수단**이다: 업데이트가 성공하면 코어 백업은 그 자리에서 삭제되므로, 운영자가
     * 자기 랜딩 페이지가 사라진 것을 나흘 뒤에 알아차리면 되돌릴 것이 아무것도 남지 않는다.
     * 형제 백업군(`core_backups` 30일 · `extension_backups` 30일 + 최신 1개)과 등급을
     * 맞춘다 — 되돌릴 수단의 수명이 그것을 쓰게 되는 시점보다 짧으면 안 된다.
     *
     * @param  int  $backupCutoff  이 시각(Unix time) 이전 mtime 만 삭제 (최신 1개는 예외)
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneCoreUpdatePreserved(int $backupCutoff, bool $dryRun): array
    {
        $root = $this->storagePath('app'.DIRECTORY_SEPARATOR.'core-update');

        if (! File::isDirectory($root)) {
            return [];
        }

        $entries = [];

        foreach (File::directories($root) as $dir) {
            // **이름 패턴을 통과한 것만** 집합에 넣는다 (형제 두 백업군과 같은 규약).
            //
            // 이 게이트가 없으면 형식이 다른 잔해(`manual`·`_keep`·`tmp` 등) 하나가
            // 문자열 정렬에서 숫자보다 뒤에 와 **영구히 「최신 1개」 자리를 차지**하고,
            // 진짜 최신 타임스탬프 사본은 무조건 보존을 잃어 보존일 뒤 삭제된다 —
            // 등급을 백업으로 올린 목적(되돌릴 수단의 수명)이 그 자리에서 무효가 된다.
            if (preg_match(self::CORE_UPDATE_PRESERVED_NAME_PATTERN, basename($dir)) !== 1) {
                continue;
            }

            $entries[] = ['path' => $dir, 'stamp' => basename($dir)];
        }

        return $this->pruneBackupSet($entries, $backupCutoff, $dryRun);
    }

    /**
     * 손상된 공개 스토리지 링크를 옮겨 둔 백업을 정리합니다.
     *
     * `storage/app/public-storage-broken-<YmdHis>-<hex>` — `public/storage` 가 링크가
     * 아닌 실제 디렉토리로 남아 있을 때 그 트리를 통째로 옮겨 두는 자리다. 내용이
     * **공개 파일 전량 사본**이라 크기에 상한이 없고, 링크가 반복해서 손상되는 환경
     * (공유 호스팅의 주기 동기화 등)에서는 실행할 때마다 하나씩 쌓인다.
     *
     * 종전에는 어느 대상군에도 없어 **정리 주체가 0** 이었다 — G3 이 이 백업을 문서 루트
     * 안에서 밖으로 옮기면서 위치만 바꾸고 회수 주체는 만들지 않았다. 형제
     * `core_update_preserved` 가 정확히 같은 사유로 편입된 선례다.
     *
     * 보존 등급은 **백업군과 같다**(보존일 + 최신 1개는 나이 무관 보존). 자동 삭제가
     * 데이터 손실이라는 판단(생산자 독블록)은 그대로 존중한다 — 여기서 바꾸는 것은
     * 「영원히 보존」이 아니라 「백업 등급으로 회수」이고, 최신 1개는 언제나 남는다.
     *
     * @param  int  $backupCutoff  이 시각(Unix time) 이전 mtime 만 삭제 (최신 1개는 예외)
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function prunePublicStorageBroken(int $backupCutoff, bool $dryRun): array
    {
        return $this->pruneBackupSet(
            $this->collectBrokenBackups(
                $this->storagePath('app'),
                StorageLinkHelper::PUBLIC_STORAGE_BROKEN_PREFIX,
                StorageLinkHelper::publicStorageBrokenNamePattern()
            ),
            $backupCutoff,
            $dryRun
        );
    }

    /**
     * **웹 트리 안**에 남은 손상 공개 스토리지 백업을 정리합니다.
     *
     * `public/storage.broken-<YmdHis>-<hex>` — 볼륨이 달라 웹 트리 밖으로 옮기지 못했을 때
     * 링크 복구가 쓰는 폴백 위치이고, 7.0.x 에서는 이쪽이 **정상 경로**였다. 형제
     * (`storage/app` 쪽)만 회수 주체를 얻는 동안 이쪽은 **정리 주체가 0** 이었다:
     * 7.1.0 이설 마이그레이션은 1회성이라 그 뒤에 새로 생기는 것을 잡지 못하고, 누적 경고도
     * `storage/app` 만 세어 발화하지 않았다.
     *
     * 이 사본은 형제와 달리 **지금 웹에서 열린다** — 그래서 회수의 필요는 더 크다. 그래도
     * 등급은 형제와 같은 백업 등급이다(보존일 + 최신 1개 상시 보존): 링크가 깨져 있던 창에
     * 업로드된 파일이 그 안에만 남아 있을 수 있어 즉시 삭제는 데이터 손실이다.
     *
     * @param  int  $backupCutoff  이 시각(Unix time) 이전 mtime 만 삭제 (최신 1개는 예외)
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function prunePublicStorageBrokenInDocroot(int $backupCutoff, bool $dryRun): array
    {
        return $this->pruneBackupSet(
            $this->collectBrokenBackups(
                $this->publicPath(),
                StorageLinkHelper::DOCROOT_BROKEN_PREFIX,
                StorageLinkHelper::docrootBrokenNamePattern()
            ),
            $backupCutoff,
            $dryRun
        );
    }

    /**
     * 한 디렉토리에서 손상 백업 항목(경로 + 정렬용 시각)을 모읍니다.
     *
     * 이름 판정은 **생산자 상수**(`StorageLinkHelper`)에서 도출한다 — 리터럴로 다시 적으면
     * 생산자가 규칙을 바꿀 때 모집단이 조용히 빈다.
     *
     * @param  string  $root  나열할 디렉토리 절대 경로
     * @param  string  $prefix  이름 접두사 (생산자 상수)
     * @param  string  $namePattern  이름 전체 판정 정규식 (생산자 도출)
     * @return list<array{path: string, stamp: string}> 백업 집합
     */
    private function collectBrokenBackups(string $root, string $prefix, string $namePattern): array
    {
        $entries = [];

        foreach (ExtensionManager::listDirectories($root, $prefix) as $dir) {
            $name = basename($dir);

            if (preg_match($namePattern, $name) !== 1) {
                continue;
            }

            // `stamp` 는 **시각 부분**만 넘긴다.
            //
            // 정렬 키는 이름 앞 15자만 보는데(`BackupDirectoryName::sortKey`), 이 접두사는
            // 그보다 길어 전체 이름을 넘기면 두 항목의 키가 접두사만으로 **같아진다** —
            // 그러면 「최신 1개」가 시각과 무관하게 정해지고, 최신 백업이 지워질 수 있다.
            $entries[] = [
                'path' => $dir,
                'stamp' => substr($name, strlen($prefix)),
            ];
        }

        return $entries;
    }

    /**
     * 루트 진입·보호 파일의 **원자 교체 임시 파일**을 정리합니다.
     *
     * `RootHtaccessUpdater` 는 `<대상>.tmp.<hex>` 에 먼저 쓰고 `rename` 으로 바꾼다.
     * 실패하면 그 임시 파일이 남고, 걷는 주체는 **그 클래스의 다음 실행**뿐이다 —
     * 코어 업데이트를 다시 하지 않는 사이트에서는 영구 잔존한다. 레이아웃 ② 에서는
     * 그 파일이 문서 루트 **안**에 있다(점 파일이라 서빙은 막히지만, 지우는 주체가
     * 없다는 사실은 그대로).
     *
     * 형제 대상군 전부가 「생산자의 자기 스윕 + 정리 커맨드」 두 주체 구조인데 이
     * 자리만 하나였다.
     *
     * 이름 판정은 **생성 규칙과 같은 형태**(16진 접미사)로 좁힌다 — 운영자가 같은 이름
     * 공간에 둔 파일을 지우지 않기 위해서다.
     *
     * @param  int  $cutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneRootEntryTemp(int $cutoff, bool $dryRun): array
    {
        $pruned = [];

        // `glob` 은 호스팅이 차단할 수 있는 함수다 — 형제 생산자
        // (`RootHtaccessUpdater::sweepStaleTempFiles`)가 같은 판정을 먼저 한다.
        // 막힌 환경에서는 그 스윕도 함께 멈추므로, 여기서 조용히 아무것도 못 지우는
        // 것이 아니라 「이 축은 재지 않았다」가 되는 편이 낫다.
        if (! ExecutionEnvironment::hasFunction('glob')) {
            return $pruned;
        }

        foreach (self::ROOT_ENTRY_TEMP_TARGETS as $target => $updater) {
            $rootFile = $this->basePath($target);

            // 잔해가 생기는 자리는 **생산자가 쓰는 자리**다 — 루트 파일이 심볼릭 링크면 생산자는
            // 임시 파일을 링크 **대상**의 디렉토리에 만든다(교차 파일시스템 `rename` 회피). 링크가
            // 놓인 디렉토리만 보면 그 잔해는 어느 정리 주체의 모집단에도 들지 않아 영구 잔존한다.
            // 도출은 **그 대상의 갱신기**(`$updater`)의 `resolveWriteTarget()` 을 그대로 쓴다 —
            // 둘이 갈리면 모집단이 조용히 빈다. 링크 자리도 함께 본다(구판 생산자가 남긴 잔해)
            // (web.config 는 RootWebConfigUpdater 가 판정한다).
            $prefixes = [$rootFile.$updater::TEMP_INFIX];
            $writeTarget = $updater::resolveWriteTarget($rootFile);
            if ($writeTarget !== null && $writeTarget !== $rootFile) {
                $prefixes[] = $writeTarget.$updater::TEMP_INFIX;
            }

            foreach ($prefixes as $prefix) {
                foreach ((array) glob($prefix.'*') as $path) {
                    if (! is_string($path) || ! is_file($path)) {
                        continue;
                    }

                    // 이름 판정은 **그 대상 갱신기의 상수**에서 도출한다 — 한쪽이 좁으면 잔해가
                    // 영원히 남고, 넓으면 운영자가 같은 이름 공간에 둔 파일이 조용히 사라진다.
                    if (preg_match($updater::TEMP_SUFFIX_PATTERN, substr($path, strlen($prefix))) !== 1) {
                        continue;
                    }

                    $mtime = @filemtime($path);

                    if ($mtime === false || $mtime >= $cutoff) {
                        continue;
                    }

                    $pruned[] = $path;

                    if (! $dryRun) {
                        File::delete($path);
                    }
                }
            }
        }

        return $pruned;
    }

    /**
     * 인스톨러 노출 점검이 남긴 1회용 파일을 정리합니다.
     *
     * `storage/installer/` 의 sentinel·링크 실측 잔여물은 인스톨러 자신이 걷지만, 설치가
     * 중단·방치로 끝난 사이트에서는 그 정리 지점이 실행되지 않는다. 내용은 난수 1회용이라
     * 정보 가치가 없지만 파일이 계속 쌓이는 것은 별개 문제다.
     *
     * @param  int  $cutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneInstallerProbes(int $cutoff, bool $dryRun): array
    {
        $pruned = [];

        foreach (self::INSTALLER_PROBE_PREFIXES as $prefix) {
            $pruned = array_merge($pruned, $this->pruneEntriesByAge(
                $this->storagePath('installer'),
                $prefix,
                $cutoff,
                $dryRun
            ));
        }

        // 레이트리밋 상태 파일도 같은 등급이다.
        //
        // `storage/installer-probe-rate.json` 은 `storage/` **직하**라 위 스캔의 모집단
        // (`storage/installer/`) 밖이었다. 지우는 주체가 finalize 하나뿐인데, 이 공정이
        // 대상으로 삼은 배치의 흔한 종착점은 **요구사항 화면에서 막혀 설치를 포기하는
        // 것**이다 — 그러면 이 파일은 영구 잔존한다. 버킷이 자기 만료를 갖더라도 파일
        // 자체를 지우는 주체는 있어야 한다.
        $rateFile = $this->storagePath(self::INSTALLER_PROBE_RATE_FILE);

        // 나이는 헬퍼로 잰다 — `filemtime()` 실패(false)를 직접 `<` 에 넣으면 `false < int`
        // 가 참이라 조회 실패가 곧 삭제(fail-open)였다.
        if (is_file($rateFile) && ! $this->isFresh($rateFile, $cutoff)) {
            $pruned[] = $rateFile;

            if (! $dryRun) {
                File::delete($rateFile);
            }
        }

        return array_merge(
            $pruned,
            $this->pruneInstallerAtomicWriteTemp($cutoff, $dryRun),
            $this->pruneInstallerWriteProbes($cutoff, $dryRun),
            $this->pruneInstallerResidue($cutoff, $dryRun)
        );
    }

    /**
     * 인스톨러 원자 교체 임시 파일(`runtime.php.tmp.<hex>` · `installer-state.json.tmp.<hex>`)을 정리합니다.
     *
     * 생산자 스윕은 **다음 쓰기 때만** 돈다 — 설치를 포기한 사이트에서는 다시 쓰지 않으므로
     * 영구 잔존한다. `runtime.php.tmp.*` 는 DB 자격증명·APP_KEY 를 담고 확장자가 `.php` 가
     * 아니라 배포 레이아웃 ②에서 평문으로 나간다.
     *
     * 이 tmp 는 설치를 재개해도 **다시 읽히지 않는다** — 그래서 형제(상태 파일 · 런타임 파일)와
     * 달리 설치 미완 사이트에서도 임시 등급 그대로 걷는다. 이름 판정은 생산자와 같은 규칙
     * (`.tmp.` + 16진 접미사)이며, 그 값이 코어 형제 `RootHtaccessUpdater` 와 같다는 사실은
     * 계약 테스트가 잠근다 — 인스톨러 상수는 여기서 참조할 수 없다.
     *
     * @param  int  $cutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneInstallerAtomicWriteTemp(int $cutoff, bool $dryRun): array
    {
        $pruned = [];

        foreach ([self::INSTALLER_STATE_FILE, self::INSTALLER_RUNTIME_FILE] as $relative) {
            $target = $this->storagePath($relative);
            $prefix = basename($target).RootHtaccessUpdater::TEMP_INFIX;

            foreach (ExtensionManager::listDirectoryFiles(dirname($target), '', $prefix) as $path) {
                if (preg_match(RootHtaccessUpdater::TEMP_SUFFIX_PATTERN, substr(basename($path), strlen($prefix))) !== 1) {
                    continue;
                }

                if ($this->isFresh($path, $cutoff)) {
                    continue;
                }

                $pruned[] = $path;

                if (! $dryRun) {
                    File::delete($path);
                }
            }
        }

        return $pruned;
    }

    /**
     * 쓰기 가능 실측 프로브(`.installer-write-probe-<hex>`) 잔해를 정리합니다.
     *
     * 생산자(`installerDirectoryIsWritable`)는 만든 직후 지우지만 fopen 과 unlink 사이에서
     * 프로세스가 죽으면 남는다. 인스톨러가 점검하는 디렉토리 전부에 생길 수 있어 모집단이
     * 넓고, 점 파일이라 형제 통로(`listDirectoryFiles`)로는 잡히지 않는다.
     *
     * @param  int  $cutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneInstallerWriteProbes(int $cutoff, bool $dryRun): array
    {
        $directories = [];

        foreach (self::INSTALLER_WRITE_PROBE_ROOTS as $relative) {
            $root = $this->basePath(str_replace('/', DIRECTORY_SEPARATOR, $relative));

            if (! is_dir($root)) {
                continue;
            }

            $directories[] = $root;

            if ($relative === 'storage') {
                array_push($directories, ...$this->subdirectoriesUpToDepth($root, self::INSTALLER_WRITE_PROBE_STORAGE_DEPTH));
            }
        }

        // 스토리지 루트가 프로젝트 루트 밖에 있을 수 있다(`storage_path` 재정의) — 그쪽도 본다.
        $storageRoot = rtrim($this->storagePath(''), DIRECTORY_SEPARATOR);

        if (is_dir($storageRoot) && ! in_array($storageRoot, $directories, true)) {
            $directories[] = $storageRoot;
            array_push($directories, ...$this->subdirectoriesUpToDepth($storageRoot, self::INSTALLER_WRITE_PROBE_STORAGE_DEPTH));
        }

        $pruned = [];

        foreach (array_unique($directories) as $directory) {
            $prefix = $directory.DIRECTORY_SEPARATOR.self::INSTALLER_WRITE_PROBE_PREFIX;

            // `glob` 이 막힌 호스팅에서는 `scandir` 로 잇는다 — 종전에는 그 환경에서 통째로
            // 빈 배열이라 11개 디렉토리의 프로브 잔해에 정리 주체가 0 이었고, 그 호스팅이 곧
            // 이 프루너가 대상으로 삼는 배치다.
            foreach ($this->listFilesByPrefix($directory, self::INSTALLER_WRITE_PROBE_PREFIX) as $path) {
                // 생성 규칙(16진 접미사)과 같은 형태만 — 코어 형제와 같은 값이다.
                if (preg_match(RootHtaccessUpdater::TEMP_SUFFIX_PATTERN, substr($path, strlen($prefix))) !== 1) {
                    continue;
                }

                if ($this->isFresh($path, $cutoff)) {
                    continue;
                }

                $pruned[] = $path;

                if (! $dryRun) {
                    File::delete($path);
                }
            }
        }

        return $pruned;
    }

    /**
     * 한 디렉토리 직하에서 이름이 접두사로 시작하는 **파일**을 나열합니다 — `glob` 이 없으면 `scandir`.
     *
     * 점 파일도 나열한다(`glob` 의 기본 동작과 같고, `scandir` 은 원래 가리지 않는다).
     * 형식(접미사) 판정은 호출부 몫이다 — 여기서는 접두사만 본다. 인스톨러 형제
     * `installerListExposureProbeFiles()` 와 같은 폴백이다.
     *
     * @param  string  $directory  대상 디렉토리
     * @param  string  $prefix  파일 이름 접두사
     * @param  bool|null  $useGlob  강제 경로 선택 (null = 가용성으로 판정; 테스트가 폴백 분기를 직접 잰다)
     * @return list<string> 절대 경로 목록
     */
    private function listFilesByPrefix(string $directory, string $prefix, ?bool $useGlob = null): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $useGlob ??= ExecutionEnvironment::hasFunction('glob');
        $files = [];

        if ($useGlob) {
            foreach ((array) glob($directory.DIRECTORY_SEPARATOR.$prefix.'*') as $path) {
                if (is_string($path) && is_file($path)) {
                    $files[] = $path;
                }
            }

            return $files;
        }

        if (! ExecutionEnvironment::hasFunction('scandir')) {
            return [];
        }

        foreach ((array) @scandir($directory) as $entry) {
            if (! is_string($entry) || $entry === '.' || $entry === '..' || ! str_starts_with($entry, $prefix)) {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;

            if (is_file($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * 한 디렉토리 아래의 하위 디렉토리를 주어진 깊이까지 모읍니다 (`glob` 없이).
     *
     * @param  string  $root  시작 디렉토리
     * @param  int  $depth  내려갈 깊이 (1 = 직하위만)
     * @return list<string> 하위 디렉토리 절대 경로 목록
     */
    private function subdirectoriesUpToDepth(string $root, int $depth): array
    {
        if ($depth <= 0) {
            return [];
        }

        $found = [];

        foreach (ExtensionManager::listDirectories($root, '') as $dir) {
            $found[] = $dir;
            array_push($found, ...$this->subdirectoriesUpToDepth($dir, $depth - 1));
        }

        return $found;
    }

    /**
     * 파손 상태 사본(`installer-state.json.corrupt.<YmdHis>.<sha8>`)을 정리합니다.
     *
     * 생산자(`installerPreserveCorruptState`)는 상한 5 로 만들기만 하고 지우는 지점이 0 이었다.
     * 마스킹 전 비밀(구버전 상태 파일)을 담을 수 있으므로 누적은 노출 문제다. 다만 진단
     * 자산이라 **백업 등급**(보존일 + 최신 1개 보존)으로 걷는다.
     *
     * @param  int  $backupCutoff  이 시각(Unix time) 이전 mtime 만 삭제 (최신 1개는 예외)
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneInstallerStateCorruptCopies(int $backupCutoff, bool $dryRun): array
    {
        $target = $this->storagePath(self::INSTALLER_STATE_FILE);
        $prefix = basename($target).self::INSTALLER_STATE_CORRUPT_INFIX;
        $entries = [];

        foreach (ExtensionManager::listDirectoryFiles(dirname($target), '', $prefix) as $path) {
            if (preg_match(self::INSTALLER_STATE_CORRUPT_SUFFIX_PATTERN, substr(basename($path), strlen($prefix)), $m) !== 1) {
                continue;
            }

            $entries[] = ['path' => $path, 'stamp' => $m[1]];
        }

        return $this->pruneBackupSet($entries, $backupCutoff, $dryRun);
    }

    /**
     * 설치 진행 산출물(상태 파일 · 런타임 설정 · 명령 출력 로그)을 정리합니다.
     *
     * 셋 다 정리 주체가 finalize 하나뿐인데, 이 공정이 대상으로 삼은 배치의 흔한 종착점은
     * **요구사항 화면에서 막혀 설치를 포기하는 것**이다 — 그러면 영구 잔존한다. 레이트리밋
     * 파일만 같은 사유로 먼저 편입되고 형제 셋이 남아 있었다.
     *
     * 등급 판단의 근거는 저장 공간이 아니라 **노출**이다. `storage/installer/runtime.php`
     * 는 DB 접속 정보와 APP_KEY 를 담고, 배포 레이아웃 ②에서는 `storage/` 가 문서 루트
     * 안이다 — 그것을 가리는 것은 루트 보호 파일 하나뿐이다.
     *
     * 그러나 이 셋은 **설치를 재개하면 다시 읽히는 파일**이라, 설치가 끝나지 않은 사이트
     * 에서는 훨씬 긴 나이를 요구한다(`INSTALLER_RESIDUE_DAYS`). 며칠 뒤 재개하려는 운영자의
     * 설치를 이 정리기가 처음으로 되돌려서는 안 된다.
     *
     * @param  int  $cutoff  이 시각(Unix time) 이전 mtime 만 삭제 (설치 완료 사이트 기준)
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneInstallerResidue(int $cutoff, bool $dryRun): array
    {
        // 설치가 끝났으면 순수 잔여물이므로 임시 등급 그대로, 아니면 재개 가능성을 존중해
        // 둘 중 **더 오래된 쪽**을 기준으로 삼는다(운영자가 보존일을 크게 잡았다면 그 값을
        // 그대로 존중한다 — 여기서 다시 완화하면 그 설정이 무의미해진다).
        $effectiveCutoff = $this->installationCompleted()
            ? $cutoff
            : min($cutoff, time() - self::INSTALLER_RESIDUE_DAYS * 86400);

        $pruned = [];

        // 잠금 파일은 상태 파일과 **같은 등급**이다 — 설치가 끝났으면 잔여물, 미완이면 재개 시
        // 다시 잠글 대상이라 함께 보존한다.
        foreach ([
            self::INSTALLER_STATE_FILE,
            self::INSTALLER_STATE_FILE.self::INSTALLER_STATE_LOCK_SUFFIX,
            self::INSTALLER_RUNTIME_FILE,
        ] as $relative) {
            $path = $this->storagePath($relative);

            if (! is_file($path) || $this->isFresh($path, $effectiveCutoff)) {
                continue;
            }

            $pruned[] = $path;

            if (! $dryRun) {
                File::delete($path);
            }
        }

        foreach (self::INSTALLER_CMD_OUTPUT_DIRS as $dir) {
            $pruned = array_merge($pruned, $this->pruneEntriesByAge(
                $this->storagePath($dir),
                self::INSTALLER_CMD_OUTPUT_PREFIX,
                $effectiveCutoff,
                $dryRun
            ));
        }

        // 생산자(`task-runner.php::reserveCommandOutputFile`)는 후보 디렉토리를 **셋** 본다 —
        // `storage/logs` · `storage/installer` · 시스템 임시 디렉토리. 앞의 둘만 걷으면
        // 세 번째에 예약된 출력 로그가 두 번째 정리 주체의 모집단 밖에 남는다(생산자 스윕은
        // 설치가 끝나면 다시 돌지 않는다). 두 주체의 모집단은 같아야 한다.
        if (ExecutionEnvironment::hasFunction('sys_get_temp_dir')) {
            $systemTemp = sys_get_temp_dir();

            if ($systemTemp !== '') {
                $pruned = array_merge($pruned, $this->pruneEntriesByAge(
                    $systemTemp,
                    self::INSTALLER_CMD_OUTPUT_PREFIX,
                    $effectiveCutoff,
                    $dryRun
                ));
            }
        }

        return $pruned;
    }

    /**
     * 이 사이트의 설치가 끝났는지 판정합니다.
     *
     * 판정 근거는 코어의 형제 지점(`RouteCacheHelper` · `ConfigCacheHelper`)과 같다 —
     * 여기서 다른 근거를 쓰면 같은 사이트가 지점마다 다른 상태로 읽힌다.
     *
     * @return bool 설치 완료면 true
     */
    private function installationCompleted(): bool
    {
        if (config('app.installer_completed')) {
            return true;
        }

        return is_file($this->storagePath(self::INSTALLED_MARKER_FILE));
    }

    /**
     * vendor 번들 스테이징(`build-*`)을 보존일 기준으로 정리합니다.
     *
     * @param  int  $cutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneVendorBundleStaging(int $cutoff, bool $dryRun): array
    {
        return $this->pruneEntriesByAge(
            $this->storagePath('app'.DIRECTORY_SEPARATOR.'vendor-bundle-staging'),
            'build-',
            $cutoff,
            $dryRun
        );
    }

    /**
     * 확장 백업(`extension_backups/{type}/{identifier}_{Ymd_His}`)을 정리합니다.
     *
     * identifier 별 최신 1개는 나이와 무관하게 보존하고, 나머지 중 보존일 경과분만
     * 삭제합니다. type 디렉토리는 열거로 얻으므로 새 백업 유형(lang-packs 등)도
     * 하드코딩 없이 같은 규칙을 받습니다.
     *
     * @param  int  $backupCutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneExtensionBackups(int $backupCutoff, bool $dryRun): array
    {
        $pruned = [];
        $backupRoot = $this->storagePath('app'.DIRECTORY_SEPARATOR.'extension_backups');

        if (! File::isDirectory($backupRoot)) {
            return $pruned;
        }

        foreach (File::directories($backupRoot) as $typeDir) {
            /** @var array<string, list<array{path: string, stamp: string}>> $byIdentifier */
            $byIdentifier = [];

            foreach (File::directories($typeDir) as $dir) {
                if (! preg_match(self::BACKUP_NAME_PATTERN, basename($dir), $matches)) {
                    continue;
                }

                $byIdentifier[$matches[1]][] = ['path' => $dir, 'stamp' => $matches[2]];
            }

            foreach ($byIdentifier as $entries) {
                array_push($pruned, ...$this->pruneBackupSet($entries, $backupCutoff, $dryRun));
            }
        }

        return $pruned;
    }

    /**
     * 코어 백업(`core_backups/core_{Ymd_His}`)을 정리합니다 — 최신 1개 상시 보존.
     *
     * @param  int  $backupCutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneCoreBackups(int $backupCutoff, bool $dryRun): array
    {
        $backupRoot = $this->storagePath('app'.DIRECTORY_SEPARATOR.'core_backups');

        if (! File::isDirectory($backupRoot)) {
            return [];
        }

        $entries = [];

        foreach (File::directories($backupRoot) as $dir) {
            if (! preg_match(self::CORE_BACKUP_NAME_PATTERN, basename($dir), $matches)) {
                continue;
            }

            $entries[] = ['path' => $dir, 'stamp' => $matches[1]];
        }

        return $this->pruneBackupSet($entries, $backupCutoff, $dryRun);
    }

    /**
     * 확장 교체가 남겨 둔 옛 클래스 파일을 정리 대기 목록에서 지웁니다 — 기록 뒤 최장 요청 시간보다 충분히 지난 항목만.
     *
     * 교체 직후에는 그 전에 부팅해 옛 훅 목록을 쥔 요청이 그 파일을 열 수 있어 바로 지우지 않는다({@see CarriedClassFileLedger}).
     * 이 예약 작업은 크론이 없는 호스팅에서도 웹 크론 주소·방문 트리거로 돈다. 지웠으면 소스 classmap 항목을 걷으려고 오토로드 파일을 다시 쓴다.
     *
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 지운(지울) 파일 경로 목록
     */
    private function pruneCarriedClassFiles(bool $dryRun): array
    {
        $ledger = app(CarriedClassFileLedger::class);

        if ($dryRun) {
            $due = [];
            foreach ($ledger->read() as $entry) {
                if ((time() - (int) ($entry['recorded_at'] ?? 0)) >= CarriedClassFileLedger::MIN_AGE_SECONDS) {
                    foreach (array_keys((array) ($entry['files'] ?? [])) as $relative) {
                        $due[] = rtrim((string) $entry['active'], '/\\').'/'.$relative;
                    }
                }
            }

            return $due;
        }

        $paths = [];
        foreach ($ledger->sweep() as $active => $files) {
            foreach ($files as $relative) {
                $paths[] = rtrim($active, '/\\').'/'.$relative;
            }
        }

        if ($paths !== []) {
            app(ExtensionManager::class)->generateAutoloadFile();
        }

        return $paths;
    }

    /**
     * 레거시 단일 `storage/logs/browser.log` 를 정리합니다.
     *
     * 브라우저 로그가 daily 드라이버로 전환되어 이 파일에 쓰는 코드는 더 이상 없다 —
     * 존재 자체가 잔존물이므로 나이와 무관하게 삭제합니다. 날짜별 `browser-*.log` 는
     * daily 드라이버가 보존일로 자체 정리하므로 대상이 아닙니다.
     *
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneLegacyBrowserLog(bool $dryRun): array
    {
        $path = $this->storagePath('logs'.DIRECTORY_SEPARATOR.'browser.log');

        if (! File::exists($path)) {
            return [];
        }

        if (! $dryRun) {
            File::delete($path);
        }

        return [$path];
    }

    /**
     * 같은 백업 집합에서 최신 1개를 제외하고 보존일 경과분을 삭제합니다.
     *
     * 최신 판정은 디렉토리명 타임스탬프(`Ymd_His` — 사전순 = 시간순) 기준입니다.
     *
     * @param  list<array{path: string, stamp: string}>  $entries  같은 identifier 의 백업 목록
     * @param  int  $backupCutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneBackupSet(array $entries, int $backupCutoff, bool $dryRun): array
    {
        if ($entries === []) {
            return [];
        }

        // 비교는 **시각 부분만** 한다 — 이름 끝의 충돌 방지 접미사는 난수라 그것까지
        // 비교하면 같은 초에 만들어진 둘의 앞뒤가 내용과 무관하게 갈린다.
        //
        // 다만 시각 부분만 보면 **같은 초의 두 항목은 키가 같아진다.** PHP 8 의 정렬은
        // 안정적이라 그때는 입력 순서가 그대로 남는데, 그 입력은 디렉토리 열거 순서
        // (= 사실상 난수 접미사의 사전순)다. 즉 접미사를 비교에서 뺀 것만으로는 그
        // 임의성이 사라지지 않고 비교자에서 입력 순서로 자리를 옮길 뿐이며, 「최신
        // 1개를 보존한다」는 약속이 그 구간에서만 성립하지 않는다.
        //
        // 그래서 시각 부분이 같으면 **mtime** 으로 가른다 — 난수가 아니라 내용에서
        // 나온 값이다. (같은 초 두 항목을 재는 회귀 시험이 이 구간을 처음으로
        // 밟았다.)
        //
        // **다만 그것만으로 전순서가 서지 않는다.** `filemtime()` 도 stamp 와 같은 **초
        // 해상도**라, 같은 초에 만들어진 둘은 mtime 까지 같아지는 것이 이 구간의 *정상*
        // 상태다. 그때 비교자가 0 을 돌리면 안정 정렬이 입력 순서를 남기고, 그 입력은 다시
        // 디렉토리 열거 순서다 — 위에서 없앴다고 적은 그 임의성이 한 겹 아래에서 그대로
        // 살아난다. 그래서 마지막 결정자로 **이름 전체**를 둔다: 그 순서가 「진짜 최신」을
        // 뜻하지는 않지만(같은 초의 둘 중 어느 쪽이 나중인지는 초 해상도로는 알 수 없다),
        // 적어도 **열거 순서와 무관하게 언제나 같은 하나가 살아남는다**. 「최신 1개 보존」이
        // 실행마다 다른 대상을 지우는 일은 이것으로 사라진다.
        // 조회 실패는 **보존** 방향이다. `0` 으로 접으면 그 항목이 「가장 오래됨」
        // 으로 맨 뒤에 정렬돼 「최신 1개 보존」의 밖이 된다 — `isFresh()` 가 실패를 보존으로
        // 읽는 것과 반대 방향이다. 최신으로 읽어 보존 쪽(맨 앞)에 둔다.
        $mtime = static function (string $path): int {
            $t = @filemtime($path);

            return $t === false ? PHP_INT_MAX : $t;
        };

        usort($entries, static function (array $a, array $b) use ($mtime): int {
            $byStamp = strcmp(
                BackupDirectoryName::sortKey($b['stamp']),
                BackupDirectoryName::sortKey($a['stamp'])
            );

            if ($byStamp !== 0) {
                return $byStamp;
            }

            $byMtime = $mtime($b['path']) <=> $mtime($a['path']);

            if ($byMtime !== 0) {
                return $byMtime;
            }

            // 초 해상도가 가르지 못하는 구간의 최종 결정자 — 열거 순서 의존을 없앤다.
            return strcmp((string) $b['path'], (string) $a['path']);
        });

        // 최신 1개는 롤백 자산 — 나이와 무관하게 보존.
        array_shift($entries);

        $pruned = [];

        foreach ($entries as $entry) {
            if ($this->isFresh($entry['path'], $backupCutoff)) {
                continue;
            }

            $pruned[] = $entry['path'];

            if (! $dryRun) {
                // 백업 집합의 항목이 **디렉토리라고 가정하지 않는다.**
                //
                // `File::deleteDirectory()` 는 대상이 파일이면 아무 일도 하지 않고 `false`
                // 를 돌린다 — 반환값을 보지 않는 이 자리에서는 「목록에는 올랐는데 지워지지
                // 않는다」가 되어, 커맨드는 「N건 삭제」를 출력하지만 파일은 그대로 남는다.
                // 오류도 경고도 없다. 형제 헬퍼(`pruneEntriesByAge`)는 처음부터 둘을 갈랐다.
                is_dir($entry['path'])
                    ? File::deleteDirectory($entry['path'])
                    : File::delete($entry['path']);
            }
        }

        return $pruned;
    }

    /**
     * 한 디렉토리 안의 항목(디렉토리·파일)을 보존일 기준으로 정리합니다.
     *
     * 종전에는 `File::glob($pattern)` 이었다. 이 서비스는 예약 작업으로 도는데 무CLI
     * 환경에서 그 스케줄은 **웹 요청 안에서** 실행되므로, `glob` 이 막힌 호스팅에서는
     * 이 자리가 치명 오류가 된다(PHP 8 은 차단 함수를 함수 테이블에서 제거한다).
     * 나열은 형제 지점들과 같은 단일 지점을 쓴다.
     *
     * @param  string  $directory  나열할 디렉토리 절대 경로
     * @param  string  $prefix  항목명 시작 (빈 문자열이면 전부)
     * @param  int  $cutoff  이 시각(Unix time) 이전 mtime 만 삭제
     * @param  bool  $dryRun  삭제 없이 목록만
     * @return list<string> 삭제(예정) 경로 목록
     */
    private function pruneEntriesByAge(string $directory, string $prefix, int $cutoff, bool $dryRun): array
    {
        $pruned = [];

        $entries = array_merge(
            ExtensionManager::listDirectories($directory, $prefix),
            ExtensionManager::listDirectoryFiles($directory, '', $prefix)
        );

        foreach ($entries as $entry) {
            if ($this->isFresh($entry, $cutoff)) {
                continue;
            }

            $pruned[] = $entry;

            if (! $dryRun) {
                is_dir($entry) ? File::deleteDirectory($entry) : File::delete($entry);
            }
        }

        return $pruned;
    }

    /**
     * 항목의 mtime 이 보존일 이내인지 판정합니다 (mtime 조회 실패 시 보존 — fail-safe).
     *
     * @param  string  $path  대상 경로
     * @param  int  $cutoff  기준 시각 (Unix time)
     * @return bool 보존일 이내(삭제 금지)면 true
     */
    private function isFresh(string $path, int $cutoff): bool
    {
        $mtime = @filemtime($path);

        return $mtime === false || $mtime > $cutoff;
    }

    /**
     * 프로젝트 루트 기준 경로를 만듭니다.
     *
     * @param  string  $path  루트 기준 상대 경로
     * @return string 절대 경로
     */
    private function basePath(string $path): string
    {
        return ($this->baseRoot ?? base_path()).DIRECTORY_SEPARATOR.$path;
    }

    /**
     * 스토리지 루트 기준 경로를 만듭니다.
     *
     * @param  string  $path  스토리지 루트 기준 상대 경로
     * @return string 절대 경로
     */
    private function storagePath(string $path): string
    {
        return ($this->storageRoot ?? storage_path()).DIRECTORY_SEPARATOR.$path;
    }

    /**
     * 웹 트리(`public/`) 루트 경로를 돌려줍니다.
     *
     * @return string 절대 경로
     */
    private function publicPath(): string
    {
        return $this->publicRoot ?? public_path();
    }
}
