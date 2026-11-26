<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Extension\HookManager;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailResponder;
use App\Thumbnails\ThumbnailSourceProviders;
use App\Thumbnails\ThumbnailUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Listeners\ThumbnailSourcesListener;
use Modules\Sirsoft\Ecommerce\Models\ContentMedia;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\ContentMediaService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Modules\Sirsoft\Ecommerce\Thumbnails\ContentMediaSourceProvider;

/**
 * 이커머스 본문 미디어 이미지의 썸네일 계약 (본문 미디어와 이미지 썸네일의 접합)
 *
 * 본문 미디어 서빙 주소에 `?size=`·`?w=` 를 붙이면 게이트(전시 중 또는 관리자 또는 서명, 공통 정보는 공개)를 지난 뒤 줄인 이미지를 낸다.
 * 숨긴 상품의 본문 이미지는 규격을 붙여도 서명 없이는 403 이다. 본문 미디어 이미지는 전용 원본 제공자(권한 원본)가 센다.
 *
 * 원본·파생본 디스크는 가짜로 바꾸고 규격은 setUp 에서 고정한다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/content-media.yaml
 */
class ContentMediaThumbnailTest extends ModuleTestCase
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

        config([
            'core.thumbnail.enabled' => true,
            'core.thumbnail.disk_public' => 'thumbnails',
            'core.thumbnail.disk_gated' => 'thumbnails',
            'core.image.driver' => 'gd',
            'core.thumbnail.presets' => [
                ['name' => 'xs', 'width' => 64, 'height' => 64, 'fit' => 'cover', 'anchor' => 'center', 'quality' => null, 'format' => 'original', 'upscale' => false, 'pregenerate' => false, 'numeric' => false, 'enabled' => true],
                ['name' => 'md', 'width' => 200, 'height' => null, 'fit' => 'width', 'anchor' => 'center', 'quality' => null, 'format' => 'original', 'upscale' => false, 'pregenerate' => false, 'numeric' => true, 'enabled' => true],
            ],
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
     * 전시 중 상품의 본문 이미지에 규격을 붙이면 그 크기의 파생본을 낸다 — 본문 미디어는 권한 원본이라 썸네일은 `private` 캐시다.
     * 파라미터가 없으면 썸네일 헤더가 없고, 정의되지 않은 규격은 원본에 사유를 붙인다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=thumbnail
     *
     * @effects content_image_size_serves_thumbnail, content_image_unknown_preset_bypasses
     */
    public function test_visible_product_image_with_size_serves_thumbnail(): void
    {
        [$media] = $this->linkedMedia(ProductDisplayStatus::VISIBLE);
        $url = ContentMediaService::SERVE_URL_PREFIX.$media->hash;

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
     * 게이트는 썸네일에도 걸린다 — 숨긴 상품의 본문 이미지는 규격을 붙여도 서명 없이는 403 이고 파생본이 생기지 않는다.
     * 서명 주소에 `size`·`w` 를 덧붙이면 통과하고, 그 밖의 파라미터를 붙이면 서명이 깨진다. 전시를 중단하면 이미 만든 파생본도 나가지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=thumbnail
     *
     * @effects content_image_thumbnail_follows_gate, signed_content_image_accepts_thumbnail_param, cached_content_thumbnail_still_gated
     */
    public function test_gate_and_signature_apply_to_thumbnail(): void
    {
        [$media, $product] = $this->linkedMedia(ProductDisplayStatus::HIDDEN);
        $url = ContentMediaService::SERVE_URL_PREFIX.$media->hash;

        $this->get($url.'?size=xs')->assertStatus(403);
        $this->get($url.'?w=150')->assertStatus(403);
        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '게이트에 막힌 요청이 파생본을 만들었다');

        $signed = app(ContentMediaService::class)->signedUrl($media->hash);
        $thumbnail = $this->get(ThumbnailUrl::withSize($signed, 'xs'));
        $thumbnail->assertStatus(200);
        $this->assertSame([64, 64], $this->dimensionsOf($thumbnail));
        $this->assertStringContainsString('private', (string) $thumbnail->headers->get('Cache-Control'));
        $this->get($signed.'&size=xs&foo=1')->assertStatus(403);

        $product->update(['display_status' => ProductDisplayStatus::VISIBLE->value]);
        $this->get($url.'?size=xs')->assertStatus(200);

        $product->update(['display_status' => ProductDisplayStatus::HIDDEN->value]);
        $this->get($url.'?size=xs')->assertStatus(403);
    }

    /**
     * 본문 미디어 서빙 주소는 썸네일 서빙 경로로 등록되고, 본문 미디어 이미지는 전용 원본 제공자(권한 원본)가 소유자에 이어진 행만 센다.
     * 다른 이미지 제공자는 본문 미디어 파일을 제 것으로 보지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=thumbnail
     *
     * @effects content_media_route_is_thumbnail_pattern, content_image_counted_once_by_own_provider
     */
    public function test_content_media_is_registered_and_counted_once(): void
    {
        $listener = new ThumbnailSourcesListener;
        foreach (ThumbnailSourcesListener::getSubscribedHooks() as $hook => $definition) {
            HookManager::addFilter($hook, [$listener, $definition['method']], $definition['priority']);
        }

        $this->assertContains('/api/modules/sirsoft-ecommerce/content-media/*', ThumbnailSourcesListener::URL_PATTERNS);
        $this->assertContains('/api/modules/sirsoft-ecommerce/content-media/*', ThumbnailUrl::patterns());
        $this->assertTrue(ThumbnailUrl::isThumbnailable(ContentMediaService::SERVE_URL_PREFIX.'abcdefabcdef?expires=1&signature=x'));

        $providers = app(ThumbnailSourceProviders::class);
        $provider = $providers->all()['sirsoft-ecommerce.content_media'] ?? null;
        $this->assertInstanceOf(ContentMediaSourceProvider::class, $provider);
        $this->assertTrue($provider->gated());
        $before = $provider->count();

        [$media] = $this->linkedMedia(ProductDisplayStatus::VISIBLE);
        // 저장 전 임시 행은 미리 만들기 대상에 들지 않는다
        app(ContentMediaService::class)->upload(ContentMediaOwnerType::Product, UploadedFile::fake()->image('t.png', 400, 300), (string) Str::uuid());
        $fullPath = 'sirsoft-ecommerce/content-media/'.$media->path;

        $this->assertStringStartsWith('product/', (string) $media->path);
        $this->assertSame($before + 1, $provider->count());

        $paths = array_map(fn ($source) => $source->path, $provider->chunk(null, 1000)['items']);
        $this->assertSame(1, count(array_keys($paths, $fullPath, true)), '본문 미디어 행은 한 번만 순회한다');

        $this->assertInstanceOf(ContentMediaSourceProvider::class, $providers->classify('modules', $fullPath));
        $this->assertInstanceOf(ContentMediaSourceProvider::class, $providers->classify('s3', $fullPath), '디스크를 옮긴 행을 분류하지 못했다');
        $others = array_keys(array_filter(
            $providers->all(),
            fn ($candidate) => $candidate->key() !== $provider->key() && $candidate->matches('modules', $fullPath)
        ));
        $this->assertSame([], $others, '본문 미디어 파일을 제 것으로 보는 제공자가 또 있다');
        $this->assertFalse($provider->matches('modules', 'sirsoft-ecommerce/images/products/P1/a.jpg'), '상품 이미지를 본문 미디어로 분류했다');
    }

    /**
     * 본문 이미지(400×300)를 올려 상품에 잇습니다.
     *
     * @param  ProductDisplayStatus  $status  상품 전시 상태
     * @return array{0: ContentMedia, 1: Product} [본문 미디어 행, 상품]
     */
    private function linkedMedia(ProductDisplayStatus $status): array
    {
        $service = app(ContentMediaService::class);
        $product = Product::factory()->create(['display_status' => $status->value]);
        $tempKey = (string) Str::uuid();
        $media = $service->upload(ContentMediaOwnerType::Product, UploadedFile::fake()->image('a.png', 400, 300), $tempKey);
        $service->syncWithContent(
            ContentMediaOwnerType::Product,
            $product->id,
            ['ko' => '<img src="'.ContentMediaService::SERVE_URL_PREFIX.$media->hash.'">'],
            true,
            $tempKey
        );

        $media = $media->fresh();
        $this->assertSame($product->id, $media->owner_id);

        return [$media, $product];
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
