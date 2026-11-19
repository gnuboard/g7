<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Public;

use App\Contracts\Extension\StorageInterface;
use App\Thumbnails\DTO\ThumbnailSpec;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailPresetSettings;
use App\Thumbnails\ThumbnailResponder;
use App\Thumbnails\ThumbnailSourceProviders;
use App\Thumbnails\ThumbnailUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\HeroSection\Listeners\ThumbnailRegistrationListener;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Services\BannerImageService;
use Plugins\Sirsoft\HeroSection\Services\BannerImageThumbnailSourceProvider;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 배너 이미지 서빙의 썸네일 계약 (`?size=`·`?w=`, 매니페스트 image-thumbnail.yaml)
 *
 * 배너 이미지(`/api/plugins/sirsoft-hero_section/images/{hash}`)는 해시만 알면 누구나 받는 공개 원본이다. PC·모바일 이미지를
 * 따로 올리지만 둘 다 각자의 원본이라 각자 썸네일 경로를 탄다. 컨트롤러가 해시 조회(게이트) 바로 뒤·원본 응답 바로 앞에서 코어
 * `ThumbnailResponder` 를 부르고, 낼 수 없으면 원본에 `X-G7-Thumbnail: bypass:{사유}` 를 붙인다. 파라미터가 없으면 응답은 이전과 같다.
 *
 * 라우터를 지나는 실제 HTTP 왕복으로 잰다(302·304 는 라우터가 헤더를 손보므로 반환값만으로는 부족하다).
 */
class BannerImageThumbnailTest extends PluginTestCase
{
    /**
     * 원본·썸네일 디스크를 가짜 저장소로 바꾸고 규격 표를 기본값(defaults.json)으로 고정한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisks();
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
     * 파라미터가 없으면 원본 그대로 — 썸네일 헤더도, 파생본도 생기지 않는다.
     *
     * @scenario thumbnail_query=none
     *
     * @effects hero_image_without_size_returns_original_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_request_without_size_returns_the_original_unchanged(): void
    {
        $image = $this->bannerImage('pc');

        $response = $this->get($this->api('/images/'.$image->hash));

        $response->assertOk();
        $this->assertSame([1920, 640], $this->dimensions($response));
        $this->assertNull($response->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame($this->imageStorage()->get($this->fullPath($image)), $response->streamedContent());
        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '파라미터 없는 요청이 파생본을 만들었다');
    }

    /**
     * PC 이미지 `?size=md` 는 가로 640 파생본(가로만 맞춤 — 비율 유지)이고, 첫 요청은 miss·다음 요청은 hit 이다. nosniff 를 싣는다.
     *
     * @scenario thumbnail_query=size
     *
     * @effects hero_image_size_returns_preset_dimensions, hero_image_thumbnail_is_hardened
     *
     * @return void 반환값 없음
     */
    public function test_size_returns_derivative_with_preset_dimensions_and_hardening(): void
    {
        $image = $this->bannerImage('pc');

        $first = $this->get($this->api('/images/'.$image->hash.'?size=md'));

        $first->assertOk();
        $this->assertSame('miss', $first->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([640, 213], $this->dimensions($first));
        $this->assertSame('nosniff', $first->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('image/jpeg', (string) $first->headers->get('Content-Type'));

        $files = Storage::disk('thumbnails')->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('thumbnails/'.$this->diskName().'/'.$this->fullPath($image).'/md_', $files[0]);

        $second = $this->get($this->api('/images/'.$image->hash.'?size=md'));
        $second->assertOk();
        $this->assertSame('hit', $second->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([640, 213], $this->dimensions($second));
    }

    /**
     * 모바일 이미지도 따로 올린 원본이라 각자 썸네일을 낸다 — 같은 배너의 PC 파생본과 키가 갈린다.
     *
     * @scenario thumbnail_query=mobile_original
     *
     * @effects hero_mobile_image_has_own_thumbnail
     *
     * @return void 반환값 없음
     */
    public function test_mobile_image_is_its_own_original(): void
    {
        $pc = $this->bannerImage('pc');
        $mobile = $this->bannerImage('mobile');

        $response = $this->get($this->api('/images/'.$mobile->hash.'?size=md'));

        $response->assertOk();
        $this->assertSame('miss', $response->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([640, 853], $this->dimensions($response));
        $this->get($this->api('/images/'.$pc->hash.'?size=md'))->assertOk();

        $files = Storage::disk('thumbnails')->allFiles();
        $this->assertCount(2, $files, 'PC·모바일 이미지가 파생본 하나를 나눠 썼다');
    }

    /**
     * `?w=1000` 은 숫자 요청 대상 규격 중 가로 ≥ 1000 인 최소(lg, 가로 1280)로 올려 맞춘다.
     *
     * @scenario thumbnail_query=w
     *
     * @effects hero_image_w_rounds_up_to_numeric_preset
     *
     * @return void 반환값 없음
     */
    public function test_numeric_width_rounds_up_to_the_smallest_numeric_preset(): void
    {
        $image = $this->bannerImage('pc');

        $response = $this->get($this->api('/images/'.$image->hash.'?w=1000'));

        $response->assertOk();
        $this->assertSame([1280, 427], $this->dimensions($response));
    }

    /**
     * 운영 환경에서 공개 원본의 썸네일은 공유 캐시가 저장할 수 있는 `public` 이다 (권한 원본의 `private` 와 반대축).
     *
     * @scenario thumbnail_query=size_production_cache
     *
     * @effects hero_image_thumbnail_cache_is_public
     *
     * @return void 반환값 없음
     */
    public function test_thumbnail_of_public_original_is_publicly_cacheable_in_production(): void
    {
        $image = $this->bannerImage('pc');
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->get($this->api('/images/'.$image->hash.'?size=md'));

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
     * @effects hero_image_bypass_marks_original
     *
     * @return void 반환값 없음
     */
    public function test_unservable_request_returns_original_with_bypass_reason(): void
    {
        $mobile = $this->bannerImage('mobile');

        $unknown = $this->get($this->api('/images/'.$mobile->hash.'?size=nope'));
        $unknown->assertOk();
        $this->assertSame('bypass:unknown-preset', $unknown->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([750, 1000], $this->dimensions($unknown));

        $larger = $this->get($this->api('/images/'.$mobile->hash.'?size=lg'));
        $larger->assertOk();
        $this->assertSame('bypass:smaller-than-preset', $larger->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([750, 1000], $this->dimensions($larger));
    }

    /**
     * 같은 서빙 경로의 영상 파일은 래스터가 아니라 원본 영상을 그대로 내고 사유만 붙인다 — 파생본을 만들지 않는다.
     *
     * @scenario thumbnail_query=video
     *
     * @effects hero_video_with_size_returns_original_video
     *
     * @return void 반환값 없음
     */
    public function test_video_with_size_returns_original_video_with_not_raster_reason(): void
    {
        $video = $this->uploadVideo();
        $plain = $this->get($this->api('/images/'.$video->hash));

        $response = $this->get($this->api('/images/'.$video->hash.'?size=md'));

        $response->assertOk();
        $this->assertSame('bypass:not-raster', $response->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame($plain->headers->get('Content-Type'), $response->headers->get('Content-Type'));
        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '영상에 파생본을 만들었다');
    }

    /**
     * 저장 전 미리보기의 임시 업로드도 해시로 공개되므로 같은 썸네일을 낸다(등록 화면 미리보기·업로더 칸).
     *
     * @scenario thumbnail_query=temp_upload
     *
     * @effects hero_temp_image_thumbnail_served
     *
     * @return void 반환값 없음
     */
    public function test_temp_upload_is_thumbnailed_for_preview(): void
    {
        $temp = $this->uploadImage($this->fullAdmin(), 'pc', ['temp_key' => (string) Str::uuid()])->assertCreated()->json('data');

        $response = $this->get($temp['url'].'?size=md');

        $response->assertOk();
        $this->assertSame('miss', $response->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([640, 213], $this->dimensions($response));
    }

    /**
     * 공개 썸네일 저장 위치가 공개 자산 디스크(public)면 302 로 파생본 직접 주소를 알려 주고, 비공개 디스크에는 쓰지 않는다.
     *
     * @scenario thumbnail_query=size_public_disk
     *
     * @effects hero_image_public_thumbnail_disk_redirects
     *
     * @return void 반환값 없음
     */
    public function test_public_asset_thumbnail_disk_redirects_to_direct_url(): void
    {
        Storage::fake('public');
        config(['core.thumbnail.disk_public' => 'public']);
        $image = $this->bannerImage('pc');

        $response = $this->get($this->api('/images/'.$image->hash.'?size=md'));

        $response->assertStatus(302);
        $this->assertStringContainsString('thumbnails/'.$this->diskName().'/'.$this->fullPath($image).'/md_', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertCount(1, Storage::disk('public')->allFiles('thumbnails'));
        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '공개 자산 디스크를 골랐는데 비공개 디스크에 썼다');
    }

    /**
     * 원본 파일만 사라진 행은 `?size=` 를 붙여도 무파라미터와 같은 404 다 — 남은 파생본으로 200 을 내면 지운 배너가 썸네일 주소로 계속 보인다.
     *
     * @scenario thumbnail_query=original_missing
     *
     * @effects hero_image_missing_original_is_404_with_size
     *
     * @return void 반환값 없음
     */
    public function test_missing_original_is_404_even_with_size(): void
    {
        $image = $this->bannerImage('pc');
        $this->get($this->api('/images/'.$image->hash.'?size=md'))->assertOk();
        $this->assertCount(1, Storage::disk('thumbnails')->allFiles(), '파생본이 먼저 만들어져야 한다');

        // 드라이버를 거치지 않고 원본만 지운다 — 파생본 무효화가 돌지 않은 상태
        $this->imageStorage()->delete($this->fullPath($image));

        $plain = $this->get($this->api('/images/'.$image->hash));
        $sized = $this->get($this->api('/images/'.$image->hash.'?size=md'));

        $plain->assertNotFound();
        $sized->assertNotFound();
        $this->assertSame($plain->json(), $sized->json());
        $this->assertNull($sized->headers->get(ThumbnailResponder::HEADER));
    }

    /**
     * 이미지를 지우면(드라이버 경유) 그 썸네일도 함께 지워진다 — 배너 이미지 교체·삭제 뒤 옛 썸네일이 남지 않는다.
     *
     * @scenario thumbnail_query=delete_purges
     *
     * @effects hero_image_delete_purges_thumbnails
     *
     * @return void 반환값 없음
     */
    public function test_deleting_the_image_purges_its_thumbnails(): void
    {
        $image = $this->bannerImage('pc');
        $this->get($this->api('/images/'.$image->hash.'?size=md'))->assertOk();
        $this->assertCount(1, Storage::disk('thumbnails')->allFiles());

        $this->assertTrue(app(BannerImageService::class)->deleteImage($image));

        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '원본을 지웠는데 썸네일이 남았다');
    }

    /**
     * 플러그인 리스너가 서빙 경로 패턴과 공개 원본 제공자를 코어 목록에 더한다(plugin.php 리스너 → 실제 훅 체인).
     * 제공자는 이 플러그인의 `images` 카테고리 래스터만 원본으로 분류하고, 기존 이미지 수·목록은 배너에 붙은 이미지만 센다.
     *
     * @scenario thumbnail_query=registration
     *
     * @effects hero_thumbnail_listener_registers_url_pattern_and_provider
     *
     * @return void 반환값 없음
     */
    public function test_listener_registers_url_pattern_and_public_source_provider(): void
    {
        $this->assertTrue(ThumbnailUrl::isThumbnailable('/api/plugins/sirsoft-hero_section/images/'.str_repeat('a', 64)));
        $this->assertSame(
            '/api/plugins/sirsoft-hero_section/images/abc?size=md',
            ThumbnailUrl::for('/api/plugins/sirsoft-hero_section/images/abc', 'md')
        );
        $this->assertFalse(ThumbnailUrl::isThumbnailable('/api/plugins/sirsoft-hero_section/hero/home_hero'));

        $attached = $this->bannerImage('pc');
        $this->uploadImage($this->fullAdmin(), 'pc', ['temp_key' => (string) Str::uuid()])->assertCreated();
        $this->uploadVideo();

        $providers = app(ThumbnailSourceProviders::class);
        $provider = $providers->all()['sirsoft-hero_section.banner_image'] ?? null;

        $this->assertInstanceOf(BannerImageThumbnailSourceProvider::class, $provider);
        $this->assertFalse($provider->gated());
        $this->assertSame('sirsoft-hero_section::messages.thumbnail.source_label', $provider->label());
        $this->assertInstanceOf(BannerImageThumbnailSourceProvider::class, $providers->classify($this->diskName(), $this->fullPath($attached)));
        $this->assertInstanceOf(BannerImageThumbnailSourceProvider::class, $providers->classify('s3', 'sirsoft-hero_section/images/2026/11/a.webp'), '다른 디스크로 옮긴 행을 분류하지 못했다');
        $this->assertNull($providers->classify('plugins', 'sirsoft-hero_section/images/2026/11/a.mp4'), '영상을 썸네일 원본으로 분류했다');
        $this->assertNull($providers->classify('plugins', 'sirsoft-hero_section/settings/a.png'));
        $this->assertNull($providers->classify('plugins', 'other-plugin/images/2026/11/a.jpg'));

        // bannerImage() 는 모바일 이미지도 올린다 — 붙은 이미지 2장(PC·모바일)만 세고 임시 업로드·영상은 세지 않는다
        $this->assertSame(2, $provider->count(), '임시 업로드·영상까지 셌다');
        $chunk = $provider->chunk(null, 1);
        $this->assertNotNull($chunk['next']);
        $this->assertCount(1, $chunk['items']);
        $this->assertSame($this->diskName(), $chunk['items'][0]->disk);
        $this->assertSame($this->fullPath($attached), $chunk['items'][0]->path);
        $this->assertFalse($chunk['items'][0]->gated);
        $this->assertSame(1920, $chunk['items'][0]->width);
        $rest = $provider->chunk($chunk['next'], 10);
        $this->assertNull($rest['next']);
        $this->assertCount(1, $rest['items']);
        $this->assertSame(750, $rest['items'][0]->width, '모바일 이미지가 따로 원본으로 잡히지 않았다');
    }

    /**
     * 제공자 목록 필터가 여러 번 불려도 같은 제공자 인스턴스를 돌려주고, 앞선 리스너가 배열이 아닌 값을 넘기면 그대로 돌려준다.
     *
     * @scenario thumbnail_query=provider_reuse
     *
     * @effects hero_thumbnail_provider_resolved_once
     *
     * @return void 반환값 없음
     */
    public function test_source_provider_is_reused_and_non_array_values_pass_through(): void
    {
        $first = (new ThumbnailRegistrationListener)->addSourceProvider([]);
        $second = (new ThumbnailRegistrationListener)->addSourceProvider(['앞선 제공자']);

        $this->assertInstanceOf(BannerImageThumbnailSourceProvider::class, $first[0]);
        $this->assertSame($first[0], $second[1]);
        $this->assertSame('앞선 제공자', $second[0]);

        $listener = new ThumbnailRegistrationListener;
        $this->assertNull($listener->addUrlPattern(null));
        $this->assertSame('invalid', $listener->addSourceProvider('invalid'));
        $this->assertSame('invalid', $listener->addPreset('invalid'));
        $this->assertSame(['앞선 규격', ThumbnailRegistrationListener::LIST_PRESET], array_map(
            fn ($row) => is_array($row) ? $row['name'] : $row,
            $listener->addPreset(['앞선 규격'])
        ));
    }

    /**
     * 썸네일을 요청했는데 원본을 낼 때(미정의 규격·원본보다 큰 규격)는 운영 환경에서도 `immutable` 을 붙이지 않는다 — 운영자가
     * 규격을 고치거나 켜면 같은 `?size=` 주소가 썸네일로 바뀌어야 하는데, 원본이 1년 불변으로 박히면 바뀌지 않는다.
     * 유지 시간도 썸네일 응답과 같은 값(코어 `cache.layout_ttl`)이다 — 1년으로 두면 브라우저가 재확인하지 않아 규격을 바꿔도
     * 그 주소가 1년 동안 원본으로 남는다. 영상(`not-raster`)도 같다. 파라미터 없는 원본 주소는 종전대로 1년 불변이다.
     *
     * @scenario thumbnail_query=bypass_cache
     *
     * @effects hero_image_bypass_original_is_not_immutable, hero_image_bypass_original_uses_thumbnail_ttl
     *
     * @return void 반환값 없음
     */
    public function test_bypassed_original_for_thumbnail_request_is_not_immutable_in_production(): void
    {
        config(['g7_settings.core.cache.layout_ttl' => 4321]);
        $mobile = $this->bannerImage('mobile');
        $video = $this->uploadVideo();
        $this->app->detectEnvironment(fn () => 'production');

        $plain = $this->get($this->api('/images/'.$mobile->hash));
        $thumbnail = $this->get($this->api('/images/'.$mobile->hash.'?size=sm'));
        $unknown = $this->get($this->api('/images/'.$mobile->hash.'?size=nope'));
        $larger = $this->get($this->api('/images/'.$mobile->hash.'?size=lg'));
        $videoBypass = $this->get($this->api('/images/'.$video->hash.'?size=md'));

        $this->assertEqualsCanonicalizing(['public', 'max-age=31536000', 'immutable'], $this->directives($plain->headers->get('Cache-Control')), '원본 주소의 1년 불변 캐시가 사라졌다');
        $this->assertStringStartsNotWith('bypass:', (string) $thumbnail->headers->get(ThumbnailResponder::HEADER), '썸네일이 나오지 않아 비교 기준이 없다');
        $this->assertContains('max-age=4321', $this->directives($thumbnail->headers->get('Cache-Control')), '썸네일 응답의 유지 시간이 코어 설정과 다르다');
        foreach (['unknown-preset' => $unknown, 'smaller-than-preset' => $larger, 'not-raster' => $videoBypass] as $reason => $response) {
            $response->assertOk();
            $this->assertSame('bypass:'.$reason, $response->headers->get(ThumbnailResponder::HEADER));
            $cacheControl = (string) $response->headers->get('Cache-Control');
            $this->assertStringNotContainsString('immutable', $cacheControl, "{$reason} 원본이 썸네일 주소에 1년 불변으로 박혔다");
            $this->assertEqualsCanonicalizing(['public', 'max-age=4321', 'must-revalidate'], $this->directives($cacheControl), "{$reason} 원본의 유지 시간이 썸네일과 다르다");
        }
    }

    /**
     * 썸네일을 요청했는데 원본을 낼 때의 유지 시간은 코어 `cache.layout_ttl` 을 따르되 원본 주소의 1년을 넘지 않는다 —
     * 설정을 1년보다 길게 잡아도 우회 원본은 `max-age=31536000` 에서 멈춘다.
     *
     * @scenario thumbnail_query=bypass_cache_cap
     *
     * @effects hero_image_bypass_original_ttl_capped_at_one_year
     *
     * @return void 반환값 없음
     */
    public function test_bypassed_original_ttl_is_capped_at_one_year(): void
    {
        config(['g7_settings.core.cache.layout_ttl' => 31536000 + 4321]);
        $image = $this->bannerImage('mobile');
        $this->app->detectEnvironment(fn () => 'production');

        $unknown = $this->get($this->api('/images/'.$image->hash.'?size=nope'));

        $unknown->assertOk();
        $this->assertSame('bypass:unknown-preset', $unknown->headers->get(ThumbnailResponder::HEADER));
        $this->assertEqualsCanonicalizing(['public', 'max-age=31536000', 'must-revalidate'], $this->directives($unknown->headers->get('Cache-Control')), '우회 원본의 유지 시간이 원본 주소의 1년을 넘었다');
    }

    /**
     * 관리 목록 규격 `sirsoft-hero_section.list`(가로 240, 가로만 맞춤)를 코어 규격 표에 확장 규격으로 등록하고, 그 이름으로 서빙한다.
     * 목록 칸(96×32)·순서 변경 창(64×24)은 2배 화면에서도 240 이면 넉넉하고, 정사각 자르기(sm)처럼 배너 가운데만 남지 않는다.
     * 숫자 요청(`?w=`)·본문 srcset 후보에는 끼어들지 않는다.
     *
     * @scenario thumbnail_query=list_preset
     *
     * @effects hero_list_preset_registered_and_served
     *
     * @return void 반환값 없음
     */
    public function test_list_preset_is_registered_as_extension_preset_and_served(): void
    {
        $registry = app(ThumbnailPresetRegistry::class);
        $spec = $registry->find(ThumbnailRegistrationListener::LIST_PRESET);

        $this->assertSame('sirsoft-hero_section.list', ThumbnailRegistrationListener::LIST_PRESET);
        $this->assertInstanceOf(ThumbnailSpec::class, $spec, '확장 규격이 등록되지 않았다');
        $this->assertSame('sirsoft-hero_section', $spec->source);
        $this->assertTrue($spec->locked);
        $this->assertSame(240, $spec->width);
        $this->assertSame(ThumbnailSpec::FIT_WIDTH, $spec->fit);
        $this->assertFalse($spec->numeric, '목록 규격이 숫자 요청 대상으로 끼어들었다');

        $image = $this->bannerImage('pc');
        $response = $this->get($this->api('/images/'.$image->hash.'?size='.ThumbnailRegistrationListener::LIST_PRESET));

        $response->assertOk();
        $this->assertSame('miss', $response->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([240, 80], $this->dimensions($response));
    }

    /**
     * 관리 목록 규격은 사람이 읽는 이름(다국어 키)을 함께 등록해, 환경설정 업로드 탭 「썸네일 규격」 표가 식별자 대신 그 이름을 보인다.
     * 코어가 키를 로케일마다 풀어 표 행의 `label` 맵으로 싣는다. 이름은 결과 그림과 무관해 지문·저장 행에 들지 않는다.
     *
     * @scenario thumbnail_query=list_preset
     *
     * @effects hero_list_preset_has_localized_name
     *
     * @return void 반환값 없음
     */
    public function test_list_preset_carries_localized_name_into_settings_table(): void
    {
        config(['app.translatable_locales' => ['ko', 'en']]);
        app(ThumbnailPresetRegistry::class)->flush();

        $registered = (new ThumbnailRegistrationListener)->addPreset([])[0];
        $this->assertSame('sirsoft-hero_section::messages.thumbnail.list_preset_label', $registered['label']);

        $rows = array_column(app(ThumbnailPresetSettings::class)->presentRows(), null, 'name');
        $row = $rows[ThumbnailRegistrationListener::LIST_PRESET] ?? null;

        $this->assertIsArray($row, '표에 목록 규격 행이 없다');
        $this->assertSame('extension', $row['kind']);
        $this->assertSame(['ko' => '배너 목록', 'en' => 'Banner list'], $row['label']);
    }

    /**
     * 관리자 API 로 배너를 등록하고 그 기기 이미지 행을 반환합니다(PC 1920×640, 모바일 750×1000).
     *
     * @param  string  $device  기기 (pc, mobile)
     * @return BannerImage 이미지 행
     */
    private function bannerImage(string $device): BannerImage
    {
        $id = $this->createBannerViaApi($this->fullAdmin(), [], mobile: true)->assertCreated()->json('data.id');

        return BannerImage::query()->where('banner_id', $id)->where('device', $device)->where('kind', 'image')->firstOrFail();
    }

    /**
     * Cache-Control 값을 지시어 목록으로 나눕니다(순서는 응답 종류마다 다르다).
     *
     * @param  string|null  $header  Cache-Control 값
     * @return array<int, string> 지시어 목록
     */
    private function directives(?string $header): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $header))));
    }

    /**
     * 임시 묶음에 MP4 영상을 올리고 그 행을 반환합니다(내용 검사를 지나도록 MP4 머리 상자를 쓴다 — BannerMediaTest 와 같은 바이트).
     *
     * @return BannerImage 영상 행
     */
    private function uploadVideo(): BannerImage
    {
        $path = tempnam(sys_get_temp_dir(), 'hero');
        file_put_contents($path, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 2000));
        $file = new UploadedFile($path, 'clip.mp4', null, null, true);

        $data = $this->actingAs($this->fullAdmin())->post($this->api('/admin/banner-images'), [
            'file' => $file, 'device' => 'pc', 'kind' => 'video', 'temp_key' => (string) Str::uuid(),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        return BannerImage::query()->findOrFail($data['id']);
    }

    /**
     * 이미지 서비스가 쓰는 스토리지 드라이버의 디스크 이름 (설치 여부에 따라 plugins 또는 local).
     *
     * @return string 디스크 이름
     */
    private function diskName(): string
    {
        $service = $this->app->make(BannerImageService::class);
        /** @var StorageInterface $storage */
        $storage = (new \ReflectionProperty($service, 'storage'))->getValue($service);

        return $storage->getDisk();
    }

    /**
     * 행의 디스크 위 전체 경로 (`sirsoft-hero_section/images/{Y/m/…}`).
     *
     * @param  BannerImage  $image  이미지 행
     * @return string 전체 경로
     */
    private function fullPath(BannerImage $image): string
    {
        return 'sirsoft-hero_section/images/'.$image->path;
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
}
