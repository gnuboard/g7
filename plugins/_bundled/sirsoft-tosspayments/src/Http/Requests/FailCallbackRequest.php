<?php

namespace Plugins\Sirsoft\Tosspayments\Http\Requests;

use App\Support\SiteUrl;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\Tosspayments\Support\ShopRedirectUrl;

/**
 * 토스페이먼츠 결제 실패 콜백 요청 검증
 *
 * GET /plugins/sirsoft-tosspayments/payment/fail
 *     ?code={ERR}&message={MSG}&orderId={OID}
 */
class FailCallbackRequest extends FormRequest
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-tosspayments';

    /**
     * 권한 확인 (PG 콜백은 인증 불필요)
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙 정의
     *
     * @return array<string, array<int, string>> 필드별 규칙
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string'],
            'message' => ['nullable', 'string'],
            'orderId' => ['nullable', 'string'],
        ];
    }

    /**
     * 검증 전 기본값 설정
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'code' => 'UNKNOWN',
            'message' => '',
        ]);
    }

    /**
     * 검증 실패를 기록하고 결제 실패 화면으로 보냅니다.
     *
     * 보안: Laravel 기본 검증 실패 처리는 `previous()`(Referer → 세션 → 요청 Host)로 이동시킨다 — 브라우저
     * 콜백에서는 그것이 외부 주소·위조 Host 로의 이동이 된다. 언제나 사이트 주소의 결제 실패 화면으로 보낸다.
     * 입력값 자체는 결제 정보를 담을 수 있어 키 이름만 기록한다.
     *
     * @param  Validator  $validator  실패한 검증기
     * @return void 반환값 없음
     *
     * @throws HttpResponseException 결제 실패 화면으로의 302
     */
    protected function failedValidation(Validator $validator): void
    {
        Log::warning('TossPayments: fail callback validation failed', [
            'errors' => $validator->errors()->toArray(),
            'input' => array_keys($this->all()),
        ]);

        $settings = plugin_settings(self::PLUGIN_IDENTIFIER);
        $baseUrl = SiteUrl::absolute(ShopRedirectUrl::resolve($settings['redirect_fail_url'] ?? ShopRedirectUrl::DEFAULT_FAIL_URL));
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        throw new HttpResponseException(
            redirect($baseUrl.$separator.http_build_query(['error' => 'invalid_params']))
        );
    }
}
