<?php

namespace App\Extension\Vendor;

/**
 * Vendor 설치 요청 컨텍스트.
 *
 * CLI/웹/API 모든 진입점에서 공통으로 사용되는 설치 파라미터 DTO.
 */
class VendorInstallContext
{
    /**
     * @param  string  $target  'core' | 'module' | 'plugin'
     * @param  string|null  $identifier  코어는 null, 확장은 식별자
     * @param  string  $sourceDir  vendor 설치 소스 경로 (vendor-bundle.zip이 이 위치에 있어야 함)
     * @param  string  $targetDir  vendor/가 배치될 경로 (보통 sourceDir과 동일)
     * @param  VendorMode  $requestedMode  사용자 요청 모드
     * @param  VendorMode|null  $previousMode  업데이트 시 이전 설치 모드 (DB에서 조회)
     * @param  string|null  $composerBinaryHint  composer 바이너리 경로 힌트
     * @param  bool  $noDev  composer --no-dev 플래그
     * @param  string  $operation  'install' | 'update'
     */
    public function __construct(
        public readonly string $target,
        public readonly ?string $identifier,
        public readonly string $sourceDir,
        public readonly string $targetDir,
        public readonly VendorMode $requestedMode = VendorMode::Auto,
        public readonly ?VendorMode $previousMode = null,
        public readonly ?string $composerBinaryHint = null,
        public readonly bool $noDev = true,
        public readonly string $operation = 'install',
    ) {}

    public function isCore(): bool
    {
        return $this->target === 'core';
    }

    public function label(): string
    {
        return $this->isCore()
            ? 'core'
            : sprintf('%s:%s', $this->target, $this->identifier);
    }
}
