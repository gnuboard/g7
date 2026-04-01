<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Services;

use Modules\Sirsoft\Ecommerce\Enums\SequenceAlgorithm;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Models\SequenceCode;
use Modules\Sirsoft\Ecommerce\Services\SequenceService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * SequenceService 기능 테스트
 *
 * 실제 데이터베이스와 함께 동작하는 통합 테스트
 */
class SequenceServiceTest extends ModuleTestCase
{
    private SequenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Telescope 비활성화 (테스트 환경)
        config(['telescope.enabled' => false]);

        // 각 테스트에서 시퀀스를 직접 생성하므로 시더 데이터 정리
        Sequence::query()->delete();
        SequenceCode::query()->delete();

        // Service 가져오기
        $this->service = app(SequenceService::class);
    }

    // ========================================
    // NanoID 알고리즘 (PRODUCT) 통합 테스트
    // ========================================

    #[Test]
    public function test_nanoid_product_code_generation(): void
    {
        // Arrange: NanoID 알고리즘으로 상품 시퀀스 초기화
        Sequence::create([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::NANOID->value,
            'prefix' => null,
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 0,
            'cycle' => false,
            'pad_length' => 0,
            'max_history_count' => 0,
        ]);

        // Act: 상품 코드 생성
        $code1 = $this->service->generateCode(SequenceType::PRODUCT);
        $code2 = $this->service->generateCode(SequenceType::PRODUCT);
        $code3 = $this->service->generateCode(SequenceType::PRODUCT);

        // Assert: 16자 대문자+숫자 문자열, 서로 다름
        $this->assertIsString($code1);
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{16}$/', $code1);
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{16}$/', $code2);
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{16}$/', $code3);

        $this->assertNotEquals($code1, $code2);
        $this->assertNotEquals($code2, $code3);
        $this->assertNotEquals($code1, $code3);
    }

    #[Test]
    public function test_nanoid_does_not_insert_into_sequence_codes_table(): void
    {
        // Arrange: NanoID 알고리즘 시퀀스
        Sequence::create([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::NANOID->value,
            'prefix' => null,
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 0,
            'cycle' => false,
            'pad_length' => 0,
            'max_history_count' => 0,
        ]);

        // Act: 10개 코드 생성
        for ($i = 0; $i < 10; $i++) {
            $this->service->generateCode(SequenceType::PRODUCT);
        }

        // Assert: 채번 이력 테이블에 PRODUCT 레코드 없음
        $historyCount = SequenceCode::where('type', SequenceType::PRODUCT->value)->count();
        $this->assertEquals(0, $historyCount);
    }

    #[Test]
    public function test_nanoid_does_not_update_current_value(): void
    {
        // Arrange: NanoID 알고리즘 시퀀스
        Sequence::create([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::NANOID->value,
            'prefix' => null,
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 0,
            'cycle' => false,
            'pad_length' => 0,
            'max_history_count' => 0,
        ]);

        // Act: 코드 생성
        $this->service->generateCode(SequenceType::PRODUCT);

        // Assert: current_value가 변경되지 않음
        $sequence = Sequence::where('type', SequenceType::PRODUCT->value)->first();
        $this->assertEquals(0, $sequence->current_value);
    }

    #[Test]
    public function test_nanoid_multiple_codes_are_unique(): void
    {
        // Arrange: NanoID 알고리즘 시퀀스
        Sequence::create([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::NANOID->value,
            'prefix' => null,
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 0,
            'cycle' => false,
            'pad_length' => 0,
            'max_history_count' => 0,
        ]);

        // Act: 100개 코드 생성
        $codes = [];
        for ($i = 0; $i < 100; $i++) {
            $codes[] = $this->service->generateCode(SequenceType::PRODUCT);
        }

        // Assert: 모든 코드가 유일함
        $uniqueCodes = array_unique($codes);
        $this->assertCount(100, $uniqueCodes);
    }

    #[Test]
    public function test_sequential_algorithm_works_correctly(): void
    {
        // Arrange: 주문 시퀀스 초기화
        Sequence::create([
            'type' => SequenceType::ORDER->value,
            'algorithm' => SequenceAlgorithm::SEQUENTIAL->value,
            'prefix' => 'ORD-',
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 99999999,
            'cycle' => false,
            'pad_length' => 8,
        ]);

        // Act: 주문 코드 생성
        $code1 = $this->service->generateCode(SequenceType::ORDER);
        $code2 = $this->service->generateCode(SequenceType::ORDER);
        $code3 = $this->service->generateCode(SequenceType::ORDER);

        // Assert: 순차적으로 증가
        $this->assertEquals('ORD-00000001', $code1);
        $this->assertEquals('ORD-00000002', $code2);
        $this->assertEquals('ORD-00000003', $code3);
    }

    #[Test]
    public function test_timestamp_algorithm_generates_valid_format(): void
    {
        // Arrange: 주문 시퀀스 초기화 (TIMESTAMP 알고리즘)
        Sequence::create([
            'type' => SequenceType::ORDER->value,
            'algorithm' => SequenceAlgorithm::TIMESTAMP->value,
            'prefix' => null,
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => PHP_INT_MAX,
            'cycle' => false,
            'pad_length' => 0,
        ]);

        // Act: 주문 코드 생성
        $code = $this->service->generateCode(SequenceType::ORDER);

        // Assert: 타임스탬프 형식 (Ymd-His + 밀리초3자리 + 랜덤1자리)
        $this->assertIsString($code);
        $this->assertMatchesRegularExpression('/^\d{8}-\d{10}$/', $code);
    }

    #[Test]
    public function test_timestamp_algorithm_generates_unique_codes(): void
    {
        // Arrange: 주문 시퀀스 초기화 (TIMESTAMP 알고리즘)
        Sequence::create([
            'type' => SequenceType::ORDER->value,
            'algorithm' => SequenceAlgorithm::TIMESTAMP->value,
            'prefix' => null,
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => PHP_INT_MAX,
            'cycle' => false,
            'pad_length' => 0,
        ]);

        // Act: 20개 코드 생성
        $codes = [];
        for ($i = 0; $i < 20; $i++) {
            $codes[] = $this->service->generateCode(SequenceType::ORDER);
        }

        // Assert: 모든 코드가 유일함
        $uniqueCodes = array_unique($codes);
        $this->assertCount(20, $uniqueCodes);

        // 모든 코드가 이력 테이블에 기록됨
        $historyCount = SequenceCode::where('type', SequenceType::ORDER->value)->count();
        $this->assertEquals(20, $historyCount);
    }

    #[Test]
    public function test_timestamp_algorithm_records_in_history(): void
    {
        // Arrange: 주문 시퀀스 초기화 (TIMESTAMP 알고리즘)
        Sequence::create([
            'type' => SequenceType::ORDER->value,
            'algorithm' => SequenceAlgorithm::TIMESTAMP->value,
            'prefix' => null,
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => PHP_INT_MAX,
            'cycle' => false,
            'pad_length' => 0,
        ]);

        // Act: 코드 생성
        $code = $this->service->generateCode(SequenceType::ORDER);

        // Assert: 이력 테이블에 기록됨
        $this->assertDatabaseHas('ecommerce_sequence_codes', [
            'type' => SequenceType::ORDER->value,
            'code' => $code,
        ]);
    }

    #[Test]
    public function test_initialize_sequence_works(): void
    {
        // Act: 시퀀스 초기화 (PRODUCT 기본값은 NANOID)
        $sequence = $this->service->initializeSequence(SequenceType::PRODUCT);

        // Assert: 시퀀스가 생성됨
        $this->assertDatabaseHas('ecommerce_sequences', [
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::NANOID->value,
        ]);

        $this->assertEquals(SequenceType::PRODUCT, $sequence->type);
        $this->assertEquals(SequenceAlgorithm::NANOID, $sequence->algorithm);
    }

    // ========================================
    // HYBRID 알고리즘 회귀 테스트 (기존 동작 유지 확인)
    // ========================================

    #[Test]
    public function test_hybrid_algorithm_uses_max_of_timestamp_and_current_value(): void
    {
        // Arrange: HYBRID 알고리즘으로 상품 시퀀스를 미래 값으로 초기화
        $futureValue = time() + 1000000;

        Sequence::create([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::HYBRID->value,
            'prefix' => null,
            'current_value' => $futureValue,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 9999999999,
            'cycle' => false,
            'pad_length' => 10,
        ]);

        // Act: 코드 생성
        $code = $this->service->generateCode(SequenceType::PRODUCT);

        // Assert: 코드는 futureValue + 1
        $this->assertEquals((string) ($futureValue + 1), $code);
    }

    #[Test]
    public function test_hybrid_generate_code_inserts_history_record(): void
    {
        // Arrange: HYBRID 알고리즘 시퀀스 (이력 삽입 확인용)
        Sequence::create([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::HYBRID->value,
            'prefix' => null,
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 9999999999,
            'cycle' => false,
            'pad_length' => 10,
        ]);

        // Act: 코드 생성
        $code = $this->service->generateCode(SequenceType::PRODUCT);

        // Assert: HYBRID는 이력 테이블에 레코드 삽입
        $this->assertDatabaseHas('ecommerce_sequence_codes', [
            'type' => SequenceType::PRODUCT->value,
            'code' => $code,
        ]);

        $this->assertEquals(1, SequenceCode::where('type', SequenceType::PRODUCT->value)->count());
    }

    #[Test]
    public function test_hybrid_all_generated_codes_are_recorded_in_history(): void
    {
        // Arrange: HYBRID 알고리즘 시퀀스
        Sequence::create([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::HYBRID->value,
            'prefix' => null,
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 9999999999,
            'cycle' => false,
            'pad_length' => 10,
        ]);

        // Act: 50개 코드 생성
        $generatedCodes = [];
        for ($i = 0; $i < 50; $i++) {
            $generatedCodes[] = $this->service->generateCode(SequenceType::PRODUCT);
        }

        // Assert: HYBRID는 이력 테이블에 50개 레코드 삽입
        $historyCount = SequenceCode::where('type', SequenceType::PRODUCT->value)->count();
        $this->assertEquals(50, $historyCount);

        foreach ($generatedCodes as $code) {
            $this->assertDatabaseHas('ecommerce_sequence_codes', [
                'type' => SequenceType::PRODUCT->value,
                'code' => $code,
            ]);
        }
    }

    #[Test]
    public function test_unique_constraint_prevents_duplicate_codes(): void
    {
        // Arrange: 이력 테이블에 직접 코드 삽입 (중복 테스트용)
        Sequence::create([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::SEQUENTIAL->value,
            'prefix' => null,
            'current_value' => 100,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 9999999999,
            'cycle' => false,
            'pad_length' => 10,
        ]);

        // 수동으로 코드 이력 삽입
        SequenceCode::create([
            'type' => SequenceType::PRODUCT->value,
            'code' => '101',
        ]);

        // Assert: 같은 코드 삽입 시도 시 예외 발생
        $this->expectException(\Illuminate\Database\QueryException::class);

        // Act: 동일 코드 삽입 시도
        SequenceCode::create([
            'type' => SequenceType::PRODUCT->value,
            'code' => '101',
        ]);
    }

    #[Test]
    public function test_different_types_can_have_same_code(): void
    {
        // Arrange: 상품 및 주문 시퀀스 초기화
        Sequence::create([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::SEQUENTIAL->value,
            'prefix' => null,
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 9999999999,
            'cycle' => false,
            'pad_length' => 10,
        ]);

        Sequence::create([
            'type' => SequenceType::ORDER->value,
            'algorithm' => SequenceAlgorithm::SEQUENTIAL->value,
            'prefix' => null,
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 9999999999,
            'cycle' => false,
            'pad_length' => 10,
        ]);

        // Act: 두 타입에서 코드 생성
        $productCode = $this->service->generateCode(SequenceType::PRODUCT);
        $orderCode = $this->service->generateCode(SequenceType::ORDER);

        // Assert: 동일한 코드 값이지만 다른 타입으로 저장됨
        $this->assertEquals($productCode, $orderCode); // 둘 다 '1'

        // 이력 테이블에 타입별로 분리되어 저장됨
        $this->assertDatabaseHas('ecommerce_sequence_codes', [
            'type' => SequenceType::PRODUCT->value,
            'code' => $productCode,
        ]);
        $this->assertDatabaseHas('ecommerce_sequence_codes', [
            'type' => SequenceType::ORDER->value,
            'code' => $orderCode,
        ]);

        // 총 2개 레코드
        $this->assertEquals(2, SequenceCode::count());
    }

    #[Test]
    public function test_max_history_count_limits_stored_codes(): void
    {
        // Arrange: max_history_count = 5로 시퀀스 초기화
        Sequence::create([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::SEQUENTIAL->value,
            'prefix' => null,
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 9999999999,
            'cycle' => false,
            'pad_length' => 10,
            'max_history_count' => 5,
        ]);

        // Act: 10개 코드 생성
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = $this->service->generateCode(SequenceType::PRODUCT);
        }

        // Assert: 이력은 최대 5개만 유지
        $historyCount = SequenceCode::where('type', SequenceType::PRODUCT->value)->count();
        $this->assertEquals(5, $historyCount);

        // 가장 최근 5개 코드만 남아있는지 확인
        $recentCodes = array_slice($codes, -5);
        foreach ($recentCodes as $code) {
            $this->assertDatabaseHas('ecommerce_sequence_codes', [
                'type' => SequenceType::PRODUCT->value,
                'code' => $code,
            ]);
        }

        // 오래된 코드는 삭제됨
        $oldCodes = array_slice($codes, 0, 5);
        foreach ($oldCodes as $code) {
            $this->assertDatabaseMissing('ecommerce_sequence_codes', [
                'type' => SequenceType::PRODUCT->value,
                'code' => $code,
            ]);
        }
    }

    #[Test]
    public function test_max_history_count_zero_keeps_all_codes(): void
    {
        // Arrange: max_history_count = 0 (무제한)
        Sequence::create([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::SEQUENTIAL->value,
            'prefix' => null,
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 9999999999,
            'cycle' => false,
            'pad_length' => 10,
            'max_history_count' => 0,
        ]);

        // Act: 20개 코드 생성
        for ($i = 0; $i < 20; $i++) {
            $this->service->generateCode(SequenceType::PRODUCT);
        }

        // Assert: 모든 20개 이력이 유지됨
        $historyCount = SequenceCode::where('type', SequenceType::PRODUCT->value)->count();
        $this->assertEquals(20, $historyCount);
    }

    #[Test]
    public function test_max_history_count_does_not_affect_other_types(): void
    {
        // Arrange: 상품은 max_history_count = 3, 주문은 무제한
        Sequence::create([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::SEQUENTIAL->value,
            'prefix' => null,
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 9999999999,
            'cycle' => false,
            'pad_length' => 10,
            'max_history_count' => 3,
        ]);

        Sequence::create([
            'type' => SequenceType::ORDER->value,
            'algorithm' => SequenceAlgorithm::SEQUENTIAL->value,
            'prefix' => 'ORD-',
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 99999999,
            'cycle' => false,
            'pad_length' => 8,
            'max_history_count' => 0,
        ]);

        // Act: 각 타입에서 5개씩 생성
        for ($i = 0; $i < 5; $i++) {
            $this->service->generateCode(SequenceType::PRODUCT);
            $this->service->generateCode(SequenceType::ORDER);
        }

        // Assert: 상품은 3개만, 주문은 5개 모두 유지
        $productCount = SequenceCode::where('type', SequenceType::PRODUCT->value)->count();
        $orderCount = SequenceCode::where('type', SequenceType::ORDER->value)->count();

        $this->assertEquals(3, $productCount);
        $this->assertEquals(5, $orderCount);
    }

    #[Test]
    public function test_max_history_count_with_timestamp_algorithm(): void
    {
        // Arrange: 타임스탬프 알고리즘 + max_history_count = 3
        Sequence::create([
            'type' => SequenceType::ORDER->value,
            'algorithm' => SequenceAlgorithm::TIMESTAMP->value,
            'prefix' => null,
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => PHP_INT_MAX,
            'cycle' => false,
            'pad_length' => 0,
            'max_history_count' => 3,
        ]);

        // Act: 5개 코드 생성
        $codes = [];
        for ($i = 0; $i < 5; $i++) {
            $codes[] = $this->service->generateCode(SequenceType::ORDER);
        }

        // Assert: 이력은 최대 3개만 유지
        $historyCount = SequenceCode::where('type', SequenceType::ORDER->value)->count();
        $this->assertEquals(3, $historyCount);

        // 가장 최근 3개만 남아있음
        $recentCodes = array_slice($codes, -3);
        foreach ($recentCodes as $code) {
            $this->assertDatabaseHas('ecommerce_sequence_codes', [
                'type' => SequenceType::ORDER->value,
                'code' => $code,
            ]);
        }
    }

    #[Test]
    public function test_max_history_count_one_keeps_only_latest(): void
    {
        // Arrange: max_history_count = 1 (최신 1건만 유지)
        Sequence::create([
            'type' => SequenceType::SHIPPING->value,
            'algorithm' => SequenceAlgorithm::SEQUENTIAL->value,
            'prefix' => 'SHP-',
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 99999999,
            'cycle' => false,
            'pad_length' => 8,
            'max_history_count' => 1,
        ]);

        // Act: 3개 코드 생성
        $code1 = $this->service->generateCode(SequenceType::SHIPPING);
        $code2 = $this->service->generateCode(SequenceType::SHIPPING);
        $code3 = $this->service->generateCode(SequenceType::SHIPPING);

        // Assert: 최신 1건만 유지
        $historyCount = SequenceCode::where('type', SequenceType::SHIPPING->value)->count();
        $this->assertEquals(1, $historyCount);

        $this->assertDatabaseMissing('ecommerce_sequence_codes', [
            'type' => SequenceType::SHIPPING->value,
            'code' => $code1,
        ]);
        $this->assertDatabaseMissing('ecommerce_sequence_codes', [
            'type' => SequenceType::SHIPPING->value,
            'code' => $code2,
        ]);
        $this->assertDatabaseHas('ecommerce_sequence_codes', [
            'type' => SequenceType::SHIPPING->value,
            'code' => $code3,
        ]);
    }
}
