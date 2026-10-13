<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Listeners\AssignDefaultCurrencyOnRegisterListener;
use Modules\Sirsoft\Ecommerce\Listeners\AssignDefaultShippingCountryOnRegisterListener;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 회원 생성 직후 결제 통화·배송국가가 정해져 있다 — 큐 워커와 무관
 *
 * 가입·관리자 생성 때 기본 통화와 배송국가를 배정하는 리스너가 큐로 가면, 생성 직후 연 회원 상세에는 두 칸이
 * 비어 있고 워커가 돈 뒤에야 채워진다.
 */
class RegisterDefaultAssignmentQueuedTest extends ModuleTestCase
{
    /**
     * 큐를 database 로 바꾸고 워커는 돌리지 않습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
    }

    /**
     * 두 배정 리스너의 action 구독이 sync 다. (실패-먼저)
     *
     * @scenario listener=register_assignment, queue=sync
     *
     * @effects derived_data_visible_in_same_request
     *
     * @return void 반환값 없음
     */
    public function test_assignment_actions_are_synchronous(): void
    {
        foreach ([AssignDefaultCurrencyOnRegisterListener::class, AssignDefaultShippingCountryOnRegisterListener::class] as $listener) {
            foreach (['core.auth.after_register', 'core.user.after_create'] as $hook) {
                $config = $listener::getSubscribedHooks()[$hook] ?? [];
                $this->assertTrue(($config['sync'] ?? false) === true, class_basename($listener)." 의 {$hook} 가 sync 가 아닙니다.");
            }
        }
    }

    /**
     * 관리자가 회원을 만든 직후 그 회원 상세 응답에 결제 통화·배송국가가 있다. (실패-먼저)
     *
     * @scenario listener=register_assignment, queue=database
     *
     * @effects derived_data_visible_in_same_request
     *
     * @return void 반환값 없음
     */
    public function test_admin_created_user_has_currency_and_shipping_country_without_worker(): void
    {
        $admin = $this->createAdminUser(['core.users.create', 'core.users.read']);

        $email = 'assign-724-'.uniqid().'@example.com';
        $created = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => '배정 확인 회원',
            'email' => $email,
            'password' => 'Password1234!',
            'password_confirmation' => 'Password1234!',
        ]);
        $created->assertStatus(201);
        $user = User::where('email', $email)->firstOrFail();
        $userId = $user->id;

        $profiles = app(EcommerceUserProfileRepositoryInterface::class);
        $this->assertNotNull($profiles->getPreferredCurrency($userId), '생성 직후 결제 통화가 비어 있습니다.');
        $this->assertNotNull($profiles->getPreferredShippingCountry($userId), '생성 직후 배송국가가 비어 있습니다.');

        $detail = $this->actingAs($admin)->getJson("/api/admin/users/{$user->uuid}");
        $detail->assertOk();
        $this->assertNotNull($detail->json('data.ecommerce_preferred_currency'), '회원 상세 응답에 결제 통화가 없습니다.');
        $this->assertNotNull($detail->json('data.ecommerce_preferred_shipping_country'), '회원 상세 응답에 배송국가가 없습니다.');
    }
}
