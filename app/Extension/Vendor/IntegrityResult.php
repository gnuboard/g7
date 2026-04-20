<?php

namespace App\Extension\Vendor;

/**
 * Vendor 번들 무결성 검증 결과.
 */
class IntegrityResult
{
    /**
     * @param  bool  $valid  모든 검증 통과 여부
     * @param  array<string>  $errors  검증 실패 키 목록 (다국어 키)
     * @param  array<string>  $warnings  경고 목록 (valid=true여도 존재 가능)
     * @param  array<string, mixed>  $meta  manifest에서 읽은 메타정보
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly array $meta = [],
    ) {}

    public static function valid(array $meta = [], array $warnings = []): self
    {
        return new self(valid: true, errors: [], warnings: $warnings, meta: $meta);
    }

    public static function invalid(array $errors, array $meta = []): self
    {
        return new self(valid: false, errors: $errors, warnings: [], meta: $meta);
    }

    public function errorMessages(): array
    {
        return array_map(
            fn (string $key) => __('exceptions.vendor.'.$key),
            $this->errors
        );
    }
}
