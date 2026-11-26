<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\User;

use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 마이페이지 내 리뷰 목록 API
 */
class UserReviewListTest extends ModuleTestCase
{
    /** 내 리뷰 주소 */
    private const URL = '/api/modules/sirsoft-ecommerce/user/reviews';

    /**
     * 내가 쓴 리뷰만 최신순으로, 지운 리뷰는 빼고, 삭제 가능 표시와 함께 준다.
     *
     * @scenario case=user_reviews_own_only
     *
     * @effects user_reviews_own_only
     *
     * @return void 반환값 없음
     */
    public function test_lists_only_my_reviews_excluding_deleted(): void
    {
        $user = $this->createUser();
        $older = ProductReview::factory()->create(['user_id' => $user->id, 'created_at' => now()->subDay()]);
        $newer = ProductReview::factory()->create(['user_id' => $user->id]);
        $deleted = ProductReview::factory()->create(['user_id' => $user->id]);
        $deleted->delete();
        ProductReview::factory()->create();

        $rows = $this->actingAs($user)->getJson(self::URL)->assertOk()->json('data.data');

        $this->assertSame([$newer->id, $older->id], array_column($rows, 'id'));
        $this->assertTrue($rows[0]['is_deletable']);
    }

    /**
     * 페이지 나누기 — per_page 만큼 주고 pagination 정보가 실린다. 상한(50)을 넘으면 422, 비로그인은 401 이다.
     *
     * @scenario case=user_reviews_paging
     *
     * @effects user_reviews_paginated
     *
     * @return void 반환값 없음
     */
    public function test_pagination_and_guards(): void
    {
        $user = $this->createUser();
        ProductReview::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson(self::URL.'?per_page=2&page=2')->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame(3, (int) ($response->json('data.pagination.total') ?? $response->json('data.total')));

        $this->actingAs($user)->getJson(self::URL.'?per_page=51')->assertStatus(422);
    }

    /**
     * 비로그인 요청은 401 이다.
     *
     * @scenario case=user_reviews_guest
     *
     * @effects user_reviews_requires_login
     *
     * @return void 반환값 없음
     */
    public function test_guest_is_rejected(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }
}
