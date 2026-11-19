<?php

namespace Plugins\Sirsoft\Popup\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Plugins\Sirsoft\Popup\Enums\ImageDevice;
use Plugins\Sirsoft\Popup\Repositories\Contracts\PopupImageRepositoryInterface;

/**
 * 이미지형 팝업에 PC 이미지가 있는지 확인한다 — 등록은 임시 묶음(temp_key)에, 수정은 팝업에 PC 이미지가 있어야 한다.
 */
class PcImagePresent implements ValidationRule
{
    /**
     * PcImagePresent 생성자
     *
     * @param  int|null  $popupId  수정 대상 팝업 ID (등록이면 null)
     */
    public function __construct(private ?int $popupId = null) {}

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
        $tempKey = $this->popupId === null && is_string($value) ? $value : null;
        $image = app(PopupImageRepositoryInterface::class)->findByOwnerAndDevice($this->popupId, $tempKey, ImageDevice::Pc->value);

        if ($image === null) {
            $fail(__('sirsoft-popup::validation.pc_image.required'));
        }
    }
}
