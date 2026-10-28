<?php

namespace Plugins\Sirsoft\Popup\Http\Requests\Admin;

use App\Extension\HookManager;
use App\Helpers\ResponseHelper;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;
use Plugins\Sirsoft\Popup\Enums\ContentType;
use Plugins\Sirsoft\Popup\Enums\ImageDevice;
use Plugins\Sirsoft\Popup\Http\Requests\Concerns\PopupFieldRules;
use Plugins\Sirsoft\Popup\Repositories\Contracts\PopupImageRepositoryInterface;
use Plugins\Sirsoft\Popup\Rules\SelectablePopupImage;

/**
 * 팝업 수정 요청 — 등록과 같은 규칙(대칭)에, 저장 때 바꿀 이미지(`pc_image_id`·`mobile_image_id`)와 세대 번호(`revision`)를 받는다.
 *
 * 수정 화면은 이미지를 임시 묶음(`temp_key`)에 올려 두고 저장 때 화면에 남은 이미지 ID 를 보낸다. 키를 보내지 않은
 * 기기는 그대로 두고, `mobile_image_id: null` 은 모바일 이미지를 뺀다. PC 이미지는 이미지형에 필수라 null 을 받지 않는다.
 */
class UpdatePopupRequest extends FormRequest
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
     * 검증 전 입력을 정리하고, 다른 관리자가 먼저 저장했으면 검증보다 먼저 409 로 알립니다.
     *
     * @return void 반환값 없음
     *
     * @throws HttpResponseException 세대 번호가 다를 때(409)
     */
    protected function prepareForValidation(): void
    {
        $this->normalizePopupInput();
        // 옛 이미지가 지워졌어도 이미지 422 보다 먼저 수정 충돌을 알린다
        $revision = filter_var($this->input('revision'), FILTER_VALIDATE_INT);
        if ($this->has('revision') && $revision !== false && $revision >= 0 && $this->route('popup')?->lock_version !== $revision) {
            throw new HttpResponseException(ResponseHelper::error('sirsoft-popup::messages.popup.conflict', 409));
        }
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $popupId = (int) $this->route('popup')?->id;
        $tempKey = is_string($this->input('temp_key')) ? $this->input('temp_key') : null;

        $rules = $this->popupRules() + [
            'revision' => ['sometimes', 'required', 'integer', 'min:0'],
            'temp_key' => ['exclude_unless:content_type,image', 'nullable', 'string', 'max:64'],
            'pc_image_id' => ['exclude_unless:content_type,image', 'sometimes', 'required', 'integer', new SelectablePopupImage(ImageDevice::Pc, $popupId, $tempKey)],
            'mobile_image_id' => ['exclude_unless:content_type,image', 'sometimes', 'nullable', 'integer', new SelectablePopupImage(ImageDevice::Mobile, $popupId, $tempKey)],
        ];

        return HookManager::applyFilters('sirsoft-popup.popup.update_validation_rules', $rules, $this);
    }

    /**
     * 규칙 뒤 검사 — 이미지형인데 PC 이미지를 바꾸지 않으면 팝업에 PC 이미지가 있어야 합니다.
     *
     * @return array<int, callable> 뒤 검사 목록
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->input('content_type') !== ContentType::Image->value || $this->has('pc_image_id')) {
                    return;
                }

                $popupId = (int) $this->route('popup')?->id;
                $pc = app(PopupImageRepositoryInterface::class)->findByOwnerAndDevice($popupId, null, ImageDevice::Pc->value);
                if ($pc === null) {
                    $validator->errors()->add('pc_image', __('sirsoft-popup::validation.pc_image.required'));
                }
            },
        ];
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
            'revision' => __('sirsoft-popup::validation.attributes.revision'),
            'temp_key' => __('sirsoft-popup::validation.attributes.temp_key'),
            'pc_image_id' => __('sirsoft-popup::validation.attributes.pc_image'),
            'mobile_image_id' => __('sirsoft-popup::validation.attributes.mobile_image'),
        ] + $this->popupAttributes();
    }
}
