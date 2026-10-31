<?php

namespace App\Support\LayoutMarkup;

/**
 * 레지스트리(`components.json`)에 등재된 컴포넌트 하나의 컴파일 시점 표현.
 *
 * `.g7.html` 의 태그 하나를 JSON 노드의 `name`+`type` 으로 바꾸는 데 필요한 정보만 담는다.
 * 매니페스트의 `description`·`path` 는 사람이 읽는 문서용이라 싣지 않는다(런타임도 쓰지 않는다).
 */
final class ComponentDefinition
{
    /**
     * @param  string  $name  컴포넌트명 — JSON 노드의 `name` 으로 그대로 방출. 템플릿 소유는 `DataGrid`, 확장 소유는 `{식별자}.{이름}` (예: `sirsoft-ecommerce.ProductCard`)
     * @param  string  $tag  마크업 태그명 — kebab (예: `data-grid`, `sirsoft-ecommerce-product-card`)
     * @param  string  $type  JSON 노드의 `type` (`basic`|`composite`|`layout`)
     * @param  array<string, string>  $propTypes  prop 명 → 선언 타입 (`:attr` 판정 힌트, 부분 목록)
     * @param  array<int, string>  $skipBindingKeys  선평가 금지 키 (DEFAULT + 매니페스트 선언 병합 결과)
     * @param  string|null  $owner  이 컴포넌트를 제공한 확장 식별자. 템플릿 소유면 null
     */
    public function __construct(
        public readonly string $name,
        public readonly string $tag,
        public readonly string $type,
        public readonly array $propTypes = [],
        public readonly array $skipBindingKeys = [],
        public readonly ?string $owner = null,
    ) {}

    /**
     * 이 컴포넌트를 확장이 제공했는지 판정합니다.
     *
     * @return bool 확장 소유면 true, 템플릿 소유면 false
     */
    public function isExtensionOwned(): bool
    {
        return $this->owner !== null;
    }

    /**
     * 해당 prop 값을 컴파일러가 선평가하면 안 되는지 판정합니다.
     *
     * 런타임(DynamicRenderer.tsx:2242-2246)이 바인딩 루프에서 건너뛰는 키와 같은 목록이다 —
     * 컴파일러가 이 키 안의 `{{}}`/`$t:defer:` 를 미리 풀어 버리면 런타임이 받을 원문이 사라진다.
     *
     * @param  string  $prop  prop 명
     * @return bool 선평가 금지 대상이면 true
     */
    public function shouldSkipBinding(string $prop): bool
    {
        return in_array($prop, $this->skipBindingKeys, true);
    }

    /**
     * 매니페스트에 선언된 prop 타입을 반환합니다.
     *
     * 선언은 **부분 목록**이다 — 번들 템플릿 실측 기준 실사용 props 의 75~77% 만 선언되어 있고
     * `Button.type`·`Input.value` 같은 정상 prop 도 다수 미선언이다. 따라서 이 값은 타입 판정
     * 힌트일 뿐이며, null(미선언)을 "없는 prop" 으로 해석해서는 안 된다.
     *
     * @param  string  $prop  prop 명
     * @return string|null 선언 타입, 미선언이면 null
     */
    public function propType(string $prop): ?string
    {
        return $this->propTypes[$prop] ?? null;
    }
}
