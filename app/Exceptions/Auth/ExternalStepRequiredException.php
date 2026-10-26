<?php

namespace App\Exceptions\Auth;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * 외부 자격 증명 로그인에 화면 단계(동의·연결 확인 등)가 더 필요할 때의 예외.
 *
 * 로그인 컨트롤러는 이 예외를 409 `errors.code = auth.external_step_required` + `data.redirect_url` 로 바꾼다 — 로그인 화면이 그 경로로 이동한다.
 *
 * 사용자에게 안내로 답하는 정상 결과라 오류 로그에 보고하지 않는다(`ShouldntReport` — 같은 성격의 `AccountLockedException` 등은
 * `HttpException` 이라 원래 보고되지 않는다).
 *
 * @since 7.2.0
 */
class ExternalStepRequiredException extends RuntimeException implements ShouldntReport
{
    /** 응답 오류 코드 */
    public const ERROR_CODE = 'auth.external_step_required';

    /**
     * 예외를 만듭니다.
     *
     * @param  string  $redirectUrl  다음 화면의 같은 출처 경로
     * @param  string|null  $reasonKey  사유 문구 키 (기록용)
     */
    public function __construct(
        public readonly string $redirectUrl,
        public readonly ?string $reasonKey = null,
    ) {
        parent::__construct(__('auth.external_step_required'));
    }
}
