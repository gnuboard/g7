<?php

namespace App\Extension\Support;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\Helpers\DependencyEnricher;
use App\Support\ExtensionIntegrations;
use Illuminate\Support\Collection;

/**
 * 선택 연동 역맵 — 「이 확장을 연동으로 선언한 켜진 확장」을 요청(또는 명령·큐 작업) 단위로 한 번만 만든다 (#822, 7.1.4).
 *
 * 관리자 확장 목록은 행마다 `integrated_by` 를 싣는다. 행마다 저장소 3종을 조회하면 활성 확장 전체 조회와 manifest
 * 재스캔이 행 수만큼 되풀이된다(20행 ≈ 60쿼리). 첫 조회 때 켜진 모듈·플러그인·템플릿을 한 번씩만 읽어
 * `{type}:{identifier} => [선언한 확장…]` 맵을 만들고 이후 조회는 맵에서 돌려준다.
 *
 * 컨테이너에 scoped 로 바인딩되어 요청·큐 작업이 끝나면 버려진다(정적 상태 없음 — 큐 워커 누수 없음). 같은 요청 안에서
 * 확장 상태가 바뀌면 상태 캐시 무효화(`invalidate{Module,Plugin,Template}StatusCache()`)가 {@see flushCurrent()} 로 맵을 비운다.
 */
class IntegrationReverseIndex
{
    /**
     * 역맵 (null 이면 아직 만들지 않음)
     *
     * @var array<string, list<array{type: string, identifier: string, name: string}>>|null
     */
    private ?array $map = null;

    /**
     * 지정한 확장을 연동으로 선언한 켜진 확장 목록을 반환합니다 (자기 자신 제외).
     *
     * @param  string  $type  대상 확장 유형 (module|plugin|template)
     * @param  string  $identifier  대상 확장 식별자
     * @return list<array{type: string, identifier: string, name: string}> 연동 중인 켜진 확장
     */
    public function integratedBy(string $type, string $identifier): array
    {
        $this->map ??= $this->build();

        return array_values(array_filter(
            $this->map[$type.':'.$identifier] ?? [],
            static fn (array $owner): bool => ! ($owner['type'] === $type && $owner['identifier'] === $identifier),
        ));
    }

    /**
     * 맵을 비웁니다 — 다음 조회 때 다시 만든다.
     *
     * @return void 반환값 없음
     */
    public function flush(): void
    {
        $this->map = null;
    }

    /**
     * 이 요청에서 이미 만든 역맵이 있으면 비웁니다 (확장 상태가 바뀐 뒤 부른다).
     *
     * 아직 만들지 않았으면 인스턴스를 새로 만들지 않는다 — 상태 캐시 무효화는 부팅·설치기에서도 불린다.
     *
     * @return void 반환값 없음
     */
    public static function flushCurrent(): void
    {
        if (! function_exists('app')) {
            return;
        }

        $app = app();
        if ($app->resolved(self::class)) {
            $app->make(self::class)->flush();
        }
    }

    /**
     * 켜진 확장만 남깁니다 — 유형별 조회 한 번으로 거른다(`ModuleRepository::getActive()` 는 옛 `is_active` 칸을 본다).
     *
     * @param  Collection  $records  확장 모델 컬렉션
     * @return Collection 켜진 확장 컬렉션
     */
    private function active(Collection $records): Collection
    {
        return $records->filter(static function (object $record): bool {
            $status = $record->status ?? null;

            return ($status instanceof \BackedEnum ? $status->value : $status) === ExtensionStatus::Active->value;
        })->values();
    }

    /**
     * 켜진 모듈·플러그인·템플릿을 한 번씩 읽어 역맵을 만듭니다.
     *
     * @return array<string, list<array{type: string, identifier: string, name: string}>> 역맵
     */
    private function build(): array
    {
        $map = [];

        $add = function (string $ownerType, object $record, array $integrations) use (&$map): void {
            $owner = [
                'type' => $ownerType,
                'identifier' => (string) $record->identifier,
                'name' => DependencyEnricher::displayName($record, (string) $record->identifier),
            ];

            foreach (ExtensionIntegrations::normalize($integrations) as $pluralType => $ids) {
                foreach ($ids as $id) {
                    $map[rtrim($pluralType, 's').':'.$id][] = $owner;
                }
            }
        };

        $modules = $this->active(app(ModuleRepositoryInterface::class)->getAll());
        if ($modules->isNotEmpty()) {
            $manager = app(ModuleManagerInterface::class);
            foreach ($modules as $record) {
                $instance = $manager->getModule($record->identifier);
                if ($instance !== null) {
                    $add('module', $record, $instance->getIntegrations());
                }
            }
        }

        $plugins = $this->active(app(PluginRepositoryInterface::class)->getAll());
        if ($plugins->isNotEmpty()) {
            $manager = app(PluginManagerInterface::class);
            foreach ($plugins as $record) {
                $instance = $manager->getPlugin($record->identifier);
                if ($instance !== null) {
                    $add('plugin', $record, $instance->getIntegrations());
                }
            }
        }

        $templates = $this->active(app(TemplateRepositoryInterface::class)->getAll());
        if ($templates->isNotEmpty()) {
            $manager = app(TemplateManagerInterface::class);
            // 콘솔 부팅은 템플릿 로딩을 건너뛴다 — 의존 역조회와 같은 이유로 먼저 불러온다(#145 F4)
            $manager->ensureLoaded();
            foreach ($templates as $record) {
                $data = $manager->getTemplate($record->identifier);
                if (is_array($data)) {
                    $add('template', $record, $data['integrations'] ?? []);
                }
            }
        }

        return $map;
    }
}
