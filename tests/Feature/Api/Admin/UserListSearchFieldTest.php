<?php

namespace Tests\Feature\Api\Admin;

use App\Http\Requests\User\UserListRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 회원 목록 검색 대상 확장 테스트 (#705)
 *
 * 검색 대상이 전체·이름·이메일 셋이던 것을 닉네임·연락처(휴대폰+전화)·회원번호/고유ID·관리자 메모까지
 * 일곱으로 넓혔다. 화면(3개)·다국어(4개)·서버(2개)가 서로 달랐던 불일치(A1)를 한 목록으로 맞춘 것이다.
 *
 * - `identifier` 는 LIKE 가 아니라 정확 일치다(숫자면 id, UUID 면 uuid).
 * - `admin_memo` 는 TEXT 라 연산자를 무시하고 포함 검색만 한다.
 * - LIKE 검색어의 `%`·`_` 는 글자 그대로 찾는다(이스케이프).
 *
 * 트랜잭션 격리 — 기존 행과 공존하므로 기대 결과는 이 테스트가 만든 회원 uuid 로만 대조한다.
 */
#[Group('core')]
#[Group('admin')]
#[Group('user')]
class UserListSearchFieldTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private string $token;

    /**
     * 검색 권한을 가진 운영자와 고유 표식을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Str::lower(Str::random(8));
        $this->admin = $this->createOperator();
    }

    /**
     * 닉네임 대상 검색은 닉네임만 본다(같은 글자가 이름에 있는 회원은 빠진다).
     *
     * @scenario filter_axis=keyword, outcome=narrows
     *
     * @effects admin_user_list_search_fields_expanded
     *
     * @return void 반환값 없음
     */
    public function test_nickname_field_searches_nickname_only(): void
    {
        $byNick = $this->member(['nickname' => 'nk'.$this->token]);
        $this->member(['name' => 'nk'.$this->token]);

        $this->assertFound([$byNick], 'nickname', 'nk'.$this->token);
    }

    /**
     * 게이트(FormRequest)가 여는 검색 대상과 저장소가 푸는 검색 대상이 같은 집합이다.
     *
     * 저장소는 모르는 대상을 조용히 건너뛰므로, 게이트에만 대상을 더하면 422 도 결과 변화도 없이
     * 검색이 무시된다 — 두 목록이 갈라지는 순간을 여기서 잡는다(정렬은 SortWhitelistGateParityTest 가 잡는다).
     *
     * @scenario filter_axis=keyword, outcome=narrows
     *
     * @effects admin_user_list_search_fields_expanded
     *
     * @return void 반환값 없음
     */
    public function test_request_and_repository_search_fields_are_the_same_set(): void
    {
        $repository = (new \ReflectionClass(UserRepository::class))->getConstant('SEARCHABLE_FIELDS');

        $this->assertIsArray($repository);
        $this->assertEqualsCanonicalizing(UserListRequest::SEARCHABLE_FIELDS, $repository);
    }

    /**
     * 연락처 대상은 휴대폰·전화 두 칸을 OR 로 본다.
     *
     * @scenario filter_axis=keyword, outcome=narrows
     *
     * @effects admin_user_list_search_fields_expanded
     *
     * @return void 반환값 없음
     */
    public function test_contact_field_matches_mobile_or_phone(): void
    {
        $digits = (string) random_int(10000000, 99999999);
        $viaMobile = $this->member(['mobile' => '010-'.$digits]);
        $viaPhone = $this->member(['phone' => '02-'.$digits]);

        $this->assertFound([$viaMobile, $viaPhone], 'contact', $digits);
    }

    /**
     * 회원번호 대상은 숫자면 id 정확 일치다(부분 일치하는 다른 번호는 나오지 않는다).
     *
     * @scenario filter_axis=keyword, outcome=narrows
     *
     * @effects admin_user_list_search_fields_expanded
     *
     * @return void 반환값 없음
     */
    public function test_identifier_numeric_matches_id_exactly(): void
    {
        $target = $this->member();

        $this->assertFound([$target], 'identifier', (string) $target->id);
    }

    /**
     * 회원번호 대상은 UUID 형식이면 uuid 정확 일치다(대소문자 무관).
     *
     * @scenario filter_axis=keyword, outcome=narrows
     *
     * @effects admin_user_list_search_fields_expanded
     *
     * @return void 반환값 없음
     */
    public function test_identifier_uuid_matches_uuid_exactly(): void
    {
        $target = $this->member();

        $this->assertFound([$target], 'identifier', strtoupper($target->uuid));
    }

    /**
     * 회원번호 대상에 숫자도 UUID 도 아닌 값이 오면 결과가 없다(LIKE 로 새지 않는다).
     *
     * @scenario filter_axis=keyword, outcome=narrows
     *
     * @effects admin_user_list_search_fields_expanded
     *
     * @return void 반환값 없음
     */
    public function test_identifier_other_format_matches_nothing(): void
    {
        $this->member(['name' => 'id'.$this->token]);

        $this->assertFound([], 'identifier', 'id'.$this->token);
    }

    /**
     * 관리자 메모 대상은 연산자와 무관하게 포함 검색이다.
     *
     * @scenario filter_axis=keyword, outcome=narrows
     *
     * @effects admin_user_list_search_fields_expanded
     *
     * @return void 반환값 없음
     */
    public function test_admin_memo_field_ignores_operator(): void
    {
        $target = $this->member(['admin_memo' => '앞부분 memo'.$this->token.' 뒷부분']);

        $this->assertFound([$target], 'admin_memo', 'memo'.$this->token, 'eq');
        $this->assertFound([$target], 'admin_memo', 'memo'.$this->token, 'starts_with');
    }

    /**
     * 「전체」 대상은 이름·닉네임·이메일·휴대폰·전화와 회원번호를 함께 본다(메모는 제외).
     *
     * @scenario filter_axis=keyword, outcome=narrows
     *
     * @effects admin_user_list_search_fields_expanded
     *
     * @return void 반환값 없음
     */
    public function test_all_field_covers_new_columns_but_not_memo(): void
    {
        $byNick = $this->member(['nickname' => 'al'.$this->token]);
        $byMobile = $this->member(['mobile' => 'al'.$this->token]);
        $this->member(['admin_memo' => 'al'.$this->token]);

        $this->assertFound([$byNick, $byMobile], 'all', 'al'.$this->token);

        $target = $this->member();
        $response = $this->search('all', (string) $target->id);
        $this->assertContains($target->uuid, collect($response->json('data.data'))->pluck('uuid')->all());
    }

    /**
     * LIKE 검색어의 `%`·`_` 는 와일드카드가 아니라 글자로 찾는다.
     *
     * @scenario filter_axis=keyword, outcome=narrows
     *
     * @effects admin_user_list_like_wildcards_escaped
     *
     * @return void 반환값 없음
     */
    public function test_like_wildcards_are_escaped(): void
    {
        $literal = $this->member(['name' => 'pct'.$this->token.'%_x']);
        $this->member(['name' => 'pct'.$this->token.'abx']);

        $this->assertFound([$literal], 'name', 'pct'.$this->token.'%_');
        // `%` 한 글자로 전체가 걸리지 않는다.
        $this->assertFound([], 'name', '%%'.$this->token.'%%');
    }

    /**
     * 이름·이메일의 기존 연산자(eq·starts_with·ends_with)는 그대로 동작한다.
     *
     * @scenario filter_axis=keyword, outcome=narrows
     *
     * @effects admin_user_list_search_fields_expanded
     *
     * @return void 반환값 없음
     */
    public function test_existing_operators_still_work_for_text_fields(): void
    {
        $exact = $this->member(['name' => 'op'.$this->token]);
        $prefixed = $this->member(['name' => 'op'.$this->token.'-tail']);

        $this->assertFound([$exact], 'name', 'op'.$this->token, 'eq');
        $this->assertFound([$exact, $prefixed], 'name', 'op'.$this->token, 'starts_with');
        $this->assertFound([$prefixed], 'name', $this->token.'-tail', 'ends_with');
    }

    /**
     * 검색어 「0」 도 검색어다 — 모든 검색 대상에서 조건이 걸린다.
     *
     * `empty()` 는 문자열 "0" 을 빈 값으로 본다. 그 판정이 요청 정리나 텍스트 칸 분기에 남아 있으면
     * 「0」 검색이 조건 없이 전체 회원을 돌려준다. 검색 대상은 게이트 목록에서 파생한다.
     *
     * @scenario filter_axis=keyword, outcome=narrows
     *
     * @effects admin_user_list_zero_keyword_is_searched
     *
     * @return void 반환값 없음
     */
    public function test_zero_keyword_is_a_real_search_term(): void
    {
        $fields = array_merge(['all'], UserListRequest::SEARCHABLE_FIELDS);
        $this->assertNotEmpty(UserListRequest::SEARCHABLE_FIELDS, '검색 대상 모집단이 비었습니다.');

        // 어느 검색 칸에도 0 이 없는 회원 — 「0」 검색에 걸리면 조건이 무시된 것이다.
        $letters = preg_replace('/\d/', 'q', $this->token);
        $without = $this->member(['name' => 'zero-less '.$letters, 'email' => 'zl'.$letters.'@sf.test']);

        // 칸마다 0 을 가진 회원 (identifier 는 정확 일치라 「0」 에 걸릴 회원이 없다).
        $withZero = [
            'all' => ['name' => 'z0'.$letters],
            'name' => ['name' => 'z0'.$letters],
            'nickname' => ['nickname' => 'z0'.$letters],
            'email' => ['email' => 'z0'.$letters.'@sf.test'],
            'contact' => ['mobile' => '010'.$letters],
            'admin_memo' => ['admin_memo' => 'memo 0 '.$letters],
        ];

        foreach ($fields as $field) {
            $hit = isset($withZero[$field]) ? $this->member($withZero[$field]) : null;

            $uuids = collect($this->search($field, '0')->assertOk()->json('data.data'))->pluck('uuid')->all();

            $this->assertNotContains($without->uuid, $uuids, "{$field}: 「0」 검색이 조건 없이 전체를 돌려줍니다.");

            if ($hit !== null) {
                $this->assertContains($hit->uuid, $uuids, "{$field}: 「0」 을 가진 회원이 빠졌습니다.");
            }
        }
    }

    /**
     * 목록에 없는 검색 대상은 422 로 거부한다.
     *
     * @scenario filter_axis=keyword, outcome=rejected
     *
     * @effects admin_user_list_filter_invalid_values_rejected
     *
     * @return void 반환값 없음
     */
    public function test_unknown_field_is_rejected(): void
    {
        $this->search('password', 'x')->assertStatus(422);
    }

    /**
     * 표식이 붙은 회원을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return User 만든 회원
     */
    private function member(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'email' => Str::lower(Str::random(12)).'@sf.test',
            'name' => 'member '.Str::random(6),
            'nickname' => null,
            'mobile' => null,
            'phone' => null,
            'admin_memo' => null,
        ], $attributes))->fresh();
    }

    /**
     * 목록 조회 권한만 가진 운영자를 만듭니다.
     *
     * @return User 운영자
     */
    private function createOperator(): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => ['ko' => 'sf', 'en' => 'sf'],
            'identifier' => 'sf-'.$user->id,
            'is_active' => true,
        ]);
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.users.read'],
            ['name' => ['ko' => 'core.users.read', 'en' => 'core.users.read'], 'type' => 'admin', 'order' => 1]
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * 검색 한 건을 부릅니다.
     *
     * @param  string  $field  검색 대상
     * @param  string  $value  검색어
     * @param  string  $operator  연산자
     * @return TestResponse 응답
     */
    private function search(string $field, string $value, string $operator = 'like')
    {
        return $this->actingAs($this->admin)->getJson('/api/admin/users?'.http_build_query([
            'filters' => [['field' => $field, 'value' => $value, 'operator' => $operator]],
            'per_page' => 100,
        ]));
    }

    /**
     * 검색 결과가 기대한 회원 집합과 같은지 봅니다.
     *
     * @param  array<int, User>  $expected  기대 회원
     * @param  string  $field  검색 대상
     * @param  string  $value  검색어
     * @param  string  $operator  연산자
     * @return void 반환값 없음
     */
    private function assertFound(array $expected, string $field, string $value, string $operator = 'like'): void
    {
        $response = $this->search($field, $value, $operator)->assertOk();

        $actual = collect($response->json('data.data'))->pluck('uuid')->sort()->values()->all();
        $wanted = collect($expected)->pluck('uuid')->sort()->values()->all();

        $this->assertSame($wanted, $actual, "{$field} {$operator} {$value}");
    }
}
