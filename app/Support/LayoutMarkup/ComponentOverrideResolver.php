<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\Log;

/**
 * 템플릿의 컴포넌트 오버라이드 선언을 확장 컴포넌트 집합에 적용해 최종 정의 목록을 만든다.
 *
 * 레이아웃 오버라이드(`LayoutExtensionService::applyExtensions`)의 판정 규약을 컴포넌트 축에
 * 그대로 계승한다:
 *  - **승패는 집합 필터링이지 priority 가 아니다**(`:2111-2123` vs `:713`). 호환 오버라이드가
 *    있으면 원본을 집합에서 **제거**하고 대체본을 넣는다. priority 는 살아남은 것들의 적용
 *    순서용이라 이 판정에 관여하지 않는다 — 두 축을 섞지 않는다.
 *  - **비호환이면 원본 폴백 + `Log::info`**(`:2089-2101`). 대체가 조용히 사라지는 대신 원본이
 *    자리를 지키고, 왜 대체가 안 붙었는지 로그에 남는다.
 *  - **대상 확장이 없으면 선언은 사문**이다 — 오류가 아니라 무시(`Log::info`). 그 확장을 나중에
 *    설치할 수 있고, 템플릿이 아직 없는 확장을 위해 미리 대체본을 두는 것도 정상이다.
 *
 * 다만 **대상 확장이 활성인데 그 이름이 없으면 오류**다. 버전 제약이 호환이라고 말하는데
 * 이름이 없다는 것은 오타이거나 제약이 틀렸다는 뜻이고, 둘 다 작성자가 고쳐야 한다.
 */
final class ComponentOverrideResolver
{
    /**
     * 템플릿 + 확장 매니페스트를 병합해 최종 컴포넌트 정의 목록을 만듭니다.
     *
     * @param  ComponentManifest  $template  대상 템플릿의 매니페스트
     * @param  array<int, ComponentManifest>  $extensions  활성 확장의 매니페스트 목록
     * @return array<int, ComponentDefinition> 오버라이드가 적용된 정의 목록
     *
     * @throws CompileError 오버라이드가 활성 확장의 없는 컴포넌트를 지목한 경우
     */
    public function resolve(ComponentManifest $template, array $extensions): array
    {
        /** @var array<string, ComponentManifest> $byOwner */
        $byOwner = [];

        foreach ($extensions as $extension) {
            $byOwner[$extension->ownerId] = $extension;
        }

        /** @var array<string, ComponentDefinition> $pool 노드 name → 정의 */
        $pool = [];

        // 수집과 대체는 다른 일이다. 수집은 중복을 거부하고(같은 이름이 둘이면 어느 쪽을
        // 뜻하는지 알 수 없다), 대체는 아래 오버라이드 단계에서만 — 명시 선언이 있을 때만 —
        // 일어난다. 수집 단계에서 덮어쓰기를 허용하면 나중 것이 조용히 이기는 런타임의
        // 침묵 승자(ComponentRegistry.ts:456)를 컴파일러가 그대로 재현하게 된다.
        $this->collect($template, $pool);

        foreach ($extensions as $extension) {
            $this->collect($extension, $pool);
        }

        foreach ($template->overrides as $override) {
            $this->apply($override, $template, $byOwner, $pool);
        }

        return array_values($pool);
    }

    /**
     * 매니페스트 하나의 정의를 풀에 수집합니다 (중복 이름은 거부).
     *
     * @param  ComponentManifest  $manifest  매니페스트
     * @param  array<string, ComponentDefinition>  $pool  정의 풀 (참조로 수정)
     *
     * @throws CompileError 같은 이름의 컴포넌트가 이미 풀에 있는 경우
     */
    private function collect(ComponentManifest $manifest, array &$pool): void
    {
        foreach ($manifest->definitions as $definition) {
            if (isset($pool[$definition->name])) {
                throw CompileError::inFile(LayoutMarkupErrorCode::AmbiguousComponentTag, $manifest->path, [
                    'tag' => $definition->tag,
                    'first' => $pool[$definition->name]->name,
                    'second' => $definition->name,
                ]);
            }

            $pool[$definition->name] = $definition;
        }
    }

    /**
     * 오버라이드 선언 하나를 정의 풀에 적용합니다.
     *
     * @param  ComponentOverride  $override  오버라이드 선언
     * @param  ComponentManifest  $template  선언한 템플릿의 매니페스트
     * @param  array<string, ComponentManifest>  $byOwner  확장 식별자 → 매니페스트
     * @param  array<string, ComponentDefinition>  $pool  정의 풀 (참조로 수정)
     *
     * @throws CompileError 대상 확장이 활성인데 그 컴포넌트가 없는 경우
     */
    private function apply(ComponentOverride $override, ComponentManifest $template, array $byOwner, array &$pool): void
    {
        $target = $byOwner[$override->target] ?? null;

        // 대상 확장 부재 = 사문 선언. 나중에 설치될 수 있으므로 오류로 보지 않는다.
        if ($target === null) {
            Log::info('컴포넌트 오버라이드 대상 확장이 활성 상태가 아닙니다 — 선언을 건너뜁니다', [
                'template' => $template->ownerId,
                'target' => $override->target,
                'component' => $override->definition->name,
            ]);

            return;
        }

        if (! isset($pool[$override->definition->name])) {
            throw CompileError::inFile(LayoutMarkupErrorCode::OverrideTargetComponentUnknown, $template->path, [
                'component' => $override->shortName(),
                'target' => $override->target,
            ]);
        }

        if (! $this->isVersionCompatible($override, $target, $template)) {
            // 원본 폴백 — 풀을 그대로 둔다. 대체본만 폐기된다.
            return;
        }

        // 집합 필터링: 원본을 제거하고 같은 좌표에 대체본을 넣는다.
        $pool[$override->definition->name] = $override->definition;
    }

    /**
     * 오버라이드의 버전 제약이 대상 확장의 실제 버전과 호환되는지 판정합니다.
     *
     * @param  ComponentOverride  $override  오버라이드 선언
     * @param  ComponentManifest  $target  대상 확장의 매니페스트
     * @param  ComponentManifest  $template  선언한 템플릿의 매니페스트
     * @return bool 호환이면 true (제약 미선언이면 항상 true)
     *
     * @throws CompileError 버전 제약을 파싱할 수 없는 경우
     */
    private function isVersionCompatible(ComponentOverride $override, ComponentManifest $target, ComponentManifest $template): bool
    {
        if ($override->targetVersion === null) {
            return true;
        }

        // 런타임(LayoutExtensionService.php:2173-2182)은 제약 파싱이 실패하면 오버라이드를
        // 적용한다 — 화면을 살려 두려는 런타임의 선택이다. 작성 시점에는 그 관용이 해롭다:
        // 틀린 제약이 "항상 호환" 으로 조용히 굳어 대상 확장이 갱신돼도 아무도 모른다.
        // 컴파일러가 런타임보다 엄격한 것은 의도적이다(D24 ⑧ 와 같은 이유).
        try {
            $compatible = Semver::satisfies($target->version, $override->targetVersion);
        } catch (\Exception) {
            throw CompileError::inFile(LayoutMarkupErrorCode::ManifestInvalid, $template->path, [
                'field' => sprintf('overrides.%s.targetVersion', $override->definition->name),
            ]);
        }

        if (! $compatible) {
            Log::info('컴포넌트 오버라이드의 버전 제약이 대상과 맞지 않습니다 — 원본을 사용합니다', [
                'template' => $template->ownerId,
                'target' => $override->target,
                'target_version' => $target->version,
                'constraint' => $override->targetVersion,
                'component' => $override->definition->name,
            ]);
        }

        return $compatible;
    }
}
