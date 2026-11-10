<?php

namespace Tests\Feature\Privacy;

use App\Models\User;
use App\Models\UserSearchHistory;
use App\Services\UserService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 탈퇴·삭제 회원의 최근 검색어 명시 삭제 (코어 7.1.5)
 *
 * 탈퇴는 users 행을 남기므로 FK CASCADE 가 돌지 않는다 — 서비스가 지우지 않으면 탈퇴 회원의 검색어가 그대로 남는다.
 * 삭제도 CASCADE 에 기대지 않고 같은 트랜잭션에서 명시 삭제한다.
 *
 * @scenario-manifest tests/scenarios/search-history.yaml
 */
class SearchHistoryUserPurgeTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 검색어가 있는 회원과 다른 회원을 만듭니다.
     *
     * @return array{0: User, 1: User} [대상 회원, 다른 회원]
     */
    private function membersWithHistory(): array
    {
        $target = User::factory()->create();
        $other = User::factory()->create();

        foreach ([$target, $other] as $user) {
            UserSearchHistory::query()->create(['user_id' => $user->id, 'scope' => 'core.site', 'keyword' => '검색어', 'searched_at' => now()]);
            UserSearchHistory::query()->create(['user_id' => $user->id, 'scope' => 'acme-gone.items', 'keyword' => '다른 범위', 'searched_at' => now()]);
        }

        return [$target, $other];
    }

    /**
     * 탈퇴하면 그 회원의 검색어가 모든 범위에서 지워지고 다른 회원의 것은 남는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=withdraw_purge
     *
     * @effects withdraw_purges_search_history
     */
    public function test_withdraw_purges_search_history(): void
    {
        [$target, $other] = $this->membersWithHistory();

        $this->assertTrue(app(UserService::class)->withdrawUser($target));

        $this->assertSame(0, UserSearchHistory::query()->where('user_id', $target->id)->count());
        $this->assertSame(2, UserSearchHistory::query()->where('user_id', $other->id)->count());
    }

    /**
     * 회원을 삭제하면 그 회원의 검색어가 지워진다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=delete_purge
     *
     * @effects delete_purges_search_history
     */
    public function test_delete_purges_search_history(): void
    {
        [$target, $other] = $this->membersWithHistory();
        $targetId = $target->id;

        $this->assertTrue(app(UserService::class)->deleteUser($target));

        $this->assertSame(0, UserSearchHistory::query()->where('user_id', $targetId)->count());
        $this->assertSame(2, UserSearchHistory::query()->where('user_id', $other->id)->count());
    }
}
