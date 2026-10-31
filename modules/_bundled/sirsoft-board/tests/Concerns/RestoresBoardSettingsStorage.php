<?php

namespace Modules\Sirsoft\Board\Tests\Concerns;

use App\Support\ExtensionStoragePath;

/**
 * 게시판 설정 저장소 스냅샷·복원 (모든 게시판 시험 공용)
 *
 * 테스트 환경의 모듈 설정 저장소(`storage/framework/testing/modules/sirsoft-board/settings`)는
 * DB 트랜잭션 롤백이 닿지 않는 실제 디렉터리라, 시험이 `setSetting`·`saveSettings`·설정 저장 API 로
 * 쓴 값이 다음 시험·다음 실행으로 새어 나간다. 신고 알림 시험이 남긴 `auto_hide_threshold = 100` 이
 * 샘플 시더 시험의 「대량 신고 케이스」 기준을 바꿔 환경마다 결과가 갈린 것이 그 예다(#789 P17).
 *
 * 시험 시작 시 디렉터리의 파일 내용을 그대로 떠 두고, 끝나면 그 상태로 되돌린다 — 바뀐 파일은
 * 원래 내용으로, 새로 생긴 파일은 지우고, 원래 없던 디렉터리는 통째로 지운다. 바뀌지 않은 파일은
 * 건드리지 않는다.
 *
 * ModuleTestCase 가 setUp 첫머리에서 뜨고 tearDown 끝에서 되돌리므로, 하위 시험이 자기 setUp·
 * tearDown 에서 디렉터리를 지우거나 다시 쓰더라도 최종 상태는 시험 전 상태다.
 */
trait RestoresBoardSettingsStorage
{
    /**
     * 시험 시작 시점의 설정 저장소 상태 — 디렉터리가 없었으면 files 가 null
     *
     * @var array{path: string, files: array<string, string>|null}|null
     */
    private ?array $boardSettingsSnapshot = null;

    /**
     * 설정 저장소의 현재 파일 내용을 떠 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function snapshotBoardSettingsStorage(): void
    {
        $path = ExtensionStoragePath::module('sirsoft-board', 'settings');

        $this->boardSettingsSnapshot = [
            'path' => $path,
            'files' => is_dir($path) ? $this->readBoardSettingsFiles($path) : null,
        ];
    }

    /**
     * 설정 저장소를 떠 둔 상태로 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function restoreBoardSettingsStorage(): void
    {
        if ($this->boardSettingsSnapshot === null) {
            return;
        }

        ['path' => $path, 'files' => $original] = $this->boardSettingsSnapshot;
        $this->boardSettingsSnapshot = null;

        if ($original === null) {
            if (is_dir($path)) {
                foreach ($this->readBoardSettingsFiles($path) as $name => $_) {
                    @unlink($path.DIRECTORY_SEPARATOR.$name);
                }
                @rmdir($path);
            }

            return;
        }

        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $current = $this->readBoardSettingsFiles($path);

        foreach (array_diff_key($current, $original) as $name => $_) {
            @unlink($path.DIRECTORY_SEPARATOR.$name);
        }

        foreach ($original as $name => $content) {
            if (($current[$name] ?? null) !== $content) {
                file_put_contents($path.DIRECTORY_SEPARATOR.$name, $content);
            }
        }
    }

    /**
     * 디렉터리 바로 아래 일반 파일의 이름 ⇒ 내용 맵을 읽습니다.
     *
     * @param  string  $path  설정 저장소 디렉터리
     * @return array<string, string> 파일명 ⇒ 내용
     */
    private function readBoardSettingsFiles(string $path): array
    {
        $files = [];

        foreach (scandir($path) ?: [] as $name) {
            $file = $path.DIRECTORY_SEPARATOR.$name;
            if (is_file($file)) {
                $files[$name] = (string) file_get_contents($file);
            }
        }

        return $files;
    }
}
