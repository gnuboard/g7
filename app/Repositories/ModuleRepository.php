<?php

namespace App\Repositories;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Models\Module;
use Illuminate\Database\Eloquent\Collection;

class ModuleRepository implements ModuleRepositoryInterface
{
    /**
     * 모든 모듈을 조회합니다.
     *
     * @return Collection 모듈 컬렉션
     */
    public function getAll(): Collection
    {
        return Module::all();
    }

    /**
     * 모든 모듈을 identifier로 키잉하여 조회합니다.
     *
     * @return Collection identifier를 키로 하는 모듈 컬렉션
     */
    public function getAllKeyedByIdentifier(): Collection
    {
        return Module::all()->keyBy('identifier');
    }

    /**
     * 설치된 모든 모듈의 identifier 목록을 조회합니다.
     *
     * @return array 설치된 모듈 identifier 배열
     */
    public function getInstalledIdentifiers(): array
    {
        return Module::pluck('identifier')->toArray();
    }

    /**
     * 이름으로 모듈을 찾습니다.
     *
     * @param  string  $name  찾을 모듈 이름
     * @return Module|null 찾은 모듈 모델 또는 null
     */
    public function findByName(string $name): ?Module
    {
        return Module::where('name', $name)->first();
    }

    /**
     * 활성화된 모듈들을 조회합니다.
     *
     * @return Collection 활성화된 모듈 컬렉션
     */
    public function getActive(): Collection
    {
        return Module::where('is_active', true)->get();
    }

    /**
     * 새로운 모듈을 생성합니다.
     *
     * @param  array  $data  모듈 생성 데이터
     * @return Module 생성된 모듈 모델
     */
    public function create(array $data): Module
    {
        return Module::create($data);
    }

    /**
     * 모듈을 생성하거나 업데이트합니다.
     *
     * @param  array  $attributes  조회 조건
     * @param  array  $values  생성/업데이트할 데이터
     * @return Module 생성 또는 업데이트된 모듈 모델
     */
    public function updateOrCreate(array $attributes, array $values): Module
    {
        return Module::updateOrCreate($attributes, $values);
    }

    /**
     * 기존 모듈을 업데이트합니다.
     *
     * @param  Module  $module  업데이트할 모듈 모델
     * @param  array  $data  업데이트할 데이터
     * @return bool 업데이트 성공 여부
     */
    public function update(Module $module, array $data): bool
    {
        return $module->update($data);
    }

    /**
     * 식별자로 모듈 상태를 업데이트합니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @param  array  $data  업데이트할 데이터
     * @return int 업데이트된 레코드 수
     */
    public function updateByIdentifier(string $identifier, array $data): int
    {
        return Module::where('identifier', $identifier)->update($data);
    }

    /**
     * 모듈을 삭제합니다.
     *
     * @param  Module  $module  삭제할 모듈 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(Module $module): bool
    {
        return $module->delete();
    }

    /**
     * 식별자로 모듈을 삭제합니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @return int 삭제된 레코드 수
     */
    public function deleteByIdentifier(string $identifier): int
    {
        return Module::where('identifier', $identifier)->delete();
    }

    /**
     * 모듈을 활성화합니다.
     *
     * @param  Module  $module  활성화할 모듈 모델
     * @return bool 활성화 성공 여부
     */
    public function activate(Module $module): bool
    {
        return $module->update(['is_active' => true]);
    }

    /**
     * 모듈을 비활성화합니다.
     *
     * @param  Module  $module  비활성화할 모듈 모델
     * @return bool 비활성화 성공 여부
     */
    public function deactivate(Module $module): bool
    {
        return $module->update(['is_active' => false]);
    }

    /**
     * 모듈의 설치 상태를 업데이트합니다.
     *
     * @param  Module  $module  대상 모듈 모델
     * @param  bool  $installed  설치 상태 (기본값: true)
     * @return bool 업데이트 성공 여부
     */
    public function setInstalled(Module $module, bool $installed = true): bool
    {
        return $module->update(['is_installed' => $installed]);
    }

    /**
     * 설치된 모듈들을 조회합니다.
     *
     * @return Collection 설치된 모듈 컬렉션
     */
    public function getInstalled(): Collection
    {
        return Module::where('is_installed', true)->get();
    }

    /**
     * 마켓플레이스용 모듈들을 조회합니다.
     *
     * @return Collection 공개된 모듈 컬렉션
     */
    public function getForMarketplace(): Collection
    {
        return Module::where('is_published', true)->get();
    }

    /**
     * 의존성 정보가 포함된 모든 모듈을 조회합니다.
     *
     * @return Collection 의존성 정보를 포함한 모듈 컬렉션
     */
    public function getAllWithDependencies(): Collection
    {
        return Module::with(['dependencies', 'dependents'])->get();
    }

    /**
     * 슬러그로 모듈을 찾습니다.
     *
     * @param  string  $slug  모듈 슬러그
     * @return Module|null 찾은 모듈 모델 또는 null
     */
    public function findBySlug(string $slug): ?Module
    {
        return Module::where('slug', $slug)->first();
    }

    /**
     * 식별자로 모듈을 찾습니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @return Module|null 찾은 모듈 모델 또는 null
     */
    public function findByIdentifier(string $identifier): ?Module
    {
        return Module::where('identifier', $identifier)->first();
    }

    /**
     * 활성화된 모듈들의 ID 목록을 반환합니다.
     *
     * @return array 활성화된 모듈 ID 배열
     */
    public function getActiveModuleIds(): array
    {
        return Module::where('status', ExtensionStatus::Active)
            ->pluck('id')
            ->toArray();
    }

    /**
     * 활성화된 모듈들의 identifier 목록을 반환합니다.
     *
     * @return array 활성화된 모듈 identifier 배열
     */
    public function getActiveModuleIdentifiers(): array
    {
        return Module::where('status', ExtensionStatus::Active)
            ->pluck('identifier')
            ->toArray();
    }

    /**
     * 식별자로 활성화된 모듈을 찾습니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @return Module|null 찾은 모듈 모델 또는 null
     */
    public function findActiveByIdentifier(string $identifier): ?Module
    {
        return Module::where('identifier', $identifier)
            ->where('status', ExtensionStatus::Active->value)
            ->first();
    }

    /**
     * 특정 모듈에 의존하는 활성 모듈을 조회합니다.
     *
     * ModuleManager를 통해 각 모듈의 getDependencies()를 확인하여
     * 해당 모듈에 의존하는 모듈만 필터링합니다.
     *
     * @param  string  $moduleIdentifier  의존 대상 모듈 식별자
     * @return Collection 해당 모듈에 의존하는 활성 모듈 컬렉션
     */
    public function findActiveByModuleDependency(string $moduleIdentifier): Collection
    {
        // 활성화된 모든 모듈 조회
        $activeModules = Module::where('status', ExtensionStatus::Active->value)->get();

        if ($activeModules->isEmpty()) {
            return new Collection();
        }

        // ModuleManager를 통해 각 모듈의 의존성 확인
        $moduleManager = app(\App\Contracts\Extension\ModuleManagerInterface::class);

        return $activeModules->filter(function (Module $module) use ($moduleManager, $moduleIdentifier) {
            $moduleInstance = $moduleManager->getModule($module->identifier);

            if (! $moduleInstance) {
                return false;
            }

            $dependencies = $moduleInstance->getDependencies();

            // 해당 모듈이 의존성에 포함되어 있는지 확인
            return in_array($moduleIdentifier, $dependencies);
        })->values();
    }

    /**
     * 특정 플러그인에 의존하는 활성 모듈을 조회합니다.
     *
     * ModuleManager를 통해 각 모듈의 getPluginDependencies()를 확인하여
     * 해당 플러그인에 의존하는 모듈만 필터링합니다.
     *
     * @param  string  $pluginIdentifier  의존 대상 플러그인 식별자
     * @return Collection 해당 플러그인에 의존하는 활성 모듈 컬렉션
     */
    public function findActiveByPluginDependency(string $pluginIdentifier): Collection
    {
        // 활성화된 모든 모듈 조회
        $activeModules = Module::where('status', ExtensionStatus::Active->value)->get();

        if ($activeModules->isEmpty()) {
            return new Collection();
        }

        // ModuleManager를 통해 각 모듈의 플러그인 의존성 확인
        $moduleManager = app(\App\Contracts\Extension\ModuleManagerInterface::class);

        return $activeModules->filter(function (Module $module) use ($moduleManager, $pluginIdentifier) {
            $moduleInstance = $moduleManager->getModule($module->identifier);

            if (! $moduleInstance) {
                return false;
            }

            // getPluginDependencies 메서드가 있는지 확인
            if (! method_exists($moduleInstance, 'getPluginDependencies')) {
                return false;
            }

            $pluginDependencies = $moduleInstance->getPluginDependencies();

            // 해당 플러그인이 의존성에 포함되어 있는지 확인
            return in_array($pluginIdentifier, $pluginDependencies);
        })->values();
    }
}
