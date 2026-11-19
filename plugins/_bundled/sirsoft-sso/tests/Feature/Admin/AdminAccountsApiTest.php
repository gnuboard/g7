<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 관리자 「회원 연결」 API — 단건 연결, 사전 등록(비밀번호 없음, 동의 기록 없음), CSV 미리보기와 실행, 마지막 수단 해제 경고, 권한.
 *
 * @since 1.0.0
 */
class AdminAccountsApiTest extends PluginTestCase
{
    use PolicyFixtures;

    /** API 접두 */
    private const BASE = '/api/plugins/sirsoft-sso/admin/accounts';

    /**
     * 기존 회원 연결과 새 회원 사전 등록을 확인한다.
     *
     * @scenario target=existing|new_user, subject=new|taken
     *
     * @effects linked_via_admin, preregistered_passwordless, no_consents, already_linked_409, activity_logs
     *
     * @return void 반환값 없음
     */
    public function test_link_and_preregister(): void
    {
        Sanctum::actingAs($this->makeAdmin(['sirsoft-sso.accounts.view', 'sirsoft-sso.accounts.provision']));
        $idp = $this->makeIdp('corp');
        $member = $this->makeMember();

        $row = $this->postJson(self::BASE, ['idp_id' => $idp->id, 'subject' => 'S-1', 'user_id' => $member->id])->assertStatus(201)->json('data');
        $this->assertSame('admin', $row['linked_via']);
        $this->assertSame($member->id, $row['user']['id']);
        $this->assertStringNotContainsString('S-1', $row['subject_masked'] === 'S-1' ? 'x' : $row['subject_masked']);

        $this->assertSame('already_linked', $this->postJson(self::BASE, ['idp_id' => $idp->id, 'subject' => 'S-1', 'user_id' => $this->makeMember()->id])->assertStatus(409)->json('errors.code'));

        $created = $this->postJson(self::BASE, ['idp_id' => $idp->id, 'subject' => 'S-2', 'new_user' => ['name' => '새 직원', 'email' => 'New.Hire@corp798.test']])->assertStatus(201)->json('data');
        $user = User::query()->find($created['user']['id']);
        $this->assertSame('new.hire@corp798.test', $user->email);
        $this->assertFalse($user->hasPassword());
        $this->assertSame(0, \DB::table('user_consents')->where('user_id', $user->id)->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'sso.preregistered')->where('loggable_id', $user->id)->count());
        $this->assertSame(2, ActivityLog::query()->where('action', 'sso.linked')->count());

        $list = $this->getJson(self::BASE.'?idp_id='.$idp->id)->assertOk()->json('data');
        $this->assertSame(2, $list['pagination']['total']);
    }

    /**
     * 마지막 로그인 수단 연결 해제는 확인을 요구하는지 확인한다.
     *
     * @scenario member=passwordless_single_link, confirm=false|true
     *
     * @effects last_method_409, removed_after_confirm, unlink_hooks
     *
     * @return void 반환값 없음
     */
    public function test_unlink_last_method_needs_confirm(): void
    {
        Sanctum::actingAs($this->makeAdmin(['sirsoft-sso.accounts.view', 'sirsoft-sso.accounts.unlink']));
        $idp = $this->makeIdp('corp');
        $account = $this->linkAccount($this->makeMember(['password' => null]), $idp);

        $this->assertSame('last_method', $this->deleteJson(self::BASE.'/'.$account->id)->assertStatus(409)->json('errors.code'));
        $this->deleteJson(self::BASE.'/'.$account->id, ['confirm_last_method' => true])->assertOk();
        $this->assertNull(SsoAccount::query()->find($account->id));
        $this->assertSame(1, ActivityLog::query()->where('action', 'sso.unlinked')->count());

        $withPassword = $this->linkAccount($this->makeMember(), $idp);
        $this->deleteJson(self::BASE.'/'.$withPassword->id)->assertOk();
    }

    /**
     * CSV 미리보기는 바꾸지 않고, 실행은 만들기와 연결만 처리하는지 확인한다.
     *
     * @scenario mode=preview|execute, rows=create|link|exists|conflict|admin|error
     *
     * @effects preview_no_change, counts_by_outcome, execute_creates_and_links, csv_imported_log
     *
     * @return void 반환값 없음
     */
    public function test_csv_preview_and_execute(): void
    {
        Sanctum::actingAs($this->makeSuper());
        $idp = $this->makeIdp('corp');
        $existing = $this->makeMember(['email' => 'link.me@corp798.test']);
        $already = $this->makeMember(['email' => 'already@corp798.test']);
        $this->linkAccount($already, $idp, 'E-3');
        $admin = $this->makeAdmin(['sirsoft-sso.settings.view'], ['email' => 'boss@corp798.test']);

        $csv = "email,name,idp_key,subject\n"
            ."brand.new@corp798.test,신규,corp,E-1\n"
            ."link.me@corp798.test,,corp,E-2\n"
            ."already@corp798.test,,corp,E-3\n"
            ."other@corp798.test,,corp,E-3\n"
            ."boss@corp798.test,,corp,E-5\n"
            ."not-an-email,,corp,E-6\n"
            ."x@corp798.test,,nope,E-7\n";

        $preview = $this->postJson(self::BASE.'/import', ['csv' => $csv, 'mode' => 'preview'])->assertOk()->json('data');
        $this->assertFalse($preview['executed']);
        $this->assertSame(['create' => 1, 'link' => 1, 'exists' => 1, 'conflict' => 1, 'admin_target_manual_only' => 1, 'error' => 2], $preview['counts']);
        $this->assertNull(User::query()->where('email', 'brand.new@corp798.test')->first());

        $result = $this->postJson(self::BASE.'/import', ['csv' => $csv, 'mode' => 'execute'])->assertOk()->json('data');
        $this->assertTrue($result['executed']);
        $created = User::query()->where('email', 'brand.new@corp798.test')->first();
        $this->assertNotNull($created);
        $this->assertDatabaseHas('sso_accounts', ['idp_id' => $idp->id, 'user_id' => $created->id, 'subject' => 'E-1', 'linked_via' => 'csv']);
        $this->assertDatabaseHas('sso_accounts', ['idp_id' => $idp->id, 'user_id' => $existing->id, 'subject' => 'E-2', 'linked_via' => 'csv']);
        $this->assertDatabaseMissing('sso_accounts', ['user_id' => $admin->id]);
        $this->assertSame(1, ActivityLog::query()->where('action', 'sso.csv_imported')->count());

        $this->assertSame('csv_header_invalid', $this->postJson(self::BASE.'/import', ['csv' => "a,b\n1,2", 'mode' => 'preview'])->assertStatus(422)->json('errors.code'));
    }

    /**
     * 권한별 접근을 확인한다 (열람만, 연결 추가, 해제).
     *
     * @scenario actor=view|provision|unlink
     *
     * @effects permission_matrix
     *
     * @return void 반환값 없음
     */
    public function test_permissions(): void
    {
        $idp = $this->makeIdp('corp');
        $account = $this->linkAccount($this->makeMember(), $idp);

        Sanctum::actingAs($this->makeAdmin(['sirsoft-sso.accounts.view']));
        $this->getJson(self::BASE)->assertOk();
        $this->postJson(self::BASE, ['idp_id' => $idp->id, 'subject' => 'P-1', 'user_id' => $this->makeMember()->id])->assertStatus(403);
        $this->deleteJson(self::BASE.'/'.$account->id)->assertStatus(403);

        Sanctum::actingAs($this->makeAdmin(['sirsoft-sso.settings.view']));
        $this->getJson(self::BASE)->assertStatus(403);
    }
}
