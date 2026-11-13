<?php

namespace Plugins\Sirsoft\Sso\Listeners\Admin;

use App\Contracts\Extension\HookListenerInterface;
use App\Helpers\TimezoneHelper;
use App\Models\User;
use App\Notifications\NotificationVariables;
use App\Support\ScreenUrl;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Plugin;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\SitePolicyRepositoryInterface;
use Plugins\Sirsoft\Sso\Services\Policy\EnforcementPolicy;
use Plugins\Sirsoft\Sso\Support\MemberName;

/**
 * 이 플러그인 알림 7종의 치환 값과 수신 문맥을 만듭니다.
 *
 * 코어 알림 발송기는 정의의 훅이 발화하면 필터 `sirsoft-sso.notification.extract_data` 로 본문 자리(`{name}` 등)에 넣을 값을
 * 묻는다. 수신자마다 언어가 다른 값(IdP 이름, 대체 문구, 퇴사 확인 경로)은 `{로케일: 문자열}` 로 넘겨 코어가 받는 사람 언어로 고른다.
 * 회원 대상 알림은 문맥의 `trigger_user_id` 로, 최고관리자 퇴사 알림과 관리자 자동 탈퇴 건너뜀 알림은
 * `related_users.super_admins` 로 수신자를 알려 준다.
 * 이 리스너가 없으면 메일 본문에 중괄호가 그대로 나간다. 코어 「기본값으로 복원」 경로에도 기본 정의를 돌려준다.
 *
 * @since 1.0.0
 */
class NotificationDataListener implements HookListenerInterface
{
    /**
     * @param  SitePolicyRepositoryInterface  $repository  최고관리자 조회
     */
    public function __construct(private readonly SitePolicyRepositoryInterface $repository) {}

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-sso.notification.extract_data' => ['method' => 'extract', 'type' => 'filter', 'priority' => 10, 'sync' => true],
            'core.notification.filter_default_definitions' => ['method' => 'defaults', 'type' => 'filter', 'priority' => 20, 'sync' => true],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 코어 알림 복원 경로에 이 플러그인의 기본 정의를 더합니다.
     *
     * @param  array<int, array<string, mixed>>  $definitions  다른 확장이 더한 기본 정의
     * @return array<int, array<string, mixed>> 정의
     */
    public function defaults(array $definitions): array
    {
        foreach ((new Plugin)->getNotificationDefinitions() as $definition) {
            $definitions[] = $definition + ['extension_type' => 'plugin', 'extension_identifier' => 'sirsoft-sso'];
        }

        return $definitions;
    }

    /**
     * 알림 유형별 치환 값을 만듭니다.
     *
     * @param  array<string, mixed>  $default  기본 구조 (notifiable·notifiables·data·context)
     * @param  string  $type  알림 유형
     * @param  array<int, mixed>  $args  훅 인자
     * @return array<string, mixed> 추출 결과
     */
    public function extract(array $default, string $type, array $args): array
    {
        return match ($type) {
            'sso_emergency_login' => $this->forAdmins($default, [
                'user_name' => $this->userName($args[0] ?? null),
                'login_at' => (string) TimezoneHelper::toSiteDateTimeString(now()),
                'ip_address' => (string) (request()?->ip() ?? ''),
                'settings_url' => $this->settingsUrl(),
            ]),
            'sso_idp_failures' => $this->forAdmins($default, [
                'idp_label' => $this->idpLabel($args[0] ?? null),
                'failure_count' => (string) (int) ($args[1] ?? 0),
                'last_error_code' => (string) ($args[2] ?? ''),
                'settings_url' => $this->settingsUrl($args[0] ?? null),
            ]),
            'sso_cert_expiring' => $this->forAdmins($default, [
                'idp_label' => $this->idpLabel($args[0] ?? null),
                'days_left' => (string) (int) ($args[1] ?? 0),
                'expires_at' => ($args[0] ?? null) instanceof IdentityProvider ? (string) TimezoneHelper::toSiteDateString($args[0]->cert_expires_at) : '',
                'settings_url' => $this->settingsUrl($args[0] ?? null),
            ]),
            'sso_email_conflict' => $this->forAdmins($default, [
                'user_name' => $this->userName($args[0] ?? null),
                'idp_label' => $this->idpLabel(($args[1] ?? null) instanceof SsoAccount ? $args[1]->idp : null),
                'idp_email' => (string) ($args[2] ?? ''),
                'user_url' => $this->userUrl($args[0] ?? null),
            ]),
            'sso_super_admin_deprovisioned' => $this->forSuperAdmins($default, [
                'user_name' => $this->userName($args[0] ?? null),
                'idp_label' => $this->idpLabel(($args[1] ?? null) instanceof SsoAccount ? $args[1]->idp : null),
                'source' => $this->sourceLabel((string) ($args[2] ?? '')),
                'user_url' => $this->userUrl($args[0] ?? null),
            ]),
            'sso_admin_withdraw_skipped' => $this->forSuperAdmins($default, [
                'user_name' => $this->userName($args[0] ?? null),
                'idp_label' => $this->idpLabel(($args[1] ?? null) instanceof SsoAccount ? $args[1]->idp : null),
                'user_url' => $this->userUrl($args[0] ?? null),
            ]),
            'sso_password_reset_redirect' => $this->forMember($default, $args[0] ?? null, [
                'idp_label' => ($args[1] ?? null) instanceof IdentityProvider
                    ? $this->idpLabel($args[1])
                    : $this->translations('sirsoft-sso::admin.notification.company_account', 'sirsoft-sso::admin.notification.company_account'),
                'login_guide' => $this->loginGuide($args[1] ?? null),
                'login_url' => ScreenUrl::absolute('auth.login', '/login'),
            ]),
            default => $default,
        };
    }

    /**
     * 관리자 대상 알림 값 — 수신자는 정의의 역할 규칙이 정한다.
     *
     * @param  array<string, mixed>  $default  기본 구조
     * @param  array<string, string|array<string, string>>  $data  치환 값 (언어별 값은 받는 사람 언어로 고른다)
     * @return array<string, mixed> 추출 결과
     */
    private function forAdmins(array $default, array $data): array
    {
        return array_merge($default, ['data' => ['app_name' => (string) config('app.name'), 'name' => '{recipient_name}'] + $data]);
    }

    /**
     * 최고관리자 전원 대상 알림 값.
     *
     * @param  array<string, mixed>  $default  기본 구조
     * @param  array<string, string|array<string, string>>  $data  치환 값 (언어별 값은 받는 사람 언어로 고른다)
     * @return array<string, mixed> 추출 결과
     */
    private function forSuperAdmins(array $default, array $data): array
    {
        $result = $this->forAdmins($default, $data);
        $context = is_array($result['context'] ?? null) ? $result['context'] : [];
        $context['related_users'] = ['super_admins' => $this->repository->superAdmins()];
        $result['context'] = $context;

        return $result;
    }

    /**
     * 회원 대상 알림 값.
     *
     * @param  array<string, mixed>  $default  기본 구조
     * @param  mixed  $user  대상 회원
     * @param  array<string, string|array<string, string>>  $extra  더할 값 (언어별 값은 받는 사람 언어로 고른다)
     * @return array<string, mixed> 추출 결과 (대상 회원이 없으면 발송 건너뜀)
     */
    private function forMember(array $default, mixed $user, array $extra): array
    {
        if (! $user instanceof User) {
            return ['context' => ['skip' => true]] + $default;
        }

        // 받는 사람이 그 회원 본인이다 — 이름은 자리표시로 싣고 코어가 받는 사람과 채널마다 표시 이름으로 푼다
        return array_merge($default, [
            'data' => ['app_name' => (string) config('app.name'), 'name' => '{recipient_name}'] + $extra,
            'context' => ['trigger_user_id' => (int) $user->getKey(), 'trigger_user' => $user],
        ]);
    }

    /**
     * 알림 본문에 실을 사건 회원 이름 — 받는 사람이 아닌 회원이라 알림 영역(`core:notification`) 표시 이름이다.
     *
     * @param  mixed  $user  회원
     * @return string 이름 (회원이 아니면 빈 문자열)
     */
    private function userName(mixed $user): string
    {
        return $user instanceof User ? MemberName::forNotification($user) : '';
    }

    /**
     * IdP 표시 이름 — 언어별 값(`{로케일: 이름}`)으로 넘겨 코어가 받는 사람 언어로 고르게 한다.
     *
     * 알림은 요청 하나가 일으켜 여러 수신자에게 간다. 지금 요청의 언어로 문자열 하나를 만들면 수신자 전원이 그 언어 이름을 받는다
     * (영어 요청 → 한국어 틀에 「Mock company account」).
     *
     * @param  mixed  $idp  IdP
     * @return array<string, string>|string 언어별 이름 (라벨이 비면 IdP 키, IdP 가 없으면 빈 문자열)
     */
    private function idpLabel(mixed $idp): array|string
    {
        if (! $idp instanceof IdentityProvider) {
            return '';
        }

        $labels = array_filter(is_array($idp->label) ? $idp->label : [], fn ($value) => is_string($value) && trim($value) !== '');

        return $labels !== [] && NotificationVariables::isLocalized($labels) ? $labels : $idp->localizedLabel();
    }

    /**
     * 퇴사 확인 경로의 읽을 수 있는 이름 (언어별 값).
     *
     * @param  string  $source  scim, periodic, login, admin
     * @return array<string, string>|string 언어별 이름 (문구가 없으면 경로 값 그대로)
     */
    private function sourceLabel(string $source): array|string
    {
        return $source !== '' ? $this->translations('sirsoft-sso::admin.notification.sources.'.$source, $source) : '';
    }

    /**
     * 비밀번호 찾기 안내 메일의 로그인 방법 문장 (언어별 값).
     *
     * OIDC, SAML 은 로그인 화면의 「○○ 계정으로 로그인」 버튼을 누른다. LDAP 은 버튼이 없고 같은 로그인 폼에 사내 아이디(또는 회사 이메일)와
     * 회사 비밀번호를 넣는다. 안내할 IdP 가 없거나 LDAP 인데 폼으로 들어갈 길이 없으면 관리자 문의로 안내한다.
     *
     * @param  mixed  $idp  안내할 IdP
     * @return array<string, string>|string 언어별 문장
     */
    private function loginGuide(mixed $idp): array|string
    {
        $base = 'sirsoft-sso::admin.notification.login_guide.';

        if (! $idp instanceof IdentityProvider) {
            return $this->translations($base.'contact', '');
        }

        if ($idp->protocol !== 'ldap') {
            return $this->translations($base.'redirect', '', fn (string $locale) => ['label' => $idp->localizedLabel($locale)]);
        }

        return match (EnforcementPolicy::directoryLoginField($idp)) {
            'username' => $this->translations($base.'directory_username', ''),
            'email' => $this->translations($base.'directory_email', ''),
            default => $this->translations($base.'contact', ''),
        };
    }

    /**
     * 문구 키를 지원 언어마다 번역한 언어별 값을 만듭니다 (받는 사람 언어로 고르는 알림 변수).
     *
     * @param  string  $key  문구 키
     * @param  string  $fallback  어느 언어에도 문구가 없을 때 값
     * @param  (callable(string): array<string, string>)|null  $replace  언어별 치환값
     * @return array<string, string>|string 언어별 값
     */
    private function translations(string $key, string $fallback, ?callable $replace = null): array|string
    {
        $values = [];
        foreach ((array) config('app.supported_locales', ['ko', 'en']) as $locale) {
            $text = __($key, $replace !== null ? $replace((string) $locale) : [], (string) $locale);
            if (is_string($text) && $text !== $key) {
                $values[(string) $locale] = $text;
            }
        }

        return $values !== [] ? $values : $fallback;
    }

    /**
     * SSO 설정 화면 절대 주소 (IdP 가 있으면 그 편집 보기).
     *
     * @param  mixed  $idp  IdP
     * @return string 주소
     */
    private function settingsUrl(mixed $idp = null): string
    {
        $path = '/admin/plugins/sirsoft-sso/settings'.($idp instanceof IdentityProvider ? '?idp='.$idp->id : '');

        return ScreenUrl::absolute('sirsoft-sso.admin.settings', $path, [], ScreenUrl::SCOPE_ADMIN);
    }

    /**
     * 회원 상세 화면 절대 주소.
     *
     * @param  mixed  $user  회원
     * @return string 주소
     */
    private function userUrl(mixed $user): string
    {
        $path = $user instanceof User ? '/admin/users/'.$user->uuid : '/admin/users';

        return ScreenUrl::absolute('admin.users.show', $path, [], ScreenUrl::SCOPE_ADMIN);
    }
}
