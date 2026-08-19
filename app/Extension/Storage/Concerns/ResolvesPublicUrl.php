<?php

namespace App\Extension\Storage\Concerns;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Storage;

/**
 * 스토리지 드라이버 공용 공개 URL 해석 trait
 *
 * public 디스크이거나 `filesystems.disks.{disk}.url` 이 설정된 디스크(S3+CDN 등)면
 * 직접 URL 을 생성하고, 그 외에는 null 을 생성합니다. 생성 결과는 디스크 종류와 무관하게
 * `core.storage.filter_url` 필터 훅을 항상 통과하므로, 확장이 URL 을 공급/수정/차단할 수 있습니다.
 */
trait ResolvesPublicUrl
{
    /**
     * 파일의 공개 URL을 반환합니다.
     *
     * @param  string  $category  카테고리
     * @param  string  $path  파일 경로 (카테고리 하위 상대 경로)
     * @return string|null 파일 URL (직접 URL 불가 디스크이고 훅 공급도 없으면 null)
     */
    public function url(string $category, string $path): ?string
    {
        $fullPath = $this->resolvePath($category, $path);

        $url = $this->diskSupportsDirectUrl()
            ? Storage::disk($this->disk)->url($fullPath)
            : null;

        $filtered = HookManager::applyFilters('core.storage.filter_url', $url, array_merge(
            $this->urlHookContext(),
            [
                'disk' => $this->disk,
                'category' => $category,
                'path' => $path,
                'full_path' => $fullPath,
            ]
        ));

        return (is_string($filtered) && trim($filtered) !== '') ? $filtered : null;
    }

    /**
     * 현재 디스크가 직접 URL 생성을 지원하는지 판정합니다.
     *
     * public 디스크는 항상 지원, 그 외 디스크는 `filesystems.disks.{disk}.url` 이
     * 비어 있지 않은 문자열로 설정된 경우에만 지원합니다 (AWS_URL 미설정/빈값 방어).
     *
     * @return bool 직접 URL 지원 여부
     */
    private function diskSupportsDirectUrl(): bool
    {
        if ($this->disk === 'public') {
            return true;
        }

        $base = config("filesystems.disks.{$this->disk}.url");

        return is_string($base) && trim($base) !== '';
    }

    /**
     * `core.storage.filter_url` 훅 컨텍스트의 드라이버별 식별 정보를 반환합니다.
     *
     * @return array{scope: string, identifier: ?string} scope(core|module|plugin) + 확장 식별자(코어는 null)
     */
    abstract protected function urlHookContext(): array;
}
