<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;
use App\Helpers\TimezoneHelper;
use App\Models\User;
use App\Support\SiteUrl;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\SocialLogin\DTO\AuthorizationRequest;
use Plugins\Sirsoft\SocialLogin\DTO\NormalizedProfile;
use Plugins\Sirsoft\SocialLogin\DTO\TokenSet;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptOutcome;
use Plugins\Sirsoft\SocialLogin\Enums\UnlinkReason;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Exceptions\SocialFlowException;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\SocialAccountRepositoryInterface;

/**
 * 회원-제공자 연결의 생성·조회·해제.
 *
 * 연결은 세 경로에서 생긴다 — 마이페이지 [연결](로그인 회원의 왕복), 「이미 가입된 이메일」 본인확인, 검증된 이메일 자동
 * 연결. 셋 다 `attach()` 를 거쳐 같은 검사(다른 회원에 이미 연결됨, 같은 제공자 중복)와 같은 훅을 탄다.
 * 해제는 「비밀번호도 없고 연결이 하나뿐」인 회원의 마지막 수단을 기본으로 막는다.
 *
 * @since 1.0.0
 */
class AccountLinkService
{
    /** 연결 직후 훅 (인자: SocialAccount, User, array{method: string}) */
    public const ACTION_AFTER_LINK = 'sirsoft-social_login.account.after_link';

    /** 해제 직전 훅 (인자: SocialAccount, User, string $reason) */
    public const ACTION_BEFORE_UNLINK = 'sirsoft-social_login.account.before_unlink';

    /** 해제 직후 훅 (인자: SocialAccount, User, string $reason) */
    public const ACTION_AFTER_UNLINK = 'sirsoft-social_login.account.after_unlink';

    /** 관리자 강제 해제 직후 훅 — 회원 알림용 (인자: User $user, string $providerKey) */
    public const ACTION_UNLINKED_BY_ADMIN = 'sirsoft-social_login.account.unlinked_by_admin';

    /** 마이페이지 경로 */
    public const PROFILE_PATH = '/mypage/profile';

    /**
     * @param  SocialAccountRepositoryInterface  $accounts  연결 저장소
     * @param  AccountCredentialWriter  $writer  연결 행 쓰기
     * @param  ProviderSettings  $settings  제공자 정책
     * @param  OAuthFlowService  $flow  왕복 시작
     * @param  AttemptRecorder  $attempts  시도 기록
     * @param  UserRepositoryInterface  $users  회원 저장소
     */
    public function __construct(
        private readonly SocialAccountRepositoryInterface $accounts,
        private readonly AccountCredentialWriter $writer,
        private readonly ProviderSettings $settings,
        private readonly OAuthFlowService $flow,
        private readonly AttemptRecorder $attempts,
        private readonly UserRepositoryInterface $users,
    ) {}

    /**
     * 회원에게 연결을 붙입니다.
     *
     * 같은 제공자 계정이 같은 회원에 이미 있으면 프로필·토큰만 새로 쓴다(다시 연결해도 행이 늘지 않는다).
     *
     * @param  User  $user  회원
     * @param  string  $providerKey  제공자 키
     * @param  NormalizedProfile  $profile  프로필
     * @param  TokenSet|null  $tokens  토큰
     * @param  string  $method  연결 경로 (link·existing·auto·signup) — 훅·활동 로그 구분
     * @param  bool  $loggedIn  이번 왕복이 로그인으로 이어지는지
     * @return SocialAccount 연결
     *
     * @throws SocialFlowException 다른 회원에 연결된 계정(`already_linked_other_user`), 같은 제공자의 다른 계정이
     *                             이미 연결됨(`provider_already_linked`)
     */
    public function attach(User $user, string $providerKey, NormalizedProfile $profile, ?TokenSet $tokens, string $method, bool $loggedIn = false): SocialAccount
    {
        $existing = $this->accounts->findByProviderUid($providerKey, $profile->providerUserId);

        if ($existing !== null) {
            if ((int) $existing->user_id !== (int) $user->getKey()) {
                throw new SocialFlowException('already_linked_other_user', 409);
            }

            $this->writer->refresh($existing, $profile, $tokens, $loggedIn);

            return $existing;
        }

        if ($this->accounts->findForUserAndProvider((int) $user->getKey(), $providerKey) !== null) {
            throw new SocialFlowException('provider_already_linked', 409);
        }

        $account = $this->writer->create($user, $providerKey, $profile, $tokens, $loggedIn);

        HookManager::doAction(self::ACTION_AFTER_LINK, $account, $user, ['method' => $method]);

        return $account;
    }

    /**
     * 마이페이지 [연결] 왕복을 시작합니다.
     *
     * @param  User  $user  로그인 회원
     * @param  string  $providerKey  제공자 키
     * @return string 제공자 인가 주소
     *
     * @throws SocialFlowException 꺼진 제공자(`provider_disabled`), 이미 연결된 제공자(`provider_already_linked`)
     */
    public function startLink(User $user, string $providerKey): string
    {
        if (! $this->settings->isEnabled($providerKey)) {
            throw new SocialFlowException('provider_disabled', 404);
        }

        if ($this->accounts->findForUserAndProvider((int) $user->getKey(), $providerKey) !== null) {
            throw new SocialFlowException('provider_already_linked', 409);
        }

        try {
            return $this->flow->start($providerKey, AttemptIntent::Link, self::PROFILE_PATH, (int) $user->getKey());
        } catch (ProviderException $e) {
            throw new SocialFlowException($e->errorCode, 422);
        }
    }

    /**
     * 마이페이지 [연결] 왕복의 콜백을 마무리합니다.
     *
     * @param  AuthorizationRequest  $state  시작 정보 (회원 ID 포함)
     * @param  NormalizedProfile  $profile  프로필
     * @param  TokenSet|null  $tokens  토큰
     * @return string 마이페이지 주소 (성공 `social=linked`, 실패 `social_error`)
     */
    public function completeLink(AuthorizationRequest $state, NormalizedProfile $profile, ?TokenSet $tokens): string
    {
        $user = $state->userId !== null ? $this->users->findById($state->userId) : null;

        if (! $user instanceof User) {
            $this->attempts->record($state->providerKey, AttemptIntent::Link->value, AttemptOutcome::Failed, $state->userId, 'user_missing');

            return self::profileUrl(['social_error' => 'user_missing']);
        }

        try {
            $this->attach($user, $state->providerKey, $profile, $tokens, 'link');
        } catch (SocialFlowException $e) {
            $this->attempts->record($state->providerKey, AttemptIntent::Link->value, AttemptOutcome::Failed, (int) $user->getKey(), $e->errorCode);

            return self::profileUrl(['social_error' => $e->errorCode, 'provider' => $state->providerKey]);
        }

        $this->attempts->record($state->providerKey, AttemptIntent::Link->value, AttemptOutcome::Link, (int) $user->getKey());

        return self::profileUrl(['social' => 'linked', 'provider' => $state->providerKey]);
    }

    /**
     * 연결 왕복이 제공자 단계(동의 취소·코드 교환 실패 등)에서 끝났을 때 돌아갈 주소를 돌려줍니다.
     *
     * 연결은 로그인한 회원이 마이페이지에서 시작하므로 로그인 화면이 아니라 마이페이지로 돌려보내야 실패 사유가 보인다
     * (로그인 화면은 로그인한 사람을 홈으로 되돌린다). 시도 기록은 왕복 서비스가 이미 남겼다.
     *
     * @param  string  $providerKey  제공자 키
     * @param  string  $errorCode  오류 코드
     * @return string 마이페이지 주소 (`social_error`)
     */
    public function failedLinkUrl(string $providerKey, string $errorCode): string
    {
        return self::profileUrl(['social_error' => $errorCode, 'provider' => $providerKey]);
    }

    /**
     * 회원의 연결 목록과 해제 가능 여부를 돌려줍니다 (마이페이지 카드).
     *
     * @param  User  $user  로그인 회원
     * @return array<string, mixed> 연결·연결 가능 제공자·비밀번호 유무·해제 가능 여부
     */
    public function summaryFor(User $user): array
    {
        $accounts = $this->accounts->findForUser((int) $user->getKey());
        $linkedKeys = $accounts->pluck('provider_key')->all();

        $rows = $accounts->map(fn (SocialAccount $account) => [
            'provider_key' => $account->provider_key,
            'label' => $this->settings->label($account->provider_key),
            'brand' => $this->settings->brand($account->provider_key),
            'email' => $account->email !== null ? OAuthFlowService::maskEmail($account->email) : null, // audit:allow resource-member-email-ungated 본인 화면용 마스킹 값(앞 2자 + 도메인)
            'linked_at' => TimezoneHelper::toUserDateTimeString($account->linked_at),
            'last_login_at' => TimezoneHelper::toUserDateTimeString($account->last_login_at),
        ])->values()->all();

        $available = [];
        foreach ($this->settings->enabledKeys() as $key) {
            if (! in_array($key, $linkedKeys, true)) {
                $available[] = [
                    'provider_key' => $key,
                    'label' => $this->settings->label($key),
                    'brand' => $this->settings->brand($key),
                    'style' => $this->settings->buttonStyle($key),
                ];
            }
        }

        $canUnlink = [];
        foreach ($linkedKeys as $key) {
            $reason = $this->unlinkBlockReason($user, count($linkedKeys));
            $canUnlink[$key] = ['allowed' => $reason === null, 'reason' => $reason];
        }

        return [
            'accounts' => $rows,
            'available' => $available,
            'has_password' => $user->hasPassword(),
            'can_unlink' => (object) $canUnlink,
        ];
    }

    /**
     * UUID 로 회원을 찾습니다 (관리자 회원 상세).
     *
     * @param  string  $uuid  회원 UUID
     * @return User|null 회원
     */
    public function findUserByUuid(string $uuid): ?User
    {
        return $this->users->findByUuid($uuid);
    }

    /**
     * 관리자 회원 상세 「소셜 로그인」 탭용 연결 목록을 돌려줍니다.
     *
     * 제공자 사용자 ID 는 앞 6자만 보인다(제공자 콘솔에서 회원을 찾는 단서로 충분하고, 전체 값은 다른 사이트와 대조하는
     * 식별자가 될 수 있다).
     *
     * @param  User  $user  대상 회원
     * @return array<string, mixed> 연결·비밀번호 유무
     */
    public function adminSummaryFor(User $user): array
    {
        $rows = $this->accounts->findForUser((int) $user->getKey())->map(fn (SocialAccount $account) => [
            'provider_key' => $account->provider_key,
            'label' => $this->settings->label($account->provider_key),
            'brand' => $this->settings->brand($account->provider_key),
            'provider_user_id_prefix' => mb_substr((string) $account->provider_user_id, 0, 6),
            'email' => $account->email, // audit:allow resource-member-email-ungated 회원 연결 열람 권한(accounts.view) 라우트만 부른다
            'email_verified' => $account->email_verified,
            'linked_at' => TimezoneHelper::toUserDateTimeString($account->linked_at),
            'last_login_at' => TimezoneHelper::toUserDateTimeString($account->last_login_at),
        ])->values()->all();

        return [
            'accounts' => $rows,
            'has_password' => $user->hasPassword(),
        ];
    }

    /**
     * 관리자 회원 목록 가입경로 필터의 선택지 — 연결이 하나라도 있는 제공자(꺼진 제공자 포함)와 연결 수.
     *
     * 켜진 제공자 목록으로 고르게 하면 운영자가 제공자를 끈 뒤 그 제공자로 가입한 회원을 찾을 수 없다.
     *
     * @return array<int, array{key: string, label: string, count: int}> 선택지
     */
    public function linkedProviderOptions(): array
    {
        $options = [];
        foreach ($this->accounts->countsByProvider() as $key => $count) {
            $options[] = ['key' => (string) $key, 'label' => $this->settings->label((string) $key), 'count' => (int) $count];
        }

        return $options;
    }

    /**
     * 관리자가 회원의 연결을 강제로 끊습니다.
     *
     * 마지막 로그인 수단 가드는 보지 않는다(관리자 판단). 제공자 권한 회수는 사이트 설정을 따르고, 회원에게 알림 훅을 쏜다.
     *
     * @param  User  $user  대상 회원
     * @param  string  $providerKey  제공자 키
     * @return void 반환값 없음
     *
     * @throws SocialFlowException 연결 없음(`not_linked`, 404)
     */
    public function adminUnlink(User $user, string $providerKey): void
    {
        $account = $this->accounts->findForUserAndProvider((int) $user->getKey(), $providerKey);
        if ($account === null) {
            throw new SocialFlowException('not_linked', 404);
        }

        $this->remove($account, $user, UnlinkReason::Admin, (bool) plugin_setting('sirsoft-social_login', 'revoke_on_unlink', true));

        HookManager::doAction(self::ACTION_UNLINKED_BY_ADMIN, $user, $providerKey);
    }

    /**
     * 회원이 자기 연결을 끊습니다.
     *
     * 제공자 쪽 권한 회수는 설정(`revoke_on_unlink`, 기본 켜짐)일 때 시도하고, 실패해도 이 사이트의 연결은 끊는다
     * (실패는 시도 기록에 남긴다 — 제공자 화면에서 직접 끊으면 된다).
     *
     * @param  User  $user  로그인 회원
     * @param  string  $providerKey  제공자 키
     * @return void 반환값 없음
     *
     * @throws SocialFlowException 연결 없음(`not_linked`, 404), 마지막 수단(`last_method`, 409)
     */
    public function unlink(User $user, string $providerKey): void
    {
        $account = $this->accounts->findForUserAndProvider((int) $user->getKey(), $providerKey);
        if ($account === null) {
            throw new SocialFlowException('not_linked', 404);
        }

        $reason = $this->unlinkBlockReason($user, $this->accounts->countForUser((int) $user->getKey()));
        if ($reason !== null) {
            throw new SocialFlowException($reason, 409);
        }

        $this->remove($account, $user, UnlinkReason::User, (bool) plugin_setting('sirsoft-social_login', 'revoke_on_unlink', true));
    }

    /**
     * 연결 1건을 지웁니다 — 훅·권한 회수 포함 (회원 해제·탈퇴·삭제·관리자 해제 공용).
     *
     * @param  SocialAccount  $account  연결
     * @param  User  $user  회원
     * @param  UnlinkReason  $reason  해제 사유
     * @param  bool  $revoke  제공자 권한 회수 시도 여부
     * @return void 반환값 없음
     */
    public function remove(SocialAccount $account, User $user, UnlinkReason $reason, bool $revoke): void
    {
        HookManager::doAction(self::ACTION_BEFORE_UNLINK, $account, $user, $reason->value);

        if ($revoke) {
            $this->tryRevoke($account, $user);
        }

        $this->accounts->delete($account);

        HookManager::doAction(self::ACTION_AFTER_UNLINK, $account, $user, $reason->value);
    }

    /**
     * 회원의 연결을 모두 지웁니다 (탈퇴·삭제 — 마지막 수단 가드는 보지 않는다).
     *
     * @param  User  $user  회원
     * @param  UnlinkReason  $reason  해제 사유
     * @return int 지운 연결 수
     */
    public function removeAllFor(User $user, UnlinkReason $reason): int
    {
        $revoke = (bool) plugin_setting('sirsoft-social_login', 'revoke_on_unlink', true);
        $accounts = $this->accounts->findForUser((int) $user->getKey());

        foreach ($accounts as $account) {
            $this->remove($account, $user, $reason, $revoke);
        }

        return $accounts->count();
    }

    /**
     * 해제를 막는 사유를 돌려줍니다.
     *
     * 비밀번호가 없고 연결이 하나뿐이면, 그 연결을 끊는 순간 로그인할 수단이 사라진다 — 설정
     * `allow_unlink_last_method` 가 켜져 있지 않으면 막는다.
     *
     * @param  User  $user  회원
     * @param  int  $linkedCount  연결 수
     * @return string|null 막는 사유 코드 (해제 가능하면 null)
     */
    private function unlinkBlockReason(User $user, int $linkedCount): ?string
    {
        if ($user->hasPassword() || $linkedCount > 1) {
            return null;
        }

        return (bool) plugin_setting('sirsoft-social_login', 'allow_unlink_last_method', false) ? null : 'last_method';
    }

    /**
     * 제공자 쪽 권한 회수를 시도합니다.
     *
     * @param  SocialAccount  $account  연결
     * @param  User  $user  회원
     * @return void 반환값 없음
     */
    private function tryRevoke(SocialAccount $account, User $user): void
    {
        $driver = $this->settings->driver($account->provider_key);
        if ($driver === null || ! (bool) ($driver->supports()['revoke'] ?? false)) {
            return;
        }

        try {
            $ok = $driver->revoke(new TokenSet((string) $account->access_token, $account->refresh_token), $account);
            if (! $ok) {
                $this->attempts->record($account->provider_key, AttemptIntent::Link->value, AttemptOutcome::Failed, (int) $user->getKey(), 'revoke_failed');
            }
        } catch (\Throwable $e) {
            // 권한 회수 실패는 연결 해제를 막지 않는다 — 원인은 로그에, 시도 기록에는 코드만.
            Log::warning('[sirsoft-social_login] 제공자 권한 회수 실패', ['provider' => $account->provider_key, 'error' => class_basename($e)]);
            $this->attempts->record(
                $account->provider_key,
                AttemptIntent::Link->value,
                AttemptOutcome::Failed,
                (int) $user->getKey(),
                $e instanceof ProviderException ? $e->errorCode : 'revoke_failed',
            );
        }
    }

    /**
     * 마이페이지 주소를 만듭니다.
     *
     * @param  array<string, string>  $query  쿼리
     * @return string 절대 주소
     */
    private static function profileUrl(array $query): string
    {
        return SiteUrl::absolute(self::PROFILE_PATH.'?'.http_build_query($query));
    }
}
