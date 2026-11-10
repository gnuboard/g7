<?php

namespace App\Thumbnails;

use App\Extension\HookManager;
use App\Thumbnails\Contracts\ThumbnailSourceProvider;
use App\Thumbnails\Providers\CoreAttachmentSourceProvider;
use App\Thumbnails\Providers\LayoutAttachmentSourceProvider;

/**
 * 썸네일 원본 제공자 목록 (코어 2개 + 필터 `core.thumbnail.filter_source_providers`)
 */
class ThumbnailSourceProviders
{
    /**
     * 등록된 제공자 전부
     *
     * @return array<string, ThumbnailSourceProvider> 키 → 제공자
     */
    public function all(): array
    {
        $core = [
            app(CoreAttachmentSourceProvider::class),
            app(LayoutAttachmentSourceProvider::class),
        ];

        $providers = HookManager::applyFilters('core.thumbnail.filter_source_providers', $core);
        $result = [];

        foreach (is_array($providers) ? $providers : $core as $provider) {
            if ($provider instanceof ThumbnailSourceProvider) {
                $result[$provider->key()] ??= $provider;
            }
        }

        return $result;
    }

    /**
     * 디스크 위 경로가 어느 제공자의 원본인지 찾습니다.
     *
     * @param  string  $disk  원본 디스크
     * @param  string  $path  디스크 위 전체 경로
     * @param  array<string, ThumbnailSourceProvider>|null  $providers  미리 해석한 목록 (파일을 많이 훑는 호출이 한 번만 해석하게)
     * @return ThumbnailSourceProvider|null 제공자 (모르면 null)
     */
    public function classify(string $disk, string $path, ?array $providers = null): ?ThumbnailSourceProvider
    {
        foreach ($providers ?? $this->all() as $provider) {
            try {
                if ($provider->matches($disk, $path)) {
                    return $provider;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * 키로 제공자를 고릅니다 (빈 목록이면 전부).
     *
     * @param  list<string>  $keys  제공자 키
     * @return array<string, ThumbnailSourceProvider> 키 → 제공자
     */
    public function only(array $keys): array
    {
        $all = $this->all();

        return $keys === [] ? $all : array_intersect_key($all, array_flip($keys));
    }
}
