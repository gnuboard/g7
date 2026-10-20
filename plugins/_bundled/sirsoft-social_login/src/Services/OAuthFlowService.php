<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;
use App\Support\SiteUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Plugins\Sirsoft\SocialLogin\Contracts\ProviderDriverInterface;
use Plugins\Sirsoft\SocialLogin\DTO\AuthorizationRequest;
use Plugins\Sirsoft\SocialLogin\DTO\NormalizedProfile;
use Plugins\Sirsoft\SocialLogin\DTO\TokenSet;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptOutcome;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\AttemptRepositoryInterface;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\ProviderRepositoryInterface;

/**
 * OAuth 왕복 — 시작(인가 주소 만들기)과 콜백(코드 교환 → 프로필 → 의도별 분기).
 *
 * `test` 의도(관리자 연결 테스트)는 여기서 결과 화면까지 끝내고, 로그인·연결·본인 재확인 의도는 `CallbackDispatcher` 로
 * 넘긴다(로그인 인계, 가입, 연결, 재확인).
 *
 * 콜백 응답 직전에는 세션 ID 를 새로 만든다(세션 고정 방어).
 *
 * @since 1.0.0
 */
class OAuthFlowService
{
    /** 프로필 정규화 필터 훅 */
    public const FILTER_PROFILE = 'sirsoft-social_login.profile.filter_normalized';

    /** 테스트 결과 보관 시간(초) — 결과 주소가 곧 열람 권한이라 짧게 둔다 */
    public const TEST_RESULT_TTL = 60;

    /**
     * @param  ProviderRegistry  $registry  제공자 레지스트리
     * @param  StateStore  $states  state 저장소
     * @param  ProviderRepositoryInterface  $providers  제공자 저장소
     * @param  AttemptRepositoryInterface  $attempts  시도 기록 저장소
     * @param  CacheInterface  $cache  플러그인 캐시
     */
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly StateStore $states,
        private readonly ProviderRepositoryInterface $providers,
        private readonly AttemptRepositoryInterface $attempts,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 왕복을 시작하고 제공자 인가 주소를 반환합니다.
     *
     * 로그인·연결은 활성 제공자만, 관리자 테스트는 활성 여부와 관계없이 시작한다(켜기 전에 확인하는 용도).
     *
     * @param  string  $providerKey  제공자 키
     * @param  AttemptIntent  $intent  의도
     * @param  string|null  $redirect  복귀 주소
     * @param  int|null  $userId  시작한 회원 ID
     * @param  string|null  $challengeId  본인 재확인 의도의 코어 본인인증 요청 ID
     * @return string 인가 주소
     *
     * @throws ProviderException 제공자가 없거나 꺼져 있을 때
     */
    public function start(string $providerKey, AttemptIntent $intent, ?string $redirect, ?int $userId = null, ?string $challengeId = null): string
    {
        $driver = $this->registry->get($providerKey);
        if ($driver === null) {
            throw new ProviderException('provider_unknown');
        }

        if ($intent !== AttemptIntent::Test && ! $this->isEnabled($providerKey, $driver)) {
            throw new ProviderException('provider_disabled');
        }

        $supports = $driver->supports();
        $request = $this->states->create(
            providerKey: $providerKey,
            intent: $intent->value,
            redirect: $redirect,
            callbackUrl: self::callbackUrl($providerKey),
            userId: $userId,
            usePkce: (bool) ($supports['pkce'] ?? false),
            useNonce: (bool) ($supports['oidc'] ?? false),
            ttlMinutes: (int) plugin_setting('sirsoft-social_login', 'state_ttl_minutes', StateStore::DEFAULT_TTL_MINUTES),
            challengeId: $challengeId,
        );

        return $driver->authorizeUrl($request);
    }

    /**
     * 콜백을 처리하고 브라우저가 갈 주소를 반환합니다.
     *
     * @param  string  $providerKey  경로의 제공자 키
     * @param  Request  $request  콜백 요청
     * @return string 이동할 주소
     */
    public function callback(string $providerKey, Request $request): string
    {
        $state = $this->states->pull((string) $request->input('state', ''));

        if ($state === null) {
            $this->record($providerKey, AttemptIntent::Login->value, AttemptOutcome::Failed, null, 'state_invalid', '', $request);

            return self::loginErrorUrl('state_invalid');
        }

        if ($state->providerKey !== $providerKey) {
            // 다른 제공자의 콜백 주소로 돌아온 state — 이미 꺼냈으므로(1회성) 다시 쓸 수 없다. 로그인한 회원이 시작한 연결·재확인은
            // 시작한 화면으로(재확인 요청도 거둔다), 그 밖에는 위조 state 와 같은 로그인 화면 응답.
            $this->record($state->providerKey, $state->intent, AttemptOutcome::Failed, $state->userId, 'state_invalid', '', $request);

            return in_array($state->intent, [AttemptIntent::Link->value, AttemptIntent::Reauth->value], true)
                ? $this->failureUrl($state, 'state_invalid')
                : self::loginErrorUrl('state_invalid');
        }

        try {
            try {
                [$profile, $tokens] = $this->exchange($providerKey, $state, $request);
            } catch (ProviderException $e) {
                throw $e;
            } catch (\Throwable $e) {
                // 제공자 오류가 아닌 예외(저장된 비밀값 복호화 실패 등)도 오류 화면 대신 같은 실패 경로로 보낸다.
                // 원인은 로그에만 남기고, 시도 기록에는 비밀값이 섞이지 않게 예외 종류만 적는다.
                report($e);

                throw new ProviderException('unexpected_error', class_basename($e), null, $e);
            }
        } catch (ProviderException $e) {
            $this->providers->recordOutcome($providerKey, $e->errorCode);
            $this->record($providerKey, $state->intent, AttemptOutcome::Failed, $state->userId, $e->errorCode, $e->getMessage(), $request);

            return $this->failureUrl($state, $e->errorCode);
        }

        $this->providers->recordOutcome($providerKey, null);
        $this->regenerateSession($request);

        if ($state->intent === AttemptIntent::Test->value) {
            $this->record($providerKey, $state->intent, AttemptOutcome::Login, $state->userId, null, null, $request);

            return $this->storeTestResult($providerKey, true, $profile, null);
        }

        // 로그인·연결·재확인 분기 — 디스패처가 쓰는 서비스들이 이 서비스(왕복 시작)를 다시 주입받으므로 여기서 꺼낸다.
        try {
            return app(CallbackDispatcher::class)->dispatch($state, $profile, $tokens);
        } catch (\Throwable $e) {
            // 분기 안의 예상 밖 오류도 오류 화면 대신 의도에 맞는 화면으로 — 원인은 로그에만.
            report($e);
            $this->record($providerKey, $state->intent, AttemptOutcome::Failed, $state->userId, 'unexpected_error', class_basename($e), $request);

            return $this->failureUrl($state, 'unexpected_error');
        }
    }

    /**
     * 일회용 테스트 결과를 꺼냅니다 (한 번 보면 지워짐).
     *
     * @param  string  $code  결과 코드
     * @return array<string, mixed>|null 결과, 없으면 null
     */
    public function pullTestResult(string $code): ?array
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $code) !== 1) {
            return null;
        }

        $key = 'test_result:'.hash('sha256', $code);
        $result = $this->cache->get($key);
        $this->cache->forget($key);

        return is_array($result) ? $result : null;
    }

    /**
     * 콜백 주소를 반환합니다 (제공자 콘솔에 등록하는 값).
     *
     * @param  string  $providerKey  제공자 키
     * @return string 콜백 절대 주소
     */
    public static function callbackUrl(string $providerKey): string
    {
        // 제공자 콘솔에 등록하는 값이라 요청 Host 가 아니라 사이트 주소(app.url) 기준이어야 한다.
        return SiteUrl::absolute(route('web.plugins.sirsoft-social_login.oauth.callback', ['provider' => $providerKey], false));
    }

    /**
     * 로그인 화면 오류 주소를 만듭니다.
     *
     * @param  string  $code  오류 코드
     * @param  string|null  $redirect  로그인 뒤 복귀 주소
     * @param  string|null  $providerKey  안내 문구에 쓸 제공자 키 (예: 이메일 동의 다시 받기)
     * @return string 주소
     */
    public static function loginErrorUrl(string $code, ?string $redirect = null, ?string $providerKey = null): string
    {
        $query = ['social_error' => $code];
        if ($providerKey !== null) {
            $query['provider'] = $providerKey;
        }
        $safe = StateStore::safeRedirect($redirect);
        if ($safe !== '/') {
            $query['redirect'] = $safe;
        }

        return SiteUrl::absolute('/login?'.http_build_query($query));
    }

    /**
     * 코드 교환과 프로필 조회를 합니다.
     *
     * 제공자가 오류(`error`)로 돌려보냈으면 교환하지 않는다. 프로필 정규화 필터를 거친 뒤 제공자 사용자 ID 가 비면 실패다.
     *
     * @param  string  $providerKey  제공자 키
     * @param  AuthorizationRequest  $state  시작 정보
     * @param  Request  $request  콜백 요청
     * @return array{0: NormalizedProfile, 1: TokenSet} 정규화 프로필과 토큰
     *
     * @throws ProviderException 제공자 오류·교환 실패·ID 없음
     */
    private function exchange(string $providerKey, AuthorizationRequest $state, Request $request): array
    {
        $error = $request->input('error');
        if (is_string($error) && $error !== '') {
            throw new ProviderException(substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $error) ?: 'provider_error', 0, 50));
        }

        $code = (string) $request->input('code', '');
        if ($code === '') {
            throw new ProviderException('code_missing');
        }

        $driver = $this->registry->get($providerKey);
        if ($driver === null) {
            throw new ProviderException('provider_unknown');
        }

        $tokens = $driver->exchangeCode($code, $state);
        $profile = $driver->fetchProfile($tokens, $state, $request->all());

        $filtered = HookManager::applyFilters(self::FILTER_PROFILE, $profile, $providerKey);
        if ($filtered instanceof NormalizedProfile) {
            $profile = $filtered;
        }

        if (trim($profile->providerUserId) === '') {
            throw new ProviderException('profile_missing_id');
        }

        return [$profile, $tokens];
    }

    /**
     * 실패 때 갈 주소를 고릅니다 — 테스트는 결과 화면, 그 밖은 로그인 화면.
     *
     * @param  AuthorizationRequest  $state  시작 정보
     * @param  string  $errorCode  오류 코드
     * @return string 주소
     */
    private function failureUrl(AuthorizationRequest $state, string $errorCode): string
    {
        if ($state->intent === AttemptIntent::Test->value) {
            return $this->storeTestResult($state->providerKey, false, null, $errorCode);
        }

        if ($state->intent === AttemptIntent::Login->value) {
            return self::loginErrorUrl($errorCode, $state->redirect);
        }

        // 연결·재확인은 로그인한 회원이 마이페이지에서 시작한 왕복 — 로그인 화면은 로그인한 사람을 홈으로 되돌려 실패 사유가
        // 보이지 않으므로 출발한 화면으로 보낸다. 디스패처가 쓰는 서비스들이 이 서비스를 주입받으므로 여기서 꺼낸다.
        try {
            return app(CallbackDispatcher::class)->failure($state, $errorCode);
        } catch (\Throwable $e) {
            report($e);

            return self::loginErrorUrl($errorCode, $state->redirect);
        }
    }

    /**
     * 테스트 결과를 일회용 코드로 저장하고 결과 화면 주소를 반환합니다.
     *
     * 결과에는 토큰을 싣지 않는다. 제공자 사용자 ID 는 앞뒤만 보이게 가린다.
     *
     * @param  string  $providerKey  제공자 키
     * @param  bool  $success  성공 여부
     * @param  NormalizedProfile|null  $profile  프로필
     * @param  string|null  $errorCode  오류 코드
     * @return string 결과 화면 주소
     */
    private function storeTestResult(string $providerKey, bool $success, ?NormalizedProfile $profile, ?string $errorCode): string
    {
        $code = Str::random(40);

        $this->cache->put('test_result:'.hash('sha256', $code), [
            'provider_key' => $providerKey,
            'success' => $success,
            'error_code' => $errorCode,
            'provider_user_id' => $profile ? self::maskId($profile->providerUserId) : null,
            'email_masked' => $profile?->email !== null ? self::maskEmail($profile->email) : null,
            'email_verified' => $profile?->emailVerified,
            'name' => $profile?->name,
            'nickname' => $profile?->nickname,
            'received_fields' => $profile ? self::receivedFields($profile) : [],
            'missing_required' => $profile ? self::missingRequired($profile) : [],
            'raw_keys' => $profile ? self::rawKeys($profile->raw) : [],
            'tested_at' => now()->toIso8601String(),
        ], self::TEST_RESULT_TTL);

        return SiteUrl::absolute(route('web.plugins.sirsoft-social_login.test-result', ['code' => $code], false));
    }

    /**
     * 정규화한 프로필에서 제공자가 값을 보낸 항목 이름을 고릅니다.
     *
     * @param  NormalizedProfile  $profile  프로필
     * @return list<string> 값이 있는 항목 이름
     */
    private static function receivedFields(NormalizedProfile $profile): array
    {
        // 값은 담지 않고 「받았는가」만 본다 — 결과는 캐시에 남으므로 이메일 등 값이 섞이면 안 된다.
        $present = static fn ($v): bool => $v !== null && $v !== '';
        $checks = [
            ['provider_user_id', $present($profile->providerUserId)],
            ['email', $present($profile->email)],
            ['email_verified', $profile->emailVerified !== null],
            ['name', $present($profile->name)],
            ['nickname', $present($profile->nickname)],
            ['avatar_url', $present($profile->avatarUrl)],
        ];

        return array_values(array_map(fn (array $c) => $c[0], array_filter($checks, fn (array $c) => $c[1])));
    }

    /**
     * 가입에 필요한데 제공자가 보내지 않은 항목을 고릅니다.
     *
     * 가입은 이메일과 이름(없으면 별명)을 쓴다 — 빠진 항목은 가입 단계에서 회원에게 다시 묻는다.
     *
     * @param  NormalizedProfile  $profile  프로필
     * @return list<string> 빠진 항목 이름
     */
    private static function missingRequired(NormalizedProfile $profile): array
    {
        $missing = [];
        if ($profile->email === null || $profile->email === '') {
            $missing[] = 'email';
        }
        if (($profile->name ?? '') === '' && ($profile->nickname ?? '') === '') {
            $missing[] = 'name';
        }

        return $missing;
    }

    /**
     * 원본 프로필의 키 이름만 두 단계까지 모읍니다 (값은 싣지 않는다).
     *
     * @param  array<string, mixed>  $raw  원본 프로필
     * @return list<string> 키 이름 (예: `kakao_account.email`)
     */
    private static function rawKeys(array $raw): array
    {
        $keys = [];
        foreach ($raw as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value) && ! array_is_list($value)) {
                foreach (array_keys($value) as $child) {
                    $keys[] = $key.'.'.$child;
                }
            }
        }

        return $keys;
    }

    /**
     * 이메일의 로컬 부분을 가립니다 (테스트 결과 화면용 — 받았는지와 도메인만 보이면 된다).
     *
     * @param  string  $email  이메일
     * @return string 가린 이메일 (예: ka******@example.test)
     */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).str_repeat('*', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }

    /**
     * 제공자 사용자 ID 를 가립니다.
     *
     * @param  string  $id  ID
     * @return string 가린 ID
     */
    public static function maskId(string $id): string
    {
        $length = mb_strlen($id);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return mb_substr($id, 0, 2).str_repeat('*', $length - 4).mb_substr($id, -2);
    }

    /**
     * 제공자가 켜져 있는지 확인합니다.
     *
     * 테이블 행이 있으면 그 `enabled` 를 따른다. 테이블에 없는 제공자(다른 확장이 필터로 등록한 것, 모의 제공자)는
     * 등록 자체를 그 확장이 켜고 끄므로 레지스트리에 있으면 켜진 것으로 본다.
     *
     * @param  string  $providerKey  제공자 키
     * @param  ProviderDriverInterface  $driver  드라이버
     * @return bool 켜져 있으면 true
     */
    private function isEnabled(string $providerKey, ProviderDriverInterface $driver): bool
    {
        $row = $this->providers->findByKey($providerKey);

        return $row === null ? $driver->key() === $providerKey : $row->enabled;
    }

    /**
     * 세션이 있으면 ID 를 새로 만듭니다.
     *
     * @param  Request  $request  요청
     * @return void
     */
    private function regenerateSession(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->regenerate(true);
        }
    }

    /**
     * 시도를 기록합니다.
     *
     * @param  string  $providerKey  제공자 키
     * @param  string  $intent  의도
     * @param  AttemptOutcome  $outcome  결과
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $errorCode  오류 코드
     * @param  string|null  $message  오류 요약
     * @param  Request  $request  요청
     * @return void
     */
    private function record(string $providerKey, string $intent, AttemptOutcome $outcome, ?int $userId, ?string $errorCode, ?string $message, Request $request): void
    {
        $this->attempts->record([
            'provider_key' => mb_substr($providerKey, 0, 50),
            'user_id' => $userId,
            'intent' => in_array($intent, AttemptIntent::values(), true) ? $intent : AttemptIntent::Login->value,
            'outcome' => $outcome->value,
            'error_code' => $errorCode,
            'error_message' => $message !== '' ? $message : null,
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);
    }
}
