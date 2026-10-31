# 페이지 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 3개 (루트: `resources/layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `admin` | 3개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `admin_page_detail` | `admin` | 화면 | `_admin_base` |
| `admin_page_form` | `admin` | 화면 | `_admin_base` |
| `admin_page_list` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
3개 전부 관리자 화면입니다 — 목록(`admin_page_list`) · 작성/수정(`admin_page_form`) ·
상세(`admin_page_detail`). 부분 레이아웃이 없을 만큼 화면이 단순합니다.

방문자가 보는 페이지 화면은 여기에 없습니다. 템플릿(`sirsoft-basic`)이 `GET /pages/{slug}` 를
호출해 그리므로, 페이지의 **보이는 모습**을 바꾸는 작업은 이 모듈이 아니라 그 템플릿 쪽입니다.

레이아웃 JSON 만 고쳤다면 빌드는 필요 없고 `php artisan module:update sirsoft-page --force`
로 반영합니다. 기존 레이아웃에 없던 클래스(`page:` 유틸리티·`page-*`)를 새로 쓰면 이 모듈 CSS 에 아직 없으므로
`module:build` 로 다시 만듭니다(아래 「에셋」).

작성 화면의 첨부 업로더는 한도를 서버 공개 설정(`attachmentSettings` = `publicLimits()`)에서 그대로 받습니다.
개수 상한이 없으면 서버가 `max_count: null` 을 내므로 `maxFiles` 를 비워(`?? undefined`) 컴포넌트 기본값을 쓰고,
허용 형식이 비면 빈 `accept` 가 모든 형식을 열므로 `||` 로 기본 목록에 떨어뜨립니다. `??` 로 숫자 기본값을 주면
"제한 없음" 이 그 숫자 상한으로 바뀝니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 1개 (정의: `resources/js/handlers/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `downloadAttachment` | `sirsoft-page.downloadAttachment` |
<!-- @generated:handlers END -->

<!-- @intent START -->
하나뿐입니다 — 관리자 페이지 상세의 첨부 카드가 부르는 `sirsoft-page.downloadAttachment`.
나머지 화면 동작은 코어 엔진의 기본 핸들러(`apiCall` · `navigate` · `setState` 등)로 충분합니다.

이 핸들러를 모듈이 직접 두는 이유는 둘입니다. 첨부 내려받기 주소는 미발행 페이지의 첨부를 읽기
권한이 있는 운영자에게만 내주므로 `<a href>` 로는 받을 수 없고(로그인 토큰이 실리지 않음) 코어
ApiClient 로 blob 을 받아야 합니다. 그런데 코어에는 내려받기 핸들러가 없고, 같은 이름의 핸들러를
관리자 템플릿(`sirsoft-admin_basic`)이 등록해 두었지만 이 화면은 **어느 관리자 템플릿으로
그려지든** 같게 동작해야 하므로 특정 템플릿의 핸들러에 기대지 않습니다. 핸들러는 실패하면 예외를
던지고, 안내 문구는 레이아웃의 `onError` 가 띄웁니다.

핸들러를 더하거나 고치면 셋이 함께 움직입니다 — `resources/js/handlers/index.ts` 등록,
`window.__SirsoftPage.initModule()` 재등록 진입점, `--production` 으로 구운 `dist/` 커밋.
진입점을 빠뜨리면 로케일 전환 직후 그 핸들러들이 오류 없이 무반응이 됩니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `resources/js/index.ts` |
| 전역 객체 | `window.__SirsoftPage` |
| 재등록 진입점 | `initModule()` |

로케일 전환 시 코어가 이 진입점을 호출해 핸들러를 다시 등록합니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
`resources/js/index.ts` 가 핸들러를 `sirsoft-page.{이름}` 으로 등록하고 `window.__SirsoftPage` 에
`initModule()` 을 노출합니다.

이 진입점은 **필수**입니다. 코어는 로케일 전환 시 확장의 재등록
진입점을 다시 부르는데, 그 함수가 없거나 이름이 다르면 전환 직후 그 확장의 액션이 전부
무반응이 되고 오류도 토스트도 남지 않습니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅
작업을 포함하지 않습니다.
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `dist/css/module.css` | 빌드 산출물 (커밋 대상) |
| `dist/js/module.iife.js` | 빌드 산출물 (커밋 대상) |
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
JS 번들 `dist/js/module.iife.js` 는 액션 핸들러만 담은 경량 IIFE 입니다(컴포넌트 없음).
화면 스타일은 이 모듈 자기 CSS 입니다 — `resources/css/main.css` 를 JS 엔트리가 불러와 빌드가 `dist/css/module.css` 로
냅니다(module.json `assets.css`, 앞글자 `namespace: page`). 템플릿 CSS 라이브러리에 기대지 않습니다.
배치·색 유틸리티는 `page:` 접두 Tailwind(이 모듈의 작성 도구, preflight 없음)가 레이아웃에 쓰인 것만 만들고,
템플릿 의미 클래스는 `page-*` 사본(`resources/css/g7-extension.generated.css`, 가장 낮은 칸 `@layer g7-extension`)을
씁니다. 이 모듈 고유의 호버·다크 짝과 의미 색(`page-tone-*`)은 생성 블록 아래 손 CSS 이고, 다크 모드는 템플릿의
`.dark` 가 아니라 코어 중립 표식(`data-g7-color-scheme`)을 봅니다. 레이아웃에 새 클래스를 쓰면
`php artisan module:build sirsoft-page --production` 으로 CSS 를 다시 만듭니다(규약: 코어 문서
`docs/extension/module-assets.md` 「확장 CSS 자기 공급 규약」).

소스는 `resources/js/` 이고 `php artisan module:build sirsoft-page --production` 으로 굽습니다
(소스맵을 남기지 않는 배포용 빌드). 굽고 나면 `dist/`(`dist/css/` 포함)를 함께 커밋합니다.

`editor-spec.json` 은 레이아웃 편집기가 이 모듈의 화면을 편집할 때 쓰는 팔레트·중첩 규칙
선언이며, 실행 코드가 아니라 manifest 입니다.

로딩 설정(`strategy: global`, `priority: 100`)은 이 번들을 모든 화면에서 앱 초기화 때 싣습니다.
다른 확장의 전역·핸들러에 기대지 않으므로 순서에 민감하지 않습니다.

`editor-spec.json` 을 고친 뒤에는 빌드 없이 `php artisan module:update sirsoft-page --force`
만 실행합니다. 편집기는 활성 디렉토리 기준으로 서빙하므로 `_bundled` 만 고치면 반영되지
않습니다.
<!-- @intent END -->
