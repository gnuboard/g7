<?php

namespace Tests\Unit\Repositories\Concerns;

use App\Repositories\Concerns\ResolvesSortSpec;
use App\Repositories\Concerns\SortsByRelatedColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 관계 테이블 컬럼 정렬 Trait 단위 테스트
 *
 * 이 Trait 이 존재하는 이유는 "조인 없이" 관계 컬럼으로 정렬하기 위해서다. 조인+GROUP BY 로
 * 구현하면 1:N 조인이 원 행을 부풀려 총 건수와 페이지 경계가 깨지고, 지연 조인의 inner 에
 * 집계를 붙이면 건너뛸 행 전체에 집계가 돌아 지연 조인 도입 이유가 사라진다.
 *
 * 따라서 여기서 고정하는 계약은 (1) 관계 키만 서브쿼리로 해석되고 나머지는 일반 경로로
 * 폴백하는지, (2) 만들어진 SQL 이 상관 서브쿼리 형태이며 JOIN·GROUP BY 를 쓰지 않는지,
 * (3) 서브쿼리 정렬 방향이 요청 방향과 일치하는지다.
 *
 * DB 에 접근하지 않는다 — 쿼리를 조립해 SQL 문자열만 검사한다.
 */
class SortsByRelatedColumnTest extends TestCase
{
    /**
     * 관계 정렬 스펙 맵 (주문 → 배송의 발송일)
     *
     * @return array<string, array{model: class-string<Model>, foreign_key: string, column: string}>
     */
    private function relatedMap(): array
    {
        return [
            'shipped_at' => [
                'model' => SortsByRelatedColumnShipping::class,
                'foreign_key' => 'order_id',
                'column' => 'shipped_at',
            ],
        ];
    }

    /**
     * Trait 을 노출하는 테스트용 리포지토리를 만듭니다.
     *
     * @return object 리포지토리 대역
     */
    private function repository(): object
    {
        return new class
        {
            use ResolvesSortSpec;
            use SortsByRelatedColumn;

            /**
             * 정렬 스펙 해석을 그대로 노출합니다. (Trait 메서드는 protected)
             *
             * @param  array  $args  resolveSortSpecWithRelated 인자
             * @return array 정렬 스펙
             */
            public function resolve(...$args): array
            {
                return $this->resolveSortSpecWithRelated(...$args);
            }
        };
    }

    /**
     * 프리픽스가 적용된 실제 테이블명을 돌려줍니다.
     *
     * 테스트 단언에도 테이블명·프리픽스를 문자열로 조립하지 않는다는 규정을 적용합니다 —
     * 프리픽스 설정이 바뀌면 단언이 조용히 깨지거나(혹은 통과해) 검사 의미를 잃습니다.
     *
     * @param  Model  $model  대상 모델
     * @return string 프리픽스 포함 테이블명
     */
    private function table(Model $model): string
    {
        return DB::getTablePrefix().$model->getTable();
    }

    /**
     * 정렬 스펙을 해석합니다.
     *
     * @param  array<string, mixed>  $filters  요청 유래 필터
     * @return array<int, array{column: string|Builder, direction: string}> 정렬 스펙
     */
    private function resolve(array $filters): array
    {
        return $this->repository()->resolve(
            $filters,
            ['id', 'ordered_at', 'created_at'],
            $this->relatedMap(),
            new SortsByRelatedColumnOrder,
            'ordered_at',
        );
    }

    /**
     * 관계 맵에 있는 정렬 키는 상관 서브쿼리로 해석됩니다.
     */
    #[Test]
    public function 관계_키는_상관_서브쿼리로_해석된다(): void
    {
        $spec = $this->resolve(['sort_by' => 'shipped_at', 'sort_order' => 'desc']);

        $this->assertCount(1, $spec);
        $this->assertInstanceOf(Builder::class, $spec[0]['column']);
        $this->assertSame('desc', $spec[0]['direction']);
    }

    /**
     * 서브쿼리가 상관 조건·단건 제한을 갖춘 형태로 조립됩니다.
     *
     * 원 행마다 관계 행 한 건만 참조하므로 원 행 수가 바뀌지 않습니다.
     */
    #[Test]
    public function 서브쿼리가_상관조건과_단건제한을_갖춘다(): void
    {
        $spec = $this->resolve(['sort_by' => 'shipped_at', 'sort_order' => 'desc']);
        $sql = str_replace('`', '"', $spec[0]['column']->toSql());

        $related = $this->table(new SortsByRelatedColumnShipping);
        $parent = $this->table(new SortsByRelatedColumnOrder);

        $this->assertStringContainsString("select \"{$related}\".\"shipped_at\"", $sql);
        $this->assertStringContainsString("from \"{$related}\"", $sql);
        // 상관 조건: 관계 외래키 = 원 모델 기본키
        $this->assertStringContainsString("\"{$related}\".\"order_id\" = \"{$parent}\".\"id\"", $sql);
        $this->assertStringContainsString('limit 1', $sql);
    }

    /**
     * 조인·집계를 쓰지 않습니다.
     *
     * 1:N 조인은 원 행을 부풀려 총 건수와 페이지 경계를 깨뜨리므로, 이 Trait 이 조인으로
     * 회귀하는 것을 막는 것이 존재 이유입니다.
     */
    #[Test]
    public function 조인과_집계를_쓰지_않는다(): void
    {
        $spec = $this->resolve(['sort_by' => 'shipped_at', 'sort_order' => 'desc']);
        $sql = strtolower($spec[0]['column']->toSql());

        $this->assertStringNotContainsString(' join ', $sql);
        $this->assertStringNotContainsString('group by', $sql);
        $this->assertStringNotContainsString('max(', $sql);
        $this->assertStringNotContainsString('min(', $sql);
    }

    /**
     * 서브쿼리 정렬 방향이 요청 방향과 같습니다.
     *
     * 같은 방향으로 정렬해 한 건만 취하므로 `desc` 는 가장 늦은 값, `asc` 는 가장 이른 값이
     * 기준이 됩니다 — 운영자가 "최근 발송순" 에서 기대하는 값과 일치합니다.
     */
    #[Test]
    public function 서브쿼리_정렬_방향이_요청과_일치한다(): void
    {
        $desc = $this->resolve(['sort_by' => 'shipped_at', 'sort_order' => 'desc']);
        $asc = $this->resolve(['sort_by' => 'shipped_at', 'sort_order' => 'asc']);

        $related = $this->table(new SortsByRelatedColumnShipping);

        $this->assertStringContainsString(
            "order by \"{$related}\".\"shipped_at\" desc",
            str_replace('`', '"', $desc[0]['column']->toSql())
        );
        $this->assertStringContainsString(
            "order by \"{$related}\".\"shipped_at\" asc",
            str_replace('`', '"', $asc[0]['column']->toSql())
        );
        $this->assertSame('asc', $asc[0]['direction']);
    }

    /**
     * 관계 맵에 없는 정렬 키는 일반 컬럼 경로로 폴백합니다.
     */
    #[Test]
    public function 관계_맵에_없는_키는_일반_경로로_폴백한다(): void
    {
        $spec = $this->resolve(['sort_by' => 'ordered_at', 'sort_order' => 'asc']);

        $this->assertSame([['column' => 'ordered_at', 'direction' => 'asc']], $spec);
    }

    /**
     * 화이트리스트 밖 정렬 키는 기본값으로 폴백합니다.
     *
     * 관계 맵에도 없고 허용 컬럼에도 없는 값이 그대로 orderBy 로 새면 안 됩니다.
     */
    #[Test]
    public function 허용_목록_밖_키는_기본값으로_폴백한다(): void
    {
        $spec = $this->resolve(['sort_by' => 'admin_memo', 'sort_order' => 'desc']);

        $this->assertSame('ordered_at', $spec[0]['column']);
    }

    /**
     * 정렬 키 미지정 시 기본값으로 해석합니다.
     */
    #[Test]
    public function 정렬_키_미지정은_기본값이다(): void
    {
        $this->assertSame('ordered_at', $this->resolve([])[0]['column']);
        $this->assertSame('ordered_at', $this->resolve(['sort_by' => '  '])[0]['column']);
    }

    /**
     * 잘못된 방향값은 기본 방향으로 정규화됩니다.
     *
     * 요청 값이 그대로 SQL 방향으로 새면 안 됩니다.
     */
    #[Test]
    public function 잘못된_방향값은_기본_방향으로_정규화된다(): void
    {
        $spec = $this->resolve(['sort_by' => 'shipped_at', 'sort_order' => 'sideways']);

        $this->assertSame('desc', $spec[0]['direction']);
        $this->assertStringContainsString('desc', strtolower($spec[0]['column']->toSql()));
    }
}

/**
 * 테스트용 원 모델 (주문)
 */
class SortsByRelatedColumnOrder extends Model
{
    protected $table = 'orders';
}

/**
 * 테스트용 관계 모델 (배송)
 */
class SortsByRelatedColumnShipping extends Model
{
    protected $table = 'order_shippings';
}
