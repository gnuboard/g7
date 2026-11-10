# Hello 플러그인 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 1개 (루트: `resources/layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `admin` | 1개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `plugin_settings` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
설정 화면(`plugin_settings`) 하나뿐입니다. **플러그인은 완전한 페이지 레이아웃을 등록할 수
없습니다** — 설정 화면과 `layout_extensions`(다른 화면에 끼워 넣는 조각)만 허용됩니다.

`plugin_settings.g7.html` 은 **파일 이름이 계약**입니다. 코어가 플러그인 디렉토리의 이 고정 경로를
찾아 설정 화면을 그리므로, 이름을 바꾸면 설정 화면 자체가 사라집니다.

이 레이아웃은 설정 자동 바인딩 패턴의 예시이기도 합니다 — 입력 항목의 `name` 이 설정 키와
맞으면 값 로드·저장이 자동으로 배선됩니다.

레이아웃 JSON 만 고쳤다면 빌드 없이
`php artisan plugin:update gnuboard7-hello_plugin --force` 로 반영합니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 액션 핸들러가 없습니다._
<!-- @generated:handlers END -->

<!-- @intent START -->
없습니다. 설정 화면은 코어 엔진의 기본 핸들러(`apiCall` · `setState` 등)만으로 충분합니다.

핸들러를 처음 추가할 때는 셋이 함께 필요합니다 — 엔트리 파일,
`window.__[Name].initPlugin()` 재등록 진입점, 그리고 `--production` 으로 구운 `dist/` 커밋.
진입점을 빠뜨리면 로케일 전환 직후 그 핸들러들이 오류 없이 무반응이 됩니다.
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
| `dist/css/plugin.css` | 빌드 산출물 (커밋 대상) |
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
실행 코드(JS)는 없습니다. 화면 모양은 손으로 쓴 **순수 CSS** `resources/css/main.css` 하나가 싣고
(앞글자 `helloplugin`, `plugin.json` 의 `assets.css`), 빌드는 그 파일을 `dist/css/plugin.css` 로 옮기기만 합니다
(Tailwind·전처리기 없음, 압축 없음). 확장 화면 모양을 템플릿 CSS 에 기대지 않고 확장이 스스로 싣는 가장 짧은
예입니다 — 색·테두리 같은 「의미」는 템플릿이 덮을 수 있는 `@layer g7-extension` 안에, 여백·정렬 같은 「배치」는
층 밖에 두고, 다크 모드는 코어의 중립 표식(`data-g7-color-scheme`)만 봅니다. CSS 는 화면 폭을 보지 않습니다 — 폭에 따라 바뀌는
바깥 여백은 레이아웃의 엔진 `responsive` 구간 값이 폭과 무관한 클래스(`helloplugin-settings--md`·`--lg`)를 붙여 고릅니다
(편집기 미리보기 기기 폭도 같은 경계를 탑니다). 폭 미디어로 같은 값을 주던 `helloplugin-page` 는 폐지 예정입니다.

CSS 를 고치면 `php artisan plugin:build gnuboard7-hello_plugin --production` 후 `plugin:update` 로 반영합니다.
레이아웃 JSON 만 고쳤다면 `php artisan plugin:update gnuboard7-hello_plugin --force` 하나로 끝납니다.
JS 를 더하면 그때 엔트리·`dist/js` 커밋·전역 진입점이 함께 필요해집니다.

구동에 필요한 제3자 자산은 외부 CDN 에서 받지 않고 확장이 동봉합니다 — CDN 도달 실패는 예외도
서버 로그도 남기지 않고 화면 기능만 조용히 사라지기 때문입니다. 부득이 외부 호스트가 필요하면
`trusted_script_hosts` 와 **그 사유**를 manifest 에 함께 선언합니다.
<!-- @intent END -->
