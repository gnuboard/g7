<?php

namespace Tests\Unit\Jobs;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Jobs\RecomputeNicknameUniqueKeysJob;
use App\Models\User;
use App\Services\NicknamePolicyService;
use Illuminate\Database\Eloquent\Collection;
use Mockery;
use Tests\TestCase;

/**
 * 닉네임 중복 키 재계산 작업의 규모 성질 — 회원이 10만 명이어도 한 번에 묶음 크기만큼만 읽고,
 * 이미 맞는 행은 쓰지 않으며, 진행 수가 전체 수와 맞게 끝난다.
 *
 * 실제 대용량 DB 대신 저장소를 흉내 내 읽기 크기와 쓰기 횟수를 센다.
 */
class RecomputeNicknameUniqueKeysJobScaleTest extends TestCase
{
    /**
     * 목을 정리한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 10만 명을 훑어도 읽기는 항상 묶음 크기이고, 고칠 것이 있는 행만 쓴다.
     *
     * 자료: ID 1부터 10만까지. 1,000 번째마다 정규화 값이 비어 있고(업그레이드 전 가입), 그중 절반은 닉네임이 서로 겹친다.
     *
     * @scenario check=recompute_scale
     *
     * @effects recompute_reads_in_bounded_chunks
     *
     * @scale n=100000 asserts=chunked_reads_bounded, writes_only_changed_rows, progress_reaches_total
     *
     * @return void 반환값 없음
     */
    public function test_hundred_thousand_members_are_processed_in_bounded_chunks(): void
    {
        $total = 100000;
        $chunk = RecomputeNicknameUniqueKeysJob::CHUNK_SIZE;
        config(['g7_settings.core.member.nickname_unique' => true]);

        // 행 상태 — 고친 값만 기억한다(나머지는 규칙으로 만든다).
        $overrides = [];
        $row = function (int $id) use (&$overrides): array {
            $stale = $id % 1000 === 0;
            $duplicate = $stale && $id % 2000 === 0;
            $nickname = $duplicate ? '겹침 '.intdiv($id, 20000) : 'nick'.$id;
            $base = [
                'nickname' => $nickname,
                'nickname_normalized' => $stale ? null : 'nick'.$id,
                'nickname_unique_key' => $stale ? null : 'nick'.$id,
            ];

            return array_merge($base, $overrides[$id] ?? []);
        };

        $reads = [];
        $maxRowsHeld = 0;
        $writes = 0;

        $users = Mockery::mock(UserRepositoryInterface::class);
        $users->shouldReceive('countMembersWithNickname')->once()->andReturn($total);
        $users->shouldReceive('nicknameRowsAfter')->andReturnUsing(function (int $afterId, int $limit) use ($total, $row, &$reads, &$maxRowsHeld) {
            $reads[] = $limit;
            $models = [];
            for ($id = $afterId + 1; $id <= min($afterId + $limit, $total); $id++) {
                $models[] = (new User)->forceFill(['id' => $id] + $row($id));
            }
            $maxRowsHeld = max($maxRowsHeld, count($models));

            return new Collection($models);
        });
        $users->shouldReceive('duplicatedNicknameNormalized')->andReturnUsing(
            fn (array $values) => array_values(array_filter(array_unique($values), fn ($value) => str_starts_with((string) $value, '겹침'))),
        );
        $users->shouldReceive('updateNicknameKeys')->andReturnUsing(function (int $id, array $columns) use (&$overrides, &$writes) {
            $writes++;
            $overrides[$id] = array_merge($overrides[$id] ?? [], $columns);
        });
        $users->shouldReceive('clearNicknameUniqueKeys')->never();

        $progress = [];
        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('put')->andReturnUsing(function (string $key, array $value) use (&$progress) {
            $progress = $value;

            return true;
        });

        (new RecomputeNicknameUniqueKeysJob)->handle($users, new NicknamePolicyService($users), $cache);

        // 읽기 — 항상 묶음 크기로만 묻고, 한 번에 든 행도 묶음 크기를 넘지 않는다.
        $this->assertSame([$chunk], array_values(array_unique($reads)));
        $this->assertLessThanOrEqual($chunk, $maxRowsHeld);
        $this->assertLessThanOrEqual(2 * (intdiv($total, $chunk) + 1), count($reads), '훑는 횟수가 두 번(정규화 값, 중복 키)을 넘었다');

        // 쓰기 — 정규화 값이 비어 있던 100행만 고친다. 그중 겹치지 않는 50행은 중복 키까지 한 번 더 쓴다.
        $this->assertSame(150, $writes);
        $this->assertSame(['nickname_normalized' => 'nick1000', 'nickname_unique_key' => 'nick1000'], $overrides[1000]);
        $this->assertSame(['nickname_normalized' => '겹침0', 'nickname_unique_key' => null], $overrides[2000]);

        // 진행 — 두 번 훑은 수로 끝난다.
        $this->assertSame(['done', $total * 2, $total * 2], [$progress['status'], $progress['processed'], $progress['total']]);
    }
}
