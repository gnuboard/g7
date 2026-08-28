<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Concerns;

/**
 * HTMLPurifier 정의 캐시 위치 검사 헬퍼 (공개 #125 회귀)
 *
 * 정의 캐시가 모듈 설치 폴더(vendor) 대신 `storage/` 아래에 기록되는지를 확인하는
 * 테스트들이 공유합니다.
 */
trait InspectsHtmlPurifierCache
{
    /**
     * 테스트 환경에서 정의 캐시가 기록되어야 하는 디렉토리 절대 경로.
     *
     * `ProductService::purifierCacheDirectory()` 의 테스트 분기와 동일해야 합니다.
     *
     * @return string 절대 경로
     */
    protected function purifierStorageBase(): string
    {
        return storage_path('framework/testing/modules/sirsoft-ecommerce/cache/htmlpurifier');
    }

    /**
     * HTMLPurifier 가 자기 설치 폴더를 캐시 기본값으로 쓸 때의 경로.
     *
     * `HTMLPurifier_DefinitionCache_Serializer::generateBaseDirectoryPath()` 의 폴백과 동일식입니다.
     *
     * @return string|null 절대 경로. HTMLPurifier 미로드 시 null
     */
    protected function vendorSerializerBase(): ?string
    {
        return defined('HTMLPURIFIER_PREFIX')
            ? HTMLPURIFIER_PREFIX.'/HTMLPurifier/DefinitionCache/Serializer'
            : null;
    }

    /**
     * 디렉토리 하위 `.ser` 파일의 "경로 => mtime:size" 스냅샷을 만듭니다.
     *
     * @param  string|null  $base  검사할 디렉토리 절대 경로 (null 이거나 미존재면 빈 배열)
     * @return array<string, string> 경로를 키로 정렬된 스냅샷
     */
    protected function serSnapshot(?string $base): array
    {
        if ($base === null || ! is_dir($base)) {
            return [];
        }

        $snapshot = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.ser')) {
                $snapshot[$file->getPathname()] = $file->getMTime().':'.$file->getSize();
            }
        }

        ksort($snapshot);

        return $snapshot;
    }
}
