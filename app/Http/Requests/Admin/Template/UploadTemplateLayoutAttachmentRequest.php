<?php

namespace App\Http\Requests\Admin\Template;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 템플릿 레이아웃 첨부 파일 업로드 요청 검증
 *
 * 권한 검사는 라우트의 permission 미들웨어(core.templates.layouts.edit)가 담당하므로
 * authorize()는 true 를 고정 반환한다(FormRequest authorize 에 권한 로직 금지 규칙).
 */
class UploadTemplateLayoutAttachmentRequest extends FormRequest
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
        // 배경 이미지 등 — 이미지 파일만 허용. 최대 크기는 첨부 설정 재사용(MB→KB).
        $maxSize = config('attachment.max_file_size', 10240);

        $rules = [
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,gif,webp,svg', 'max:'.$maxSize],
            'layout_name' => ['nullable', 'string', 'max:150'],
        ];

        // 모듈/플러그인이 검증 규칙을 동적으로 확장할 수 있도록 훅 제공
        return HookManager::applyFilters('core.template_layout_attachment.upload_validation_rules', $rules, $this);
    }

    /**
     * 검증 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => __('templates.layout_attachments.validation.file_required'),
            'file.file' => __('templates.layout_attachments.validation.file_invalid'),
            'file.image' => __('templates.layout_attachments.validation.file_image'),
            'file.mimes' => __('templates.layout_attachments.validation.file_mimes'),
            'file.max' => __('templates.layout_attachments.validation.file_max', [
                'max' => (int) (config('attachment.max_file_size', 10240) / 1024),
            ]),
            'layout_name.max' => __('templates.layout_attachments.validation.layout_name_max'),
        ];
    }
}
