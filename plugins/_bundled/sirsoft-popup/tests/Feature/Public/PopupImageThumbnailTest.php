<?php

namespace Plugins\Sirsoft\Popup\Tests\Feature\Public;

use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailResponder;
use App\Thumbnails\ThumbnailSourceProviders;
use App\Thumbnails\ThumbnailUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\Popup\Listeners\ThumbnailRegistrationListener;
use Plugins\Sirsoft\Popup\Models\PopupImage;
use Plugins\Sirsoft\Popup\Services\PopupImageService;
use Plugins\Sirsoft\Popup\Services\PopupImageThumbnailSourceProvider;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;
use Tests\Unit\Thumbnails\Support\TestImages;

/**
 * 팝업 이미지 서빙의 썸네일 계약 (`?size=`·`?w=`, 매니페스트 image-thumbnail.yaml)
 *
 * 팝업 이미지(`/api/plugins/sirsoft-popup/images/{hash}`)는 해시만 알면 누구나 받는 공개 원본이다. PC·모바일 이미지를 따로
 * 올리지만 둘 다 각자의 원본이라 각자 썸네일 경로를 탄다. 컨트롤러가 해시 조회(게이트) 바로 뒤·원본 응답 바로 앞에서 코어
 * `ThumbnailResponder` 를 부르고, 낼 수 없으면 원본에 `X-G7-Thumbnail: bypass:{사유}` 를 붙인다. 파라미터가 없으면 응답은 이전과 같다.
 */
class PopupImageThumbnailTest extends PluginTestCase
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
     * @effects popup_image_without_size_returns_original_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_request_without_size_returns_the_original_unchanged(): void
    {
        $image = $this->popupImage('pc');

        $response = $this->get($this->api('/images/'.$image->hash));

        $response->assertOk();
        $this->assertSame([480, 520], $this->dimensions($response));
        $this->assertNull($response->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame($this->imageStorage()->get($this->fullPath($image)), $response->streamedContent());
        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '파라미터 없는 요청이 파생본을 만들었다');
    }

    /**
     * `?size=sm` 은 자르기 160×160 파생본이고, 첫 요청은 miss·다음 요청은 hit 이다. nosniff 를 싣는다.
     *
     * @scenario thumbnail_query=size
     *
     * @effects popup_image_size_returns_preset_dimensions, popup_image_thumbnail_is_hardened
     *
     * @return void 반환값 없음
     */
    public function test_size_returns_derivative_with_preset_dimensions_and_hardening(): void
    {
        $image = $this->popupImage('pc');

        $first = $this->get($this->api('/images/'.$image->hash.'?size=sm'));

        $first->assertOk();
        $this->assertSame('miss', $first->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([160, 160], $this->dimensions($first));
        $this->assertSame('nosniff', $first->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('image/jpeg', (string) $first->headers->get('Content-Type'));

        $files = Storage::disk('thumbnails')->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('thumbnails/'.$this->imageStorageDriver()->getDisk().'/'.$this->fullPath($image).'/sm_', $files[0]);

        $second = $this->get($this->api('/images/'.$image->hash.'?size=sm'));
        $second->assertOk();
        $this->assertSame('hit', $second->headers->get(ThumbnailResponder::HEADER));
    }

    /**
     * 모바일 이미지도 따로 올린 원본이라 각자 썸네일을 낸다. `?w=700` 은 숫자 요청 대상 중 가로 ≥ 700 최소(lg 1280)인데
     * 모바일 원본(750)보다 커서 원본을 내고, `?w=300` 은 md(640)로 올려 맞춘다.
     *
     * @scenario thumbnail_query=mobile_original
     *
     * @effects popup_mobile_image_has_own_thumbnail, popup_image_w_rounds_up_to_numeric_preset
     *
     * @return void 반환값 없음
     */
    public function test_mobile_image_is_its_own_original_and_w_rounds_up(): void
    {
        $mobile = $this->popupImage('mobile');

        $md = $this->get($this->api('/images/'.$mobile->hash.'?w=300'));
        $md->assertOk();
        $this->assertSame('miss', $md->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([640, 768], $this->dimensions($md));

        $lg = $this->get($this->api('/images/'.$mobile->hash.'?w=700'));
        $lg->assertOk();
        $this->assertSame('bypass:smaller-than-preset', $lg->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([750, 900], $this->dimensions($lg));
    }

    /**
     * 운영 환경에서 공개 원본의 썸네일은 공유 캐시가 저장할 수 있는 `public` 이다.
     *
     * @scenario thumbnail_query=size_production_cache
     *
     * @effects popup_image_thumbnail_cache_is_public
     *
     * @return void 반환값 없음
     */
    public function test_thumbnail_of_public_original_is_publicly_cacheable_in_production(): void
    {
        $image = $this->popupImage('pc');
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->get($this->api('/images/'.$image->hash.'?size=sm'));

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
     * @effects popup_image_bypass_marks_original
     *
     * @return void 반환값 없음
     */
    public function test_unservable_request_returns_original_with_bypass_reason(): void
    {
        $image = $this->popupImage('pc');

        $unknown = $this->get($this->api('/images/'.$image->hash.'?size=nope'));
        $unknown->assertOk();
        $this->assertSame('bypass:unknown-preset', $unknown->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([480, 520], $this->dimensions($unknown));

        $larger = $this->get($this->api('/images/'.$image->hash.'?size=md'));
        $larger->assertOk();
        $this->assertSame('bypass:smaller-than-preset', $larger->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([480, 520], $this->dimensions($larger));
    }

    /**
     * 저장 전 미리보기의 임시 업로드도 해시로 공개되므로 같은 썸네일을 낸다(등록 화면 미리보기·업로더 칸).
     *
     * @scenario thumbnail_query=temp_upload
     *
     * @effects popup_temp_image_thumbnail_served
     *
     * @return void 반환값 없음
     */
    public function test_temp_upload_is_thumbnailed_for_preview(): void
    {
        $temp = $this->uploadImage($this->fullAdmin(), 'pc', (string) Str::uuid())->assertCreated()->json('data');

        $response = $this->get($temp['url'].'?size=sm');

        $response->assertOk();
        $this->assertSame('miss', $response->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([160, 160], $this->dimensions($response));
    }

    /**
     * 공개 썸네일 저장 위치가 공개 자산 디스크(public)면 302 로 파생본 직접 주소를 알려 주고, 비공개 디스크에는 쓰지 않는다.
     *
     * @scenario thumbnail_query=size_public_disk
     *
     * @effects popup_image_public_thumbnail_disk_redirects
     *
     * @return void 반환값 없음
     */
    public function test_public_asset_thumbnail_disk_redirects_to_direct_url(): void
    {
        Storage::fake('public');
        config(['core.thumbnail.disk_public' => 'public']);
        $image = $this->popupImage('pc');

        $response = $this->get($this->api('/images/'.$image->hash.'?size=sm'));

        $response->assertStatus(302);
        $this->assertStringContainsString('thumbnails/'.$this->imageStorageDriver()->getDisk().'/'.$this->fullPath($image).'/sm_', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertCount(1, Storage::disk('public')->allFiles('thumbnails'));
        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '공개 자산 디스크를 골랐는데 비공개 디스크에 썼다');
    }

    /**
     * 원본 파일만 사라진 행은 `?size=` 를 붙여도 무파라미터와 같은 404 다 — 남은 파생본으로 200 을 내면 지운 팝업이 썸네일 주소로 계속 보인다.
     *
     * @scenario thumbnail_query=original_missing
     *
     * @effects popup_image_missing_original_is_404_with_size
     *
     * @return void 반환값 없음
     */
    public function test_missing_original_is_404_even_with_size(): void
    {
        $image = $this->popupImage('pc');
        $this->get($this->api('/images/'.$image->hash.'?size=sm'))->assertOk();
        $this->assertCount(1, Storage::disk('thumbnails')->allFiles(), '파생본이 먼저 만들어져야 한다');

        // 드라이버를 거치지 않고 원본만 지운다 — 파생본 무효화가 돌지 않은 상태
        $this->imageStorage()->delete($this->fullPath($image));

        $plain = $this->get($this->api('/images/'.$image->hash));
        $sized = $this->get($this->api('/images/'.$image->hash.'?size=sm'));

        $plain->assertNotFound();
        $sized->assertNotFound();
        $this->assertSame($plain->json(), $sized->json());
        $this->assertNull($sized->headers->get(ThumbnailResponder::HEADER));
    }

    /**
     * 이미지를 지우면(드라이버 경유) 그 썸네일도 함께 지워진다 — 팝업 이미지 교체·삭제 뒤 옛 썸네일이 남지 않는다.
     *
     * @scenario thumbnail_query=delete_purges
     *
     * @effects popup_image_delete_purges_thumbnails
     *
     * @return void 반환값 없음
     */
    public function test_deleting_the_image_purges_its_thumbnails(): void
    {
        $image = $this->popupImage('pc');
        $this->get($this->api('/images/'.$image->hash.'?size=sm'))->assertOk();
        $this->assertCount(1, Storage::disk('thumbnails')->allFiles());

        $this->assertTrue(app(PopupImageService::class)->deleteImage($image));

        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '원본을 지웠는데 썸네일이 남았다');
    }

    /**
     * 플러그인 리스너가 서빙 경로 패턴과 공개 원본 제공자를 코어 목록에 더한다(plugin.php 리스너 → 실제 훅 체인).
     * 제공자는 이 플러그인의 `images` 카테고리 래스터만 원본으로 분류하고, 기존 이미지 수·목록은 팝업에 붙은 이미지만 센다.
     *
     * @scenario thumbnail_query=registration
     *
     * @effects popup_thumbnail_listener_registers_url_pattern_and_provider
     *
     * @return void 반환값 없음
     */
    public function test_listener_registers_url_pattern_and_public_source_provider(): void
    {
        $this->assertTrue(ThumbnailUrl::isThumbnailable('/api/plugins/sirsoft-popup/images/'.str_repeat('a', 64)));
        $this->assertSame(
            '/api/plugins/sirsoft-popup/images/abc?size=sm',
            ThumbnailUrl::for('/api/plugins/sirsoft-popup/images/abc', 'sm')
        );
        $this->assertFalse(ThumbnailUrl::isThumbnailable('/api/plugins/sirsoft-popup/popups'));

        $attached = $this->popupImage('pc');
        $this->uploadImage($this->fullAdmin(), 'pc', (string) Str::uuid())->assertCreated();
        $disk = $this->imageStorageDriver()->getDisk();

        $providers = app(ThumbnailSourceProviders::class);
        $provider = $providers->all()['sirsoft-popup.popup_image'] ?? null;

        $this->assertInstanceOf(PopupImageThumbnailSourceProvider::class, $provider);
        $this->assertFalse($provider->gated());
        $this->assertSame('sirsoft-popup::messages.thumbnail.source_label', $provider->label());
        $this->assertInstanceOf(PopupImageThumbnailSourceProvider::class, $providers->classify($disk, $this->fullPath($attached)));
        $this->assertInstanceOf(PopupImageThumbnailSourceProvider::class, $providers->classify('s3', 'sirsoft-popup/images/2026/11/a.gif'), '다른 디스크로 옮긴 행을 분류하지 못했다');
        $this->assertNull($providers->classify('plugins', 'sirsoft-popup/settings/a.png'));
        $this->assertNull($providers->classify('plugins', 'other-plugin/images/2026/11/a.jpg'));

        // createPopupViaApi 는 모바일 이미지도 올린다 — 붙은 이미지 2장만 세고 임시 업로드는 세지 않는다
        $this->assertSame(2, $provider->count(), '임시 업로드까지 셌다');
        $chunk = $provider->chunk(null, 1);
        $this->assertNotNull($chunk['next']);
        $this->assertCount(1, $chunk['items']);
        $this->assertSame($disk, $chunk['items'][0]->disk);
        $this->assertSame($this->fullPath($attached), $chunk['items'][0]->path);
        $this->assertFalse($chunk['items'][0]->gated);
        $this->assertSame(480, $chunk['items'][0]->width);
        $rest = $provider->chunk($chunk['next'], 10);
        $this->assertNull($rest['next']);
        $this->assertCount(1, $rest['items']);
    }

    /**
     * 제공자 목록 필터가 여러 번 불려도 같은 제공자 인스턴스를 돌려주고, 앞선 리스너가 배열이 아닌 값을 넘기면 그대로 돌려준다.
     *
     * @scenario thumbnail_query=provider_reuse
     *
     * @effects popup_thumbnail_provider_resolved_once
     *
     * @return void 반환값 없음
     */
    public function test_source_provider_is_reused_and_non_array_values_pass_through(): void
    {
        $first = (new ThumbnailRegistrationListener)->addSourceProvider([]);
        $second = (new ThumbnailRegistrationListener)->addSourceProvider(['앞선 제공자']);

        $this->assertInstanceOf(PopupImageThumbnailSourceProvider::class, $first[0]);
        $this->assertSame($first[0], $second[1]);
        $this->assertSame('앞선 제공자', $second[0]);

        $listener = new ThumbnailRegistrationListener;
        $this->assertNull($listener->addUrlPattern(null));
        $this->assertSame('invalid', $listener->addSourceProvider('invalid'));
    }

    /**
     * 썸네일을 요청했는데 원본을 낼 때(미정의 규격·원본보다 큰 규격)는 운영 환경에서도 `immutable` 을 붙이지 않는다 — 운영자가
     * 규격을 고치거나 켜면 같은 `?size=` 주소가 썸네일로 바뀌어야 하는데, 원본이 1년 불변으로 박히면 바뀌지 않는다.
     * 유지 시간도 썸네일 응답과 같은 값(코어 `cache.layout_ttl`)이다 — 1년으로 두면 브라우저가 재확인하지 않아 규격을 바꿔도
     * 그 주소가 1년 동안 원본으로 남는다. 파라미터 없는 원본 주소는 종전대로 1년 불변이다.
     *
     * @scenario thumbnail_query=bypass_cache
     *
     * @effects popup_image_bypass_original_is_not_immutable, popup_image_bypass_original_uses_thumbnail_ttl
     *
     * @return void 반환값 없음
     */
    public function test_bypassed_original_for_thumbnail_request_is_not_immutable_in_production(): void
    {
        config(['g7_settings.core.cache.layout_ttl' => 4321]);
        $image = $this->popupImage('pc');
        $this->app->detectEnvironment(fn () => 'production');

        $plain = $this->get($this->api('/images/'.$image->hash));
        $thumbnail = $this->get($this->api('/images/'.$image->hash.'?size=sm'));
        $unknown = $this->get($this->api('/images/'.$image->hash.'?size=nope'));
        $larger = $this->get($this->api('/images/'.$image->hash.'?size=md'));

        $this->assertEqualsCanonicalizing(['public', 'max-age=31536000', 'immutable'], $this->directives($plain->headers->get('Cache-Control')), '원본 주소의 1년 불변 캐시가 사라졌다');
        $this->assertStringStartsNotWith('bypass:', (string) $thumbnail->headers->get(ThumbnailResponder::HEADER), '썸네일이 나오지 않아 비교 기준이 없다');
        $this->assertContains('max-age=4321', $this->directives($thumbnail->headers->get('Cache-Control')), '썸네일 응답의 유지 시간이 코어 설정과 다르다');
        foreach (['unknown-preset' => $unknown, 'smaller-than-preset' => $larger] as $reason => $response) {
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
     * @effects popup_image_bypass_original_ttl_capped_at_one_year
     *
     * @return void 반환값 없음
     */
    public function test_bypassed_original_ttl_is_capped_at_one_year(): void
    {
        config(['g7_settings.core.cache.layout_ttl' => 31536000 + 4321]);
        $image = $this->popupImage('pc');
        $this->app->detectEnvironment(fn () => 'production');

        $unknown = $this->get($this->api('/images/'.$image->hash.'?size=nope'));

        $unknown->assertOk();
        $this->assertSame('bypass:unknown-preset', $unknown->headers->get(ThumbnailResponder::HEADER));
        $this->assertEqualsCanonicalizing(['public', 'max-age=31536000', 'must-revalidate'], $this->directives($unknown->headers->get('Cache-Control')), '우회 원본의 유지 시간이 원본 주소의 1년을 넘었다');
    }

    /**
     * 움직이는 GIF 는 「움직이는 이미지는 원본 사용」(기본 켬)이면 `?size=sm` 에도 원본 바이트 그대로 + `bypass:animated` 다 —
     * 줄이면 첫 장면만 남아 팝업이 멈춘다. 파생본을 만들지 않고, 원본 주소처럼 1년 불변으로 박지도 않는다(설정을 끄면 바뀌어야 한다).
     *
     * @scenario thumbnail_query=animated_gif
     *
     * @effects popup_animated_gif_returns_original_with_reason
     *
     * @return void 반환값 없음
     */
    public function test_animated_gif_returns_original_bytes_with_animated_reason(): void
    {
        config(['core.thumbnail.animated_original' => true, 'g7_settings.core.cache.layout_ttl' => 4321]);
        $path = TestImages::fullFrameGif(3);
        $file = new UploadedFile($path, 'animated.gif', 'image/gif', null, true);
        $data = $this->uploadImage($this->fullAdmin(), 'pc', (string) Str::uuid(), $file)->assertCreated()->json('data');
        $image = PopupImage::query()->findOrFail($data['id']);
        $this->assertSame('image/gif', $image->mime);
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->get($this->api('/images/'.$image->hash.'?size=sm'));

        $response->assertOk();
        $this->assertSame('bypass:animated', $response->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame($this->imageStorage()->get($this->fullPath($image)), $response->streamedContent(), '움직이는 GIF 원본이 아닌 것을 냈다');
        $this->assertSame((string) file_get_contents($path), $response->streamedContent(), '업로드가 GIF 를 다시 저장해 움직임을 잃었다');
        $this->assertEqualsCanonicalizing(['public', 'max-age=4321', 'must-revalidate'], $this->directives($response->headers->get('Cache-Control')), '움직이는 GIF 원본이 썸네일 주소에 썸네일과 다른 유지 시간으로 나갔다');
        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '움직이는 GIF 에 파생본을 만들었다');
    }

    /**
     * 관리자 API 로 팝업을 등록하고 그 기기 이미지 행을 반환합니다(PC 480×520, 모바일 750×900).
     *
     * @param  string  $device  기기 (pc, mobile)
     * @return PopupImage 이미지 행
     */
    private function popupImage(string $device): PopupImage
    {
        $id = $this->createPopupViaApi($this->fullAdmin(), [], mobile: true)->assertCreated()->json('data.id');

        return PopupImage::query()->where('popup_id', $id)->where('device', $device)->firstOrFail();
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
     * 행의 디스크 위 전체 경로 (`sirsoft-popup/images/{Y/m/…}`).
     *
     * @param  PopupImage  $image  이미지 행
     * @return string 전체 경로
     */
    private function fullPath(PopupImage $image): string
    {
        return 'sirsoft-popup/images/'.$image->path;
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
