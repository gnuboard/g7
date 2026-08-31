<?php

namespace Tests\Unit\Services;

use App\Extension\Helpers\StorageLinkHelper;
use App\Extension\Storage\CoreStorageDriver;
use App\Services\PublicStorageService;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 공개 스토리지 경로 해석(B-3)의 경로 봉쇄 계약 테스트.
 *
 * Feature 테스트(`PublicStorageServeTest`)의 링크 케이스는 POSIX `symlink()` 에 의존해
 * Windows 에서 skip 된다 — 이 저장소의 표준 실행 환경이 Windows 라 「심볼릭 링크 비추적」
 * 축이 상시 무게이트였다. 여기서는 OS 별로 만들 수 있는 링크 종류(POSIX symlink /
 * Windows junction)를 각각 만들어 같은 계약을 양쪽에서 고정한다.
 *
 * @scenario link_kind=symlink, link_position=leaf, target=inside_root
 */
class PublicStorageServiceTest extends TestCase
{
    /** 작업 디렉토리 접두사 — 잔여물 스윕이 이 접두사 전체를 본다. */
    private const WORK_PREFIX = 'g7-test-pss-';

    private string $root;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('app/public/'.self::WORK_PREFIX.bin2hex(random_bytes(4)));
        $this->outside = storage_path('app/'.self::WORK_PREFIX.'outside-'.bin2hex(random_bytes(4)));

        mkdir($this->root.'/plain', 0755, true);
        mkdir($this->outside, 0755, true);
        file_put_contents($this->root.'/plain/ok.txt', 'inside');
        file_put_contents($this->outside.'/secret.txt', 'outside');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        $this->removeTree($this->outside);

        // 앞선 실행이 남긴 잔여물도 함께 걷는다.
        //
        // 이 테스트는 실 `storage/app/public` 아래에 작업 디렉토리를 만든다(공개 스토리지
        // 서빙의 루트가 그곳으로 고정돼 있어 다른 곳에서는 검증이 성립하지 않는다). 그래서
        // 한 번이라도 fatal 로 죽거나 링크 삭제가 실패하면 그 디렉토리가 개발자 트리에
        // 영구히 남고, 실행할 때마다 하나씩 늘어난다 — 실제로 36개가 쌓여 있었다.
        // tearDown 하나에만 기대지 않고 진입/종료 양쪽에서 접두사를 훑는다.
        foreach (glob(storage_path('app/public').DIRECTORY_SEPARATOR.self::WORK_PREFIX.'*') ?: [] as $stale) {
            if (is_dir($stale)) {
                $this->removeTree($stale);
            }
        }

        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;

            // 링크·junction 은 내용을 따라가지 않고 자신만 지운다.
            //
            // 판정은 `StorageLinkHelper` 가 SSoT 다. 종전에는 여기서 `@readlink() !== false` 로
            // junction 을 가렸는데, Windows 의 이 PHP 빌드에서 `readlink()` 는 **일반 디렉토리에도
            // 자기 경로를 돌려준다** — 그래서 모든 평범한 디렉토리가 junction 으로 오판돼
            // 비어 있지도 않은데 `rmdir` 을 맞고, 실패하면서 트리 전체가 남았다. 이 테스트가
            // 실 `storage/app/public` 을 쓰기 때문에 그 잔해가 개발자 트리에 계속 쌓였다(36개).
            if (StorageLinkHelper::isLinkOrJunction($path)) {
                @unlink($path) || @rmdir($path);

                continue;
            }
            if (is_dir($path)) {
                $this->removeTree($path);

                continue;
            }
            @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * OS 별로 가능한 방식으로 디렉토리 링크를 만든다.
     *
     * POSIX 는 symlink, Windows 는 junction(`mklink /J` — symlink 와 달리 관리자 권한이
     * 필요 없다). 둘 다 실패하면 그 환경에서는 이 축을 잴 수 없다.
     */
    private function makeDirectoryLink(string $link, string $target): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return @symlink($target, $link);
        }

        if (@symlink($target, $link)) {
            return true;
        }

        $command = 'cmd /c mklink /J '.escapeshellarg(str_replace('/', '\\', $link))
            .' '.escapeshellarg(str_replace('/', '\\', $target)).' 2>&1';
        @exec($command, $out, $code);

        return $code === 0 && (is_dir($link) || is_link($link));
    }

    private function relative(string $absolute): string
    {
        $base = str_replace('\\', '/', storage_path('app/public')).'/';

        return substr(str_replace('\\', '/', $absolute), strlen($base));
    }

    public function test_resolves_plain_file_under_root(): void
    {
        $result = (new PublicStorageService)->getFilePath($this->relative($this->root.'/plain/ok.txt'));

        $this->assertTrue($result['success']);
        $this->assertSame('inside', file_get_contents((string) $result['filePath']));
    }

    /**
     * 실재하지만 읽을 수 없는 파일은 `file_not_readable` 로 돌려준다 (15차 G3 라운드).
     *
     * 종전에는 존재·realpath 판정만 하고 성공을 돌려줘, 뒤이은 `BinaryFileResponse` 가
     * `File must be readable` 로 던져 무인증 표면이 500 이었다. 정적 서빙은 같은 파일에
     * 403 을 준다. 판정은 이 서비스가 소유하고 컨트롤러가 403 으로 배선한다
     * (`PublicStorageServeTest`). 권한 판정 자체는 `isReadable()` 한 자리라, Windows 처럼
     * `chmod` 로 재현할 수 없는 환경에서는 그 자리를 덮어 잠근다.
     *
     * @effects public_storage_unreadable_file_is_forbidden_like_static_serving
     */
    public function test_unreadable_file_is_reported_as_not_readable(): void
    {
        $service = new class extends PublicStorageService
        {
            protected function isReadable(string $filePath): bool
            {
                return false;
            }
        };

        $result = $service->getFilePath($this->relative($this->root.'/plain/ok.txt'));

        $this->assertFalse($result['success']);
        $this->assertSame('file_not_readable', $result['error']);
        $this->assertNull($result['filePath']);
    }

    /**
     * 이름이 점으로 시작하는 파일은 이 통로로도 내주지 않는다.
     *
     * 배포 레이아웃 ②의 루트 `.htaccess` 가 `<FilesMatch "^\.">` 로 숨김 파일을 막는데
     * 그 방어선은 **정적 서빙에만** 걸린다. 이 통로가 같은 규칙을 갖지 않으면 링크가 없는
     * 사이트에서만 `.gitignore`·`.htaccess` 가 열려, 같은 사이트가 링크 유무로 다르게
     * 동작한다. `storage/app/public/.gitignore` 는 저장소에 실재하는 파일이다.
     *
     * @effects public_storage_route_refuses_dot_files_like_the_static_defense_line
     */
    public function test_rejects_dot_files_and_dot_directories(): void
    {
        file_put_contents($this->root.'/.gitignore', '*');
        mkdir($this->root.'/.hidden', 0755, true);
        file_put_contents($this->root.'/.hidden/inside.txt', 'x');

        $service = new PublicStorageService;

        $file = $service->getFilePath($this->relative($this->root).'/.gitignore');
        $this->assertFalse($file['success'], '숨김 파일이 열리면 정적 서빙의 방어선과 어긋난다');
        $this->assertSame('file_not_found', $file['error']);

        $nested = $service->getFilePath($this->relative($this->root).'/.hidden/inside.txt');
        $this->assertFalse($nested['success'], '경로 중간의 숨김 디렉토리도 같은 규칙이다');
        $this->assertSame('file_not_found', $nested['error']);
    }

    /**
     * 널바이트가 든 경로는 **이 계층에서** 거부된다 (형제 통로와 같은 강도).
     *
     * 형제 2차 봉쇄(`SecuresExtensionAssetPath`)의 독블록은 「PublicStorageService 는 같은
     * 두 축(널바이트·숨김 세그먼트)을 이미 서비스 계층에 갖고 있다」를 근거로 그 축을
     * 도입했는데, 널바이트 축은 실제로 여기에 없었다 — 문서와 구현이 서로를 근거로 삼는
     * 상태다. 그리고 그 부재는 무해하지 않다: 경로 구성요소 검사가 `readlink()` 를 부르는데
     * PHP 8 의 그 함수는 널바이트에 **ValueError 를 던지고** `@` 로도 억눌러지지 않는다
     * (Windows 실측). 서비스는 확장에 열려 있어 「현재 호출부가 FormRequest 를 거친다」는
     * 방어가 되지 못한다.
     *
     * 응답 형태는 `file_not_found` 다 — 널바이트가 든 이름의 파일은 실재할 수 없고, 이
     * 통로의 거부는 정적 서빙과 같은 코드(404)를 쓴다.
     *
     * 플랫폼 주의: 예외 경로는 Windows 의 junction 판정에서만 도는다. POSIX 에서는 가드가
     * 없어도 `is_file()` 이 false 라 같은 404 로 끝나므로 그 환경에서는 이 케이스가 변이를
     * 잡지 못한다 — 계약 자체는 양쪽에서 같게 고정된다.
     *
     * @scenario path_shape=null_byte, segment_position=leaf, segment_position=intermediate
     *
     * @effects public_storage_route_rejects_null_byte_paths_at_the_service_layer
     */
    public function test_rejects_null_byte_paths(): void
    {
        $service = new PublicStorageService;

        $leaf = $service->getFilePath($this->relative($this->root)."/plain/ok.txt\0.png");
        $this->assertFalse($leaf['success'], '널바이트가 든 경로가 통과했다');
        $this->assertSame('file_not_found', $leaf['error']);

        $intermediate = $service->getFilePath($this->relative($this->root)."/pla\0in/ok.txt");
        $this->assertFalse($intermediate['success'], '경로 중간의 널바이트도 같은 규칙이다');
        $this->assertSame('file_not_found', $intermediate['error']);
    }

    /**
     * `a/./b` 는 거부가 아니라 정규화다 — 정적 서빙이 200 을 주는 주소다.
     *
     * @effects public_storage_route_normalizes_dot_segment_like_static_serving
     */
    public function test_current_directory_segment_is_normalized_not_rejected(): void
    {
        $result = (new PublicStorageService)->getFilePath($this->relative($this->root).'/plain/./ok.txt');

        $this->assertTrue($result['success'], '정적 서빙은 이 주소에 200 을 준다 — 링크 유무로 결과가 갈리면 안 된다');
        $this->assertSame('inside', file_get_contents((string) $result['filePath']));
    }

    /**
     * 운영자가 옮긴 공개 디스크 루트를 따른다 — 파일을 쓰는 쪽과 읽는 쪽의 기준이 같아야 한다.
     *
     * 6차 라운드가 형제 키 `url` 에 세운 「운영자 설정이 우선」 규칙의 같은 축이다.
     * 이 값이 갈리면 링크가 없는 그 사이트의 공개 자산이 통째로 404 가 된다.
     *
     * @effects configured_public_disk_root_is_the_single_source_for_serving
     */
    public function test_root_path_follows_the_configured_public_disk_root(): void
    {
        $this->assertSame(
            storage_path('app/public'),
            PublicStorageService::rootPath(),
            '기본 설정에서는 종전 경로와 같아야 한다 (회귀 축)'
        );

        $moved = storage_path('app/'.self::WORK_PREFIX.'moved-root');
        config()->set('filesystems.disks.public.root', $moved);

        $this->assertSame($moved, PublicStorageService::rootPath(), '운영자가 옮긴 루트를 따라야 한다');

        config()->set('filesystems.disks.public.root', null);
        $this->assertSame(storage_path('app/public'), PublicStorageService::rootPath(), '미설정이면 기본값으로 돌아온다');
    }

    /**
     * `root` 는 드라이버마다 뜻이 다르다 — 로컬 절대 경로일 때만 서빙 루트로 신뢰한다.
     *
     * S3 의 `root` 는 파일시스템 경로가 아니라 버킷 안의 **키 접두사**(`media` 등)다.
     * 그 값을 그대로 쓰면 `realpath('media')` 가 웹 프로세스의 작업 디렉토리 기준으로
     * 풀려, 경로 봉쇄의 기준선이 storage 트리 밖의 임의 디렉토리가 된다.
     *
     * @effects configured_public_disk_root_is_the_single_source_for_serving
     */
    public function test_root_path_ignores_non_local_driver_and_relative_root(): void
    {
        config()->set('filesystems.disks.public', [
            'driver' => 's3',
            'bucket' => 'g7-test-bucket',
            'root' => 'media',
        ]);

        $this->assertSame(
            storage_path('app/public'),
            PublicStorageService::rootPath(),
            'S3 의 root 는 키 접두사다 — 로컬 경로로 신뢰하면 봉쇄 기준선이 CWD 기준으로 풀린다'
        );

        config()->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => 'relative/media',
        ]);

        $this->assertSame(
            storage_path('app/public'),
            PublicStorageService::rootPath(),
            '상대 경로는 실행 문맥(작업 디렉토리)에 따라 다른 곳을 가리킨다'
        );
    }

    /**
     * 상위 세그먼트(`..`)는 **404** 로 거부한다 — 형제 통로·정적 서빙과 같은 코드다.
     *
     * 종전 판본은 403(`path_not_allowed`)이었다. 그 상태가 두 규정을 동시에 어겼다:
     *
     *  ① 확장 자산 세 통로(module·plugin·template)는 같은 입력에 404 를 준다(정제기가
     *     점 시작 세그먼트를 걷어내 결과가 파일이 아니게 된다). 정적 서빙도 404 다.
     *     이 통로만 403 이면 같은 사이트가 `public/storage` 링크 유무에 따라 다른 응답을
     *     내는데, 그 차이는 오류가 아니라 조용한 동작 차이로만 나타난다.
     *  ② 같은 함수의 `realpath` 실패 분기는 「403 이 『존재한다』를 뜻하면 파일 존재 여부가
     *     샌다」는 사유로 이미 404 를 고른다. 그 원칙을 한 함수 안에서 둘로 가르면 어느
     *     쪽이 기준인지 알 수 없고, 실제로 상위 디렉토리의 실재 여부가 응답 코드로
     *     드러났다(실재하면 규칙이 422, 없으면 서비스가 403).
     *
     * @effects public_storage_route_rejects_traversal_and_symlink
     */
    public function test_rejects_parent_segment(): void
    {
        $result = (new PublicStorageService)->getFilePath($this->relative($this->root).'/plain/../plain/ok.txt');

        $this->assertFalse($result['success']);
        $this->assertSame(
            'file_not_found',
            $result['error'],
            '`..` 거부가 형제 통로·정적 서빙과 다른 코드를 낸다 — 링크 유무로 응답이 갈리고 403 이 존재를 알린다'
        );
    }

    /**
     * 경로 **중간** 구성요소가 링크면 대상이 루트 안이어도 거부한다 (경유 자체가 계약 위반).
     *
     * Windows junction 은 `is_link()` 로 보이지 않는다 — 그 사각을 이 케이스가 지킨다.
     *
     * @effects public_storage_route_rejects_traversal_and_symlink
     */
    public function test_rejects_intermediate_directory_link_even_when_target_is_inside_root(): void
    {
        $link = $this->root.'/linked';
        if (! $this->makeDirectoryLink($link, $this->root.'/plain')) {
            $this->markTestSkipped('이 환경에서는 디렉토리 링크(symlink·junction)를 만들 수 없다');
        }

        $result = (new PublicStorageService)->getFilePath($this->relative($link).'/ok.txt');

        $this->assertFalse($result['success'], '링크를 경유한 접근은 대상이 루트 안이어도 거부된다');
        // 거부 코드는 **404** 다 — `..`·점 세그먼트·realpath 실패 세 분기가 이미 그 기준을
        // 세웠고(「403 이 『존재한다』를 뜻하면 파일 존재 여부가 샌다」), 링크 분기만 403 이면
        // 무인증 프로버가 응답 코드로 「그 경로에 링크가 있다」를 알아낸다 (7차 G3 라운드 C4).
        $this->assertSame('file_not_found', $result['error']);
    }

    /**
     * 루트 밖을 가리키는 링크는 당연히 거부된다 (realpath 봉쇄와 링크 검사 이중 방어).
     *
     * @effects public_storage_route_rejects_traversal_and_symlink
     */
    public function test_rejects_directory_link_pointing_outside_root(): void
    {
        $link = $this->root.'/escape';
        if (! $this->makeDirectoryLink($link, $this->outside)) {
            $this->markTestSkipped('이 환경에서는 디렉토리 링크(symlink·junction)를 만들 수 없다');
        }

        $result = (new PublicStorageService)->getFilePath($this->relative($link).'/secret.txt');

        $this->assertFalse($result['success']);
        // 링크 분기가 먼저 잡으므로 404 다 (위 케이스와 같은 사유). 루트 밖 판정 자체는
        // 아래 최종 봉쇄(`realpath` 결과가 루트 밖)가 403 으로 유지한다 — 그것은 파일의
        // 존재가 아니라 서빙 트리의 경계에 대한 판정이라 성격이 다르다.
        $this->assertSame('file_not_found', $result['error']);
    }

    /**
     * 서빙 루트 폴백 경고는 **프로세스당 1회**다.
     *
     * `rootPath()` 는 자산 요청마다 불린다(서빙 + FormRequest 규칙 생성). 접지 않으면
     * 무인증·무상한 표면의 요청 수만큼 같은 줄이 쌓인다.
     *
     * 이 게이트가 필요한 이유: 9차 라운드가 이 접기를 **지역 클로저 안의 `static $seen`**
     * 으로 넣었는데, 클로저가 호출마다 새로 만들어져 매번 초기화됐다 — 코드는 있는데
     * 접기가 성립하지 않는 상태였고 어떤 검사도 그것을 보지 않았다.
     *
     * @scenario public_disk_root=non_local, observability=warn_once
     *
     * @effects root_path_fallback_warns_once_per_process
     */
    public function test_root_path_fallback_warns_once_per_process(): void
    {
        PublicStorageService::resetRootPathWarnings();

        config([
            'filesystems.disks.public.driver' => 's3',
            'filesystems.disks.public.root' => 'media',
        ]);

        Log::spy();

        PublicStorageService::rootPath();
        PublicStorageService::rootPath();
        PublicStorageService::rootPath();

        Log::shouldHaveReceived('warning')->once();

        PublicStorageService::resetRootPathWarnings();
    }

    /**
     * 「이 디스크가 로컬인가」를 두 지점이 **같은 답**으로 판정한다.
     *
     * 서빙 루트(`PublicStorageService::rootPath()`)와 URL 전환 판정
     * (`ResolvesPublicUrl::pointsAtLocalPublicStorage()`)은 같은 축을 본다. 종전에는
     * 전자가 `!== null && !== 'local'`, 후자가 `!== 'local'` 이라 **드라이버 미설정을
     * 서로 반대로** 읽었다: 서빙 루트는 운영자 값을 신뢰하는데 URL 은 `/storage` 로
     * 나가는 반쪽 상태이고, 링크 없는 사이트의 공개 자산이 전량 404 가 되며 로그도
     * 남지 않는다. 두 독블록이 각각 그 상태를 막겠다고 적어 두고 만들고 있었다.
     *
     * 형태만 맞추면 다시 갈리므로 **같은 답을 내는지**를 계약으로 고정한다 (15차 G3).
     *
     * @effects public_disk_locality_predicates_agree
     */
    public function test_local_disk_predicates_agree_across_both_consumers(): void
    {
        // trait 의 판정은 private 이라 리플렉션으로 부른다 — 사본을 재구현하면 이
        // 검사가 재려는 「같은 답인가」가 성립하지 않는다(사본끼리만 일치하게 된다).
        $driver = new CoreStorageDriver('public');
        $predicate = new \ReflectionMethod($driver, 'pointsAtLocalPublicStorage');
        $predicate->setAccessible(true);

        // [드라이버 값, 로컬로 봐야 하는가]
        $cases = [
            ['local', true],
            [null, true],   // 미설정 = Laravel 기본값(로컬)
            ['s3', false],
        ];

        foreach ($cases as [$configured, $expectedLocal]) {
            PublicStorageService::resetRootPathWarnings();

            // 운영자가 지정한 루트는 **기본값과 달라야** 폴백 여부를 관측할 수 있다.
            // 같게 두면 신뢰한 경우와 폴백한 경우가 구분되지 않는다.
            $configuredRoot = storage_path('app/public-elsewhere');

            config([
                'filesystems.disks.public.driver' => $configured,
                'filesystems.disks.public.root' => $configuredRoot,
                'filesystems.disks.public.url' => null,
            ]);

            $servingRootTrusted = PublicStorageService::rootPath() === $configuredRoot;

            $this->assertSame(
                $expectedLocal,
                $servingRootTrusted,
                sprintf('서빙 루트 판정이 드라이버 %s 에서 어긋난다', var_export($configured, true))
            );

            $this->assertSame(
                $servingRootTrusted,
                $predicate->invoke($driver),
                sprintf(
                    '두 술어가 드라이버 %s 에서 다른 답을 낸다 — 서빙 루트와 URL 이 갈리는 반쪽 상태다',
                    var_export($configured, true)
                )
            );
        }

        PublicStorageService::resetRootPathWarnings();
    }

    /**
     * `storage:link` 가 만드는 링크의 대상은 공개 디스크 root 와 **같은 출처**여야 한다.
     *
     * 종전에는 `config/filesystems.php` 안에서 두 자리가 각각 리터럴이었다. 운영자가
     * `disks.public.root` 만 옮기면 링크는 옛 위치를 가리키고, 파일을 쓰는 쪽·서빙하는 쪽은
     * 새 루트를 본다 — 공개 자산이 오류도 로그도 없이 전부 404 다. 코어 복구기가 22·23차에
     * 걸쳐 닫은 결함의 **세 번째 출처**였다 (23차 G3 라운드 D23-3).
     *
     * 링크 키(`public/storage`)도 함께 잰다 — 그 자리가 바뀌면 판정기가 보는 경로와 갈린다.
     */
    public function test_storage_link_target_follows_the_configured_public_disk_root(): void
    {
        $links = config('filesystems.links');

        $this->assertIsArray($links);
        $this->assertNotEmpty($links, '링크 선언이 비면 이 검사는 아무것도 재지 않는다');

        $publicLink = public_path('storage');

        $this->assertArrayHasKey(
            $publicLink,
            $links,
            'public/storage 링크 선언이 없다 — 판정기(StorageLinkHelper)가 보는 경로와 갈렸다',
        );

        $this->assertSame(
            config('filesystems.disks.public.root'),
            $links[$publicLink],
            'storage:link 가 만드는 링크의 대상이 공개 디스크 root 와 다르다 — 링크가 빈 트리를 '
                .'가리키게 되고 그 사이트의 공개 자산이 전부 404 가 된다',
        );

        // 위 단언만으로는 부족하다 — 결함의 실체는 「값이 다르다」가 아니라 **두 자리가 각각
        // 리터럴이다** 였다. 기본 설정에서는 그 두 리터럴이 우연히 같은 값이라 값 비교는
        // 통과하고, 운영자가 root 를 옮긴 사이트에서만 갈린다. 그 상황은 이 테스트가 만들 수
        // 없다(`links` 는 설정 로드 시점에 평가되므로 런타임 오버라이드가 재평가되지 않는다).
        //
        // 그래서 **소스가 한 출처에서 파생하는지**를 함께 잰다. 22차의 D23-1 이 정확히 이
        // 형태로 거짓 green 이었다 — 픽스처가 그 상황을 한 번도 만들지 않았다
        // (24차 G3 라운드 R24-C M6: 리터럴로 되돌려도 이 클래스가 전부 초록이었다).
        $source = file_get_contents(config_path('filesystems.php'));

        $this->assertMatchesRegularExpression(
            '/^\s*public_path\(\x27storage\x27\)\s*=>\s*\$[A-Za-z_][A-Za-z0-9_]*\s*,/m',
            $source,
            'links 의 대상이 변수 파생이 아니라 리터럴이다 — 그러면 disks.public.root 를 옮긴 '
                .'사이트에서 두 자리가 갈리고, 그 발산은 오류도 로그도 없이 공개 자산 전량 404 로만 '
                .'나타난다',
        );

        $this->assertSame(
            1,
            preg_match_all('/storage_path\(\x27app\/public\x27\)/', $source),
            '공개 디스크 루트 리터럴이 파일 안에 두 번 이상 있다 — 출처가 하나여야 한다',
        );
    }
}
