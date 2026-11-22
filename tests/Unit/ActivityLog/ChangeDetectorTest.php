<?php

namespace Tests\Unit\ActivityLog;

use App\ActivityLog\ChangeDetector;
use Illuminate\Database\Eloquent\Model;
use Tests\PureTestCase;

/**
 * ChangeDetector 테스트
 *
 * 모델의 $activityLogFields 메타데이터와 스냅샷을 비교하여
 * 구조화된 변경 이력을 올바르게 생성하는지 검증합니다.
 */
class ChangeDetectorTest extends PureTestCase
{
    /**
     * 변경된 필드를 올바르게 감지하는지 확인
     *
     * @scenario case=text_field_change
     *
     * @effects changed_text_fields_reported_with_label_key
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=no_change
     *
     * @effects unchanged_snapshot_yields_null
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=no_change
     *
     * @effects missing_snapshot_yields_null
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=enum_field_change
     *
     * @effects enum_change_carries_old_and_new_label_keys
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=enum_field_change
     *
     * @effects null_enum_old_value_has_null_label_key
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=untracked_field
     *
     * @effects untracked_fields_ignored
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=untracked_field
     *
     * @effects model_without_tracked_fields_yields_null
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=enum_field_change
     *
     * @effects backed_enum_snapshot_compared_by_value
     *
     * @return void 반환값 없음
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

    /**
     * display 콜러블이 선언된 필드는 원값으로 비교한 뒤 old·new 를 요약값으로 기록한다 (#381 메뉴 아이콘 SVG).
     *
     * @scenario case=text_field_change
     *
     * @effects display_meta_summarizes_values
     *
     * @return void 반환값 없음
     */
    public function test_display_callable_summarizes_old_and_new(): void
    {
        $longNew = str_repeat('b', 5000);
        $model = new ChangeDetectorDisplayTestModel;
        $model->body = $longNew;
        $model->title = '새 제목';

        $changes = ChangeDetector::detect($model, ['body' => 'short', 'title' => '옛 제목']);

        $this->assertNotNull($changes);
        $body = collect($changes)->firstWhere('field', 'body');
        $this->assertSame('len:5', $body['old']);
        $this->assertSame('len:5000', $body['new']);
        $this->assertSame('model.body', $body['label_key']);
        $this->assertSame('text', $body['type']);

        // display 가 없는 필드는 원값 그대로
        $title = collect($changes)->firstWhere('field', 'title');
        $this->assertSame('옛 제목', $title['old']);
        $this->assertSame('새 제목', $title['new']);
    }

    /**
     * 요약값이 같아도 원값이 같으면 변경이 아니고, 원값이 다르면 요약값이 같더라도 변경으로 남는다(비교는 원값 기준).
     *
     * @scenario case=no_change
     *
     * @effects display_meta_summarizes_values, unchanged_snapshot_yields_null
     *
     * @return void 반환값 없음
     */
    public function test_display_field_compares_raw_values(): void
    {
        $model = new ChangeDetectorDisplayTestModel;
        $model->body = 'same';
        $model->title = '제목';

        $this->assertNull(ChangeDetector::detect($model, ['body' => 'same', 'title' => '제목']));

        // 길이가 같아 요약값이 같은(len:4) 다른 원값 — 변경으로 기록된다
        $changes = ChangeDetector::detect($model, ['body' => 'diff', 'title' => '제목']);
        $this->assertCount(1, $changes);
        $this->assertSame('len:4', $changes[0]['old']);
        $this->assertSame('len:4', $changes[0]['new']);
    }
}

// ============================================================
// 테스트용 모델 및 Enum 정의
// ============================================================

/**
 * 테스트용 모델 (display 요약 콜러블이 있는 text 필드)
 */
class ChangeDetectorDisplayTestModel extends Model
{
    /** @var array<string, array> */
    public static array $activityLogFields = [
        'body' => ['label_key' => 'model.body', 'type' => 'text', 'display' => [self::class, 'summarize']],
        'title' => ['label_key' => 'model.title', 'type' => 'text'],
    ];

    protected $guarded = [];

    /**
     * 값을 길이 요약으로 바꿉니다.
     *
     * @param  mixed  $value  원값
     * @return string|null 요약값 (null 이면 null)
     */
    public static function summarize(mixed $value): ?string
    {
        return $value === null ? null : 'len:'.strlen((string) $value);
    }
}

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

    /**
     * status 속성을 테스트용 Enum 으로 캐스팅하도록 선언한다.
     *
     * @return array<string, string> 속성명 → 캐스트 클래스 매핑
     */
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
     * @return string 'test.status.' 접두어에 case 값을 붙인 라벨 키
     */
    public function labelKey(): string
    {
        return 'test.status.'.$this->value;
    }
}
