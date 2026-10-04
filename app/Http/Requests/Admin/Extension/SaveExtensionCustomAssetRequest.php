<?php

namespace App\Http\Requests\Admin\Extension;

use App\Extension\HookManager;
use App\Rules\SafeCustomAssetPath;
use App\Services\CustomAssetService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 사용자 추가 에셋 본문 저장 요청 검증
 *
 * 권한 검사는 라우트의 permission 미들웨어(core.extensions.custom_assets.manage)가 담당하므로
 * authorize()는 true 를 고정 반환한다.
 */
class SaveExtensionCustomAssetRequest extends FormRequest
{
    /**
     * 요청 권한 확인 — 권한은 permission 미들웨어가 담당.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전 전처리 — 비운 본문을 빈 문자열로 되돌립니다.
     *
     * 전역 미들웨어(`ConvertEmptyStringsToNull`)가 빈 문자열을 null 로 바꿔, 아래 `string` 규칙이 «비운 본문»
     * 을 422 로 막았다. 키가 있는데 null 이면 운영자가 본문을 비운 것이다 — 키 자체가 없는 요청과 구분한다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        if ($this->exists('content') && $this->input('content') === null) {
            $this->merge(['content' => '']);
        }
    }

    /**
     * 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'path' => ['required', 'string', 'max:255', new SafeCustomAssetPath(CustomAssetService::EDITABLE_EXTENSIONS)],
            // 빈 문자열 저장을 허용한다 — 운영자가 CSS 를 통째로 비우는 것은 정당한 조작이고,
            // 그것을 막으면 파일을 지우는 것 말고는 되돌릴 방법이 없어진다.
            'content' => ['present', 'string', 'max:'.CustomAssetService::MAX_TEXT_BYTES],
        ];

        return HookManager::applyFilters('core.extension_custom_asset.save_validation_rules', $rules, $this);
    }

    /**
     * 검증 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'path.required' => __('custom_assets.validation.path_required'),
            'content.present' => __('custom_assets.validation.content_present'),
            'content.max' => __('custom_assets.errors.too_large_to_edit', [
                'limit' => (string) CustomAssetService::MAX_TEXT_BYTES,
            ]),
        ];
    }
}
