<?php

namespace Tests\Feature\Analytics;

use App\Contracts\Repositories\Analytics\ActionNameRepositoryInterface;
use App\Models\Analytics\AnalyticsActionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 이름 사전 — 같은 이름이 한 행으로 모이고, 동시 삽입이 비콘을 깨뜨리지 않는다.
 *
 * 두 요청이 같은 새 주소를 동시에 알리면 둘 다 「없음」을 보고 삽입해 한쪽이 유니크 제약에
 * 걸린다. 그 예외가 밖으로 나가면 그 방문의 비콘 전체가 실패 로그행이 된다.
 */
class ActionNameRepositoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 같은 종류·이름은 같은 행을 돌려준다.
     *
     * @return void 없음
     *
     * @scenario source=beacon, visit_state=open, visitor=guest
     *
     * @effects dictionary_returns_one_row_per_type_and_name
     */
    public function test_same_type_and_name_return_the_same_row(): void
    {
        $repo = app(ActionNameRepositoryInterface::class);

        $a = $repo->firstOrCreateByHash('url', 'example.test/a', 2);
        $b = $repo->firstOrCreateByHash('url', 'example.test/a', 2);
        $c = $repo->firstOrCreateByHash('title', 'example.test/a');

        $this->assertSame($a->id, $b->id);
        $this->assertNotSame($a->id, $c->id, '종류가 다르면 다른 행');
        $this->assertSame(2, AnalyticsActionName::count());
    }

    /**
     * 조회와 삽입 사이에 다른 요청이 같은 이름을 넣어도 예외 없이 그 행을 돌려준다.
     *
     * 경합은 삽입 직전에 같은 행을 끼워 넣어 재현한다 — 모델의 `creating` 시점은 조회가
     * 「없음」을 확인한 뒤이고 실제 INSERT 전이다.
     *
     * @return void 없음
     *
     * @scenario source=beacon, visit_state=open, visitor=guest
     *
     * @effects concurrent_dictionary_insert_falls_back_to_reread
     */
    public function test_concurrent_insert_falls_back_to_reread(): void
    {
        $repo = app(ActionNameRepositoryInterface::class);
        $hash = md5('url|example.test/race');

        AnalyticsActionName::creating(function (AnalyticsActionName $model) use ($hash): void {
            if ($model->name_hash !== $hash || DB::table('analytics_action_names')->where('name_hash', $hash)->exists()) {
                return;
            }

            DB::table('analytics_action_names')->insert([
                'type' => 'url',
                'name' => 'example.test/race',
                'name_hash' => $hash,
                'created_at' => now(),
            ]);
        });

        $row = $repo->firstOrCreateByHash('url', 'example.test/race');

        $this->assertSame('example.test/race', $row->name);
        $this->assertSame(1, AnalyticsActionName::query()->where('name_hash', $hash)->count());
    }
}
