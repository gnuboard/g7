<?php

namespace App\Repositories;

use App\Contracts\Repositories\ExtensionUpdateJournalRepositoryInterface;
use App\Extension\Helpers\ExtensionUpdateJournal;
use App\Extension\Helpers\FilePermissionHelper;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 확장 업데이트 진행 기록 Repository (JSON 파일 기반)
 *
 * 확장 하나당 파일 하나(`storage/app/extension_update_journal/{type}/{identifier}.json`)에 기록을 둔다. sudo 업데이트와
 * php-fpm 업데이트가 같은 기록을 이어 써야 하므로 디렉토리는 업데이트 백업 디렉토리와 같은 권한 규칙을 따른다.
 */
class ExtensionUpdateJournalRepository implements ExtensionUpdateJournalRepositoryInterface
{
    /**
     * 기록 디렉토리 (storage 기준)
     */
    private const DIRECTORY = 'app/extension_update_journal';

    /**
     * {@inheritDoc}
     */
    public function find(string $type, string $identifier): ?ExtensionUpdateJournal
    {
        $path = $this->path($type, $identifier);
        if (! is_file($path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) File::get($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            Log::warning('확장 업데이트 진행 기록을 읽지 못했습니다 — 기록 없이 진행합니다', [
                'type' => $type,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! is_array($decoded) || ! is_string($decoded['from_version'] ?? null) || ! is_string($decoded['to_version'] ?? null)) {
            return null;
        }

        return new ExtensionUpdateJournal($type, $identifier, [
            'from_version' => $decoded['from_version'],
            'to_version' => $decoded['to_version'],
            'source' => is_string($decoded['source'] ?? null) ? $decoded['source'] : null,
            'files_touched' => (bool) ($decoded['files_touched'] ?? false),
            'completed_steps' => array_values(array_filter((array) ($decoded['completed_steps'] ?? []), 'is_string')),
            'started_at' => (string) ($decoded['started_at'] ?? ''),
            'resumed' => (bool) ($decoded['resumed'] ?? false),
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function save(ExtensionUpdateJournal $journal): void
    {
        $path = $this->path($journal->type(), $journal->identifier());

        try {
            // sudo 업데이트와 php-fpm 업데이트가 같은 기록을 이어 써야 하므로 백업 디렉토리와 같은 권한 규칙을 따른다
            foreach ([storage_path(self::DIRECTORY), dirname($path)] as $dir) {
                File::ensureDirectoryExists($dir, 0775);
                FilePermissionHelper::inheritOwnershipFromParent($dir);
                FilePermissionHelper::syncGroupWritability($dir);
            }

            File::put($path, json_encode($journal->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), true);
            @chmod($path, 0664);
        } catch (\Throwable $e) {
            Log::warning('확장 업데이트 진행 기록을 쓰지 못했습니다 — 기록 없이 진행합니다', [
                'type' => $journal->type(),
                'identifier' => $journal->identifier(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $type, string $identifier): void
    {
        $path = $this->path($type, $identifier);
        if (! is_file($path)) {
            return;
        }

        try {
            File::delete($path);
        } catch (\Throwable $e) {
            Log::warning('확장 업데이트 진행 기록을 지우지 못했습니다', [
                'type' => $type,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 기록 파일 경로를 반환합니다.
     *
     * @param  string  $type  확장 종류 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @return string 기록 파일 절대 경로
     */
    private function path(string $type, string $identifier): string
    {
        return storage_path(self::DIRECTORY."/{$type}/{$identifier}.json");
    }
}
