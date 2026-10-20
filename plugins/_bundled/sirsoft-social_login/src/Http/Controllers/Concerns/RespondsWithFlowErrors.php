<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Controllers\Concerns;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\SocialLogin\Exceptions\SocialFlowException;

/**
 * 흐름 거절(`SocialFlowException`)을 JSON 응답으로 바꿉니다.
 *
 * 응답 `errors.code` 는 화면이 문구(`sirsoft-social_login.errors.{code}`)와 다음 행동(예: 마지막 수단이면 비밀번호 설정
 * 안내)을 고르는 데 쓴다.
 *
 * @since 1.0.0
 */
trait RespondsWithFlowErrors
{
    /**
     * 거절 응답을 만듭니다.
     *
     * @param  SocialFlowException  $e  거절
     * @return JsonResponse 응답
     */
    protected function flowError(SocialFlowException $e): JsonResponse
    {
        return ResponseHelper::pluginError('sirsoft-social_login', $e->messageKey(), $e->status, ['code' => $e->errorCode]);
    }
}
