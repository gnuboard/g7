<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Security;

use App\Enums\PermissionType;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\ExtraFeeTemplate;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\ExtraFeeTemplateService;
use Modules\Sirsoft\Ecommerce\Services\ShippingPolicyService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 서비스 계층 스코프 게이트 재적용 (KVE-2026-1919 형제)
 *
 * `PermissionMiddleware` 는 라우트 파라미터가 Model 로 resolve 될 때만 스코프를 검사하고,
 * 아니면 "목록 엔드포인트" 로 보아 건너뛴다. 이 모듈에는 두 형태가 모두 있었다:
 *
 * 1. 정적 경로 — `bulk-*` 처럼 리소스 파라미터 자체가 없음
 * 2. 파라미터명 불일치 — 권한은 `shippingPolicy`/`coupon` 을 가리키는데 라우트는 `{id}`
 *    라 바인딩이 안 일어남. 이쪽은 **상세 경로까지** 무가드였다.
 *
 * 조사 시점 이 모듈 전체의 `checkScopeAccess`/`filterByScope` 호출은 **0건**이었다 —
 * 미들웨어가 스킵되면 어떤 2차 방어도 없었다는 뜻이다.
 *
 * 대표 경로(상세 1 + 일괄 1)를 고정한다. 나머지 서비스도 같은 trait 을 쓴다.
 */
class ServiceScopeReapplyTest extends ModuleTestCase
{
    /**
     * 지정한 스코프로 권한을 보유한 관리자를 만듭니다.
     *
     * @param  string  $identifier  권한 식별자
     * @param  string|null  $scope  스코프 유형 (self / role / null=전체)
     * @return User 생성된 관리자
     */
    private function adminWithScope(string $identifier, ?string $scope): User
    {
        $user = User::factory()->create();

        $role = Role::create([
            'identifier' => 'scoped-'.$user->id.'-'.uniqid(),
            'name' => ['ko' => '스코프 관리자', 'en' => 'Scoped Admin'],
        ]);
        $user->roles()->attach($role->id);

        foreach (['admin.access', $identifier] as $key) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $key],
                ['name' => ['ko' => $key, 'en' => $key], 'type' => PermissionType::Admin]
            );

            if ($key === $identifier) {
                // 스코프 대상 권한임을 명시 — 미들웨어가 상세 경로에서 쓰는 것과 같은 메타데이터
                $permission->update(['resource_route_key' => 'shippingPolicy', 'owner_key' => 'created_by']);
            }

            $role->permissions()->syncWithoutDetaching([
                $permission->id => ['scope_type' => $key === $identifier ? $scope : null],
            ]);
        }

        // PermissionHelper 정적 캐시 초기화 (다른 액터의 판정이 남지 않도록)
        $reflection = new \ReflectionClass(PermissionHelper::class);
        $prop = $reflection->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        return $user->fresh();
    }

    /**
     * @scenario route_shape=param_name_mismatch
     *
     * @effects detail_route_reapplies_scope_gate_when_binding_absent
     */
    public function test_self_scoped_admin_cannot_update_others_shipping_policy(): void
    {
        $actor = $this->adminWithScope('sirsoft-ecommerce.shipping-policies.update', 'self');
        $owner = User::factory()->create();
        $this->actingAs($actor);

        $policy = $this->makePolicy($owner->id);

        $this->expectException(AccessDeniedHttpException::class);

        app(ShippingPolicyService::class)->update($policy, ['name' => ['ko' => '변경', 'en' => 'Changed']]);
    }

    /**
     * (과차단 회귀) 자기 소유 대상은 그대로 수정할 수 있어야 한다.
     *
     * @scenario route_shape=param_name_mismatch
     *
     * @effects detail_route_allows_in_scope_target
     */
    public function test_self_scoped_admin_can_update_own_shipping_policy(): void
    {
        $actor = $this->adminWithScope('sirsoft-ecommerce.shipping-policies.update', 'self');
        $this->actingAs($actor);

        $policy = $this->makePolicy($actor->id);

        $updated = app(ShippingPolicyService::class)->update($policy, [
            'name' => ['ko' => '변경', 'en' => 'Changed'],
        ]);

        $this->assertNotNull($updated, '자기 소유 정책은 수정할 수 있어야 합니다');
    }

    /**
     * @scenario route_shape=static_bulk
     *
     * @effects static_bulk_route_reapplies_scope_gate
     */
    public function test_self_scoped_admin_cannot_bulk_toggle_others_extra_fee_templates(): void
    {
        $actor = $this->adminWithScope('sirsoft-ecommerce.shipping-policies.update', 'self');
        $owner = User::factory()->create();
        $this->actingAs($actor);

        $mine = $this->makeTemplate($actor->id, '63001');
        $theirs = $this->makeTemplate($owner->id, '63002');

        try {
            app(ExtraFeeTemplateService::class)->bulkToggleActive([$mine->id, $theirs->id], false);
            $this->fail('스코프 밖 대상이 섞이면 거부되어야 합니다');
        } catch (AccessDeniedHttpException) {
            // 기대 경로
        }

        // 전량 거부 — 내 것도 바뀌지 않아야 한다.
        $this->assertTrue((bool) $mine->fresh()->is_active, '거부된 일괄 요청은 아무것도 바꾸지 않아야 합니다');
        $this->assertTrue((bool) $theirs->fresh()->is_active);
    }

    /**
     * (과차단 회귀) 글로벌 스코프 액터의 일괄 작업은 종전대로 동작해야 한다.
     *
     * @scenario route_shape=static_bulk
     *
     * @effects static_bulk_route_allows_global_scoped_actor
     */
    public function test_global_scoped_admin_can_bulk_toggle_any_extra_fee_template(): void
    {
        $actor = $this->adminWithScope('sirsoft-ecommerce.shipping-policies.update', null);
        $owner = User::factory()->create();
        $this->actingAs($actor);

        $theirs = $this->makeTemplate($owner->id, '63003');

        $count = app(ExtraFeeTemplateService::class)->bulkToggleActive([$theirs->id], false);

        $this->assertSame(1, $count);
        $this->assertFalse((bool) $theirs->fresh()->is_active);
    }

    /**
     * (계약 표면) 스코프 거부는 HTTP 403 으로 표면화되어야 한다.
     *
     * 서비스가 던지는 AccessDeniedHttpException 이 컨트롤러 generic catch 에 삼켜지면
     * 권한 거부가 500 서버 장애로 위장된다 — 서비스 층 단언만으로는 이 회귀를 못 본다.
     *
     * @scenario route_shape=static_bulk
     *
     * @effects scope_denial_surfaces_as_403_over_http
     */
    public function test_scope_denied_bulk_toggle_surfaces_as_403_over_http(): void
    {
        $actor = $this->adminWithScope('sirsoft-ecommerce.shipping-policies.update', 'self');
        $owner = User::factory()->create();

        $mine = $this->makeTemplate($actor->id, '63004');
        $theirs = $this->makeTemplate($owner->id, '63005');

        $response = $this->actingAs($actor)->patchJson(
            '/api/modules/sirsoft-ecommerce/admin/extra-fee-templates/bulk-toggle-active',
            ['ids' => [$mine->id, $theirs->id], 'is_active' => false]
        );

        $response->assertStatus(403);

        // 전량 거부 — 상태 불변까지 단언한다.
        $this->assertTrue((bool) $mine->fresh()->is_active);
        $this->assertTrue((bool) $theirs->fresh()->is_active);
    }

    /**
     * (계약 복원) 존재하지 않는 대상은 스코프 거부가 아니라 not_found 400 으로 응답한다.
     *
     * 스코프 게이트 도입이 "리소스 부재 = 스코프 거부(→ 500 위장)" 로 기존 400 계약을
     * 바꾸면 안 된다.
     *
     * @scenario route_shape=param_name_mismatch
     *
     * @effects missing_target_keeps_not_found_contract
     */
    public function test_missing_brand_returns_not_found_not_scope_denied(): void
    {
        $actor = $this->adminWithScope('sirsoft-ecommerce.brands.delete', null);

        $response = $this->actingAs($actor)->deleteJson(
            '/api/modules/sirsoft-ecommerce/admin/brands/999999'
        );

        $response->assertStatus(400);
    }

    /**
     * 소유자를 지정해 배송정책을 만듭니다 (팩토리 부재 — 기존 테스트와 같은 직접 생성).
     *
     * @param  int  $ownerId  소유자 ID
     * @return ShippingPolicy 생성된 배송정책
     */
    private function makePolicy(int $ownerId): ShippingPolicy
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '정책', 'en' => 'Policy'],
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 1,
            'created_by' => $ownerId,
        ]);

        $policy->countrySettings()->create([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => 'fixed',
            'base_fee' => 3000,
        ]);

        return $policy;
    }

    /**
     * 소유자를 지정해 추가배송비 템플릿을 만듭니다.
     *
     * @param  int  $ownerId  소유자 ID
     * @param  string  $zipcode  우편번호 (유니크 충돌 회피용)
     * @return ExtraFeeTemplate 생성된 템플릿
     */
    private function makeTemplate(int $ownerId, string $zipcode): ExtraFeeTemplate
    {
        return ExtraFeeTemplate::create([
            'zipcode' => $zipcode,
            'fee' => 3000.00,
            'region' => '제주도',
            'description' => '제주도 추가배송비',
            'is_active' => true,
            'created_by' => $ownerId,
        ]);
    }
}
