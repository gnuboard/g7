<?php

namespace App\Extension\Vendor;

/**
 * Vendor 설치 결과 DTO.
 */
class VendorInstallResult
{
    /**
     * @param  VendorMode  $mode  실제로 사용된 모드 (auto가 resolver에 의해 해석된 결과)
     * @param  string  $strategy  'composer' | 'bundled' | 'skipped'
     * @param  int  $packageCount  설치된 패키지 수 (bundled일 때 manifest 기준)
     * @param  float  $durationSeconds  설치 소요 시간
     * @param  array<string, mixed>  $details  부가 정보 (composer 출력, bundle 크기 등)
     */
    public function __construct(
        public readonly VendorMode $mode,
        public readonly string $strategy,
        public readonly int $packageCount = 0,
        public readonly float $durationSeconds = 0.0,
        public readonly array $details = [],
    ) {}

    public static function skipped(VendorMode $mode, string $reason): self
    {
        return new self(
            mode: $mode,
            strategy: 'skipped',
            details: ['skip_reason' => $reason],
        );
    }
}
