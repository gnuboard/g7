<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;

/**
 * 관리자 클레임 첨부 이미지 업로드 요청 (운영자 대리 첨부 — 이미 귀속된 신청서에 추가)
 *
 * 파일 규칙은 유저 업로드(`User\UploadClaimImageRequest`)와 동일 계약이다 — 최대 용량은
 * `claim.image_max_size_kb` 설정(KB) 단일 출처, 확장자 허용 목록은
 * `ClaimAttachmentService::ALLOWED_EXTENSIONS` 하나를 읽는다(화면 `accept` 도 같은 출처).
 * 장수 상한(`claim.max_images`)은 서비스(`uploadFor()`)가 신청서의 기존 첨부를 세어 판정한다.
 *
 * ## 필드 이름이 둘인 이유
 *
 * 공용 업로더 컴포넌트는 파일을 `file` 로 보낸다. 종전 계약은 `image` 였고 문서·기존 호출부가
 * 그 이름을 쓴다. 둘 다 받되 **한 마이너 동안만** 유지한다 — 한쪽만 남기면 배포 순서에 따라
 * 어느 한쪽이 무조건 422 가 되는 창이 생긴다.
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
     * 이 요청이 파일을 실은 필드 이름을 반환합니다.
     *
     * 파일을 다른 필드로 **옮기지 않는다** — `Request::allFiles()` 는 한 번 계산한 결과를
     * 캐시하므로, 검증 직전에 파일 백을 고쳐도 검증기는 옛 목록을 본다(그 경로로 만들면
     * 필드가 비어 보여 무조건 422 가 된다). 대신 규칙을 그 필드에 건다.
     *
     * @return string 파일 필드 이름
     */
    public function uploadedFieldName(): string
    {
        return $this->hasFile('image') ? 'image' : 'file';
    }

    /**
     * 업로드된 파일을 반환합니다. (컨트롤러가 필드 이름을 알 필요가 없게)
     *
     * @return UploadedFile|null 업로드 파일
     */
    public function uploadedImage(): ?UploadedFile
    {
        return $this->file($this->uploadedFieldName());
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        return [
            $this->uploadedFieldName() => [
                'required', 'file', 'image',
                'mimes:'.implode(',', ClaimAttachmentService::ALLOWED_EXTENSIONS),
                'max:'.$this->maxSizeKb(),
            ],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 메시지 배열
     */
    public function messages(): array
    {
        $field = $this->uploadedFieldName();

        return [
            $field.'.required' => __('sirsoft-ecommerce::validation.claims.image.required'),
            $field.'.file' => __('sirsoft-ecommerce::validation.claims.image.file'),
            $field.'.image' => __('sirsoft-ecommerce::validation.claims.image.image'),
            $field.'.mimes' => __('sirsoft-ecommerce::validation.claims.image.mimes'),
            $field.'.max' => __('sirsoft-ecommerce::validation.claims.image.max', ['max' => $this->maxSizeKb()]),
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
