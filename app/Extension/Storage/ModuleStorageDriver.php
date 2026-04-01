<?php

namespace App\Extension\Storage;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Support\Facades\Storage;

/**
 * 모듈 스토리지 드라이버
 *
 * 모듈별로 격리된 파일 저장소를 제공합니다.
 * 경로 패턴: storage/app/modules/{identifier}/{category}/{path}
 */
class ModuleStorageDriver implements StorageInterface
{
    /**
     * 모듈 식별자
     */
    private string $identifier;

    /**
     * 사용할 디스크 이름
     */
    private string $disk;

    /**
     * ModuleStorageDriver 생성자
     *
     * @param  string  $identifier  모듈 식별자 (예: sirsoft-board)
     * @param  string  $disk  디스크 이름 (예: local, public, s3)
     */
    public function __construct(string $identifier, string $disk = 'local')
    {
        $this->identifier = $identifier;
        $this->disk = $disk;
    }

    /**
     * 카테고리와 경로를 조합하여 전체 경로를 생성합니다.
     *
     * @param  string  $category  카테고리
     * @param  string  $path  상대 경로
     * @return string 전체 경로 ({identifier}/{category}/{path})
     */
    private function resolvePath(string $category, string $path): string
    {
        $basePath = "{$this->identifier}/{$category}";

        if (empty($path)) {
            return $basePath;
        }

        return "{$basePath}/{$path}";
    }

    /**
     * {@inheritDoc}
     */
    public function put(string $category, string $path, mixed $content): bool
    {
        $fullPath = $this->resolvePath($category, $path);

        return Storage::disk($this->disk)->put($fullPath, $content);
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $category, string $path): ?string
    {
        $fullPath = $this->resolvePath($category, $path);

        if (! Storage::disk($this->disk)->exists($fullPath)) {
            return null;
        }

        return Storage::disk($this->disk)->get($fullPath);
    }

    /**
     * {@inheritDoc}
     */
    public function exists(string $category, string $path): bool
    {
        $fullPath = $this->resolvePath($category, $path);

        return Storage::disk($this->disk)->exists($fullPath);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $category, string $path): bool
    {
        $fullPath = $this->resolvePath($category, $path);

        return Storage::disk($this->disk)->delete($fullPath);
    }

    /**
     * {@inheritDoc}
     */
    public function url(string $category, string $path): ?string
    {
        // public disk인 경우에만 직접 URL 반환
        if ($this->disk !== 'public') {
            return null;
        }

        $fullPath = $this->resolvePath($category, $path);

        return Storage::disk($this->disk)->url($fullPath);
    }

    /**
     * {@inheritDoc}
     */
    public function files(string $category, string $directory = ''): array
    {
        $fullPath = $this->resolvePath($category, $directory);

        return Storage::disk($this->disk)->files($fullPath);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteDirectory(string $category, string $directory = ''): bool
    {
        $fullPath = $this->resolvePath($category, $directory);

        return Storage::disk($this->disk)->deleteDirectory($fullPath);
    }

    /**
     * {@inheritDoc}
     */
    public function getBasePath(string $category): string
    {
        $fullPath = $this->resolvePath($category, '');

        return Storage::disk($this->disk)->path($fullPath);
    }

    /**
     * {@inheritDoc}
     */
    public function getDisk(): string
    {
        return $this->disk;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteAll(string $category): bool
    {
        return $this->deleteDirectory($category, '');
    }

    /**
     * {@inheritDoc}
     */
    public function response(string $category, string $path, string $filename, array $headers = []): ?\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $fullPath = $this->resolvePath($category, $path);

        if (! Storage::disk($this->disk)->exists($fullPath)) {
            return null;
        }

        return Storage::disk($this->disk)->response($fullPath, $filename, $headers);
    }

    /**
     * {@inheritDoc}
     */
    public function withDisk(string $disk): static
    {
        $clone = clone $this;
        $clone->disk = $disk;

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function download(string $category, string $path, string $filename, array $headers = []): ?\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $fullPath = $this->resolvePath($category, $path);

        if (! Storage::disk($this->disk)->exists($fullPath)) {
            return null;
        }

        return Storage::disk($this->disk)->download($fullPath, $filename, $headers);
    }
}
