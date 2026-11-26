<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

use App\Extension\HookManager;
use App\Models\User;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailResponder;
use App\Thumbnails\ThumbnailSourceProviders;
use App\Thumbnails\ThumbnailUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Page\Listeners\ThumbnailServingListener;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Services\PageContentMediaService;
use Modules\Sirsoft\Page\Services\PageService;
use Modules\Sirsoft\Page\Support\AttachmentThumbnailSourceProvider;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 페이지 본문 미디어 이미지의 썸네일 계약 (본문 미디어와 이미지 썸네일의 접합)
 *
 * 본문 미디어 서빙 주소에 `?size=`·`?w=` 를 붙이면 게이트(발행 또는 열람권 또는 서명)를 지난 뒤 줄인 이미지를 낸다. 미발행 페이지의
 * 본문 이미지는 규격을 붙여도 서명 없이는 403 이다. 본문 미디어 이미지는 페이지 첨부 원본 제공자가 함께 맡아 한 번만 센다.
 *
 * 원본·파생본 디스크는 가짜로 바꾸고 규격은 setUp 에서 고정한다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-page/tests/scenarios/page-content-media.yaml
 */
class PageContentMediaThumbnailTest extends ModuleTestCase
{
    /**
     * 저장소를 가짜로 바꾸고 규격을 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
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

        // 페이지 저장은 작성자(버전 스냅샷)가 필요하다 — 본문 미디어도 같은 사람이 올린 것만 잇는다(열람권은 없는 회원)
        $this->actingAs(User::factory()->create());
        // 서명 주소 생성·서빙 요청에 모듈 라우트가 필요하다
        $this->registerModuleRoutes();
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
     * 발행 페이지의 본문 이미지에 규격을 붙이면 그 크기의 파생본을 낸다 — 본문 미디어는 권한 원본이라 썸네일은 `private` 캐시다.
     * 파라미터가 없으면 썸네일 헤더가 없고, 정의되지 않은 규격은 원본에 사유를 붙인다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=thumbnail
     *
     * @effects content_image_size_serves_thumbnail, content_image_unknown_preset_bypasses
     */
    public function test_published_page_image_with_size_serves_thumbnail(): void
    {
        [$media] = $this->linkedMedia(published: true);
        $url = PageContentMediaService::SERVE_PATTERN_URL_PREFIX.$media->hash;

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
    }

    /**
     * 게이트는 썸네일에도 걸린다 — 미발행 페이지의 본문 이미지는 규격을 붙여도 서명 없이는 403 이고 파생본이 생기지 않는다.
     * 서명 주소에 `size`·`w` 를 덧붙이면 통과하고, 그 밖의 파라미터를 붙이면 서명이 깨진다. 발행을 취소하면 이미 만든 파생본도 나가지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=thumbnail
     *
     * @effects content_image_thumbnail_follows_gate, signed_content_image_accepts_thumbnail_param, cached_content_thumbnail_still_gated
     */
    public function test_gate_and_signature_apply_to_thumbnail(): void
    {
        [$media, $page] = $this->linkedMedia(published: false);
        $url = PageContentMediaService::SERVE_PATTERN_URL_PREFIX.$media->hash;

        $this->get($url.'?size=xs')->assertStatus(403);
        $this->get($url.'?w=150')->assertStatus(403);
        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '게이트에 막힌 요청이 파생본을 만들었다');

        $signed = app(PageContentMediaService::class)->signedUrl($media->hash);
        $thumbnail = $this->get(ThumbnailUrl::withSize($signed, 'xs'));
        $thumbnail->assertStatus(200);
        $this->assertSame([64, 64], $this->dimensionsOf($thumbnail));
        $this->assertStringContainsString('private', (string) $thumbnail->headers->get('Cache-Control'));
        $this->get($signed.'&size=xs&foo=1')->assertStatus(403);

        $page->update(['published' => true]);
        $this->get($url.'?size=xs')->assertStatus(200);

        $page->update(['published' => false]);
        $this->get($url.'?size=xs')->assertStatus(403);
    }

    /**
     * 본문 미디어 서빙 주소는 썸네일 서빙 경로로 등록되고, 본문 미디어 이미지는 페이지 첨부 원본 제공자 하나가 한 번만 센다
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
        $listener = new ThumbnailServingListener;
        foreach (ThumbnailServingListener::getSubscribedHooks() as $hook => $definition) {
            HookManager::addFilter($hook, [$listener, $definition['method']], $definition['priority']);
        }

        $this->assertSame('/api/modules/sirsoft-page/pages/content-media/*', ThumbnailServingListener::CONTENT_MEDIA_PATTERN);
        $this->assertContains(ThumbnailServingListener::CONTENT_MEDIA_PATTERN, ThumbnailUrl::patterns());
        $this->assertTrue(ThumbnailUrl::isThumbnailable(PageContentMediaService::SERVE_PATTERN_URL_PREFIX.'abcdefabcdef?expires=1&signature=x'));

        $provider = app(AttachmentThumbnailSourceProvider::class);
        $before = $provider->count();

        [$media] = $this->linkedMedia(published: true);
        $fullPath = 'sirsoft-page/attachments/'.$media->path;

        $this->assertSame(PageAttachment::COLLECTION_CONTENT_MEDIA, $media->collection);
        $this->assertStringStartsWith('content-media/', (string) $media->path);
        $this->assertSame($before + 1, $provider->count());

        $paths = array_map(fn ($source) => $source->path, $provider->chunk(null, 1000)['items']);
        $this->assertSame(1, count(array_keys($paths, $fullPath, true)), '본문 미디어 행은 한 번만 순회한다');

        $this->assertTrue($provider->matches('modules', $fullPath));
        $this->assertTrue($provider->gated());
        $others = array_keys(array_filter(
            app(ThumbnailSourceProviders::class)->all(),
            fn ($candidate) => $candidate->key() !== $provider->key() && $candidate->matches('modules', $fullPath)
        ));
        $this->assertSame([], $others, '본문 미디어 파일을 제 것으로 보는 제공자가 페이지 첨부 말고 또 있다');
    }

    /**
     * 본문 이미지(400×300)를 올려 페이지에 잇습니다.
     *
     * @param  bool  $published  발행 여부
     * @return array{0: PageAttachment, 1: Page} [본문 미디어 행, 페이지]
     */
    private function linkedMedia(bool $published): array
    {
        $service = app(PageContentMediaService::class);
        $tempKey = (string) Str::uuid();
        $media = $service->upload(UploadedFile::fake()->image('a.png', 400, 300), $tempKey);

        $page = app(PageService::class)->createPage([
            'slug' => 'cm-thumb-'.Str::lower(Str::random(6)),
            'title' => ['ko' => '본문 미디어 썸네일'],
            'content' => ['ko' => '<p><img src="'.PageContentMediaService::SERVE_PATTERN_URL_PREFIX.$media->hash.'"></p>'],
            'content_mode' => 'html',
            'published' => $published,
            'temp_key' => $tempKey,
        ]);

        $media = $media->fresh();
        $this->assertSame($page->id, (int) $media->page_id);

        return [$media, $page];
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
