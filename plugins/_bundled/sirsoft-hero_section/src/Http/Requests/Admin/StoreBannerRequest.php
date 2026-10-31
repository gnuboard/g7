<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Plugins\Sirsoft\HeroSection\Enums\BannerDevice;
use Plugins\Sirsoft\HeroSection\Http\Requests\Concerns\BannerFieldRules;
use Plugins\Sirsoft\HeroSection\Rules\PcImagePresent;
use Plugins\Sirsoft\HeroSection\Rules\SelectableBannerImage;

/**
 * 배너 등록 요청 — 등록 화면이 만든 임시 묶음(temp_key)에 PC 이미지가 올라와 있어야 한다. 화면에 남은 이미지 ID(pc_image_id·
 * mobile_image_id)를 보내면 그것만 붙고(모바일 null 은 붙이지 않음), 보내지 않은 기기는 묶음의 가장 최근 이미지가 붙는다.
 */
class StoreBannerRequest extends FormRequest
{
    use BannerFieldRules;

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
     * 검증 전 다국어 문구를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeTranslations();
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $tempKey = is_string($this->input('temp_key')) ? $this->input('temp_key') : null;

        $rules = $this->bannerRules() + [
            'temp_key' => ['required', 'string', 'max:64', new PcImagePresent],
            'pc_image_id' => ['sometimes', 'required', 'integer', new SelectableBannerImage(BannerDevice::Pc, null, $tempKey)],
            'mobile_image_id' => ['sometimes', 'nullable', 'integer', new SelectableBannerImage(BannerDevice::Mobile, null, $tempKey)],
        ] + $this->videoSelectionRules(null, $tempKey);

        return HookManager::applyFilters('sirsoft-hero_section.banner.store_validation_rules', $rules, $this);
    }

    /**
     * 규칙 뒤 검사 — 미디어 종류가 영상 파일이면 PC 영상이 있어야 합니다.
     *
     * @return array<int, callable> 뒤 검사 목록
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkVideoPresent($validator, null, null),
        ];
    }

    /**
     * 검증된 값(기간 UTC 변환, 유튜브 주소 → 영상 ID)을 반환합니다.
     *
     * @param  string|null  $key  특정 키만 반환
     * @param  mixed  $default  기본값
     * @return mixed 변환한 검증 값
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        return $key === null ? $this->convertPeriod($data) : $data;
    }

    /**
     * 검증 오류에 쓸 필드 이름을 반환합니다.
     *
     * @return array<string, string> 필드 => 이름
     */
    public function attributes(): array
    {
        return [
            'presentation' => __('sirsoft-hero_section::validation.attributes.presentation'),
            'presentation.*' => __('sirsoft-hero_section::validation.attributes.presentation'),
            'position' => __('sirsoft-hero_section::validation.attributes.position'),
            'is_active' => __('sirsoft-hero_section::validation.attributes.is_active'),
            'display_period' => __('sirsoft-hero_section::validation.attributes.display_period'),
            'starts_at' => __('sirsoft-hero_section::validation.attributes.starts_at'),
            'ends_at' => __('sirsoft-hero_section::validation.attributes.ends_at'),
            'title' => __('sirsoft-hero_section::validation.attributes.title'),
            'description' => __('sirsoft-hero_section::validation.attributes.description'),
            'button_text' => __('sirsoft-hero_section::validation.attributes.button_text'),
            'image_alt' => __('sirsoft-hero_section::validation.attributes.image_alt'),
            'title_visible' => __('sirsoft-hero_section::validation.attributes.title_visible'),
            'title_color' => __('sirsoft-hero_section::validation.attributes.title_color'),
            'title_align' => __('sirsoft-hero_section::validation.attributes.title_align'),
            'description_visible' => __('sirsoft-hero_section::validation.attributes.description_visible'),
            'description_color' => __('sirsoft-hero_section::validation.attributes.description_color'),
            'description_align' => __('sirsoft-hero_section::validation.attributes.description_align'),
            'link_url' => __('sirsoft-hero_section::validation.attributes.link_url'),
            'link_new_window' => __('sirsoft-hero_section::validation.attributes.link_new_window'),
            'temp_key' => __('sirsoft-hero_section::validation.attributes.temp_key'),
            'pc_image_id' => __('sirsoft-hero_section::validation.attributes.pc_image'),
            'mobile_image_id' => __('sirsoft-hero_section::validation.attributes.mobile_image'),
            'media_type' => __('sirsoft-hero_section::validation.attributes.media_type'),
            'youtube_url' => __('sirsoft-hero_section::validation.attributes.youtube_url'),
            'pc_video' => __('sirsoft-hero_section::validation.attributes.pc_video'),
            'pc_video_id' => __('sirsoft-hero_section::validation.attributes.pc_video'),
            'mobile_video_id' => __('sirsoft-hero_section::validation.attributes.mobile_video'),
        ];
    }
}
