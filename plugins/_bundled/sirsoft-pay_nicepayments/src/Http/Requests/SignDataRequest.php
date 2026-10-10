<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Http\Requests;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * 결제 요청 SignData 발급 요청 검증
 *
 * 비회원 결제도 호출하는 공개 표면 — 형식·길이만 닫고, 주문·금액·구매자 대조는
 * 컨트롤러의 도메인 검증(우리 DB 기준)이 담당한다.
 *
 * 계약 변경: 종전 수동 검사(400) → 표준 422. 클라이언트는 `.ok` 만 검사하므로 안전(실측).
 * 상한 근거: Amt 12자리(나이스페이먼츠 스펙), Moid 64자.
 */
class SignDataRequest extends FormRequest
{
    /**
     * 요청 권한 — 공개 엔드포인트이므로 true 고정.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * SignData 발급 요청 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, string>> 필드별 검증 규칙
     */
    public function rules(): array
    {
        return [
            'amt' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'moid' => ['required', 'string', 'max:64'],
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
