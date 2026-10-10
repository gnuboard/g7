<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Http\Requests;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * 토스페이먼츠 가상계좌 입금통보(DEPOSIT_CALLBACK) 웹훅 요청 검증.
 *
 * 본문 예시:
 *   { "createdAt": "...", "secret": "...", "status": "DONE",
 *     "transactionKey": "...", "orderId": "..." }
 *
 * status 는 입금완료(DONE) / 입금취소(CANCELED) 두 값이 유효하다. secret 대조는
 * 저장된 payment_meta.toss_secret 과 컨트롤러에서 수행하므로 여기서는 형식만 검증한다.
 */
class DepositWebhookRequest extends FormRequest
{
    /**
     * 인가는 미들웨어 체인에 위임한다 (웹훅은 CSRF 면제 공개 엔드포인트).
     *
     * @return bool 항상 true (인가는 secret 대조로 컨트롤러에서 수행)
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
            'orderId' => ['required', 'string', 'max:100'],
            'status' => ['required', 'string', 'in:DONE,CANCELED'],
            'secret' => ['nullable', 'string', 'max:255'],
            'transactionKey' => ['nullable', 'string', 'max:255'],
            'createdAt' => ['nullable', 'string', 'max:64'],
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
