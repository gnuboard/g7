<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Admin;

use App\Contracts\Extension\StorageInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerImageRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Services\BannerImageService;
use Plugins\Sirsoft\HeroSection\Services\BannerService;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 배너 이미지가 등록·수정 「저장」 때만 붙고, 삭제 때 고아 파일을 남기지 않는지 테스트 (매니페스트 admin-banners.yaml)
 *
 * 수정 화면은 이미지를 임시 묶음에 올려 두고 저장 때 화면에 남은 이미지 ID 를 보낸다. 저장하지 않으면 사이트 화면은 그대로다.
 */
class BannerImageSaveTest extends PluginTestCase
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
     * 수정 화면에서 올리기만 하고 저장하지 않으면 배너 이미지도 사용자 화면도 그대로다.
     *
     * @scenario surface=update_image_upload_not_applied_until_save
     *
     * @effects public_cache_invalidated_for_position
     *
     * @return void 반환값 없음
     */
    public function test_upload_not_applied_until_save(): void
    {
        $id = $this->createBannerViaApi($this->admin, [], mobile: true)->assertCreated()->json('data.id');
        $before = $this->publicPcUrl();

        $this->uploadImage($this->admin, 'pc', ['temp_key' => (string) Str::uuid()])->assertCreated();

        $this->assertSame($before, $this->publicPcUrl());
        $this->assertSame(2, BannerImage::query()->where('banner_id', $id)->count());
    }

    /**
     * 새 PC 이미지를 골라 저장하면 그 이미지가 붙고 옛 이미지 행·파일은 지워지며 사용자 화면도 바뀐다.
     *
     * @scenario surface=update_image_replaced_on_save
     *
     * @effects image_files_deleted, public_cache_invalidated_for_position, activity_log_per_item
     *
     * @return void 반환값 없음
     */
    public function test_replaced_on_save(): void
    {
        $id = $this->createBannerViaApi($this->admin)->assertCreated()->json('data.id');
        $old = BannerImage::query()->where('banner_id', $id)->where('device', 'pc')->first();
        $before = $this->publicPcUrl();
        $tempKey = (string) Str::uuid();
        $newId = $this->uploadImage($this->admin, 'pc', ['temp_key' => $tempKey])->assertCreated()->json('data.id');

        $data = $this->update($id, ['temp_key' => $tempKey, 'pc_image_id' => $newId])->assertOk()->json('data');

        $this->assertSame($newId, $data['images']['pc']['id']);
        $this->assertSame([$newId], BannerImage::query()->where('banner_id', $id)->where('device', 'pc')->pluck('id')->all());
        $this->assertNull(BannerImage::query()->find($old->id));
        $this->imageStorage()->assertMissing('sirsoft-hero_section/images/'.$old->path);
        $this->assertNotSame($before, $this->publicPcUrl());
        $log = DB::table('activity_logs')->where('action', 'hero_banner.image_update')->where('loggable_id', $id)->first();
        $this->assertNotNull($log);
        $this->assertSame('sirsoft-hero_section::activity_log.description.hero_banner_image_update', $log->description_key);
    }

    /**
     * 모바일 이미지를 빼고(null) 저장하면 그때 모바일 이미지 행·파일이 지워진다.
     *
     * @scenario surface=update_image_mobile_removed_on_save
     *
     * @effects image_files_deleted, activity_log_per_item
     *
     * @return void 반환값 없음
     */
    public function test_mobile_removed_on_save(): void
    {
        $id = $this->createBannerViaApi($this->admin, [], mobile: true)->assertCreated()->json('data.id');
        $mobile = BannerImage::query()->where('banner_id', $id)->where('device', 'mobile')->first();
        $pc = BannerImage::query()->where('banner_id', $id)->where('device', 'pc')->value('id');

        $data = $this->update($id, ['temp_key' => (string) Str::uuid(), 'pc_image_id' => $pc, 'mobile_image_id' => null])->assertOk()->json('data');

        $this->assertNull($data['images']['mobile']);
        $this->assertNull(BannerImage::query()->find($mobile->id));
        $this->imageStorage()->assertMissing('sirsoft-hero_section/images/'.$mobile->path);
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'hero_banner.image_remove')->where('loggable_id', $id)->count());
        // PC 는 같은 이미지라 바뀌지 않았다
        $this->assertSame(0, DB::table('activity_logs')->where('action', 'hero_banner.image_update')->where('loggable_id', $id)->count());
    }

    /**
     * 이미지 ID 를 보내지 않으면 이미지는 그대로다(문구만 고치는 저장).
     *
     * @scenario surface=update_image_kept_when_ids_omitted
     *
     * @return void 반환값 없음
     */
    public function test_kept_when_ids_omitted(): void
    {
        $id = $this->createBannerViaApi($this->admin, [], mobile: true)->assertCreated()->json('data.id');
        $ids = BannerImage::query()->where('banner_id', $id)->orderBy('id')->pluck('id')->all();

        $this->update($id, ['link_url' => '/changed'])->assertOk();

        $this->assertSame($ids, BannerImage::query()->where('banner_id', $id)->orderBy('id')->pluck('id')->all());
        $this->assertSame(0, DB::table('activity_logs')->whereIn('action', ['hero_banner.image_update', 'hero_banner.image_remove'])->count());
    }

    /**
     * 다른 배너의 이미지·다른 화면의 임시 업로드·기기가 다른 이미지·빈 PC 는 422 이고 아무것도 바뀌지 않는다.
     *
     * @scenario surface=update_image_rejects_foreign_image
     *
     * @return void 반환값 없음
     */
    public function test_rejects_foreign_image(): void
    {
        $id = $this->createBannerViaApi($this->admin)->assertCreated()->json('data.id');
        $otherId = $this->createBannerViaApi($this->admin)->assertCreated()->json('data.id');
        $otherPc = BannerImage::query()->where('banner_id', $otherId)->value('id');
        $tempKey = (string) Str::uuid();
        $strangerTemp = $this->uploadImage($this->admin, 'pc', ['temp_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $mobileTemp = $this->uploadImage($this->admin, 'mobile', ['temp_key' => $tempKey])->assertCreated()->json('data.id');
        $before = BannerImage::query()->where('banner_id', $id)->pluck('id')->all();

        $this->update($id, ['temp_key' => $tempKey, 'pc_image_id' => $otherPc])->assertStatus(422)->assertJsonValidationErrors(['pc_image_id']);
        $this->update($id, ['temp_key' => $tempKey, 'pc_image_id' => $strangerTemp])->assertStatus(422)->assertJsonValidationErrors(['pc_image_id']);
        $this->update($id, ['temp_key' => $tempKey, 'pc_image_id' => $mobileTemp])->assertStatus(422)->assertJsonValidationErrors(['pc_image_id']);
        $this->update($id, ['temp_key' => $tempKey, 'pc_image_id' => null])->assertStatus(422)->assertJsonValidationErrors(['pc_image_id']);

        $this->assertSame($before, BannerImage::query()->where('banner_id', $id)->pluck('id')->all());
        $this->assertNotNull(BannerImage::query()->find($otherPc));
    }

    /**
     * 옛 파일 삭제가 실패해도 새 이미지가 보이고, 옛 이미지는 임시 이미지로 남아 정리 명령이 다시 지운다.
     *
     * @scenario surface=update_image_release_failure_left_for_prune
     *
     * @effects image_files_deleted
     *
     * @return void 반환값 없음
     */
    public function test_release_failure_left_for_prune(): void
    {
        $id = $this->createBannerViaApi($this->admin)->assertCreated()->json('data.id');
        $old = BannerImage::query()->where('banner_id', $id)->where('device', 'pc')->first();
        $tempKey = (string) Str::uuid();
        $newId = $this->uploadImage($this->admin, 'pc', ['temp_key' => $tempKey])->assertCreated()->json('data.id');

        $realService = $this->app->make(BannerImageService::class);
        $storage = \Mockery::mock(StorageInterface::class);
        $storage->shouldReceive('exists')->andReturn(true);
        $storage->shouldReceive('delete')->andReturn(false);
        $this->app->instance(BannerImageService::class, new BannerImageService(app(BannerImageRepositoryInterface::class), $storage));

        $data = $this->update($id, ['temp_key' => $tempKey, 'pc_image_id' => $newId])->assertOk()->json('data');

        $this->assertSame($newId, $data['images']['pc']['id']);
        $released = BannerImage::query()->find($old->id);
        $this->assertNotNull($released);
        $this->assertNull($released->banner_id);
        $this->assertNotNull($released->temp_key);
        $this->assertStringContainsString('/images/'.BannerImage::query()->whereKey($newId)->value('hash'), $this->publicPcUrl());

        $this->app->instance(BannerImageService::class, $realService);
        BannerImage::query()->whereKey($old->id)->update(['created_at' => now()->subDays(3)]);
        $result = $realService->pruneTempUploads(2, 500, false);

        $this->assertSame(1, $result['deleted']);
        $this->assertNull(BannerImage::query()->find($old->id));
        $this->imageStorage()->assertMissing('sirsoft-hero_section/images/'.$old->path);
    }

    /**
     * 배너 삭제·일괄 삭제 때 파일 삭제가 실패해도 이미지 행은 임시 이미지로 남아 정리 명령이 다시 지운다(고아 파일 없음).
     *
     * @scenario surface=destroy_file_failure_left_for_prune
     *
     * @effects image_files_deleted
     *
     * @return void 반환값 없음
     */
    public function test_destroy_file_failure_left_for_prune(): void
    {
        $single = $this->createBannerViaApi($this->admin, [], mobile: true)->assertCreated()->json('data.id');
        $bulk = $this->createBannerViaApi($this->admin)->assertCreated()->json('data.id');
        $imageIds = BannerImage::query()->whereIn('banner_id', [$single, $bulk])->pluck('id')->all();
        $paths = BannerImage::query()->whereIn('id', $imageIds)->pluck('path')->all();

        $realService = $this->app->make(BannerImageService::class);
        $storage = \Mockery::mock(StorageInterface::class);
        $storage->shouldReceive('exists')->andReturn(true);
        $storage->shouldReceive('delete')->andReturn(false);
        $this->app->instance(BannerImageService::class, new BannerImageService(app(BannerImageRepositoryInterface::class), $storage));

        $this->actingAs($this->admin)->deleteJson($this->api('/admin/banners/'.$single))->assertOk();
        $this->actingAs($this->admin)->deleteJson($this->api('/admin/banners/bulk'), ['ids' => [$bulk]])->assertOk();

        $left = BannerImage::query()->whereIn('id', $imageIds)->get();
        $this->assertCount(3, $left);
        $this->assertTrue($left->every(fn (BannerImage $image) => $image->banner_id === null && $image->temp_key !== null));

        $this->app->instance(BannerImageService::class, $realService);
        BannerImage::query()->whereIn('id', $imageIds)->update(['created_at' => now()->subDays(3)]);
        $this->assertSame(3, $realService->pruneTempUploads(2, 500, false)['deleted']);
        foreach ($paths as $path) {
            $this->imageStorage()->assertMissing('sirsoft-hero_section/images/'.$path);
        }
    }

    /**
     * 등록 저장은 기기마다 한 장만 붙인다 — 이미지 ID 를 보내지 않으면 묶음의 가장 최근 이미지, 남은 것은 임시로 둔다.
     *
     * @scenario surface=store_links_one_image_per_device
     *
     * @return void 반환값 없음
     */
    public function test_store_links_one_image_per_device(): void
    {
        $tempKey = (string) Str::uuid();
        // 교체 업로드 때 앞 이미지의 파일 삭제가 실패해 같은 묶음에 PC 이미지가 둘 남은 상황
        $stale = $this->uploadImage($this->admin, 'pc', ['temp_key' => $tempKey])->assertCreated()->json('data.id');
        $latest = BannerImage::factory()->pc()->temp($tempKey)->create()->id;

        $data = $this->actingAs($this->admin)->postJson($this->api('/admin/banners'), $this->bannerPayload(['temp_key' => $tempKey]))->assertCreated()->json('data');

        $this->assertSame([$latest], BannerImage::query()->where('banner_id', $data['id'])->where('device', 'pc')->pluck('id')->all());
        $this->assertSame($latest, $data['images']['pc']['id']);
        $this->assertNull(BannerImage::query()->find($stale)->banner_id);
    }

    /**
     * 등록 저장도 이미지 ID 를 보내면 그것만 붙인다 — 화면에서 뺀 모바일(null)은 붙지 않는다.
     *
     * @scenario surface=store_respects_selected_images
     *
     * @return void 반환값 없음
     */
    public function test_store_respects_selected_images(): void
    {
        $tempKey = (string) Str::uuid();
        $pc = $this->uploadImage($this->admin, 'pc', ['temp_key' => $tempKey])->assertCreated()->json('data.id');
        $mobile = $this->uploadImage($this->admin, 'mobile', ['temp_key' => $tempKey])->assertCreated()->json('data.id');

        $data = $this->actingAs($this->admin)->postJson($this->api('/admin/banners'), $this->bannerPayload(['temp_key' => $tempKey, 'pc_image_id' => $pc, 'mobile_image_id' => null]))
            ->assertCreated()->json('data');

        $this->assertSame($pc, $data['images']['pc']['id']);
        $this->assertNull($data['images']['mobile']);
        $this->assertNull(BannerImage::query()->find($mobile)->banner_id);

        $foreign = $this->uploadImage($this->admin, 'pc', ['temp_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->actingAs($this->admin)->postJson($this->api('/admin/banners'), $this->bannerPayload(['temp_key' => $tempKey, 'pc_image_id' => $foreign]))
            ->assertStatus(422)->assertJsonValidationErrors(['pc_image_id']);
    }

    /**
     * 등록 저장을 연달아 두 번 눌러 두 요청이 같은 임시 이미지를 골랐을 때 — 검증은 둘 다 통과했지만 먼저 저장된 배너가 이미지를 가져갔으면,
     * 뒤 요청은 그 이미지를 빼앗지 않고 저장 전체를 되돌린다(이미지 없는 배너도, 같은 배너 둘도 남지 않는다).
     *
     * @scenario surface=store_double_submit_keeps_first_image
     *
     * @return void 반환값 없음
     */
    public function test_store_double_submit_does_not_steal_image(): void
    {
        $tempKey = (string) Str::uuid();
        $pc = $this->uploadImage($this->admin, 'pc', ['temp_key' => $tempKey])->assertCreated()->json('data.id');
        $first = $this->actingAs($this->admin)->postJson($this->api('/admin/banners'), $this->bannerPayload(['temp_key' => $tempKey, 'pc_image_id' => $pc]))
            ->assertCreated()->json('data.id');
        $count = Banner::query()->count();

        // 두 번째 요청은 첫 요청이 커밋되기 전에 검증을 통과했다 — 그 뒤 단계(서비스)부터 재현한다
        foreach ([['temp_key' => $tempKey, 'pc_image_id' => $pc], ['temp_key' => $tempKey]] as $data) {
            try {
                app(BannerService::class)->create($this->bannerPayload($data), $this->admin->id);
                $this->fail('먼저 저장된 배너의 이미지를 가져가면 안 된다');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey(isset($data['pc_image_id']) ? 'pc_image_id' : 'temp_key', $e->errors());
            }
        }

        $this->assertSame($first, BannerImage::query()->find($pc)->banner_id);
        $this->assertSame($count, Banner::query()->count());
    }

    /**
     * 배너 수정 API 를 부릅니다(나머지 값은 기본 배너 값).
     *
     * @param  int  $id  배너 ID
     * @param  array<string, mixed>  $overrides  바꿀 값
     * @return TestResponse 응답
     */
    private function update(int $id, array $overrides): TestResponse
    {
        return $this->actingAs($this->admin)->putJson($this->api('/admin/banners/'.$id), $this->bannerPayload($overrides));
    }

    /**
     * 사이트 홈 공개 응답의 첫 배너 PC 이미지 주소를 반환합니다.
     *
     * @return string|null PC 이미지 주소
     */
    private function publicPcUrl(): ?string
    {
        return $this->getJson($this->api('/hero?position=home_hero'))->json('data.banners.0.images.pc.url');
    }
}
