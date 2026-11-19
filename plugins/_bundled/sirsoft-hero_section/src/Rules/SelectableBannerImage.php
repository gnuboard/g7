<?php

namespace Plugins\Sirsoft\HeroSection\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Plugins\Sirsoft\HeroSection\Enums\BannerDevice;
use Plugins\Sirsoft\HeroSection\Enums\MediaKind;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerImageRepositoryInterface;

/**
 * 등록·수정 화면이 고른 이미지를 확인한다 — 그 배너에 지금 붙은 이미지(수정)이거나, 이 화면의 임시 묶음(temp_key)에 올린 이미지여야 하고
 * 기기·종류(이미지·영상)가 맞아야 한다. 다른 배너의 파일이나 다른 화면의 임시 업로드, 영상 칸에 이미지를 붙이지 못하게 한다.
 */
class SelectableBannerImage implements ValidationRule
{
    /**
     * SelectableBannerImage 생성자
     *
     * @param  BannerDevice  $device  기대하는 기기
     * @param  int|null  $bannerId  수정 대상 배너 ID (등록이면 null)
     * @param  string|null  $tempKey  이 화면의 임시 묶음 키
     * @param  MediaKind  $kind  기대하는 파일 종류 (기본 이미지)
     */
    public function __construct(
        private BannerDevice $device,
        private ?int $bannerId,
        private ?string $tempKey,
        private MediaKind $kind = MediaKind::Image,
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
        $image = is_numeric($value) ? app(BannerImageRepositoryInterface::class)->find((int) $value) : null;

        $owned = $image !== null && (
            ($this->bannerId !== null && $image->banner_id === $this->bannerId)
            || ($image->banner_id === null && $this->tempKey !== null && $this->tempKey !== '' && $image->temp_key === $this->tempKey)
        );

        if (! $owned || $image->device !== $this->device->value || $image->kind !== $this->kind->value) {
            $fail(__('sirsoft-hero_section::validation.image_selection.invalid'));
        }
    }
}
