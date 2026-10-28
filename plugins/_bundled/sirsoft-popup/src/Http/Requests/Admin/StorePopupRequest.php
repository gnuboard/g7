<?php

namespace Plugins\Sirsoft\Popup\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Plugins\Sirsoft\Popup\Enums\ImageDevice;
use Plugins\Sirsoft\Popup\Http\Requests\Concerns\PopupFieldRules;
use Plugins\Sirsoft\Popup\Rules\PcImagePresent;
use Plugins\Sirsoft\Popup\Rules\SelectablePopupImage;

/**
 * 팝업 등록 요청 — 이미지형이면 등록 화면이 만든 임시 묶음(temp_key)에 PC 이미지가 올라와 있어야 한다.
 *
 * 화면에 남은 이미지 ID(pc_image_id·mobile_image_id)를 보내면 그것만 붙고(모바일 null 은 붙이지 않음),
 * 보내지 않은 기기는 묶음의 가장 최근 이미지가 붙는다. 직접 작성형은 이미지를 받지 않는다.
 */
class StorePopupRequest extends FormRequest
{
    use PopupFieldRules;

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
     * 검증 전 입력을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $this->normalizePopupInput();
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $tempKey = is_string($this->input('temp_key')) ? $this->input('temp_key') : null;

        $rules = $this->popupRules() + [
            'temp_key' => ['exclude_unless:content_type,image', 'required', 'string', 'max:64', new PcImagePresent],
            'pc_image_id' => ['exclude_unless:content_type,image', 'sometimes', 'required', 'integer', new SelectablePopupImage(ImageDevice::Pc, null, $tempKey)],
            'mobile_image_id' => ['exclude_unless:content_type,image', 'sometimes', 'nullable', 'integer', new SelectablePopupImage(ImageDevice::Mobile, null, $tempKey)],
        ];

        return HookManager::applyFilters('sirsoft-popup.popup.store_validation_rules', $rules, $this);
    }

    /**
     * 검증된 값(기간 UTC 변환·안 쓰는 값 비움)을 반환합니다.
     *
     * @param  string|null  $key  특정 키만 반환
     * @param  mixed  $default  기본값
     * @return mixed 변환한 검증 값
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        return $key === null ? $this->convertPopupInput($data) : $data;
    }

    /**
     * 검증 오류에 쓸 필드 이름을 반환합니다.
     *
     * @return array<string, string> 필드 => 이름
     */
    public function attributes(): array
    {
        return [
            'temp_key' => __('sirsoft-popup::validation.attributes.temp_key'),
            'pc_image_id' => __('sirsoft-popup::validation.attributes.pc_image'),
            'mobile_image_id' => __('sirsoft-popup::validation.attributes.mobile_image'),
        ] + $this->popupAttributes();
    }
}
