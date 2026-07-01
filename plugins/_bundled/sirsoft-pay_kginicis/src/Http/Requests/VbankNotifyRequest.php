<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * KG 이니시스 PC 가상계좌 입금 통보 요청 검증
 *
 * POST /plugins/sirsoft-pay_kginicis/payment/vbank-notify
 * 공식 매뉴얼: https://manual.inicis.com/pay/etc-noti.html#pc
 *
 * KG 이니시스 서버가 직접 호출하는 입금 확인 웹훅.
 * 응답으로 정확히 "OK" (200, text/plain) 를 돌려줘야 하며,
 * 그렇지 않으면 최대 10회까지 재시도합니다.
 *
 * 한글 데이터(nm_inputbank, nm_input)는 EUC-KR 인코딩으로 수신될 수 있으므로
 * prepareForValidation 에서 UTF-8 로 자동 변환합니다.
 */
class VbankNotifyRequest extends FormRequest
{
/** EUC-KR → UTF-8 변환 대상 필드 */
    private const KOREAN_FIELDS = ['nm_inputbank', 'nm_input'];

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
            'no_tid'       => ['required', 'string', 'max:40'],
            'no_oid'       => ['required', 'string', 'max:40'],
            'id_merchant'  => ['required', 'string', 'max:10'],

            // 거래 일시
            'dt_trans'     => ['required', 'string', 'max:8'],
            'tm_trans'     => ['required', 'string', 'max:6'],

            // 계좌 정보
            'cd_bank'      => ['required', 'string', 'max:8'],
            'cd_deal'      => ['nullable', 'string', 'max:8'],
            'no_vacct'     => ['required', 'string', 'max:20'],
            'amt_input'    => ['required', 'string', 'max:13'],

            // 입금자 정보 (선택)
            'nm_inputbank' => ['nullable', 'string', 'max:10'],
            'nm_input'     => ['nullable', 'string', 'max:20'],

            // 정산 정보 (선택)
            'dt_inputstd'  => ['nullable', 'string', 'max:8'],
            'dt_calculstd' => ['nullable', 'string', 'max:8'],
            'flg_close'    => ['nullable', 'string', 'max:1'],

            // 현금영수증 정보 (선택)
            'dt_cshr'      => ['nullable', 'string', 'max:8'],
            'tm_cshr'      => ['nullable', 'string', 'max:6'],
            'no_cshr_appl' => ['nullable', 'string', 'max:9'],
            'no_cshr_tid'  => ['nullable', 'string', 'max:40'],

            // 기타 (선택, 차단 안 되게 nullable)
            'msg_id'       => ['nullable', 'string'],
            'no_msgseq'    => ['nullable', 'string'],
            'cd_joinorg'   => ['nullable', 'string'],
            'dt_transbase' => ['nullable', 'string'],
            'no_transeq'   => ['nullable', 'string'],
            'type_msg'     => ['nullable', 'string'],
            'cl_close'     => ['nullable', 'string'],
            'cl_kor'       => ['nullable', 'string'],
            'no_msgmanage' => ['nullable', 'string'],
            'amt_check'    => ['nullable', 'string'],
        ];
    }
}
