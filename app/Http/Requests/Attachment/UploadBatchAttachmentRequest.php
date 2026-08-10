<?php

namespace App\Http\Requests\Attachment;

use App\Enums\AttachmentSourceType;
use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 첨부파일 일괄 업로드 요청 검증
 */
class UploadBatchAttachmentRequest extends FormRequest
{
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
        $extensions = $this->allowedExtensions();

        $fileRules = ['file', 'max:'.$maxSize];
        // 목록이 비면 확장자 제한 없음 (탈출구) — 빈 규칙을 붙이면 전 파일이 거부된다
        if ($extensions !== []) {
            $fileRules[] = 'extensions:'.implode(',', $extensions);
        }

        $rules = [
            'files' => ['required', 'array', 'min:1'],
            'files.*' => $fileRules,
            'attachmentable_type' => ['nullable', 'string', 'max:255'],
            'attachmentable_id' => ['nullable', 'integer', 'min:1'],
            'collection' => ['sometimes', 'string', 'max:100'],
            'source_type' => ['sometimes', Rule::enum(AttachmentSourceType::class)],
            'source_identifier' => ['nullable', 'string', 'max:255'],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.attachment.upload_batch_validation_rules', $rules, $this);
    }

    /**
     * 검증 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'files.required' => __('attachment.validation.files_required'),
            'files.array' => __('attachment.validation.files_array'),
            'files.min' => __('attachment.validation.files_min'),
            'files.*.file' => __('attachment.validation.file_invalid'),
            'files.*.max' => __('attachment.validation.file_max', ['max' => (int) config('attachment.max_file_size', 10240) / 1024]),
            'files.*.extensions' => __('attachment.validation.file_extension_invalid', ['extensions' => implode(', ', $this->allowedExtensions())]),
            'attachmentable_type.string' => __('attachment.validation.type_invalid'),
            'attachmentable_id.integer' => __('attachment.validation.id_invalid'),
        ];
    }

    /**
     * 검증 전 데이터 준비
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'collection' => $this->collection ?? 'default',
            'source_type' => $this->source_type ?? AttachmentSourceType::Core->value,
        ]);
    }

    /**
     * 허용 확장자 목록을 반환합니다 (소문자 정규화 + 확장 훅 적용).
     *
     * 빈 배열은 "확장자 제한 없음" 을 의미합니다.
     *
     * @return array<int, string> 허용 확장자 목록
     */
    protected function allowedExtensions(): array
    {
        $extensions = (array) config('attachment.allowed_extensions', []);

        $extensions = array_values(array_filter(array_map(
            static fn ($ext) => strtolower(trim(ltrim((string) $ext, '.'))),
            $extensions
        )));

        return array_values(array_unique(
            (array) HookManager::applyFilters('core.attachment.allowed_extensions', $extensions, $this)
        ));
    }
}
