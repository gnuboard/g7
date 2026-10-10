<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\ShippingApiRequestField;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyCountrySetting;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 계산 API 요청 필드 정리(DataMigration) 회귀 테스트
 *
 * 1.3.0 이전 설치본의 `api_request_fields` 에는 전송 후보 밖 값(`order_amount`·`weight`·
 * `zipcode` 등 구버전 샘플이 남긴 값)이 들어 있다. 그 값은 전송되지 않아 기능상 죽은
 * 값이지만 저장 검증이 거절하므로 **조회한 그대로 되돌려 저장해도 422** 가 되어 그 정책은
 * 관리자 화면에서 아무것도 고칠 수 없는 상태로 남는다(S17 B4). 이 백필이 그 상태를 푼다.
 *
 * 검증 축: ① 후보 밖 값 제거 ② 후보 안 값 보존(전송되던 필드 유지) ③ 이미 정상인 행 불변
 * ④ 멱등 ⑤ 비배열·null 값 불변 ⑥ 전건 후보 밖이면 빈 배열 ⑦ **후보 목록이 Enum SSoT 와
 * 동일**(모집단을 손으로 적지 않는다 — `ShippingApiRequestField` 에 값이 늘고 이 백필의
 * 상수가 그대로면 그 값이 조용히 지워지므로, 이 축이 그때 red 가 된다)
 */
class NormalizeShippingApiRequestFieldsTest extends ModuleTestCase
{
    private const TABLE = 'ecommerce_shipping_policy_country_settings';

    private object $migration;

    private UpgradeContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 3)
            .'/upgrades/data/1.3.0/migrations/07_NormalizeShippingApiRequestFields.php';

        $class = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_3_0\\Migrations\\NormalizeShippingApiRequestFields';

        $this->migration = new $class;
        $this->context = new UpgradeContext(
            fromVersion: '1.1.1',
            toVersion: '1.3.0',
            currentStep: '1.3.0',
        );
    }

    /**
     * 계산 API 국가 설정 1건을 만들고 그 id 를 돌려줍니다.
     *
     * `api_request_fields` 는 모델 캐스트를 우회해 원시 JSON 으로 심는다 — 기존 설치본에
     * 남아 있는 형태(후보 밖 값 포함)를 그대로 재현해야 하기 때문이다.
     *
     * @param  mixed  $rawFields  저장할 원시 값 (JSON 문자열 또는 null)
     * @return int 국가 설정 id
     */
    private function makeCountrySetting(mixed $rawFields): int
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => 'API 배송', 'en' => 'API Shipping'],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $setting = ShippingPolicyCountrySetting::create([
            'shipping_policy_id' => $policy->id,
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => 'api',
            'base_fee' => 3000,
            'is_active' => true,
        ]);

        DB::table(self::TABLE)->where('id', $setting->id)->update([
            'api_request_fields' => $rawFields,
        ]);

        return (int) $setting->id;
    }

    /**
     * 저장된 `api_request_fields` 원시 값을 읽습니다.
     *
     * @param  int  $id  국가 설정 id
     * @return string|null 원시 JSON
     */
    private function rawFields(int $id): ?string
    {
        $value = DB::table(self::TABLE)->where('id', $id)->value('api_request_fields');

        return $value === null ? null : (string) $value;
    }

    /**
     * 저장된 `api_request_fields` 를 배열로 읽습니다.
     *
     * @param  int  $id  국가 설정 id
     * @return array<int, mixed> 디코드된 목록
     */
    private function storedFields(int $id): array
    {
        return json_decode((string) $this->rawFields($id), true) ?: [];
    }

    public function test_removes_fields_outside_the_allowed_candidates(): void
    {
        $id = $this->makeCountrySetting(json_encode(['policy_id', 'order_amount', 'items', 'zipcode']));

        // 착수 상태 확인 — 후보 밖 값이 실제로 저장돼 있다
        $this->assertSame(['policy_id', 'order_amount', 'items', 'zipcode'], $this->storedFields($id));

        $this->migration->run($this->context);

        $this->assertSame(['policy_id', 'items'], $this->storedFields($id));
    }

    public function test_keeps_every_allowed_field(): void
    {
        $allowed = ShippingApiRequestField::values();

        $id = $this->makeCountrySetting(json_encode($allowed));

        $this->migration->run($this->context);

        // 값을 새로 채우지도, 전송되던 필드를 덜어내지도 않는다
        $this->assertSame($allowed, $this->storedFields($id));
    }

    public function test_leaves_already_clean_rows_untouched(): void
    {
        $id = $this->makeCountrySetting(json_encode(['country_code', 'group_total']));
        $before = $this->rawFields($id);

        $this->migration->run($this->context);

        $this->assertSame($before, $this->rawFields($id));
    }

    public function test_is_idempotent(): void
    {
        $id = $this->makeCountrySetting(json_encode(['weight', 'total_quantity', 'zipcode']));

        $this->migration->run($this->context);
        $afterFirst = $this->rawFields($id);

        $this->migration->run($this->context);

        $this->assertSame($afterFirst, $this->rawFields($id));
        $this->assertSame(['total_quantity'], $this->storedFields($id));
    }

    public function test_empties_the_list_when_every_field_is_outside_the_candidates(): void
    {
        $id = $this->makeCountrySetting(json_encode(['order_amount', 'weight', 'zipcode']));

        $this->migration->run($this->context);

        $this->assertSame([], $this->storedFields($id));
    }

    public function test_leaves_null_and_non_array_values_alone(): void
    {
        $nullId = $this->makeCountrySetting(null);
        $scalarId = $this->makeCountrySetting(json_encode('order_amount'));

        $this->migration->run($this->context);

        $this->assertNull($this->rawFields($nullId));
        $this->assertSame(json_encode('order_amount'), $this->rawFields($scalarId));
    }

    public function test_allowed_candidates_match_the_enum_ssot(): void
    {
        $reflection = new \ReflectionClass($this->migration);
        $constants = $reflection->getConstants();

        $this->assertArrayHasKey('ALLOWED_FIELDS', $constants, '후보 목록 상수가 사라졌다면 이 백필이 무엇을 남기는지 알 수 없다.');

        $enumValues = ShippingApiRequestField::values();

        $this->assertNotSame([], $enumValues, '후보 어휘가 비면 이 대조는 아무것도 재지 않는다.');

        // 업그레이드 스텝은 구버전 코드베이스에서도 실행되므로 Enum 을 참조할 수 없어 목록을
        // 복제해 둔다. 그 사본이 SSoT 와 갈리면 새 후보 값이 조용히 지워진다.
        $this->assertSame(
            $enumValues,
            $constants['ALLOWED_FIELDS'],
            'ShippingApiRequestField 에 값을 더했다면 07_NormalizeShippingApiRequestFields::ALLOWED_FIELDS 도 함께 갱신할 것.',
        );
    }
}
