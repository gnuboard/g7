<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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

 * authorize

 *

 * @return bool

 */

    public function authorize(): bool
    {
        return true;
    }

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

 * rules

 *

 * @return array

 */

    public function rules(): array
    {
        return [
            // 필수 식별자
            'P_TID'     => ['required', 'string', 'max:40'],
            'P_MID'     => ['required', 'string', 'max:10'],
            'P_OID'     => ['required', 'string', 'max:100'],
            'P_AMT'     => ['required', 'string', 'max:12'],
            'P_STATUS'  => ['required', 'string', 'max:2'],
            'P_TYPE'    => ['required', 'string', 'max:10'],

            // 입금 정보
            'P_AUTH_DT' => ['nullable', 'string', 'max:14'],
            'P_FN_CD1'  => ['nullable', 'string', 'max:4'],
            'P_FN_NM'   => ['nullable', 'string', 'max:50'],
            'P_UNAME'   => ['nullable', 'string', 'max:30'],
            'P_RMESG1'  => ['nullable', 'string', 'max:500'],

            // 기타 (선택, 차단 안 되게 nullable)
            'P_FN_CD2'  => ['nullable', 'string'],
            'P_RMESG2'  => ['nullable', 'string'],
            'P_NOTI'    => ['nullable', 'string', 'max:600'],
            'P_AUTH_NO' => ['nullable', 'string'],

            // 현금영수증 정보 (선택)
            'P_CSHR_AMT'  => ['nullable', 'string', 'max:12'],
            'P_CSHR_TAX'  => ['nullable', 'string', 'max:12'],
            'P_CSHR_TYPE' => ['nullable', 'string', 'max:14'],
            'P_CSHR_DT'   => ['nullable', 'string', 'max:14'],
        ];
    }
}
