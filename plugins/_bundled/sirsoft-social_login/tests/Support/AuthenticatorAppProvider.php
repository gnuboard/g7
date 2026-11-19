<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Support;

use App\Contracts\Extension\IdentityVerificationInterface;
use App\Contracts\Extension\UserScopedIdentityProviderInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationStatus;
use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * 시험용 인증 앱 수단 — 회원별 등록이 있어야 쓸 수 있는 2단계 인증(TOTP 플러그인과 같은 계약).
 *
 * 메일과 달리 회원 이메일을 쓰지 않으므로, 메일을 받을 수 없는 대체 이메일 회원도 등록했다면 이 수단으로 2단계 인증을 받는다.
 * 요청 행만 만들고 확인은 하지 않는다(소셜 로그인 시험은 교환 결과가 이 수단의 2단계 인증 단계인지만 잰다).
 */
class AuthenticatorAppProvider implements IdentityVerificationInterface, UserScopedIdentityProviderInterface
{
    public const ID = 'test.authenticator_app';

    /** @var array<int, true> 등록한 회원 ID */
    private array $enrolled = [];

    /**
     * 본인인증 요청 기록 저장소를 받습니다.
     *
     * @param  IdentityVerificationLogRepositoryInterface  $logs  본인인증 요청 기록
     */
    public function __construct(private readonly IdentityVerificationLogRepositoryInterface $logs) {}

    /**
     * 회원을 등록합니다.
     *
     * @param  User  $user  등록할 회원
     * @return void 반환값 없음
     */
    public function enroll(User $user): void
    {
        $this->enrolled[(int) $user->getKey()] = true;
    }

    /**
     * 수단 ID 를 돌려줍니다.
     *
     * @return string 수단 ID
     */
    public function getId(): string
    {
        return self::ID;
    }

    /**
     * 수단 이름을 돌려줍니다.
     *
     * @return string 이름
     */
    public function getLabel(): string
    {
        return 'Authenticator app (test)';
    }

    /**
     * 채널 목록을 돌려줍니다.
     *
     * @return array<int, string> 채널
     */
    public function getChannels(): array
    {
        return ['app'];
    }

    /**
     * 채널 이름을 돌려줍니다.
     *
     * @return array<string, string> 채널 이름
     */
    public function getChannelLabels(): array
    {
        return ['app' => 'App'];
    }

    /**
     * 입력 방식을 돌려줍니다.
     *
     * @return string 입력 방식
     */
    public function getRenderHint(): string
    {
        return 'text_code';
    }

    /**
     * 로그인 2단계 인증 목적만 지원합니다.
     *
     * @param  string  $purpose  목적
     * @return bool 로그인이면 true
     */
    public function supportsPurpose(string $purpose): bool
    {
        return $purpose === 'login';
    }

    /**
     * 사이트에서 쓸 수 있는지 돌려줍니다.
     *
     * @return bool 항상 true
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * 이 회원에게 2단계 인증을 요구할 수 있는지 돌려줍니다.
     *
     * @param  User  $user  회원
     * @return bool 등록한 회원이면 true
     */
    public function canChallengeUser(User $user): bool
    {
        return isset($this->enrolled[(int) $user->getKey()]);
    }

    /**
     * 인증 요청 행을 만들고 요청을 돌려줍니다.
     *
     * @param  User|array<string, mixed>  $target  대상
     * @param  array<string, mixed>  $context  맥락
     * @return VerificationChallenge 요청
     */
    public function requestChallenge(User|array $target, array $context = []): VerificationChallenge
    {
        $expiresAt = Carbon::now()->addMinutes(5);
        $targetHash = hash('sha256', $target instanceof User ? (string) $target->getKey() : '');

        $log = $this->logs->create([
            'provider_id' => self::ID,
            'purpose' => (string) ($context['purpose'] ?? 'login'),
            'channel' => 'app',
            'user_id' => $target instanceof User ? $target->getKey() : null,
            'target_hash' => $targetHash,
            'status' => IdentityVerificationStatus::Sent->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 5,
            'metadata' => [],
            'expires_at' => $expiresAt,
        ]);

        return new VerificationChallenge(
            id: $log->id,
            providerId: self::ID,
            purpose: (string) ($context['purpose'] ?? 'login'),
            channel: 'app',
            targetHash: $targetHash,
            expiresAt: $expiresAt,
            renderHint: 'text_code',
            publicPayload: [],
            maxAttempts: 5,
        );
    }

    /**
     * 확인은 하지 않습니다 — 시험이 여기까지 오면 계약이 깨진 것이다.
     *
     * @param  string  $challengeId  요청 ID
     * @param  array<string, mixed>  $input  입력
     * @param  array<string, mixed>  $context  맥락
     * @return VerificationResult 결과
     */
    public function verify(string $challengeId, array $input, array $context = []): VerificationResult
    {
        throw new \LogicException('시험용 수단은 확인하지 않는다.');
    }

    /**
     * 취소는 하지 않습니다.
     *
     * @param  string  $challengeId  요청 ID
     * @return bool 항상 false
     */
    public function cancel(string $challengeId): bool
    {
        return false;
    }

    /**
     * 설정 스키마를 돌려줍니다.
     *
     * @return array<string, mixed> 빈 스키마
     */
    public function getSettingsSchema(): array
    {
        return [];
    }

    /**
     * 설정을 받지 않고 같은 인스턴스를 돌려줍니다.
     *
     * @param  array<string, mixed>  $config  설정
     * @return static 같은 인스턴스
     */
    public function withConfig(array $config): static
    {
        return $this;
    }
}
