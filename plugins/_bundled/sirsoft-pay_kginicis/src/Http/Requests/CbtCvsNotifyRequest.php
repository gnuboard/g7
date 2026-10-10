<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * KG 이니시스 CBT 편의점 입금 NOTI 요청 검증.
 *
 * POST /plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify
 *
 * CBT CVS 입금 통보는 JSON으로 수신되며, PG 재시도 정책 때문에 필수값 누락도
 * Laravel 422가 아니라 컨트롤러에서 "FAIL" text/plain으로 응답한다. 그래서 규칙은 모두 선택이고,
 * 검증이 실패하는 것은 형식 위반(문자열 자리에 배열 등)뿐이다 — 그 경우는 `failedValidation()` 이
 * 422 JSON 으로 끝낸다(PG 에는 `FAIL` 과 같은 실패라 재시도 동작은 같다).
 */
class CbtCvsNotifyRequest extends FormRequest
{
    /**
     * 요청 권한 — PG 서버가 직접 부르는 통보 주소라 true 고정.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 폼 본문이 비어 있으면 JSON 본문(EUC-KR 이면 UTF-8 로 변환)을 입력으로 씁니다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        if ($this->all() !== []) {
            return;
        }

        $content = $this->getContent();
        if ($content === '') {
            return;
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            $converted = @mb_convert_encoding($content, 'UTF-8', 'CP949');
            if ($converted !== false && $converted !== '') {
                $content = $converted;
            }
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $this->replace($decoded);
        }
    }

    /**
     * rules
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'tid' => ['nullable', 'string', 'max:80'],
            'mid' => ['nullable', 'string', 'max:20'],
            'applDt' => ['nullable', 'string', 'max:8'],
            'applTm' => ['nullable', 'string', 'max:6'],
            'status' => ['nullable', 'string', 'max:10'],
            'payNm' => ['nullable', 'string', 'max:50'],
            'orderId' => ['nullable', 'string', 'max:100'],
            'applNo' => ['nullable', 'string', 'max:80'],
            'sid' => ['nullable', 'string', 'max:100'],
            'convenience' => ['nullable', 'string', 'max:30'],
            'confNo' => ['nullable', 'string', 'max:80'],
            'receiptNo' => ['nullable', 'string', 'max:80'],
            'paymentTerm' => ['nullable', 'string', 'max:20'],
            'amount' => ['nullable'],
            'currencyCd' => ['nullable', 'string', 'max:10'],
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
