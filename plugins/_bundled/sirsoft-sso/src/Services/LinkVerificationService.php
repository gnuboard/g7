<?php

namespace Plugins\Sirsoft\Sso\Services;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\IdentityVerificationPurpose;
use App\Enums\IdentityVerificationStatus;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\IdentityVerificationService;
use App\Support\UndeliverableEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\DTO\ResolveResult;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Repositories\Contracts\IdentityProviderRepositoryInterface;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * 동의 대기와 연결 확인 대기의 완료 — 로그인 화면 모달이 부르는 API 의 서비스.
 *
 * 연결 확인(D6 ②): IdP 신원과 같은 이메일의 기존 회원이 있을 때, 그 회원이 비밀번호(비밀번호가 있는 회원) 또는 메일 인증번호로
 * 직접 증명해야 연결한다. 비밀번호를 3번 틀리면 그 대기를 폐기하고(처음부터 다시) 틀린 시도는 G7 로그인 실패 횟수에도 넣는다 —
 * 연결 확인 화면이 비밀번호 무차별 대입의 우회로가 되지 않게.
 *
 * 완료 응답은 인계 주소가 아니라 인계 코드(`handoff_code`)와 묶음 쿠키다 — 모달이 엔진 `loginHandoff` 액션을 그 코드로 직접 실행한다.
 *
 * @since 1.0.0
 */
class LinkVerificationService
{
    /** 연결 확인 비밀번호 허용 실패 횟수 */
    public const MAX_PASSWORD_FAILURES = 3;

    /** 연결 확인 메일 인증 출처 식별자 */
    private const ORIGIN = 'sirsoft-sso.link_verify';

    /**
     * @param  PendingStore  $pending  대기 저장소
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     * @param  UserRepositoryInterface  $users  회원 저장소
     * @param  AccountResolver  $resolver  회원 결정
     * @param  LoginFlowService  $flow  인계 발급
     * @param  IdentityVerificationService  $identity  코어 본인인증
     */
    public function __construct(
        private readonly PendingStore $pending,
        private readonly IdentityProviderRepositoryInterface $idps,
        private readonly UserRepositoryInterface $users,
        private readonly AccountResolver $resolver,
        private readonly LoginFlowService $flow,
        private readonly IdentityVerificationService $identity,
    ) {}

    /**
     * 동의 대기 화면 정보를 돌려줍니다.
     *
     * @param  string  $token  대기 토큰
     * @param  Request  $request  요청
     * @return array{idp: array{key: string, label: string}, guard: string, name: string|null, email: string|null} 화면 정보
     *
     * @throws SsoException 만료, 다른 브라우저
     */
    public function consentInfo(string $token, Request $request): array
    {
        [$data, $idp, $identity] = $this->load($token, PendingStore::TYPE_CONSENT, $request);
        $email = AccountResolver::registrationEmail($idp, $identity);

        return [
            'idp' => ['key' => $idp->key, 'label' => $idp->localizedLabel()],
            'guard' => (string) $data['guard'],
            'name' => $identity->displayName,
            'email' => UndeliverableEmail::is($email) ? null : LoginFlowService::maskEmail($email), // audit:allow resource-member-email-ungated 가린 값만 — 본인 IdP 신원의 가입 주소(묶음 쿠키 확인 뒤)
        ];
    }

    /**
     * 동의를 받고 회원을 만들어 인계 코드를 발급합니다.
     *
     * @param  string  $token  대기 토큰
     * @param  Request  $request  요청
     * @return object{code: string, cookie: Cookie|null, guard: string, redirect: string} 인계 코드
     *
     * @throws SsoException 만료, 다른 브라우저, 생성 거절
     */
    public function completeConsent(string $token, Request $request): object
    {
        [$data, $idp, $identity] = $this->load($token, PendingStore::TYPE_CONSENT, $request);
        $this->pending->forget($token);

        $result = $this->resolver->completeConsent($idp, $identity);

        return $this->handoff($idp, $identity, $result, $data);
    }

    /**
     * 연결 확인 화면 정보를 돌려줍니다 — 기존 회원 이메일은 가린다.
     *
     * @param  string  $token  대기 토큰
     * @param  Request  $request  요청
     * @return array{idp: array{key: string, label: string}, guard: string, email: string, methods: array<int, string>} 화면 정보
     *
     * @throws SsoException 만료, 다른 브라우저
     */
    public function linkInfo(string $token, Request $request): array
    {
        [$data, $idp, , $user] = $this->loadLink($token, $request);

        return [
            'idp' => ['key' => $idp->key, 'label' => $idp->localizedLabel()],
            'guard' => (string) $data['guard'],
            'email' => LoginFlowService::maskEmail((string) $user->email), // audit:allow resource-member-email-ungated 가린 값만(h***@corp.com) — 연결 확인 모달 안내
            'methods' => $this->methods($user),
        ];
    }

    /**
     * 비밀번호로 연결을 확인합니다.
     *
     * @param  string  $token  대기 토큰
     * @param  string  $password  기존 회원 비밀번호
     * @param  Request  $request  요청
     * @return object{code: string, cookie: Cookie|null, guard: string, redirect: string} 인계 코드
     *
     * @throws SsoException `password_mismatch`, 3회 초과면 `link_expired`
     */
    public function verifyPassword(string $token, string $password, Request $request): object
    {
        [$data, $idp, $identity, $user] = $this->loadLink($token, $request);

        if (! $user->hasPassword()) {
            throw new SsoException('password_not_available');
        }

        if ($password === '' || ! Hash::check($password, (string) $user->getAuthPassword())) {
            // 비밀번호 오입력과 같게 G7 로그인 실패 횟수에 넣는다(잠금 계산).
            HookManager::doAction('core.auth.login_failed', (string) $user->email, [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'attempted_at' => now(),
                'source' => 'sirsoft-sso:link_verify',
            ]);

            $data['failures'] = (int) ($data['failures'] ?? 0) + 1;
            if ($data['failures'] >= self::MAX_PASSWORD_FAILURES) {
                $this->pending->forget($token);

                throw new SsoException('link_expired', 'too many password failures', 404);
            }

            $this->pending->put($token, $data);

            throw new SsoException('password_mismatch');
        }

        return $this->link($token, $data, $idp, $identity, $user);
    }

    /**
     * 기존 회원 메일로 인증번호를 보냅니다.
     *
     * @param  string  $token  대기 토큰
     * @param  Request  $request  요청
     * @return array{challenge_id: string, expires_at: string} 인증 요청
     *
     * @throws SsoException 받을 메일이 없으면 `email_not_available`, 발송 실패면 `mail_failed`
     */
    public function requestEmailCode(string $token, Request $request): array
    {
        [$data, , , $user] = $this->loadLink($token, $request);

        if (UndeliverableEmail::is((string) $user->email)) {
            throw new SsoException('email_not_available');
        }

        $challenge = $this->identity->start(IdentityVerificationPurpose::SensitiveAction->value, $user, [
            'origin_type' => 'custom',
            'origin_identifier' => self::ORIGIN,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ], 'g7:core.mail');

        if (($this->identity->getStatus($challenge->id)['status'] ?? null) === IdentityVerificationStatus::Failed->value) {
            Log::error('[sirsoft-sso] 연결 확인 인증번호 메일 발송 실패', ['challenge_id' => $challenge->id, 'user_id' => $user->getKey()]);

            throw new SsoException('mail_failed', 'mail failed', 503);
        }

        $data['challenge_id'] = $challenge->id;
        $this->pending->put($token, $data);

        return ['challenge_id' => $challenge->id, 'expires_at' => $challenge->expiresAt->toIso8601String()];
    }

    /**
     * 메일 인증번호로 연결을 확인합니다.
     *
     * @param  string  $token  대기 토큰
     * @param  string  $challengeId  인증 요청 ID
     * @param  string  $code  인증번호
     * @param  Request  $request  요청
     * @return object{code: string, cookie: Cookie|null, guard: string, redirect: string} 인계 코드
     *
     * @throws SsoException `challenge_mismatch`, `invalid_code`
     */
    public function verifyEmailCode(string $token, string $challengeId, string $code, Request $request): object
    {
        [$data, $idp, $identity, $user] = $this->loadLink($token, $request);

        if (! hash_equals((string) ($data['challenge_id'] ?? ''), $challengeId)) {
            throw new SsoException('challenge_mismatch');
        }

        $result = $this->identity->verify($challengeId, ['code' => $code], [
            'origin_type' => 'custom',
            'origin_identifier' => self::ORIGIN,
        ]);

        $verified = $result->success
            ? $this->identity->resolveVerifiedUser($challengeId, IdentityVerificationPurpose::SensitiveAction->value)
            : null;

        if (! $verified instanceof User || (int) $verified->getKey() !== (int) $user->getKey()) {
            throw new SsoException('invalid_code');
        }

        // 연결 확인에 쓴 인증은 여기서 끝낸다 — 같은 확인으로 다른 민감 작업을 하지 못하게.
        $verificationToken = $result->claims['verification_token'] ?? null;
        if (is_string($verificationToken) && $verificationToken !== '') {
            $this->identity->consumeToken($verificationToken);
        }

        return $this->link($token, $data, $idp, $identity, $user);
    }

    /**
     * 연결하고 인계 코드를 발급합니다.
     *
     * @param  string  $token  대기 토큰
     * @param  array<string, mixed>  $data  대기 값
     * @param  IdentityProvider  $idp  IdP
     * @param  ExternalIdentity  $identity  신원
     * @param  User  $user  확인된 회원
     * @return object{code: string, cookie: Cookie|null, guard: string, redirect: string} 인계 코드
     */
    private function link(string $token, array $data, IdentityProvider $idp, ExternalIdentity $identity, User $user): object
    {
        $this->pending->forget($token);
        $result = $this->resolver->completeVerifiedLink($idp, $identity, $user);

        return $this->handoff($idp, $identity, $result, $data);
    }

    /**
     * 결정 결과로 api 응답용 인계 코드를 발급합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  ExternalIdentity  $identity  신원
     * @param  ResolveResult  $result  결정 결과
     * @param  array<string, mixed>  $data  대기 값
     * @return object{code: string, cookie: Cookie|null, guard: string, redirect: string} 인계 코드
     *
     * @throws SsoException 거절 결과
     */
    private function handoff(IdentityProvider $idp, ExternalIdentity $identity, ResolveResult $result, array $data): object
    {
        if (! $result->logsIn() || $result->user === null || $result->account === null) {
            throw new SsoException((string) ($result->errorCode ?? 'not_registered'));
        }

        // 콜백 요청이 대기 값에 실어 둔 refresh_token 을 드라이버에 되돌린다 — 회원 결정 훅 구독자(주기 점검 보관)가 그대로 꺼낸다.
        $driver = $idp->driver();
        if (method_exists($driver, 'holdRefreshToken')) {
            $driver->holdRefreshToken(is_string($data['refresh_token'] ?? null) ? $data['refresh_token'] : null);
        }

        $guard = (string) ($data['guard'] ?? 'user');
        $issued = $this->flow->issueHandoff($result->user, $result->account, $identity, $idp, $guard, $result->outcome, forApi: true);
        $issued->guard = $guard;
        $issued->redirect = SafeRedirect::path((string) ($data['redirect'] ?? '/'));

        return $issued;
    }

    /**
     * 대기 값과 IdP, 신원을 꺼냅니다.
     *
     * @param  string  $token  대기 토큰
     * @param  string  $type  종류
     * @param  Request  $request  요청
     * @return array{0: array<string, mixed>, 1: IdentityProvider, 2: ExternalIdentity} 대기 값, IdP, 신원
     *
     * @throws SsoException 만료, 다른 브라우저, IdP 꺼짐
     */
    private function load(string $token, string $type, Request $request): array
    {
        $data = $this->pending->get($token, $type, $request);
        $idp = $this->idps->findById((int) ($data['idp_id'] ?? 0));
        if ($idp === null || ! $idp->enabled) {
            $this->pending->forget($token);

            throw new SsoException('idp_disabled', 'pending idp disabled', 404);
        }

        return [$data, $idp, ExternalIdentity::fromArray((array) ($data['identity'] ?? []))];
    }

    /**
     * 연결 확인 대기를 꺼냅니다 — 대상 회원이 사라졌거나 관리자가 됐으면 폐기.
     *
     * @param  string  $token  대기 토큰
     * @param  Request  $request  요청
     * @return array{0: array<string, mixed>, 1: IdentityProvider, 2: ExternalIdentity, 3: User} 대기 값, IdP, 신원, 회원
     *
     * @throws SsoException 만료, 대상 없음, 관리자 대상
     */
    private function loadLink(string $token, Request $request): array
    {
        [$data, $idp, $identity] = $this->load($token, PendingStore::TYPE_LINK, $request);

        $user = $this->users->findById((int) ($data['user_id'] ?? 0));
        if (! $user instanceof User || $user->isWithdrawn()) {
            $this->pending->forget($token);

            throw new SsoException('link_expired', 'link target missing', 404);
        }

        if (AccountResolver::isPrivileged($user)) {
            $this->pending->forget($token);

            throw new SsoException('admin_target_manual_only', 'link target is admin', 403);
        }

        return [$data, $idp, $identity, $user];
    }

    /**
     * 회원이 쓸 수 있는 확인 방법.
     *
     * @param  User  $user  회원
     * @return array<int, string> password, email
     */
    private function methods(User $user): array
    {
        $methods = [];
        if ($user->hasPassword()) {
            $methods[] = 'password';
        }
        if (! UndeliverableEmail::is((string) $user->email)) {
            $methods[] = 'email';
        }

        return $methods;
    }
}
