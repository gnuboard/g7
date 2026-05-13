<?php

namespace App\Extension\Vendor;

/**
 * Vendor 번들 빌드 결과 DTO.
 */
class VendorBundleResult
{
    /**
     * @param  string  $target  'core' | 'module:identifier' | 'plugin:identifier'
     * @param  string  $zipPath  생성된 zip 파일 절대 경로
     * @param  string  $manifestPath  생성된 vendor-bundle.json 절대 경로
     * @param  int  $zipSize  zip 파일 크기 (bytes)
     * @param  int  $packageCount  번들에 포함된 composer 패키지 수
     * @param  bool  $skipped  이미 최신 상태여서 빌드 스킵 여부
     * @param  string  $reason  스킵 사유 또는 빌드 메시지
     */
    public function __construct(
        public readonly string $target,
        public readonly string $zipPath,
        public readonly string $manifestPath,
        public readonly int $zipSize,
        public readonly int $packageCount,
        public readonly bool $skipped = false,
        public readonly string $reason = '',
    ) {}

    public function zipSizeHuman(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->zipSize;
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return sprintf('%.1f %s', $size, $units[$unit]);
    }
}
