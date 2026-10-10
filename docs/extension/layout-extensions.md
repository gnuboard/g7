# 레이아웃 확장 시스템 (Layout Extensions)

> 모듈/플러그인이 기존 레이아웃에 동적으로 UI를 주입하는 시스템입니다.

## 목차

- [개요](#개요)
- [핵심 원칙](#핵심-원칙)
- [확장 타입](#확장-타입)
- [Extension 파일 위치](#extension-파일-위치)
- [Overlay 확장](#overlay-확장)
- [Extension Point 확장](#extension-point-확장)
- [템플릿 오버라이드](#템플릿-오버라이드)
- [모듈 활성화/비활성화 동작](#모듈-활성화비활성화-동작)
- [우선순위](#우선순위)
- [데이터 소스 병합](#데이터-소스-병합)
- [모달 병합](#모달-병합)
- [Overlay 레이아웃 섹션 병합](#overlay-레이아웃-섹션-병합)
- [관련 파일](#관련-파일)
- [문제 해결](#문제-해결)

---

## 개요

Layout Extension 시스템은 모듈/플러그인이 코어 또는 다른 확장의 레이아웃에 UI 컴포넌트를 동적으로 주입할 수 있게 해줍니다.

**주요 특징**:
- 코어 레이아웃 수정 없이 UI 확장
- 모듈 비활성화 시 자동으로 확장 UI 숨김
- 템플릿에서 모듈 확장 오버라이드 가능
- 우선순위 기반 렌더링 순서 제어
- 관리자가 레이아웃 시각 편집기로 확장 조각을 직접 편집 (아래 "시각 편집" 참조)

### 시각 편집 (레이아웃 편집기)

레이아웃 시각 편집기(`/admin/layout-editor/{templateId}`)의 라우트 트리에 `[확장 주입]`
그룹(출처별 하위그룹)이 표시되며, 확장 항목을 선택하면 확장 편집 모드로 진입해 확장 조각
(extension_point=`content.components`, overlay=각 injection 의 `components`)을 일반 레이아웃과
동등하게 시각 편집한다. 저장은 해당 확장 행(layout-extensions API)으로 적재되어 버전 관리·수정
감지·`--layout-strategy=keep` 병합(고친 부분 유지 + 새 버전 반영)이 동작한다.

`inject_props` 주입은 편집할 컴포넌트 트리가 없으므로, 그 호스트 노드를 라우트/공통 레이아웃
편집 중 선택해 **속성 모달의 "확장이 주입한 속성" 섹션**(출처 배지)에서 편집한다 — 편집 결과는
호스트 레이아웃이 아니라 그 확장 행으로 교차 저장된다. 라우트/공통 레이아웃 편집 중 확장이 주입한
영역은 잠금 표시되며 "확장 편집" 어포던스로 확장 편집 모드에 진입할 수 있다.

#### 주입 kind × 편집기 대응

확장이 호스트에 주입하는 모든 kind 와 편집기의 대응 방식은 아래 표가 기준이다. 새 주입 방법을
추가할 때는 이 표에 편집기 대응을 함께 정의해야 한다.

| 주입 kind | 주입 경로 | 편집기 대응 |
| --- | --- | --- |
| extension_point `components` (mode: replace/append/prepend) | 호스트 EP 노드 자리 (components·modals 트리 모두 순회) | 확장 편집 모드 시각 편집. 게이트(`if`) 뒤면 editor-spec `states` 로 상태 시뮬레이션, 미정의 시 디그레이드 안내 |
| overlay `injections[].components` (position: prepend/append/prepend_child/append_child/replace) | 호스트 `target_id` 노드 기준 | 동일 — 확장 편집 모드 시각 편집 |
| overlay `injections[].position: inject_props` | 호스트 `target_id` 노드 props 병합 | 라우트/공통 편집 중 속성 모달 "확장이 주입한 속성" 섹션에서 편집 (확장 행으로 교차 저장) |
| 호스트 **모달 내부** EP 주입 (예: 회원가입 약관 모달의 `html_content`) | 호스트 `modals[]` 안 EP 노드 | 확장 편집 모드가 그 모달 노드를 표시용 `isOpen=true` 로 캔버스에 함께 렌더해 시각 편집 (모달 편집 모드와 동형) |
| `content.modals` (확장이 모달을 통째로 기여) | 호스트 `modals[]` 끝에 병합 | 시각 편집 비대상 — 트리에는 노출되고, 진입 시 "코드 편집 이용" 디그레이드 안내 |
| `content.data_sources` | 호스트 `data_sources` 병합 | 데이터 소스 모달에 출처 배지로 표시 (시각 편집 비대상) |
| `content.scripts` | 호스트 `scripts` 병합 (중복 제거) | 캔버스에 로드만 (CKEditor CDN 등) — 편집은 코드 편집 |
| `content.init_actions` (overlay) | 호스트 `init_actions` 끝에 병합 | 시각 편집 비대상 — 진입 시 "코드 편집 이용" 디그레이드 안내 |

트리 노출 판별(백엔드 `getExtensionHostLayouts`)은 "주입이 성립하는가"(overlay: 실재 `target_id`
injection 또는 modals/data_sources/scripts/init_actions 보유, EP: 병합할 payload 보유 + 확장점 실재)만 본다.
시각 편집 가능 여부의 정밀 판정은 진입 시 프론트(editability 판정 + 렌더 검증 + 폴백 안내)가
수행한다 — 시각 조각이 없는 확장도 숨기지 않고 디그레이드 안내로 코드 편집을 유도한다.

---

## 핵심 원칙

```text
필수: layout_extensions 통한 동적 주입 사용 (코어 레이아웃에 모듈 UI 하드코딩 금지)
필수: Layout Extension 시스템을 통한 동적 UI 주입
필수: 모듈 비활성화 시 관련 UI 자동 숨김
✅ 필수: 확장 컴포넌트에 ExtensionBadge 표시 (관리자 UI)
필수: 플러그인이 자기 화면을 가질 때 라우트는 plugins/{identifier}/ 네임스페이스 안에 선언
   (다른 확장이 소유한 경로를 선언하면 설치 순서에 따라 화면이 조용히 바뀐다)
필수: 다른 확장의 화면에 UI 를 끼워 넣을 때는 확장 지점(layout_extensions) 사용
필수: 다른 모듈·플러그인의 화면을 target_layout 으로 지목하면 그 확장을 manifest dependencies 또는 integrations 에 선언
필수: 남의 화면에 끼우는 조각이 쓰는 전역 키는 자기 접두 또는 자기 state_contracts 이름 (아래 접두 규칙)
```

### 대상 화면이 없을 때와 선언

대상 화면이 없는 설치에서 코어는 그 조각을 **건너뛸 뿐 오류를 내지 않습니다**(아래 「템플릿 대상 판정」). 그래서 조각은 대상 확장이 없어도
안전하지만, 그 사실이 **선언을 생략해도 된다는 뜻은 아닙니다.** 다른 모듈·플러그인이 소유한 화면을 `target_layout` 으로 지목하면 그 확장을
`integrations`(있으면 연동, 없어도 동작) 또는 `dependencies`(없으면 동작 불가)에 선언합니다 — 선언이 없으면 운영자와 상대 확장 개발자가 그
결합을 알 수 없고, 상대가 화면 이름을 바꾸면 조각이 조용히 사라집니다. 정적 검사 `extension-undeclared-extension-reference` 가 조각의
`target_layout` 을 소유 확장으로 되돌려 확인합니다. 템플릿의 화면(`admin_settings` 등 코어 화면 계약)은 어느 템플릿이든 같은 이름이라
선언 대상이 아닙니다. 선언 형식은 [플러그인 개발 — 의존성](plugin-development.md#2-의존성-dependency)에 있습니다.

### 조각의 전역 상태 키 접두 규칙

조각은 **남의 화면**에 끼워집니다. 그 화면도 전역 상태(`_global`)를 쓰므로, 조각이 무접두 이름(`grantTarget`, `cashReceiptForm` 등)을 쓰면 화면
주인이나 같은 화면의 다른 조각이 같은 이름을 쓰는 순간 서로의 값을 덮습니다 — 오류 없이 화면만 틀립니다.

조각이 쓰는 전역 키는 다음 중 하나여야 합니다.

| 허용 | 예 |
| --- | --- |
| 자기 접두 + 대문자 또는 `_` | `gdprGrantTargetKey`, `ecommerceCashReceiptForm` |
| 자기 manifest `state_contracts` 에 선언한 이름 | 쇼핑몰의 `preferredCurrency` |
| 코어 `plugins`·`modules` 의 자기 식별자 하위 | `plugins.sirsoft-ckeditor5` |

접두는 식별자의 vendor 뒤 이름에서 분류어(`pay_`, `message_`, `verification_`)를 뗀 camelCase 입니다(`sirsoft-gdpr` → `gdpr`,
`sirsoft-pay_kginicis` → `kginicis`). 이미 관례가 있는 확장은 그 접두를 씁니다(나이스페이먼츠 `nicepay`, 토스페이먼츠 `toss`,
비즈뿌리오 `bz`). 정적 검사 `overlay-global-key-prefix` 가 조각 마크업(`resources/extensions/*.g7.html`)을 확인합니다. 다른 확장이 읽어야 하는
값이면 접두 대신 `state_contracts` 로 공개 계약을 선언합니다 — [전역 상태 공개 계약](plugin-development.md#전역-상태-공개-계약-state_contracts).

---

## 확장 타입

| 타입 | 키 | 설명 | 사용 시점 |
|------|-----|------|----------|
| **Overlay** | `target_layout` | 기존 레이아웃의 특정 컴포넌트 ID를 찾아 주입 | 특정 위치에 삽입/추가 |
| **Extension Point** | `extension_point` | 레이아웃에 사전 정의된 확장 포인트에 주입 | 확장용 영역이 미리 정의된 경우 |

---

## Extension 파일 위치

### 모듈

```
modules/
└── vendor-module/
    └── resources/
        └── extensions/           ← 확장 정의 디렉토리
            └── *.g7.html         ← 확장 소스 파일 (소스 SSoT · 문서 루트 <extension>)
```

### 플러그인

```
plugins/
└── vendor-plugin/
    └── resources/
        └── extensions/
            └── *.g7.html
```

### 템플릿 오버라이드

```
templates/
└── vendor-template/
    └── extensions/
        └── {module-identifier}/    ← 오버라이드 대상 확장
            └── *.g7.html           ← 오버라이드 소스 파일 (문서 루트 <extension>)
```

확장 주입 fragment 를 템플릿 판본으로 대체하는 것이므로 문서 루트는 원본과 같은 `<extension>` 입니다. `<override>` 는 **레이아웃** 대체(`layouts/overrides/`) 전용 루트입니다 ([layout-markup.md](../frontend/layout-markup.md) §문서 루트).

세 등록 경로(모듈 · 플러그인 · 템플릿 오버라이드)는 모두 같은 소스 선택 규약을 씁니다 — `.g7.html` 소스가 있으면 등록 시점에 메모리로 컴파일하고, 짝 마크업이 없는 `.json` 만 직접 읽습니다(`CollectsFragmentSources`).

---

## Overlay 확장

기존 레이아웃의 특정 컴포넌트를 찾아 UI를 주입합니다.

### JSON 스키마

```html
<extension target-layout="admin_user_form" priority="100">
  <inject target-id="section_marketing_consent" position="append"/>
</extension>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "target_layout": "admin_user_form",
  "injections": [
    {
      "target_id": "section_marketing_consent",
      "position": "append",
      "components": [...]
    }
  ],
  "data_sources": [...],
  "priority": 100
}
```

### 필드 설명

| 필드 | 필수 | 타입 | 설명 |
|------|------|------|------|
| `target_layout` | ✅ | string | 확장할 대상 레이아웃 이름 |
| `injections` | ✅ | array | 주입 정의 배열 |
| `injections[].target_id` | ✅ | string | 주입 대상 컴포넌트 ID |
| `injections[].position` | ✅ | string | 주입 위치 (아래 참조) |
| `injections[].components` | ✅ | array | 주입할 컴포넌트 배열 |
| `data_sources` | ❌ | array | 추가 데이터 소스 (선택) |
| `init_actions` | ❌ | array | 호스트 레이아웃 `init_actions` 뒤에 추가할 초기화 액션 배열 (선택). 모듈이 호스트가 모르는 `_global` 초기화(예: 모듈별 표시 통화 복원)를 기여할 때 사용. 호스트는 추가 단계의 존재/모듈을 모른다 |
| `priority` | ❌ | number | 우선순위 (기본값: 100, 낮을수록 먼저) |

> `init_actions` 병합은 **overlay(`target_layout`)** 확장에서 지원한다. 호스트 `init_actions` 배열 끝에 확장의 `init_actions` 가 순서대로 추가되므로, 호스트 초기화가 모두 끝난 뒤 확장 초기화가 실행된다. 모듈 비활성 시 그 확장 행 자체가 비활성화되어 추가 단계도 사라진다(호스트 레이아웃 수정 0). 모듈이 호출하는 핸들러는 모듈 소유 네임스페이스(`{module-id}.handlerName`)를 사용해 모듈 미설치 시 미등록 핸들러로 무동작하게 하는 것을 권장한다.

### 상속(extends) 체인 전파 — base 레이아웃 타겟 overlay

`target_layout` 이 다른 화면이 `extends` 로 상속하는 **공통/베이스 레이아웃**(예: `_user_base`, `_admin_base`)인 overlay 는, 그 base 를 상속하는 모든 자식 레이아웃(`home`, `shop/index` 등)을 서빙할 때도 적용된다.

서빙 파이프라인은 `extends` 를 해소해 자식 문서를 만들 때 상속한 base 이름들을 내부 메타로 보존하고, overlay 매칭 시 `[자식 레이아웃명] + 상속 체인` 전체를 대상으로 한다. 따라서 `_user_base` 를 `target_layout` 으로 하는 overlay(예: 헤더 슬롯 주입, `init_actions` 통화 복원)는 base 를 직접 서빙하지 않아도 자식 화면에 정상 주입된다. 다단계 상속(`A extends B extends _user_base`)도 체인 끝까지 전파된다.

```text
overlay(target_layout=_user_base) 주입
    ↓ (home extends _user_base 서빙 시)
home 화면에 _user_base 타겟 overlay 가 적용됨 (슬롯·init_actions 포함)
```

- Extension Point 는 병합된 컴포넌트 트리를 직접 스캔하므로 상속과 무관하게 항상 동작한다(이 전파는 overlay 매칭에만 해당).
- 같은 overlay 가 중복 적용되지 않도록 매칭 결과는 확장 ID 기준으로 합집합 후 `priority` 로 재정렬된다.
- 편집기에서 자식 라우트 레이아웃을 편집할 때, base 타겟 overlay 가 주입한 영역은 확장 출처 메타가 부여되어 확장 잠금 영역으로 표시된다(해당 overlay 자체의 편집 호스트는 base 레이아웃).

### partial 재사용은 전파되지 않는다 — 여러 화면이 쓰는 카드에는 슬롯을 쓴다

앞 절의 전파는 **`extends` 상속 체인**에만 해당한다. `partial` 로 마운트되는 공유 조각은
그 체인에 들어가지 않으므로, overlay 가 겨냥한 `target_id` 노드가 partial 안에 있으면
**그 partial 을 쓰는 다른 레이아웃에서는 주입이 일어나지 않는다.**

증상이 조용하다. 카드 자체는 정상으로 뜨고 주입 블록만 빠진 채 열리며, 매칭 실패는 오류도
경고도 콘솔 로그도 남기지 않는다. 두 화면을 나란히 놓고 보기 전에는 드러나지 않는다.

| 상황 | 방식 |
| --- | --- |
| 한 레이아웃(또는 그 상속 체인)에만 붙이는 주입 | overlay (`target_layout` + `target_id`) |
| **여러 레이아웃이 partial 로 공유하는 카드**에 붙이는 주입 | partial 안에 `type: extension_point` 슬롯을 두고 `extension_point` 로 주입 |

슬롯은 병합된 컴포넌트 트리를 직접 스캔하므로 그 카드가 서는 곳이면 어디든 함께 선다.
호스트 템플릿이 슬롯을 아직 두지 않았다면, 주입 대상을 늘리기 전에 그 partial 에 슬롯을
먼저 신설한다(내용을 복제해 화면마다 별도 주입 파일을 두면, 한쪽을 고칠 때 다른 쪽만 낡는다).

overlay 를 슬롯으로 **전환**할 때는 기설치본을 함께 본다. 확장 갱신은 등록과 "적용 불가"
정리만 하고 파일이 더 이상 선언하지 않는 대상의 행은 지우지 않으므로, 전환 뒤 구 overlay 행이
남아 원래 화면에 블록이 **두 번** 뜬다. 구행을 지우는 업그레이드 스텝을 함께 작성한다.

### position 옵션

| 값 | 설명 | 다이어그램 |
|-----|------|----------|
| `prepend` | 타겟 **앞에** 형제로 삽입 | `[NEW] [TARGET] [siblings...]` |
| `append` | 타겟 **뒤에** 형제로 삽입 | `[TARGET] [NEW] [siblings...]` |
| `prepend_child` | 타겟 children **맨 앞에** 삽입 | `TARGET { [NEW] [children...] }` |
| `append_child` | 타겟 children **맨 뒤에** 삽입 | `TARGET { [children...] [NEW] }` |
| `replace` | 타겟 **완전 교체** | `[NEW]` (기존 타겟 제거) |
| `inject_props` | 타겟 컴포넌트의 **props에 값 주입** | `TARGET.props ← injection.props` |

### inject_props 상세

`inject_props`는 기존 컴포넌트의 props에 값을 주입하는 특수 position입니다. `components` 필드 대신 `props` 필드를 사용합니다.

#### 병합 전략

| 전략 | 설명 | 예시 |
|------|------|------|
| `_append` | 배열 끝에 추가 | `tabs._append: [newTab]` → 기존 tabs 뒤에 추가 |
| `_prepend` | 배열 앞에 추가 | `tabs._prepend: [newTab]` → 기존 tabs 앞에 추가 |
| `_merge` | 객체 병합 (shallow) | `style._merge: {color: 'red'}` → 기존 style에 병합 |
| `_patch` | **목록 중 지목한 항목만** 교체 | `columns._patch: {by, items}` → 그 항목만 손대고 나머지는 보존 |
| (직접 값) | 스칼라 덮어쓰기 | `disabled: true` → 기존 값 대체 |

#### `_patch` — 목록에서 한 항목만 고치기

목록형 prop(`columns` · `tabs` 등)에서 **한 항목만** 바꿀 때 씁니다. 이것이 없으면 확장은
목록 전체를 복사해야 하는데, 그 복사본은 **만든 시점에 얼어붙습니다** — 호스트가 나중에
항목을 더해도 확장의 낡은 복사본이 그것을 덮어써 조용히 지웁니다.

```json
"columns": {
  "_patch": {
    "by": "field",
    "items": [{ "field": "payment_method", "width": "140px" }]
  }
}
```

- `by` 로 항목을 식별합니다. 값이 같은 항목에 **shallow 병합**되어 적지 않은 키는 보존됩니다.
- `by` 값이 기존에 없으면 목록 **끝에 추가**됩니다. 이때도 항목 안의 `_append`·중첩 `_patch` 는
  빈 대상에 적용한 결과로 풀려 들어갑니다(`{"_append": [a]}` → `[a]`) — 지시자 객체가 그대로 남지 않습니다.
- 항목 안 `_append`·`_patch` 의 대상이 표현식 문자열이면 덮어쓰지 않고 경고 로그만 남기고 건너뜁니다.
- 손대지 않은 항목은 그대로 남고 **순서와 개수도 보존**됩니다.
- 형식이 어긋나면(`by` 누락 등) **원본 목록을 그대로 둡니다.** 잘못 쓴 패치가 목록을 비우면
  그 화면이 통째로 사라지기 때문입니다.

##### 여러 확장이 같은 항목을 고칠 때 — `_append` 와 중첩 `_patch`

항목 안의 값을 그냥 적으면 **덮어쓰기**입니다. 그래서 확장 둘 이상이 같은 항목의 같은 키를
쓰면 **priority 가 가장 높은 하나만 남습니다.** 이때는 값을 `{"_append": [ … ]}` 로 적어
기존 배열 뒤에 덧붙이세요. 한 겹 더 들어가야 하면 값에 `_patch` 를 다시 씁니다.

```json
"columns": {
  "_patch": {
    "by": "field",
    "items": [{
      "field": "payment_method",
      "cellChildren": {
        "_patch": {
          "by": "id",
          "items": [{
            "id": "payment_method_cell",
            "children": { "_append": [ { "type": "basic", "name": "Span", "text": "…" } ] }
          }]
        }
      }
    }]
  }
}
```

> **호스트가 래퍼를 소유하게 하세요.** 확장이 각자 래퍼를 만들어 통째로 넣으면 서로 지웁니다.
> 호스트 레이아웃이 래퍼와 기본 내용을 갖고 확장은 그 안에 `_append` 만 하면, 확장이 몇 개든
> 공존합니다. 이때 확장이 지목하는 `id` 는 **공개 앵커**이므로 호스트가 함부로 바꾸면 안 됩니다.
>
> 실제로 결제 플러그인 3종이 주문목록 「결제수단」 칸에 각자 배지를 달았는데, 덮어쓰기 탓에
> 하나만 남아 그 하나가 나머지 몫까지 대신 그려 주는 우회가 생겼습니다. 그 우회는 남의
> 플러그인이 선언한 데이터소스를 참조해야 해서, 그 플러그인이 없는 사이트에서는 미정의
> 참조가 됐습니다.

#### inject_props 예시: 탭에 항목 추가

```html
<extension target-layout="admin_user_detail" priority="100">
  <inject target-id="user_detail_tabs" position="inject_props">
    <props>
      <prop name="tabs">
        <append>
          <item id="ext_verification" label="$t:admin.users.form.sections.verification_info" iconName="shield"/>
        </append>
      </prop>
    </props>
  </inject>
  <inject target-id="extension_tab_content" position="append_child">
    <div data-ref="ext_verification_content" data-if="{{(_global.activeUserDetailTab || query.tab || 'basic') === 'ext_verification'}}"/>
  </inject>
</extension>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "target_layout": "admin_user_detail",
  "injections": [
    {
      "target_id": "user_detail_tabs",
      "position": "inject_props",
      "props": {
        "tabs": {
          "_append": [
            {
              "id": "ext_verification",
              "label": "$t:admin.users.form.sections.verification_info",
              "iconName": "shield"
            }
          ]
        }
      }
    },
    {
      "target_id": "extension_tab_content",
      "position": "append_child",
      "components": [
        {
          "id": "ext_verification_content",
          "type": "basic",
          "name": "Div",
          "if": "{{(_global.activeUserDetailTab || query.tab || 'basic') === 'ext_verification'}}",
          "children": [...]
        }
      ]
    }
  ],
  "priority": 100
}
```

#### 주의사항

- `inject_props`일 때 `components` 필드는 무시됩니다
- 대상 prop이 표현식 문자열인 경우 `_append`/`_prepend`/`_merge`/`_patch` 적용이 불가하며, 경고 로그가 기록됩니다
- `_append`와 `_merge` 등 여러 전략을 하나의 prop에 혼합 사용할 수 없습니다 (첫 번째 매칭 전략 우선)
- 목록형 prop을 통째로 적어 넣지 마세요 — 그 복사본은 만든 시점에 얼어붙어, 호스트가 나중에
  더한 항목을 조용히 지웁니다. 한 항목만 고칠 때는 `_patch` 를 쓰세요
- 여러 확장이 같은 자리를 함께 쓸 수 있는 곳(셀 배지 등)이면 덮어쓰기 대신 `_append` 를 쓰고,
  래퍼는 호스트가 소유하게 하세요 (위 「여러 확장이 같은 항목을 고칠 때」 참고)

### 예시: 사용자 폼에 알림 설정 추가

```html
<extension target-layout="admin_user_form" priority="100">
  <inject target-id="section_marketing_consent" position="append">
    <div data-ref="section_notification_settings" class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-6 mb-6">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">$t:sirsoft-board.admin.users.form.sections.notification_settings</h3>
        <extension-badge type="module" identifier="sirsoft-board" installedModules="{{_global.installedModules}}"/>
      </div>
      <input type="checkbox" name="notify_post_complete" checked="{{_local.form?.notify_post_complete ?? false}}"/>
    </div>
  </inject>
</extension>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "target_layout": "admin_user_form",
  "injections": [
    {
      "target_id": "section_marketing_consent",
      "position": "append",
      "components": [
        {
          "id": "section_notification_settings",
          "type": "basic",
          "name": "Div",
          "props": {
            "className": "bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-6 mb-6"
          },
          "children": [
            {
              "type": "basic",
              "name": "Div",
              "props": {
                "className": "flex items-center justify-between mb-4"
              },
              "children": [
                {
                  "type": "basic",
                  "name": "H3",
                  "props": {
                    "className": "text-lg font-semibold text-gray-900 dark:text-white"
                  },
                  "text": "$t:sirsoft-board.admin.users.form.sections.notification_settings"
                },
                {
                  "type": "composite",
                  "name": "ExtensionBadge",
                  "props": {
                    "type": "module",
                    "identifier": "sirsoft-board",
                    "installedModules": "{{_global.installedModules}}"
                  }
                }
              ]
            },
            {
              "type": "basic",
              "name": "Input",
              "props": {
                "type": "checkbox",
                "name": "notify_post_complete",
                "checked": "{{_local.form?.notify_post_complete ?? false}}"
              }
            }
          ]
        }
      ]
    }
  ],
  "priority": 100
}
```

---

## Extension Point 확장

레이아웃에 미리 정의된 확장 포인트에 컴포넌트를 주입합니다.

### 지원 위치

Extension Point는 레이아웃의 다음 섹션에서 사용할 수 있습니다:

| 위치           | 지원 | 설명                                 |
|----------------|------|--------------------------------------|
| `components`   | ✅   | 메인 컴포넌트 트리 (기본)            |
| `modals`       | ✅   | 모달 내부 컴포넌트 트리 (v1.17.0+)   |
| 반복 렌더 템플릿 | ✅ | DataGrid·CardGrid 의 셀 템플릿(`props.columns[].cellChildren`·`props.cardColumns[].cellChildren`), 확장 행·하위 행 템플릿(`props.expandChildren`·`props.subRowChildren`) (7.1.0+) |

```text
v1.17.0 이전: modals 내부의 extension_point는 처리되지 않았음
✅ v1.17.0+: components와 modals 양쪽 모두 재귀적으로 extension_point 처리
✅ 7.1.0+: 셀·확장 행 템플릿(props 안에 실리는 트리) 안의 extension_point 도 처리
✅ engine-v1.73.0+: 프런트 반복 렌더러(renderItemChildren)가 셀 안 확장점을 행 컨텍스트로 그린다
   (호스트 <div> 규칙은 DynamicRenderer 와 공용 — 그 이전 엔진은 「컴포넌트를 찾을 수 없습니다」 로 버렸다)
```

#### 템플릿이 열어 두어야 하는 확장점

번들 모듈·플러그인의 조각은 아래 확장점에 붙는다. 템플릿을 직접 만들거나 고칠 때 이 자리를 지우면 그 조각은 등록 단계에서
빠지고 화면에는 오류 없이 그 표시만 사라진다. 이름은 바꾸지 않는다. 관리자 템플릿의 자리는
[admin-template-contract.md](admin-template-contract.md#조각-앵커) 에 따로 정리했다.

사용자 템플릿(기준 구현 `sirsoft-basic`):

| 확장점 | 놓는 곳 | 붙는 확장 | 빠지면 |
| --- | --- | --- | --- |
| `mypage_order_payment_provider_slot` (7.1.0+) | 내 주문·비회원 주문·신청서 상세의 결제 정보 카드 | 결제 플러그인 4종(KG이니시스·NHN KCP·나이스페이먼츠·토스페이먼츠) | 가상계좌 입금 안내(계좌·금액·기한)·매출전표·영수증 링크가 보이지 않는다 |
| `mypage_order_cash_receipt_slot` | 같은 결제 정보 카드 | 이커머스 | 현금영수증 카드가 보이지 않는다 |
| `withdraw_warnings` (7.1.0+) | 회원 탈퇴 확인 창의 경고 목록 | 이커머스 | 「환불로 받은 마일리지도 탈퇴하면 소멸」 경고가 빠진다 |
| `shop_checkout_cash_receipt_slot` | 주문서 결제 영역 | 이커머스 | 주문서에서 현금영수증을 신청할 수 없다 |
| `shop_checkout_extensions` | 주문서 화면(모달 자리) | 토스페이먼츠 | 결제 실패·취소 안내 창이 뜨지 않는다 |
| `address_search_slot` | 배송지·회원정보의 주소 입력 칸(5곳) | 다음 우편번호 | 주소 검색 단추가 없다 |
| `html_content` | 페이지 본문·약관·개인정보 창 | 편집기 플러그인(CKEditor5) | 편집기 본문 서식(`ck-content`)이 빠진 채 기본 HTML 로 보인다 |
| `user_global_overlay` | `_user_base` 끝 | GDPR | 쿠키 동의 배너가 뜨지 않는다 |

- 슬롯형 확장점은 `<extension-point name="…"><default/></extension-point>` 로 둔다. 주입이 없으면 아무것도 그리지 않는다.
- 모달 안의 목록(예: 탈퇴 경고)은 노드 id 를 겨냥하는 Overlay 가 닿지 않으므로 확장점으로 연다.
- 표시 분기(결제수단·PG·발급 상태 등)는 주입하는 쪽이 조건으로 처리한다 — 템플릿은 자리만 둔다.

#### 셀 템플릿 안의 확장점

목록의 한 칸에 확장이 표시를 덧붙여야 할 때(예: 주문 목록 결제수단 칸의 결제 플러그인 배지), 컬럼 배열을
`inject_props` 로 통째로 덮지 말고 호스트가 그 칸에 확장점을 연다. 컬럼 배열을 덮으면 확장이 컬럼 전체의
사본을 들고 있어야 하고, 호스트가 컬럼을 고치면 그 사본에 묻혀 화면에서 사라진다. 여러 확장이 같은 배열을
덮으면 우선순위가 가장 높은 하나만 남는다.

```html
<column field="payment_method" header="$t:sirsoft-ecommerce.admin.order.column.payment_method">
  <cell>
    <div class="stack-flush">
      <span>{{row.payment?.payment_method_label}}</span>
      <extension-point name="admin_order_list_payment_method_badges"/>
    </div>
  </cell>
</column>
```

- 셀 템플릿은 행마다 렌더되므로 주입 컴포넌트는 `row` 문맥을 그대로 읽는다(`{{my_map.data?.[row.order_number]}}`).
- 주입 조각의 `data_sources` 는 셀이 아니라 레이아웃에 한 번 병합된다 — 행마다 다시 부르지 않는다.
- 확장점 호스트는 빈 `<div>` 로 렌더된다. 주입이 없는 행에서도 자리를 차지하지 않도록 간격·구분선은
  호스트 셀이 아니라 주입 컴포넌트 쪽에 둔다.
- 여러 확장이 같은 칸에 붙으므로 주입 컴포넌트는 자기 행에서만 보이게 조건(`data-if`)을 건다.

### 레이아웃에서 Extension Point 정의

```html
<layout name="admin_user_form" version="1.0.0">
  <div data-ref="user_form_container">
    <extension-point name="user_form_additional_fields">
      <prop name="readOnlyFields">
        <item>zipcode</item>
        <item>address</item>
      </prop>
      <default/>
      <callback name="onAddressSelect">
        <set-state target="local" form.zipcode="{{$event.zipcode}}"/>
      </callback>
    </extension-point>
  </div>
</layout>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "layout_name": "admin_user_form",
  "components": [
    {
      "id": "user_form_container",
      "type": "basic",
      "name": "Div",
      "children": [
        {
          "type": "extension_point",
          "name": "user_form_additional_fields",
          "default": [],
          "props": {
            "readOnlyFields": ["zipcode", "address"]
          },
          "callbacks": {
            "onAddressSelect": { "handler": "setState", "params": { "target": "local", "form.zipcode": "{{$event.zipcode}}" } }
          }
        }
      ]
    }
  ]
}
```

#### Extension Point 데이터 전달 (engine-v1.28.0+)

| 필드 | 타입 | 설명 |
|------|------|------|
| `props` | object | 주입 컴포넌트에 전달할 데이터. 표현식 평가됨. 플러그인에서 `{{extensionPointProps.xxx}}`로 접근 |
| `callbacks` | object | 주입 컴포넌트에 전달할 액션 객체. 평가 없이 그대로 전달. 플러그인에서 `{{extensionPointCallbacks.xxx}}`로 접근 |

전달된 값은 주입 컴포넌트와 그 **자손 전체**에서 참조할 수 있습니다.

#### 검색 봇 화면에서의 동작

일반 화면과 봇용 화면(SEO)은 같은 레이아웃을 각각 렌더합니다. 확장 포인트 데이터 전달의 지원 범위는 두 화면이 다릅니다. 봇 화면의 `props` 해석은 **7.0.6** 부터 동작합니다 — 그 이전 버전에서는 확장이 교체한 영역이 봇에게 빈 요소로 나갔습니다.

| 항목 | 일반 화면 | 봇 화면 |
|------|----------|--------|
| `{{extensionPointProps.xxx}}` | 해석됨 | 해석됨 |
| `{{extensionPointCallbacks.xxx}}` | 해석됨 | **해석되지 않음** |

봇 화면에는 액션 실행기가 없으므로 콜백은 의도적으로 전달하지 않습니다. **검색 결과에 노출되어야 할 내용을 콜백 경유로 만들지 마세요** — 봇에게는 빈 값이 됩니다. 본문·제목처럼 색인되어야 하는 값은 `props` 로 전달합니다.

사용자 작성 콘텐츠를 넘기는 확장 포인트라면 콘텐츠와 함께 **평문/HTML 판정 값(`isHtml`)도 전달**하세요. 두 화면 모두 이 값에 따라 평문으로 이스케이프하거나 위험 요소를 제거한 뒤 출력합니다. 판정 값을 넘기지 않으면 HTML 로 간주됩니다.

봇 화면의 노드 문법 지원 범위 전체는 [seo-system.md "SEO 렌더러 지원 노드 키"](../backend/seo-system.md)를, 정화 규칙은 같은 문서의 "봇 화면의 HTML 정화" 를 참조하세요.

### 모듈에서 Extension Point에 주입

```html
<extension extension-point="user_form_additional_fields" mode="replace" priority="100">
  <div data-ref="custom_field_section"/>
</extension>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "extension_point": "user_form_additional_fields",
  "mode": "replace",
  "components": [
    {
      "id": "custom_field_section",
      "type": "basic",
      "name": "Div",
      "children": [...]
    }
  ],
  "data_sources": [...],
  "priority": 100
}
```

### JSON 스키마

| 필드 | 필수 | 타입 | 설명 |
|------|------|------|------|
| `extension_point` | ✅ | string | 확장 포인트 이름 |
| `mode` | ❌ | string | 주입 모드 (아래 참조, 기본값: `append`) |
| `components` | ❌ | array | 주입할 컴포넌트 배열 |
| `data_sources` | ❌ | array | 추가 데이터 소스 |
| `modals` | ❌ | array | 호스트 레이아웃에 병합할 모달 배열 |
| `scripts` | ❌ | array | 추가 스크립트 |
| `priority` | ❌ | number | 우선순위 (기본값: 100) |

### mode 옵션

| 값 | 설명 | 동작 |
| ----- | ------ | ------ |
| `append` | default **뒤에** 추가 (기본값) | `[default...] [NEW]` |
| `prepend` | default **앞에** 추가 | `[NEW] [default...]` |
| `replace` | default **완전 교체** | `[NEW]` (default 제거) |

### 예시: 관리자 회원 목록 필터에 행 추가

관리자 템플릿의 회원 목록은 상세검색 아래에 `admin_user_list_filters` 확장점을 연다. 이 자리에 붙은 행은
「검색편집」의 기본/상세 이동 대상이 아니고(코어가 모르는 키라 `visibleFilters` 순서에 넣을 수 없다),
상세검색을 펼쳤을 때만 보인다.

```html
<extension extension-point="admin_user_list_filters" mode="append" priority="200">
  <div data-ref="ext_vendor_point_filter_row" class="flex flex-col gap-2 md:flex-row md:items-center">
    <permissions>
      <permission>vendor-module.points.read</permission>
    </permissions>
    <span class="block mb-2 w-32 text-sm text-gray-600 dark:text-gray-400 md:mb-0 shrink-0">$t:vendor-module.admin.user_filter.points</span>
    <input type="number" name="vendorPointMin" class="input w-32" value="{{((_local.userFilterExtraQuery || []).find(e => e.startsWith('vendor-module.point_min=')) || '').slice('vendor-module.point_min='.length)}}">
      <on type="change">
        <!-- 값은 URL 에 직접 싣지 않는다 — 이 모듈의 핸들러가 확장 값 배열을 갱신하고, 확장점을 연 관리자 템플릿의 검색 버튼이 ext_filters[] 로 보낸다. -->
        <sequence>
          <action handler="vendor-module.setUserFilterExt" key="vendor-module.point_min" value="{{$event.target.value}}" current="{{_local.userFilterExtraQuery || []}}"/>
          <set-state target="_local" userFilterExtraQuery="{{$prev}}"/>
        </sequence>
      </on>
    </input>
  </div>
</extension>
```

- 행은 평범한 유틸 클래스로 편다. 관리자 템플릿의 필터 행 클래스(`filter-row`·`filter-label`)는 그 템플릿 CSS 에만 있어, 다른 관리자 템플릿에 주입되면 행 배치가 무너진다.
- 값 조립 핸들러는 모듈이 자기 네임스페이스로 등록한다(`vendor-module.setUserFilterExt` — 같은 키 교체, 빈 값 제거, `{확장id}.{키}={값}` 원소). 관리자 템플릿의 `setUserFilterExt` 는 그 템플릿 화면용이라 다른 관리자 템플릿에는 없다 — 조각이 부르면 그 템플릿에서 값이 검색에 실리지 않는다. 구현 예: `modules/_bundled/sirsoft-ecommerce/resources/js/handlers/userListFilterExtHandlers.ts`.
- 표시값은 같은 배열(`_local.userFilterExtraQuery`)에서 되읽는다 — 새로고침·뒤로가기에서 URL 의 `ext_filters[]` 로 복원된다.
- 코어의 필터 초기화는 이 배열을 비운다.
- 서버 쪽 검증·조건은 [훅 문서의 관리자 회원 목록 확장 필터 훅](hooks.md#관리자-회원-목록-확장-필터-훅) 을 본다.

### 확장 병합 칸 (주입 폼의 값을 템플릿 API 요청에 실어 보내기)

슬롯에 주입한 폼이 입력값을 서버로 보내려면, 그 값이 **템플릿이 소유한 apiCall 의 `params.body`** 에 도달해야 한다. 그런데 확장에는 body 에 키를 추가할 수단이 없다.

- 결제 버튼처럼 `id` 가 없는 노드는 overlay `target_id` 로 잡을 수 없다
- `inject_props` 는 컴포넌트 props 병합 전용이라 `actions[].params.body` 에 닿지 않는다 (`_merge` 는 shallow)

그래서 템플릿이 **확장 병합 칸**을 하나 열어 둔다. 규약은 이렇다.

**템플릿 쪽** — body 를 통짜 표현식으로 만들고 말미에 확장 칸을 spread 한다.

```json
{
  "handler": "apiCall",
  "target": "/api/modules/sirsoft-ecommerce/user/orders",
  "params": {
    "body": "{{ ({ temp_order_id: _local.tempOrderId, payment_method: _computed.selectedPaymentMethod, ...(_local.checkoutExtraPayload ?? {}) }) }}"
  }
}
```

**확장 쪽** — 자기 필드를 그 칸에 setState 한다.

```html
<on type="change">
  <set-state target="local" checkoutExtraPayload.cash_receipt_requested="{{$event.target.checked}}"/>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "change",
  "handler": "setState",
  "params": {
    "target": "local",
    "checkoutExtraPayload.cash_receipt_requested": "{{$event.target.checked}}"
  }
}
```

지켜야 할 것:

- 확장은 **자기 도메인 키만** 쓴다. spread 가 뒤에 오므로 템플릿의 기존 키를 덮어쓸 수 있다
- 폼을 접거나 신청을 해제하면 칸에서 **자기 키를 지운다.** 남겨 두면 서버가 신청으로 오인한다. `setState` 기본은 깊은 병합이라
  `{}` 를 넣으면 아무 키도 지워지지 않는다 — `merge: "shallow"` 로 최상위 칸을 바꾸되, 값은 기존 칸에서 자기 접두 키만 걸러 낸
  객체로 만든다(다른 확장의 키 보존). 예: `Object.fromEntries(Object.entries(_local.checkoutExtraPayload ?? {}).filter(e => !e[0].startsWith('cash_receipt_')))`
- 칸의 주인은 호스트다 — 호스트는 자기 사정으로 칸을 통째로 갈아끼울 수 있다(결제수단 전환 등). 그러니 확장의 **표시 상태를 자기 스크래치 키만으로 파생하지 않는다.** 호스트가 칸을 비워도 그 키는 남아 있어, 화면은 신청 켜짐인데 제출 내용은 비어 있는 상태가 만들어진다. 표시·렌더 게이트는 자기 키와 **칸의 값을 함께** 보고 판정하고, 다시 신청할 때의 기본값도 스크래치가 아니라 칸에서 읽는다
- 표시 게이트와 입력 영역 게이트가 나뉘어 있으면 **두 판정식이 같은지** 테스트로 고정한다. 한쪽만 열리면 빈 칸이 뜨거나 보이지 않는 값이 제출된다
- 템플릿이 소유한 필드(예: 환불계좌)는 칸을 경유하지 않고 body 에 직접 기재한다
- body 를 통짜 표현식으로 전환할 때는 **전환 전후 산출값이 동일한지** 회귀 테스트로 고정한다 (`DataBindingEngine.evaluateExpression` 으로 실제 평가)

현재 열려 있는 칸:

| 칸 이름 | 위치 | 소비처 |
|---------|------|--------|
| `_local.checkoutExtraPayload` | `sirsoft-basic` 주문서 (`_checkout_summary.g7.html`) | 주문 생성 `POST /user/orders` |

---

## 템플릿 오버라이드

템플릿은 모듈/플러그인의 확장을 오버라이드하여 커스터마이징할 수 있습니다.

### 디렉토리 구조

```
templates/_bundled/sirsoft-basic/
└── extensions/
    └── sirsoft-daum_postcode/            ← 오버라이드 대상 확장
        └── user-address-search.g7.html   ← 오버라이드 파일 (소스 SSoT)
```

### 오버라이드 매칭 규칙

오버라이드는 **파일명으로 짝지어지지 않습니다**. `registerTemplateOverride()` 가 쓰는 키는 다음 두 가지입니다.

| 키 | 출처 |
| --- | --- |
| `override_target` | 상위 **디렉토리명** (= 대상 확장 식별자) |
| `target_name` | 파일 내용의 `extension_point` 또는 `target_layout` |

파일명은 가독성 관례일 뿐이라 원본과 달라도 됩니다. 소스 SSoT 는 `.g7.html`, 문서 루트는 원본과 같은 `<extension>` 입니다.

```
확장 원본:      plugins/_bundled/sirsoft-daum_postcode/resources/extensions/ecommerce-address-search.g7.html
                (extension_point: address_search_slot, priority 100)
템플릿 오버라이드: templates/_bundled/sirsoft-basic/extensions/sirsoft-daum_postcode/user-address-search.g7.html
                (같은 extension_point, priority 50 — 파일명은 다름)
```

### 오버라이드 우선순위

```
1. 템플릿 오버라이드 (source_type = Template)  ← 가장 높은 우선순위
2. 플러그인 확장 (source_type = Plugin)
3. 모듈 확장 (source_type = Module)            ← 가장 낮은 우선순위
```

### 오버라이드 예시

모듈의 기본 확장을 템플릿에서 스타일 변경:

```html
<extension target-layout="admin_user_form" priority="100">
  <inject target-id="section_marketing_consent" position="append">
    <div data-ref="section_notification_settings" class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 rounded-lg p-6 mb-6"/>
  </inject>
</extension>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "target_layout": "admin_user_form",
  "injections": [
    {
      "target_id": "section_marketing_consent",
      "position": "append",
      "components": [
        {
          "id": "section_notification_settings",
          "type": "basic",
          "name": "Div",
          "props": {
            "className": "bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 rounded-lg p-6 mb-6"
          },
          "children": [...]
        }
      ]
    }
  ],
  "priority": 100
}
```

---

## 모듈 활성화/비활성화 동작

### 활성화 시

1. `resources/extensions/` 스캔 — `.g7.html` 소스 우선(등록 시점 메모리 컴파일), 짝 마크업이 없는 `.json` 은 직접 읽음
2. **템플릿과 무관하게 컴파일** — 이름은 태그 표기 규칙, 종류는 `data-type` 으로 정해지므로 어느 템플릿에 붙든 산출이 같다(JSON 조각과 같음). 그 템플릿에 해당 컴포넌트가 있는지는 렌더러가 판정한다(없으면 그리지 않는다)
3. **대상 템플릿 적용 가능 여부 판별** (아래 "템플릿 대상 판정" 참조)
4. `template_layout_extensions` 테이블에 등록 (또는 기존 레코드 복원) — 행의 고유 키는 대상 5축 + **`fragment_key`**
5. 활성 확장 전부의 조각을 모든 활성 템플릿에 **다시 등록**(멱등) — 이 확장이 가져온 호스트 레이아웃을 겨냥하던 다른 확장의 조각이 이제 붙는다
6. 레이아웃 캐시 무효화

### 조각 식별자 `fragment_key`

`fragment_key` 는 조각 소스 파일의 기본 이름에서 `.g7.html`/`.json` 을 뗀 문자열이다(`admin-user-currency-field.g7.html` → `admin-user-currency-field`). 같은 확장이 **같은 대상에 조각을 둘** 보낼 수 있으므로(예: 회원 폼에 통화 필드와 배송 국가 필드) DB 행은 대상만으로 갈리지 않고, 이 키가 행을 가른다. 짝(`x.g7.html`·`x.json`)은 같은 논리 조각이라 같은 키다. 관리자 레이아웃 편집기의 확장 목록은 대상 이름 아래 이 키를 작게 표시해 같은 대상의 두 행을 구분한다.

확장이 `LayoutExtensionService::registerExtension()` 을 직접 부르는 자리(업데이트 뒤 조각을 복원하는 Listener 등)는 여섯 번째 인자로 이 키를 넘긴다. 넘기지 않으면 같은 대상의 기존 행이 하나일 때 그 키를 잇고, 둘 이상이면 어느 조각인지 알 수 없어 처리하지 않는다(경고 로그).

#### 7.0 에서 올라온 조각 행

7.0.x 의 행은 대상 단위로 하나였고, 이 키를 더할 때 기존 행은 대상 이름으로 채워졌다. 코어 7.1.0 업데이트는 그 행을 소스 파일 키로 옮긴다 — 같은 대상을 겨냥한 파일이 하나면 그 파일 키로, 여럿이면 등록 당시 원본 해시가 같은 파일의 키로 옮긴다. 이미 파일 키 행이 있으면 운영자가 고친 쪽을 남긴다. 템플릿 오버라이드(`templates/{식별자}/extensions/{대상}/`) 행도 같은 규칙이다. 어느 조각이었는지 정할 수 없는 고친 행은 손대지 않고 업데이트 기록에 경고로 남기므로, 운영자가 레이아웃 편집기에서 확인해 정리한다.

#### 편집기에서 연 7.0 조각 행

7.0 은 조각을 모든 활성 템플릿에 등록해, 그 템플릿의 컴포넌트 목록에 없는 컴포넌트를 쓴 행이 남아 있을 수 있다. 레이아웃 편집기는 그런 행을 저장할 수 있도록 **저장본에 이미 있던** 목록 밖 템플릿 컴포넌트를 그 개수까지 허용하고 경고(`template_component_kept`)로 알린다. 새로 적은 목록 밖 컴포넌트는 종전대로 오류(`component_not_in_registry`)다. 그런 행이 붙을 자리가 없는 템플릿에 있으면 다음 동기화가 정리한다(아래 「템플릿 대상 판정」).

### 템플릿 대상 판정

모듈/플러그인 확장은 admin·user 등 모든 활성 템플릿에 동기화되지만, 확장이 **대상으로 하는 레이아웃이 실제 존재하는 템플릿에만 등록**된다. 확장 활성화·템플릿 활성화·확장 갱신 세 경로가 모두 같은 판정을 거친다.

- **Overlay**: `target_layout` 이 그 템플릿에 레이아웃으로 존재해야 한다. 모듈 레이아웃은 `{모듈}.{layout_name}` 으로 저장되므로 논리 이름(`layout_name`)으로 대조한다.
- **Extension Point**: `extension_point`(확장점명)이 그 템플릿의 어느 레이아웃 `content` 에 `type: extension_point` 노드로 정의되어 있어야 한다.

판정은 `LayoutExtensionService::isExtensionApplicableToTemplate()` 이 수행한다. 대상이 없는 템플릿(예: admin 레이아웃 대상 확장 ↔ user 템플릿)에는 등록하지 않으며, 과거 무차별 등록으로 잘못 생성된 행은 다음 동기화 시 `removeInapplicableExtension()` 으로 정리(soft delete)되고, 소스 디렉토리에서 사라진 조각의 행도 같은 동기화가 정리한다. 호스트 레이아웃이 나중에 설치되는 경우는 확장 활성화·업데이트가 활성 확장 전부의 조각을 전 템플릿에 다시 등록해 메운다.

설치본 전량의 정합성은 `php artisan layout:check-installed` 로 점검한다 — 행마다 역변환→프로덕션 컴파일→왕복 대조에 더해 부적합 등록·소실·고아 조각을 보고하고, 하나라도 있으면 종료 코드 1 이다.

### 비활성화 시

1. 해당 모듈의 확장 레코드 Soft Delete
2. **렌더링 시 비활성화된 모듈의 확장 자동 스킵**
3. 레이아웃 캐시 무효화

### 템플릿 오버라이드와 모듈 비활성화

**중요**: 모듈이 비활성화되면, 해당 모듈을 오버라이드하는 템플릿 확장도 함께 숨김 처리됩니다.

```
sirsoft-board 모듈 비활성화
    ↓
sirsoft-board의 모든 확장 숨김
    ↓
sirsoft-board를 오버라이드하는 템플릿 확장도 숨김
```

---

## 우선순위

### priority 필드

- 기본값: 100
- 낮을수록 먼저 렌더링
- 같은 위치에 여러 확장이 있을 경우 순서 결정

```html
<extension target-layout="admin_user_form" priority="50">
  <inject target-id="target_node" position="append_child">…</inject>
</extension>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "target_layout": "admin_user_form",
  "priority": 50,
  "injections": [...]
}
```

### 우선순위 가이드라인

| 범위 | 용도 | 예시 |
|------|------|------|
| 1-25 | 필수 정보 | 보안 경고, 중요 알림 |
| 50-75 | 주요 기능 | 핵심 모듈 UI |
| 100 | 기본값 | 일반 확장 |
| 150+ | 보조 정보 | 분석, 통계 |

---

## 데이터 소스 병합

확장에서 정의한 `data_sources`는 호스트 레이아웃의 데이터 소스에 병합됩니다.

```html
<extension target-layout="admin_user_form">
  <data-source id="notification_settings" type="api" method="GET" endpoint="/api/modules/sirsoft-board/admin/users/{{route.id}}/notification-settings" :auto_fetch="true" :auth_required="true"/>
</extension>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "target_layout": "admin_user_form",
  "injections": [...],
  "data_sources": [
    {
      "id": "notification_settings",
      "type": "api",
      "method": "GET",
      "endpoint": "/api/modules/sirsoft-board/admin/users/{{route.id}}/notification-settings",
      "auto_fetch": true,
      "auth_required": true
    }
  ]
}
```

---

## 모달 병합

Extension Point 확장에서 정의한 `modals`는 호스트 레이아웃의 `modals` 배열에 자동 병합됩니다.

### 왜 modals 섹션을 사용해야 하는가?

Extension Point의 children으로 주입된 인라인 Modal(`show` prop 기반)은 정상 동작하지 않습니다. 그누보드7 Modal 시스템에서 안정적으로 동작하려면 `modals` 섹션에 등록하고 `openModal`/`closeModal` 핸들러로 제어해야 합니다.

> 상세 모달 규칙: [modal-usage.md](../frontend/modal-usage.md)

### Extension JSON에서 modals 정의

```html
<extension extension-point="shop_checkout_extensions" priority="100">
  <modal>
    <modal data-ref="payment_error_modal" title="$t:plugin.payment_error_title" size="small"/>
  </modal>
</extension>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "extension_point": "shop_checkout_extensions",
  "modals": [
    {
      "id": "payment_error_modal",
      "type": "composite",
      "name": "Modal",
      "props": {
        "title": "$t:plugin.payment_error_title",
        "size": "small"
      },
      "children": [...]
    }
  ],
  "priority": 100
}
```

### 핸들러에서 모달 열기

Extension으로 병합된 모달은 커스텀 핸들러에서 `G7Core.modal.open()`으로 열 수 있습니다:

```typescript
const G7Core = (window as any).G7Core;
G7Core?.modal?.open?.('payment_error_modal');
```

또는 레이아웃 JSON 액션에서:

```html
<on type="click">
  <open-modal target="payment_error_modal"/>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "click",
  "handler": "openModal",
  "target": "payment_error_modal"
}
```

### 병합 동작

- 여러 확장의 `modals`가 하나의 호스트 레이아웃에 모두 병합됩니다
- 호스트 레이아웃에 기존 `modals`가 있으면 확장 모달이 뒤에 추가됩니다
- 확장에 `modals`가 없으면 호스트 레이아웃에 영향 없음 (하위 호환)

### 주의사항

```text
modals 섹션 모달에 show prop 사용 금지 (무시됨)
모달 ID는 호스트 레이아웃의 기존 모달과 중복되지 않도록 플러그인 prefix 사용 권장
✅ 모달 닫기 시 setState → closeModal 순서 유지 (순서 중요)
```

---

## Overlay 레이아웃 섹션 병합

Overlay 확장에서 `computed`, `state`, `modals` 섹션을 정의하면 호스트 레이아웃에 자동 병합됩니다.

### 지원 섹션

| 섹션 | Extension Point | Overlay | 설명 |
|------|:---:|:---:|------|
| `data_sources` | ✅ | ✅ | API 데이터 소스 추가 |
| `scripts` | ✅ | ✅ | 커스텀 핸들러 스크립트 추가 |
| `modals` | ✅ | ✅ | 모달 컴포넌트 추가 |
| `computed` | ✅ | ✅ | 계산된 값 추가 |
| `state` | ✅ | ✅ | 초기 상태 추가 |

### 예시: Overlay에서 computed와 state 추가

```html
<extension target-layout="admin_user_detail" priority="100">
  <modal>
    <modal data-ref="ext_verification_modal" title="인증 이력"/>
  </modal>
</extension>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "target_layout": "admin_user_detail",
  "injections": [...],
  "computed": {
    "verificationStatus": "{{user?.data?.identity_verified ? 'verified' : 'pending'}}"
  },
  "initLocal": {
    "showVerificationHistory": false
  },
  "modals": [
    {
      "id": "ext_verification_modal",
      "type": "composite",
      "name": "Modal",
      "props": { "title": "인증 이력" },
      "children": [...]
    }
  ],
  "priority": 100
}
```

### 병합 동작

- 여러 overlay의 같은 섹션은 priority 오름차순으로 순차 병합됩니다
- 동일 키 충돌 시 후순위 overlay가 덮어씁니다
- 해당 섹션이 없는 overlay는 기존 레이아웃에 영향 없음 (하위 호환)

---

## 레이아웃 오버라이드 vs 확장 오버라이드

| 구분 | 레이아웃 오버라이드 | 확장 오버라이드 |
|------|-------------------|---------------|
| 대상 | 모듈이 등록한 완전한 레이아웃 | 확장 지점/Overlay로 주입된 UI |
| 위치 | `templates/{t}/layouts/overrides/{module}/*.g7.html` | `templates/{t}/extensions/{module}/*.g7.html` |
| 문서 루트 | `<override>` | `<extension>` |
| DB 테이블 | `template_layouts` | `template_layout_extensions` |
| 해석 서비스 | `LayoutResolverService` | `LayoutExtensionService` |
| version_constraint | 지원 | 지원 |

---

## 레이아웃 확장 편집 및 버전 관리

관리자는 모듈/플러그인이 주입한 레이아웃 확장을 일반 레이아웃과 동등한 수준으로 편집·버전관리·복구·미리보기할 수 있다.

### 관리자 편집

레이아웃 편집 화면(`admin_template_layout_edit`)의 좌측 트리에서 확장 항목을 선택하면 해당 확장의 `content` JSON 을 편집할 수 있다. 편집 권한은 일반 레이아웃과 동일한 `core.templates.layouts.edit` 를 사용한다.

확장 편집 API 는 `{extensionId}` 정수 PK 로 식별한다 — 동일 `target_name` 에 여러 source 의 확장이 존재할 수 있기 때문이다.

#### 좌측 트리는 오버라이드 해석 결과만 노출한다

편집 화면 좌측 트리(`GET .../layout-extensions`)는 화면 렌더링 경로와 동일한 오버라이드 해석을 적용한다. 동일 `(extension_type, target_name)` 범위에서 템플릿 오버라이드(`source_type = Template`)가 특정 모듈/플러그인을 오버라이드하면, 그 모듈/플러그인의 원본 확장은 화면에 적용되지 않으므로 트리에서도 제외된다.

이는 관리자가 "저장은 되지만 화면에 반영되지 않는" 가려진 확장 행을 편집하는 것을 막기 위함이다. 트리에는 항상 해당 템플릿에서 실제로 적용되는 확장(템플릿 오버라이드 + 오버라이드되지 않은 모듈/플러그인 확장)만 표시된다.

해석 로직은 `LayoutExtensionRepository::resolveOverrides()` 단일 출처를 사용하며, 렌더링 경로(`getResolvedExtensionPoints` / `getResolvedOverlays`)와 편집 트리(`getResolvedByTemplateId`)가 동일한 가시성 기준을 공유한다.

| 메서드 | 라우트 |
| ------ | ------ |
| `GET` | `/api/admin/templates/{templateName}/layout-extensions` (출처별 그룹핑) |
| `GET` | `.../layout-extensions/{extensionId}` |
| `PUT` | `.../layout-extensions/{extensionId}` |
| `GET` | `.../layout-extensions/{extensionId}/versions` |
| `GET` | `.../layout-extensions/{extensionId}/versions/{version}` |
| `POST` | `.../layout-extensions/{extensionId}/versions/{versionId}/restore` |
| `POST` | `.../layout-extensions/{extensionId}/preview` |

### 버전 이력

확장을 편집·저장하면 `template_layout_extension_versions` 테이블에 버전 스냅샷이 쌓인다. 일반 레이아웃의 `template_layout_versions` 와 동일한 구조이며, 이전 버전으로 복구할 수 있다. 버전 diff 계산 로직은 `App\Repositories\Concerns\CalculatesJsonContentDiff` trait 으로 일반 레이아웃과 공유한다.

### 사용자 수정 감지 (`original_content_hash`)

`template_layout_extensions` 의 `original_content`(원본 본문 — `keep` 병합의 기준, API 응답에서 숨김) / `original_content_hash` / `original_content_size` 컬럼은 모듈/플러그인이 등록한 원본 확장 콘텐츠의 정규화 해시를 보존한다. 관리자가 확장을 편집해도 이 원본 해시는 변경되지 않는다 — 현재 `content` 해시와 원본 해시를 비교하여 "사용자가 수정한 확장"을 판별한다.

### 모듈/플러그인 업데이트 시 충돌 전략

`module:update` / `plugin:update` 의 `--layout-strategy` 옵션이 확장에도 적용된다 (일반 레이아웃과 동일한 수준):

- `overwrite` (기본): 파일 내용으로 확장 `content` 를 무조건 덮어쓴다. 관리자가 편집한 확장이 있으면 업데이트 전 경고를 출력한다.
- `keep`: 관리자가 편집한 확장 조각에도 일반 레이아웃과 같은 3자 병합을 쓴다 — 기준은 `original_content`(등록 당시 원본), 고친 부분은 운영자 것, 손대지 않은 부분은 새 버전, 겹치면 운영자 것 유지. 결과는 업데이트 보고의 `kind: extension` 행으로 남는다(규칙: [extension-update-system.md §10](extension-update-system.md#10-레이아웃-충돌-전략-3종-공통)).
- 다른 모듈·플러그인을 켜거나 끄면 확장 조각이 다시 등록되는데, 이때도 `keep` 병합을 써서 **관리자가 고친 조각이 원본으로 돌아가지 않는다**.

### 미리보기

확장 편집 중 미리보기는 편집 중인 `content` 를 대표 레이아웃에 임시 적용하여 렌더링한다.

- `overlay` 타입: `target_layout` 자체가 대표 레이아웃이므로 별도 선택이 불필요하다.
- `extension_point` 타입: 하나의 확장점이 여러 레이아웃에서 사용될 수 있으므로, 미리볼 대표 레이아웃을 선택하는 모달이 노출된다.

미리보기 URL 은 일반 레이아웃과 동일한 `/api/layouts/preview/{token}.json` 을 재사용하며, `preview_type` 컬럼으로 일반/확장 미리보기를 분기한다.

---

## 관련 파일

### 백엔드

| 파일 | 설명 |
|------|------|
| `app/Services/LayoutExtensionService.php` | 확장 적용·편집·버전관리 핵심 서비스 |
| `app/Models/LayoutExtension.php` | 확장 모델 (DB 테이블) |
| `app/Models/TemplateLayoutExtensionVersion.php` | 확장 버전 이력 모델 |
| `app/Repositories/LayoutExtensionRepository.php` | 확장 Repository |
| `app/Repositories/LayoutExtensionVersionRepository.php` | 확장 버전 Repository |
| `app/Repositories/Concerns/CalculatesJsonContentDiff.php` | JSON diff 계산 trait (일반 레이아웃과 공유) |
| `app/Http/Controllers/Api/Admin/LayoutExtensionController.php` | 확장 편집/버전관리 admin 컨트롤러 |
| `app/Enums/LayoutExtensionType.php` | 확장 타입 Enum (ExtensionPoint, Overlay) |
| `app/Enums/LayoutSourceType.php` | 출처 타입 Enum (Module, Plugin, Template) |

### 데이터베이스

| 테이블 | 설명 |
|--------|------|
| `template_layout_extensions` | 확장 등록 정보 저장 |

### 주요 컬럼

```
template_layout_extensions
├── template_id            # 템플릿 ID
├── extension_type         # ExtensionPoint | Overlay
├── target_name            # 확장 포인트명 또는 대상 레이아웃명
├── source_type            # Module | Plugin | Template
├── source_identifier      # 모듈/플러그인/템플릿 식별자
├── override_target        # 오버라이드 대상 (템플릿 오버라이드 시)
├── content                # 확장 JSON 내용
├── original_content_hash  # 원본 확장 콘텐츠 SHA-256 해시 (사용자 수정 감지용)
├── original_content_size  # 원본 확장 콘텐츠 바이트 크기
├── priority               # 우선순위
├── is_active              # 활성 상태
└── deleted_at             # Soft Delete

template_layout_extension_versions
├── extension_id     # 레이아웃 확장 ID (FK, cascadeOnDelete)
├── version          # 버전 번호 (자동 증가)
├── content          # 확장 정의 JSON 스냅샷
├── changes_summary  # 변경 요약 JSON (added/removed/modified/char_diff)
└── created_by       # 저장자 ID
```

---

## 문제 해결

### 확장이 표시되지 않음

**확인 사항**:
1. 모듈/플러그인이 활성화되어 있는지 확인
2. `target_layout` 또는 `extension_point` 이름이 정확한지 확인
3. `target_id`가 대상 레이아웃에 존재하는지 확인
4. JSON 문법 오류 확인

**캐시 초기화**:
```bash
php artisan cache:clear
```

### 오버라이드가 적용되지 않음

**확인 사항**:
1. 템플릿 오버라이드 파일 경로 확인
   - `templates/{template}/extensions/{module-identifier}/{filename}.{g7.html,json}`
2. 디렉토리명이 대상 확장 식별자와 일치하는지, 내용의 `extension_point`/`target_layout` 이 원본과 같은지 확인
3. `template_layout_extensions` 테이블에서 `source_type = 'template'` 레코드 확인

### 모듈 비활성화 후에도 확장 표시됨

**원인**: 캐시 문제

**해결**:
```bash
php artisan cache:clear
```

### 여러 확장의 순서가 예상과 다름

**해결**: `priority` 값 조정 (낮을수록 먼저 렌더링)

---

## 관련 문서

- [module-layouts.md](module-layouts.md) - 모듈 레이아웃 등록
- [hooks.md](hooks.md) - 훅 시스템
- [layout-json.md](../frontend/layout-json.md) - 레이아웃 JSON 스키마
- [components.md](../frontend/components.md) - 컴포넌트 개발 규칙
