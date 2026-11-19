<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Public;

use App\Extension\Storage\PluginStorageDriver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 공개 배너 이미지 응답 테스트 (매니페스트 public-hero.yaml — 이미지 케이스)
 */
class PublicBannerImageTest extends PluginTestCase
{
    /**
     * 테스트 준비 — 이미지 파일은 가짜 디스크에 쓴다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisks();
    }

    /**
     * 배너에 연결된 래스터 이미지는 인라인 + nosniff + 긴 캐시로 나간다.
     *
     * @scenario surface=image_streams_inline_raster
     *
     * @effects inline_media_policy_applied
     *
     * @return void 반환값 없음
     */
    public function test_streams_inline_raster(): void
    {
        $id = $this->createBannerViaApi($this->fullAdmin())->assertCreated()->json('data.id');
        $hash = BannerImage::query()->where('banner_id', $id)->value('hash');

        // 긴 캐시는 운영 환경 정책이다(코어 파일 서빙 헬퍼 — 개발 환경은 no-cache)
        $this->app->detectEnvironment(fn () => 'production');
        $response = $this->get($this->api('/images/'.$hash));

        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * 모르는 해시는 404 다.
     *
     * @scenario surface=image_unknown_hash_returns_404
     *
     * @return void 반환값 없음
     */
    public function test_unknown_hash_returns_404(): void
    {
        $this->get($this->api('/images/'.Str::random(64)))->assertNotFound();
    }

    /**
     * 아직 배너에 붙지 않은 임시 이미지도 해시로 내보낸다 — 등록·수정 화면 업로더 썸네일과 미리보기가 저장 전에 그린다.
     *
     * @scenario surface=image_temp_hash_streams_for_preview
     *
     * @return void 반환값 없음
     */
    public function test_temp_hash_streams_for_preview(): void
    {
        $temp = $this->uploadImage($this->fullAdmin(), 'pc', ['temp_key' => (string) Str::uuid()])->assertCreated()->json('data');

        $this->get($temp['url'])->assertOk();
    }

    /**
     * 공개 이미지는 코어 파일 서빙 계약을 따른다 — 운영 환경에서 해시 주소라 1년 불변 캐시, 검증자(ETag)로 304 재검증하고
     * 304 도 200 과 같은 캐시 정책과 nosniff 를 싣는다. 개발 환경은 코어와 같이 캐시하지 않는다(no-cache).
     *
     * @scenario surface=image_follows_core_file_serving_contract
     *
     * @effects file_serving_contract_matches_core
     *
     * @return void 반환값 없음
     */
    public function test_image_follows_core_file_serving_contract(): void
    {
        $url = $this->api('/images/'.$this->publicHash());

        $this->assertContains('no-cache', $this->directives($this->get($url)->assertOk()->headers->get('Cache-Control')));

        $this->app->detectEnvironment(fn () => 'production');
        $first = $this->get($url)->assertOk();
        $this->assertEqualsCanonicalizing(['public', 'max-age=31536000', 'immutable'], $this->directives($first->headers->get('Cache-Control')));
        $etag = (string) $first->headers->get('ETag');
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $etag);

        $again = $this->get($url, ['If-None-Match' => $etag]);
        $again->assertStatus(304);
        $this->assertEqualsCanonicalizing(['public', 'max-age=31536000', 'immutable'], $this->directives($again->headers->get('Cache-Control')));
        $this->assertSame('nosniff', $again->headers->get('X-Content-Type-Options'));
        $this->assertSame($etag, $again->headers->get('ETag'));
    }

    /**
     * 공개 이미지 하나를 만들고 그 해시를 반환합니다.
     *
     * @return string 해시
     */
    private function publicHash(): string
    {
        $id = $this->createBannerViaApi($this->fullAdmin())->assertCreated()->json('data.id');

        return (string) BannerImage::query()->where('banner_id', $id)->value('hash');
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
     * 공개 자산 디스크(직접 URL) 행도 공개 API 주소로 계속 받을 수 있다 — 썸네일·같은 출처 자리와 옛 캐시 주소 호환 (#878).
     *
     * @scenario surface=serve
     *
     * @effects hero_serve_and_delete_follow_row_disk
     *
     * @return void 반환값 없음
     */
    public function test_cdn_disk_row_still_served_by_api(): void
    {
        Config::set('filesystems.disks.fake_cdn', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/fake_cdn'),
            'url' => 'https://cdn.test/assets',
        ]);
        $path = '2026/11/'.Str::random(16).'.jpg';
        $fake = UploadedFile::fake()->image('cdn.jpg', 20, 20);
        $jpeg = (string) file_get_contents($fake->getRealPath());
        $driver = new PluginStorageDriver('sirsoft-hero_section', 'fake_cdn');
        $driver->put('images', $path, $jpeg);
        $image = BannerImage::factory()->create(['temp_key' => (string) Str::uuid(), 'disk' => 'fake_cdn', 'path' => $path, 'size' => strlen($jpeg)]);

        $response = $this->get($this->api('/images/'.$image->hash));

        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame($jpeg, $response->streamedContent());
        $driver->delete('images', $path);
    }

    /**
     * 웹서버 위임(X-Sendfile)을 켜면 로컬 영상은 헤더만 PHP 가 내고 본문은 웹서버가 보낸다 — 1년 불변 캐시는 그대로 (#878, D5).
     *
     * @scenario surface=serve
     *
     * @effects hero_video_delegated_to_web_server
     *
     * @return void 반환값 없음
     */
    public function test_local_video_is_delegated_to_web_server_when_enabled(): void
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'hsv');
        file_put_contents($tmp, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 2000));
        $file = new UploadedFile($tmp, 'clip.mp4', 'video/mp4', null, true);
        $data = $this->actingAs($this->fullAdmin())
            ->post($this->api('/admin/banner-images'), ['file' => $file, 'device' => 'pc', 'kind' => 'video', 'temp_key' => (string) Str::uuid()], ['Accept' => 'application/json'])
            ->assertCreated()->json('data');

        $this->app->detectEnvironment(fn () => 'production');

        Config::set('core.file_serving.delegation', 'none');
        $off = $this->get($data['url'])->assertOk();
        $this->assertNull($off->headers->get('X-Sendfile'));

        Config::set('core.file_serving.delegation', 'x-sendfile');
        try {
            $on = $this->get($data['url'], ['Range' => 'bytes=0-9']);
            $on->assertOk();
            $this->assertNotEmpty($on->headers->get('X-Sendfile'));
            $this->assertStringContainsString('immutable', (string) $on->headers->get('Cache-Control'));
            ob_start();
            $on->baseResponse->sendContent();
            $this->assertSame('', ob_get_clean(), '위임하면 본문은 웹서버가 보낸다');
        } finally {
            Config::set('core.file_serving.delegation', 'none');
        }
    }
}
