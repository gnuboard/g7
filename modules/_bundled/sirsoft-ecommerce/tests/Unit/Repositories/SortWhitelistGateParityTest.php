<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

require_once __DIR__.'/../../ModuleTestCase.php';

use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\CouponListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ExtraFeeTemplateListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\OrderListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ProductListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ShippingPolicyListRequest;
use Modules\Sirsoft\Ecommerce\Repositories\CouponRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ExtraFeeTemplateRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ShippingPolicyRepository;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;

/**
 * 정렬 허용 컬럼의 포함관계 회귀 가드 (이커머스).
 *
 * 목록 정렬은 두 곳에서 제한된다 — 요청을 차단하는 FormRequest(게이트)와
 * ResolvesSortSpec 이 해석하는 Repository 의 닫힌 집합(저장소).
 *
 * 불변식은 **게이트 ⊆ 저장소** 다 (docs/backend/service-repository.md).
 * 저장소가 게이트보다 좁으면 검증을 통과한 정렬이 조용히 기본 정렬로 되돌아간다.
 * 저장소가 더 넓은 것은 의도된 여유다 — 게이트가 훅으로 확장에 열려 있기 때문이다.
 */
#[Group('ecommerce')]
class SortWhitelistGateParityTest extends ModuleTestCase
{
    /**
     * Repository 상수 ↔ FormRequest 규칙 쌍 목록.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function surfaceProvider(): array
    {
        return [
            '주문 목록' => [
                OrderRepository::class,
                OrderListRequest::class,
                'SORTABLE_COLUMNS',
            ],
            '상품 목록' => [
                ProductRepository::class,
                ProductListRequest::class,
                'ADMIN_SORTABLE_COLUMNS',
            ],
            '추가배송비 템플릿' => [
                ExtraFeeTemplateRepository::class,
                ExtraFeeTemplateListRequest::class,
                'SORTABLE_COLUMNS',
            ],
            '배송정책 목록' => [
                ShippingPolicyRepository::class,
                ShippingPolicyListRequest::class,
                'SORTABLE_COLUMNS',
            ],
            '쿠폰 목록' => [
                CouponRepository::class,
                CouponListRequest::class,
                'SORTABLE_COLUMNS',
            ],
        ];
    }

    #[DataProvider('surfaceProvider')]
    public function test_게이트가_허용한_정렬은_저장소도_허용한다(
        string $repositoryClass,
        string $requestClass,
        string $constantName
    ): void {
        $reflection = new ReflectionClass($repositoryClass);

        $repoColumns = $reflection->getConstant($constantName);
        $this->assertIsArray($repoColumns, "{$repositoryClass}::{$constantName} 상수를 찾지 못했습니다.");

        // 관계 테이블 컬럼 기준 정렬(SortsByRelatedColumn)도 저장소가 허용하는 정렬이다.
        // 맵의 **키**가 요청 sort_by 값이므로 원 테이블 컬럼 목록과 합쳐서 대조한다.
        $repoColumns = array_values($repoColumns);
        $related = $reflection->getConstant('RELATED_SORTABLE_COLUMNS');

        if (is_array($related)) {
            $repoColumns = array_merge($repoColumns, array_keys($related));
        }

        $gateColumns = $this->readGateSortColumns($requestClass);
        $this->assertNotEmpty($gateColumns, "{$requestClass} 에서 sort_by 허용 목록을 찾지 못했습니다.");

        $missing = array_values(array_diff($gateColumns, $repoColumns));

        $this->assertSame(
            [],
            $missing,
            sprintf(
                '게이트가 통과시키는 정렬 컬럼을 저장소가 허용하지 않습니다 — 그 정렬은 조용히 '
                ."기본 정렬로 되돌아갑니다.\n  누락: %s\n  저장소: %s\n  게이트: %s",
                implode(',', $missing),
                implode(',', array_values($repoColumns)),
                implode(',', $gateColumns)
            )
        );
    }

    /**
     * FormRequest 소스에서 sort_by 허용 목록을 읽습니다.
     *
     * @return array<int, string> 정렬 허용 컬럼 목록
     */
    private function readGateSortColumns(string $class): array
    {
        $source = file_get_contents((new ReflectionClass($class))->getFileName());

        if (preg_match("/'sort_by'\s*=>\s*'[^']*\bin:([^'|]+)/", $source, $m)) {
            return $this->splitColumns($m[1]);
        }

        if (preg_match("/'sort_by'\s*=>\s*\[[^\]]*'in:([^']+)'/", $source, $m)) {
            return $this->splitColumns($m[1]);
        }

        if (preg_match("/'sort_by'\s*=>\s*\[.*?Rule::in\(\s*\[(.*?)\]/s", $source, $m)) {
            return $this->splitColumns(str_replace("'", '', $m[1]));
        }

        return [];
    }

    /**
     * 쉼표 구분 컬럼 문자열을 배열로 변환합니다.
     *
     * @return array<int, string> 정리된 컬럼 목록
     */
    private function splitColumns(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
