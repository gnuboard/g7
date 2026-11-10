<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Definitions;

use App\Extension\HookManager;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\GenericNotification;
use App\Services\NotificationTemplateService;
use Carbon\Carbon;
use Plugins\Sirsoft\Sso\Bootstrap\Notifications;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Plugin;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 알림 정의 7종(§6.19 6종 + 관리자 자동 탈퇴 건너뜀 W33) — 모양, 훅 선언, 본문 자리의 치환 값, 수신 문맥.
 *
 * @since 1.0.0
 */
class NotificationDefinitionsTest extends PluginTestCase
{
    use PolicyFixtures;

    /** @var array<int, string> 계획서 §6.19 의 알림 유형 + 관리자 자동 탈퇴 건너뜀(W33, F1 M8 T10 실측 뒤 분리) */
    private const TYPES = [
        'sso_emergency_login', 'sso_idp_failures', 'sso_cert_expiring', 'sso_email_conflict', 'sso_super_admin_deprovisioned', 'sso_admin_withdraw_skipped', 'sso_password_reset_redirect',
    ];

    /**
     * 정의가 7종이고 각 훅이 발행 훅으로 선언돼 있으며 본문 자리가 변수 목록에 있는지 확인한다.
     *
     * @scenario definitions=all
     *
     * @effects seven_types, hooks_declared, placeholders_declared, ko_en_present
     *
     * @return void 반환값 없음
     */
    public function test_definition_shape(): void
    {
        $definitions = (new Plugin)->getNotificationDefinitions();
        $hooks = (new Plugin)->getHooks();

        $this->assertSame(self::TYPES, array_column($definitions, 'type'));

        foreach ($definitions as $definition) {
            $this->assertSame(Notifications::HOOK_PREFIX, $definition['hook_prefix']);
            foreach ($definition['hooks'] as $hook) {
                $this->assertArrayHasKey($hook, $hooks, $definition['type'].' 훅 '.$hook.' 이 getHooks() 에 없다');
            }

            $variables = array_column($definition['variables'], 'key');
            foreach ($definition['templates'] as $template) {
                foreach (['ko', 'en'] as $locale) {
                    $this->assertNotEmpty($template['subject'][$locale] ?? null);
                    $this->assertNotEmpty($template['body'][$locale] ?? null);
                    preg_match_all('/\{([a-z_]+)\}/', $template['subject'][$locale].$template['body'][$locale].($template['click_url'] ?? ''), $m);
                    foreach (array_unique($m[1]) as $placeholder) {
                        $this->assertContains($placeholder, $variables, $definition['type'].' 의 {'.$placeholder.'} 가 variables 에 없다');
                    }
                }
            }
        }
    }

    /**
     * 치환 값 필터가 회원 대상 알림에 대상 회원을, 최고관리자 퇴사 알림에 최고관리자 묶음을 싣는지 확인한다.
     *
     * @scenario type=sso_password_reset_redirect|sso_super_admin_deprovisioned|sso_admin_withdraw_skipped|sso_idp_failures
     *
     * @effects member_context, super_admins_context, withdraw_skip_separate, values_filled
     *
     * @return void 반환값 없음
     */
    public function test_extract_data(): void
    {
        $idp = $this->makeIdp('corp');
        $member = $this->makeMember(['name' => '김직원']);
        $super = $this->makeSuper();
        $account = $this->linkAccount($super, $idp);
        $base = ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];

        $reset = HookManager::applyFilters('sirsoft-sso.notification.extract_data', $base, 'sso_password_reset_redirect', [$member, $idp]);
        $this->assertSame($member->id, $reset['context']['trigger_user_id']);
        // IdP 이름은 언어별 값으로 싣고 코어가 받는 사람 언어로 고른다(키 순서는 저장 JSON 순서라 무관) — 그린 결과는 test_values_follow_recipient_language
        $this->assertEquals(['ko' => '본사 계정', 'en' => 'HQ account'], $reset['data']['idp_label']);
        $this->assertSame('김직원', $reset['data']['name']);

        $deprovisioned = HookManager::applyFilters('sirsoft-sso.notification.extract_data', $base, 'sso_super_admin_deprovisioned', [$super, $account, 'scim']);
        $this->assertTrue($deprovisioned['context']['related_users']['super_admins']->contains('id', $super->id));
        $this->assertNotSame('', $deprovisioned['data']['source']);

        // 탈퇴 기한이 지난 관리자 — 최고관리자 퇴사(차단 안 함) 알림과 다른 정의로, 최고관리자 전원이 받는다
        $admin = $this->makeAdmin([], ['name' => '이관리']);
        $adminAccount = $this->linkAccount($admin, $idp);
        $skipped = HookManager::applyFilters('sirsoft-sso.notification.extract_data', $base, 'sso_admin_withdraw_skipped', [$admin, $adminAccount]);
        $this->assertTrue($skipped['context']['related_users']['super_admins']->contains('id', $super->id));
        $this->assertSame('이관리', $skipped['data']['user_name']);
        $this->assertEquals(['ko' => '본사 계정', 'en' => 'HQ account'], $skipped['data']['idp_label']);
        $this->assertStringContainsString('/admin/users/', $skipped['data']['user_url']);

        $definitions = array_column((new Plugin)->getNotificationDefinitions(), null, 'type');
        $skippedBody = $definitions['sso_admin_withdraw_skipped']['templates'][0]['body']['ko'];
        $this->assertStringNotContainsString('최고관리자', $definitions['sso_admin_withdraw_skipped']['templates'][0]['subject']['ko']);
        $this->assertStringNotContainsString('차단하지 않습니다', $skippedBody);

        $failing = HookManager::applyFilters('sirsoft-sso.notification.extract_data', $base, 'sso_idp_failures', [$idp, 6, 'token_error']);
        $this->assertSame('6', $failing['data']['failure_count']);
        $this->assertStringContainsString('/admin/plugins/sirsoft-sso/settings?idp='.$idp->id, $failing['data']['settings_url']);
        $this->assertInstanceOf(IdentityProvider::class, $idp);
    }

    /**
     * 비상 로그인 시각과 인증서 만료일이 알림 본문에 서버 시간대(UTC)가 아니라 사이트 시간대로 들어가는지 확인한다.
     *
     * UTC 15:30 은 서울의 다음 날 00:30 이다 — 변환이 빠지면 관리자는 하루 이른 만료일과 9시간 이른 로그인 시각을 받는다.
     *
     * @scenario type=sso_emergency_login|sso_cert_expiring, site_timezone=Asia/Seoul
     *
     * @effects notification_dates_in_site_timezone
     *
     * @return void 반환값 없음
     */
    public function test_dates_are_rendered_in_site_timezone(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);
        $idp = $this->makeIdp('corp');
        $idp->forceFill(['cert_expires_at' => Carbon::parse('2026-12-31 15:30:00', 'UTC')])->save();
        $base = ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];

        $expiring = HookManager::applyFilters('sirsoft-sso.notification.extract_data', $base, 'sso_cert_expiring', [$idp->fresh(), 7]);
        $this->assertSame('2027-01-01', $expiring['data']['expires_at']);

        $super = $this->makeSuper();
        $this->travelTo(Carbon::parse('2026-11-11 15:30:00', 'UTC'));
        try {
            $emergency = HookManager::applyFilters('sirsoft-sso.notification.extract_data', $base, 'sso_emergency_login', [$super]);
        } finally {
            $this->travelBack();
        }
        $this->assertSame('2026-11-12 00:30:00', $emergency['data']['login_at']);
    }

    /**
     * IdP 이름과 대체 문구, 퇴사 확인 경로 이름이 알림을 일으킨 요청의 언어가 아니라 받는 사람 언어로 들어가는지 확인한다.
     *
     * 영어 요청이 일으킨 이메일 충돌 알림이 한국어 관리자 30명에게 「한국어 틀 + Mock company account」로 갔다(#798).
     * 실제 정의의 메일, 사이트 알림 틀을 코어 알림으로 그려 받는 사람마다 본다.
     *
     * @scenario type=all_with_idp|password_reset_without_idp|deprovisioned_source, request_locale=en, recipient=ko|en
     *
     * @effects idp_label_follows_recipient, fallback_follows_recipient, source_follows_recipient
     *
     * @return void 반환값 없음
     */
    public function test_values_follow_recipient_language(): void
    {
        $this->loadPluginLang();
        config(['app.site_locale' => 'ko']);
        app()->setLocale('en');

        $idp = $this->makeIdp('corp');
        $super = $this->makeSuper();
        $member = $this->makeMember(['name' => '김직원']);
        $account = $this->linkAccount($member, $idp);
        $base = ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];
        $definitions = array_column((new Plugin)->getNotificationDefinitions(), null, 'type');
        $this->app->instance(NotificationTemplateService::class, $this->templatesFrom($definitions));

        $cases = [
            'sso_idp_failures' => [$idp, 6, 'token_error'],
            'sso_cert_expiring' => [$idp, 7],
            'sso_email_conflict' => [$member, $account, 'other@corp798.test'],
            'sso_super_admin_deprovisioned' => [$super, $this->linkAccount($super, $idp), 'scim'],
            'sso_admin_withdraw_skipped' => [$member, $account],
            'sso_password_reset_redirect' => [$member, $idp],
        ];
        $expected = ['ko' => '본사 계정', 'en' => 'HQ account'];

        foreach ($cases as $type => $args) {
            $data = HookManager::applyFilters('sirsoft-sso.notification.extract_data', $base, $type, $args)['data'];
            foreach ($expected as $language => $label) {
                $rendered = $this->render($type, $data, $language, isset($definitions[$type]['templates'][1]));
                $this->assertStringContainsString($label, $rendered, $type.' → '.$language);
                $this->assertStringNotContainsString($expected[$language === 'ko' ? 'en' : 'ko'], $rendered, $type.' → '.$language);
            }
        }

        // IdP 를 모를 때 회원 본인용 대체 문구도 받는 사람 언어
        $fallback = HookManager::applyFilters('sirsoft-sso.notification.extract_data', $base, 'sso_password_reset_redirect', [$member, null])['data'];
        $this->assertStringContainsString('이 계정은 회사 계정(으)로만', $this->render('sso_password_reset_redirect', $fallback, 'ko', false));
        $this->assertStringContainsString('signs in only with your company account', $this->render('sso_password_reset_redirect', $fallback, 'en', false));

        // 퇴사 확인 경로 이름(SCIM 알림)도 받는 사람 언어
        $deprovisioned = HookManager::applyFilters('sirsoft-sso.notification.extract_data', $base, 'sso_super_admin_deprovisioned', $cases['sso_super_admin_deprovisioned'])['data'];
        $this->assertStringContainsString('(SCIM 알림)', $this->render('sso_super_admin_deprovisioned', $deprovisioned, 'ko', true));
        $this->assertStringContainsString('(SCIM notice)', $this->render('sso_super_admin_deprovisioned', $deprovisioned, 'en', true));
    }

    /**
     * 비밀번호 찾기 안내 메일이 회원이 실제로 할 수 있는 로그인 방법을 적는지 확인한다.
     *
     * LDAP/AD 는 로그인 화면에 버튼이 없고 같은 폼에 사내 아이디(또는 회사 이메일)와 회사 비밀번호를 넣는다 — 「○○(으)로 로그인」을
     * 누르라는 안내는 OIDC, SAML 에만 맞다. 안내할 IdP 가 없으면 관리자 문의로 안내한다.
     *
     * @scenario type=sso_password_reset_redirect, guide_idp=ldap_username|ldap_email|oidc|none, recipient=ko|en
     *
     * @effects reset_guide_matches_login_method
     *
     * @return void 반환값 없음
     */
    public function test_reset_guidance_follows_login_method(): void
    {
        $this->loadPluginLang();
        config(['app.site_locale' => 'ko']);

        $member = $this->makeMember(['name' => '김직원']);
        $oidc = $this->makeIdp('corp');
        $ldapConfig = ['host' => 'ldap.dir798.test', 'port' => 389, 'base_dn' => 'dc=dir798,dc=test'];
        $ldapUsername = $this->makeIdp('dir', [
            'protocol' => 'ldap', 'label' => ['ko' => '사내 디렉터리', 'en' => 'Corporate directory'],
            'config' => $ldapConfig + ['accept_username' => true], 'secrets' => [], 'owned_domains' => [],
        ]);
        $ldapEmail = $this->makeIdp('dir-mail', [
            'protocol' => 'ldap', 'label' => ['ko' => '사내 메일 디렉터리', 'en' => 'Mail directory'],
            'config' => $ldapConfig, 'secrets' => [], 'owned_domains' => ['dir798.test'],
        ]);
        $base = ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];
        $definitions = array_column((new Plugin)->getNotificationDefinitions(), null, 'type');
        $this->app->instance(NotificationTemplateService::class, $this->templatesFrom($definitions));
        $mail = fn (?IdentityProvider $idp, string $language) => $this->render(
            'sso_password_reset_redirect',
            HookManager::applyFilters('sirsoft-sso.notification.extract_data', $base, 'sso_password_reset_redirect', [$member, $idp])['data'],
            $language,
            false,
        );

        // LDAP(사내 아이디) — 버튼 안내 없이 폼에 사내 아이디와 회사 비밀번호
        $ko = $mail($ldapUsername, 'ko');
        $this->assertStringContainsString('사내 아이디', $ko);
        $this->assertStringNotContainsString('로그인」을 눌러', $ko);
        $en = $mail($ldapUsername, 'en');
        $this->assertStringContainsString('company username', $en);
        $this->assertStringNotContainsString('Sign in with Corporate directory', $en);

        // LDAP(소유 도메인만) — 회사 이메일과 회사 비밀번호
        $this->assertStringContainsString('회사 이메일', $mail($ldapEmail, 'ko'));
        $this->assertStringContainsString('company email', $mail($ldapEmail, 'en'));

        // OIDC — 종전처럼 버튼 안내
        $this->assertStringContainsString('「본사 계정(으)로 로그인」을 눌러', $mail($oidc, 'ko'));
        $this->assertStringContainsString('Sign in with HQ account', $mail($oidc, 'en'));

        // 안내할 IdP 없음 — 없는 버튼 대신 관리자 문의
        $none = $mail(null, 'ko');
        $this->assertStringContainsString('관리자에게 문의', $none);
        $this->assertStringNotContainsString('로그인」을 눌러', $none);
    }

    /**
     * 정의의 메일, 사이트 알림 틀을 돌려주는 템플릿 서비스를 만듭니다 (DB 틀 대신).
     *
     * @param  array<string, array<string, mixed>>  $definitions  유형 => 정의
     * @return NotificationTemplateService 서비스
     */
    private function templatesFrom(array $definitions): NotificationTemplateService
    {
        $service = $this->createMock(NotificationTemplateService::class);
        $service->method('resolve')->willReturnCallback(function (string $type, string $channel) use ($definitions): ?NotificationTemplate {
            foreach ($definitions[$type]['templates'] ?? [] as $source) {
                if (($source['channel'] ?? null) === $channel) {
                    $template = new NotificationTemplate;
                    $template->subject = $source['subject'];
                    $template->body = $source['body'];
                    $template->click_url = $source['click_url'] ?? null;
                    $template->is_active = true;

                    return $template;
                }
            }

            return null;
        });

        return $service;
    }

    /**
     * 알림을 받는 사람 언어로 그린 메일(과 사이트 알림)의 제목, 본문을 이어 붙여 돌려줍니다.
     *
     * @param  string  $type  알림 유형
     * @param  array<string, mixed>  $data  치환 값
     * @param  string  $language  받는 사람 언어
     * @param  bool  $withDatabase  사이트 알림 틀도 그릴지
     * @return string 그린 글
     */
    private function render(string $type, array $data, string $language, bool $withDatabase): string
    {
        $notification = new GenericNotification($type, Notifications::HOOK_PREFIX, $data, 'plugin', 'sirsoft-sso');
        $recipient = new User(['email' => $language.'@corp798.test', 'language' => $language]);
        $mail = $notification->toMail($recipient);
        $text = $mail->envelope()->subject.' '.$mail->content()->with['body'];
        if ($withDatabase) {
            $site = $notification->toArray($recipient);
            $text .= ' '.$site['subject'].' '.$site['body'];
        }

        return $text;
    }
}
