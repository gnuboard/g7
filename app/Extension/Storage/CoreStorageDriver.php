<?php

namespace App\Extension\Storage;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Support\Facades\Storage;

/**
 * 코어 스토리지 드라이버
 *
 * 코어 서비스(AttachmentService 등)에서 사용하는 파일 저장소를 제공합니다.
 * 모듈/플러그인과 달리 identifier 없이 {category}/{path} 경로 패턴을 사용합니다.
 */
class CoreStorageDriver implements StorageInterface
{
    /**
     * 사용할 디스크 이름
     */
    private string $disk;

    /**
     * CoreStorageDriver 생성자
     *
     * @param  string  $disk  디스크 이름 (예: local, public, s3)
     */
    public function __construct(string $disk = 'local')
    {
        $this->disk = $disk;
    }

    /**
     * 카테고리와 경로를 조합하여 전체 경로를 생성합니다.
     *
     * @param  string  $category  카테고리 (빈 문자열이면 경로만 사용)
     * @param  string  $path  상대 경로
     * @return string 전체 경로 ({category}/{path} 또는 {path})
     */
    private function resolvePath(string $category, string $path): string
    {
        if (empty($category)) {
            return $path;
        }

        if (empty($path)) {
            return $category;
        }

        return "{$category}/{$path}";
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
