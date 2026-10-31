<?php

namespace App\Extension\Storage;

use App\Contracts\Extension\StorageInterface;
use App\Exceptions\UnsafeStoragePathException;
use App\Extension\Storage\Concerns\GuardsStoragePaths;
use App\Extension\Storage\Concerns\ResolvesPublicUrl;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 모듈 스토리지 드라이버
 *
 * 모듈별로 격리된 파일 저장소를 제공합니다.
 * 경로 패턴: storage/app/modules/{identifier}/{category}/{path}
 */
class ModuleStorageDriver implements StorageInterface
{
    use GuardsStoragePaths;
    use ResolvesPublicUrl;

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
     * 카테고리·경로 조각을 결합 전에 검사하고, 결합 결과가 `{identifier}/{category}`
     * 하위인지 다시 확인합니다 (GuardsStoragePaths).
     *
     * @param  string  $category  카테고리
     * @param  string  $path  상대 경로
     * @return string 전체 경로 ({identifier}/{category}/{path})
     *
     * @throws UnsafeStoragePathException 빈 카테고리, 순회·절대경로·인코딩 조각, 기준 밖 결과
     */
    private function resolvePath(string $category, string $path): string
    {
        if ($category === '') {
            // 빈 카테고리는 확장 루트를 가리킨다 — 다른 거부와 같이 보안 로그를 남긴다
            $this->rejectStoragePath('empty_category', 'category', $category);
        }

        $this->assertSafeRelativePath($category, 'category');
        $this->assertSafeRelativePath($path, 'path');

        $basePath = "{$this->identifier}/{$category}";
        $fullPath = $path === '' ? $basePath : "{$basePath}/{$path}";

        $this->assertResolvedWithinBase($fullPath, $basePath);

        return $fullPath;
    }

    /**
     * 모듈 storage 루트에 `.preserve-ownership` 마커가 없으면 작성합니다 (멱등).
     *
     * 코어 update 의 `FilePermissionHelper::chownRecursiveDetailed` 가
     * `respectPreservationMarker=true` 로 호출되면 본 마커가 있는 디렉토리 서브트리
     * 전체를 chown 비대상으로 자동 skip → 시드 시점 owner/perms 영구 보존.
     *
     * @return void
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
     * `core.storage.filter_url` 훅 컨텍스트의 드라이버별 식별 정보를 반환합니다.
     *
     * @return array{scope: string, identifier: ?string} scope + 모듈 식별자
     */
    protected function urlHookContext(): array
    {
        return [
            'scope' => 'module',
            'identifier' => $this->identifier,
        ];
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
     *
     * 빈 디렉토리 인자는 인터페이스 계약대로 카테고리 루트를 지웁니다. 그보다 위(확장 루트·디스크 루트)는
     * 지우지 않습니다 — 순회 경로는 resolvePath() 가 먼저 거부합니다.
     *
     * @throws UnsafeStoragePathException 디스크·확장 루트 대상 또는 안전하지 않은 경로
     */
    public function deleteDirectory(string $category, string $directory = ''): bool
    {
        $fullPath = $this->resolvePath($category, $directory);

        $this->assertNotDestructiveOnRoot(
            $fullPath,
            [$this->identifier],
            'deleteDirectory',
            $category
        );

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
     *
     * 카테고리 전체를 지웁니다(deleteDirectory($category, '') 와 같음). 디스크·확장 루트는 지우지 않습니다.
     *
     * @throws UnsafeStoragePathException 디스크·확장 루트 대상
     */
    public function deleteAll(string $category): bool
    {
        $fullPath = $this->resolvePath($category, '');

        $this->assertNotDestructiveOnRoot($fullPath, [$this->identifier], 'deleteAll', $category);

        return Storage::disk($this->disk)->deleteDirectory($fullPath);
    }

    /**
     * {@inheritDoc}
     */
    public function response(string $category, string $path, string $filename, array $headers = []): ?StreamedResponse
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
    public function download(string $category, string $path, string $filename, array $headers = []): ?StreamedResponse
    {
        $fullPath = $this->resolvePath($category, $path);

        if (! Storage::disk($this->disk)->exists($fullPath)) {
            return null;
        }

        return Storage::disk($this->disk)->download($fullPath, $filename, $headers);
    }
}
