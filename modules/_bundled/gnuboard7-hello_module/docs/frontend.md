# Hello 모듈 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 4개 (루트: `resources/layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `admin` | 3개 |
| `user` | 1개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `admin_markup_demo` | `admin` | 화면 | - |
| `admin_memo_form` | `admin` | 화면 | `_admin_base` |
| `admin_memo_list` | `admin` | 화면 | `_admin_base` |
| `user_memo_list` | `user` | 화면 | `_user_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
4개이며 그중 하나가 `user` 그룹인 것이 학습 포인트입니다.

| 레이아웃 | 그룹 | 무엇을 보여주는가 |
|---|---|---|
| `admin_memo_list` · `admin_memo_form` | `admin` | 관리자 목록·작성 화면의 최소 형태 (`_admin_base` 상속) |
| `admin_markup_demo` | `admin` | 마크업 형식(`.g7.html`) 한 장이 화면이 되는 최소 예시 (베이스 상속 없음) |
| `user_memo_list` | `user` | **모듈도 사용자 레이아웃을 가질 수 있다** (`_user_base` 상속) · **모듈 화면 SEO 선언 예시** (`meta.seo`). 메모 한 건은 이 모듈이 제공하는 `MemoCard` 로 그린다 |

실제 도메인 모듈(게시판·이커머스)은 방문자 화면을 소유하지 않고 템플릿에 맡깁니다 — 템플릿마다
디자인이 달라야 하기 때문입니다. 이 샘플의 `user_memo_list` 는 그 규칙의 예외가 아니라, 구조상
가능하다는 사실을 보이는 예시입니다.

모듈 레이아웃은 위치에 따라 등록 대상이 갈립니다 — `admin/` 하위는 Admin 템플릿에,
`user/` 하위는 User 템플릿에 등록됩니다.

`user_memo_list` 는 **모듈 소유 화면의 SEO 선언**도 보입니다. 템플릿 `_user_base` 의 기본값(SEO 끔)을
이 화면의 `<meta>` 안 `<seo :enabled="true">` 가 덮어, 검색봇이 `/memos` 를 요청하면 제목·설명·og 태그와
본문(메모 제목)이 담긴 HTML 을 받습니다. `<data-sources><item>memos</item></data-sources>` 로 고른 데이터 소스만
봇 렌더 때 호출합니다. 라우트의 레이아웃 이름은 모듈 식별자가 붙은 전체 이름
(`gnuboard7-hello_module.user_memo_list`)이고, 봇 캐시를 지울 때도 이 이름을 씁니다
(`php artisan seo:clear --layout=gnuboard7-hello_module.user_memo_list`). 코어 7.1.4 이상에서 동작합니다.

레이아웃의 클래스는 모두 이 모듈 CSS 의 `hellomodule-*` 입니다([스타일](#스타일--모듈-자기-css)). 템플릿 CSS 나
Tailwind 유틸리티를 쓰지 않으므로, 클래스를 새로 쓰면 `resources/css/main.css` 에 정의를 더하고 빌드합니다.
클래스를 고치지 않고 레이아웃만 고쳤다면 빌드 없이
`php artisan module:update gnuboard7-hello_module --force` 로 반영합니다.
<!-- @intent END -->

## 스타일 — 모듈 자기 CSS

확장 화면의 모양은 확장이 자기 CSS 로 싣습니다. 그누보드7 은 Tailwind 를 전제하지 않으므로, 이 샘플은 빌드 도구 없이
손으로 쓴 **순수 CSS** 한 장(`resources/css/main.css`)으로 그 최소 형태를 보입니다.

| 할 일 | 위치 |
|---|---|
| 클래스 앞글자 선언 | `module.json` → `assets.css.namespace: "hellomodule"` — 클래스는 `hellomodule-` 로 시작한다 |
| 산출 경로 선언 | `module.json` → `assets.css.entry`·`output`(`dist/css/module.css`) |
| 빌드 배선 | `resources/js/index.ts` 가 CSS 를 import 하고, `vite.config.ts` 의 `assetFileNames` 가 그 CSS 를 `output` 경로로 낸다 |
| 컴포넌트 | `MemoCard` 기본 판본 `hellomodule-card*`, Hello 관리자 템플릿 판본 `hellomodule-row*` |
| 레이아웃 | 4종 모두 `hellomodule-*` 클래스만 쓴다 |

**우선순위 세 칸** — 파일 하나 안에서 두 칸을 함께 씁니다. 규약 전체는 그누보드7 개발 문서 `docs/extension/module-assets.md` 의 「확장 CSS 자기 공급 규약」·「템플릿 확장 CSS 덮기」 절에 있습니다.

| 칸 | 이 파일에서 | 무엇을 두는가 | 템플릿과의 관계 |
|---|---|---|---|
| ① 기본값 | `@layer g7-extension { … }` 안 | 색·테두리·배경·글자(의미) | 이 층은 템플릿 CSS **뒤**에 선언된다 — 템플릿의 층 있는 규칙(Tailwind 등)은 여기에 지고, 템플릿의 층 없는 규칙과 ③ 덮기 파일만 이긴다. 템플릿이 아무것도 정의하지 않으면 이 값이 쓰인다 |
| ② 일반 | 층 밖 | 여백·정렬·간격(배치) | 층 없는 규칙이라 템플릿의 층 있는 규칙을 이기고, 템플릿 CSS 뒤에 실려 같은 강도의 템플릿 층 없는 규칙도 이긴다 |
| ③ 템플릿 덮기 파일 | (템플릿 쪽) | 템플릿이 이 모듈 화면을 자기 모양으로 바꾸는 CSS | 맨 뒤에 실려 ①②를 덮는다. 템플릿이 선언한 버전 범위가 이 모듈 버전과 맞지 않으면 빠진다 |

③ 의 예시는 Hello 사용자 템플릿의 `templates/_bundled/gnuboard7-hello_user_template/src/styles/overrides/gnuboard7-hello_module.css` 입니다 — `/memos` 쪽 제목
(`hellomodule-page__title`)의 색을 바꿉니다. 그 템플릿은 `MemoCard` 컴포넌트도 대체하므로, `/memos` 에서는 컴포넌트
대체와 CSS 덮기를 함께 볼 수 있습니다.

**다크 모드** — 템플릿마다 다크 방식이 달라 `.dark` 같은 템플릿 클래스에 기대지 않습니다. 템플릿이 다크 방식을
선언하면 코어가 `data-g7-color-scheme="dark|light"` 표식을 달고, 이 CSS 는 그 표식만 봅니다:

```css
.hellomodule-card:is([data-g7-color-scheme="dark"] *, [data-g7-color-scheme="dark"]):not(:where([data-g7-color-scheme="dark"] [data-g7-color-scheme="light"] *, [data-g7-color-scheme="dark"] [data-g7-color-scheme="light"])) {
    background-color: #1f2937;
}
```

`:is(…)` 와 `:not(…)` 사이에 공백을 두지 않습니다 — 공백은 「자손」 결합자라 전혀 다른 선택자가 됩니다.

`:not(:where(…))` 는 다크 화면 안의 라이트 구역(다크 관리자 화면 속 라이트 미리보기 등)을 뺍니다. 다크를 선언하지 않은
템플릿(Hello 템플릿 둘)에서는 표식이 늘 `light` 라 다크 규칙이 쓰이지 않습니다.

같은 결과를 Tailwind·Sass 로 만들어도 됩니다 — 규약이 확인하는 것은 「앞글자 붙은 자기 CSS」 뿐입니다.

## 권한 게이트 (abilities)

쓰기 UI 는 **응답의 `abilities` 로 잠그고**, 서버는 같은 권한으로 403 을 낸다. 서버만 막으면 사용자는 다 입력한 뒤에야
거절당한다. 이 샘플은 그 최소 형태를 보인다(API 응답 필드는 [docs/api/memos.md](api/memos.md)).

| 화면 | 근거 키 | 잠그는 것 |
|---|---|---|
| `admin_memo_list` | `memos.data.abilities.can_create`(컬렉션) | 「새 메모」 버튼 `disabled` + 이유 툴팁 |
| `admin_memo_form`(생성) | `memoCreateAbility.data.abilities.can_create` — 목록 API 를 한 건만 받아 컬렉션 abilities 를 읽는다 | `_computed.isReadOnly` → 입력·저장 (응답이 없거나 실패하면 잠긴 쪽) · `_computed.readOnlyNoticeVisible` → 안내 문구 (응답이 권한 없음일 때만) |
| `admin_memo_form`(수정) | `memoData.data.abilities.can_update`(단건 `resourceMeta`) | 같음 |

- 응답이 오기 전에는 잠그지 않되(`!!data && …`) 저장 버튼은 응답 전에도 잠근다 — 응답 전 저장으로 우회하지 못하게.
- 키 이름은 라우트 미들웨어와 같은 권한 식별자에서 나온다(`MemoCollection`·`MemoResource` 의 `abilityMap()`).

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 액션 핸들러가 없습니다._
<!-- @generated:handlers END -->

<!-- @intent START -->
없습니다. 이 샘플의 화면은 코어 엔진의 기본 핸들러(`apiCall` · `navigate` · `setState` 등)
만으로 충분합니다.

핸들러를 처음 추가할 때는 셋이 함께 필요합니다 — 엔트리 파일, `window.__[Name].initModule()`
재등록 진입점, 그리고 `--production` 으로 구운 `dist/` 커밋. 진입점을 빠뜨리면 로케일 전환
직후 그 핸들러들이 오류 없이 무반응이 됩니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `resources/js/index.ts` |
| 전역 객체 | **미노출** |
| 재등록 진입점 | `initModule()` |

이 엔트리는 액션 핸들러를 등록하지 않으므로 재등록 계약 대상이 아닙니다. 전역 객체는 외부에서 확장 존재를 확인하는 용도로만 남습니다.
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
없습니다 — 등록할 액션 핸들러가 없기 때문입니다.

핸들러를 도입하는 순간 이 진입점이 **필수**가 됩니다. 코어는 로케일 전환 시 확장의 재등록
진입점을 다시 부르는데, 그 함수가 없거나 이름이 다르면 전환 직후 그 확장의 액션이 전부
무반응이 되고 오류도 토스트도 남지 않습니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅
작업을 포함하지 않습니다.
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `dist/css/module.css` | 빌드 산출물 (커밋 대상) |
| `dist/js/hello-module.iife.js` | 빌드 산출물 (커밋 대상) |
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
빌드 산출물은 둘입니다 — 컴포넌트를 자가등록하는 `dist/js/hello-module.iife.js` 와 화면 스타일
`dist/css/module.css`([스타일](#스타일--모듈-자기-css)). 소스(`resources/js/**`, `resources/css/**`)를 고치면
`php artisan module:build gnuboard7-hello_module --production` 으로 다시 굽고 `dist/` 를 커밋한 뒤
`php artisan module:update gnuboard7-hello_module --force` 로 반영합니다.

`module.json` 에는 `assets.js`·`assets.css` 를 **객체 형식**으로 선언합니다
(`"js": { "entry": "resources/js/index.ts", "output": "dist/js/....iife.js" }`,
`"css": { "entry": "resources/css/main.css", "output": "dist/css/module.css", "namespace": "hellomodule" }`).
목록형(`"js": ["..."]`)은 어느 소비자도 읽지 않아 그 자산이 영영 로드되지 않는데, 오류도 경고도 남지 않습니다.

구동에 필요한 제3자 자산은 외부 CDN 에서 받지 않고 확장이 동봉합니다 — CDN 도달 실패는 예외도
서버 로그도 남기지 않고 화면 기능만 조용히 사라지기 때문입니다.
<!-- @intent END -->
