<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

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
            'no_tid' => ['required', 'string', 'max:40'],
            'no_oid' => ['required', 'string', 'max:40'],
            'id_merchant' => ['required', 'string', 'max:10'],

            // 거래 일시
            'dt_trans' => ['required', 'string', 'max:8'],
            'tm_trans' => ['required', 'string', 'max:6'],

            // 계좌 정보
            'cd_bank' => ['required', 'string', 'max:8'],
            'cd_deal' => ['nullable', 'string', 'max:8'],
            'no_vacct' => ['required', 'string', 'max:20'],
            'amt_input' => ['required', 'string', 'max:13'],

            // 입금자 정보 (선택)
            'nm_inputbank' => ['nullable', 'string', 'max:10'],
            'nm_input' => ['nullable', 'string', 'max:20'],

            // 정산 정보 (선택)
            'dt_inputstd' => ['nullable', 'string', 'max:8'],
            'dt_calculstd' => ['nullable', 'string', 'max:8'],
            'flg_close' => ['nullable', 'string', 'max:1'],

            // 현금영수증 정보 (선택)
            'dt_cshr' => ['nullable', 'string', 'max:8'],
            'tm_cshr' => ['nullable', 'string', 'max:6'],
            'no_cshr_appl' => ['nullable', 'string', 'max:9'],
            'no_cshr_tid' => ['nullable', 'string', 'max:40'],

            // 기타 (선택, 차단 안 되게 nullable)
            'msg_id' => ['nullable', 'string'],
            'no_msgseq' => ['nullable', 'string'],
            'cd_joinorg' => ['nullable', 'string'],
            'dt_transbase' => ['nullable', 'string'],
            'no_transeq' => ['nullable', 'string'],
            'type_msg' => ['nullable', 'string'],
            'cl_close' => ['nullable', 'string'],
            'cl_kor' => ['nullable', 'string'],
            'no_msgmanage' => ['nullable', 'string'],
            'amt_check' => ['nullable', 'string'],
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
