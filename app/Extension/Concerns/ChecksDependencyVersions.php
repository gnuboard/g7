<?php

namespace App\Extension\Concerns;

use App\Extension\Helpers\DependencyEnricher;

/**
 * 모듈·플러그인·템플릿 의존성 충족 판정 — 활성 여부 + 버전 제약.
 *
 * 종전에는 「활성인지」만 봐서 지원하지 않는 옛 의존 버전 위에 설치·활성화됐고, 설치 미리보기
 * (DependencyEnricher)는 같은 상황을 「미충족」으로 보여 두 판정이 엇갈렸다. 판정식은
 * DependencyEnricher 하나를 쓰고 이 trait 는 그 위의 얇은 위임이다(템플릿 활성화도 이 trait 를 쓴다, #152).
 */
trait ChecksDependencyVersions
{
    /**
     * 의존 선언을 (식별자, 종류, 버전 제약) 으로 펼친다 — 판정식 원천({@see DependencyEnricher::iterate()})에 위임.
     *
     * @param  array<string, mixed>  $dependencies  ['modules' => [id => 제약], 'plugins' => [id => 제약]]
     * @return \Generator<int, array{identifier: string, type: string, constraint: string}> 펼친 의존 항목
     */
    protected function iterateDependencyConstraints(array $dependencies): \Generator
    {
        yield from DependencyEnricher::iterate($dependencies);
    }

    /**
     * 충족되지 않은 의존 확장 목록 — 미설치·비활성·버전 미달.
     *
     * 판정은 {@see DependencyEnricher::enrich()} 가 한다(템플릿 설치·활성화·런타임 503 과 같은 판정식). 업데이트 중인
     * 확장은 활성으로 보지 않는다 — 업데이트 중인 확장에 기대는 새 설치·활성화는 허용하지 않는다(상태 가드와 같은 원칙).
     *
     * @param  array<string, mixed>  $dependencies  의존 선언
     * @return array{modules: array<int, array{identifier: string, name: string, type: string, status: string, required_version: string, installed_version: string|null}>, plugins: array<int, array<string, mixed>>} 종류별 미충족 항목
     */
    protected function collectUnmetDependencies(array $dependencies): array
    {
        $unmet = ['modules' => [], 'plugins' => []];

        foreach (DependencyEnricher::enrich($dependencies) as $item) {
            if ($item['status'] === DependencyEnricher::STATUS_MET) {
                continue;
            }

            $unmet[$item['type'] === 'module' ? 'modules' : 'plugins'][] = [
                'identifier' => $item['identifier'],
                'name' => $item['name'],
                'type' => $item['type'],
                'status' => $item['status'],
                'required_version' => $item['required_version'],
                'installed_version' => $item['installed_version'],
            ];
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
