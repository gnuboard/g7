<?php

namespace Modules\Sirsoft\Page\Tests\Feature\User;

// FeatureTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../FeatureTestCase.php';

use App\Extension\Storage\ModuleStorageDriver;
use App\Seo\ExpressionEvaluator;
use App\Seo\SeoMetaResolver;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailResponder;
use App\Thumbnails\ThumbnailSourceProviders;
use App\Thumbnails\ThumbnailUrl;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Page\Listeners\ThumbnailServingListener;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Services\PageAttachmentService;
use Modules\Sirsoft\Page\Support\AttachmentThumbnailSourceProvider;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;

/**
 * 페이지 첨부 미리보기의 이미지 썸네일 계약 (#869)
 *
 * 미리보기 주소에 `?size=`·`?w=` 를 붙이면 발행상태 게이트(미발행은 `pages.read` 관리자·유효 서명만)를
 * 지난 뒤 규격 크기로 줄인 파생본을 낸다. 파라미터가 없으면 종전 원본 응답과 같고, 게이트는 썸네일에도
 * 그대로 걸린다 — 썸네일이 게이트 앞에 있으면 미발행 페이지 이미지가 크기만 바뀌어 새어 나간다.
 *
 * 서명은 `size`·`w` 를 빼고 검증한다 — 관리자 화면이 서명 주소 뒤에 `size` 를 덧붙여도 서명이 깨지지
 * 않아야 하고, 반대로 변조·만료는 그대로 막아야 한다.
 *
 * 원본·파생본 디스크는 가짜로 바꾼다(실제 storage/app 을 건드리지 않는다). 규격은 setUp 에서 고정한다.
 */
class PublicPageAttachmentThumbnailTest extends FeatureTestCase
{
    private const BASE = '/api/modules/sirsoft-page/pages/attachment';

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
     * 이 테스트가 만든 attach-thumb- 슬러그 페이지를 지우고 규격 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Page::where('slug', 'like', 'attach-thumb-%')->forceDelete();
        app(ThumbnailPresetRegistry::class)->flush();

        parent::tearDown();
    }

    /**
     * 실제 JPEG 파일과 첨부 행을 만듭니다.
     *
     * @param  bool  $published  부모 페이지 발행 여부
     * @param  string  $disk  행 disk
     * @return array{0: PageAttachment, 1: string} 첨부와 저장된 원본 바이트
     */
    private function makeImageAttachment(bool $published, string $disk = 'modules'): array
    {
        $factory = Page::factory()->state(['slug' => 'attach-thumb-'.uniqid()]);
        $page = ($published ? $factory->published() : $factory)->create();

        $image = imagecreatetruecolor(400, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 120, 200));
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $path = '2026/11/05/'.uniqid('thumb', true).'.jpg';
        (new ModuleStorageDriver('sirsoft-page', $disk))->put('attachments', $path, $bytes);

        $attachment = PageAttachment::factory()->image()->create([
            'page_id' => $page->id,
            'disk' => $disk,
            'path' => $path,
            'size' => strlen($bytes),
            'meta' => ['width' => 400, 'height' => 300],
        ]);

        return [$attachment, $bytes];
    }

    /**
     * 서명 미리보기 주소
     *
     * @param  PageAttachment  $attachment  첨부
     * @param  int  $minutes  유효 시간(분, 음수면 만료)
     * @return string 상대 서명 주소
     */
    private function signedPreviewUrl(PageAttachment $attachment, int $minutes = 30): string
    {
        return URL::temporarySignedRoute(
            'api.modules.sirsoft-page.pages.attachment.preview',
            now()->addMinutes($minutes),
            ['hash' => $attachment->hash],
            absolute: false
        );
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
     * 파라미터 없는 미리보기는 종전 원본 그대로다 — 발행 페이지 원본은 공유 캐시를 막지 않는다.
     *
     * @scenario case=no_param
     *
     * @effects preview_without_size_serves_the_original_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_preview_without_size_serves_the_original_unchanged(): void
    {
        [$attachment, $bytes] = $this->makeImageAttachment(true);

        $response = $this->get(self::BASE.'/'.$attachment->hash.'/preview');

        $response->assertOk();
        $this->assertSame($bytes, $response->streamedContent());
        $this->assertNull($response->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSharedCacheable($response, '발행 페이지 원본은 공유 캐시를 막지 않는다');
    }

    /**
     * 썸네일을 요청했는데 원본을 낼 때(미정의 규격)는 그 원본의 유지 시간이 썸네일 응답과 같은 값(코어 `cache.layout_ttl`)이다 —
     * 원본 주소의 1년으로 두면 운영자가 규격을 바꿔도 같은 `?size=` 주소가 1년 동안 원본으로 남는다. 무파라미터 원본은 1년 그대로다.
     *
     * @scenario case=array_param
     *
     * @effects bypassed_original_uses_thumbnail_ttl
     *
     * @return void 반환값 없음
     */
    public function test_bypassed_original_uses_thumbnail_ttl(): void
    {
        Config::set('g7_settings.core.cache.layout_ttl', 4321);
        [$attachment] = $this->makeImageAttachment(true);
        $url = self::BASE.'/'.$attachment->hash.'/preview';

        $plain = $this->get($url)->assertOk();
        $this->assertStringContainsString('max-age='.PageAttachmentService::PREVIEW_CACHE_MAX_AGE, (string) $plain->headers->get('Cache-Control'));

        $bypassed = $this->get($url.'?size=nope')->assertOk();
        $cacheControl = (string) $bypassed->headers->get('Cache-Control');
        $this->assertSame('bypass:unknown-preset', $bypassed->headers->get(ThumbnailResponder::HEADER));
        $this->assertStringContainsString('max-age=4321', $cacheControl, '우회 원본의 유지 시간이 썸네일과 다르다');
        $this->assertStringNotContainsString('max-age='.PageAttachmentService::PREVIEW_CACHE_MAX_AGE, $cacheControl);
        $this->assertSharedCacheable($bypassed, '발행 페이지 우회 원본이 공유 캐시 대상에서 빠졌다');
    }

    /**
     * 규격을 붙이면 그 크기의 파생본을 낸다 — miss 다음 hit, `private` 캐시와 nosniff.
     *
     * @scenario case=preset
     *
     * @effects preview_with_size_serves_the_preset_dimensions, gated_thumbnail_is_private_and_hardened
     *
     * @return void 반환값 없음
     */
    public function test_preview_with_size_serves_the_preset_dimensions(): void
    {
        [$attachment] = $this->makeImageAttachment(true);
        $url = self::BASE.'/'.$attachment->hash.'/preview';

        $first = $this->get($url.'?size=xs');

        $first->assertOk();
        $this->assertSame('miss', $first->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([64, 64], $this->dimensionsOf($first));
        $this->assertPrivateCache($first);
        $this->assertSame('nosniff', $first->headers->get('X-Content-Type-Options'));

        $this->assertSame('hit', $this->get($url.'?size=xs')->headers->get(ThumbnailResponder::HEADER));

        // 숫자 요청은 「숫자 요청 대상」 규격으로 올려 맞춘다 (w=150 → md 가로 200)
        $this->assertSame([200, 150], $this->dimensionsOf($this->get($url.'?w=150')));
    }

    /**
     * 게이트는 썸네일에도 걸린다 — 미발행 페이지 첨부는 size 를 붙여도 원본과 같은 404 다.
     *
     * @scenario case=unpublished_gate
     *
     * @effects unpublished_gate_applies_to_thumbnail
     *
     * @return void 반환값 없음
     */
    public function test_unpublished_gate_applies_to_thumbnail(): void
    {
        [$attachment] = $this->makeImageAttachment(false);
        $url = self::BASE.'/'.$attachment->hash.'/preview';

        $this->get($url)->assertStatus(404);
        $this->get($url.'?size=xs')->assertStatus(404);

        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '게이트에 막힌 요청이 파생본을 만들었다');
    }

    /**
     * 유효 서명 주소에 size 를 덧붙여도 미발행 게이트를 통과해 썸네일을 받고, 서명으로 연 미발행
     * 원본은 `private` 캐시다.
     *
     * @scenario case=signed_size
     *
     * @effects signed_url_with_size_passes_unpublished_gate, unpublished_original_is_private
     *
     * @return void 반환값 없음
     */
    public function test_signed_url_with_size_passes_unpublished_gate(): void
    {
        [$attachment] = $this->makeImageAttachment(false);
        $signed = $this->signedPreviewUrl($attachment);

        $thumbnail = $this->get(ThumbnailUrl::withSize($signed, 'xs'));

        $thumbnail->assertOk();
        $this->assertSame([64, 64], $this->dimensionsOf($thumbnail));

        $original = $this->get($signed);

        $original->assertOk();
        $this->assertPrivateCache($original, '미발행 원본이 공유 캐시에 남을 수 있다');
    }

    /**
     * 변조·만료된 서명은 size 를 붙여도 404 다 — 서명 범위를 줄인 것이 변조·만료를 통과시키지 않는다.
     *
     * @scenario case=tampered_or_expired
     *
     * @effects tampered_or_expired_signature_with_size_still_blocked
     *
     * @return void 반환값 없음
     */
    public function test_tampered_or_expired_signature_with_size_still_blocked(): void
    {
        [$attachment] = $this->makeImageAttachment(false);

        $tampered = preg_replace_callback(
            '/(signature=)([0-9a-f]+)/',
            fn ($m) => $m[1].substr($m[2], 0, -8).strrev(substr($m[2], -8)),
            $this->signedPreviewUrl($attachment)
        );

        $this->get(ThumbnailUrl::withSize($tampered, 'xs'))->assertStatus(404);
        $this->get(ThumbnailUrl::withSize($this->signedPreviewUrl($attachment, minutes: -1), 'xs'))->assertStatus(404);

        $this->assertSame([], Storage::disk('thumbnails')->allFiles());
    }

    /**
     * 배열 파라미터(`size[]=x`)는 서명 검증에서 빠지되 규격으로 해석되지 않는다 — 유효 서명이면
     * 원본 200 에 `bypass:unknown-preset` 이다.
     *
     * @scenario case=array_param
     *
     * @effects array_size_with_valid_signature_serves_the_original_with_bypass
     *
     * @return void 반환값 없음
     */
    public function test_array_size_with_valid_signature_serves_the_original_with_bypass(): void
    {
        [$attachment, $bytes] = $this->makeImageAttachment(false);

        $response = $this->get($this->signedPreviewUrl($attachment).'&size[]=x');

        $response->assertOk();
        $this->assertSame('bypass:unknown-preset', $response->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame($bytes, $response->streamedContent());
    }

    /**
     * 행 disk 가 주입 디스크와 달라도 그 행의 디스크에서 원본을 읽어 파생본을 만든다.
     *
     * @scenario case=row_disk
     *
     * @effects thumbnail_reads_the_disk_recorded_on_the_row
     *
     * @return void 반환값 없음
     */
    public function test_thumbnail_reads_the_disk_recorded_on_the_row(): void
    {
        Config::set('filesystems.disks.page_alt', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/page_alt'),
        ]);
        Storage::fake('page_alt');

        [$attachment] = $this->makeImageAttachment(true, disk: 'page_alt');

        $response = $this->get(self::BASE.'/'.$attachment->hash.'/preview?size=xs');

        $response->assertOk();
        $this->assertSame([64, 64], $this->dimensionsOf($response));

        $keys = Storage::disk('thumbnails')->allFiles();
        $this->assertNotEmpty($keys);
        $this->assertStringStartsWith('thumbnails/page_alt/sirsoft-page/attachments/', $keys[0], '파생본 키가 행 disk 를 따르지 않는다');
    }

    /**
     * 원본 파일만 사라진 첨부(원격 저장소에서 직접 지운 경우 등)는 `?size=` 를 붙여도 무파라미터와 같은 404 다 —
     * 남아 있는 파생본으로 200 을 내면 지운 이미지가 썸네일 주소로 계속 보인다.
     *
     * @scenario case=original_missing
     *
     * @effects missing_original_is_404_even_with_size
     *
     * @return void 반환값 없음
     */
    public function test_missing_original_is_404_even_with_size(): void
    {
        [$attachment] = $this->makeImageAttachment(true);
        $url = self::BASE.'/'.$attachment->hash.'/preview';
        $this->get($url.'?size=xs')->assertOk();
        $this->assertNotEmpty(Storage::disk('thumbnails')->allFiles(), '파생본이 먼저 만들어져야 한다');

        // 드라이버를 거치지 않고 원본만 지운다 — 파생본 무효화가 돌지 않은 상태
        Storage::disk('modules')->delete('sirsoft-page/attachments/'.$attachment->path);

        $plain = $this->get($url);
        $sized = $this->get($url.'?size=xs');

        $plain->assertNotFound();
        $sized->assertNotFound();
        $this->assertSame($plain->json(), $sized->json());
        $this->assertNull($sized->headers->get(ThumbnailResponder::HEADER));
    }

    /**
     * 제공자 목록 필터가 여러 번 불려도 같은 제공자 인스턴스를 돌려준다 — 리스너는 필터 호출마다 새로 만들어지므로
     * 그때마다 컨테이너에서 제공자를 새로 만들면 리포지토리까지 매번 다시 조립된다.
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
     * 페이지 공유 카드의 og:image 는 끝에 `size=og` 가 붙는다 — 사용자 템플릿 레이아웃의 실제 SEO 선언으로 잰다.
     *
     * @scenario case=og_card
     *
     * @effects page_og_image_uses_og_preset
     *
     * @return void 반환값 없음
     */
    public function test_page_og_image_renders_with_og_preset(): void
    {
        Config::set('app.url', 'https://site.test');
        $layout = json_decode((string) file_get_contents(base_path('templates/_bundled/sirsoft-basic/layouts/page/show.json')), true);
        $url = self::BASE.'/abcdefabcdef/preview';

        $result = (new SeoMetaResolver(new ExpressionEvaluator))->resolve(
            $layout['meta']['seo'],
            ['page' => ['data' => ['title' => '소개', 'content_thumbnail_url' => $url]]],
            'sirsoft-page',
            null,
            [],
        );

        $this->assertStringContainsString('og:image" content="https://site.test'.$url.'?size=og"', $result['ogTags']);
    }

    /**
     * 미리보기 주소가 썸네일 서빙 경로로, 페이지 첨부가 권한 원본 제공자로 등록된다.
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
        $this->assertTrue(ThumbnailUrl::isThumbnailable(self::BASE.'/abcdefabcdef/preview'));

        $provider = app(AttachmentThumbnailSourceProvider::class);

        $this->assertTrue($provider->gated());
        $this->assertTrue($provider->matches('modules', 'sirsoft-page/attachments/2026/11/05/a.jpg'));
        $this->assertFalse($provider->matches('modules', 'sirsoft-page/attachments/temp/key/a.jpg'), '임시 첨부는 제외');
        $this->assertFalse($provider->matches('modules', 'sirsoft-board/attachments/notice/2026/11/05/a.jpg'));

        [$attachment] = $this->makeImageAttachment(true);
        $fullPath = 'sirsoft-page/attachments/'.$attachment->path;

        $this->assertGreaterThanOrEqual(1, $provider->count());
        $this->assertContains($fullPath, array_map(fn ($source) => $source->path, $provider->chunk(null, 1000)['items']));
        $this->assertSame($provider->key(), app(ThumbnailSourceProviders::class)->classify('modules', $fullPath)?->key());
    }

    /**
     * 다운로드도 미리보기와 같은 판정이다 — 발행 첨부는 공유 캐시 대상, 미발행 첨부는 공유 캐시에 남기지 않는다.
     *
     * @scenario case=unpublished_gate
     *
     * @effects unpublished_download_is_private
     *
     * @return void 반환값 없음
     */
    public function test_download_cache_follows_publication(): void
    {
        [$published] = $this->makeImageAttachment(true);
        [$draft] = $this->makeImageAttachment(false);

        $public = $this->get(self::BASE.'/'.$published->hash);
        $public->assertOk();
        $this->assertSharedCacheable($public, '발행 첨부 다운로드는 종전처럼 공유 캐시 대상이다');

        $admin = $this->createAdminUser(['sirsoft-page.pages.read']);
        $restricted = $this->actingAs($admin)->get(self::BASE.'/'.$draft->hash);
        $restricted->assertOk();
        $this->assertPrivateCache($restricted, '미발행 다운로드가 공유 캐시에 남을 수 있다');
    }
}
