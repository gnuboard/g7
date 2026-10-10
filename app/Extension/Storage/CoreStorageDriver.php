<?php

namespace App\Extension\Storage;

use App\Contracts\Extension\StorageInterface;
use App\Exceptions\UnsafeStoragePathException;
use App\Extension\Storage\Concerns\GuardsStoragePaths;
use App\Extension\Storage\Concerns\ResolvesPublicUrl;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 코어 스토리지 드라이버
 *
 * 코어 서비스(AttachmentService 등)에서 사용하는 파일 저장소를 제공합니다.
 * 모듈/플러그인과 달리 identifier 없이 {category}/{path} 경로 패턴을 사용합니다.
 */
class CoreStorageDriver implements StorageInterface
{
    use GuardsStoragePaths;
    use ResolvesPublicUrl;

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
     * 카테고리·경로 조각을 결합 전에 검사하고, 결합 결과가 카테고리(빈 카테고리면
     * 디스크 루트) 하위인지 다시 확인합니다 (GuardsStoragePaths).
     *
     * @param  string  $category  카테고리 (빈 문자열이면 경로만 사용)
     * @param  string  $path  상대 경로
     * @return string 전체 경로 ({category}/{path} 또는 {path})
     *
     * @throws UnsafeStoragePathException 순회·절대경로·인코딩 조각, 기준 밖 결과
     */
    private function resolvePath(string $category, string $path): string
    {
        $this->assertSafeRelativePath($category, 'category');
        $this->assertSafeRelativePath($path, 'path');

        if ($category === '') {
            $fullPath = $path;
        } elseif ($path === '') {
            $fullPath = $category;
        } else {
            $fullPath = "{$category}/{$path}";
        }

        $this->assertResolvedWithinBase($fullPath, $category);

        return $fullPath;
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
     * `core.storage.filter_url` 훅 컨텍스트의 드라이버별 식별 정보를 반환합니다.
     *
     * @return array{scope: string, identifier: ?string} scope + 식별자 (코어는 null)
     */
    protected function urlHookContext(): array
    {
        return [
            'scope' => 'core',
            'identifier' => null,
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
     * 빈 디렉토리 인자는 인터페이스 계약대로 카테고리 루트를 지웁니다. 디스크 루트는 지우지 않습니다 —
     * 순회 경로는 resolvePath() 가 먼저 거부합니다.
     *
     * @throws UnsafeStoragePathException 디스크 루트 대상 또는 안전하지 않은 경로
     */
    public function deleteDirectory(string $category, string $directory = ''): bool
    {
        $fullPath = $this->resolvePath($category, $directory);

        $this->assertNotDestructiveOnRoot($fullPath, [], 'deleteDirectory', $category);

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
     * 카테고리 전체를 지웁니다(deleteDirectory($category, '') 와 같음). 빈 카테고리(디스크 루트)는 지우지 않습니다.
     *
     * @throws UnsafeStoragePathException 디스크 루트 대상
     */
    public function deleteAll(string $category): bool
    {
        $fullPath = $this->resolvePath($category, '');

        $this->assertNotDestructiveOnRoot($fullPath, [], 'deleteAll', $category);

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
