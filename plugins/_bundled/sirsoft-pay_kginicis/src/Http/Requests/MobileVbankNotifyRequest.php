<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * KG 이니시스 모바일 가상계좌 입금 통보 요청 검증
 *
 * POST /plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify
 * 공식 매뉴얼: https://manual.inicis.com/pay/etc-noti.html#mo
 *
 * P_STATUS == "02" && P_TYPE == "VBANK" 인 경우만 실제 입금 처리.
 * 응답으로 "OK" (200, text/plain) 를 돌려줘야 합니다.
 */
class MobileVbankNotifyRequest extends FormRequest
{
    /** EUC-KR → UTF-8 변환 대상 필드 */
    private const KOREAN_FIELDS = ['P_FN_NM', 'P_UNAME'];

    /**
     * 요청 권한 — PG 서버가 직접 부르는 통보 주소라 권한은 IP 허용 목록 미들웨어가 판정한다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전에 한글 필드를 EUC-KR(CP949)에서 UTF-8 로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $data = $this->all();

        foreach (self::KOREAN_FIELDS as $field) {
            if (empty($data[$field]) || ! is_string($data[$field])) {
                continue;
            }
            if (! mb_check_encoding($data[$field], 'UTF-8')) {
                $converted = @mb_convert_encoding($data[$field], 'UTF-8', 'CP949');
                if ($converted !== false && $converted !== '') {
                    $data[$field] = $converted;
                }
            }
        }

        $this->replace($data);
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, string>> 검증 규칙
     */
    public function rules(): array
    {
        return [
            // 필수 식별자
            'P_TID' => ['required', 'string', 'max:40'],
            'P_MID' => ['required', 'string', 'max:10'],
            'P_OID' => ['required', 'string', 'max:100'],
            'P_AMT' => ['required', 'string', 'max:12'],
            'P_STATUS' => ['required', 'string', 'max:2'],
            'P_TYPE' => ['required', 'string', 'max:10'],

            // 입금 정보
            'P_AUTH_DT' => ['nullable', 'string', 'max:14'],
            'P_FN_CD1' => ['nullable', 'string', 'max:4'],
            'P_FN_NM' => ['nullable', 'string', 'max:50'],
            'P_UNAME' => ['nullable', 'string', 'max:30'],
            'P_RMESG1' => ['nullable', 'string', 'max:500'],

            // 기타 (선택, 차단 안 되게 nullable)
            'P_FN_CD2' => ['nullable', 'string'],
            'P_RMESG2' => ['nullable', 'string'],
            'P_NOTI' => ['nullable', 'string', 'max:600'],
            'P_AUTH_NO' => ['nullable', 'string'],

            // 현금영수증 정보 (선택)
            'P_CSHR_AMT' => ['nullable', 'string', 'max:12'],
            'P_CSHR_TAX' => ['nullable', 'string', 'max:12'],
            'P_CSHR_TYPE' => ['nullable', 'string', 'max:14'],
            'P_CSHR_DT' => ['nullable', 'string', 'max:14'],
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
