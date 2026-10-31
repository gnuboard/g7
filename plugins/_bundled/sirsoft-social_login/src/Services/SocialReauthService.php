<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use App\Contracts\Extension\CacheInterface;
use App\Enums\IdentityVerificationPurpose;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\User;
use App\Services\IdentityVerificationService;
use App\Support\SiteUrl;
use App\Support\UndeliverableEmail;
use Illuminate\Support\Str;
use Plugins\Sirsoft\SocialLogin\DTO\AuthorizationRequest;
use Plugins\Sirsoft\SocialLogin\DTO\NormalizedProfile;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptOutcome;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Exceptions\SocialFlowException;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\SocialAccountRepositoryInterface;

/**
 * 대체 이메일 회원의 본인 재확인 — 연결된 제공자로 다시 로그인해 확인합니다.
 *
 * 이메일을 받지 않고 가입한 비밀번호 없는 회원(`…@social.invalid`)은 인증번호 메일도, 비밀번호도 쓸 수 없다. 이 회원이
 * 마이페이지 [정보 수정] 진입·비밀번호 첫 설정 같은 민감 작업 앞에서 본인 확인을 요구받으면 연결된 제공자로 왕복해
 * 같은 제공자 계정임을 확인한다. 확인 결과는 코어 본인인증 이력(목적 `sensitive_action`)으로 남아, 코어 관문(정보 수정
 * 진입의 `verification_token`, 비밀번호 변경 정책의 유예 시간)이 메일 인증번호와 똑같이 받아들인다 — 코어 수정 없이
 * 본인인증 수단(`SocialReauthIdentityProvider`)으로 붙는다.
 *
 * 왕복이 끝나면 확인 토큰을 주소에 싣지 않고, 일회용 수령 코드만 실어 돌려보낸다. 화면이 로그인한 상태로 그 코드를
 * 내고 토큰을 받는다.
 *
 * @since 1.0.0
 */
class SocialReauthService
{
    /** 코어 본인인증 수단 ID */
    public const PROVIDER_ID = 'sirsoft-social_login';

    /** 왕복 증명 보관 시간(초) — 콜백이 바로 확인하므로 짧다 */
    private const PROOF_TTL = 120;

    /** 수령 코드 보관 시간(초) */
    private const CLAIM_TTL = 300;

    /** 재확인 요청 맥락의 출처 식별자 — 플러그인 화면의 제공자 버튼이 시작한 요청 */
    public const ORIGIN_IDENTIFIER = 'sirsoft-social_login.reauth';

    /** 「본인 확인 계속하기」 시작 주소 보관 시간(초) — 본인인증 요청 유효 시간과 같다 */
    private const CONTINUE_TTL = 600;

    /** 「본인 확인 계속하기」 시작 주소 경로(같은 출처, 뒤에 1회용 티켓) */
    public const CONTINUE_PATH = '/plugins/sirsoft-social_login/reauth/';

    /**
     * @param  SocialAccountRepositoryInterface  $accounts  연결 저장소
     * @param  ProviderSettings  $settings  제공자 정책
     * @param  OAuthFlowService  $flow  왕복 시작
     * @param  IdentityVerificationService  $identity  코어 본인인증
     * @param  CacheInterface  $cache  플러그인 캐시
     * @param  AttemptRecorder  $attempts  시도 기록
     */
    public function __construct(
        private readonly SocialAccountRepositoryInterface $accounts,
        private readonly ProviderSettings $settings,
        private readonly OAuthFlowService $flow,
        private readonly IdentityVerificationService $identity,
        private readonly CacheInterface $cache,
        private readonly AttemptRecorder $attempts,
    ) {}

    /**
     * 이 회원이 소셜 재확인 대상인지 판정합니다 — 비밀번호가 없고, 이메일로 인증번호를 받을 수 없고, 연결이 있는 회원.
     *
     * 판정은 조회만 한다(로그인·본인인증 판정 경로에서 불린다).
     *
     * @param  User  $user  회원
     * @return bool 대상이면 true
     */
    public function isEligible(User $user): bool
    {
        return ! $user->hasPassword()
            && UndeliverableEmail::is($user->email)
            && $this->accounts->countForUser((int) $user->getKey()) > 0;
    }

    /**
     * 이 회원의 민감 작업 재확인이 실제로 소셜 재확인으로 가는지 판정합니다 — 화면의 소셜 안내·제공자 버튼 조건(`reauth_via_social`).
     *
     * 대상(`isEligible`)이어도 코어가 다른 수단을 먼저 고르면(회사 계정과 소셜을 둘 다 연결한 회원은 회사 계정 다시 로그인) 「본인 확인
     * 계속하기」·428 재확인이 그 수단으로 가므로, 소셜 안내를 보이면 실제 수단과 어긋난다. 코어 재확인 시작과 같은 해석으로 판정한다.
     * 제공자 버튼으로 직접 시작하는 왕복(`start`)은 대상이면 그대로 받는다.
     *
     * @param  User  $user  회원
     * @return bool 소셜 재확인으로 가면 true
     */
    public function isChosenReauth(User $user): bool
    {
        if (! $this->isEligible($user)) {
            return false;
        }

        return app(IdentityVerificationManager::class)
            ->resolveUsableForUser(IdentityVerificationPurpose::SensitiveAction->value, $user)
            ?->getId() === self::PROVIDER_ID;
    }

    /**
     * 재확인 왕복을 시작합니다.
     *
     * @param  User  $user  로그인 회원
     * @param  string  $providerKey  다시 로그인할 제공자 (회원에 연결된 것)
     * @param  string|null  $returnPath  확인 뒤 돌아갈 같은 출처 경로
     * @return string 제공자 인가 주소
     *
     * @throws SocialFlowException 대상 아님(`reauth_not_available`, 403), 연결 없음(`not_linked`, 404), 제공자 꺼짐
     */
    public function start(User $user, string $providerKey, ?string $returnPath): string
    {
        if (! $this->isEligible($user)) {
            throw new SocialFlowException('reauth_not_available', 403);
        }

        if ($this->accounts->findForUserAndProvider((int) $user->getKey(), $providerKey) === null) {
            throw new SocialFlowException('not_linked', 404);
        }

        if (! $this->settings->isEnabled($providerKey)) {
            throw new SocialFlowException('provider_disabled', 404);
        }

        $challenge = $this->identity->start(IdentityVerificationPurpose::SensitiveAction->value, $user, [
            'origin_type' => 'custom',
            'origin_identifier' => self::ORIGIN_IDENTIFIER,
            'ip_address' => request()->ip(),
            'user_agent' => (string) request()->userAgent(),
        ], self::PROVIDER_ID);

        if ($challenge->providerId !== self::PROVIDER_ID) {
            // 수단 등록이 빠져 다른 수단(메일)으로 넘어간 경우 — 받을 수 없는 메일을 보내지 않게 여기서 끊는다.
            $this->identity->cancel($challenge->id);

            throw new SocialFlowException('reauth_not_available', 403);
        }

        try {
            return $this->flow->start($providerKey, AttemptIntent::Reauth, StateStore::safeRedirect($returnPath), (int) $user->getKey(), $challenge->id);
        } catch (ProviderException $e) {
            $this->identity->cancel($challenge->id);

            throw new SocialFlowException($e->errorCode, 422);
        }
    }

    /**
     * 코어 재확인 요청(화면의 「본인 확인 계속하기」 — 수단을 비운 요청)에 실을 같은 출처 시작 주소를 만듭니다.
     *
     * 코어가 이 회원의 민감 작업 재확인 수단으로 소셜 재확인을 고르면, 화면은 응답의 `redirect_url`(같은 출처 경로만 따른다)로
     * 이동한다. 그 주소는 1회용 티켓이고, 열면 회원이 연결한 제공자 중 연결 순서 첫째(마이페이지·재확인 버튼 목록과 같은 순서)이면서
     * 켜진 제공자의 인가 화면으로 보낸다. 왕복은 제공자 버튼이 시작한 것과 같다(같은 본인인증 요청, 같은 콜백 대조·수령 코드).
     * 시작할 수 있는 제공자가 없거나 제공자가 시작을 거절하면 null — 화면은 「이동할 수 없습니다」 안내를 보이고 제공자 버튼은 그대로다.
     *
     * @param  User  $user  재확인 대상 회원 (대상 판정은 호출자가 마쳤다)
     * @param  string  $challengeId  코어 본인인증 요청 ID
     * @param  string|null  $returnPath  확인 뒤 돌아갈 같은 출처 경로
     * @return string|null 시작 주소 (같은 출처 경로), 없으면 null
     */
    public function prepareContinue(User $user, string $challengeId, ?string $returnPath): ?string
    {
        $providerKey = null;
        foreach ($this->accounts->findForUser((int) $user->getKey()) as $account) {
            if ($this->settings->isEnabled((string) $account->provider_key)) {
                $providerKey = (string) $account->provider_key;
                break;
            }
        }

        if ($providerKey === null) {
            return null;
        }

        $redirect = StateStore::safeRedirect($returnPath);

        try {
            $authorizeUrl = $this->flow->start($providerKey, AttemptIntent::Reauth, $redirect, (int) $user->getKey(), $challengeId);
        } catch (ProviderException) {
            return null;
        }

        $ticket = Str::random(40);
        $this->cache->put($this->continueKey($ticket), ['url' => $authorizeUrl, 'redirect' => $redirect], self::CONTINUE_TTL);

        return self::CONTINUE_PATH.$ticket;
    }

    /**
     * 「본인 확인 계속하기」 시작 주소의 티켓을 꺼냅니다 (한 번만).
     *
     * @param  string  $ticket  1회용 티켓
     * @return string|null 제공자 인가 주소, 없거나 이미 썼으면 null
     */
    public function pullContinue(string $ticket): ?string
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $ticket) !== 1) {
            return null;
        }

        $key = $this->continueKey($ticket);
        $payload = $this->cache->get($key);
        $this->cache->forget($key);

        return is_array($payload) && is_string($payload['url'] ?? null) && $payload['url'] !== '' ? $payload['url'] : null;
    }

    /**
     * 시작 주소 티켓이 없거나 이미 쓴 경우 돌아갈 주소를 만듭니다.
     *
     * @return string 절대 주소 (`social_error=reauth_failed`)
     */
    public static function continueFailedUrl(): string
    {
        return self::returnUrl('/mypage/profile/edit', ['social_error' => 'reauth_failed']);
    }

    /**
     * 재확인 왕복의 콜백을 마무리합니다.
     *
     * 돌아온 제공자 계정이 시작한 회원의 그 제공자 연결과 같아야 한다. 맞으면 코어 본인인증 요청을 통과시키고, 화면이
     * 확인 토큰을 받아 갈 일회용 수령 코드를 돌아갈 주소에 싣는다.
     *
     * @param  AuthorizationRequest  $state  시작 정보 (회원 ID·본인인증 요청 ID 포함)
     * @param  NormalizedProfile  $profile  프로필
     * @return string 돌아갈 주소 (성공 `social_reauth={코드}`, 실패 `social_error`)
     */
    public function complete(AuthorizationRequest $state, NormalizedProfile $profile): string
    {
        $account = $this->accounts->findByProviderUid($state->providerKey, $profile->providerUserId);

        if ($state->challengeId === null || $state->userId === null || $account === null || (int) $account->user_id !== $state->userId) {
            if ($state->challengeId !== null) {
                $this->identity->cancel($state->challengeId);
            }
            $this->attempts->record($state->providerKey, AttemptIntent::Reauth->value, AttemptOutcome::Failed, $state->userId, 'reauth_mismatch');

            return self::returnUrl($state->redirect, ['social_error' => 'reauth_mismatch']);
        }

        $this->cache->put($this->proofKey($state->challengeId), ['user_id' => $state->userId], self::PROOF_TTL);

        $result = $this->identity->verify($state->challengeId, [], [
            'origin_type' => 'custom',
            'origin_identifier' => 'sirsoft-social_login.reauth',
        ]);

        $token = $result->claims['verification_token'] ?? null;
        if (! $result->success || ! is_string($token) || $token === '') {
            $this->attempts->record($state->providerKey, AttemptIntent::Reauth->value, AttemptOutcome::Failed, $state->userId, 'reauth_failed');

            return self::returnUrl($state->redirect, ['social_error' => 'reauth_failed']);
        }

        $code = Str::random(40);
        $this->cache->put($this->claimKey($code), ['user_id' => $state->userId, 'token' => $token], self::CLAIM_TTL);

        $this->attempts->record($state->providerKey, AttemptIntent::Reauth->value, AttemptOutcome::Login, $state->userId);

        return self::returnUrl($state->redirect, ['social_reauth' => $code]);
    }

    /**
     * 재확인 왕복이 제공자 단계(동의 취소·코드 교환 실패 등)에서 끝났을 때 본인인증 요청을 거두고 돌아갈 주소를 돌려줍니다.
     *
     * 재확인은 로그인한 회원이 정보 수정·비밀번호 첫 설정 화면에서 시작하므로 그 화면으로 돌려보내야 실패 사유가 보인다.
     * 시도 기록은 왕복 서비스가 이미 남겼다.
     *
     * @param  AuthorizationRequest  $state  시작 정보
     * @param  string  $errorCode  오류 코드
     * @return string 돌아갈 주소 (`social_error`)
     */
    public function abandon(AuthorizationRequest $state, string $errorCode): string
    {
        if ($state->challengeId !== null) {
            $this->identity->cancel($state->challengeId);
        }

        return self::returnUrl($state->redirect, ['social_error' => $errorCode]);
    }

    /**
     * 수령 코드로 확인 토큰을 받습니다 (한 번만, 같은 회원만).
     *
     * @param  User  $user  로그인 회원
     * @param  string  $code  수령 코드
     * @return string 확인 토큰 (`verification_token`)
     *
     * @throws SocialFlowException 없음·만료·다른 회원(`reauth_claim_invalid`, 404)
     */
    public function claim(User $user, string $code): string
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $code) !== 1) {
            throw new SocialFlowException('reauth_claim_invalid', 404);
        }

        $key = $this->claimKey($code);
        $payload = $this->cache->get($key);
        $this->cache->forget($key);

        if (! is_array($payload) || (int) ($payload['user_id'] ?? 0) !== (int) $user->getKey() || ! is_string($payload['token'] ?? null)) {
            throw new SocialFlowException('reauth_claim_invalid', 404);
        }

        return $payload['token'];
    }

    /**
     * 콜백이 남긴 왕복 증명을 꺼냅니다 (본인인증 수단의 확인 단계 — 한 번만).
     *
     * 증명은 콜백이 제공자 계정과 회원 연결을 대조한 뒤에만 생긴다. 코어 본인인증 확인 API 를 직접 불러도 증명이 없으니
     * 통과하지 못한다.
     *
     * @param  string  $challengeId  본인인증 요청 ID
     * @param  int|null  $userId  요청 대상 회원 ID
     * @return bool 증명이 있고 회원이 맞으면 true
     */
    public function consumeProof(string $challengeId, ?int $userId): bool
    {
        $key = $this->proofKey($challengeId);
        $proof = $this->cache->get($key);
        $this->cache->forget($key);

        return is_array($proof) && $userId !== null && (int) ($proof['user_id'] ?? 0) === $userId;
    }

    /**
     * 돌아갈 주소를 만듭니다.
     *
     * @param  string|null  $path  같은 출처 경로
     * @param  array<string, string>  $query  덧붙일 쿼리
     * @return string 절대 주소
     */
    private static function returnUrl(?string $path, array $query): string
    {
        $safe = StateStore::safeRedirect($path);
        $separator = str_contains($safe, '?') ? '&' : '?';

        return SiteUrl::absolute($safe.$separator.http_build_query($query));
    }

    /**
     * 왕복 증명 캐시 키.
     *
     * @param  string  $challengeId  본인인증 요청 ID
     * @return string 캐시 키
     */
    private function proofKey(string $challengeId): string
    {
        return 'reauth_proof:'.hash('sha256', $challengeId);
    }

    /**
     * 「본인 확인 계속하기」 시작 티켓 캐시 키 (원문 대신 해시).
     *
     * @param  string  $ticket  티켓
     * @return string 캐시 키
     */
    private function continueKey(string $ticket): string
    {
        return 'reauth_continue:'.hash('sha256', $ticket);
    }

    /**
     * 수령 코드 캐시 키 (원문 대신 해시).
     *
     * @param  string  $code  수령 코드
     * @return string 캐시 키
     */
    private function claimKey(string $code): string
    {
        return 'reauth_claim:'.hash('sha256', $code);
    }
}
