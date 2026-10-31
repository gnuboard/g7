<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Board\Services\BoardSettingsService;
use Modules\Sirsoft\Board\Tests\Concerns\RestoresBoardSettingsStorage;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 설정 저장소 스냅샷·복원 트레이트의 경계 상태 검증 (#789 P17)
 *
 * 기반 클래스가 시험마다 뜨고 되돌리는 동작을 별도 탐침 객체로 직접 돌려, 저장소 디렉터리가 없던
 * 첫 실행과 파일 일부만 있던 상태 모두 원래대로 돌아오는지 본다. 탐침이 디렉터리를 지우고 써도
 * 이 시험 자체의 tearDown 이 시험 전 상태로 되돌린다.
 */
class BoardSettingsStorageRestoreTraitTest extends ModuleTestCase
{
    /**
     * 디렉터리가 없던 상태(첫 실행)에서 떠 두면, 시험이 디렉터리·파일을 만들어도 오류 없이 디렉터리째 지웁니다.
     *
     * @scenario case=storage_absent_on_first_run
     *
     * @effects directory_created_during_test_removed
     *
     * @return void 반환값 없음
     */
    public function test_restore_removes_directory_created_when_none_existed(): void
    {
        $path = ExtensionStoragePath::module('sirsoft-board', 'settings');
        File::deleteDirectory($path);
        $this->assertDirectoryDoesNotExist($path);

        $probe = $this->makeProbe();
        $probe->snapshot();

        $service = app(BoardSettingsService::class);
        $service->setSetting('report_policy.auto_hide_threshold', 100);
        $this->assertFileExists($path.'/report_policy.json');

        $probe->restore();

        $this->assertDirectoryDoesNotExist($path);

        // 두 번 되돌려도 깨지지 않는다 (스냅샷은 한 번 쓰면 비워진다)
        $probe->restore();
        $this->assertDirectoryDoesNotExist($path);
    }

    /**
     * 파일 일부만 있던 상태에서 떠 두면, 바뀐 파일은 원래 내용으로, 새 파일은 지우고, 지워진 파일은 되살립니다.
     *
     * @scenario case=storage_partially_present
     *
     * @effects changed_file_restored_new_file_removed, deleted_directory_recreated
     *
     * @return void 반환값 없음
     */
    public function test_restore_rewrites_changed_removes_new_and_recreates_deleted_files(): void
    {
        $path = ExtensionStoragePath::module('sirsoft-board', 'settings');
        File::deleteDirectory($path);
        File::makeDirectory($path, 0755, true);
        $original = json_encode(['auto_hide_threshold' => 7], JSON_PRETTY_PRINT);
        File::put($path.'/report_policy.json', $original);

        $probe = $this->makeProbe();
        $probe->snapshot();

        $service = app(BoardSettingsService::class);
        $service->setSetting('report_policy.auto_hide_threshold', 100);
        $service->setSetting('basic_defaults.per_page', 77);
        $this->assertFileExists($path.'/basic_defaults.json');

        $probe->restore();

        $this->assertSame($original, File::get($path.'/report_policy.json'));
        $this->assertFileDoesNotExist($path.'/basic_defaults.json');

        // 시험이 디렉터리를 통째로 지운 경우(설정 시험들의 tearDown)도 되살린다
        $probe->snapshot();
        File::deleteDirectory($path);
        $probe->restore();

        $this->assertSame($original, File::get($path.'/report_policy.json'));
    }

    /**
     * 트레이트 메서드를 공개로 드러낸 탐침 객체를 만듭니다.
     *
     * @return object snapshot()·restore() 를 가진 탐침
     */
    private function makeProbe(): object
    {
        return new class
        {
            use RestoresBoardSettingsStorage;

            /**
             * 저장소를 떠 둡니다.
             *
             * @return void 반환값 없음
             */
            public function snapshot(): void
            {
                $this->snapshotBoardSettingsStorage();
            }

            /**
             * 저장소를 떠 둔 상태로 되돌립니다.
             *
             * @return void 반환값 없음
             */
            public function restore(): void
            {
                $this->restoreBoardSettingsStorage();
            }
        };
    }
}
