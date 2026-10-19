<?php

namespace Tests\Support\Concerns;

use App\Enums\ExtensionOwnerType;
use App\Extension\Helpers\ExtensionScheduleSyncHelper;
use App\Extension\Validators\ScheduleManifestValidator;
use App\Models\Schedule;
use App\Services\Schedule\ScheduleConditionEvaluator;

/**
 * 번들 확장 `getSchedules()` 선언이 7.2.0 새 형식이고 코어 동기화에서 걸러지지 않는지 단언합니다.
 *
 * 동기화 헬퍼는 검증 위반 항목을 경고 로그만 남기고 건너뛴다 — 선언이 틀리면 설치 뒤 스케줄이 조용히 빠진다.
 * 그래서 각 확장 테스트가 자기 선언을 실제 정규화·검증기·조건 해석기에 통과시켜 본다.
 *
 * 사용: 확장 테스트에서 `use AssertsExtensionScheduleDeclarations;` 후
 * `$this->assertScheduleDeclarationsSyncable((new Module)->getSchedules(), ExtensionOwnerType::Module, 'vendor-id');`
 */
trait AssertsExtensionScheduleDeclarations
{
    /**
     * 선언 목록 전체가 새 형식이고 정규화·검증·조건 해석을 통과하는지 단언합니다.
     *
     * - 각 항목: `name`·`type` 명시, 옛 키(`schedule`·`enabled_config`) 없음, `label`·`description` 의 ko·en 채움
     * - 정규화(`ExtensionScheduleSyncHelper::normalizeDefinition`) 뒤 검증기 위반 0
     * - `enabled_condition` 의 각 조건이 소유 확장 설정 기본값에서 해석 가능
     * - `name` 이 확장 안에서 고유
     *
     * @param  array<int, mixed>  $schedules  getSchedules() 결과
     * @param  ExtensionOwnerType  $ownerType  소유 타입 (module|plugin)
     * @param  string  $identifier  확장 식별자
     * @return void 반환값 없음
     */
    protected function assertScheduleDeclarationsSyncable(array $schedules, ExtensionOwnerType $ownerType, string $identifier): void
    {
        $this->assertTrue(array_is_list($schedules), "{$identifier}: getSchedules() 는 목록이어야 합니다");
        $this->assertNotSame([], $schedules, "{$identifier}: 선언이 비어 있습니다");

        $helper = app(ExtensionScheduleSyncHelper::class);
        $validator = app(ScheduleManifestValidator::class);
        $evaluator = app(ScheduleConditionEvaluator::class);
        $names = [];

        foreach ($schedules as $index => $definition) {
            $this->assertIsArray($definition, "{$identifier}[{$index}] 는 배열이어야 합니다");
            $name = (string) ($definition['name'] ?? '');
            $context = "{$identifier}:{$name}";

            $this->assertNotSame('', $name, "{$identifier}[{$index}]: name 이 없으면 옛 형식으로 정규화됩니다");
            $this->assertArrayHasKey('type', $definition, "{$context}: type 을 명시해야 새 형식으로 판별됩니다");
            $this->assertArrayNotHasKey('schedule', $definition, "{$context}: 옛 키 schedule 대신 expression");
            $this->assertArrayNotHasKey('enabled_config', $definition, "{$context}: 옛 키 enabled_config 대신 enabled_condition");
            foreach (['label', 'description'] as $field) {
                foreach (['ko', 'en'] as $locale) {
                    $this->assertNotEmpty($definition[$field][$locale] ?? null, "{$context}: {$field}.{$locale} 이 비었습니다");
                }
            }

            $normalized = $helper->normalizeDefinition($definition);
            $this->assertSame([], $validator->errors($normalized, $ownerType), "{$context}: 선언 검증 위반");

            foreach ($definition['enabled_condition'] ?? [] as $condition) {
                $this->assertTrue(
                    $evaluator->isResolvable($condition, $identifier),
                    "{$context}: 실행 조건 키 ".($condition['key'] ?? '?').' 를 설정 기본값에서 찾을 수 없습니다',
                );
            }

            $names[] = $name;
        }

        $this->assertSame(array_values(array_unique($names)), $names, "{$identifier}: name 이 중복됩니다");
    }

    /**
     * 옛 형식(name 없음)으로 만들어진 행이 새 형식 선언으로 동기화될 때 지워지지 않고 이어지는지 단언합니다.
     *
     * 코어를 먼저 7.2.0 으로 올리면 아직 옛 버전인 확장의 선언이 명령에서 만든 자동 이름으로 행을 만든다. 확장을
     * 새 형식으로 업데이트하면 동기화가 그 행을 새 name 으로 이어받아야 운영자 수정·실행 이력이 남는다 — 이 확장의
     * 실제 선언(명령·순서)으로 옛 행을 만들고 새 선언으로 다시 동기화해, 행 수·ID 가 그대로이고 정리된 행이 0 인지 본다.
     * 옛 버전과 명령 문자열·순서가 같다는 전제다(명령을 바꾸면 다른 작업으로 보고 새 행이 생긴다).
     *
     * @param  array<int, array<string, mixed>>  $schedules  getSchedules() 결과 (새 형식)
     * @param  ExtensionOwnerType  $ownerType  소유 타입 (module|plugin)
     * @param  string  $identifier  확장 식별자
     * @return void 반환값 없음
     */
    protected function assertLegacyRowsAdoptedOnFormatUpgrade(array $schedules, ExtensionOwnerType $ownerType, string $identifier): void
    {
        $this->assertNotSame([], $schedules, "{$identifier}: 선언이 비어 있습니다");
        $helper = app(ExtensionScheduleSyncHelper::class);
        $owned = fn () => Schedule::ownedBy($ownerType, $identifier);
        $owned()->get()->each(fn (Schedule $row) => $row->delete());

        $legacy = array_map(fn (array $definition) => [
            'command' => $definition['command'],
            'schedule' => $definition['expression'],
        ], $schedules);
        $helper->syncSchedules($ownerType, $identifier, $legacy);
        $legacyIds = $owned()->orderBy('id')->pluck('id')->all();
        $this->assertCount(count($schedules), $legacyIds, "{$identifier}: 옛 형식 행 수");

        $result = $helper->syncSchedules($ownerType, $identifier, $schedules);

        $this->assertSame(0, $result['removed'], "{$identifier}: 새 형식 동기화가 옛 행을 지웠습니다");
        $this->assertSame($legacyIds, $owned()->orderBy('id')->pluck('id')->all(), "{$identifier}: 행 ID 가 바뀌었습니다");
        $this->assertEqualsCanonicalizing(
            array_column($schedules, 'name'),
            $owned()->pluck('name')->all(),
            "{$identifier}: 이어받은 행의 이름이 선언 name 과 다릅니다",
        );
    }

    /**
     * 선언의 이름 ⇒ [명령, cron 식, 시간대, 조건 키 목록] 표를 만듭니다 (실행 시각 보존 대조용).
     *
     * @param  array<int, array<string, mixed>>  $schedules  getSchedules() 결과
     * @return array<string, array{0: string, 1: string, 2: string|null, 3: array<int, string>}> 이름별 요약
     */
    protected function scheduleDeclarationTable(array $schedules): array
    {
        $table = [];
        foreach ($schedules as $definition) {
            $table[$definition['name']] = [
                (string) $definition['command'],
                (string) $definition['expression'],
                $definition['timezone'] ?? null,
                array_map(fn (array $c) => $c['source'].':'.$c['key'], $definition['enabled_condition'] ?? []),
            ];
        }

        return $table;
    }
}
