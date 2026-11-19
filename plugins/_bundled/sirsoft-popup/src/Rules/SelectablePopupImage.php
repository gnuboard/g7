<?php

namespace Plugins\Sirsoft\Popup\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Plugins\Sirsoft\Popup\Enums\ImageDevice;
use Plugins\Sirsoft\Popup\Repositories\Contracts\PopupImageRepositoryInterface;

/**
 * 등록·수정 화면이 고른 이미지를 확인한다 — 그 팝업에 지금 붙은 이미지(수정)이거나, 이 화면의 임시 묶음(temp_key)에 올린 이미지여야 하고
 * 기기가 맞아야 한다. 다른 팝업의 이미지나 다른 화면의 임시 업로드를 가져다 붙이지 못하게 한다.
 */
class SelectablePopupImage implements ValidationRule
{
    /**
     * SelectablePopupImage 생성자
     *
     * @param  ImageDevice  $device  기대하는 기기
     * @param  int|null  $popupId  수정 대상 팝업 ID (등록이면 null)
     * @param  string|null  $tempKey  이 화면의 임시 묶음 키
     */
    public function __construct(
        private ImageDevice $device,
        private ?int $popupId,
        private ?string $tempKey,
    ) {}

    /**
     * 검증을 실행합니다.
     *
     * @param  string  $attribute  필드명
     * @param  mixed  $value  이미지 ID
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $image = is_numeric($value) ? app(PopupImageRepositoryInterface::class)->find((int) $value) : null;

        $owned = $image !== null && (
            ($this->popupId !== null && $image->popup_id === $this->popupId)
            || ($image->popup_id === null && $this->tempKey !== null && $this->tempKey !== '' && $image->temp_key === $this->tempKey)
        );

        if (! $owned || $image->device !== $this->device->value) {
            $fail(__('sirsoft-popup::validation.image_selection.invalid'));
        }
    }
}
