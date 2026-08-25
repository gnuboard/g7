<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;

/**
 * 관리자 클레임 첨부 이미지 업로드 요청 (운영자 대리 첨부 — 이미 귀속된 신청서에 추가)
 *
 * 파일 규칙은 유저 업로드(`User\UploadClaimImageRequest`)와 동일 계약이다 — 최대 용량은
 * `claim.image_max_size_kb` 설정(KB) 단일 출처, 확장자 허용 목록은 클라이언트가 준 확장자가
 * 저장 경로에 그대로 쓰이므로 명시한다. 장수 상한(`claim.max_images`)은 서비스
 * (`uploadFor()`)가 신청서의 기존 첨부를 세어 판정한다.
 */
class UploadClaimImageRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인 (권한 체크는 라우트의 permission 미들웨어에서 수행)
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:'.$this->maxSizeKb()],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 메시지 배열
     */
    public function messages(): array
    {
        return [
            'image.required' => __('sirsoft-ecommerce::validation.claims.image.required'),
            'image.file' => __('sirsoft-ecommerce::validation.claims.image.file'),
            'image.image' => __('sirsoft-ecommerce::validation.claims.image.image'),
            'image.mimes' => __('sirsoft-ecommerce::validation.claims.image.mimes'),
            'image.max' => __('sirsoft-ecommerce::validation.claims.image.max', ['max' => $this->maxSizeKb()]),
        ];
    }

    /**
     * 첨부 최대 용량(KB) 설정을 반환합니다. (`ClaimAttachmentService` 단일 출처)
     *
     * @return int 최대 용량 (KB)
     */
    protected function maxSizeKb(): int
    {
        return app(ClaimAttachmentService::class)->maxSizeKb();
    }
}
