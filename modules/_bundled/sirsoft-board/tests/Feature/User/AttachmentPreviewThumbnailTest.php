<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\Storage\ModuleStorageDriver;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailResponder;
use App\Thumbnails\ThumbnailSourceProviders;
use App\Thumbnails\ThumbnailUrl;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Listeners\ThumbnailServingListener;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Services\AttachmentService;
use Modules\Sirsoft\Board\Support\AttachmentThumbnailSourceProvider;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시판 첨부 미리보기의 이미지 썸네일 계약
 *
 * 미리보기 주소에 `?size=`·`?w=` 를 붙이면 콘텐츠 상태 게이트(삭제글·비밀글·블라인드 글)를 지난 뒤
 * 규격 크기로 줄인 파생본을 낸다. 파라미터가 없으면 종전 원본 응답과 같아야 하고, 게이트는 썸네일에도
 * 그대로 걸려야 한다 — 썸네일이 게이트 앞에 있으면 비밀글 이미지가 크기만 바뀌어 새어 나간다.
 *
 * 원본·파생본 디스크는 가짜로 바꾼다(실제 storage/app 을 건드리지 않는다). 규격은 setUp 에서
 * 고정한다 — 설치본 설정이 테스트로 새지 않게.
 */
class AttachmentPreviewThumbnailTest extends BoardTestCase
{
    /**
     * 이 시험이 쓰는 게시판 슬러그
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'thumb-preview';
    }

    /**
     * 비밀글을 쓸 수 있는 게시판 속성
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '썸네일 미리보기 테스트', 'en' => 'Thumbnail Preview Test'],
            'is_active' => true,
            'secret_mode' => 'enabled',
            'blocked_keywords' => [],
        ];
    }

    /**
     * 원본·파생본 디스크를 가짜로 바꾸고 규격을 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('modules');
        Storage::fake('thumbnails');

        // 캐시 정책은 운영 환경에서만 public·private 로 갈린다(그 외 환경은 둘 다 no-cache 이고 Symfony 가
        // private 를 덧붙인다) — 갈림을 재려고 운영 환경으로 둔다.
        $this->app['env'] = 'production';

        Config::set('core.thumbnail.enabled', true);
        Config::set('core.thumbnail.disk_gated', 'thumbnails');
        Config::set('core.thumbnail.disk_public', 'thumbnails');
        Config::set('core.image.driver', 'gd');
        Config::set('core.thumbnail.presets', [
            ['name' => 'xs', 'width' => 64, 'height' => 64, 'fit' => 'cover', 'anchor' => 'center', 'quality' => null, 'format' => 'original', 'upscale' => false, 'pregenerate' => false, 'numeric' => false, 'enabled' => true],
            ['name' => 'md', 'width' => 200, 'height' => null, 'fit' => 'width', 'anchor' => 'center', 'quality' => null, 'format' => 'original', 'upscale' => false, 'pregenerate' => false, 'numeric' => true, 'enabled' => true],
        ]);
        app(ThumbnailPresetRegistry::class)->flush();
        RateLimiter::clear(ThumbnailResponder::RATE_LIMITER.':127.0.0.1');
    }

    /**
     * 규격 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app(ThumbnailPresetRegistry::class)->flush();

        parent::tearDown();
    }

    /**
     * 실제 JPEG 파일과 첨부 행을 만듭니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  string  $hash  첨부 해시 (12자)
     * @param  string  $disk  행 disk
     * @return string 저장된 원본 바이트
     */
    private function createImageAttachment(int $postId, string $hash, string $disk = 'modules'): string
    {
        $image = imagecreatetruecolor(400, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 40));
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $path = "{$this->board->slug}/2026/11/05/{$hash}.jpg";
        (new ModuleStorageDriver('sirsoft-board', $disk))->put('attachments', $path, $bytes);

        DB::table('board_attachments')->insert([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'original_filename' => 'photo.jpg',
            'stored_filename' => "{$hash}.jpg",
            'hash' => $hash,
            'disk' => $disk,
            'mime_type' => 'image/jpeg',
            'size' => strlen($bytes),
            'path' => $path,
            'meta' => json_encode(['width' => 400, 'height' => 300]),
            'collection' => 'attachments',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $bytes;
    }

    /**
     * 미리보기 주소
     *
     * @param  string  $hash  첨부 해시
     * @return string 상대 주소
     */
    private function previewUrl(string $hash): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachment/{$hash}/preview";
    }

    /**
     * 공유 캐시가 저장해도 되는 응답인지 (`public`, `private` 없음)
     *
     * @param  TestResponse  $response  응답
     * @param  string  $message  실패 메시지
     * @return void 반환값 없음
     */
    private function assertSharedCacheable(TestResponse $response, string $message = ''): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('public', $cacheControl, $message);
        $this->assertStringNotContainsString('private', $cacheControl, $message);
    }

    /**
     * 공유 캐시 저장 금지 응답인지 (`private`, `public` 없음)
     *
     * @param  TestResponse  $response  응답
     * @param  string  $message  실패 메시지
     * @return void 반환값 없음
     */
    private function assertPrivateCache(TestResponse $response, string $message = ''): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cacheControl, $message);
        $this->assertStringNotContainsString('public', $cacheControl, $message);
    }

    /**
     * 응답 본문 이미지의 가로·세로
     *
     * @param  TestResponse  $response  응답
     * @return array{0: int, 1: int} 가로·세로
     */
    private function dimensionsOf(TestResponse $response): array
    {
        $info = getimagesizefromstring($response->streamedContent());
        $this->assertIsArray($info, '응답 본문이 이미지가 아니다');

        return [$info[0], $info[1]];
    }

    /**
     * 파라미터 없는 미리보기는 종전 원본 그대로다 — 썸네일 헤더도 붙지 않는다.
     *
     * @scenario case=no_param
     *
     * @effects preview_without_size_serves_the_original_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_preview_without_size_serves_the_original_unchanged(): void
    {
        $postId = $this->createTestPost(['title' => '정상글']);
        $bytes = $this->createImageAttachment($postId, 'thumbnoneAAA');

        $response = $this->get($this->previewUrl('thumbnoneAAA'));

        $response->assertOk();
        $this->assertSame($bytes, $response->streamedContent());
        $this->assertNull($response->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSharedCacheable($response, '정상글 원본은 공유 캐시를 막지 않는다');
    }

    /**
     * 미리보기 한 번에 부모 글은 한 번만 읽는다 — 게이트 셋과 캐시 판정이 같은 글을 쓴다. 건마다 다시 읽으면 갤러리
     * 한 화면의 이미지 수만큼 쿼리가 곱으로 늘어난다.
     *
     * @scenario case=no_param
     *
     * @effects preview_reads_parent_post_once
     *
     * @return void 반환값 없음
     */
    public function test_preview_reads_parent_post_once(): void
    {
        $postId = $this->createTestPost(['title' => '정상글']);
        $this->createImageAttachment($postId, 'thumbqueryAA');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get($this->previewUrl('thumbqueryAA'))->assertOk();

        $lookups = array_filter(
            DB::getQueryLog(),
            static fn (array $query) => str_contains($query['query'], 'board_posts') && str_contains($query['query'], '`status`'),
        );
        DB::disableQueryLog();

        $this->assertCount(1, $lookups, '게이트와 캐시 판정이 부모 글을 따로 읽었다');
    }

    /**
     * 규격을 붙이면 그 크기의 파생본을 낸다 — 첫 요청은 miss(생성), 다음은 hit(재사용).
     * 게시판 첨부는 게이트 원본이라 파생본도 `private` 캐시와 nosniff 를 싣는다.
     *
     * @scenario case=preset
     *
     * @effects preview_with_size_serves_the_preset_dimensions, gated_thumbnail_is_private_and_hardened
     *
     * @return void 반환값 없음
     */
    public function test_preview_with_size_serves_the_preset_dimensions(): void
    {
        $postId = $this->createTestPost(['title' => '정상글']);
        $this->createImageAttachment($postId, 'thumbsizeAAA');

        $first = $this->get($this->previewUrl('thumbsizeAAA').'?size=xs');

        $first->assertOk();
        $this->assertSame('miss', $first->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([64, 64], $this->dimensionsOf($first));
        $this->assertPrivateCache($first);
        $this->assertSame('nosniff', $first->headers->get('X-Content-Type-Options'));

        $second = $this->get($this->previewUrl('thumbsizeAAA').'?size=xs');

        $second->assertOk();
        $this->assertSame('hit', $second->headers->get(ThumbnailResponder::HEADER));

        // 숫자 요청은 「숫자 요청 대상」 규격으로 올려 맞춘다 (w=150 → md 가로 200)
        $numeric = $this->get($this->previewUrl('thumbsizeAAA').'?w=150');

        $numeric->assertOk();
        $this->assertSame([200, 150], $this->dimensionsOf($numeric));
    }

    /**
     * 정의되지 않은 규격은 원본을 내고 사유를 붙인다.
     *
     * @scenario case=unknown_preset
     *
     * @effects unknown_preset_serves_the_original_with_bypass_reason
     *
     * @return void 반환값 없음
     */
    public function test_unknown_preset_serves_the_original_with_bypass_reason(): void
    {
        $postId = $this->createTestPost(['title' => '정상글']);
        $bytes = $this->createImageAttachment($postId, 'thumbunknAAA');

        $response = $this->get($this->previewUrl('thumbunknAAA').'?size=nope');

        $response->assertOk();
        $this->assertSame('bypass:unknown-preset', $response->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame($bytes, $response->streamedContent());
    }

    /**
     * 게이트는 썸네일에도 걸린다 — 열람 권한 없는 비밀글 첨부는 size 를 붙여도 원본과 같은 403 이다.
     *
     * @scenario case=secret_gate
     *
     * @effects secret_post_gate_applies_to_thumbnail
     *
     * @return void 반환값 없음
     */
    public function test_secret_post_gate_applies_to_thumbnail(): void
    {
        $postId = $this->createTestPost(['title' => '비밀글', 'is_secret' => true]);
        $this->createImageAttachment($postId, 'thumbsecrAAA');

        $this->get($this->previewUrl('thumbsecrAAA'))->assertStatus(403);
        $this->get($this->previewUrl('thumbsecrAAA').'?size=xs')->assertStatus(403);

        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '게이트에 막힌 요청이 파생본을 만들었다');
    }

    /**
     * 서명으로 연 비밀글 첨부는 원본·썸네일 모두 `private` 캐시다.
     *
     * @scenario case=signed_private
     *
     * @effects signed_secret_original_is_private
     *
     * @return void 반환값 없음
     */
    public function test_signed_secret_original_is_private(): void
    {
        $postId = $this->createTestPost(['title' => '비밀글', 'is_secret' => true]);
        $this->createImageAttachment($postId, 'thumbsignAAA');

        $signed = URL::temporarySignedRoute(
            'api.modules.sirsoft-board.boards.attachment.preview',
            now()->addMinutes(30),
            ['slug' => $this->board->slug, 'hash' => 'thumbsignAAA'],
            absolute: false
        );

        $original = $this->get($signed);
        $original->assertOk();
        $this->assertPrivateCache($original);

        $thumbnail = $this->get(ThumbnailUrl::withSize($signed, 'xs'));
        $thumbnail->assertOk();
        $this->assertSame([64, 64], $this->dimensionsOf($thumbnail));
        $this->assertPrivateCache($thumbnail);
    }

    /**
     * 삭제글·블라인드 글 첨부도 size 를 붙이면 원본과 같은 403 이다 — 썸네일이 콘텐츠 상태 게이트 뒤에 있다.
     *
     * @scenario case=deleted_blinded_gate
     *
     * @effects deleted_and_blinded_post_gate_applies_to_thumbnail
     *
     * @return void 반환값 없음
     */
    public function test_deleted_and_blinded_post_gate_applies_to_thumbnail(): void
    {
        $blindedId = $this->createTestPost(['title' => '블라인드 글', 'status' => 'blinded']);
        $this->createImageAttachment($blindedId, 'thumbblndAAA');
        $deletedId = $this->createTestPost(['title' => '삭제글', 'status' => 'deleted', 'deleted_at' => now()]);
        $this->createImageAttachment($deletedId, 'thumbdeltAAA');

        foreach (['thumbblndAAA', 'thumbdeltAAA'] as $hash) {
            $this->get($this->previewUrl($hash))->assertStatus(403);
            $this->get($this->previewUrl($hash).'?size=xs')->assertStatus(403);
            $this->get($this->previewUrl($hash).'?w=150')->assertStatus(403);
        }

        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '게이트에 막힌 요청이 파생본을 만들었다');
    }

    /**
     * 서명 주소에 `w=` 를 덧붙여도 서명이 깨지지 않는다 — 숫자 요청도 썸네일 파라미터라 서명 검증에서 뺀다.
     *
     * @scenario case=signed_width
     *
     * @effects signed_url_with_numeric_width_passes_gate
     *
     * @return void 반환값 없음
     */
    public function test_signed_url_with_numeric_width_passes_gate(): void
    {
        $postId = $this->createTestPost(['title' => '비밀글', 'is_secret' => true]);
        $this->createImageAttachment($postId, 'thumbsgwdAAA');

        $response = $this->get($this->signedPreviewUrl('thumbsgwdAAA').'&w=150');

        $response->assertOk();
        $this->assertSame([200, 150], $this->dimensionsOf($response));
        $this->assertPrivateCache($response);
    }

    /**
     * 서명 주소에 썸네일이 아닌 파라미터를 붙이면 서명이 깨져 403 이다 — 서명 검증이 `size`·`w` 말고는
     * 아무것도 빼지 않는다(무시 목록이 넓어지면 이 축이 붉어진다).
     *
     * @scenario case=signed_extra_param
     *
     * @effects signed_url_with_extra_param_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_signed_url_with_extra_param_is_rejected(): void
    {
        $postId = $this->createTestPost(['title' => '비밀글', 'is_secret' => true]);
        $this->createImageAttachment($postId, 'thumbsgfoAAA');
        $signed = $this->signedPreviewUrl('thumbsgfoAAA');

        $this->get($signed)->assertOk();
        $this->get($signed.'&foo=1')->assertStatus(403);
        $this->get(ThumbnailUrl::withSize($signed, 'xs').'&foo=1')->assertStatus(403);
        $this->get($signed.'&w=150&foo=1')->assertStatus(403);

        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '서명이 깨진 요청이 파생본을 만들었다');
    }

    /**
     * 제공자 `chunk` 는 `next` 커서로 이어 읽는다 — 한 묶음이 가득 차면 다음 커서를, 마지막 묶음이면 null 을 준다.
     *
     * @scenario case=chunk_cursor
     *
     * @effects provider_chunk_pages_with_next_cursor
     *
     * @return void 반환값 없음
     */
    public function test_provider_chunk_pages_with_next_cursor(): void
    {
        $postId = $this->createTestPost(['title' => '정상글']);
        $expected = [];

        foreach (['thumbchk1AAA', 'thumbchk2AAA', 'thumbchk3AAA'] as $hash) {
            $this->createImageAttachment($postId, $hash);
            $expected[] = "sirsoft-board/attachments/{$this->board->slug}/2026/11/05/{$hash}.jpg";
        }

        $provider = app(AttachmentThumbnailSourceProvider::class);
        $first = $provider->chunk(null, 2);

        $this->assertCount(2, $first['items']);
        $this->assertNotNull($first['next'], '가득 찬 묶음이 다음 커서를 주지 않았다');

        // 시험 DB 에 남은 다른 행이 있어도 끝까지 이어 읽으면 이 시험의 세 행이 한 번씩 나온다
        $paths = array_map(fn ($source) => $source->path, $first['items']);
        $cursor = $first['next'];
        $pages = 1;

        while ($cursor !== null && $pages < 1000) {
            $chunk = $provider->chunk($cursor, 2);
            $this->assertLessThanOrEqual(2, count($chunk['items']));
            $paths = array_merge($paths, array_map(fn ($source) => $source->path, $chunk['items']));
            $this->assertNotSame($cursor, $chunk['next'], '커서가 앞으로 가지 않는다');
            $cursor = $chunk['next'];
            $pages++;
        }

        $this->assertNull($cursor, '마지막 묶음이 null 커서로 끝나지 않았다');
        $this->assertGreaterThanOrEqual(2, $pages);

        foreach ($expected as $path) {
            $this->assertSame(1, count(array_keys($paths, $path, true)), $path.' 가 한 번만 나와야 한다');
        }
    }

    /**
     * 제공자 목록 필터가 여러 번 불려도 같은 제공자 인스턴스를 돌려준다 — 리스너는 필터 호출마다 새로 만들어지므로
     * 그때마다 컨테이너에서 제공자를 새로 만들면 리포지토리·서비스까지 매번 다시 조립된다.
     *
     * @scenario case=provider_reuse
     *
     * @effects source_provider_resolved_once
     *
     * @return void 반환값 없음
     */
    public function test_source_provider_is_reused_across_filter_calls(): void
    {
        $first = (new ThumbnailServingListener)->addSourceProviders([]);
        $second = (new ThumbnailServingListener)->addSourceProviders(['앞선 제공자']);

        $this->assertInstanceOf(AttachmentThumbnailSourceProvider::class, $first[0]);
        $this->assertSame($first[0], $second[1]);
        $this->assertSame('앞선 제공자', $second[0]);
        $this->assertSame('그대로', (new ThumbnailServingListener)->addSourceProviders('그대로'));
    }

    /**
     * 비밀글 첨부 미리보기의 한시 서명 주소 (상대 주소)
     *
     * @param  string  $hash  첨부 해시
     * @return string 서명 주소
     */
    private function signedPreviewUrl(string $hash): string
    {
        return URL::temporarySignedRoute(
            'api.modules.sirsoft-board.boards.attachment.preview',
            now()->addMinutes(30),
            ['slug' => $this->board->slug, 'hash' => $hash],
            absolute: false
        );
    }

    /**
     * 행 disk 가 주입 디스크와 달라도 그 행의 디스크에서 원본을 읽어 파생본을 만든다.
     *
     * @scenario case=row_disk_thumbnail
     *
     * @effects thumbnail_reads_the_disk_recorded_on_the_row
     *
     * @return void 반환값 없음
     */
    public function test_thumbnail_reads_the_disk_recorded_on_the_row(): void
    {
        Config::set('filesystems.disks.board_alt', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/board_alt'),
        ]);
        Storage::fake('board_alt');

        $postId = $this->createTestPost(['title' => '옛 디스크']);
        $this->createImageAttachment($postId, 'thumbaltdAAA', disk: 'board_alt');

        $response = $this->get($this->previewUrl('thumbaltdAAA').'?size=xs');

        $response->assertOk();
        $this->assertSame([64, 64], $this->dimensionsOf($response));

        $keys = Storage::disk('thumbnails')->allFiles();
        $this->assertNotEmpty($keys);
        $this->assertStringStartsWith('thumbnails/board_alt/sirsoft-board/attachments/', $keys[0], '파생본 키가 행 disk 를 따르지 않는다');
    }

    /**
     * 미리보기 주소가 썸네일 서빙 경로로, 게시판 첨부가 권한 원본 제공자로 등록된다.
     *
     * @scenario case=registration
     *
     * @effects preview_route_is_registered_as_thumbnail_pattern_and_gated_source
     *
     * @return void 반환값 없음
     */
    public function test_preview_route_is_registered_as_thumbnail_pattern_and_gated_source(): void
    {
        $this->assertContains(ThumbnailServingListener::PREVIEW_PATTERN, ThumbnailUrl::patterns());
        $this->assertTrue(ThumbnailUrl::isThumbnailable($this->previewUrl('abcdefabcdef')));

        $provider = app(AttachmentThumbnailSourceProvider::class);

        $this->assertTrue($provider->gated());
        $this->assertTrue($provider->matches('modules', 'sirsoft-board/attachments/notice/2026/11/05/a.jpg'));
        $this->assertFalse($provider->matches('modules', 'sirsoft-board/attachments/notice/temp/key/a.jpg'), '임시 첨부는 제외');
        $this->assertFalse($provider->matches('modules', 'sirsoft-page/attachments/2026/11/05/a.jpg'));

        $postId = $this->createTestPost(['title' => '정상글']);
        $this->createImageAttachment($postId, 'thumbprovAAA');

        $this->assertGreaterThanOrEqual(1, $provider->count());

        $chunk = $provider->chunk(null, 1000);
        $paths = array_map(fn ($source) => $source->path, $chunk['items']);

        $this->assertContains("sirsoft-board/attachments/{$this->board->slug}/2026/11/05/thumbprovAAA.jpg", $paths);
        $this->assertSame(
            $provider->key(),
            app(ThumbnailSourceProviders::class)->classify('modules', "sirsoft-board/attachments/{$this->board->slug}/2026/11/05/thumbprovAAA.jpg")?->key()
        );

        // 서비스가 만드는 원본 정보는 항상 게이트 원본이다
        $source = app(AttachmentService::class)->thumbnailSourceFor(
            Attachment::where('hash', 'thumbprovAAA')->firstOrFail()
        );
        $this->assertNotNull($source);
        $this->assertTrue($source->gated);
        $this->assertSame(400, $source->width);
    }
}
