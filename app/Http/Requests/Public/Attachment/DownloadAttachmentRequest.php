<?php

namespace App\Http\Requests\Public\Attachment;

use Illuminate\Foundation\Http\FormRequest;

class DownloadAttachmentRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 공개 다운로드 라우트 — 접근 제어는 AttachmentService 의 권한 훅
     * (core.attachment.download)이 하이브리드로 수행합니다.
     *
     * @return bool 항상 true (권한은 서비스 훅 책임)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * 해시는 라우트 제약(`[a-zA-Z0-9]{12}`)이 이미 형식을 강제하고,
     * 그 외 입력 필드가 없는 요청입니다.
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        return [];
    }
}
