<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\SequenceAlgorithm;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Exceptions\SequenceCodeDuplicateException;
use Modules\Sirsoft\Ecommerce\Exceptions\SequenceNotFoundException;
use Modules\Sirsoft\Ecommerce\Exceptions\SequenceOverflowException;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\SequenceRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\SequenceService;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SequenceService 단위 테스트
 */
class SequenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private SequenceService $service;

    /** @var \Mockery\MockInterface&SequenceRepositoryInterface */
    private $repository;

    protected function setUp(): void
    {
        parent::setUp();

        // Telescope 비활성화 (테스트 환경)
        config(['telescope.enabled' => false]);

        // Mock Repository 생성
        $this->repository = Mockery::mock(SequenceRepositoryInterface::class);

        // Service 생성
        $this->service = new SequenceService($this->repository);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function test_generate_code_returns_string_and_inserts_history(): void
    {
        // Arrange
        $sequence = new Sequence([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::HYBRID->value,
            'prefix' => null,
            'current_value' => 1737561234,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 9999999999,
            'cycle' => false,
            'pad_length' => 10,
        ]);

        $this->repository
            ->shouldReceive('findByType')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn($sequence);

        $this->repository
            ->shouldReceive('findByTypeForUpdate')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn($sequence);

        // 코드 이력 삽입 호출 검증
        $this->repository
            ->shouldReceive('insertCode')
            ->once()
            ->with(SequenceType::PRODUCT, Mockery::type('string'));

        $this->repository
            ->shouldReceive('updateCurrentValue')
            ->once()
            ->andReturn(true);

        // Act
        $result = $this->service->generateCode(SequenceType::PRODUCT);

        // Assert
        $this->assertIsString($result);
        $this->assertGreaterThan(1737561234, (int) $result);
    }

    #[Test]
    public function test_throws_exception_when_sequence_not_found(): void
    {
        // Arrange: findByType에서 null 반환 시 트랜잭션 진입 전 예외 발생
        $this->repository
            ->shouldReceive('findByType')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn(null);

        // Assert
        $this->expectException(SequenceNotFoundException::class);

        // Act
        $this->service->generateCode(SequenceType::PRODUCT);
    }

    #[Test]
    public function test_throws_exception_on_overflow_without_cycle(): void
    {
        // Arrange
        $sequence = new Sequence([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::SEQUENTIAL->value,
            'prefix' => null,
            'current_value' => 9999999999,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 9999999999,
            'cycle' => false,
            'pad_length' => 10,
        ]);

        $this->repository
            ->shouldReceive('findByType')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn($sequence);

        $this->repository
            ->shouldReceive('findByTypeForUpdate')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn($sequence);

        // Assert
        $this->expectException(SequenceOverflowException::class);

        // Act
        $this->service->generateCode(SequenceType::PRODUCT);
    }

    #[Test]
    public function test_cycles_to_min_value_when_cycle_enabled(): void
    {
        // Arrange
        $sequence = new Sequence([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::SEQUENTIAL->value,
            'prefix' => null,
            'current_value' => 9999999999,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 9999999999,
            'cycle' => true,
            'pad_length' => 10,
        ]);

        $this->repository
            ->shouldReceive('findByType')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn($sequence);

        $this->repository
            ->shouldReceive('findByTypeForUpdate')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn($sequence);

        $this->repository
            ->shouldReceive('insertCode')
            ->once()
            ->with(SequenceType::PRODUCT, '1');

        $this->repository
            ->shouldReceive('updateCurrentValue')
            ->once()
            ->with($sequence, 1)
            ->andReturn(true);

        // Act
        $result = $this->service->generateCode(SequenceType::PRODUCT);

        // Assert
        $this->assertEquals('1', $result);
    }

    #[Test]
    public function test_sequential_algorithm_increments_value(): void
    {
        // Arrange
        $sequence = new Sequence([
            'type' => SequenceType::ORDER->value,
            'algorithm' => SequenceAlgorithm::SEQUENTIAL->value,
            'prefix' => 'ORD-',
            'current_value' => 100,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 99999999,
            'cycle' => false,
            'pad_length' => 8,
        ]);

        $this->repository
            ->shouldReceive('findByType')
            ->once()
            ->with(SequenceType::ORDER)
            ->andReturn($sequence);

        $this->repository
            ->shouldReceive('findByTypeForUpdate')
            ->once()
            ->with(SequenceType::ORDER)
            ->andReturn($sequence);

        $this->repository
            ->shouldReceive('insertCode')
            ->once()
            ->with(SequenceType::ORDER, 'ORD-00000101');

        $this->repository
            ->shouldReceive('updateCurrentValue')
            ->once()
            ->with($sequence, 101)
            ->andReturn(true);

        // Act
        $result = $this->service->generateCode(SequenceType::ORDER);

        // Assert
        $this->assertEquals('ORD-00000101', $result);
    }

    #[Test]
    public function test_format_code_with_prefix(): void
    {
        // Arrange
        $sequence = new Sequence([
            'type' => SequenceType::ORDER->value,
            'algorithm' => SequenceAlgorithm::SEQUENTIAL->value,
            'prefix' => 'ORD-',
            'current_value' => 100,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 99999999,
            'cycle' => false,
            'pad_length' => 8,
        ]);

        $this->repository
            ->shouldReceive('findByType')
            ->once()
            ->with(SequenceType::ORDER)
            ->andReturn($sequence);

        $this->repository
            ->shouldReceive('findByTypeForUpdate')
            ->once()
            ->with(SequenceType::ORDER)
            ->andReturn($sequence);

        $this->repository
            ->shouldReceive('insertCode')
            ->once()
            ->with(SequenceType::ORDER, 'ORD-00000101');

        $this->repository
            ->shouldReceive('updateCurrentValue')
            ->once()
            ->andReturn(true);

        // Act
        $result = $this->service->generateCode(SequenceType::ORDER);

        // Assert
        $this->assertStringStartsWith('ORD-', $result);
        $this->assertEquals('ORD-00000101', $result);
    }

    #[Test]
    public function test_initialize_sequence_creates_with_default_config(): void
    {
        // Arrange
        $expectedData = [
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
            'date_format' => null,
            'last_reset_date' => null,
        ];

        $createdSequence = new Sequence($expectedData);

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->with($expectedData)
            ->andReturn($createdSequence);

        // Act
        $result = $this->service->initializeSequence(SequenceType::PRODUCT);

        // Assert (Enum 캐스팅이 적용되므로 Enum 객체와 비교)
        $this->assertEquals(SequenceType::PRODUCT, $result->type);
        $this->assertEquals(SequenceAlgorithm::NANOID, $result->algorithm);
    }

    #[Test]
    public function test_cleanup_old_codes_when_max_history_count_is_set(): void
    {
        // Arrange: max_history_count = 5인 시퀀스
        $sequence = new Sequence([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::SEQUENTIAL->value,
            'prefix' => null,
            'current_value' => 100,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 9999999999,
            'cycle' => false,
            'pad_length' => 10,
            'max_history_count' => 5,
        ]);

        $this->repository
            ->shouldReceive('findByType')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn($sequence);

        $this->repository
            ->shouldReceive('findByTypeForUpdate')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn($sequence);

        $this->repository
            ->shouldReceive('insertCode')
            ->once()
            ->with(SequenceType::PRODUCT, '101');

        $this->repository
            ->shouldReceive('updateCurrentValue')
            ->once()
            ->with($sequence, 101)
            ->andReturn(true);

        // 현재 6건 존재 (5 초과)
        $this->repository
            ->shouldReceive('countCodes')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn(6);

        // deleteOldCodes 호출 검증 (keepCount = 5)
        $this->repository
            ->shouldReceive('deleteOldCodes')
            ->once()
            ->with(SequenceType::PRODUCT, 5)
            ->andReturn(1);

        // Act
        $result = $this->service->generateCode(SequenceType::PRODUCT);

        // Assert
        $this->assertEquals('101', $result);
    }

    #[Test]
    public function test_no_cleanup_when_max_history_count_is_zero(): void
    {
        // Arrange: max_history_count = 0 (무제한)
        $sequence = new Sequence([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::SEQUENTIAL->value,
            'prefix' => null,
            'current_value' => 100,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 9999999999,
            'cycle' => false,
            'pad_length' => 10,
            'max_history_count' => 0,
        ]);

        $this->repository
            ->shouldReceive('findByType')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn($sequence);

        $this->repository
            ->shouldReceive('findByTypeForUpdate')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn($sequence);

        $this->repository
            ->shouldReceive('insertCode')
            ->once()
            ->with(SequenceType::PRODUCT, '101');

        $this->repository
            ->shouldReceive('updateCurrentValue')
            ->once()
            ->with($sequence, 101)
            ->andReturn(true);

        // countCodes와 deleteOldCodes는 호출되지 않아야 함
        $this->repository
            ->shouldNotReceive('countCodes');

        $this->repository
            ->shouldNotReceive('deleteOldCodes');

        // Act
        $result = $this->service->generateCode(SequenceType::PRODUCT);

        // Assert
        $this->assertEquals('101', $result);
    }

    #[Test]
    public function test_no_cleanup_when_count_does_not_exceed_limit(): void
    {
        // Arrange: max_history_count = 10, 현재 5건
        $sequence = new Sequence([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::SEQUENTIAL->value,
            'prefix' => null,
            'current_value' => 100,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 9999999999,
            'cycle' => false,
            'pad_length' => 10,
            'max_history_count' => 10,
        ]);

        $this->repository
            ->shouldReceive('findByType')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn($sequence);

        $this->repository
            ->shouldReceive('findByTypeForUpdate')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn($sequence);

        $this->repository
            ->shouldReceive('insertCode')
            ->once()
            ->with(SequenceType::PRODUCT, '101');

        $this->repository
            ->shouldReceive('updateCurrentValue')
            ->once()
            ->with($sequence, 101)
            ->andReturn(true);

        // 현재 5건 (10 미만) → deleteOldCodes 호출 안 됨
        $this->repository
            ->shouldReceive('countCodes')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn(5);

        $this->repository
            ->shouldNotReceive('deleteOldCodes');

        // Act
        $result = $this->service->generateCode(SequenceType::PRODUCT);

        // Assert
        $this->assertEquals('101', $result);
    }

    #[Test]
    public function test_initialize_sequence_product_default_max_history_count(): void
    {
        // Arrange: PRODUCT 기본값은 NanoID (max_history_count = 0)
        $expectedData = [
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
            'date_format' => null,
            'last_reset_date' => null,
        ];

        $createdSequence = new Sequence($expectedData);

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->with($expectedData)
            ->andReturn($createdSequence);

        // Act
        $result = $this->service->initializeSequence(SequenceType::PRODUCT);

        // Assert: PRODUCT NanoID 기본값 0 (이력 미사용)
        $this->assertEquals(0, $result->max_history_count);
    }

    #[Test]
    public function test_initialize_sequence_with_custom_max_history_count(): void
    {
        // Arrange: 커스텀 max_history_count 옵션 전달
        $expectedData = [
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::NANOID->value,
            'prefix' => null,
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 0,
            'cycle' => false,
            'pad_length' => 0,
            'max_history_count' => 1000,
            'date_format' => null,
            'last_reset_date' => null,
        ];

        $createdSequence = new Sequence($expectedData);

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->with($expectedData)
            ->andReturn($createdSequence);

        // Act
        $result = $this->service->initializeSequence(SequenceType::PRODUCT, [
            'max_history_count' => 1000,
        ]);

        // Assert
        $this->assertEquals(1000, $result->max_history_count);
    }

    #[Test]
    public function test_throws_duplicate_exception_on_unique_constraint_violation(): void
    {
        // Arrange
        $sequence = new Sequence([
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

        $this->repository
            ->shouldReceive('findByType')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn($sequence);

        $this->repository
            ->shouldReceive('findByTypeForUpdate')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn($sequence);

        // UNIQUE 제약조건 위반 예외 (SQLSTATE 23000)
        // PDOException code는 int이므로 23000 사용
        $pdoException = new PDOException('Duplicate entry', 23000);
        $queryException = new QueryException(
            'mysql',
            'INSERT INTO ecommerce_sequence_codes ...',
            [],
            $pdoException
        );

        $this->repository
            ->shouldReceive('insertCode')
            ->once()
            ->andThrow($queryException);

        // Assert
        $this->expectException(SequenceCodeDuplicateException::class);

        // Act
        $this->service->generateCode(SequenceType::PRODUCT);
    }

    #[Test]
    public function test_rethrows_query_exception_for_non_duplicate_errors(): void
    {
        // Arrange
        $sequence = new Sequence([
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

        $this->repository
            ->shouldReceive('findByType')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn($sequence);

        $this->repository
            ->shouldReceive('findByTypeForUpdate')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn($sequence);

        // 다른 SQL 에러 (중복이 아닌 에러)
        // PDOException code는 int이므로 0 사용
        $pdoException = new PDOException('Connection lost', 0);
        $queryException = new QueryException(
            'mysql',
            'INSERT INTO ecommerce_sequence_codes ...',
            [],
            $pdoException
        );

        $this->repository
            ->shouldReceive('insertCode')
            ->once()
            ->andThrow($queryException);

        // Assert - 중복이 아닌 에러는 그대로 전파
        $this->expectException(QueryException::class);

        // Act
        $this->service->generateCode(SequenceType::PRODUCT);
    }

    // ========================================
    // NanoID 알고리즘 테스트
    // ========================================

    #[Test]
    public function test_nanoid_generates_string_with_correct_length(): void
    {
        // Arrange: NanoID 알고리즘 시퀀스
        $sequence = new Sequence([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::NANOID->value,
            'prefix' => null,
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 0,
            'cycle' => false,
            'pad_length' => 0,
        ]);

        $this->repository
            ->shouldReceive('findByType')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn($sequence);

        // NanoID는 insertCode, updateCurrentValue를 호출하지 않음
        $this->repository->shouldNotReceive('findByTypeForUpdate');
        $this->repository->shouldNotReceive('insertCode');
        $this->repository->shouldNotReceive('updateCurrentValue');

        // Act
        $result = $this->service->generateCode(SequenceType::PRODUCT);

        // Assert: 16자 대문자+숫자 문자열
        $this->assertIsString($result);
        $this->assertEquals(16, strlen($result));
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{16}$/', $result);
    }

    #[Test]
    public function test_nanoid_does_not_use_sequence_table_operations(): void
    {
        // Arrange: NanoID 알고리즘 시퀀스
        $sequence = new Sequence([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::NANOID->value,
            'prefix' => null,
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 0,
            'cycle' => false,
            'pad_length' => 0,
        ]);

        $this->repository
            ->shouldReceive('findByType')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn($sequence);

        // DB 쓰기 관련 메서드 호출 금지 확인
        $this->repository->shouldNotReceive('findByTypeForUpdate');
        $this->repository->shouldNotReceive('insertCode');
        $this->repository->shouldNotReceive('updateCurrentValue');
        $this->repository->shouldNotReceive('countCodes');
        $this->repository->shouldNotReceive('deleteOldCodes');

        // Act
        $result = $this->service->generateCode(SequenceType::PRODUCT);

        // Assert: 코드가 정상 생성됨 (Mockery shouldNotReceive는 tearDown에서 검증)
        $this->assertIsString($result);
    }

    #[Test]
    public function test_nanoid_with_prefix(): void
    {
        // Arrange: 접두사가 있는 NanoID 시퀀스
        $sequence = new Sequence([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::NANOID->value,
            'prefix' => 'PRD-',
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 0,
            'cycle' => false,
            'pad_length' => 0,
        ]);

        $this->repository
            ->shouldReceive('findByType')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn($sequence);

        // Act
        $result = $this->service->generateCode(SequenceType::PRODUCT);

        // Assert: 접두사 + 16자 NanoID
        $this->assertStringStartsWith('PRD-', $result);
        $this->assertEquals(20, strlen($result)); // 4(prefix) + 16(nanoid)
        $this->assertMatchesRegularExpression('/^PRD-[0-9A-Z]{16}$/', $result);
    }

    #[Test]
    public function test_nanoid_generates_unique_codes(): void
    {
        // Arrange: NanoID 알고리즘 시퀀스
        $sequence = new Sequence([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => SequenceAlgorithm::NANOID->value,
            'prefix' => null,
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => 0,
            'cycle' => false,
            'pad_length' => 0,
        ]);

        $this->repository
            ->shouldReceive('findByType')
            ->andReturn($sequence);

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
    public function test_nanoid_throws_exception_when_sequence_not_found(): void
    {
        // Arrange
        $this->repository
            ->shouldReceive('findByType')
            ->once()
            ->with(SequenceType::PRODUCT)
            ->andReturn(null);

        // Assert
        $this->expectException(SequenceNotFoundException::class);

        // Act
        $this->service->generateCode(SequenceType::PRODUCT);
    }
}
