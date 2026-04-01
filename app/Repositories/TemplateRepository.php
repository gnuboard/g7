<?php

namespace App\Repositories;

use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Models\Template;
use Illuminate\Database\Eloquent\Collection;

class TemplateRepository implements TemplateRepositoryInterface
{
    /**
     * 모든 템플릿 조회
     */
    public function getAll(?string $type = null): Collection
    {
        $query = Template::query()
            ->orderBy('created_at', 'desc');

        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query->get();
    }

    /**
     * ID로 템플릿 조회
     */
    public function findById(int $id): ?Template
    {
        return Template::find($id);
    }

    /**
     * identifier로 템플릿 조회
     */
    public function findByIdentifier(string $identifier): ?Template
    {
        return Template::where('identifier', $identifier)->first();
    }

    /**
     * 타입별 활성화된 템플릿 조회
     */
    public function findActiveByType(string $type): ?Template
    {
        return Template::where('type', $type)
            ->where('status', ExtensionStatus::Active->value)
            ->first();
    }

    /**
     * 템플릿 업데이트
     */
    public function update(int $id, array $data): Template
    {
        $template = $this->findById($id);
        $template->update($data);

        return $template->fresh();
    }

    /**
     * 템플릿 삭제 (Soft Delete)
     */
    public function delete(int $id): bool
    {
        $template = $this->findById($id);

        return $template->delete();
    }

    /**
     * 특정 타입의 활성화된 모든 템플릿을 조회합니다.
     *
     * @param  string  $type  템플릿 타입 (admin, user 등)
     * @return Collection 활성화된 템플릿 컬렉션
     */
    public function getActiveByType(string $type): Collection
    {
        return Template::where('type', $type)
            ->where('status', ExtensionStatus::Active->value)
            ->get();
    }

    /**
     * 모든 활성화된 템플릿을 조회합니다.
     *
     * @return Collection 모든 활성화된 템플릿 컬렉션
     */
    public function getActive(): Collection
    {
        return Template::where('status', ExtensionStatus::Active->value)->get();
    }

    /**
     * 모든 템플릿을 identifier로 키잉하여 조회합니다.
     *
     * @return Collection identifier를 키로 하는 템플릿 컬렉션
     */
    public function getAllKeyedByIdentifier(): Collection
    {
        return Template::all()->keyBy('identifier');
    }

    /**
     * 설치된 모든 템플릿의 identifier 목록을 조회합니다.
     *
     * @return array 설치된 템플릿 identifier 배열
     */
    public function getInstalledIdentifiers(): array
    {
        return Template::pluck('identifier')->toArray();
    }

    /**
     * 템플릿을 생성하거나 업데이트합니다.
     *
     * @param  array  $attributes  조회 조건
     * @param  array  $values  생성/업데이트할 데이터
     * @return Template 생성 또는 업데이트된 템플릿 모델
     */
    public function updateOrCreate(array $attributes, array $values): Template
    {
        return Template::updateOrCreate($attributes, $values);
    }

    /**
     * 식별자로 템플릿 상태를 업데이트합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  array  $data  업데이트할 데이터
     * @return int 업데이트된 레코드 수
     */
    public function updateByIdentifier(string $identifier, array $data): int
    {
        return Template::where('identifier', $identifier)->update($data);
    }

    /**
     * 식별자로 템플릿을 삭제합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return int 삭제된 레코드 수
     */
    public function deleteByIdentifier(string $identifier): int
    {
        return Template::where('identifier', $identifier)->delete();
    }

    /**
     * 특정 모듈에 의존하는 활성 템플릿을 조회합니다.
     *
     * DB에서 활성화된 템플릿을 조회한 후, TemplateManager를 통해
     * 각 템플릿의 template.json에서 dependencies.modules를 확인하여
     * 해당 모듈에 의존하는 템플릿만 필터링합니다.
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return Collection 해당 모듈에 의존하는 활성 템플릿 컬렉션
     */
    public function findActiveByModuleDependency(string $moduleIdentifier): Collection
    {
        // 활성화된 모든 템플릿 조회
        $activeTemplates = Template::where('status', ExtensionStatus::Active->value)->get();

        if ($activeTemplates->isEmpty()) {
            return new Collection();
        }

        // TemplateManager를 통해 각 템플릿의 의존성 확인
        $templateManager = app(\App\Contracts\Extension\TemplateManagerInterface::class);

        return $activeTemplates->filter(function (Template $template) use ($templateManager, $moduleIdentifier) {
            $templateData = $templateManager->getTemplate($template->identifier);

            if (! $templateData) {
                return false;
            }

            $dependencies = $templateData['dependencies'] ?? [];
            $moduleDependencies = $dependencies['modules'] ?? [];

            // 해당 모듈이 의존성에 포함되어 있는지 확인
            return array_key_exists($moduleIdentifier, $moduleDependencies);
        })->values();
    }

    /**
     * 특정 플러그인에 의존하는 활성 템플릿을 조회합니다.
     *
     * DB에서 활성화된 템플릿을 조회한 후, TemplateManager를 통해
     * 각 템플릿의 template.json에서 dependencies.plugins를 확인하여
     * 해당 플러그인에 의존하는 템플릿만 필터링합니다.
     *
     * @param  string  $pluginIdentifier  플러그인 식별자
     * @return Collection 해당 플러그인에 의존하는 활성 템플릿 컬렉션
     */
    public function findActiveByPluginDependency(string $pluginIdentifier): Collection
    {
        // 활성화된 모든 템플릿 조회
        $activeTemplates = Template::where('status', ExtensionStatus::Active->value)->get();

        if ($activeTemplates->isEmpty()) {
            return new Collection();
        }

        // TemplateManager를 통해 각 템플릿의 의존성 확인
        $templateManager = app(\App\Contracts\Extension\TemplateManagerInterface::class);

        return $activeTemplates->filter(function (Template $template) use ($templateManager, $pluginIdentifier) {
            $templateData = $templateManager->getTemplate($template->identifier);

            if (! $templateData) {
                return false;
            }

            $dependencies = $templateData['dependencies'] ?? [];
            $pluginDependencies = $dependencies['plugins'] ?? [];

            // 해당 플러그인이 의존성에 포함되어 있는지 확인
            return array_key_exists($pluginIdentifier, $pluginDependencies);
        })->values();
    }
}
