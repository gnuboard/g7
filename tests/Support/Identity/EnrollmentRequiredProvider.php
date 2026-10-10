<?php

namespace Tests\Support\Identity;

use App\Contracts\Extension\IdentityVerificationInterface;
use App\Contracts\Extension\MailFallbackAwareProviderInterface;
use App\Contracts\Extension\UserScopedIdentityProviderInterface;
use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Models\User;

/**
 * 「이 사용자에게 쓸 수 있는 수단이 없고 운영자가 메일 폴백을 껐다」를 만드는 테스트 프로바이더.
 *
 * `AuthService::resolveTwoFactorProvider()` 가 `TwoFactorEnrollmentRequiredException` 을
 * 던지게 하는 **유일한 조합**이다 — TOTP 같은 사용자별 등록 수단을 들여온 사이트에서 아직
 * 등록하지 않은 계정이 겪는 상태를 흉내낸다. 실제 플러그인(`sirsoft-totp`)을 끌어오지 않고
 * 그 조합만 재현하므로, 플러그인 설치 여부와 무관하게 코어 계약을 잴 수 있다.
 *
 * challenge 는 만들지 않는다 — 이 프로바이더가 쓰이는 경로는 challenge 발행 **이전**에
 * 예외로 끝나야 하고, 만약 발행까지 도달하면 그 자체가 계약 위반이므로 던져서 드러낸다.
 */
class EnrollmentRequiredProvider implements IdentityVerificationInterface, MailFallbackAwareProviderInterface, UserScopedIdentityProviderInterface
{
    public const ID = 'test:enrollment_required';

    /**
     * 프로바이더 식별자를 반환합니다.
     *
     * @return string 식별자
     */
    public function getId(): string
    {
        return self::ID;
    }

    /**
     * 표시 이름을 반환합니다.
     *
     * @return string 표시 이름
     */
    public function getLabel(): string
    {
        return 'Enrollment Required Provider';
    }

    /**
     * 지원 채널을 반환합니다.
     *
     * @return array<int, string> 채널 목록
     */
    public function getChannels(): array
    {
        return ['app'];
    }

    /**
     * 채널 표시 이름을 반환합니다.
     *
     * @return array<string, string> 채널별 표시 이름
     */
    public function getChannelLabels(): array
    {
        return ['app' => 'App'];
    }

    /**
     * 화면 렌더 힌트를 반환합니다.
     *
     * @return string 렌더 힌트
     */
    public function getRenderHint(): string
    {
        return 'text_code';
    }

    /**
     * 해당 purpose 를 지원하는지 반환합니다.
     *
     * @param  string  $purpose  검사할 purpose
     * @return bool 항상 true — 해석 단계까지는 후보로 잡혀야 한다
     */
    public function supportsPurpose(string $purpose): bool
    {
        return true;
    }

    /**
     * 프로바이더 가용 여부를 반환합니다.
     *
     * @return bool 항상 true
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * challenge 를 발행합니다 — 이 프로바이더는 발행하지 않습니다.
     *
     * @param  User|array  $target  대상
     * @param  array  $context  부가 정보
     * @return VerificationChallenge 반환하지 않는다
     *
     * @throws \LogicException 발행까지 도달하면 계약 위반이므로 드러낸다
     */
    public function requestChallenge(User|array $target, array $context = []): VerificationChallenge
    {
        throw new \LogicException('이 프로바이더는 challenge 를 발행하지 않는다 — 여기 도달했다면 계약이 깨진 것이다.');
    }

    /**
     * challenge 를 검증합니다 — 이 프로바이더는 검증하지 않습니다.
     *
     * @param  string  $challengeId  challenge 식별자
     * @param  array  $input  입력값
     * @param  array  $context  부가 정보
     * @return VerificationResult 반환하지 않는다
     *
     * @throws \LogicException 검증까지 도달하면 계약 위반이므로 드러낸다
     */
    public function verify(string $challengeId, array $input, array $context = []): VerificationResult
    {
        throw new \LogicException('이 프로바이더는 검증하지 않는다 — 여기 도달했다면 계약이 깨진 것이다.');
    }

    /**
     * challenge 를 취소합니다.
     *
     * @param  string  $challengeId  challenge 식별자
     * @return bool 항상 false
     */
    public function cancel(string $challengeId): bool
    {
        return false;
    }

    /**
     * 설정 스키마를 반환합니다.
     *
     * @return array 빈 스키마
     */
    public function getSettingsSchema(): array
    {
        return [];
    }

    /**
     * 설정을 주입한 사본을 반환합니다.
     *
     * @param  array  $config  설정
     * @return static 자기 자신 (설정을 쓰지 않는다)
     */
    public function withConfig(array $config): static
    {
        return $this;
    }

    /**
     * 이 사용자에게 이 수단을 쓸 수 있는지 반환합니다.
     *
     * @param  User  $user  대상 사용자
     * @return bool 항상 false — 아직 등록하지 않은 상태를 흉내낸다
     */
    public function canChallengeUser(User $user): bool
    {
        return false;
    }

    /**
     * 메일 폴백을 허용하는지 반환합니다.
     *
     * @return bool 항상 false — 운영자가 명시적으로 껐다는 뜻이다
     */
    public function allowsMailFallback(): bool
    {
        return false;
    }
}
