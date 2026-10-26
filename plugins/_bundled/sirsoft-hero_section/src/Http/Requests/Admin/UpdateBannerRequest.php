<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Plugins\Sirsoft\HeroSection\Enums\BannerDevice;
use Plugins\Sirsoft\HeroSection\Http\Requests\Concerns\BannerFieldRules;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerImageRepositoryInterface;

/**
 * 배너 수정 요청 — 등록과 같은 규칙(대칭)에, 배너에 PC 이미지가 남아 있어야 한다.
 */
class UpdateBannerRequest extends FormRequest
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
        return HookManager::applyFilters('sirsoft-hero_section.banner.update_validation_rules', $this->bannerRules(), $this);
    }

    /**
     * 규칙 뒤 검사 — 배너에 PC 이미지가 있어야 합니다.
     *
     * @return array<int, callable> 뒤 검사 목록
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $bannerId = (int) $this->route('banner')?->id;
                $pc = app(BannerImageRepositoryInterface::class)->findByOwnerAndDevice($bannerId, null, BannerDevice::Pc->value);
                if ($pc === null) {
                    $validator->errors()->add('pc_image', __('sirsoft-hero_section::validation.pc_image.required'));
                }
            },
        ];
    }

    /**
     * 검증된 값(기간 UTC 변환)을 반환합니다.
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
            'pc_image' => __('sirsoft-hero_section::validation.attributes.pc_image'),
        ];
    }
}
