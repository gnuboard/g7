<?php

namespace Database\Factories;

use App\Enums\ExtensionOwnerType;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 스케줄 모델 팩토리.
 *
 * 기본 상태는 운영자 직접 등록 artisan 행이다. 소유 행은 `ownedBy()` 로 만든다.
 *
 * @extends Factory<Schedule>
 *
 * @since 7.2.0
 */
class ScheduleFactory extends Factory
{
    /**
     * 팩토리가 만드는 모델 클래스.
     *
     * @var class-string<Schedule>
     */
    protected $model = Schedule::class;

    /**
     * 기본 상태를 정의합니다.
     *
     * @return array<string, mixed> 스케줄 기본 속성
     */
    public function definition(): array
    {
        $name = 'schedule-'.$this->faker->unique()->slug(2);

        return [
            'name' => $name,
            'label' => ['ko' => $name, 'en' => $name],
            'description' => null,
            'type' => 'artisan',
            'command' => 'inspire',
            'command_class' => null,
            'expression' => '0 3 * * *',
            'timezone' => null,
            'frequency' => 'custom',
            'without_overlapping' => 0,
            'on_one_server' => false,
            'dispatch_mode' => 'sync',
            'queue_connection' => null,
            'queue_name' => null,
            'history_mode' => 'all',
            'notify_on_failure' => true,
            'enabled_condition' => null,
            'run_in_maintenance' => false,
            'timeout' => null,
            'is_active' => true,
            'last_result' => 'never',
            'extension_type' => null,
            'extension_identifier' => null,
        ];
    }

    /**
     * 코어·확장 소유 행 상태.
     *
     * @param  ExtensionOwnerType  $type  소유 타입
     * @param  string  $identifier  소유 식별자 (코어는 'core')
     * @return static 소유 행 상태가 적용된 팩토리
     */
    public function ownedBy(ExtensionOwnerType $type, string $identifier): static
    {
        return $this->state(fn () => [
            'extension_type' => $type->value,
            'extension_identifier' => $identifier,
        ]);
    }
}
