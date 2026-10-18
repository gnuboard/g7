<?php

namespace Plugins\Sirsoft\Totp\Identity;

use App\Contracts\Extension\IdentityVerificationInterface;
use App\Contracts\Extension\MailFallbackAwareProviderInterface;
use App\Contracts\Extension\UserScopedIdentityProviderInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationChannel;
use App\Enums\IdentityVerificationPurpose;
use App\Enums\IdentityVerificationStatus;
use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Models\User;
use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Totp\Models\TotpCredential;
use Plugins\Sirsoft\Totp\Repositories\Contracts\TotpCredentialRepositoryInterface;
use Plugins\Sirsoft\Totp\Support\Totp;

/**
 * 인증 앱(TOTP) 본인인증 프로바이더.
 *
 * 코어 IDV 계약을 그대로 구현하되, **아무것도 발송하지 않는** 수단이라는 점이 메일
 * 프로바이더와 다릅니다. 코드는 사용자 단말의 앱이 이미 만들고 있으므로 서버가 할 일은
 * challenge 를 열어 두고 제출된 코드를 대조하는 것뿐입니다.
 *
 * 그래서 `requestChallenge()` 는 로그를 `Sent` 로 **즉시** 기록합니다. 코어
 * `AuthService::startTwoFactorChallenge()` 가 `Failed` 를 발송 실패로 읽고 로그인을
 * 차단하므로, `Requested` 로 두면 "전달 가능 상태"를 확인할 방법이 없습니다. 여기서
 * `Sent` 는 "보냈다"가 아니라 "사용자가 코드를 얻을 수 있는 상태가 됐다"를 뜻합니다.
 *
 * @since 1.0.0
 */
class TotpIdentityProvider implements IdentityVerificationInterface, MailFallbackAwareProviderInterface, UserScopedIdentityProviderInterface
{
    /** 프로바이더 식별자 */
    public const PROVIDER_ID = 'totp';

    /** 백업 코드로 인정할 최소 길이 — 6자리 TOTP 와 구분하는 기준 */
    private const BACKUP_CODE_MIN_LENGTH = 8;

    /**
     * @param  IdentityVerificationLogRepositoryInterface  $logRepository  challenge 로그 저장소
     * @param  TotpCredentialRepositoryInterface  $credentials  자격증명 저장소
     * @param  array<string, mixed>  $config  플러그인 설정 (issuer, allow_mail_fallback)
     */
    public function __construct(
        protected IdentityVerificationLogRepositoryInterface $logRepository,
        protected TotpCredentialRepositoryInterface $credentials,
        protected array $config = [],
    ) {}

    /**
     * 프로바이더 식별자를 반환합니다.
     *
     * @return string 프로바이더 ID
     */
    public function getId(): string
    {
        return self::PROVIDER_ID;
    }

    /**
     * 관리자 UI 표시 라벨을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function getLabel(): string
    {
        return __('sirsoft-totp::messages.provider.label');
    }

    /**
     * 지원 채널 목록을 반환합니다.
     *
     * @return array<int, string> 채널 키 배열
     */
    public function getChannels(): array
    {
        return [IdentityVerificationChannel::App->value];
    }

    /**
     * 채널 키 → 다국어 표시 라벨 맵을 반환합니다.
     *
     * @return array<string, string> 채널 키 → 라벨 맵
     */
    public function getChannelLabels(): array
    {
        return [
            IdentityVerificationChannel::App->value => __('identity.channels.app'),
        ];
    }

    /**
     * 기본 렌더 힌트를 반환합니다.
     *
     * @return string 렌더 힌트
     */
    public function getRenderHint(): string
    {
        return 'text_code';
    }

    /**
     * 지정된 purpose 를 지원하는지 반환합니다.
     *
     * 로그인 2단계 인증 전용입니다. 가입·비밀번호 재설정은 아직 자격증명이 없는 시점이라
     * 인증 앱으로 확인할 수 없고, 열어 두면 운영자가 그 목적에 매핑해 흐름을 막을 수 있습니다.
     *
     * @param  string  $purpose  본인인증 목적
     * @return bool 지원 여부
     */
    public function supportsPurpose(string $purpose): bool
    {
        return $purpose === IdentityVerificationPurpose::Login->value;
    }

    /**
     * 프로바이더 사용 가능 여부를 반환합니다.
     *
     * 외부 연동이 없어 런타임 설정에 의존하지 않는다 — 플러그인이 활성이면 항상 사용 가능하고,
     * 사용자별 등록 여부는 `canChallengeUser()` 가 따로 답한다.
     *
     * @return bool 항상 true
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * 이 사용자에게 지금 challenge 를 발행할 수 있는지 여부.
     *
     * 확정된 자격증명이 있어야 한다. 발급만 하고 확정하지 않은 자격증명으로 2단계 인증을
     * 요구하면, 앱에 시크릿을 넣지 않은 사용자의 계정이 그대로 잠긴다.
     *
     * @param  User  $user  대상 사용자
     * @return bool challenge 발행 가능 여부
     */
    public function canChallengeUser(User $user): bool
    {
        return $this->credentials->hasConfirmed((int) $user->id);
    }

    /**
     * 인증 앱을 등록하지 않은 사용자를 메일 2단계 인증으로 되돌려 보내도 되는지 여부.
     *
     * 기본은 허용이다 — 등록하지 않은 관리자가 로그인 자체를 못 하는 콜드스타트 락아웃을
     * 구조적으로 배제한다. 그 우회를 허용하지 않는 사이트만 설정에서 끈다.
     *
     * @return bool 메일 폴백 허용 여부
     */
    public function allowsMailFallback(): bool
    {
        return (bool) ($this->config['allow_mail_fallback'] ?? true);
    }

    /**
     * Challenge 를 발행합니다.
     *
     * 발송이 없으므로 로그를 `Sent` 로 즉시 기록한다 (클래스 주석 참조).
     *
     * @param  User|array<string, mixed>  $target  대상 사용자 — 반드시 User (등록된 자격증명이 있어야 한다)
     * @param  array<string, mixed>  $context  origin/ip/ua/purpose 등
     * @return VerificationChallenge 발행된 challenge
     *
     * @throws \InvalidArgumentException 사용자 대상이 아니거나 확정 자격증명이 없을 때
     */
    public function requestChallenge(User|array $target, array $context = []): VerificationChallenge
    {
        if (! $target instanceof User) {
            throw new \InvalidArgumentException('TotpIdentityProvider requires a User target.');
        }

        if (! $this->canChallengeUser($target)) {
            throw new \InvalidArgumentException('TotpIdentityProvider requires a confirmed credential.');
        }

        $purpose = (string) ($context['purpose'] ?? IdentityVerificationPurpose::Login->value);
        $ttlMinutes = (int) config('settings.identity.challenge_ttl_minutes', 15);
        $maxAttempts = (int) config('settings.identity.max_attempts', 5);

        // 이메일이 없어도 동작해야 하는 수단이므로 대상 해시는 사용자 id 로 만든다.
        $targetHash = hash('sha256', 'user:'.$target->id);
        $expiresAt = Carbon::now()->add(CarbonInterval::minutes($ttlMinutes));

        $log = $this->logRepository->create([
            'provider_id' => self::PROVIDER_ID,
            'purpose' => $purpose,
            'channel' => IdentityVerificationChannel::App->value,
            'user_id' => $target->id,
            'target_hash' => $targetHash,
            // 발송 단계가 없다 — 코어가 "전달 가능" 으로 읽는 상태로 바로 기록한다.
            'status' => IdentityVerificationStatus::Sent->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
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
            providerId: self::PROVIDER_ID,
            purpose: $purpose,
            channel: IdentityVerificationChannel::App->value,
            targetHash: $targetHash,
            expiresAt: $expiresAt,
            renderHint: 'text_code',
            publicPayload: [
                'code_length' => Totp::DIGITS,
                // 프론트가 「인증번호 다시 받기」를 숨기고 부제를 앱 안내로 바꾸는 근거.
                'delivery' => 'none',
            ],
            metadata: [],
            maxAttempts: $maxAttempts,
        );
    }

    /**
     * Challenge 를 검증합니다.
     *
     * 상태 가드 시퀀스(NOT_FOUND / WRONG_PROVIDER / INVALID_STATE / EXPIRED / MAX_ATTEMPTS)는
     * 메일 프로바이더와 동일한 계약을 따른다 — 프론트와 코어가 두 수단을 같은 코드로 다룬다.
     *
     * 입력이 6자리면 TOTP, 8자 이상이면 백업 코드로 본다. 실패 사유는 코드 오답과 구분하지
     * 않는다 — 구분해 주면 어떤 입력이 "형식은 맞다" 인지 흘러 나간다.
     *
     * @param  string  $challengeId  challenge UUID
     * @param  array<string, mixed>  $input  `code` 키에 사용자 입력
     * @param  array<string, mixed>  $context  요청 컨텍스트
     * @return VerificationResult 검증 결과
     */
    public function verify(string $challengeId, array $input, array $context = []): VerificationResult
    {
        $log = $this->logRepository->findById($challengeId);

        if (! $log) {
            return VerificationResult::failure($challengeId, self::PROVIDER_ID, 'NOT_FOUND', 'identity.errors.challenge_not_found');
        }

        if ($log->provider_id !== self::PROVIDER_ID) {
            return VerificationResult::failure($challengeId, self::PROVIDER_ID, 'WRONG_PROVIDER', 'identity.errors.wrong_provider');
        }

        $status = $log->status instanceof IdentityVerificationStatus ? $log->status->value : (string) $log->status;

        if (in_array($status, [
            IdentityVerificationStatus::Verified->value,
            IdentityVerificationStatus::Expired->value,
            IdentityVerificationStatus::Cancelled->value,
        ], true)) {
            return VerificationResult::failure($challengeId, self::PROVIDER_ID, 'INVALID_STATE', 'identity.errors.invalid_state');
        }

        if ($log->isExpired()) {
            $this->logRepository->updateById($log->id, [
                'status' => IdentityVerificationStatus::Expired->value,
            ]);

            return VerificationResult::failure($challengeId, self::PROVIDER_ID, 'EXPIRED', 'identity.errors.expired');
        }

        if ($log->attempts >= $log->max_attempts) {
            return VerificationResult::failure($challengeId, self::PROVIDER_ID, 'MAX_ATTEMPTS', 'identity.errors.max_attempts');
        }

        $credential = $log->user_id === null
            ? null
            : $this->credentials->findConfirmedByUserId((int) $log->user_id);

        $this->logRepository->updateById($log->id, [
            'attempts' => $log->attempts + 1,
        ]);

        $provided = trim((string) ($input['code'] ?? ''));
        $matched = $credential !== null && $this->matchCredential($credential, $provided);

        if (! $matched) {
            // 이번 오답으로 max_attempts 에 도달하면 잠금(Failed)으로 전환하고 MAX_ATTEMPTS 로
            // 답한다 — 메일 프로바이더와 같은 이유(막 소진된 응답에서 안내하지 않으면 그 문구가
            // 사용자에게 영영 도달하지 못한다).
            if (($log->attempts + 1) >= $log->max_attempts) {
                $this->logRepository->updateById($log->id, [
                    'status' => IdentityVerificationStatus::Failed->value,
                ]);

                return VerificationResult::failure($challengeId, self::PROVIDER_ID, 'MAX_ATTEMPTS', 'identity.errors.max_attempts');
            }

            return VerificationResult::failure($challengeId, self::PROVIDER_ID, 'INVALID_CODE', 'identity.errors.invalid_code');
        }

        $verifiedAt = Carbon::now();
        $verificationToken = $this->generateVerificationToken($log->purpose, $log->target_hash);

        $this->logRepository->updateById($log->id, [
            'status' => IdentityVerificationStatus::Verified->value,
            'verified_at' => $verifiedAt,
            'verification_token' => $verificationToken,
        ]);

        return VerificationResult::success(
            challengeId: $challengeId,
            providerId: self::PROVIDER_ID,
            verifiedAt: $verifiedAt,
            identityHash: null,
            claims: ['verification_token' => $verificationToken],
        );
    }

    /**
     * 입력을 자격증명과 대조합니다 (TOTP 코드 또는 백업 코드).
     *
     * TOTP 성공 시 `last_used_timestep` 을 올려 같은 코드의 재사용을 막는다.
     *
     * @param  TotpCredential  $credential  대상 자격증명
     * @param  string  $provided  사용자 입력
     * @return bool 일치 여부
     */
    protected function matchCredential(TotpCredential $credential, string $provided): bool
    {
        // 사용자가 넣는 구분자(공백·하이픈)를 걷어낸 알맹이로 어느 수단인지 가른다.
        $alnum = preg_replace('/[^A-Za-z0-9]/', '', $provided) ?? '';

        if (strlen($alnum) === Totp::DIGITS && ctype_digit($alnum)) {
            $step = Totp::verify(
                secret: (string) $credential->secret,
                code: $alnum,
                window: 1,
                notBeforeTimestep: $credential->last_used_timestep,
            );

            if ($step === null) {
                return false;
            }

            $this->credentials->update($credential, ['last_used_timestep' => $step]);

            return true;
        }

        // 6자리 숫자가 아니면 백업 코드로 본다. 길이 하한을 두어 오타난 TOTP 가 백업 코드
        // 대조로 새지 않게 한다.
        if (strlen($alnum) < self::BACKUP_CODE_MIN_LENGTH) {
            return false;
        }

        $remaining = $credential->matchBackupCode($provided);

        if ($remaining === null) {
            return false;
        }

        // 백업 코드는 1회용 — 맞은 항목을 뺀 목록으로 즉시 덮어쓴다.
        $this->credentials->update($credential, ['backup_codes' => $remaining]);

        return true;
    }

    /**
     * Challenge 를 취소합니다.
     *
     * @param  string  $challengeId  challenge UUID
     * @return bool 취소 성공 여부
     */
    public function cancel(string $challengeId): bool
    {
        $log = $this->logRepository->findById($challengeId);

        if (! $log || $log->provider_id !== self::PROVIDER_ID) {
            return false;
        }

        $this->logRepository->updateById($log->id, [
            'status' => IdentityVerificationStatus::Cancelled->value,
        ]);

        return true;
    }

    /**
     * 관리자 환경설정 UI 가 렌더할 설정 스키마.
     *
     * 이 프로바이더의 설정은 플러그인 설정 화면이 소유한다 — IDV 프로바이더 카드에는
     * 중복 노출하지 않는다.
     *
     * @return array<string, array<string, mixed>> 빈 스키마
     */
    public function getSettingsSchema(): array
    {
        return [];
    }

    /**
     * 설정값을 주입한 새 인스턴스를 반환합니다.
     *
     * @param  array<string, mixed>  $config  런타임 설정
     * @return static 설정이 주입된 복제본
     */
    public function withConfig(array $config): static
    {
        $clone = clone $this;
        $clone->config = $config;

        return $clone;
    }

    /**
     * 검증 완료 토큰을 생성합니다.
     *
     * 메일 프로바이더와 같은 레시피를 쓴다 — 코어가 토큰의 출처를 가리지 않고 소비한다.
     *
     * @param  string  $purpose  본인인증 목적
     * @param  string  $targetHash  대상 해시
     * @return string 검증 토큰
     */
    protected function generateVerificationToken(string $purpose, string $targetHash): string
    {
        return hash_hmac(
            'sha256',
            $purpose.'|'.$targetHash.'|'.Str::uuid()->toString(),
            (string) config('app.key', 'fallback-secret')
        );
    }
}
