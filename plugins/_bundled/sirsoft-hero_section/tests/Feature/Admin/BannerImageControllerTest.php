<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Support\UploadLimit;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 관리자 배너 이미지 API 테스트 (매니페스트 admin-banners.yaml — 이미지 케이스)
 */
class BannerImageControllerTest extends PluginTestCase
{
    /**
     * 관리자
     */
    private User $admin;

    /**
     * 테스트 준비 — 이미지 파일은 가짜 디스크에 쓴다.
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
     * 등록·수정 화면 임시 묶음으로 올리면 배너 없이 temp_key 로 저장되고 크기를 재서 돌려준다.
     *
     * @scenario surface=image_upload_with_temp_key
     *
     * @return void 반환값 없음
     */
    public function test_upload_with_temp_key(): void
    {
        $tempKey = (string) Str::uuid();

        $data = $this->uploadImage($this->admin, 'pc', ['temp_key' => $tempKey])->assertCreated()->json('data');

        $this->assertSame(1920, $data['width']);
        $this->assertSame(640, $data['height']);
        $this->assertStringStartsWith('/api/plugins/sirsoft-hero_section/images/', $data['url']);
        // 관리자 업로더(file-uploader)가 첨부로 받아들이는 모양
        $this->assertSame($data['url'], $data['download_url']);
        $this->assertSame('pc', $data['device']);
        $this->assertSame('image/jpeg', $data['mime_type']);
        $this->assertTrue($data['is_image']);
        $this->assertSame(64, strlen($data['hash']));
        $this->assertSame('pc.jpg', $data['original_filename']);
        $this->assertNotSame('', $data['size_formatted']);
        $this->assertArrayNotHasKey('path', $data);
        $image = BannerImage::query()->find($data['id']);
        $this->assertNull($image->banner_id);
        $this->assertSame($tempKey, $image->temp_key);
        $this->imageStorage()->assertExists('sirsoft-hero_section/images/'.$image->path);
    }

    /**
     * 같은 임시 묶음의 같은 기기 이미지를 다시 올리면 앞 이미지(행·파일)를 교체한다.
     *
     * @scenario surface=image_upload_replaces_same_device
     *
     * @effects image_files_deleted
     *
     * @return void 반환값 없음
     */
    public function test_upload_replaces_same_device(): void
    {
        $tempKey = (string) Str::uuid();
        $oldId = $this->uploadImage($this->admin, 'pc', ['temp_key' => $tempKey])->assertCreated()->json('data.id');
        $old = BannerImage::query()->find($oldId);

        $newId = $this->uploadImage($this->admin, 'pc', ['temp_key' => $tempKey])->assertCreated()->json('data.id');

        $this->assertSame([$newId], BannerImage::query()->where('temp_key', $tempKey)->where('device', 'pc')->pluck('id')->all());
        $this->assertNull(BannerImage::query()->find($oldId));
        $this->imageStorage()->assertMissing('sirsoft-hero_section/images/'.$old->path);
    }

    /**
     * 업로드는 임시 묶음 키가 있어야 한다 — 배너에 바로 올리는 길은 없다(배너 이미지는 저장 때만 바뀐다).
     *
     * @scenario surface=image_upload_requires_temp_key
     *
     * @return void 반환값 없음
     */
    public function test_upload_requires_temp_key(): void
    {
        $id = $this->createBannerViaApi($this->admin)->assertCreated()->json('data.id');
        $before = BannerImage::query()->where('banner_id', $id)->pluck('id')->all();

        $this->uploadImage($this->admin, 'pc', ['banner_id' => $id])
            ->assertStatus(422)->assertJsonValidationErrors(['temp_key']);

        $this->assertSame($before, BannerImage::query()->where('banner_id', $id)->pluck('id')->all());
    }

    /**
     * 형식(JPG·PNG·WEBP 밖)과 설정 용량을 넘는 파일은 422 다.
     *
     * @scenario surface=image_upload_rejects_type_and_size
     *
     * @return void 반환값 없음
     */
    public function test_upload_rejects_type_and_size(): void
    {
        $owner = ['temp_key' => (string) Str::uuid()];

        $this->uploadImage($this->admin, 'pc', $owner, UploadedFile::fake()->image('a.gif', 10, 10))
            ->assertStatus(422)->assertJsonValidationErrors(['file']);
        $this->uploadImage($this->admin, 'pc', $owner, UploadedFile::fake()->create('a.svg', 1, 'image/svg+xml'))
            ->assertStatus(422)->assertJsonValidationErrors(['file']);

        $this->setPluginSettings(['max_upload_mb' => 1]);
        $big = UploadedFile::fake()->image('big.jpg', 100, 100)->size(1500);
        $this->uploadImage($this->admin, 'pc', $owner, $big)
            ->assertStatus(422)->assertJsonValidationErrors(['file']);

        $this->assertSame(0, BannerImage::query()->count());
    }

    /**
     * 서버 PHP 한도에 걸려 비어 들어온 파일은 「서버 업로드 한도」 안내로 422 다.
     *
     * @scenario surface=image_upload_over_server_limit_message
     *
     * @return void 반환값 없음
     */
    public function test_upload_over_server_limit_message(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hero');
        $file = new UploadedFile($path, 'huge.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

        $response = $this->uploadImage($this->admin, 'pc', ['temp_key' => (string) Str::uuid()], $file)->assertStatus(422);

        $message = $response->json('errors.file.0');
        $this->assertSame(__('sirsoft-hero_section::validation.file.server_limit', ['mb' => UploadLimit::serverLimitMb()]), $message);
        @unlink($path);
    }

    /**
     * 배너에 붙은 이미지(PC·모바일)는 이미지 API 로 지울 수 없다 — 배너 수정 저장으로만 바뀐다.
     *
     * @scenario surface=image_attached_cannot_be_deleted
     *
     * @return void 반환값 없음
     */
    public function test_attached_image_cannot_be_deleted(): void
    {
        $id = $this->createBannerViaApi($this->admin, [], mobile: true)->assertCreated()->json('data.id');

        $images = BannerImage::query()->where('banner_id', $id)->get();
        $this->assertCount(2, $images);

        foreach ($images as $image) {
            $this->actingAs($this->admin)->deleteJson($this->api('/admin/banner-images/'.$image->id))->assertStatus(422);
            $this->assertNotNull(BannerImage::query()->find($image->id));
        }
    }

    /**
     * 아직 배너에 붙지 않은 임시 이미지는 지울 수 있고 파일도 지워진다.
     *
     * @scenario surface=image_temp_can_be_deleted
     *
     * @effects image_files_deleted
     *
     * @return void 반환값 없음
     */
    public function test_temp_image_can_be_deleted(): void
    {
        $id = $this->uploadImage($this->admin, 'mobile', ['temp_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $image = BannerImage::query()->find($id);

        $this->actingAs($this->admin)->deleteJson($this->api('/admin/banner-images/'.$id))->assertOk();

        $this->assertNull(BannerImage::query()->find($id));
        $this->imageStorage()->assertMissing('sirsoft-hero_section/images/'.$image->path);
    }
}
