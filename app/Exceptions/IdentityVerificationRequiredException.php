<?php

namespace App\Exceptions;

/**
 * 정책 위반 — 본인인증 필요.
 *
 * 미들웨어/Listener 가 정책 매칭 후 던지며, 글로벌 Handler 가 HTTP 428 (Precondition Required) 응답으로 매핑합니다.
 * 프론트 `ErrorHandlingResolver` 가 이 상태코드 + error_code 를 감지해 자동으로 Challenge 모달을 열고
 * return_request 를 재실행합니다.
 *
 * **부모 클래스 선택 (CRITICAL)**: 의도적으로 `\Error` 를 상속한다.
 *
 * 이유: 코어/모듈/플러그인 컨트롤러 23+ 곳이 `try { ... } catch (\Exception $e) { ... }` 패턴으로
 * 자체 응답 변환을 하는데, IDV 예외가 `\Exception` 자식이면 그 catch-all 에 포획되어 422 일반
 * 에러로 강등 → 프론트 IdentityGuardInterceptor 가 모달을 띄우지 못한다.
 * `\Error` 는 PHP 의 `\Exception` 과 별도 계층이므로 `catch (\Exception)` 으로 잡히지 않으며,
 * Laravel 글로벌 핸들러의 `render(Throwable)` 콜백은 `\Throwable` 으로 받아 정상 428 매핑한다.
 *
 * 즉 어떤 라우트 (코어/모듈/플러그인) 에서 어느 catch-all 패턴이 있어도 IDV 흐름은 항상 글로벌
 * 핸들러까지 도달한다 — 라우트별 안전망 코드 작성 불필요.
 *
 * 예외적으로 명시 catch 가 필요한 호출자는 `catch (IdentityVerificationRequiredException $e)`
 * 또는 `catch (\Throwable $e)` 로 잡을 수 있다 (테스트의 expectException 도 정상 작동).
 *
 * @since 7.0.0-beta.4
 */
class IdentityVerificationRequiredException extends \Error
{
    /**
     * @param  string  $policyKey  매칭된 정책 식별자 (identity_policies.key)
     * @param  string  $purpose  요구되는 IDV purpose
     * @param  string|null  $providerId  특정 provider 강제 시 id, null 이면 기본
     * @param  string|null  $renderHint  프론트 렌더 힌트
     * @param  array|null  $returnRequest  재실행할 원 요청 정보 (method/url/headers_echo)
     */
    public function __construct(
        public readonly string $policyKey,
        public readonly string $purpose,
        public readonly ?string $providerId = null,
        public readonly ?string $renderHint = null,
        public readonly ?array $returnRequest = null,
        string $message = 'identity.errors.verification_required',
    ) {
        parent::__construct($message);
    }

    public function getPayload(): array
    {
        return [
            'policy_key' => $this->policyKey,
            'purpose' => $this->purpose,
            'provider_id' => $this->providerId,
            'render_hint' => $this->renderHint,
            'challenge_start_url' => '/api/identity/challenges',
            'return_request' => $this->returnRequest,
        ];
    }
}
