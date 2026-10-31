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
            // 허용 목록은 **내용**을 좁힌다 — `mimes` 의 통과 판정은 `guessExtension()` 기반이라
            // 클라이언트가 준 확장자를 보지 않는다(그 값을 보는 것은 내부의 `shouldBlockPhpUpload()`
            // 뿐이며 대상도 php 계열에 한정된다). `image` 룰도 Laravel 11 이후 SVG 를 기본 제외하고
            // 같은 php 차단을 경유하므로, 여기서 `mimes` 가 더하는 것은 bmp 를 받지 않는 것이다.
            //
            // 저장 경로의 확장자 방어는 이 룰이 아니라 저장 지점이 맡는다
            // (`ClaimAttachmentService` 의 `storedExtension()` — 내용 기준으로 정한다).
            'image' => ['required', 'file', 'image', 'mimes:'.implode(',', ClaimAttachmentService::ALLOWED_EXTENSIONS), 'max:'.$this->maxSizeKb()],
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
     * 검증 오류 문구에 쓰는 필드 표시명
     *
     * @return array<string, string> 필드 키 → 표시명
     */
    public function attributes(): array
    {
        return [
            'image' => __('sirsoft-ecommerce::validation.request_attributes.image'),
        ];
    }

    /**
     * 추가 검증 로직 — 주문 존재·소유권
     *
     * @param  Validator  $validator  검증기
     * @return void
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
     * @return void
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
