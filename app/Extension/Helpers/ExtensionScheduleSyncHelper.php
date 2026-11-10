<?php

namespace App\Extension\Helpers;

use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\ScheduleFrequency;
use App\Extension\Validators\ScheduleManifestValidator;
use App\Models\Schedule;
use Illuminate\Console\Scheduling\ManagesFrequencies;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 코어 카탈로그·확장 `getSchedules()` 선언을 schedules 테이블로 동기화합니다.
 *
 * - 행 식별: (extension_type, extension_identifier, name) — `uq_schedules_owner_name`
 * - 운영자 수정 보존: `HasUserOverrides::syncOrCreateFromUpgrade` (trackable·다국어 dot-path 단위)
 * - 완전 동기화: 같은 소유 범위에서 선언에 없는 행은 지운다(이력 포함). 직접 등록 행(extension_type NULL)은 범위 밖이다.
 * - 선언 검증: `ScheduleManifestValidator` — 위반 선언은 건너뛰고 경고 로그만 남긴다(그 이름의 기존 행은 지우지 않는다).
 *
 * 선언 형식(새 스키마, 옛 형식의 상위집합):
 * `name`, `label{}`, `description{}`, `type`(artisan|job), `command`, `expression`|`schedule`, `frequency`, `timezone`,
 * `without_overlapping`(분), `on_one_server`, `dispatch_mode`, `history_mode`, `notify_on_failure`, `timeout`,
 * `enabled_condition[]`, `command_class`, `is_active`, `run_in_maintenance`.
 * 옛 형식(`command`·`schedule`·`description` 문자열·`enabled_config`)은 `normalizeLegacySchedules()` 가 바꾼다.
 *
 * @since 7.2.0
 */
class ExtensionScheduleSyncHelper
{
    /** @var array<string, mixed> 선언에 없을 때 쓰는 기본값 */
    public const DEFAULTS = [
        'type' => 'artisan',
        'frequency' => 'custom',
        'timezone' => null,
        'without_overlapping' => 0,
        'on_one_server' => true,
        'dispatch_mode' => 'sync',
        'queue_connection' => null,
        'queue_name' => null,
        'history_mode' => 'all',
        'notify_on_failure' => true,
        'timeout' => null,
        'enabled_condition' => null,
        'command_class' => null,
        'run_in_maintenance' => false,
        'is_active' => true,
        'label' => null,
        'description' => null,
    ];

    /** @var array<int, string> DB 로 넘기는 선언 필드 (그 밖의 키 — 예: 카탈로그 group — 는 버린다) */
    private const COLUMNS = [
        'name', 'label', 'description', 'type', 'command', 'command_class', 'expression', 'timezone', 'frequency',
        'without_overlapping', 'on_one_server', 'dispatch_mode', 'queue_connection', 'queue_name', 'history_mode',
        'notify_on_failure', 'enabled_condition', 'run_in_maintenance', 'timeout', 'is_active',
    ];

    /**
     * @param  ScheduleManifestValidator  $validator  선언 검증기
     * @param  ScheduleRepositoryInterface  $schedules  스케줄 저장소 (옛 자동 이름 행 이어받기)
     */
    public function __construct(
        private readonly ScheduleManifestValidator $validator,
        private readonly ScheduleRepositoryInterface $schedules,
    ) {}

    /**
     * 옛 형식 `getSchedules()` 항목을 새 스키마로 바꿉니다. 이미 새 스키마(`name` 있음)면 그대로 둔다.
     *
     * - name: 명령 문자열을 소문자·`-` 로 바꾼 값(예: `sirsoft-board:aggregate-stats` → `sirsoft-board-aggregate-stats`), 겹치면 `-2`, `-3` …
     * - schedule: cron 식이면 그대로, Laravel 주기 메서드명(daily·hourly·everyFiveMinutes 등)이면 cron 으로 변환,
     *   everyThirtySeconds 는 30초 주기
     * - description 문자열: `{app.locale: 문자열}` 로 감싸 label·description 양쪽에 쓴다
     * - enabled_config `"{identifier}.key"`: 접두사를 떼어 `[{source: module_setting|plugin_setting, key, operator: truthy, default: true}]`
     *   (현행 console.php 의 `(bool) module_setting($id, $key, true)` 의미 보존)
     *
     * @param  array<int|string, mixed>  $definitions  getSchedules() 결과
     * @param  ExtensionOwnerType  $ownerType  소유 타입 (module|plugin)
     * @param  string  $identifier  확장 식별자
     * @return array<int, array<string, mixed>> 새 스키마 선언 목록 (변환할 수 없는 항목은 빠진다)
     */
    public function normalizeLegacySchedules(array $definitions, ExtensionOwnerType $ownerType, string $identifier): array
    {
        $out = [];
        $usedNames = [];
        $locale = (string) config('app.locale', 'ko');

        foreach ($definitions as $key => $def) {
            if (! is_array($def)) {
                continue;
            }

            if (! isset($def['name']) && is_string($key)) {
                $def['name'] = $key;
            }
            if (isset($def['name'])) {
                $usedNames[] = (string) $def['name'];
                $out[] = $def;

                continue;
            }

            if (empty($def['command']) || ! is_string($def['command'])) {
                continue;
            }

            $base = $this->autoNameBase($def['command']);
            $name = $base;
            for ($n = 2; in_array($name, $usedNames, true); $n++) {
                $name = "{$base}-{$n}";
            }
            $usedNames[] = $name;

            $converted = ['name' => $name, 'command' => $def['command']];
            foreach (['type', 'timezone', 'without_overlapping', 'on_one_server', 'dispatch_mode', 'history_mode',
                'notify_on_failure', 'timeout', 'command_class', 'is_active', 'run_in_maintenance', 'label'] as $field) {
                if (array_key_exists($field, $def)) {
                    $converted[$field] = $def[$field];
                }
            }
            // 옛 형식 선언의 기본값 — 중복 실행 방지(1440분)(#161 F3, 7.1.5). 길어진 매시 작업이 다음 회차와 겹치던 것을
            // 막는다. 끄려면 `without_overlapping => false`, 분 단위 정수면 그 시간 뒤 잠금이 만료된다.
            // 「한 서버에서만 실행」(`on_one_server`)은 새 스키마 기본값이 이미 true 다.
            $converted['without_overlapping'] ??= true;
            if (isset($def['schedule'])) {
                $converted['schedule'] = $def['schedule'];
            }

            if (isset($def['description'])) {
                $description = is_array($def['description']) ? $def['description'] : [$locale => (string) $def['description']];
                $converted['description'] = $description;
                $converted['label'] ??= $description;
            }

            if (isset($def['enabled_config']) && is_string($def['enabled_config']) && $def['enabled_config'] !== '') {
                $settingKey = $def['enabled_config'];
                if (str_starts_with($settingKey, $identifier.'.')) {
                    $settingKey = substr($settingKey, strlen($identifier) + 1);
                }
                $converted['enabled_condition'] = [[
                    'source' => $ownerType === ExtensionOwnerType::Plugin ? 'plugin_setting' : 'module_setting',
                    'key' => $settingKey,
                    'operator' => 'truthy',
                    'default' => true,
                ]];
            }

            $out[] = $converted;
        }

        return $out;
    }

    /**
     * 선언 1건을 기본값을 채운 DB 속성 모양으로 정규화합니다.
     *
     * @param  array<string, mixed>  $definition  새 스키마 선언 (`schedule` 별칭 허용)
     * @return array<string, mixed> DB 속성 (name·command 포함)
     */
    public function normalizeDefinition(array $definition): array
    {
        $attributes = array_merge(self::DEFAULTS, array_intersect_key($definition, array_flip(self::COLUMNS)));
        $locale = (string) config('app.locale', 'ko');

        if (! isset($definition['expression']) && isset($definition['schedule'])) {
            [$expression, $frequency] = $this->resolveSchedule((string) $definition['schedule']);
            $attributes['expression'] = $expression;
            if (! isset($definition['frequency'])) {
                $attributes['frequency'] = $frequency;
            }
        }

        if (($attributes['frequency'] ?? null) === ScheduleFrequency::EveryThirtySeconds->value) {
            $attributes['expression'] = '* * * * *';
        }

        foreach (['label', 'description'] as $field) {
            if (is_string($attributes[$field])) {
                $attributes[$field] = [$locale => $attributes[$field]];
            }
        }

        // 옛 boolean 선언 호환 — true 는 Laravel withoutOverlapping() 기본(1440분)과 같게 본다
        if (is_bool($attributes['without_overlapping'])) {
            $attributes['without_overlapping'] = $attributes['without_overlapping'] ? 1440 : 0;
        }

        foreach (['on_one_server', 'notify_on_failure', 'run_in_maintenance', 'is_active'] as $field) {
            $attributes[$field] = (bool) $attributes[$field];
        }

        return $attributes;
    }

    /**
     * 한 소유자의 선언 전체를 DB 와 동기화합니다.
     *
     * @param  ExtensionOwnerType  $ownerType  소유 타입
     * @param  string  $identifier  소유 식별자 (코어는 'core')
     * @param  array<int|string, mixed>  $definitions  선언 목록 (키가 문자열이면 name 으로 쓴다 — 코어 카탈로그 형식)
     * @return array{synced: array<int, string>, skipped: array<string, array<int, string>>, removed: int} 동기화 결과
     */
    public function syncSchedules(ExtensionOwnerType $ownerType, string $identifier, array $definitions): array
    {
        $result = ['synced' => [], 'skipped' => [], 'removed' => 0];

        if (! $this->isSchemaReady()) {
            Log::warning('[ScheduleSync] schedules 테이블이 7.2.0 스키마가 아님 — 동기화 건너뜀', [
                'extension_type' => $ownerType->value,
                'extension_identifier' => $identifier,
            ]);

            return $result;
        }

        if ($ownerType !== ExtensionOwnerType::Core) {
            $definitions = $this->normalizeLegacySchedules($definitions, $ownerType, $identifier);
        }

        // 확장은 옛 형식이 만들던 자동 이름 행을 새 name 으로 이어받는다 — 코어를 먼저 7.2.0 으로 올린 사이트에서
        // 옛 버전 확장이 자동 이름으로 만든 행(운영자 수정·실행 이력 포함)이 새 형식 업데이트 때 지워지지 않게 한다.
        $autoNames = $ownerType === ExtensionOwnerType::Core ? [] : $this->legacyAutoNames($definitions);
        $declaredNames = array_values(array_filter(array_map(
            fn ($definition) => is_array($definition) && is_string($definition['name'] ?? null) ? $definition['name'] : null,
            $definitions,
        )));

        $keepNames = [];
        foreach ($definitions as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }
            if (! isset($definition['name']) && is_string($key)) {
                $definition['name'] = $key;
            }

            $attributes = $this->normalizeDefinition($definition);
            $name = is_string($attributes['name'] ?? null) ? $attributes['name'] : null;
            if ($name !== null) {
                $keepNames[] = $name;
            }

            $errors = $this->validator->errors($attributes, $ownerType);
            if ($errors !== []) {
                $result['skipped'][$name ?? (string) $key] = $errors;
                Log::warning('[ScheduleSync] 잘못된 스케줄 선언 — 건너뜀', [
                    'extension_type' => $ownerType->value,
                    'extension_identifier' => $identifier,
                    'name' => $name,
                    'errors' => $errors,
                ]);

                continue;
            }

            if ($name !== null) {
                $this->adoptAutoNamedRow($ownerType, $identifier, $name, $autoNames[$key] ?? null, $declaredNames);
            }

            unset($attributes['name']);
            Schedule::syncOrCreateFromUpgrade(
                ['extension_type' => $ownerType->value, 'extension_identifier' => $identifier, 'name' => $name],
                $attributes,
            );
            $result['synced'][] = $name;
        }

        $result['removed'] = $this->removeStale($ownerType, $identifier, array_values(array_unique($keepNames)));

        return $result;
    }

    /**
     * 한 소유자의 스케줄 행을 이력과 함께 모두 지웁니다 (확장 제거 — 데이터 삭제 시).
     *
     * @param  ExtensionOwnerType  $ownerType  소유 타입
     * @param  string  $identifier  소유 식별자
     * @return int 지운 스케줄 수
     */
    public function cleanupSchedulesForExtension(ExtensionOwnerType $ownerType, string $identifier): int
    {
        if (! Schema::hasTable('schedules')) {
            return 0;
        }

        return $this->deleteRows(Schedule::ownedBy($ownerType, $identifier)->get(['id', 'name']), $ownerType, $identifier);
    }

    /**
     * 명령 문자열에서 옛 형식의 자동 이름 바탕을 만듭니다 (소문자 + 영숫자 외 문자는 `-`).
     *
     * @param  string  $command  선언 명령 (예: `sirsoft-board:aggregate-stats`)
     * @return string 자동 이름 바탕 (예: `sirsoft-board-aggregate-stats`)
     */
    private function autoNameBase(string $command): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($command)), '-');
    }

    /**
     * 선언 순서대로 옛 형식이 만들었을 자동 이름을 계산합니다 (같은 명령이 겹치면 `-2`, `-3` …).
     *
     * 옛 형식에는 name 이 없었으므로 명시 name 과 무관하게 명령만으로 계산해야 옛 버전이 만든 이름과 같아진다.
     *
     * @param  array<int|string, mixed>  $definitions  정규화된 선언 목록
     * @return array<int|string, string> 선언 키 => 자동 이름 (명령이 없는 항목은 빠진다)
     */
    private function legacyAutoNames(array $definitions): array
    {
        $names = [];
        $used = [];
        foreach ($definitions as $key => $definition) {
            if (! is_array($definition) || ! is_string($definition['command'] ?? null) || $definition['command'] === '') {
                continue;
            }
            $base = $this->autoNameBase($definition['command']);
            $name = $base;
            for ($n = 2; in_array($name, $used, true); $n++) {
                $name = "{$base}-{$n}";
            }
            $used[] = $name;
            $names[$key] = $name;
        }

        return $names;
    }

    /**
     * 새 name 행이 아직 없고 같은 명령의 옛 자동 이름 행이 있으면 그 행의 이름만 바꿔 이어 씁니다.
     *
     * 이름만 바꾸므로 운영자 수정(user_overrides)·실행 이력·다음 실행 시각이 그대로 남고, 이어지는 동기화가 보존 규칙대로
     * 갱신한다. 자동 이름이 다른 선언의 name 으로 쓰이고 있으면 그 선언의 행이므로 건드리지 않는다.
     *
     * @param  ExtensionOwnerType  $ownerType  소유 타입
     * @param  string  $identifier  소유 식별자
     * @param  string  $name  새 형식 선언의 name
     * @param  string|null  $autoName  같은 선언의 옛 자동 이름 (명령이 없으면 null)
     * @param  array<int, string>  $declaredNames  이번 선언에 쓰인 name 전체
     * @return void 반환값 없음
     */
    private function adoptAutoNamedRow(ExtensionOwnerType $ownerType, string $identifier, string $name, ?string $autoName, array $declaredNames): void
    {
        if ($autoName === null || $autoName === $name || in_array($autoName, $declaredNames, true)) {
            return;
        }
        if (Schedule::ownedBy($ownerType, $identifier)->where('name', $name)->exists()) {
            return;
        }

        $legacy = $this->schedules->findOwned($ownerType, $identifier, $autoName);
        if ($legacy === null) {
            return;
        }

        $this->schedules->rename($legacy->id, $name);
        Log::info('[ScheduleSync] 옛 자동 이름 행을 새 name 으로 이어받음', [
            'extension_type' => $ownerType->value,
            'extension_identifier' => $identifier,
            'from' => $autoName,
            'to' => $name,
            'schedule_id' => $legacy->id,
        ]);
    }

    /**
     * 같은 소유 범위에서 선언에 없는 행을 지웁니다.
     *
     * @param  ExtensionOwnerType  $ownerType  소유 타입
     * @param  string  $identifier  소유 식별자
     * @param  array<int, string>  $keepNames  남길 name 목록
     * @return int 지운 행 수
     */
    private function removeStale(ExtensionOwnerType $ownerType, string $identifier, array $keepNames): int
    {
        $stale = Schedule::ownedBy($ownerType, $identifier)->whereNotIn('name', $keepNames)->get(['id', 'name']);

        return $this->deleteRows($stale, $ownerType, $identifier);
    }

    /**
     * 스케줄 행과 그 이력을 지웁니다 (DB CASCADE 에 기대지 않는다).
     *
     * @param  iterable<Schedule>  $rows  지울 행
     * @param  ExtensionOwnerType  $ownerType  소유 타입 (로그용)
     * @param  string  $identifier  소유 식별자 (로그용)
     * @return int 지운 행 수
     */
    private function deleteRows(iterable $rows, ExtensionOwnerType $ownerType, string $identifier): int
    {
        $names = [];
        foreach ($rows as $row) {
            $row->histories()->delete();
            $row->delete();
            $names[] = $row->name;
        }

        if ($names !== []) {
            Log::info('[ScheduleSync] 스케줄 정리', [
                'extension_type' => $ownerType->value,
                'extension_identifier' => $identifier,
                'names' => $names,
            ]);
        }

        return count($names);
    }

    /**
     * 옛 `schedule` 값(cron 식 또는 Laravel 주기 메서드명)을 cron 식과 frequency 로 바꿉니다.
     *
     * @param  string  $schedule  cron 식 또는 메서드명 (예: daily, everyFiveMinutes)
     * @return array{0: string, 1: string} [expression, frequency] — 알 수 없는 메서드면 빈 식(검증에서 걸러진다)
     */
    private function resolveSchedule(string $schedule): array
    {
        if (str_contains(trim($schedule), ' ')) {
            return [trim($schedule), ScheduleFrequency::Custom->value];
        }

        if ($schedule === 'everyThirtySeconds') {
            return ['* * * * *', ScheduleFrequency::EveryThirtySeconds->value];
        }

        $frequency = ScheduleFrequency::tryFrom($schedule)?->value ?? ScheduleFrequency::Custom->value;

        // Laravel 이 쓰는 주기 메서드 구현(ManagesFrequencies)만 빌려 cron 식을 얻는다. Scheduling\Event 를
        // 직접 만들면 EventMutex 바인딩이 스케줄러 밖(설치·업데이트 동기화)에 없어 생성 자체가 실패한다.
        $probe = new class
        {
            use ManagesFrequencies;

            /** @var string 계산 중인 cron 식 */
            public string $expression = '* * * * *';

            /** @var \DateTimeZone|string|null 시간대 (between 계열이 참조) */
            public \DateTimeZone|string|null $timezone = null;
        };

        try {
            if (! method_exists($probe, $schedule)) {
                return ['', $frequency];
            }
            $probe->{$schedule}();

            return [$probe->expression, $frequency];
        } catch (Throwable) {
            return ['', $frequency];
        }
    }

    /**
     * schedules 테이블이 7.2.0 스키마(dispatch_mode 컬럼)인지 확인합니다.
     *
     * @return bool 동기화 가능 여부
     */
    private function isSchemaReady(): bool
    {
        return Schema::hasTable('schedules') && Schema::hasColumn('schedules', 'dispatch_mode');
    }
}
