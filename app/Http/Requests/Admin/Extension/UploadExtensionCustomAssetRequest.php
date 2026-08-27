<?php

namespace App\Http\Requests\Admin\Extension;

use App\Extension\HookManager;
use App\Rules\AllowedTemplateFileType;
use App\Rules\SafeCustomAssetPath;
use App\Services\CustomAssetService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 사용자 추가 에셋 업로드 요청 검증
 *
 * 권한 검사는 라우트의 permission 미들웨어(core.extensions.custom_assets.manage)가 담당한다.
 *
 * 허용 확장자는 자산 서빙과 **같은 목록**(`AllowedTemplateFileType`)을 쓴다. 여기만 넓히면
 * 올릴 수는 있는데 서빙되지 않는 파일이 생기고, 여기만 좁히면 서빙 규칙이 사문화된다.
 */
class UploadExtensionCustomAssetRequest extends FormRequest
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
     * 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKilobytes = (int) (CustomAssetService::MAX_UPLOAD_BYTES / 1024);

        $rules = [
            'file' => [
                'required',
                'file',
                'max:'.$maxKilobytes,
                'mimes:'.implode(',', AllowedTemplateFileType::getAllowedExtensions()),
            ],
            // 하위 디렉토리는 선택 — 없으면 `custom/` 바로 아래에 놓인다.
            'directory' => ['nullable', 'string', 'max:200', new SafeCustomAssetPath],
        ];

        return HookManager::applyFilters('core.extension_custom_asset.upload_validation_rules', $rules, $this);
    }

    /**
     * 검증 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => __('custom_assets.validation.file_required'),
            'file.file' => __('custom_assets.validation.file_invalid'),
            'file.mimes' => __('custom_assets.validation.file_mimes', [
                'allowed' => implode(', ', AllowedTemplateFileType::getAllowedExtensions()),
            ]),
            'file.max' => __('custom_assets.errors.upload_too_large', [
                'limit' => (string) CustomAssetService::MAX_UPLOAD_BYTES,
            ]),
        ];
    }
}
