<?php

namespace App\Http\Requests\Admin\Template;

use App\Extension\HookManager;
use App\Rules\AttachmentFileContent;
use App\Support\TemplateLayoutAttachmentPolicy;
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
        // 최대 크기는 첨부 설정 재사용(MB→KB).
        $maxSize = config('attachment.max_file_size', 10240);

        // 허용 형식은 이름 확장자와 파일 내용을 함께 본다 — 한쪽만 보면 내용은 이미지이고 이름만
        // `.html` 인 파일, 또는 이름만 이미지인 다른 형식이 통과한다.
        $allowed = $this->allowedExtensions();

        $rules = [
            'file' => [
                'required',
                'file',
                'extensions:'.implode(',', $allowed),
                new AttachmentFileContent($allowed),
                'max:'.$maxSize,
            ],
            'layout_name' => ['nullable', 'string', 'max:150'],
        ];

        // 모듈/플러그인이 검증 규칙을 동적으로 확장할 수 있도록 훅 제공
        return HookManager::applyFilters('core.template_layout_attachment.upload_validation_rules', $rules, $this);
    }

    /**
     * 이 요청의 템플릿에 허용된 레이아웃 첨부 확장자를 반환합니다.
     *
     * @return array<int, string> 허용 확장자 목록 (소문자, 비어 있지 않음)
     */
    private function allowedExtensions(): array
    {
        return TemplateLayoutAttachmentPolicy::allowedExtensions((string) $this->route('identifier'));
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
            'file.extensions' => __('templates.layout_attachments.validation.file_extensions', [
                'extensions' => implode(', ', $this->allowedExtensions()),
            ]),
            'file.max' => __('templates.layout_attachments.validation.file_max', [
                'max' => (int) (config('attachment.max_file_size', 10240) / 1024),
            ]),
            'layout_name.max' => __('templates.layout_attachments.validation.layout_name_max'),
        ];
    }
}
