<?php

namespace Plugins\Sirsoft\Sso\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;

/**
 * 관리자·마이페이지 API 의 오류 응답 — `errors.code` 에 오류 코드를, 설정 검증 실패면 `errors.fields` 에 칸별 문구를 싣는다.
 *
 * 문구는 관리자 영역 키(`sirsoft-sso::admin.error.{code}`)를 먼저 찾고, 없으면 흐름 공통 키(`messages.error.{code}`)를 쓴다.
 *
 * @since 1.0.0
 */
trait RespondsWithAdminErrors
{
    /**
     * 오류 응답을 만듭니다.
     *
     * @param  SsoException  $e  오류
     * @return JsonResponse 응답
     */
    protected function adminError(SsoException $e): JsonResponse
    {
        $errors = ['code' => $e->errorCode];

        if ($e->errorCode === 'config_invalid') {
            $fields = json_decode($e->getMessage(), true);
            $errors['fields'] = is_array($fields) ? $fields : [];
        }

        if ($e->errorCode === 'domain_owned_by_other_idp') {
            $domains = json_decode($e->getMessage(), true);
            $errors['domains'] = is_array($domains) ? $domains : [];
        }

        $key = 'admin.error.'.$e->errorCode;
        if (__('sirsoft-sso::'.$key) === 'sirsoft-sso::'.$key) {
            $key = 'messages.error.'.$e->errorCode;
        }

        return ResponseHelper::pluginError('sirsoft-sso', $key, $e->status, $errors);
    }
}
