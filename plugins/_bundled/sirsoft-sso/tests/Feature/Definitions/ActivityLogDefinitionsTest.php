<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Definitions;

use App\Extension\HookManager;
use App\Models\ActivityLog;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 활동 로그(§6.19, D23) — 문구 키 ko/en 전부, 발행 훅이 활동 로그로 남는지, 액션 라벨이 플러그인 문구로 풀리는지.
 *
 * @since 1.0.0
 */
class ActivityLogDefinitionsTest extends PluginTestCase
{
    use PolicyFixtures;

    /** @var array<int, string> 계획서 §6.19 활동 로그 + 이메일 동기화(W15) + 명령 복원 */
    private const ACTIONS = [
        'login', 'login_failed', 'jit_created', 'linked', 'unlinked', 'preregistered', 'csv_imported', 'deprovisioned', 'reactivated',
        'withdrawn_after_deprovision', 'roles_synced', 'attributes_synced', 'email_synced', 'email_sync_conflict', 'emergency_login',
        'enforcement_changed', 'enforcement_disabled_cli', 'enforcement_restored_cli', 'idp_created', 'idp_updated', 'idp_deleted',
        'scim_token_issued', 'sp_keys_generated', 'consent_skipped',
    ];

    /**
     * 모든 활동의 라벨과 설명 문구가 ko/en 에 있는지 확인한다.
     *
     * @scenario locale=ko|en, actions=all
     *
     * @effects labels_present, descriptions_present
     *
     * @return void 반환값 없음
     */
    public function test_strings_present_in_both_locales(): void
    {
        $this->loadPluginLang();

        foreach (['ko', 'en'] as $locale) {
            foreach (self::ACTIONS as $action) {
                foreach (['action', 'description'] as $group) {
                    $key = 'sirsoft-sso::activity_log.'.$group.'.sso.'.$action;
                    $this->assertNotSame($key, __($key, [], $locale), $locale.' '.$key);
                }
            }
        }
    }

    /**
     * 발행 훅(연결, 퇴사, 역할 동기화, 로그인 실패)이 활동 로그로 남고 라벨이 풀리는지 확인한다.
     *
     * @scenario hook=after_link_jit_no_consent|after_deprovision|roles_after_sync|auth_login_failed
     *
     * @effects jit_and_consent_skipped_logged, deprovisioned_logged, roles_logged, roles_logged_with_idp, failure_logged, label_resolved
     *
     * @return void 반환값 없음
     */
    public function test_hooks_are_logged(): void
    {
        $this->loadPluginLang();
        $idp = $this->makeIdp('corp', ['require_consent' => false]);
        $member = $this->makeMember();
        $account = $this->linkAccount($member, $idp, null, ['linked_via' => 'jit']);

        HookManager::doAction('sirsoft-sso.account.after_link', $account, $member, 'jit');
        HookManager::doAction('sirsoft-sso.account.after_deprovision', $account, $member, 'scim');
        HookManager::doAction('sirsoft-sso.roles.after_sync', $member, ['manager'], [], $idp);
        HookManager::doAction('sirsoft-sso.auth.login_failed', $idp, 'token_error', 'login', 'user', 'failed');

        foreach (['sso.jit_created', 'sso.consent_skipped', 'sso.deprovisioned', 'sso.roles_synced', 'sso.login_failed'] as $action) {
            $this->assertSame(1, ActivityLog::query()->where('action', $action)->count(), $action);
        }

        app()->setLocale('ko');
        $log = ActivityLog::query()->where('action', 'sso.deprovisioned')->first();
        $this->assertSame('회사 계정 퇴사 처리', $log->action_label);
        $synced = ActivityLog::query()->where('action', 'sso.roles_synced')->first();
        $this->assertSame(['manager'], $synced->properties['added']);
        // 어느 IdP 그룹에서 온 역할인지 남는다(F1 M9 실측: idp_key 가 null 이었다)
        $this->assertSame($idp->key, $synced->properties['idp_key']);
        $this->assertArrayNotHasKey('skipped', $synced->properties);
    }

    /**
     * 역할 동기화에서 코어 등급 검사가 일부 변경을 건너뛰면 같은 활동 `sso.roles_synced` 에 건너뛴 사유와 「일부 건너뜀」 설명을 남긴다(§6.13).
     *
     * @scenario hook=roles_after_sync, skipped=core_guard
     *
     * @effects roles_synced_with_skip_reason
     *
     * @return void 반환값 없음
     */
    public function test_roles_sync_skip_reason_is_logged(): void
    {
        $this->loadPluginLang();
        $idp = $this->makeIdp('corp', ['require_consent' => false]);
        $member = $this->makeMember();

        HookManager::doAction('sirsoft-sso.roles.after_sync', $member, ['manager'], [], $idp, 'core_guard');

        $log = ActivityLog::query()->where('action', 'sso.roles_synced')->sole();
        $this->assertSame('core_guard', $log->properties['skipped']);
        $this->assertSame(['manager'], $log->properties['added']);
        $this->assertSame('sirsoft-sso::activity_log.description.sso.roles_synced_partly', $log->description_key);
        app()->setLocale('ko');
        $this->assertStringContainsString('코어 회원 등급 검사가 거름', $log->localized_description);
    }
}
