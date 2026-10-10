<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Extension\HookManager;
use App\Models\User;
use Mockery;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderShippingRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 사용 중인 배송유형·택배사 삭제 시도의 응답 계약
 *
 * 관리자 화면은 배송설정 저장 payload 에서 항목을 빼는 방식으로 삭제한다. 그 항목이
 * 주문에서 사용 중이면 서비스가 도메인 실패를 던지는데, 컨트롤러가 그것을 generic
 * catch 로 받아 500 "설정 저장 중 오류" 로 뭉개면 운영자는 **왜** 못 지우는지 알 수 없다.
 * 인프라 장애와 구분되지 않으므로 조치 방법도 없다.
 *
 * 도메인 실패는 사유가 담긴 400 으로, 그 외 예외만 500 으로 나가야 한다.
 */
class EcommerceSettingsInUseDeletionTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-ecommerce/admin/settings';

    private User $adminUser;

    /**
     * 설정 읽기·수정 권한을 가진 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
        ]);
    }

    /**
     * 대체한 Mockery 객체를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 주문에서 사용 중인 것으로 보이도록 주문배송 Repository 를 대체합니다.
     *
     * 실제 주문 그래프를 세우지 않고 "사용 중" 조건만 만든다 — 이 테스트가 고정하려는
     * 것은 사용량 집계가 아니라 그 결과가 어떤 응답이 되는가이기 때문이다.
     *
     * @param  int  $count  사용 건수
     * @return void 반환값 없음
     */
    private function pretendInUse(int $count): void
    {
        $repository = Mockery::mock(OrderShippingRepositoryInterface::class);
        $repository->shouldReceive('countByShippingType')->andReturn($count);
        $repository->shouldReceive('countByCarrierId')->andReturn($count);

        $this->app->instance(OrderShippingRepositoryInterface::class, $repository);
    }

    /**
     * 사용 중인 배송유형을 payload 에서 빼 저장하면 사유가 담긴 400 이 돌아온다.
     *
     * @scenario resource=shipping_type, error_class=domain
     *
     * @effects in_use_shipping_type_deletion_returns_400_with_reason
     *
     * @return void 반환값 없음
     */
    public function test_deleting_in_use_shipping_type_returns_400_with_reason(): void
    {
        $type = ShippingType::create([
            'code' => 'inuse_type',
            'name' => ['ko' => '사용중배송유형', 'en' => 'In-use type'],
            'category' => 'delivery',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->pretendInUse(3);

        // payload 에서 이 유형을 빼면 syncShippingTypes 가 삭제 대상으로 판정한다.
        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'shipping',
            'shipping' => [
                'types' => [],
            ],
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath(
            'message',
            trans_count('sirsoft-ecommerce::exceptions.shipping_type_in_use', [
                'name' => $type->getLocalizedName(),
                'count' => 3,
            ])
        );

        // 사유가 사라지지 않았는지 — 일반 저장 실패 문구로 대체되면 안 된다.
        $this->assertNotSame(
            __('sirsoft-ecommerce::messages.settings.save_error'),
            $response->json('message')
        );

        // 삭제는 롤백되어야 한다.
        $this->assertDatabaseHas($type->getTable(), ['id' => $type->id]);
    }

    /**
     * 사용 중인 택배사도 같은 계약을 따른다 (같은 저장 경로의 형제 결함).
     *
     * @scenario resource=shipping_carrier, error_class=domain
     *
     * @effects in_use_shipping_carrier_deletion_returns_400_with_reason
     *
     * @return void 반환값 없음
     */
    public function test_deleting_in_use_shipping_carrier_returns_400_with_reason(): void
    {
        $carrier = ShippingCarrier::create([
            'code' => 'inuse_carrier',
            'name' => ['ko' => '사용중택배사', 'en' => 'In-use carrier'],
            'type' => 'domestic',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->pretendInUse(2);

        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [],
            ],
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath(
            'message',
            trans_count('sirsoft-ecommerce::exceptions.shipping_carrier_in_use', ['count' => 2])
        );
        $this->assertNotSame(
            __('sirsoft-ecommerce::messages.settings.save_error'),
            $response->json('message')
        );

        $this->assertDatabaseHas($carrier->getTable(), ['id' => $carrier->id]);
    }

    /**
     * 사용 중인 배송유형 삭제가 거절될 때 **배송 스칼라가 파일에 남지 않는다** (S10 13차).
     *
     * 종전엔 삭제 가드가 `syncShippingTypes()` 안(=`saveSettings()` 뒤)에만 있어, 400 으로
     * 거절되면서도 배송 스칼라는 이미 JSON 파일에 기록된 반쪽 상태를 남겼다. 설정 저장은 파일
     * 쓰기라 트랜잭션으로 되돌릴 수 없다 — 그래서 가드를 파일 쓰기 앞으로 옮겼다.
     *
     * @scenario resource=shipping_type, error_class=domain
     *
     * @effects in_use_shipping_type_deletion_returns_400_with_reason
     *
     * @return void 반환값 없음
     */
    public function test_rejected_in_use_shipping_type_deletion_leaves_the_settings_file_untouched(): void
    {
        ShippingType::create([
            'code' => 's13_inuse_type',
            'name' => ['ko' => '사용중배송유형', 'en' => 'In-use type'],
            'category' => 'delivery',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->pretendInUse(3);

        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();
        $before = $settings->getSetting('shipping.free_shipping_threshold');

        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'shipping',
            'shipping' => [
                'free_shipping_threshold' => 777777,
                'types' => [],
            ],
        ])->assertStatus(400);

        $settings->clearCache();
        $this->assertSame(
            $before,
            $settings->getSetting('shipping.free_shipping_threshold'),
            '거절된 저장이 배송 스칼라를 파일에 남겼다 — 가드가 파일 쓰기 뒤에 있다'
        );
    }

    /**
     * 사용 중인 택배사 쪽도 같은 계약을 따른다 (형제 축).
     *
     * @scenario resource=shipping_carrier, error_class=domain
     *
     * @effects in_use_shipping_carrier_deletion_returns_400_with_reason
     *
     * @return void 반환값 없음
     */
    public function test_rejected_in_use_carrier_deletion_leaves_the_settings_file_untouched(): void
    {
        ShippingCarrier::create([
            'code' => 's13_inuse_carrier',
            'name' => ['ko' => '사용중택배사', 'en' => 'In-use carrier'],
            'type' => 'domestic',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->pretendInUse(2);

        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();
        $before = $settings->getSetting('shipping.free_shipping_threshold');

        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'shipping',
            'shipping' => [
                'free_shipping_threshold' => 888888,
                'carriers' => [],
            ],
        ])->assertStatus(400);

        $settings->clearCache();
        $this->assertSame(
            $before,
            $settings->getSetting('shipping.free_shipping_threshold'),
            '거절된 저장이 배송 스칼라를 파일에 남겼다 — 가드가 파일 쓰기 뒤에 있다'
        );
    }

    /**
     * 카탈로그만 담긴 배송 payload 가 shipping.json 스칼라 전축을 지우지 않는다 (S10 13차).
     *
     * `carriers`/`types` 는 DB 관리 대상이라 컨트롤러가 JSON 저장에서 빼는데, 그러고 나면
     * `shipping` 섹션이 빈 배열이 된다. 그대로 넘기면 파일이 `[]` 로 덮여 운영자가 저장해 둔
     * 스칼라 전축이 사라지고 다음 조회에서 기본값으로 되살아난다 — 오류도 로그도 없이
     * "저장됨" 토스트만 뜬다. 클레임 섹션이 같은 이유로 이미 갖고 있던 가드의 형제 축이다.
     *
     * @scenario resource=shipping_carrier, error_class=none
     *
     * @effects settings_shipping_section_with_only_catalogs_does_not_wipe_scalars
     *
     * @return void 반환값 없음
     */
    public function test_shipping_payload_with_only_catalogs_does_not_wipe_scalar_settings(): void
    {
        $settings = app(EcommerceSettingsService::class);

        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'shipping',
            'shipping' => ['free_shipping_threshold' => 55555],
        ])->assertOk();

        $settings->clearCache();
        $this->assertSame(55555, $settings->getSetting('shipping.free_shipping_threshold'));

        // 카탈로그만 담긴 저장 — 컨트롤러가 carriers 를 빼면 shipping 섹션이 비어 버린다.
        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'shipping',
            'shipping' => ['carriers' => []],
        ])->assertOk();

        $settings->clearCache();
        $this->assertSame(
            55555,
            $settings->getSetting('shipping.free_shipping_threshold'),
            '카탈로그만 담긴 저장이 배송 스칼라를 지웠다 (센티널 free_shipping_threshold — 실패 모드가 섹션 통째 대치라 1축이면 드러난다)'
        );
    }

    /**
     * 카탈로그가 `null` 로 도착해도 배송 스칼라 전축이 살아남는다 (S10 14차).
     *
     * 규칙이 `nullable` 이라 `carriers`/`types` 는 빈 배열뿐 아니라 `null` 로도 도착한다
     * (폼 인코딩의 빈 값도 ConvertEmptyStringsToNull 로 null 이 된다). 종전 컨트롤러는
     * `isset()` 으로 카탈로그를 떼어내 null 을 걸러내지 못했고, 그 결과 ① 죽은 키가
     * shipping.json 에 남고 ② 섹션이 `[]` 가 아니게 되어 빈 섹션 가드가 발화하지 않아
     * 스칼라 전축이 지워졌다. 형제 케이스(빈 배열)만으로는 이 변종이 드러나지 않는다 —
     * 그쪽은 `isset()` 이 참이라 정상 경로를 탄다.
     *
     * @scenario resource=shipping_carrier, error_class=none
     *
     * @effects settings_shipping_null_catalog_does_not_wipe_scalars
     *
     * @return void 반환값 없음
     */
    public function test_shipping_payload_with_a_null_carrier_catalog_does_not_wipe_scalar_settings(): void
    {
        $settings = app(EcommerceSettingsService::class);

        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'shipping',
            'shipping' => ['free_shipping_threshold' => 55555],
        ])->assertOk();

        $settings->clearCache();
        $this->assertSame(55555, $settings->getSetting('shipping.free_shipping_threshold'));

        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'shipping',
            'shipping' => ['carriers' => null],
        ])->assertOk();

        $settings->clearCache();
        $this->assertSame(
            55555,
            $settings->getSetting('shipping.free_shipping_threshold'),
            'null 카탈로그가 담긴 저장이 배송 스칼라를 지웠다 (센티널 free_shipping_threshold — 실패 모드가 섹션 통째 대치라 1축이면 드러난다)'
        );
        $this->assertNull(
            $settings->getSetting('shipping.carriers'),
            'DB 가 단일 출처인 카탈로그와 같은 이름의 죽은 키가 shipping.json 에 남았다'
        );
    }

    /**
     * 배송유형 카탈로그도 `null` 변종에서 같은 보호를 받는다 (형제 축 대칭).
     *
     * 두 카탈로그는 컨트롤러에서 같은 판정을 공유하지만, 한쪽만 단언하면 그 공유가
     * 깨져도 드러나지 않는다.
     *
     * @scenario resource=shipping_type, error_class=none
     *
     * @effects settings_shipping_null_catalog_does_not_wipe_scalars
     *
     * @return void 반환값 없음
     */
    public function test_shipping_payload_with_a_null_type_catalog_does_not_wipe_scalar_settings(): void
    {
        $settings = app(EcommerceSettingsService::class);

        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'shipping',
            'shipping' => ['free_shipping_threshold' => 55555],
        ])->assertOk();

        $settings->clearCache();
        $this->assertSame(55555, $settings->getSetting('shipping.free_shipping_threshold'));

        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'shipping',
            'shipping' => ['types' => null],
        ])->assertOk();

        $settings->clearCache();
        $this->assertSame(
            55555,
            $settings->getSetting('shipping.free_shipping_threshold'),
            'null 카탈로그가 담긴 저장이 배송 스칼라를 지웠다 (센티널 free_shipping_threshold — 실패 모드가 섹션 통째 대치라 1축이면 드러난다)'
        );
        $this->assertNull(
            $settings->getSetting('shipping.types'),
            'DB 가 단일 출처인 카탈로그와 같은 이름의 죽은 키가 shipping.json 에 남았다'
        );
    }

    /**
     * 도메인 실패가 아닌 예외는 그대로 500 이어야 한다 (구분의 반대편).
     *
     * @scenario resource=shipping_type, error_class=infrastructure
     *
     * @effects settings_save_infrastructure_exception_returns_500
     *
     * @return void 반환값 없음
     */
    public function test_infrastructure_exception_on_settings_save_returns_500(): void
    {
        $repository = Mockery::mock(OrderShippingRepositoryInterface::class);
        $repository->shouldReceive('countByShippingType')
            ->andThrow(new \RuntimeException('SQLSTATE[HY000]: connection lost'));
        $this->app->instance(OrderShippingRepositoryInterface::class, $repository);

        ShippingType::create([
            'code' => 'infra_type',
            'name' => ['ko' => '인프라', 'en' => 'Infra'],
            'category' => 'delivery',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'shipping',
            'shipping' => [
                'types' => [],
            ],
        ]);

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', (string) $response->json('message'));
    }

    /**
     * 택배사 경로의 인프라 예외도 같은 구분을 따른다.
     *
     * 배송유형과 택배사는 사용량을 각각 `countByShippingType`·`countByCarrierId` 로 세므로
     * 호출 지점이 다르다. 한쪽만 단언하면 나머지 경로의 뭉개기가 그대로 남는다.
     *
     * @scenario resource=shipping_carrier, error_class=infrastructure
     *
     * @effects settings_save_carrier_infrastructure_exception_returns_500
     *
     * @return void 반환값 없음
     */
    public function test_infrastructure_exception_on_carrier_settings_save_returns_500(): void
    {
        $repository = Mockery::mock(OrderShippingRepositoryInterface::class);
        $repository->shouldReceive('countByCarrierId')
            ->andThrow(new \RuntimeException('SQLSTATE[HY000]: connection lost'));
        $this->app->instance(OrderShippingRepositoryInterface::class, $repository);

        ShippingCarrier::create([
            'code' => 'infra_carrier',
            'name' => ['ko' => '인프라택배사', 'en' => 'Infra carrier'],
            'type' => 'domestic',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [],
            ],
        ]);

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', (string) $response->json('message'));
    }

    /**
     * 카탈로그만 담긴 저장도 활동로그에 그 섹션을 남깁니다.
     *
     * 위 두 가드가 카탈로그만 담긴 섹션을 **저장 대상에서 제거**하므로, 저장 훅에 넘기는 목록을
     * `array_keys($settings)` 로만 만들면 배송사·배송유형·사유 카탈로그를 통째로 갈아엎은 저장이
     * 감사 로그에 "저장한 카테고리 없음" 으로 남는다. 응답은 200 이고 DB 는 정상 반영되므로
     * 화면에도 로그에도 어긋남이 드러나지 않는다 — 그래서 훅 페이로드가 유일한 통로다.
     *
     * @scenario resource=shipping_carrier, error_class=none
     *
     * @effects settings_catalog_only_save_reports_its_section_to_the_save_hook
     *
     * @return void 반환값 없음
     */
    public function test_catalog_only_save_reports_its_section_to_the_save_hook(): void
    {
        $captured = [];

        HookManager::addAction(
            'sirsoft-ecommerce.settings.after_save',
            function (array $categories) use (&$captured): void {
                $captured = $categories;
            }
        );

        $carrier = ShippingCarrier::create([
            'code' => 's15_hook_carrier',
            'name' => ['ko' => '훅 배송사'],
            'type' => 'domestic',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [[
                    'id' => $carrier->id,
                    'code' => 's15_hook_carrier',
                    'name' => ['ko' => '이름을 바꾼 배송사'],
                    'type' => 'domestic',
                    'is_active' => true,
                ]],
            ],
        ])->assertOk();

        $this->assertContains(
            'shipping',
            $captured,
            '배송 카탈로그를 갈아엎은 저장이 활동로그에 "저장한 카테고리 없음" 으로 남는다'
        );
    }
}
