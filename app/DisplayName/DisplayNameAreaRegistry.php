<?php

namespace App\DisplayName;

use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Services\NotificationChannelService;
use Illuminate\Support\Facades\Log;

/**
 * 표시 이름 영역 등록소.
 *
 * 코어 영역(사이트 기본, 관리자 화면, 알림과 알림 채널)에 활성 확장의 `getDisplayNameAreas()` 와
 * 필터 `core.display_name.filter_areas` 가 더한 영역을 합친다. 알림 채널 영역은 지금 쓸 수 있는 채널 목록에서 만든다(확장 채널 포함).
 *
 * 설정 저장 파일에 없는 영역은 선언 기본값으로 읽힌다. 설정은 범주 단위로 얕게 합쳐지므로 영역 묶음의 깊은 키가
 * 저장 파일에 없을 수 있기 때문이다({@see DisplayNameSettings}).
 *
 * @since 7.2.0
 */
class DisplayNameAreaRegistry
{
    /** 영역: 사이트 기본 (모든 영역의 뿌리) */
    public const AREA_SITE = 'core:site';

    /** 영역: 관리자 화면 */
    public const AREA_ADMIN = 'core:admin';

    /** 영역: 알림 */
    public const AREA_NOTIFICATION = 'core:notification';

    /**
     * 모은 영역 (null 이면 아직 모으지 않음).
     *
     * @var array<string, DisplayNameArea>|null
     */
    protected ?array $areas = null;

    /**
     * 알림 채널의 영역 키를 만듭니다.
     *
     * @param  string  $channel  채널 식별자 (예: mail, database)
     * @return string `core:notification:{채널}`
     */
    public static function notificationChannelArea(string $channel): string
    {
        return self::AREA_NOTIFICATION.':'.$channel;
    }

    /**
     * 영역을 직접 등록합니다 (테스트와 부팅 코드용).
     *
     * @param  DisplayNameArea  $area  영역
     * @return void 반환값 없음
     */
    public function register(DisplayNameArea $area): void
    {
        $this->all();
        $this->areas[$area->key] = $area;
    }

    /**
     * 영역을 반환합니다.
     *
     * @param  string  $key  영역 키
     * @return DisplayNameArea|null 영역 (없거나 등록한 확장이 꺼져 있으면 null)
     */
    public function get(string $key): ?DisplayNameArea
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * 영역이 있는지 반환합니다.
     *
     * @param  string  $key  영역 키
     * @return bool 있으면 true
     */
    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /**
     * 모든 영역을 반환합니다.
     *
     * @return array<string, DisplayNameArea> 영역 키 => 영역
     */
    public function all(): array
    {
        if ($this->areas !== null) {
            return $this->areas;
        }

        $areas = [];
        foreach ($this->coreAreas() as $area) {
            $areas[$area->key] = $area;
        }

        foreach ($this->extensionDeclarations() as [$ownerType, $identifier, $declared]) {
            foreach ($declared as $data) {
                $area = is_array($data) ? DisplayNameArea::fromArray($data, $ownerType, $identifier) : null;
                if ($area === null) {
                    Log::warning('표시 이름 영역 선언의 키 형식이 맞지 않아 건너뜁니다', ['source' => $identifier, 'key' => is_array($data) ? ($data['key'] ?? null) : null]);

                    continue;
                }
                if (isset($areas[$area->key])) {
                    Log::warning('표시 이름 영역 키 충돌 — 먼저 등록된 쪽을 유지합니다', ['key' => $area->key, 'skipped' => $identifier]);

                    continue;
                }
                $areas[$area->key] = $area;
            }
        }

        $filtered = HookManager::applyFilters('core.display_name.filter_areas', $areas);
        $this->areas = [];
        foreach (is_array($filtered) ? $filtered : $areas as $area) {
            if ($area instanceof DisplayNameArea) {
                $this->areas[$area->key] = $area;
            }
        }
        // 뿌리 영역은 필터가 빼도 남긴다 — 상속 사슬의 끝이 없으면 계산이 끝나지 않는다.
        $this->areas[self::AREA_SITE] ??= $areas[self::AREA_SITE];

        return $this->areas;
    }

    /**
     * 영역에서 뿌리(`core:site`)까지의 상속 사슬을 반환합니다 (자기 자신 포함).
     *
     * 등록되지 않은 영역 키를 주면 뿌리 영역만 돌려준다. 부모가 없거나 등록되지 않은 영역은 뿌리로 이어진다.
     *
     * @param  string  $key  영역 키
     * @return array<int, DisplayNameArea> 자기 자신부터 뿌리까지
     */
    public function lineage(string $key): array
    {
        $all = $this->all();
        $chain = [];
        $seen = [];
        $current = $all[$key] ?? null;

        while ($current !== null && ! isset($seen[$current->key])) {
            $seen[$current->key] = true;
            $chain[] = $current;
            if ($current->key === self::AREA_SITE) {
                return $chain;
            }
            $current = $current->parent !== null ? ($all[$current->parent] ?? null) : null;
        }

        $chain[] = $all[self::AREA_SITE];

        return $chain;
    }

    /**
     * 영역을 부모 아래에 자식이 오는 순서로 펼쳐 반환합니다 (운영자 화면의 표).
     *
     * @return array<int, array{area: DisplayNameArea, depth: int}> 표시 순서의 영역과 깊이
     */
    public function tree(): array
    {
        $all = $this->all();
        $children = [];
        foreach ($all as $area) {
            if ($area->key === self::AREA_SITE) {
                continue;
            }
            $parent = $area->parent !== null && isset($all[$area->parent]) ? $area->parent : self::AREA_SITE;
            $children[$parent][] = $area;
        }

        $rows = [];
        $walk = function (DisplayNameArea $area, int $depth) use (&$walk, &$rows, &$children): void {
            $rows[] = ['area' => $area, 'depth' => $depth];
            $kids = $children[$area->key] ?? [];
            unset($children[$area->key]);
            usort($kids, fn (DisplayNameArea $a, DisplayNameArea $b) => $a->sortOrder <=> $b->sortOrder);
            foreach ($kids as $kid) {
                $walk($kid, $depth + 1);
            }
        };
        $walk($all[self::AREA_SITE], 0);

        return $rows;
    }

    /**
     * 모은 결과를 버립니다 (확장 활성 상태가 바뀐 뒤와 테스트용).
     *
     * @return void 반환값 없음
     */
    public function flush(): void
    {
        $this->areas = null;
    }

    /**
     * 코어 영역을 만듭니다.
     *
     * @return array<int, DisplayNameArea> 코어 영역 목록
     */
    protected function coreAreas(): array
    {
        $areas = [
            // 사이트 기본은 처음에 「회원이 고른 값」이다. 회원 기본 종류를 바꾸면 사이트 전체가 따라 바뀌고, 표시 이름을
            // 고르는 역할 권한이 없는 동안은 모든 회원이 회원 기본 종류로 보인다.
            new DisplayNameArea(
                key: self::AREA_SITE,
                label: 'display_name.areas.site',
                parent: null,
                audience: DisplayNameArea::AUDIENCE_USER,
                defaultMode: DisplayNameArea::MODE_MEMBER,
                sortOrder: 0,
            ),
            new DisplayNameArea(
                key: self::AREA_ADMIN,
                label: 'display_name.areas.admin',
                parent: self::AREA_SITE,
                audience: DisplayNameArea::AUDIENCE_ADMIN,
                defaultMode: DisplayNameArea::MODE_FIXED,
                defaultKind: 'name',
                sortOrder: 10,
            ),
            new DisplayNameArea(
                key: self::AREA_NOTIFICATION,
                label: 'display_name.areas.notification',
                parent: self::AREA_SITE,
                audience: DisplayNameArea::AUDIENCE_NOTIFICATION,
                sortOrder: 20,
            ),
        ];

        $order = 0;
        foreach ($this->notificationChannels() as $id => $name) {
            $key = self::notificationChannelArea($id);
            if (preg_match(DisplayNameArea::KEY_PATTERN, $key) !== 1) {
                continue;
            }
            $areas[] = new DisplayNameArea(
                key: $key,
                // 채널 이름은 이미 요청 로케일로 풀려 있다 — 한 로케일짜리 배열로 실어 그대로 보인다.
                label: [app()->getLocale() => $name],
                parent: self::AREA_NOTIFICATION,
                audience: DisplayNameArea::AUDIENCE_NOTIFICATION,
                sortOrder: $order++,
            );
        }

        return $areas;
    }

    /**
     * 지금 쓸 수 있는 알림 채널을 읽습니다.
     *
     * @return array<string, string> 채널 식별자 => 채널 이름
     */
    protected function notificationChannels(): array
    {
        try {
            $channels = [];
            foreach (app(NotificationChannelService::class)->getAvailableChannels() as $channel) {
                $id = is_array($channel) ? ($channel['id'] ?? null) : null;
                if (is_string($id) && $id !== '') {
                    $channels[$id] = is_string($channel['name'] ?? null) && $channel['name'] !== '' ? $channel['name'] : $id;
                }
            }

            return $channels;
        } catch (\Throwable $e) {
            Log::debug('표시 이름 영역: 알림 채널 목록을 읽지 못했습니다', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * 활성 확장의 영역 선언을 모읍니다.
     *
     * @return array<int, array{0: string, 1: string, 2: array<int, mixed>}> [종류, 식별자, 선언 목록]
     */
    protected function extensionDeclarations(): array
    {
        $declarations = [];
        try {
            foreach (app(ModuleManager::class)->getActiveModules() as $module) {
                if (method_exists($module, 'getDisplayNameAreas')) {
                    $declarations[] = ['module', $module->getIdentifier(), (array) $module->getDisplayNameAreas()];
                }
            }
            foreach (app(PluginManager::class)->getActivePlugins() as $plugin) {
                if (method_exists($plugin, 'getDisplayNameAreas')) {
                    $declarations[] = ['plugin', $plugin->getIdentifier(), (array) $plugin->getDisplayNameAreas()];
                }
            }
        } catch (\Throwable $e) {
            // 설치 전, 마이그레이션 전 구간에서는 확장 목록을 읽지 못할 수 있다. 코어 영역만으로 동작한다.
            Log::debug('표시 이름 영역: 확장 선언을 읽지 못했습니다', ['error' => $e->getMessage()]);
        }

        return $declarations;
    }
}
