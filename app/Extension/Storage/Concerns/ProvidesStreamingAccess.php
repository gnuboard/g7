<?php

namespace App\Extension\Storage\Concerns;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * `StreamingStorageInterface` 구현 — 코어 저장소 드라이버 셋이 함께 쓴다.
 *
 * 쓰는 클래스는 `resolvePath(string $category, string $path): string`(경로 가드 포함)과 `$disk` 를 가져야 한다.
 * 모든 경로는 그 `resolvePath` 를 거친다 — 스트림 경로라고 가드를 우회하지 않는다.
 */
trait ProvidesStreamingAccess
{
    /**
     * {@inheritDoc}
     */
    public function readStream(string $category, string $path)
    {
        $fullPath = $this->resolvePath($category, $path);
        $disk = Storage::disk($this->disk);

        if (! $disk->exists($fullPath)) {
            return null;
        }

        return $disk->readStream($fullPath);
    }

    /**
     * {@inheritDoc}
     */
    public function writeStream(string $category, string $path, $stream): bool
    {
        $fullPath = $this->resolvePath($category, $path);

        if (method_exists($this, 'ensurePreservationMarker')) {
            $this->ensurePreservationMarker();
        }

        return (bool) Storage::disk($this->disk)->writeStream($fullPath, $stream);
    }

    /**
     * {@inheritDoc}
     */
    public function size(string $category, string $path): ?int
    {
        $fullPath = $this->resolvePath($category, $path);
        $disk = Storage::disk($this->disk);

        return $disk->exists($fullPath) ? (int) $disk->size($fullPath) : null;
    }

    /**
     * {@inheritDoc}
     */
    public function localPath(string $category, string $path): ?string
    {
        $fullPath = $this->resolvePath($category, $path);
        $disk = Storage::disk($this->disk);

        if (config("filesystems.disks.{$this->disk}.driver") !== 'local' || ! $disk->exists($fullPath)) {
            return null;
        }

        return $disk->path($fullPath);
    }

    /**
     * {@inheritDoc}
     */
    public function readRangeStream(string $category, string $path, int $start, int $end)
    {
        $fullPath = $this->resolvePath($category, $path);
        $disk = Storage::disk($this->disk);

        if ($start < 0 || $end < $start || ! $disk->exists($fullPath)) {
            return null;
        }

        // s3: 구간만 받는다 — 앞부분을 내려받아 버리지 않는다
        if ($disk instanceof FilesystemAdapter && config("filesystems.disks.{$this->disk}.driver") === 's3') {
            $client = method_exists($disk, 'getClient') ? $disk->getClient() : null;
            $config = $disk->getConfig();

            if ($client !== null) {
                $prefix = trim((string) ($config['root'] ?? ''), '/');
                $result = $client->getObject([
                    'Bucket' => $config['bucket'] ?? null,
                    'Key' => ($prefix !== '' ? $prefix.'/' : '').$fullPath,
                    'Range' => "bytes={$start}-{$end}",
                ]);

                return $result['Body']->detach();
            }
        }

        $stream = $disk->readStream($fullPath);
        if (! is_resource($stream)) {
            return null;
        }

        if ($start === 0) {
            return $stream;
        }

        $meta = stream_get_meta_data($stream);
        if (($meta['seekable'] ?? false) && fseek($stream, $start) === 0) {
            return $stream;
        }

        // 시크가 안 되는 스트림 — 앞부분을 읽어 버린다(큰 오프셋에서 느리다, s3 는 위에서 네이티브)
        $remaining = $start;
        while ($remaining > 0 && ! feof($stream)) {
            $chunk = fread($stream, min(1048576, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $remaining -= strlen($chunk);
        }

        return $stream;
    }
}
