<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\HeroSection\Enums\SlideEffect;
use Plugins\Sirsoft\HeroSection\Models\PositionSetting;

/**
 * 위치별 슬라이더 설정 저장 요청 — 간격은 3·5·7초만.
 */
class UpdatePositionSettingRequest extends FormRequest
{
    /**
     * 권한은 라우트 permission 미들웨어가 판정합니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'autoplay' => ['required', 'boolean'],
            'interval_seconds' => ['required', 'integer', Rule::in(PositionSetting::INTERVALS)],
            'effect' => ['required', Rule::enum(SlideEffect::class)],
        ];
    }

    /**
     * 검증 오류에 쓸 필드 이름을 반환합니다.
     *
     * @return array<string, string> 필드 => 이름
     */
    public function attributes(): array
    {
        return [
            'autoplay' => __('sirsoft-hero_section::validation.attributes.autoplay'),
            'interval_seconds' => __('sirsoft-hero_section::validation.attributes.interval_seconds'),
            'effect' => __('sirsoft-hero_section::validation.attributes.effect'),
        ];
    }
}
