# 데이터 바인딩 - 다국어 처리

> **버전**: engine-v1.3.0+
> **관련 문서**: [data-binding.md](data-binding.md) | [g7core-api.md](g7core-api.md) | [layout-json.md](layout-json.md)

---

## 목차

1. [$localized() 헬퍼 함수 (engine-v1.4.0+)](#localized-헬퍼-함수-v140)
2. [다국어 처리 기본](#다국어-처리-기본)
3. [지연 번역 ($t:defer:)](#지연-번역-tdefer---반복-컨텍스트용-v130)
4. [모듈/플러그인 다국어 병합](#모듈플러그인-다국어-병합)
5. [다국어 파일 분할 ($partial)](#다국어-파일-분할-partial-v150)
6. [컴포넌트 다국어 처리](#컴포넌트-다국어-처리)
7. [JavaScript에서 G7Core.t() 사용](#javascript에서-g7coret-사용)
8. [정본 화면을 복제할 때의 라벨 키](#정본-화면을-복제할-때의-라벨-키)

---

## $localized() 헬퍼 함수 (engine-v1.4.0+)

API에서 반환되는 다국어 객체(예: `{ ko: "한국어", en: "English" }`)를 현재 로케일에 맞게 자동으로 변환하는 헬퍼 함수입니다.

### 문제 상황

API에서 다국어 필드가 문자열 또는 객체로 반환될 수 있습니다:

```typescript
// 문자열인 경우
{ name: "샘플 모듈" }

// 객체인 경우
{ name: { ko: "샘플 모듈", en: "Sample Module" } }
```

기존에는 이를 처리하기 위해 복잡한 표현식이 필요했습니다:

```html
<div>{{typeof _global.selectedModule.name === 'string' ? _global.selectedModule.name : _global.selectedModule.name[$locale]}}</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
// 기존 방식 (129자)
"text": "{{typeof _global.selectedModule.name === 'string' ? _global.selectedModule.name : _global.selectedModule.name[$locale]}}"
```

### $localized() 사용법

```html
<div>{{$localized(_global.selectedModule.name)}}</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
// 새 방식 (45자)
"text": "{{$localized(_global.selectedModule.name)}}"
```

### 로케일 폴백 체인

`$localized()` 함수는 다음 우선순위로 값을 반환합니다:

1. 현재 로케일 (`$locale`) 값
2. 한국어 (`ko`) 값
3. 영어 (`en`) 값
4. 객체의 첫 번째 값
5. 빈 문자열 (값이 없는 경우)

### 지원 입력 타입

| 입력 타입 | 반환 값 | 예시 |
|----------|---------|------|
| `null` / `undefined` | `''` (빈 문자열) | `$localized(null)` → `''` |
| 문자열 | 문자열 그대로 | `$localized("Hello")` → `"Hello"` |
| 다국어 객체 | 로케일에 맞는 값 | `$localized({ko: "안녕", en: "Hello"})` → `"안녕"` (ko 로케일) |
| 기타 | 문자열 변환 | `$localized(123)` → `"123"` |

### 사용 예시

**기본 사용**:

```html
<p>{{$localized(_global.selectedModule.name)}}</p>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "basic",
  "name": "P",
  "text": "{{$localized(_global.selectedModule.name)}}"
}
```

**번역 파라미터와 함께 사용**:

```html
<div>$t:admin.modules.modals.install_confirm|name={{$localized(_global.selectedModule.name)}}</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "text": "$t:admin.modules.modals.install_confirm|name={{$localized(_global.selectedModule.name)}}"
}
```

**문자열 연결**:

```html
<div>{{$localized(_global.selectedModule.description) + ' (v' + _global.selectedModule.version + ')'}}</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "text": "{{$localized(_global.selectedModule.description) + ' (v' + _global.selectedModule.version + ')'}}"
}
```

**iteration 컨텍스트에서 사용**:

```html
<li data-each="menu in _global.selectedModule.admin_menus">{{$localized(menu.name)}}</li>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "basic",
  "name": "Li",
  "iteration": {
    "source": "_global.selectedModule.admin_menus",
    "item_var": "menu"
  },
  "text": "{{$localized(menu.name)}}"
}
```

**iteration + 문자열 연결**:

```html
<span data-each="perm in _global.selectedModule.permissions">{{$localized(perm.name) + ' (' + perm.identifier + ')'}}</span>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "basic",
  "name": "Span",
  "iteration": {
    "source": "_global.selectedModule.permissions",
    "item_var": "perm"
  },
  "text": "{{$localized(perm.name) + ' (' + perm.identifier + ')'}}"
}
```

### Optional Chaining과 함께 사용

```html
<div>{{$localized(_global.selectedModule?.name) || '-'}}</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "text": "{{$localized(_global.selectedModule?.name) || '-'}}"
}
```

### $localized() 사용 시 주의사항

```
✅ API에서 다국어 객체를 반환하는 필드에 사용
✅ 문자열/객체 타입이 혼재할 수 있는 필드에 사용
✅ iteration 컨텍스트 (menu, perm, role 등)에서도 정상 작동
$t: 번역 키와 혼동하지 말 것 ($t:는 프론트엔드 다국어 파일 참조)
$localized()는 API 응답의 다국어 객체 처리용
```

### $t: vs $localized() 비교

| 기능 | `$t:` | `$localized()` |
|------|-------|----------------|
| 용도 | 프론트엔드 다국어 키 번역 | API 응답 다국어 객체 처리 |
| 데이터 소스 | 템플릿 언어 파일 (ko.json, en.json) | API 응답 데이터 |
| 문법 | `$t:admin.title` | `{{$localized(data.name)}}` |
| 파라미터 | `$t:key\|param=value` | 지원 안 함 |
| 중첩 번역 | `$t:key\|param=$t:key2` (v1.25.0+) | 지원 안 함 |

---

## 다국어 처리 기본

### 기본 문법

| 문법 | 설명 | 예시 |
|------|------|------|
| `$t:key` | 다국어 키 | `$t:dashboard.title` |
| `$t:key\|param=value` | 파라미터 전달 | `$t:products.total\|count=10` |
| `$t:moduleIdentifier.key` | 모듈 다국어 키 | `$t:sirsoft-sample.admin.title` |

> **인자는 `$t:key|param=` 으로만 넘긴다.** 식 안의 `{{$t('key', { count: n })}}` 는 문법 오류 없이 평가되지만, `$t()` 헬퍼는 **키 하나만 받고 인자 객체를 버린다** — 화면에 `{{count}}` 자리표시자가 그대로 보인다. 인자가 필요하면 `$t:key|count={{n}}` 으로 쓰고(인자 값 안의 파이프도 된다: `|at={{row.at | datetime}}`), 반복 안에서 반복 변수를 넘기면 `$t:defer:key|…` 를 쓴다. `$t('key')` 처럼 인자 없는 호출은 식 안에서 그대로 쓸 수 있다.

#### 모듈 식별자와 하이픈 파싱 규칙 (engine-v1.28.1+)

`$t:` 키에서 모듈 식별자는 **별도의 네임스페이스 분리 로직이 아닌, 번역 딕셔너리의 중첩 키 조회**로 처리됩니다.

- `TranslationEngine.getNestedValue()`가 `path.split('.')`으로 키를 분할하여 딕셔너리를 순차 탐색
- 예: `$t:sirsoft-ecommerce.admin.orders.title` → `['sirsoft-ecommerce', 'admin', 'orders', 'title']` 순서로 탐색
- 백엔드에서 모듈 언어 데이터를 병합할 때 모듈 identifier(`sirsoft-ecommerce`)를 최상위 키로 배치하므로, 첫 번째 `.` 앞까지가 자연스럽게 모듈 식별자 역할을 합니다

`DataBindingEngine.preprocessTranslationTokens()`는 `$t:` 키의 허용 문자에 하이픈(`-`)을 포함합니다:

```text
패턴: /\$t:[a-zA-Z_][a-zA-Z0-9_.\-]*/
                                  ^^ 하이픈 포함 (engine-v1.28.1에서 추가)
```

이를 통해 `$t:sirsoft-page.admin.key` 같은 하이픈 포함 모듈 키가 정상 파싱됩니다. `preprocessOptionalChaining()`에서도 `$t:` 패턴을 임시 토큰으로 보호하여, `.`이 optional chaining(`?.`)으로 변환되지 않도록 합니다.

### 예시

```html
<div title="$t:dashboard.title" subtitle="$t:dashboard.subtitle" total="$t:products.total_count|count={{products.total}}">본문</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "props": {
    "title": "$t:dashboard.title",
    "subtitle": "$t:dashboard.subtitle",
    "total": "$t:products.total_count|count={{products.total}}"
  }
}
```

### 파라미터 치환

```html
<div>$t:products.total_count|count={{products.total}}</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "text": "$t:products.total_count|count={{products.total}}"
}

// 다국어 파일: "총 {count}개의 상품"
// 결과: "총 15개의 상품"
```

### 수에 따른 단수·복수 (engine-v1.78.7+)

영어처럼 수에 따라 낱말 꼴이 바뀌는 언어는 **`count` 인자**로 문구를 고른다. 레이아웃·컴포넌트는 바꾸지 않고 사전에 변형 키만 더한다.

| 사전 키 | 쓰이는 때 |
| --- | --- |
| `키` (기본 키) | 복수 범주가 `other` 일 때, 그리고 변형 키가 없을 때 — **기본 키는 언제나 other(일반 복수) 꼴로 쓴다** |
| `키_one` · `키_two` · `키_few` · `키_many` · `키_zero` | 그 로케일의 CLDR 복수 범주(`Intl.PluralRules`)가 그 이름일 때 |

```json
// lang/partial/en/board.json
{
  "post_count": "{{count}} posts",
  "post_count_one": "{{count}} post"
}
```

```html
<!-- en: 1 → "1 post", 0·2 → "0 posts"·"2 posts" / ko·ja: 범주가 other 뿐이라 기본 키 그대로 -->
<span>$t:board.post_count|count={{board.posts_count}}</span>
```

규칙:

- 인자 이름은 `count` 하나다. `{{total}}`·`{{days}}` 처럼 다른 이름의 수로는 고르지 않는다 — 고르려면 같은 값을 `count` 로도 넘긴다.
- `count` 가 숫자 표기(`1`·`-2`·`1.5`)일 때만 고른다. 빈 값·천 단위 구분 기호가 든 값(`1,000`)·글자는 기본 키를 쓴다.
- 범주는 **그 문구를 꺼낸 사전의 로케일**로 구한다. 현재 로케일 사전에 기본 키가 있으면 그 사전에서만 고르고(변형이 없으면 그 사전의 기본 키), 기본 키가 없어 폴백 로케일(en)로 넘어가면 en 범주로 다시 고른다.
- `키_other` 는 찾지 않는다(기본 키가 other 꼴). 변형 키만 있고 기본 키가 없으면 그 사전에 키가 없는 것으로 본다.
- `G7Core.t('board.post_count', { count: 1 })`·`useTranslation().t(...)` 도 같은 규칙을 탄다.
- 봇(SEO) 화면의 서버 평가기도 같은 규칙으로 고른다 — intl 확장이 있으면 ICU 복수 규칙, 없으면 ko·ja·zh 등은 other, 나머지는 정수 1·-1 이면 one(그 밖 범주는 근사하지 않는다).
- 범주가 other 뿐인 언어(ko·ja)는 변형 키를 둘 필요가 없다.

### 파라미터 값에 중첩 $t: 사용 (engine-v1.25.0+)

파라미터 값 자체가 다른 번역 키를 참조해야 할 때, `$t:` 토큰을 파라미터 값으로 사용할 수 있습니다.

```html
<div>$t:order.status_message|status=$t:enums.order_status.completed</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "text": "$t:order.status_message|status=$t:enums.order_status.completed"
}

// 다국어 파일:
//   order.status_message: "주문 상태가 {status}(으)로 변경됩니다."
//   enums.order_status.completed: "배송완료"
// 결과: "주문 상태가 배송완료(으)로 변경됩니다."
```

#### 동작 원리

엔진이 2단계로 처리합니다:

1. **사전 해석**: `=$t:key` 패턴을 먼저 찾아 번역 값으로 치환
2. **메인 해석**: 일반 `$t:key|param=value` 패턴을 처리

#### 다중 파라미터 혼합

`$t:` 중첩 파라미터와 일반 파라미터를 함께 사용할 수 있습니다:

```html
<div>$t:coupon.status_change|count={{selectedItems.length}}|status=$t:enums.coupon_status.stopped</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "text": "$t:coupon.status_change|count={{selectedItems.length}}|status=$t:enums.coupon_status.stopped"
}
```

#### 주의사항

```
✅ 파라미터 값 위치에만 사용 가능 (|param=$t:key)
✅ 다중 깊이 중첩 지원 (최대 5단계, 변화 없을 때까지 반복)
✅ =$t: 패턴이 없으면 사전 해석 자체를 skip (기존 동작 무영향)
중첩 $t: 키에는 파라미터 전달 불가 ($t:key만 가능, $t:key|p=v는 불가)
```

---

## 지연 번역 ($t:defer:) - 반복 컨텍스트용 (engine-v1.3.0+)

CardGrid, DataGrid 등 **반복 렌더링 컴포넌트**의 `cellChildren` 내부에서 `row`, `item` 같은 iteration 변수를 번역 파라미터로 사용할 때는 `$t:defer:` prefix를 사용합니다.

```
중요: DynamicRenderer는 props 처리 시점에 번역을 수행하는데,
         이 시점에는 row 컨텍스트가 없어서 {{row.xxx}}가 빈 문자열이 됨
✅ 해결: $t:defer: prefix를 사용하면 번역이 renderItemChildren으로 지연됨
```

### 문법

| 문법 | 설명 | 처리 시점 |
|------|------|----------|
| `$t:key` | 일반 번역 | DynamicRenderer (즉시) |
| `$t:defer:key` | 지연 번역 | renderItemChildren (iteration 컨텍스트 포함) |

### 사용 예시

```html
<card-grid>
  <prop name="cellChildren">
    <item type="basic" name="Span" text="$t:defer:admin.modules.vendor|vendor={{row.vendor}}"/>
    <item type="basic" name="Span" if="{{row.dependencies &amp;&amp; row.dependencies.length > 0}}" text="$t:defer:admin.modules.dependencies|deps={{row.dependencies.join(', ')}}"/>
    <item type="basic" name="Span" if="{{!row.dependencies || row.dependencies.length === 0}}" text="$t:admin.modules.no_dependencies"/>
  </prop>
</card-grid>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "name": "CardGrid",
  "props": {
    "cellChildren": [
      {
        "type": "basic",
        "name": "Span",
        "text": "$t:defer:admin.modules.vendor|vendor={{row.vendor}}"
      },
      {
        "type": "basic",
        "name": "Span",
        "if": "{{row.dependencies && row.dependencies.length > 0}}",
        "text": "$t:defer:admin.modules.dependencies|deps={{row.dependencies.join(', ')}}"
      },
      {
        "type": "basic",
        "name": "Span",
        "if": "{{!row.dependencies || row.dependencies.length === 0}}",
        "text": "$t:admin.modules.no_dependencies"
      }
    ]
  }
}
```

### 동작 원리

1. `DynamicRenderer.resolveTranslationsDeep`이 `$t:defer:` prefix를 감지하면 번역을 건너뜀
2. 원본 문자열이 그대로 `cellChildren` prop으로 전달됨
3. `renderItemChildren`이 각 row를 렌더링할 때 `$t:defer:` 번역 수행
4. 이 시점에는 `row` 컨텍스트가 있어서 `{{row.vendor}}`가 올바르게 해석됨

### 사용 시나리오

| 시나리오 | 사용 문법 |
|----------|----------|
| 일반 페이지 제목, 버튼 텍스트 | `$t:key` |
| CardGrid/DataGrid의 cellChildren에서 row 데이터 참조 | `$t:defer:key` |
| 리스트 아이템에서 item 데이터 참조 | `$t:defer:key` |

### 주의사항

```
✅ row., item., product. 등 iteration 변수 참조 시 $t:defer: 사용
✅ 일반 전역 데이터 참조 시에는 $t: 사용 (불필요한 defer 사용 금지)
$t:defer:는 renderItemChildren 내부에서만 처리됨
일반 컴포넌트에서 $t:defer: 사용 시 번역되지 않음
```

---

## 모듈/플러그인 다국어 병합

```
중요: 모듈과 플러그인도 템플릿과 동일한 다국어 시스템 사용
✅ 필수: moduleIdentifier를 키로 사용한 네이밍 규칙 준수
```

### 핵심 원칙

1. **템플릿 언어 JSON 서빙 시 자동 병합**: 활성화된 모듈과 플러그인의 다국어 데이터가 템플릿 언어 데이터와 병합되어 프론트엔드에 전달됩니다.
2. **moduleIdentifier 키 네이밍**: 병합된 JSON에서 각 모듈/플러그인의 언어 데이터는 해당 확장의 identifier를 최상위 키로 사용합니다.
3. **내부 파일에는 identifier 없음**: 모듈/플러그인 내부의 언어 파일(`resources/lang/*.json`)에는 moduleIdentifier가 포함되지 않습니다.

### 모듈 내부 언어 파일 구조

```json
// modules/sirsoft-sample/resources/lang/ko.json
{
  "admin": {
    "index": {
      "title": "샘플 항목 관리",
      "description": "샘플 모듈의 항목을 관리합니다"
    }
  }
}
```

### 병합된 언어 JSON 구조 (프론트엔드로 전달)

```json
{
  "auth": {
    "login": "로그인",
    "logout": "로그아웃"
  },
  "sirsoft-sample": {
    "admin": {
      "index": {
        "title": "샘플 항목 관리",
        "description": "샘플 모듈의 항목을 관리합니다"
      }
    }
  }
}
```

### 레이아웃에서 모듈 다국어 사용

```html
<h1 data-ref="page-title">$t:sirsoft-sample.admin.index.title</h1>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "page-title",
  "type": "basic",
  "name": "H1",
  "text": "$t:sirsoft-sample.admin.index.title"
}
```

### 병합 우선순위

1. 코어 (`lang/{locale}.json` + partial) — 베이스 레이어
2. 템플릿 언어 데이터
3. 모듈 언어 데이터 (모듈 identifier를 키로 추가)
4. 플러그인 언어 데이터 (플러그인 identifier를 키로 추가)
5. 활성 언어팩 (`template.language.merge` filter 훅 — 가장 높은 우선순위)

### 병합 정책 — Deep Merge (재귀 병합)

5개 레이어는 `array_merge` 가 아닌 재귀 deep merge (`deepMergeLanguageData`) 로 합쳐진다. 동일 top-level 키 (`layout_editor`, `core`, `auth` 등) 의 하위 트리를 leaf 까지 내려가며 병합하고, 어느 한쪽이 다른 레이어의 부분 트리만 정의해도 나머지 형제 키가 보존된다.

- assoc 트리 ↔ assoc 트리 충돌 → 재귀 병합 (양쪽 leaf 모두 보존, 동일 leaf 는 뒤가 우선)
- list / scalar / 한쪽만 array → 뒤 입력으로 덮어쓰기
- 우선순위(낮음 → 높음): 코어 < 템플릿 < 모듈 < 플러그인 < 활성 언어팩

확장 작성자 영향:

- 코어 도메인 네임스페이스 안에 자신의 sub-key 만 정의해도 안전. 예: 템플릿이 `layout_editor.palette` 만 정의해도 코어의 `layout_editor.chrome / device / zoom / preview / save` 가 살아남는다
- 코어 leaf 를 의도적으로 오버라이드하려면 동일 키 경로에 leaf 만 정의 (트리 교체 의도가 아니라 leaf override 의도임을 코드로 표현)
- 모듈/플러그인은 식별자 wrap (`module.{id}.*` / `plugin.{id}.*`) 사용을 우선 — 코어 도메인과 충돌할 일이 없고 deep merge 부작용도 회피

배경: 과거 shallow `array_merge` 는 동일 top-level 키 시 트리 전체가 교체되어, 템플릿이 한 sub-key 만 정의해도 코어의 다른 sub-key 가 통째 누락되는 결함이 발생. 상세 정책 / 회귀 사례는 [docs/extension/language-packs.md](../extension/language-packs.md#병합-정책--deep-merge-재귀-병합) 참조.

### 백엔드 구현 (`TemplateService`)

```php
public function getLanguageDataWithModules(string $identifier, string $locale): array
{
    // 1. 템플릿 언어 데이터 로드
    $templateLangData = $this->getLanguageData($identifier, $locale);

    // 2. 활성 모듈 언어 데이터 로드 및 병합
    $moduleLangData = $this->loadActiveModulesLanguageData($locale);

    // 3. 활성 플러그인 언어 데이터 로드 및 병합
    $pluginLangData = $this->loadActivePluginsLanguageData($locale);

    // 4. 코어 자체의 프론트엔드 다국어 자원 로드 (베이스 레이어)
    $coreLangData = $this->loadCoreFrontendLanguageData($locale);

    // 5. Deep merge — assoc 트리 재귀 병합, list/scalar leaf 는 뒤가 우선
    //    우선순위(낮음 → 높음): 코어 < 템플릿 < 모듈 < 플러그인
    $mergedData = $this->deepMergeLanguageData(
        $coreLangData, $templateLangData, $moduleLangData, $pluginLangData
    );

    // 6. 활성 언어팩 filter 훅 (가장 높은 우선순위 — 코어/모듈/플러그인 모두 덮어쓸 수 있음)
    $mergedData = HookManager::applyFilters('template.language.merge', $mergedData, $identifier, $locale);

    return ['success' => true, 'data' => $mergedData, 'error' => null];
}

private function loadActiveModulesLanguageData(string $locale): array
{
    $langData = [];
    $activeModules = $this->moduleManager->getActiveModules();

    foreach ($activeModules as $module) {
        $moduleIdentifier = $module->getIdentifier();
        $langFilePath = base_path("modules/{$moduleIdentifier}/resources/lang/{$locale}.json");

        if (file_exists($langFilePath)) {
            $content = file_get_contents($langFilePath);
            $data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                $langData[$moduleIdentifier] = $data;
            }
        }
    }

    return $langData;
}
```

### 캐시 무효화

모듈/플러그인 활성화/비활성화 시 템플릿 언어 캐시가 자동으로 무효화됩니다:

```php
// ModuleManager, PluginManager에서 트레이트 사용
use App\Extension\Traits\ClearsTemplateCaches;

class ModuleManager implements ModuleManagerInterface
{
    use ClearsTemplateCaches;

    public function activateModule(string $identifier): bool
    {
        // 모듈 활성화 로직
        // ...

        // 템플릿 언어 캐시 무효화
        $this->clearAllTemplateLanguageCaches();

        return true;
    }
}
```

### 모듈/플러그인 개발 시 주의사항

```
✅ `resources/lang/{locale}.json` 파일에 moduleIdentifier 없이 순수 키만 작성
✅ 레이아웃 JSON에서 `$t:moduleIdentifier.key.path` 형태로 참조
❌ 내부 파일에 `{ "moduleIdentifier": { ... } }` 형태로 작성 금지
```

---

## 다국어 파일 분할 ($partial) (engine-v1.5.0+)

대용량 다국어 JSON 파일을 여러 개의 작은 파일로 분할하여 관리할 수 있습니다. 레이아웃 시스템의 `partial` 패턴과 유사하게 동작합니다.

```
중요: 분할해도 API 응답은 기존과 동일 (병합된 단일 JSON)
✅ 장점: 파일 관리 용이, Git 충돌 감소, 협업 효율 향상
```

### $partial 문법

메인 다국어 파일에서 `$partial` 키를 사용하여 외부 partial 파일을 참조합니다.
**중요:** `$partial` 값에는 `partial/{locale}/`을 포함한 전체 상대 경로를 명시합니다. (레이아웃 partial과 동일한 패턴)

```json
// ko.json (메인 파일)
{
  "common": {
    "$partial": "partial/ko/common.json"
  },
  "admin": {
    "$partial": "partial/ko/admin.json"
  },
  "errors": {
    "network": "네트워크 오류"
  }
}
```

```json
// en.json (메인 파일)
{
  "common": {
    "$partial": "partial/en/common.json"
  },
  "admin": {
    "$partial": "partial/en/admin.json"
  }
}
```

```json
// partial/ko/common.json
{
  "save": "저장",
  "cancel": "취소",
  "delete": "삭제"
}
```

**병합 결과:**
```json
{
  "common": {
    "save": "저장",
    "cancel": "취소",
    "delete": "삭제"
  },
  "admin": { ... },
  "errors": {
    "network": "네트워크 오류"
  }
}
```

### 디렉토리 구조

Partial 파일은 `partial/{locale}/` 디렉토리에 위치합니다.

**템플릿:**
```
templates/sirsoft-admin_basic/lang/
├── ko.json                    # 메인 파일 ($partial 참조)
├── en.json                    # 메인 파일 ($partial 참조)
└── partial/
    ├── ko/
    │   ├── common.json
    │   ├── admin.json
    │   ├── auth.json
    │   └── errors.json
    └── en/
        ├── common.json
        ├── admin.json
        ├── auth.json
        └── errors.json
```

**모듈:**
```
modules/sirsoft-ecommerce/resources/lang/
├── ko.json
├── en.json
└── partial/
    ├── ko/
    │   ├── common.json
    │   ├── admin/
    │   │   ├── products.json
    │   │   ├── orders.json
    │   │   └── categories.json
    │   └── enums.json
    └── en/
        └── ...
```

### 중첩 $partial

Partial 내부에서 다른 partial을 참조할 수 있습니다 (최대 10단계).

```json
// partial/ko/admin.json
{
  "products": {
    "$partial": "admin/products.json"
  },
  "orders": {
    "$partial": "admin/orders.json"
  }
}
```

### 분할 기준 가이드

| 파일 크기 | 권장 분할 | 비고 |
|----------|----------|------|
| ~100줄 | 불필요 | 단일 파일 유지 |
| 100~500줄 | 선택 | 주요 섹션별 분할 |
| 500~1000줄 | 권장 | 최상위 키별 분할 |
| 1000줄+ | 필수 | 세부 섹션까지 분할 |

### 오류 처리

| 상황 | 발생 오류 |
|------|----------|
| Partial 파일 없음 | `RuntimeException: 다국어 fragment 파일을 찾을 수 없습니다` |
| JSON 파싱 오류 | `RuntimeException: 다국어 fragment JSON 파싱 오류` |
| 순환 참조 | `RuntimeException: 다국어 fragment 순환 참조 감지` |
| 최대 깊이 초과 | `RuntimeException: 다국어 fragment 최대 깊이(10) 초과` |

### 주의사항

```
✅ Partial 경로는 lang 디렉토리 기준 전체 상대 경로 (partial/{locale}/... 형식)
✅ 파일명에 .json 확장자 포함 필수
✅ 중첩 partial도 동일한 규칙 적용
순환 참조 주의 (A→B→A 형태 금지)
최대 중첩 깊이 10단계 제한
```

### 백엔드 구현

`ResolvesLanguageFragments` trait이 partial 해석을 담당합니다.

```php
// app/Extension/Traits/ResolvesLanguageFragments.php
trait ResolvesLanguageFragments
{
    protected function resolveLanguageFragments(array $data, string $basePath, int $depth = 0): array
    {
        // $partial 디렉티브 재귀적 해석
        // 순환 참조 및 최대 깊이 검사
    }
}

// app/Services/TemplateService.php
class TemplateService
{
    use ResolvesLanguageFragments;

    private function loadLanguageFileWithFragments(string $langPath): ?array
    {
        // JSON 로드 후 partial 해석
        // basePath는 lang 디렉토리, $partial 값에 partial/{locale}/... 전체 경로 포함
        $basePath = dirname($langPath);
        return $this->resolveLanguageFragments($data, $basePath);
    }
}
```

---

## 컴포넌트 다국어 처리

```
중요: 컴포넌트 내부에서는 다국어 키를 직접 사용하지 않음
✅ 필수: Props 기반 다국어 패턴 사용
✅ 필수: 영어를 기본값(fallback)으로 설정
```

### 핵심 원칙

그누보드7 템플릿 시스템에서 컴포넌트는 레이아웃 JSON에서 전달받은 텍스트를 그대로 표시합니다. 다국어 변환은 템플릿 엔진이 레이아웃 JSON을 렌더링할 때 자동으로 처리합니다.

### 다국어 처리 흐름

```
1. 레이아웃 JSON에 다국어 키 정의
   → "profileText": "$t:admin.profile_settings"

2. 템플릿 엔진이 현재 로케일에 따라 다국어 파일에서 값 조회
   → 한국어: "프로필 설정"
   → 영어: "Profile Settings"

3. 변환된 텍스트를 Props로 컴포넌트에 전달
   → profileText="프로필 설정" (한국어)
   → profileText="Profile Settings" (영어)

4. 컴포넌트는 전달받은 값을 그대로 표시
   → {profileText} 렌더링
```

### 올바른 컴포넌트 작성 패턴

```tsx
// ✅ 올바른 예: Props로 텍스트 받기 (영어 기본값)
export interface UserProfileProps {
  user: User;
  profileText?: string;    // 기본값: 영어
  logoutText?: string;     // 기본값: 영어
  onProfileClick?: () => void;
  onLogoutClick?: () => void;
}

export const UserProfile: React.FC<UserProfileProps> = ({
  user,
  profileText = 'Profile Settings',  // ✅ 영어 기본값
  logoutText = 'Logout',              // ✅ 영어 기본값
  onProfileClick,
  onLogoutClick,
}) => {
  return (
    <Div>
      <Button onClick={onProfileClick}>
        {profileText}  {/* Props로 받은 텍스트 사용 */}
      </Button>
      <Button onClick={onLogoutClick}>
        {logoutText}   {/* Props로 받은 텍스트 사용 */}
      </Button>
    </Div>
  );
};
```

### 잘못된 패턴

```tsx
// ❌ 잘못된 예: 컴포넌트 내부에서 다국어 키 사용 (금지)
export const UserProfile: React.FC<UserProfileProps> = ({ user }) => {
  return (
    <Div>
      {/* ❌ 컴포넌트는 다국어 시스템에 직접 접근하지 않음 */}
      <Button>{t('admin.profile_settings')}</Button>
      <Button>{t('admin.logout')}</Button>
    </Div>
  );
};
```

### 레이아웃 JSON 작성 패턴

```html
<user-profile data-ref="user_profile" user="{{current_user.data}}" profileText="$t:admin.profile_settings" logoutText="$t:admin.logout"/>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "user_profile",
  "type": "composite",
  "name": "UserProfile",
  "props": {
    "user": "{{current_user.data}}",
    "profileText": "$t:admin.profile_settings",  // 다국어 키
    "logoutText": "$t:admin.logout"              // 다국어 키
  }
}
```

> 데이터는 위처럼 props 표현식(`"{{current_user.data}}"`)으로 바인딩한다. 별도의 `data_binding` 매핑 키는 **사어(死語)** 로, 렌더러가 읽지 않고 마크업 태그로도 승격되지 않는다 ([layout-json.md](layout-json.md) 최상위 속성 표).

### 다국어 파일 구조

```json
// resources/lang/ko.json
{
  "admin": {
    "profile_settings": "프로필 설정",
    "logout": "로그아웃",
    "notifications": "알림",
    "no_notifications": "알림이 없습니다"
  }
}

// resources/lang/en.json
{
  "admin": {
    "profile_settings": "Profile Settings",
    "logout": "Logout",
    "notifications": "Notifications",
    "no_notifications": "No notifications"
  }
}
```

### 기본값 규칙

1. **영어를 기본값으로 사용**: 모든 텍스트 Props의 기본값은 영어로 작성
2. **의미 있는 기본값**: 빈 문자열이 아닌 실제 사용 가능한 텍스트 제공
3. **일관성 유지**: 동일한 의미의 텍스트는 같은 표현 사용

```tsx
// ✅ 올바른 기본값
submitButtonText = 'Sign In'           // 명확한 영어 텍스트
processingText = 'Processing...'       // 로딩 상태 표시
emptyText = 'No notifications'         // 빈 상태 메시지

// ❌ 잘못된 기본값
submitButtonText = ''                  // 빈 문자열 (의미 없음)
submitButtonText = '로그인'            // 한국어 (영어 사용 필수)
```

### 실제 사용 예시 (LoginForm 컴포넌트)

```tsx
export interface LoginFormProps {
  emailLabel?: string;
  passwordLabel?: string;
  submitButtonText?: string;
  processingText?: string;
  emailPlaceholder?: string;
  passwordPlaceholder?: string;
  forgotPasswordText?: string;
  forgotPasswordUrl?: string;
  // ...
}

export const LoginForm: React.FC<LoginFormProps> = ({
  emailLabel = 'Email',                      // ✅ 영어 기본값
  passwordLabel = 'Password',                // ✅ 영어 기본값
  submitButtonText = 'Sign In',              // ✅ 영어 기본값
  processingText = 'Processing...',          // ✅ 영어 기본값
  emailPlaceholder = 'Email',                // ✅ 영어 기본값
  passwordPlaceholder = 'Password',          // ✅ 영어 기본값
  forgotPasswordText = 'Forgot your password?',  // ✅ 영어 기본값
  // ...
}) => {
  return (
    <Form>
      <Label>{emailLabel}</Label>
      <Input placeholder={emailPlaceholder} />
      <Label>{passwordLabel}</Label>
      <Input type="password" placeholder={passwordPlaceholder} />
      <A>{forgotPasswordText}</A>
      <Button>{submitButtonText}</Button>
    </Form>
  );
};
```

### 레이아웃 JSON에서 사용

```html
<login-form data-ref="login_form" emailLabel="$t:auth.login.email" passwordLabel="$t:auth.login.password" submitButtonText="$t:auth.login.submit" processingText="$t:auth.login.processing" emailPlaceholder="$t:auth.login.email_placeholder" passwordPlaceholder="$t:auth.login.password_placeholder" forgotPasswordText="$t:auth.login.forgot_password"/>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "login_form",
  "type": "composite",
  "name": "LoginForm",
  "props": {
    "emailLabel": "$t:auth.login.email",
    "passwordLabel": "$t:auth.login.password",
    "submitButtonText": "$t:auth.login.submit",
    "processingText": "$t:auth.login.processing",
    "emailPlaceholder": "$t:auth.login.email_placeholder",
    "passwordPlaceholder": "$t:auth.login.password_placeholder",
    "forgotPasswordText": "$t:auth.login.forgot_password"
  }
}
```

### Props 기반 다국어 패턴의 장점

1. **템플릿 엔진 의존성 제거**: 컴포넌트가 템플릿 엔진의 다국어 시스템에 의존하지 않음
2. **재사용성 향상**: 다른 프로젝트나 환경에서도 사용 가능
3. **테스트 용이성**: Props로 텍스트를 직접 전달하여 테스트 간소화
4. **타입 안전성**: TypeScript로 Props 타입 정의 가능
5. **폴백 동작**: 다국어 키가 없어도 영어 기본값으로 정상 표시

### 주의사항

```
금지: useTranslation, t() 함수 등 다국어 훅/함수 사용
금지: 컴포넌트 내부에서 다국어 파일 직접 import
금지: 한국어를 기본값으로 설정
✅ 필수: 모든 사용자 표시 텍스트는 Props로 받기
✅ 필수: 영어를 기본값으로 설정
✅ 필수: Props 타입에 선택적(optional) 정의
```

### 참고 컴포넌트

- `LoginForm.tsx`: 폼 라벨 및 버튼 텍스트 다국어 처리
- `UserProfile.tsx`: 드롭다운 메뉴 텍스트 다국어 처리
- `NotificationCenter.tsx`: 알림 관련 텍스트 다국어 처리

---

## JavaScript에서 G7Core.t() 사용

컴포넌트 코드에서 프로그래밍 방식으로 번역 함수를 사용할 수 있습니다.

> 전체 API 레퍼런스: [g7core-api.md](g7core-api.md#다국어-g7corelocale-g7coret)

### 컴포넌트에서 t 함수 선언

```tsx
// 컴포넌트 파일 상단에 선언
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;
```

### G7Core.t() 사용 예시

```tsx
// 간단한 번역
const text = G7Core.t('common.confirm');  // '확인'

// 파라미터 치환
const message = G7Core.t('admin.users.pagination_info', {
  from: 1,
  to: 10,
  total: 100
});
// "1-10 / 총 100건"

// 컴포넌트에서 사용
<Button>{t('common.confirm')}</Button>
<Span>{t('admin.users.count', { count: 10 })}</Span>
```

### 로케일 관리 API

```typescript
// 현재 로케일 조회
const currentLocale = G7Core.locale.current();  // 'ko'

// 지원 로케일 목록
const supportedLocales = G7Core.locale.supported();  // ['ko', 'en']

// 로케일 변경
await G7Core.locale.change('en');
```

---

## 번역 면제 바인딩 (`raw:` 접두사) (engine-v1.27.0+)

사용자 입력 데이터를 표시하는 바인딩에 `raw:` 접두사를 붙이면,
데이터에 `$t:` 패턴이 포함되어도 번역되지 않고 원본이 보존됩니다.

### 사용법

| 일반 바인딩 | 번역 면제 바인딩 |
|------------|-----------------|
| `{{post.title}}` | `{{raw:post.title}}` |
| `{{comment.content}}` | `{{raw:comment.content}}` |
| `{{product.name}}` | `{{raw:product.name}}` |

### 사용 시점

- 사용자가 직접 입력한 텍스트 (게시판 제목, 댓글, 상품명, 닉네임 등)
- 시스템 데이터 (핸들러 결과, 서버 메타데이터, 설정값 등)에는 사용 금지
- `$t:` 토큰이 의도적으로 포함된 핸들러 결과 (예: DataGrid 컬럼 헤더)에는 사용 금지

### 예시

```html
<span>{{raw:post.title}}</span>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "basic",
  "name": "Span",
  "text": "{{raw:post.title}}"
}
```

### 파이프와 함께 사용

```html
<div>{{raw:post.title | truncate:50}}</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
"text": "{{raw:post.title | truncate:50}}"
```

### 복잡한 표현식

```html
<div>{{raw:post.title ?? '제목 없음'}}</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
"text": "{{raw:post.title ?? '제목 없음'}}"
```

### 혼합 보간

일반 번역 토큰과 raw 바인딩을 같은 문자열에서 함께 사용할 수 있습니다:

```html
<div>{{raw:post.title}} - $t:common.by {{post.author}}</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
"text": "{{raw:post.title}} - $t:common.by {{post.author}}"
```

이 경우 `raw:` 영역 내부의 `$t:` 패턴은 보호되고, 영역 외부의 `$t:common.by`는 정상 번역됩니다.

### 내부 동작

1. `DataBindingEngine`: `raw:` 접두사를 감지하여 제거 후 정상 평가, 결과를 Unicode Noncharacter 마커(`\uFDD0`...`\uFDD1`)로 래핑
2. `resolveTranslationsDeep`: 마커가 감지되면 번역을 건너뛰고 마커만 제거하여 원본 반환
3. 객체/배열 결과는 `wrapRawDeep`로 내부 모든 리프 문자열에 마커 부착

### 주의사항

- `raw:`는 `resolveTranslationsDeep`의 `$t:` 번역만 건너뜁니다
- 표현식 내부의 `$t()` 함수 호출에는 영향 없습니다
- 핸들러 결과(`resultTo`)에 사용하면 의도된 `$t:` 번역도 건너뛰므로 주의

---

## 정본 화면을 복제할 때의 라벨 키

한 화면의 행 마크업을 다른 화면에 그대로 옮겨 그리는 경우가 있다 — 주문 상세의 결제 정보를
클레임 상세에서도 같은 모양으로 보여 주는 식이다. 이때 **라벨 키까지 정본의 것을 읽는다.**
같은 값을 그리면서 키만 새로 지으면 두 문제가 한꺼번에 생긴다.

- 같은 값이 두 화면에서 **다른 말**로 불린다 ("과세 금액" ↔ "과세금액"). 값이 같으므로
  오류도 경고도 로그도 남지 않고, 두 화면을 나란히 놓고 보기 전에는 드러나지 않는다.
- 나중에 정본의 문구가 바뀌어도 **사본만 옛 문구로 남는다**. 사본의 키는 정본과 아무 관계가
  없으므로 정본을 고치는 사람이 사본의 존재를 알 방법이 없다.

```json
// ❌ 행 마크업만 복제하고 라벨 키는 새로 만든다
{ "type": "basic", "name": "Span", "text": "$t:sirsoft-ecommerce.admin.claim.detail.payment.taxable_amount" }

// ✅ 정본의 키를 그대로 읽는다
{ "type": "basic", "name": "Span", "text": "$t:sirsoft-ecommerce.admin.order.detail.payment_info.taxable_amount" }
```

사본이 자기 키를 갖는 것은 **정본에 없는 문구**뿐이다 (그 화면에만 있는 안내 문장 등).

이 규율은 사본 쪽 회귀 테스트로 고정한다 — 사본 레이아웃이 참조하는 `$t:` 키 집합에서
정본 레이아웃의 키 집합과 사본 고유 키를 뺀 나머지가 비어 있는지 단언한다. 목록을 코드에
적어 두지 말고 **정본 파일을 읽어** 만들어야, 정본이 키를 바꿀 때 그 테스트가 함께 움직인다.

---

## 관련 문서

- [데이터 바인딩 기본](data-binding.md) - 기본 문법, 표현식
- [g7core-api.md](g7core-api.md) - G7Core 전역 API 레퍼런스
- [layout-json.md](layout-json.md) - 레이아웃 JSON 스키마
- [data-sources.md](data-sources.md) - 데이터 소스 시스템
- [state-management.md](state-management.md) - 전역 상태 관리
