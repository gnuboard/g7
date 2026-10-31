<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Listeners;

use App\Extension\HookListenerRegistrar;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Listeners\UserListAddressFilterListener;
use Modules\Sirsoft\Ecommerce\Listeners\UserListMileageFilterListener;
use Modules\Sirsoft\Ecommerce\Models\MileageBalance;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 회원관리 목록 확장 필터 예제 2종 테스트 (#705 D5 — E1 마일리지 잔액 · E2 배송지)
 *
 * 코어 회원 목록이 연 확장 필터 규약(`ext_filters[]` → `ext.{확장id}.{키}` → 검증 훅 → 쿼리 훅)을
 * 이커머스가 실제로 써서 목록을 거르는지 끝에서 끝까지 본다. 요청은 화면과 같은 형식
 * (`ext_filters[]=sirsoft-ecommerce.mileage_min=1000`)으로 보낸다.
 *
 * - 잔액은 상점 기본 통화 한 가지로만 본다. 잔액 행이 없는 회원은 0 으로 본다.
 * - 배송지 국가는 「그 나라 배송지를 하나라도」, 등록 여부 0 은 국가보다 우선한다.
 * - 조회 권한(마일리지·배송지)이 없으면 조건을 무시한다.
 */
#[Group('ecommerce')]
#[Group('admin')]
class UserListFilterExtensionTest extends ModuleTestCase
{
    private User $admin;

    private string $marker;

    /**
     * 목록·마일리지·배송지 권한을 가진 운영자와 이메일 표식을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 모듈 훅 리스너는 부팅 때 이 모듈이 활성으로 보여야 등록되는데, 모듈 등록 행은 부팅 **뒤**
        // setUp 에서 만들어진다. 코어 테스트가 먼저 돈 프로세스에서는 첫 케이스에 리스너가 없어
        // 확장 조건이 조용히 무시됐다(실측). 실제 등록 경로(HookListenerRegistrar)로 등록을 보장한다 —
        // 부팅이 이미 등록했다면 프로세스 내 중복 등록 가드가 skip 한다(선례: EcommerceSettingsSeoCacheInvalidationTest).
        HookListenerRegistrar::register(UserListMileageFilterListener::class, 'sirsoft-ecommerce');
        HookListenerRegistrar::register(UserListAddressFilterListener::class, 'sirsoft-ecommerce');

        $this->marker = '@ext'.Str::lower(Str::random(8)).'.test';
        $this->admin = $this->createAdminUser([
            'core.users.read',
            'sirsoft-ecommerce.mileage.read',
            'sirsoft-ecommerce.user-addresses.read',
        ]);
    }

    /**
     * 마일리지 하한은 잔액이 그 이상인 회원만 남긴다(행 없는 회원 = 0 은 빠진다).
     *
     * @scenario example=mileage, outcome=narrows
     *
     * @effects ecommerce_mileage_filter_narrows_user_list
     *
     * @return void 반환값 없음
     */
    public function test_mileage_min_narrows_list(): void
    {
        $rich = $this->member();
        $this->balance($rich, 5000);
        $poor = $this->member();
        $this->balance($poor, 100);
        $this->member(); // 잔액 행 없음

        $this->assertListed([$rich], ['sirsoft-ecommerce.mileage_min=1000']);
    }

    /**
     * 마일리지 상한은 넘는 회원만 빼고, 잔액 행 없는 회원(0)은 남긴다. 0 하한은 유효값이다.
     *
     * @scenario example=mileage, outcome=narrows
     *
     * @effects ecommerce_mileage_filter_narrows_user_list
     *
     * @return void 반환값 없음
     */
    public function test_mileage_max_keeps_members_without_balance_row(): void
    {
        $rich = $this->member();
        $this->balance($rich, 5000);
        $poor = $this->member();
        $this->balance($poor, 100);
        $none = $this->member();

        $this->assertListed([$poor, $none], ['sirsoft-ecommerce.mileage_max=1000']);
        $this->assertListed([$poor, $none], ['sirsoft-ecommerce.mileage_min=0', 'sirsoft-ecommerce.mileage_max=1000']);
        $this->assertListed([$poor], ['sirsoft-ecommerce.mileage_min=50', 'sirsoft-ecommerce.mileage_max=1000']);
    }

    /**
     * 상한 0 은 「빈 값」이 아니라 조건이다 — 잔액이 0 인 회원(행 0원·행 없음)만 남는다.
     *
     * @scenario example=mileage, outcome=narrows
     *
     * @effects ecommerce_mileage_filter_narrows_user_list
     *
     * @return void 반환값 없음
     */
    public function test_mileage_max_zero_is_a_real_bound(): void
    {
        $zero = $this->member();
        $this->balance($zero, 0);
        $poor = $this->member();
        $this->balance($poor, 100);
        $none = $this->member();

        $this->assertListed([$zero, $none], ['sirsoft-ecommerce.mileage_max=0']);
    }

    /**
     * 기본 통화가 아닌 통화의 잔액은 보지 않는다.
     *
     * @scenario example=mileage, outcome=narrows
     *
     * @effects ecommerce_mileage_filter_narrows_user_list
     *
     * @return void 반환값 없음
     */
    public function test_mileage_uses_default_currency_only(): void
    {
        $usdOnly = $this->member();
        $this->balance($usdOnly, 9999, 'USD');

        $this->assertListed([], ['sirsoft-ecommerce.mileage_min=1000']);
    }

    /**
     * 상한이 하한보다 작거나 숫자가 아니면 확장이 건 규칙이 422 로 거절한다.
     *
     * @scenario example=mileage, outcome=rejected
     *
     * @effects ecommerce_user_filters_reject_invalid_values
     *
     * @return void 반환값 없음
     */
    public function test_mileage_invalid_values_are_rejected(): void
    {
        $this->list(['sirsoft-ecommerce.mileage_min=5000', 'sirsoft-ecommerce.mileage_max=100'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ext.sirsoft-ecommerce.mileage_max']);

        $this->list(['sirsoft-ecommerce.mileage_min=abc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ext.sirsoft-ecommerce.mileage_min']);
    }

    /**
     * 배송지 국가는 그 나라 배송지를 하나라도 가진 회원을 남긴다(대소문자 무관).
     *
     * @scenario example=address, outcome=narrows
     *
     * @effects ecommerce_address_filter_narrows_user_list
     *
     * @return void 반환값 없음
     */
    public function test_shipping_country_narrows_list(): void
    {
        $jp = $this->member();
        $this->address($jp, 'KR');
        $this->address($jp, 'JP');
        $kr = $this->member();
        $this->address($kr, 'KR');
        $this->member();

        $this->assertListed([$jp], ['sirsoft-ecommerce.shipping_country=JP']);
        $this->assertListed([$jp], ['sirsoft-ecommerce.shipping_country=jp']);
    }

    /**
     * 배송지 등록 여부 1/0 과, 0 이 국가보다 우선하는지.
     *
     * @scenario example=address, outcome=narrows
     *
     * @effects ecommerce_address_filter_narrows_user_list
     *
     * @return void 반환값 없음
     */
    public function test_has_address_yes_no_and_precedence(): void
    {
        $with = $this->member();
        $this->address($with, 'KR');
        $without = $this->member();

        $this->assertListed([$with], ['sirsoft-ecommerce.has_address=1']);
        $this->assertListed([$without], ['sirsoft-ecommerce.has_address=0']);
        $this->assertListed([$without], ['sirsoft-ecommerce.has_address=0', 'sirsoft-ecommerce.shipping_country=KR']);
    }

    /**
     * 두 확장 필터(마일리지·배송지)를 동시에 걸면 AND 로 묶인다.
     *
     * @scenario example=mileage, outcome=narrows
     *
     * @effects ecommerce_mileage_filter_narrows_user_list, ecommerce_address_filter_narrows_user_list
     *
     * @return void 반환값 없음
     */
    public function test_two_extension_filters_combined(): void
    {
        $both = $this->member();
        $this->balance($both, 3000);
        $this->address($both, 'US');
        $mileageOnly = $this->member();
        $this->balance($mileageOnly, 3000);
        $addressOnly = $this->member();
        $this->address($addressOnly, 'US');

        $this->assertListed([$both], ['sirsoft-ecommerce.mileage_min=1000', 'sirsoft-ecommerce.shipping_country=US']);
    }

    /**
     * 확장 필터는 코어 필터와도 AND 로 묶인다.
     *
     * @scenario example=mileage, outcome=narrows
     *
     * @effects ecommerce_mileage_filter_narrows_user_list
     *
     * @return void 반환값 없음
     */
    public function test_extension_filter_combined_with_core_filter(): void
    {
        $blockedRich = $this->member(['status' => 'blocked']);
        $this->balance($blockedRich, 3000);
        $activeRich = $this->member();
        $this->balance($activeRich, 3000);

        $this->assertListed([$blockedRich], ['sirsoft-ecommerce.mileage_min=1000'], ['status' => ['blocked']]);
    }

    /**
     * 배송지 국가 형식이 어긋나면 422 다.
     *
     * @scenario example=address, outcome=rejected
     *
     * @effects ecommerce_user_filters_reject_invalid_values
     *
     * @return void 반환값 없음
     */
    public function test_shipping_country_invalid_is_rejected(): void
    {
        $this->list(['sirsoft-ecommerce.shipping_country=KOR'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ext.sirsoft-ecommerce.shipping_country']);
    }

    /**
     * 조회 권한이 없는 운영자에게는 조건이 적용되지 않는다(잔액·배송지 추정 차단).
     *
     * @scenario example=mileage, outcome=ignored_without_permission
     *
     * @effects ecommerce_user_filters_require_permission
     *
     * @return void 반환값 없음
     */
    public function test_filters_ignored_without_permission(): void
    {
        $operator = $this->createAdminUser(['core.users.read']);
        $rich = $this->member();
        $this->balance($rich, 5000);
        $poor = $this->member();

        $response = $this->actingAs($operator)->getJson('/api/admin/users?'.$this->query(['sirsoft-ecommerce.mileage_min=1000', 'sirsoft-ecommerce.has_address=1']))->assertOk();

        $this->assertSame(
            collect([$rich, $poor])->pluck('uuid')->sort()->values()->all(),
            collect($response->json('data.data'))->pluck('uuid')->sort()->values()->all()
        );
    }

    /**
     * 배송지 조회 권한이 없는 운영자에게는 배송지 조건이 적용되지 않는다.
     *
     * @scenario example=address, outcome=ignored_without_permission
     *
     * @effects ecommerce_user_filters_require_permission
     *
     * @return void 반환값 없음
     */
    public function test_address_filter_ignored_without_permission(): void
    {
        $operator = $this->createAdminUser(['core.users.read', 'sirsoft-ecommerce.mileage.read']);
        $with = $this->member();
        $this->address($with, 'JP');
        $without = $this->member();

        $response = $this->actingAs($operator)->getJson('/api/admin/users?'.$this->query(['sirsoft-ecommerce.shipping_country=JP']))->assertOk();

        $this->assertSame(
            collect([$with, $without])->pluck('uuid')->sort()->values()->all(),
            collect($response->json('data.data'))->pluck('uuid')->sort()->values()->all()
        );
    }

    /**
     * 표식을 붙인 회원을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return User 회원
     */
    private function member(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'email' => Str::lower(Str::random(10)).$this->marker,
            'status' => 'active',
        ], $attributes));
    }

    /**
     * 잔액 캐시 행을 만듭니다.
     *
     * @param  User  $user  회원
     * @param  float  $available  사용 가능 잔액
     * @param  string|null  $currency  통화 (기본 통화)
     * @return void 반환값 없음
     */
    private function balance(User $user, float $available, ?string $currency = null): void
    {
        MileageBalance::query()->create([
            'user_id' => $user->id,
            'currency' => $currency ?? app(CurrencyConversionService::class)->getDefaultCurrency(),
            'available' => $available,
        ]);
    }

    /**
     * 배송지를 만듭니다.
     *
     * @param  User  $user  회원
     * @param  string  $country  국가 코드
     * @return void 반환값 없음
     */
    private function address(User $user, string $country): void
    {
        UserAddress::query()->create([
            'user_id' => $user->id,
            'name' => 'addr-'.Str::random(4),
            'recipient_name' => 'recipient',
            'recipient_phone' => '010-0000-0000',
            'country_code' => $country,
            'is_default' => false,
        ]);
    }

    /**
     * 확장 필터 원소와 코어 조건으로 쿼리스트링을 만듭니다(표식 검색 포함).
     *
     * @param  array<int, string>  $ext  `ext_filters[]` 원소
     * @param  array<string, mixed>  $core  코어 조건
     * @return string 쿼리스트링
     */
    private function query(array $ext, array $core = []): string
    {
        return http_build_query(array_merge([
            'filters' => [['field' => 'email', 'value' => $this->marker]],
            'per_page' => 100,
            'ext_filters' => $ext,
        ], $core));
    }

    /**
     * 목록을 부릅니다.
     *
     * @param  array<int, string>  $ext  `ext_filters[]` 원소
     * @param  array<string, mixed>  $core  코어 조건
     * @return TestResponse 응답
     */
    private function list(array $ext, array $core = [])
    {
        return $this->actingAs($this->admin)->getJson('/api/admin/users?'.$this->query($ext, $core));
    }

    /**
     * 목록 결과가 기대 회원 집합과 같은지 봅니다.
     *
     * @param  array<int, User>  $expected  기대 회원
     * @param  array<int, string>  $ext  `ext_filters[]` 원소
     * @param  array<string, mixed>  $core  코어 조건
     * @return void 반환값 없음
     */
    private function assertListed(array $expected, array $ext, array $core = []): void
    {
        $response = $this->list($ext, $core)->assertOk();

        $this->assertSame(
            collect($expected)->pluck('uuid')->sort()->values()->all(),
            collect($response->json('data.data'))->pluck('uuid')->sort()->values()->all(),
            json_encode($ext)
        );
    }
}
