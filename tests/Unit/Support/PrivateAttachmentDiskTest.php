<?php

namespace Tests\Unit\Support;

use App\Support\PrivateAttachmentDisk;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 비공개 첨부 저장소 디스크 해석기 
 *
 * 우선순위 — 코어 첨부: ATTACHMENT_DISK env 명시 > 선택 > 종전 규칙(attachment.disk). 확장: 선택 > 확장 기본 디스크.
 * 「기본」·빈 값·정의되지 않은 디스크(꺼진 플러그인)는 선택 없음으로 본다.
 */
class PrivateAttachmentDiskTest extends TestCase
{
    /**
     * 시험 디스크를 정의하고 env 명시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('filesystems.disks.fx916-vault', ['driver' => 'local', 'root' => storage_path('framework/testing/fx916-vault')]);
        Config::set('attachment.disk_explicit', null);
        Config::set('attachment.disk', 'attachments');
    }

    /**
     * 「기본」·빈 값·고아 디스크는 null, 정의된 디스크는 그 이름.
     *
     * @return void 반환값 없음
     *
     * @scenario case=resolver
     *
     * @effects private_attachment_resolve_returns_null_for_default_blank_orphan
     */
    public function test_resolve(): void
    {
        foreach (['', 'default', 'fx916-gone'] as $value) {
            Config::set('core.storage.private_attachment_disk', $value);
            $this->assertNull(PrivateAttachmentDisk::resolve(), "값 '{$value}'");
        }
        Config::set('core.storage.private_attachment_disk', 'fx916-vault');
        $this->assertSame('fx916-vault', PrivateAttachmentDisk::resolve());
    }

    /**
     * 고아 판정은 「기본」이 아니고 정의되지 않은 값일 때만.
     *
     * @return void 반환값 없음
     *
     * @scenario case=orphan_disk
     *
     * @effects private_attachment_orphan_only_for_undefined_non_default
     */
    public function test_orphaned(): void
    {
        Config::set('core.storage.private_attachment_disk', 'fx916-gone');
        $this->assertTrue(PrivateAttachmentDisk::isOrphaned());
        foreach (['', 'default', 'fx916-vault'] as $value) {
            Config::set('core.storage.private_attachment_disk', $value);
            $this->assertFalse(PrivateAttachmentDisk::isOrphaned(), "값 '{$value}'");
        }
    }

    /**
     * 코어 첨부: env 명시 > 선택 > 종전 규칙. 빈 env 는 명시가 아니다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=core_priority
     *
     * @effects private_attachment_core_priority_env_then_selection_then_legacy
     */
    public function test_core_priority(): void
    {
        Config::set('core.storage.private_attachment_disk', '');
        Config::set('attachment.disk', 's3'); // storage_driver=s3 규칙이 미리 계산된 상태
        $this->assertSame('s3', PrivateAttachmentDisk::forCore());

        Config::set('core.storage.private_attachment_disk', 'fx916-vault');
        $this->assertSame('fx916-vault', PrivateAttachmentDisk::forCore());

        Config::set('attachment.disk_explicit', '');
        $this->assertSame('fx916-vault', PrivateAttachmentDisk::forCore());

        Config::set('attachment.disk_explicit', 'attachments');
        $this->assertSame('attachments', PrivateAttachmentDisk::forCore());
    }

    /**
     * 확장: 선택이 있으면 그 디스크, 없으면 확장 기본 디스크.
     *
     * @return void 반환값 없음
     *
     * @scenario case=extension_default
     *
     * @effects private_attachment_extension_falls_back_to_extension_disk
     */
    public function test_extension_default(): void
    {
        Config::set('core.storage.private_attachment_disk', 'default');
        $this->assertSame('modules', PrivateAttachmentDisk::forExtension('modules'));
        $this->assertSame('plugins', PrivateAttachmentDisk::forExtension('plugins'));

        Config::set('core.storage.private_attachment_disk', 'fx916-vault');
        $this->assertSame('fx916-vault', PrivateAttachmentDisk::forExtension('modules'));
    }
}
