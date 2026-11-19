<?php

namespace Tests\Unit\Thumbnails;

use App\Extension\HookManager;
use App\Extension\Storage\CoreStorageDriver;
use App\Thumbnails\ThumbnailStore;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * 원격(S3) 원본 디스크에서 저장소 계층 썸네일 연동이 더하는 원격 요청 수의 상한 (계획서 §12)
 *
 * 실제 버킷 없이 코어 s3 디스크(Flysystem AwsS3V3 + AWS SDK)에 SDK `handler` 옵션으로 가짜 응답기를 꽂고,
 * 명령 이름별 요청 수를 센다. 썸네일 디스크가 기본값(로컬 `thumbnails`)이면 파생본 확인·삭제는 원격 요청을 내지 않는다.
 */
class RemoteDiskThumbnailSyncRequestTest extends TestCase
{
    /** @var array<string, int> 가짜 버킷 키 → 크기 */
    private array $objects = [];

    /** @var array<string, int> 명령 이름 → 요청 수 */
    private array $requests = [];

    /**
     * 가짜 S3 디스크와 로컬 썸네일 디스크를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('filesystems.disks.s3', [
            'driver' => 's3',
            'key' => 'test',
            'secret' => 'test',
            'region' => 'ap-northeast-2',
            'bucket' => 'test-bucket',
            'url' => null,
            'endpoint' => null,
            'use_path_style_endpoint' => false,
            'throw' => true,
            'report' => false,
            'retries' => 0,
            'handler' => fn (CommandInterface $command, RequestInterface $request) => $this->respond($command),
        ]);
        Storage::forgetDisk('s3');
        Storage::fake('thumbnails');

        Config::set('core.thumbnail.enabled', true);
        Config::set('core.thumbnail.presets', null);
        Config::set('core.thumbnail.disk_public', 'thumbnails');
        Config::set('core.thumbnail.disk_gated', 'thumbnails');

        HookManager::clearAction('core.thumbnail.after_purge');
    }

    /**
     * 가짜 디스크를 버립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Storage::forgetDisk('s3');
        HookManager::clearAction('core.thumbnail.after_purge');

        parent::tearDown();
    }

    /**
     * 가짜 S3 응답 — 명령을 세고 메모리 버킷으로 답합니다.
     *
     * @param  CommandInterface  $command  SDK 명령
     * @return PromiseInterface 결과 또는 404 거절
     */
    private function respond(CommandInterface $command): PromiseInterface
    {
        $name = $command->getName();
        $this->requests[$name] = ($this->requests[$name] ?? 0) + 1;

        switch ($name) {
            case 'HeadObject':
                return isset($this->objects[$command['Key']])
                    ? Create::promiseFor(new Result(['ContentLength' => $this->objects[$command['Key']]]))
                    : Create::rejectionFor(new S3Exception('Not Found', $command, ['response' => new Response(404), 'code' => 'NotFound']));
            case 'PutObject':
                $this->objects[$command['Key']] = 1;

                return Create::promiseFor(new Result([]));
            case 'DeleteObject':
                unset($this->objects[$command['Key']]);

                return Create::promiseFor(new Result([]));
            case 'ListObjectsV2':
                // 구분자가 있으면 바로 아래 「디렉터리」를 CommonPrefixes 로 묶는다(얕은 목록·디렉터리 확인). MaxKeys 까지만 낸다
                $prefix = (string) ($command['Prefix'] ?? '');
                $delimiter = $command['Delimiter'] ?? null;
                $max = (int) ($command['MaxKeys'] ?? 1000);
                $keys = array_values(array_filter(array_keys($this->objects), fn (string $key) => str_starts_with($key, $prefix)));
                sort($keys);
                $contents = [];
                $prefixes = [];

                foreach ($keys as $key) {
                    $rest = substr($key, strlen($prefix));
                    $position = is_string($delimiter) ? strpos($rest, $delimiter) : false;

                    if ($position !== false) {
                        $prefixes[$prefix.substr($rest, 0, $position + 1)] = true;
                    } else {
                        $contents[] = ['Key' => $key, 'Size' => 1];
                    }
                }

                $contents = array_slice($contents, 0, $max);
                $prefixes = array_slice(array_keys($prefixes), 0, max(0, $max - count($contents)));
                $result = ['KeyCount' => count($contents) + count($prefixes), 'IsTruncated' => false];

                if ($contents !== []) {
                    $result['Contents'] = $contents;
                }

                if ($prefixes !== []) {
                    $result['CommonPrefixes'] = array_map(fn (string $p) => ['Prefix' => $p], $prefixes);
                }

                return Create::promiseFor(new Result($result));
            case 'ListObjects':
                $prefix = (string) $command['Prefix'];
                $hits = array_values(array_filter(array_keys($this->objects), fn (string $key) => str_starts_with($key, $prefix)));

                return Create::promiseFor(new Result($hits === [] ? [] : ['Contents' => array_map(fn (string $key) => ['Key' => $key], $hits)]));
            case 'DeleteObjects':
                foreach ($command['Delete']['Objects'] ?? [] as $object) {
                    unset($this->objects[$object['Key']]);
                }

                return Create::promiseFor(new Result([]));
        }

        return Create::promiseFor(new Result([]));
    }

    /**
     * 동작 하나가 낸 원격 요청을 셉니다.
     *
     * @param  callable  $operation  동작
     * @return array<string, int> 명령 이름 → 요청 수
     */
    private function remoteRequests(callable $operation): array
    {
        $this->requests = [];
        $operation();
        ksort($this->requests);

        return $this->requests;
    }

    /**
     * 원본 디스크에 공개 주소가 없고 썸네일 디스크가 로컬이면 put 은 원격 요청을 하나도 더하지 않는다.
     *
     * 덮어쓰기 판정(HeadObject)이 쓰이는 곳은 두 가지다. 원본 공개 주소의 CDN 무효화, 그리고 원격 썸네일 디스크에서
     * 새 파일의 재확인을 건너뛰는 것. 둘 다 해당하지 않으면 판정 없이 쓴 뒤 로컬 썸네일 디스크만 재확인한다(로컬 조회).
     *
     * @effects remote_put_overhead_bounded
     *
     * @return void 반환값 없음
     */
    public function test_put_without_public_original_url_adds_no_remote_requests(): void
    {
        $driver = new CoreStorageDriver('s3');
        $driver->put('images', '2026/11/a.jpg', 'v1');
        $stale = 'thumbnails/s3/images/2026/11/a.jpg/sm_0123abcd.jpg';
        Storage::disk('thumbnails')->put($stale, 'old');

        $raw = $this->remoteRequests(fn () => Storage::disk('s3')->put('images/2026/11/b.jpg', 'v1'));
        $overwrite = $this->remoteRequests(fn () => $driver->put('images', '2026/11/a.jpg', 'v2'));
        $new = $this->remoteRequests(fn () => $driver->put('images', '2026/11/new.jpg', 'v1'));

        $this->assertSame(['PutObject' => 1], $raw);
        $this->assertSame(['PutObject' => 1], $overwrite);
        $this->assertSame(['PutObject' => 1], $new);
        Storage::disk('thumbnails')->assertMissing($stale);
    }

    /**
     * 원본 디스크에 공개 주소(CDN)가 있으면 덮어쓰기 판정 HeadObject 1회를 더한다. 판정은 파일만 확인한다(`fileExists`).
     * Laravel `exists()` 는 Flysystem `has()`(파일이 없으면 디렉터리 확인까지)라 HeadObject 404 뒤에
     * ListObjectsV2 가 한 번 더 나가, 가장 흔한 업로드 경로의 지연이 요청 1회분 늘었다(실측 100 ms 조건 +215 ms).
     *
     * @effects remote_put_overhead_bounded
     *
     * @return void 반환값 없음
     */
    public function test_public_original_url_put_adds_one_head_request(): void
    {
        $this->useS3Url('https://cdn.example.test');
        $driver = new CoreStorageDriver('s3');

        $new = $this->remoteRequests(fn () => $driver->put('images', '2026/11/new.jpg', 'v1'));
        $overwrite = $this->remoteRequests(fn () => $driver->put('images', '2026/11/new.jpg', 'v2'));

        $this->assertSame(['HeadObject' => 1, 'PutObject' => 1], $new);
        $this->assertSame(['HeadObject' => 1, 'PutObject' => 1], $overwrite);
    }

    /**
     * 썸네일 디스크가 원격(s3)이면 put 하나에 파생본 디렉터리 조회 1회만 더한다. 원본 공개 주소가 없으면 덮어쓰기
     * 판정 없이 쓴 뒤 재확인 하나로 새 파일과 덮어쓰기를 함께 다룬다(종전 덮어쓰기는 HeadObject 1회에 쓰기 전 비우기와
     * 쓴 뒤 재확인의 조회 2회). 파생본이 있으면 그 삭제(ListObjects + DeleteObjects)는 실제 일이라 더해진다.
     *
     * @effects remote_put_overhead_bounded
     *
     * @return void 반환값 없음
     */
    public function test_remote_thumbnail_disk_put_adds_one_listing(): void
    {
        $this->useRemoteThumbnailDisk();
        $driver = new CoreStorageDriver('s3');

        $this->assertSame(
            ['ListObjectsV2' => 1, 'PutObject' => 1],
            $this->remoteRequests(fn () => $driver->put('images', '2026/11/fresh.jpg', 'v1'))
        );
        $this->assertSame(
            ['ListObjectsV2' => 1, 'PutObject' => 1],
            $this->remoteRequests(fn () => $driver->put('images', '2026/11/fresh.jpg', 'v2'))
        );

        $derivative = 'thumbnails/s3/images/2026/11/fresh.jpg/sm_0123abcd.jpg';
        $this->objects[$derivative] = 1;

        $this->assertSame(
            ['DeleteObjects' => 1, 'ListObjects' => 1, 'ListObjectsV2' => 1, 'PutObject' => 1],
            $this->remoteRequests(fn () => $driver->put('images', '2026/11/fresh.jpg', 'v3'))
        );
        $this->assertArrayNotHasKey($derivative, $this->objects, '덮어쓴 원본의 옛 파생본은 지워진다');
    }

    /**
     * 원본 공개 주소가 있고 썸네일 디스크가 원격이면 새 파일은 HeadObject 1회, 덮어쓰기는 HeadObject 1회와 파생본
     * 디렉터리 조회 1회다. 종전의 쓰기 전 비우기 조회와 쓴 뒤 재확인 조회 2회를 쓴 뒤 한 번으로 합쳤다.
     *
     * @effects remote_put_overhead_bounded
     *
     * @return void 반환값 없음
     */
    public function test_public_original_url_with_remote_thumbnail_disk_checks_once_per_overwrite(): void
    {
        $this->useS3Url('https://cdn.example.test');
        $this->useRemoteThumbnailDisk();
        $driver = new CoreStorageDriver('s3');

        $this->assertSame(
            ['HeadObject' => 1, 'PutObject' => 1],
            $this->remoteRequests(fn () => $driver->put('images', '2026/11/fresh.jpg', 'v1'))
        );
        $this->assertSame(
            ['HeadObject' => 1, 'ListObjectsV2' => 1, 'PutObject' => 1],
            $this->remoteRequests(fn () => $driver->put('images', '2026/11/fresh.jpg', 'v2'))
        );
    }

    /**
     * 썸네일을 끄면 put 의 파생본 연동을 건너뛴다(원본 공개 주소가 없으면 원격 요청 0). 삭제 연동은 남는다. 지운 원본의
     * 파생본이 공개 디스크에서 계속 열리면 안 되기 때문이다. 끈 동안 덮어쓴 원본의 옛 파생본은 다시 켤 때 비운다(설정 저장 시험).
     *
     * @effects remote_put_overhead_bounded, disabled_thumbnails_skip_put_sync
     *
     * @return void 반환값 없음
     */
    public function test_disabled_thumbnails_skip_put_sync_but_keep_delete_sync(): void
    {
        $this->useRemoteThumbnailDisk();
        Config::set('core.thumbnail.enabled', false);
        $driver = new CoreStorageDriver('s3');

        $this->assertSame(['PutObject' => 1], $this->remoteRequests(fn () => $driver->put('images', '2026/11/off.jpg', 'v1')));
        $derivative = 'thumbnails/s3/images/2026/11/off.jpg/sm_0123abcd.jpg';
        $this->objects[$derivative] = 1;
        $this->assertSame(['PutObject' => 1], $this->remoteRequests(fn () => $driver->put('images', '2026/11/off.jpg', 'v2')));
        $this->assertArrayHasKey($derivative, $this->objects, '끈 동안의 덮어쓰기는 파생본을 건드리지 않는다');

        $driver->delete('images', '2026/11/off.jpg');
        $this->assertArrayNotHasKey($derivative, $this->objects, '삭제 연동은 꺼도 돈다');
    }

    /**
     * 썸네일을 꺼도 원본에 공개 주소(CDN)가 있으면 덮어쓴 원본 주소의 CDN 무효화는 남긴다 — 원본 캐시는 썸네일 기능과
     * 별개다. 판정 HeadObject 1회만 내고 원격 썸네일 디스크의 파생본 조회는 하지 않는다. 새 파일은 무효화하지 않는다.
     *
     * @effects disabled_thumbnails_skip_put_sync, purge_hook_fired_with_payload
     *
     * @return void 반환값 없음
     */
    public function test_disabled_thumbnails_still_invalidate_overwritten_public_original(): void
    {
        $this->useS3Url('https://cdn.example.test');
        $this->useRemoteThumbnailDisk();
        Config::set('core.thumbnail.enabled', false);
        $purges = [];
        HookManager::addAction('core.thumbnail.after_purge', function (array $items) use (&$purges) {
            $purges[] = $items;
        });
        $driver = new CoreStorageDriver('s3');

        $this->assertSame(['HeadObject' => 1, 'PutObject' => 1], $this->remoteRequests(fn () => $driver->put('images', '2026/11/cdn.jpg', 'v1')));
        $this->assertSame([], $purges, '새 파일은 CDN 무효화를 보내지 않는다');

        $derivative = 'thumbnails/s3/images/2026/11/cdn.jpg/sm_0123abcd.jpg';
        $this->objects[$derivative] = 1;
        $this->assertSame(['HeadObject' => 1, 'PutObject' => 1], $this->remoteRequests(fn () => $driver->put('images', '2026/11/cdn.jpg', 'v2')));
        $this->assertArrayHasKey($derivative, $this->objects);

        $this->assertCount(1, $purges);
        $this->assertSame('', $purges[0][0]['thumbnail_disk']);
        $this->assertNull($purges[0][0]['public_url_prefix']);
        $this->assertSame('https://cdn.example.test/images/2026/11/cdn.jpg', $purges[0][0]['original_public_url']);
    }

    /**
     * 썸네일 디스크가 로컬이면 delete 는 원격 요청을 더하지 않고, 래스터가 아닌 파일은 put 도 더하지 않는다.
     *
     * @effects remote_put_overhead_bounded, non_raster_never_purges
     *
     * @return void 반환값 없음
     */
    public function test_delete_and_non_raster_add_no_remote_requests(): void
    {
        $this->useS3Url('https://cdn.example.test');
        $driver = new CoreStorageDriver('s3');
        $driver->put('images', '2026/11/a.jpg', 'v1');
        $driver->put('docs', 'a.pdf', 'v1');

        $this->assertSame(['DeleteObject' => 1], $this->remoteRequests(fn () => $driver->delete('images', '2026/11/a.jpg')));
    }

    /**
     * 래스터가 아닌 원본(PDF)은 썸네일 연동을 하지 않지만, 원본에 공개 주소(CDN)가 있으면 덮어쓰기·삭제 때 그 주소를
     * CDN 무효화에 싣는다 — 원본 캐시 무효화는 확장자와 무관하다. 공개 주소가 없으면 put 부가 원격 요청은 0 이고,
     * 썸네일 디스크가 원격이어도 PDF 는 파생본 디렉터리를 조회하지 않는다. 새 파일은 무효화하지 않는다.
     *
     * @effects remote_put_overhead_bounded, non_raster_never_purges, non_raster_public_original_invalidated
     *
     * @return void 반환값 없음
     */
    public function test_non_raster_overwrite_invalidates_public_original_only(): void
    {
        $this->useRemoteThumbnailDisk();
        $purges = [];
        HookManager::addAction('core.thumbnail.after_purge', function (array $items) use (&$purges) {
            $purges[] = $items;
        });
        $driver = new CoreStorageDriver('s3');

        $driver->put('docs', 'plain.pdf', 'v1');
        $this->assertSame(['PutObject' => 1], $this->remoteRequests(fn () => $driver->put('docs', 'plain.pdf', 'v2')));
        $this->assertSame([], $purges, '공개 주소가 없으면 무효화할 것이 없다');

        $this->useS3Url('https://cdn.example.test');
        $driver = new CoreStorageDriver('s3');

        $this->assertSame(['HeadObject' => 1, 'PutObject' => 1], $this->remoteRequests(fn () => $driver->put('docs', 'a.pdf', 'v1')));
        $this->assertSame([], $purges, '새 파일은 무효화하지 않는다');

        $this->assertSame(['HeadObject' => 1, 'PutObject' => 1], $this->remoteRequests(fn () => $driver->put('docs', 'a.pdf', 'v2')));
        $this->assertSame(['DeleteObject' => 1], $this->remoteRequests(fn () => $driver->delete('docs', 'a.pdf')));

        $this->assertCount(2, $purges, '덮어쓰기와 삭제가 한 번씩');
        foreach ($purges as $batch) {
            $this->assertSame('', $batch[0]['thumbnail_disk']);
            $this->assertNull($batch[0]['public_url_prefix']);
            $this->assertSame('https://cdn.example.test/docs/a.pdf', $batch[0]['original_public_url']);
        }
    }

    /**
     * 원격 썸네일 디스크를 다른 위치와 나눠 쓰지 않아도 전 규격 비우기는 `deleteDirectory(thumbnails)` 한 번으로 지우지
     * 않는다 — S3 는 그 한 번이 파일 1000개마다 목록·일괄 삭제 요청을 내 요청 하나가 파일 수만큼 길어진다. 시간 예산만큼
     * 걸어 지우고 커서로 이어 간다(예산 0 이면 걸음마다 파일 하나). 고정 루트 밖 원본은 남고, CDN 무효화는 비우기가
     * 끝나는 걸음에 루트 와일드카드 하나로 싣는다(걸음마다 싣지 않아 무효화 경로가 걸음 수만큼 늘지 않는다).
     * 로컬 디스크는 원격 왕복이 없는 디렉터리 삭제라 종전처럼 한 번에 지운다(ThumbnailControllerTest 의 -1 단언).
     *
     * @effects purge_walks_in_bounded_steps
     *
     * @return void 반환값 없음
     */
    public function test_remote_thumbnail_disk_whole_purge_walks_in_bounded_steps(): void
    {
        Config::set('core.thumbnail.disk_gated', 's3');
        $derivatives = [];

        foreach (['a', 'b'] as $name) {
            foreach (['sm', 'md'] as $preset) {
                $derivatives[] = $key = "thumbnails/attachments/2026/11/{$name}.jpg/{$preset}_0123abcd.jpg";
                $this->objects[$key] = 1;
            }
        }

        $this->objects['images/keep.jpg'] = 1;
        $purges = [];
        HookManager::addAction('core.thumbnail.after_purge', function (array $items) use (&$purges) {
            $purges[] = $items;
        });

        $store = app(ThumbnailStore::class);
        $cursor = null;
        $steps = 0;
        $deleted = 0;
        $this->requests = [];

        do {
            $step = $store->purgeLocationStep(ThumbnailStore::KIND_GATED, null, $cursor, 0.0);
            $deleted += $step['deleted'];
            $cursor = $step['next_cursor'];
            $steps++;
        } while ($cursor !== null && $steps < 20);

        $this->assertSame(4, $deleted, '파일 수를 센다(-1 아님)');
        $this->assertGreaterThanOrEqual(4, $steps, '예산 0 이면 걸음마다 파일 하나');
        $this->assertArrayNotHasKey('ListObjects', $this->requests, '디렉터리째 일괄 삭제를 쓰지 않는다');
        $this->assertSame(['images/keep.jpg'], array_keys($this->objects));

        $this->assertCount(1, $purges, '무효화는 끝나는 걸음에 한 번');
        $this->assertSame(['source_disk' => '*', 'prefix' => '', 'thumbnail_disk' => 's3'], array_intersect_key($purges[0][0], array_flip(['source_disk', 'prefix', 'thumbnail_disk'])));
    }

    /**
     * 가짜 s3 디스크에 공개 주소를 줍니다 (원본 CDN 무효화 대상이 되는 구성).
     *
     * @param  string  $url  공개 주소
     * @return void 반환값 없음
     */
    private function useS3Url(string $url): void
    {
        Config::set('filesystems.disks.s3.url', $url);
        Storage::forgetDisk('s3');
    }

    /**
     * 두 썸네일 저장 위치를 원격 s3 로 둡니다.
     *
     * 공개 위치는 공개 자산 디스크 카탈로그 안에서만 고르므로 s3 가 카탈로그에 없으면 비공개 디스크로 물러선다.
     * 권한 위치는 s3 를 허용한다. 두 위치 중 하나만 원격이어도 조회 수 단언은 같다.
     *
     * @return void 반환값 없음
     */
    private function useRemoteThumbnailDisk(): void
    {
        Config::set('core.thumbnail.disk_public', 's3');
        Config::set('core.thumbnail.disk_gated', 's3');
    }
}
