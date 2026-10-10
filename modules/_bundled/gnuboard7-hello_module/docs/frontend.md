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
3개이며 그중 하나가 `user` 그룹인 것이 학습 포인트입니다.

| 레이아웃 | 그룹 | 무엇을 보여주는가 |
|---|---|---|
| `admin_memo_list` · `admin_memo_form` | `admin` | 관리자 목록·작성 화면의 최소 형태 (`_admin_base` 상속) |
| `user_memo_list` | `user` | **모듈도 사용자 레이아웃을 가질 수 있다** (`_user_base` 상속) |

실제 도메인 모듈(게시판·이커머스)은 방문자 화면을 소유하지 않고 템플릿에 맡깁니다 — 템플릿마다
디자인이 달라야 하기 때문입니다. 이 샘플의 `user_memo_list` 는 그 규칙의 예외가 아니라, 구조상
가능하다는 사실을 보이는 예시입니다.

모듈 레이아웃은 위치에 따라 등록 대상이 갈립니다 — `admin/` 하위는 Admin 템플릿에,
`user/` 하위는 User 템플릿에 등록됩니다.

레이아웃 JSON 만 고쳤다면 빌드 없이
`php artisan module:update gnuboard7-hello_module --force` 로 반영합니다.
<!-- @intent END -->

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
| `dist/js/hello-module.iife.js` | 빌드 산출물 (커밋 대상) |
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
없습니다. 이 샘플의 프론트엔드는 레이아웃 JSON 3개뿐이라 빌드할 실행 코드가 없습니다.

그래서 반영이 `php artisan module:update gnuboard7-hello_module --force` 하나로 끝납니다.
JS 를 더하면 그때 빌드(`module:build --production`)·`dist/` 커밋·전역 진입점 셋이 함께
필요해집니다. 그때 `module.json` 에는 `assets.js.output` **객체 형식**으로 선언합니다
(`"assets": { "js": { "entry": "resources/js/index.ts", "output": "dist/js/....iife.js" } }`).
목록형(`"js": ["..."]`)은 어느 소비자도 읽지 않아 그 스크립트가 영영 로드되지 않는데,
오류도 경고도 남지 않습니다.

구동에 필요한 제3자 자산은 외부 CDN 에서 받지 않고 확장이 동봉합니다 — CDN 도달 실패는 예외도
서버 로그도 남기지 않고 화면 기능만 조용히 사라지기 때문입니다.
<!-- @intent END -->
