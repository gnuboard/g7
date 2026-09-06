<?php

namespace App\Http\Requests\Admin\Extension;

use App\Extension\HookManager;
use App\Rules\SafeCustomAssetPath;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 사용자 추가 에셋 본문 조회·삭제 요청 검증
 *
 * 권한 검사는 라우트의 permission 미들웨어(core.extensions.custom_assets.manage)가 담당한다.
 *
 * 조회와 삭제가 같은 요청 클래스를 쓰는 이유: 둘 다 "존재하는 파일 하나를 경로로 지목"
 * 이라는 동일한 입력이고, 검증 강도가 갈리면 약한 쪽이 우회로가 된다.
 */
class ReadExtensionCustomAssetRequest extends FormRequest
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
        $rules = [
            // 삭제는 폰트·이미지도 대상이므로 확장자 제한을 두지 않는다. 편집 가능 여부는
            // 서비스가 판정한다 — 여기서 편집 확장자로 좁히면 올린 폰트를 지울 수 없다.
            'path' => ['required', 'string', 'max:255', new SafeCustomAssetPath],
        ];

        return HookManager::applyFilters('core.extension_custom_asset.read_validation_rules', $rules, $this);
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
        ];
    }
}
