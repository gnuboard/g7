<?php

namespace Plugins\Sirsoft\Popup\Services;

use App\Extension\Helpers\FilePermissionHelper;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\Popup\Exceptions\PopupImageUploadException;

/**
 * 파일 쓰기 전 로컬 대장 — DB·원격 저장소 장애에도 요청이 만든 파일의 재정리 근거를 남긴다.
 *
 * 이미지 행이 생기면 삭제 대상에서 제외한다. 미완료 항목만 보존기간 뒤 기존 정리 명령이 다시 확인한다.
 */
class PendingImageFiles
{
    /**
     * 파일을 쓰기 전에 이 요청의 고유 경로와 디스크를 기록합니다. 기록 실패면 쓰기를 시작하지 않는다.
     *
     * @param  string  $hash  새 파일 해시
     * @param  string  $path  카테고리 안 상대경로
     * @param  string  $disk  저장소 디스크
     * @return void 반환값 없음
     */
    public function remember(string $hash, string $path, string $disk): void
    {
        $dir = $this->directory();
        $record = json_encode([
            'hash' => $hash, 'path' => $path, 'disk' => $disk, 'created_at' => now()->getTimestamp(),
        ], JSON_THROW_ON_ERROR);
        if (! FilePermissionHelper::ensureWritableDirectory($dir, 0775, $failure)
            || file_put_contents($dir.'/'.$hash.'.json', $record, LOCK_EX) !== strlen($record)) {
            throw new PopupImageUploadException;
        }
    }

    /**
     * 파일 정리 또는 행 저장이 끝난 항목을 대장에서 지웁니다. 실패하면 다음 정리 때 행을 대조한다.
     *
     * @param  string  $hash  새 파일 해시
     * @return void 반환값 없음
     */
    public function forget(string $hash): void
    {
        $file = $this->directory().'/'.$hash.'.json';
        if (is_file($file) && ! @unlink($file)) {
            Log::error('팝업 이미지 정리 대장 항목 제거 실패 — 다음 정리에서 행을 다시 확인합니다', ['hash' => $hash]);
        }
    }

    /**
     * 보존기간이 지난 미완료 파일을 제한 수만큼 읽습니다. 손상 항목은 지우지 않고 알린다.
     *
     * @param  int  $before  이 시각보다 먼저 기록한 항목(epoch 초)
     * @param  int  $limit  최대 항목 수
     * @return array<int, array{hash: string, path: string, disk: string, created_at: int}> 재확인 항목
     */
    public function stale(int $before, int $limit): array
    {
        $records = [];
        foreach ($this->recordFiles() as $file) {
            try {
                $row = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($row) || ! is_string($row['path'] ?? null) || ! is_string($row['hash'] ?? null)
                    || ! preg_match('~^\\d{4}/\\d{2}/'.preg_quote($row['hash'], '~').'\\.(jpg|png|webp|gif)$~D', $row['path'])
                    || ! preg_match('/^[A-Za-z0-9]{64}$/D', $row['hash']) || basename($file) !== $row['hash'].'.json'
                    || ! is_string($row['disk'] ?? null) || ! is_int($row['created_at'] ?? null)) {
                    Log::error('팝업 이미지 정리 대장 항목 형식 오류', ['record' => basename($file)]);

                    continue;
                }
                if ($row['created_at'] < $before) {
                    $records[] = $row;
                    if (count($records) >= $limit) {
                        break;
                    }
                }
            } catch (\Throwable $e) {
                Log::error('팝업 이미지 정리 대장 항목 읽기 실패', ['record' => basename($file)]);
            }
        }

        return $records;
    }

    /**
     * 대장 디렉토리의 `*.json` 항목 경로를 이름순으로 반환합니다.
     *
     * `glob` 은 호스팅이 막을 수 있는 함수라(PHP 8 은 막힌 함수 호출이 `Error`) `scandir` 로 나열한다 — 막힌 서버에서
     * 매일 정리 작업이 죽지 않게. `glob('*.json')` 과 같게 점으로 시작하는 이름은 빼고 이름순을 지킨다.
     *
     * @return array<int, string> 항목 파일 경로
     */
    private function recordFiles(): array
    {
        $directory = $this->directory();
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        foreach (scandir($directory) ?: [] as $name) {
            if ($name[0] === '.' || ! str_ends_with($name, '.json') || ! is_file($directory.'/'.$name)) {
                continue;
            }
            $files[] = $directory.'/'.$name;
        }

        return $files;
    }

    /**
     * 원격 저장소와 독립된 로컬 대장 디렉토리를 반환합니다.
     *
     * @return string 로컬 디렉토리
     */
    private function directory(): string
    {
        return ExtensionStoragePath::plugin('sirsoft-popup', 'cleanup/pending-images');
    }
}
