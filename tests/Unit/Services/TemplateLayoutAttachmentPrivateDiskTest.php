<?php

namespace Tests\Unit\Services;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Services\TemplateLayoutAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 레이아웃 배경 이미지는 「비공개 첨부 저장소」 선택을 따르지 않는다
 *
 * 레이아웃 첨부는 방문자 화면에 그대로 나가는 공개 자산이다. 저장 디스크는 공개 자산 디스크, 그것이 꺼져 있으면 종전 첨부 디스크
 * (`attachment.disk`)다. 판정 지점은 둘이다 — 서비스에 주입되는 스토리지 드라이버의 기본 디스크(`CoreServiceProvider` 바인딩)와
 * 업로드가 고르는 저장 디스크(`TemplateLayoutAttachmentService::upload`). 둘 중 하나라도 비공개 첨부 저장소
 * (`PrivateAttachmentDisk::forCore()`)를 따르면 이 시험이 실패한다.
 */
class TemplateLayoutAttachmentPrivateDiskTest extends TestCase
{
    use RefreshDatabase;

    /** 비공개 첨부 저장소로 고를 시험 디스크 */
    private const PRIVATE_DISK = 'fx916-layout-vault';

    /** 공개 자산 디스크로 고를 시험 디스크 */
    private const PUBLIC_DISK = 'fx916-layout-cdn';

    /** 시험 템플릿 식별자 */
    private const TEMPLATE = 'g7test-d29basic';

    /**
     * 스키마를 다시 만들지 않고 트랜잭션으로 격리합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 시험 디스크를 정의하고 비공개 첨부 저장소를 다른 디스크로 고른 상태를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::PRIVATE_DISK, self::PUBLIC_DISK] as $disk) {
            // 해석기는 `filesystems.disks` 에 정의된 디스크만 선택으로 인정한다 — fake 만으로는 고아 디스크가 된다
            Config::set("filesystems.disks.{$disk}", ['driver' => 'local', 'root' => storage_path("framework/testing/disks/{$disk}")]);
            Storage::fake($disk);
        }
        Storage::fake('attachments');
        Config::set('attachment.disk', 'attachments');
        Config::set('attachment.disk_explicit', null);
        Config::set('core.storage.private_attachment_disk', self::PRIVATE_DISK);

        Template::create([
            'identifier' => self::TEMPLATE,
            'vendor' => 'g7test',
            'name' => ['ko' => '시험', 'en' => 'Test'],
            'version' => '1.0.0',
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '시험', 'en' => 'Test'],
        ]);
    }

    /**
     * 공개 자산 디스크가 꺼져 있으면 비공개 첨부 저장소를 골라도 종전 첨부 디스크에 저장한다.
     *
     * @scenario case=layout_attachment_ignores_private_disk
     *
     * @effects layout_attachment_ignores_private_attachment_selection
     *
     * @return void 반환값 없음
     */
    public function test_upload_stays_on_attachment_disk_without_public_asset_disk(): void
    {
        Config::set('core.storage.public_asset_disk', '');

        $service = app(TemplateLayoutAttachmentService::class);

        // 바인딩 — 주입된 드라이버의 기본 디스크
        $storage = (fn () => $this->storage)->call($service);
        $this->assertSame('attachments', $storage->getDisk(), '레이아웃 첨부 드라이버가 비공개 첨부 저장소를 따랐다');

        // 업로드 — 행의 disk 와 실제 저장 위치
        $result = $service->upload(self::TEMPLATE, UploadedFile::fake()->image('bg.png', 20, 20), 'home');

        $this->assertTrue($result['success'], (string) $result['error']);
        $this->assertSame('attachments', $result['attachment']->disk, '레이아웃 첨부가 비공개 첨부 저장소에 저장됐다');
        Storage::disk('attachments')->assertExists('template-layout-attachments/'.$result['attachment']->path);
        $this->assertSame([], Storage::disk(self::PRIVATE_DISK)->allFiles(), '비공개 첨부 저장소에 레이아웃 첨부가 쓰였다');
    }

    /**
     * 공개 자산 디스크가 있으면 비공개 첨부 저장소 선택과 무관하게 공개 자산 디스크에 저장한다.
     *
     * @scenario case=layout_attachment_ignores_private_disk
     *
     * @effects layout_attachment_ignores_private_attachment_selection
     *
     * @return void 반환값 없음
     */
    public function test_upload_goes_to_public_asset_disk_not_private_disk(): void
    {
        Config::set('core.storage.public_asset_disk', self::PUBLIC_DISK);

        $result = app(TemplateLayoutAttachmentService::class)->upload(self::TEMPLATE, UploadedFile::fake()->image('bg.png', 20, 20), 'home');

        $this->assertTrue($result['success'], (string) $result['error']);
        $this->assertSame(self::PUBLIC_DISK, $result['attachment']->disk);
        $this->assertSame([], Storage::disk(self::PRIVATE_DISK)->allFiles(), '비공개 첨부 저장소에 레이아웃 첨부가 쓰였다');
    }
}
