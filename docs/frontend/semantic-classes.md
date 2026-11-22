# 레이아웃 의미 클래스 우선

> 적용 버전: 코어 7.2.1, 관리자 템플릿 sirsoft-admin_basic 1.2.1, 사용자 템플릿 sirsoft-basic 1.4.1

## TL;DR

```text
1. 같은 모양을 여러 화면에 반복해 적지 않는다 — 템플릿이 제공하는 의미 클래스를 쓴다(보조 글자·오류 문구·주 버튼·카드·라벨·관리자 페이지 본문 상자). 바인딩 식 안의 클래스 문자열도 같다.
2. 의미 클래스는 소유자별이다 — 템플릿 레이아웃은 그 템플릿 클래스, 확장은 자기 CSS 의 사본 이름({ns}-…, 사용자 조각은 {ns}-user-…)을 쓴다.
3. 여백·배치 유틸리티는 의미 클래스 옆에 그대로 적는다 — 유틸리티 층이 의미 클래스(components 층)를 이긴다.
4. 빈 class 속성(class="")은 적지 않는다. 정의 없는 클래스 이름은 쓰지 않는다.
5. 폭별로 클래스 일부만 바꿀 때는 구간 class 를 통째로 다시 적지 말고 class-merge 로 바뀐 것만 적는다.
6. 이 규칙은 정적 검사가 막는다. 예외는 `audit:allow <규칙> reason: <사유>` 주석으로만 남긴다.
```

## 왜 의미 클래스인가

같은 Tailwind 문자열(`text-sm text-gray-500 dark:text-gray-400` 등)을 화면마다 복사하면 다크 모드 색 한 단계를 바꾸는 데 수백 곳을 고쳐야 하고, 복사하면서 조금씩 달라진 변형이 같은 화면에 섞인다(같은 폼 안에서 오류 문구 색이 세 가지로 보이는 식). 의미 클래스는 그 모양을 한 곳에서 정의한다.

의미 클래스는 템플릿 CSS 의 `@layer components` 에 있다. Tailwind 유틸리티(`@layer utilities`)가 components 층보다 우선하므로, 의미 클래스 옆에 적은 여백·크기 유틸리티가 이긴다.

```html
<!-- 보조 글자 + 위 여백 -->
<p class="text-muted text-sm mt-2">$t:admin.users.hint</p>
```

## 관리자 템플릿 카탈로그

관리자 템플릿(sirsoft-admin_basic) 레이아웃이 쓰는 이름이다. 확장의 관리자 화면은 같은 이름에 앞글자를 붙인 사본(`{ns}-text-muted` 등)을 쓴다 — 아래 「확장 화면」 절.

| 인라인 모양(토큰 포함 판정, 순서 무관) | 쓸 클래스 | 비고 |
| --- | --- | --- |
| `text-gray-500` + `dark:text-gray-400` | `text-muted` | 크기 토큰(`text-xs`·`text-sm`·`text-base`)은 남긴다 |
| 입력 오류 문구(`text-red-500`/`text-red-600` + `text-xs`) | `form-error` | 오류 바인딩(`errors.…`)이나 `*-error` id 를 가진 노드. 필수 표시 별표·상태 문구는 대상 아님 |
| Button 의 파랑(`bg-blue-600 … text-white`)·검정(`bg-gray-900 dark:bg-white …`) 주 버튼 | `btn btn-primary` | `px-4 py-2 text-sm font-medium rounded-lg` 는 뺀다. `px-3 py-1.5` 는 `btn-sm` |
| 테두리 보조 버튼(`border border-gray-300 text-gray-700 hover:bg-gray-50`) | `btn btn-secondary` | 패딩 규칙은 주 버튼과 같다 |
| 카드(`bg-white dark:bg-gray-800 rounded-lg border`)이면서 여백이 `.admin-card` 와 같음 | `admin-card` | 여백이 다른 상자는 그대로 두고 사유 주석을 단다(아래 「여백이 다른 카드」) |
| 페이지 본문 상자(`p-6` + `min-h-screen` + `bg-gray-50` + `dark:bg-gray-900`) | `admin-page-content` | 정의와 같은 네 토큰이다. `pb-24` 같은 추가 여백은 옆에 남긴다 |
| 라벨(`block text-sm font-medium text-gray-700 dark:text-gray-300`) | `form-label` | `mb-1` 은 빼고 다른 아래 여백은 남긴다 |
| `btn` 과 함께 쓴 `disabled:opacity-50`·`disabled:cursor-not-allowed` | (지운다) | `.btn` 이 이미 준다 |
| 정의 없는 `form-help` | `form-hint` | |

관리자 주 버튼은 검정(다크 흰색)이다. 7.2.1 에서 파랑 인라인 주 버튼과 오류 문구 색을 이 표로 통일했다.

### 여백이 다른 카드

관리자 화면에서 카드 모양(`bg-white` + `dark:bg-gray-800` + `rounded-lg` + `border`, 테두리 색은 무엇이든)인 상자는 둘 중 하나로 적는다.

- 여백과 배치를 `.admin-card`(`p-4 mb-6 flex flex-col`)에 맞출 수 있으면 `admin-card` 를 쓴다.
- 창 안의 목록 테두리, 격자 칸, 미리보기 상자처럼 여백이 달라야 하면 인라인으로 두고, 그 노드 바로 위 주석에 무엇을 위한 상자이고 왜 여백이 다른지 적는다.

```html
<!-- audit:allow layout-inline-semantic-duplicate reason: 삭제 창의 테이블 목록 상자, 줄마다 구분선을 긋는 목록이라 상자 여백 없이 ml-6 들여쓰기만 둠 -->
<div class="ml-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg overflow-hidden">
```

한 태그 앞에는 주석을 하나만 쓸 수 있다. 이미 설명 주석이 있는 노드는 그 주석 안에 줄을 바꿔 `audit:allow …` 줄을 더한다.

카드로 보지 않는 것: Button 과 입력 부품(Input, Select, Textarea 등), `btn`, `button`, `input` 계열 클래스가 붙은 노드(링크로 그린 단추 등), 그림자가 있는 떠 있는 상자(드롭다운, 팝오버). 사용자 템플릿 화면에는 이 요구가 없다.

## 바인딩 식 안의 클래스 문자열

`{{ }}` 식 안의 따옴표 문자열도 같은 규칙을 따른다. 문자열 하나하나를 독립된 class 값으로 보고, **문자열 하나 안에** 카탈로그의 토큰이 다 들어 있으면 의미 클래스로 적는다. 식 밖의 정적 클래스와 합쳐서 판정하지 않는다.

```html
<!-- 종전 -->
<span class="{{row.error ? 'text-sm text-red-600' : 'text-sm text-gray-500 dark:text-gray-400'}}">…</span>

<!-- 지금: 둘째 문자열만 바뀐다 -->
<span class="{{row.error ? 'text-sm text-red-600' : 'text-sm text-muted'}}">…</span>
```

식 안에서는 **바꾸기 전과 모양이 같을 때만** 의미 클래스로 적는다. 아래는 인라인으로 둔다(검사도 잡지 않는다).

| 경우 | 까닭 |
| --- | --- |
| 삼항식 한 갈래만 `btn btn-primary` 같은 버튼 클래스가 되는 경우 | `.btn` 의 글자 크기, 굵기, hover 색이 그 갈래에만 붙어 다른 갈래와 어긋난다. 식 전체를 `variant` 속성이나 갈래마다 온전한 의미 클래스로 다시 짤 때만 바꾼다 |
| 식 밖의 유틸리티가 같은 속성을 정하는 경우(`class="text-gray-900 {{x ? 'text-gray-500 dark:text-gray-400' : ''}}"`) | 의미 클래스는 유틸리티보다 낮은 층이라, 옮기면 식 밖 `text-gray-900` 이 늘 이기게 된다 |
| 문자열을 이어 붙이는 식(`'text-' + size`, 템플릿 리터럴, `.join(' ')`), 함수 인자로 넘기는 문자열, 정적 글자에 붙은 식(`tone-{{x}}`) | 문자열이 온전한 클래스 목록인지 알 수 없다 |

## 사용자 템플릿 카탈로그

사용자 템플릿(sirsoft-basic) `templates/_bundled/sirsoft-basic/src/styles/main.css` 의 정의다. 사용자 템플릿 화면은 **모양이 같은 것만** 바꾼다 — 인라인 토큰이 정의의 시각 토큰을 그대로 포함할 때만 의미 클래스로 쓰고, 색·크기가 다른 변형은 인라인으로 둔다.

| 클래스 | 정의 | 쓰는 곳 |
| --- | --- | --- |
| `text-muted` | `text-gray-500 dark:text-gray-400` | 설명·날짜·건수 같은 흐린 글자 |
| `form-label` | `block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1` | 폼 라벨 |
| `form-error` | `block mt-1 text-xs text-red-600 dark:text-red-400` | 입력 오류 문구 |
| `btn` | `inline-flex items-center justify-center px-4 py-2 font-medium rounded-lg transition-colors`, 키보드 포커스 링(`focus-visible`) | 버튼 바탕 |
| `btn-sm` / `btn-lg` | `px-3 py-1.5 text-sm` / `px-6 py-3 text-base` | 버튼 크기 |
| `btn-primary` | 검정(다크 흰색) | 저장·확인 |
| `btn-accent` | 파랑 — 템플릿 설정 「포인트 색」을 따른다 | 강조 동작 |
| `btn-outline` | 테두리 회색 | 취소·보조 동작 |
| `btn-secondary` · `btn-ghost` · `btn-danger` · `btn-success` | 회색 채움 · 투명 · 빨강 · 초록 | |
| `card-plain` | `bg-white border border-gray-200 rounded-lg dark:bg-gray-800 dark:border-gray-700` (여백 없음) | 그림자 없는 상자. 여백은 유틸리티로 준다 |
| `card` | 그림자 있는 상자 | 프로필 계열 |

## Button 의 variant·size

두 템플릿의 Button 은 `variant` 를 적었을 때만 `btn btn-{variant}` 를, `size` 가 `sm`·`lg` 면 `btn-sm`·`btn-lg` 를 붙인다. 적지 않으면 아무 클래스도 붙이지 않으므로, className 으로 모양을 내는 기존 버튼은 그대로다. 호출처 className 이 내부 클래스를 이긴다.

| 템플릿 | variant |
| --- | --- |
| 관리자 | `primary` `secondary` `danger` `success` `ghost` `outline` |
| 사용자 | 위 6종 + `accent` |

```html
<button type="submit" variant="primary" size="sm">$t:common.save</button>
```

## 확장 화면

확장(모듈·플러그인)은 어느 템플릿에 실릴지 모르므로 템플릿 의미 클래스에 기대지 않고 자기 CSS 를 싣는다. 의미 클래스는 앞글자를 붙인 사본으로 쓴다.

| 화면 | 이름 | 예 |
| --- | --- | --- |
| 관리자 화면 | `{ns}-{클래스}` | `ecommerce-text-muted`, `board-btn-primary` |
| 사용자 조각 | `{ns}-user-{클래스}` | `page-user-form-error` |

사본 정의는 확장 CSS 변환 도구가 템플릿 빌드 결과에서 복사해 `{modules|plugins}/_bundled/{확장}/resources/css/g7-extension.generated.css` 에 쓴다. 손으로 고치지 않고 도구를 다시 돌린다([확장 CSS 도구](../../scripts/extension-css/README.md)).

## 빈 class 속성

`class=""`·`"className": ""` 는 DOM 에 빈 `class` 속성을 남길 뿐이다. 적지 않는다. 구간 `class-merge=""` 는 컴파일 오류다.

## 정의 없는 클래스

템플릿 레이아웃이 쓰는 클래스 이름은 그 템플릿 CSS 에 정의가 있어야 한다(Tailwind 유틸리티 제외). 정의가 없는 이름은 아무 모양도 내지 않는다. 시험·스크립트가 선택자로만 쓰는 이름은 사유와 함께 예외로 둔다.

## 구간 재작성

폭별 구간에서 기본 클래스 대부분을 다시 적고 한두 개만 바꾼 것은 `class-merge` 로 바뀐 것만 적는다([반응형 레이아웃 — 바뀐 클래스만 덧붙이기](responsive-layout.md#바뀐-클래스만-덧붙이기-class-merge)).

```html
<!-- 종전: 모바일에서 패딩 하나 바꾸려고 전체를 다시 적음 -->
<div class="w-full max-w-md p-8 bg-white rounded-lg shadow">
  <responsive on="portable" class="w-full max-w-md p-6 bg-white rounded-lg shadow"/>
</div>

<!-- 지금 -->
<div class="w-full max-w-md p-8 bg-white rounded-lg shadow">
  <responsive on="portable" class-merge="p-6"/>
</div>
```

## 정적 검사

| 검사 | 막는 것 |
| --- | --- |
| `layout-inline-semantic-duplicate` | 위 카탈로그의 인라인 반복(정적 class 값과 바인딩 식 안의 문자열 모두), 관리자 화면에서 사유 주석 없이 둔 여백이 다른 카드 |
| `layout-empty-class-attribute` | 빈 class 속성 |
| `layout-template-class-defined` | 템플릿 레이아웃의 정의 없는 클래스 |
| `layout-responsive-class-rewrite` | `class-merge` 로 바꿀 수 있는 구간 재작성 |

예외는 그 노드 바로 위 주석에 `audit:allow <검사> reason: <사유>` 로 남긴다(사유 필수).
