<?php

namespace Plugins\Sirsoft\Sso\Identity;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\IdentityVerificationInterface;
use App\Contracts\Extension\UserScopedIdentityProviderInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationPurpose;
use App\Enums\IdentityVerificationStatus;
use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Sso\DTO\LoginIntent;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Repositories\Contracts\IdentityProviderRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Services\LoginFlowService;
use Plugins\Sirsoft\Sso\Services\SafeRedirect;

/**
 * 코어 본인인증 수단 「회사 계정으로 재확인」.
 *
 * 받을 메일이 없는 SSO 회원(사번 계정 등)은 메일 인증번호로 민감 작업을 재확인할 수 없다 — 그 회원은 연결된 IdP 에 다시 로그인해서
 * 확인한다(목적 `sensitive_action` 만). 인증번호가 없다: 요청을 내면 IdP 왕복 시작 주소(1회용 시작 티켓, 브라우저 묶음)를
 * `external_redirect` 로 돌려주고, 콜백이 같은 회원의 같은 연결을 확인한 뒤 남긴 일회용 증명이 있어야 `verify()` 가 통과한다. 그래서 코어
 * 본인인증 확인 API 를 직접 불러도(입력 값과 무관하게) 통과하지 못한다.
 *
 * 회원별 판정(`canChallengeUser`)은 「활성 연결 보유」다 — 코어가 목적별 수단을 고를 때 이 회원에게 쓸 수 없으면 다음 수단으로 넘어간다.
 *
 * @since 1.0.0
 */
class SsoReauthIdentityProvider implements IdentityVerificationInterface, UserScopedIdentityProviderInterface
{
    /** 수단 식별자 */
    public const PROVIDER_ID = 'sirsoft-sso';

    /** 인증 채널 — 코어 본인인증 이력의 채널 칸은 16자다(렌더 힌트 `external_redirect` 와 다른 값) */
    public const CHANNEL = 'sso';

    /** 요청 유효 시간(분) — IdP 화면을 다녀올 시간 */
    private const TTL_MINUTES = 10;

    /** 콜백이 남긴 증명 보관(초) */
    private const PROOF_TTL = 120;

    /** @var array<string, mixed> 런타임 설정 (쓰지 않음) */
    private array $config = [];

    /**
     * @param  IdentityVerificationLogRepositoryInterface  $logRepository  본인인증 이력 저장소
     * @param  SsoAccountRepositoryInterface  $accounts  연결 저장소
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     * @param  CacheInterface  $cache  플러그인 캐시 (증명)
     */
    public function __construct(
        private readonly IdentityVerificationLogRepositoryInterface $logRepository,
        private readonly SsoAccountRepositoryInterface $accounts,
        private readonly IdentityProviderRepositoryInterface $idps,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 수단 식별자를 반환합니다.
     *
     * @return string 식별자
     */
    public function getId(): string
    {
        return self::PROVIDER_ID;
    }

    /**
     * 관리자 화면 표시 이름을 반환합니다.
     *
     * @return string 이름
     */
    public function getLabel(): string
    {
        return (string) __('sirsoft-sso::messages.reauth.provider_label');
    }

    /**
     * 지원 채널 목록을 반환합니다.
     *
     * @return array<int, string> 채널
     */
    public function getChannels(): array
    {
        return [self::CHANNEL];
    }

    /**
     * 채널 표시 이름을 반환합니다.
     *
     * @return array<string, string> 채널 => 이름
     */
    public function getChannelLabels(): array
    {
        return [self::CHANNEL => (string) __('sirsoft-sso::messages.reauth.channel_label')];
    }

    /**
     * 렌더 힌트 — IdP 화면으로 이동.
     *
     * @return string 렌더 힌트
     */
    public function getRenderHint(): string
    {
        return 'external_redirect';
    }

    /**
     * 민감 작업 재확인만 지원합니다.
     *
     * @param  string  $purpose  목적
     * @return bool 지원하면 true
     */
    public function supportsPurpose(string $purpose): bool
    {
        return $purpose === IdentityVerificationPurpose::SensitiveAction->value;
    }

    /**
     * 사용 가능 여부 — 별도 자격 증명이 없어 늘 사용 가능하다(회원별 판정은 canChallengeUser).
     *
     * @return bool 항상 true
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * 이 회원에게 요청을 낼 수 있는지 — 리디렉션 로그인이 되는 활성 IdP 에 활성 연결이 있어야 한다.
     *
     * @param  User  $user  회원
     * @return bool 대상이면 true
     */
    public function canChallengeUser(User $user): bool
    {
        return $this->reauthIdp($user) !== null;
    }

    /**
     * 본인인증 요청을 만들고 IdP 왕복 시작 주소를 돌려줍니다.
     *
     * 왕복 뒤 복귀 경로는 요청 맥락의 `return_path`(같은 출처 경로), 없으면 마이페이지.
     *
     * @param  User|array<string, mixed>  $target  대상 회원
     * @param  array<string, mixed>  $context  요청 맥락
     * @return VerificationChallenge 요청
     *
     * @throws \InvalidArgumentException 회원이 아니거나 대상이 아닐 때
     */
    public function requestChallenge(User|array $target, array $context = []): VerificationChallenge
    {
        $idp = $target instanceof User ? $this->reauthIdp($target) : null;
        if (! $target instanceof User || $idp === null) {
            throw new \InvalidArgumentException('SsoReauthIdentityProvider requires a member with an active SSO link.');
        }

        $purpose = (string) ($context['purpose'] ?? IdentityVerificationPurpose::SensitiveAction->value);
        $targetHash = hash('sha256', mb_strtolower((string) $target->email));
        $expiresAt = Carbon::now()->addMinutes(self::TTL_MINUTES);

        $log = $this->logRepository->create([
            'provider_id' => $this->getId(),
            'purpose' => $purpose,
            'channel' => self::CHANNEL,
            'user_id' => $target->id,
            'target_hash' => $targetHash,
            'status' => IdentityVerificationStatus::Processing->value,
            'render_hint' => 'external_redirect',
            'attempts' => 0,
            'max_attempts' => 1,
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
            'origin_type' => $context['origin_type'] ?? null,
            'origin_identifier' => $context['origin_identifier'] ?? null,
            'origin_policy_key' => $context['origin_policy_key'] ?? null,
            'properties' => $context['properties'] ?? null,
            'metadata' => ['idp_key' => $idp->key],
            'expires_at' => $expiresAt,
        ]);

        $returnPath = SafeRedirect::path(is_string($context['return_path'] ?? null) ? $context['return_path'] : null, '/mypage');
        $start = app(LoginFlowService::class)->issueStartUrl($target, $idp, LoginIntent::REAUTH, request(), $returnPath, (string) $log->id);

        return new VerificationChallenge(
            id: $log->id,
            providerId: $this->getId(),
            purpose: $purpose,
            channel: self::CHANNEL,
            targetHash: $targetHash,
            expiresAt: $expiresAt,
            renderHint: 'external_redirect',
            // 코어 ChallengeResource 의 redirect_url 이 이 값을 읽는다 — 같은 출처 경로(/plugins/sirsoft-sso/login/…)만 싣는다.
            redirectUrl: $start['start_url'],
            publicPayload: [
                'delivery' => 'none',
                'redirect_url' => $start['start_url'],
                'idp' => ['key' => $idp->key, 'label' => $idp->localizedLabel()],
            ],
            maxAttempts: 1,
        );
    }

    /**
     * 콜백이 같은 회원의 같은 연결을 확인했다는 일회용 증명을 남깁니다 (`LoginFlowService` 가 부른다).
     *
     * @param  string  $challengeId  요청 ID
     * @param  User  $user  IdP 가 확인한 회원
     * @param  IdentityProvider  $idp  왕복한 IdP
     * @return bool 요청과 회원이 맞아 증명을 남겼으면 true
     */
    public function markVerified(string $challengeId, User $user, IdentityProvider $idp): bool
    {
        $log = $this->logRepository->findById($challengeId);
        if (! $log || $log->provider_id !== $this->getId() || (int) $log->user_id !== (int) $user->getKey() || $log->isExpired()) {
            return false;
        }

        if (($log->metadata['idp_key'] ?? null) !== $idp->key) {
            return false;
        }

        $this->cache->put($this->proofKey($challengeId), (int) $user->getKey(), self::PROOF_TTL);

        return true;
    }

    /**
     * 요청을 확인합니다 — 콜백이 남긴 일회용 증명이 있어야 통과한다(입력 값은 보지 않는다).
     *
     * @param  string  $challengeId  요청 ID
     * @param  array<string, mixed>  $input  입력 (쓰지 않음)
     * @param  array<string, mixed>  $context  맥락
     * @return VerificationResult 결과
     */
    public function verify(string $challengeId, array $input, array $context = []): VerificationResult
    {
        $log = $this->logRepository->findById($challengeId);

        if (! $log) {
            return VerificationResult::failure($challengeId, $this->getId(), 'NOT_FOUND', 'identity.errors.challenge_not_found');
        }

        if ($log->provider_id !== $this->getId()) {
            return VerificationResult::failure($challengeId, $this->getId(), 'WRONG_PROVIDER', 'identity.errors.wrong_provider');
        }

        $status = $log->status instanceof IdentityVerificationStatus ? $log->status->value : (string) $log->status;
        if ($status !== IdentityVerificationStatus::Processing->value) {
            return VerificationResult::failure($challengeId, $this->getId(), 'INVALID_STATE', 'identity.errors.invalid_state');
        }

        if ($log->isExpired()) {
            $this->logRepository->updateById($log->id, ['status' => IdentityVerificationStatus::Expired->value]);

            return VerificationResult::failure($challengeId, $this->getId(), 'EXPIRED', 'identity.errors.expired');
        }

        $this->logRepository->updateById($log->id, ['attempts' => $log->attempts + 1]);

        $proofKey = $this->proofKey($challengeId);
        $proofUserId = $this->cache->get($proofKey);
        $this->cache->forget($proofKey);

        if ($log->user_id === null || (int) $proofUserId !== (int) $log->user_id) {
            $this->logRepository->updateById($log->id, ['status' => IdentityVerificationStatus::Failed->value]);

            return VerificationResult::failure($challengeId, $this->getId(), 'INVALID_CODE', 'identity.errors.invalid_code');
        }

        $verifiedAt = Carbon::now();
        $verificationToken = hash_hmac(
            'sha256',
            $log->purpose.'|'.$log->target_hash.'|'.Str::uuid()->toString(),
            (string) config('app.key', 'fallback-secret')
        );

        $this->logRepository->updateById($log->id, [
            'status' => IdentityVerificationStatus::Verified->value,
            'verified_at' => $verifiedAt,
            'verification_token' => $verificationToken,
        ]);

        return VerificationResult::success(
            challengeId: $challengeId,
            providerId: $this->getId(),
            verifiedAt: $verifiedAt,
            claims: ['verification_token' => $verificationToken],
        );
    }

    /**
     * 요청을 취소합니다.
     *
     * @param  string  $challengeId  요청 ID
     * @return bool 취소했으면 true
     */
    public function cancel(string $challengeId): bool
    {
        $log = $this->logRepository->findById($challengeId);

        if (! $log || $log->provider_id !== $this->getId()) {
            return false;
        }

        $this->logRepository->updateById($log->id, ['status' => IdentityVerificationStatus::Cancelled->value]);
        $this->cache->forget($this->proofKey($challengeId));

        return true;
    }

    /**
     * 관리자 설정 스키마 — 설정 없음.
     *
     * @return array<string, mixed> 빈 스키마
     */
    public function getSettingsSchema(): array
    {
        return [];
    }

    /**
     * 설정을 입힌 새 인스턴스를 반환합니다.
     *
     * @param  array<string, mixed>  $config  설정
     * @return static 인스턴스
     */
    public function withConfig(array $config): static
    {
        $clone = clone $this;
        $clone->config = $config;

        return $clone;
    }

    /**
     * 재확인에 쓸 IdP — 회원의 활성 연결 중 리디렉션 로그인이 되는 활성 IdP 의 첫째.
     *
     * @param  User  $user  회원
     * @return IdentityProvider|null IdP
     */
    private function reauthIdp(User $user): ?IdentityProvider
    {
        foreach ($this->accounts->forUser((int) $user->getKey()) as $account) {
            if (! $account->active) {
                continue;
            }

            $idp = $this->idps->findById((int) $account->idp_id);
            if ($idp === null || ! $idp->enabled) {
                continue;
            }

            try {
                if ($idp->driver()->capabilities()->redirect && $idp->driver()->isAvailable()) {
                    return $idp;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * 증명 캐시 키.
     *
     * @param  string  $challengeId  요청 ID
     * @return string 키
     */
    private function proofKey(string $challengeId): string
    {
        return 'reauth_proof:'.hash('sha256', $challengeId);
    }
}
