<?php

namespace Tests\Unit\Extension\Storage;

use App\Extension\Storage\ModuleStorageDriver;
use App\Extension\Storage\RowDiskStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 행 디스크 도우미 — 행에 기록된 디스크로 저장소 고르기 (#887, G1)
 *
 * 기본 디스크를 바꾼 뒤에도 옛 행은 그 행이 저장된 디스크에서 읽고 지운다. 비었거나 같은 디스크면 기본 저장소, 설정에 없는
 * 디스크(그 디스크를 제공하던 확장이 꺼진 경우)면 기본 저장소로 물러나 500 대신 404 로 저하한다.
 *
 * @scenario-manifest tests/scenarios/content-media-core-skeleton.yaml
 */
class RowDiskStorageTest extends TestCase
{
    /**
     * 비었거나 기본과 같은 디스크, 설정에 없는 디스크는 기본 저장소 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=row_disk, extension=module
     *
     * @effects default_for_empty_or_same, fallback_for_unknown_disk
     */
    public function test_returns_default_for_empty_same_or_unknown_disk(): void
    {
        $default = new ModuleStorageDriver('row-disk-test', 'local');

        $this->assertSame($default, RowDiskStorage::resolve($default, null));
        $this->assertSame($default, RowDiskStorage::resolve($default, ''));
        $this->assertSame($default, RowDiskStorage::resolve($default, 'local'));

        config(['filesystems.disks.gone-disk' => null]);
        $this->assertSame($default, RowDiskStorage::resolve($default, 'gone-disk'));
    }

    /**
     * 설정에 있는 다른 디스크면 그 디스크의 사본을 돌려주고, 그 디스크의 파일을 읽는다. 기본 저장소는 바뀌지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=row_disk, extension=module
     *
     * @effects other_disk_resolved
     */
    public function test_returns_with_disk_copy_for_configured_disk(): void
    {
        Storage::fake('local');
        config(['filesystems.disks.archive' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/archive')]]);
        Storage::fake('archive');
        (new ModuleStorageDriver('row-disk-test', 'archive'))->put('attachments', 'a.txt', 'ARCHIVE');

        $default = new ModuleStorageDriver('row-disk-test', 'local');
        $resolved = RowDiskStorage::resolve($default, 'archive');

        $this->assertNotSame($default, $resolved);
        $this->assertSame('archive', $resolved->getDisk());
        $this->assertSame('local', $default->getDisk());
        $this->assertSame('ARCHIVE', $resolved->get('attachments', 'a.txt'));
        $this->assertNull($default->get('attachments', 'a.txt'));
    }
}
