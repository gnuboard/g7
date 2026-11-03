<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 배너 미디어(유튜브·영상 파일) 테스트 (매니페스트 hero-media.yaml)
 *
 * 영상 파일은 이미지처럼 임시 묶음에 올린 뒤 등록·수정 「저장」 때 붙고, 유튜브는 주소를 받아 영상 ID 만 저장한다.
 * 어느 종류든 PC 이미지는 대표 이미지로 필수다. 공개 영상은 범위 요청(Range)을 받는다 — iOS Safari 재생 조건.
 */
class BannerMediaTest extends PluginTestCase
{
    /**
     * 유효한 유튜브 영상 ID
     */
    private const YT = 'dQw4w9WgXcQ';

    /**
     * 관리자
     */
    private User $admin;

    /**
     * 테스트 준비 — 파일은 가짜 디스크에 쓴다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisks();
        $this->admin = $this->fullAdmin();
    }

    /**
     * MP4·WEBM 영상은 영상 종류로 올라가고 가로·세로를 재지 않으며, 업로더에는 이미지가 아닌 파일로 보인다.
     *
     * @scenario surface=video_upload_accepted
     *
     * @return void 반환값 없음
     */
    public function test_video_upload_accepted(): void
    {
        foreach (['clip.mp4' => 'video/mp4', 'clip.webm' => 'video/webm'] as $name => $mime) {
            $data = $this->uploadVideo('pc', (string) Str::uuid(), $this->videoFile($name))->assertCreated()->json('data');

            $this->assertSame('video', $data['kind']);
            $this->assertFalse($data['is_image']);
            $this->assertSame($mime, $data['mime_type']);
            $this->assertNull($data['width']);
            $this->assertTrue($this->imageStorage()->exists('sirsoft-hero_section/images/'.BannerImage::query()->find($data['id'])->path));
        }
    }

    /**
     * 확장자만 영상인 HTML, 이미지 칸에 올린 영상, 영상 칸에 올린 이미지는 거절한다.
     *
     * @scenario surface=video_upload_rejects_disguised
     *
     * @return void 반환값 없음
     */
    public function test_video_upload_rejects_disguised(): void
    {
        $tempKey = (string) Str::uuid();

        $this->uploadVideo('pc', $tempKey, $this->realFile('fake.mp4', '<html><script>alert(1)</script></html>'))
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->uploadImage($this->admin, 'pc', ['temp_key' => $tempKey], $this->videoFile('clip.mp4'))
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->uploadVideo('pc', $tempKey, UploadedFile::fake()->image('pc.jpg', 1920, 640))
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame(0, BannerImage::query()->where('temp_key', $tempKey)->count());
    }

    /**
     * 영상 용량 한도는 이미지와 따로 영상 설정(`max_video_upload_mb`)을 따른다.
     *
     * @scenario surface=video_upload_size_limit
     *
     * @return void 반환값 없음
     */
    public function test_video_upload_size_limit(): void
    {
        $this->setPluginSettings(['max_upload_mb' => 1, 'max_video_upload_mb' => 3]);
        $tempKey = (string) Str::uuid();

        // 이미지 한도(1MB)보다 크지만 영상 한도(3MB) 안이면 받는다
        $this->uploadVideo('pc', $tempKey, UploadedFile::fake()->create('ok.mp4', 2048, 'video/mp4'))->assertCreated();
        $this->uploadVideo('pc', $tempKey, UploadedFile::fake()->create('big.mp4', 4096, 'video/mp4'))
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    /**
     * 같은 묶음의 PC 이미지와 PC 영상은 서로를 바꾸지 않는다 — 기기·종류마다 하나씩이다.
     *
     * @scenario surface=video_and_image_slots_independent
     *
     * @return void 반환값 없음
     */
    public function test_video_and_image_slots_independent(): void
    {
        $tempKey = (string) Str::uuid();
        $image = $this->uploadImage($this->admin, 'pc', ['temp_key' => $tempKey])->assertCreated()->json('data.id');
        $video = $this->uploadVideo('pc', $tempKey)->assertCreated()->json('data.id');

        $this->assertEqualsCanonicalizing([$image, $video], BannerImage::query()->where('temp_key', $tempKey)->pluck('id')->all());
    }

    /**
     * 유튜브 배너는 주소창·공유·쇼츠 주소를 받아 영상 ID 만 저장하고, 공개 응답에 영상 ID 를 싣는다.
     *
     * @scenario surface=youtube_store_saves_id
     *
     * @effects public_hero_media_fields
     *
     * @return void 반환값 없음
     */
    public function test_youtube_store_saves_id(): void
    {
        foreach (['https://www.youtube.com/watch?v='.self::YT.'&t=3s', 'https://youtu.be/'.self::YT, 'https://www.youtube.com/shorts/'.self::YT] as $url) {
            $data = $this->createBannerViaApi($this->admin, ['media_type' => 'youtube', 'youtube_url' => $url])->assertCreated()->json('data');

            $this->assertSame('youtube', $data['media_type']);
            $this->assertSame(self::YT, $data['youtube_id']);
            $this->assertSame('https://www.youtube.com/watch?v='.self::YT, $data['youtube_url']);
        }

        $public = $this->getJson($this->api('/hero?position=home_hero'))->assertOk()->json('data.banners.0');
        $this->assertSame('youtube', $public['media_type']);
        $this->assertSame(self::YT, $public['youtube_id']);
        $this->assertNull($public['videos']);
        $this->assertNotNull($public['images']['pc']);
    }

    /**
     * 유튜브가 아닌 주소·모양이 틀린 영상 ID·빈 주소는 거절하고, PC 이미지(대표 이미지)는 유튜브여도 필수다.
     *
     * @scenario surface=youtube_store_rejects_invalid
     *
     * @return void 반환값 없음
     */
    public function test_youtube_store_rejects_invalid(): void
    {
        foreach (['https://evil.example/watch?v='.self::YT, 'https://youtube.com.evil.example/watch?v='.self::YT, 'https://www.youtube.com/watch?v=short', 'javascript:alert(1)', ''] as $url) {
            $this->createBannerViaApi($this->admin, ['media_type' => 'youtube', 'youtube_url' => $url])
                ->assertStatus(422)->assertJsonValidationErrors('youtube_url');
        }

        $this->actingAs($this->admin)->postJson($this->api('/admin/banners'), $this->bannerPayload(['media_type' => 'youtube', 'youtube_url' => 'https://youtu.be/'.self::YT]) + ['temp_key' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonValidationErrors('temp_key');
        $this->assertSame(0, Banner::query()->count());
    }

    /**
     * 영상 파일 배너는 PC 영상이 있어야 저장되고, 저장하면 임시 묶음의 영상이 붙어 공개 응답에 나간다.
     *
     * @scenario surface=video_store_requires_pc_video
     *
     * @effects public_hero_media_fields
     *
     * @return void 반환값 없음
     */
    public function test_video_store_requires_pc_video(): void
    {
        $this->createBannerViaApi($this->admin, ['media_type' => 'video'])
            ->assertStatus(422)->assertJsonValidationErrors('pc_video');

        $tempKey = (string) Str::uuid();
        $this->uploadImage($this->admin, 'pc', ['temp_key' => $tempKey])->assertCreated();
        $pcVideo = $this->uploadVideo('pc', $tempKey)->assertCreated()->json('data.id');
        $mobileVideo = $this->uploadVideo('mobile', $tempKey)->assertCreated()->json('data.id');

        $data = $this->actingAs($this->admin)->postJson($this->api('/admin/banners'), $this->bannerPayload(['media_type' => 'video']) + ['temp_key' => $tempKey, 'pc_video_id' => $pcVideo, 'mobile_video_id' => $mobileVideo])
            ->assertCreated()->json('data');

        $this->assertSame('video', $data['media_type']);
        $this->assertSame($pcVideo, $data['videos']['pc']['id']);
        $this->assertSame($mobileVideo, $data['videos']['mobile']['id']);
        $this->assertNull($data['youtube_id']);

        $public = $this->getJson($this->api('/hero?position=home_hero'))->json('data.banners.0');
        $this->assertSame('video', $public['media_type']);
        $this->assertSame(['url', 'mime'], array_keys($public['videos']['pc']));
        $this->assertSame('video/mp4', $public['videos']['pc']['mime']);
        $this->assertNotNull($public['videos']['mobile']);
    }

    /**
     * 영상 칸에 이미지 ID 나 다른 배너의 영상을 보내면 거절한다.
     *
     * @scenario surface=video_selection_rejects_foreign
     *
     * @return void 반환값 없음
     */
    public function test_video_selection_rejects_foreign(): void
    {
        $tempKey = (string) Str::uuid();
        $image = $this->uploadImage($this->admin, 'pc', ['temp_key' => $tempKey])->assertCreated()->json('data.id');
        $foreign = $this->uploadVideo('pc', (string) Str::uuid())->assertCreated()->json('data.id');

        foreach ([$image, $foreign] as $id) {
            $this->actingAs($this->admin)->postJson($this->api('/admin/banners'), $this->bannerPayload(['media_type' => 'video']) + ['temp_key' => $tempKey, 'pc_video_id' => $id])
                ->assertStatus(422)->assertJsonValidationErrors('pc_video_id');
        }
    }

    /**
     * 영상 배너를 이미지로 바꾸며 영상 선택을 비워 저장하면 영상 행·파일을 지우고, 공개 응답은 이미지 배너가 된다.
     * 수정 때 영상 선택을 보내지 않으면 붙은 영상을 그대로 두고, 영상 종류인데 PC 영상을 빼면 거절한다.
     *
     * @scenario surface=video_update_switch_and_keep
     *
     * @effects image_files_deleted, activity_log_per_item
     *
     * @return void 반환값 없음
     */
    public function test_video_update_switch_and_keep(): void
    {
        $tempKey = (string) Str::uuid();
        $this->uploadImage($this->admin, 'pc', ['temp_key' => $tempKey])->assertCreated();
        $pcVideo = $this->uploadVideo('pc', $tempKey)->assertCreated()->json('data.id');
        $id = $this->actingAs($this->admin)->postJson($this->api('/admin/banners'), $this->bannerPayload(['media_type' => 'video']) + ['temp_key' => $tempKey])
            ->assertCreated()->json('data.id');
        $path = BannerImage::query()->find($pcVideo)->path;

        // 선택을 보내지 않으면 그대로
        $this->update($id, ['media_type' => 'video'])->assertOk();
        $this->assertSame($id, BannerImage::query()->find($pcVideo)->banner_id);

        // 영상 종류인데 PC 영상을 빼면 거절
        $this->update($id, ['media_type' => 'video', 'pc_video_id' => null])->assertStatus(422)->assertJsonValidationErrors('pc_video');

        // 이미지로 바꾸며 영상을 비우면 행·파일 삭제
        $data = $this->update($id, ['media_type' => 'image', 'pc_video_id' => null, 'mobile_video_id' => null])->assertOk()->json('data');
        $this->assertSame('image', $data['media_type']);
        $this->assertNull($data['videos']['pc']);
        $this->assertNull(BannerImage::query()->find($pcVideo));
        $this->assertFalse($this->imageStorage()->exists('sirsoft-hero_section/images/'.$path));
        $this->assertNull($this->getJson($this->api('/hero?position=home_hero'))->json('data.banners.0.videos'));

        $log = DB::table('activity_logs')->where('action', 'hero_banner.video_remove')->where('loggable_id', $id)->first();
        $this->assertNotNull($log);
        $this->assertSame('pc', json_decode((string) $log->description_params, true)['device'] ?? null);
    }

    /**
     * 유튜브에서 다른 종류로 바꾸면 저장된 영상 ID 를 지운다 — 공개 응답에 남은 ID 가 나가지 않게.
     *
     * @scenario surface=youtube_cleared_on_type_change
     *
     * @return void 반환값 없음
     */
    public function test_youtube_cleared_on_type_change(): void
    {
        $id = $this->createBannerViaApi($this->admin, ['media_type' => 'youtube', 'youtube_url' => 'https://youtu.be/'.self::YT])->assertCreated()->json('data.id');

        $this->update($id, ['media_type' => 'image'])->assertOk();

        $this->assertNull(Banner::query()->find($id)->youtube_id);
        $this->assertNull($this->getJson($this->api('/hero?position=home_hero'))->json('data.banners.0.youtube_id'));
    }

    /**
     * 공개 영상은 범위 요청에 206 과 Content-Range 로 답하고, 인라인 재생(다운로드 강제 없음) + nosniff 다.
     *
     * @scenario surface=video_public_range_response
     *
     * @return void 반환값 없음
     */
    public function test_video_public_range_response(): void
    {
        $data = $this->uploadVideo('pc', (string) Str::uuid())->assertCreated()->json('data');
        $size = BannerImage::query()->find($data['id'])->size;

        $full = $this->get($data['url']);
        $full->assertOk();
        $this->assertSame('video/mp4', $full->headers->get('Content-Type'));
        $this->assertSame('bytes', $full->headers->get('Accept-Ranges'));
        $this->assertSame('nosniff', $full->headers->get('X-Content-Type-Options'));
        $this->assertStringNotContainsString('attachment', (string) $full->headers->get('Content-Disposition'));

        $partial = $this->get($data['url'], ['Range' => 'bytes=0-99']);
        $partial->assertStatus(206);
        $this->assertSame('bytes 0-99/'.$size, $partial->headers->get('Content-Range'));
        $this->assertSame('100', $partial->headers->get('Content-Length'));
    }

    /**
     * 관리자 API 로 영상을 올립니다.
     *
     * @param  string  $device  기기 (pc, mobile)
     * @param  string  $tempKey  임시 묶음 키
     * @param  UploadedFile|null  $file  파일 (기본: 작은 MP4)
     * @return TestResponse 업로드 응답
     */
    private function uploadVideo(string $device, string $tempKey, ?UploadedFile $file = null): TestResponse
    {
        return $this->actingAs($this->admin)->post($this->api('/admin/banner-images'), [
            'file' => $file ?? $this->videoFile('clip.mp4'),
            'device' => $device,
            'kind' => 'video',
            'temp_key' => $tempKey,
        ], ['Accept' => 'application/json']);
    }

    /**
     * 내용으로 영상 형식을 알아볼 수 있는 작은 MP4·WEBM 파일을 만듭니다.
     *
     * @param  string  $name  파일명 (확장자로 형식을 고른다)
     * @return UploadedFile 업로드 파일
     */
    private function videoFile(string $name): UploadedFile
    {
        $bytes = str_ends_with($name, '.webm')
            ? "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x02\x42\x85\x81\x02".str_repeat("\x00", 2000)
            : "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 2000);

        return $this->realFile($name, $bytes);
    }

    /**
     * 내용을 가진 실제 업로드 파일을 만듭니다 — 형식은 이름이 아니라 내용으로 판정된다.
     *
     * @param  string  $name  파일명
     * @param  string  $content  내용
     * @return UploadedFile 업로드 파일
     */
    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'hero');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * 배너를 수정합니다.
     *
     * @param  int  $id  배너 ID
     * @param  array<string, mixed>  $overrides  바꿀 값
     * @return TestResponse 수정 응답
     */
    private function update(int $id, array $overrides): TestResponse
    {
        return $this->actingAs($this->admin)->putJson($this->api('/admin/banners/'.$id), $this->bannerPayload($overrides));
    }
}
