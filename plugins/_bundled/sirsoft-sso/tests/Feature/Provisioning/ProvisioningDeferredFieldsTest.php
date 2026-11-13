<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Provisioning;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Models\FieldDefinition;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\Sso\Services\Admin\AccountAdminService;
use Plugins\Sirsoft\Sso\Services\Provisioning\ScimSchema;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * 사람이 화면을 보지 않는 회원 생성(SCIM, 관리자 사전 등록, 로그인 시 자동 생성)과 운영자가 필수로 둔 회원 항목.
 *
 * 이 경로들은 필수 항목 때문에 생성이 실패하면 안 되고, 만든 회원에 「필수 항목 나중 입력」 표시를 남겨
 * 그 회원이 처음 로그인했을 때 추가 정보 입력 화면으로 보낼 수 있어야 한다.
 *
 * @since 1.0.0
 */
class ProvisioningDeferredFieldsTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * 운영자가 가입 필수로 둔 추가 항목 하나와 필수로 바꾼 휴대폰을 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        FieldDefinition::create([
            'field_set' => 'core.user', 'key' => 'employee_no', 'type' => 'text', 'source_type' => 'custom',
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => '사번'],
            'enabled' => true, 'required' => true, 'show_on_signup' => true,
        ]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
    }

    /**
     * SCIM 으로 만든 회원 — 필수 항목이 비어도 201 이고 표시가 남는다.
     *
     * @scenario path=scim
     *
     * @effects provisioned_member_is_created_despite_required_fields, provisioned_member_is_marked_deferred
     *
     * @return void 반환값 없음
     */
    public function test_scim_created_member_is_marked_deferred(): void
    {
        $idp = $this->makeIdp();
        $token = $this->issueScimToken($idp);
        $email = $this->uniqueEmail('corp798.test');

        $this->scim('POST', $idp, '/Users', $token, [
            'schemas' => [ScimSchema::USER],
            'userName' => 'scim-490',
            'externalId' => 'E-490',
            'name' => ['formatted' => '스킴 회원'],
            'emails' => [['value' => $email, 'primary' => true]],
            'active' => true,
        ])->assertStatus(201);

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame('스킴 회원', $user->name);
        $this->assertNotNull($user->required_fields_deferred_at, 'SCIM 으로 만든 회원에 필수 항목 나중 입력 표시가 없다');
    }

    /**
     * 관리자 사전 등록(CSV 가져오기와 같은 경로)으로 만든 회원 — 필수 항목이 비어도 만들어지고 표시가 남는다.
     *
     * @scenario path=preregister
     *
     * @effects provisioned_member_is_created_despite_required_fields, provisioned_member_is_marked_deferred
     *
     * @return void 반환값 없음
     */
    public function test_preregistered_member_is_marked_deferred(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_super' => true]));
        $idp = $this->makeIdp();
        $email = $this->uniqueEmail('corp798.test');

        app(AccountAdminService::class)->preregister($idp, ['name' => '사전 등록', 'email' => $email], 'csv');

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertFalse($user->hasPassword());
        $this->assertNotNull($user->required_fields_deferred_at, '사전 등록한 회원에 필수 항목 나중 입력 표시가 없다');
    }

    /**
     * 로그인 시 자동 생성 — 동의 창이 회원 항목을 받지 않으므로 표시가 남는다.
     *
     * @scenario path=jit
     *
     * @effects provisioned_member_is_created_despite_required_fields, provisioned_member_is_marked_deferred
     *
     * @return void 반환값 없음
     */
    public function test_jit_created_member_is_marked_deferred(): void
    {
        $idp = $this->mockOidcIdp();
        $email = $this->uniqueEmail('corp798.test');

        $callback = $this->roundTrip($idp, 'full', ['sub' => 'jit-490', 'email' => $email, 'name' => '자동 생성']);
        $pending = $this->redirectQuery($callback)['sso_pending'];
        $this->withBrowserBind()
            ->postJson('/api/plugins/sirsoft-sso/pending/'.$pending.'/complete', ['agree_terms' => true, 'agree_privacy' => true])
            ->assertOk();

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame('자동 생성', $user->name);
        $this->assertNotNull($user->required_fields_deferred_at, '자동 생성한 회원에 필수 항목 나중 입력 표시가 없다');
    }
}
