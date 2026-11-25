<?php

namespace App\Http\Requests\Menu;

use App\Extension\HookManager;
use App\Rules\AttachmentFileContent;
use App\Rules\MenuIconUploadFileRule;
use App\Services\MenuIconService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 메뉴 아이콘 파일 업로드 요청 (#381).
 *
 * png·webp·jpg·gif(1MB 이하)는 코어 첨부로, SVG(32KB 이하)는 내용 검사 뒤 인라인 코드로 돌려준다.
 */
class UploadMenuIconRequest extends FormRequest
{
    /** @var list<string> 허용 확장자 */
    public const ALLOWED_EXTENSIONS = ['png', 'webp', 'jpg', 'jpeg', 'gif', 'svg'];

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어(core.menus.create 또는 core.menus.update)에서 수행됩니다.
     *
     * @return bool 권한 검증 결과 (항상 true)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'file' => [
                'required',
                'file',
                'max:'.MenuIconService::UPLOAD_MAX_KB,
                'extensions:'.implode(',', self::ALLOWED_EXTENSIONS),
                new AttachmentFileContent(self::ALLOWED_EXTENSIONS),
                new MenuIconUploadFileRule,
            ],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.menu.upload_icon_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => __('validation.menu.icon_upload.file_required'),
            'file.file' => __('validation.menu.icon_upload.file_invalid'),
            'file.max' => __('validation.menu.icon_upload.file_max', ['max' => MenuIconService::UPLOAD_MAX_KB]),
            'file.extensions' => __('validation.menu.icon_upload.file_type'),
        ];
    }

    /**
     * 검증 항목 표시 이름을 반환합니다.
     *
     * @return array<string, string> 필드 → 표시 이름
     */
    public function attributes(): array
    {
        return [
            'file' => __('validation.menu.attributes.icon_file'),
        ];
    }
}
