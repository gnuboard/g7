<?php

namespace Tests\Unit\Repositories\Concerns;

use App\Repositories\Concerns\ResolvesSortSpec;
use PHPUnit\Framework\TestCase;

/**
 * 정렬 스펙 해석 Trait 단위 테스트
 *
 * 요청 유래 값이 화이트리스트 밖으로 새지 않는지, 그리고 부적절한 값이 와도 기본값으로
 * 안전하게 폴백하는지를 고정한다.
 */
class ResolvesSortSpecTest extends TestCase
{
    /** 테스트용 리포지토리를 생성합니다. */
    private function repository(): object
    {
        return new class
        {
            use ResolvesSortSpec;

            /**
             * 정렬 스펙 해석을 그대로 노출합니다. (Trait 메서드는 protected)
             *
             * @param  array  $args  resolveSortSpec 인자
             * @return array 정렬 스펙
             */
            public function resolve(...$args): array
            {
                return $this->resolveSortSpec(...$args);
            }
        };
    }

    public function test_allowed_column_and_direction_pass_through(): void
    {
        $spec = $this->repository()->resolve(
            ['sort_by' => 'created_at', 'sort_order' => 'asc'],
            ['id', 'created_at', 'status'],
            'id',
        );

        $this->assertSame([['column' => 'created_at', 'direction' => 'asc']], $spec);
    }

    public function test_unknown_column_falls_back_to_default(): void
    {
        $spec = $this->repository()->resolve(
            ['sort_by' => 'admin_memo', 'sort_order' => 'desc'],
            ['id', 'created_at'],
            'id',
        );

        $this->assertSame('id', $spec[0]['column']);
    }

    public function test_invalid_direction_falls_back_to_default(): void
    {
        $spec = $this->repository()->resolve(
            ['sort_by' => 'created_at', 'sort_order' => 'sideways'],
            ['created_at'],
            'created_at',
            'asc',
        );

        $this->assertSame('asc', $spec[0]['direction']);
    }

    public function test_column_alias_is_applied_before_whitelist_check(): void
    {
        $spec = $this->repository()->resolve(
            ['sort_by' => 'author', 'sort_order' => 'desc'],
            ['id', 'author_name'],
            'id',
            'desc',
            ['author' => 'author_name'],
        );

        $this->assertSame('author_name', $spec[0]['column']);
    }

    public function test_custom_filter_keys_are_honored(): void
    {
        $spec = $this->repository()->resolve(
            ['order_by' => 'view_count', 'order_direction' => 'asc'],
            ['id', 'view_count'],
            'id',
            'desc',
            [],
            'order_by',
            'order_direction',
        );

        $this->assertSame([['column' => 'view_count', 'direction' => 'asc']], $spec);
    }

    public function test_backed_enum_values_are_normalized(): void
    {
        $spec = $this->repository()->resolve(
            ['sort_by' => SampleSortColumn::CreatedAt, 'sort_order' => SampleSortDirection::Asc],
            ['id', 'created_at'],
            'id',
        );

        $this->assertSame([['column' => 'created_at', 'direction' => 'asc']], $spec);
    }

    public function test_missing_and_blank_values_fall_back(): void
    {
        $spec = $this->repository()->resolve(
            ['sort_by' => '   '],
            ['id', 'created_at'],
            'created_at',
            'desc',
        );

        $this->assertSame([['column' => 'created_at', 'direction' => 'desc']], $spec);
    }

    public function test_array_value_is_rejected(): void
    {
        $spec = $this->repository()->resolve(
            ['sort_by' => ['created_at'], 'sort_order' => ['asc']],
            ['id', 'created_at'],
            'id',
        );

        $this->assertSame([['column' => 'id', 'direction' => 'desc']], $spec);
    }
}

/** 테스트용 정렬 컬럼 Enum */
enum SampleSortColumn: string
{
    case CreatedAt = 'created_at';
}

/** 테스트용 정렬 방향 Enum */
enum SampleSortDirection: string
{
    case Asc = 'asc';
}
