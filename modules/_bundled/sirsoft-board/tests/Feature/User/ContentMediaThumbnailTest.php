<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailResponder;
use App\Thumbnails\ThumbnailSourceProviders;
use App\Thumbnails\ThumbnailUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Listeners\ThumbnailServingListener;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Services\PostContentMediaService;
use Modules\Sirsoft\Board\Support\AttachmentThumbnailSourceProvider;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시판 본문 미디어 이미지의 썸네일 계약 (본문 미디어와 이미지 썸네일의 접합)
 *
 * 본문 미디어 서빙 주소에 `?size=`·`?w=` 를 붙이면 글 게이트(읽기 권한·비밀·삭제·블라인드·서명)를 지난 뒤 줄인 이미지를 낸다.
 * 원본을 못 보는 요청은 썸네일도 못 본다. 서명 주소에 규격을 덧붙여도 서명이 유효하다. 본문 미디어 이미지는 게시판 첨부
 * 원본 제공자가 함께 맡아 한 번만 센다.
 *
 * 원본·파생본 디스크는 가짜로 바꾸고 규격은 setUp 에서 고정한다(설치본 설정이 시험으로 새지 않게).
 *
 * @scenario-manifest modules/_bundled/sirsoft-board/tests/scenarios/content-media.yaml
 */
class ContentMediaThumbnailTest extends BoardTestCase
{
    /**
     * 이 시험이 쓰는 게시판 슬러그를 돌려줍니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'content-media-thumb';
    }

    /**
     * 비밀글을 쓸 수 있는 게시판 속성을 돌려줍니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '본문 미디어 썸네일', 'en' => 'Content Media Thumbnail'],
            'is_active' => true,
            'use_file_upload' => false,
            'secret_mode' => 'enabled',
        ];
    }

    /**
     * 원본·파생본 디스크를 가짜로 바꾸고 규격·권한을 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('modules');
        Storage::fake('thumbnails');

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

        $this->setGuestPermissions(['posts.read', 'posts.write', 'attachments.upload']);
        $this->withCredentials();
        DB::table('board_attachments')->where('path', 'like', $this->getTestBoardSlug().'/content-media/%')->delete();
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
     * 공개 글의 본문 이미지에 규격을 붙이면 그 크기의 파생본을 낸다 — 게시판 본문 미디어는 권한 원본이라 `private` 캐시와 nosniff.
     * 파라미터가 없으면 썸네일 헤더가 없고, 정의되지 않은 규격은 원본에 사유를 붙인다. 이미 만들어진 파생본도 글 읽기 권한이
     * 없어지면 나가지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=thumbnail
     *
     * @effects content_image_size_serves_thumbnail, content_image_unknown_preset_bypasses, cached_content_thumbnail_still_gated
     */
    public function test_public_post_image_with_size_serves_thumbnail(): void
    {
        [$hash] = $this->linkedMedia(isSecret: false);
        $url = $this->serveUrl($hash);

        $plain = $this->get($url);
        $plain->assertStatus(200);
        $this->assertFalse($plain->headers->has(ThumbnailResponder::HEADER));

        $first = $this->get($url.'?size=xs');
        $first->assertStatus(200);
        $this->assertSame('miss', $first->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([64, 64], $this->dimensionsOf($first));
        $this->assertSame('nosniff', $first->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', (string) $first->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', (string) $first->headers->get('Cache-Control'));

        $this->assertSame('hit', $this->get($url.'?size=xs')->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([200, 150], $this->dimensionsOf($this->get($url.'?w=150')));

        $bypass = $this->get($url.'?size=nope');
        $bypass->assertStatus(200);
        $this->assertSame('bypass:unknown-preset', $bypass->headers->get(ThumbnailResponder::HEADER));

        // 파생본이 이미 있어도 게이트가 먼저다 — 글 읽기 권한이 없으면 원본과 같이 403
        $this->setGuestPermissions(['posts.write', 'attachments.upload']);
        $this->get($url)->assertStatus(403);
        $this->get($url.'?size=xs')->assertStatus(403);
    }

    /**
     * 게이트는 썸네일에도 걸린다 — 비밀글과 임시 행의 본문 이미지는 규격을 붙여도 서명 없이는 403 이고 파생본이 생기지 않는다.
     * 서명 주소에 `size`·`w` 를 덧붙이면 통과하고, 그 밖의 파라미터를 붙이면 서명이 깨진다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=thumbnail
     *
     * @effects content_image_thumbnail_follows_gate, signed_content_image_accepts_thumbnail_param
     */
    public function test_gate_and_signature_apply_to_thumbnail(): void
    {
        [$secretHash] = $this->linkedMedia(isSecret: true);
        $upload = $this->upload((string) Str::uuid());
        $tempHash = (string) $upload->json('data.hash');

        $this->get($this->serveUrl($secretHash).'?size=xs')->assertStatus(403);
        $this->get($this->serveUrl($tempHash).'?size=xs')->assertStatus(403);
        $this->get($this->serveUrl($tempHash).'?w=150')->assertStatus(403);
        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '게이트에 막힌 요청이 파생본을 만들었다');

        $signed = app(PostContentMediaService::class)->signedUrl($this->getTestBoardSlug(), $secretHash);
        $thumbnail = $this->get(ThumbnailUrl::withSize($signed, 'xs'));
        $thumbnail->assertStatus(200);
        $this->assertSame([64, 64], $this->dimensionsOf($thumbnail));
        $this->assertStringContainsString('private', (string) $thumbnail->headers->get('Cache-Control'));
        $this->get($signed.'&size=xs&foo=1')->assertStatus(403);

        $tempThumbnail = $this->get((string) $upload->json('data.url').'&w=150');
        $tempThumbnail->assertStatus(200);
        $this->assertSame([200, 150], $this->dimensionsOf($tempThumbnail));
    }

    /**
     * 본문 미디어 서빙 주소는 썸네일 서빙 경로로 등록되고, 본문 미디어 이미지는 게시판 첨부 원본 제공자 하나가 한 번만 센다
     * (같은 표·같은 저장 위치 — 제공자를 따로 두지 않는다).
     *
     * @return void 반환값 없음
     *
     * @scenario case=thumbnail
     *
     * @effects content_media_route_is_thumbnail_pattern, content_image_counted_once_by_attachment_provider
     */
    public function test_content_media_is_registered_and_counted_once(): void
    {
        $slug = $this->getTestBoardSlug();
        $this->assertSame('/api/modules/sirsoft-board/boards/*/content-media/*', ThumbnailServingListener::CONTENT_MEDIA_PATTERN);
        $this->assertContains(ThumbnailServingListener::CONTENT_MEDIA_PATTERN, ThumbnailUrl::patterns());
        $this->assertTrue(ThumbnailUrl::isThumbnailable($this->serveUrl('abcdefabcdef').'?expires=1&signature=x'));

        $provider = app(AttachmentThumbnailSourceProvider::class);
        $before = $provider->count();

        [$hash] = $this->linkedMedia(isSecret: false);
        $row = Attachment::query()->where('hash', $hash)->firstOrFail();
        $fullPath = 'sirsoft-board/attachments/'.$row->path;

        $this->assertSame(Attachment::COLLECTION_CONTENT_MEDIA, $row->collection);
        $this->assertStringStartsWith("{$slug}/content-media/", (string) $row->path);
        $this->assertSame($before + 1, $provider->count());

        $paths = array_map(fn ($source) => $source->path, $provider->chunk(null, 1000)['items']);
        $this->assertSame(1, count(array_keys($paths, $fullPath, true)), '본문 미디어 행은 한 번만 순회한다');

        $this->assertTrue($provider->matches('modules', $fullPath));
        $this->assertTrue($provider->gated());
        $others = array_keys(array_filter(
            app(ThumbnailSourceProviders::class)->all(),
            fn ($candidate) => $candidate->key() !== $provider->key() && $candidate->matches('modules', $fullPath)
        ));
        $this->assertSame([], $others, '본문 미디어 파일을 제 것으로 보는 제공자가 게시판 첨부 말고 또 있다');
    }

    /**
     * 비회원으로 올려 공개(또는 비밀) 비회원 글에 이은 본문 이미지(400×300).
     *
     * @param  bool  $isSecret  비밀글 여부
     * @return array{0: string, 1: int} [해시, 글 ID]
     */
    private function linkedMedia(bool $isSecret): array
    {
        $tempKey = (string) Str::uuid();
        $upload = $this->upload($tempKey);
        $hash = (string) $upload->json('data.hash');

        $postId = (int) $this->postJson("/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/posts", [
            'title' => $isSecret ? '비밀 본문 이미지' : '공개 본문 이미지',
            'content' => '<p><img src="'.$upload->json('data.url').'"></p>',
            'content_mode' => 'html',
            'author_name' => '비회원',
            'password' => 'guest1234',
            'is_secret' => $isSecret,
            'temp_key' => $tempKey,
        ])->assertStatus(201)->json('data.id');

        $this->assertSame($postId, (int) Attachment::query()->where('hash', $hash)->firstOrFail()->post_id);

        return [$hash, $postId];
    }

    /**
     * 본문 이미지(400×300)를 임시로 올립니다.
     *
     * @param  string  $tempKey  임시 키
     * @return TestResponse 업로드 응답
     */
    private function upload(string $tempKey): TestResponse
    {
        return $this->postJson("/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/content-media", [
            'file' => UploadedFile::fake()->image('s.png', 400, 300),
            'temp_key' => $tempKey,
        ])->assertStatus(201);
    }

    /**
     * 서명 없는 본문 미디어 서빙 주소를 돌려줍니다.
     *
     * @param  string  $hash  해시
     * @return string 서명 없는 서빙 주소
     */
    private function serveUrl(string $hash): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/content-media/{$hash}";
    }

    /**
     * 응답 본문 이미지의 가로·세로.
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
}
