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

사용자 레이어(`PopupLayer`)의 장 전환은 외부 슬라이더 라이브러리 없이 레이어가 직접 하는 가로 이동이다 — 현재 장과 들어올 옆 장 하나만 ±100% 자리에 두고 `transform` 으로 옮기며(전체 트랙 없음), 끝 → 처음도 한 칸 넘김으로 보인다. 이전·다음·점·←→·자동 넘김과 끌기가 모두 같은 이동을 쓴다. 끌기는 마우스·터치 포인터 모두 받는다: 8px 움직인 뒤 가로 성분이 크면 시작해 두 장이 손을 따라 밀리고, 놓을 때 표시 폭의 20% 이상이거나 빠른 휙 넘김이면 옆 장으로, 아니면 제자리로 돌아온다. 세로 움직임은 본문 스크롤에 맡긴다(`touch-action: pan-y pinch-zoom`). 끈 뒤 같은 동작의 click 은 캡처 단계에서 막아 덮는 링크·본문 링크로 이동하지 않는다. 끈 거리는 `getBoundingClientRect()` 의 실제 표시 폭 대비 비율이라 축소된 미리보기 틀 안에서도 맞다. 누르는 동안 자동 넘김을 멈추고, 1장이면 끌기를 켜지 않으며, `prefers-reduced-motion: reduce` 면 미끄러짐 없이 바로 바뀐다. 넘기는 동안 잠깐 보이는 옆 장은 `aria-hidden` + `inert`, 화면 밖 장은 `hidden` 이고, 떠나는 장에 있던 포커스는 레이어로 옮긴다. 슬라이드 DOM 순서와 `data-popup-id` 는 서버 순서 그대로다. 다크 모드는 `.dark` 조상 아래에서 바탕·띠·단추만 바꾼다. 배포 빌드는 `php artisan plugin:build sirsoft-popup --production`(소스맵 없음).

넘김 컨트롤: 점·「n / N」·일시정지는 본문과 하단 바 사이의 별도 띠가 아니라 본문(`.g7pp-body`) 안 아래쪽 가운데에 겹친 캡슐(`.g7pp-pager`)에 있다 — 반투명 어두운 바탕(`backdrop-filter: blur`)·둥근 모서리·흰 점이라 이미지 위와 흰 직접 작성형 본문 위 모두에서 보이고, 높이를 차지하지 않는다. 지금 장 점은 길쭉한 알약(`.g7pp-dot--current`, 폭 전환은 `prefers-reduced-motion: no-preference` 에서만), 나머지는 작은 원이며, 보이는 점은 작아도 단추(누르는 영역)는 44px 이상이다. 캡슐은 줄을 바꾸지 않는다: 한 줄에 들어가는 개수 — PC `MAX_DOTS`(8)·휴대폰 `MAX_DOTS_MOBILE`(5), 창 폭이 더 좁으면 그 폭에서 캡슐 여백·일시정지를 뺀 만큼 — 를 넘으면 점 대신 같은 캡슐에 「n / N」(`.g7pp-counter`)을 둔다. 자동 넘김이면 일시정지·재생 단추(`.g7pp-pager__pause`, `aria-pressed`)가 캡슐 오른쪽 끝에 있다. 이전·다음(`.g7pp-arrow`)은 PC·휴대폰 모두 본문 양옆 가운데에 겹친 원형 단추다. 다크 모드에서는 캡슐·화살표 바탕을 더 어둡게 하고 가는 흰 테두리를 둘러 어두운 본문 위에서도 보이게 한다. 캡슐과 화살표는 덮는 링크(z-index 1)보다 위 층(2)이고, 그 위에서 누르면 본문 가로 끌기를 시작하지 않는다(덮는 링크로도 가지 않는다). 캡슐이 있으면 본문에 `.g7pp-body--paged` 가 붙어 직접 작성형 본문은 아래 여백을 캡슐 높이 + 띄운 거리 × 2(`--g7pp-pager-h`·`--g7pp-pager-gap`)로 늘려 마지막 줄이 가려지지 않는다. 이미지형은 이미지 위에 겹친다. Tab 순서는 장 안 요소 → 이전·다음 → 점 → 일시정지 → 하단 바다. 1장이면 컨트롤이 없다. 관리자 미리보기도 같은 마크업이다.

창 옮기기: 가운데·좌표 창(PC)과 모바일 가운데 창은 하단 바(`.g7pp-footer`, 「보지 않기」·「닫기」 띠)를 마우스·손가락으로 끌어 옮긴다 — 위쪽 손잡이 띠는 따로 두지 않는다. 바가 단추로 채워져 있으므로 단추 위에서 눌러도 된다: 누른 뒤의 움직임·놓음은 창(`window`)에서 받는다(마우스는 누른 요소가 포인터를 잡지 않아, 빠르게 끌면 문턱을 넘는 첫 움직임이 바 밖에서 온다). 8px 이상 움직이면 그때 바가 포인터를 잡아(`setPointerCapture`) 옮기기를 시작하고, 놓은 뒤 같은 동작에서 오는 click 하나를 바의 캡처 단계에서 막아 「닫기」·「보지 않기」가 실행되지 않는다. 8px 에 못 미치고 놓으면 누른 단추가 그대로 동작한다(누를 때 포인터를 잡지 않는 이유 — 잡으면 click 대상이 바로 바뀐다). 손가락으로 끈 뒤에는 브라우저가 click 을 보내지 않으므로 막을 표시는 그 동작이 끝나면 지운다. 옮길 수 있는 바는 `.g7pp-footer--movable`(`cursor: grab`, `touch-action: none` — 단추에도 적용)이고, 끄는 동안 창에 `.g7pp-dialog--held`(글자 선택 없음, `cursor: grabbing`)가 붙는다. 키보드로는 옮기지 않는다(전용 초점 요소 없음 — 하단 바 단추의 ←→ 는 레이어 다른 곳처럼 장을 넘긴다). 옮긴 거리는 인라인 `--g7pp-move-x`·`--g7pp-move-y` 로 주고 CSS `translate` 가 배치용 `transform`(가운데 정렬)과 따로 더한다. 창은 화면 가장자리 8px 안쪽에 놓인다. 화면 맨 윗줄에 붙은 레이어 밖 `fixed`·`sticky` 요소 중 층이 레이어(z-index 40) 이상인 것(템플릿 헤더 등)의 아래, 바닥 GDPR 배너(`#gdpr_cookie_banner`)의 위에만 놓인다. 열릴 때·창 크기가 바뀔 때(`resize` 순간과, 엔진이 늦춰 알린 폭으로 템플릿이 헤더를 다시 그린 렌더 두 번)·배너가 붙고 빠질 때 그 사이로 다시 맞추고, 사이가 창보다 좁으면 본문 상한 `--g7pp-fit-h` 를 줄여 하단 바까지 보이게 한다(층은 올리지 않는다). 그래도 창이 화면보다 크면 하단 바(옮기는 곳이자 「닫기」)가 보이는 쪽을 먼저 지킨다. 본문 높이 상한의 고정 몫은 하단 바·여백 116px(`calc(100vh - 116px)`, 미리보기는 `--g7pp-frame-h - 116px`), 좌표 배치의 화면 안 식은 `- 76px` 이다(넘김 컨트롤은 본문 위에 겹쳐 몫이 없다). 옮긴 자리는 컴포넌트 상태일 뿐이라 경로·목록·배치가 바뀌거나 새로고침하면 원래 자리이고, 쿠키·저장소를 쓰지 않는다. 누르는 동안 자동 넘김을 멈춘다. 모바일 하단 시트는 손잡이 막대가 없고 끌어 내려 닫지 않는다 — 하단 바를 끌어도 아무것도 하지 않으며 단추로만 닫는다. 관리자 미리보기도 하단 바를 끌어 옮기지 않는다.
<!-- @intent END -->
