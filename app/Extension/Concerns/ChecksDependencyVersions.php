<?php

namespace App\Extension\Concerns;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\Helpers\DependencyEnricher;
use App\Support\ExtensionDependencies;

/**
 * 모듈·플러그인 의존성 충족 판정 — 활성 여부 + 버전 제약 (템플릿 의존성 검사와 같은 기준).
 *
 * 종전에는 「활성인지」만 봐서 지원하지 않는 옛 의존 버전 위에 설치·활성화됐고, 설치 미리보기
 * (DependencyEnricher)는 같은 상황을 「미충족」으로 보여 두 판정이 엇갈렸다. 버전 판정식은
 * DependencyEnricher::checkVersionConstraint(Semver) 하나를 쓴다.
 */
trait ChecksDependencyVersions
{
    /**
     * 의존 선언을 (식별자, 종류, 버전 제약) 으로 펼친다.
     *
     * @param  array<string, mixed>  $dependencies  ['modules' => [id => 제약], 'plugins' => [id => 제약]]
     * @return \Generator<int, array{identifier: string, type: string, constraint: string}> 펼친 의존 항목
     */
    protected function iterateDependencyConstraints(array $dependencies): \Generator
    {
        // 목록형(['a', 'b'])·빈 제약은 정규화기가 '*' 로 맞춘다 — 의존을 읽는 곳은 모두 이 정규화기를 거친다
        $constraints = ExtensionDependencies::constraints($dependencies);

        foreach (['modules' => 'module', 'plugins' => 'plugin'] as $key => $type) {
            foreach ($constraints[$key] as $identifier => $constraint) {
                yield ['identifier' => (string) $identifier, 'type' => $type, 'constraint' => $constraint];
            }
        }
    }

    /**
     * 충족되지 않은 의존 확장 목록 — 미설치·비활성·버전 미달.
     *
     * @param  array<string, mixed>  $dependencies  의존 선언
     * @return array{modules: array<int, array<string, mixed>>, plugins: array<int, array<string, mixed>>} 종류별 미충족 항목
     */
    protected function collectUnmetDependencies(array $dependencies): array
    {
        $unmet = ['modules' => [], 'plugins' => []];
        $repositories = [
            'module' => app(ModuleRepositoryInterface::class),
            'plugin' => app(PluginRepositoryInterface::class),
        ];

        foreach ($this->iterateDependencyConstraints($dependencies) as $dep) {
            $record = $repositories[$dep['type']]->findByIdentifier($dep['identifier']);
            $bucket = $dep['type'] === 'module' ? 'modules' : 'plugins';

            if (! $record) {
                $unmet[$bucket][] = ['identifier' => $dep['identifier'], 'name' => $dep['identifier'], 'status' => 'not_installed', 'required_version' => $dep['constraint'], 'installed_version' => null];

                continue;
            }

            if ($record->status !== ExtensionStatus::Active->value) {
                $unmet[$bucket][] = ['identifier' => $record->identifier, 'name' => $record->getLocalizedName(), 'status' => 'inactive', 'required_version' => $dep['constraint'], 'installed_version' => $record->version];

                continue;
            }

            if ($dep['constraint'] !== '*' && ! DependencyEnricher::checkVersionConstraint((string) $record->version, $dep['constraint'])) {
                $unmet[$bucket][] = ['identifier' => $record->identifier, 'name' => $record->getLocalizedName(), 'status' => 'version_mismatch', 'required_version' => $dep['constraint'], 'installed_version' => $record->version];
            }
        }

        return $unmet;
    }

    /**
     * 설치 시 의존성 미충족 사유 문구 (이 확장 종류의 번역 키 접두어로).
     *
     * @param  array<string, mixed>  $dependencies  의존 선언
     * @param  string  $langPrefix  번역 파일 (modules|plugins)
     * @return array<int, string> 사유 문구
     */
    protected function unmetDependencyMessages(array $dependencies, string $langPrefix): array
    {
        $messages = [];
        $unmet = $this->collectUnmetDependencies($dependencies);

        foreach ([...$unmet['modules'], ...$unmet['plugins']] as $item) {
            $messages[] = $item['status'] === 'version_mismatch'
                ? __($langPrefix.'.dependency_version_unmet', ['dependency' => $item['identifier'], 'required' => $item['required_version'], 'installed' => $item['installed_version']])
                : __($langPrefix.'.dependency_not_active', ['dependency' => $item['identifier']]);
        }

        return $messages;
    }
}
