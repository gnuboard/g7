<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Export;

use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Jobs\GenerateExportJob;
use Modules\Sirsoft\Ecommerce\Models\Export;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;

/**
 * 「현재 화면 컬럼」 내보내기의 개인정보 판정 — 창(카탈로그)이 요청자 권한으로 좁혀 보인 필드와 서버 판정·파일 열이 같은지 잠근다.
 *
 * 화면 컬럼 하나(주문의 「주문자」, 마일리지의 「회원」)는 개인정보 필드와 아닌 필드를 함께 펼친다. 개인정보 내보내기 권한이 없는
 * 운영자에게 창은 개인정보 필드를 뺀 목록을 보이므로, 서버도 그 좁힌 필드로 판정해야 한다 — 전체 필드로 판정하면 사유 칸이 없는
 * 창에서 「사유를 입력하세요」로 막히고, 사유를 넣어도 권한 거부로 막혀 기본 흐름이 통째로 닫힌다.
 *
 * 검증 축:
 * - 권한 없음 + 현재 화면 컬럼: 사유 없이 202, 파일 열에 개인정보 필드 없음, 개인정보 표시 꺼짐 (주문·마일리지)
 * - 권한 있음 + 현재 화면 컬럼: 종전대로 사유 필수, 사유를 넣으면 개인정보 열 포함
 * - 권한 없음 + 데이터 선택(개인정보 필드를 직접 보냄): 여전히 거부
 */
class ExportPersonalFieldNarrowingTest extends ExportTestCase
{
    /**
     * 개인정보 권한을 뺀 내보내기·원본 조회 권한
     *
     * @var array<int, string>
     */
    private const BASE_PERMISSIONS = [
        'sirsoft-ecommerce.exports.create',
        'sirsoft-ecommerce.exports.read',
        'sirsoft-ecommerce.orders.read',
        'sirsoft-ecommerce.mileage.read',
    ];

    /**
     * 주문 「주문자」 화면 컬럼의 개인정보 필드
     *
     * @var array<int, string>
     */
    private const ORDERER_PERSONAL_FIELDS = ['address.orderer_name', 'address.orderer_phone', 'address.orderer_email'];

    /**
     * 큐를 가짜로 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([GenerateExportJob::class]);
    }

    /**
     * 개인정보 내보내기 권한이 없는 운영자(기본 역할 「이커머스 관리자」와 같은 꼴)
     *
     * @return User 운영자
     */
    private function operatorWithoutPersonal(): User
    {
        return $this->createAdminUser(self::BASE_PERMISSIONS);
    }

    /**
     * 개인정보 내보내기 권한이 있는 운영자
     *
     * @return User 운영자
     */
    private function operatorWithPersonal(): User
    {
        return $this->createAdminUser([...self::BASE_PERMISSIONS, 'sirsoft-ecommerce.exports.personal_data']);
    }

    /**
     * 내보내기를 요청합니다.
     *
     * @param  User  $user  요청자
     * @param  array<string, mixed>  $body  본문
     * @return TestResponse 응답
     */
    private function request(User $user, array $body): TestResponse
    {
        return $this->actingAs($user)->postJson($this->exportsUrl(), array_merge([
            'format' => 'csv', 'locale_mode' => 'locale', 'include_totals' => false,
        ], $body), ['Idempotency-Key' => 'personal-'.uniqid()]);
    }

    /**
     * 주문 목록 기본 화면 컬럼 그대로(「주문자」 포함) 선택한 주문을 내보내는 본문
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return array<string, mixed> 본문
     */
    private function orderScreenBody(array $overrides = []): array
    {
        $order = OrderFactory::new()->paid()->create();
        $this->travel(1)->seconds();

        return array_merge([
            'type' => 'orders', 'column_mode' => 'current', 'screen_columns' => ['order_number', 'orderer'],
            'row_unit' => 'order', 'scope' => 'selected', 'selected_ids' => [$order->id], 'reason' => '',
        ], $overrides);
    }

    /**
     * 권한 없는 운영자의 주문 기본값 요청은 사유 없이 접수되고, 파일 열에서 주문자명·휴대폰·이메일이 빠진다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, scope=selected, permission=personal_data
     *
     * @effects export_screen_columns_follow_personal_permission
     */
    public function test_orders_screen_columns_without_personal_permission_drop_personal_fields(): void
    {
        $id = (int) $this->request($this->operatorWithoutPersonal(), $this->orderScreenBody())
            ->assertStatus(202)
            ->json('data.id');

        $export = Export::findOrFail($id);
        $fields = (array) $export->options['fields'];
        $this->assertFalse((bool) $export->contains_personal_data);
        $this->assertContains('order.member_type', $fields);
        $this->assertContains('order.member_uuid', $fields);
        foreach (self::ORDERER_PERSONAL_FIELDS as $personal) {
            $this->assertNotContains($personal, $fields, "{$personal} 가 파일 열에 남았습니다.");
        }
    }

    /**
     * 권한 없는 운영자의 마일리지 기본값 요청(「회원」 포함)도 사유 없이 접수되고 회원명이 빠진다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=mileage, scope=all, permission=personal_data
     *
     * @effects export_screen_columns_follow_personal_permission
     */
    public function test_mileage_screen_columns_without_personal_permission_drop_member_name(): void
    {
        $id = (int) $this->request($this->operatorWithoutPersonal(), [
            'type' => 'mileage', 'column_mode' => 'current', 'screen_columns' => ['created_at', 'member', 'amount'],
            'row_unit' => 'transaction', 'scope' => 'all', 'reason' => '',
            'period' => ['mode' => 'custom', 'start_date' => now()->subDays(7)->toDateString(), 'end_date' => now()->toDateString()],
        ])->assertStatus(202)->json('data.id');

        $export = Export::findOrFail($id);
        $fields = (array) $export->options['fields'];
        $this->assertFalse((bool) $export->contains_personal_data);
        $this->assertContains('mileage.member_uuid', $fields);
        $this->assertNotContains('mileage.member_name', $fields);
    }

    /**
     * 개인정보 권한이 있는 운영자는 종전대로 같은 기본값에서 사유를 요구받고, 사유를 넣으면 개인정보 열이 함께 나간다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, scope=selected, permission=personal_data
     *
     * @effects export_screen_columns_follow_personal_permission, export_personal_fields_need_permission_and_reason
     */
    public function test_orders_screen_columns_with_personal_permission_still_need_reason(): void
    {
        $operator = $this->operatorWithPersonal();

        $this->request($operator, $this->orderScreenBody())
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $id = (int) $this->request($operator, $this->orderScreenBody(['reason' => '월말 정산']))
            ->assertStatus(202)
            ->json('data.id');

        $export = Export::findOrFail($id);
        $this->assertTrue((bool) $export->contains_personal_data);
        foreach (self::ORDERER_PERSONAL_FIELDS as $personal) {
            $this->assertContains($personal, (array) $export->options['fields']);
        }
    }

    /**
     * 「데이터 선택」으로 개인정보 필드를 직접 보내면 권한 없는 운영자는 여전히 거부된다(창이 막는 것을 API 로 우회하지 못한다).
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, scope=selected, permission=personal_data
     *
     * @effects export_personal_fields_need_permission_and_reason
     */
    public function test_custom_personal_fields_without_permission_are_rejected(): void
    {
        $operator = $this->operatorWithoutPersonal();
        $body = $this->orderScreenBody([
            'column_mode' => 'custom', 'screen_columns' => [], 'fields' => ['order.order_number', 'address.orderer_name'],
        ]);

        // 사유가 없으면 사유부터, 사유를 넣어도 권한으로 막힌다
        $this->request($operator, $body)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->request($operator, array_merge($body, ['reason' => '우회 시도']))->assertStatus(403);
        $this->assertSame(0, Export::query()->where('requested_by', $operator->id)->count());
    }
}
