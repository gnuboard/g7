<?php

namespace Modules\Sirsoft\Page\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 페이지 첨부파일 업로드 요청
 */
class UploadPageAttachmentRequest extends FormRequest
{
    /**
     * 허용 형식 폴백 (config/settings/defaults.json 의 attachment.allowed_types 와 동일)
     *
     * 설정이 로드되지 않은 상태에서도 형식 제한이 유지되도록 하는 안전값입니다.
     *
     * @return bool 항상 true (권한은 미들웨어에서 검증)
     */
    private const FALLBACK_ALLOWED_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'application/zip',
    ];

    /**
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true (권한은 미들웨어에서 검증)
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
        // 모듈 환경설정(attachment.*)이 SSoT — 하드코딩된 상한/형식과 이원화되지 않도록 동적 생성한다.
        // 설정이 MIME 배열이므로 mimes: 가 아니라 mimetypes: 를 사용한다(의미 정합).
        $maxSizeMb = (int) g7_module_settings('sirsoft-page', 'attachment.max_size_mb', 10);
        $maxSizeKb = max(1, $maxSizeMb) * 1024;

        // 설정이 아직 로드되지 않은 환경(신규 설치 직후 등)에서 형식 검증이 통째로 비활성화되지
        // 않도록, 키가 없으면 모듈 기본값으로 폴백한다. 명시적 빈 배열만 "제한 없음" 이다.
        $allowedTypes = g7_module_settings('sirsoft-page', 'attachment.allowed_types', null);
        if (! is_array($allowedTypes)) {
            $allowedTypes = self::FALLBACK_ALLOWED_TYPES;
        }
        $allowedTypes = array_values(array_filter(array_map('strval', $allowedTypes)));

        $fileRules = ['required', 'file', 'max:'.$maxSizeKb];
        // 목록이 비면 형식 제한 없음 — 빈 규칙을 붙이면 전 파일이 거부된다
        if ($allowedTypes !== []) {
            $fileRules[] = 'mimetypes:'.implode(',', $allowedTypes);
        }

        return [
            'file' => $fileRules,
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
            'file.mimetypes' => __('sirsoft-page::validation.attachment.file.mimetypes'),
        ];
    }
}
