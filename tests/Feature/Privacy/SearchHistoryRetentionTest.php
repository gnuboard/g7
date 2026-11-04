<?php

namespace Tests\Feature\Privacy;

use App\Models\User;
use App\Models\UserSearchHistory;
use App\Privacy\Handlers\SearchHistoryRetentionHandler;
use App\Privacy\ManualRun\PrivacyManualRunRegistry;
use App\Privacy\PrivacyRetentionManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 최근 검색어 보관 기한 파기 (코어 7.1.5)
 *
 * 환경설정 > 개인정보 「최근 검색어」 카드의 범위별 보관 기한(일)이 지난 검색어를 개인정보 보유기간 작업이 지운다.
 * 0(기한 없음)인 범위는 건드리지 않고, 기한을 적은 범위가 하나도 없으면 꺼진 것으로 보고한다. 명단은 회원·범위·건수·
 * 가장 오래된 검색일만 보이고 검색어는 보이지 않는다.
 *
 * @scenario-manifest tests/scenarios/search-history.yaml
 */
class SearchHistoryRetentionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 검색어 행을 만듭니다.
     *
     * @param  User  $user  회원
     * @param  string  $scope  범위
     * @param  string  $keyword  검색어
     * @param  int  $daysAgo  며칠 전 검색인지
     * @return UserSearchHistory 행
     */
    private function row(User $user, string $scope, string $keyword, int $daysAgo): UserSearchHistory
    {
        return UserSearchHistory::query()->create([
            'user_id' => $user->id,
            'scope' => $scope,
            'keyword' => $keyword,
            'searched_at' => Carbon::now()->subDays($daysAgo),
        ]);
    }

    /**
     * 범위마다 정한 기한이 지난 검색어만 지우고, 기한 없음(0) 범위와 기한 안의 검색어는 남긴다.
     * 미리보기·dry-run·실제 처리 건수가 같다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=retention_per_scope
     *
     * @effects retention_deletes_only_expired_rows_per_scope, retention_zero_days_keeps_rows, retention_preview_matches_process
     */
    public function test_deletes_only_rows_past_their_scope_retention(): void
    {
        $user = User::factory()->create();
        Config::set('g7_settings.core.privacy.search_history_scopes', [
            ['scope' => 'core.site', 'max_count' => 10, 'retention_days' => 30],
            ['scope' => 'acme-gone.items', 'max_count' => 10, 'retention_days' => 0],
        ]);

        $old = $this->row($user, 'core.site', '오래됨', 40);
        $fresh = $this->row($user, 'core.site', '최근', 10);
        $keep = $this->row($user, 'acme-gone.items', '기한 없음', 400);

        $handler = app(SearchHistoryRetentionHandler::class);

        $this->assertTrue($handler->isEnabled());
        $this->assertSame(1, $handler->preview());
        $this->assertSame(['count' => 1, 'mode' => 'delete'], $handler->process(dryRun: true));
        $this->assertTrue(UserSearchHistory::query()->whereKey($old->id)->exists(), 'dry-run 은 지우지 않는다');

        $this->assertSame(['count' => 1, 'mode' => 'delete'], $handler->process(dryRun: false));
        $this->assertFalse(UserSearchHistory::query()->whereKey($old->id)->exists());
        $this->assertTrue(UserSearchHistory::query()->whereKey($fresh->id)->exists());
        $this->assertTrue(UserSearchHistory::query()->whereKey($keep->id)->exists());
        $this->assertSame(0, $handler->preview(), '다시 세면 0 — 멱등');
    }

    /**
     * 기한을 적은 범위가 없으면 꺼짐으로 보고하고 아무것도 지우지 않는다(기본값 — 설치만으로 지워지지 않는다).
     *
     * @return void 반환값 없음
     *
     * @scenario case=retention_disabled_by_default
     *
     * @effects retention_disabled_without_any_scope_days
     */
    public function test_disabled_when_no_scope_has_retention_days(): void
    {
        $user = User::factory()->create();
        $row = $this->row($user, 'core.site', '아주 오래됨', 3000);

        $handler = app(SearchHistoryRetentionHandler::class);

        $this->assertFalse($handler->isEnabled());
        $this->assertSame(['count' => 0, 'mode' => 'disabled'], $handler->process(dryRun: false));
        $this->assertTrue(UserSearchHistory::query()->whereKey($row->id)->exists());
    }

    /**
     * 대상 명단은 회원·범위로 묶어 건수와 가장 오래된 검색일만 내고 검색어는 싣지 않는다. 코어 기본 핸들러로 등록되어
     * 직권 실행 대상(`retention:search-history`)이 저절로 생긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=retention_targets_and_manual_run
     *
     * @effects retention_targets_hide_keywords, retention_registered_as_core_handler_with_manual_run
     */
    public function test_targets_hide_keywords_and_handler_is_registered(): void
    {
        $user = User::factory()->create(['name' => '검색 회원']);
        Config::set('g7_settings.core.privacy.search_history_scopes', [
            ['scope' => 'core.site', 'max_count' => 10, 'retention_days' => 30],
        ]);
        $this->row($user, 'core.site', '비밀스러운 관심사', 50);
        $this->row($user, 'core.site', '다른 관심사', 45);

        $handler = app(SearchHistoryRetentionHandler::class);
        $page = $handler->targets(1, 20);

        $this->assertSame(1, $page->total(), '회원·범위 하나로 묶인다');
        $row = $page->items()[0]->toArray();
        $this->assertSame($user->id.':core.site', $row['id']);
        $this->assertSame('검색 회원', $row['title']);
        $this->assertSame(__('search.history.scopes.core_site'), $row['subtitle']);
        $this->assertSame(__('privacy.targets.keyword_count_value', ['count' => 2]), $row['context']);
        $this->assertSame('/admin/users/'.$user->uuid, $row['url']);
        $this->assertStringNotContainsString('관심사', json_encode($row, JSON_UNESCAPED_UNICODE), '검색어는 명단에 나오지 않는다');
        $this->assertSame(['title', 'subtitle', 'context', 'date'], array_keys($handler->targetColumns()));

        $this->assertArrayHasKey('search-history', app(PrivacyRetentionManager::class)->handlers());
        $this->assertNotNull(app(PrivacyManualRunRegistry::class)->runner('retention:search-history'));
    }
}
