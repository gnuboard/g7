<?php

namespace App\Extension\Helpers;

use App\Extension\ExtensionManager;
use App\Services\PublicStorageService;
use App\Support\ExecutionEnvironment;
use App\Support\PublicDiskHardening;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * `public/storage` symlink 멱등 복구 헬퍼.
 *
 * 코어 업데이트(특히 `--prune`) 이후 `public/storage` symlink 가 부재/손상 상태로 남는
 * 결함(공개 #43)을 종료 시점에 방어 복구한다. `is_link` 이면 no-op, 부재/일반 디렉토리이면
 * `storage/app/public` 을 가리키는 symlink 를 (필요 시 백업 후) 재생성한다.
 *
 * V-1 안전: 이 헬퍼는 beta.5 DataMigration `RecoverPublicStorageSymlink` 가 in-process
 * fallback 으로 호출할 수 있으므로, `Illuminate\Support\Facades\*` 파사드 + 네이티브
 * 함수 + 로컬 로직만 사용한다 (신규 Service/Manager/Repository 메서드 의존 금지).
 *
 * 로거 주입: 두 호출자의 로깅 채널이 다르다 — migration 은 `$context->logger`(채널
 * `upgrade`), 코어 업데이트 커맨드는 `Log::channel('upgrade')`. 파사드를 직접 쓰면
 * migration 의 채널 격리가 깨지므로 로거를 주입받고, `null` 이면 `upgrade` 채널로 폴백한다.
 */
class StorageLinkHelper
{
    /**
     * 손상 백업 이름의 접두사 — **웹 트리 밖**(`storage/app/`) 정상 경로.
     *
     * 이 값과 아래 형식 조각이 이 클래스가 만드는 이름의 SSoT 다. 지우는 쪽
     * (`StorageLeftoverPruneService`)과 옮기는 쪽(7.1.0 이설 마이그레이션)이 이 상수를
     * 그대로 읽는다 — 리터럴로 다시 적으면 그 상태는 오류가 아니라 **모집단이 조용히
     * 비는 것**으로만 나타난다 (`BackupDirectoryName` 독블록이 소유한 그 실패 모드).
     */
    public const PUBLIC_STORAGE_BROKEN_PREFIX = 'public-storage-broken-';

    /**
     * 손상 백업 이름의 접두사 — **웹 트리 안**(`public/`) 폴백 경로.
     *
     * 볼륨이 달라 웹 트리 밖으로 옮기지 못했을 때만 쓰인다(`public/storage.broken.<…>`).
     * 7.0.x 는 이쪽이 정상 경로였으므로 기설치 사이트에는 이 이름이 남아 있다.
     */
    public const DOCROOT_BROKEN_PREFIX = 'storage.broken.';

    /**
     * 손상 백업 이름의 시각 + 충돌 회피 접미사 형식 조각 (캡처 없음).
     *
     * 접미사 그룹이 **여러 번** 올 수 있다. 7.1.0 이설 마이그레이션은 이미 접미사를 가진
     * 이름(`…-a1b2c3d4`)을 옮기면서 충돌하면 그 뒤에 하나를 더 붙이므로
     * (`…-a1b2c3d4-e5f6a7b8`), 그룹을 하나만 받으면 그 결과가 **영원히 모집단 밖**에
     * 남는다 — 공개 파일 전량 사본이 무한 잔존하는 형태다.
     */
    public const BROKEN_STAMP = '\d{14}(?:-[0-9a-f]+)*';

    /**
     * 누적 경고 임계 — 이 개수 이상 쌓이면 운영자에게 알린다.
     */
    private const ACCUMULATED_BACKUP_WARN_THRESHOLD = 3;

    /**
     * 웹 트리 **밖** 손상 백업 이름을 판정하는 정규식을 돌려줍니다.
     *
     * @return string `preg_match` 에 그대로 넘길 수 있는 완성 패턴
     */
    public static function publicStorageBrokenNamePattern(): string
    {
        return '/^'.preg_quote(self::PUBLIC_STORAGE_BROKEN_PREFIX, '/').self::BROKEN_STAMP.'$/';
    }

    /**
     * 웹 트리 **안** 손상 백업 이름을 판정하는 정규식을 돌려줍니다.
     *
     * @return string `preg_match` 에 그대로 넘길 수 있는 완성 패턴
     */
    public static function docrootBrokenNamePattern(): string
    {
        return '/^'.preg_quote(self::DOCROOT_BROKEN_PREFIX, '/').self::BROKEN_STAMP.'$/';
    }

    /**
     * 손상 백업 이름의 시각 + 충돌 회피 접미사를 만듭니다 (생산자 단일 지점).
     *
     * @return string `YmdHis-xxxxxxxx` 형태
     */
    public static function brokenStamp(): string
    {
        return date('YmdHis').'-'.self::uniqueSuffix(4);
    }

    /**
     * 겹치지 않는 짧은 접미사를 만듭니다 (차단 가능한 함수 없이).
     *
     * `random_bytes()` 는 엔트로피를 못 얻으면 `Random\RandomException` 을 던진다. 이
     * 호출들은 코어 업데이트 **성공 경로의 후반부**(소유권 복원 뒤·유지보수 해제 전)에
     * 있어서, 예외가 그대로 오르면 상위 `catch (\Throwable)` 이 받아 **이미 성공한
     * 업데이트를 통째로 롤백**한다. 형제 지점 둘(`RootHtaccessUpdater` ·
     * 인스톨러 노출 점검)은 같은 사유로 이미 감싸 두었는데 이 파일만 무가드였다.
     *
     * 이 값은 비밀이 아니라 **같은 디렉토리에서 겹치지 않을 이름**이면 충분하다 —
     * 그래서 폴백은 비-CSPRNG 로 충분하고, 그 판단은 형제 독블록이 이미 적어 두었다.
     *
     * @param  int  $bytes  바이트 수
     * @return string 16진 접미사
     */
    private static function uniqueSuffix(int $bytes): string
    {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (\Throwable) {
            $seed = '';

            for ($i = 0; $i < $bytes; $i++) {
                $seed .= chr(mt_rand(0, 255));
            }

            return bin2hex($seed).dechex((int) (microtime(true) * 1000));
        }
    }

    /**
     * `public/storage` 링크 가용 여부의 memo.
     *
     * URL 생성 경로(ResolvesPublicUrl)가 파일마다 부르므로 lstat 반복을 피한다.
     *
     * 수명은 **프로세스 단위**다(요청 단위가 아니다). 일반 PHP-FPM/mod_php 요청에서는 둘이
     * 같지만, 프로세스가 살아 있는 배치(Octane·`queue:work` 데몬)에서는 링크 상태를 바꾼 뒤에도
     * 낡은 판정이 남는다 — 큐가 만드는 메일 본문의 이미지 URL 이 그 대상이다. 이 클래스의
     * 복구 경로(`ensurePublicStorageLink()`)는 진입에서 스스로 무효화하지만, Laravel 표준
     * `php artisan storage:link` 나 FTP·파일 관리자 조작은 이 클래스를 거치지 않는다.
     * 그런 조작 뒤에는 `queue:restart`(워커 재기동)로 반영한다 — 워커는 어차피 코드 갱신
     * 반영에도 그 절차를 쓴다. 테스트는 `TestCase` 가 전제를 고정한다.
     */
    private static ?bool $publicStorageLinkMemo = null;

    /**
     * `public/storage` 링크(symlink 또는 Windows junction)가 실제로 동작하는지 판정합니다 (B-3).
     *
     * 이 판정이 public 디스크 공개 URL 의 형태를 가른다 — 링크가 있으면 정적 경로
     * (`{APP_URL}/storage/...`), 없으면 읽기 전용 서빙 라우트(`AssetUrl::publicStorage()`).
     * "링크가 있지만 대상이 끊어진" 반쪽 상태는 없음으로 판정한다 (조용한 404 금지).
     *
     * @return bool 링크가 존재하고 대상 디렉토리에 도달 가능하면 true
     */
    public static function publicStorageLinkUsable(): bool
    {
        return static::$publicStorageLinkMemo ??= static::detectPublicStorageLinkUsable();
    }

    /**
     * memo 를 초기화합니다 (테스트 및 링크 생성/복구 직후 재판정용).
     *
     * @return void 반환값 없음
     */
    public static function resetPublicStorageLinkMemo(): void
    {
        static::$publicStorageLinkMemo = null;
    }

    /**
     * 링크 가용 판정을 강제로 지정합니다 (테스트 전용 — `AssetUrl::forceMode` 와 같은 계열).
     *
     * @param  bool|null  $usable  강제할 판정. null 이면 강제 해제(재실측)
     * @return void 반환값 없음
     */
    public static function forcePublicStorageLinkUsable(?bool $usable): void
    {
        static::$publicStorageLinkMemo = $usable;
    }

    /**
     * `public/storage` 링크 가용 여부를 실측합니다.
     *
     * 링크가 **설정된 공개 스토리지 루트**를 가리키는지까지 본다. 파일을 쓰는 쪽
     * (`Storage::disk('public')`)과 서빙 쪽(`PublicStorageService::rootPath()`)은 이미
     * `filesystems.disks.public.root` 를 따르는데, 이 판정만 `public/storage` 의 존재
     * 여부로 끝나면 운영자가 그 루트를 옮긴 사이트에서 **옛 위치를 가리키는 살아 있는
     * 링크**가 가용으로 읽힌다 — URL 은 `/storage/...` 로 나가고 그 링크는 빈 트리를
     * 가리키므로 공개 자산이 통째로 404 가 되며, 오류도 로그도 남지 않는다.
     *
     * 이 경로는 URL 생성(런타임 앱)에서만 불린다 — V-1 in-process fallback 이 부르는
     * `ensurePublicStorageLink()` 계열과 달리 코어 서비스 참조가 안전하다.
     *
     * @return bool 링크(symlink/junction) 존재 + 대상 도달 가능 + 설정 루트 일치 여부
     */
    private static function detectPublicStorageLinkUsable(): bool
    {
        return static::isUsableStorageLink(public_path('storage'), PublicStorageService::rootPath());
    }

    /**
     * 주어진 경로가 **동작하는** 디렉토리 링크인지 판정합니다 (B-3 판정의 순수 술어).
     *
     * 판정 대상을 인자로 받는 이유는 이 규칙이 검사 가능해야 하기 때문이다 — 실 경로에
     * 고정돼 있으면 세 상태(가용·부재·끊어짐)를 재려면 실 저장소의 `public/storage` 를
     * 건드려야 하고, 그래서 이 로직은 오랫동안 어떤 테스트도 실행하지 않는 코드였다.
     *
     * 「링크는 있는데 대상이 없는」 반쪽 상태는 **불가**다 — 가용으로 읽으면 그 사이트의
     * 공개 이미지가 전부 조용히 404 가 된다.
     *
     * `$expectedTarget` 을 주면 링크가 **그 디렉토리**를 가리키는지까지 본다. 링크는
     * 정상인데 대상이 설정된 공개 스토리지 루트와 다른 상태는 「반쪽」의 다른 형태다 —
     * 링크는 살아 있으니 판정은 통과하지만 파일은 다른 트리에 쌓인다.
     *
     * @param  string  $path  검사할 경로
     * @param  string|null  $expectedTarget  링크가 가리켜야 할 디렉토리 (null 이면 대상 비교 생략)
     * @return bool 링크(symlink 또는 Windows junction)이면서 대상 디렉토리에 도달 가능하면 true
     */
    public static function isUsableStorageLink(string $path, ?string $expectedTarget = null): bool
    {
        if (! static::isReachableDirectoryLink($path)) {
            return false;
        }

        if ($expectedTarget === null) {
            return true;
        }

        $resolvedLink = realpath($path);

        if ($resolvedLink === false) {
            return false;
        }

        // 대상 디렉토리가 아직 없으면(로컬 디스크는 첫 쓰기에서 만든다) 해석 결과가 없으므로
        // 설정 문자열 자체를 정규화해 비교한다 — 그 경우에도 「링크가 다른 곳을 가리킨다」는
        // 사실은 성립하며, 여기서 관대하게 통과시키면 원래 결함이 그대로 남는다.
        $resolvedTarget = realpath($expectedTarget);

        return static::isSamePath($resolvedLink, $resolvedTarget === false ? $expectedTarget : $resolvedTarget);
    }

    /**
     * 경로가 **도달 가능한 디렉토리 링크**인지 판정합니다 (대상 비교 이전 단계).
     *
     * @param  string  $path  검사할 경로
     * @return bool 링크이면서 대상에 도달 가능하면 true
     */
    private static function isReachableDirectoryLink(string $path): bool
    {
        if (is_link($path)) {
            return is_dir($path); // 끊어진 링크는 불가로 판정
        }

        if (! static::isReparsePoint($path)) {
            return false;
        }

        // 「링크는 있는데 대상이 없는」 반쪽 상태를 걸러낸다.
        //
        // PHP 8.3.26 / Windows 11 직접 실측 (StorageLinkUsabilityTest 가 이 표를 고정한다):
        //
        //   대상            is_link  is_file  is_dir  file_exists  readlink
        //   정상 junction    false    false    false      true      target
        //   끊어진 junction  false    false    false      false     target
        //   일반 디렉토리    false    false    true       true      자기 경로
        //
        // 그래서 `file_exists() && ! is_file()` 이 정상 junction 만 통과시킨다.
        //
        // **`is_dir()` 로 바꾸지 말 것.** 정상 junction 도 is_dir() 이 false 라, 바꾸는 순간
        // 링크가 정상인데 「없음」으로 판정되어 그 사이트의 공개 자산 URL 이 통째로 대체 서빙
        // 경로로 넘어간다. 이 사실은 검증 라운드에서 두 번 뒤집혔다 — 1차가 실측으로 맞게
        // 적었고, 2차가 「is_dir·file_exists 둘 다 true」로 잘못 정정하면서 「판정식은 is_dir()
        // 과 결과가 같다」는 (역시 틀린) 근거를 함께 남겼다. 위 표가 그 둘을 모두 부정한다.
        return file_exists($path) && ! is_file($path);
    }

    /**
     * 문서 루트 **안**에 공개 파일 사본이 남았다는 사실 (호출부가 콘솔로 표면화한다).
     *
     * @var array<int, string>
     */
    private static array $docrootExposureWarnings = [];

    /**
     * 마지막 복구에서 문서 루트 노출이 생겼으면 그 사유를 꺼내고 버퍼를 비웁니다.
     *
     * @return array<int, string> 운영자 안내 문구 (없으면 빈 배열)
     */
    public static function takeDocrootExposureWarnings(): array
    {
        $warnings = static::$docrootExposureWarnings;
        static::$docrootExposureWarnings = [];

        return $warnings;
    }

    /**
     * 문서 루트 노출 통보를 버퍼에 담습니다.
     *
     * 같은 프로세스에서 통보를 만드는 자리(복구 폴백)와 꺼내는 자리(커맨드)가 다르므로
     * 버퍼가 필요하다. **별도 프로세스**에서 만들어진 통보를 부모가 받아 자기 통로로
     * 낼 때도 이 지점을 쓴다 — 업그레이드 스텝은 `core:execute-upgrade-steps` 자식에서
     * 도는 흐름이 있고, 그 프로세스에는 소비자가 없어 통보가 종료와 함께 폐기됐다.
     *
     * @param  string  $warning  운영자 안내 문구
     * @return void 반환값 없음
     */
    public static function recordDocrootExposureWarning(string $warning): void
    {
        if ($warning === '') {
            return;
        }

        static::$docrootExposureWarnings[] = $warning;
    }

    /**
     * `public/storage` 가 정상 symlink 인지 확인하고, 아니면 멱등 재생성합니다.
     *
     * 멱등 판정 순서:
     *   1. `is_link(public/storage)` → 이미 정상 symlink, no-op.
     *   2. `storage/app/public` 부재 → Laravel storage:link 컨벤션 미사용 환경, skip
     *      (false positive 차단 — 운영자 의도적 구성 존중).
     *   3. `public/storage` 부재 → `symlink()` 로 신규 생성 (prune 삭제 갭 복구 핵심).
     *   4. `public/storage` 가 일반 디렉토리 → `.broken.{YmdHis}` rename 백업 후 재생성
     *      (dereferenced 콘텐츠 데이터 손실 없이 보존).
     *
     * Windows `SeCreateSymbolicLink` 권한 부족으로 `symlink()` 가 실패하면 target 이
     * 디렉토리일 때 `mklink /J`(junction) 로 폴백한다. 그래도 실패하면 rename 백업을
     * 원위치로 복원하고 warning 로그만 남긴다 (graceful degrade — 데이터 손실 없음).
     *
     * @param  LoggerInterface|null  $logger  로그 채널 격리용 주입 로거.
     *                                        `null` 이면 `Log::channel('upgrade')` 로 폴백.
     * @return void 반환값 없음
     */
    public static function ensurePublicStorageLink(?LoggerInterface $logger = null): void
    {
        $logger ??= Log::channel('upgrade');

        // 버퍼는 **소비자만** 비운다 (`takeDocrootExposureWarnings()`). 생산자가 진입에서
        // 비우면, 같은 프로세스의 앞선 호출이 만든 통보가 읽히기 전에 사라진다 — 업그레이드
        // 스텝이 in-process 폴백으로 돌아 이 함수를 부르고, 뒤이어 커맨드가 같은 함수를
        // 다시 부르는 흐름이 실재한다. 그러면 「공개 파일 전량 사본이 지금 웹에서 열린다」는
        // 사실이 콘솔에 한 줄도 뜨지 않고 로그 파일에만 남는다.

        // 복구가 링크 상태를 바꿀 수 있으므로 가용 여부 memo 를 무효화한다 (B-3 판정 동기).
        static::resetPublicStorageLinkMemo();

        $publicStorage = public_path('storage');
        // 복구가 만드는 링크의 대상은 **판정기가 보는 것과 같은 출처**여야 한다. 종전에는
        // 이 자리만 리터럴(`storage_path('app/public')`)이라, 운영자가 공개 디스크 루트를
        // 옮긴 사이트에서 복구가 옛 위치를 가리키는 링크를 만들었다 — 그러면
        // `isUsableStorageLink()` 가 대상 불일치로 즉시 「불가」로 읽는 반쪽 상태를
        // 복구기 자신이 생산한다.
        $storageSource = PublicStorageService::rootPath();

        // 공개 디스크 직접 주소의 웹서버 보호 규칙 파일을 함께 보장한다 (링크 상태와 무관하게, 멱등).
        static::ensurePublicDiskHardening($logger);

        // 1) Laravel storage:link source 부재 → 표준 미사용 환경, skip (false positive 차단)
        //
        // 멱등 판정보다 앞에 둔다 — 대상이 없으면 「그 대상을 가리키는가」를 물을 수 없고,
        // 어느 쪽이든 결과는 no-op 로 같다.
        if (! is_dir($storageSource)) {
            return;
        }

        // 2) 이미 **설정된 루트를 가리키는** 정상 링크 → no-op (멱등)
        //
        // 종전 게이트는 `is_link()` 하나였다. 그래서 앞서 「복구기가 옛 위치를 가리키는
        // 링크를 **생산**하지 않게」 고친 뒤에도, 이미 그 상태인 사이트는 복구기가 **고치지도
        // 않은 채** 그대로 지나쳤다 — 링크는 살아 있으니 `is_link()` 가 true 이고, 같은
        // 상태를 판정기(`isUsableStorageLink`)는 대상 불일치로 「불가」로 읽는다. 즉 생산은
        // 막았는데 잔존은 영구였고, 그 사이트의 공개 자산은 오류도 로그도 없이 전부 404 다.
        //
        // 끊어진 링크(dangling)도 종전에는 여기서 no-op 이었다 — `is_link()` 는 대상을 보지
        // 않는다.
        if (static::isUsableStorageLink($publicStorage, $storageSource)) {
            return;
        }

        // 2-1) 링크이긴 한데 **다른 곳을 가리키거나 끊어진** 상태 → 링크만 걷고 재생성.
        //
        // 지우는 대상이 링크 **자신**이라 데이터가 사라지지 않는다(대상 트리는 그대로다).
        // 일반 디렉토리를 지우는 아래 (4) 와는 성질이 다르므로 백업을 두지 않는다.
        if (static::isLinkOrJunction($publicStorage)) {
            $removed = PHP_OS_FAMILY === 'Windows' && ! is_link($publicStorage)
                // Windows junction 은 디렉토리 엔트리라 `unlink` 가 아니라 `rmdir` 로 걷는다.
                ? @rmdir($publicStorage)
                : @unlink($publicStorage);

            if (! $removed) {
                $logger->warning('[storage-link] public/storage 링크가 설정된 공개 스토리지 루트를 가리키지 않는데 걷어내지 못했다 — 수동 확인 필요', [
                    'link' => $publicStorage,
                    'expected_target' => $storageSource,
                ]);

                return;
            }

            if (static::createLink($storageSource, $publicStorage)) {
                $logger->info('[storage-link] public/storage 링크를 설정된 공개 스토리지 루트로 재연결', [
                    'target' => $storageSource,
                ]);
            } else {
                $logger->warning('[storage-link] public/storage 링크 재연결 실패 — 수동 `php artisan storage:link` 필요', [
                    'target' => $storageSource,
                ]);
            }

            return;
        }

        // 3) public/storage 부재 → 신규 생성 (prune 이 삭제한 케이스 — 기존 스텝 미커버)
        if (! file_exists($publicStorage)) {
            if (static::createLink($storageSource, $publicStorage)) {
                $logger->info('[storage-link] public/storage symlink 신규 생성', [
                    'target' => $storageSource,
                ]);
            } else {
                $logger->warning('[storage-link] public/storage symlink 생성 실패 — 수동 `php artisan storage:link` 필요', [
                    'target' => $storageSource,
                ]);
            }

            return;
        }

        // Windows junction(`mklink /J`)은 `is_link()` 가 false 지만 이미 정상 링크다
        // (reparse point) — rename 백업 대상이 아니다. 그 판정은 위 (2)·(2-1) 로 올라갔다:
        // 설정 루트를 가리키는 junction 은 (2) 가 no-op 로 존중하고, 다른 곳을 가리키는
        // junction 은 (2-1) 이 걷어내 재연결한다. 종전에는 이 자리가 **대상을 보지 않고**
        // 무조건 no-op 이라, 잘못 걸린 junction 이 영구히 남았다.

        // 4) public/storage 가 일반 디렉토리(손상) → rename 백업 후 재생성
        //
        // 백업은 **웹 트리(`public/`) 밖**으로 옮긴다. `public/storage.broken.YmdHis` 에 두면
        // 그 사본이 제2 URL 로 무기한 서빙되어, 운영자가 지운 공개 파일이 계속 열린다
        // (정리 주체도 없다). 볼륨이 달라 그 이동이 불가능한 환경에서는 종전 위치로
        // 폴백한다 — 복구 자체를 건너뛰는 것이 더 나쁘다.
        //
        // 「문서 루트 밖」이 아니라 「웹 트리 밖」이다. **배포 레이아웃 ②**(문서 루트 =
        // 프로젝트 루트)에서는 `storage/` 도 문서 루트 **안**이고, 그 배치를 지키는 것은
        // 루트 보호 파일 하나뿐이다 — 그 파일 갱신이 실패했거나 `AllowOverride` 로 무시되는
        // 서버라면 이 사본도 그대로 열린다. 종전 주석은 그 사실을 「밖」이라 단정했고,
        // 그래서 이 경로에서는 노출 통보가 한 번도 발화하지 않았다.
        // 이름은 **유일화**한다 (폴백 경로와 같은 규칙). 초 단위 시각만 붙이면 같은 초의
        // 재시도나 구 판본이 남긴 동명 잔해와 충돌해 rename 이 실패하고, 그 실패는
        // 「볼륨이 달라 못 옮겼다」와 구분되지 않은 채 곧장 웹 트리 안 폴백으로 간다 —
        // 즉 옮길 수 있는 상황인데도 공개 파일 사본이 웹에 남는다.
        $backup = storage_path('app/'.self::PUBLIC_STORAGE_BROKEN_PREFIX.self::brokenStamp());
        $moved = @rename($publicStorage, $backup);
        $insideWebTree = false;

        if (! $moved) {
            // 폴백 이름은 **유일화**한다. 종전에는 초 단위 시각만 붙이고 성공 여부를
            // `is_dir($backup)` 으로 되물었는데, 그 판정은 「방금 내가 옮겼다」와 「그 이름의
            // 디렉토리가 이미 있다」를 구분하지 못한다 — 같은 초 안의 재시도나 구 판본이
            // 남긴 동명 잔해가 있으면 2차 rename 을 건너뛰고, `public/storage` 가 일반
            // 디렉토리인 채로 재생성 단계에 들어가 `symlink()` 가 EEXIST 로 실패하고
            // 이어지는 원복까지 실패한다(로그는 「재생성 실패」만 남아 원인을 가린다).
            // 성공 여부는 되묻지 말고 불리언으로 들고 다닌다.
            $backup = dirname($publicStorage).DIRECTORY_SEPARATOR.self::DOCROOT_BROKEN_PREFIX.self::brokenStamp();
            $moved = @rename($publicStorage, $backup);
            $insideWebTree = $moved;
        }

        if (! $moved) {
            $logger->warning('[storage-link] public/storage rename 실패 — 복구 skip', [
                'path' => $publicStorage,
            ]);

            return;
        }

        if (static::createLink($storageSource, $publicStorage)) {
            if ($insideWebTree) {
                // 폴백으로 문서 루트 **안**에 남은 사본이다 — 지금 이 순간 웹에서 열린다.
                // 그 사실을 info 로 흘리면 「검증 후 삭제 권장」과 구분되지 않으므로
                // 등급을 올려 알린다.
                $logger->warning('[storage-link] public/storage symlink 재생성 완료 — 백업을 문서 루트 밖으로 옮기지 못해 그 사본이 **현재 웹에서 접근 가능**하다. 내용을 확인한 뒤 즉시 옮기거나 삭제할 것', [
                    'backup' => $backup,
                    'target' => $storageSource,
                ]);

                // 로그 채널만으로는 부족하다 — 이것은 「문서 루트에 공개 파일 전량 사본이
                // 노출됐다」는 사실이고, 형제 축(루트 보호 파일)은 이미 콘솔 통로를 갖는다.
                // 같은 위험 등급이 한쪽은 콘솔, 한쪽은 로그 파일에만 남으면 운영자는 모른다.
                static::$docrootExposureWarnings[] = (string) __(
                    'settings.core_update.storage_link_backup_inside_docroot',
                    ['path' => $backup]
                );

                return;
            }

            $logger->info('[storage-link] public/storage symlink 재생성 완료 — 백업 디렉토리 검증 후 수동 삭제 권장', [
                'backup' => $backup,
                'target' => $storageSource,
            ]);

            // 웹 트리 밖으로 옮기는 데 성공한 경우다. 그래도 운영자에게 알린다 —
            // **배포 레이아웃 ②**(문서 루트 = 프로젝트 루트)에서는 `storage/` 도 문서 루트
            // 안이라, 그 사본을 가리는 것은 루트 보호 파일 하나뿐이다. 그 파일 갱신이
            // 실패했거나 `AllowOverride` 로 무시되는 서버라면 공개 파일 전량 사본이 그대로
            // 열린다. 이 코드는 문서 루트가 어디인지 알 수 없으므로 **단정하지 않고**
            // 조건을 밝혀 전달한다 — 판단과 조치는 배치를 아는 운영자가 한다.
            static::$docrootExposureWarnings[] = (string) __(
                'settings.core_update.storage_link_backup_needs_review',
                ['path' => $backup]
            );

            static::warnOnAccumulatedBackups($logger);

            return;
        }

        // 재생성 실패 → rename 원복 (데이터 손실 없음)
        @rename($backup, $publicStorage);
        $logger->warning('[storage-link] public/storage symlink 재생성 실패 — rename 원복, 수동 `php artisan storage:link` 필요', [
            'target' => $storageSource,
        ]);
    }

    /**
     * 공개 디스크 루트에 웹서버 보호 규칙 파일(`.htaccess`)이 놓여 있게 합니다(멱등).
     *
     * 규칙 생성 클래스는 7.1.6 에서 처음 생겼다 — 이전 버전 메모리에서 불려도 죽지 않게 존재를 확인한다.
     *
     * @param  LoggerInterface  $logger  로거
     * @return void 반환값 없음
     */
    protected static function ensurePublicDiskHardening(LoggerInterface $logger): void
    {
        if (! class_exists(PublicDiskHardening::class) || ! is_dir(storage_path('app/public'))) {
            return;
        }

        try {
            if (! PublicDiskHardening::ensureHtaccess()) {
                $logger->warning('[storage-link] 공개 디스크 보호 규칙 파일(.htaccess)을 쓰지 못했습니다 — 권한을 확인하세요');
            }
        } catch (\Throwable $e) {
            $logger->warning('[storage-link] 공개 디스크 보호 규칙 파일 생성 실패', ['error' => $e->getMessage()]);
        }
    }

    /**
     * `symlink()` 로 링크를 생성하고, 실패 시 Windows junction 폴백을 시도합니다.
     *
     * Windows 에서 PHP `symlink()` 는 `SeCreateSymbolicLink` 권한이 필요하지만 junction
     * (`mklink /J`)은 권한 없이 생성 가능하므로, target 이 디렉토리이면 junction 으로 폴백한다.
     *
     * @param  string  $target  링크가 가리킬 대상 (`storage/app/public`)
     * @param  string  $link  생성할 링크 경로 (`public/storage`)
     * @return bool 링크 생성 성공 여부
     */
    protected static function createLink(string $target, string $link): bool
    {
        // `symlink` 도 호스팅이 차단할 수 있는 함수다 — 인스톨러는 이미 이 이름을 그렇게
        // 다룬다(`installerHasFunction('symlink')`). `@` 는 가드가 아니다: PHP 8 은 차단
        // 함수를 함수 테이블에서 제거하므로 그 호출은 `Error`(치명)이고 `@` 는 진단만
        // 억제한다. 차단 환경에서는 아래 junction 폴백을 거쳐 false 로 내려가고, 호출자가
        // 안내 경로(수동 `storage:link` · 링크 없음 시 대체 서빙 라우트)를 탄다.
        if (ExecutionEnvironment::hasFunction('symlink') && @symlink($target, $link)) {
            static::inheritParentOwnership($link);

            return true;
        }

        // symlink 실패 + target 이 디렉토리 → Windows junction 폴백.
        // `exec` 는 호스팅이 차단할 수 있는 함수다 — 무가드로 부르면 PHP 8 에서 Error(치명)가
        // 된다. 차단 환경에서는 폴백 없이 false 를 돌려 호출자의 안내 경로(수동 storage:link,
        // 링크 없음 시 대체 서빙 라우트)로 넘긴다 (G3 이관 — hosting-blocked-function 가드).
        if (PHP_OS_FAMILY === 'Windows' && is_dir($target) && ExecutionEnvironment::hasFunction('exec')) {
            exec(
                'cmd /c mklink /J '.escapeshellarg($link).' '.escapeshellarg($target).' 2>&1',
                $out,
                $code
            );
            if ($code === 0 && is_dir($link)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 갓 생성한 링크의 소유자/그룹을 부모 디렉토리로부터 상속시킵니다.
     *
     * `symlink()` 는 소유권 지정 인자가 없어 실행 프로세스(sudo → root)의 소유로 링크를
     * 만든다. 이 때문에 sudo 로 실행된 코어 업데이트가 종료 시점에 `public/storage` 를
     * 재생성하면 링크가 root:root 로 남아, 원래 앱 실행 유저 소유였던 링크의 소유권이
     * 바뀌던 문제(공개 #43 후속)를 차단한다. 부모 `public/` 의 owner/group 을 기준으로 삼아
     * 이 프로젝트의 "신규 항목은 부모 소유권 상속" 컨벤션(`FilePermissionHelper`)과 일치시킨다.
     *
     * `chown`/`chgrp` 은 심볼릭 링크의 target 을 따라가므로, 링크 *자체* 를 대상으로 하는
     * `lchown`/`lchgrp` 을 사용한다. 비-POSIX(Windows) 또는 함수 부재/권한 부족 시 무해하게 skip.
     *
     * @param  string  $link  방금 생성한 링크 경로
     * @return void 반환값 없음
     */
    protected static function inheritParentOwnership(string $link): void
    {
        // lchown/lchgrp 은 POSIX 전용 — Windows 및 함수 비활성 환경에서는 no-op.
        if (! function_exists('lchown') || ! function_exists('lchgrp')) {
            return;
        }

        $parent = dirname($link);
        $owner = @fileowner($parent);
        $group = @filegroup($parent);

        if ($owner !== false) {
            @lchown($link, $owner);
        }
        if ($group !== false) {
            @lchgrp($link, $group);
        }
    }

    /**
     * 손상 백업이 쌓이면 경고합니다.
     *
     * 이 백업은 **지우지 않는다** — 링크가 깨져 있던 창에 업로드된 파일이 그 안에만 남아
     * 있을 수 있어, 자동 삭제는 데이터 손실이 된다. 그러나 링크가 반복해서 손상되는 환경
     * (공유 호스팅의 주기적 파일 동기화)에서는 공개 파일 전량 사본이 실행마다 하나씩 쌓여
     * 디스크를 잠식하는데, 「검증 후 수동 삭제 권장」은 그 사실이 몇 개까지 늘었는지는 말해
     * 주지 않는다. 정리 주체가 사람이라면 적어도 그 사람에게 규모가 도달해야 한다.
     *
     * 두 생산자가 공유한다 — 링크 복구 폴백과 7.1.0 이설 마이그레이션. 후자는 문서 루트
     * 안의 백업 N 개를 한꺼번에 옮기므로, 옮긴 결과가 곧 이 더미다: 그 규모 통보가 그
     * 경로에서만 빠져 있었다.
     *
     * @param  LoggerInterface  $logger  로거
     * @return void 반환값 없음
     */
    public static function warnOnAccumulatedBackups(LoggerInterface $logger): void
    {
        // 모집단 판정은 **정리 주체와 같은 규칙**(이름 형식)이다.
        //
        // 종전에는 접두사만 봤다. 그러면 운영자가 같은 이름 공간에 둔 디렉토리가 개수에
        // 섞여 임계를 앞당기고, 반대로 형식이 맞는 것만 세는 정리 주체와 숫자가 갈린다 —
        // 「N개 쌓였다」와 「그중 회수 대상은 몇 개인가」가 어긋나면 안내가 무의미해진다.
        //
        // 웹 트리 **안**에 남은 사본(`public/storage.broken.*`)도 같은 더미다. 그쪽은
        // 폴백 경로와 7.0.x 기설치본이 만들고, 위험 등급은 오히려 더 높은데(그 자리에서
        // 서빙된다) 종전 모집단은 `storage/app` 만 세어 그 규모가 한 번도 보고되지 않았다.
        $outside = static::listBrokenBackups(
            storage_path('app'),
            self::PUBLIC_STORAGE_BROKEN_PREFIX,
            static::publicStorageBrokenNamePattern()
        );
        $insideWebTree = static::listBrokenBackups(
            public_path(),
            self::DOCROOT_BROKEN_PREFIX,
            static::docrootBrokenNamePattern()
        );

        $backups = array_merge($outside, $insideWebTree);

        if (count($backups) < self::ACCUMULATED_BACKUP_WARN_THRESHOLD) {
            return;
        }

        // 위치는 **실제로 항목이 있는 곳만** 말한다. 개수는 두 자리를 합산하는데 위치는
        // 한 곳만 대면, 운영자가 그 디렉토리를 열고 「숫자가 맞지 않는다」로 끝난다.
        $locations = [];

        if ($outside !== []) {
            $locations[] = storage_path('app');
        }

        if ($insideWebTree !== []) {
            $locations[] = public_path();
        }

        $logger->warning('[storage-link] 손상 백업 디렉토리가 쌓여 있습니다 — 내용을 확인한 뒤 정리하세요 (코어는 데이터 손실을 피하려 자동 삭제하지 않습니다)', [
            'count' => count($backups),
            'location' => implode(', ', $locations),
            'inside_web_tree' => count($insideWebTree),
        ]);

        // 같은 사실을 **운영자에게 도달하는 통로**에도 싣는다.
        //
        // 로그 채널만으로는 부족하다 — 이 공정이 대상으로 삼은 배치(무CLI 공유 호스팅)의
        // 운영자는 `storage/logs/*.log` 를 열 수단이 없다. 같은 클래스가 같은 위험 등급에
        // 이미 쓰고 있는 통보 버퍼를 함께 쓴다. 그 규율을 명문화한 문장이 바로 이 백업을
        // 문서 루트 밖으로 옮긴 업그레이드 스텝 안에 있다.
        //
        // 문구는 노출 경고와 **분리**한다 — 이것은 보안이 아니라 용량 회수 안내다.
        //
        // 문구를 여기에 한국어로 적어 두면 영어 운영자에게 한국어가 그대로 나간다. 같은
        // 버퍼에 실리는 형제 통보 둘은 이미 `__()` 를 쓰는데 이 자리만 하드코딩이었다.
        static::recordDocrootExposureWarning((string) __(
            'settings.core_update.storage_link_backup_accumulated',
            ['count' => count($backups), 'path' => implode(', ', $locations)]
        ));
    }

    /**
     * 한 디렉토리에서 **이름 형식이 맞는** 손상 백업만 나열합니다.
     *
     * @param  string  $directory  나열할 디렉토리 절대 경로
     * @param  string  $prefix  이름 접두사
     * @param  string  $namePattern  이름 전체를 판정하는 정규식
     * @return array<int, string> 백업 디렉토리 절대 경로 목록
     */
    private static function listBrokenBackups(string $directory, string $prefix, string $namePattern): array
    {
        $found = [];

        foreach (ExtensionManager::listDirectories($directory, $prefix) as $path) {
            if (preg_match($namePattern, basename($path)) === 1) {
                $found[] = $path;
            }
        }

        return $found;
    }

    /**
     * 경로가 심볼릭 링크이거나 Windows junction 인지 판정합니다.
     *
     * `is_link()` 만으로는 Windows junction 을 보지 못한다 — 「링크를 따라가지 않는다」를
     * 계약으로 갖는 지점(공개 스토리지 서빙의 경로 구성요소 검사 등)은 이 판정을 공유해야
     * 한다. 한쪽만 `is_link()` 를 쓰면 그 지점의 계약이 OS 에 따라 성립·불성립으로 갈린다.
     *
     * @param  string  $path  검사할 경로
     * @return bool 심볼릭 링크 또는 junction 이면 true
     */
    public static function isLinkOrJunction(string $path): bool
    {
        return is_link($path) || static::isReparsePoint($path);
    }

    /**
     * 두 경로가 같은 위치를 가리키는지 판정합니다.
     *
     * 구분자와 후행 슬래시를 흡수하고, Windows 에서는 대소문자도 무시한다 — 같은
     * 디렉토리를 표기 차이만으로 「다르다」고 읽으면 정상 배치가 대체 서빙으로 넘어간다.
     *
     * @param  string  $a  비교 대상 경로
     * @param  string  $b  비교 대상 경로
     * @return bool 같은 위치이면 true
     */
    private static function isSamePath(string $a, string $b): bool
    {
        $normalize = static function (string $path): string {
            $normalized = rtrim(str_replace('\\', '/', $path), '/');

            return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
        };

        return $normalize($a) === $normalize($b);
    }

    /**
     * 경로가 Windows JUNCTION 인지 판정합니다 (`is_link()` 미인식 reparse point).
     *
     * Windows 에서 junction 과 일반 디렉토리는 `is_link()`·`is_file()` 이 둘 다 false 라
     * 구분되지 않고, `readlink()` 는 일반 디렉토리에는 **자기 경로**를, junction 에는
     * **target 경로**를 반환한다. 따라서 "readlink 가 자기 경로와 다른 target 을 반환" 하는
     * 경우만 junction 으로 판정한다. 일반 symlink 는 위 가드가 먼저 걸러내므로 여기서 false,
     * 비-Windows 는 항상 false 다. 이 판정으로 정상 junction 은 rename 재생성 대상에서
     * 제외된다.
     *
     * @param  string  $path  검사할 경로
     * @return bool junction 여부
     */
    protected static function isReparsePoint(string $path): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        // 일반 symlink / 파일 은 junction 아님.
        if (is_link($path) || is_file($path)) {
            return false;
        }

        $target = @readlink($path);
        if ($target === false) {
            return false;
        }

        // readlink 가 자기 경로와 다른 target 을 반환하면 junction. (일반 디렉토리는 자기 경로
        // 반환 → 같으므로 false.) 경로 구분자/대소문자 차이를 흡수하기 위해 정규화 후 비교.
        $normalize = static fn (string $p): string => strtolower(str_replace('/', '\\', rtrim($p, '/\\')));

        return $normalize($target) !== $normalize($path);
    }
}
