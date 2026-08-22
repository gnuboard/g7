<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingTypeSeeder;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 배송정책 목록 페이로드 프루닝 (#518 / 공개 #76)
 *
 * 종전에는 정책마다 국가별 설정이 **전체 컬럼**으로 실렸다. 목록이 그리지도 않는
 * `extra_fee_settings`(도서산간 지역 배열)·`api_config`·`api_request_fields`·`ranges` 가
 * 정책당 국가 수만큼 곱해져 응답 대부분을 차지했다.
 *
 * 이 목록의 소비자 둘(배송정책 관리 목록, 상품 등록/수정의 배송정책 선택기)은 국가 칩·
 * 배송방법·부과정책·배송비·활성여부만 그린다. 그래서 관계를 **없애는** 대신 그 필드만 남긴다.
 *
 * 앞선 두 번의 잘못된 판정을 회귀로 고정한다:
 *   1. "관리 목록은 국가별 설정을 안 쓴다" → 데이터그리드 파셜이 국가 칩·요약·설정 목록을
 *      그리고 있어 열이 조용히 비었다. 화면 소비 여부는 파셜까지 열어야 확인된다.
 *   2. 관계를 opt-in 으로 돌리자 화면이 그걸 되켜서 감소량이 0 이 됐고, 활성만 싣는 바람에
 *      "비활성" 배지가 영영 뜨지 않게 됐다.
 */
class ShippingPolicyListPayloadTest extends ModuleTestCase
{
    protected User $adminUser;

    protected string $apiBase = '/api/modules/sirsoft-ecommerce/admin/shipping-policies';

    /** 목록 표현에서 빠져야 하는 상세 전용 컬럼 */
    private const DETAIL_ONLY_KEYS = [
        'ranges',
        'api_endpoint',
        'api_request_fields',
        'api_response_fee_field',
        'api_config',
        'extra_fee_settings',
        'extra_fee_multiply',
        'custom_shipping_name',
    ];

    /** 목록 화면이 실제로 그리는 국가별 설정 필드 */
    private const LIST_KEYS = [
        'country_code',
        'shipping_method',
        'shipping_method_label',
        'charge_policy',
        'charge_policy_label',
        'base_fee',
        'currency_code',
        'free_threshold',
        'extra_fee_enabled',
        'is_active',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShippingTypeSeeder::class);
        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.shipping-policies.read',
        ]);
    }

    /**
     * 국가별 설정을 가진 배송정책을 만듭니다.
     *
     * @param  string  $name  정책 이름
     * @param  array<int, string>  $countryCodes  활성 국가 코드
     * @param  array<int, string>  $inactiveCodes  비활성 국가 코드
     * @return ShippingPolicy 생성된 정책
     */
    private function makePolicy(string $name, array $countryCodes, array $inactiveCodes = []): ShippingPolicy
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => $name, 'en' => $name],
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 1,
        ]);

        foreach ([[$countryCodes, true], [$inactiveCodes, false]] as [$codes, $isActive]) {
            foreach ($codes as $code) {
                $policy->countrySettings()->create([
                    'country_code' => $code,
                    'shipping_method' => 'parcel',
                    'currency_code' => 'KRW',
                    'charge_policy' => 'fixed',
                    'base_fee' => 3000,
                    'is_active' => $isActive,
                    // 목록에서 빠져야 할 무거운 중첩 JSON
                    'extra_fee_enabled' => true,
                    'extra_fee_settings' => [
                        ['region' => '제주', 'fee' => 3000],
                        ['region' => '울릉도', 'fee' => 5000],
                    ],
                    'ranges' => ['tiers' => [['min' => 0, 'max' => 10, 'fee' => 2500, 'unit' => 'kg']]],
                ]);
            }
        }

        return $policy;
    }

    #[Test]
    /**
     * @scenario endpoint=admin_list,opt_in=default
     * @effects list_omits_nested_country_setting_blocks, list_keeps_fields_the_screen_draws
     */
    public function test_list_carries_only_the_fields_the_screen_draws(): void
    {
        $this->makePolicy('정책A', ['KR', 'US']);

        $row = $this->actingAs($this->adminUser)
            ->getJson($this->apiBase)
            ->assertOk()
            ->json('data.data.0');

        $this->assertArrayHasKey(
            'country_settings',
            $row,
            '목록 데이터그리드가 국가 칩과 국가별 설정 목록을 그린다 — 빼면 열이 빈다'
        );

        $setting = $row['country_settings'][0];

        foreach (self::LIST_KEYS as $key) {
            $this->assertArrayHasKey($key, $setting, "목록이 그리는 필드 {$key} 가 빠졌다");
        }

        foreach (self::DETAIL_ONLY_KEYS as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $setting,
                "{$key} 는 목록이 그리지 않는다 — 정책당 국가 수만큼 곱해져 응답에 실리면 안 된다"
            );
        }
    }

    #[Test]
    /**
     * @scenario endpoint=admin_list,opt_in=default
     * @effects list_keeps_fields_the_screen_draws
     */
    public function test_list_still_computes_summaries_without_opt_in(): void
    {
        // 요약 2필드는 목록이 열로 그린다. 관계를 조건부로 두면 열이 비고, 관계 없이 계산하면
        // 행마다 재조회(N+1)가 된다 — 경량 관계를 항상 싣는 이유다.
        $this->makePolicy('정책A', ['KR', 'US']);

        $row = $this->actingAs($this->adminUser)
            ->getJson($this->apiBase)
            ->assertOk()
            ->json('data.data.0');

        $this->assertNotSame('', $row['fee_summary'], '배송비 요약 열이 비면 안 된다');
        $this->assertNotSame('', $row['countries_display'], '국가 표시 열이 비면 안 된다');
    }

    #[Test]
    /**
     * @scenario endpoint=admin_list,opt_in=default
     * @effects list_includes_inactive_country_settings
     */
    public function test_list_includes_inactive_country_settings(): void
    {
        // 목록은 비활성 국가 설정에 "비활성" 배지를 그린다(`{{!cs.is_active}}`).
        // 활성만 실으면 그 배지가 영영 뜨지 않는다.
        $this->makePolicy('정책A', ['KR'], ['US']);

        $row = $this->actingAs($this->adminUser)
            ->getJson($this->apiBase)
            ->assertOk()
            ->json('data.data.0');

        $flags = collect($row['country_settings'])->pluck('is_active')->sort()->values()->all();

        $this->assertSame([false, true], $flags, '비활성 국가 설정도 목록에 실려야 배지를 그릴 수 있다');
    }

    #[Test]
    /**
     * @scenario endpoint=admin_list,opt_in=default
     * @effects summaries_count_only_active_settings_regardless_of_load_path
     */
    public function test_summaries_count_only_active_settings_regardless_of_load_path(): void
    {
        // 목록은 비활성까지 싣지만 요약은 활성만 센다. 로더의 필터에 요약의 정확성을 맡기면
        // 같은 정책의 요약이 조회 경로(목록/상세/opt-in)에 따라 달라진다.
        $policy = $this->makePolicy('정책A', ['KR'], ['US', 'CN']);

        $row = $this->actingAs($this->adminUser)
            ->getJson($this->apiBase)
            ->assertOk()
            ->json('data.data.0');

        $this->assertSame(
            $policy->fresh()->getCountriesWithFlags(),
            $row['countries_display'],
            '로드된 컬렉션으로 계산한 값과 조회로 계산한 값이 같아야 한다'
        );
        $this->assertStringNotContainsString('+2', $row['countries_display'], '비활성 2건이 요약에 세어지면 안 된다');
    }

    #[Test]
    /**
     * @scenario endpoint=admin_list,opt_in=with_country_settings
     * @effects opt_in_restores_full_country_settings
     */
    public function test_opt_in_restores_full_country_settings(): void
    {
        // 외부 연동 하위호환 — 전체 컬럼이 필요한 호출자는 종전 응답을 그대로 받는다.
        $this->makePolicy('정책A', ['KR', 'US']);

        $row = $this->actingAs($this->adminUser)
            ->getJson($this->apiBase.'?with_country_settings=true')
            ->assertOk()
            ->json('data.data.0');

        $this->assertCount(2, $row['country_settings']);
        $this->assertArrayHasKey('extra_fee_settings', $row['country_settings'][0]);
        $this->assertArrayHasKey('ranges', $row['country_settings'][0]);
    }

    #[Test]
    /**
     * @scenario endpoint=admin_list,opt_in=with_country_settings
     * @effects opt_in_accepts_query_string_boolean_forms
     */
    public function test_opt_in_accepts_query_string_boolean_forms(): void
    {
        $this->makePolicy('정책A', ['KR']);

        foreach (['true', '1'] as $truthy) {
            $setting = $this->actingAs($this->adminUser)
                ->getJson($this->apiBase.'?with_country_settings='.$truthy)
                ->assertOk()
                ->json('data.data.0.country_settings.0');

            $this->assertArrayHasKey('api_config', $setting, "with_country_settings={$truthy} 가 참으로 해석되어야 한다");
        }

        foreach (['false', '0'] as $falsy) {
            $setting = $this->actingAs($this->adminUser)
                ->getJson($this->apiBase.'?with_country_settings='.$falsy)
                ->assertOk()
                ->json('data.data.0.country_settings.0');

            $this->assertArrayNotHasKey('api_config', $setting, "with_country_settings={$falsy} 는 거짓이어야 한다");
        }

        // 해석 불가한 값은 통과시키지 않는다 (오타가 "지정 안 함" 으로 조용히 넘어가면 안 된다)
        $this->actingAs($this->adminUser)
            ->getJson($this->apiBase.'?with_country_settings=banana')
            ->assertStatus(422);
    }

    #[Test]
    /**
     * @scenario endpoint=admin_list,opt_in=default
     * @effects country_setting_queries_do_not_grow_with_rows
     */
    public function test_default_list_country_setting_queries_do_not_grow_with_rows(): void
    {
        $this->assertCountrySettingQueriesAreConstant($this->apiBase);
    }

    #[Test]
    /**
     * @scenario endpoint=active_list,opt_in=default
     * @effects country_setting_queries_do_not_grow_with_rows
     */
    public function test_active_list_country_setting_queries_do_not_grow_with_rows(): void
    {
        // `activeList` 는 정책마다 getCountriesWithFlags()/getFeeSummary() 를 호출한다.
        // 관계를 싣지 않으면 두 메서드가 행마다 재조회해 정책 수 × 2 쿼리가 된다 —
        // 페이로드를 줄이려다 쿼리를 늘리는 맞바꿈이 실제로 한 번 발생했다.
        $this->assertCountrySettingQueriesAreConstant($this->apiBase.'/active');
    }

    /**
     * 국가별 설정 조회 쿼리 수가 정책 수에 비례하지 않음을 단언합니다.
     *
     * @param  string  $url  측정할 엔드포인트
     * @return void
     */
    private function assertCountrySettingQueriesAreConstant(string $url): void
    {
        $this->makePolicy('A', ['KR', 'US']);

        // 권한/설정 캐시를 채워 측정에서 제외
        $this->actingAs($this->adminUser)->getJson($url);

        $measure = function () use ($url): int {
            $count = 0;
            DB::listen(function ($query) use (&$count) {
                if (str_contains($query->sql, 'shipping_policy_country_settings')) {
                    $count++;
                }
            });

            $this->actingAs($this->adminUser)->getJson($url)->assertOk();

            return $count;
        };

        $withOne = $measure();

        foreach (['B', 'C', 'D', 'E'] as $name) {
            $this->makePolicy($name, ['KR', 'US']);
        }

        $withFive = $measure();

        $this->assertSame(
            $withOne,
            $withFive,
            "{$url} — 정책 1건일 때 {$withOne}회, 5건일 때 {$withFive}회. 행 수에 비례하면 요약 메서드가 관계를 재조회하고 있다"
        );
    }
}
