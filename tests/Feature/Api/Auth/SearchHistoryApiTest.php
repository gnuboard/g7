<?php

namespace Tests\Feature\Api\Auth;

use App\Extension\ModuleManager;
use App\Models\User;
use App\Models\UserSearchHistory;
use App\Support\SearchScopes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * 내 최근 검색어 API (`/api/me/search-history`, 코어 7.1.5)
 *
 * 검색창 제출 때 엔진이 부르는 본인 전용 API 다. 범위는 코어·활성 확장 선언만 받고, 같은 검색어는 한 행으로
 * 갱신하며, 범위별 보관 개수를 넘는 옛 행은 지운다. 자동 저장을 끄면 기록을 멈추고 목록을 감춘다. 로그인 직후
 * 브라우저(비회원) 목록을 시간순으로 병합한다.
 *
 * @scenario-manifest tests/scenarios/search-history.yaml
 */
class SearchHistoryApiTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 활성 회원을 만들어 로그인시킵니다.
     *
     * @return User 회원
     */
    private function actingMember(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * 검색어를 기록합니다.
     *
     * @param  string  $keyword  검색어
     * @param  string  $scope  범위
     * @return TestResponse 응답
     */
    private function record(string $keyword, string $scope = SearchScopes::CORE_SITE)
    {
        return $this->postJson('/api/me/search-history', ['scope' => $scope, 'keyword' => $keyword]);
    }

    /**
     * 공백을 정리해 기록하고, 같은 검색어는 한 행으로 맨 앞에 올리며, 목록은 최신순이다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=member_submit_records
     *
     * @effects keyword_normalized_on_record, same_keyword_deduped_and_moved_first
     */
    public function test_records_normalized_keyword_and_dedupes(): void
    {
        $user = $this->actingMember();

        Carbon::setTestNow('2026-11-04 10:00:00');
        $this->record("  노트북   가방\n")->assertOk()->assertJsonPath('data.recorded', true)->assertJsonPath('data.item.keyword', '노트북 가방');
        Carbon::setTestNow('2026-11-04 10:01:00');
        $this->record('모니터')->assertOk();
        Carbon::setTestNow('2026-11-04 10:02:00');
        $this->record('노트북 가방')->assertOk();

        $response = $this->getJson('/api/me/search-history?scope=core.site')->assertOk();

        $response->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.auto_save', true)
            ->assertJsonPath('data.max_count', 10);
        $this->assertSame(['노트북 가방', '모니터'], array_column($response->json('data.items'), 'keyword'));
        $this->assertSame(2, UserSearchHistory::query()->where('user_id', $user->id)->count());

        Carbon::setTestNow();
    }

    /**
     * 범위의 보관 개수를 넘으면 가장 오래된 검색어부터 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=max_count_trim
     *
     * @effects records_trimmed_to_scope_max_count
     */
    public function test_trims_to_scope_max_count(): void
    {
        $user = $this->actingMember();
        Config::set('g7_settings.core.privacy.search_history_scopes', [['scope' => 'core.site', 'max_count' => 3, 'retention_days' => 0]]);

        foreach (['a1', 'a2', 'a3', 'a4', 'a5'] as $i => $keyword) {
            Carbon::setTestNow(Carbon::parse('2026-11-04 10:00:00')->addMinutes($i));
            $this->record($keyword)->assertOk();
        }
        Carbon::setTestNow();

        $items = $this->getJson('/api/me/search-history?scope=core.site')->assertOk()->json('data.items');

        $this->assertSame(['a5', 'a4', 'a3'], array_column($items, 'keyword'));
        $this->assertSame(3, UserSearchHistory::query()->where('user_id', $user->id)->count(), '넘친 행은 감추는 것이 아니라 지운다');
    }

    /**
     * 선언되지 않은 범위·비활성 확장 범위는 422 로 거부하고, 활성 확장이 선언한 범위는 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=undeclared_or_inactive_scope
     *
     * @effects undeclared_scope_rejected, active_extension_scope_accepted
     */
    public function test_rejects_undeclared_scope_and_accepts_active_extension_scope(): void
    {
        $this->actingMember();

        $this->record('가방', 'acme-shop.products')->assertStatus(422)->assertJsonValidationErrors('scope');
        $this->getJson('/api/me/search-history?scope=acme-shop.products')->assertStatus(422);

        $fakeModule = new class
        {
            /**
             * 식별자를 반환합니다.
             *
             * @return string 식별자
             */
            public function getIdentifier(): string
            {
                return 'acme-shop';
            }

            /**
             * 범위 선언을 반환합니다.
             *
             * @return array<string, mixed> 범위 id → 선언
             */
            public function getSearchScopes(): array
            {
                return SearchScopes::normalize(['products' => ['max_count' => 5]], 'acme-shop');
            }
        };
        $manager = Mockery::mock(app(ModuleManager::class))->makePartial();
        $manager->shouldReceive('getActiveModules')->andReturn([$fakeModule]);
        $this->app->instance(ModuleManager::class, $manager);

        $this->record('가방', 'acme-shop.products')->assertOk()->assertJsonPath('data.recorded', true);
        $this->getJson('/api/me/search-history?scope=acme-shop.products')
            ->assertOk()
            ->assertJsonPath('data.max_count', 5)
            ->assertJsonPath('data.items.0.keyword', '가방');
    }

    /**
     * 자동 저장을 끄면 기록하지 않고 목록을 감추며, 저장된 검색어는 지우지 않는다. 다시 켜면 보인다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=auto_save_off
     *
     * @effects auto_save_off_stops_recording, auto_save_off_hides_list_keeps_rows
     */
    public function test_auto_save_off_stops_recording_and_hides_list(): void
    {
        $user = $this->actingMember();
        $this->record('남는 검색어')->assertOk();

        $this->putJson('/api/me/search-history/settings', ['auto_save' => false])->assertOk()->assertJsonPath('data.auto_save', false);
        $this->assertFalse($user->fresh()->search_history_auto_save);

        $this->record('새 검색어')->assertOk()->assertJsonPath('data.recorded', false);
        $this->getJson('/api/me/search-history?scope=core.site')
            ->assertOk()
            ->assertJsonPath('data.auto_save', false)
            ->assertJsonCount(0, 'data.items');
        $this->assertSame(['남는 검색어'], UserSearchHistory::query()->where('user_id', $user->id)->pluck('keyword')->all());

        $this->putJson('/api/me/search-history/settings', ['auto_save' => true])->assertOk();
        $this->getJson('/api/me/search-history?scope=core.site')->assertJsonPath('data.items.0.keyword', '남는 검색어');
    }

    /**
     * 본인 검색어만 지울 수 있고(남의 것은 404), 범위를 준 전체 삭제는 그 범위만 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=delete_owner_only
     *
     * @effects delete_is_owner_scoped, clear_by_scope_only
     */
    public function test_delete_is_owner_scoped_and_clear_by_scope(): void
    {
        $other = User::factory()->create();
        $othersRow = UserSearchHistory::query()->create(['user_id' => $other->id, 'scope' => 'core.site', 'keyword' => '남의 것', 'searched_at' => now()]);

        $user = $this->actingMember();
        $mine = $this->record('내 것')->json('data.item.id');
        UserSearchHistory::query()->create(['user_id' => $user->id, 'scope' => 'acme-gone.items', 'keyword' => '다른 범위', 'searched_at' => now()]);

        $this->deleteJson('/api/me/search-history/'.$othersRow->id)->assertNotFound();
        $this->assertTrue(UserSearchHistory::query()->whereKey($othersRow->id)->exists());

        $this->deleteJson('/api/me/search-history/'.$mine)->assertOk();
        $this->assertFalse(UserSearchHistory::query()->whereKey($mine)->exists());

        $this->record('하나 더')->assertOk();
        $this->deleteJson('/api/me/search-history?scope=core.site')->assertOk()->assertJsonPath('data.deleted', 1);
        $this->assertSame(['다른 범위'], UserSearchHistory::query()->where('user_id', $user->id)->pluck('keyword')->all());
    }

    /**
     * 브라우저 목록 병합 — 시간순으로 넣어 같은 검색어는 하나(늦은 시각), 미선언 범위는 건너뛰고, 보관 개수로 자른다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=login_merge
     *
     * @effects merge_dedupes_and_orders_by_time, merge_skips_undeclared_scope, merge_trims_to_max_count
     */
    public function test_merge_orders_dedupes_skips_and_trims(): void
    {
        $user = $this->actingMember();
        Config::set('g7_settings.core.privacy.search_history_scopes', [['scope' => 'core.site', 'max_count' => 3, 'retention_days' => 0]]);
        UserSearchHistory::query()->create(['user_id' => $user->id, 'scope' => 'core.site', 'keyword' => '서버', 'searched_at' => Carbon::parse('2026-11-01 09:00:00')]);

        $ms = fn (string $at) => Carbon::parse($at)->getTimestampMs();

        $this->postJson('/api/me/search-history/merge', ['items' => [
            ['scope' => 'core.site', 'keyword' => '브라우저1', 'searched_at' => $ms('2026-11-01 08:00:00')],
            ['scope' => 'core.site', 'keyword' => '서버', 'searched_at' => $ms('2026-11-01 09:30:00')],
            ['scope' => 'core.site', 'keyword' => '브라우저2', 'searched_at' => $ms('2026-11-01 10:00:00')],
            ['scope' => 'core.site', 'keyword' => ' 브라우저3 ', 'searched_at' => $ms('2026-11-01 10:30:00')],
            ['scope' => 'acme-gone.items', 'keyword' => '건너뜀', 'searched_at' => $ms('2026-11-01 11:00:00')],
        ]])->assertOk()->assertJsonPath('data.merged', 4);

        $items = $this->getJson('/api/me/search-history?scope=core.site')->json('data.items');
        $this->assertSame(['브라우저3', '브라우저2', '서버'], array_column($items, 'keyword'));
        $this->assertSame(0, UserSearchHistory::query()->where('scope', 'acme-gone.items')->count());

        $tooMany = array_fill(0, 201, ['scope' => 'core.site', 'keyword' => 'x']);
        $this->postJson('/api/me/search-history/merge', ['items' => $tooMany])->assertStatus(422)->assertJsonValidationErrors('items');
    }

    /**
     * 기능을 끈 사이트는 기록하지 않고 목록이 `enabled=false` 다. 비회원은 401 이다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=feature_disabled_or_guest
     *
     * @effects disabled_feature_records_nothing, guest_api_unauthorized
     */
    public function test_disabled_feature_and_guest(): void
    {
        $this->getJson('/api/me/search-history?scope=core.site')->assertUnauthorized();

        $this->actingMember();
        Config::set('g7_settings.core.privacy.search_history_enabled', false);

        $this->record('검색어')->assertOk()->assertJsonPath('data.recorded', false);
        $this->getJson('/api/me/search-history?scope=core.site')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonCount(0, 'data.items');
    }
}
