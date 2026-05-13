<?php

namespace Tests\Support\Identity;

use App\Contracts\Extension\IdentityVerificationInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationStatus;
use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Models\User;
use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 테스트 전용 IDV 프로바이더.
 *
 * 결정적 코드(`'000000'`)를 발급하며 메일 발송을 수행하지 않는다 — Mail::fake() 와 Notification::fake() 의존 없이
 * 라이프사이클 통합 테스트(보호 API → 428 → challenge → verify → 재시도) 를 빠르게 회전할 수 있다.
 *
 * 메일 회로 자체를 검증하는 테스트는 `MailIdentityProvider` 그대로 + `Mail::fake()` 사용 (Part B-3 Mail::fake 라우트).
 */
class TestIdentityProvider implements IdentityVerificationInterface
{
    public const ID = 'test:fixed_code';

    public const FIXED_CODE = '000000';

    public function __construct(
        protected IdentityVerificationLogRepositoryInterface $logRepository,
        protected array $config = [],
    ) {}

    public function getId(): string
    {
        return self::ID;
    }

    public function getLabel(): string
    {
        return 'Test Fixed Code Provider';
    }

    public function getChannels(): array
    {
        return ['email'];
    }

    public function getRenderHint(): string
    {
        return 'text_code';
    }

    public function supportsPurpose(string $purpose): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function requestChallenge(User|array $target, array $context = []): VerificationChallenge
    {
        $email = $target instanceof User
            ? $target->email
            : (string) ($target['email'] ?? 'test@example.test');

        $purpose = (string) ($context['purpose'] ?? 'sensitive_action');
        $targetHash = hash('sha256', mb_strtolower($email));
        $expiresAt = Carbon::now()->add(CarbonInterval::minutes(15));

        $log = $this->logRepository->create([
            'provider_id' => self::ID,
            'purpose' => $purpose,
            'channel' => 'email',
            'user_id' => $target instanceof User ? $target->id : null,
            'target_hash' => $targetHash,
            'status' => IdentityVerificationStatus::Sent->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 5,
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
            'origin_type' => $context['origin_type'] ?? null,
            'origin_identifier' => $context['origin_identifier'] ?? null,
            'origin_policy_key' => $context['origin_policy_key'] ?? null,
            'properties' => $context['properties'] ?? null,
            'metadata' => ['code_hash' => Hash::make(self::FIXED_CODE)],
            'expires_at' => $expiresAt,
        ]);

        return new VerificationChallenge(
            id: $log->id,
            providerId: self::ID,
            purpose: $purpose,
            channel: 'email',
            targetHash: $targetHash,
            expiresAt: $expiresAt,
            renderHint: 'text_code',
            publicPayload: ['code_length' => strlen(self::FIXED_CODE)],
            metadata: [],
        );
    }

    public function verify(string $challengeId, array $input, array $context = []): VerificationResult
    {
        $log = $this->logRepository->findById($challengeId);

        if (! $log || $log->provider_id !== self::ID) {
            return VerificationResult::failure($challengeId, self::ID, 'NOT_FOUND', 'identity.errors.challenge_not_found');
        }

        if ($log->isExpired()) {
            $this->logRepository->updateById($log->id, [
                'status' => IdentityVerificationStatus::Expired->value,
            ]);

            return VerificationResult::failure($challengeId, self::ID, 'EXPIRED', 'identity.errors.expired');
        }

        $provided = (string) ($input['code'] ?? '');

        if ($provided !== self::FIXED_CODE) {
            return VerificationResult::failure($challengeId, self::ID, 'INVALID_CODE', 'identity.errors.invalid_code');
        }

        $verifiedAt = Carbon::now();
        $verificationToken = hash_hmac('sha256', $log->purpose.'|'.$log->target_hash.'|'.Str::uuid()->toString(), 'test-secret');

        $this->logRepository->updateById($log->id, [
            'status' => IdentityVerificationStatus::Verified->value,
            'verified_at' => $verifiedAt,
            'verification_token' => $verificationToken,
        ]);

        return VerificationResult::success(
            challengeId: $challengeId,
            providerId: self::ID,
            verifiedAt: $verifiedAt,
            claims: ['verification_token' => $verificationToken],
        );
    }

    public function cancel(string $challengeId): bool
    {
        return $this->logRepository->updateById($challengeId, [
            'status' => IdentityVerificationStatus::Cancelled->value,
        ]);
    }

    public function getSettingsSchema(): array
    {
        return [];
    }

    public function withConfig(array $config): static
    {
        $clone = clone $this;
        $clone->config = array_merge($this->config, $config);

        return $clone;
    }
}
