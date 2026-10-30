# 히어로섹션 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 3개 (루트: `resources/layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `admin` | 3개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `hero_section_banner_form` | `admin` | 화면 | `_admin_base` |
| `hero_section_banner_list` | `admin` | 화면 | `_admin_base` |
| `plugin_settings` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
관리자 화면 3개 — 목록(`hero_section_banner_list`: 위치 탭·미리보기·위치별 슬라이더 설정·필터·일괄·[순서 변경] 창), 등록/수정(`hero_section_banner_form`: 등록은 `route.id` 없음, 수정은 `/:id/edit`), 환경설정(`plugin_settings`). 경로는 플러그인 네임스페이스(`*/admin/plugins/sirsoft-hero_section/banners…`) 안이다.

- 목록의 위치·상태·검색·정렬·쪽은 전부 URL 쿼리다. 상태 필터·정렬은 서버가 기간 조건으로 처리하고 화면은 쿼리만 바꾼다. 위치·필터·정렬이 바뀌면 선택 묶음(`_global.bulkSelectedIds`)을 비운다.
- 등록·수정 화면은 들어올 때마다 임시 키(`_global.tempKey = $uuid()`)를 만들어 이미지를 그 키로 먼저 올린다. 이미지는 「저장」 때만 배너에 붙는다 — 등록·수정 모두 `temp_key` 와 화면에 남은 이미지 ID(`pc_image_id`·`mobile_image_id`, 모바일을 뺐으면 null)를 보낸다. 저장하지 않고 나가면 사이트 화면은 그대로이고, 남은 임시 이미지는 매일 정리 명령이 지운다.
- 이미지 업로더(`file-uploader`)는 업로드 응답을 첨부 모양으로 받는다 — 서버 `BannerImageResource` 가 그 모양에 url·device·width·height 를 더해 낸다. 등록·수정 모두 두 업로더의 삭제 주소를 비워 화면에서만 뺀다(서버 반영은 저장 때). PC 는 필수라 빼면 저장이 잠기고, 새로 올리면 교체된다. 임시 이미지도 공개 이미지 주소로 내보내므로 저장 전 썸네일·미리보기가 그려진다.
- 업로드 한도는 위치 설정 응답의 `upload.effective_mb`(설정값과 서버 PHP 한도 중 작은 값)를 쓴다 — 플러그인 설정 API 는 설정 관리 권한이라 배너 담당자가 못 읽을 수 있다. 환경설정 화면도 같은 응답으로 서버 한도를 안내한다.

- 등록·수정 입력 순서는 기본 정보 → 이미지 → 이미지 표시 → 문구 → 링크 → 기간이다(사진을 먼저 올려 미리보기를 보며 문구를 쓴다). 미리보기 카드는 좁은 화면에서 `order-first` 로 입력 위에, `lg` 이상에서는 `lg:order-last` 로 오른쪽 열에 고정된다.
- 저장 단추가 잠기면 그 옆 `#hero_save_reason`(`role="status"`, 단추의 `aria-describedby`)에 이유를 하나 보인다 — 올리는 중이 먼저, 다음이 PC 이미지 없음(누르면 `#hero_field_pc_image` 로 스크롤). 저장 중에는 단추 글자가 「저장 중」이라 이유를 따로 보이지 않는다.
- 버튼 문구가 어느 언어에든 있는데 이동 주소가 비면 버튼 칸 아래 경고를, 링크 칸 아래에는 누르면 어떻게 되는지(버튼만 / 배너 전체 / 이동 안 함) 한 줄을 보인다. 조건은 사용자 슬라이더와 같다(버튼 = 문구 + 주소). 저장은 막지 않는다.
- 목록 제목 칸은 제작 상태를 함께 보인다 — 제목이 빈 사이트 활성 언어(위치 설정 `editor.locales` 중 `title_locales` 에 없는 것), 모바일 이미지 없음(`has_mobile_image`), 버튼 문구만 있고 주소 없음(`button_without_link`). 미리보기 제목 옆 「사이트에서 보기」는 `editor.preview_frame.positions[위치].path` 를 새 창으로 열고(없으면 숨김), 그 위치 틀이 null 이면 활성 사용자 템플릿에 자리가 없다고 안내한다.
- 빈 상태는 셋으로 나눈다 — 위치에 배너가 없음(`statistics.positions[위치] === 0`: 권장 크기와 [첫 배너 등록] 카드, 표 빈 문구는 비움), 상태·검색으로 걸러 0건(「조건에 맞는 배너가 없습니다」), 배너는 있는데 지금 노출 0건(미리보기에 예약·비노출·종료 건수).
- 업로더의 pending/compressing/uploading/error 상태를 기기별로 받아 저장을 잠근다. 저장 중에는 업로더도 잠긴다. 폼은 즉시 반영해 초점·배경을 연속으로 바꿀 때 중첩 객체의 앞선 값이 사라지지 않게 한다.
- `HeroBannerPreview`는 실제 PC 1366px·모바일 390px iframe 문서에 슬라이더를 그리고 전체를 축소한다. 부모의 스타일시트만 복사하며 스크립트는 복사하지 않는다. about:blank 초기 문서는 layout effect에서도 초기화한다. 확대 dialog는 Escape와 닫기 단추를 지원하고 포커스를 돌려준다.
- 구좌 틀: `frame`(위치 설정 응답 `editor.preview_frame`)·`position` 을 주면 iframe 폭(기기 viewport)은 그대로 두고 그 안에 활성 사용자 템플릿의 본문 상자 사슬(`chain[].className`·`responsive`)을 같은 템플릿 CSS(`stylesheets`)로 그린 뒤 슬라이더를 가장 안쪽 상자에 넣는다. 반응형 키 선택은 코어 `ResponsiveManager` 규칙(사용자 범위 > 프리셋, 좁은 범위 우선)을 따른다. 틀 높이는 슬라이더 높이만큼 잡고 위쪽은 밀어 올려 감춘다. 템플릿 CSS 는 틀이 늦게 와도 그때 실린다. 틀이 없거나 그 위치에 확장점이 없으면 종전처럼 기기 폭 전체에 그린다.
- 편집(draft) 미리보기는 안내 두 가지를 띄운다 — 지금 기기에서 `.hs__scroll` 에 스크롤이 생기면(배너 1장 그대로, 또는 넘김 단추 자리 `.hs--controlled` 를 잠깐 비워 잰 값) 줄이기 권유, 사진 평균색(16×16 축소)과 문구 배경 절반 세기로 어림한 대비가 제목 3:1·설명 4.5:1 미만이면 추정값과 권장 배경. 둘 다 `role="status"` 안내일 뿐 값을 바꾸거나 저장을 막지 않는다.
- 콘텐츠 언어는 관리자 UI 언어와 독립적이다. `draft`의 문구 값 변화를 비교해 편집 언어를 알리고, 요청 언어 → 사이트 기본 → en → 첫 유효 문구 순으로 고른다. `HeroPreviewLanguage`는 명시적으로 언어를 선택한다.
- `HeroPeriodHint`는 위치 설정 응답의 `editor.timezone`·`editor.server_now`를 이용한다. 시간대 없는 입력을 브라우저 현지 시간으로 파싱하지 않고 사이트 벽시계로 비교한다.
- 사용자 화면은 PC 1920×640·모바일 750×1000 표시 비율을 사용한다. `presentation`의 기기별 초점과 overlay를 미리보기·공개 화면에 같이 반영한다. hover·focusWithin·명시적 pause 중 하나라도 남으면 자동 넘김은 멈춘다.
- 수정 요청은 상세의 `revision`을 보낸다. HTTP 409이면 이동하지 않고 입력과 임시 이미지를 유지한다. 최신본을 다른 탭에서 열어 비교할 수 있다.
- 충돌 패널의 「최신본 불러와 비교」는 상세를 다시 받아 `_local.conflictLatest` 에 두고 `HeroConflictResolver`(base=처음 불러온 상세 또는 직전 적용 기준, mine=`_local.form`, latest)로 항목별 3-way 비교를 그린다. 기본 선택은 다른 운영자만 바꾼 항목=최신, 나만 바꾼 항목=내 값, 둘 다=미선택(적용 잠금). 다른 운영자가 바꾼 이미지는 내 값을 고를 수 없다(옛 id 재전송 방지). 적용하면 최신본을 바탕으로 고른 내 값만 얹은 폼(최신 `revision`)과 다음 비교 기준을 돌려준다. 조회 실패는 `conflictLoadError` 로 다시 시도 단추를 보이며 입력은 건드리지 않는다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 액션 핸들러가 없습니다._
<!-- @generated:handlers END -->

<!-- @intent START -->
커스텀 액션 핸들러를 두지 않는다 — 임시 업로드 키는 `$uuid()`, 순서 변경은 엔진 `<sortable>`, 저장·재조회·토스트는 기본 액션으로 충분하다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_프론트 엔트리포인트가 없습니다._
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
프런트 엔트리(`resources/js/index.ts`)가 `HeroSlider`(사용자 확장점 슬라이더)·`HeroBannerPreview`(관리자 미리보기 — 같은 슬라이더를 기기 틀 안에서 그린다)를 코어 레지스트리에 등록한다(`registerExtensionComponent`).

슬라이더의 넘김은 [Swiper](https://swiperjs.com) 14.3.0(`swiper/react`, 모듈 `Autoplay`·`EffectFade`)이 맡는다 — 플러그인 번들에 함께 빌드한다(정확한 버전 고정, React 는 코어 전역을 쓴다). 화면의 ‹ › 단추·「1 / 3」·일시정지 단추와 캐러셀 접근성 표시(region·slide 역할, 비활성 장 `aria-hidden`)는 컴포넌트가 그리고 Swiper 기본 화살표·점 표시는 쓰지 않는다. 2장 이상이면 무한 반복(`loop`)·손가락 넘기기를 켜고 PC 마우스 끌기(`simulateTouch`)는 끈다. 효과는 slide 500ms · fade 교차 600ms · none(=slide 0ms), 움직임 줄이기면 none·자동 재생 끔. 자동 재생은 Swiper autoplay(설정 간격 ms)이고 마우스·포커스·일시정지 단추에서 `autoplay.stop()` 한다 — 자동으로 넘어가는 동안 장 번호는 `aria-live="off"`. 무한 반복은 슬라이드 DOM 순서를 바꾸므로 각 장에 논리 순서 `data-index` 를 둔다. 전환(0.5초) 중 누른 단추는 Swiper 가 무시한다(`loopPreventsSliding` 기본값). 단위 시험은 `resources/js/__tests__/swiperMock.tsx` 대역으로 연결 계약만 보고, 실제 넘김은 E2E `hero-slider-swiper.spec.ts` 가 본다. 긴 문구 영역 `.hs__scroll` 은 넘칠 때만 탭 순서에 들어오는 이름 붙은 영역이 되고(보이는 장 `tabIndex=0`, 나머지 -1), 손가락 세로 쓸기는 문구 스크롤·가로 쓸기는 장 넘김으로 갈린다 — Swiper 가로 넘기기의 `touch-action: pan-y` 그대로 충돌이 없음을 E2E `long-text.spec.ts`(CDP 터치)가 본다. 버튼 문구가 없는 전체 링크 배너는 `a.hs__link` 를 슬라이드를 덮는 빈 형제(z-index 1)로, `.hs__text` 를 그 위(z-index 2)에 두고 문구 클릭을 링크 `click()` 으로 넘긴다 — 링크 안에 문구 영역이 들어가 중첩 상호작용이 되지 않게 한다(편집 미리보기 iframe 은 `a` 클릭을 막으므로 넘긴 클릭도 막힌다).
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
`resources/js/index.ts` → `dist/js/plugin.iife.js`(React external — 코어가 실은 인스턴스를 빌려 쓴다), `resources/css/hero.css` → `dist/css/plugin.css`. 빌드는 `php artisan plugin:build sirsoft-hero_section --production` 이고 산출물(`dist/`·`components.json`)을 함께 커밋한다.
<!-- @intent END -->
