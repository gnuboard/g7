<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\Storage\ModuleStorageDriver;
use App\Http\Middleware\GzipEncodeResponse;
use App\Http\Responses\NotModifiedResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Models\CategoryImage;
use Modules\Sirsoft\Ecommerce\Services\CategoryImageService;
use Modules\Sirsoft\Ecommerce\Support\AssetStorage;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CategoryImageService 행 disk 기준 서빙/삭제 테스트
 *
 * 공개 자산 디스크 전환 이후에도 전환 이전 행(구 disk)의 서빙이 404 가 되지 않고,
 * 삭제가 그 행의 실제 저장 위치를 향하는지(혼재 운용 정합) 검증합니다.
 *
 * CategoryImageServiceTest 는 StorageInterface 를 Mock 으로 대체하므로
 * 실제 디스크 해석(withDisk) 회귀를 검출할 수 없어 별도 파일로 둡니다.
 */
class CategoryImageServiceRowDiskTest extends ModuleTestCase
{
    private CategoryImageService $service;

    /**
     * 직접 URL 을 가진 가짜 CDN 디스크를 설정에 등록하고 자산 스토리지 캐시를 비운 뒤 실제 카테고리 이미지 서비스를 꺼낸다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('filesystems.disks.fake_cdn', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/fake_cdn'),
            'url' => 'https://cdn.test/assets',
        ]);
        AssetStorage::flush();

        $this->service = app(CategoryImageService::class);
    }

    /**
     * 자산 스토리지 캐시를 비워 가짜 디스크 해석이 다른 테스트에 남지 않게 한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        AssetStorage::flush();

        parent::tearDown();
    }

    /**
     * 지정 disk 에 파일과 카테고리 이미지 행을 함께 생성합니다.
     *
     * @param  string  $disk  행 disk
     * @param  string  $path  이미지 경로 (images/ 하위 상대 경로)
     * @return CategoryImage 생성된 행
     */
    private function makeRowOnDisk(string $disk, string $path): CategoryImage
    {
        (new ModuleStorageDriver('sirsoft-ecommerce', $disk))->put('images', $path, 'image-bytes');

        return CategoryImage::create([
            'temp_key' => 'category-row-disk-test',
            'original_filename' => 'category.jpg',
            'stored_filename' => basename($path),
            'disk' => $disk,
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'file_size' => 11,
            'collection' => 'main',
            'sort_order' => 0,
        ]);
    }

    /**
     * 주입 스토리지와 다른 디스크에 기록된 행도 그 행의 디스크에서 스트리밍 서빙되는지 확인한다(혼재 운용).
     *
     * @effects serve_and_delete_follow_row_disk, mixed_rows_resolve_per_row_disk
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function download_serves_row_from_its_recorded_disk(): void
    {
        // 혼재 운용 — 주입 스토리지 disk 와 다른 disk 의 행도 서빙 가능해야 한다
        $cdnRow = $this->makeRowOnDisk('fake_cdn', 'category/row-disk-test/cdn.jpg');
        $localRow = $this->makeRowOnDisk('modules', 'category/row-disk-test/local.jpg');

        $this->assertInstanceOf(StreamedResponse::class, $this->service->download($cdnRow->hash));
        $this->assertInstanceOf(StreamedResponse::class, $this->service->download($localRow->hash));
    }

    /**
     * 삭제가 행에 기록된 디스크의 파일을 지우는지 확인한다.
     *
     * @effects serve_and_delete_follow_row_disk
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function delete_removes_file_on_row_disk(): void
    {
        $cdnRow = $this->makeRowOnDisk('fake_cdn', 'category/row-disk-test/del.jpg');
        $cdnDriver = new ModuleStorageDriver('sirsoft-ecommerce', 'fake_cdn');
        $this->assertTrue($cdnDriver->exists('images', $cdnRow->path));

        $this->assertTrue($this->service->delete($cdnRow->id));

        $this->assertFalse($cdnDriver->exists('images', $cdnRow->path));
    }

    /**
     * 행 disk 를 제공하던 플러그인이 비활성화되어 config 에서 사라진 상태를 만듭니다.
     *
     * @param  string  $disk  사라지게 할 디스크명
     * @return void 반환값 없음
     */
    private function vanishDisk(string $disk): void
    {
        Config::set("filesystems.disks.{$disk}", null);
        Storage::forgetDisk($disk);
        AssetStorage::flush();
    }

    /**
     * 고아 disk 행의 서빙은 500 이 아니라 스트리밍 폴백이어야 한다.
     *
     * 디스크를 제공하던 플러그인을 비활성화하면 행 disk 가 config 에서 사라진다.
     * 이때 withDisk 로 미등록 디스크를 만들면 Laravel 이 InvalidArgumentException 을
     * 던져 서빙이 500 이 된다 — 접근자는 정상적으로 API 경로를 내주므로 사용자에게는
     * 이미지 링크가 살아 있는데 열면 500 인 상태가 된다.
     *
     * @effects orphan_disk_falls_back_to_streaming
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function download_falls_back_when_row_disk_is_orphaned(): void
    {
        $row = $this->makeRowOnDisk('fake_cdn', 'category/row-disk-test/orphan.jpg');
        $this->vanishDisk('fake_cdn');

        // 예외 없이 폴백 — 파일은 도달 불가이므로 null(404) 이 정상 degradation
        $this->assertNull($this->service->download($row->hash));
    }

    /**
     * 고아 disk 행의 삭제도 예외 없이 완료되어야 한다(카테고리 삭제 자체가 막히면 안 됨).
     *
     * @effects orphan_disk_falls_back_to_streaming
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function delete_succeeds_when_row_disk_is_orphaned(): void
    {
        $row = $this->makeRowOnDisk('fake_cdn', 'category/row-disk-test/orphan-del.jpg');
        $this->vanishDisk('fake_cdn');

        $this->assertTrue($this->service->delete($row->id));
        $this->assertNull(CategoryImage::find($row->id));
    }

    /**
     * put 종단 — SP 카테고리 배선을 실제로 경유해 공개 자산 디스크 설정값이
     * 신규 행의 disk 컬럼에 기록되는지 단언합니다(배선 회귀 검출).
     *
     * @effects mixed_rows_resolve_per_row_disk
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function upload_records_configured_public_asset_disk_on_row(): void
    {
        Config::set('core.storage.public_asset_disk', 'fake_cdn');

        $service = app(CategoryImageService::class);
        $image = $service->upload(UploadedFile::fake()->image('cdn-category.jpg', 40, 40));

        $this->assertSame('fake_cdn', $image->disk);
        $this->assertTrue(
            (new ModuleStorageDriver('sirsoft-ecommerce', 'fake_cdn'))->exists('images', $image->path)
        );

        $this->assertTrue($service->delete($image->id));
    }

    /**
     * put 종단 — 공개 자산 디스크 미설정이면 신규 행이 기본 디스크로 기록(현행 보존).
     *
     * @effects mixed_rows_resolve_per_row_disk
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function upload_records_base_disk_when_public_asset_disk_unset(): void
    {
        Config::set('core.storage.public_asset_disk', '');

        $service = app(CategoryImageService::class);
        $image = $service->upload(UploadedFile::fake()->image('local-category.jpg', 40, 40));

        $this->assertSame('modules', $image->disk);

        $this->assertTrue($service->delete($image->id));
    }

    /**
     * 공개 서빙 URL — 캐시 계약은 컨트롤러가 코어 단일 지점에 넘겨 입히므로 HTTP 로 잰다.
     *
     * @param  CategoryImage  $row  이미지 행
     * @return string 서빙 URL
     */
    private function serveUrl(CategoryImage $row): string
    {
        return '/api/modules/sirsoft-ecommerce/category-image/'.$row->hash;
    }

    /**
     * 304 가 본문 없는 전용 응답이고 200 의 캐시 축을 그대로 싣는지 단언합니다.
     *
     * 종전에는 서비스 trait 이 자체 304 객체(`StreamedResponse` 서브클래스)를 만들었고,
     * 그 테스트의 「본문 없음」 단언은 `sendContent()` 를 버퍼에 흘려 재는 형태였다 — Symfony
     * `setNotModified()` 가 `setContent(null)` 로 `streamed = true` 를 세우므로 **어떤 구현으로도**
     * red 가 되지 않는 공허한 단언이었다. 여기서는 응답 클래스(코어 `NotModifiedResponse`)와
     * 200 과의 헤더 대칭(`Last-Modified`·`Cache-Control`·`Expires`)을 잰다 — 옛 형태로 되돌리면
     * 클래스 단언에서 red 가 된다.
     *
     * @param  TestResponse  $full  같은 자원의 200 응답
     * @param  TestResponse  $notModified  조건부 요청으로 받은 304 응답
     * @return void 반환값 없음
     */
    private function assertNotModifiedMirrors200(TestResponse $full, TestResponse $notModified): void
    {
        $notModified->assertStatus(304);
        $this->assertInstanceOf(
            NotModifiedResponse::class,
            $notModified->baseResponse,
            '304 는 코어 단일 지점이 만드는 전용 응답이어야 한다 — 서비스 사본은 환경별 정책·-gzip 흡수·Expires 에서 갈린다'
        );
        $this->assertSame('', (string) $notModified->baseResponse->getContent(), '304 에 본문이 실렸다');

        foreach (['ETag', 'Last-Modified', 'Cache-Control', 'Expires'] as $header) {
            $this->assertNotNull($full->headers->get($header), "200 이 {$header} 를 싣지 않는다");
            $this->assertSame(
                $full->headers->get($header),
                $notModified->headers->get($header),
                "304 가 200 과 다른 {$header} 를 광고한다 (RFC 9110 §15.4.5)"
            );
        }

        // 이미지는 압축 대상이 아니라 200 에 Vary 가 없다 — 304 에만 붙이면 반대 방향 비대칭이다.
        $this->assertSame($full->headers->get('Vary'), $notModified->headers->get('Vary'));
    }

    /**
     * 공개 이미지 서빙은 행 메타 기준 검증자를 싣고, 그것을 **실제로 처리**한다.
     *
     * 종전에는 `Cache-Control: public, max-age=31536000` 만 실었다 — 검증자가 없으니
     * 만료 전에는 재검증 자체가 불가능하고(파일을 갈아 끼워도 1년간 옛것을 본다) 만료
     * 뒤에는 내용이 그대로여도 매번 전문이 나갔다. 오류도 로그도 없이 대역폭과 신선도
     * 양쪽에서만 손해가 나므로 응답 헤더를 직접 재는 것 말고는 드러날 통로가 없다.
     * 이제 그 계약은 코어 단일 지점이 만든다.
     *
     * @effects serve_and_delete_follow_row_disk
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function download_emits_row_meta_validators_and_answers_revalidation(): void
    {
        $row = $this->makeRowOnDisk('modules', 'category/row-disk-test/revalidate.jpg');

        $first = $this->get($this->serveUrl($row));

        $first->assertOk();
        $this->assertStringStartsWith('image/jpeg', (string) $first->headers->get('Content-Type'));

        $etag = (string) $first->headers->get('ETag');

        $this->assertMatchesRegularExpression(
            '/^"[0-9a-f]{32}"$/',
            $etag,
            'ETag 가 따옴표로 감싼 행 메타 해시여야 한다 (RFC 9110 §8.8.3)',
        );

        $this->assertSame(
            gmdate('D, d M Y H:i:s', $row->updated_at->getTimestamp()).' GMT',
            $first->headers->get('Last-Modified'),
        );

        // 코어 지점은 200 에 `Expires` 를 싣는다 — 서비스 사본에는 없던 축이다.
        $this->assertNotNull($first->headers->get('Expires'), '200 이 Expires 를 싣지 않는다 — 코어 지점을 지나지 않았다');

        // 클라이언트가 그 검증자를 돌려주면 304 로 끝나야 한다.
        $second = $this->withHeaders(['If-None-Match' => $etag])->get($this->serveUrl($row));

        $this->assertNotModifiedMirrors200($first, $second);
        $this->assertSame($etag, $second->headers->get('ETag'));
    }

    /**
     * `Cache-Control` 은 **환경**이 정한다 — 코어 지점은 운영 환경이 아니면 `no-cache` 다.
     *
     * 종전 서비스 사본은 `public, max-age=31536000, must-revalidate` 를 환경과 무관하게 고정했다.
     * 그러면 개발·스테이징에서 이 통로만 1년 캐시가 걸려, 같은 사이트의 다른 파일 서빙과 다르게
     * 동작한다. 같은 요청을 운영 환경으로 돌리면 정책이 바뀌는 것으로 코어 지점 경유를 잰다.
     *
     * @effects serve_and_delete_follow_row_disk
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function cache_control_follows_the_environment_like_the_core_serving_helper(): void
    {
        $row = $this->makeRowOnDisk('modules', 'category/row-disk-test/env.jpg');

        // 테스트 환경(`testing`)은 운영이 아니다.
        $nonProduction = $this->get($this->serveUrl($row));
        $nonProduction->assertOk();
        // Symfony 가 `no-cache` 에 `private` 를 덧붙여 `no-cache, private` 로 내보낸다 —
        // 값이 아니라 지시어 포함 여부로 잰다(`max-age` 가 없어야 사본이 아니다).
        $nonProductionCacheControl = (string) $nonProduction->headers->get('Cache-Control');
        $this->assertStringContainsString(
            'no-cache',
            $nonProductionCacheControl,
            '비운영 환경의 Cache-Control 은 코어 지점과 같이 no-cache 여야 한다 — 환경 무관 고정값은 서비스 사본의 흔적이다'
        );
        $this->assertStringNotContainsString('max-age', $nonProductionCacheControl);

        $this->app->detectEnvironment(static fn (): string => 'production');

        try {
            $production = $this->get($this->serveUrl($row));
            $production->assertOk();

            $cacheControl = (string) $production->headers->get('Cache-Control');
            $this->assertStringContainsString('max-age='.CategoryImageService::IMAGE_CACHE_MAX_AGE, $cacheControl);
            $this->assertStringContainsString('must-revalidate', $cacheControl);
            // 자산 버전(`?v=`)이 없는 URL 이므로 `immutable` 을 붙이지 않는다.
            $this->assertStringNotContainsString('immutable', $cacheControl);

            // 304 도 같은 정책을 광고한다.
            $notModified = $this->withHeaders(['If-None-Match' => (string) $production->headers->get('ETag')])
                ->get($this->serveUrl($row));
            $this->assertNotModifiedMirrors200($production, $notModified);
        } finally {
            $this->app->detectEnvironment(static fn (): string => 'testing');
        }
    }

    /**
     * `-gzip` 접미사가 붙은 `If-None-Match` 도 같은 파일로 인정한다 — 코어 비교 지점의 규약.
     *
     * 종전 서비스 사본은 Symfony `isNotModified()` 에 판정을 맡겼는데, 그것은 정확 일치 비교라
     * 앞단 CDN·정적 서빙이 압축본에 붙여 준 `"<etag>-gzip"` 을 클라이언트가 되보내면 영원히
     * 전문 200 이었다. 304 는 클라이언트가 보유한 표현의 검증자를 그대로 돌려준다.
     *
     * @effects serve_and_delete_follow_row_disk
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function gzip_suffixed_validator_is_recognised_and_echoed_by_the_core_judgment(): void
    {
        $row = $this->makeRowOnDisk('modules', 'category/row-disk-test/gzip.jpg');

        $first = $this->get($this->serveUrl($row));
        $first->assertOk();

        $gzip = preg_replace('/"$/', '-gzip"', (string) $first->headers->get('ETag'));

        $second = $this->withHeaders(['If-None-Match' => $gzip])->get($this->serveUrl($row));

        $this->assertNotModifiedMirrors200Except($first, $second, ['ETag']);
        $this->assertSame($gzip, $second->headers->get('ETag'), '304 는 클라이언트가 보유한 표현의 검증자를 돌려준다');
    }

    /**
     * `assertNotModifiedMirrors200()` 에서 일부 헤더의 동일성 단언만 제외합니다.
     *
     * @param  TestResponse  $full  같은 자원의 200 응답
     * @param  TestResponse  $notModified  조건부 요청으로 받은 304 응답
     * @param  array<int, string>  $except  제외할 헤더
     * @return void 반환값 없음
     */
    private function assertNotModifiedMirrors200Except(TestResponse $full, TestResponse $notModified, array $except): void
    {
        $notModified->assertStatus(304);
        $this->assertInstanceOf(NotModifiedResponse::class, $notModified->baseResponse);
        $this->assertSame('', (string) $notModified->baseResponse->getContent());

        foreach (array_diff(['ETag', 'Last-Modified', 'Cache-Control', 'Expires'], $except) as $header) {
            $this->assertSame($full->headers->get($header), $notModified->headers->get($header), "304 가 200 과 다른 {$header} 를 광고한다");
        }
    }

    /**
     * `If-Modified-Since` 만 있어도 행이 그보다 새롭지 않으면 304 다 — 시각으로만 재검증하는
     * 프록시가 쓰는 경로. 판정이 `If-None-Match` 만 보면 이 경로는 영원히 전문 200 이다.
     *
     * @effects serve_and_delete_follow_row_disk
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function download_answers_if_modified_since_alone_with_304(): void
    {
        $row = $this->makeRowOnDisk('modules', 'category/row-disk-test/ims.jpg');

        $first = $this->get($this->serveUrl($row));
        $first->assertOk();

        $second = $this->withHeaders(['If-Modified-Since' => (string) $first->headers->get('Last-Modified')])
            ->get($this->serveUrl($row));

        $this->assertNotModifiedMirrors200($first, $second);
    }

    /**
     * 검증자가 다르면 전문 200 이다 — 304 판정이 헐거우면 갈아 끼운 이미지가 도달하지 않는다.
     *
     * @effects serve_and_delete_follow_row_disk
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function download_returns_full_200_for_a_non_matching_validator(): void
    {
        $row = $this->makeRowOnDisk('modules', 'category/row-disk-test/mismatch.jpg');

        $response = $this->withHeaders(['If-None-Match' => '"not-the-current-etag"'])->get($this->serveUrl($row));

        $response->assertOk();
        $this->assertNotEmpty($response->headers->get('ETag'));
    }

    /**
     * 사용자 업로드물 통로는 압축 제외를 **선언**한다 (형제 `PublicStorageController` 와 같다).
     *
     * @effects serve_and_delete_follow_row_disk
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function serving_declares_compression_skip(): void
    {
        $row = $this->makeRowOnDisk('modules', 'category/row-disk-test/skip.jpg');

        $this->withHeaders(['Accept-Encoding' => 'gzip'])->get($this->serveUrl($row))->assertOk();

        $this->assertTrue($this->app['request']->attributes->get(GzipEncodeResponse::SKIP_ATTRIBUTE));
    }
}
