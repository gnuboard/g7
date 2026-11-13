<?php

namespace Tests\Unit\DisplayName;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\DisplayName\DisplayNameQuery;
use App\Fields\FieldContext;
use App\Fields\FieldSet;
use App\Fields\FieldValueService;
use App\Models\ActivityLog;
use App\Models\FieldDefinition;
use App\Models\User;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 표시 이름 기준의 작성자 검색과 정렬 헬퍼 — 영역 설정에 따라 찾는 컬럼과 정렬 식이 달라진다.
 *
 * 작성자 관계를 가진 모델로 활동 로그(`user` 관계)를 쓴다.
 */
class DisplayNameQueryTest extends TestCase
{
    use RefreshDatabase;

    private DisplayNameQuery $query;

    private User $hong;

    private User $kim;

    private User $lee;

    /**
     * 회원 셋과 그들이 남긴 기록을 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreFieldDefinitionSeeder::class);
        $this->query = app(DisplayNameQuery::class);

        $this->hong = User::factory()->create(['name' => '홍길동', 'nickname' => '바람', 'email' => 'charlie@example.com']);
        $this->kim = User::factory()->create(['name' => '김철수', 'nickname' => '구름', 'email' => 'alpha@example.com']);
        $this->lee = User::factory()->create(['name' => '이영희', 'nickname' => null, 'email' => 'bravo@example.com']);

        foreach ([$this->hong, $this->kim, $this->lee] as $user) {
            ActivityLog::create(['user_id' => $user->id, 'log_type' => 'user', 'action' => 'dn.test', 'description_key' => 'dn.test']);
        }
    }

    /**
     * 표시 이름 설정을 바꿉니다.
     *
     * @param  array<string, mixed>  $member  회원 범주 설정
     * @return void 반환값 없음
     */
    private function settings(array $member): void
    {
        foreach ($member as $key => $value) {
            config(["g7_settings.core.member.{$key}" => $value]);
        }
    }

    /**
     * 사용자 화면 검색으로 찾은 기록의 회원 ID 를 반환합니다.
     *
     * @param  string  $keyword  검색어
     * @return array<int, int> 회원 ID (오름차순)
     */
    private function found(string $keyword): array
    {
        return $this->query->applySearch(ActivityLog::query()->where('action', 'dn.test'), $keyword)
            ->pluck('user_id')->sort()->values()->all();
    }

    /**
     * 작성자순으로 정렬한 기록의 회원 ID 를 반환합니다.
     *
     * @param  string  $direction  정렬 방향
     * @return array<int, int> 회원 ID (정렬 순서)
     */
    private function sorted(string $direction = 'asc'): array
    {
        $table = (new ActivityLog)->getTable();

        return $this->query->orderByAuthor(ActivityLog::query()->where('action', 'dn.test'), 'core:site', $direction, ['user_key' => $table.'.user_id'])
            ->orderBy($table.'.id')
            ->get()->pluck('user_id')->all();
    }

    /**
     * 사용자 화면 검색은 그 영역에서 보이는 종류의 원본 컬럼만 찾는다. 가린 종류로 고정된 영역은 아무것도 찾지 않는다.
     *
     * @scenario case=search_user
     *
     * @effects user_search_matches_only_shown_kind_columns, masked_kind_area_finds_nothing, withdrawn_member_excluded_from_search, like_wildcards_are_literal
     *
     * @return void 반환값 없음
     */
    public function test_user_search_matches_only_the_shown_kind(): void
    {
        // 이름 고정: 이름만 찾는다
        $this->assertSame([$this->hong->id], $this->found('길동'));
        $this->assertSame([], $this->found('바람'), '이름 영역에서 닉네임이 검색됐다');
        $this->assertSame([], $this->found('%'), '와일드카드 문자가 전체 일치로 동작했다');

        // 닉네임 고정: 닉네임만 찾는다(실명으로 되짚지 못한다)
        $this->settings(['display_name_areas' => ['core:site' => ['mode' => 'fixed', 'kind' => 'nickname']]]);
        $this->assertSame([$this->hong->id], $this->found('바람'));
        $this->assertSame([], $this->found('길동'), '닉네임 영역에서 이름이 검색됐다');

        // 이름(닉네임): 둘 다
        $this->settings(['display_name_areas' => ['core:site' => ['mode' => 'fixed', 'kind' => 'name_nickname']]]);
        $this->assertSame([$this->hong->id], $this->found('길동'));
        $this->assertSame([$this->kim->id], $this->found('구름'));

        // 가린 종류: 찾지 않는다
        foreach (['masked_name', 'masked_id'] as $kind) {
            $this->settings(['display_name_areas' => ['core:site' => ['mode' => 'fixed', 'kind' => $kind]]]);
            $this->assertSame([], $this->found('길동'), "가린 종류 {$kind} 에서 이름이 검색됐다");
            $this->assertSame([], $this->query->searchableKinds());
        }

        // 탈퇴한 회원은 문구로 보이므로 검색에서 빠진다
        $this->settings(['display_name_areas' => ['core:site' => ['mode' => 'fixed', 'kind' => 'name']]]);
        $this->hong->withdraw();
        $this->assertSame([], $this->found('길동'));
    }

    /**
     * 「회원이 고른 값」 영역은 회원마다 자기에게 적용되는 종류의 컬럼만 찾는다.
     *
     * @scenario case=search_member_mode
     *
     * @effects member_mode_search_respects_each_members_kind, member_mode_search_uses_default_kind_for_unchosen
     *
     * @return void 반환값 없음
     */
    public function test_member_mode_search_respects_each_members_kind(): void
    {
        $this->settings([
            'display_name_selectable_kinds' => ['name', 'nickname'],
            'display_name_member_default_kind' => 'name',
            'display_name_areas' => ['core:site' => ['mode' => 'member']],
        ]);
        $this->hong->forceFill(['display_name_kind' => 'nickname'])->save();
        // 허용에서 빠진 종류를 골랐던 회원은 회원 기본 종류(이름)로 보인다
        $this->kim->forceFill(['display_name_kind' => 'masked_name'])->save();

        $this->assertSame([$this->hong->id], $this->found('바람'));
        $this->assertSame([], $this->found('길동'), '닉네임으로 보이는 회원이 이름으로 검색됐다');
        $this->assertSame([$this->kim->id], $this->found('철수'));
        $this->assertSame([], $this->found('구름'), '이름으로 보이는 회원이 닉네임으로 검색됐다');
        $this->assertSame([$this->lee->id], $this->found('영희'));
    }

    /**
     * 관리자 화면 검색은 영역 설정과 무관하게 이름과 닉네임을 모두 찾고, 비회원 이름 컬럼을 주면 그 컬럼도 찾는다.
     *
     * @scenario case=search_admin
     *
     * @effects admin_search_matches_name_and_nickname, guest_column_is_searched_for_guest_rows_only, field_kind_value_is_searchable
     *
     * @return void 반환값 없음
     */
    public function test_admin_search_and_guest_column(): void
    {
        $this->settings(['display_name_areas' => ['core:site' => ['mode' => 'fixed', 'kind' => 'masked_name']]]);
        $table = (new ActivityLog)->getTable();
        $admin = fn (string $keyword) => $this->query->applyAdminSearch(ActivityLog::query()->where('action', 'dn.test'), $keyword)
            ->pluck('user_id')->sort()->values()->all();

        $this->assertSame([$this->hong->id], $admin('길동'));
        $this->assertSame([$this->hong->id], $admin('바람'));

        // 비회원 행: 이름 컬럼(여기서는 description_key 를 빌려 쓴다)으로 찾는다. 회원 행의 같은 컬럼은 찾지 않는다.
        ActivityLog::create(['user_id' => null, 'log_type' => 'user', 'action' => 'dn.test', 'description_key' => '손님 길동']);
        ActivityLog::where('user_id', $this->kim->id)->update(['description_key' => '길동 아님']);
        $rows = $this->query->applySearch(ActivityLog::query()->where('action', 'dn.test'), '길동', 'core:site', [
            'guest_column' => $table.'.description_key', 'user_key' => $table.'.user_id',
        ])->get();
        $this->assertSame([null], $rows->pluck('user_id')->all(), '가린 영역에서 회원이 검색됐거나 비회원 행을 찾지 못했다');

        // 추가 항목 종류: 값 테이블의 값을 찾는다
        FieldDefinition::create([
            'field_set' => FieldSet::CORE_USER, 'key' => 'alias', 'type' => 'text', 'source_type' => 'custom', 'source_identifier' => 'custom',
            'storage' => 'value', 'name' => ['ko' => '활동명'], 'enabled' => true, 'display_name_usable' => true,
        ]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
        app(FieldValueService::class)->write($this->lee, FieldSet::CORE_USER, ['alias' => '달빛 검객'], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $this->lee));
        $this->settings(['display_name_areas' => ['core:site' => ['mode' => 'fixed', 'kind' => 'field:alias']]]);
        $this->assertSame([$this->lee->id], $this->found('달빛'));
    }

    /**
     * 작성자순 정렬은 화면에 보이는 값의 순서다. 값이 없는 회원은 대체 종류의 값으로 자리를 잡는다.
     *
     * @scenario case=sort_author
     *
     * @effects author_sort_follows_shown_kind, author_sort_uses_fallback_for_empty_values, author_sort_member_mode_uses_each_members_kind, author_sort_keeps_model_columns
     *
     * @return void 반환값 없음
     */
    public function test_order_by_author_follows_the_shown_name(): void
    {
        // 이름: 김철수 < 이영희 < 홍길동
        $this->assertSame([$this->kim->id, $this->lee->id, $this->hong->id], $this->sorted());
        $this->assertSame([$this->hong->id, $this->lee->id, $this->kim->id], $this->sorted('desc'));

        // 닉네임: 구름(김) < 바람(홍), 닉네임이 없는 이영희는 아이디 가림(bra…)으로 → 영문이 한글보다 앞
        $this->settings(['display_name_areas' => ['core:site' => ['mode' => 'fixed', 'kind' => 'nickname']]]);
        $this->assertSame([$this->lee->id, $this->kim->id, $this->hong->id], $this->sorted());

        // 아이디 가림: alpha(김) < bravo(이) < charlie(홍)
        $this->settings(['display_name_areas' => ['core:site' => ['mode' => 'fixed', 'kind' => 'masked_id']]]);
        $this->assertSame([$this->kim->id, $this->lee->id, $this->hong->id], $this->sorted());

        // 회원이 고른 값: 홍길동만 닉네임(바람), 나머지는 이름 → 김철수 < 바람 < 이영희
        $this->settings([
            'display_name_selectable_kinds' => ['name', 'nickname'],
            'display_name_member_default_kind' => 'name',
            'display_name_areas' => ['core:site' => ['mode' => 'member']],
        ]);
        $this->hong->forceFill(['display_name_kind' => 'nickname'])->save();
        $this->assertSame([$this->kim->id, $this->hong->id, $this->lee->id], $this->sorted());

        // 붙인 users 의 id 가 모델의 id 를 덮지 않는다
        $table = (new ActivityLog)->getTable();
        $first = $this->query->orderByAuthor(ActivityLog::query()->where('action', 'dn.test'), 'core:site', 'asc', ['user_key' => $table.'.user_id'])->first();
        $this->assertSame($this->kim->id, $first->user_id);
        $this->assertSame(ActivityLog::where('user_id', $this->kim->id)->value('id'), $first->id);
    }
}
