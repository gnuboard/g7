<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Repositories;

use Illuminate\Support\Carbon;
use Plugins\Sirsoft\Sso\Models\SsoLoginAttempt;
use Plugins\Sirsoft\Sso\Repositories\Contracts\LoginAttemptRepositoryInterface;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 로그인 시도 이력 저장소 — 보존 정리(`pruneBefore`)가 기준 시각 전 행만 지우는지.
 *
 * @since 1.0.0
 */
class LoginAttemptRepositoryTest extends PluginTestCase
{
    /**
     * 기준 시각 전 행만 지우고 지운 수를 돌려주는지 확인한다.
     *
     * @scenario created_at=before|at|after
     *
     * @effects only_older_rows_deleted, deleted_count_returned
     *
     * @return void 반환값 없음
     */
    public function test_prune_before_deletes_only_older_rows(): void
    {
        $idp = $this->mockOidcIdp();
        $cutoff = Carbon::parse('2026-07-01 00:00:00');
        foreach (['old-1' => '2026-06-01 00:00:00', 'old-2' => '2026-06-30 23:59:59', 'at' => '2026-07-01 00:00:00', 'new' => '2026-07-02 00:00:00'] as $reason => $at) {
            SsoLoginAttempt::query()->create([
                'idp_id' => $idp->id,
                'intent' => 'login',
                'guard' => 'user',
                'outcome' => SsoLoginAttempt::OUTCOME_FAILED,
                'reason_code' => $reason,
                'created_at' => Carbon::parse($at),
            ]);
        }

        $deleted = app(LoginAttemptRepositoryInterface::class)->pruneBefore($cutoff);

        $this->assertSame(2, $deleted);
        $this->assertSame(['at', 'new'], SsoLoginAttempt::query()->where('idp_id', $idp->id)->orderBy('created_at')->pluck('reason_code')->all());
    }
}
