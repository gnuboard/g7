<?php

namespace Tests\Feature\Api\Public;

use App\Contracts\Extension\StorageInterface;
use App\Models\Attachment;
use App\Services\AttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * S3 디스크 첨부의 공개 이미지 서빙 회귀 테스트 (공개 #99 / 내부 #563)
 *
 * attachment.disk 가 s3 로 전환된 상태에서 이미지 인라인 서빙 분기가 로컬 절대
 * 경로(filemtime/filesize)를 전제해 500 이 나던 결함의 회귀 가드.
 * 실환경 재현: 실 AWS 업로드 후 GET /api/attachment/{hash} →
 * "filemtime(): stat failed for \\2026/08/13/….png" 500 (2026-08-13 라이브 로그).
 *
 * S3 디스크의 getBasePath('') 는 로컬 stat 가능한 경로가 아니므로(어댑터 prefix
 * 가 빈 문자열), 그 의미를 재현하는 StorageInterface 대역으로 원격 디스크 행을
 * 만든다. Storage::fake('s3') 는 로컬 디스크라 이 결함을 재현하지 못한다.
 */
class AttachmentS3DiskServingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 원격(S3) 디스크 의미를 재현하는 스토리지 대역을 컨텍스트 바인딩합니다.
     *
     * @param  string  $content  스트림으로 서빙될 바이트
     */
    private function bindRemoteDiskStorage(string $content = 'png-bytes'): void
    {
        $storage = Mockery::mock(StorageInterface::class);
        // 행 disk 해석(`ResolvesRowStorage::storageForRow`)이 주입 스토리지의 disk 이름을 먼저 묻는다 —
        // 's3' 와 다른 이름을 돌려줘야 `withDisk('s3')` 분기로 들어간다 (14차 G3 라운드).
        $storage->shouldReceive('getDisk')->andReturn('local');
        $storage->shouldReceive('withDisk')->andReturnSelf();
        $storage->shouldReceive('exists')->andReturn(true);
        // S3 어댑터의 path('') 의미 재현 — 로컬 stat 불가능한 값 (라이브 로그의 '\2026/…' 원인)
        $storage->shouldReceive('getBasePath')->andReturn('');
        $storage->shouldReceive('response')->andReturnUsing(
            fn () => new StreamedResponse(
                function () use ($content) {
                    echo $content;
                },
                200,
                ['Content-Type' => 'image/png', 'Content-Length' => (string) strlen($content)]
            )
        );

        $this->app->when(AttachmentService::class)
            ->needs(StorageInterface::class)
            ->give(fn () => $storage);
    }

    /**
     * s3 디스크 이미지 행을 생성합니다.
     */
    private function makeS3ImageAttachment(): Attachment
    {
        return Attachment::factory()->create([
            'disk' => 's3',
            'path' => '2026/08/13/regression-guard.png',
            'mime_type' => 'image/png',
        ]);
    }

    /**
     * s3 디스크 이미지 첨부가 200 스트림 + 캐싱 헤더로 서빙되어야 합니다.
     *
     * 종전 코드는 getBasePath 로 로컬 절대 경로를 조립해 filemtime 에서 죽었다(500).
     *
     * @scenario region_shape=aws_region,endpoint=absent,driver_usability=adapter_present,attachment_disk=follow_s3
     *
     * @effects attachment_upload_follows_driver
     */
    public function test_s3_disk_image_attachment_serves_200_with_cache_headers(): void
    {
        $this->bindRemoteDiskStorage();
        $attachment = $this->makeS3ImageAttachment();

        $response = $this->get('/api/attachment/'.$attachment->hash);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->headers->get('ETag'));
        $this->assertStringContainsString('image/png', (string) $response->headers->get('Content-Type'));
        $this->assertNotEmpty((string) $response->headers->get('Cache-Control'));
    }

    /**
     * 동일 ETag 의 If-None-Match 재요청은 304 로 응답해야 합니다 (캐싱 계약 유지).
     *
     * ETag 는 파일 stat 이 아닌 행 메타(디스크·경로·수정시각·크기) 기반이어야
     * 원격 디스크에서도 결정적이다.
     */
    public function test_s3_disk_image_attachment_etag_roundtrip_yields_304(): void
    {
        $this->bindRemoteDiskStorage();
        $attachment = $this->makeS3ImageAttachment();

        $first = $this->get('/api/attachment/'.$attachment->hash);
        $first->assertStatus(200);
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $second = $this->withHeaders(['If-None-Match' => $etag])
            ->get('/api/attachment/'.$attachment->hash);

        $second->assertStatus(304);
        $this->assertSame($etag, $second->headers->get('ETag'));
    }

    /**
     * 첨부 통로도 `Last-Modified` 를 내보내고 `If-Modified-Since` 를 처리한다.
     *
     * 8차 라운드가 스트림 서빙 헬퍼에 이 축을 넣으며 「첨부 통로까지 배선」이라고 적었는데,
     * 그 축을 재는 테스트는 **템플릿 레이아웃 첨부 라우트 하나**뿐이었다. 첨부 쪽 배선
     * (`AttachmentService::getFileInfo()` 의 `last_modified` 키 → 컨트롤러의
     * `lastModifiedAt:`)을 `null` 로 바꿔도 전 스위트가 초록이다 — ETag 를 쓰지 않는
     * 프록시가 매번 전문을 받아 가는 상태가 무게이트로 남는다.
     *
     * @scenario conditional_request=ims, consumer=attachment
     *
     * @effects attachment_stream_declares_last_modified_and_honours_ims
     */
    public function test_attachment_stream_declares_last_modified_and_honours_if_modified_since(): void
    {
        $this->bindRemoteDiskStorage();
        $attachment = $this->makeS3ImageAttachment();

        $first = $this->get('/api/attachment/'.$attachment->hash);
        $first->assertStatus(200);

        $lastModified = (string) $first->headers->get('Last-Modified');
        $this->assertNotEmpty(
            $lastModified,
            '첨부 스트림이 Last-Modified 를 내보내지 않는다 — ETag 를 쓰지 않는 프록시가 매번 전문을 받아 간다'
        );

        $second = $this->withHeaders(['If-Modified-Since' => $lastModified])
            ->get('/api/attachment/'.$attachment->hash);

        $second->assertStatus(304);
    }

    /**
     * 로컬 디스크 이미지 행의 서빙 계약(200 + ETag)이 유지되어야 합니다 (혼재 회귀 가드).
     *
     * 기존 행은 행 disk 로 계속 서빙된다 — s3 전환 후에도 과거 로컬 행이 깨지면 안 된다.
     */
    public function test_local_disk_image_attachment_still_serves(): void
    {
        $this->bindRemoteDiskStorage();

        $attachment = Attachment::factory()->create([
            'disk' => 'attachments',
            'path' => '2026/08/13/legacy-local.png',
            'mime_type' => 'image/png',
        ]);

        $response = $this->get('/api/attachment/'.$attachment->hash);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->headers->get('ETag'));
    }
}
