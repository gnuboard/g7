# 확장 컴포넌트 (Extension Components)

확장(모듈/플러그인)이 자기 화면 컴포넌트를 제공하고, 템플릿이 그것을 갈아끼우고, 확장이 특정 템플릿 전용 판본을 내는 규약.

## TL;DR (5초 요약)

```text
1. 확장 컴포넌트 이름 = {확장식별자}.{이름} / 마크업 태그 = <{정규화된-식별자}-{kebab}> (프리픽스 필수)
2. 등록은 확장 번들이 스스로 — G7Core.getComponentRegistry().registerExtensionComponent()
3. 오버라이드(남의 것 대체) 권한은 템플릿 전용 — 확장은 components/overrides/ 를 둘 수 없다
4. 확장이 자기 것의 템플릿 전용 판본을 내려면 components/for/{템플릿식별자}/ + forTemplate 선언
5. 판본 선택·오버라이드 승패는 코어가 판정 — 확장은 선언만 한다
```

## 목차

- [세 개념](#세-개념)
- [① 제공 — 확장이 컴포넌트를 낸다](#-제공--확장이-컴포넌트를-낸다)
- [② 오버라이드 — 템플릿이 확장 컴포넌트를 대체한다](#-오버라이드--템플릿이-확장-컴포넌트를-대체한다)
- [③ 템플릿 전용 판본 — 확장이 자기 것을 그 템플릿에서만 다르게](#-템플릿-전용-판본--확장이-자기-것을-그-템플릿에서만-다르게)
- [작성자 관점](#작성자-관점)
- [편집기 팔레트](#편집기-팔레트)
- [샘플](#샘플)

## 세 개념

세 가지는 서로 다른 일이며 섞지 않는다. 레이아웃 확장의 `extension_point`(추가) vs `override_target`(대체) 이분법을 컴포넌트 축에 계승한 것이다.

| 개념 | 누가 | 무엇을 | 어디에 |
|---|---|---|---|
| **제공(provide)** | 확장 | 새 이름의 컴포넌트를 추가 | `{확장}/resources/js/components/{basic,composite,layout}/` |
| **오버라이드(override)** | **템플릿만** | 확장 소유 컴포넌트를 자기 구현으로 대체 | `{템플릿}/src/components/overrides/{확장식별자}/` |
| **템플릿 전용 판본(for)** | 확장 | **자기** 컴포넌트를 특정 템플릿에서만 다르게 | `{확장}/resources/js/components/for/{템플릿식별자}/` |

대체 권한이 템플릿에만 있는 것은 레이아웃 오버라이드와 같은 규약이다 — `override_target` 은 `source_type=template` 일 때만 성립한다. 확장이 남의 것을 대체하기 시작하면 서로 덮어쓰는 교착이 생기고, 그 승자 규칙에는 선례가 없다.

`for` 를 `override` 라 부르지 않는 이유: 그 낱말은 코드베이스에서 이미 세 가지 뜻(사용자 수정 기록 / 템플릿→확장 레이아웃 대체 / 스펙 키 덮어쓰기)으로 쓰인다. 네 번째를 얹으면 이름 자체가 혼동의 원인이 된다.

## ① 제공 — 확장이 컴포넌트를 낸다

### 소스 위치와 이름

컴포넌트는 `resources/js/components/{basic,composite,layout}/{이름}.tsx` 에 둔다. 하위 분류 디렉토리 이름이 곧 컴포넌트 `type` 이며, 분류 밖에 두면 `composite` 로 분류된다.

레지스트리에 등록되는 이름은 **`{확장식별자}.{이름}`** 이다 — 액션 핸들러의 `{identifier}.{name}` 과 같은 관례다.

```text
sirsoft-ecommerce 의 ProductCard
  → 등록 이름: sirsoft-ecommerce.ProductCard
  → 마크업 태그: <sirsoft-ecommerce.product-card data-type="composite">
```

접두어 없는 이름은 거부된다. 접두어가 없으면 템플릿 소유 컴포넌트를 덮어쓸 수 있고, 작성자가 태그만 보고 출처를 알 수 없다. 정적 검사가 자동 차단한다.

### 태그 표기 — 식별자를 그대로 싣는다

확장 컴포넌트 태그는 `확장식별자.kebab` 이다. 점 앞에는 확장 식별자를 **그대로**(밑줄 포함) 쓰고, 점 뒤에 이름의
kebab 표기를 붙인다. 컴파일러는 점에서 나눠 등록 이름(`식별자.이름`)을 **어떤 목록도 보지 않고** 만든다 — JSON
레이아웃이 `name` 을 그대로 적던 것과 같다(2026-09-28 결정: 마크업 컴파일은 템플릿·확장 목록과 무관).

```text
gnuboard7-hello_module 의 MemoCard
  → 등록 이름: gnuboard7-hello_module.MemoCard
  → 마크업 태그: <gnuboard7-hello_module.memo-card data-type="composite">
```

종전에는 식별자의 `_` 를 `-` 로 접은 프리픽스(`<gnuboard7-hello-module-memo-card>`)를 썼다. 그 표기는 식별자와 이름의
경계, 원래 밑줄이 사라져 목록 없이는 이름을 되돌릴 수 없었고, `a_b` 와 `a-b` 같은 두 확장이 겹쳤다. 식별자를 그대로
싣으므로 이제 두 문제가 모두 없다.

### 등록 — 확장은 스스로 등록한다

템플릿 컴포넌트는 코어가 `components.json` 을 읽고 템플릿 번들의 전역 export 에서 구현체를 찾아 등록한다. **확장에는 그 전역에 실릴 자리가 없다.** 그래서 확장은 액션 핸들러와 같은 방식으로 자기 번들에서 스스로 등록한다.

```ts
const MODULE_IDENTIFIER = 'sirsoft-ecommerce';

function initModule(): void {
    const registry = (window as any).G7Core?.getComponentRegistry?.();

    if (!registry?.registerExtensionComponent) return;

    registry.registerExtensionComponent(
        `${MODULE_IDENTIFIER}.ProductCard`,
        ProductCard,
        { name: `${MODULE_IDENTIFIER}.ProductCard`, type: 'composite' }
    );
}

initModule();
```

**호출 시점은 안전하다.** 코어가 확장 번들 로드를 초기 라우트 처리 전에 기다리므로, 여기서 하는 등록은 첫 렌더보다 반드시 먼저 끝난다.

번들 로드가 실패하면 등록은 영영 오지 않고 그 컴포넌트 노드는 화면에서 조용히 사라진다(미발견 컴포넌트는 렌더에서 `null` 이 된다). 그 침묵은 `registry.getMissingExtensionComponents(names)` 로 관측한다.

### 빌드

엔트리는 `resources/js/index.ts`, 산출물은 IIFE 번들이며 `module.json`/`plugin.json` 의 `assets.js` 에 선언한다. React 는 반드시 external 로 둔다 — 번들이 자기 React 사본을 품으면 훅 호출이 다른 인스턴스에 걸려 "Invalid hook call" 로 죽는다.

`module:build` / `plugin:build` 가 컴포넌트 소스를 스캔해 `components.json` 을 만든다. 컴포넌트의 `{이름}Props` 선언에서 prop 메타(타입·필수 여부·설명)를 추출하므로, 편집기 자동 컨트롤을 원하면 props 를 인터페이스로 선언한다.

## ② 오버라이드 — 템플릿이 확장 컴포넌트를 대체한다

### 디렉토리와 선언

구현체는 `{템플릿}/src/components/overrides/{확장식별자}/{이름}.tsx` 에 두고, `components.json` 에 선언한다. 디렉토리명이 곧 대상 식별자다 — 레이아웃 오버라이드(`layouts/overrides/{확장식별자}/`)와 같은 규약이다.

```json
{
    "templateId": "sirsoft-basic",
    "version": "1.0.3",
    "components": { "basic": [], "composite": [], "layout": [] },
    "overrides": [
        {
            "target": "sirsoft-ecommerce",
            "targetVersion": "^1.0",
            "name": "ProductCard",
            "exportName": "EcommerceProductCard",
            "type": "composite",
            "path": "src/components/overrides/sirsoft-ecommerce/ProductCard.tsx",
            "props": {}
        }
    ]
}
```

| 필드 | 뜻 |
|---|---|
| `target` | 대체 대상 확장 식별자. 디렉토리명과 같아야 한다 |
| `targetVersion` | 대상 확장의 허용 버전 제약(Semver). 미선언이면 무제한 |
| `name` | 대상 확장이 붙인 이름(짧은 이름). 대체는 이 좌표에서 일어난다 |
| `exportName` | 템플릿 번들에서 이 대체본을 내보낸 이름. 미선언이면 `name` |
| `type`·`props` | 대체본의 엔트리 메타 — 원본이 아니라 이쪽이 쓰인다 |

`exportName` 이 필요한 경우: 템플릿에도 같은 이름(`ProductCard`)의 자기 컴포넌트가 있으면 번들 전역(평면)에서 어느 쪽인지 가릴 수 없다. 그럴 때만 별도 export 이름을 준다.

`exportName` 은 번들에서 구현체를 찾는 **로컬 별칭일 뿐 좌표가 아니다**. 레지스트리 좌표는 언제나 `{target}.{name}` — 즉 대상 확장이 붙인 이름이다. 별칭을 좌표에 섞으면 컴파일러가 방출하는 노드 이름과 갈려 대체본이 아무도 조회하지 않는 키에 앉고, 확장 원본이 경고 없이 그대로 렌더된다. 게다가 `exportName` 미선언 시에는 `name` 으로 폴백해 우연히 맞으므로, 이 혼동은 **별칭이 실제로 필요한 경우에만**(= 이름 충돌이 있는, 가장 필요한 상황에만) 드러난다.

### 대체해도 마크업은 바뀌지 않는다

대체는 **같은 이름 아래에서** 일어난다. 마크업 태그도 노드 `name` 도 그대로이고, 갈리는 것은 구현체와 엔트리 메타뿐이다.

```html
<!-- 원본이 쓰이든 템플릿 대체본이 쓰이든 마크업은 하나다 -->
<sirsoft-ecommerce-product-card title="{{product.name}}"/>
```

그래서 오버라이드를 붙이거나 떼는 것이 레이아웃에 파급되지 않는다.

### 승패는 집합 필터링, priority 아님

호환 오버라이드가 있으면 원본이 집합에서 **제거**되고 대체본이 그 자리에 들어앉는다. `priority` 는 이 판정에 관여하지 않는다 — 선례상 priority 는 살아남은 것들의 적용 **순서**용이며, 두 축을 섞지 않는다.

| 상황 | 결과 |
|---|---|
| 대상 확장이 활성 + 버전 호환 | 대체본 적용 (원본 제거) |
| 대상 확장이 활성 + **버전 비호환** | **원본 폴백** + `Log::info` — 대체본만 폐기된다 |
| 대상 확장이 비활성·미설치 | 선언은 사문 — 무시(오류 아님). 나중에 설치될 수 있다 |
| 대상이 활성인데 그 이름이 없음 | **컴파일 오류** — 오타이거나 제약이 틀렸다 |
| 같은 컴포넌트를 두 번 오버라이드 | **컴파일 오류** |

비호환 시 원본이 자리를 지키는 것이 이 규약의 요점이다 — 화면이 조용히 사라지지 않는다.

버전 판정은 **서버(컴파일러)가 한다.** 대상 확장의 실제 버전을 아는 것이 서버이고, 런타임은 그 결과를 이행만 한다. 판정이 두 곳에 있으면 서로 갈린다.

### 확장은 오버라이드를 선언할 수 없다

확장 매니페스트에 `overrides` 가 있으면 컴파일러가 거부하고, 확장이 `resources/js/components/overrides/` 디렉토리를 두면 정적 검사가 차단한다. 그 자리에 두면 매니페스트 생성기가 그것을 평범한 `composite` 컴포넌트로 등재해 **의도와 다른 것이 조용히 만들어진다**.

## ③ 템플릿 전용 판본 — 확장이 자기 것을 그 템플릿에서만 다르게

"확장이 특정 템플릿을 오버라이드한다" 는 말은 **템플릿 소유 컴포넌트를 대체한다는 뜻이 아니다.** 확장이 **자기** 컴포넌트의 그 템플릿용 판본을 낸다는 뜻이다. 확장은 자기 것만 건드리므로 교착이 구조적으로 불가능하다.

### 디렉토리와 선언

```text
{확장}/resources/js/components/composite/ProductCard.tsx          ← 기본 판본
{확장}/resources/js/components/for/sirsoft-basic/ProductCard.tsx  ← sirsoft-basic 전용 판본
```

`module:build` 가 `for/` 를 스캔해 매니페스트에 싣는다. 판본은 같은 이름의 **다른 구현**이지 별개 컴포넌트가 아니므로 `components` 에 중복 등재되지 않는다.

```json
{
    "identifier": "sirsoft-ecommerce",
    "components": { "composite": [{ "name": "ProductCard", "type": "composite" }] },
    "for": { "sirsoft-basic": ["ProductCard"] }
}
```

판본만 있고 원본이 없으면 컴파일 오류다 — 그 판본은 어느 태그로도 작성할 수 없는 죽은 코드다.

### 선택은 코어가 한다 — 확장은 선언만

```ts
registry.registerExtensionComponent(
    `${MODULE_IDENTIFIER}.ProductCard`,
    ProductCardForBasic,
    { name: `${MODULE_IDENTIFIER}.ProductCard`, type: 'composite' },
    { forTemplate: 'sirsoft-basic' }
);
```

확장은 "이 판본은 그 템플릿용" 이라고 선언만 하고, 활성 템플릿과 대조하는 판정은 코어가 전담한다. 확장이 활성 템플릿을 읽어 스스로 분기하면 확장마다 판정 규칙과 폴백이 갈리고, 그 순간 선언형이 아니라 명령형이 된다 — 확장 번들 priority 를 선언(`loading.priority`)으로만 받고 코어가 정렬하는 규율과 같은 이유다.

판정 규칙:

| 상황 | 결과 |
|---|---|
| `forTemplate` == 활성 템플릿 | 전용 판본 채택 (기본 판본을 이긴다) |
| `forTemplate` != 활성 템플릿 | 무시 |
| 템플릿이 그 이름을 오버라이드함 | 템플릿이 이긴다 — 확장의 등록은 무시된다 |

기본 판본과 전용 판본의 **등록 순서는 결과를 바꾸지 않는다**. 순서에 결과가 걸리면 그것 자체가 침묵 승자다.

전용 판본에 버전 제약이 없는 이유: 자기 것을 자기 버전으로 내는 것이라 겨룰 대상이 없다. 반면 오버라이드는 남의 것을 대체하므로 제약이 필수다 — 그 남이 갱신되면 대체본이 낡는다.

## 작성자 관점

확장 컴포넌트는 템플릿 컴포넌트와 같은 평면에 있다. 다른 것은 태그에 프리픽스가 붙는다는 점뿐이다.

```html
<div class="grid gap-4">
  <sirsoft-ecommerce-product-card title="{{product.name}}" :featured="true"/>
</div>
```

JSON 레이아웃에서는 노드 `name` 에 등록 이름을 그대로 쓴다.

```json
{ "type": "composite", "name": "sirsoft-ecommerce.ProductCard", "props": { "title": "{{product.name}}" } }
```

**"미등록 컴포넌트 확인" 규율의 대상이 넓어졌다** — 레이아웃 편집기(와 템플릿 자신의 레이아웃 점검)의 검사 목록은 대상 템플릿의 `components.json` 단독이 아니라 **편집 대상 템플릿 + 설치된 모듈·플러그인 전부(활성·비활성 무관) + 그 템플릿의 확장 컴포넌트 오버라이드**다. 확장 매니페스트는 설치 디렉토리(`modules/{식별자}/components.json`·`plugins/{식별자}/components.json`)에서 읽는다 — 편집 도중 확장을 꺼도 그 컴포넌트를 쓴 레이아웃을 계속 저장할 수 있게 하려는 것이다. 목록에 없는 확장 컴포넌트(확장 삭제·이름 오타)는 **경고**(`extension_component_not_installed`)만 내고 저장을 막지 않는다. 점 없는 템플릿 컴포넌트 오타는 종전대로 오류다. 어떤 판본이 실제로 쓰이는지(원본/템플릿 대체본/전용 판본)는 작성자가 알 필요가 없다 — 셋 다 같은 태그·같은 이름이다.

## 편집기

### 팔레트 등재

확장 컴포넌트는 확장의 `editor-spec.json` 에 `componentPalette` 를 선언해야 팔레트에 나타난다. 편집기 스펙은 이미 확장 것을 병합하므로(모듈 → 플러그인 → 템플릿) 그 경로를 그대로 쓴다. 확장 스펙이 담을 수 있는 이름은 **자기 식별자가 앞에 붙은 자기 컴포넌트뿐**이다 — 템플릿 컴포넌트의 어휘(`controls`·`nesting`, 템플릿 이름의 팔레트·역량)는 템플릿 스펙이 소유한다([editor-spec.md](editor-spec.md) 「소유」 절).

```json
{
  "moduleId": "sirsoft-ecommerce",
  "componentPalette": {
    "groups": [
      { "label": "$t:sirsoft-ecommerce.editor.palette.group", "kind": "content",
        "components": ["sirsoft-ecommerce.ProductCard"] }
    ],
    "entries": {
      "sirsoft-ecommerce.ProductCard": {
        "label": "$t:sirsoft-ecommerce.editor.palette.product_card.label",
        "requiresDefaultNode": true,
        "defaultNode": { "type": "composite", "name": "sirsoft-ecommerce.ProductCard", "props": {} }
      }
    }
  },
  "componentCapabilities": {
    "sirsoft-ecommerce.ProductCard": { "label": "...", "coreProps": ["id"], "styleControls": ["marginAll"] }
  }
}
```

팔레트 키는 레지스트리 등록 이름(`{확장식별자}.{이름}`)을 그대로 쓴다. 라벨은 **확장 자신의 언어팩 네임스페이스**(`$t:{확장식별자}.editor.palette.*`)에 두고 코어의 `editor.*` 를 침범하지 않는다.

팔레트 목록에 실리는 항목(종류, 설명, 속성 선언)은 편집기가 **그 확장의 `components.json`** 에서 읽는다(`/api/{modules|plugins}/{식별자}/components`). 편집 대상 템플릿의 `components.json` 에는 확장 컴포넌트가 없기 때문이다. 팔레트에 컴포넌트를 등재한 확장만 요청하고, 템플릿이 그 컴포넌트를 대체(`overrides`)했으면 대체본의 설명과 속성을 쓴다. 등재 이름이 그 확장 `components.json` 에 없으면 팔레트에 나오지 않는다.

놓을 자리(`nesting`)는 템플릿이 자기 컴포넌트 이름으로 쓴 규칙이라 확장 이름을 모른다. 그래서 편집기는 팔레트에 등재된 확장 컴포넌트를 **블록 하나**로 보고, 템플릿이 일반 블록(`Div`)을 끌어 놓게 한 자리(루트)와 `Div` 를 자식으로 받는 컨테이너에 함께 놓게 한다. 목록(`Ul`)이나 인라인(`Span`·`P`)처럼 `Div` 를 받지 않는 자리에는 놓이지 않는다. 템플릿이 확장 컴포넌트 이름을 어느 컨테이너의 `accepts` 에 직접 적었으면 컨테이너 자리는 그 선언만 따르고 `Div` 를 받는 다른 컨테이너에는 더하지 않는다. 끌어 놓기 목록(`draggable`)에는 그때도 더한다 — 놓기 판정이 끌어 놓기 목록을 먼저 보므로, 빠지면 템플릿이 적어 둔 컨테이너에도 놓을 수 없다.

prop 컨트롤은 `components.json` 의 `props` 선언에서 나오고, 그 선언은 빌드가 컴포넌트 소스의 `{이름}Props` 인터페이스에서 추출한다 — 인터페이스를 선언하지 않으면 컨트롤이 생기지 않는다.

팔레트에 등재한 composite/layout 컴포넌트는 `editorAttrs?: EditorAttrs` 를 받아 **시각적 루트 요소에 `{...editorAttrs}` 로 spread** 해야 한다. 템플릿 컴포넌트와 같은 계약이다([components-types.md](../frontend/components-types.md) 「editorAttrs 패스스루」). 빠뜨리면 팔레트에서 꺼내 놓은 노드를 편집기가 선택하거나 옮기지 못한다. 기본 판본(`resources/js/components/`), 템플릿 전용 판본(`…/for/<템플릿>/`), 템플릿 대체본(`templates/<템플릿>/src/components/overrides/<확장>/`) 모두 해당한다.

### 캔버스 미리보기

편집기는 호스트 페이지와 **별도의 격리 레지스트리**를 만들어 편집 대상 템플릿을 렌더한다. 확장 IIFE 는 호스트 부팅 때 이미 실행이 끝났으므로, 코어는 확장의 **선언 전체**를 클래스에 보관해 두고 격리 인스턴스가 `applyExtensionComponents()` 로 자기 몫을 다시 고른다.

이것이 단순 복사가 아닌 이유: 호스트가 admin 템플릿을 띄운 채 user 템플릿을 편집하면 **같은 선언에서 호스트는 기본 판본을, 캔버스는 user 전용 판본을** 골라야 한다. 확장 작성자가 신경 쓸 일은 없다 — 선언만 하면 각 레지스트리가 알아서 고른다.

## 샘플

`gnuboard7-hello_module` + `gnuboard7-hello_user_template` 이 세 개념을 각각 하나씩 실물로 담고 있다.

| 개념 | 파일 |
|---|---|
| ① 제공 | `modules/_bundled/gnuboard7-hello_module/resources/js/components/composite/MemoCard.tsx` |
| ① 등록 | `modules/_bundled/gnuboard7-hello_module/resources/js/index.ts` |
| ② 오버라이드 | `templates/_bundled/gnuboard7-hello_user_template/src/components/overrides/gnuboard7-hello_module/MemoCard.tsx` |
| ③ 전용 판본 | `modules/_bundled/gnuboard7-hello_module/resources/js/components/for/gnuboard7-hello_admin_template/MemoCard.tsx` |
| 팔레트 등재 | `modules/_bundled/gnuboard7-hello_module/editor-spec.json` |

## 관련 문서

- [module-basics.md](module-basics.md) — 모듈 디렉토리 구조
- [module-assets.md](module-assets.md) — 확장 번들 로딩
- [template-basics.md](template-basics.md) — 템플릿 컴포넌트
- [editor-spec.md](editor-spec.md) — 편집기 팔레트 선언
- [../frontend/components.md](../frontend/components.md) — 컴포넌트 작성 규칙
