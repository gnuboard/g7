<?php

namespace App\Extension\Storage;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Support\Facades\Storage;

/**
 * 플러그인 스토리지 드라이버
 *
 * 플러그인별로 격리된 파일 저장소를 제공합니다.
 * 경로 패턴: storage/app/plugins/{identifier}/{category}/{path}
 */
class PluginStorageDriver implements StorageInterface
{
    /**
     * 플러그인 식별자
     */
    private string $identifier;

    /**
     * 사용할 디스크 이름
     */
    private string $disk;

    /**
     * PluginStorageDriver 생성자
     *
     * @param  string  $identifier  플러그인 식별자 (예: sirsoft-payment)
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
     * 플러그인 storage 루트에 `.preserve-ownership` 마커가 없으면 작성합니다 (멱등).
     *
     * 코어 update 의 `FilePermissionHelper::chownRecursiveDetailed` 가
     * `respectPreservationMarker=true` 로 호출되면 본 마커가 있는 디렉토리 서브트리
     * 전체를 chown 비대상으로 자동 skip → 시드 시점 owner/perms 영구 보존.
     */
    private function ensurePreservationMarker(): void
    {
        $markerPath = "{$this->identifier}/.preserve-ownership";
        if (Storage::disk($this->disk)->exists($markerPath)) {
            return;
        }

        Storage::disk($this->disk)->put(
            $markerPath,
            "# G7 preservation marker\n# 코어 update 의 chownRecursive 가 본 디렉토리 트리를 자동 skip 합니다.\n"
        );
    }

    /**
     * {@inheritDoc}
     */
    public function put(string $category, string $path, mixed $content): bool
    {
        $fullPath = $this->resolvePath($category, $path);
        $this->ensurePreservationMarker();

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
