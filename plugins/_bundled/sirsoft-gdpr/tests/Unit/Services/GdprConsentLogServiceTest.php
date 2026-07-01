<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Services;

use App\Models\User;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsentHistory;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentLogService;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * GDPR 관리자 동의 로그 조회 서비스 Unit 테스트
 */
class GdprConsentLogServiceTest extends PluginTestCase
{
    private GdprConsentLogService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(GdprConsentLogService::class);
    }

    /**
     * 동의 이력 행 생성 헬퍼.
     *
     * @param  array<string, mixed>  $overrides
     * @return GdprUserConsentHistory
     */
    private function makeHistory(array $overrides = []): GdprUserConsentHistory
    {
        return GdprUserConsentHistory::create(array_merge([
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'banner',
            'policy_version' => '1.0',
        ], $overrides));
    }

    public function test_paginate_returns_empty_when_no_records(): void
    {
        $paginator = $this->service->paginateForAdmin([], 20);

        $this->assertSame(0, $paginator->total());
        $this->assertSame([], $paginator->items());
    }

    public function test_paginate_orders_by_created_at_desc(): void
    {
        $user = User::factory()->create();

        $this->makeHistory(['user_id' => $user->id, 'consent_key' => 'cookie_analytics', 'created_at' => now()->subDay()]);
        $this->makeHistory(['user_id' => $user->id, 'consent_key' => 'cookie_marketing', 'created_at' => now()]);

        $paginator = $this->service->paginateForAdmin([], 20);

        $items = $paginator->items();
        $this->assertSame(2, $paginator->total());
        $this->assertSame('cookie_marketing', $items[0]->consent_key);
    }

    public function test_paginate_filters_by_email_partial_match(): void
    {
        $alice = User::factory()->create(['email' => 'alice@example.com']);
        $bob = User::factory()->create(['email' => 'bob@example.com']);

        $this->makeHistory(['user_id' => $alice->id]);
        $this->makeHistory(['user_id' => $bob->id]);

        // 부분 일치 — 'alice' 만으로 Alice 행 조회
        $paginator = $this->service->paginateForAdmin(['email' => 'alice'], 20);

        $this->assertSame(1, $paginator->total());
        $this->assertSame($alice->id, $paginator->items()[0]->user_id);
    }

    public function test_paginate_filters_by_email_substring_in_middle(): void
    {
        $user = User::factory()->create(['email' => 'someone@special.io']);
        $other = User::factory()->create(['email' => 'other@example.com']);

        $this->makeHistory(['user_id' => $user->id]);
        $this->makeHistory(['user_id' => $other->id]);

        // 도메인 부분 일치
        $paginator = $this->service->paginateForAdmin(['email' => 'special'], 20);

        $this->assertSame(1, $paginator->total());
        $this->assertSame($user->id, $paginator->items()[0]->user_id);
    }

    public function test_paginate_filters_by_actions_array(): void
    {
        $user = User::factory()->create();

        $this->makeHistory(['user_id' => $user->id, 'action' => 'granted']);
        $this->makeHistory(['user_id' => $user->id, 'action' => 'revoked']);

        // 단일 액션
        $paginator = $this->service->paginateForAdmin(['actions' => ['revoked']], 20);
        $this->assertSame(1, $paginator->total());
        $this->assertSame('revoked', $paginator->items()[0]->action);

        // 다중 액션 — 모두 매칭
        $paginator = $this->service->paginateForAdmin(['actions' => ['granted', 'revoked']], 20);
        $this->assertSame(2, $paginator->total());
    }

    public function test_paginate_filters_by_sources_array(): void
    {
        $user = User::factory()->create();

        $this->makeHistory(['user_id' => $user->id, 'source' => 'banner']);
        $this->makeHistory(['user_id' => $user->id, 'source' => 'mypage']);
        $this->makeHistory(['user_id' => $user->id, 'source' => 'preference_center']);

        $paginator = $this->service->paginateForAdmin(['sources' => ['mypage', 'preference_center']], 20);

        $this->assertSame(2, $paginator->total());
    }

    public function test_paginate_filters_by_consent_keys_array(): void
    {
        $user = User::factory()->create();

        $this->makeHistory(['user_id' => $user->id, 'consent_key' => 'cookie_necessary']);
        $this->makeHistory(['user_id' => $user->id, 'consent_key' => 'cookie_analytics']);
        $this->makeHistory(['user_id' => $user->id, 'consent_key' => 'cookie_marketing']);

        $paginator = $this->service->paginateForAdmin(['consent_keys' => ['cookie_analytics', 'cookie_marketing']], 20);

        $this->assertSame(2, $paginator->total());
    }

    public function test_paginate_filters_by_session_id(): void
    {
        $this->makeHistory(['session_id' => 'abc123', 'user_id' => null]);
        $this->makeHistory(['session_id' => 'xyz999', 'user_id' => null]);

        $paginator = $this->service->paginateForAdmin(['session_id' => 'abc123'], 20);

        $this->assertSame(1, $paginator->total());
        $this->assertSame('abc123', $paginator->items()[0]->session_id);
    }

    public function test_paginate_empty_filter_arrays_returns_all(): void
    {
        $user = User::factory()->create();
        $this->makeHistory(['user_id' => $user->id, 'consent_key' => 'cookie_analytics']);
        $this->makeHistory(['user_id' => $user->id, 'consent_key' => 'cookie_marketing']);

        // 빈 배열은 전체 조회 (필터 적용 안 함)
        $paginator = $this->service->paginateForAdmin([
            'consent_keys' => [],
            'actions' => [],
            'sources' => [],
        ], 20);

        $this->assertSame(2, $paginator->total());
    }

    public function test_paginate_clamps_per_page_to_min_1_max_100(): void
    {
        $user = User::factory()->create();
        $this->makeHistory(['user_id' => $user->id]);

        $tooLow = $this->service->paginateForAdmin([], 0);
        $tooHigh = $this->service->paginateForAdmin([], 9999);

        $this->assertSame(1, $tooLow->perPage());
        $this->assertSame(100, $tooHigh->perPage());
    }
}
