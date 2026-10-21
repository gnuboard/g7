<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Services;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Listeners\ShippingPolicyCacheListener;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\ShippingPolicyResolver;
use Modules\Sirsoft\Ecommerce\Services\ShippingPolicyService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송정책 변경 후 해석기 캐시 무효화 검증
 *
 * `ShippingPolicyResolver` 는 싱글톤이라 기본 배송정책을 요청 내 1회만 조회한다.
 * 무효화 경로가 없으면 배송정책을 바꾼 요청이 같은 요청 안에서 변경 전 정책을 계속 쓴다
 * — 예외도 오류도 남지 않고 배송비만 이전 값으로 계산된다.
 */
class ShippingPolicyResolverCacheTest extends ModuleTestCase
{
    /**
     * 배송정책을 생성합니다.
     *
     * @param  bool  $isDefault  기본 배송정책 여부
     * @param  int  $baseFee  기본 배송비
     * @return ShippingPolicy 생성된 배송정책
     */
    private function makePolicy(bool $isDefault, int $baseFee = 600): ShippingPolicy
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '배송정책 '.$baseFee, 'en' => 'Policy '.$baseFee],
            'is_default' => $isDefault,
            'is_active' => true,
        ]);

        $policy->countrySettings()->create([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => ChargePolicyEnum::FIXED,
            'base_fee' => $baseFee,
            'is_active' => true,
        ]);

        return $policy->load('countrySettings');
    }

    /**
     * 기본 배송정책은 요청 내 1회만 조회됩니다 (캐시가 살아 있는지 확인).
     *
     * @scenario mutation=none
     *
     * @effects default_policy_cached_within_request
     *
     * @return void 반환값 없음
     */
    public function test_default_policy_is_cached_within_request(): void
    {
        $policy = $this->makePolicy(isDefault: true);

        $resolver = app(ShippingPolicyResolver::class);

        $first = $resolver->getDefaultPolicy();
        $this->assertNotNull($first);
        $this->assertSame($policy->id, $first->id);

        // 캐시가 없으면 이 단언이 새 기본정책을 집어 캐시 부재를 드러낸다
        $this->makePolicy(isDefault: false);

        $this->assertSame($first, $resolver->getDefaultPolicy(), '요청 내 재조회가 발생했습니다.');
    }

    /**
     * `flushCache()` 후에는 저장소를 다시 조회합니다. (실패-먼저)
     *
     * @scenario mutation=manual_flush
     *
     * @effects resolver_cache_flushed
     *
     * @return void 반환값 없음
     */
    public function test_flush_cache_forces_refetch(): void
    {
        $old = $this->makePolicy(isDefault: true, baseFee: 600);

        $resolver = app(ShippingPolicyResolver::class);
        $this->assertSame($old->id, $resolver->getDefaultPolicy()?->id);

        $old->update(['is_default' => false]);
        $new = $this->makePolicy(isDefault: true, baseFee: 900);

        $resolver->flushCache();

        $this->assertSame($new->id, $resolver->getDefaultPolicy()?->id, 'flushCache() 후에도 이전 정책이 반환됩니다.');
    }

    /**
     * 기본 배송정책 지정을 바꾸면 같은 요청 안에서 해석기가 새 정책을 반환합니다. (실패-먼저)
     *
     * @scenario mutation=set_default
     *
     * @effects resolver_cache_flushed
     *
     * @return void 반환값 없음
     */
    public function test_set_default_invalidates_resolver_cache(): void
    {
        $old = $this->makePolicy(isDefault: true, baseFee: 600);
        $candidate = $this->makePolicy(isDefault: false, baseFee: 900);

        $resolver = app(ShippingPolicyResolver::class);
        $this->assertSame($old->id, $resolver->getDefaultPolicy()?->id);

        app(ShippingPolicyService::class)->setDefault($candidate);

        $this->assertSame(
            $candidate->id,
            $resolver->getDefaultPolicy()?->id,
            '기본 배송정책을 바꿨는데 해석기가 이전 정책을 반환합니다.'
        );
    }

    /**
     * 기본 배송정책 수정도 같은 요청 안에서 반영됩니다. (실패-먼저)
     *
     * @scenario mutation=update
     *
     * @effects resolver_cache_flushed
     *
     * @return void 반환값 없음
     */
    public function test_update_invalidates_resolver_cache(): void
    {
        $policy = $this->makePolicy(isDefault: true, baseFee: 600);

        $resolver = app(ShippingPolicyResolver::class);
        $this->assertSame(
            600,
            (int) $resolver->getDefaultPolicy()?->getCountrySetting('KR')?->base_fee
        );

        app(ShippingPolicyService::class)->update($policy, [
            'name' => ['ko' => '배송정책', 'en' => 'Policy'],
            'is_default' => true,
            'is_active' => true,
            'country_settings' => [[
                'country_code' => 'KR',
                'shipping_method' => 'parcel',
                'currency_code' => 'KRW',
                'charge_policy' => ChargePolicyEnum::FIXED->value,
                'base_fee' => 1500,
                'is_active' => true,
            ]],
        ]);

        $this->assertSame(
            1500,
            (int) $resolver->getDefaultPolicy()?->getCountrySetting('KR')?->base_fee,
            '배송정책을 수정했는데 해석기가 이전 배송비를 반환합니다.'
        );
    }

    /**
     * 기본 배송정책 삭제도 같은 요청 안에서 반영됩니다. (실패-먼저)
     *
     * @scenario mutation=delete
     *
     * @effects resolver_cache_flushed
     *
     * @return void 반환값 없음
     */
    public function test_delete_invalidates_resolver_cache(): void
    {
        $policy = $this->makePolicy(isDefault: true);

        $resolver = app(ShippingPolicyResolver::class);
        $this->assertNotNull($resolver->getDefaultPolicy());

        app(ShippingPolicyService::class)->delete($policy);

        $this->assertNull($resolver->getDefaultPolicy(), '삭제된 배송정책이 계속 반환됩니다.');
    }

    /**
     * 리스너가 배송정책 변경 훅 전부를 구독합니다.
     *
     * 변경 경로가 늘어나도 같은 훅만 발화하면 무효화가 따라오도록, 구독 목록을 고정한다.
     *
     * @scenario mutation=hook_subscription
     *
     * @effects resolver_cache_flushed
     *
     * @return void 반환값 없음
     */
    public function test_listener_subscribes_every_mutation_hook(): void
    {
        $hooks = array_keys(ShippingPolicyCacheListener::getSubscribedHooks());

        foreach ([
            'after_create',
            'after_update',
            'after_delete',
            'after_bulk_delete',
            'after_toggle_active',
            'after_bulk_toggle_active',
            'after_set_default',
        ] as $event) {
            $this->assertContains('sirsoft-ecommerce.shipping_policy.'.$event, $hooks);
        }
    }

    /**
     * 배송정책 변경 훅 7개가 모두 동기(sync) 실행으로 선언돼 있습니다. (실패-먼저)
     *
     * 요청 안 메모리(해석기 싱글톤)를 비우는 리스너는 그 요청 안에서 돌아야 한다 — 큐로 가면 워커 프로세스의
     * 사본만 비우고 웹 요청 쪽 해석기는 옛 기본 배송정책을 계속 돌려준다.
     *
     * @scenario mutation=hook_subscription
     *
     * @effects resolver_flushed_in_request
     *
     * @return void 반환값 없음
     */
    public function test_every_mutation_hook_is_synchronous(): void
    {
        foreach (ShippingPolicyCacheListener::getSubscribedHooks() as $hook => $config) {
            $this->assertTrue(($config['sync'] ?? false) === true, "{$hook} 가 sync 가 아닙니다 — 큐로 가면 웹 요청의 해석기 캐시가 비워지지 않습니다.");
        }
    }

    /**
     * 큐 드라이버가 database 여도 배송정책을 바꾼 같은 요청에서 해석기가 새 값을 돌려줍니다. (실패-먼저)
     *
     * 워커를 돌리지 않는다 — 운영에서 웹 요청과 워커는 다른 프로세스라, 워커가 비우는 것은 워커의 해석기다.
     * 큐 작업 목록에 이 리스너가 하나도 없어야 한다.
     *
     * @scenario mutation=queued_request
     *
     * @effects resolver_flushed_in_request
     *
     * @return void 반환값 없음
     */
    public function test_resolver_is_flushed_in_request_when_queue_is_database(): void
    {
        config(['queue.default' => 'database']);

        $service = app(ShippingPolicyService::class);
        $resolver = app(ShippingPolicyResolver::class);

        $old = $this->makePolicy(isDefault: true, baseFee: 600);
        $candidate = $this->makePolicy(isDefault: false, baseFee: 900);

        // setDefault
        $this->assertSame($old->id, $resolver->getDefaultPolicy()?->id);
        $service->setDefault($candidate);
        $this->assertSame($candidate->id, $resolver->getDefaultPolicy()?->id, 'setDefault 후 같은 요청에서 옛 기본 배송정책이 반환됩니다.');

        // update
        $service->update($candidate->fresh(), [
            'name' => ['ko' => '배송정책', 'en' => 'Policy'],
            'is_default' => true,
            'is_active' => true,
            'country_settings' => [[
                'country_code' => 'KR',
                'shipping_method' => 'parcel',
                'currency_code' => 'KRW',
                'charge_policy' => ChargePolicyEnum::FIXED->value,
                'base_fee' => 1500,
                'is_active' => true,
            ]],
        ]);
        $this->assertSame(1500, (int) $resolver->getDefaultPolicy()?->getCountrySetting('KR')?->base_fee, 'update 후 같은 요청에서 옛 배송비가 반환됩니다.');

        // toggleActive
        $this->assertTrue((bool) $resolver->getDefaultPolicy()?->is_active);
        $service->toggleActive($candidate->fresh());
        $this->assertFalse((bool) $resolver->getDefaultPolicy()?->is_active, 'toggleActive 후 같은 요청에서 옛 사용여부가 반환됩니다.');

        // bulkToggleActive
        $service->bulkToggleActive([$candidate->id], true);
        $this->assertTrue((bool) $resolver->getDefaultPolicy()?->is_active, 'bulkToggleActive 후 같은 요청에서 옛 사용여부가 반환됩니다.');

        // delete
        $service->delete($candidate->fresh());
        $this->assertNull($resolver->getDefaultPolicy(), 'delete 후 같은 요청에서 삭제된 배송정책이 반환됩니다.');

        $queued = DB::table('jobs')->where('payload', 'like', '%ShippingPolicyCacheListener%')->count();
        $this->assertSame(0, $queued, '해석기 캐시 비우기가 큐 작업으로 쌓였습니다 — 워커의 사본만 비웁니다.');
    }
}
