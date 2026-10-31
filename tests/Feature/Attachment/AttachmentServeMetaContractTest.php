<?php

namespace Tests\Feature\Attachment;

use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Extension\HookManager;
use App\Extension\Storage\CoreStorageDriver;
use App\Models\Attachment;
use App\Models\User;
use App\Services\AttachmentService;
use App\Support\ImageResizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 코어 첨부 서빙 메타 계약 테스트 (11차 G3 라운드 B11-4 · B11-5)
 *
 * 두 축을 저장 결과·응답으로 고정합니다.
 *
 * 1) 업로드가 기록하는 `size` 는 **실제 저장 바이트**와 같아야 한다. 축소는 제자리
 *    덮어쓰기이고 `ImageResizer::resizeInPlace()` 는 쓰기 전에 `is_file()` 로 그 경로를
 *    stat 하므로, `clearstatcache()` 없이 읽는 `getSize()` 는 축소 **전** 크기를 돌려준다.
 *    그 값은 서빙에서 `Content-Length` 로 선언되므로 어긋나는 순간 응답이 잘린다.
 * 2) 서빙 메타 키가 없으면 **명시 실패**여야 한다. 조용한 폴백(`?? null`)은 그 축을
 *    소리 없이 없애 버려, ETag 를 쓰지 않고 시각으로만 재검증하는 프록시가 영원히
 *    전문을 받아 간다 — 오류도 로그도 남지 않는다.
 */
class AttachmentServeMetaContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 훅 등록을 비우고 첨부 디스크를 가짜 `attachments` 디스크로 바꿔 업로드와 서빙 메타를 격리한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        HookManager::resetAll();
        config(['attachment.disk' => 'attachments']);
        Storage::fake('attachments');
    }

    /**
     * 축소 경로를 지난 업로드도 행 `size` 가 실제 저장 바이트와 같다.
     *
     * @scenario conditional_request=none
     *
     * @effects attachment_recorded_size_matches_stored_bytes_after_the_resize_path
     *
     * @return void 반환값 없음
     */
    public function test_recorded_size_matches_stored_bytes_after_the_resize_path(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD 확장이 없어 축소 경로를 태울 수 없습니다');
        }

        config([
            'attachment.image_max_width' => 32,
            'attachment.image_max_height' => 32,
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);

        // 상한을 크게 넘는 이미지 — 축소가 실제로 일어난다.
        $file = UploadedFile::fake()->image('big.jpg', 400, 400);

        $attachment = app(AttachmentService::class)->upload($file);

        // 축소가 **실제로 일어났는지** 먼저 확인한다 — 일어나지 않았다면 이 케이스는
        // 재려던 축을 재지 못하고 상한 없는 경로의 사본일 뿐이다.
        $this->assertIsArray($attachment->meta, '이미지 메타가 기록되지 않았다');
        $this->assertLessThanOrEqual(
            32,
            $attachment->meta['width'],
            '축소가 일어나지 않았다 — 이 케이스가 축소 경로를 재지 못한다',
        );

        $this->assertSame(
            Storage::disk('attachments')->size($attachment->path),
            (int) $attachment->size,
            '행의 size 가 실제 저장 바이트와 다르다 — 서빙의 Content-Length 선언이 응답을 자른다',
        );
    }

    /**
     * 서빙 메타 키가 없으면 500 + 로그로 **명시 실패**한다.
     *
     * `getFileInfo()` 는 그 키를 항상 채우지만 서비스는 확장에 열려 있다 — 서브클래스가
     * 키 없이 돌려주면 종전에는 `?? null` 이 그 사실을 삼켜 정상 200 이 나갔다. 형제
     * 모듈(sirsoft-board)은 같은 사유로 이미 키 부재를 명시 실패로 다룬다.
     *
     * @scenario conditional_request=none
     *
     * @effects attachment_missing_serve_meta_fails_loudly
     *
     * @return void 반환값 없음
     */
    public function test_missing_serve_meta_fails_loudly(): void
    {
        $user = User::factory()->create();
        $attachment = Attachment::factory()->image()->uploadedBy($user)->create([
            'disk' => 'attachments',
        ]);

        Storage::disk('attachments')->put($attachment->path, 'fake image content');

        $this->bindServiceThatDropsServeMetaKey();

        Log::spy();

        $this->actingAs($user)
            ->get("/api/attachment/{$attachment->hash}")
            ->assertStatus(500);

        // 로그 문구는 코어 헬퍼(`streamedFileResponseFromMeta()`) 단일 지점의 것이다 — 종전
        // 컨트롤러 사본의 '첨부 서빙 메타 누락' 에서 바뀌었다(16차 E-6: 열한 통로가 같은 문구·
        // 같은 판정을 쓴다). 문구는 사용자 계약이 아니라 운영자 로그의 식별자이고, 통로는
        // `hash` 문맥으로 구분된다.
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context = []) => $message === '파일 서빙 메타 누락'
                && ($context['missing'] ?? null) === 'last_modified'
                && ($context['hash'] ?? null) === $attachment->hash)
            ->once();
    }

    /**
     * 행 `mime_type` 이 빈 첨부는 500 이 아니라 **내려받기**(`application/octet-stream`)로 나간다.
     *
     * 구형 업로드·외부 이관 행은 `mime_type` 이 빈 문자열일 수 있다. 종전에는 스트림 헬퍼가
     * 「어느 쪽에서도 유형을 알 수 없다」며 `LogicException` 을 던져 무인증 표면이 500 이었다.
     * 제품 결정(16차 G3 라운드): 알 수 없으면 정적 서빙의 미지 확장자와 같은 유형으로
     * 내려보내고, 내용을 스니핑하지 않는다(`nosniff` 동반).
     *
     * @scenario conditional_request=none
     *
     * @effects unknown_mime_is_served_as_octet_stream_download, file_serving_response_declares_nosniff
     *
     * @return void 반환값 없음
     */
    public function test_blank_mime_type_row_is_served_as_octet_stream_download(): void
    {
        $user = User::factory()->create();
        $attachment = Attachment::factory()->uploadedBy($user)->create([
            'disk' => 'attachments',
            'mime_type' => '',
            'original_filename' => 'legacy.bin',
        ]);

        Storage::disk('attachments')->put($attachment->path, 'legacy payload without a recorded type');

        $response = $this->actingAs($user)->get("/api/attachment/{$attachment->hash}");

        $response->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'), '비이미지 첨부는 내려받기 통로다');
    }

    /**
     * 반대 방향 — 메타가 온전하면 그대로 200 + `Last-Modified` 다 (회귀 0 확인).
     *
     * @scenario conditional_request=if_modified_since
     *
     * @effects attachment_complete_serve_meta_still_serves_with_last_modified
     *
     * @return void 반환값 없음
     */
    public function test_complete_serve_meta_still_serves_with_last_modified(): void
    {
        $user = User::factory()->create();
        $attachment = Attachment::factory()->image()->uploadedBy($user)->create([
            'disk' => 'attachments',
        ]);

        Storage::disk('attachments')->put($attachment->path, 'fake image content');

        $response = $this->actingAs($user)->get("/api/attachment/{$attachment->hash}");

        $response->assertOk();
        $this->assertSame(
            gmdate('D, d M Y H:i:s', $attachment->updated_at->getTimestamp()).' GMT',
            $response->headers->get('Last-Modified'),
        );
    }

    /**
     * 서빙 메타에서 `last_modified` 를 빠뜨리는 확장 구현을 컨테이너에 세웁니다.
     *
     * 컨텍스트 바인딩(`when(AttachmentService::class)`)은 서브클래스에 적용되지 않으므로
     * 스토리지를 직접 만들어 넘깁니다 — 코어 바인딩과 같은 구성입니다.
     *
     * @return void 반환값 없음
     */
    private function bindServiceThatDropsServeMetaKey(): void
    {
        $this->app->bind(AttachmentService::class, fn ($app) => new class($app->make(AttachmentRepositoryInterface::class), new CoreStorageDriver(config('attachment.disk', 'attachments')), $app->make(ImageResizer::class)) extends AttachmentService
        {
            /**
             * `last_modified` 를 빠뜨린 서빙 메타를 돌려줍니다.
             *
             * @param  string  $hash  첨부파일 해시
             * @param  mixed  $user  요청한 사용자
             * @return array<string, mixed>|null 서빙 정보 (last_modified 없음)
             */
            public function getFileInfo(string $hash, mixed $user = null): ?array
            {
                $info = parent::getFileInfo($hash, $user);

                if ($info === null) {
                    return null;
                }

                unset($info['last_modified']);

                return $info;
            }
        });
    }
}
