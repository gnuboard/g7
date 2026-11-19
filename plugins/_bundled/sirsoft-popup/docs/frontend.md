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
관리자 화면 세 개(목록·등록/수정·환경설정)와 사용자 주입 조각 하나. 미리보기는 사용자 레이어와 같은 컴포넌트(`PopupPreview` → `PopupLayer` 미리보기 모드)를 쓴다 — 관리자가 보는 모양과 방문자가 보는 모양이 갈리지 않게. 목록의 삭제, 일괄 삭제, 복사, 순서 변경 창은 바닥 단추를 Modal 직속 `<footer>`(`props.footer`, 관리자 Modal 의 고정 하단)에 둔다 — 본문 상자 안의 단추 줄로 두면 본문과 함께 스크롤되고 640px 이하에서 세로로 쌓이지 않는다(코어 `docs/frontend/modal-usage.md` 「Footer 버튼 배치」, 시험 `popup_list.test.tsx`). 목록 표(`popup_datagrid`, 템플릿 `DataGrid`)는 서버 페이지(`pagination.last_page`·`has_more_pages`)로 넘기므로 `serverSidePagination` 과 함께 `pagination: true` 를 준다 — `DataGrid` 의 `pagination` 기본값이 false 라 빠지면 페이지 이동 줄이 그려지지 않는다.

화면 폭에 따라 배치가 바뀌는 곳은 Tailwind 반응형 접두(`gpp:md:…` 등) 대신 엔진 `<responsive on="…">` 덮어쓰기로 쓴다. 덮어쓰기는 className 을 통째로 바꾸므로 그 구간에서 살아 있는 클래스를 모두 적는다 — 노출 요약의 「4번째부터 휴대폰 폭에서 접기」처럼 식(`{{ }}`)이 고르는 클래스도 구간마다 식을 나눠 싣는다(`popup_list` 의 `summary_items` 항목·「더 보기」 항목).

폭 미디어를 품은 의미 클래스 사본(`g7pp-grid-3col-responsive`·`g7pp-grid-2col-responsive`·`g7pp-page-title`·`g7pp-admin-page-content-responsive`·`g7pp-page-header` 등)도 쓰지 않습니다. 같은 값을 접두 유틸과 `responsive` 구간으로 적습니다 — 예를 들어 넓은 화면에서 3열이 되는 입력 줄은 `class="gpp:grid gpp:grid-cols-1 gpp:gap-4 gpp:items-start"` 에 `<responsive on="1024-" class="gpp:grid gpp:grid-cols-3 gpp:gap-4 gpp:items-start"/>` 를 둡니다. 알림 상자 다음 칸 여백처럼 그 클래스에 기대던 형제 규칙은 그 자리에 여백 유틸(`gpp:mb-4` 등)을 직접 줍니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 액션 핸들러가 없습니다._
<!-- @generated:handlers END -->

<!-- @intent START -->
커스텀 액션 핸들러가 없다 — 엔진 기본 액션으로 충분하다. 「보지 않기」 쿠키는 레이아웃 값이 아니라 컴포넌트 책임이라 `resources/js/lib/dismissCookie.ts` 에서 쓴다(엔진에 쿠키 액션이 없다).

「닫기」·「보지 않기」로 레이어를 닫으면 **이 경로의 후보 전부**를 이 페이지 동안 닫힘으로 기억한다(`popupVisibility.closableOnPage` → `rememberClosed`, 새로고침하면 초기화). 보이던 팝업만 기억하면 같은 페이지에서 창 폭이 바뀌어(창 줄이기·태블릿 회전) 기기 판정이 달라질 때 다른 기기 전용 팝업이 레이어를 다시 연다(#902). 「보지 않기」 쿠키에는 결정대로 **누를 때 보이던 팝업만** 적는다 — 보지 않은 팝업을 날짜 단위로 숨기지 않는다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_프론트 엔트리포인트가 없습니다._
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
`resources/js/index.ts` 는 컴포넌트 5개(`PopupLayer`·`PopupPreview`·`PopupPeriodHint`·`PopupConflictResolver`·`PopupOverlayEditor`)를 레지스트리에 등록만 한다. 레이어·미리보기·편집기가 함께 쓰는 이미지 프레임(`resources/js/lib/ImageFrame.tsx`)은 레이아웃용 컴포넌트가 아니라 `components/` 밖에 둔다 — `components/` 아래 파일은 빌드가 모두 `components.json` 에 레이아웃용으로 싣는다. React 는 external, DOMPurify 는 번들에 포함한다(템플릿 것을 빌리지 않는다).
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
스타일은 플러그인이 자기 CSS 로 싣는다(확장 CSS 자기 공급 규약 — manifest `assets.css.namespace` = `g7pp`). 손으로 쓴 규칙은 `g7pp-…`, 관리자 레이아웃·보조 컴포넌트의 유틸리티는 접두 Tailwind 빌드 `gpp:…`(진입 `resources/css/main.css`, 템플릿 의미 클래스는 변환기가 `g7pp-…` 로 복사한 `g7-extension.generated.css`)다. 다크는 템플릿 `.dark` 가 아니라 사이트 중립 표식 `<html data-g7-color-scheme="dark">` 로 읽는다. 레이아웃 클래스를 바꾸면 `node scripts/extension-css/convert-layout-classes.mjs plugins/_bundled/sirsoft-popup` 을 다시 돌린다(멱등). 관리자 미리보기(`PopupPreview`)는 같은 레이어를 기기 크기 틀 안에 줄여 그리며, 틀이 화면 높이를 CSS 변수 `--g7pp-frame-h` 로 넘기고 미리보기 본문 높이는 그 값으로 잰다. 직접 작성형 본문은 `form` props 만 믿지 않는다 — CKEditor 5 는 타이핑마다 `setLocal({ render: false, selfManaged: true })` 로 상태에만 써서 다른 칸이 화면을 다시 그릴 때까지 props 가 옛 본문에 머물므로, `G7Core.state.subscribe()`(다시 그리기를 건너뛰는 쓰기도 알린다)로 `_local.form.body_html` 을 받아 그리고, props 가 바뀌면 props 를 정본으로 돌린다. 새로 마운트되는 미리보기(실제 크기 창)는 알림이 이미 지나갔고 부모 `_local` 스냅숏에도 그 본문이 없으므로, 마운트할 때 `G7Core.state.get()._local.form.body_html` 을 한 번 읽는다. `actualSize` 면 틀을 줄이지 않고(배율 1) 기기 크기 그대로 그리며 `.g7pp-frame-scroll`(최대 75vh)이 넘치는 부분을 스크롤하고, 열린 다음 프레임에 팝업(`.g7pp-dialog`)이 칸 가운데 오게 스크롤해 둔다(좁은 휴대폰에서 PC 1280 틀) — 등록·수정·환경설정의 「실제 크기로 보기」 모달이 부모 `_local` 스냅숏(`$parent._local`)으로 같은 미리보기를 띄운다.

사용자 레이어(`PopupLayer`)의 장 전환은 외부 슬라이더 라이브러리 없이 레이어가 직접 하는 가로 이동이다 — 현재 장과 들어올 옆 장 하나만 ±100% 자리에 두고 `transform` 으로 옮기며(전체 트랙 없음), 끝 → 처음도 한 칸 넘김으로 보인다. 이전·다음·점·←→·자동 넘김과 끌기가 모두 같은 이동을 쓴다. 끌기는 마우스·터치 포인터 모두 받는다: 8px 움직인 뒤 가로 성분이 크면 시작해 두 장이 손을 따라 밀리고, 놓을 때 표시 폭의 20% 이상이거나 빠른 휙 넘김(놓기 직전 100ms 동안 끈 방향으로 0.5px/ms 이상, 폭의 4% 이상 — 누른 순간부터의 평균이 아니라서 잠깐 누르고 있다 휙 밀어도 넘어간다)이면 옆 장으로, 아니면 제자리로 돌아온다. 세로 움직임은 본문 스크롤에 맡긴다(`touch-action: pan-y pinch-zoom`). 브라우저는 손가락이 닿은 곳에서 가장 가까운 스크롤 영역까지만 `touch-action` 을 모아 읽으므로, 스크롤 영역인 직접 작성형 본문(`.g7pp-html`)과 그 안에도 같은 값을 다시 둔다 — 빠지면 그 장에서 가로 터치를 브라우저가 가져가(`pointercancel`) 손가락으로 넘겨지지 않는다. 끈 뒤 같은 동작의 click 은 캡처 단계에서 막아 덮는 링크·본문 링크로 이동하지 않는다. 끈 거리는 `getBoundingClientRect()` 의 실제 표시 폭 대비 비율이라 축소된 미리보기 틀 안에서도 맞다. 누르는 동안 자동 넘김을 멈추고, 1장이면 끌기를 켜지 않으며, `prefers-reduced-motion: reduce` 면 미끄러짐 없이 바로 바뀐다. 넘기는 동안 잠깐 보이는 옆 장은 `aria-hidden` + `inert`, 화면 밖 장은 `hidden` 이고, 떠나는 장에 있던 포커스는 레이어로 옮긴다. 슬라이드 DOM 순서와 `data-popup-id` 는 서버 순서 그대로다. 다크 모드는 `.dark` 조상 아래에서 바탕·띠·단추만 바꾼다. 배포 빌드는 `php artisan plugin:build sirsoft-popup --production`(소스맵 없음).

넘김 컨트롤: 점·「n / N」·일시정지는 본문과 하단 바 사이의 별도 띠가 아니라 본문(`.g7pp-body`) 안 아래쪽 가운데에 겹친 캡슐(`.g7pp-pager`)에 있다 — 반투명 어두운 바탕(`backdrop-filter: blur`)·둥근 모서리·흰 점이라 이미지 위와 흰 직접 작성형 본문 위 모두에서 보이고, 높이를 차지하지 않는다. 지금 장 점은 길쭉한 알약(`.g7pp-dot--current`, 폭 전환은 `prefers-reduced-motion: no-preference` 에서만), 나머지는 작은 원이며, 보이는 점은 작아도 단추(누르는 영역)는 44px 이상이다. 캡슐은 줄을 바꾸지 않는다: 한 줄에 들어가는 개수 — PC `MAX_DOTS`(8)·휴대폰 `MAX_DOTS_MOBILE`(5), 창 폭이 더 좁으면 그 폭에서 캡슐 여백·일시정지를 뺀 만큼 — 를 넘으면 점 대신 같은 캡슐에 「n / N」(`.g7pp-counter`)을 둔다. 자동 넘김이면 일시정지·재생 단추(`.g7pp-pager__pause`, `aria-pressed`)가 캡슐 오른쪽 끝에 있다. 이전·다음(`.g7pp-arrow`)은 PC·휴대폰 모두 본문 양옆 가운데에 겹친 원형 단추다. 다크 모드에서는 캡슐·화살표 바탕을 더 어둡게 하고 가는 흰 테두리를 둘러 어두운 본문 위에서도 보이게 한다. 캡슐과 화살표는 덮는 링크(z-index 1)보다 위 층(2)이고, 그 위에서 누르면 본문 가로 끌기를 시작하지 않는다(덮는 링크로도 가지 않는다). 캡슐이 있으면 본문에 `.g7pp-body--paged` 가 붙어 직접 작성형 본문은 아래 여백을 캡슐 높이 + 띄운 거리 × 2(`--g7pp-pager-h`·`--g7pp-pager-gap`)로 늘려 마지막 줄이 가려지지 않는다. 이미지형은 이미지 위에 겹친다. Tab 순서는 장 안 요소 → 이전·다음 → 점 → 일시정지 → 하단 바다. 1장이면 컨트롤이 없다. 관리자 미리보기도 같은 마크업이다.

창 옮기기: 가운데·좌표 창(PC)과 모바일 가운데 창은 하단 바(`.g7pp-footer`, 「보지 않기」·「닫기」 띠)를 마우스·손가락으로 끌어 옮긴다 — 위쪽 손잡이 띠는 따로 두지 않는다. 바가 단추로 채워져 있으므로 단추 위에서 눌러도 된다: 누른 뒤의 움직임·놓음은 창(`window`)에서 받는다(마우스는 누른 요소가 포인터를 잡지 않아, 빠르게 끌면 문턱을 넘는 첫 움직임이 바 밖에서 온다). 8px 이상 움직이면 그때 바가 포인터를 잡아(`setPointerCapture`) 옮기기를 시작하고, 놓은 뒤 같은 동작에서 오는 click 하나를 바의 캡처 단계에서 막아 「닫기」·「보지 않기」가 실행되지 않는다. 8px 에 못 미치고 놓으면 누른 단추가 그대로 동작한다(누를 때 포인터를 잡지 않는 이유 — 잡으면 click 대상이 바로 바뀐다). 손가락으로 끈 뒤에는 브라우저가 click 을 보내지 않으므로 막을 표시는 그 동작이 끝나면 지운다. 옮길 수 있는 바는 `.g7pp-footer--movable`(`cursor: grab`, `touch-action: none` — 단추에도 적용)이고, 끄는 동안 창에 `.g7pp-dialog--held`(글자 선택 없음, `cursor: grabbing`)가 붙는다. 키보드로는 옮기지 않는다(전용 초점 요소 없음 — 하단 바 단추의 ←→ 는 레이어 다른 곳처럼 장을 넘긴다). 옮긴 거리는 인라인 `--g7pp-move-x`·`--g7pp-move-y` 로 주고 CSS `translate` 가 배치용 `transform`(가운데 정렬)과 따로 더한다. 창은 화면 가장자리 8px 안쪽에 놓인다. 화면 맨 윗줄에 붙은 레이어 밖 `fixed`·`sticky` 요소 중 층이 레이어(z-index 40) 이상인 것(템플릿 헤더 등)의 아래, 바닥 GDPR 배너(`#gdpr_cookie_banner`)의 위에만 놓인다. 열릴 때·창 크기가 바뀔 때(`resize` 순간과, 엔진이 늦춰 알린 폭으로 템플릿이 헤더를 다시 그린 렌더 두 번)·배너가 붙고 빠질 때 그 사이로 다시 맞추고, 사이가 창보다 좁으면 본문 상한 `--g7pp-fit-h` 를 줄여 하단 바까지 보이게 한다(층은 올리지 않는다). 그래도 창이 화면보다 크면 하단 바(옮기는 곳이자 「닫기」)가 보이는 쪽을 먼저 지킨다. 본문 높이 상한의 고정 몫은 하단 바·여백 116px(`calc(100vh - 116px)`, 미리보기는 `--g7pp-frame-h - 116px`), 좌표 배치의 화면 안 식은 `- 76px` 이다(넘김 컨트롤은 본문 위에 겹쳐 몫이 없다). 옮긴 자리는 컴포넌트 상태일 뿐이라 경로·목록·배치가 바뀌거나 새로고침하면 원래 자리이고, 쿠키·저장소를 쓰지 않는다. 누르는 동안 자동 넘김을 멈춘다. 모바일 하단 시트는 손잡이 막대가 없고 끌어 내려 닫지 않는다 — 하단 바를 끌어도 아무것도 하지 않으며 단추로만 닫는다. 관리자 미리보기도 하단 바를 끌어 옮기지 않는다.
<!-- @intent END -->

**이미지 위 글 상자** — 이미지형 장은 `.g7pp-media`(크기 컨테이너, `container-type: size`) 안에 이미지 프레임 `.g7pp-media__frame` 을 둔다. 프레임은 `aspect-ratio: var(--g7pp-ratio)` 에 `width: min(100cqw, 100cqh × 비율)` 이라 이미지를 잘리지 않게 담는(`contain`) 것과 같은 사각형이 되고, 이미지는 그 프레임을 꽉 채운다. 글 상자(`.g7pp-overlay`)는 그 프레임 기준 `left/top/width` % 로 놓이고 글자 크기는 CSS 변수 `--g7pp-ov-size` × `1cqw`(프레임이 `container-type: inline-size`)라, 창·기기·미리보기 축소와 무관하게 이미지에 붙어 함께 커지고 작아진다. 서버가 이미지 크기를 읽지 못했으면(응답 `width/height` null) 이미지를 받은 뒤 `naturalWidth/Height` 로 비율을 정하고 그 전에는 상자를 그리지 않는다(`--unsized` 는 예전처럼 칸 전체에 담는다). 상자는 `pointer-events: none`·`user-select: none` 이라 상자 위 누름은 덮는 링크로, 상자 위 끌기는 장 넘김으로 간다. 프레임은 `overflow: hidden` 이라 글이 길어(다른 언어 등) 넘친 부분은 이미지 경계에서 잘린다 — 편집기 캔버스만 `overflow: visible` 로 넘친 부분을 보이고, 언어마다 보이지 않는 복제본(`data-measure-box`)으로 높이를 재 넘치는 상자·언어를 경고한다. 컨테이너 단위를 모르는 브라우저(`@supports not (width: 1cqw)` — Chrome 105·Safari 16 미만)는 예전처럼 이미지만 칸에 담고 상자를 숨긴다. 편집기는 상자가 있는데 이미지 비율이 2% 넘게 바뀌면 위치 재확인을 알리고(`ImageFrame` 의 `onRatio`), 저장 오류 키(`overlays.{기기}.{순번}…`)의 상자에 오류 표시를 하며, 휴대폰에 보이는 배치면 작은 휴대폰(이미지 폭 320px) 기준 예상 글자 크기를 보이고 12px 미만이면 알린다. 「바탕 깔기」 상자는 글자·바탕 대비(`resources/js/lib/overlays.ts` `boxContrast` — 바탕이 반투명하면 이미지가 흰색·검은색일 때 중 나쁜 쪽)를 보이고 4.5:1 미만이면 알린다(바탕 없는 상자는 이미지 색을 알 수 없어 재지 않는다). 사이트 다크·라이트 모드는 글 상자 색을 바꾸지 않지만 투명 이미지 뒤에는 팝업 바탕(`--g7pp-bg`)이 비치므로, 캔버스 바탕을 투명 표시·라이트(#ffffff)·다크(#1f2937)로 바꿔 미리 본다. 서버 검증 오류의 칸 이름은 「PC 글 상자 :position」(검증기가 1부터 센 순번으로 바꾼다)이라 어느 상자인지 문구로도 알 수 있다. 휴대폰에서 모바일 이미지를 쓰면 모바일 배치(없으면 PC 배치), 모바일 이미지가 없으면 PC 이미지와 PC 배치(`resources/js/lib/overlays.ts` `boxesFor`). 관리자 편집기(`PopupOverlayEditor`)는 같은 `ImageFrame`·`overlayStyle` 로 캔버스를 그려 사용자 화면과 같은 % 를 보장하고, 끄는 동안은 화면만 따라오고 놓을 때 한 번만 `onChange` 로 폼에 돌려준다(움직일 때마다 폼 전체를 다시 그리지 않게).

**숨은 장 내용 지연** — 여러 장 캐러셀에서 장마다 `PopupContent`(이미지·본문 HTML)를 그리는 것은 지금 장, 다음 장(`(지금 + 1) % 장 수`), 끄는·넘기는 동안 보이는 옆 장(`motion.peer`), 이미 한 번 그린 장(팝업 id 를 `useRef` Set 에 기억)뿐이다. 나머지 장은 빈 자리(`.g7pp-slide` 의 `hidden`·`aria-hidden`·`inert` 규칙은 같다)라 숨은 장의 이미지와 본문 `<img>` 를 첫 방문에 받지 않는다. 점으로 먼 장에 가면 그 장이 지금 장이 되는 렌더에서 바로 그린다. 이미지를 바로 받는(`loading="eager"`, `fetchPriority="high"`) 장은 지금 장뿐이고 나머지는 `lazy` 다. 옮길 수 있는 창(PC 창·휴대폰 가운데 창)은 본문 상한(`fit`)을 잰 뒤에 내용을 붙인다 — 재기 전에 이미지를 붙이면 fit 없는 `sizes` 로 큰 후보를 먼저 요청했다가 끊고 작은 후보를 다시 받는다. fit 은 열리는 렌더의 layout effect 에서 정해져 그리기 전에 다시 렌더하므로 빈 창이 보이지 않는다.

**이미지 썸네일** — 사용자 레이어(`PopupLayer` → `PopupContent` → `ImageFrame`)는 레이어 배치로 정한 `sizes`(`resources/js/lib/thumbnail.ts` `popupImageSizes` — 칸 폭은 PC `(max-width: {폭+32}px) calc(100vw - 32px), {폭}px`, 휴대폰 가운데 창 `calc(100vw - 48px)`, 하단 시트 `100vw`. 이미지는 칸에 잘리지 않게(`contain`) 담기므로 장마다 제 이미지 비율을 알면 칸 높이(`.g7pp-body` 높이 식과 같은 PC `min({설정 높이}px, 100vh - 116px, {fit}px)`, 가운데 창 `min(70vh, 420px, 100vh - 116px, {fit}px)`, 하단 시트 `min(60vh, 380px, 100vh - 116px)` — `{fit}` 은 레이어가 헤더·GDPR 배너를 피해 줄인 본문 상한(`--g7pp-fit-h`, `fitHeight`)이고 없으면 그 항을 빼며, 빼면 PC 1671×770 에서 320px 로 그리면서 390px 로 계산해 한 단계 큰 후보를 골랐다) × 비율과 `min()` 으로 견준다 — 375 하단 시트의 960×1280 은 표시 285px 인데 `100vw` 로 원본을 고르던 것을 막는다. 미리보기는 기기 틀 안이라 PC 설정 폭·휴대폰 `767px` 상한과 칸 높이 상한 × 비율을 견준 숫자)를 `PopupContent` 가 보이는 이미지로 정해 `ImageFrame` 에 주고, (`ImageFrame` 의 `<img>` 는 이미지 주소를 `key` 로 써서 기기 전환으로 이미지가 바뀌면 새로 만들고, 속성은 `sizes` → `srcSet` → `src` 순서로 적는다 — 같은 img 를 갱신하면 새 sizes 가 옛 srcset 과 먼저 만나 WebKit 이 옛 이미지를 한 번 더 고르고, srcset 이 sizes 보다 먼저 붙으면 `100vw` 로 원본을 한 번 더 받는다) 프레임은 원본 가로를 알 때만 `responsiveSrcset()` 으로 srcset 을 붙인다. 후보는 코어 공개 API `G7Core.thumbnail.contentSrcsetWithin(주소, 원본 가로, { includeOriginal: true })` 가 만든다 — 「숫자 요청 대상」 규격(기본 md·lg·xl) 중 **그 원본 가로보다 작은 것**과 원본(`{주소} {width}w`)이다. 플러그인은 후보를 직접 거르지 않는다 — 템플릿 본문 이미지와 같은 정책이다. PC·모바일 이미지는 각자의 원본이라 각자 후보가 정해진다. 글 상자 편집기는 `sizes` 를 주지 않아 원본 그대로 그린다. 코어 함수 `contentSrcsetWithin` 이 없거나 서빙 경로(`/api/plugins/sirsoft-popup/images/*`, 리스너가 코어에 등록)가 아니면 원본 하나다. 관리 목록·노출 요약·순서 변경 창은 `thumbPreset="sm"`.
