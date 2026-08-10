<?php

namespace Tests\Unit\Support\Query;

use App\Enums\TotalRelation;
use App\Models\User;
use App\Support\Query\BoundedCount;
use App\Support\Query\BoundedPaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BoundedCount 계약 테스트
 *
 * 목록을 조회하지 않고 건수만 세는 자리(탭 배지 등)에서, 상한에 걸려 잘린 값이
 * 정확한 것처럼 보고되지 않는지 확인한다. 잘린 값은 오류로 드러나지 않고 그냥
 * 틀린 숫자로만 보이므로 이 계약이 유일한 방어선이다.
 */
class BoundedCountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 상한 이하면 정확한 건수와 Exact 정확도를 보고하는지 확인
     */
    public function test_reports_exact_when_under_cap(): void
    {
        User::factory()->count(6)->create();

        $count = BoundedPaginator::count(User::query(), 100);

        $this->assertInstanceOf(BoundedCount::class, $count);
        $this->assertSame(User::query()->count(), $count->total);
        $this->assertSame(TotalRelation::Exact, $count->totalRelation());
        $this->assertFalse($count->isTruncated());
        $this->assertSame(100, $count->resultCap());
    }

    /**
     * 상한을 넘으면 상한값 + AtLeast 를 보고하는지 확인
     */
    public function test_reports_at_least_when_over_cap(): void
    {
        User::factory()->count(9)->create();

        $count = BoundedPaginator::count(User::query(), 4);

        $this->assertSame(4, $count->total);
        $this->assertSame(TotalRelation::AtLeast, $count->totalRelation());
        $this->assertTrue($count->isTruncated());
    }

    /**
     * 상한이 없으면(0 또는 null) 항상 정확한 건수를 세는지 확인
     */
    public function test_null_cap_means_unlimited(): void
    {
        User::factory()->count(5)->create();

        $count = BoundedPaginator::count(User::query(), null);

        $this->assertSame(User::query()->count(), $count->total);
        $this->assertFalse($count->isTruncated());
        $this->assertNull($count->resultCap());
    }

    /**
     * 응답에 실을 필드 묶음이 한 곳에서 조립되는지 확인
     *
     * 배지마다 키를 손으로 조립하면 한 군데만 빠져도 그 화면에서만 잘린 값이
     * 정확한 것처럼 나간다.
     */
    public function test_to_array_carries_every_accuracy_field(): void
    {
        User::factory()->count(9)->create();

        $payload = BoundedPaginator::count(User::query(), 4)->toArray();

        $this->assertSame([
            'total' => 4,
            'total_relation' => 'at_least',
            'total_is_exact' => false,
            'result_cap' => 4,
        ], $payload);
    }

    /**
     * 필터가 걸린 쿼리에서도 그 술어 기준으로 세는지 확인
     */
    public function test_respects_query_filters(): void
    {
        User::factory()->count(4)->create(['status' => 'active']);
        User::factory()->count(3)->create(['status' => 'inactive']);

        $count = BoundedPaginator::count(User::query()->where('status', 'active'), 100);

        $this->assertSame(4, $count->total);
        $this->assertFalse($count->isTruncated());
    }
}
