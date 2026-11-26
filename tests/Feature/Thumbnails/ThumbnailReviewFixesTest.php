<?php

namespace Tests\Feature\Thumbnails;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Extension\HookManager;
use App\Seo\Editor\SeoOgPreviewService;
use App\Seo\SeoMetaResolver;
use App\Support\ThumbnailDiskOptions;
use App\Thumbnails\DTO\ThumbnailResult;
use App\Thumbnails\DTO\ThumbnailSource;
use App\Thumbnails\DTO\ThumbnailSpec;
use App\Thumbnails\ThumbnailGenerator;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailResponder;
use App\Thumbnails\ThumbnailStore;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;
use Tests\Unit\Thumbnails\Support\TestImages;

/**
 * 코어 검토 결함 회귀 — 준비 단계 예외의 원본 폴백, 일시 사유 bypass 의 캐시 금지, 잘린 원본 복사 거부,
 * 공개 썸네일 디스크 허용 목록, 옛 썸네일 디스크 정리, 원본 통로 캐시 상한, 파생본 없는 공개 원본의 퍼지.
 */
class ThumbnailReviewFixesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 디스크를 가짜로 바꾸고 썸네일 설정을 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        HookManager::resetAll();
        Storage::fake('attachments');
        Storage::fake('thumbnails');
        Storage::fake('public');

        Config::set('attachment.disk', 'attachments');
        Config::set('core.image.driver', 'gd');
        Config::set('core.thumbnail.enabled', true);
        Config::set('core.thumbnail.presets', null);
        Config::set('core.thumbnail.disk_public', 'thumbnails');
        Config::set('core.thumbnail.disk_gated', 'thumbnails');
        Config::set('core.thumbnail.generate_per_minute', 120);
        Config::set('g7_settings.core.cache.layout_ttl', 86400);
        app(ThumbnailPresetRegistry::class)->flush();
        RateLimiter::clear(ThumbnailResponder::RATE_LIMITER.':127.0.0.1');
    }

    /**
     * 임시 이미지를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        TestImages::cleanup();

        parent::tearDown();
    }

    /**
     * 첨부 디스크에 800×600 JPEG 원본을 두고 그 원본 정보를 만듭니다.
     *
     * @param  bool  $gated  권한 원본인지
     * @param  int|null  $maxAge  캐시 상한
     * @return ThumbnailSource 원본
     */
    private function source(bool $gated = true, ?int $maxAge = null): ThumbnailSource
    {
        $path = '2026/11/05/'.Str::uuid().'.jpg';
        Storage::disk('attachments')->put($path, (string) file_get_contents(TestImages::solid(800, 600, 'jpeg')));

        return new ThumbnailSource('attachments', $path, 'image/jpeg', $gated, 800, 600, $maxAge);
    }

    /**
     * 썸네일 요청
     *
     * @param  string  $query  쿼리
     * @return Request 요청
     */
    private function request(string $query): Request
    {
        return Request::create('/x?'.$query, 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
    }

    /**
     * 준비 단계(캐시·잠금 장애, 원격 읽기 실패)의 예외는 500 이 아니라 원본 폴백(bypass:error)이다.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects preparation_failure_falls_back_to_original
     *
     * @return void 반환값 없음
     */
    public function test_preparation_failure_falls_back_to_original(): void
    {
        $this->app->instance(ThumbnailGenerator::class, new class extends ThumbnailGenerator
        {
            /**
             * 부모 의존(엔진·저장소·캐시)을 받지 않는 대역
             */
            public function __construct() {}

            /**
             * 캐시 저장소 장애를 흉내 내 준비 단계에서 예외를 던집니다.
             *
             * @param  ThumbnailSource  $source  원본
             * @param  ThumbnailSpec  $spec  규격
             * @param  bool  $allowGenerate  생성 허용
             * @return ThumbnailResult 결과 (도달하지 않음)
             */
            public function prepare(ThumbnailSource $source, ThumbnailSpec $spec, bool $allowGenerate = true): ThumbnailResult
            {
                throw new RuntimeException('cache store down');
            }
        });

        $request = $this->request('size=sm');
        $response = app(ThumbnailResponder::class)->respond($request, $this->source());

        $this->assertNull($response);
        $this->assertSame('error', ThumbnailResponder::bypassReason($request));

        $original = ThumbnailResponder::markBypass(response('original', 200), $request);
        $this->assertSame('bypass:error', $original->headers->get(ThumbnailResponder::HEADER));
        $this->assertStringContainsString('no-store', (string) $original->headers->get('Cache-Control'));
    }

    /**
     * 생성 제한으로 원본을 내면 그 `?size=` 주소는 캐시되지 않는다(no-store). 영구 사유(미정의 규격)는 원본 캐시 그대로.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects transient_bypass_is_not_cacheable
     *
     * @return void 반환값 없음
     */
    public function test_rate_limited_bypass_is_not_cacheable(): void
    {
        Config::set('core.thumbnail.generate_per_minute', 1);
        $source = $this->source();

        $this->assertNotNull(app(ThumbnailResponder::class)->respond($this->request('size=sm'), $source));

        $limited = $this->request('size=md');
        $this->assertNull(app(ThumbnailResponder::class)->respond($limited, $source));
        $this->assertSame('rate-limited', ThumbnailResponder::bypassReason($limited));
        $original = ThumbnailResponder::markBypass(response('original', 200, ['Cache-Control' => 'public, max-age=86400']), $limited);
        $this->assertStringContainsString('no-store', (string) $original->headers->get('Cache-Control'));

        $unknown = $this->request('size=nope');
        app(ThumbnailResponder::class)->respond($unknown, $source);
        $kept = ThumbnailResponder::markBypass(response('original', 200, ['Cache-Control' => 'public, max-age=86400']), $unknown);
        $this->assertStringNotContainsString('no-store', (string) $kept->headers->get('Cache-Control'));
    }

    /**
     * 썸네일을 요청했는데 원본을 낼 때(미정의 규격·작은 원본·움직이는 이미지 등)는 그 원본의 유지 시간을 썸네일 응답과 같은 값
     * (`cache.layout_ttl`)으로 줄이고 `immutable` 을 뺀다 — 운영자가 규격이나 「움직이는 이미지는 원본 사용」을 바꾸면 같은 `?size=`
     * 주소가 썸네일로 바뀌어야 하는데, 1년으로 두면 브라우저·CDN 이 재확인하지 않는다. 원본 통로의 유지 시간이 더 짧으면 그 값을,
     * `max-age` 가 없는 응답(비운영 환경의 `no-cache`)은 그대로 둔다. `?size=` 가 없는 원본 응답은 건드리지 않는다.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects permanent_bypass_original_uses_thumbnail_ttl
     *
     * @return void 반환값 없음
     */
    public function test_permanent_bypass_original_uses_thumbnail_ttl_without_immutable(): void
    {
        Config::set('g7_settings.core.cache.layout_ttl', 4321);
        $source = $this->source(false);
        $year = 'public, max-age=31536000, immutable';

        $unknown = $this->request('size=nope');
        $this->assertNull(app(ThumbnailResponder::class)->respond($unknown, $source));
        $this->assertSame('unknown-preset', ThumbnailResponder::bypassReason($unknown));

        $immutable = ThumbnailResponder::markBypass(response('original', 200, ['Cache-Control' => $year, 'Expires' => gmdate('D, d M Y H:i:s', time() + 31536000).' GMT']), $unknown);
        $this->assertSame('bypass:unknown-preset', $immutable->headers->get(ThumbnailResponder::HEADER));
        $this->assertEqualsCanonicalizing(['public', 'max-age=4321', 'must-revalidate'], $this->directives($immutable), '우회 원본이 썸네일 주소에 1년 불변으로 박혔다');
        $this->assertEqualsWithDelta(time() + 4321, strtotime((string) $immutable->headers->get('Expires')), 5, '우회 원본의 Expires 가 줄어든 유지 시간과 다르다');

        $revalidate = ThumbnailResponder::markBypass(response('original', 200, ['Cache-Control' => 'public, max-age=31536000, must-revalidate']), $unknown);
        $this->assertEqualsCanonicalizing(['public', 'max-age=4321', 'must-revalidate'], $this->directives($revalidate));

        $notModified = ThumbnailResponder::markBypass(response('', 304, ['Cache-Control' => 'public, max-age=31536000, must-revalidate']), $unknown);
        $this->assertEqualsCanonicalizing(['public', 'max-age=4321', 'must-revalidate'], $this->directives($notModified), '304 재검증 응답이 200 과 다른 유지 시간을 실었다');

        $shorter = ThumbnailResponder::markBypass(response('original', 200, ['Cache-Control' => 'private, max-age=300']), $unknown);
        $this->assertEqualsCanonicalizing(['private', 'max-age=300'], $this->directives($shorter), '원본 통로의 더 짧은 유지 시간을 늘렸다');

        $development = ThumbnailResponder::markBypass(response('original', 200, ['Cache-Control' => 'no-cache']), $unknown);
        $this->assertContains('no-cache', $this->directives($development));
        $this->assertNotContains('max-age=4321', $this->directives($development), '비운영 환경의 no-cache 에 유지 시간을 붙였다');

        $plain = ThumbnailResponder::markBypass(response('original', 200, ['Cache-Control' => $year]), $this->request(''));
        $this->assertNull($plain->headers->get(ThumbnailResponder::HEADER));
        $this->assertEqualsCanonicalizing(['public', 'max-age=31536000', 'immutable'], $this->directives($plain), '`?size=` 없는 원본 주소의 캐시가 바뀌었다');

        $failed = $this->request('size=sm');
        $failed->attributes->set(ThumbnailResponder::BYPASS_ATTRIBUTE, 'error');
        $transient = ThumbnailResponder::markBypass(response('original', 200, ['Cache-Control' => $year]), $failed);
        $this->assertContains('no-store', $this->directives($transient), '일시 사유 원본이 캐시될 수 있게 나갔다');
        $this->assertNotContains('immutable', $this->directives($transient));
    }

    /**
     * 우회 원본의 유지 시간은 `cache.layout_ttl` 을 따르되 1년을 넘지 않는다 — 설정을 1년보다 길게 잡아도, 원본 통로가 1년보다 긴
     * 값을 실어도 `max-age=31536000` 에서 멈춘다.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects permanent_bypass_original_ttl_capped_at_one_year
     *
     * @return void 반환값 없음
     */
    public function test_permanent_bypass_original_ttl_is_capped_at_one_year(): void
    {
        Config::set('g7_settings.core.cache.layout_ttl', 31536000 * 2);
        $unknown = $this->request('size=nope');
        app(ThumbnailResponder::class)->respond($unknown, $this->source(false));

        $year = ThumbnailResponder::markBypass(response('original', 200, ['Cache-Control' => 'public, max-age=31536000, immutable']), $unknown);
        $this->assertEqualsCanonicalizing(['public', 'max-age=31536000', 'must-revalidate'], $this->directives($year));

        $longer = ThumbnailResponder::markBypass(response('original', 200, ['Cache-Control' => 'public, max-age=63072000, immutable']), $unknown);
        $this->assertEqualsCanonicalizing(['public', 'max-age=31536000', 'must-revalidate'], $this->directives($longer), '우회 원본의 유지 시간이 1년을 넘었다');
    }

    /**
     * 공유 캐시 유지 시간(`s-maxage`)도 같은 값으로 줄인다 — `max-age` 만 줄이면 CDN 은 `s-maxage` 를 먼저 따르므로 규격을 바꿔도
     * 그 `?size=` 주소가 앞단에 1년 동안 원본으로 남는다. 더 짧은 값은 늘리지 않고, `max-age` 없이 `s-maxage` 만 있어도 줄인다.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects permanent_bypass_shared_max_age_follows_thumbnail_ttl
     *
     * @return void 반환값 없음
     */
    public function test_permanent_bypass_original_shared_max_age_follows_thumbnail_ttl(): void
    {
        Config::set('g7_settings.core.cache.layout_ttl', 4321);
        $unknown = $this->request('size=nope');
        app(ThumbnailResponder::class)->respond($unknown, $this->source(false));

        $shared = ThumbnailResponder::markBypass(response('original', 200, ['Cache-Control' => 'public, max-age=31536000, s-maxage=31536000, immutable']), $unknown);
        $this->assertEqualsCanonicalizing(['public', 'max-age=4321', 's-maxage=4321', 'must-revalidate'], $this->directives($shared), '우회 원본의 공유 캐시 유지 시간이 1년으로 남았다');

        $sharedOnly = ThumbnailResponder::markBypass(response('original', 200, ['Cache-Control' => 'public, s-maxage=31536000']), $unknown);
        $this->assertContains('s-maxage=4321', $this->directives($sharedOnly), 'max-age 없는 응답의 s-maxage 를 줄이지 않았다');

        $shorter = ThumbnailResponder::markBypass(response('original', 200, ['Cache-Control' => 'public, max-age=300, s-maxage=60']), $unknown);
        $this->assertEqualsCanonicalizing(['public', 'max-age=300', 's-maxage=60'], $this->directives($shorter), '원본 통로의 더 짧은 공유 캐시 유지 시간을 늘렸다');

        $plain = ThumbnailResponder::markBypass(response('original', 200, ['Cache-Control' => 'public, max-age=31536000, s-maxage=31536000']), $this->request(''));
        $this->assertContains('s-maxage=31536000', $this->directives($plain), '`?size=` 없는 원본 주소의 공유 캐시가 바뀌었다');
    }

    /**
     * Cache-Control 값을 지시어 목록으로 나눕니다(순서는 응답 종류마다 다르다).
     *
     * @param  Response  $response  응답
     * @return list<string> 지시어 (소문자)
     */
    private function directives(Response $response): array
    {
        return array_values(array_filter(array_map(
            fn (string $part) => strtolower(trim($part)),
            explode(',', (string) $response->headers->get('Cache-Control'))
        )));
    }

    /**
     * 비어 있는(또는 잘려 0바이트인) 원본 복사는 버린다 — 깨진 썸네일을 영구 저장하지 않게.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects truncated_source_copy_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_empty_source_copy_is_rejected(): void
    {
        Storage::disk('attachments')->put('2026/11/05/empty.jpg', '');

        $this->assertNull(app(ThumbnailGenerator::class)->copyToTemp('attachments', '2026/11/05/empty.jpg'));
    }

    /**
     * 공개 썸네일 디스크로 공개 자산 카탈로그 밖 디스크(`settings` 등)가 오면 비공개 디스크로 쓴다.
     *
     * @scenario serving_point=layout_attachment, request_param=size
     *
     * @effects public_thumbnail_disk_limited_to_catalog
     *
     * @return void 반환값 없음
     */
    public function test_public_disk_outside_catalog_falls_back_to_private_disk(): void
    {
        Config::set('core.thumbnail.disk_public', 'settings');
        $this->assertSame(ThumbnailStore::PRIVATE_DISK, app(ThumbnailStore::class)->diskFor(false));

        Config::set('core.thumbnail.disk_public', 'public');
        $this->assertSame('public', app(ThumbnailStore::class)->diskFor(false));
    }

    /**
     * 권한 썸네일 디스크는 화면 선택지와 같은 판정을 쓴다 — thumbnails, s3, 비공개 첨부 저장소 카탈로그 디스크만 그대로 쓰고
     * 공개 자산 디스크(public, 플러그인 공개 디스크), 정의 없는 디스크, 카탈로그 밖 디스크는 비공개 디스크로 쓴다.
     * 판정이 실패하면 코어 기본 목록(thumbnails, s3)으로 좁힌다.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects gated_thumbnail_disk_limited_to_allowlist
     *
     * @return void 반환값 없음
     */
    public function test_gated_disk_follows_thumbnail_disk_options(): void
    {
        Config::set('filesystems.disks.vault', ['driver' => 'local', 'root' => storage_path('framework/testing/disks/vault')]);
        Config::set('filesystems.disks.cloudfront', ['driver' => 'local', 'root' => storage_path('framework/testing/disks/cloudfront'), 'url' => 'https://cdn.test']);
        Config::set('filesystems.disks.ghost', null);
        HookManager::addFilter('core.settings.available_private_attachment_drivers', function (array $drivers): array {
            foreach (['vault', 'ghost', 'cloudfront'] as $id) {
                $drivers[] = ['id' => $id, 'label' => ['ko' => $id, 'en' => $id], 'provider' => 'test-plugin'];
            }

            return $drivers;
        });
        HookManager::addFilter('core.settings.available_public_asset_drivers', function (array $drivers): array {
            $drivers[] = ['id' => 'cloudfront', 'label' => ['ko' => 'CloudFront', 'en' => 'CloudFront'], 'provider' => 'test-plugin'];

            return $drivers;
        });

        try {
            $expected = ['thumbnails' => 'thumbnails', 's3' => 's3', 'vault' => 'vault', 'public' => 'thumbnails', 'cloudfront' => 'thumbnails', 'ghost' => 'thumbnails', 'settings' => 'thumbnails', '' => 'thumbnails'];

            foreach ($expected as $configured => $resolved) {
                Config::set('core.thumbnail.disk_gated', $configured);
                $this->assertSame($resolved, app(ThumbnailStore::class)->diskFor(true), "설정값 '{$configured}'");
            }

            // 판정 실패 — 코어 기본 목록으로 좁힌다(플러그인 디스크는 비공개 디스크, s3 는 그대로)
            $this->app->bind(ThumbnailDiskOptions::class, fn () => throw new RuntimeException('판정 실패'));

            Config::set('core.thumbnail.disk_gated', 'vault');
            $this->assertSame(ThumbnailStore::PRIVATE_DISK, app(ThumbnailStore::class)->diskFor(true));

            Config::set('core.thumbnail.disk_gated', 's3');
            $this->assertSame('s3', app(ThumbnailStore::class)->diskFor(true));
        } finally {
            HookManager::clearFilter('core.settings.available_private_attachment_drivers');
            HookManager::clearFilter('core.settings.available_public_asset_drivers');
        }
    }

    /**
     * 저장 위치를 바꾼 뒤 옛 디스크의 파생본을 지우고, 그 디렉터리를 퍼지 묶음에 싣는다.
     *
     * @scenario serving_point=layout_attachment, request_param=size
     *
     * @effects retired_thumbnail_disk_is_purged
     *
     * @return void 반환값 없음
     */
    public function test_retired_disk_is_purged(): void
    {
        Storage::disk('public')->put('thumbnails/attachments/a/b.jpg/sm_0123abcd.jpg', 'x');
        $items = [];
        HookManager::addAction('core.thumbnail.after_purge', function (array $payload) use (&$items) {
            $items = array_merge($items, $payload);
        });

        // 지금 쓰는 디스크는 지우지 않는다
        Config::set('core.thumbnail.disk_public', 'public');
        $this->assertFalse(app(ThumbnailStore::class)->purgeRetiredDisk('public'));
        $this->assertTrue(Storage::disk('public')->exists('thumbnails/attachments/a/b.jpg/sm_0123abcd.jpg'));

        Config::set('core.thumbnail.disk_public', 'thumbnails');
        $this->assertTrue(app(ThumbnailStore::class)->purgeRetiredDisk('public'));
        $this->assertFalse(Storage::disk('public')->exists('thumbnails/attachments/a/b.jpg/sm_0123abcd.jpg'));
        $this->assertSame('public', $items[0]['thumbnail_disk'] ?? null);
    }

    /**
     * 원본 통로의 캐시 상한(`maxAge`)이 썸네일 캐시 유지 시간을 묶는다 — 원본보다 오래 남지 않게.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects thumbnail_cache_capped_by_source_max_age
     *
     * @return void 반환값 없음
     */
    public function test_source_max_age_caps_thumbnail_cache(): void
    {
        $this->app['env'] = 'production';

        $response = app(ThumbnailResponder::class)->respond($this->request('size=sm'), $this->source(true, 300));

        $this->assertNotNull($response);
        $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * 파생본이 없어도 공개 자산 디스크 원본을 지우면 원본 URL 을 퍼지한다. 디렉터리째 지우면 그 표지를 싣는다.
     *
     * @scenario serving_point=layout_attachment, request_param=size
     *
     * @effects public_original_purged_without_thumbnails
     *
     * @return void 반환값 없음
     */
    public function test_public_original_is_purged_even_without_thumbnails(): void
    {
        $items = [];
        HookManager::addAction('core.thumbnail.after_purge', function (array $payload) use (&$items) {
            $items = array_merge($items, $payload);
        });

        app(ThumbnailStore::class)->purgePrefix('public', 'banners/a.jpg');
        app(ThumbnailStore::class)->purgePrefix('public', 'banners', true);

        $this->assertCount(2, $items);
        $this->assertSame('', $items[0]['thumbnail_disk']);
        $this->assertNull($items[0]['public_url_prefix']);
        $this->assertStringEndsWith('/banners/a.jpg', (string) $items[0]['original_public_url']);
        $this->assertFalse($items[0]['original_is_directory']);
        $this->assertTrue($items[1]['original_is_directory']);

        // 새로 쓴 직후의 재확인(includeOriginal=false)은 원본 퍼지를 싣지 않는다 — 새 업로드마다 CDN 무효화를 보내지 않게
        $items = [];
        app(ThumbnailStore::class)->purgePrefix('public', 'banners/new.jpg', false, false);
        $this->assertSame([], $items);
    }

    /**
     * 원격 원본 스트림이 정상 종료했어도 받은 바이트가 디스크가 말하는 크기보다 적으면 버린다 — GD 가 잘린 JPEG 를
     * 회색으로 채워 열어 깨진 썸네일이 영구히 남는 것을 막는다. 크기가 맞으면 그대로 쓴다.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects truncated_remote_source_copy_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_truncated_remote_copy_is_rejected_by_size_comparison(): void
    {
        Config::set('filesystems.disks.g7test-remote', ['driver' => 's3']);
        $generator = app(ThumbnailGenerator::class);

        $remote = function (string $bytes, int $size): FilesystemAdapter {
            $stream = fopen('php://memory', 'r+b');
            fwrite($stream, $bytes);
            rewind($stream);
            $disk = \Mockery::mock(FilesystemAdapter::class);
            $disk->shouldReceive('readStream')->andReturn($stream);
            $disk->shouldReceive('size')->andReturn($size);

            return $disk;
        };

        Storage::set('g7test-remote', $remote('abc', 10));
        $this->assertNull($generator->copyToTemp('g7test-remote', 'a/b.jpg'));

        Storage::set('g7test-remote', $remote('abcdefghij', 10));
        $temp = $generator->copyToTemp('g7test-remote', 'a/b.jpg');
        $this->assertIsString($temp);
        $this->assertSame('abcdefghij', file_get_contents($temp));
        @unlink($temp);
    }

    /**
     * 편집기 미리보기의 og·twitter 이미지 주소가 실제 메타 태그와 같다 — 등록된 서빙 경로면 og 규격 주소다.
     * 레이아웃이 이미지 크기를 직접 선언하면 렌더와 같이 원 주소를 유지한다.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects og_preview_matches_rendered_card
     *
     * @return void 반환값 없음
     */
    public function test_og_preview_image_matches_the_rendered_card_address(): void
    {
        $resolver = app(SeoMetaResolver::class);
        $service = new SeoOgPreviewService(
            $resolver,
            $this->createMock(ModuleManagerInterface::class),
            $this->createMock(PluginManagerInterface::class),
        );
        $image = '/api/attachment/abcdefghijkl';
        $value = fn (array $preview, string $group, string $key) => array_column($preview[$group], null, 'key')[$key]['effectiveValue'] ?? null;

        $preview = $service->preview(['og' => ['image' => $image], 'twitter' => ['image' => $image]], [], []);
        $rendered = $resolver->renderOgHtml(['type' => 'website'] + $resolver->resolveOgData(['og' => ['image' => $image]], [], '', ''));

        $this->assertStringContainsString('size=og', (string) $value($preview, 'og', 'image'));
        $this->assertStringContainsString('og:image" content="'.e((string) $value($preview, 'og', 'image')).'"', $rendered);
        $this->assertStringContainsString('size=og', (string) $value($preview, 'twitter', 'image'));

        $declared = $service->preview(['og' => ['image' => $image, 'image_width' => 800]], [], []);
        $this->assertStringNotContainsString('size=og', (string) $value($declared, 'og', 'image'));
    }
}
