<?php

namespace Modules\Sirsoft\Page\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 페이지 첨부파일 업로드 요청
 */
class UploadPageAttachmentRequest extends FormRequest
{
    /**
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * query string 파라미터를 body로 병합합니다.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'collection' => $this->collection ?? 'attachments',
            'temp_key' => $this->query('temp_key') ?? $this->temp_key,
            'page_id' => $this->query('page_id') ?? $this->page_id,
        ]);
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf,zip,doc,docx,xls,xlsx,ppt,pptx,hwp,txt'],
            'page_id' => ['nullable', 'integer', 'min:1'],
            'collection' => ['sometimes', 'string', 'max:100'],
            'temp_key' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * 검증 오류 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => __('sirsoft-page::validation.attachment.file.required'),
            'file.file' => __('sirsoft-page::validation.attachment.file.file'),
            'file.max' => __('sirsoft-page::validation.attachment.file.max'),
            'file.mimes' => __('sirsoft-page::validation.attachment.file.mimes'),
        ];
    }
}
