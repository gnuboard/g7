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

    /**
     * zip 크기를 B·KB·MB·GB 중 알맞은 단위로 표기합니다.
     *
     * @return string 소수 1자리 크기와 단위 (예: `31.8 KB`)
     */
    public function zipSizeHuman(): string
    {
        return self::formatSize($this->zipSize);
    }

    /**
     * 바이트 수를 B·KB·MB·GB 중 알맞은 단위로 표기합니다.
     *
     * 작은 묶음이 `0.0 MB` 로 뭉개지지 않도록 1024 단위로 올려 가며 단위를 고른다.
     *
     * @param  int|float  $bytes  바이트 수
     * @return string 소수 1자리 크기와 단위 (예: `498.0 B`, `31.8 KB`, `14.1 MB`)
     */
    public static function formatSize(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = max(0, $bytes);
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return sprintf('%.1f %s', $size, $units[$unit]);
    }
}
