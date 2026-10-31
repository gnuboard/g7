<?php

namespace App\Support\LayoutMarkup;

/**
 * 템플릿이 확장 소유 컴포넌트를 대체한다는 선언 하나.
 *
 * 레이아웃 오버라이드의 3종 세트(대상 + 버전 제약 + 원본 폴백)를 컴포넌트 축에 그대로
 * 계승한다(`LayoutExtensionService.php:2135-2172`). 대체는 **같은 이름 아래에서** 일어나므로
 * 마크업 태그는 바뀌지 않는다 — `<sirsoft-ecommerce-product-card>` 는 원본이 쓰이든 템플릿의
 * 대체본이 쓰이든 같은 태그이고, 노드 `name` 도 `sirsoft-ecommerce.ProductCard` 그대로다.
 * 갈리는 것은 구현체(런타임)와 엔트리 메타(type/props)뿐이다.
 *
 * 오버라이드는 **남의 것을 대체**하므로 버전 제약이 필수다 — 그 남이 갱신되면 대체본이
 * 낡는다. 확장이 자기 컴포넌트의 템플릿 전용 판본을 내는 `for` 에 버전 제약이 없는 것과
 * 대비된다(자기 것을 자기 버전으로 내는 것이라 겨룰 대상이 없다).
 */
final class ComponentOverride
{
    /**
     * @param  string  $target  대체 대상 확장 식별자 (예: `sirsoft-ecommerce`)
     * @param  string|null  $targetVersion  대상 확장의 허용 버전 제약 (Semver, 미선언이면 null = 무제한)
     * @param  ComponentDefinition  $definition  대체본의 정의 (name·tag·type 은 원본과 같은 좌표)
     */
    public function __construct(
        public readonly string $target,
        public readonly ?string $targetVersion,
        public readonly ComponentDefinition $definition,
    ) {}

    /**
     * 이 오버라이드가 가리키는 원본 컴포넌트의 짧은 이름을 반환합니다.
     *
     * 매니페스트의 `name` 은 프리픽스 없는 짧은 이름(`ProductCard`)이고, 레지스트리 키는
     * `{확장식별자}.{짧은이름}` 이다.
     *
     * @return string 짧은 이름 (예: `ProductCard`)
     */
    public function shortName(): string
    {
        $key = $this->definition->name;
        $dot = strrpos($key, '.');

        return $dot === false ? $key : substr($key, $dot + 1);
    }
}
