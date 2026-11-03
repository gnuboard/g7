<?php

namespace Plugins\Sirsoft\HeroSection\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Plugins\Sirsoft\HeroSection\Enums\BannerDevice;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerImageRepositoryInterface;

/**
 * PC 이미지가 있는지 확인한다 — 등록은 임시 묶음(temp_key)에, 수정은 배너에 PC 이미지가 있어야 한다.
 */
class PcImagePresent implements ValidationRule
{
    /**
     * PcImagePresent 생성자
     *
     * @param  int|null  $bannerId  수정 대상 배너 ID (등록이면 null)
     */
    public function __construct(private ?int $bannerId = null) {}

    /**
     * 검증을 실행합니다.
     *
     * @param  string  $attribute  필드명
     * @param  mixed  $value  검증 값 (등록: temp_key, 수정: 무시)
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $tempKey = $this->bannerId === null && is_string($value) ? $value : null;
        $image = app(BannerImageRepositoryInterface::class)->findByOwnerAndDevice($this->bannerId, $tempKey, BannerDevice::Pc->value);

        if ($image === null) {
            $fail(__('sirsoft-hero_section::validation.pc_image.required'));
        }
    }
}
