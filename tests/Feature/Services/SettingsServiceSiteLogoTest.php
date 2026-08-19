<?php

namespace Tests\Feature\Services;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Enums\AttachmentSourceType;
use App\Extension\Storage\CoreStorageDriver;
use App\Models\Attachment;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 사이트 로고 교체 시 구 로고가 재편입되지 않는지 검증 (회귀)
 *
 * 저장할 목록은 **이번 요청이 제출한 것**이 정한다. 컬렉션 전체를 다시 수집하면 저장에
 * 실패했거나 작성 중 이탈해 남은 미참조 첨부까지 설정에 되살아나, 운영자가 올린 적 없는
 * 로고가 화면에 다시 나타난다(브라우저 실측 — 신규 1건만 제출했는데 저장 결과가 2건).
 */
class SettingsServiceSiteLogoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * site_logo 컬렉션 첨부를 파일과 함께 만듭니다.
     *
     * @return Attachment 생성된 첨부
     */
    private function makeLogo(): Attachment
    {
        $filename = uniqid('logo-').'.png';
        $path = "2026/08/14/{$filename}";
        $disk = config('attachment.disk');

        (new CoreStorageDriver($disk))->put('', $path, 'bytes');

        return Attachment::create([
            'attachmentable_type' => null,
            'attachmentable_id' => null,
            'source_type' => AttachmentSourceType::Core,
            'source_identifier' => null,
            'original_filename' => $filename,
            'stored_filename' => $filename,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => 'image/png',
            'size' => 5,
            'collection' => 'site_logo',
            'order' => 0,
        ]);
    }

    /**
     * @effects site_logo_replacement_drops_previous
     */
    #[Test]
    public function replacing_the_logo_removes_the_previous_one_instead_of_re_collecting_it(): void
    {
        $service = app(SettingsService::class);
        $config = app(ConfigRepositoryInterface::class);

        // 1차 저장 — 첫 로고가 설정에 실린다.
        $first = $this->makeLogo();
        $service->saveSettings(['_tab' => 'general', 'general' => ['site_logo' => [$first->id]]]);

        $this->assertSame([$first->id], $config->getCategory('general')['site_logo']);

        // 2차 저장 — 운영자가 첫 로고를 빼고 새 로고를 올린 상태.
        // 화면은 업로드를 끝낸 뒤 저장을 보내므로 새 로고 id 가 제출값에 실린다.
        $second = $this->makeLogo();
        $service->saveSettings(['_tab' => 'general', 'general' => ['site_logo' => [$second->id]]]);

        $saved = $config->getCategory('general')['site_logo'];

        $this->assertSame([$second->id], $saved, '설정에는 최신 로고만 남아야 한다.');
        $this->assertDatabaseMissing('attachments', ['id' => $first->id]);
    }

    /**
     * 저장에 실패했거나 이탈로 남은 미참조 첨부가 설정에 되살아나면 안 된다.
     *
     * @effects site_logo_unreferenced_collection_member_not_re_absorbed
     */
    #[Test]
    public function an_unreferenced_collection_member_is_not_re_absorbed(): void
    {
        $service = app(SettingsService::class);
        $config = app(ConfigRepositoryInterface::class);

        // 설정에 실린 적 없는 첨부 (직전 저장 실패로 남은 상태)
        $orphan = $this->makeLogo();

        // 운영자는 새 로고 1건만 올려 저장한다.
        $fresh = $this->makeLogo();
        $service->saveSettings(['_tab' => 'general', 'general' => ['site_logo' => [$fresh->id]]]);

        $this->assertSame(
            [$fresh->id],
            $config->getCategory('general')['site_logo'],
            '제출하지 않은 미참조 첨부가 설정에 편입되면 안 된다.'
        );
        $this->assertDatabaseHas('attachments', ['id' => $orphan->id]);
    }

    /**
     * 제출값에 이미 삭제된 첨부 id 가 섞여 있어도 그 id 는 저장되지 않아야 한다.
     *
     * @effects site_logo_stale_submitted_id_filtered
     */
    #[Test]
    public function a_submitted_id_that_no_longer_exists_is_filtered_out(): void
    {
        $service = app(SettingsService::class);
        $config = app(ConfigRepositoryInterface::class);

        $logo = $this->makeLogo();
        $service->saveSettings(['_tab' => 'general', 'general' => ['site_logo' => [$logo->id, 999999]]]);

        $this->assertSame([$logo->id], $config->getCategory('general')['site_logo']);
    }

    /**
     * site_logo 를 제출하지 않은 저장(다른 필드만 변경)은 로고를 건드리지 않아야 한다.
     *
     * @effects site_logo_untouched_when_not_submitted
     */
    #[Test]
    public function saving_other_fields_does_not_remove_the_logo(): void
    {
        $service = app(SettingsService::class);
        $config = app(ConfigRepositoryInterface::class);

        $logo = $this->makeLogo();
        $service->saveSettings(['_tab' => 'general', 'general' => ['site_logo' => [$logo->id]]]);

        $service->saveSettings(['_tab' => 'general', 'general' => ['site_name' => '테스트 사이트']]);

        $this->assertSame([$logo->id], $config->getCategory('general')['site_logo']);
        $this->assertDatabaseHas('attachments', ['id' => $logo->id]);
    }

    /**
     * 저장이 실패하면 뺀 로고의 파일도 남아 있어야 한다 (회귀).
     *
     * 파기를 저장보다 먼저 하면, 저장이 실패했을 때 설정에는 구 로고 id 가 그대로 남는데
     * 파일은 이미 사라져 로고가 깨진다. 파기는 저장이 확정된 뒤에만 수행해야 한다.
     *
     * @effects site_logo_failed_save_keeps_removed_attachment
     */
    #[Test]
    public function a_failed_save_does_not_destroy_the_removed_logo(): void
    {
        $config = app(ConfigRepositoryInterface::class);

        // 로고가 저장된 상태를 만든다.
        $logo = $this->makeLogo();
        app(SettingsService::class)->saveSettings([
            '_tab' => 'general',
            'general' => ['site_logo' => [$logo->id]],
        ]);

        $this->assertSame([$logo->id], $config->getCategory('general')['site_logo']);

        // 저장 단계만 실패하도록 만든다 (조회는 실제 동작 유지).
        $failing = \Mockery::mock($config);
        $failing->shouldReceive('saveCategory')->andReturnFalse();
        $this->app->instance(ConfigRepositoryInterface::class, $failing);

        // 운영자가 로고를 빼고 저장 → 저장 실패
        app(SettingsService::class)->saveSettings([
            '_tab' => 'general',
            'general' => ['site_logo' => []],
        ]);

        $this->app->instance(ConfigRepositoryInterface::class, $config);

        $this->assertDatabaseHas('attachments', ['id' => $logo->id]);
        $this->assertTrue(
            (new CoreStorageDriver($logo->disk))->exists('', $logo->path),
            '저장이 실패했으면 파일도 남아 있어야 한다.'
        );
        $this->assertSame(
            [$logo->id],
            $config->getCategory('general')['site_logo'],
            '저장이 실패했으므로 설정도 그대로여야 한다.'
        );
    }
}
