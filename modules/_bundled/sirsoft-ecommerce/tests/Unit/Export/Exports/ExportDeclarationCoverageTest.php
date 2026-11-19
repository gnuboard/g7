<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Export\Exports;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Exports\Declarations\ModelDeclaration;
use Modules\Sirsoft\Ecommerce\Exports\Declarations\ModelDeclarationRegistry;
use Modules\Sirsoft\Ecommerce\Exports\ExportExclusion;
use Modules\Sirsoft\Ecommerce\Exports\Types\AbstractExportType;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * 내보내기 선언 잠금 테스트 (D19 — 반자동 매핑)
 *
 * 내보내기 대상 모델의 실제 테이블 컬럼은 전부 **내보내기 선언(`fields()`)이나 제외 선언(`excluded()`)** 중 한 곳에만
 * 있어야 한다. 새 마이그레이션이 컬럼을 늘려도 내보내기 선언은 저절로 따라오지 않는다 — 이 테스트가
 * 그 컬럼 이름을 지목하며 붉어진다. 「자동으로 들어가되(그 밖의 항목) 개인정보 여부는 사람이 정한다」는 반자동 규칙의
 * 안전장치다.
 */
class ExportDeclarationCoverageTest extends ExportTestCase
{
    /**
     * 내보내기 대상 모델 목록
     *
     * @return array<string, array{0: class-string}> 데이터셋
     */
    public static function modelProvider(): array
    {
        return [
            'product' => [Product::class],
            'product_option' => [ProductOption::class],
            'order' => [Order::class],
            'order_option' => [OrderOption::class],
            'order_address' => [OrderAddress::class],
            'order_payment' => [OrderPayment::class],
            'order_shipping' => [OrderShipping::class],
            'mileage_transaction' => [MileageTransaction::class],
        ];
    }

    /**
     * 모델의 선언 클래스를 반환합니다(없으면 실패).
     *
     * @param  class-string  $modelClass  모델 클래스
     * @return class-string<ModelDeclaration> 선언 클래스
     */
    private function declarationOf(string $modelClass): string
    {
        $declaration = ModelDeclarationRegistry::for($modelClass);
        $this->assertNotNull($declaration, "{$modelClass} 의 내보내기 선언이 등록돼 있지 않습니다.");

        return $declaration;
    }

    /**
     * 테이블의 모든 컬럼이 선언 또는 제외 중 정확히 한 곳에 있다.
     *
     * @param  class-string  $modelClass  모델 클래스
     * @return void 반환값 없음
     *
     * @scenario model=declared
     *
     * @effects export_declarations_cover_all_columns
     */
    #[DataProvider('modelProvider')]
    public function test_every_column_is_declared_or_excluded(string $modelClass): void
    {
        $declaration = $this->declarationOf($modelClass);
        $table = (new $modelClass)->getTable();
        $columns = Schema::getColumnListing($table);
        $declared = array_keys($declaration::fields());
        $excluded = array_keys($declaration::excluded());

        $this->assertSame([], array_values(array_diff($columns, $declared, $excluded)), "{$table}: 선언도 제외 사유도 없는 컬럼이 있습니다 — {$declaration} 의 fields() 또는 excluded() 에 추가하세요.");
        $this->assertSame([], array_values(array_diff(array_merge($declared, $excluded), $columns)), "{$table}: 테이블에 없는 컬럼을 선언했습니다.");
        $this->assertSame([], array_values(array_intersect($declared, $excluded)), "{$table}: 같은 컬럼이 선언과 제외 양쪽에 있습니다.");
    }

    /**
     * 선언은 형식이 맞고(type·personal), 제외 사유는 정해진 사유 상수 중 하나다.
     *
     * @param  class-string  $modelClass  모델 클래스
     * @return void 반환값 없음
     *
     * @scenario model=declared
     *
     * @effects export_declarations_are_well_formed
     */
    #[DataProvider('modelProvider')]
    public function test_declarations_are_well_formed(string $modelClass): void
    {
        $declaration = $this->declarationOf($modelClass);
        $normalized = AbstractExportType::declarationsOf($modelClass);
        $this->assertCount(count($declaration::fields()), $normalized);

        $reasons = array_values((new ReflectionClass(ExportExclusion::class))->getConstants());
        foreach ($declaration::excluded() as $column => $reason) {
            $this->assertContains($reason, $reasons, "{$modelClass}::{$column} 의 제외 사유가 ExportExclusion 상수가 아닙니다.");
        }
    }

    /**
     * 개인정보 여부(personal)를 빠뜨린 선언은 거부된다 — 자동으로 「개인정보 아님」으로 취급하지 않는다.
     *
     * 확장이 필터로 더한 선언도 같은 검사를 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario model=undeclared_personal
     *
     * @effects export_declaration_requires_personal_flag
     */
    public function test_declaration_without_personal_flag_is_rejected(): void
    {
        HookManager::addFilter('sirsoft-ecommerce.export.declarations', fn (array $classes) => [...$classes, ExportDeclarationMissingPersonalFixture::class]);
        ModelDeclarationRegistry::flush();

        try {
            $this->expectException(InvalidArgumentException::class);

            AbstractExportType::declarationsOf(ExportDeclarationMissingPersonalFixtureModel::class);
        } finally {
            HookManager::clearFilter('sirsoft-ecommerce.export.declarations');
            ModelDeclarationRegistry::flush();
        }
    }
}

/**
 * personal 을 빠뜨린 선언의 대상 모델 (테스트 고정물)
 */
class ExportDeclarationMissingPersonalFixtureModel {}

/**
 * personal 을 빠뜨린 선언 (테스트 고정물)
 */
final class ExportDeclarationMissingPersonalFixture implements ModelDeclaration
{
    /**
     * {@inheritDoc}
     */
    public static function model(): string
    {
        return ExportDeclarationMissingPersonalFixtureModel::class;
    }

    /**
     * {@inheritDoc}
     */
    public static function fields(): array
    {
        return ['nickname' => ['type' => 'string']];
    }

    /**
     * {@inheritDoc}
     */
    public static function excluded(): array
    {
        return [];
    }
}
