<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Feature\Http\Controllers;

use App\Extension\HookManager;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailResponder;
use App\Thumbnails\ThumbnailSourceProviders;
use App\Thumbnails\ThumbnailUrl;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\Ckeditor5\Listeners\ThumbnailRegistrationListener;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageUploadRepositoryInterface;
use Plugins\Sirsoft\Ckeditor5\Services\ImageThumbnailSourceProvider;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * 본문 이미지 서빙의 썸네일 계약 (`?size=`·`?w=`)
 *
 * 본문 이미지는 인증 없이 보이는 완전 공개 원본이다. 컨트롤러가 원본 응답 바로 앞에서 코어
 * `ThumbnailResponder` 를 부르고, 낼 수 없으면 원본에 `X-G7-Thumbnail: bypass:{사유}` 를 붙인다.
 * 파라미터가 없는 요청은 이전과 바이트·헤더가 같아야 한다 — 이미 저장된 본문 HTML 의 주소가 그대로이기 때문이다.
 *
 * 라우터를 지나는 실제 HTTP 왕복으로 잰다(304·302 는 라우터가 헤더를 손보므로 반환값만으로는 부족하다).
 */
class ImageServeThumbnailTest extends PluginTestCase
{
    private const BASE = '/api/plugins/sirsoft-ckeditor5/images/';

    /**
     * 원본·썸네일 디스크를 가짜 저장소로 바꾸고 규격 표를 기본값(defaults.json)으로 고정한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('plugins');
        Storage::fake('thumbnails');

        config([
            'core.thumbnail.enabled' => true,
            'core.thumbnail.presets' => [],
            'core.thumbnail.disk_public' => 'thumbnails',
            'core.image.driver' => 'gd',
        ]);
        app(ThumbnailPresetRegistry::class)->flush();
    }

    /**
     * 가로 1000·세로 800 JPEG 원본과 행을 만든다.
     *
     * @return Ckeditor5ImageUpload 생성된 행
     */
    private function makeImage(): Ckeditor5ImageUpload
    {
        $image = imagecreatetruecolor(1000, 800);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('plugins')->put('sirsoft-ckeditor5/images/2026/11/05/thumb.jpg', $bytes);

        return Ckeditor5ImageUpload::create([
            'original_name' => 'thumb.jpg',
            'file_path' => 'images/2026/11/05/thumb.jpg',
            'storage_disk' => 'plugins',
            'file_size' => strlen($bytes),
            'mime_type' => 'image/jpeg',
            'uploaded_by' => null,
        ]);
    }

    /**
     * 응답 본문 이미지의 [가로, 세로]
     *
     * @param  TestResponse  $response  응답
     * @return array{0: int, 1: int} 크기
     */
    private function dimensions(TestResponse $response): array
    {
        $size = getimagesizefromstring($response->streamedContent());
        $this->assertIsArray($size, '응답 본문이 이미지가 아니다');

        return [$size[0], $size[1]];
    }

    /**
     * 파라미터가 없으면 원본 그대로 — 썸네일 헤더도 붙지 않는다.
     *
     * @scenario thumbnail_query=none
     *
     * @effects ckeditor_image_serve_without_size_returns_original_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_request_without_size_returns_the_original_unchanged(): void
    {
        $image = $this->makeImage();

        $response = $this->get(self::BASE.$image->hash);

        $response->assertOk();
        $this->assertSame([1000, 800], $this->dimensions($response));
        $this->assertNull($response->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame(Storage::disk('plugins')->get('sirsoft-ckeditor5/images/2026/11/05/thumb.jpg'), $response->streamedContent());
        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '파라미터 없는 요청이 파생본을 만들었다');
    }

    /**
     * `?size=sm` 은 자르기 160×160 파생본이고, 첫 요청은 miss·다음 요청은 hit 이다. 원본과 같은 nosniff 를 싣는다.
     *
     * @scenario thumbnail_query=size
     *
     * @effects ckeditor_image_serve_size_returns_preset_dimensions, ckeditor_image_serve_thumbnail_is_hardened
     *
     * @return void 반환값 없음
     */
    public function test_size_returns_derivative_with_preset_dimensions_and_hardening(): void
    {
        $image = $this->makeImage();

        $first = $this->get(self::BASE.$image->hash.'?size=sm');

        $first->assertOk();
        $this->assertSame('miss', $first->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([160, 160], $this->dimensions($first));
        $this->assertSame('nosniff', $first->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('image/jpeg', (string) $first->headers->get('Content-Type'));

        $files = Storage::disk('thumbnails')->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('thumbnails/plugins/sirsoft-ckeditor5/images/2026/11/05/thumb.jpg/sm_', $files[0]);

        $second = $this->get(self::BASE.$image->hash.'?size=sm');
        $second->assertOk();
        $this->assertSame('hit', $second->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([160, 160], $this->dimensions($second));
    }

    /**
     * `?w=300` 은 숫자 요청 대상 규격 중 가로 ≥ 300 인 최소(md, 가로 640)로 올려 맞춘다.
     *
     * @scenario thumbnail_query=w
     *
     * @effects ckeditor_image_serve_w_rounds_up_to_numeric_preset
     *
     * @return void 반환값 없음
     */
    public function test_numeric_width_rounds_up_to_the_smallest_numeric_preset(): void
    {
        $image = $this->makeImage();

        $response = $this->get(self::BASE.$image->hash.'?w=300');

        $response->assertOk();
        $this->assertSame([640, 512], $this->dimensions($response));
    }

    /**
     * 운영 환경에서 공개 원본의 썸네일은 공유 캐시가 저장할 수 있는 `public` 이다 (권한 원본의 `private` 와 반대축).
     *
     * 비운영 환경은 원본·썸네일 모두 `no-cache` 라 구분되지 않으므로 운영 환경으로 바꿔 잰다.
     *
     * @scenario thumbnail_query=size_production_cache
     *
     * @effects ckeditor_image_serve_thumbnail_cache_is_public
     *
     * @return void 반환값 없음
     */
    public function test_thumbnail_of_public_original_is_publicly_cacheable_in_production(): void
    {
        $image = $this->makeImage();
        $previous = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $response = $this->get(self::BASE.$image->hash.'?size=sm');
        } finally {
            $this->app['env'] = $previous;
        }

        $response->assertOk();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringNotContainsString('private', $cacheControl, '공개 원본의 썸네일이 private 로 나갔다');
    }

    /**
     * 썸네일을 낼 수 없으면 원본에 사유를 붙인다 — 미정의 규격, 원본보다 큰 규격(확대 끔).
     *
     * @scenario thumbnail_query=bypass
     *
     * @effects ckeditor_image_serve_bypass_marks_original
     *
     * @return void 반환값 없음
     */
    public function test_unservable_request_returns_original_with_bypass_reason(): void
    {
        $image = $this->makeImage();

        $unknown = $this->get(self::BASE.$image->hash.'?size=nope');
        $unknown->assertOk();
        $this->assertSame('bypass:unknown-preset', $unknown->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([1000, 800], $this->dimensions($unknown));

        $larger = $this->get(self::BASE.$image->hash.'?size=xl');
        $larger->assertOk();
        $this->assertSame('bypass:smaller-than-preset', $larger->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([1000, 800], $this->dimensions($larger));
    }

    /**
     * 썸네일을 요청했는데 원본을 낼 때(미정의 규격·원본보다 큰 규격)는 운영 환경에서 그 원본의 유지 시간이 썸네일 응답과 같은 값
     * (코어 `cache.layout_ttl`)이다 — 원본 주소의 1년으로 두면 운영자가 규격을 바꿔도 같은 `?size=` 주소가 1년 동안 원본으로 남는다.
     * `?size=` 가 없는 원본 주소의 1년은 그대로다.
     *
     * @scenario thumbnail_query=bypass
     *
     * @effects ckeditor_image_serve_bypass_original_uses_thumbnail_ttl
     *
     * @return void 반환값 없음
     */
    public function test_bypassed_original_uses_thumbnail_ttl_in_production(): void
    {
        config(['g7_settings.core.cache.layout_ttl' => 4321]);
        $image = $this->makeImage();
        $previous = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $plain = $this->get(self::BASE.$image->hash);
            $unknown = $this->get(self::BASE.$image->hash.'?size=nope');
            $larger = $this->get(self::BASE.$image->hash.'?size=xl');
        } finally {
            $this->app['env'] = $previous;
        }

        $this->assertEqualsCanonicalizing(['public', 'max-age=31536000', 'must-revalidate'], $this->directives($plain), '원본 주소의 캐시가 바뀌었다');

        foreach (['unknown-preset' => $unknown, 'smaller-than-preset' => $larger] as $reason => $response) {
            $response->assertOk();
            $this->assertSame('bypass:'.$reason, $response->headers->get(ThumbnailResponder::HEADER));
            $this->assertEqualsCanonicalizing(['public', 'max-age=4321', 'must-revalidate'], $this->directives($response), "{$reason} 원본의 유지 시간이 썸네일과 다르다");
        }
    }

    /**
     * Cache-Control 값을 지시어 목록으로 나눕니다(순서는 응답 종류마다 다르다).
     *
     * @param  TestResponse  $response  응답
     * @return list<string> 지시어 (소문자)
     */
    private function directives(TestResponse $response): array
    {
        return array_values(array_filter(array_map(
            fn (string $part) => strtolower(trim($part)),
            explode(',', (string) $response->headers->get('Cache-Control'))
        )));
    }

    /**
     * 공개 썸네일 저장 위치가 공개 자산 디스크(public)면 302 로 파생본 직접 주소를 알려 준다.
     *
     * @scenario thumbnail_query=size_public_disk
     *
     * @effects ckeditor_image_serve_public_thumbnail_disk_redirects
     *
     * @return void 반환값 없음
     */
    public function test_public_asset_thumbnail_disk_redirects_to_direct_url(): void
    {
        Storage::fake('public');
        config(['core.thumbnail.disk_public' => 'public']);
        $image = $this->makeImage();

        $response = $this->get(self::BASE.$image->hash.'?size=sm');

        $response->assertStatus(302);
        $this->assertStringContainsString('thumbnails/plugins/sirsoft-ckeditor5/images/2026/11/05/thumb.jpg/sm_', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertCount(1, Storage::disk('public')->allFiles('thumbnails'));
        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '공개 자산 디스크를 골랐는데 비공개 디스크에 썼다');
    }

    /**
     * 원본 파일만 사라진 행(원격 저장소에서 직접 지운 경우 등)은 `?size=` 를 붙여도 무파라미터와 같은 404 다 —
     * 남아 있는 파생본으로 200 을 내면 지운 이미지가 썸네일 주소로 계속 보인다.
     *
     * @scenario thumbnail_query=original_missing
     *
     * @effects ckeditor_image_serve_missing_original_is_404_with_size
     *
     * @return void 반환값 없음
     */
    public function test_missing_original_is_404_even_with_size(): void
    {
        $image = $this->makeImage();
        $this->get(self::BASE.$image->hash.'?size=sm')->assertOk();
        $this->assertCount(1, Storage::disk('thumbnails')->allFiles(), '파생본이 먼저 만들어져야 한다');

        // 드라이버를 거치지 않고 원본만 지운다 — 파생본 무효화가 돌지 않은 상태
        Storage::disk('plugins')->delete('sirsoft-ckeditor5/'.$image->file_path);

        $plain = $this->get(self::BASE.$image->hash);
        $sized = $this->get(self::BASE.$image->hash.'?size=sm');

        $plain->assertNotFound();
        $sized->assertNotFound();
        $this->assertSame($plain->json(), $sized->json());
        $this->assertNull($sized->headers->get(ThumbnailResponder::HEADER));
    }

    /**
     * 제공자 목록 필터가 여러 번 불려도 같은 제공자 인스턴스를 돌려준다 — 리스너는 필터 호출마다 새로 만들어지므로
     * 제공자를 그때마다 컨테이너에서 새로 만들면 리포지토리·서비스까지 매번 다시 조립된다.
     *
     * @scenario thumbnail_query=provider_reuse
     *
     * @effects ckeditor_thumbnail_provider_resolved_once
     *
     * @return void 반환값 없음
     */
    public function test_source_provider_is_reused_across_filter_calls(): void
    {
        $first = (new ThumbnailRegistrationListener)->addSourceProvider([]);
        $second = (new ThumbnailRegistrationListener)->addSourceProvider(['앞선 제공자']);

        $this->assertInstanceOf(ImageThumbnailSourceProvider::class, $first[0]);
        $this->assertSame($first[0], $second[1]);
        $this->assertSame('앞선 제공자', $second[0]);
    }

    /**
     * 리스너가 서빙 경로 패턴과 공개 원본 제공자를 코어 목록에 더한다(실제 훅 체인).
     *
     * @scenario thumbnail_query=registration
     *
     * @effects ckeditor_thumbnail_listener_registers_url_pattern_and_provider
     *
     * @return void 반환값 없음
     */
    public function test_listener_registers_url_pattern_and_public_source_provider(): void
    {
        $this->assertFalse(ThumbnailUrl::isThumbnailable('/api/plugins/sirsoft-ckeditor5/images/abcdef123456'));

        $this->registerListener();
        $image = $this->makeImage();

        $this->assertTrue(ThumbnailUrl::isThumbnailable('/api/plugins/sirsoft-ckeditor5/images/abcdef123456'));
        $this->assertSame(
            '/api/plugins/sirsoft-ckeditor5/images/abcdef123456?size=lg',
            ThumbnailUrl::for('/api/plugins/sirsoft-ckeditor5/images/abcdef123456', 'lg')
        );
        $this->assertFalse(ThumbnailUrl::isThumbnailable('/api/plugins/sirsoft-ckeditor5/upload'));

        $providers = app(ThumbnailSourceProviders::class);
        $provider = $providers->all()['sirsoft-ckeditor5.image'] ?? null;

        $this->assertInstanceOf(ImageThumbnailSourceProvider::class, $provider);
        $this->assertFalse($provider->gated());
        $this->assertInstanceOf(ImageThumbnailSourceProvider::class, $providers->classify('plugins', 'sirsoft-ckeditor5/images/2026/11/05/thumb.jpg'));
        $this->assertInstanceOf(ImageThumbnailSourceProvider::class, $providers->classify('s3', 'sirsoft-ckeditor5/images/2026/11/05/cdn.jpg'), '공개 자산 디스크로 옮긴 행을 분류하지 못했다');
        $this->assertNull($providers->classify('plugins', 'sirsoft-ckeditor5/temp/a.jpg'), '임시 업로드를 원본으로 분류했다');
        $this->assertNull($providers->classify('plugins', 'sirsoft-ckeditor5/settings/a.png'));
        $this->assertNull($providers->classify('plugins', 'other-plugin/images/a.jpg'));
        // 본문 이미지는 `images` 카테고리에만 쓴다 — 다른 카테고리(뒤에 생길 비공개 카테고리 포함)를 공개 원본으로 분류하지 않는다
        $this->assertNull($providers->classify('plugins', 'sirsoft-ckeditor5/attachments/a.jpg'), '본문 이미지가 아닌 카테고리를 공개 원본으로 분류했다');
        $this->assertNull($providers->classify('plugins', 'sirsoft-ckeditor5/private/a.jpg'));

        $this->assertSame(1, $provider->count());

        $chunk = $provider->chunk(null, 10);
        $this->assertNull($chunk['next']);
        $this->assertCount(1, $chunk['items']);
        $this->assertSame('plugins', $chunk['items'][0]->disk);
        $this->assertSame('sirsoft-ckeditor5/'.$image->file_path, $chunk['items'][0]->path);
        $this->assertFalse($chunk['items'][0]->gated);
    }

    /**
     * 앞선 리스너가 배열이 아닌 값을 넘겨도 지우지 않고 그대로 돌려준다 — 필터 체인의 값을 이 리스너가 판단하지 않는다.
     *
     * @scenario thumbnail_query=registration
     *
     * @effects ckeditor_thumbnail_listener_keeps_non_array_filter_value
     *
     * @return void 반환값 없음
     */
    public function test_listener_returns_non_array_filter_value_untouched(): void
    {
        $listener = new ThumbnailRegistrationListener;

        $this->assertNull($listener->addUrlPattern(null));
        $this->assertSame('invalid', $listener->addSourceProvider('invalid'));
    }

    /**
     * 나눠 읽는 상한이 0 이하여도 최소 한 건은 읽는다 — 형제 저장소와 같은 하한이다. 빈 결과면 호출자가 끝으로 알고 멈춘다.
     *
     * @scenario thumbnail_query=registration
     *
     * @effects ckeditor_image_chunk_reads_at_least_one_row
     *
     * @return void 반환값 없음
     */
    public function test_chunk_limit_has_lower_bound_of_one(): void
    {
        $this->makeImage();

        $rows = app(ImageUploadRepositoryInterface::class)->chunkByMimeTypes(['image/jpeg'], 0, 0);

        $this->assertGreaterThanOrEqual(1, $rows->count());
    }

    /**
     * 리스너의 구독 훅을 실제 HookManager 에 건다 (tearDown 이 스냅샷으로 되돌린다).
     *
     * @return void 반환값 없음
     */
    private function registerListener(): void
    {
        $listener = new ThumbnailRegistrationListener;

        foreach (ThumbnailRegistrationListener::getSubscribedHooks() as $hook => $definition) {
            $this->assertSame('filter', $definition['type']);
            HookManager::addFilter($hook, [$listener, $definition['method']], $definition['priority']);
        }
    }
}
