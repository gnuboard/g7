# 페이지 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 3개 (루트: `resources/layouts`).

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
로 반영합니다. 새로 쓴 Tailwind 클래스가 빌드된 CSS 에 없으면 그 스타일만 조용히 빠지므로,
기존 레이아웃에 없던 클래스를 도입할 때는 확인이 필요합니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 액션 핸들러가 없습니다._
<!-- @generated:handlers END -->

<!-- @intent START -->
없습니다. 이 모듈의 관리자 화면은 코어 엔진의 기본 핸들러(`apiCall` · `navigate` · `setState`
등)만으로 충분해서 자체 핸들러를 두지 않았습니다.

그래서 **전역 진입점(`initModule`)도 없고 빌드 산출물(`dist/`)도 없습니다.** 핸들러를 처음
추가할 때는 셋이 함께 필요합니다 — 엔트리 파일, `window.__SirsoftPage.initModule()` 재등록
진입점, 그리고 `module:build --production` 으로 구운 `dist/` 커밋. 진입점을 빠뜨리면 로케일
전환 직후 그 핸들러들이 오류 없이 무반응이 됩니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_프론트 엔트리포인트가 없습니다._
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
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
JS·CSS 산출물이 없고 `editor-spec.json` 하나만 있습니다 — 레이아웃 편집기가 이 모듈의 화면을
편집할 때 쓰는 팔레트·중첩 규칙 선언이며, 실행 코드가 아니라 manifest 입니다.

로딩 설정(`strategy: global`, `priority: 100`)은 골격 기본값이 그대로 남은 것입니다. 실을
자산이 없으므로 현재는 아무 영향이 없지만, 나중에 JS 를 더하면 이 선언이 확장 번들 안에서의
순서를 정하게 됩니다.

`editor-spec.json` 을 고친 뒤에는 빌드 없이 `php artisan module:update sirsoft-page --force`
만 실행합니다. 편집기는 활성 디렉토리 기준으로 서빙하므로 `_bundled` 만 고치면 반영되지
않습니다.
<!-- @intent END -->
