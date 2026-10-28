# 팝업 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 3개 (루트: `resources/layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `admin` | 3개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `plugin_settings` | `admin` | 화면 | `_admin_base` |
| `popup_form` | `admin` | 화면 | `_admin_base` |
| `popup_list` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
관리자 화면 세 개(목록·등록/수정·환경설정)와 사용자 주입 조각 하나. 미리보기는 사용자 레이어와 같은 컴포넌트(`PopupPreview` → `PopupLayer` 미리보기 모드)를 쓴다 — 관리자가 보는 모양과 방문자가 보는 모양이 갈리지 않게.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 액션 핸들러가 없습니다._
<!-- @generated:handlers END -->

<!-- @intent START -->
커스텀 액션 핸들러가 없다 — 엔진 기본 액션으로 충분하다. 「보지 않기」 쿠키는 레이아웃 값이 아니라 컴포넌트 책임이라 `resources/js/lib/dismissCookie.ts` 에서 쓴다(엔진에 쿠키 액션이 없다).
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_프론트 엔트리포인트가 없습니다._
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
`resources/js/index.ts` 는 컴포넌트 4개(`PopupLayer`·`PopupPreview`·`PopupPeriodHint`·`PopupConflictResolver`)를 레지스트리에 등록만 한다. React 는 external, DOMPurify 는 번들에 포함한다(템플릿 것을 빌리지 않는다).
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `dist/css/plugin.css` | 빌드 산출물 (커밋 대상) |
| `dist/js/plugin.iife.js` | 빌드 산출물 (커밋 대상) |
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
스타일은 `g7pp-` 접두의 플러그인 소유 CSS 다 — 확장 컴포넌트의 유틸리티 클래스는 템플릿 빌드에 들어 있다는 보장이 없다. 관리자 미리보기(`PopupPreview`)는 같은 레이어를 기기 크기 틀 안에 줄여 그리며, 틀이 화면 높이를 CSS 변수 `--g7pp-frame-h` 로 넘기고 미리보기 본문 높이는 그 값으로 잰다.

사용자 레이어(`PopupLayer`)의 장 전환은 외부 슬라이더 라이브러리 없이 레이어가 직접 하는 가로 이동이다 — 현재 장과 들어올 옆 장 하나만 ±100% 자리에 두고 `transform` 으로 옮기며(전체 트랙 없음), 끝 → 처음도 한 칸 넘김으로 보인다. 이전·다음·점·←→·자동 넘김과 끌기가 모두 같은 이동을 쓴다. 끌기는 마우스·터치 포인터 모두 받는다: 8px 움직인 뒤 가로 성분이 크면 시작해 두 장이 손을 따라 밀리고, 놓을 때 표시 폭의 20% 이상이거나 빠른 휙 넘김이면 옆 장으로, 아니면 제자리로 돌아온다. 세로 움직임은 본문 스크롤에 맡긴다(`touch-action: pan-y pinch-zoom`). 끈 뒤 같은 동작의 click 은 캡처 단계에서 막아 덮는 링크·본문 링크로 이동하지 않는다. 끈 거리는 `getBoundingClientRect()` 의 실제 표시 폭 대비 비율이라 축소된 미리보기 틀 안에서도 맞다. 누르는 동안 자동 넘김을 멈추고, 1장이면 끌기를 켜지 않으며, `prefers-reduced-motion: reduce` 면 미끄러짐 없이 바로 바뀐다. 넘기는 동안 잠깐 보이는 옆 장은 `aria-hidden` + `inert`, 화면 밖 장은 `hidden` 이고, 떠나는 장에 있던 포커스는 레이어로 옮긴다. 슬라이드 DOM 순서와 `data-popup-id` 는 서버 순서 그대로다. 다크 모드는 `.dark` 조상 아래에서 바탕·띠·단추만 바꾼다. 배포 빌드는 `php artisan plugin:build sirsoft-popup --production`(소스맵 없음). 장이 8개(`MAX_DOTS`)를 넘으면 점 단추 대신 「n / N」 숫자만 둔다 — 점이 여러 줄로 늘어 아래 띠가 길어지지 않게 한다.

창 옮기기: 가운데·좌표 창(PC)과 모바일 가운데 창은 맨 위 손잡이 띠(`.g7pp-grip`, 28px 단추)를 마우스·손가락으로 끌어 옮긴다. 옮긴 거리는 인라인 `--g7pp-move-x`·`--g7pp-move-y` 로 주고 CSS `translate` 가 배치용 `transform`(가운데 정렬)과 따로 더한다. 손잡이에 포커스를 두면 화살표 키로 16px 씩 옮기고 Home 으로 제자리다(손잡이에서 ←→ 는 장을 넘기지 않는다). 창은 화면 가장자리 8px 안쪽에 놓인다. 화면 맨 윗줄에 붙은 레이어 밖 `fixed`·`sticky` 요소 중 층이 레이어(z-index 40) 이상인 것(템플릿 헤더 등)의 아래, 바닥 GDPR 배너(`#gdpr_cookie_banner`)의 위에만 놓인다. 열릴 때·창 크기가 바뀔 때·배너가 붙고 빠질 때 그 사이로 다시 맞추고, 사이가 창보다 좁으면 본문 상한 `--g7pp-fit-h` 를 줄여 손잡이·닫기 띠가 모두 보이게 한다(층은 올리지 않는다). 옮긴 자리는 컴포넌트 상태일 뿐이라 경로·목록·배치가 바뀌거나 새로고침하면 원래 자리이고, 쿠키·저장소를 쓰지 않는다. 모바일 하단 시트는 손잡이 막대(`.g7pp-handle`)를 아래로 끌어 내리면 닫힌다(높이 30% 이상 또는 빠르게 휙, 「닫기」와 같음). 짧으면 제자리로 미끄러져 돌아오고(움직임 줄이기면 즉시) 위로는 올라가지 않는다. 손잡이 띠·막대는 `touch-action: none` 이고, 누르는 동안 자동 넘김을 멈춘다. 관리자 미리보기는 손잡이를 같은 모양(`aria-hidden`)으로 그리되 옮기지 않는다.
<!-- @intent END -->
