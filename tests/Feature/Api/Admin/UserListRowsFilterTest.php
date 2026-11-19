<?php

namespace Tests\Feature\Api\Admin;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 관리자 회원 목록 열 데이터 일괄 필터 `core.user.filter_list_rows` (#40 K6 · P22)
 *
 * 목록은 행마다 리소스 필터를 걸지 않고(N+1) 페이지 전체 행과 회원 묶음을 한 번 넘긴다. 확장이 더한 키는 응답에 실리고,
 * 배열이 아니거나 행 수가 다른 반환은 버리고 원래 행을 쓴다.
 */
class UserListRowsFilterTest extends TestCase
{
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /**
     * 관리자로 회원 목록을 요청합니다.
     *
     * @return TestResponse 응답
     */
    private function listAsAdmin(): TestResponse
    {
        $admin = $this->makeAdmin(['email' => 'list.admin.'.Str::lower(Str::random(6)).'@example.test']);
        $this->makeMember(['email' => 'list.member.'.Str::lower(Str::random(6)).'@example.test']);

        return $this->withHeader('Authorization', 'Bearer '.$this->issueToken($admin))->getJson('/api/admin/users?per_page=10');
    }

    /**
     * 필터는 요청당 한 번, 페이지 행 수와 같은 회원 묶음으로 호출되고 더한 키가 응답에 실린다.
     *
     * @scenario filter_return=augmented
     *
     * @effects list_rows_filter_called_once, list_rows_filter_receives_users, list_rows_filter_additions_in_response
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 필터는_한_번_호출되고_더한_키가_실린다(): void
    {
        $calls = [];
        HookManager::addFilter('core.user.filter_list_rows', function (array $rows, Collection $users) use (&$calls) {
            $calls[] = ['rows' => count($rows), 'users' => $users->count(), 'first' => $users->first()];

            return array_map(fn (array $row) => $row + ['ext_marker' => 'x'], $rows);
        }, 10);

        $response = $this->listAsAdmin()->assertOk();

        $this->assertCount(1, $calls, '목록 1회 요청에 필터 1회');
        $this->assertSame($calls[0]['rows'], $calls[0]['users']);
        $this->assertInstanceOf(User::class, $calls[0]['first']);
        $this->assertGreaterThanOrEqual(2, $calls[0]['rows']);
        $this->assertSame('x', $response->json('data.data.0.ext_marker'));
    }

    /**
     * 행 수가 다른 반환은 버리고 원래 행을 쓴다 (배열이 아닌 반환도 같은 분기).
     *
     * @scenario filter_return=wrong_count
     *
     * @effects list_rows_filter_bad_return_ignored
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 잘못된_반환은_버린다(): void
    {
        HookManager::addFilter('core.user.filter_list_rows', fn (array $rows) => array_slice($rows, 0, 1), 10);

        $response = $this->listAsAdmin()->assertOk();

        $this->assertGreaterThanOrEqual(2, count($response->json('data.data')));
        $this->assertNotNull($response->json('data.data.0.uuid'));
    }
}
