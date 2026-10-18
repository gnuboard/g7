<?php

// audit:allow api-doc-coverage reason: 상한·허용 형식 해석(기본값·폴백)을 Support\AttachmentLimits 로 옮긴 계층 정리다. 검증 규칙의 값·요청 키·422 형태·응답이 모두 그대로다 — 폴백 목록과 용량 계산식은 동일하게 이동했다(`api:docgen --scope=module:sirsoft-page --check` drift 없음 확인, 2026-09-23).

namespace Modules\Sirsoft\Page\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Page\Http\Requests\Concerns\ValidatesTempKey;
use Modules\Sirsoft\Page\Support\AttachmentLimits;

/**
 * 페이지 첨부파일 업로드 요청
 */
class UploadPageAttachmentRequest extends FormRequest
{
    use ValidatesTempKey;

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
     *
     * @return void
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
        // 해석(기본값·폴백)은 화면용 공개 설정과 같은 지점(AttachmentLimits)을 쓴다 — 두 벌이면
        // 화면이 안내한 상한과 서버가 거절하는 상한이 갈린다.
        $maxSizeKb = AttachmentLimits::maxSizeMb() * 1024;
        $allowedTypes = AttachmentLimits::allowedTypes();

        $fileRules = ['required', 'file', 'max:'.$maxSizeKb];
        // 목록이 비면 형식 제한 없음 — 빈 규칙을 붙이면 전 파일이 거부된다
        if ($allowedTypes !== []) {
            $fileRules[] = 'mimetypes:'.implode(',', $allowedTypes);
        }

        return [
            'file' => $fileRules,
            'page_id' => ['nullable', 'integer', 'min:1'],
            'collection' => ['sometimes', 'string', 'max:100'],
            'temp_key' => $this->tempKeyRules(),
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

    /**
     * 검증 오류에 사용할 사용자 친화적 필드명
     *
     * @return array<string, string> 필드명 → 표시 이름
     */
    public function attributes(): array
    {
        return $this->tempKeyAttributes();
    }
}
