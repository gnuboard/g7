<?php

namespace Plugins\Sirsoft\Popup\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Popup\Enums\ImageDevice;
use Plugins\Sirsoft\Popup\Models\PopupImage;

/**
 * 팝업 이미지 팩토리 — 파일은 만들지 않고 행만 만든다(파일이 필요한 테스트는 서비스로 올린다).
 *
 * @extends Factory<PopupImage>
 */
class PopupImageFactory extends Factory
{
    /**
     * 팩토리 대상 모델
     *
     * @var class-string<PopupImage>
     */
    protected $model = PopupImage::class;

    /**
     * 기본 속성을 반환합니다 — PC 이미지 480×520.
     *
     * @return array<string, mixed> 기본 속성
     */
    public function definition(): array
    {
        $hash = Str::random(64);

        return [
            'popup_id' => null,
            'temp_key' => null,
            'device' => ImageDevice::Pc->value,
            'hash' => $hash,
            'disk' => null,
            'path' => 'test/'.$hash.'.jpg',
            'original_name' => 'popup.jpg',
            'mime' => 'image/jpeg',
            'size' => 1024,
            'width' => 480,
            'height' => 520,
        ];
    }

    /**
     * PC 이미지로 만듭니다.
     *
     * @return static PC 이미지 팩토리
     */
    public function pc(): static
    {
        return $this->state(fn () => ['device' => ImageDevice::Pc->value, 'width' => 480, 'height' => 520]);
    }

    /**
     * 모바일 이미지로 만듭니다.
     *
     * @return static 모바일 이미지 팩토리
     */
    public function mobile(): static
    {
        return $this->state(fn () => ['device' => ImageDevice::Mobile->value, 'width' => 750, 'height' => 900]);
    }

    /**
     * 저장 전 임시 업로드로 만듭니다.
     *
     * @param  string  $tempKey  임시 업로드 묶음 키
     * @return static 임시 업로드 팩토리
     */
    public function temp(string $tempKey): static
    {
        return $this->state(fn () => ['popup_id' => null, 'temp_key' => $tempKey]);
    }
}
