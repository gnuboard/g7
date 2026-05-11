<?php

namespace Tests\Unit\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * sirsoft-ecommerce 모듈의 ProductLabelAssignment 모델 테스트
 */
#[Group('module-dependent')]
class ProductLabelAssignmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ProductLabelAssignment 모델 클래스명
     */
    private string $assignmentClass = 'Modules\\Sirsoft\\Ecommerce\\Models\\ProductLabelAssignment';

    /**
     * ProductLabel 모델 클래스명
     */
    private string $labelClass = 'Modules\\Sirsoft\\Ecommerce\\Models\\ProductLabel';

    /**
     * Product 모델 클래스명
     */
    private string $productClass = 'Modules\\Sirsoft\\Ecommerce\\Models\\Product';

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // ProductLabelAssignment 클래스가 로드되어 있지 않으면 테스트 스킵
        if (! class_exists($this->assignmentClass)) {
            $this->markTestSkipped('sirsoft-ecommerce 모듈이 설치되어 있지 않습니다.');
        }

        // 모듈 마이그레이션 실행 — 활성 디렉토리 우선, 부재 시 _bundled 로 폴백
        $activeMigrationPath = base_path('modules/sirsoft-ecommerce/database/migrations');
        $bundledMigrationPath = base_path('modules/_bundled/sirsoft-ecommerce/database/migrations');

        $migrationPath = is_dir($activeMigrationPath) ? $activeMigrationPath : $bundledMigrationPath;

        if (! is_dir($migrationPath)) {
            $this->markTestSkipped('sirsoft-ecommerce 마이그레이션 디렉토리를 찾을 수 없습니다.');
        }

        $this->artisan('migrate', [
            '--path' => $migrationPath,
            '--realpath' => true,
        ]);
    }

    /**
     * 테스트용 ProductLabel 생성
     *
     * @param array $overrides 오버라이드할 속성
     * @return mixed ProductLabel 인스턴스
     */
    private function createLabel(array $overrides = []): mixed
    {
        $data = array_merge([
            'name' => ['ko' => '테스트 라벨', 'en' => 'Test Label'],
            'color' => '#FF0000',
            'is_active' => true,
        ], $overrides);

        return $this->labelClass::create($data);
    }

    /**
     * 테스트용 Product 생성
     *
     * @param array $overrides 오버라이드할 속성
     * @return mixed Product 인스턴스
     */
    private function createProduct(array $overrides = []): mixed
    {
        $data = array_merge([
            'name' => ['ko' => '테스트 상품', 'en' => 'Test Product'],
            'product_code' => 'TEST-'.uniqid(),
            'selling_price' => 10000,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
        ], $overrides);

        return $this->productClass::create($data);
    }

    /**
     * 테스트용 ProductLabelAssignment 생성
     *
     * @param array $overrides 오버라이드할 속성
     * @return mixed ProductLabelAssignment 인스턴스
     */
    private function createAssignment(array $overrides = []): mixed
    {
        // 기본 label과 product 생성
        $label = $this->createLabel();
        $product = $this->createProduct();

        $data = array_merge([
            'product_id' => $product->id,
            'label_id' => $label->id,
        ], $overrides);

        return $this->assignmentClass::create($data);
    }

    /**
     * start_date와 end_date가 date로 캐스팅되는지 테스트
     */
    public function test_dates_are_cast_to_date(): void
    {
        // Arrange
        $label = $this->createLabel();
        $product = $this->createProduct();

        // Act
        $assignment = $this->assignmentClass::create([
            'product_id' => $product->id,
            'label_id' => $label->id,
            'start_date' => '2025-01-15',
            'end_date' => '2025-06-30',
        ]);

        // Assert
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $assignment->start_date);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $assignment->end_date);
        $this->assertEquals('2025-01-15', $assignment->start_date->toDateString());
        $this->assertEquals('2025-06-30', $assignment->end_date->toDateString());
    }

    /**
     * isActive()가 날짜 범위 내일 때 true를 반환하는지 테스트
     */
    public function test_is_active_returns_true_when_within_date_range(): void
    {
        // Arrange
        $assignment = $this->createAssignment([
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ]);

        // Act & Assert
        $this->assertTrue($assignment->isActive());
    }

    /**
     * isActive()가 시작일 이전일 때 false를 반환하는지 테스트
     */
    public function test_is_active_returns_false_when_before_start_date(): void
    {
        // Arrange
        $assignment = $this->createAssignment([
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ]);

        // Act & Assert
        $this->assertFalse($assignment->isActive());
    }

    /**
     * isActive()가 종료일 이후일 때 false를 반환하는지 테스트
     */
    public function test_is_active_returns_false_when_after_end_date(): void
    {
        // Arrange
        $assignment = $this->createAssignment([
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
        ]);

        // Act & Assert
        $this->assertFalse($assignment->isActive());
    }

    /**
     * isActive()가 날짜 제한 없을 때 true를 반환하는지 테스트
     */
    public function test_is_active_returns_true_when_no_date_limits(): void
    {
        // Arrange
        $assignment = $this->createAssignment([
            'start_date' => null,
            'end_date' => null,
        ]);

        // Act & Assert
        $this->assertTrue($assignment->isActive());
    }

    /**
     * scopeCurrentlyActive가 활성 라벨만 필터링하는지 테스트
     */
    public function test_scope_currently_active_filters_correctly(): void
    {
        // Arrange
        $label = $this->createLabel();
        $product = $this->createProduct();

        // 활성 라벨 (날짜 범위 내)
        $activeAssignment = $this->assignmentClass::create([
            'product_id' => $product->id,
            'label_id' => $label->id,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ]);

        // 비활성 라벨 (기간 지남)
        $label2 = $this->createLabel();
        $inactiveAssignment = $this->assignmentClass::create([
            'product_id' => $product->id,
            'label_id' => $label2->id,
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->subDays(5)->toDateString(),
        ]);

        // Act
        $activeAssignments = $this->assignmentClass::currentlyActive()->get();

        // Assert
        $this->assertTrue($activeAssignments->contains('id', $activeAssignment->id));
        $this->assertFalse($activeAssignments->contains('id', $inactiveAssignment->id));
    }

    /**
     * product 관계가 정상 동작하는지 테스트
     */
    public function test_product_relationship_works(): void
    {
        // Arrange
        $label = $this->createLabel();
        $product = $this->createProduct(['name' => ['ko' => '관계 테스트 상품', 'en' => 'Relation Test Product']]);

        $assignment = $this->assignmentClass::create([
            'product_id' => $product->id,
            'label_id' => $label->id,
        ]);

        // Act
        $relatedProduct = $assignment->product;

        // Assert
        $this->assertInstanceOf($this->productClass, $relatedProduct);
        $this->assertEquals($product->id, $relatedProduct->id);
    }

    /**
     * label 관계가 정상 동작하는지 테스트
     */
    public function test_label_relationship_works(): void
    {
        // Arrange
        $label = $this->createLabel(['name' => ['ko' => '관계 테스트 라벨', 'en' => 'Relation Test Label']]);
        $product = $this->createProduct();

        $assignment = $this->assignmentClass::create([
            'product_id' => $product->id,
            'label_id' => $label->id,
        ]);

        // Act
        $relatedLabel = $assignment->label;

        // Assert
        $this->assertInstanceOf($this->labelClass, $relatedLabel);
        $this->assertEquals($label->id, $relatedLabel->id);
    }
}
