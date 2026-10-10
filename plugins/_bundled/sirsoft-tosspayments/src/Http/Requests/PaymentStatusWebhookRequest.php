<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Http\Requests;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * 토스페이먼츠 결제상태 변경(PAYMENT_STATUS_CHANGED) 웹훅 요청 검증.
 *
 * 본문 예시:
 *   { "eventType": "PAYMENT_STATUS_CHANGED", "createdAt": "...",
 *     "data": { "orderId": "...", "status": "...", "paymentKey": "..." } }
 *
 * 상태 동기화(로깅 + 불일치 경고)만 수행하므로 data 하위 필드는 nullable 로 둔다.
 */
class PaymentStatusWebhookRequest extends FormRequest
{
    /**
     * 인가는 미들웨어 체인에 위임한다 (웹훅은 CSRF 면제 공개 엔드포인트).
     *
     * @return bool 항상 true (상태 동기화 로깅 전용 엔드포인트)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙 반환.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'eventType' => ['nullable', 'string', 'max:64'],
            'createdAt' => ['nullable', 'string', 'max:64'],
            'data' => ['required', 'array'],
            'data.orderId' => ['required', 'string', 'max:100'],
            'data.status' => ['required', 'string', 'max:40'],
            'data.paymentKey' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * 검증 실패를 422 JSON 으로 끝냅니다.
     *
     * 보안: 이 주소는 브라우저 화면이 아니라 PG 서버·결제 스크립트가 부른다. Laravel 기본 검증 실패
     * 처리는 JSON 을 요구하지 않은 요청을 `previous()`(Referer → 세션 → 요청 Host)로 302 이동시켜,
     * 우리 주소가 외부 이동 경유지가 된다. 302 도 PG 에는 실패라 재시도 동작은 같다.
     *
     * @param  Validator  $validator  실패한 검증기
     * @return void 반환값 없음
     *
     * @throws HttpResponseException 422 JSON 응답
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ResponseHelper::validationError($validator->errors()));
    }
}
