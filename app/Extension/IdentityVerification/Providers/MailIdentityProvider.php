<?php

namespace App\Extension\IdentityVerification\Providers;

use App\Contracts\Extension\IdentityVerificationInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationStatus;
use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Models\User;
use App\Services\IdentityMessageDispatcher;
use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * 코어 기본 본인인증 프로바이더 — 메일 채널.
 *
 * 두 가지 렌더링 힌트를 purpose 별로 분기합니다.
 * - purpose=password_reset  → render_hint=link, 기존 `password_reset_tokens` 흐름 재사용 가능
 * - 그 외 purpose            → render_hint=text_code, 6자리 코드를 발송
 *
 * 모든 challenge 는 identity_verification_logs 테이블에 기록됩니다.
 *
 * @since 7.0.0-beta.4
 */
class MailIdentityProvider implements IdentityVerificationInterface
{
    public const ID = 'g7:core.mail';

    /**
     * @param  array  $config  withConfig() 로 전달되는 런타임 설정 (code_length, code_ttl_minutes, link_ttl_minutes 등)
     */
    public function __construct(
        protected IdentityVerificationLogRepositoryInterface $logRepository,
        protected array $config = [],
    ) {}

    /**
     * 프로바이더 식별자를 반환합니다.
     *
     * @return string 프로바이더 ID
     */
    public function getId(): string
    {
        return self::ID;
    }

    /**
     * 프로바이더 표시 라벨을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function getLabel(): string
    {
        return __('identity.providers.mail.label');
    }

    /**
     * 지원 채널 목록을 반환합니다.
     *
     * @return array<string> 채널 키 배열
     */
    public function getChannels(): array
    {
        return ['email'];
    }

    /**
     * 기본 렌더 힌트를 반환합니다.
     *
     * @return string 렌더 힌트 (text_code, link 등)
     */
    public function getRenderHint(): string
    {
        // 기본값 — purpose 별 분기는 requestChallenge 내부에서 수행
        return 'text_code';
    }

    /**
     * 지정된 purpose 를 지원하는지 반환합니다.
     *
     * @param  string  $purpose  본인인증 목적
     * @return bool 지원 여부
     */
    public function supportsPurpose(string $purpose): bool
    {
        // 메일 프로바이더는 모든 코어·플러그인 purpose 를 범용 지원
        return true;
    }

    /**
     * 프로바이더 사용 가능 여부를 반환합니다.
     *
     * @return bool 메일러 설정 존재 시 true
     */
    public function isAvailable(): bool
    {
        $mailer = (string) config('mail.default', '');

        return $mailer !== '';
    }

    /**
     * 인증 챌린지를 발급하고 메일을 발송합니다.
     *
     * @param  User|array  $target  대상 사용자 또는 식별 정보
     * @param  array  $context  요청 컨텍스트 (purpose, ip_address 등)
     * @return VerificationChallenge 발급된 챌린지
     *
     * @throws \InvalidArgumentException 이메일이 비어있는 경우
     */
    public function requestChallenge(User|array $target, array $context = []): VerificationChallenge
    {
        $email = $target instanceof User
            ? $target->email
            : (string) ($target['email'] ?? '');

        if ($email === '') {
            throw new \InvalidArgumentException('MailIdentityProvider requires an email target.');
        }

        $purpose = (string) ($context['purpose'] ?? 'sensitive_action');
        $renderHint = $this->resolveRenderHint($purpose);
        $ttlMinutes = (int) config('settings.identity.challenge_ttl_minutes', 15);
        $maxAttempts = (int) config('settings.identity.max_attempts', 5);
        $targetHash = hash('sha256', mb_strtolower($email));

        $metadata = [];
        $publicPayload = [];
        $code = null;
        $linkToken = null;

        if ($renderHint === 'text_code') {
            $code = $this->generateNumericCode((int) ($this->config['code_length'] ?? 6));
            $metadata['code_hash'] = Hash::make($code);
            $publicPayload['code_length'] = strlen($code);
        } else {
            // link 흐름 — 수신자에게는 서명 링크 전달, 서버는 해시만 저장
            $linkToken = Str::random(64);
            $metadata['link_token_hash'] = Hash::make($linkToken);
            $publicPayload['link_hint'] = 'email_link';
        }

        $expiresAt = Carbon::now()->add(CarbonInterval::minutes($ttlMinutes));

        $log = $this->logRepository->create([
            'provider_id' => self::ID,
            'purpose' => $purpose,
            'channel' => 'email',
            'user_id' => $target instanceof User ? $target->id : null,
            'target_hash' => $targetHash,
            'status' => IdentityVerificationStatus::Requested->value,
            'render_hint' => $renderHint,
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
            'origin_type' => $context['origin_type'] ?? null,
            'origin_identifier' => $context['origin_identifier'] ?? null,
            'origin_policy_key' => $context['origin_policy_key'] ?? null,
            'properties' => $context['properties'] ?? null,
            'metadata' => $metadata,
            'expires_at' => $expiresAt,
        ]);

        $sent = $this->dispatchMessage(
            email: $email,
            purpose: $purpose,
            renderHint: $renderHint,
            challengeId: $log->id,
            policyKey: $context['origin_policy_key'] ?? null,
            code: $code,
            linkToken: $linkToken,
            ttlMinutes: $ttlMinutes,
            expiresAt: $expiresAt,
        );

        $this->logRepository->updateById($log->id, [
            'status' => $sent ? IdentityVerificationStatus::Sent->value : IdentityVerificationStatus::Failed->value,
        ]);

        return new VerificationChallenge(
            id: $log->id,
            providerId: self::ID,
            purpose: $purpose,
            channel: 'email',
            targetHash: $targetHash,
            expiresAt: $expiresAt,
            renderHint: $renderHint,
            publicPayload: $publicPayload,
            metadata: [],
        );
    }

    /**
     * 사용자가 제출한 코드/토큰을 검증합니다.
     *
     * @param  string  $challengeId  챌린지 ID
     * @param  array  $input  사용자 입력 (code 또는 token)
     * @param  array  $context  검증 컨텍스트
     * @return VerificationResult 검증 결과
     */
    public function verify(string $challengeId, array $input, array $context = []): VerificationResult
    {
        $log = $this->logRepository->findById($challengeId);

        if (! $log) {
            return VerificationResult::failure($challengeId, self::ID, 'NOT_FOUND', 'identity.errors.challenge_not_found');
        }

        if ($log->provider_id !== self::ID) {
            return VerificationResult::failure($challengeId, self::ID, 'WRONG_PROVIDER', 'identity.errors.wrong_provider');
        }

        if (in_array($log->status, [
            IdentityVerificationStatus::Verified->value,
            IdentityVerificationStatus::Expired->value,
            IdentityVerificationStatus::Cancelled->value,
        ], true)) {
            return VerificationResult::failure($challengeId, self::ID, 'INVALID_STATE', 'identity.errors.invalid_state');
        }

        if ($log->isExpired()) {
            $this->logRepository->updateById($log->id, [
                'status' => IdentityVerificationStatus::Expired->value,
            ]);

            return VerificationResult::failure($challengeId, self::ID, 'EXPIRED', 'identity.errors.expired');
        }

        if ($log->attempts >= $log->max_attempts) {
            return VerificationResult::failure($challengeId, self::ID, 'MAX_ATTEMPTS', 'identity.errors.max_attempts');
        }

        $storedHash = $log->metadata['code_hash'] ?? $log->metadata['link_token_hash'] ?? null;
        $provided = (string) ($input['code'] ?? $input['token'] ?? '');

        $this->logRepository->updateById($log->id, [
            'attempts' => $log->attempts + 1,
        ]);

        if ($storedHash === null || ! Hash::check($provided, $storedHash)) {
            if (($log->attempts + 1) >= $log->max_attempts) {
                $this->logRepository->updateById($log->id, [
                    'status' => IdentityVerificationStatus::Failed->value,
                ]);
            }

            return VerificationResult::failure($challengeId, self::ID, 'INVALID_CODE', 'identity.errors.invalid_code');
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
            providerId: self::ID,
            verifiedAt: $verifiedAt,
            identityHash: null, // 메일 프로바이더는 PII 정규화 식별자 없음 (KCP/이니시스에서만 반환)
            claims: ['verification_token' => $verificationToken],
        );
    }

    /**
     * 챌린지를 취소 상태로 전환합니다.
     *
     * @param  string  $challengeId  챌린지 ID
     * @return bool 성공 여부
     */
    public function cancel(string $challengeId): bool
    {
        return $this->logRepository->updateById($challengeId, [
            'status' => IdentityVerificationStatus::Cancelled->value,
        ]);
    }

    /**
     * 프로바이더 설정 스키마를 반환합니다.
     *
     * @return array 설정 필드 정의 배열
     */
    public function getSettingsSchema(): array
    {
        return [
            'code_length' => [
                'label' => __('identity.providers.mail.settings.code_length'),
                'type' => 'integer',
                'default' => 6,
                'help' => __('identity.providers.mail.settings.code_length_help'),
            ],
            'from_address' => [
                'label' => __('identity.providers.mail.settings.from_address'),
                'type' => 'string',
                'default' => null,
                'help' => __('identity.providers.mail.settings.from_address_help'),
            ],
        ];
    }

    /**
     * 런타임 설정을 병합한 프로바이더 인스턴스를 반환합니다.
     *
     * @param  array  $config  병합할 설정 배열
     * @return static 설정이 병합된 새 인스턴스
     */
    public function withConfig(array $config): static
    {
        $clone = clone $this;
        $clone->config = array_merge($this->config, $config);

        return $clone;
    }

    protected function resolveRenderHint(string $purpose): string
    {
        return $purpose === 'password_reset' ? 'link' : 'text_code';
    }

    protected function generateNumericCode(int $length): string
    {
        $length = max(4, min(10, $length));
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= (string) random_int(0, 9);
        }

        return $code;
    }

    protected function generateVerificationToken(string $purpose, string $targetHash): string
    {
        return hash_hmac(
            'sha256',
            $purpose.'|'.$targetHash.'|'.Str::uuid()->toString(),
            (string) config('app.key', 'fallback-secret')
        );
    }

    /**
     * IDV 전용 메시지 디스패처를 통해 메일을 발송합니다.
     *
     * @param  string  $email
     * @param  string  $purpose
     * @param  string  $renderHint  text_code | link
     * @param  string  $challengeId
     * @param  string|null  $policyKey
     * @param  string|null  $code  text_code 흐름 시 평문 인증 코드
     * @param  string|null  $linkToken  link 흐름 시 서명 링크용 raw 토큰
     * @param  int  $ttlMinutes
     * @param  Carbon  $expiresAt
     * @return bool 발송 성공 여부
     */
    protected function dispatchMessage(
        string $email,
        string $purpose,
        string $renderHint,
        string $challengeId,
        ?string $policyKey,
        ?string $code,
        ?string $linkToken,
        int $ttlMinutes,
        Carbon $expiresAt,
    ): bool {
        try {
            $actionUrl = $linkToken !== null
                ? $this->buildSignedLink($challengeId, $linkToken, $expiresAt)
                : null;

            return app(IdentityMessageDispatcher::class)->dispatch(
                providerId: self::ID,
                purpose: $purpose,
                policyKey: $policyKey,
                renderHint: $renderHint,
                channel: 'mail',
                target: $email,
                data: [
                    'code' => $code,
                    'action_url' => $actionUrl,
                    'expire_minutes' => $ttlMinutes,
                    'purpose_label' => $this->resolvePurposeLabel($purpose),
                    'app_name' => (string) config('app.name'),
                    'site_url' => (string) config('app.url'),
                    'recipient_email' => $email,
                ],
                context: [
                    'challenge_id' => $challengeId,
                    'render_hint' => $renderHint,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('[IDV] Mail dispatch failed', [
                'email' => $email,
                'purpose' => $purpose,
                'policy_key' => $policyKey,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * link 흐름용 서명 링크를 생성합니다.
     *
     * @param  string  $challengeId
     * @param  string  $linkToken
     * @param  Carbon  $expiresAt
     * @return string
     */
    protected function buildSignedLink(string $challengeId, string $linkToken, Carbon $expiresAt): string
    {
        try {
            return URL::temporarySignedRoute(
                'api.identity.challenges.verify',
                $expiresAt,
                ['challenge_id' => $challengeId, 'token' => $linkToken],
            );
        } catch (\Throwable) {
            // 라우트 미존재 환경(테스트 등)에서는 경로 + query 로 fallback
            return rtrim((string) config('app.url'), '/').'/identity/verify?challenge='.$challengeId.'&token='.$linkToken;
        }
    }

    /**
     * purpose 라벨(다국어)을 현재 로케일 문자열로 해석합니다.
     *
     * @param  string  $purpose
     * @return string
     */
    protected function resolvePurposeLabel(string $purpose): string
    {
        $key = 'identity.purposes.'.$purpose.'.label';
        $translated = __($key);

        return is_string($translated) && $translated !== $key ? $translated : $purpose;
    }
}
