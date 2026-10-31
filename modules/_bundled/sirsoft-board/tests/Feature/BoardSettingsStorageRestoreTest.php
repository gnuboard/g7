<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Board\Services\BoardSettingsService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 시험이 쓴 게시판 설정이 다음 시험으로 새지 않는지 검증 (#789 P17)
 *
 * 설정 저장소는 DB 롤백이 닿지 않는 실제 디렉터리다. 신고 알림 시험이 저장한 `auto_hide_threshold = 100`
 * 이 되돌려지지 않아 뒤에 도는 샘플 시더 시험이 환경에 따라 실패했다. 앞 시험이 쓰고, 뒤 시험이
 * 저장소가 앞 시험 시작 전 상태 그대로인지 본다 — 두 시험은 선언 순서대로 같은 프로세스에서 돈다.
 */
class BoardSettingsStorageRestoreTest extends ModuleTestCase
{
    /**
     * 앞 시험이 쓰기 직전의 설정 저장소 파일 내용 (디렉터리가 없었으면 null)
     *
     * @var array<string, string>|null
     */
    private static ?array $before = null;

    /**
     * 앞 시험이 실제로 저장소를 바꿨는지 (뒤 시험의 전제)
     */
    private static bool $written = false;

    /**
     * 단건 저장(setSetting)·통째 저장(saveSettings)으로 저장소를 바꿉니다.
     *
     * @scenario case=previous_test_writes_do_not_leak
     *
     * @effects next_test_sees_storage_as_before_previous_test
     *
     * @return void 반환값 없음
     */
    public function test_a_test_writes_report_policy_and_other_categories(): void
    {
        $path = $this->settingsPath();
        self::$before = is_dir($path) ? $this->readFiles($path) : null;

        $service = app(BoardSettingsService::class);
        $service->setSetting('report_policy.auto_hide_threshold', 100);
        $service->saveSettings(['basic_defaults' => ['per_page' => 77]]);
        $service->clearCache();

        $this->assertSame(100, json_decode(File::get($path.'/report_policy.json'), true)['auto_hide_threshold']);
        $this->assertFileExists($path.'/basic_defaults.json');

        self::$written = true;
    }

    /**
     * 앞 시험이 쓴 값이 남지 않고, 저장소가 앞 시험 시작 전 상태 그대로입니다.
     *
     * @scenario case=previous_test_writes_do_not_leak
     *
     * @effects next_test_sees_storage_as_before_previous_test
     *
     * @return void 반환값 없음
     */
    public function test_b_next_test_sees_storage_as_it_was_before_previous_test(): void
    {
        $this->assertTrue(self::$written, '앞 시험(test_a)이 먼저 돌아 저장소를 바꿔야 한다 — 단독 실행 금지');

        $path = $this->settingsPath();
        $now = is_dir($path) ? $this->readFiles($path) : null;

        $this->assertSame(self::$before, $now, '앞 시험이 쓴 설정 파일이 되돌려지지 않았다');
    }

    /**
     * 게시판 설정 저장소 경로를 반환합니다.
     *
     * @return string 디렉터리 경로
     */
    private function settingsPath(): string
    {
        return ExtensionStoragePath::module('sirsoft-board', 'settings');
    }

    /**
     * 디렉터리 바로 아래 파일의 이름 ⇒ 내용 맵을 읽습니다.
     *
     * @param  string  $path  디렉터리 경로
     * @return array<string, string> 파일명 ⇒ 내용
     */
    private function readFiles(string $path): array
    {
        $files = [];
        foreach (File::files($path) as $file) {
            $files[$file->getFilename()] = $file->getContents();
        }
        ksort($files);

        return $files;
    }
}
