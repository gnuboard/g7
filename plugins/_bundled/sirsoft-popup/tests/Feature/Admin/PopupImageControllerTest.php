<?php

namespace Plugins\Sirsoft\Popup\Tests\Feature\Admin;

use App\Contracts\Extension\StorageInterface;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Popup\Models\PopupImage;
use Plugins\Sirsoft\Popup\Repositories\Contracts\PopupImageRepositoryInterface;
use Plugins\Sirsoft\Popup\Services\PopupImageService;
use Plugins\Sirsoft\Popup\Support\UploadLimit;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;

/**
 * 관리자 팝업 이미지 API 테스트 (매니페스트 admin-popups.yaml — 이미지 케이스)
 *
 * 업로드는 언제나 임시 묶음(temp_key)에 들어가고 팝업에는 저장 때 붙는다(`PopupControllerTest` 가 본다).
 */
class PopupImageControllerTest extends PluginTestCase
{
    /**
     * 조회·등록수정·삭제 권한을 가진 관리자
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
     * JPG·PNG·WEBP·GIF 네 형식을 받고, 크기를 재서 응답에 싣는다(GIF 는 줄이지 않아 움직임이 남는다).
     *
     * @scenario surface=image_upload_accepts_four_formats
     *
     * @effects files_created_or_removed, response_shape_with_abilities
     *
     * @return void 반환값 없음
     */
    public function test_upload_accepts_four_formats(): void
    {
        foreach (['a.jpg', 'a.png', 'a.webp', 'a.gif'] as $name) {
            $data = $this->uploadImage($this->admin, 'pc', (string) Str::uuid(), UploadedFile::fake()->image($name, 320, 240))
                ->assertCreated()->json('data');

            $this->assertSame(320, $data['width'], $name);
            $this->assertSame(240, $data['height'], $name);
            $this->assertStringStartsWith('/api/plugins/sirsoft-popup/images/', $data['url']);
            $this->imageStorage()->assertExists('sirsoft-popup/images/'.PopupImage::query()->find($data['id'])->path);
        }

        $this->assertSame('image/gif', PopupImage::query()->latest('id')->value('mime'));
    }

    /**
     * 형식(네 형식 밖)과 설정 용량을 넘는 파일은 422 다.
     *
     * @scenario surface=image_upload_rejects_oversize
     *
     * @return void 반환값 없음
     */
    public function test_upload_rejects_type_and_size(): void
    {
        $tempKey = (string) Str::uuid();

        $this->uploadImage($this->admin, 'pc', $tempKey, UploadedFile::fake()->create('a.svg', 1, 'image/svg+xml'))
            ->assertStatus(422)->assertJsonValidationErrors(['file']);

        $this->setPluginSettings(['max_upload_mb' => 1]);
        $big = UploadedFile::fake()->image('big.jpg', 100, 100)->size(1500);
        $this->uploadImage($this->admin, 'pc', $tempKey, $big)
            ->assertStatus(422)->assertJsonValidationErrors(['file']);

        $this->assertSame(0, PopupImage::query()->count());
    }

    /**
     * 서버 PHP 한도에 걸려 비어 들어온 파일은 「서버 업로드 한도」 안내로 422 다.
     *
     * @scenario surface=image_upload_php_limit_message
     *
     * @return void 반환값 없음
     */
    public function test_upload_over_server_limit_message(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'popup');
        $file = new UploadedFile($path, 'huge.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

        $response = $this->uploadImage($this->admin, 'pc', (string) Str::uuid(), $file)->assertStatus(422);

        $this->assertSame(__('sirsoft-popup::validation.file.server_limit', ['mb' => UploadLimit::serverLimitMb()]), $response->json('errors.file.0'));
        @unlink($path);
    }

    /**
     * 임시 이미지는 지울 수 있고(파일도), 팝업에 붙은 이미지는 422 로 막는다.
     *
     * @scenario surface=image_delete_only_temp
     *
     * @effects files_created_or_removed
     *
     * @return void 반환값 없음
     */
    public function test_delete_only_temp_images(): void
    {
        $tempId = $this->uploadImage($this->admin, 'mobile', (string) Str::uuid())->assertCreated()->json('data.id');
        $temp = PopupImage::query()->find($tempId);

        $this->actingAs($this->admin)->deleteJson($this->api('/admin/popup-images/'.$tempId))->assertOk();
        $this->assertNull(PopupImage::query()->find($tempId));
        $this->imageStorage()->assertMissing('sirsoft-popup/images/'.$temp->path);

        $popupId = $this->createPopupViaApi($this->admin)->assertCreated()->json('data.id');
        $attached = PopupImage::query()->where('popup_id', $popupId)->first();
        $this->actingAs($this->admin)->deleteJson($this->api('/admin/popup-images/'.$attached->id))->assertStatus(422);
        $this->assertNotNull(PopupImage::query()->find($attached->id));
    }

    /**
     * 파일을 쓰지 못하면 행을 만들지 않고 500 과 안내를 돌려준다 — 파일 없는 이미지가 팝업에 붙지 않게.
     *
     * @scenario surface=image_upload_write_failure_leaves_no_row
     *
     * @effects files_created_or_removed
     *
     * @return void 반환값 없음
     */
    public function test_upload_write_failure_leaves_no_row(): void
    {
        $storage = \Mockery::mock(StorageInterface::class);
        $storage->shouldReceive('put')->andReturn(false);
        $storage->shouldReceive('getDisk')->andReturn('local');
        $this->app->instance(PopupImageService::class, new PopupImageService(app(PopupImageRepositoryInterface::class), $storage));
        $before = PopupImage::query()->count();

        $this->uploadImage($this->admin, 'pc', (string) Str::uuid())
            ->assertStatus(500)
            ->assertJsonPath('message', __('sirsoft-popup::messages.image.upload_failed'));
        $this->assertSame($before, PopupImage::query()->count());
    }

    /**
     * 파일을 쓴 뒤 행 저장이 실패하면 쓴 파일을 지운다 — 행이 없는 파일은 임시 이미지 정리도 찾지 못한다.
     *
     * @scenario surface=image_upload_db_failure_removes_file
     *
     * @effects files_created_or_removed
     *
     * @return void 반환값 없음
     */
    public function test_upload_db_failure_removes_written_file(): void
    {
        $repository = \Mockery::mock(app(PopupImageRepositoryInterface::class));
        $repository->shouldReceive('create')->andThrow(new \RuntimeException('DB 연결 끊김'));
        $this->app->instance(PopupImageService::class, new PopupImageService($repository, $this->imageStorageDriver()));
        $before = $this->imageFiles();

        $this->uploadImage($this->admin, 'pc', (string) Str::uuid())
            ->assertStatus(500)
            ->assertJsonPath('message', __('sirsoft-popup::messages.image.upload_failed'));
        $this->assertSame($before, $this->imageFiles());
    }
}
