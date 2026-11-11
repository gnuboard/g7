<?php

namespace Tests\Unit\Support;

use App\Support\PublicAssetDisk;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 공개 자산 디스크 — 「공개 디스크로 내보낼 확장자」 판정.
 *
 * 공개 디스크의 파일은 서버를 거치지 않고 직접 주소로 나간다. 저장할 디스크 선택, 직접 주소 발급,
 * 저장 계층의 최후 관문이 모두 같은 확장자 목록을 본다.
 *
 * @scenario-manifest tests/scenarios/public-disk-exportable-extensions.yaml
 */
class PublicAssetDiskExtensionTest extends TestCase
{
    /**
     * 공개 자산 디스크를 `public` 으로 선언합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('core.storage.public_asset_disk', 'public');
        Config::set('core.storage.public_asset_extensions', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif']);
    }

    /**
     * 설정이 비어 있으면 기본 목록을 쓰고, 설정값은 문자열·대문자·점도 정규화한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=extension_list__configured
     *
     * @effects exportable_list_falls_back_to_default_when_empty
     */
    public function test_exportable_list_normalizes_and_falls_back_to_default(): void
    {
        Config::set('core.storage.public_asset_extensions', []);
        $this->assertSame(PublicAssetDisk::DEFAULT_EXPORTABLE_EXTENSIONS, PublicAssetDisk::exportableExtensions());

        Config::set('core.storage.public_asset_extensions', 'PNG, .pdf');
        $this->assertSame(['png', 'pdf'], PublicAssetDisk::exportableExtensions());
        $this->assertTrue(PublicAssetDisk::exportsExtension('.PDF'));
        $this->assertFalse(PublicAssetDisk::exportsExtension('jpg'));
        $this->assertFalse(PublicAssetDisk::exportsExtension(''));
    }

    /**
     * 저장할 공개 디스크는 디스크가 선언돼 있고 확장자가 목록 안일 때만 나온다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=disk_selection__not_exportable
     *
     * @effects non_exportable_file_stays_on_private_disk_with_proxy_url
     */
    public function test_resolve_for_extension_returns_disk_only_for_exportable_extension(): void
    {
        $this->assertSame('public', PublicAssetDisk::resolveForExtension('png'));
        $this->assertNull(PublicAssetDisk::resolveForExtension('html'));
        $this->assertNull(PublicAssetDisk::resolveForExtension('pdf'));

        Config::set('core.storage.public_asset_disk', '');
        $this->assertNull(PublicAssetDisk::resolveForExtension('png'));

        // 확장 개별 설정이 'none' 이면 전역이 켜져 있어도 공개 디스크를 쓰지 않는다.
        Config::set('core.storage.public_asset_disk', 'public');
        $this->assertNull(PublicAssetDisk::resolveForExtension('png', 'none'));
    }

    /**
     * 직접 주소는 행 disk 가 지금의 공개 디스크이고 경로 확장자가 목록 안일 때만 허용된다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=direct_url__not_exportable
     *
     * @effects direct_url_refused_for_non_exportable_extension
     */
    public function test_direct_url_is_refused_for_non_exportable_extension_on_public_disk(): void
    {
        $this->assertTrue(PublicAssetDisk::allowsDirectUrl('public', 'a/b/c.PNG'));
        $this->assertFalse(PublicAssetDisk::allowsDirectUrl('public', 'a/b/c.html'));
        $this->assertFalse(PublicAssetDisk::allowsDirectUrl('public', 'a/b/noext'));
        $this->assertFalse(PublicAssetDisk::allowsDirectUrl('attachments', 'a/b/c.png'));
        $this->assertFalse(PublicAssetDisk::allowsDirectUrl(null, 'a/b/c.png'));
        $this->assertFalse(PublicAssetDisk::allowsDirectUrl('', 'a/b/c.png'));
    }

    /**
     * 최후 관문의 대상은 Laravel `public` 디스크와 코어 전역 공개 자산 디스크다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=write_guard__not_exportable
     *
     * @effects write_guard_targets_public_and_global_public_asset_disk
     */
    public function test_write_guard_targets_public_and_the_global_public_asset_disk(): void
    {
        Config::set('filesystems.disks.fake_cdn', ['driver' => 'local', 'root' => storage_path('framework/testing/disks/fake_cdn')]);
        Config::set('core.storage.public_asset_disk', 'fake_cdn');

        // 코어 전역 공개 자산 디스크
        $this->assertTrue(PublicAssetDisk::refusesWrite('fake_cdn', 'x/y.html'));
        $this->assertFalse(PublicAssetDisk::refusesWrite('fake_cdn', 'x/y.png'));

        // Laravel public 디스크 — 공개 자산 디스크로 선언하지 않았어도 대상
        $this->assertTrue(PublicAssetDisk::refusesWrite('public', 'x/y.php'));
        $this->assertTrue(PublicAssetDisk::refusesWrite('public', 'x/noext'));
        $this->assertFalse(PublicAssetDisk::refusesWrite('public', 'x/y.webp'));

        // 그 밖의 디스크는 대상이 아니다
        $this->assertFalse(PublicAssetDisk::refusesWrite('attachments', 'x/y.html'));
        $this->assertFalse(PublicAssetDisk::refusesWrite('modules', 'x/y.pdf'));
    }

    /**
     * 운영자가 목록에 넣은 확장자는 세 판정 모두에서 허용된다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=write_guard__operator_added
     *
     * @effects operator_added_extension_is_exported
     */
    public function test_operator_added_extension_passes_every_gate(): void
    {
        Config::set('core.storage.public_asset_extensions', ['png', 'pdf']);

        $this->assertSame('public', PublicAssetDisk::resolveForExtension('pdf'));
        $this->assertTrue(PublicAssetDisk::allowsDirectUrl('public', 'a/guide.pdf'));
        $this->assertFalse(PublicAssetDisk::refusesWrite('public', 'a/guide.pdf'));
    }

    /**
     * 공개 자산 디스크가 일반 첨부 디스크와 같은 저장 공간이면 관문이 일반 첨부를 막지 않는다.
     *
     * 저장소 드라이버와 공개 자산 디스크를 둘 다 객체 저장소로 고르면 두 설정이 같은 디스크를 가리킨다.
     * 그 디스크는 비공개 첨부의 정상 저장 위치이기도 하므로 관문이 막으면 pdf 같은 일반 첨부가 저장되지 않는다.
     * 직접 주소는 여전히 「공개 디스크로 내보낼 확장자」 안에서만 나간다. Laravel `public` 디스크는 예외가 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=write_guard__shared_with_attachment_disk
     *
     * @effects write_guard_allows_attachment_disk_shared_with_public_asset_disk
     */
    public function test_write_guard_allows_the_attachment_disk_even_when_it_is_the_public_asset_disk(): void
    {
        Config::set('filesystems.disks.fake_cdn', ['driver' => 'local', 'root' => storage_path('framework/testing/disks/fake_cdn')]);
        Config::set('core.storage.public_asset_disk', 'fake_cdn');
        Config::set('attachment.disk', 'fake_cdn');

        $this->assertFalse(PublicAssetDisk::refusesWrite('fake_cdn', '2026/11/11/report.pdf'));
        $this->assertFalse(PublicAssetDisk::refusesWrite('fake_cdn', 'x/y.png'));

        // 저장은 받아도 직접 주소는 목록 밖 확장자에 내주지 않는다.
        $this->assertFalse(PublicAssetDisk::allowsDirectUrl('fake_cdn', '2026/11/11/report.pdf'));
        $this->assertNull(PublicAssetDisk::resolveForExtension('pdf'));

        // 첨부 디스크가 Laravel public 디스크여도 public 은 언제나 관문 대상이다.
        Config::set('core.storage.public_asset_disk', 'public');
        Config::set('attachment.disk', 'public');
        $this->assertTrue(PublicAssetDisk::refusesWrite('public', 'x/y.html'));

        // 첨부 디스크가 다른 디스크면 기존대로 막는다.
        Config::set('core.storage.public_asset_disk', 'fake_cdn');
        Config::set('attachment.disk', 'attachments');
        $this->assertTrue(PublicAssetDisk::refusesWrite('fake_cdn', 'x/y.pdf'));
    }
}
