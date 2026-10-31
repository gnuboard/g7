<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Export;

use App\Models\User;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\ExportStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Export;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;

/**
 * 내보내기 관리 목록의 검색 — 검색칸 안내(「파일명 또는 요청자 이름·이메일」)대로 찾는지 잠근다.
 *
 * 검증 축:
 * - 요청자 이메일: 「전체」·「요청자」 검색이 목록에 보이는 요청자 이메일(전체·일부)로 찾는다
 * - 요청자 이름: 요청 때 남긴 이름과 지금 이름 둘 다로 찾는다(목록은 지금 이름을 보인다)
 * - 와일드카드: 검색어의 `%`·`_` 는 글자 그대로 찾는다
 * - 요청자 한정(`requester_uuid`): 이름이 포함 관계인 다른 요청자의 작업이 섞이지 않는다
 */
class ExportListSearchTest extends ExportTestCase
{
    protected User $admin;

    /**
     * 내보내기 조회 권한(범위 전체) 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.exports.read',
            'sirsoft-ecommerce.orders.read',
        ]);
    }

    /**
     * 요청자 회원을 만듭니다.
     *
     * @param  string  $name  이름
     * @param  string  $email  이메일
     * @return User 회원
     */
    private function member(string $name, string $email): User
    {
        return User::factory()->create(['name' => $name, 'email' => $email]);
    }

    /**
     * 완료된 내보내기 작업 한 건을 만듭니다(요청 때 이름을 스냅숏으로 남긴다).
     *
     * @param  User|null  $requester  요청자 (null 이면 탈퇴한 요청자)
     * @param  string  $fileName  파일명
     * @param  string|null  $snapshot  요청자 이름 스냅숏 (null 이면 요청자의 지금 이름)
     * @return Export 작업
     */
    private function job(?User $requester, string $fileName, ?string $snapshot = null): Export
    {
        return Export::query()->create([
            'uuid' => (string) Str::uuid(),
            'type' => 'orders',
            'format' => 'csv',
            'status' => ExportStatusEnum::COMPLETED->value,
            'requested_by' => $requester?->id,
            'requester_name_snapshot' => $snapshot ?? $requester?->name,
            'options' => ['fields' => ['order.order_number'], 'scope' => 'all', 'period' => ['mode' => 'current']],
            'file_name' => $fileName,
            'requested_at' => now(),
            'completed_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
    }

    /**
     * 목록을 조회해 작업 ID 를 정렬해 돌려줍니다.
     *
     * @param  array<string, mixed>  $query  쿼리
     * @return array<int, int> 작업 ID
     */
    private function listIds(array $query): array
    {
        $ids = collect($this->actingAs($this->admin)
            ->getJson($this->exportsUrl('?'.http_build_query($query)))
            ->assertOk()
            ->json('data.data'))->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        return $ids;
    }

    /**
     * 「전체」·「요청자」 검색이 목록에 보이는 요청자 이메일(전체·일부)로 그 요청자의 작업을 찾는다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, action=requester_menu
     *
     * @effects export_list_search_matches_requester_email
     */
    public function test_search_finds_requester_by_email(): void
    {
        $alice = $this->member('검색확인 앨리스', 'export-search-alice@example.test');
        $bob = $this->member('검색확인 밥', 'export-search-bob@example.test');
        $mine = $this->job($alice, 'orders_alice.csv');
        $this->job($bob, 'orders_bob.csv');

        foreach (['all', 'requester'] as $field) {
            $this->assertSame([$mine->id], $this->listIds(['search_field' => $field, 'search_keyword' => 'export-search-alice@example.test']), "{$field}: 이메일 전체");
            $this->assertSame([$mine->id], $this->listIds(['search_field' => $field, 'search_keyword' => 'search-alice@']), "{$field}: 이메일 일부");
        }

        // 「파일명」 검색은 이메일을 보지 않는다
        $this->assertSame([], $this->listIds(['search_field' => 'file_name', 'search_keyword' => 'export-search-alice@example.test']));
    }

    /**
     * 요청 뒤 이름을 바꾼 요청자는 지금 이름(목록에 보이는 이름)으로도, 요청 때 이름으로도 찾는다.
     * 탈퇴해 연결이 끊긴 요청자는 요청 때 남긴 이름으로 찾는다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, action=requester_menu
     *
     * @effects export_list_search_matches_requester_email
     */
    public function test_search_finds_requester_by_current_and_snapshot_name(): void
    {
        $renamed = $this->member('검색확인 새이름', 'export-search-renamed@example.test');
        $job = $this->job($renamed, 'orders_renamed.csv', '검색확인 옛이름');
        $left = $this->job(null, 'orders_left.csv', '검색확인 탈퇴자');

        $this->assertSame([$job->id], $this->listIds(['search_field' => 'requester', 'search_keyword' => '검색확인 새이름']));
        $this->assertSame([$job->id], $this->listIds(['search_field' => 'requester', 'search_keyword' => '검색확인 옛이름']));
        $this->assertSame([$left->id], $this->listIds(['search_field' => 'all', 'search_keyword' => '검색확인 탈퇴자']));
    }

    /**
     * 검색어의 `%`·`_` 는 와일드카드가 아니라 글자 그대로 찾는다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders
     *
     * @effects export_list_search_escapes_like_wildcards
     */
    public function test_like_wildcards_in_keyword_are_literal(): void
    {
        $requester = $this->member('검색확인 와일드', 'export-search-wild@example.test');
        $percent = $this->job($requester, 'wildcheck_50%_list.csv');
        $plain = $this->job($requester, 'wildcheck_500_list.csv');
        $under = $this->job($requester, 'wildcheck_a_b.csv');
        $other = $this->job($requester, 'wildcheck_axb.csv');

        $this->assertSame([$percent->id], $this->listIds(['search_field' => 'file_name', 'search_keyword' => 'wildcheck_50%']));
        $this->assertSame([$under->id], $this->listIds(['search_field' => 'file_name', 'search_keyword' => 'wildcheck_a_b']));
        $this->assertNotContains($plain->id, $this->listIds(['search_field' => 'all', 'search_keyword' => '%']));
        $this->assertNotContains($other->id, $this->listIds(['search_field' => 'all', 'search_keyword' => '%']));
    }

    /**
     * 요청자 한정(`requester_uuid`)은 그 회원의 작업만 보인다 — 이름이 포함 관계인 다른 요청자(「홍길동」과 「홍길동2」)의
     * 작업이 섞이지 않는다. 이름 검색어와 함께 와도 같다(「이 요청자의 내보내기만 보기」가 둘을 함께 싣는다).
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, action=requester_menu
     *
     * @effects requester_filter_matches_requester_exactly
     */
    public function test_requester_uuid_filter_excludes_similar_names(): void
    {
        $hong = $this->member('검색확인홍길동', 'export-search-hong@example.test');
        $hong2 = $this->member('검색확인홍길동2', 'export-search-hong2@example.test');
        $mine = $this->job($hong, 'orders_hong.csv');
        $this->job($hong2, 'orders_hong2.csv');

        // 이름 포함 검색만으로는 두 사람이 다 걸린다 — 그래서 메뉴는 회원 식별자로 거른다
        $this->assertCount(2, $this->listIds(['search_field' => 'requester', 'search_keyword' => '검색확인홍길동']));

        $this->assertSame([$mine->id], $this->listIds(['requester_uuid' => $hong->uuid]));
        $this->assertSame([$mine->id], $this->listIds([
            'search_field' => 'requester', 'search_keyword' => '검색확인홍길동', 'requester_uuid' => $hong->uuid,
        ]));
    }

    /**
     * 요청자 한정 값은 회원 고유ID(UUID) 형식만 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, action=requester_menu
     *
     * @effects requester_filter_matches_requester_exactly
     */
    public function test_requester_uuid_must_be_uuid(): void
    {
        $this->actingAs($this->admin)
            ->getJson($this->exportsUrl('?requester_uuid=not-a-uuid'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('requester_uuid');
    }
}
