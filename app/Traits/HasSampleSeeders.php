<?php

namespace App\Traits;

/**
 * 샘플 시더 포함 여부 관리 Trait
 *
 * Artisan 커맨드에서 --sample 옵션으로 전달된 플래그를
 * 시더 체인을 통해 전파할 수 있게 합니다.
 *
 * 사용 예시:
 * php artisan module:seed sirsoft-ecommerce --sample
 * php artisan db:seed --sample
 */
trait HasSampleSeeders
{
    /**
     * 샘플 시더 포함 여부
     *
     * @var bool
     */
    protected bool $includeSample = false;

    /**
     * 샘플 시더 포함 여부를 설정합니다.
     *
     * @param  bool  $value  샘플 포함 여부
     * @return static
     */
    public function setIncludeSample(bool $value): static
    {
        $this->includeSample = $value;

        return $this;
    }

    /**
     * 샘플 시더를 포함해야 하는지 반환합니다.
     *
     * @return bool
     */
    public function shouldIncludeSample(): bool
    {
        return $this->includeSample;
    }
}
