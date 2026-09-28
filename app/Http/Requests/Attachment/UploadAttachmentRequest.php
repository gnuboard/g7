<?php

namespace App\Http\Requests\Attachment;

use App\Enums\AttachmentSourceType;
use App\Extension\HookManager;
use App\Http\Requests\Attachment\Concerns\ValidatesAttachmentFileType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 첨부파일 업로드 요청 검증
 */
class UploadAttachmentRequest extends FormRequest
{
    use ValidatesAttachmentFileType;

    /**
     * 요청 권한 확인
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
        // config/attachment.max_file_size 는 KB 단위 (관리자 설정 MB → KB 변환은 SettingsServiceProvider 담당)
        $maxSize = (int) config('attachment.max_file_size', 10240);
        // 파일명 확장자(extensions:)와 내용 추론 확장자(AttachmentFileContent)가 모두 허용 목록에 들어야 통과한다.
        // 목록이 비면 확장자 제한 없음 (탈출구) — ValidatesAttachmentFileType::fileTypeRules()
        $fileRules = ['required', 'file', 'max:'.$maxSize, ...$this->fileTypeRules()];

        $rules = [
            'file' => $fileRules,
            'attachmentable_type' => ['nullable', 'string', 'max:255'],
            'attachmentable_id' => ['nullable', 'integer', 'min:1'],
            'collection' => ['sometimes', 'string', 'max:100'],
            'source_type' => ['sometimes', Rule::enum(AttachmentSourceType::class)],
            'source_identifier' => ['nullable', 'string', 'max:255'],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.attachment.upload_validation_rules', $rules, $this);
    }

    /**
     * 검증 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => __('attachment.validation.file_required'),
            'file.file' => __('attachment.validation.file_invalid'),
            'file.max' => __('attachment.validation.file_max', ['max' => (int) config('attachment.max_file_size', 10240) / 1024]),
            'file.extensions' => __('attachment.validation.file_extension_invalid', ['extensions' => implode(', ', $this->allowedExtensions())]),
            'attachmentable_type.string' => __('attachment.validation.type_invalid'),
            'attachmentable_id.integer' => __('attachment.validation.id_invalid'),
        ];
    }

    /**
     * 검증 전 데이터 준비
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'collection' => $this->collection ?? 'default',
            'source_type' => $this->source_type ?? AttachmentSourceType::Core->value,
        ]);
    }
}
