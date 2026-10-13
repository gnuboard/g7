<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Providers;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Mockery;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;
use Modules\Sirsoft\Ecommerce\Services\ShippingPolicyResolver;
use Modules\Sirsoft\Ecommerce\Support\CurrencySettingsCache;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 이커머스 요청 단위 메모 서비스의 수명 — 큐 작업마다 새로 만들어진다
 *
 * 라라벨 큐 워커는 작업 사이에 scoped 인스턴스만 비우고 싱글톤은 프로세스가 끝날 때까지 살린다. 기본 배송정책·
 * 결제수단·통화·설정을 한 번 읽어 들고 있는 서비스가 싱글톤이면, 오래 떠 있는 워커는 재시작 전까지 옛 값을 쓴다.
 * scoped 로 만들 수 없는 static 캐시(통화 설정·배송유형 코드)는 큐 작업 시작마다 비운다.
 */
class EcommerceScopedBindingsTest extends ModuleTestCase
{
    /**
     * 요청 단위로 공유하고 큐 작업마다 새로 만들어야 하는 서비스 목록.
     *
     * @return array<string, array{0: class-string}> 서비스 클래스
     */
    public static function scopedServices(): array
    {
        return [
            'currency' => [CurrencyConversionService::class],
            'shipping_policy' => [ShippingPolicyResolver::class],
            'payment_method' => [PaymentMethodResolver::class],
            'settings' => [EcommerceSettingsService::class],
        ];
    }

    /**
     * 같은 요청 안에서는 같은 인스턴스이고, 큐 작업 경계(scoped 비우기) 뒤에는 새 인스턴스다. (실패-먼저)
     *
     * @scenario mutation=scope_reset
     *
     * @effects worker_scope_reset
     *
     * @param  class-string  $service  서비스 클래스
     * @return void 반환값 없음
     */
    #[DataProvider('scopedServices')]
    public function test_service_is_shared_in_request_and_renewed_per_queue_job(string $service): void
    {
        $first = app($service);

        $this->assertSame($first, app($service), '같은 요청 안에서 인스턴스가 공유되지 않습니다 — 요청 단위 캐시가 무력해집니다.');

        // 큐 워커가 작업마다 부르는 것과 같은 메서드
        app()->forgetScopedInstances();

        $this->assertNotSame($first, app($service), '큐 작업 경계 뒤에도 같은 인스턴스입니다 — 워커가 재시작 전까지 옛 값을 씁니다.');
    }

    /**
     * 큐 작업이 시작되면 static 통화 설정·배송유형 코드 캐시가 비워진다. (실패-먼저)
     *
     * @scenario mutation=scope_reset
     *
     * @effects worker_scope_reset
     *
     * @return void 반환값 없음
     */
    public function test_static_caches_are_cleared_when_a_queue_job_starts(): void
    {
        $this->primeStaticCaches();

        event(new JobProcessing('database', $this->fakeJob()));

        $this->assertNull($this->staticValue(CurrencySettingsCache::class, 'currencies'), '큐 작업 시작 뒤에도 옛 통화 설정 캐시가 남았습니다.');
        $this->assertNull($this->staticValue(ShippingType::class, 'codeCache'), '큐 작업 시작 뒤에도 옛 배송유형 코드 캐시가 남았습니다.');
    }

    /**
     * sync 연결의 작업은 웹 요청 안에서 돌므로 요청 단위 캐시를 비우지 않는다.
     *
     * @scenario mutation=sync_connection_job
     *
     * @effects default_policy_cached_within_request
     *
     * @return void 반환값 없음
     */
    public function test_static_caches_are_kept_for_sync_connection_jobs(): void
    {
        $this->primeStaticCaches();

        event(new JobProcessing('sync', $this->fakeJob()));

        $this->assertNotNull($this->staticValue(CurrencySettingsCache::class, 'currencies'), 'sync 연결 작업이 요청 단위 통화 캐시를 비웠습니다.');
        $this->assertNotNull($this->staticValue(ShippingType::class, 'codeCache'), 'sync 연결 작업이 요청 단위 배송유형 캐시를 비웠습니다.');
    }

    /**
     * 두 static 캐시를 현재 값으로 채웁니다.
     *
     * @return void 반환값 없음
     */
    private function primeStaticCaches(): void
    {
        CurrencySettingsCache::clear();
        ShippingType::clearCodeCache();

        CurrencySettingsCache::currencies();
        ShippingType::getCachedByCode('parcel');

        $this->assertNotNull($this->staticValue(CurrencySettingsCache::class, 'currencies'));
        $this->assertNotNull($this->staticValue(ShippingType::class, 'codeCache'));
    }

    /**
     * 큐 작업 대역을 만듭니다 (라라벨 문맥 복원이 읽는 payload 만 응답).
     *
     * @return Job 큐 작업 대역
     */
    private function fakeJob(): Job
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([]);
        $job->shouldIgnoreMissing();

        return $job;
    }

    /**
     * 클래스의 static 캐시 속성 값을 읽습니다.
     *
     * @param  class-string  $class  클래스
     * @param  string  $property  속성 이름
     * @return mixed 속성 값
     */
    private function staticValue(string $class, string $property): mixed
    {
        return (new \ReflectionProperty($class, $property))->getValue();
    }
}
