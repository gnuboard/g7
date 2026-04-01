<?php

namespace Tests\Unit\ActivityLog;

use App\ActivityLog\ChangeDetector;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

/**
 * ChangeDetector 테스트
 *
 * 모델의 $activityLogFields 메타데이터와 스냅샷을 비교하여
 * 구조화된 변경 이력을 올바르게 생성하는지 검증합니다.
 */
class ChangeDetectorTest extends TestCase
{
    /**
     * 변경된 필드를 올바르게 감지하는지 확인
     */
    public function test_detects_changed_fields(): void
    {
        $model = new ChangeDetectorTestModel;
        $model->name = '변경 후';
        $model->email = 'new@example.com';

        $snapshot = [
            'name' => '변경 전',
            'email' => 'old@example.com',
        ];

        $changes = ChangeDetector::detect($model, $snapshot);

        $this->assertNotNull($changes);
        $this->assertCount(2, $changes);

        // name 필드 변경 확인
        $nameChange = collect($changes)->firstWhere('field', 'name');
        $this->assertEquals('model.name', $nameChange['label_key']);
        $this->assertEquals('변경 전', $nameChange['old']);
        $this->assertEquals('변경 후', $nameChange['new']);
        $this->assertEquals('text', $nameChange['type']);

        // email 필드 변경 확인
        $emailChange = collect($changes)->firstWhere('field', 'email');
        $this->assertEquals('model.email', $emailChange['label_key']);
        $this->assertEquals('old@example.com', $emailChange['old']);
        $this->assertEquals('new@example.com', $emailChange['new']);
    }

    /**
     * 변경이 없으면 null을 반환하는지 확인
     */
    public function test_returns_null_when_no_changes(): void
    {
        $model = new ChangeDetectorTestModel;
        $model->name = '동일값';
        $model->email = 'same@example.com';

        $snapshot = [
            'name' => '동일값',
            'email' => 'same@example.com',
        ];

        $changes = ChangeDetector::detect($model, $snapshot);

        $this->assertNull($changes);
    }

    /**
     * snapshot이 null이면 null을 반환하는지 확인
     */
    public function test_returns_null_when_snapshot_is_null(): void
    {
        $model = new ChangeDetectorTestModel;
        $model->name = 'test';

        $changes = ChangeDetector::detect($model, null);

        $this->assertNull($changes);
    }

    /**
     * enum 타입 필드의 old_label_key와 new_label_key가 포함되는지 확인
     */
    public function test_handles_enum_type_with_label_keys(): void
    {
        $model = new ChangeDetectorEnumTestModel;
        $model->status = ChangeDetectorTestStatus::Active;

        $snapshot = [
            'status' => 'inactive',
        ];

        $changes = ChangeDetector::detect($model, $snapshot);

        $this->assertNotNull($changes);
        $this->assertCount(1, $changes);

        $statusChange = $changes[0];
        $this->assertEquals('status', $statusChange['field']);
        $this->assertEquals('model.status', $statusChange['label_key']);
        $this->assertEquals('enum', $statusChange['type']);
        $this->assertEquals('inactive', $statusChange['old']);
        $this->assertEquals('active', $statusChange['new']);
        $this->assertEquals('test.status.inactive', $statusChange['old_label_key']);
        $this->assertEquals('test.status.active', $statusChange['new_label_key']);
    }

    /**
     * enum 타입 필드의 old가 null이면 old_label_key도 null인지 확인
     */
    public function test_enum_null_old_value_returns_null_label_key(): void
    {
        $model = new ChangeDetectorEnumTestModel;
        $model->status = ChangeDetectorTestStatus::Active;

        $snapshot = [
            'status' => null,
        ];

        $changes = ChangeDetector::detect($model, $snapshot);

        $this->assertNotNull($changes);
        $statusChange = $changes[0];
        $this->assertNull($statusChange['old_label_key']);
        $this->assertEquals('test.status.active', $statusChange['new_label_key']);
    }

    /**
     * $activityLogFields에 없는 필드는 무시되는지 확인
     */
    public function test_ignores_untracked_fields(): void
    {
        $model = new ChangeDetectorTestModel;
        $model->name = '변경됨';
        $model->email = 'same@example.com';
        $model->untracked_field = '추적 안 됨';

        $snapshot = [
            'name' => '원본',
            'email' => 'same@example.com',
            'untracked_field' => '이전값',
        ];

        $changes = ChangeDetector::detect($model, $snapshot);

        $this->assertNotNull($changes);
        $this->assertCount(1, $changes);
        $this->assertEquals('name', $changes[0]['field']);
    }

    /**
     * $activityLogFields가 없는 모델은 null을 반환하는지 확인
     */
    public function test_returns_null_when_model_has_no_activity_log_fields(): void
    {
        $model = new ChangeDetectorNoFieldsModel;
        $model->name = 'test';

        $snapshot = ['name' => 'old'];

        $changes = ChangeDetector::detect($model, $snapshot);

        $this->assertNull($changes);
    }

    /**
     * BackedEnum 객체가 스냅샷에 있을 때 값으로 변환하여 비교하는지 확인
     */
    public function test_converts_backed_enum_in_snapshot_to_value(): void
    {
        $model = new ChangeDetectorEnumTestModel;
        $model->status = ChangeDetectorTestStatus::Active;

        // 스냅샷에 Enum 객체가 들어있는 경우 (toArray() 시 발생 가능)
        $snapshot = [
            'status' => ChangeDetectorTestStatus::Inactive,
        ];

        $changes = ChangeDetector::detect($model, $snapshot);

        $this->assertNotNull($changes);
        $this->assertEquals('inactive', $changes[0]['old']);
        $this->assertEquals('active', $changes[0]['new']);
    }
}

// ============================================================
// 테스트용 모델 및 Enum 정의
// ============================================================

/**
 * 테스트용 모델 (text 필드만)
 */
class ChangeDetectorTestModel extends Model
{
    /** @var array<string, array> */
    public static array $activityLogFields = [
        'name' => ['label_key' => 'model.name', 'type' => 'text'],
        'email' => ['label_key' => 'model.email', 'type' => 'text'],
    ];

    protected $guarded = [];
}

/**
 * 테스트용 모델 (enum 필드 포함)
 */
class ChangeDetectorEnumTestModel extends Model
{
    /** @var array<string, array> */
    public static array $activityLogFields = [
        'status' => [
            'label_key' => 'model.status',
            'type' => 'enum',
            'enum' => ChangeDetectorTestStatus::class,
        ],
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ChangeDetectorTestStatus::class,
        ];
    }
}

/**
 * 테스트용 모델 ($activityLogFields 없음)
 */
class ChangeDetectorNoFieldsModel extends Model
{
    protected $guarded = [];
}

/**
 * 테스트용 Enum (labelKey() 메서드 포함)
 */
enum ChangeDetectorTestStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    /**
     * 다국어 라벨 키를 반환합니다.
     *
     * @return string
     */
    public function labelKey(): string
    {
        return 'test.status.'.$this->value;
    }
}
