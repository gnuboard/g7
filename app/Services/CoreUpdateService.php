<?php

namespace App\Services;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Exceptions\CoreUpdateOperationException;
use App\Exceptions\PackageIntegrityException;
use App\Exceptions\UpgradeHandoffException;
use App\Extension\AbstractUpgradeStep;
use App\Extension\CoreVersionChecker;
use App\Extension\Custom\CustomUpdatePreflight;
use App\Extension\Exceptions\UpgradeStepClassMissingException;
use App\Extension\Helpers\AbstractRootMarkerFileUpdater;
use App\Extension\Helpers\BackupDirectoryName;
use App\Extension\Helpers\ChangelogParser;
use App\Extension\Helpers\CoreBackupHelper;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\Helpers\ExtensionRoleSyncHelper;
use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Helpers\GithubHelper;
use App\Extension\Helpers\RootIndexShim;
use App\Extension\Helpers\SafeArchiveExtractor;
use App\Extension\Helpers\UpdateReservedPaths;
use App\Extension\Integrity\AcquiredPackage;
use App\Extension\Integrity\PackageAcquirer;
use App\Extension\Integrity\PackageExpectation;
use App\Extension\Integrity\PackageIntegrityOptions;
use App\Extension\Integrity\PackageReleaseNotFoundException;
use App\Extension\Integrity\PackageVerification;
use App\Extension\Integrity\PackageVerificationStatus;
use App\Extension\Integrity\UnsignedPackagePolicy;
use App\Extension\Support\LifecycleWarnings;
use App\Extension\Upgrade\UpgradeStepRunLedger;
use App\Extension\UpgradeContext;
use App\Extension\Vendor\EnvironmentDetector;
use App\Extension\Vendor\VendorInstallContext;
use App\Extension\Vendor\VendorInstallResult;
use App\Extension\Vendor\VendorMode;
use App\Extension\Vendor\VendorResolver;
use App\Support\CoreVersionDiskReader;
use App\Support\ExecutionEnvironment;
use App\Support\PackageManifestCacheHelper;
use App\Support\RuntimeRole;
use App\Support\ScopedPermissionEnforcement;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Database\Seeders\CoreScheduleSeeder;
use Database\Seeders\IdentityPolicySeeder;
use Database\Seeders\NotificationDefinitionSeeder;
use Dotenv\Dotenv;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CoreUpdateService
{
    /**
     * 코어 업데이트 리포트 로그의 이름 접두사 (`storage/logs/` 직하).
     *
     * 이 파일들은 daily 로테이션 **밖**이라 프레임워크가 걷지 않고, 업데이트마다 하나씩
     * 영구히 쌓인다. 만드는 쪽(이 서비스 · `CoreUpdateCommand`)과 지우는 쪽
     * (`StorageLeftoverPruneService`)이 같은 값을 보게 한다 — 리터럴 사본으로 갈리면
     * 그 상태는 오류가 아니라 **모집단이 조용히 비는 것**으로만 나타난다.
     */
    public const UPDATE_REPORT_PREFIX = 'core_update_';

    /**
     * 운영자 파비콘 보존 대상 경로 (base 상대, #434)
     */
    private const PUBLIC_FAVICON_PATH = 'public/favicon.ico';

    /**
     * 리포트 이름을 판정하는 정규식 (캡처 1 = 시각 부분).
     *
     * 상태 어휘는 세 가지다 — 성공(`success`) · 실패(`failed`, 커맨드) ·
     * 실패 리포트(`failure`, 이 서비스).
     */
    public const UPDATE_REPORT_NAME_PATTERN = '/^'.self::UPDATE_REPORT_PREFIX.'(?:success|failed|failure)_(\d{8}_\d{6})\.log$/';

    /**
     * `_pending` 전개 디렉토리의 이름 접두사 (`{pending_path}/core_{Ymd_His}/`).
     *
     * 정리 주체는 `cleanupPending()` 하나였고 그것은 정상 경로와 **처리된** 실패 경로에서만
     * 불린다 — 치명 오류·타임아웃·kill(무CLI 웹 워커에서 특히 흔하다)이면 그 코드가 돌지
     * 않아 릴리즈 한 벌 크기의 디렉토리가 영구히 남는다. 회수는 `StorageLeftoverPruneService`
     * 의 `core_pending_staging` 대상군이 맡고, 이름 규약의 일치는 계약 테스트가 이 상수를
     * 읽어 잠근다 (#609 G6 — 회차 6 이관).
     */
    public const PENDING_DIR_PREFIX = 'core_';

    /**
     * 코어 업데이트 리포트 로그의 절대 경로를 만듭니다 (생산자 단일 지점).
     *
     * @param  string  $status  상태 어휘 (`success` | `failed` | `failure`)
     * @return string `storage/logs/core_update_{status}_{Ymd_His}.log`
     */
    public static function updateReportPath(string $status): string
    {
        // audit:allow datetime-display-user-timezone reason: 서버 로그·리포트 파일 — UTC 기록
        return storage_path('logs/'.self::UPDATE_REPORT_PREFIX.$status.'_'.date('Ymd_His').'.log');
    }

    /**
     * 마지막 `restoreOwnership()` 실행에서 누적된 권한 정상화 경고.
     *
     * 각 항목은 `['target' => string, 'kind' => 'chown'|'group_writable', 'failed' => int, 'failed_paths' => string[]]`.
     * 콘솔/로그가 운영자에게 즉시 노출하기 위한 side-channel — 호출 후 `getLastPermissionWarnings()` 로 조회.
     *
     * @var array<int, array{target:string, kind:string, failed:int, failed_paths:array<int,string>}>
     */
    protected array $lastPermissionWarnings = [];

    /** 실행 잠금 파일 핸들 (획득 상태에서만 리소스). */
    private static $runLockHandle = null;

    /**
     * 실행 잠금 보유 깊이.
     *
     * 재진입을 허용하면서 **안쪽 해제가 바깥 보유자의 잠금을 푸는** 것을 막는다.
     * 그 형태가 실재한다 — 웹 오케스트레이터가 잠금을 잡은 뒤 같은 프로세스에서
     * `Artisan::call('core:update')` 를 부르면, 커맨드의 `finally` 가 오케스트레이터의
     * 잠금을 풀고 이후 백업 삭제·롤백 구간이 **무잠금으로** 돈다.
     */
    private static int $runLockDepth = 0;

    /**
     * 실행 잠금을 **쓸 수 없어** 보호 없이 진행한 사유 (호출부가 표면화한다).
     *
     * 두 폴백 경로는 `true` 를 돌려주므로 호출부가 실패와 구분할 수 없고, 종전에는
     * 사유가 로그 파일에만 남았다 — 운영자 화면에는 아무 표시가 없어, 두 창에서 동시에
     * 업데이트를 눌러 백업이 서로 파괴되는 상태(잠금을 넣은 바로 그 사유)가 무경고로
     * 재현된다. 잠금이 걸린 경우(`already_running`)만 다국어 문구가 있었고 이 축은
     * 문구조차 없었다.
     *
     * @var array<int, string>
     */
    private static array $runLockWarnings = [];

    /**
     * 잠금이 「죽은 실행이 남긴 것」으로 판정되는 나이 (초).
     *
     * 코어 업데이트는 내려받기·압축 해제·마이그레이션을 포함해 길어질 수 있으므로
     * 넉넉히 잡는다. 이보다 오래된 잠금 파일은 프로세스가 비정상 종료하며 남긴 것으로
     * 보고 넘겨받는다 — `flock` 은 프로세스가 죽으면 OS 가 풀어 주므로 이 나이 판정은
     * 파일 자체가 남는 경우의 정리용이다.
     */
    private const RUN_LOCK_STALE_SECONDS = 3600;

    /**
     * spawn 실패 시 처리 방식 — `app.update.spawn_failure_mode` 하나만 읽는다(코드 쪽 폴백값을 따로 두지 않는다).
     *
     * 종전에는 config 기본값은 `abort` 인데 코드 두 곳의 폴백은 `fallback` 이라, config 캐시가 그 키를 모르는 판에서는
     * 같은 설치가 다른 방식으로 움직였다. 값이 없거나 모르는 값이면 config 기본값과 같은 `abort` 다.
     *
     * @return string `abort` 또는 `fallback`
     */
    public static function spawnFailureMode(): string
    {
        $mode = config('app.update.spawn_failure_mode');

        return in_array($mode, ['abort', 'fallback'], true) ? $mode : 'abort';
    }

    /**
     * 업그레이드 스텝 수동 재개 명령 (POSIX 셸).
     *
     * 새 프로세스의 단독 실행은 낡은 메모리 가드에 걸리지 않지만, 옛 판 config 캐시로 부팅하는 환경을 위해
     * `APP_VERSION=<대상 판>` 접두를 붙인다.
     *
     * @param  string  $fromVersion  시작 판
     * @param  string  $toVersion  대상 판
     * @return string 재개 명령
     */
    public static function resumeCommand(string $fromVersion, string $toVersion): string
    {
        return sprintf('APP_VERSION=%s php artisan core:execute-upgrade-steps --from=%s --to=%s --force', $toVersion, $fromVersion, $toVersion);
    }

    /**
     * 업그레이드 스텝 수동 재개 안내 — POSIX 셸과 PowerShell 두 형식.
     *
     * @param  string  $fromVersion  시작 판
     * @param  string  $toVersion  대상 판
     * @return string 안내 문장
     */
    public static function resumeCommandForShells(string $fromVersion, string $toVersion): string
    {
        return sprintf(
            '%s (PowerShell: $env:APP_VERSION=\'%s\'; php artisan core:execute-upgrade-steps --from=%s --to=%s --force)',
            self::resumeCommand($fromVersion, $toVersion),
            $toVersion,
            $fromVersion,
            $toVersion,
        );
    }

    /**
     * 코어 업데이트 실행을 직렬화합니다.
     *
     * 종전에는 이 흐름 전체에 어떤 잠금도 없었다. 그래서 두 실행이 겹치면 서로의 백업을
     * 파괴한다 — 한쪽이 이미 신 버전으로 바꾼 디스크를 다른 쪽이 「업데이트 이전 스냅샷」
     * 이라며 백업하고, 먼저 끝난 쪽이 성공하면 아직 쓰고 있는 백업을 통째로 지운다.
     * 그 뒤 남은 쪽이 실패하면 **되돌릴 것이 없다.** 경로 충돌은 난수 접미사로 닫았지만
     * 그것은 「서로 다른 디렉토리를 쓴다」까지이고, 같은 디스크를 동시에 바꾸는 것 자체는
     * 잠금만이 막는다 (제품 결정 2026-08-26).
     *
     * 실패해도 예외를 던지지 않는다 — 잠금 파일을 만들 수 없는 환경(읽기 전용 storage)에서
     * 업데이트 자체를 막으면 이 안이 대상으로 삼은 제한 호스팅이 더 나빠진다. 그 경우는
     * 종전과 같은 무잠금 동작으로 진행하고 사실만 남긴다.
     *
     * @return bool 잠금을 얻었거나 잠금을 쓸 수 없으면 true, **다른 실행이 진행 중**이면 false
     */
    public function acquireRunLock(): bool
    {
        if (self::$runLockHandle !== null) {
            // 같은 프로세스가 이미 보유 — 재진입은 허용하되 **깊이를 센다.**
            // 세지 않으면 안쪽 호출의 해제가 바깥 보유자의 잠금을 푼다.
            self::$runLockDepth++;

            return true;
        }

        $path = storage_path('app/core-update.lock');

        try {
            File::ensureDirectoryExists(dirname($path));
        } catch (\Throwable $e) {
            self::$runLockWarnings[] = (string) __('settings.core_update.run_lock_unavailable', [
                'reason' => $e->getMessage(),
            ]);

            return true; // 잠금을 쓸 수 없는 환경 — 종전 동작
        }

        // 오래 남은 잠금 파일은 비정상 종료의 잔해다. `flock` 자체는 OS 가 풀어 주므로
        // 아래 획득이 성공하며, 여기서는 나이만 기록해 진단에 남긴다.
        //
        // 수정 시각을 **읽지 못한 것**은 「오래됨」이 아니다. 종전에는 `(int) @filemtime()` 이라
        // 조회 실패(`false`)가 0 — 1970년 — 으로 접혀 `time() - 0` 이 언제나 상한을 넘었고,
        // 방금 만든 잠금 파일이 「이전 실행이 비정상 종료했을 수 있다」는 경고를 남겼다.
        // 잘못된 경고는 운영자가 진짜 잔해를 볼 때 무시하게 만든다. 조회 실패는 판정
        // 불가로 두고 나이 판정을 건너뛴다.
        if (is_file($path)) {
            $modifiedAt = @filemtime($path);

            if ($modifiedAt === false) {
                Log::info('코어 업데이트 잠금 파일의 수정 시각을 확인할 수 없어 잔류 여부를 판정하지 않습니다', [
                    'path' => $path,
                ]);
            } elseif ((time() - $modifiedAt) > self::RUN_LOCK_STALE_SECONDS) {
                Log::warning('코어 업데이트 잠금 파일이 오래 남아 있습니다 — 이전 실행이 비정상 종료했을 수 있습니다', [
                    'path' => $path,
                ]);
            }
        }

        $handle = @fopen($path, 'c');

        if ($handle === false) {
            Log::warning('코어 업데이트 잠금 파일을 열지 못했습니다 — 동시 실행 보호 없이 진행합니다', [
                'path' => $path,
            ]);

            // `:reason` 은 **사유**가 들어가는 자리다. 경로를 넣으면 세 언어 모두
            // 「보호를 켤 수 없어 그대로 진행합니다 (…/core-update.lock)」가 되어 문장이
            // 성립하지 않고, 운영자가 원인(권한·디스크·open_basedir)을 알 수 없다.
            $reason = error_get_last()['message'] ?? '';

            self::$runLockWarnings[] = (string) __('settings.core_update.run_lock_unavailable', [
                'reason' => $reason !== ''
                    ? $reason
                    : (string) __('settings.core_update.run_lock_unknown_reason', ['path' => $path]),
            ]);

            return true;
        }

        if (! @flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        self::$runLockHandle = $handle;
        self::$runLockDepth = 1;
        @ftruncate($handle, 0);
        @fwrite($handle, (string) time());
        @fflush($handle);

        return true;
    }

    /**
     * 잠금 없이 진행하게 된 사유를 꺼내고 버퍼를 비웁니다.
     *
     * @return array<int, string> 운영자 안내 문구 (없으면 빈 배열)
     */
    public static function takeRunLockWarnings(): array
    {
        $warnings = self::$runLockWarnings;
        self::$runLockWarnings = [];

        return $warnings;
    }

    /**
     * 실행 잠금을 놓습니다.
     *
     * @return void 반환값 없음
     */
    public function releaseRunLock(): void
    {
        if (self::$runLockHandle === null) {
            return;
        }

        // 실제로 얻은 쪽만 푼다 — 안쪽 호출의 해제가 바깥 보유자의 잠금을 풀면
        // 이후 구간이 무잠금으로 돌아 잠금이 지키려던 것이 그대로 재현된다.
        if (self::$runLockDepth > 1) {
            self::$runLockDepth--;

            return;
        }

        @flock(self::$runLockHandle, LOCK_UN);
        @fclose(self::$runLockHandle);
        self::$runLockHandle = null;
        self::$runLockDepth = 0;
    }

    /**
     * 실행 잠금 보유 깊이를 돌려줍니다 (테스트·진단 전용).
     *
     * @return int 보유 깊이 (0 이면 미보유)
     */
    public static function runLockDepth(): int
    {
        return self::$runLockDepth;
    }

    /**
     * 마지막 restoreOwnership 호출에서 발생한 권한 정상화 경고를 조회합니다.
     *
     * @return array<int, array{target:string, kind:string, failed:int, failed_paths:array<int,string>}>
     */
    public function getLastPermissionWarnings(): array
    {
        return $this->lastPermissionWarnings;
    }

    /**
     * 마지막 파일 적용에서 루트 진입·보호 파일 갱신이 실패한 사유.
     *
     * 루트 `.htaccess` 는 다른 코어 파일과 달리 블록 교체 규칙으로 갱신되고, 그 갱신은
     * 실패할 수 있다(그 파일만 읽기 전용인 호스팅 · 중복 블록 · 릴리즈 산출물 결함).
     * 실패해도 업데이트 자체는 계속되므로 — 다른 파일은 이미 신 버전이라 여기서 세우면
     * 중간 상태가 남는다 — 사유가 운영자에게 **도달**해야 한다. 종전에는 갱신기가 실패를
     * 판정하고도 그 값을 두 호출부가 모두 버려, 화면에는 「완료」만 뜨고 보호 규칙은
     * 구버전으로 남았다. 권한 경고와 같은 side-channel 규약이다.
     *
     * @var array<int, string>
     */
    protected array $lastRootFileWarnings = [];

    /**
     * 코어가 마지막으로 쓴 `deploy/**` 조각의 해시 원장 파일 이름 (`storage/app/core-update/` 아래).
     */
    public const DEPLOY_FRAGMENT_LEDGER = 'deploy-fragments.json';

    /**
     * 운영자 수정본을 보존할 때 새 판본을 옆에 두는 이름의 접미사 (`g7-layout2.conf.dist`).
     *
     * `dist` 는 네 서버 조각의 확장자 차단 목록에 있고 `deploy/` 자체도 차단 디렉토리다 —
     * 이 사본은 웹에서 열리지 않는다.
     */
    public const DEPLOY_FRAGMENT_DIST_SUFFIX = '.dist';

    /**
     * 옛 `.dist` 가 남아 있고 그 내용이 새 판본과 다를 때, 새 판본을 두는 이름의 접미사 (`….dist.new`).
     *
     * `.dist` 를 덮어쓰면 운영자가 그 파일에 합치던 중간 작업이 사라지고, 새 판본을 버리면 최신 보호
     * 규칙이 운영자 손에 닿지 않는다 — 둘 다 남긴다. 이 파일은 코어가 만든 것이라 다음 업데이트가
     * 자유롭게 갱신한다. `deploy/` 디렉토리 자체가 네 서버 조각의 차단 목록에 있어 웹에서 열리지 않는다
     * (G3 회차 16 W2 A5).
     */
    public const DEPLOY_FRAGMENT_DIST_NEW_SUFFIX = '.dist.new';

    /**
     * 마지막 파일 적용에서 누적된 루트 진입·보호 파일 경고를 조회합니다.
     *
     * @return array<int, string> 운영자 안내 문구 목록 (없으면 빈 배열)
     */
    public function getLastRootFileWarnings(): array
    {
        return $this->lastRootFileWarnings;
    }

    /**
     * 루트 마커 파일(`.htaccess`·`web.config`) 갱신 결과를 판정해 실패면 경고로 누적합니다.
     *
     * @param  class-string<AbstractRootMarkerFileUpdater>  $updater  그 파일의 갱신기
     * @param  string  $result  `$updater::apply()` 반환값
     * @param  string  $destinationPath  대상 파일 경로
     * @return void 반환값 없음
     */
    protected function recordRootMarkerFileResult(string $updater, string $result, string $destinationPath): void
    {
        // 실패가 아닌 결과도 하나는 알려야 한다 — `preserved`(마커 없는 파일이라 손대지
        // 않음)는 코어 보호 규칙이 **미적용으로 남았다**는 뜻이라, 조용히 넘기면 문서 루트가
        // 무방비인 사이트가 「적용 완료」만 보고 운영된다. 동작은 그대로 두고 사실만 싣는다.
        $message = $updater::isFailure($result)
            ? $updater::failureMessage($result, $destinationPath)
            : $updater::noticeMessage($result, $destinationPath);

        if ($message !== null) {
            $this->lastRootFileWarnings[] = $message;
        }
    }

    /**
     * 루트 진입·보호 파일을 복사하되, 실패를 **예외가 아니라 경고**로 다룹니다.
     *
     * 형제 파일 `.htaccess` 는 같은 계약을 갖는다 — 쓰기 실패를 예외로
     * 터뜨리지 않고 결과 코드로 잡아 경고로 표시하고 업데이트는 계속한다(제품 결정).
     * 그런데 `index.php`·`web.config` 는 그 계약 밖에서 `FilePermissionHelper::copyFile()`
     * 로 곧장 떨어졌고, 그 헬퍼는 `void` 라 `File::copy()` 의 실패를 판정하지 않는다.
     *
     * 배포 대상 순서(`config/app.php` 의 `update.targets`)는 `index.php` 가 `.htaccess`
     * **앞**이다. 그래서 FTP 로 푼 사이트처럼 루트 파일 소유자가 웹 프로세스와 다른
     * 환경에서는 `index.php` 복사가 `ErrorException` 으로 승격되며 그 자리에서 중단되고,
     * `.htaccess` 는 평가될 기회조차 없다 — 형제가 만든 통보 통로가 **그 호스팅에서만
     * 한 번도 열리지 않는다.** 이 안이 대상으로 삼은 바로 그 호스팅이다.
     *
     * 실패해도 나머지 대상은 계속 적용한다 — 루트 파일 하나가 구버전으로 남는 것과
     * 업데이트 전체가 중단되고 롤백되는 것은 위험 등급이 다르다.
     *
     * @param  string  $target  정규화된 대상 이름 (`index.php` 등)
     * @param  string  $source  소스 파일
     * @param  string  $destination  대상 파일
     * @return void 반환값 없음
     */
    protected function copyRootFileOrWarn(string $target, string $source, string $destination): void
    {
        try {
            FilePermissionHelper::copyFile($source, $destination);

            // 예외 없이 돌아와도 내용이 도달했는지는 별개다. `copyFile()` 은 `void` 이고
            // 그 안의 `File::copy()` 가 돌려주는 `false` 를 삼키므로, 쓰기가 통째로
            // 실패해도(FTP 로 푼 사이트처럼 루트 파일 소유자가 웹 프로세스와 다른 환경)
            // 예외 없이 돌아온다. 디스크가 차서 부분 쓰기로 끝나는 경로도 있다.
            // 형제 `writeAtomically()` 가 길이를 보는 것과 같은 판정을 여기서도 한다.
            $expected = @filesize($source);
            $actual = is_file($destination) ? @filesize($destination) : false;

            if ($expected !== false && $actual !== false && $expected === $actual) {
                return;
            }

            // 여기까지 왔으면 「도달했다」고 말할 수 없다. 종전에는 이 자리에서 대상 파일이
            // **없을 때만** 통보했는데, 실제로 가장 흔한 실패는 그 반대다 — 덮어쓰지 못해
            // **구버전 파일이 그대로 남는** 경우다. 그때 `is_file()` 은 참이라 통보가
            // 통째로 빠져, 길이를 재고도 그 결과를 버린 셈이었다(길이 대조를 지워도
            // 관측 동작이 같아 어떤 검사도 그것을 보지 못했다). 크기를 읽지 못한 경우도
            // 확인 불가이므로 같은 등급으로 다룬다 — 형제 갱신기가 읽기 실패를 실패로
            // 분류하는 것과 같은 규율이다.
            $this->lastRootFileWarnings[] = (string) __('settings.core_update.root_file_copy_failed', [
                'path' => $destination,
            ]);
        } catch (\Throwable $e) {
            Log::warning('루트 진입·보호 파일을 갱신하지 못했습니다', [
                'target' => $target,
                'destination' => $destination,
                'error' => $e->getMessage(),
            ]);

            $this->lastRootFileWarnings[] = (string) __('settings.core_update.root_file_copy_failed', [
                'path' => $destination,
            ]);
        }
    }

    /**
     * 루트 `index.php` 를 코어 shim 으로 **교체하기 직전**에 그 사실을 통보합니다.
     *
     * 형제 파일(`.htaccess`)은 마커 블록 규칙과 `RESULT_*` 통보 통로를 갖는데 이 파일은
     * 둘 다 없어 통째 복사로 끝난다. 계획서 §4 B-2 가 「통째로 교체된다」고 정한 대상은
     * **코어가 놓아 둔 shim** 이고, 그 판단은 「운영자가 이 파일을 수정하지 않는다」는
     * 전제 위에 있다. 전제가 성립하지 않는 경우가 하나 있다 — 레이아웃 ③ 사이트가
     * 프로젝트 루트에 두던 자기 랜딩·리다이렉트 `index.php` 다. 그 파일은 첫 업데이트에서
     * 말없이 사라지고, 성공 시 백업까지 지워지면 되돌릴 수단이 없다.
     *
     * 교체는 그대로 하되, **되찾을 수단을 함께 남긴다.** 종전 문구는 「그 내용은 백업에만
     * 남습니다」/「백업에서 확인하세요」였는데, 성공 경로는 이 통보 직후 백업을 무조건
     * 지운다(`CoreUpdateCommand` 의 `deleteBackup()` — 보존 옵션이 없다). 그래서 그 안내는
     * 실행할 수 없는 안내였다. 교체 직전에 **그 파일 하나만** 업데이트 흐름이 건드리지
     * 않는 위치로 사본해 두고, 문구가 그 경로를 싣는다(백업 전체 보존으로 넓히지 않는다).
     *
     * @param  string  $destinationPath  대상 파일 경로
     * @return void 반환값 없음
     */
    protected function recordRootIndexReplacement(string $destinationPath): void
    {
        if (! is_file($destinationPath)) {
            return; // 신규 생성 — 덮어쓸 것이 없다.
        }

        $existing = @file_get_contents($destinationPath);

        if ($existing === false) {
            $this->lastRootFileWarnings[] = (string) __('settings.core_update.root_index_unreadable', [
                'path' => $destinationPath,
            ]);

            return;
        }

        // 코어 shim 의 식별자 — shim 은 `public/index.php` 로 위임하는 한 줄이 전부다.
        // 판정은 `RootIndexShim` 이 소유한다(계약 테스트도 같은 지점을 쓴다). 종전에는
        // 여기 공백까지 고정한 리터럴이 박혀 있어, PSR-12 스타일로 띄어 쓴 shim 이
        // 「운영자 파일」로 오판되어 매 업데이트마다 거짓 경고 + 쓸모없는 사본이 쌓였다.
        if (RootIndexShim::matches($existing)) {
            return;
        }

        $preservedPath = $this->preserveReplacedRootIndex($existing);

        $this->lastRootFileWarnings[] = $preservedPath !== null
            ? (string) __('settings.core_update.root_index_replaced', [
                'path' => $destinationPath,
                'copy' => $preservedPath,
            ])
            : (string) __('settings.core_update.root_index_replaced_not_preserved', [
                'path' => $destinationPath,
            ]);
    }

    /**
     * 교체 직전의 루트 진입 파일 내용을 업데이트 흐름 밖에 사본으로 남깁니다.
     *
     * 위치는 `storage/app/core-update/<타임스탬프>/index.php` 다. `storage` 는
     * `update.protected_paths` 이자 `update.targets` 밖이라 적용·자동 발견 폴백·롤백
     * prune 어느 쪽도 건드리지 않는다 — 성공 시 지워지는 백업(`core_backups`)과 다른 점이
     * 이것이다. 같은 초에 두 번 도달해도 앞선 사본을 덮지 않는다.
     *
     * 사본을 남기지 못하는 것은 업데이트를 세울 사유가 아니다(교체는 계속한다) — 대신
     * 호출부가 「남겼다」고 말하지 않도록 `null` 을 돌려준다.
     *
     * @param  string  $contents  교체 직전 파일 내용
     * @return string|null 사본 절대 경로 (남기지 못했으면 null)
     */
    protected function preserveReplacedRootIndex(string $contents): ?string
    {
        try {
            // 이름은 백업 이름 SSoT 가 만든다 — 정리자(`StorageLeftoverPruneService`)의 판정
            // 정규식도 같은 곳을 보므로 한쪽만 바뀌는 일이 없다. 같은 초 충돌 접미사도 거기서 온다.
            $directory = storage_path('app'.DIRECTORY_SEPARATOR.'core-update'.DIRECTORY_SEPARATOR.BackupDirectoryName::stamp());
            File::ensureDirectoryExists($directory);

            $path = $directory.DIRECTORY_SEPARATOR.'index.php';
            $suffix = 1;
            while (file_exists($path)) {
                $path = $directory.DIRECTORY_SEPARATOR.'index.'.$suffix.'.php';
                $suffix++;
            }

            // 부분 쓰기도 실패로 본다. `file_put_contents` 는 디스크가 차면 `false` 가
            // 아니라 **쓴 바이트 수**를 돌려주므로, `=== false` 만 보면 잘린 사본이 성공으로
            // 통과한다. 그러면 「여기 사본이 있습니다」라는 안내가 나가는데 그 사본으로는
            // 되찾을 수 없다 — 이 자리는 **유일한 복구 수단**이라 「남기지 못했다」로
            // 말하는 편이 낫다. 형제 두 지점(`AbstractRootMarkerFileUpdater::writeAtomically` ·
            // `copyRootFileOrWarn`)이 이미 같은 규율로 길이를 대조한다.
            $written = File::put($path, $contents);

            if ($written === false || $written !== strlen($contents)) {
                // 잘린 사본을 그대로 두면 잔존물 정리기가 그것을 백업으로 보고 **최신 1개**
                // 자리를 차지하게 한다 — 되돌릴 수 없는 파일이 되돌릴 수단 자리를 막는다.
                @unlink($path);
                // 비었을 때만 지워진다 — 같은 초의 앞선 성공 사본은 남긴다.
                @rmdir($directory);

                return null;
            }

            return $path;
        } catch (\Throwable $e) {
            Log::channel('upgrade')->warning('[core-update] 교체 직전 루트 index.php 사본 생성 실패', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 쓰기 권한이 필요한 디렉토리를 멱등적으로 보장합니다.
     *
     * 코어 업그레이드 spawn 자식이 fresh 코드/config 환경에서 진입 직후 1회 호출하여
     * 활성 디렉토리의 ownership / 그룹 쓰기 권한을 정합 상태로 강제. 미래 release 가
     * 새 쓰기 권한 디렉토리를 도입할 때 본 메서드 호출만으로 자동 처리되며, 해당 release
     * 의 upgrade step 에 mkdir/chown 코드를 하드코딩할 필요 없음.
     *
     * 처리:
     * - 디렉토리 부재 시 modules/ → plugins/ → templates/ → base_path() stat 기준으로 mkdir
     * - chown by extension reference owner/group (chownRecursiveDetailed)
     * - 강제 g+w 모드 (syncGroupWritabilityDetailed force=true) — sudo 결함으로 root 가
     *   0755 로 생성한 케이스에서 silent no-op 되던 결함 차단
     *
     * 한계: spawn 자식이 fresh 디스크 config 를 읽는 시점에만 작동. 부모(이전 버전) 만
     * 알고 있던 신규 디렉토리는 처리 못 함 — 그런 일회성 케이스는 해당 release 의 upgrade
     * step 이 단발 처리 (예: beta.3→beta.4 의 lang-packs/* 보정).
     *
     * @param  array<int, string>  $relativePaths  base_path() 기준 상대 경로 배열
     * @param  callable|object|null  $logger  Monolog 호환 logger ($logger->info(...)) 또는 callable($level, $message)
     * @return array{
     *     reference: array{owner:int|false, group:int|false, perms:int|null, source:string},
     *     processed: array<int, array{path:string, mkdir:bool, chown_changed:int, chown_failed:int, gw_changed:int, gw_failed:int}>,
     *     warnings: array<int, string>,
     * }
     */
    public function ensureWritableDirectories(array $relativePaths, $logger = null): array
    {
        $reference = $this->resolveExtensionDirReferenceStat();
        $owner = $reference['owner'];
        $group = $reference['group'];
        $perms = $reference['perms'] ?? 0775;

        if ($owner === false) {
            [$owner, $group, $_] = FilePermissionHelper::inferWebServerOwnership();
        }

        $log = function (string $level, string $message) use ($logger): void {
            if ($logger === null) {
                Log::channel('upgrade')->$level($message);

                return;
            }
            if (is_callable($logger)) {
                $logger($level, $message);

                return;
            }
            if (is_object($logger) && method_exists($logger, $level)) {
                $logger->$level($message);

                return;
            }
            Log::channel('upgrade')->$level($message);
        };

        $log('info', sprintf(
            '[ensureWritableDirectories] 기준 — owner=%s group=%s perms=%s source=%s',
            $owner === false ? 'unresolved' : (string) $owner,
            $group === false ? 'unresolved' : (string) $group,
            sprintf('0%o', $perms),
            $reference['source'],
        ));

        $processed = [];
        $warnings = [];

        foreach ($relativePaths as $relative) {
            $relative = trim((string) $relative);
            if ($relative === '') {
                continue;
            }
            $path = base_path($relative);
            $entry = ['path' => $relative, 'mkdir' => false, 'chown_changed' => 0, 'chown_failed' => 0, 'gw_changed' => 0, 'gw_failed' => 0];

            if (! File::isDirectory($path)) {
                if (! @mkdir($path, $perms, true) && ! File::isDirectory($path)) {
                    $msg = sprintf('[ensureWritableDirectories] mkdir 실패 — %s', $relative);
                    $log('warning', $msg);
                    $warnings[] = $msg;
                    $processed[] = $entry;

                    continue;
                }
                @chmod($path, $perms);
                $entry['mkdir'] = true;
                $log('info', sprintf('[ensureWritableDirectories] mkdir + perms 0%o — %s', $perms, $relative));
            }

            if ($owner !== false) {
                $report = FilePermissionHelper::chownRecursiveDetailed($path, $owner, $group);
                $entry['chown_changed'] = $report['changed'];
                $entry['chown_failed'] = $report['failed'];
                if ($report['failed'] > 0) {
                    $msg = sprintf(
                        '[ensureWritableDirectories] chown 실패 %d 건 (%s) — 첫 실패: %s. 수동: sudo chown -R %d:%d %s',
                        $report['failed'],
                        $relative,
                        $report['failed_paths'][0] ?? '?',
                        $owner,
                        (int) ($group ?: 0),
                        $path,
                    );
                    $log('warning', $msg);
                    $warnings[] = $msg;
                } elseif ($report['changed'] > 0) {
                    $log('info', sprintf('[ensureWritableDirectories] chown changed=%d — %s', $report['changed'], $relative));
                }
            }

            // perms 정상화 (루트가 g-w 등으로 생성됐을 때 reference perms 로 강제)
            $currentPerms = @fileperms($path) & 0777;
            if ($currentPerms !== false && $currentPerms !== $perms) {
                if (! @chmod($path, $perms)) {
                    $msg = sprintf('[ensureWritableDirectories] chmod 실패 — %s. 수동: sudo chmod %o %s', $relative, $perms, $path);
                    $log('warning', $msg);
                    $warnings[] = $msg;
                } else {
                    $log('info', sprintf('[ensureWritableDirectories] perms 보정 %o → %o — %s', $currentPerms, $perms, $relative));
                }
            }

            // force=true: 루트 g-w 정책 보존 우회 (sudo 결함 보정)
            $gwReport = FilePermissionHelper::syncGroupWritabilityDetailed($path, true);
            $entry['gw_changed'] = $gwReport['changed'];
            $entry['gw_failed'] = $gwReport['failed'];
            if ($gwReport['failed'] > 0) {
                $msg = sprintf('[ensureWritableDirectories] g+w 실패 %d 건 — %s', $gwReport['failed'], $relative);
                $log('warning', $msg);
                $warnings[] = $msg;
            } elseif ($gwReport['changed'] > 0) {
                $log('info', sprintf('[ensureWritableDirectories] g+w changed=%d — %s', $gwReport['changed'], $relative));
            }

            $processed[] = $entry;
        }

        return ['reference' => $reference, 'processed' => $processed, 'warnings' => $warnings];
    }

    /**
     * 확장 디렉토리(modules/ → plugins/ → templates/ → base_path()) 의 stat 을 권한 기준으로 반환합니다.
     *
     * @return array{owner:int|false, group:int|false, perms:int|null, source:string}
     */
    public function resolveExtensionDirReferenceStat(): array
    {
        foreach (['modules', 'plugins', 'templates'] as $dir) {
            $path = base_path($dir);
            if (! File::isDirectory($path)) {
                continue;
            }
            $stat = @stat($path);
            if (! is_array($stat)) {
                continue;
            }

            return [
                'owner' => (int) $stat['uid'],
                'group' => (int) $stat['gid'],
                'perms' => ($stat['mode'] & 0777) ?: null,
                'source' => $dir,
            ];
        }

        $stat = @stat(base_path());
        if (! is_array($stat)) {
            return ['owner' => false, 'group' => false, 'perms' => null, 'source' => 'unresolved'];
        }

        return [
            'owner' => (int) $stat['uid'],
            'group' => (int) $stat['gid'],
            'perms' => ($stat['mode'] & 0777) ?: null,
            'source' => 'base_path',
        ];
    }

    /**
     * GitHub API에서 최신 코어 릴리스를 확인합니다.
     *
     * @return array{update_available: bool, current_version: string, latest_version: string, github_url: string, check_failed?: bool, error?: string}
     */
    public function checkForUpdates(): array
    {
        $currentVersion = CoreVersionChecker::getCoreVersion();
        $githubUrl = config('app.update.github_url');
        $result = $this->fetchLatestVersionFromGithub($githubUrl);

        if ($result['error'] !== null) {
            return [
                'update_available' => false,
                'current_version' => $currentVersion,
                'latest_version' => $currentVersion,
                'github_url' => $githubUrl,
                'check_failed' => true,
                'error' => $result['error'],
            ];
        }

        $latestVersion = $result['version'];
        $updateAvailable = $latestVersion && version_compare($latestVersion, $currentVersion, '>');

        // 업데이트가 있으면 원격 CHANGELOG.md를 다운로드하여 캐시
        if ($updateAvailable) {
            $this->cacheRemoteChangelog($githubUrl, $latestVersion);
        }

        return [
            'update_available' => $updateAvailable,
            'current_version' => $currentVersion,
            'latest_version' => $latestVersion ?? $currentVersion,
            'github_url' => $githubUrl,
            // 최신 릴리스에 서명 자산이 있는지 (7.2.0, 모르면 null) — 없으면 업데이트가 「미서명」 확인을 요구한다
            'signature_available' => $result['signature_available'] ?? null,
        ];
    }

    /**
     * 직전 업데이트 시도가 개발자 custom 자리 사전 검사로 멈춘 기록을 돌려줍니다 (#792).
     *
     * 업데이트는 CLI 가 하므로 결과는 기록으로 전달된다. 관리자 확인 창은 지금 확인한 새 버전과 같은 버전의
     * 기록만 보여 준다 — 다른 버전을 시도하다 멈춘 기록은 지금 업데이트와 무관하다.
     *
     * @param  string|null  $latestVersion  확인한 새 코어 버전
     * @return array{to_version:string, checked_at:string, reserved_files:array<int,string>, conflicts:array<int,array<string,string>>}|null 같은 버전의 기록, 없으면 null
     *
     * @since 7.2.0
     */
    public function pendingCustomPreflight(?string $latestVersion): ?array
    {
        if ($latestVersion === null || $latestVersion === '') {
            return null;
        }

        $record = CustomUpdatePreflight::lastRecord();

        return ($record !== null && ($record['to_version'] ?? null) === $latestVersion) ? $record : null;
    }

    /**
     * 코어 CHANGELOG.md를 파싱합니다.
     *
     * from/to 버전이 지정되면 캐시된 원격 CHANGELOG에서 범위를 추출합니다.
     * 버전 미지정 시 로컬 CHANGELOG 전체를 반환합니다.
     *
     * @param  string|null  $fromVersion  시작 버전
     * @param  string|null  $toVersion  종료 버전
     * @return array 파싱된 변경사항
     */
    public function getChangelog(?string $fromVersion = null, ?string $toVersion = null): array
    {
        // 범위 지정 시: 캐시된 원격 CHANGELOG에서 범위 필터링
        if ($fromVersion && $toVersion) {
            $cachedPath = $this->getRemoteChangelogCachePath();

            if (File::exists($cachedPath)) {
                return ChangelogParser::getVersionRange($cachedPath, $fromVersion, $toVersion);
            }

            // 캐시가 없으면 로컬 파일에서 시도 (폴백)
            $localPath = base_path('CHANGELOG.md');
            if (File::exists($localPath)) {
                return ChangelogParser::getVersionRange($localPath, $fromVersion, $toVersion);
            }

            return [];
        }

        // 범위 미지정 시: 로컬 CHANGELOG 전체
        $changelogPath = base_path('CHANGELOG.md');

        if (! File::exists($changelogPath)) {
            return [];
        }

        return ChangelogParser::parse($changelogPath);
    }

    /**
     * GitHub에서 원격 CHANGELOG.md를 다운로드하여 캐시합니다.
     *
     * @param  string  $githubUrl  GitHub 저장소 URL
     * @param  string  $version  최신 버전 (태그명)
     * @return void 반환값 없음
     */
    protected function cacheRemoteChangelog(string $githubUrl, string $version): void
    {
        try {
            if (! preg_match('#github\.com[/:]([^/]+)/([^/\.]+)#', $githubUrl, $matches)) {
                return;
            }

            $owner = $matches[1];
            $repo = $matches[2];
            $token = config('app.update.github_token', '');

            $content = GithubHelper::fetchRawFile($owner, $repo, $version, 'CHANGELOG.md', $token);

            if ($content !== null) {
                $cachePath = $this->getRemoteChangelogCachePath();
                File::ensureDirectoryExists(dirname($cachePath));
                File::put($cachePath, $content);
            }
        } catch (\Exception $e) {
            Log::channel('upgrade')->warning('원격 CHANGELOG 캐시 실패', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 원격 CHANGELOG 캐시 파일 경로를 반환합니다.
     *
     * @return string 캐시 파일 경로
     */
    protected function getRemoteChangelogCachePath(): string
    {
        return storage_path('app/temp/core_remote_changelog.md');
    }

    /**
     * 코어 업데이트에 필요한 시스템 요구사항을 검증합니다.
     *
     * @return array{valid: bool, errors: string[], available_methods: string[]}
     */
    public function checkSystemRequirements(): array
    {
        $errors = [];
        $strategies = $this->buildExtractionStrategies();
        $availableMethods = array_map(fn ($s) => $s['label'], $strategies);

        if (empty($strategies)) {
            $errors[] = __('settings.core_update.no_extract_method_available');
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'available_methods' => $availableMethods,
        ];
    }

    /**
     * GitHub에서 최신 버전을 조회합니다.
     *
     * @param  string  $githubUrl  GitHub 저장소 URL
     * @return array{version: string|null, error: string|null}
     */
    protected function fetchLatestVersionFromGithub(string $githubUrl): array
    {
        if (! $githubUrl) {
            return ['version' => null, 'error' => __('settings.core_update.github_url_not_configured')];
        }

        try {
            if (! preg_match('#github\.com[/:]([^/]+)/([^/\.]+)#', $githubUrl, $matches)) {
                return ['version' => null, 'error' => __('settings.core_update.invalid_github_url')];
            }

            $owner = $matches[1];
            $repo = $matches[2];
            $apiUrl = "https://api.github.com/repos/{$owner}/{$repo}/releases/latest";
            $token = (string) (config('app.update.github_token') ?? '');

            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'G7',
                    'Accept' => 'application/vnd.github.v3+json',
                ])
                    ->when($token !== '', fn ($r) => $r->withToken($token))
                    ->timeout(5)
                    ->connectTimeout(5)
                    ->get($apiUrl);
            } catch (ConnectionException $e) {
                Log::channel('upgrade')->warning(__('settings.core_update.log_api_call_failed'), [
                    'url' => $apiUrl,
                    'error' => $e->getMessage(),
                ]);

                return ['version' => null, 'error' => __('settings.core_update.github_api_failed')];
            }

            $statusCode = $response->status();
            $data = $response->json();
            $apiMessage = is_array($data) && isset($data['message']) ? $data['message'] : '';

            // 화면에 실을 사유는 **비어 있을 수 없다.** 아래 키들은 전부
            // `(HTTP :status — :message)` 를 싣는데, GitHub 이 비-JSON 본문을 주거나
            // `message` 를 담지 않으면 `$apiMessage` 가 빈 문자열이 되어 운영자 화면에
            // `(HTTP 401 — )` 가 뜬다. 사유를 모를 때는 비워 두지 말고 일반 문구로 채운다.
            //
            // 로그에는 **원문 그대로**(`$apiMessage`)를 남긴다 — 진단에서는 「비어 있었다」가
            // 그 자체로 사실이고, 그것을 일반 문구로 덮으면 원인 추적이 어려워진다.
            $apiReason = $apiMessage !== '' ? $apiMessage : __('settings.core_update.unknown_error');

            if ($statusCode === 401 || $statusCode === 403) {
                Log::channel('upgrade')->warning(__('settings.core_update.log_auth_failed'), [
                    'url' => $apiUrl,
                    'status' => $statusCode,
                    'has_token' => $token !== '',
                    'api_message' => $apiMessage,
                ]);

                return ['version' => null, 'error' => $token === ''
                    ? __('settings.core_update.github_token_required')
                    : __('settings.core_update.github_token_invalid', ['status' => $statusCode, 'message' => $apiReason]),
                ];
            }

            if ($statusCode === 404) {
                // releases/latest 404 → 저장소 자체 존재 여부를 추가 확인
                $repoExists = $this->checkGithubRepoExists($owner, $repo, $token);

                if ($repoExists) {
                    // 저장소는 존재하지만 릴리스가 없음
                    Log::channel('upgrade')->info(__('settings.core_update.log_not_found'), [
                        'url' => $apiUrl,
                        'reason' => 'no_releases',
                    ]);

                    // 이 분기는 **릴리스가 없는 것이 정상**인 경우라 API 가 `message` 를
                    // 주지 않는 것이 흔하다. 그대로 넘기면 운영자 화면에 `(HTTP 404 — )`
                    // 가 뜬다 — 형제 호출부와 같은 규율로 일반 문구를 채운다.
                    return ['version' => null, 'error' => __('settings.core_update.no_releases_found', [
                        'status' => $statusCode,
                        'message' => $apiReason,
                    ])];
                }

                // 저장소 자체를 찾을 수 없음
                Log::channel('upgrade')->warning(__('settings.core_update.log_not_found'), [
                    'url' => $apiUrl,
                    'has_token' => $token !== '',
                    'api_message' => $apiMessage,
                ]);

                return ['version' => null, 'error' => $token === ''
                    ? __('settings.core_update.github_repo_not_found_no_token', ['status' => $statusCode, 'message' => $apiReason])
                    : __('settings.core_update.github_repo_not_found', ['status' => $statusCode, 'message' => $apiReason]),
                ];
            }

            if ($statusCode !== 200) {
                Log::channel('upgrade')->warning(__('settings.core_update.log_unexpected_status'), [
                    'url' => $apiUrl,
                    'status' => $statusCode,
                    'api_message' => $apiMessage,
                ]);

                return ['version' => null, 'error' => __('settings.core_update.github_api_error', ['status' => $statusCode, 'message' => $apiReason])];
            }

            if (is_array($data) && isset($data['tag_name'])) {
                $version = ltrim($data['tag_name'], 'v');

                // 릴리스에 서명 자산(g7-{version}.zip + .sig.json)이 함께 올라왔는지 — 확인 화면의 서명 배지 (7.2.0)
                return [
                    'version' => $version,
                    'error' => null,
                    'signature_available' => GithubHelper::findSignedAssetPair(
                        array_values(array_filter((array) ($data['assets'] ?? []), 'is_array')),
                        PackageExpectation::CORE_IDENTIFIER.'-'.$version
                    ) !== null,
                ];
            }

            // 치환 자리를 비워 두지 않는다 — 이 키는 `(HTTP :status — :message)` 를 싣고
            // 있어, 파라미터 없이 부르면 번역기가 자리표시자를 **그대로 둔 문장**을 돌려줘
            // 운영자 화면에 `:status`·`:message` 가 노출된다. 사유를 모를 때는 비워 두지
            // 말고 일반 문구로 채운다 (형제 호출부는 전부 채우고 있었고 이 자리만 빠졌다).
            return ['version' => null, 'error' => __('settings.core_update.no_releases_found', [
                'status' => $statusCode,
                'message' => $apiReason,
            ])];
        } catch (\Exception $e) {
            Log::channel('upgrade')->error(__('settings.core_update.log_version_check_error'), ['error' => $e->getMessage()]);

            return ['version' => null, 'error' => __('settings.core_update.github_api_failed')];
        }
    }

    /**
     * GitHub 저장소 존재 여부를 확인합니다.
     *
     * @param  string  $owner  저장소 소유자
     * @param  string  $repo  저장소 이름
     * @param  string  $token  GitHub Personal Access Token
     * @return bool 저장소가 존재하면 true
     */
    protected function checkGithubRepoExists(string $owner, string $repo, string $token = ''): bool
    {
        return GithubHelper::checkRepoExists($owner, $repo, $token);
    }

    /**
     * _pending 디렉토리의 존재/퍼미션/소유그룹을 검증합니다.
     *
     * @return array{valid: bool, path: string, errors: array}
     */
    public function validatePendingPath(): array
    {
        $pendingPath = config('app.update.pending_path');
        $errors = [];

        if (! File::isDirectory($pendingPath)) {
            try {
                File::ensureDirectoryExists($pendingPath, 0770, true);
            } catch (\Exception $e) {
                $errors[] = __('settings.core_update.pending_path_create_failed', [
                    'path' => $pendingPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $owner = 'unknown';
        $group = 'unknown';
        $perms = 'unknown';

        if (File::isDirectory($pendingPath)) {
            if (! is_writable($pendingPath)) {
                $errors[] = __('settings.core_update.pending_path_not_writable', ['path' => $pendingPath]);
            }

            $owner = function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($pendingPath))['name'] ?? 'unknown' : 'unknown';
            $group = function_exists('posix_getgrgid') ? posix_getgrgid(filegroup($pendingPath))['name'] ?? 'unknown' : 'unknown';
            $perms = substr(sprintf('%o', fileperms($pendingPath)), -3);
        }

        return [
            'valid' => empty($errors),
            'path' => $pendingPath,
            'owner' => $owner,
            'group' => $group,
            'permissions' => $perms,
            'errors' => $errors,
        ];
    }

    /**
     * GitHub 태그 릴리스에서 코어 배포본을 받아 서명을 검증하고 _pending 에 풉니다.
     *
     * 릴리스에 `g7-{version}.zip` + `.sig.json` 이 있으면 그 자산을 받아 **코어 내장 키로만** 검증한다(제작자 키로 서명한
     * 코어는 미신뢰). 서명 자산이 없으면 미서명 — `$integrity->allowUnsigned` 이고 「미서명 패키지 차단」이 꺼져 있을 때만
     * 태그 zipball 을 받는다(7.2.0). 풀기는 `extractZipToPending()` 과 같은 길(ZipArchive + 안전 해제)이고, 푼 뒤
     * `config/app.php` 의 버전을 서명된 버전과 대조한다.
     *
     * @param  string  $version  다운로드할 버전
     * @param  \Closure|null  $onProgress  진행 콜백
     * @param  PackageIntegrityOptions|null  $integrity  패키지 무결성 선택 (null 이면 미서명은 거절)
     * @return string _pending 내 압축 해제된 경로
     *
     * @throws PackageIntegrityException 서명 불일치·미신뢰 키·미서명 확인 필요 시
     * @throws CoreUpdateOperationException 저장소 주소 오류·릴리스 없음·해제 실패 시
     */
    public function downloadUpdate(string $version, ?\Closure $onProgress = null, ?PackageIntegrityOptions $integrity = null): string
    {
        $integrity ??= PackageIntegrityOptions::none();
        $downloadDir = storage_path('app/temp/core_download_'.uniqid());

        $onProgress?->__invoke('download', __('settings.core_update.downloading'));

        try {
            $acquired = $this->acquireRelease($version, $downloadDir, $integrity);
            $sourcePath = $this->extractZipToPending($acquired->zipPath, $onProgress);

            try {
                $this->confirmCoreIntegrity($acquired->verification, $sourcePath, $integrity);
            } catch (PackageIntegrityException $e) {
                $this->cleanupPending($sourcePath);
                throw $e;
            }

            return $sourcePath;
        } finally {
            if (File::isDirectory($downloadDir)) {
                File::deleteDirectory($downloadDir);
            }
        }
    }

    /**
     * 코어 릴리스 패키지를 받아 해제 전 검증까지 합니다 (CLI·웹 공통).
     *
     * @param  string  $version  받을 버전
     * @param  string  $destDir  내려받을 디렉토리
     * @param  PackageIntegrityOptions  $integrity  패키지 무결성 선택
     * @return AcquiredPackage 받은 패키지
     *
     * @throws PackageIntegrityException 서명 불일치·미신뢰 키·미서명 확인 필요 시
     * @throws CoreUpdateOperationException 저장소 주소 오류·릴리스 없음·추출 수단 없음
     */
    public function acquireRelease(string $version, string $destDir, PackageIntegrityOptions $integrity): AcquiredPackage
    {
        $githubUrl = (string) config('app.update.github_url');

        if (! preg_match('#github\.com[/:]([^/]+)/([^/\.]+)#', $githubUrl, $matches)) {
            throw new CoreUpdateOperationException('settings.core_update.invalid_github_url');
        }

        if ($this->buildExtractionStrategies() === []) {
            throw new CoreUpdateOperationException('settings.core_update.no_extract_method_available');
        }

        $token = (string) (config('app.update.github_token') ?? '');

        try {
            return app(PackageAcquirer::class)->fromGithubRelease(
                $matches[1], $matches[2], $version, $token, PackageExpectation::core($version), $integrity, $destDir
            );
        } catch (PackageReleaseNotFoundException) {
            // 어느 태그로도 배포본 주소를 얻지 못했다 = 한 번도 내려받지 않았다(태그 없음·GitHub 연결 실패).
            throw new CoreUpdateOperationException('settings.core_update.release_not_found', ['version' => $version]);
        }
    }

    /**
     * 착수 전에 릴리스의 서명 자산 유무로 미서명 정책을 묻습니다 (실제 검증은 내려받은 뒤).
     *
     * 유지보수 모드·백업 전에 「미서명이라 확인이 필요함」(409)을 알리려는 것이다. 서명 자산이 있으면 아무것도 하지 않는다.
     *
     * @param  string  $version  받을 버전
     * @param  PackageIntegrityOptions  $integrity  패키지 무결성 선택
     * @return void 반환값 없음
     *
     * @throws PackageIntegrityException 서명 자산이 없고 계속할 수 없을 때
     */
    public function preflightReleaseIntegrity(string $version, PackageIntegrityOptions $integrity): void
    {
        $githubUrl = (string) config('app.update.github_url');
        if (! preg_match('#github\.com[/:]([^/]+)/([^/\.]+)#', $githubUrl, $matches)) {
            return;
        }

        $release = GithubHelper::fetchReleaseByTag($matches[1], $matches[2], $version, (string) (config('app.update.github_token') ?? ''));
        if ($release['error'] !== null) {
            // 확인할 수 없으면 착수 전에 막지 않는다 — 내려받을 때 같은 판정을 다시 한다
            return;
        }

        if (GithubHelper::findSignedAssetPair($release['assets'] ?? [], PackageExpectation::CORE_IDENTIFIER.'-'.ltrim($version, 'vV')) !== null) {
            return;
        }

        app(UnsignedPackagePolicy::class)->decide(PackageVerification::unsigned(), $integrity);
    }

    /**
     * 운영자가 둔 코어 ZIP(`--zip`·웹 수동 ZIP)을 해제 전에 검증합니다.
     *
     * 서명 파일은 `$integrity->signaturePath`, 없으면 ZIP 옆 `{zip}.sig.json` 을 자동으로 쓴다.
     *
     * @param  string  $zipPath  코어 ZIP 경로
     * @param  PackageIntegrityOptions  $integrity  패키지 무결성 선택
     * @return PackageVerification 정책을 통과한 검증 결과
     *
     * @throws PackageIntegrityException 서명 불일치·미신뢰 키·미서명 확인 필요 시
     */
    public function verifyCoreZipIntegrity(string $zipPath, PackageIntegrityOptions $integrity): PackageVerification
    {
        return app(PackageAcquirer::class)->verifyLocalZip($zipPath, PackageExpectation::core(), $integrity);
    }

    /**
     * 푼 배포본의 `config/app.php` 버전을 서명된 버전과 대조합니다 (해제 뒤·적용 전).
     *
     * @param  PackageVerification  $verification  해제 전 검증 결과
     * @param  string  $sourcePath  푼 배포본 루트
     * @param  PackageIntegrityOptions  $integrity  패키지 무결성 선택
     * @return PackageVerification 대조 결과
     *
     * @throws PackageIntegrityException 서명된 버전과 다를 때
     */
    public function confirmCoreIntegrity(PackageVerification $verification, string $sourcePath, PackageIntegrityOptions $integrity): PackageVerification
    {
        if ($verification->status === PackageVerificationStatus::Unsigned) {
            return $verification;
        }

        // 버전 줄 해석은 CoreVersionDiskReader 한 곳이 소유한다 — 서명 도구(extension:sign)도 같은 규칙으로 읽는다
        $version = CoreVersionDiskReader::shippedVersion($sourcePath.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php');

        return app(PackageAcquirer::class)->confirmManifest($verification, ['version' => $version], $integrity);
    }

    /**
     * 사용 가능한 추출 전략 목록을 빌드합니다.
     *
     * @return array<int, array{archive_type: string, method: string, label: string}>
     */
    protected function buildExtractionStrategies(): array
    {
        $strategies = [];

        // 1단계: ZipArchive (PHP zip 확장)
        if (class_exists(\ZipArchive::class)) {
            $strategies[] = [
                'archive_type' => 'zipball',
                'method' => 'extractWithZipArchive',
                'label' => 'ZipArchive',
            ];
        }

        // unzip 명령어 폴백은 두지 않는다 — 항목 이름 검사(역슬래시 정규화·압축 밖 경로 거부)를
        // 거치지 않고 심볼릭 링크까지 풀기 때문이다. ZipArchive(zip 확장)는 필수 요구사항이다.

        return $strategies;
    }

    /**
     * ZipArchive를 사용하여 ZIP 파일을 압축 해제합니다.
     *
     * @param  string  $zipPath  ZIP 파일 경로
     * @param  string  $extractDir  추출 대상 디렉토리
     * @return void 반환값 없음
     */
    protected function extractWithZipArchive(string $zipPath, string $extractDir): void
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new CoreUpdateOperationException('settings.core_update.zip_extract_failed');
        }

        // 역슬래시 경로 정규화 + 압축 밖 경로·심볼릭 링크 거부
        SafeArchiveExtractor::extractZip($zipPath, $extractDir);
    }

    /**
     * 다운로드된 업데이트 패키지를 검증합니다.
     *
     * @param  string  $pendingPath  검증할 경로
     * @return void 반환값 없음
     *
     * @throws CoreUpdateOperationException 검증 실패 시
     */
    public function validatePendingUpdate(string $pendingPath): void
    {
        if (! File::exists($pendingPath.DIRECTORY_SEPARATOR.'composer.json')) {
            throw new CoreUpdateOperationException('settings.core_update.invalid_package');
        }

        if (! File::isDirectory($pendingPath.DIRECTORY_SEPARATOR.'app')) {
            throw new CoreUpdateOperationException('settings.core_update.invalid_package');
        }

        // 그누보드7 프로젝트인지 확인 (config/app.php의 version 키 존재 여부)
        $configPath = $pendingPath.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';
        if (! File::exists($configPath)) {
            throw new CoreUpdateOperationException('settings.core_update.invalid_package_not_g7');
        }

        $config = include $configPath;
        if (! is_array($config) || ! isset($config['version'])) {
            throw new CoreUpdateOperationException('settings.core_update.invalid_package_not_g7');
        }
    }

    /**
     * 외부 소스 디렉토리를 _pending 경로로 복제합니다.
     *
     * --source 모드에서 원본 소스 디렉토리를 보호하기 위해
     * _pending으로 복사한 뒤 해당 경로에서 작업합니다.
     *
     * @param  string  $sourceDir  원본 소스 디렉토리 경로
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return string _pending 경로
     */
    public function copySourceToPending(string $sourceDir, ?\Closure $onProgress = null): string
    {
        $pendingPath = $this->createPendingDirectory();

        $onProgress?->__invoke('copy', '소스 디렉토리 복제 중...');

        FilePermissionHelper::copyDirectory($sourceDir, $pendingPath, $onProgress);

        return $pendingPath;
    }

    /**
     * 외부 ZIP 파일을 _pending으로 추출합니다.
     *
     * --zip 모드에서 사용합니다. ZIP 구조가 GitHub 릴리스 zipball(owner-repo-hash/ 래퍼)이든
     * 평탄한 G7 루트든 모두 지원합니다. 추출 후 validatePendingUpdate() 로 G7 패키지
     * 구조(composer.json + app/ + config/app.php)를 검증합니다.
     *
     * 추출 전략은 downloadUpdate() 와 동일한 폴백 체인(ZipArchive → unzip) 을 사용하며,
     * GitHub 호출은 수행하지 않습니다.
     *
     * @param  string  $zipPath  외부 ZIP 파일 경로
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return string _pending 내 추출된 소스 경로 (래퍼 감지 후)
     *
     * @throws CoreUpdateOperationException ZIP 미존재 / 추출 실패 / 패키지 검증 실패 시
     */
    public function extractZipToPending(string $zipPath, ?\Closure $onProgress = null): string
    {
        if (! File::exists($zipPath)) {
            throw new CoreUpdateOperationException('settings.core_update.zip_file_not_found', ['path' => $zipPath]);
        }

        $pendingPath = $this->createPendingDirectory();
        $extractDir = $pendingPath.DIRECTORY_SEPARATOR.'extracted';
        File::ensureDirectoryExists($extractDir);

        $strategies = $this->buildExtractionStrategies();
        if (empty($strategies)) {
            throw new CoreUpdateOperationException('settings.core_update.no_extract_method_available');
        }

        $lastError = null;
        foreach ($strategies as $strategy) {
            $method = $strategy['method'];
            $label = $strategy['label'];

            $onProgress?->__invoke('extract', __('settings.core_update.extracting_with', ['method' => $label]));

            try {
                // 전 전략 잔여물 제거
                if (File::isDirectory($extractDir)) {
                    File::deleteDirectory($extractDir);
                }
                File::ensureDirectoryExists($extractDir);

                $this->$method($zipPath, $extractDir);

                $sourcePath = $this->resolveExtractedRoot($extractDir);

                $onProgress?->__invoke('validate', __('settings.core_update.validating'));
                $this->validatePendingUpdate($sourcePath);

                return $sourcePath;
            } catch (\Throwable $e) {
                $lastError = $e;
                $onProgress?->__invoke('fallback', __('settings.core_update.extract_fallback', [
                    'method' => $label,
                    'error' => $e->getMessage(),
                ]));
            }
        }

        throw new CoreUpdateOperationException(
            'settings.core_update.all_extract_methods_failed',
            [],
            $lastError,
        );
    }

    /**
     * 추출 디렉토리에서 G7 소스 루트를 판별합니다.
     *
     * - 하위에 디렉토리 1개, 파일 0개 → 래퍼 디렉토리(GitHub zipball 등) → 하위 반환
     * - 그 외 → extractDir 자체를 반환 (composer.json 등이 루트에 있다고 가정)
     *
     * @param  string  $extractDir  추출 대상 디렉토리
     * @return string 확장 소스 디렉토리 경로
     */
    protected function resolveExtractedRoot(string $extractDir): string
    {
        $dirs = File::directories($extractDir);
        $files = File::files($extractDir);

        if (count($dirs) === 1 && count($files) === 0) {
            return $dirs[0];
        }

        return $extractDir;
    }

    /**
     * 내려받은(또는 운영자가 올린) 배포본 ZIP 을 나눠서 풉니다 (웹 업데이트 — 한 요청의 시간 몫 안에서 멈춘다).
     *
     * 풀이는 `extractZipToPending()` 과 같은 안전 해제(`SafeArchiveExtractor`)다. 처음 호출(`$from` 0)은
     * 앞 시도의 잔여물을 지우고 시작한다. 다 풀면 소스 루트를 판별하고 G7 패키지 구조를 검증해
     * `source_path` 로 돌려준다.
     *
     * @param  string  $zipPath  ZIP 경로
     * @param  string  $pendingPath  격리 디렉토리 (`createPendingDirectory()` 결과)
     * @param  int  $from  이어 풀 항목 순번
     * @param  \Closure|null  $shouldPause  멈출 때인지 묻는 함수
     * @return array{done: bool, next: int, total: int, source_path: string|null} 진행분
     *
     * @throws CoreUpdateOperationException 압축 해제 수단이 없거나 패키지 검증 실패 시
     */
    public function extractArchiveChunk(string $zipPath, string $pendingPath, int $from = 0, ?\Closure $shouldPause = null): array
    {
        if (! File::exists($zipPath)) {
            throw new CoreUpdateOperationException('settings.core_update.zip_file_not_found', ['path' => $zipPath]);
        }

        if ($this->buildExtractionStrategies() === []) {
            throw new CoreUpdateOperationException('settings.core_update.no_extract_method_available');
        }

        $extractDir = $pendingPath.DIRECTORY_SEPARATOR.'extracted';

        if ($from === 0 && File::isDirectory($extractDir)) {
            File::deleteDirectory($extractDir);
        }

        File::ensureDirectoryExists($extractDir);

        $chunk = SafeArchiveExtractor::extractZipChunk($zipPath, $extractDir, $from, $shouldPause);

        if (! $chunk['done']) {
            return ['done' => false, 'next' => $chunk['next'], 'total' => $chunk['total'], 'source_path' => null];
        }

        $sourcePath = $this->resolveExtractedRoot($extractDir);
        $this->validatePendingUpdate($sourcePath);

        return ['done' => true, 'next' => $chunk['next'], 'total' => $chunk['total'], 'source_path' => $sourcePath];
    }

    /**
     * GitHub 릴리스 배포본 ZIP 을 격리 디렉토리로 내려받기만 합니다 (풀기는 `extractArchiveChunk()`).
     *
     * 웹 업데이트는 내려받기와 풀기를 다른 요청에서 한다 — 한 요청에 둘을 다 하면 큰 배포본이 요청 시간
     * 제한에 걸린다. 명령줄 업데이트는 `downloadUpdate()` 가 둘을 이어 한다.
     *
     * @param  string  $version  대상 버전
     * @param  string  $pendingPath  격리 디렉토리
     * @param  PackageIntegrityOptions|null  $integrity  패키지 무결성 선택 (7.2.0, null 이면 미서명은 거절)
     * @return string 내려받은 ZIP 경로
     *
     * @throws PackageIntegrityException 서명 불일치·미신뢰 키·미서명 확인 필요 시
     * @throws CoreUpdateOperationException 저장소 주소가 잘못됐거나 배포본을 찾지 못한 경우
     */
    public function fetchReleaseArchive(string $version, string $pendingPath, ?PackageIntegrityOptions $integrity = null): string
    {
        return $this->fetchVerifiedReleaseArchive($version, $pendingPath, $integrity ?? PackageIntegrityOptions::none())->zipPath;
    }

    /**
     * 코어 릴리스 배포본을 격리 디렉토리로 내려받고 해제 전 검증까지 합니다 (웹 — 풀기는 다음 요청, 7.2.0).
     *
     * 검증 결과는 호출부가 실행 상태에 실어 두었다가, 다 푼 뒤 `confirmCoreIntegrity()` 로 버전을 대조한다.
     *
     * @param  string  $version  대상 버전
     * @param  string  $pendingPath  격리 디렉토리
     * @param  PackageIntegrityOptions  $integrity  패키지 무결성 선택
     * @return AcquiredPackage 받은 패키지
     *
     * @throws PackageIntegrityException 서명 불일치·미신뢰 키·미서명 확인 필요 시
     * @throws CoreUpdateOperationException 저장소 주소가 잘못됐거나 배포본을 찾지 못한 경우
     */
    public function fetchVerifiedReleaseArchive(string $version, string $pendingPath, PackageIntegrityOptions $integrity): AcquiredPackage
    {
        return $this->acquireRelease($version, $pendingPath, $integrity);
    }

    /**
     * 코어 핵심 파일을 백업합니다.
     *
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return string 백업 경로
     */
    public function createBackup(?\Closure $onProgress = null): string
    {
        return $this->createBackupChunk([], null, $onProgress)['backup_path'];
    }

    /**
     * 코어 핵심 파일 백업을 나눠서 만듭니다 (웹 업데이트 — 한 요청의 시간 몫 안에서 멈춘다).
     *
     * `createBackup()` 은 이 함수를 멈춤 없이 부른다 — 명령줄과 웹이 같은 복사 경로를 쓴다.
     *
     * @param  array<string, mixed>  $progress  직전 호출이 돌려준 진행분 (처음이면 빈 배열)
     * @param  \Closure|null  $shouldPause  멈출 때인지 묻는 함수 (null 이면 끝까지)
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return array{done: bool, backup_path: string, target: int, cursor: string|null} 진행분
     */
    public function createBackupChunk(array $progress = [], ?\Closure $shouldPause = null, ?\Closure $onProgress = null): array
    {
        $targets = array_merge(
            config('app.update.targets', []),
            config('app.update.backup_only', []),
            config('app.update.backup_extra', [])
        );

        $excludes = config('app.update.excludes', []);

        return CoreBackupHelper::createBackupChunk($targets, $excludes, $progress, $shouldPause, $onProgress);
    }

    /**
     * 코어 업데이트 대상 파일을 덮어씁니다.
     *
     * 기본(증분) 모드 — `$applyList` 가 주어지고 `$prune === false`:
     *  - 코어(신 버전)가 실제로 추가·변경한 파일만 적용한다(3-way 판정 산출물).
     *  - 코어가 건드리지 않은 파일은 복사·chmod·chown·mtime 갱신을 전부 스킵하여 현재
     *    디스크 상태(사용자 수정 포함 가능)를 그대로 보존한다.
     *  - orphan(소스에 없는 대상 파일) 삭제를 하지 않는다 — 사용자가 추가한 신규 파일 보존.
     *
     * prune 모드 — `$prune === true` 또는 `$applyList === null`(백업 부재 fallback):
     *  - targets 전체를 무조건 덮어쓰고, 소스에 없는 orphan 을 삭제한다(기존 동작).
     *
     * 주의: ExtensionPendingHelper::copyToActive()는 PHP copy()를 사용하여
     * 파일 퍼미션/소유자를 보존하지 않으므로, 코어 업데이트에서는 사용하지 않습니다.
     * 대신 FilePermissionHelper::copyDirectory()로 기존 퍼미션을 유지합니다.
     *
     * @param  string  $sourcePath  소스 경로 (_pending 내)
     * @param  \Closure|null  $onProgress  진행 콜백
     * @param  bool  $prune  true 면 전체 덮어쓰기 + orphan 삭제(기존 동작), false 면 증분 적용
     * @param  array<int, string>|null  $applyList  적용 대상 상대경로 목록(target 접두사 포함).
     *                                              `CoreBackupHelper::computeApplyList()` 산출물. null 이면 증분 불가로 판단해 전체 덮어쓰기.
     * @param  array<int, string>  $skipPaths  이번 업데이트가 배치하지 않는 경로(base 상대, 예 `plugins/_bundled/{id}`).
     *                                         증분·prune·백업 부재 세 모드 모두에서 복사와 orphan 정리를 건너뛴다 —
     *                                         설치되지 않았고 디스크에도 없는 공식 번들을 새로 깔지 않기 위함(#146).
     * @return array{root_file_warnings: list<string>} 이 호출에서 누적된 루트 진입·보호 파일 경고 — `getLastRootFileWarnings()` 와 같은 값이다. 콘솔 커맨드가 아닌 소비처(무CLI 웹 워커)가 그 게터를 모르면 통보가 통째로 사라지므로 반환값에도 싣는다.
     */
    public function applyUpdate(string $sourcePath, ?\Closure $onProgress = null, bool $prune = false, ?array $applyList = null, array $skipPaths = []): array
    {
        $targets = config('app.update.targets', []);
        $excludes = config('app.update.excludes', []);
        // custom 예약 자리(#792)는 어느 모드에서도 복사·orphan 정리·자동 발견 대상이 아니다 —
        // skipPaths 와 같은 경로 판정으로 copyDirectory 의 복사·removeOrphanItems 양쪽에서 건너뛴다.
        $skipPaths = array_values(array_filter(array_map(fn ($p) => $this->normalizeRelativePath((string) $p), UpdateReservedPaths::mergeInto($skipPaths))));

        // 운영자가 직접 바꿔 둔 `public/favicon.ico` 는 덮지 않는다 (#434 D7). 이름 기준 excludes 에 넣으면
        // 코어 번들 아이콘(`public/assets/core/favicon/favicon.ico`)까지 갱신이 막히므로 이 경로 하나만 건너뛴다.
        // 비어 있는 파일(7.1 이하 기본값)은 운영자 파일이 아니므로 새 번들 아이콘으로 바뀐다.
        if ($this->isOperatorFavicon($sourcePath) && ! in_array(self::PUBLIC_FAVICON_PATH, $skipPaths, true)) {
            $skipPaths[] = self::PUBLIC_FAVICON_PATH;
        }

        // 루트 진입·보호 파일 경고는 이 호출 단위로만 유효하다 (권한 경고와 같은 규약).
        $this->lastRootFileWarnings = [];

        // 증분 모드 여부: prune 이 아니고 적용 목록이 주어진 경우에만 증분 적용.
        // applyList 가 null 이면(백업 부재 등) 안전을 위해 전체 덮어쓰기로 회귀한다.
        $incremental = ! $prune && $applyList !== null;

        // 증분 모드에서 각 target 하위 파일을 O(1) 로 조회하기 위한 lookup 맵.
        // computeApplyList 는 target 접두사를 포함한 상대경로(예: 'public/.htaccess')를
        // 반환하므로, 그대로 정규화 키로 담는다.
        $applySet = [];
        if ($incremental) {
            foreach ($applyList as $rel) {
                $applySet[$this->normalizeRelativePath((string) $rel)] = true;
            }
        }

        $applied = [];

        foreach ($targets as $target) {
            $src = $sourcePath.DIRECTORY_SEPARATOR.$target;
            $dest = base_path($target);

            if (! File::exists($src) && ! File::isDirectory($src)) {
                continue;
            }

            $normalizedTarget = $this->normalizeRelativePath($target);

            if ($this->isWithinSkipPaths($normalizedTarget, $skipPaths)) {
                $applied[$normalizedTarget] = true;

                continue;
            }

            $onProgress?->__invoke('apply', $target);

            if (File::isDirectory($src)) {
                // 증분 모드: 이 target 하위의 적용 대상만 추린 뒤, target 접두사를 벗겨
                // copyDirectory 의 내부 상대경로($itemRelativePath)와 직접 매칭되는
                // 화이트리스트 맵을 만든다.
                $targetApplyList = null;
                if ($incremental) {
                    $targetApplyList = $this->buildTargetApplyList($applySet, $normalizedTarget);
                }

                // `{domain}/_bundled` 타깃은 최상위 한 레벨의 orphan 을 보존한다 — 사용자가
                // `_bundled/` 바로 아래에 직접 만든 커스텀 확장 디렉토리/파일이 코어 업데이트
                // 소스(번들 확장만 포함)에 없다는 이유로 삭제되던 결함 차단.
                // 번들 확장 디렉토리 *내부* stale 정리는 prune 모드에서만 수행된다.
                $preserveTopLevelOrphans = str_ends_with($normalizedTarget, '_bundled');

                // `public` 타깃 prune 시 `public/storage` symlink 를 orphan 삭제에서 보호한다 —
                // 릴리즈 소스에는 런타임 symlink 가 없으므로 orphan 으로 판정되어 삭제되면
                // 업로드 파일이 404 되는 결함(#43). 다른 타깃은 빈 배열이라 영향 없음.
                $preserveLinkPaths = $normalizedTarget === 'public' ? ['storage'] : [];

                // `deploy/**` 는 운영자가 **제자리에서 고쳐 쓰는** 서버 설정 조각이다 (nginx 는
                // `include` 로 그 파일을 직접 읽는다). 통째 복사는 그 수정을 조용히 지운다.
                if ($normalizedTarget === 'deploy') {
                    $this->applyDeployFragments($src, $dest, $targetApplyList, $onProgress);
                } else {
                    FilePermissionHelper::copyDirectory(
                        $src,
                        $dest,
                        $onProgress,
                        $excludes,
                        removeOrphans: $prune,
                        preserveTopLevelOrphans: $preserveTopLevelOrphans,
                        applyList: $targetApplyList,
                        preserveLinkPaths: $preserveLinkPaths,
                        skipPaths: $this->stripTargetPrefix($skipPaths, $normalizedTarget),
                    );
                }
            } else {
                // 단일 파일 target — 증분 모드에서는 적용 목록에 있을 때만 복사.
                if ($incremental && ! isset($applySet[$normalizedTarget])) {
                    $applied[$normalizedTarget] = true;

                    continue;
                }
                File::ensureDirectoryExists(dirname($dest));

                // 루트 `.htaccess`·`web.config` 는 운영자가 서버 규칙을 덧붙이는 파일이다 (D25).
                // 증분 판정의 base 가 "현재 디스크 스냅샷"이라 운영자 수정본은 언제나
                // changed 로 분류되므로, 통째 복사 대신 코어 관리 블록(BEGIN ~ END 마커)만
                // 교체해 블록 밖 운영자 규칙을 보존한다 (prune 모드도 동일 — 이 파일의
                // 운영자 규칙 소실은 사이트 서버 설정 파손이라 전체 덮어쓰기 대상이 아니다).
                // 어느 파일이 마커 파일인지는 리터럴이 아니라 보호 파일 SSoT 에서 도출한다 —
                // 종전에는 `.htaccess` 리터럴이 분기였고 `web.config` 는 통째 복사로 떨어졌다.
                $updater = CoreBackupHelper::rootMarkerFileUpdater($normalizedTarget);
                if ($updater !== null) {
                    $this->recordRootMarkerFileResult($updater, $updater::apply($src, $dest), $dest);
                } else {
                    if ($normalizedTarget === 'index.php') {
                        $this->recordRootIndexReplacement($dest);
                    }

                    $this->copyRootFileOrWarn($normalizedTarget, $src, $dest);
                }
            }

            $applied[$normalizedTarget] = true;
        }

        // 자동 발견 폴백 — 부모 프로세스(구버전) 의 stale `app.update.targets` 가
        // 신버전이 도입한 최상위 디렉토리(예: beta.4 의 `lang-packs/`) 를 인식하지 못하는
        // 결함을 안전망으로 차단한다.
        //
        // source 디렉토리의 최상위 항목 중 targets 에 누락되었고 PROTECTED 에도 포함되지
        // 않은 항목은 동일 흐름으로 복사하며 경고 로그를 남긴다. 다음 코어 업데이트의
        // applyUpdate 는 이미 디스크에 반영된 신버전 config 의 targets 로 정상 처리한다.
        $this->applyDiscoveredTopLevelPaths($sourcePath, $applied, $excludes, $onProgress, $skipPaths);

        return ['root_file_warnings' => $this->lastRootFileWarnings];
    }

    /**
     * `deploy/**` 서버 설정 조각을 **운영자 수정본을 보존하며** 갱신합니다 (G3 회차 15 W2-1).
     *
     * 조각 머리말은 운영자에게 그 파일을 직접 고치라고 지시한다(`fastcgi_pass`·하위 폴더
     * 접두사·리다이렉트 목적지). nginx 는 그 파일을 `include` 로 제자리에서 읽으므로 통째
     * 복사는 사이트의 PHP 소켓 설정을 릴리즈 기본값으로 되돌린다 — 오류 없이, 다음 reload 때.
     *
     * 판정 근거는 **코어가 마지막으로 쓴 내용의 해시**(원장 `storage/app/core-update/`
     * `deploy-fragments.json`)다. 백업 스냅샷은 근거가 못 된다 — 그것은 현재 디스크의 사본이라
     * 언제나 현재 디스크와 같다. 파일마다:
     *   · 디스크가 새 판본과 같다 → 원장만 갱신하고 잔존 `.dist` 를 걷는다
     *   · 디스크가 없다 → 신규 복사
     *   · 디스크가 원장의 해시와 같다(코어가 쓴 그대로) → 정상 갱신 + 잔존 `.dist` 제거
     *   · 그 외(운영자 수정본, 또는 원장이 없어 판정할 수 없음) → 덮어쓰지 않고 새 판본을
     *     `<이름>.dist` 로 옆에 두고 경고 통로(`lastRootFileWarnings`)에 싣는다. 판정할 수
     *     없을 때 잃는 쪽보다 한 번 더 확인시키는 쪽을 고른다
     *
     * `.dist` 는 웹에서 열리지 않는다 — `deploy/` 디렉토리 자체와 `dist` 확장자가 네 서버
     * 조각의 차단 목록에 모두 있다(`ServerConfigParityTest`). 되돌리기는 `.dist` 와 원장을
     * 함께 걷는다(`restoreFromBackup()`). 이 경로는 orphan 을 지우지 않는다 — 운영자가 둔
     * 파일이 사라지는 것이 곧 이 메서드가 막는 결함이다.
     *
     * @param  string  $sourceDir  릴리즈 판본의 `deploy/` 절대 경로
     * @param  string  $destinationDir  설치본의 `deploy/` 절대 경로
     * @param  array<string, bool>|null  $applyList  증분 모드의 적용 목록(`deploy/` 접두사를 벗긴 상대 경로). null 이면 전체
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return void 반환값 없음
     */
    protected function applyDeployFragments(string $sourceDir, string $destinationDir, ?array $applyList, ?\Closure $onProgress): void
    {
        $ledgerPath = $this->deployFragmentLedgerPath();
        $ledger = $this->readDeployFragmentLedger($ledgerPath);

        foreach (File::allFiles($sourceDir) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            if ($applyList !== null && ! isset($applyList[$relative])) {
                continue;
            }

            $sourcePath = $file->getPathname();
            $destinationPath = $destinationDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $distPath = $destinationPath.self::DEPLOY_FRAGMENT_DIST_SUFFIX;
            $distNewPath = $destinationPath.self::DEPLOY_FRAGMENT_DIST_NEW_SUFFIX;

            $onProgress?->__invoke('apply', 'deploy/'.$relative);

            $newContents = @file_get_contents($sourcePath);
            if ($newContents === false) {
                Log::channel('upgrade')->warning('[core-update] deploy 조각 소스를 읽지 못했습니다', ['path' => $sourcePath]);

                continue;
            }
            $newHash = hash('sha256', $newContents);

            if (is_file($destinationPath)) {
                $current = @file_get_contents($destinationPath);
                $currentHash = $current === false ? null : hash('sha256', $current);
                $reference = isset($ledger[$relative]) && is_string($ledger[$relative]) ? $ledger[$relative] : null;

                if ($currentHash === $newHash) {
                    $ledger[$relative] = $newHash;
                    @unlink($distPath);
                    @unlink($distNewPath);

                    continue;
                }

                // 원장이 없거나(이 기능 이전 설치본) 코어가 쓴 해시와 다르면 운영자 수정본으로 본다.
                if ($currentHash === null || $reference === null || $currentHash !== $reference) {
                    $this->placeDeployFragmentSidecar($destinationPath, $distPath, $distNewPath, $newContents);

                    // 보존해도 원장에는 **코어가 제시한 새 판본의 해시**를 기록한다 (G3 회차 16 W2 A2/C-2).
                    //
                    // 원장은 「코어가 마지막으로 제시한 판본」이다. 운영자가 안내대로 `.dist` 를 합쳐
                    // 파일이 새 판본과 같아지면 다음 업데이트에서 디스크 == 원장이라 정상 갱신으로
                    // 탈출한다. 합치지 않으면 디스크 ≠ 원장이라 보존이 유지된다 — 올바른 동작이다.
                    // 종전에는 이 분기가 원장을 씨앗하지 않아, 원장이 없던 사이트는 합친 뒤에도
                    // **매 업데이트마다** 「판정 불가 → 보존 + `.dist`」 로 떨어졌다(오류 없음).
                    $ledger[$relative] = $newHash;

                    continue;
                }
            }

            $this->copyRootFileOrWarn('deploy/'.$relative, $sourcePath, $destinationPath);
            if (is_file($destinationPath) && @filesize($destinationPath) === strlen($newContents)) {
                $ledger[$relative] = $newHash;
                @unlink($distPath);
                @unlink($distNewPath);
            }
        }

        $this->writeDeployFragmentLedger($ledgerPath, $ledger);
    }

    /**
     * 보존한 조각 옆에 새 판본을 사이드카(`.dist` 또는 `.dist.new`)로 두고 사실을 통보합니다.
     *
     * `.dist` 가 없거나 내용이 새 판본과 같으면 `.dist` 에 쓴다. 이미 있는데 내용이 다르면 그 파일은
     * 운영자가 합치던 중간 작업일 수 있으므로 덮어쓰지 않고 새 판본을 `.dist.new` 로 둔다 — 코어가
     * 만든 파일은 코어가 자유롭게 갱신하지만, 운영자가 손댔을 수 있는 파일은 건드리지 않는다
     * (G3 회차 16 W2 A5). 쓰기 실패·보존 사실은 통보 통로와 업그레이드 로그 채널 **양쪽**에 싣는다 —
     * 로그 파일에만 남으면 「완료」만 본 운영자에게 닿지 않고, 통보 통로에만 있으면 기록이 남지 않는다 (G-1).
     *
     * @param  string  $destinationPath  보존한 조각 경로
     * @param  string  $distPath  `.dist` 경로
     * @param  string  $distNewPath  `.dist.new` 경로
     * @param  string  $newContents  새 판본 내용
     * @return void 반환값 없음
     */
    protected function placeDeployFragmentSidecar(string $destinationPath, string $distPath, string $distNewPath, string $newContents): void
    {
        $existingDist = is_file($distPath) ? @file_get_contents($distPath) : null;
        $conflict = is_string($existingDist) && $existingDist !== $newContents;
        $target = $conflict ? $distNewPath : $distPath;

        if (! $conflict && $existingDist === $newContents) {
            // 이미 같은 새 판본이 옆에 있다 — 다시 쓰지 않고 사실만 통보한다.
            $written = strlen($newContents);
        } else {
            $written = @file_put_contents($target, $newContents);
        }

        if ($written === false || $written !== strlen($newContents)) {
            @unlink($target);
            Log::channel('upgrade')->warning('[core-update] deploy 조각 새 판본 사이드카를 쓰지 못했습니다', [
                'path' => $destinationPath,
                'sidecar' => $target,
            ]);
            $this->lastRootFileWarnings[] = (string) __('settings.core_update.deploy_fragment_dist_write_failed', [
                'path' => $destinationPath,
                'dist' => $target,
            ]);

            return;
        }

        if ($conflict) {
            Log::channel('upgrade')->warning('[core-update] deploy 조각 보존 — 옛 .dist 와 내용이 달라 새 판본을 .dist.new 로 두었습니다', [
                'path' => $destinationPath,
                'dist' => $distPath,
                'dist_new' => $distNewPath,
            ]);
            $this->lastRootFileWarnings[] = (string) __('settings.core_update.deploy_fragment_dist_conflict', [
                'path' => $destinationPath,
                'dist' => $distPath,
                'dist_new' => $distNewPath,
            ]);

            return;
        }

        Log::channel('upgrade')->warning('[core-update] deploy 조각 보존 — 운영자 수정본(또는 판정 불가)이라 새 판본을 .dist 로 두었습니다', [
            'path' => $destinationPath,
            'dist' => $distPath,
        ]);
        $this->lastRootFileWarnings[] = (string) __('settings.core_update.deploy_fragment_preserved', [
            'path' => $destinationPath,
            'dist' => $distPath,
        ]);
    }

    /**
     * 해시 원장을 읽습니다. 없거나 손상이면 빈 배열.
     *
     * @param  string  $ledgerPath  원장 경로
     * @return array<string, string> 상대 경로 => sha256
     */
    protected function readDeployFragmentLedger(string $ledgerPath): array
    {
        if (! is_file($ledgerPath)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($ledgerPath), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 해시 원장을 쓰고, 쓰지 못하면 통보 통로와 업그레이드 로그 채널에 싣습니다 (G3 회차 16 W2 A4).
     *
     * 원장은 다음 업데이트의 판정 근거다. 쓰지 못한 채 조용히 넘기면 그 사이트는 다음 업데이트에서
     * 전 조각이 「판정 불가」로 떨어지고 운영자는 이유를 알 수 없다. `File::put()` 은 실패 시
     * `false` 를, 디스크가 차면 **쓴 바이트 수**를 돌려주므로 둘 다 본다. 디렉토리 생성은 예외를
     * 던질 수 있어(권한) 같은 통로로 접는다.
     *
     * @param  string  $ledgerPath  원장 경로
     * @param  array<string, string>  $ledger  상대 경로 => sha256
     * @return bool 썼으면 true
     */
    protected function writeDeployFragmentLedger(string $ledgerPath, array $ledger): bool
    {
        $encoded = (string) json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $reason = null;

        try {
            File::ensureDirectoryExists(dirname($ledgerPath));
            $written = File::put($ledgerPath, $encoded);
        } catch (\Throwable $e) {
            $written = false;
            $reason = $e->getMessage();
        }

        if ($written === false || $written !== strlen($encoded)) {
            Log::channel('upgrade')->warning('[core-update] deploy 조각 해시 원장을 쓰지 못했습니다 — 다음 업데이트가 전 조각을 판정 불가로 봅니다', [
                'ledger' => $ledgerPath,
                'reason' => $reason ?? ($written === false ? 'put_failed' : 'partial_write'),
            ]);
            $this->lastRootFileWarnings[] = (string) __('settings.core_update.deploy_fragment_ledger_write_failed', [
                'ledger' => $ledgerPath,
            ]);

            return false;
        }

        return true;
    }

    /**
     * 현재 디스크의 `deploy/**` 해시로 원장을 **씨앗**합니다 (G3 회차 16 W2 A2 ②).
     *
     * 원장이 없는 설치본은 다음 업데이트에서 전 조각이 「판정 불가 → 보존 + `.dist`」 로 떨어진다.
     * 설치 완료 시점(인스톨러)과 업그레이드 스텝이 이 진입점을 부른다 — 그 시점의 디스크는
     * **사이드카가 없는 조각에 한해** 릴리즈 판본과 바이트 동일하다(설치는 릴리즈 판본을 그대로
     * 풀고, 업데이트는 다른 것을 보존할 때 반드시 사이드카를 남긴다). 사이드카가 있는 조각은
     * 씨앗하지 않는다 — 씨앗하면 운영자 수정본이 「코어가 쓴 그대로」로 읽혀 다음 업데이트가 덮어쓴다.
     *
     * 원장이 이미 있으면 **없는 키만 채우는 병합**이다 — 완전한 원장에는 아무것도
     * 더하지 않아 멱등이고, 부분 원장(선택 적용이 남긴)에는 빠진 조각을 채운다. 업그레이드 스텝은
     * 경로 C 규율로 같은 로직을 로컬에 갖는다 — 두 구현의 일치는 계약 테스트가 대조한다.
     *
     * @param  string  $projectRoot  프로젝트 루트 절대 경로
     * @return list<string> 이 호출에서 **새로 채운** 조각의 상대 경로 (더할 것이 없거나 쓰기 실패면 빈 배열)
     */
    public function seedDeployFragmentLedger(string $projectRoot): array
    {
        // 이 호출의 경고만 반영한다 — 소비처(`core:deploy-ledger-seed`)가 반환값의 `[]` 만으로는
        // 「완전한 원장(정상)」과 「원장 쓰기 실패」를 구분할 수 없어, 경고 통로로 실패를 가른다.
        $this->lastRootFileWarnings = [];

        $ledgerPath = $this->deployFragmentLedgerPath();

        // 원장이 있어도 조기 반환하지 않는다 — **원장에 없는 키만 채우는 병합**이다.
        //
        // `applyDeployFragments()` 는 세 지점(선택 적용에서 제외·소스 읽기 실패·복사 검증 실패)에서
        // `continue` 로 빠지며 그 조각을 원장에 넣지 않는다. 그러면 이후 **부분 원장**이 디스크에
        // 남는데, 종전 씨앗기는 `is_file($ledgerPath)` 하나로 조기 반환해 그 빠진 키를 영원히 채우지
        // 못했다(오류 없음). 다음 업데이트는 원장에 없는 그 조각을 「판정 불가 → 보존 + `.dist`」 로
        // 떨어뜨린다. 이미 있는 키는 건드리지 않으므로(운영자 수정본의 근거 해시가 그대로 유지된다)
        // 원장이 완전하면 아무것도 추가되지 않아 종전과 같은 멱등이다.
        $ledger = $this->readDeployFragmentLedger($ledgerPath);

        $deployDir = $projectRoot.DIRECTORY_SEPARATOR.'deploy';
        if (! File::isDirectory($deployDir)) {
            return [];
        }

        $added = [];
        foreach (File::allFiles($deployDir) as $file) {
            $name = $file->getFilename();
            if (str_ends_with($name, self::DEPLOY_FRAGMENT_DIST_SUFFIX) || str_ends_with($name, self::DEPLOY_FRAGMENT_DIST_NEW_SUFFIX)) {
                continue;
            }

            $relative = str_replace('\\', '/', $file->getRelativePathname());
            if (isset($ledger[$relative])) {
                continue; // 원장에 이미 있는 키는 채우지 않는다 — 운영자 수정본의 근거 해시를 보존한다
            }

            $path = $file->getPathname();
            if (is_file($path.self::DEPLOY_FRAGMENT_DIST_SUFFIX) || is_file($path.self::DEPLOY_FRAGMENT_DIST_NEW_SUFFIX)) {
                continue; // 사이드카가 있는 조각은 씨앗하지 않는다 — 운영자 수정본이 「코어가 쓴 그대로」로 읽힌다
            }

            $contents = @file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            $ledger[$relative] = hash('sha256', $contents);
            $added[] = $relative;
        }

        if ($added === []) {
            return []; // 채울 것이 없다 — 원장이 완전하거나 씨앗 대상 조각이 없다 (멱등)
        }

        ksort($ledger);

        return $this->writeDeployFragmentLedger($ledgerPath, $ledger) ? $added : [];
    }

    /**
     * 코어가 마지막으로 쓴 `deploy/**` 조각의 해시 원장 경로.
     *
     * `storage` 는 `update.protected_paths` 이자 `update.targets` 밖이라 적용·폴백·롤백
     * prune 어느 쪽도 건드리지 않는다. 잔존물 정리기(`StorageLeftoverPruneService`)는 그
     * 디렉토리의 **하위 디렉토리**만 보므로 이 파일은 그 모집단 밖이다 — 잔존물이 아니라
     * 다음 업데이트가 읽는 상태 파일이다.
     *
     * @return string 절대 경로
     */
    protected function deployFragmentLedgerPath(): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.'core-update'.DIRECTORY_SEPARATOR.self::DEPLOY_FRAGMENT_LEDGER);
    }

    /**
     * 설치본 `deploy/` 아래의 `.dist`·`.dist.new` 사본과 해시 원장을 걷습니다 (되돌리기 전용).
     *
     * 백업에는 `.dist` 가 없으므로 복원만으로는 남는다 — 되돌린 판본 옆에 미래 판본이 놓인다.
     * 원장도 지운다: 복원된 내용이 코어가 쓴 것인지 운영자 수정본인지 여기서는 알 수 없으므로,
     * 다음 업데이트가 **판정할 수 없음**(보존 + 안내) 쪽으로 떨어지게 둔다.
     *
     * @param  string  $projectRoot  프로젝트 루트
     * @return void 반환값 없음
     */
    protected function removeDeployFragmentSidecars(string $projectRoot): void
    {
        $deployDir = $projectRoot.DIRECTORY_SEPARATOR.'deploy';
        if (File::isDirectory($deployDir)) {
            foreach (File::allFiles($deployDir) as $file) {
                $name = $file->getFilename();
                if (str_ends_with($name, self::DEPLOY_FRAGMENT_DIST_SUFFIX) || str_ends_with($name, self::DEPLOY_FRAGMENT_DIST_NEW_SUFFIX)) {
                    @unlink($file->getPathname());
                }
            }
        }

        @unlink($this->deployFragmentLedgerPath());
    }

    /**
     * 정규화 경로가 skipPaths 중 하나와 같거나 그 하위인지 판정합니다.
     *
     * @param  string  $path  정규화된 상대 경로
     * @param  array<int, string>  $skipPaths  정규화된 skipPaths
     * @return bool 건너뛸 경로면 true
     */
    private function isWithinSkipPaths(string $path, array $skipPaths): bool
    {
        foreach ($skipPaths as $skip) {
            if ($path === $skip || str_starts_with($path, $skip.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * base 상대 skipPaths 중 target 하위 항목만 골라 target 접두사를 벗깁니다.
     *
     * `copyDirectory` 는 target 루트 기준 상대경로로 순회하므로 `buildTargetApplyList` 와 같은 방식으로
     * 접두사를 제거해야 매칭된다.
     *
     * @param  array<int, string>  $skipPaths  정규화된 base 상대 skipPaths
     * @param  string  $normalizedTarget  정규화된 target 경로 (예: `plugins/_bundled`)
     * @return array<int, string> target 루트 기준 상대경로 목록
     */
    private function stripTargetPrefix(array $skipPaths, string $normalizedTarget): array
    {
        $prefix = $normalizedTarget.'/';
        $out = [];
        foreach ($skipPaths as $skip) {
            if (str_starts_with($skip, $prefix)) {
                $out[] = substr($skip, strlen($prefix));
            }
        }

        return $out;
    }

    /**
     * 사이트의 `public/favicon.ico` 가 운영자가 직접 넣은 파일인지 판정합니다 (#434 D7).
     *
     * 비어 있지 않고 이번 배포본의 같은 파일과 내용이 다르면 운영자 파일이다. 비어 있는 파일(7.1 이하 기본값)이나
     * 배포본과 같은 파일은 운영자 파일이 아니므로 평소대로 갱신한다.
     *
     * @param  string  $sourcePath  배포본 루트 (_pending 추출본)
     * @return bool 보존해야 하면 true
     */
    private function isOperatorFavicon(string $sourcePath): bool
    {
        $current = base_path(self::PUBLIC_FAVICON_PATH);

        if (! is_file($current) || (int) @filesize($current) === 0) {
            return false;
        }

        $incoming = $sourcePath.DIRECTORY_SEPARATOR.self::PUBLIC_FAVICON_PATH;

        return ! is_file($incoming) || @md5_file($current) !== @md5_file($incoming);
    }

    /**
     * source 디렉토리의 최상위 항목 중 targets allowlist 에 누락된 신규 항목을 자동으로 적용합니다.
     *
     * @param  string  $sourcePath  _pending 추출 소스 루트
     * @param  array<string, bool>  $applied  이미 처리된 normalize 된 상대 경로 맵
     * @param  array<int, string>  $excludes  copyDirectory 내부 제외 목록
     * @param  \Closure|null  $onProgress  진행 콜백
     * @param  array<int, string>  $skipPaths  배치하지 않는 정규화 경로(base 상대) — 폴백 복사에서도 건너뛴다
     * @return void 반환값 없음
     */
    private function applyDiscoveredTopLevelPaths(string $sourcePath, array $applied, array $excludes, ?\Closure $onProgress, array $skipPaths = []): void
    {
        if (! File::isDirectory($sourcePath)) {
            return;
        }

        $protected = array_flip(array_map([$this, 'normalizeRelativePath'], (array) config('app.update.protected_paths', [])));
        $userExcludes = array_flip(array_map([$this, 'normalizeRelativePath'], $excludes));

        // `File::files()` 의 기본값은 숨김 파일 제외(`ignoreDotFiles(true)`)다 — 그대로 두면
        // 점으로 시작하는 최상위 파일이 이 폴백에서 **통째로 보이지 않는다.** 구버전 targets 로
        // 도는 첫 업데이트에서 루트 `index.php` 는 전달되고 루트 `.htaccess` 만 빠져, 보호 규칙
        // 없는 진입점이 남는다 (D25 의 「함께 갱신」이 그 전환에서만 깨진다). 열거가 넓어져도
        // 보호는 아래 protected_paths·excludes 검사가 그대로 담당한다.
        // 디렉토리 쪽은 넓히지 않는다 — 릴리즈 페이로드의 점 디렉토리는 전부 protected_paths
        // 대상이라 얻는 것이 없고, `File::directories()` 에는 대응 인자도 없다.
        $entries = array_merge(File::directories($sourcePath), File::files($sourcePath, hidden: true));

        foreach ($entries as $absPath) {
            $name = basename($absPath);
            $normalized = $this->normalizeRelativePath($name);

            if ($name === '.' || $name === '..') {
                continue;
            }
            if (isset($applied[$normalized])) {
                continue;
            }
            // 부모 디렉토리 단위로 이미 적용된 경우 (예: targets 에 'modules/_bundled' 가 있고 source 에 'modules' 디렉토리만 보일 때)
            // 자식 디렉토리를 통째로 다시 복사하지 않도록 검사
            if ($this->isCoveredByApplied($normalized, $applied)) {
                continue;
            }
            if (isset($protected[$normalized])) {
                continue;
            }
            if (isset($userExcludes[$normalized])) {
                continue;
            }
            if ($this->isWithinSkipPaths($normalized, $skipPaths)) {
                continue;
            }

            $dest = base_path($name);
            Log::channel('upgrade')->warning('[core-update] targets allowlist 누락 신규 항목 자동 적용', [
                'name' => $name,
                'reason' => 'parent process targets list did not include this path; falling back to source auto-discovery',
            ]);
            $onProgress?->__invoke('apply', $name.' (auto)');

            if (File::isDirectory($absPath)) {
                // 자동 발견 폴백은 destination orphan 정리 책임이 없다.
                // 부모 프로세스의 stale targets 누락을 메우는 안전망이므로,
                // base 의 활성 서브디렉토리(예: templates/sirsoft-basic) 가 source 에 없다고
                // 해서 삭제하면 사용자 데이터 영구 손실 위험. orphan 처리는 정상 targets 분기만 수행.
                //
                // `deploy` 는 정상 분기와 같은 보존 규칙을 탄다 — 구버전 targets 로 도는 첫
                // 업데이트가 운영자 수정본을 덮어쓰지 않도록 (루트 `.htaccess` 와 같은 사유).
                if ($normalized === 'deploy') {
                    $this->applyDeployFragments($absPath, $dest, null, $onProgress);
                } else {
                    FilePermissionHelper::copyDirectory($absPath, $dest, $onProgress, $excludes, removeOrphans: false, skipPaths: $this->stripTargetPrefix($skipPaths, $normalized));
                }
            } else {
                File::ensureDirectoryExists(dirname($dest));

                // 자동 발견 폴백 경로에서도 루트 마커 파일은 블록 교체 규칙(D25)을 지킨다 —
                // 구버전 targets 로 도는 첫 업데이트가 운영자 규칙을 덮어쓰지 않도록.
                $updater = CoreBackupHelper::rootMarkerFileUpdater($normalized);
                if ($updater !== null) {
                    $this->recordRootMarkerFileResult($updater, $updater::apply($absPath, $dest), $dest);
                } else {
                    if ($normalized === 'index.php') {
                        $this->recordRootIndexReplacement($dest);
                    }

                    $this->copyRootFileOrWarn($normalized, $absPath, $dest);
                }
            }
        }
    }

    /**
     * 상대 경로를 normalize 합니다 (선행/후행 슬래시 제거 + DIRECTORY_SEPARATOR 통일).
     *
     * @param  string  $path  정규화할 상대 경로
     * @return string 정규화된 상대 경로
     */
    private function normalizeRelativePath(string $path): string
    {
        return trim(str_replace(['\\', '/'], '/', $path), '/');
    }

    /**
     * 전체 적용 집합에서 특정 target 하위 항목만 추려, target 접두사를 제거한
     * `copyDirectory` 내부 상대경로 화이트리스트 맵을 만듭니다.
     *
     * `computeApplyList` 는 target 접두사를 포함한 경로(예: `public/.htaccess`)를 담지만,
     * `copyDirectory` 는 target 루트 기준 상대경로(`.htaccess`)로 항목을 순회하므로 접두사를
     * 벗겨야 매칭된다.
     *
     * @param  array<string, bool>  $applySet  전체 적용 대상 정규화 경로 lookup 맵
     * @param  string  $normalizedTarget  정규화된 target 경로 (예: `public`)
     * @return array<string, bool> target 내부 상대경로 화이트리스트 (빈 맵일 수 있음 → 전부 스킵)
     */
    private function buildTargetApplyList(array $applySet, string $normalizedTarget): array
    {
        $prefix = $normalizedTarget.'/';
        $out = [];
        foreach ($applySet as $rel => $_) {
            if (str_starts_with((string) $rel, $prefix)) {
                $out[substr((string) $rel, strlen($prefix))] = true;
            }
        }

        return $out;
    }

    /**
     * 정규화된 path 가 이미 처리된 상위/하위 path 와 관계가 있는지 검사합니다.
     *
     * 양방향 검사:
     *  - 방향 1 — path 가 applied 의 자식: applied=['templates'], path='templates/sub' (자식 중복 복사 방지)
     *  - 방향 2 — path 가 applied 의 부모: applied=['templates/_bundled'], path='templates'
     *    (부모 통째로 복사 시 활성 서브디렉토리 orphan 판정 회피)
     *
     * @param  string  $path  검사할 정규화 경로
     * @param  array<string, bool>  $applied  이미 처리된 경로 맵
     * @return bool 이미 처리된 경로와 겹치면 true
     */
    private function isCoveredByApplied(string $path, array $applied): bool
    {
        $segments = explode('/', $path);
        $accumulated = '';
        foreach ($segments as $segment) {
            $accumulated = $accumulated === '' ? $segment : $accumulated.'/'.$segment;
            if (isset($applied[$accumulated])) {
                return true;
            }
        }

        $prefix = $path.'/';
        foreach ($applied as $appliedPath => $_) {
            if (str_starts_with((string) $appliedPath, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * _pending 디렉토리에서 composer install을 실행합니다 (사전 검증용).
     *
     * @param  string  $pendingPath  _pending 내 소스 경로
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return void 반환값 없음
     *
     * @throws CoreUpdateOperationException 실행 실패 시
     */
    public function runComposerInstallInPending(string $pendingPath, ?\Closure $onProgress = null): void
    {
        $this->executeComposerInstall($pendingPath, $onProgress, noScripts: true);
    }

    /**
     * _pending 디렉토리의 vendor/ 를 구성합니다 (VendorResolver 경유).
     *
     * VendorMode에 따라:
     * - Composer: 기존 흐름 재사용 (runComposerInstallInPending + copyVendorFromPending)
     * - Bundled: vendor-bundle.zip 추출 (pending 디렉토리에 배치 후 운영 vendor로 복사 필요)
     * - Auto: EnvironmentDetector 기반 자동 결정
     *
     * 본 메서드 완료 후 vendor/ 는 _pending 내부에 위치하며,
     * 이후 copyVendorFromPending() 또는 bundle 내장 vendor 직접 사용으로 운영 반영됨.
     *
     * @param  string  $pendingPath  _pending 내 소스 경로
     * @param  VendorMode  $mode  요청된 vendor 설치 모드
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return VendorInstallResult 설치 결과 컨텍스트 (vendor 경로/모드 포함)
     *
     * @throws CoreUpdateOperationException 실행 실패 시
     */
    public function runVendorInstallInPending(
        string $pendingPath,
        VendorMode $mode = VendorMode::Auto,
        ?\Closure $onProgress = null,
    ): VendorInstallResult {
        $resolver = App::make(VendorResolver::class);

        $context = new VendorInstallContext(
            target: 'core',
            identifier: null,
            sourceDir: $pendingPath,
            targetDir: $pendingPath,
            requestedMode: $mode,
            composerBinaryHint: config('process.composer_binary'),
            operation: 'update',
        );

        // Composer 전략 시 기존 코어 로직을 콜백으로 전달 (코드 중복 방지)
        $composerExecutor = function (VendorInstallContext $ctx) use ($onProgress): VendorInstallResult {
            $this->runComposerInstallInPending($ctx->sourceDir, $onProgress);

            return new VendorInstallResult(
                mode: VendorMode::Composer,
                strategy: 'composer',
                packageCount: 0,
                details: ['pending_path' => $ctx->sourceDir],
            );
        };

        return $resolver->install($context, $composerExecutor);
    }

    /**
     * _pending과 운영 디렉토리의 composer.json/composer.lock이 동일한지 확인합니다.
     *
     * 두 파일이 모두 동일하면 composer install 및 vendor 복사를 스킵할 수 있습니다.
     *
     * @param  string  $pendingPath  _pending 내 소스 경로
     * @return bool 두 파일이 모두 동일하면 true
     */
    public function isComposerUnchangedForCore(string $pendingPath): bool
    {
        $pendingJson = $pendingPath.DIRECTORY_SEPARATOR.'composer.json';
        $pendingLock = $pendingPath.DIRECTORY_SEPARATOR.'composer.lock';
        $baseJson = base_path('composer.json');
        $baseLock = base_path('composer.lock');

        // composer.json 비교
        if (! file_exists($pendingJson) || ! file_exists($baseJson)) {
            return false;
        }

        if (md5_file($pendingJson) !== md5_file($baseJson)) {
            Log::channel('upgrade')->info('코어 업데이트: composer.json 변경 감지');

            return false;
        }

        // composer.lock 비교
        $pendingLockExists = file_exists($pendingLock);
        $baseLockExists = file_exists($baseLock);

        if ($pendingLockExists !== $baseLockExists) {
            Log::channel('upgrade')->info('코어 업데이트: composer.lock 존재 여부 불일치');

            return false;
        }

        if ($pendingLockExists && $baseLockExists) {
            if (md5_file($pendingLock) !== md5_file($baseLock)) {
                Log::channel('upgrade')->info('코어 업데이트: composer.lock 변경 감지');

                return false;
            }
        }

        Log::channel('upgrade')->info('코어 업데이트: composer 의존성 변경 없음 — 스킵 가능');

        return true;
    }

    /**
     * 운영 디렉토리에서 composer install을 실행합니다 (파일 덮어쓰기 후 autoload 갱신용).
     *
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return void 반환값 없음
     *
     * @throws CoreUpdateOperationException 실행 실패 시
     */
    public function runComposerInstall(?\Closure $onProgress = null): void
    {
        $this->executeComposerInstall(base_path(), $onProgress);
    }

    /**
     * _pending(또는 소스) 디렉토리의 vendor를 운영 디렉토리로 복사합니다.
     *
     * composer install을 2번 실행하는 대신, _pending에서 이미 설치된 vendor를
     * 운영 디렉토리로 직접 복사하여 효율성을 높입니다.
     *
     * @param  string  $pendingPath  _pending 또는 소스 디렉토리 경로
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return void 반환값 없음
     *
     * @throws CoreUpdateOperationException vendor 디렉토리가 없을 경우
     */
    public function copyVendorFromPending(string $pendingPath, ?\Closure $onProgress = null): void
    {
        $sourceVendor = $pendingPath.DIRECTORY_SEPARATOR.'vendor';
        $destVendor = base_path('vendor');

        if (! File::isDirectory($sourceVendor)) {
            throw new CoreUpdateOperationException('settings.core_update.source_vendor_missing');
        }

        $onProgress?->__invoke('vendor', 'vendor 디렉토리 복사 중...');

        // 기존 vendor/ 내용만 비움 — 디렉토리 자체는 유지하여 공유 호스팅에서
        // 프로젝트 루트 쓰기 권한이 없어도 작동하도록 한다.
        // (File::deleteDirectory($destVendor) 는 vendor/ 자체를 삭제하므로
        //  base_path() 에 쓰기 권한이 있어야 하는 문제를 회피)
        if (File::isDirectory($destVendor)) {
            File::cleanDirectory($destVendor);
        } else {
            File::ensureDirectoryExists($destVendor);
        }

        FilePermissionHelper::copyDirectory($sourceVendor, $destVendor, $onProgress);
    }

    /**
     * _pending 의 vendor 를 운영 vendor 와 통째로 바꿔 끼웁니다 (웹 업데이트 — 한 요청 안에 끝난다).
     *
     * 파일 단위 복사(`copyVendorFromPending()`)는 수만 개라 한 요청 시간 안에 끝나지 않을 수 있고, 중간에
     * 끊기면 다음 요청이 부팅할 vendor 가 반쪽이다. 이름 바꾸기는 즉시 끝나고 운영 vendor 는 언제나 한쪽 판
     * 전체다. 옛 vendor 는 `$parking` 으로 옮겨 두어 되돌리기가 그대로 다시 끼운다.
     *
     * 바꿔 끼우지 못하면(프로젝트 루트 쓰기 권한 없음, 다른 파일 시스템, 사용 중 파일) 운영 vendor 를 그대로
     * 둔 채 false 를 돌려준다 — 호출부가 복사로 넘어간다.
     *
     * @param  string  $pendingPath  _pending 경로
     * @param  string  $parking  옛 vendor 를 옮겨 둘 경로 (없는 경로)
     * @return bool 바꿔 끼웠으면 true
     *
     * @throws CoreUpdateOperationException vendor 디렉토리가 없을 경우
     */
    public function swapVendorFromPending(string $pendingPath, string $parking): bool
    {
        $sourceVendor = $pendingPath.DIRECTORY_SEPARATOR.'vendor';

        if (! File::isDirectory($sourceVendor)) {
            throw new CoreUpdateOperationException('settings.core_update.source_vendor_missing');
        }

        return FilePermissionHelper::swapDirectory(base_path('vendor'), $sourceVendor, $parking);
    }

    /**
     * 옮겨 둔 옛 vendor 를 운영 vendor 로 다시 끼웁니다 (웹 되돌리기).
     *
     * @param  string  $parking  `swapVendorFromPending()` 이 옛 vendor 를 옮겨 둔 경로
     * @param  string  $discard  새 vendor 를 옮겨 둘 경로 (없는 경로 — 정리 대상)
     * @return bool 다시 끼웠으면 true
     */
    public function swapVendorBack(string $parking, string $discard): bool
    {
        return FilePermissionHelper::swapDirectory(base_path('vendor'), $parking, $discard);
    }

    /**
     * 지정 디렉토리에서 composer install을 별도 프로세스로 실행합니다.
     *
     * @param  string  $workingDir  작업 디렉토리
     * @param  \Closure|null  $onProgress  진행 콜백
     * @param  bool  $noScripts  post-autoload-dump 등 스크립트 건너뛰기 (_pending용)
     * @return void 반환값 없음
     *
     * @throws CoreUpdateOperationException 실행 실패 시
     */
    protected function executeComposerInstall(string $workingDir, ?\Closure $onProgress = null, bool $noScripts = false): void
    {
        $onProgress?->__invoke('composer', __('settings.core_update.running_composer'));

        $composerBin = config('process.composer_binary');
        $phpBinary = config('process.php_binary', 'php');

        if ($composerBin) {
            if (str_contains($composerBin, ' ')) {
                // 공백 포함 = 전체 실행 명령어 (예: "/usr/local/php84/bin/php /home/user/g7/composer.phar")
                $composerCmd = $composerBin;
            } elseif (str_ends_with($composerBin, '.phar')) {
                // .phar인 경우 PHP 바이너리로 실행
                $composerCmd = escapeshellarg($phpBinary).' '.escapeshellarg($composerBin);
            } else {
                $composerCmd = escapeshellarg($composerBin);
            }
        } else {
            $composerCmd = 'composer';
        }

        $command = $composerCmd.' install --no-dev --optimize-autoloader --no-interaction'.($noScripts ? ' --no-scripts' : '').' 2>&1';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // composer 실행은 `proc_open` 없이는 성립하지 않는다. 무가드로 부르면 그 함수가
        // 막힌 호스팅에서 `Error` 로 요청이 죽어, 호출자(`VendorResolver`)가 번들 모드로
        // 폴백할 기회를 잃는다 — 무CLI 환경에서 vendor 가 빈 채 진행되는 것보다 나쁜
        // 상태다(사유가 남지 않는다). 여기서는 도메인 예외로 바꿔 폴백에 태운다 (#609 D34).
        if (! ExecutionEnvironment::hasFunction('proc_open')) {
            throw new CoreUpdateOperationException('settings.core_update.composer_unavailable');
        }

        $process = proc_open($command, $descriptors, $pipes, $workingDir, EnvironmentDetector::buildComposerEnv());

        if (! is_resource($process)) {
            throw new CoreUpdateOperationException('settings.core_update.composer_failed');
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        Log::channel('upgrade')->info('코어 업데이트: composer install 완료', [
            'working_dir' => $workingDir,
            'exit_code' => $exitCode,
            'output' => $output,
        ]);

        if ($exitCode !== 0) {
            throw new CoreUpdateOperationException('settings.core_update.composer_failed_with_output', ['output' => "\n".$output]);
        }
    }

    /**
     * 데이터베이스 마이그레이션을 실행합니다.
     *
     * @return void 반환값 없음
     */
    public function runMigrations(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }

    /**
     * 코어 역할/권한을 동기화합니다.
     *
     * RolePermissionSeeder와 달리 기존 데이터를 삭제하지 않고,
     * ExtensionRoleSyncHelper를 사용하여 user_overrides를 보존합니다.
     *
     * - 신규 권한: 생성
     * - 기존 권한: 항상 덮어쓰기 (Permission은 유저 수정 불가)
     * - 신규 역할: 생성
     * - 기존 역할: user_overrides에 없는 필드만 갱신
     * - 역할-권한 매핑: user_overrides에 기록된 개별 권한 식별자는 보호
     *
     * @return void 반환값 없음
     */
    public function syncCoreRolesAndPermissions(): void
    {
        $roleSyncHelper = app(ExtensionRoleSyncHelper::class);
        $permConfig = $this->getCorePermissionDefinitions();
        $moduleConfig = $permConfig['module'];

        // 1레벨: 코어 모듈 권한
        $coreModule = $roleSyncHelper->syncPermission(
            identifier: $moduleConfig['identifier'],
            newName: $moduleConfig['name'],
            newDescription: $moduleConfig['description'],
            extensionType: ExtensionOwnerType::Core,
            extensionIdentifier: 'core',
            otherAttributes: [
                'type' => isset($moduleConfig['type'])
                    ? PermissionType::from($moduleConfig['type'])
                    : PermissionType::Admin,
                'order' => $moduleConfig['order'],
                'parent_id' => null,
            ],
        );

        // 모든 리프 권한 식별자를 수집 (역할-권한 매핑용)
        $allLeafIdentifiers = [];

        // 2레벨: 카테고리 + 3레벨: 개별 권한
        $categories = $permConfig['categories'];

        foreach ($categories as $categoryData) {
            // 카테고리 type 결정 우선순위:
            // 1. 카테고리에 명시적 type 필드
            // 2. 모든 하위 권한이 동일한 type → 그 type
            // 3. 그 외 → admin (기본값)
            $childTypes = collect($categoryData['permissions'] ?? [])
                ->map(fn ($p) => $p['type'] ?? 'admin')
                ->unique();

            $categoryType = ($childTypes->count() === 1 && $childTypes->first() === 'user')
                ? PermissionType::User
                : PermissionType::Admin;

            if (isset($categoryData['type'])) {
                $categoryType = PermissionType::from($categoryData['type']);
            }

            $category = $roleSyncHelper->syncPermission(
                identifier: $categoryData['identifier'],
                newName: $categoryData['name'],
                newDescription: $categoryData['description'],
                extensionType: ExtensionOwnerType::Core,
                extensionIdentifier: 'core',
                otherAttributes: [
                    'type' => $categoryType,
                    'order' => $categoryData['order'],
                    'parent_id' => $coreModule->id,
                ],
            );

            foreach ($categoryData['permissions'] as $permData) {
                // 개별 권한 type: 명시 우선, 없으면 카테고리 type 상속
                $permissionType = isset($permData['type'])
                    ? PermissionType::from($permData['type'])
                    : $categoryType;

                $roleSyncHelper->syncPermission(
                    identifier: $permData['identifier'],
                    newName: $permData['name'],
                    newDescription: $permData['description'],
                    extensionType: ExtensionOwnerType::Core,
                    extensionIdentifier: 'core',
                    otherAttributes: [
                        'type' => $permissionType,
                        'order' => $permData['order'],
                        'parent_id' => $category->id,
                        'resource_route_key' => $permData['resource_route_key'] ?? null,
                        'owner_key' => $permData['owner_key'] ?? null,
                    ],
                );

                $allLeafIdentifiers[] = $permData['identifier'];
            }
        }

        // 2. 코어 역할 동기화 (user_overrides 보존)
        $coreRoles = $this->getCoreRoleDefinitions();

        // 역할-권한 매핑 구축: permIdentifier → [roleIdentifier, ...]
        $permissionRoleMap = [];

        foreach ($coreRoles as $roleDef) {
            $roleSyncHelper->syncRole(
                identifier: $roleDef['identifier'],
                newDescription: $roleDef['description'],
                newName: $roleDef['name'],
                extensionType: ExtensionOwnerType::Core,
                extensionIdentifier: 'core',
                otherAttributes: $roleDef['attributes'] ?? [],
            );

            // 역할-권한 매핑 수집
            $rolePerms = $roleDef['permissions'];
            if ($rolePerms === 'all_leaf') {
                $rolePerms = $allLeafIdentifiers;
            }

            if (is_array($rolePerms)) {
                foreach ($rolePerms as $permIdentifier) {
                    $permissionRoleMap[$permIdentifier][] = $roleDef['identifier'];
                }
            }
        }

        // 3. 역할-권한 할당 동기화 (user_overrides 보호)
        // 코어: core/core 소유 전체 권한을 diff 범위로 사용해 이관된 구 식별자도 detach 가능
        $allCorePermIdentifiers = app(PermissionRepositoryInterface::class)
            ->getByExtension(ExtensionOwnerType::Core, 'core')
            ->pluck('identifier')
            ->all();
        $roleSyncHelper->syncAllRoleAssignments($permissionRoleMap, $allCorePermIdentifiers);

        // 완전 동기화: config 에서 제거된 stale 권한 삭제 (user_overrides 보존)
        // leaf + 카테고리 + 모듈 레벨 식별자를 모두 수집해서 diff
        $allDefinedIdentifiers = array_merge(
            [$moduleConfig['identifier']],
            array_column($categories, 'identifier'),
            $allLeafIdentifiers,
        );
        $deletedPerms = $roleSyncHelper->cleanupStalePermissions(
            ExtensionOwnerType::Core,
            'core',
            $allDefinedIdentifiers,
        );

        // 완전 동기화: config 에서 제거된 stale 역할 삭제 (user_overrides + user_roles 참조 보존)
        $definedRoleIdentifiers = array_column($coreRoles, 'identifier');
        $deletedRoles = $roleSyncHelper->cleanupStaleRoles(
            ExtensionOwnerType::Core,
            'core',
            $definedRoleIdentifiers,
        );

        Log::channel('upgrade')->info('코어 역할/권한 동기화 완료', [
            'stale_permissions_deleted' => $deletedPerms,
            'stale_roles_deleted' => $deletedRoles,
        ]);

        // 스코프가 붙었지만 어디서도 강제되지 않는 부여를 드러낸다 — 운영자가 역할 편집으로
        // 만든 부여일 수 있어 업데이트를 막지는 않는다(ScopedPermissionEnforcement 참고).
        $enforcement = app(ScopedPermissionEnforcement::class);
        $unenforced = $enforcement->findUnenforcedScopedGrants();
        if ($unenforced !== []) {
            Log::channel('upgrade')->warning('스코프가 강제되지 않는 권한 부여가 있습니다', ['grants' => $unenforced]);
        }

        // 비회원 역할은 스코프를 가질 수 없다(7.1.1) — 업그레이드 스텝이 회수한 뒤에도 DB 를 직접 고쳐 남은 행을 드러낸다.
        $guestScoped = $enforcement->findGuestScopedGrants();
        if ($guestScoped !== []) {
            Log::channel('upgrade')->warning('비회원 역할에 권한 범위가 붙은 부여가 있습니다 — 비회원은 범위를 가질 수 없어 실제로는 전체 접근입니다', ['grants' => $guestScoped]);
        }
    }

    /**
     * 코어 메뉴를 동기화합니다.
     *
     * CoreAdminMenuSeeder와 달리 기존 데이터를 삭제하지 않고,
     * ExtensionMenuSyncHelper를 사용하여 user_overrides를 보존합니다.
     *
     * - 신규 메뉴: 생성
     * - 기존 메뉴: user_overrides에 없는 필드(name, icon, order, url)만 갱신
     *
     * @return void 반환값 없음
     */
    public function syncCoreMenus(): void
    {
        $menuSyncHelper = app(ExtensionMenuSyncHelper::class);
        $coreMenus = $this->getCoreMenuDefinitions();

        foreach ($coreMenus as $menuData) {
            $menuSyncHelper->syncMenuRecursive(
                menuData: $menuData,
                extensionType: ExtensionOwnerType::Core,
                extensionIdentifier: 'core',
                parentId: null,
            );
        }

        // 완전 동기화: config 에서 제거된 stale 메뉴 삭제 (user_overrides 보존)
        $currentSlugs = $menuSyncHelper->collectSlugsRecursive($coreMenus);
        $deleted = $menuSyncHelper->cleanupStaleMenus(
            ExtensionOwnerType::Core,
            'core',
            $currentSlugs,
        );

        Log::channel('upgrade')->info('코어 메뉴 동기화 완료', ['stale_deleted' => $deleted]);
    }

    /**
     * 디스크의 config/core.php 를 재로드하고 config-driven 코어 도메인 데이터를 재동기화합니다.
     *
     * Laravel 은 프로세스 시작 시점에 로드한 config 를 재로드하지 않으므로,
     * 업데이트로 config/core.php 가 교체되어도 현재 프로세스의 `config('core.*')`
     * 는 이전 값을 반환한다. 본 메서드는 디스크 값을 다시 require 하여 Config
     * Repository 에 주입한 뒤 다음 도메인 동기화를 일괄 수행한다:
     *
     *   - syncCoreRolesAndPermissions / syncCoreMenus  ← config('core.permissions|roles|menus')
     *   - NotificationDefinitionSeeder                  ← config('core.notification_definitions') (본인인증 알림 포함)
     *   - IdentityPolicySeeder                          ← config('core.identity_policies')
     *   - CoreScheduleSeeder                            ← config('core.schedules') (7.2.0 — 코어 예약 카탈로그)
     *   - CoreFieldDefinitionSeeder                     ← config('core.fields') (7.2.0 — 코어 회원 항목)
     *
     * 모든 시더는 멱등 upsert + user_overrides 보존 패턴이라 정상 환경에서 재실행해도 무해.
     * 각 도메인은 독립 try/catch + Log::warning 으로 격리 — 한 도메인 실패가 다른 도메인을
     * 막지 않는다. 각 시더는 호출 전 테이블 존재 가드로 마이그레이션 미실행 환경에서도 안전.
     *
     * 주 사용처:
     *   1. CoreUpdateCommand Step 9 의 정상 경로 — applyUpdate(Step 7) 로 디스크가 교체된 직후
     *      부모 프로세스가 fresh config 로 sync 를 수행하기 위한 표준 진입점. syncCoreRolesAndPermissions
     *      / syncCoreMenus 직접 호출 금지 — 부모 메모리의 stale config 로 sync 가 돌면 신규
     *      권한·메뉴가 누락된다 (회귀 차단은 audit 룰 `core-update-command-direct-sync` 가 자동 적발).
     *   2. CoreUpdateCommand Step 10 의 spawn fallback — proc_open 실패 시 in-process 로
     *      upgrade step 실행 후 config 재주입 + 재동기화.
     *   3. 코어 upgrade step 의 박제 보정 호출 — 이전 버전 CoreUpdateCommand 의 stale 부모
     *      sync 결함을 spawn 자식의 fresh config 로 사후 보정. 멱등이라 정상 환경 무해.
     *   4. 수동 복구 도구 — 운영자가 코어 권한·메뉴·알림·IDV 정의 누락 의심 시 호출.
     *
     * ⚠ 경로 A(beta.1 → beta.2) 에서는 직접 호출 금지. beta.1 메모리에는
     * 본 메서드가 존재하지 않으므로 Fatal 발생. 해당 경로의 upgrade step 은
     * 파일 내부 로컬 로직으로 config 재로드 + sync 재호출을 직접 구현해야 한다.
     *
     * @return void 반환값 없음
     */
    public function reloadCoreConfigAndResync(): void
    {
        $path = config_path('core.php');
        if (! File::exists($path)) {
            Log::channel('upgrade')->warning('reloadCoreConfigAndResync: config/core.php 미존재 — 스킵');

            return;
        }

        $fresh = require $path;
        if (! is_array($fresh)) {
            Log::channel('upgrade')->warning('reloadCoreConfigAndResync: config/core.php 반환값이 배열이 아님 — 스킵');

            return;
        }

        config(['core' => $fresh]);

        try {
            $this->syncCoreRolesAndPermissions();
        } catch (\Throwable $e) {
            Log::channel('upgrade')->warning('reloadCoreConfigAndResync: 권한 재동기화 실패', ['error' => $e->getMessage()]);
            LifecycleWarnings::current()->add('core_resync', 'permissions', $e->getMessage());
        }

        try {
            $this->syncCoreMenus();
        } catch (\Throwable $e) {
            Log::channel('upgrade')->warning('reloadCoreConfigAndResync: 메뉴 재동기화 실패', ['error' => $e->getMessage()]);
            LifecycleWarnings::current()->add('core_resync', 'menus', $e->getMessage());
        }

        try {
            if (Schema::hasTable('notification_definitions')) {
                (new NotificationDefinitionSeeder)->run();
            }
        } catch (\Throwable $e) {
            Log::channel('upgrade')->warning('reloadCoreConfigAndResync: 알림 정의 재시딩 실패', ['error' => $e->getMessage()]);
            LifecycleWarnings::current()->add('core_resync', 'notification_definitions', $e->getMessage());
        }

        try {
            if (Schema::hasTable('identity_policies')) {
                (new IdentityPolicySeeder)->run();
            }
        } catch (\Throwable $e) {
            Log::channel('upgrade')->warning('reloadCoreConfigAndResync: IDV 정책 재시딩 실패', ['error' => $e->getMessage()]);
            LifecycleWarnings::current()->add('core_resync', 'identity_policies', $e->getMessage());
        }

        // 코어 예약 카탈로그 — 스키마가 7.2.0 이전이면 헬퍼가 스스로 건너뛴다(업그레이드 마이그레이션 전 구간).
        try {
            if (Schema::hasTable('schedules')) {
                (new CoreScheduleSeeder)->run();
            }
        } catch (\Throwable $e) {
            Log::channel('upgrade')->warning('reloadCoreConfigAndResync: 코어 스케줄 재시딩 실패', ['error' => $e->getMessage()]);
        }

        // 코어 회원 항목 선언 — 운영자가 고친 속성은 보존되고 운영자가 만든 항목은 건드리지 않는다(7.2.0).
        try {
            if (Schema::hasTable('field_definitions')) {
                (new CoreFieldDefinitionSeeder)->run();
            }
        } catch (\Throwable $e) {
            Log::channel('upgrade')->warning('reloadCoreConfigAndResync: 코어 회원 항목 재시딩 실패', ['error' => $e->getMessage()]);
            LifecycleWarnings::current()->add('core_resync', 'field_definitions', $e->getMessage());
        }

        // 본인인증 메시지는 7.1.0 부터 알림 정의(category=identity)로 선언되어 위 NotificationDefinitionSeeder 가
        // 함께 동기화한다 — 옛 IDV 메시지 시더 호출은 없어졌다(7.0.x 부모 프로세스용 호환 심은 시더 클래스에 남아 있다).
    }

    /**
     * 디스크의 `config/app.php` 에서 `update.{$key}` 를 직접 읽습니다.
     *
     * spawn 자식(`core:execute-upgrade-steps`)은 부모가 spawn 전에 config 캐시를 비우지 않은 경우
     * (7.0.9 이하 부모) 이전 버전 설치본의 `bootstrap/cache/config.php` 로 부팅한다. 그 상태의
     * `config('app.update.*')` 는 캐시에 박힌 옛 목록이라, 신버전이 추가한 항목(7.0.10 의
     * `public/build/ext` 쓰기 권한 디렉토리)이 자식의 권한 정상화에서 빠진다 (2026-09-06 서버 실측).
     * 본 메서드는 메모리 config 를 건드리지 않고 디스크 파일을 평가해 신버전 값을 돌려준다.
     *
     * 캐시 부팅에서는 `.env` 도 로드되지 않으므로(`LoadEnvironmentVariables` 가 건너뜀), 운영자의
     * `G7_UPDATE_*` 재정의가 `config/app.php` 의 `env()` 에 보이도록 `.env` 를 먼저 불변 로드한다 —
     * 이미 프로세스 env 에 있는 값(부모가 넘긴 `APP_VERSION` 등)은 덮어쓰지 않는다.
     *
     * @param  string  $key  `config/app.php` 의 `update` 배열 키
     * @param  mixed  $default  파일에 키가 없을 때 돌려줄 값
     * @return mixed 디스크 config 의 값
     */
    public function freshDiskUpdateConfig(string $key, mixed $default = []): mixed
    {
        $path = config_path('app.php');
        if (! File::exists($path)) {
            return $default;
        }

        if (app()->configurationIsCached() && File::exists(base_path('.env'))) {
            try {
                // audit:allow service-direct-data-access reason: Dotenv 는 모델이 아니라 .env 파서 — 캐시 부팅에서 로드되지 않은 .env 를 불변 로드한다 (프로세스 env 우선)
                Dotenv::create(Env::getRepository(), base_path(), '.env')->safeLoad();
            } catch (\Throwable $e) {
                Log::channel('upgrade')->warning('freshDiskUpdateConfig: .env 로드 실패 — 프로세스 env 만으로 평가', ['error' => $e->getMessage()]);
            }
        }

        $fresh = require $path;
        if (! is_array($fresh)) {
            return $default;
        }

        return $fresh['update'][$key] ?? $default;
    }

    /**
     * 코어 업그레이드 스텝을 실행합니다.
     * 각 스텝에서 환경설정 파일 생성, 데이터 마이그레이션 등을 수행합니다.
     *
     * 예외 전파 정책:
     *  - 일반 예외 (\Throwable): 그대로 상위 전파. CoreUpdateCommand 가 catch 후 롤백.
     *  - UpgradeHandoffException: 그대로 상위 전파. CoreUpdateCommand 가 catch 후 롤백 없이
     *    .env APP_VERSION 을 afterVersion 으로 고정, maintenance 해제, 사용자에게 재실행 안내.
     *    즉 "해당 스텝 직전까지의 상태를 확정 + 재진입 지점 지정" 시나리오에 사용.
     *
     * @param  string  $fromVersion  시작 버전
     * @param  string  $toVersion  종료 버전
     * @param  \Closure|null  $onStep  각 스텝 실행 시 콜백 (버전 문자열 전달)
     * @param  bool  $force  true 시 fromVersion == toVersion이면 해당 버전 스텝도 포함
     * @param  \Closure|null  $onDiscovered  범위 내 발견된 스텝 파일 수를 실행 전 1회 통지 (int 전달)
     * @param  bool  $freshProcess  새로 시작한 프로세스(`core:execute-upgrade-steps`)에서 부르면 true — 낡은 메모리 가드를 건너뛴다
     * @param  \Closure|null  $onSkipped  실행 대장에 이미 끝난 것으로 남아(같은 DB 의 다른 서버·실행) 건너뛴 스텝마다 호출 (버전 문자열 전달)
     * @return void 반환값 없음
     */
    public function runUpgradeSteps(string $fromVersion, string $toVersion, ?\Closure $onStep = null, bool $force = false, ?\Closure $onDiscovered = null, bool $freshProcess = false, ?\Closure $onSkipped = null): void
    {
        // 새로 시작한 프로세스(`core:execute-upgrade-steps` — spawn 자식이든 운영자 단독 실행이든)는 디스크의 새 코드를
        // 읽으므로 아래 「낡은 메모리」 가드의 대상이 아니다. 가드는 옛 코드를 이미 메모리에 올린 `core:update` 부모가
        // in-process 로 스텝을 돌릴 때만 의미가 있다. 종전에는 단독 실행도 .env 의 옛 APP_VERSION 으로 판정돼
        // 「수동 재개」 안내대로 실행해도 가드가 다시 막았다(#888).
        // 부모 in-process fallback 진입 시 stale 메모리 가드.
        //
        // 현재 메모리의 `config('app.version')` 이 toVersion 보다 낮으면 부모는 stale 코드를
        // 보유한 채 step 을 실행 중. upgrade step 안에서 신규 메서드 호출 시 fatal 위험.
        // `spawn_failure_mode` 와 연동하여 abort/fallback 분기.
        //
        // spawn 자식 (ExecuteUpgradeStepsCommand) 은 spawn env 의 APP_VERSION=toVersion 을 받아
        // 부팅되므로 memoryVersion === toVersion → 가드 미발동이어야 한다.
        //
        // 판독은 `CoreVersionChecker::getCoreVersion()` (env 우선, config 폴백) 으로 한다.
        // `config('app.version')` 만 읽으면 안 된다 — 부모는 spawn 전(Step 10)에 config 캐시를
        // 비우지 않으므로, 이전 버전 설치본의 `bootstrap/cache/config.php` 가 있으면 자식은
        // 그 캐시로 부팅해 config 에는 fromVersion 이 박혀 있고 env 오버라이드는 무시된다.
        // 그 상태에서 config 만 보면 정상 spawn 자식을 stale 부모로 오판해 abort 한다
        // (7.0.9→7.0.10 실사례, 2026-09-06 — 스텝 0건 릴리즈에서도 중단). 이전 릴리즈에서는
        // 자식 진입부의 `config:cache` 가 전역 Container 를 일회용 앱으로 바꿔 놓는 부수효과로
        // `config()` 가 우연히 env 기반 값을 읽어 가드가 침묵했을 뿐이며, 그 부수효과는
        // `ConfigCacheHelper::withPreservedContainer` 가 제거했다.
        //
        // 부모 in-process fallback 에서는 env 가 .env 의 APP_VERSION(= 아직 fromVersion, Step 11 전)
        // 이므로 가드가 그대로 발동한다.
        $memoryVersion = CoreVersionChecker::getCoreVersion() ?: (string) config('app.version', $fromVersion);
        if (! $freshProcess && version_compare($memoryVersion, $toVersion, '<')) {
            $mode = self::spawnFailureMode();
            $message = sprintf(
                '[core-update] runUpgradeSteps 부모 메모리 stale 감지 — memory=%s, target=%s, mode=%s',
                $memoryVersion,
                $toVersion,
                $mode,
            );

            if ($mode === 'abort') {
                throw new UpgradeHandoffException(
                    afterVersion: $fromVersion,
                    reason: $message.' — fail-fast 모드. 수동 재개로 진행하세요. '.self::resumeCommandForShells($fromVersion, $toVersion),
                    resumeCommand: self::resumeCommand($fromVersion, $toVersion),
                );
            }

            Log::channel('upgrade')->warning($message.' — fallback 모드. upgrade step 안에서 신규 메서드 호출 시 fatal 가능.');
        }

        $upgradesPath = base_path('upgrades');

        if (! File::isDirectory($upgradesPath)) {
            // upgrades 디렉토리 자체가 없으면 발견된 스텝 0건 — 정상 통지 후 종료.
            $onDiscovered?->__invoke(0);

            return;
        }

        // force + 동일 버전: 해당 버전의 스텝도 포함 (>= 비교)
        $sameVersion = version_compare($fromVersion, $toVersion, '==');

        $steps = [];
        $files = File::files($upgradesPath);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filename = $file->getFilenameWithoutExtension();
            if (! preg_match('/^Upgrade_(\d+)_(\d+)_(\d+)(?:_([a-zA-Z]\w*(?:_\d+)*))?$/', $filename, $matches)) {
                continue;
            }

            $version = "{$matches[1]}.{$matches[2]}.{$matches[3]}";

            if (! empty($matches[4])) {
                $version .= '-'.str_replace('_', '.', $matches[4]);
            }

            $included = $force && $sameVersion
                ? version_compare($version, $toVersion, '==')
                : version_compare($version, $fromVersion, '>') && version_compare($version, $toVersion, '<=');

            if ($included) {
                require_once $file->getPathname();
                $className = "App\\Upgrades\\{$filename}";

                // 범위 안 단계 파일이 있는데 클래스가 없거나 인터페이스 미구현이면 업데이트 실패
                // (7.1.3 — 예전엔 조용히 건너뛰어 데이터는 옛 형태인데 버전만 올라갔다)
                try {
                    UpgradeStepClassMissingException::assertUsable($file->getPathname(), $className, UpgradeStepInterface::class);
                } catch (UpgradeStepClassMissingException $e) {
                    throw new CoreUpdateOperationException(
                        $e->interface === null ? 'extensions.errors.upgrade_step_class_missing' : 'extensions.errors.upgrade_step_interface_missing',
                        ['file' => $e->stepFile, 'class' => $e->class, 'interface' => (string) $e->interface],
                        $e,
                    );
                }

                if (class_exists($className)) {
                    $instance = new $className;
                    if ($instance instanceof UpgradeStepInterface) {
                        // 7.0.0-beta.5 부터 신규 업그레이드 스텝은 AbstractUpgradeStep 상속 의무.
                        // "각 스텝별 동작 100% 동일 보장" invariant 를 위해 카탈로그/변환/핫픽스를
                        // upgrades/data/{version}/ 의 버전 namespace 아래로 격리하도록 강제한다.
                        //
                        // 미상속 시점에 즉시 throw → core:update 전체 중단 → 상위 백업 복원.
                        // 상세: docs/extension/upgrade-step-guide.md §12
                        if (version_compare($version, '7.0.0-beta.5', '>=')
                            && ! $instance instanceof AbstractUpgradeStep) {
                            throw new CoreUpdateOperationException(sprintf(
                                'Upgrade step %s must extend App\\Extension\\AbstractUpgradeStep '
                                .'(introduced in 7.0.0-beta.5). See docs/extension/upgrade-step-guide.md §12.',
                                $version,
                            ));
                        }

                        $steps[$version] = $instance;
                    }
                }
            }
        }

        uksort($steps, 'version_compare');

        // 범위 내에서 발견된 스텝 파일 수(discovered)를 먼저 통지한다.
        // 호출자(spawn 자식)는 이 값으로 "스텝 파일이 애초에 없어 0건(정상)" 과 "스텝 파일은
        // 있는데 실행이 0건(비정상 — gnuboard/g7#28 silent skip)" 을 구분한다. onStep 은 실제 실행
        // 건마다 호출되므로 executed 수만 세며, discovered 는 실행 전에 1회 통지한다.
        $onDiscovered?->__invoke(count($steps));

        $context = new UpgradeContext($fromVersion, $toVersion);

        // 실행 대장 — DB 를 함께 쓰는 다른 서버가 이미 끝낸 스텝은 건너뛰고, 실행 선점은 유니크 삽입으로 한다(7.2.0).
        // 내용 지문을 함께 넘긴다 — 검수 서버의 같은 판 --force 재배포는 같은 내용으로 끝난 스텝을 건너뛴다(#888)
        $ledger = app(UpgradeStepRunLedger::class);

        foreach ($steps as $version => $step) {
            $ran = $ledger->run('core', '', (string) $version, UpgradeStepRunLedger::stepKey($step, (string) $version), $force, function () use ($onStep, $version, $step, $context): void {
                $onStep?->__invoke($version);
                Log::channel('upgrade')->info("코어 업그레이드 스텝 실행: {$version}");
                $step->run($context->withCurrentStep($version));
            }, fingerprint: UpgradeStepRunLedger::fingerprint($step, (string) $version));

            // 다른 실행이 이미 끝낸 스텝은 실행 건수가 아니라 건너뜀으로 따로 알린다 — 부모의 「발견했는데 실행 0건」 가드가
            // 공유 DB 의 두 번째 서버를 실패로 읽지 않게 한다(#888)
            if (! $ran) {
                $onSkipped?->__invoke((string) $version);
            }
        }
    }

    /**
     * .env 파일의 APP_VERSION을 갱신합니다.
     *
     * 디스크만 고치면 **이 프로세스는 여전히 옛 값을 들고 있다.** Dotenv 의 immutable
     * 로더는 이미 저장소에 실린 키를 덮지 않으므로, 같은 요청 안에서 `config:cache` 를
     * 구우면(`ConfigCacheHelper::rebuild()` 가 그 커맨드를 새 애플리케이션에서 돌려도
     * `Env::getRepository()` 는 프로세스 전역이다) **옛 버전이
     * 새 캐시에 박제된다.** 그러면 업데이트가 성공했는데도 사이트가 옛 버전으로 동작하고,
     * 그 상태는 다음 수명주기 이벤트가 캐시를 다시 구울 때까지 이어진다 — 버전 표시만이
     * 아니라 새 코어가 추가한 config 값도 캐시에 없는 채로 남는다.
     *
     * 그래서 디스크와 **함께** 프로세스의 환경 저장소도 갱신한다. 채널은 `$_ENV`/`$_SERVER`/
     * `putenv` 셋이다 — 캐시 부팅한 부모(Dotenv 미실행)도 두 슈퍼전역 대입만으로 이후
     * `config:cache` 굽기가 새 값을 본다(`env()` 는 `$_ENV` 를 먼저 읽는다). `putenv` 는 차단된
     * 호스팅에서 호출 자체가 치명 오류라 `ExecutionEnvironment::hasFunction()` 가드 아래에서만
     * 부른다(설계 결정 2026-09-11: 세 채널 유지 + 가드).
     * (2026-09-07 실측: `config:clear` 후 7.0.10 → 7.0.11 업데이트가 config 캐시에 7.0.10 을 구웠다.)
     *
     * @param  string  $version  새 버전
     * @return void 반환값 없음
     */
    public function updateVersionInEnv(string $version): void
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath)) {
            return;
        }

        $this->syncVersionIntoProcessEnvironment($version);

        $content = File::get($envPath);

        if (preg_match('/^APP_VERSION=.*/m', $content)) {
            $content = preg_replace('/^APP_VERSION=.*/m', "APP_VERSION={$version}", $content);
        } else {
            $content .= "\nAPP_VERSION={$version}\n";
        }

        File::put($envPath, $content);
    }

    /**
     * 이 프로세스의 환경 저장소에 새 `APP_VERSION` 을 반영합니다.
     *
     * `env('APP_VERSION')` 은 `Env::getRepository()` 를 읽고, 그 저장소의 어댑터 사슬에는
     * `$_ENV` · `$_SERVER` 가 들어 있다. 두 슈퍼전역을 함께 고쳐야 이후의 config 굽기와
     * 서브프로세스 자식(`SubprocessCommandRunner::inheritedEnvironment()` 가 `$_ENV` 를
     * 병합한다)이 같은 값을 본다. 프로세스 환경(`putenv`)도 같은 값으로 맞추되, 그 함수는
     * 하드닝 프로필의 표준 차단 목록에 있으므로 가용할 때만 부른다 — PHP 8 은 차단 함수를
     * 함수 테이블에서 제거해 무가드 호출이 치명 오류가 된다.
     *
     * 런타임 config 미러(`app.version`)도 함께 맞춘다 — 이미 부팅된 config 저장소는
     * `.env` 를 다시 읽지 않으므로, 이 값을 고치지 않으면 같은 요청의 나머지 구간이
     * 옛 버전으로 동작한다.
     *
     * @param  string  $version  새 버전
     * @return void 반환값 없음
     */
    private function syncVersionIntoProcessEnvironment(string $version): void
    {
        $_ENV['APP_VERSION'] = $version;
        $_SERVER['APP_VERSION'] = $version;
        if (ExecutionEnvironment::hasFunction('putenv')) {
            putenv('APP_VERSION='.$version);
        }

        config(['app.version' => $version]);
    }

    /**
     * 백업에서 코어 파일을 복원합니다.
     *
     * @param  string  $backupPath  백업 경로
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return array{failed_targets: list<string>, root_file_warnings: list<string>} 복원 실패한 target 목록(빈 배열이면 전체 성공)과 이 호출에서 누적된 루트 진입·보호 파일 경고(`getLastRootFileWarnings()` 와 같은 값 — 커맨드가 아닌 소비처를 위해 반환값에도 싣는다)
     */
    public function restoreFromBackup(string $backupPath, ?\Closure $onProgress = null): array
    {
        $result = $this->restoreFromBackupChunk($backupPath, [], null, null, [], $onProgress);

        return [
            'failed_targets' => $result['failed_targets'],
            'root_file_warnings' => $result['root_file_warnings'],
        ];
    }

    /**
     * 백업에서 나눠서 복원합니다 (웹 되돌리기 — 한 요청의 시간 몫 안에서 멈춘다).
     *
     * `restoreFromBackup()` 은 이 함수를 멈춤 없이 부른다. 다 끝난 호출만 루트 진입·보호 파일 경고를
     * 싣는다 — 끝나기 전의 디스크는 아직 판정할 상태가 아니다.
     *
     * @param  string  $backupPath  백업 경로
     * @param  array<string, mixed>  $progress  직전 호출이 돌려준 진행분 (처음이면 빈 배열)
     * @param  \Closure|null  $shouldPause  멈출 때인지 묻는 함수 (null 이면 끝까지)
     * @param  array<int, string>|null  $priorityFiles  먼저 되돌릴 base 상대 파일 경로 (웹은 증분 적용 목록)
     * @param  array<int, string>  $skipTargets  덮어쓰지 않을 target (웹은 바꿔 끼워 되돌린 vendor 등)
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return array{done: bool, phase: string, priority_index: int, target: int, cursor: string|null, failed_targets: list<string>, root_file_warnings: list<string>} 진행분
     */
    public function restoreFromBackupChunk(
        string $backupPath,
        array $progress = [],
        ?\Closure $shouldPause = null,
        ?array $priorityFiles = null,
        array $skipTargets = [],
        ?\Closure $onProgress = null,
    ): array {
        $skip = array_map(static fn ($t): string => trim((string) $t), $skipTargets);
        $targets = array_values(array_filter(
            array_merge(
                config('app.update.targets', []),
                config('app.update.backup_only', [])
            ),
            static fn ($t): bool => ! in_array(trim((string) $t), $skip, true),
        ));
        $excludes = config('app.update.excludes', []);

        // 경고 버퍼는 **호출 단위**다(프로퍼티 주석이 선언한 규약). 적용 단계에서 쌓인
        // 경고를 그대로 두면 자동 롤백에서 같은 통로로 다시 나가는데, 그 문구는 이미
        // 사실이 아니다 — 그 파일은 방금 백업에서 복원됐다. 「루트 .htaccess 를 쓰지
        // 못했습니다, 다시 시도하세요」를 복원 직후에 읽은 운영자는 존재하지 않는 상태를
        // 쫓게 된다.
        $this->lastRootFileWarnings = [];

        $result = CoreBackupHelper::restoreFromBackupChunk($backupPath, $targets, $excludes, $progress, $shouldPause, $priorityFiles, $onProgress);

        if (! $result['done']) {
            return $result + ['root_file_warnings' => []];
        }

        $failedTargets = $result['failed_targets'];

        // 적용 단계가 `deploy/**` 옆에 둔 `.dist` 는 백업에 없어 복원만으로는 남는다.
        $this->removeDeployFragmentSidecars(base_path());

        // 되돌린 뒤 루트 진입·보호 파일이 없으면 그 사실을 콘솔 통로에 싣는다.
        //
        // 되돌리기 자체는 의도된 동작이다(그 파일이 없던 버전으로 돌아가는 것이 목적). 문제는
        // 그 **결과**다 — 문서 루트가 프로젝트 루트인 사이트에서 루트 `.htaccess` 가 사라지면
        // 보호·위임 규칙이 통째로 없어져 `.env` 가 그대로 열린다. 종전에는 `Log::warning` 뿐이라
        // 「백업에서 복원되었습니다」만 보고 창을 닫은 운영자에게 도달하지 않았다.
        $missingRootFiles = CoreBackupHelper::missingRootProtectionFiles(base_path());
        if ($missingRootFiles !== []) {
            // 문구는 **가장 흔한 정상 상태를 먼저** 말한다. 문서 루트가 `public/` 인 표준
            // 배치에서 이 파일들이 없는 것은 정상이고 그쪽이 절대다수인데, 보안 경고를 먼저
            // 읽히면 정상 롤백마다 경보가 뜨는 셈이라 진짜 경고가 묻힌다.
            $this->lastRootFileWarnings[] = (string) __('settings.core_update.rollback_root_protection_missing', [
                'paths' => implode(', ', $missingRootFiles),
            ]);
        }

        // 되돌리기가 문서 루트 **안**에 공개 파일 사본을 되살렸으면 그것도 같은 통로로 싣는다.
        //
        // 백업은 업데이트 이전 스냅샷이라 그 시점의 `public/storage.broken.*`(공개 업로드
        // 전량 사본)을 그대로 되돌려 놓는다. 7.1.0 스텝이 이미 문서 루트 밖으로 옮긴 뒤라면
        // 같은 파일이 두 벌이 되고 그중 하나는 웹에서 열린다. 종전에는 그 사실이 `Log::warning`
        // 한 줄뿐이라, 형제 축(루트 진입·보호 파일)에는 있는 콘솔 통로가 이 축에만 없었다 —
        // 롤백한 운영자는 「백업에서 복원되었습니다」만 보고 노출을 알 수 없다.
        $docrootResidue = CoreBackupHelper::restoredDocrootResidue(base_path());
        if ($docrootResidue !== []) {
            $this->lastRootFileWarnings[] = (string) __('settings.core_update.rollback_docroot_residue', [
                'paths' => implode(', ', $docrootResidue),
            ]);
        }

        return array_merge($result, [
            'failed_targets' => array_values($failedTargets),
            'root_file_warnings' => $this->lastRootFileWarnings,
        ]);
    }

    /**
     * Maintenance 모드를 활성화합니다.
     *
     * @return string bypass secret
     */
    public function enableMaintenanceMode(): string
    {
        $secret = Str::uuid()->toString();

        Artisan::call('down', [
            '--secret' => $secret,
            '--retry' => 60,
            '--refresh' => 15,
        ]);

        // 우회 비밀값은 기록에 싣지 않는다 — 로그는 저장소 폴더 안에 있어 보호 규칙이 빠진 웹
        // 서버에서는 그대로 열리고, 그 값이면 점검 중인 사이트에 들어갈 수 있다. 값이 필요한
        // 쪽(명령줄 실패 안내, 관리자 화면 우회 쿠키)은 반환값과 점검 모드 파일에서 읽는다.
        Log::channel('upgrade')->info('코어 업데이트: 유지보수 모드 활성화');

        return $secret;
    }

    /**
     * Maintenance 모드를 비활성화합니다.
     *
     * @return void 반환값 없음
     */
    public function disableMaintenanceMode(): void
    {
        Artisan::call('up');
        Log::channel('upgrade')->info('코어 업데이트: 유지보수 모드 비활성화');
    }

    /**
     * 타임스탬프 기반 _pending 하위 디렉토리를 생성합니다.
     *
     * `{pending_path}/core_{Ymd_His}/` 형식의 격리된 디렉토리를 생성하여
     * .gitignore 덮어쓰기, 정리 실패, 동시 실행 충돌을 방지합니다.
     *
     * @return string 생성된 pending 디렉토리 경로
     */
    public function createPendingDirectory(): string
    {
        $basePath = config('app.update.pending_path');
        // 초 단위 타임스탬프는 고유하지 않다 — 같은 초에 시작한 두 실행이 같은 디렉토리를
        // 받으면 한쪽이 내려받는 중인 페이로드에 다른 쪽이 겹쳐 쓴다. docblock 이 「동시
        // 실행 충돌을 방지합니다」라고 선언한 성질을 실제로 성립시킨다.
        //
        // 모양은 `BackupDirectoryName` 이 소유한다 — 여기서 손으로 조립하면 형제 생산자와
        // 같은 문자열을 두 곳에 적게 되고, 그 상태가 곧 이 규약이 폐기하려던 것이다:
        // 한쪽이 모양을 바꿔도 다른 쪽은 따라가지 못하고 그 사실이 오류로 나타나지 않는다.
        $timestamp = BackupDirectoryName::stamp();
        $pendingPath = $basePath.DIRECTORY_SEPARATOR.self::PENDING_DIR_PREFIX.$timestamp;

        File::ensureDirectoryExists($pendingPath, 0770, true);

        return $pendingPath;
    }

    /**
     * _pending 하위 디렉토리를 정리합니다.
     *
     * 타임스탬프 기반 격리 디렉토리(`core_{Ymd_His}/`)를 통째로 삭제합니다.
     *
     * 호출자가 넘기는 경로는 격리 디렉토리 자체가 아니라 그 안쪽의 소스 경로일 수 있다 —
     * ZIP·GitHub 경로는 `core_{ts}/extracted/{루트}/` 를, `--local` 은 `core_{ts}/local_source/`
     * 를 소스로 돌려준다. 그 안쪽만 지우면 `core_{ts}/extracted/` 껍데기가 업데이트마다 남고,
     * sudo 실행이면 root 소유라 운영자·웹서버 계정이 지울 수 없다(7.0.0 부터 누적된 실사례).
     * 그래서 격리 디렉토리 루트로 올라가서 지운다.
     *
     * @param  string  $pendingPath  삭제할 pending 경로 (격리 디렉토리 또는 그 하위 소스 경로)
     * @return void 반환값 없음
     */
    public function cleanupPending(string $pendingPath): void
    {
        ExtensionPendingHelper::cleanupStaging($this->resolveStagingRoot($pendingPath));
    }

    /**
     * 경로가 속한 격리 디렉토리(`{pending_path}/core_*`) 루트를 돌려줍니다.
     *
     * 경로가 pending 기준 디렉토리 아래가 아니면 그대로 돌려준다 (`--source` 로 넘어온
     * 외부 디렉토리처럼 우리가 만들지 않은 경로를 위로 올라가 지우는 일이 없도록).
     *
     * @param  string  $path  격리 디렉토리 또는 그 하위 경로
     * @return string 격리 디렉토리 루트 또는 입력 경로 그대로
     */
    public function resolveStagingRoot(string $path): string
    {
        $rawBase = rtrim((string) config('app.update.pending_path'), '/\\');
        $base = str_replace('\\', '/', $rawBase);
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        if ($base === '' || $normalized === $base || ! str_starts_with($normalized, $base.'/')) {
            return $path;
        }

        $relative = substr($normalized, strlen($base) + 1);
        $first = explode('/', $relative, 2)[0];

        if ($first === '' || $first === '.' || $first === '..') {
            return $path;
        }

        return $rawBase.DIRECTORY_SEPARATOR.$first;
    }

    /**
     * pending 기준 디렉토리에 남은 **빈** 격리 디렉토리(`core_*`)를 청소합니다.
     *
     * 이전 버전의 정리 단계가 소스 경로 안쪽만 지워 남긴 `core_{ts}/extracted/` 껍데기가
     * 대상이다. 부모(구버전 코드)가 남긴 것을 새 코드가 도는 자식 프로세스가 치우므로,
     * 이 결함을 가진 버전에서 올라오는 업데이트도 껍데기 없이 끝난다.
     *
     * 파일이 하나라도 있는 디렉토리는 건드리지 않는다 — 부모가 아직 쓰고 있는 격리
     * 디렉토리(추출본·vendor)는 파일을 갖고 있으므로 이 술어만으로 안전하게 구분된다.
     *
     * @return int 삭제한 격리 디렉토리 수
     */
    public function sweepEmptyStagingDirectories(): int
    {
        $base = (string) config('app.update.pending_path');

        if ($base === '' || ! File::isDirectory($base)) {
            return 0;
        }

        $swept = 0;

        foreach (File::directories($base) as $dir) {
            if (! str_starts_with(basename($dir), 'core_') || is_link($dir)) {
                continue;
            }

            if (! $this->isDirectoryTreeEmpty($dir)) {
                continue;
            }

            ExtensionPendingHelper::cleanupStaging($dir);

            if (File::isDirectory($dir)) {
                Log::channel('upgrade')->warning('코어 업데이트: 빈 격리 디렉토리 청소 실패 (권한)', ['path' => $dir]);

                continue;
            }

            $swept++;
        }

        if ($swept > 0) {
            Log::channel('upgrade')->info('코어 업데이트: 빈 격리 디렉토리 청소', ['swept' => $swept]);
        }

        return $swept;
    }

    /**
     * 디렉토리 트리에 파일(또는 링크)이 하나도 없는지 판정합니다.
     *
     * 읽을 수 없는 하위 디렉토리가 있으면 "비어 있지 않다" 로 본다 — 내용을 모르는
     * 디렉토리를 지우지 않기 위해서다.
     *
     * @param  string  $dir  판정할 디렉토리
     * @return bool 파일·링크가 하나도 없으면 true
     */
    private function isDirectoryTreeEmpty(string $dir): bool
    {
        try {
            $items = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
        } catch (\Throwable) {
            return false;
        }

        foreach ($items as $item) {
            if ($item->isLink() || ! $item->isDir()) {
                return false;
            }

            if (! $this->isDirectoryTreeEmpty($item->getPathname())) {
                return false;
            }
        }

        return true;
    }

    /**
     * 현재 코드베이스의 targets을 _pending에 복제합니다 (로컬 테스트용).
     *
     * GitHub 다운로드 대신 현재 프로젝트의 업데이트 대상 파일/디렉토리를
     * _pending/local_source/로 복사하여 업데이트 패키지를 시뮬레이션합니다.
     *
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return string _pending 내 소스 경로
     */
    public function prepareLocalSource(?\Closure $onProgress = null): string
    {
        $pendingPath = $this->createPendingDirectory();
        $sourcePath = $pendingPath.DIRECTORY_SEPARATOR.'local_source';

        File::ensureDirectoryExists($sourcePath, 0770, true);

        $targets = config('app.update.targets', []);
        $excludes = config('app.update.excludes', []);

        foreach ($targets as $target) {
            $src = base_path($target);

            if (! File::exists($src) && ! File::isDirectory($src)) {
                continue;
            }

            $dest = $sourcePath.DIRECTORY_SEPARATOR.$target;
            $onProgress?->__invoke('copy', $target);

            if (File::isDirectory($src)) {
                FilePermissionHelper::copyDirectory($src, $dest, $onProgress, $excludes);
            } else {
                FilePermissionHelper::copyFile($src, $dest);
            }
        }

        // 패키지 유효성 검증
        $this->validatePendingUpdate($sourcePath);

        return $sourcePath;
    }

    /**
     * 모든 캐시를 초기화하고 패키지 목록을 재생성합니다.
     *
     * vendor 교체 후 bootstrap/cache의 컴파일 캐시가 stale 상태일 수 있으므로
     * services.php/packages.php 삭제 후 package:discover로 재생성합니다
     * (`PackageManifestCacheHelper::rebuild()` — spawn 직전 선정리와 같은 삭제 로직을 공유).
     * 이는 composer install의 post-autoload-dump 후속 작업(clearCompiled + package:discover)을 재현합니다.
     *
     * @return void 반환값 없음
     */
    public function clearAllCaches(): void
    {
        // 1. 기존 캐시 초기화
        Artisan::call('config:clear');
        Artisan::call('cache:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');

        // 2~3. 컴파일 캐시 삭제 후 현재 vendor 기반으로 재생성
        //      (composer postAutoloadDump → clearCompiled + package:discover 재현).
        //      services.php, packages.php 가 교체 전 vendor 를 참조할 수 있다.
        PackageManifestCacheHelper::rebuild();

        // 4. 확장 오토로드 재생성 (코어 업데이트로 _bundled 변경 가능)
        Artisan::call('extension:update-autoload');

        // 5. 디스크 상태 캐시 + opcache 초기화.
        //    Step 7 applyUpdate 가 신규 lang 파일 (예: beta.4 의 `lang/ko/identity.php`) 을
        //    디스크에 깔아도, 부모 프로세스의 PHP file-stat 캐시 / opcache 가 그 파일을
        //    "부재" 로 캐싱한 상태일 수 있음. 후속 `__()` 호출이 raw key 를 반환하여
        //    관리자 UI 에 i18n 키 그대로 노출되는 회귀 차단.
        clearstatcache(true);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /**
     * 상주 중인 큐 워커에 정상 종료 후 재시작 신호를 보냅니다.
     *
     * 큐 워커는 부팅이 한 번뿐이라 코어 코드·config·확장 목록을 기동 시점 상태로 물고 있다.
     * 코어 업데이트가 파일을 전부 교체해도 워커는 옛 코드로 잡을 계속 처리하며, 그 사실이
     * 오류로 드러나지 않는다 — 운영자가 워커를 손수 재시작할 때까지 조용히 어긋난 채 돈다.
     *
     * 캐시가 새 코드 기준으로 정리된 뒤(`clearAllCaches()` 직후) 호출해야 워커가 새 캐시로
     * 재기동한다. 신호 전송 실패는 업데이트 결과를 되돌리지 않는다 (경고 로깅 후 계속).
     *
     * @return void
     */
    public function signalQueueRestart(): void
    {
        // 검수 서버의 재시작 신호는 공유 캐시를 거쳐 운영 서버 워커까지 재시작시킨다
        if (RuntimeRole::isStage()) {
            return;
        }

        try {
            Artisan::call('queue:restart');
        } catch (\Throwable $e) {
            Log::channel('upgrade')->warning('queue:restart 실행 실패 (업데이트는 계속 진행)', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 코어 업그레이드 컨텍스트의 번들 확장 업데이트 감지.
     *
     * `_bundled/{id}/{manifest}.json` 의 version 과 DB 에 설치된 현재 version 을
     * 직접 비교하여 "번들에 최신 버전이 포함된" 확장 목록을 반환한다.
     *
     * `Manager::checkXxxUpdate()` 와의 차이:
     *   - 일반 update 커맨드용 `checkXxxUpdate()` 는 GitHub 엄격 우선 정책
     *     (GitHub URL 조회 성공 시 _bundled 폴백 없음)
     *   - 본 메서드는 **코어 업그레이드 후 _bundled 자동 반영** 용도이므로
     *     GitHub 상태와 무관하게 _bundled 버전만 기준으로 판정
     *   - beta.2 가 GitHub 미릴리스 상태에서도 _bundled 신버전을 정확히 감지
     *
     * 호출처:
     *   - `BundledExtensionUpdatePrompt::collectBundledUpdates()` (beta.2+ 프롬프트)
     *   - `Upgrade_7_0_0_beta_2::spawnResyncInlineLocal()` inline PHP (beta.1 → beta.2 경로 C)
     *
     * @return array{
     *     modules: array<int, array{identifier:string, current_version:string, latest_version:string, update_source:string}>,
     *     plugins: array<int, array{identifier:string, current_version:string, latest_version:string, update_source:string}>,
     *     templates: array<int, array{identifier:string, current_version:string, latest_version:string, update_source:string}>
     * }
     */
    public function collectBundledExtensionUpdates(): array
    {
        return [
            'modules' => $this->detectBundledUpdatesFor('modules', 'module.json'),
            'plugins' => $this->detectBundledUpdatesFor('plugins', 'plugin.json'),
            'templates' => $this->detectBundledUpdatesFor('templates', 'template.json'),
        ];
    }

    /**
     * 단일 확장 타입의 _bundled 업데이트 목록을 조회합니다.
     *
     * @param  string  $tableAndDir  'modules' | 'plugins' | 'templates'  (DB 테이블명 + 디렉토리명 일치 전제)
     * @param  string  $manifestName  'module.json' | 'plugin.json' | 'template.json'
     * @return array<int, array{identifier:string, current_version:string, latest_version:string, update_source:string}>
     */
    private function detectBundledUpdatesFor(string $tableAndDir, string $manifestName): array
    {
        if (! Schema::hasTable($tableAndDir)) {
            return [];
        }

        $results = [];
        // audit:allow service-direct-data-access reason: 3개 확장 테이블(modules/plugins/templates)을 동적 테이블명으로 일괄 스캔하는 generic 업데이트 감지 헬퍼. Repository 별 타입 분리 시 동일 로직이 3중 복제되며 manifest 비교 분기를 분산시켜 회귀 위험 증가
        foreach (DB::table($tableAndDir)->get(['identifier', 'version']) as $record) {
            $identifier = (string) $record->identifier;
            $current = (string) $record->version;
            $bundledPath = base_path($tableAndDir.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.$identifier.DIRECTORY_SEPARATOR.$manifestName);

            if (! is_file($bundledPath)) {
                continue;
            }

            $manifest = json_decode((string) file_get_contents($bundledPath), true);
            $bundled = is_array($manifest) ? ($manifest['version'] ?? null) : null;

            if ($bundled === null || version_compare((string) $bundled, $current, '<=')) {
                continue;
            }

            $results[] = [
                'identifier' => $identifier,
                'current_version' => $current,
                'latest_version' => (string) $bundled,
                'update_source' => 'bundled',
            ];
        }

        return $results;
    }

    /**
     * 업데이트 시작 시점의 원본 소유자·그룹을 스냅샷합니다.
     *
     * 이 스냅샷은 업데이트 종료 시점 `restoreOwnership()` 에 전달되어 각 경로를
     * **원래 자신의 소유자** 로 정확히 복원한다. base_path 기준 통일 방식과 달리
     * 비대칭 환경(예: 루트=someuser, vendor=www-data) 에서도 원본을 유지한다.
     *
     * 대상 경로는 config('app.update.restore_ownership') 에 정의된 목록.
     * chown 미지원 환경(Windows 등) 은 빈 배열을 반환한다.
     *
     * @return array<string, array{owner:int|false, group:int|false}> target => {owner, group}
     */
    public function snapshotOwnership(): array
    {
        $targets = config('app.update.restore_ownership', ['vendor']);

        return $this->snapshotOwnershipFor($targets);
    }

    /**
     * 지정한 경로 목록의 소유자·그룹을 스냅샷합니다.
     *
     * 확장 업데이트(모듈/플러그인/템플릿) 에서 업데이트 전 해당 확장 스코프의
     * 경로만 스냅샷하고 싶을 때 사용. 예: `['bootstrap/cache', "modules/{$id}"]`.
     * 본 메서드 결과는 `restoreOwnership()` 에 그대로 전달 가능.
     *
     * @param  array  $paths  base_path() 기준 상대 경로 배열
     * @return array<string, array{owner:int|false, group:int|false}>
     */
    public function snapshotOwnershipFor(array $paths): array
    {
        if (! function_exists('chown')) {
            return [];
        }

        $snapshot = [];
        foreach ($paths as $target) {
            $path = base_path($target);
            if (! File::exists($path) && ! File::isDirectory($path)) {
                continue;
            }

            // 7.0.0-beta.3+: target 루트 퍼미션을 perms 필드로 추가 스냅샷.
            // restoreOwnership 이 sudo 업데이트로 인한 그룹 쓰기 권한 비대칭을
            // 정상화할 때 사용 (Laravel 런타임 쓰기 경로에 한해).
            $snapshot[$target] = [
                'owner' => @fileowner($path),
                'group' => @filegroup($path),
                'perms' => (@fileperms($path) & 0777) ?: null,
            ];
        }

        return $snapshot;
    }

    /**
     * 좁힌 영역의 항목별 owner/group/perms 를 재귀 스냅샷합니다 (Stage 4 정합화).
     *
     * `snapshotOwnership()` 가 target 의 **루트만** stat 하는 한계를 보완. PHP-FPM 쓰기
     * 영역(`storage/logs`, `storage/framework`, `storage/app/core_pending`, `bootstrap/cache`)
     * 처럼 좁고 항목 수가 적은 영역에 한해 재귀 스냅샷 후 `restoreOwnership` 의 detailed
     * 인자로 전달하면 항목별 정확 복원이 가능하다.
     *
     * 결과 형식: `[$absolutePath => ['owner', 'group', 'perms', 'is_dir', 'is_link']]`
     * 키는 절대 경로 (base_path 적용 후 또는 입력이 이미 절대면 그대로).
     *
     * 안전 한계:
     * - 50,000 항목 초과 시 warning 로그 후 첫 50,000 만 직렬화 (스레드 폭주 방어)
     * - chown 미지원 환경(Windows 등) 은 빈 배열 반환
     * - symbolic link 는 lstat 으로 처리하여 대상 따라가지 않음 (은닉 cycle 방어)
     *
     * 제외 경로(`$excludes`)는 이번 실행이 스스로 만든 격리 디렉토리를 넘기는 자리다.
     * 스냅샷은 격리 디렉토리가 만들어진 **뒤에** 수집되므로, 제외하지 않으면 sudo 실행이
     * root 로 만든 추출본이 "원본 소유권" 으로 기록되고 복원 단계가 그 항목을 다시 root
     * 로 되돌린다 — 정리가 어떤 이유로든 실패하면 잔존물은 언제나 root 소유가 된다.
     *
     * @param  array<int, string>  $paths  base_path 상대 또는 절대 경로 목록
     * @param  array<int, string>  $excludes  스냅샷에서 제외할 경로 (그 하위 전체 포함)
     * @return array<string, array{owner:int|false, group:int|false, perms:int|null, is_dir:bool, is_link:bool}>
     */
    public function snapshotOwnershipDetailed(array $paths, array $excludes = []): array
    {
        if (! function_exists('chown')) {
            return [];
        }

        $snapshot = [];
        $maxItems = 50000;
        $truncated = false;
        $excludePrefixes = [];

        foreach ($excludes as $exclude) {
            $exclude = rtrim(str_replace('\\', '/', trim((string) $exclude)), '/');
            if ($exclude !== '') {
                $excludePrefixes[] = $exclude;
            }
        }

        foreach ($paths as $rawPath) {
            $rawPath = trim((string) $rawPath);
            if ($rawPath === '') {
                continue;
            }

            $absolute = $this->resolveAbsolutePath($rawPath);
            if (! File::exists($absolute) && ! is_link($absolute)) {
                continue;
            }

            $this->collectStatRecursively($absolute, $snapshot, $maxItems, $truncated, $excludePrefixes);

            if ($truncated) {
                break;
            }
        }

        if ($truncated) {
            Log::channel('upgrade')->warning('snapshotOwnershipDetailed: 50000 항목 초과 — 첫 50000 항목만 스냅샷', [
                'collected' => count($snapshot),
                'paths' => $paths,
            ]);
        }

        return $snapshot;
    }

    /**
     * 입력 경로를 절대 경로로 정규화합니다.
     *
     * 절대 경로(/ 또는 Windows 드라이브) 는 그대로, 상대 경로는 base_path 적용.
     *
     * @param  string  $path  입력 경로 (절대 또는 base_path 기준 상대)
     * @return string 절대 경로
     */
    private function resolveAbsolutePath(string $path): string
    {
        if ($path === '') {
            return base_path();
        }
        if ($path[0] === '/' || $path[0] === DIRECTORY_SEPARATOR) {
            return $path;
        }
        // Windows 드라이브 접두사 (예: C:\ 또는 C:/)
        if (strlen($path) >= 2 && ctype_alpha($path[0]) && $path[1] === ':') {
            return $path;
        }

        return base_path($path);
    }

    /**
     * 트리를 재귀 stat 하여 snapshot 배열에 누적합니다.
     *
     * @param  string  $path  stat 을 시작할 경로
     * @param  array<string, array{owner:int|false, group:int|false, perms:int|null, is_dir:bool, is_link:bool}>  $snapshot
     * @param  int  $maxItems  누적 항목 상한
     * @param  bool  $truncated  상한 도달 여부 (참조로 갱신)
     * @param  array<int, string>  $excludePrefixes  제외 경로(슬래시 정규화, 끝 슬래시 없음) — 일치하거나 그 하위면 건너뛴다
     * @return void 반환값 없음
     */
    private function collectStatRecursively(string $path, array &$snapshot, int $maxItems, bool &$truncated, array $excludePrefixes = []): void
    {
        if ($truncated || count($snapshot) >= $maxItems) {
            $truncated = true;

            return;
        }

        if ($excludePrefixes !== []) {
            $normalized = rtrim(str_replace('\\', '/', $path), '/');
            foreach ($excludePrefixes as $prefix) {
                if ($normalized === $prefix || str_starts_with($normalized, $prefix.'/')) {
                    return;
                }
            }
        }

        $isLink = is_link($path);
        // symbolic link 는 lstat — 대상 추적 금지
        $stat = $isLink ? @lstat($path) : @stat($path);
        if (! is_array($stat)) {
            return;
        }

        $snapshot[$path] = [
            'owner' => isset($stat['uid']) ? (int) $stat['uid'] : false,
            'group' => isset($stat['gid']) ? (int) $stat['gid'] : false,
            'perms' => isset($stat['mode']) ? ($stat['mode'] & 0777) : null,
            'is_dir' => is_dir($path) && ! $isLink,
            'is_link' => $isLink,
        ];

        // symbolic link 는 대상 추적 금지 + 디렉토리만 재귀
        if ($isLink || ! is_dir($path)) {
            return;
        }

        $items = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            $this->collectStatRecursively($item->getPathname(), $snapshot, $maxItems, $truncated, $excludePrefixes);
            if ($truncated) {
                return;
            }
        }
    }

    /**
     * root 로 실행된 업데이트가 종료된 뒤, 런타임 쓰기 디렉토리의 소유권을 정상화합니다.
     *
     * `restoreOwnership()` 은 흐름 **중간**의 한 단계라, 그 이후에 일어나는 캐시
     * 쓰기(버전 bump·상태/훅 캐시 재생성·키 인덱스 갱신·번들 빌드)가 root 소유
     * 파일을 새로 만든다. 그 파일들이 남으면 웹 프로세스의 캐시 쓰기가 Permission
     * denied 로 죽어 전면 500 이 된다 (실사례: 7.0.9→7.0.10 sudo 업데이트 —
     * 치명점은 모든 remember 가 갱신하는 캐시 키 인덱스 파일).
     *
     * 따라서 이 메서드는 **흐름의 마지막**(restoreUpgradeLogOwnership 과 같은
     * 지점)에서 호출되어, 대상 디렉토리 자신의 소유자(웹 쓰기 소유)를 기준으로
     * 내용물을 재귀 정상화하고 그룹 쓰기를 동기화한다. 과거 업데이트가 남긴
     * root 잔재도 함께 정리된다 (재귀 전체 대상).
     *
     * 비-root 프로세스는 chown 자체가 불가능하고 필요도 없으므로 즉시 no-op.
     * 기준 디렉토리 자체가 root 소유(비정상 배포)면 상속 근거가 없어 스킵한다.
     *
     * @return void 반환값 없음
     */
    public function normalizeRuntimeOwnershipAfterRootRun(): void
    {
        if (! function_exists('chown') || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }

        $targets = [
            storage_path('framework/cache'),
            base_path('bootstrap/cache'),
            storage_path('app/ext-bundles'),
            // 확장 업데이트의 다운로드·추출 임시 폴더. 부모 `storage/app/temp` 가 sudo 업데이트에서 root 로
            // 최초 생성되면 이후 관리자 화면의 확장 업데이트가 임시 폴더를 만들지 못한다 (#651).
            storage_path('app/temp'),
            // `restore_ownership` 은 `storage/logs` 를 포함하지만 그 복원은 흐름 **중간**(Step 11)이라,
            // 그 뒤에 만들어지는 daily 롤오버·신규 로그 파일은 root 로 남는다 (#651).
            storage_path('logs'),
        ];

        foreach ($targets as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $owner = @fileowner($dir);
            $group = @filegroup($dir);

            if ($owner === false || $owner === 0) {
                Log::channel('upgrade')->warning('런타임 소유권 정상화 스킵 — 기준 디렉토리가 root/판독불가 소유', [
                    'dir' => $dir,
                ]);

                continue;
            }

            FilePermissionHelper::chownRecursive($dir, $owner, $group);
            FilePermissionHelper::syncGroupWritability($dir);
        }
    }

    /**
     * 업데이트 경로의 소유권을 스냅샷 기준으로 복원합니다.
     *
     * sudo 로 실행된 외부 프로세스(composer install, package:discover,
     * extension:update-autoload 등)가 root 소유로 생성한 파일을 **업데이트 전의
     * 각 경로 원본 소유자** 로 되돌린다. 스냅샷은 `snapshotOwnership()` 로 업데이트
     * 초반에 수집하여 전달한다.
     *
     * 동작 원칙:
     * - target 별로 스냅샷에 기록된 원본 owner/group 을 기준으로 재귀 chown
     * - 스냅샷에 없거나 수집 실패(false)한 target 은 `FilePermissionHelper::inferWebServerOwnership()`
     *   의 웹서버 계정 추정값으로 fallback (storage 등 웹서버 쓰기 디렉토리 기준)
     * - 이미 일치하는 항목은 no-op
     * - 소유권만 복원, 퍼미션은 건드리지 않음
     * - @chown/@chgrp suppress 로 권한 부족 시 silent fail
     * - 대상 경로 목록은 config('app.update.restore_ownership') 기준
     *
     * Stage 4 (`$detailedSnapshot` 인자) — 항목별 정확 복원:
     * - `snapshotOwnershipDetailed()` 결과를 전달하면 좁힌 영역의 owner/group/perms 를
     *   항목별로 정확 복원 (디렉토리 traversal 비트 손실 같은 회귀 차단)
     * - 빈 배열이면 기존 chownRecursive 만 동작 (호환성 유지)
     * - 두 메커니즘은 독립 — `$snapshot` 에는 거시 chown 대상, `$detailedSnapshot` 에는
     *   PHP-FPM 쓰기 영역의 정확 복원 대상을 따로 전달
     *
     * @param  array<string, array{owner:int|false, group:int|false}>  $snapshot  snapshotOwnership() 결과
     * @param  \Closure|null  $onProgress  진행 콜백
     * @param  array<string, array{owner:int|false, group:int|false, perms:int|null, is_dir:bool, is_link:bool}>  $detailedSnapshot  snapshotOwnershipDetailed() 결과 (선택)
     * @return void 반환값 없음
     */
    public function restoreOwnership(array $snapshot, ?\Closure $onProgress = null, array $detailedSnapshot = []): void
    {
        $result = $this->restoreOwnershipChunk($snapshot, $detailedSnapshot, [], null, $onProgress);

        $this->lastPermissionWarnings = $result['warnings'];
    }

    /**
     * 소유권·퍼미션 복원을 나눠서 합니다 (웹 업데이트 — 한 요청의 시간 몫 안에서 멈춘다).
     *
     * `restoreOwnership()` 은 이 함수를 멈춤 없이 부른다 — 명령줄과 웹이 같은 판정을 쓴다. 세 구간을 지난다:
     * ① 스냅샷 대상별 소유권(`chownRecursiveChunk`) ② 항목별 정확 복원(detailed, 상한 5만 항목이라 한 번에)
     * ③ 런타임 쓰기 경로 그룹 쓰기 권한(`syncGroupWritabilityChunk`). 대상 트리(vendor·확장·백업)는 수만 항목이라
     * 한 요청에 다 훑으면 요청 시간 제한에 걸린다(큰 사이트에서는 수 분) — 대상 안에서도 경로 커서로 멈춘다.
     *
     * @param  array<string, array{owner:int|false, group:int|false}>  $snapshot  snapshotOwnership() 결과
     * @param  array<string, array<string, mixed>>  $detailedSnapshot  snapshotOwnershipDetailed() 결과
     * @param  array<string, mixed>  $progress  직전 호출이 돌려준 진행분 (처음이면 빈 배열)
     * @param  \Closure|null  $shouldPause  멈출 때인지 묻는 함수 (null 이면 끝까지)
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return array{done: bool, phase: string, index: int, cursor: string|null, report: array<string, mixed>, restored: int, group_changed: int, warnings: list<array<string, mixed>>} 진행분
     */
    public function restoreOwnershipChunk(array $snapshot, array $detailedSnapshot = [], array $progress = [], ?\Closure $shouldPause = null, ?\Closure $onProgress = null): array
    {
        $phase = is_string($progress['phase'] ?? null) ? $progress['phase'] : 'chown';
        $index = max(0, (int) ($progress['index'] ?? 0));
        $cursor = is_string($progress['cursor'] ?? null) ? $progress['cursor'] : null;
        $report = (array) ($progress['report'] ?? []);
        $restoredCount = (int) ($progress['restored'] ?? 0);
        $groupWritableChanged = (int) ($progress['group_changed'] ?? 0);
        $warnings = array_values((array) ($progress['warnings'] ?? []));
        $detailedProgress = (array) ($progress['detailed'] ?? []);

        $snapshotOf = static function (bool $done) use (&$phase, &$index, &$cursor, &$report, &$restoredCount, &$groupWritableChanged, &$warnings, &$detailedProgress): array {
            return [
                'done' => $done,
                'phase' => $phase,
                'index' => $index,
                'cursor' => $cursor,
                'report' => $report,
                'restored' => $restoredCount,
                'group_changed' => $groupWritableChanged,
                'warnings' => $warnings,
                'detailed' => $detailedProgress,
            ];
        };

        if (! function_exists('chown')) {
            $phase = 'done';

            return $snapshotOf(true);
        }

        if ($phase === 'chown') {
            // 스냅샷이 제공되면 그 키를 복원 대상 범위로 사용 (스코프 복원).
            // 스냅샷이 비어있으면 전체 config 범위로 fallback (기존 동작 유지).
            $targets = array_values(! empty($snapshot)
                ? array_keys($snapshot)
                : config('app.update.restore_ownership', ['vendor']));
            $fallback = null;

            for (; $index < count($targets); $index++) {
                $target = (string) $targets[$index];
                $path = base_path($target);
                if (! File::exists($path) && ! File::isDirectory($path)) {
                    $cursor = null;
                    $report = [];

                    continue;
                }

                // 1순위: 스냅샷의 원본 소유자
                $owner = $snapshot[$target]['owner'] ?? false;
                $group = $snapshot[$target]['group'] ?? false;
                $source = 'snapshot';

                // 2순위: inferWebServerOwnership fallback
                if ($owner === false) {
                    $fallback ??= FilePermissionHelper::inferWebServerOwnership();
                    [$owner, $group, $fallbackSource] = $fallback;
                    $source = 'infer:'.$fallbackSource;
                }

                if ($owner === false) {
                    $cursor = null;
                    $report = [];

                    continue;
                }

                if ($cursor === null) {
                    $onProgress?->__invoke('ownership', $target);
                }

                // 트랙 2-A — `.preserve-ownership` 마커가 있는 서브트리(사용자 데이터 영역) 자동 skip.
                // ModuleStorageDriver/PluginStorageDriver 가 자동 작성하는 마커로 시드 시점 owner 영구 보존.
                $chunk = FilePermissionHelper::chownRecursiveChunk($path, (int) $owner, $group, true, $cursor, $shouldPause, $report);
                $report = $chunk['report'];

                if (! $chunk['done']) {
                    $cursor = $chunk['cursor'];

                    return $snapshotOf(false);
                }

                if ($report['failed'] > 0) {
                    Log::warning('chownRecursive: 부분 실패', [
                        'root' => $path,
                        'owner' => $owner,
                        'group' => $group,
                        'changed' => $report['changed'],
                        'failed' => $report['failed'],
                        'first_failure' => $report['first_failure'],
                    ]);
                }

                if ($report['changed'] > 0) {
                    Log::channel('upgrade')->info('코어 업데이트: 소유권 복원', [
                        'target' => $target,
                        'owner' => $owner,
                        'group' => $group,
                        'source' => $source,
                        'changed_entries' => $report['changed'],
                    ]);
                    $restoredCount += $report['changed'];
                }

                if ($report['failed'] > 0) {
                    $warnings[] = [
                        'target' => $target,
                        'kind' => 'chown',
                        'failed' => $report['failed'],
                        'failed_paths' => array_slice($report['failed_paths'], 0, 50),
                    ];
                }

                $cursor = null;
                $report = [];

                if ($shouldPause !== null && $index + 1 < count($targets) && $shouldPause()) {
                    $index++;

                    return $snapshotOf(false);
                }
            }

            $phase = 'detailed';
            $index = 0;
        }

        if ($phase === 'detailed') {
            // Stage 4 — detailed snapshot 기반 항목별 정확 복원 (chown + chgrp + chmod).
            // 좁힌 영역(PHP-FPM 쓰기 경로)의 owner/group/perms 를 원본과 100% 일치 복원.
            // 항목이 최대 5만 개라 이것도 항목 순번 커서로 나눈다(한 구간으로 하면 실행 시간이 짧은 서버에서 그 한 요청이 몫을 넘긴다).
            if (! empty($detailedSnapshot)) {
                $detailedProgress = $this->restoreFromDetailedSnapshotChunk($detailedSnapshot, $detailedProgress, $shouldPause, $onProgress);

                if (! $detailedProgress['done']) {
                    return $snapshotOf(false);
                }

                if ($detailedProgress['warning'] !== null) {
                    $warnings[] = $detailedProgress['warning'];
                }

                $detailedProgress = [];
            }

            $phase = 'group';
            $index = 0;

            if ($shouldPause !== null && $shouldPause()) {
                return $snapshotOf(false);
            }
        }

        if ($phase === 'group') {
            // 7.0.0-beta.3+: Laravel 런타임 쓰기 경로(storage/, bootstrap/cache/) 에 한해
            // 그룹 쓰기 권한 비대칭 정상화. sudo root 업데이트가 umask 022 로 신규 생성한
            // 하위 디렉토리(g-w) 가 chownRecursive 후에도 g-w 로 남아 php-fpm(www-data 그룹)
            // 이 cache 쓰기 실패하는 문제를 구조적으로 차단.
            //
            // 정책: 루트가 g+w 면 하위 g-w 항목을 g+w 로 승격, 그 외 비트 무변경.
            // 운영자가 의도적으로 그룹 쓰기를 차단한 경로는 보존됨.
            $groupWritableTargets = array_values(config('app.update.restore_ownership_group_writable', [
                'storage',
                'bootstrap/cache',
            ]));
            // 백업 디렉토리는 운영자 정책 보존 대상이 아니라 업데이트/롤백이 생성·소비·삭제하는
            // 임시 산출물이다. sudo 업데이트가 이들을 g-w(0755) 로 만들어 두면 이후 www-data 가
            // 그 안에 백업을 mkdir 하지 못한다(mkdir(): Permission denied). 따라서 이 경로들은
            // force=true 로 정상화하여 루트가 g-w 라도 g+w 승격 후 하위까지 재귀 전파한다.
            // (그 외 경로는 force=false — 운영자가 의도적으로 차단한 그룹 쓰기 정책을 보존.)
            $forceGroupWritablePaths = [
                'storage/app/extension_backups',
                'storage/app/core_backups',
            ];

            for (; $index < count($groupWritableTargets); $index++) {
                $target = (string) $groupWritableTargets[$index];
                $path = base_path($target);
                if (! File::isDirectory($path)) {
                    $cursor = null;
                    $report = [];

                    continue;
                }

                if (! function_exists('chmod')) {
                    break;
                }

                if ($cursor === null) {
                    $onProgress?->__invoke('group_writable', $target);
                }

                $force = in_array($target, $forceGroupWritablePaths, true);
                $chunk = FilePermissionHelper::syncGroupWritabilityChunk($path, $force, $cursor, $shouldPause, $report);
                $report = $chunk['report'];

                if (! $chunk['done']) {
                    $cursor = $chunk['cursor'];

                    return $snapshotOf(false);
                }

                $groupWritableChanged += (int) $report['changed'];

                if ($report['changed'] > 0) {
                    Log::info('syncGroupWritability: 그룹 쓰기 권한 정상화', ['root' => $path, 'changed' => $report['changed']]);
                }

                if ($report['failed'] > 0) {
                    Log::warning('syncGroupWritability: 부분 실패', [
                        'root' => $path,
                        'changed' => $report['changed'],
                        'failed' => $report['failed'],
                        'first_failure' => $report['failed_paths'][0] ?? null,
                    ]);
                    $warnings[] = [
                        'target' => $target,
                        'kind' => 'group_writable',
                        'failed' => $report['failed'],
                        'failed_paths' => array_slice($report['failed_paths'], 0, 50),
                    ];
                }

                $cursor = null;
                $report = [];

                if ($shouldPause !== null && $index + 1 < count($groupWritableTargets) && $shouldPause()) {
                    $index++;

                    return $snapshotOf(false);
                }
            }

            if ($groupWritableChanged > 0) {
                Log::channel('upgrade')->info('코어 업데이트: 그룹 쓰기 권한 정상화', [
                    'targets' => $groupWritableTargets,
                    'changed_entries' => $groupWritableChanged,
                ]);
            }

            if ($restoredCount > 0) {
                Log::channel('upgrade')->info('코어 업데이트: 소유권 복원 완료', [
                    'restored_entries_total' => $restoredCount,
                    'targets' => ! empty($snapshot) ? array_keys($snapshot) : config('app.update.restore_ownership', ['vendor']),
                ]);
            }

            $phase = 'done';
        }

        return $snapshotOf(true);
    }

    /**
     * `snapshotOwnershipDetailed()` 결과를 항목별로 정확 복원합니다 — 항목 순번 커서로 나눠 한다.
     *
     * 동작:
     * - 각 항목의 현재 stat 을 읽어 snapshot 과 비교
     * - owner/group/perms 가 다르면 해당 비트만 변경 (chown/chgrp/chmod)
     * - symbolic link 는 lchown 시도 (없으면 skip), perms 무변경
     * - silent fail — 권한 부족·chmod 미지원 환경에서도 예외 미발생
     * - 실패 항목 누적 → 끝났을 때 'kind' => 'detailed' 경고로 돌려준다 (`restoreOwnershipChunk()` 가 경고 목록에 싣는다)
     * 멈춤은 항목 하나를 끝낼 때마다 묻는다. 스냅샷은 실행 기록에 저장된 배열이라 요청 사이에 순서가 같다.
     *
     * @param  array<string, array{owner:int|false, group:int|false, perms:int|null, is_dir:bool, is_link:bool}>  $detailedSnapshot  스냅샷
     * @param  array<string, mixed>  $progress  직전 호출이 돌려준 진행분 (처음이면 빈 배열)
     * @param  \Closure|null  $shouldPause  멈출 때인지 묻는 함수 (null 이면 끝까지)
     * @param  \Closure|null  $onProgress  항목별 진행 콜백 (선택)
     * @return array{done: bool, next: int, changed: int, failed: int, failed_paths: list<string>, warning: array<string, mixed>|null} 진행분 (warning 은 끝났을 때만)
     */
    private function restoreFromDetailedSnapshotChunk(array $detailedSnapshot, array $progress = [], ?\Closure $shouldPause = null, ?\Closure $onProgress = null): array
    {
        $changed = (int) ($progress['changed'] ?? 0);
        $failed = (int) ($progress['failed'] ?? 0);
        $failedPaths = array_values(array_map('strval', (array) ($progress['failed_paths'] ?? [])));
        $supportsLchown = function_exists('lchown');
        $supportsLchgrp = function_exists('lchgrp');
        $paths = array_keys($detailedSnapshot);
        $total = count($paths);
        $position = max(0, (int) ($progress['next'] ?? 0));

        for (; $position < $total; $position++) {
            $absolutePath = (string) $paths[$position];
            $meta = (array) $detailedSnapshot[$paths[$position]];

            if (file_exists($absolutePath) || is_link($absolutePath)) {
                $isLink = $meta['is_link'] ?? is_link($absolutePath);
                $targetOwner = $meta['owner'] ?? false;
                $targetGroup = $meta['group'] ?? false;
                $targetPerms = $meta['perms'] ?? null;
                $itemFailed = false;

                // owner 복원
                if ($targetOwner !== false) {
                    $currentOwner = $isLink ? @lstat($absolutePath)['uid'] ?? false : @fileowner($absolutePath);
                    if ($currentOwner !== false && $currentOwner !== $targetOwner) {
                        if ($isLink) {
                            if ($supportsLchown && @lchown($absolutePath, $targetOwner)) {
                                $changed++;
                            } else {
                                $itemFailed = true;
                            }
                        } else {
                            if (@chown($absolutePath, $targetOwner)) {
                                $changed++;
                            } else {
                                $itemFailed = true;
                            }
                        }
                    }
                }

                // group 복원
                if ($targetGroup !== false) {
                    $currentGroup = $isLink ? @lstat($absolutePath)['gid'] ?? false : @filegroup($absolutePath);
                    if ($currentGroup !== false && $currentGroup !== $targetGroup) {
                        if ($isLink) {
                            if ($supportsLchgrp && @lchgrp($absolutePath, $targetGroup)) {
                                $changed++;
                            } else {
                                $itemFailed = true;
                            }
                        } else {
                            if (@chgrp($absolutePath, $targetGroup)) {
                                $changed++;
                            } else {
                                $itemFailed = true;
                            }
                        }
                    }
                }

                // perms 복원 — symbolic link 는 perms 무변경 (대부분 OS 가 link perms 무시)
                if (! $isLink && $targetPerms !== null) {
                    $currentPerms = @fileperms($absolutePath);
                    if ($currentPerms !== false && ($currentPerms & 0777) !== $targetPerms) {
                        if (@chmod($absolutePath, $targetPerms)) {
                            $changed++;
                        } else {
                            $itemFailed = true;
                        }
                    }
                }

                if ($itemFailed) {
                    $failed++;
                    if (count($failedPaths) < 50) {
                        $failedPaths[] = $absolutePath;
                    }
                }

                $onProgress?->__invoke('detailed_restore', $absolutePath);
            }

            if ($shouldPause !== null && $position + 1 < $total && $shouldPause()) {
                return [
                    'done' => false,
                    'next' => $position + 1,
                    'changed' => $changed,
                    'failed' => $failed,
                    'failed_paths' => $failedPaths,
                    'warning' => null,
                ];
            }
        }

        if ($changed > 0) {
            Log::channel('upgrade')->info('코어 업데이트: 항목별 정확 복원 완료', [
                'snapshot_items' => $total,
                'changed_attributes' => $changed,
            ]);
        }

        $warning = null;

        if ($failed > 0) {
            $warning = [
                'target' => 'detailed_snapshot',
                'kind' => 'detailed',
                'failed' => $failed,
                'failed_paths' => $failedPaths,
            ];
            Log::channel('upgrade')->warning('코어 업데이트: 항목별 복원 부분 실패', [
                'failed_count' => $failed,
                'first_failed' => $failedPaths[0] ?? null,
            ]);
        }

        return ['done' => true, 'next' => $total, 'changed' => $changed, 'failed' => $failed, 'failed_paths' => $failedPaths, 'warning' => $warning];
    }

    /**
     * 업데이트 실패 리포트를 생성합니다.
     *
     * @param  \Throwable  $exception  발생한 예외
     * @param  string  $fromVersion  시작 버전
     * @param  string  $toVersion  종료 버전
     * @return string 리포트 파일 경로
     */
    public function generateFailureReport(\Throwable $exception, string $fromVersion, string $toVersion): string
    {
        $reportPath = self::updateReportPath('failure');

        $content = implode("\n", [
            '=== 그누보드7 코어 업데이트 실패 리포트 ===',
            // audit:allow datetime-display-user-timezone reason: 서버 로그·리포트 파일 — UTC 기록
            '날짜: '.date('Y-m-d H:i:s'),
            "시작 버전: {$fromVersion}",
            "대상 버전: {$toVersion}",
            '',
            '=== 오류 정보 ===',
            "메시지: {$exception->getMessage()}",
            "파일: {$exception->getFile()}:{$exception->getLine()}",
            '',
            '=== 스택 트레이스 ===',
            $exception->getTraceAsString(),
            '',
            '=== 시스템 정보 ===',
            'PHP: '.PHP_VERSION,
            'Laravel: '.app()->version(),
            'OS: '.PHP_OS,
        ]);

        File::put($reportPath, $content);

        Log::channel('upgrade')->error('코어 업데이트 실패', [
            'from' => $fromVersion,
            'to' => $toVersion,
            'error' => $exception->getMessage(),
            'report' => $reportPath,
        ]);

        return $reportPath;
    }

    /**
     * 코어 권한 정의를 반환합니다.
     *
     * @return array 권한 정의 배열
     */
    protected function getCorePermissionDefinitions(): array
    {
        return config('core.permissions', []);
    }

    /**
     * 코어 역할 정의를 반환합니다.
     *
     * @return array 역할 정의 배열
     */
    protected function getCoreRoleDefinitions(): array
    {
        return config('core.roles', []);
    }

    /**
     * 코어 메뉴 정의를 반환합니다.
     *
     * @return array 메뉴 정의 배열
     */
    protected function getCoreMenuDefinitions(): array
    {
        return config('core.menus', []);
    }
}
