<?php

namespace App\Services\Schedule;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\ScheduleFrequency;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\Schedule;
use App\Support\LocalizedValue;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Throwable;

/**
 * 스케줄 조회 서비스.
 *
 * 코어·확장이 자기 스케줄 행을 찾거나(예: koreaexim 의 다음 갱신 시각), 화면이 소유 필터 옵션을 만들 때 쓴다.
 *
 * @since 7.2.0
 */
class ScheduleLookupService
{
    /** 직접 등록 행의 소유 type 값 (목록 쿼리 `extension_type=custom` 은 IS NULL 로 해석) */
    public const CUSTOM_OWNER_TYPE = 'custom';

    /**
     * @param  ScheduleRepositoryInterface  $repository  스케줄 저장소
     * @param  ModuleManager  $moduleManager  모듈 매니저 (설치 행이 없을 때 매니페스트 이름)
     * @param  PluginManager  $pluginManager  플러그인 매니저 (설치 행이 없을 때 매니페스트 이름)
     * @param  ModuleRepositoryInterface  $moduleRepository  설치 모듈 행 (언어팩이 넣은 다국어 이름)
     * @param  PluginRepositoryInterface  $pluginRepository  설치 플러그인 행 (언어팩이 넣은 다국어 이름)
     */
    public function __construct(
        private readonly ScheduleRepositoryInterface $repository,
        private readonly ModuleManager $moduleManager,
        private readonly PluginManager $pluginManager,
        private readonly ModuleRepositoryInterface $moduleRepository,
        private readonly PluginRepositoryInterface $pluginRepository,
    ) {}

    /**
     * 코어·확장 소유 스케줄 1건을 찾습니다.
     *
     * @param  string  $extensionType  소유 타입 (core/module/plugin)
     * @param  string  $identifier  소유 식별자 (코어는 'core')
     * @param  string  $name  스케줄 기계 식별자
     * @return Schedule|null 찾은 스케줄, 소유 타입이 잘못됐거나 없으면 null
     */
    public function findOwned(string $extensionType, string $identifier, string $name): ?Schedule
    {
        $type = ExtensionOwnerType::tryFrom($extensionType);
        if ($type === null) {
            return null;
        }

        return $this->repository->findOwned($type, $identifier, $name);
    }

    /**
     * 행의 일정(주기·cron·시간대) 기준 다음 실행 시각을 계산합니다.
     *
     * 활성 여부·실행 조건은 보지 않는다 — 순수하게 "일정상 다음 시각" 이다.
     * 30초 주기는 다음 :00/:30 초 경계, 그 외는 expression 을 행의 시간대(없으면 app.schedule_timezone)로 해석한다.
     *
     * @param  Schedule  $schedule  스케줄
     * @param  CarbonInterface|null  $from  기준 시각 (null 이면 지금)
     * @return CarbonInterface|null 다음 실행 시각(저장 타임존 app.timezone), 식이 잘못됐으면 null
     */
    public function nextRunAt(Schedule $schedule, ?CarbonInterface $from = null): ?CarbonInterface
    {
        $storeTimezone = (string) config('app.timezone', 'UTC');
        $base = CarbonImmutable::instance($from ?? now())->setTimezone($storeTimezone);

        if ($schedule->frequency === ScheduleFrequency::EveryThirtySeconds) {
            $next = $base->startOfSecond()->addSecond();
            $remainder = $next->second % 30;

            return $remainder === 0 ? $next : $next->addSeconds(30 - $remainder);
        }

        $expression = (string) $schedule->expression;
        if ($expression === '' || ! CronExpression::isValidExpression($expression)) {
            return null;
        }

        $timezone = $schedule->timezone ?: (string) config('app.schedule_timezone', $storeTimezone);

        try {
            $next = (new CronExpression($expression))->getNextRunDate($base->toDateTimeImmutable(), 0, false, $timezone);
        } catch (Throwable) {
            return null;
        }

        return CarbonImmutable::instance($next)->setTimezone($storeTimezone);
    }

    /**
     * 소유자별 스케줄 요약을 반환합니다 (소유 필터 옵션·owners API 응답).
     *
     * 순서: 코어 → 모듈 → 플러그인(각각 이름순) → 직접 등록. 스케줄이 0건인 소유자는 넣지 않는다.
     *
     * @param  string|null  $locale  이름 해석 로케일 (null 이면 현재 로케일)
     * @return array<int, array{type: string, identifier: string|null, name: string, schedule_count: int}> 요약 목록
     */
    public function ownersSummary(?string $locale = null): array
    {
        $locale = $locale ?? app()->getLocale();
        $buckets = ['core' => [], 'module' => [], 'plugin' => [], self::CUSTOM_OWNER_TYPE => []];

        foreach ($this->repository->countByOwner() as $row) {
            $type = $row->extension_type === null ? self::CUSTOM_OWNER_TYPE : (string) $row->extension_type;
            if (! array_key_exists($type, $buckets)) {
                continue;
            }

            $identifier = $type === self::CUSTOM_OWNER_TYPE ? null : (string) $row->extension_identifier;
            $buckets[$type][] = [
                'type' => $type,
                'identifier' => $identifier,
                'name' => $this->ownerName($type, $identifier, $locale),
                'schedule_count' => (int) $row->schedule_count,
            ];
        }

        foreach (['module', 'plugin'] as $type) {
            usort($buckets[$type], fn (array $a, array $b) => strcmp($a['name'], $b['name']));
        }

        return array_merge(...array_values($buckets));
    }

    /**
     * 소유자 표시 이름을 해석합니다.
     *
     * 확장 이름은 모듈·플러그인 관리 목록(getInstalled*WithDetails)과 같은 순서로 고른다 — 설치 행의 다국어
     * name(언어팩 켜기가 ja 등을 넣는다) → 매니페스트 getName()(ko/en) → 식별자. 매니페스트만 보면 ja 에서
     * 확장 이름이 한국어로 떨어진다(M1 실측 D9).
     *
     * @param  string  $type  소유 type (core/module/plugin/custom)
     * @param  string|null  $identifier  소유 식별자
     * @param  string  $locale  로케일
     * @return string 표시 이름 (확장을 못 찾으면 식별자)
     */
    public function ownerName(string $type, ?string $identifier, ?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        return match ($type) {
            'core' => (string) __('extension_owner_type.core', [], $locale),
            self::CUSTOM_OWNER_TYPE => (string) __('schedule.owner.custom', [], $locale),
            'module' => $this->localizeName(
                $this->moduleRepository->findByIdentifier((string) $identifier)?->name
                    ?: $this->moduleManager->getModule((string) $identifier)?->getName(),
                $identifier,
                $locale,
            ),
            'plugin' => $this->localizeName(
                $this->pluginRepository->findByIdentifier((string) $identifier)?->name
                    ?: $this->pluginManager->getPlugin((string) $identifier)?->getName(),
                $identifier,
                $locale,
            ),
            default => (string) $identifier,
        };
    }

    /**
     * 확장 이름(문자열 또는 {로케일: 이름})을 로케일로 해석합니다.
     *
     * @param  string|array<string, string>|null  $name  확장 getName() 결과
     * @param  string|null  $identifier  폴백 식별자
     * @param  string  $locale  로케일
     * @return string 해석된 이름
     */
    private function localizeName(string|array|null $name, ?string $identifier, string $locale): string
    {
        if (is_string($name) && $name !== '') {
            return $name;
        }

        if (is_array($name)) {
            // 다국어 값 해석은 저장소 단일 기준(LocalizedValue) — 어느 로케일에도 없으면 식별자
            $picked = LocalizedValue::pick($name, $locale, false);
            if ($picked !== null && $picked !== '') {
                return $picked;
            }
        }

        return (string) $identifier;
    }
}
