<?php

namespace App\Extension\Auth;

use App\Contracts\Extension\ExternalCredentialVerifierInterface;
use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 외부 자격 증명 검증자 레지스트리.
 *
 * 확장은 필터 `core.auth.registered_credential_verifiers` 로 검증자를 등록한다(`IdentityVerificationManager` 와 같은 방식 —
 * 필터 결과에서 계약을 구현한 것만 남긴다). 순서는 등록 순서이고 첫 일치가 이긴다.
 *
 * @since 7.2.0
 */
class CredentialVerifierManager
{
    /**
     * 코드로 직접 등록한 검증자 (필터 등록과 합쳐진다).
     *
     * @var array<string, ExternalCredentialVerifierInterface>
     */
    protected array $verifiers = [];

    /**
     * 검증자를 등록합니다.
     *
     * @param  ExternalCredentialVerifierInterface  $verifier  검증자
     * @return void
     */
    public function register(ExternalCredentialVerifierInterface $verifier): void
    {
        $this->verifiers[$verifier->getId()] = $verifier;
    }

    /**
     * 등록된 검증자 전부를 돌려줍니다 (필터 반영, 계약 미구현 항목 제외).
     *
     * @return array<string, ExternalCredentialVerifierInterface> 식별자 → 검증자
     */
    public function all(): array
    {
        $merged = HookManager::applyFilters('core.auth.registered_credential_verifiers', $this->verifiers);

        if (! is_array($merged)) {
            return $this->verifiers;
        }

        $valid = [];

        foreach ($merged as $verifier) {
            if ($verifier instanceof ExternalCredentialVerifierInterface) {
                $valid[$verifier->getId()] = $verifier;
            }
        }

        return $valid;
    }

    /**
     * 지금 쓸 수 있는 검증자만 돌려줍니다.
     *
     * @return array<string, ExternalCredentialVerifierInterface> 식별자 → 검증자
     */
    public function available(): array
    {
        return array_filter($this->all(), fn (ExternalCredentialVerifierInterface $verifier) => $this->safeAvailable($verifier));
    }

    /**
     * 이 로그인 경로에서 이메일이 아닌 아이디를 받는 검증자가 있는지 돌려줍니다.
     *
     * @param  string  $guard  로그인 경로 (user | admin)
     * @return bool 하나라도 받으면 true
     */
    public function acceptsUsernames(string $guard): bool
    {
        foreach ($this->available() as $verifier) {
            try {
                if ($verifier->acceptsUsernames($guard)) {
                    return true;
                }
            } catch (Throwable $e) {
                $this->logFailure($verifier, 'acceptsUsernames', $e);
            }
        }

        return false;
    }

    /**
     * 식별자를 담당하는 첫 검증자와 일치 결과를 돌려줍니다.
     *
     * 검증자가 예외를 던지면 그 검증자는 건너뛴다 — 한 확장의 오류가 비밀번호 로그인 전체를 막지 않게.
     *
     * @param  string  $identifier  로그인 칸 입력
     * @param  string  $guard  로그인 경로 (user | admin)
     * @return array{verifier: ExternalCredentialVerifierInterface, match: ExternalCredentialMatch}|null 일치 없음이면 null
     */
    public function match(string $identifier, string $guard): ?array
    {
        foreach ($this->available() as $verifier) {
            try {
                $match = $verifier->match($identifier, $guard);
            } catch (Throwable $e) {
                $this->logFailure($verifier, 'match', $e);

                continue;
            }

            if ($match instanceof ExternalCredentialMatch) {
                return ['verifier' => $verifier, 'match' => $match];
            }
        }

        return null;
    }

    /**
     * 사용 가능 여부를 예외 없이 묻습니다.
     *
     * @param  ExternalCredentialVerifierInterface  $verifier  검증자
     * @return bool 사용 가능하면 true
     */
    private function safeAvailable(ExternalCredentialVerifierInterface $verifier): bool
    {
        try {
            return $verifier->isAvailable();
        } catch (Throwable $e) {
            $this->logFailure($verifier, 'isAvailable', $e);

            return false;
        }
    }

    /**
     * 검증자 오류를 기록합니다 (입력값·비밀번호는 싣지 않는다).
     *
     * @param  ExternalCredentialVerifierInterface  $verifier  검증자
     * @param  string  $method  실패한 메서드
     * @param  Throwable  $e  예외
     * @return void
     */
    private function logFailure(ExternalCredentialVerifierInterface $verifier, string $method, Throwable $e): void
    {
        Log::warning('외부 자격 증명 검증자 오류 — 이 검증자를 건너뜁니다', [
            'verifier' => $verifier->getId(),
            'method' => $method,
            'exception' => $e::class,
        ]);
    }
}
