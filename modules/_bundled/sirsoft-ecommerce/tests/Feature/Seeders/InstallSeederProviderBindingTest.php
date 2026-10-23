<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Seeders;

use App\Extension\Helpers\ExtensionProviderRegistrar;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Artisan;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ClaimReasonSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingCarrierSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingTypeSeeder;
use Modules\Sirsoft\Ecommerce\Providers\EcommerceServiceProvider;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderShippingRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 설치 시더 3종이 프로바이더 저장소 바인딩을 해석한다 — 코어 7.1.3 프로바이더 선등록 회귀 잠금.
 *
 * 새 설치는 부팅 뒤 같은 프로세스에서 모듈 디렉터리를 놓고 시더를 돌린다. 그때 이 모듈의 프로바이더가
 * 등록된 적이 없으면 `ShippingTypeSeeder` 정리 단계의 `OrderShippingRepositoryInterface`,
 * `ShippingCarrierSeeder`·`ClaimReasonSeeder` 의 서비스(저장소 인터페이스 주입)가 해석되지 않아 시더가
 * 실패했고, 코어는 그 실패를 로그만 남기고 설치를 성공 처리했다. 코어는 이제 설치 직전
 * `ExtensionProviderRegistrar` 로 프로바이더를 선등록한다 — 이 시험은 「프로바이더가 없는 설치 시점」을
 * 재현해 시더가 실패함을 확인한 뒤, 선등록하면 세 시더가 모두 성공함을 잠근다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/install-seeder-provider-binding.yaml
 */
class InstallSeederProviderBindingTest extends ModuleTestCase
{
    /**
     * 프로바이더가 등록되지 않은 설치 시점에는 정리 단계 저장소가 해석되지 않고, 선등록 뒤에는 세 시더가 성공한다.
     *
     * @scenario provider_state=preregistered
     *
     * @effects install_seeders_resolve_repositories_after_preregistration, install_time_without_provider_fails_resolution
     *
     * @return void 반환값 없음
     */
    public function test_install_seeders_resolve_repositories_after_provider_preregistration(): void
    {
        $this->forgetEcommerceProvider();

        try {
            app(OrderShippingRepositoryInterface::class);
            $this->fail('프로바이더가 없으면 저장소 인터페이스를 해석할 수 없어야 한다(설치 시점 재현)');
        } catch (BindingResolutionException) {
            // 설치 시점 재현 성공
        }

        $registered = ExtensionProviderRegistrar::ensureRegistered('modules', 'sirsoft-ecommerce');
        $this->assertContains(EcommerceServiceProvider::class, $registered);

        foreach ([ShippingTypeSeeder::class, ShippingCarrierSeeder::class, ClaimReasonSeeder::class] as $seeder) {
            $this->assertSame(0, Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]), $seeder.' 실패');
        }
    }

    /**
     * 이미 등록된 프로바이더는 다시 등록하지 않는다.
     *
     * @scenario provider_state=already_registered
     *
     * @effects already_registered_provider_not_reregistered
     *
     * @return void 반환값 없음
     */
    public function test_already_registered_provider_is_left_alone(): void
    {
        $this->assertSame([], ExtensionProviderRegistrar::ensureRegistered('modules', 'sirsoft-ecommerce'));
    }

    /**
     * 이커머스 프로바이더를 「등록된 적 없는」 상태로 되돌립니다 — 등록 기록과 저장소 바인딩을 지운다.
     *
     * @return void 반환값 없음
     */
    private function forgetEcommerceProvider(): void
    {
        $provider = $this->app->getProvider(EcommerceServiceProvider::class);
        $this->assertNotNull($provider);

        $repositories = (fn () => $this->repositories)->call($provider);
        foreach (array_keys($repositories) as $abstract) {
            $this->app->offsetUnset($abstract);
        }

        (function () {
            unset($this->serviceProviders[EcommerceServiceProvider::class], $this->loadedProviders[EcommerceServiceProvider::class]);
        })->call($this->app);

        $this->assertNull($this->app->getProvider(EcommerceServiceProvider::class));
    }
}
