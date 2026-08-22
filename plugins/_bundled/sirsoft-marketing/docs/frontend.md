# 마케팅 동의 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 1개 (루트: `resources/layouts`).

| 그룹 | 개수 |
|---|---|
| `admin` | 1개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `plugin_settings` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
관리자 설정 화면(`plugin_settings`) 하나뿐입니다. **이 플러그인의 실제 UI 는 레이아웃이 아니라
확장 조각 5개**이며, 그것들은 다른 화면 안에 들어갑니다.

`plugin_settings.json` 은 **파일 이름이 계약**입니다. 코어가 플러그인 디렉토리의 이 고정 경로를
찾아 설정 화면을 그리므로, 이름을 바꾸면 설정 화면 자체가 사라집니다.

레이아웃 JSON 만 고쳤다면 빌드는 필요 없고 `php artisan plugin:update sirsoft-marketing --force`
로 반영합니다. 새로 쓴 Tailwind 클래스가 빌드된 CSS 에 없으면 그 스타일만 조용히 빠지므로,
기존 레이아웃에 없던 클래스를 도입할 때는 확인이 필요합니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 액션 핸들러가 없습니다._
<!-- @generated:handlers END -->

<!-- @intent START -->
없습니다. 동의 항목은 일반 체크박스이고 저장은 코어 회원 API 를 타므로, 자체 핸들러가
필요하지 않습니다.

체크박스를 다룰 때 주의할 점이 하나 있습니다 — 저장값이 `null` 일 수 있는 체크박스는
`name` 자동바인딩만으로 묶으면 값이 `null` 로 고착됩니다. 조각에서 동의 체크박스를 손볼 때는
`autoBinding: false` + `checked` 표현식 + `change` 액션 형태를 유지합니다.

핸들러를 처음 추가한다면 전역 진입점(`window.__SirsoftMarketing.initPlugin()`)과 빌드 산출물
(`dist/`)이 함께 필요합니다.
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
JS·CSS 산출물이 없고 `editor-spec.json` 하나만 있습니다 — 레이아웃 편집기가 이 플러그인의
화면을 편집할 때 쓰는 팔레트·중첩 규칙 선언이며 실행 코드가 아닙니다. 이 플러그인의 프론트엔드는
전부 **선언형 JSON**(레이아웃 조각 5개 + 설정 화면 1개 + 편집기 스펙)입니다.

그래서 빌드 단계가 없고, 변경 반영은 `php artisan plugin:update sirsoft-marketing --force`
하나로 끝납니다. 나중에 JS 를 더하면 그때 빌드·`dist/` 커밋·전역 진입점 셋이 함께 필요해집니다.

구동에 필요한 제3자 자산은 외부 CDN 에서 받지 않고 확장이 동봉합니다 — CDN 도달 실패는 예외도
서버 로그도 남기지 않고 화면 기능만 조용히 사라지기 때문입니다.
<!-- @intent END -->
