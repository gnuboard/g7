<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ResolvesClaimOrder;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;

/**
 * 클레임 첨부 이미지 업로드 요청 (신청서 작성 중 — 주문에 묶어 저장 · 신청서 미귀속, 회원 · 비회원 공용)
 *
 * 최대 용량은 `claim.image_max_size_kb` 설정(KB)을 따른다. 장수 상한(`claim.max_images`)은 서비스가
 * 주문의 미귀속 첨부를 세어 판정한다.
 */
class UploadClaimImageRequest extends FormRequest
{
    use ResolvesClaimOrder;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
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
            // `image` 룰만으로는 SVG 가 통과한다 — 저장된 파일은 운영자가 열람하고 서빙은
            // inline 이라 스크립트를 담은 SVG 가 동일 오리진에서 실행된다. 확장자는 클라이언트가
            // 준 값이 그대로 저장 경로에 쓰이므로 허용 목록을 명시한다(상품 이미지 축과 동일).
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
     * 추가 검증 로직 — 주문 존재·소유권
     *
     * @param  Validator  $validator  검증기
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->assertOrderOwnership();
        });
    }

    /**
     * 검증 실패 시 응답 커스터마이징
     *
     * @param  Validator  $validator  검증기
     *
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator, ResponseHelper::error(
            $validator->errors()->first(),
            422,
            $validator->errors()->toArray()
        ));
    }

    /**
     * 최대 용량(KB)을 반환합니다.
     *
     * 해석은 `ClaimAttachmentService::maxSizeKb()` 단일 출처를 경유한다 — 같은 설정을 여기서
     * 따로 읽으면 화면이 안내한 한도(`publicSettings.image_max_size_kb` 도 그 게터에서 나온다)와
     * 여기서 거절하는 한도가 조용히 갈라진다. 설정 저장 경로가 생기면(S10) 실제로 갈라진다.
     *
     * @return int 최대 용량 (KB)
     */
    private function maxSizeKb(): int
    {
        return app(ClaimAttachmentService::class)->maxSizeKb();
    }
}
