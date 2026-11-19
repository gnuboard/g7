<?php

namespace Tests\Support;

use App\Contracts\Extension\StorageInterface;
use App\Extension\Storage\CoreStorageDriver;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 테스트용 사이트맵 저장소 — 사이트맵 파일이 실제 storage 경로에 쓰이지 않게 합니다.
 *
 * 제품은 사이트맵을 `local` 디스크(storage/app/private)의 `cache/sitemap` 에 씁니다. 테스트는 큐가
 * `sync` 이므로 게시판·게시글·상품·페이지를 만들기만 해도 리스너가 사이트맵 재생성 잡을 즉시 실행하고,
 * 그 잡이 테스트 DB 의 색인(테스트 주소)으로 실제 사이트맵 파일을 덮어씁니다. 같은 폴더에서 개발 서버를
 * 띄우고 있으면 검색 로봇이 테스트 주소를 받아 가게 됩니다.
 *
 * 그래서 TestCase 가 사이트맵 소비자(SitemapManager·SitemapFileStore)에 이 저장소를 주입합니다.
 * 호출마다 디스크를 고릅니다.
 * - 테스트가 `Storage::fake('local')` 로 `local` 디스크를 페이크로 바꿨으면 그 `local` 을 씁니다
 *   (사이트맵 파일을 `local` 디스크에서 단언하는 기존 테스트가 그대로 동작합니다).
 * - 아니면 페이크 전용 디스크 {@see self::FALLBACK_DISK} 를 씁니다.
 *
 * 생성 시점이 아니라 호출 시점에 고르므로, 테스트가 setUp 뒤에 `local` 을 페이크로 바꿔도
 * 그 전에 만들어진 싱글톤(SitemapFileStore)이 같은 디스크를 봅니다.
 */
final class IsolatedSitemapStorage implements StorageInterface
{
    /**
     * `local` 이 페이크가 아닐 때 쓰는 페이크 전용 디스크 이름
     */
    public const FALLBACK_DISK = 'sitemap-testing';

    /**
     * withDisk() 로 고정한 디스크 (null 이면 호출마다 고름)
     */
    private ?string $pinnedDisk = null;

    /**
     * 페이크 전용 디스크를 준비합니다. TestCase 가 앱 생성 직후 한 번 호출합니다.
     *
     * @return void 반환값 없음
     */
    public static function prepareFallbackDisk(): void
    {
        config([
            'filesystems.disks.'.self::FALLBACK_DISK => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/'.self::FALLBACK_DISK),
                'serve' => false,
                'throw' => false,
            ],
        ]);

        Storage::fake(self::FALLBACK_DISK);
    }

    /**
     * 해석된 디스크가 테스트 전용 경로(storage/framework/testing)를 가리키는지 확인합니다.
     *
     * config 의 root 는 페이크 후에도 실경로 그대로이므로 해석된 디스크의 경로로 판정합니다.
     *
     * @param  string  $disk  디스크 이름
     * @return bool 테스트 전용 경로 여부
     */
    public static function diskIsTestingPath(string $disk): bool
    {
        $root = str_replace('\\', '/', Storage::disk($disk)->path(''));
        $testingRoot = str_replace('\\', '/', storage_path('framework/testing/'));

        return str_starts_with($root, $testingRoot);
    }

    /**
     * 이번 호출에 쓸 디스크 이름을 반환합니다.
     *
     * @return string 디스크 이름
     */
    private function disk(): string
    {
        if ($this->pinnedDisk !== null) {
            return $this->pinnedDisk;
        }

        return self::diskIsTestingPath('local') ? 'local' : self::FALLBACK_DISK;
    }

    /**
     * 이번 호출에 쓸 코어 드라이버를 반환합니다.
     *
     * @return CoreStorageDriver 코어 드라이버
     */
    private function driver(): CoreStorageDriver
    {
        return new CoreStorageDriver($this->disk());
    }

    /**
     * {@inheritDoc}
     */
    public function put(string $category, string $path, mixed $content): bool
    {
        return $this->driver()->put($category, $path, $content);
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $category, string $path): ?string
    {
        return $this->driver()->get($category, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function exists(string $category, string $path): bool
    {
        return $this->driver()->exists($category, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $category, string $path): bool
    {
        return $this->driver()->delete($category, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function url(string $category, string $path): ?string
    {
        return $this->driver()->url($category, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function files(string $category, string $directory = ''): array
    {
        return $this->driver()->files($category, $directory);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteDirectory(string $category, string $directory = ''): bool
    {
        return $this->driver()->deleteDirectory($category, $directory);
    }

    /**
     * {@inheritDoc}
     */
    public function getBasePath(string $category): string
    {
        return $this->driver()->getBasePath($category);
    }

    /**
     * {@inheritDoc}
     */
    public function getDisk(): string
    {
        return $this->disk();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteAll(string $category): bool
    {
        return $this->driver()->deleteAll($category);
    }

    /**
     * {@inheritDoc}
     */
    public function response(string $category, string $path, string $filename, array $headers = []): ?StreamedResponse
    {
        return $this->driver()->response($category, $path, $filename, $headers);
    }

    /**
     * {@inheritDoc}
     */
    public function withDisk(string $disk): static
    {
        $clone = clone $this;
        $clone->pinnedDisk = $disk;

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function download(string $category, string $path, string $filename, array $headers = []): ?StreamedResponse
    {
        return $this->driver()->download($category, $path, $filename, $headers);
    }
}
