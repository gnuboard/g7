<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Plugins\Sirsoft\HeroSection\Enums\BannerDevice;
use Plugins\Sirsoft\HeroSection\Http\Requests\Concerns\BannerFieldRules;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerImageRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Rules\SelectableBannerImage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 배너 수정 요청 — 등록과 같은 규칙(대칭)에, 저장 때 바꿀 이미지(`pc_image_id`·`mobile_image_id`)를 받는다.
 *
 * 수정 화면은 이미지를 임시 묶음(`temp_key`)에 올려 두고 저장 때 화면에 남은 이미지 ID 를 보낸다. 키를 보내지 않은
 * 기기는 그대로 두고, `mobile_image_id: null` 은 모바일 이미지를 뺀다. PC 이미지는 필수라 null 을 받지 않는다.
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
        // 옛 이미지가 삭제됐어도 이미지 422보다 먼저 수정 충돌을 알린다.
        $revision = filter_var($this->input('revision'), FILTER_VALIDATE_INT);
        if ($this->has('revision') && $revision !== false && $revision >= 0 && $this->route('banner')?->lock_version !== $revision) {
            throw new ConflictHttpException(__('sirsoft-hero_section::messages.banner.conflict'));
        }
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $bannerId = (int) $this->route('banner')?->id;
        $tempKey = is_string($this->input('temp_key')) ? $this->input('temp_key') : null;

        $rules = $this->bannerRules() + [
            'revision' => ['sometimes', 'required', 'integer', 'min:0'],
            'temp_key' => ['nullable', 'string', 'max:64'],
            'pc_image_id' => ['sometimes', 'required', 'integer', new SelectableBannerImage(BannerDevice::Pc, $bannerId, $tempKey)],
            'mobile_image_id' => ['sometimes', 'nullable', 'integer', new SelectableBannerImage(BannerDevice::Mobile, $bannerId, $tempKey)],
        ];

        return HookManager::applyFilters('sirsoft-hero_section.banner.update_validation_rules', $rules, $this);
    }

    /**
     * 규칙 뒤 검사 — PC 이미지를 바꾸지 않으면 배너에 PC 이미지가 있어야 합니다.
     *
     * @return array<int, callable> 뒤 검사 목록
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->has('pc_image_id')) {
                    return;
                }

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
            'revision' => __('sirsoft-hero_section::validation.attributes.revision'),
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
            'pc_image' => __('sirsoft-hero_section::validation.attributes.pc_image'),
            'temp_key' => __('sirsoft-hero_section::validation.attributes.temp_key'),
            'pc_image_id' => __('sirsoft-hero_section::validation.attributes.pc_image'),
            'mobile_image_id' => __('sirsoft-hero_section::validation.attributes.mobile_image'),
        ];
    }
}
