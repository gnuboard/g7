<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptOutcome;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\AttemptRepositoryInterface;

/**
 * 소셜 로그인 시도 1건을 남깁니다 (진단·통계의 원천).
 *
 * 요청의 IP·브라우저 정보는 현재 요청에서 읽는다. 오류 문구에는 제공자 비밀값이 섞이지 않게 호출자가 코드·요약만 넘긴다.
 *
 * @since 1.0.0
 */
class AttemptRecorder
{
    /**
     * @param  AttemptRepositoryInterface  $attempts  시도 기록 저장소
     */
    public function __construct(private readonly AttemptRepositoryInterface $attempts) {}

    /**
     * 시도를 기록합니다.
     *
     * @param  string  $providerKey  제공자 키
     * @param  string  $intent  의도 (모르는 값이면 login 으로 남긴다)
     * @param  AttemptOutcome  $outcome  결과
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $errorCode  오류 코드
     * @param  string|null  $message  오류 요약
     * @return void
     */
    public function record(string $providerKey, string $intent, AttemptOutcome $outcome, ?int $userId = null, ?string $errorCode = null, ?string $message = null): void
    {
        $request = request();

        $this->attempts->record([
            'provider_key' => mb_substr($providerKey, 0, 50),
            'user_id' => $userId,
            'intent' => in_array($intent, AttemptIntent::values(), true) ? $intent : AttemptIntent::Login->value,
            'outcome' => $outcome->value,
            'error_code' => $errorCode,
            'error_message' => $message !== '' ? $message : null,
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);
    }
}
