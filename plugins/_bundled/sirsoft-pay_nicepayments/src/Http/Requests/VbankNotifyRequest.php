<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Http\Requests;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * 나이스페이먼츠 가상계좌 입금 통보 요청 검증
 *
 * POST /plugins/sirsoft-pay_nicepayments/payment/vbank-notify
 * 공식 매뉴얼: https://developers.nicepay.co.kr/manual-noti.php
 *
 * NicePay 서버가 직접 호출하는 입금 확인 웹훅. 응답으로 정확히 "OK" (200, text/plain) 를
 * 돌려줘야 하며, 그렇지 않으면 NicePay 가 최대 10회까지 재시도하고 실패 메일을 보낸다.
 *
 * 매뉴얼 필드명을 그대로 사용 (대소문자 정확히 일치):
 *   필수: PG, PayMethod, MID, Amt, MOID, TID, ResultCode, AuthDate
 *   가상계좌: VbankName, VbankNum, VbankInputName, FnCd, FnName, StateCd
 *   기타: name(구매자), BuyerEmail, ResultMsg, CancelDate, MallReserved 등
 *
 * 한글 데이터는 매뉴얼상 EUC-KR 인코딩으로 들어오므로 prepareForValidation 에서
 * UTF-8 로 자동 변환한다.
 *
 * 호환성 보호:
 *   - Moid (소문자 i) 로 보내는 호환 케이스도 자동으로 MOID 로 매핑.
 *   - Signature 필드는 공식 spec 에는 없으나 nullable 로 받아 차단되지 않게 한다.
 *
 * 보안: 발송 IP 는 NicePay 공식 IP 만 허용. 로컬/테스트 환경은 예외.
 */
class VbankNotifyRequest extends FormRequest
{
    /** 한글 인코딩 변환 대상 필드 (EUC-KR → UTF-8) */
    private const TEXT_FIELDS_FOR_DECODE = [
        'GoodsName',
        'name',          // 구매자명
        'VbankName',
        'VbankInputName',
        'FnName',
        'ResultMsg',
        'AuthResultMsg',
        'MallReserved',
    ];

    /**
     * FormRequest 인가 — 권한 검증은 vbank-notify.ip-whitelist 미들웨어에서 처리
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 한글 EUC-KR → UTF-8 변환 + 필드 alias 정규화.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $data = $this->all();

        // 1) 한글 필드 EUC-KR → UTF-8 변환 (이미 UTF-8 인 경우는 보존)
        foreach (self::TEXT_FIELDS_FOR_DECODE as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field]) || $data[$field] === '') {
                continue;
            }
            $value = $data[$field];

            // 이미 valid UTF-8 이면 그대로
            if (mb_check_encoding($value, 'UTF-8')) {
                // 단, ASCII 7-bit 가 아니고 EUC-KR 로도 valid 해서 모호한 경우는
                // 한글 보였을 때만 decode 시도 — 매뉴얼이 EUC-KR 명시이므로 일단 보존
                continue;
            }

            if (mb_check_encoding($value, 'EUC-KR') || mb_check_encoding($value, 'CP949')) {
                $converted = @mb_convert_encoding($value, 'UTF-8', 'CP949'); // CP949 = EUC-KR superset
                if (is_string($converted) && $converted !== '') {
                    $data[$field] = $converted;
                }
            }
        }

        // 2) Moid (소문자 i) → MOID 정규화 — 일부 호환 환경 대응
        if (! isset($data['MOID']) && isset($data['Moid'])) {
            $data['MOID'] = $data['Moid'];
        }

        $this->replace($data);
    }

    /**
     * 입금통보 페이로드 검증 규칙
     *
     * NicePay 매뉴얼 필드명을 그대로 사용. 한글(BuyerName 등) 은 EUC-KR 로
     * 들어와 prepareForValidation 에서 UTF-8 로 변환된 후 검증.
     *
     * @return array<string, mixed> Laravel 검증 규칙
     */
    public function rules(): array
    {
        return [
            // 필수 식별자
            'TID' => ['required', 'string', 'max:30'],
            'MOID' => ['required', 'string', 'max:64'],
            'Amt' => ['required', 'integer', 'min:1'],
            'ResultCode' => ['required', 'string', 'max:4'],

            // 통보 메타 (공식 매뉴얼)
            'PG' => ['nullable', 'string', 'max:10'],
            'PayMethod' => ['nullable', 'string', 'max:10'],
            'MID' => ['nullable', 'string', 'max:10'],
            'MallUserID' => ['nullable', 'string', 'max:20'],
            'AuthDate' => ['nullable', 'string', 'max:14'],
            'AuthCode' => ['nullable', 'string', 'max:30'],
            'StateCd' => ['nullable', 'string', 'max:1'],
            'ResultMsg' => ['nullable', 'string', 'max:200'],
            'GoodsName' => ['nullable', 'string', 'max:80'],
            'name' => ['nullable', 'string', 'max:60'],
            'BuyerEmail' => ['nullable', 'string', 'max:60'],

            // 가상계좌 전용
            'VbankName' => ['nullable', 'string', 'max:40'],
            'VbankNum' => ['nullable', 'string', 'max:30'],
            'VbankInputName' => ['nullable', 'string', 'max:40'],
            'FnCd' => ['nullable', 'string', 'max:4'],
            'FnName' => ['nullable', 'string', 'max:40'],

            // 취소 (입금취소 통보 시)
            'CancelDate' => ['nullable', 'string', 'max:14'],
            'CancelMOID' => ['nullable', 'string', 'max:64'],

            // 가맹점 사용자 정의
            'MallReserved' => ['nullable', 'string', 'max:1000'],
            'MallReserved1' => ['nullable', 'string', 'max:20'],
            'TransType' => ['nullable', 'string', 'max:1'],
            'CartCnt' => ['nullable', 'string', 'max:10'],

            // 호환성: Moid 도 받음 (prepareForValidation 에서 MOID 로 매핑됨)
            'Moid' => ['nullable', 'string'],

            // 공식 spec 에 없으나 일부 환경에서 받을 수 있음 — 차단 안 되게 nullable
            'Signature' => ['nullable', 'string'],
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
