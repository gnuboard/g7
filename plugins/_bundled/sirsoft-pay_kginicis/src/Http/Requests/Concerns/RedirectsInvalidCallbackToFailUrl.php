<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests\Concerns;

use App\Support\SiteUrl;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\PayKginicis\Support\ShopRedirectUrl;

/**
 * 브라우저 결제 콜백의 검증 실패를 결제 실패 화면으로 보냅니다.
 *
 * 보안: Laravel 기본 검증 실패 처리는 JSON 을 기대하지 않는 요청을 `previous()`(Referer → 세션 →
 * 요청 Host)로 302 이동시킨다. PG 가 브라우저로 넘기는 콜백에서는 그것이 Referer 를 그대로 따라가는
 * 외부 이동이 된다 — 언제나 사이트 주소의 결제 실패 화면으로 보낸다.
 */
trait RedirectsInvalidCallbackToFailUrl
{
    /**
     * 검증 실패를 기록하고 결제 실패 화면으로 이동시킵니다.
     *
     * 입력값 자체는 결제 정보를 담을 수 있어 키 이름만 기록한다.
     *
     * @param  Validator  $validator  실패한 검증기
     * @param  string  $logMessage  로그 문구
     * @return never 항상 예외로 끝난다
     *
     * @throws HttpResponseException 결제 실패 화면으로의 302
     */
    protected function redirectInvalidCallback(Validator $validator, string $logMessage): never
    {
        Log::error($logMessage, [
            'errors' => $validator->errors()->toArray(),
            'input' => array_keys($this->all()),
        ]);

        $settings = plugin_settings('sirsoft-pay_kginicis');
        $baseUrl = SiteUrl::absolute(ShopRedirectUrl::resolve($settings['redirect_fail_url'] ?? ShopRedirectUrl::DEFAULT_FAIL_URL));
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        throw new HttpResponseException(
            redirect($baseUrl.$separator.http_build_query(['error' => 'invalid_params']))
        );
    }
}
