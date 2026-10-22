<?php

namespace Plugins\Sirsoft\SocialLogin\Identity;

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
use Plugins\Sirsoft\SocialLogin\Services\SocialReauthService;

/**
 * 코어 본인인증 수단 「소셜 재로그인」.
 *
 * 대체 이메일 회원(비밀번호 없음, 메일을 받을 수 없음)의 민감 작업 재확인(목적 `sensitive_action`)에만 쓴다. 인증번호가
 * 없다 — 확인은 소셜 로그인 콜백이 연결된 제공자 계정을 대조한 뒤 남긴 일회용 증명으로만 통과한다. 그래서 코어 본인인증
 * 확인 API 를 직접 불러도(입력 값과 무관하게) 통과하지 못한다.
 *
 * 대상 해시는 회원 이메일(대체 이메일 포함)의 해시로 둔다 — 코어 관문이 같은 회원의 최근 확인을 이메일 해시로 찾는다.
 *
 * @since 1.0.0
 */
class SocialReauthIdentityProvider implements IdentityVerificationInterface, UserScopedIdentityProviderInterface
{
    /** 인증 채널 */
    public const CHANNEL = 'social';

    /** 요청 유효 시간(분) — 제공자 화면을 다녀올 시간 */
    private const TTL_MINUTES = 10;

    /** @var array<string, mixed> 런타임 설정 (쓰지 않음) */
    private array $config = [];

    /**
     * @param  IdentityVerificationLogRepositoryInterface  $logRepository  본인인증 이력 저장소
     * @param  SocialReauthService  $reauth  소셜 재확인 서비스 (대상 판정·증명)
     */
    public function __construct(
        private readonly IdentityVerificationLogRepositoryInterface $logRepository,
        private readonly SocialReauthService $reauth,
    ) {}

    /**
     * 수단 식별자를 반환합니다.
     *
     * @return string 식별자
     */
    public function getId(): string
    {
        return SocialReauthService::PROVIDER_ID;
    }

    /**
     * 관리자 화면 표시 이름을 반환합니다.
     *
     * @return string 이름
     */
    public function getLabel(): string
    {
        return __('sirsoft-social_login::messages.reauth.provider_label');
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
        return [self::CHANNEL => __('sirsoft-social_login::messages.reauth.channel_label')];
    }

    /**
     * 렌더 힌트를 반환합니다 — 제공자 화면으로 이동.
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
     * 사용 가능 여부 — 별도 자격증명이 없어 늘 사용 가능하다(회원별 판정은 canChallengeUser).
     *
     * @return bool 항상 true
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * 이 회원에게 요청을 낼 수 있는지 판정합니다 (조회만).
     *
     * @param  User  $user  회원
     * @return bool 대상이면 true
     */
    public function canChallengeUser(User $user): bool
    {
        return $this->reauth->isEligible($user);
    }

    /**
     * 본인인증 요청을 만듭니다.
     *
     * @param  User|array<string, mixed>  $target  대상 회원
     * @param  array<string, mixed>  $context  요청 맥락
     * @return VerificationChallenge 요청
     *
     * @throws \InvalidArgumentException 회원이 아니거나 대상이 아닐 때
     */
    public function requestChallenge(User|array $target, array $context = []): VerificationChallenge
    {
        if (! $target instanceof User || ! $this->canChallengeUser($target)) {
            throw new \InvalidArgumentException('SocialReauthIdentityProvider requires an eligible member.');
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
            'metadata' => [],
            'expires_at' => $expiresAt,
        ]);

        return new VerificationChallenge(
            id: $log->id,
            providerId: $this->getId(),
            purpose: $purpose,
            channel: self::CHANNEL,
            targetHash: $targetHash,
            expiresAt: $expiresAt,
            renderHint: 'external_redirect',
            publicPayload: ['delivery' => 'none'],
            maxAttempts: 1,
        );
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

        if (! $this->reauth->consumeProof($challengeId, $log->user_id !== null ? (int) $log->user_id : null)) {
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
}
