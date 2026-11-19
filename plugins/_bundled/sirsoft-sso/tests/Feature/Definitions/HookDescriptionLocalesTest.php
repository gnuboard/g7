<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Definitions;

use Plugins\Sirsoft\Sso\Plugin;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 발행 훅 설명(§6.18) — 모든 훅이 ko/en/ja 설명을 갖고, 알림용 훅 6개와 그 인자 모양이 선언과 같다.
 *
 * @since 1.0.0
 */
class HookDescriptionLocalesTest extends PluginTestCase
{
    /**
     * 모든 발행 훅 설명이 세 로케일에 있고 비어 있지 않은지 확인한다.
     *
     * @scenario locales=ko|en|ja
     *
     * @effects descriptions_complete
     *
     * @return void 반환값 없음
     */
    public function test_every_hook_has_three_locales(): void
    {
        foreach ((new Plugin)->getHooks() as $name => $hook) {
            $this->assertContains($hook['type'], ['action', 'filter'], $name);
            foreach (['ko', 'en', 'ja'] as $locale) {
                $this->assertNotEmpty($hook['description'][$locale] ?? null, $name.' '.$locale);
            }
        }
    }

    /**
     * 알림과 활동 로그가 구독하는 훅의 인자 모양이 설명에 적힌 것과 같은지 확인한다.
     *
     * @scenario hooks=notification_and_log
     *
     * @effects arguments_documented
     *
     * @return void 반환값 없음
     */
    public function test_notification_hook_arguments(): void
    {
        $hooks = (new Plugin)->getHooks();
        $expected = [
            'sirsoft-sso.auth.login_failed' => '(?IdentityProvider $idp, string $errorCode, string $intent, string $guard, string $outcome)',
            'sirsoft-sso.idp.failing' => '(IdentityProvider $idp, int $failureCount, string $lastErrorCode)',
            'sirsoft-sso.idp.cert_expiring' => '(IdentityProvider $idp, int $daysLeft)',
            'sirsoft-sso.account.email_sync_conflict' => '(User $user, SsoAccount $account, string $idpEmail)',
            'sirsoft-sso.account.super_admin_deprovisioned' => '(User $user, SsoAccount $account, string $source)',
            'sirsoft-sso.account.admin_withdraw_skipped' => '(User $user, SsoAccount $account)',
            'sirsoft-sso.roles.after_sync' => '(User $user, array $added, array $removed, IdentityProvider $idp, ?string $skipped)',
            'sirsoft-sso.auth.password_reset_redirected' => '(User $user, ?IdentityProvider $idp)',
            'sirsoft-sso.auth.emergency_login' => '(User $user)',
        ];

        foreach ($expected as $name => $arguments) {
            $this->assertArrayHasKey($name, $hooks);
            foreach (['ko', 'en', 'ja'] as $locale) {
                $this->assertStringContainsString($arguments, $hooks[$name]['description'][$locale], $name.' '.$locale);
            }
        }
    }
}
