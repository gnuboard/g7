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

- 업로더의 pending/compressing/uploading/error 상태를 기기별로 받아 저장을 잠근다. 저장 중에는 업로더도 잠긴다. 폼은 즉시 반영해 초점·배경을 연속으로 바꿀 때 중첩 객체의 앞선 값이 사라지지 않게 한다.
- `HeroBannerPreview`는 실제 PC 1366px·모바일 390px iframe 문서에 슬라이더를 그리고 전체를 축소한다. 부모의 스타일시트만 복사하며 스크립트는 복사하지 않는다. about:blank 초기 문서는 layout effect에서도 초기화한다. 확대 dialog는 Escape와 닫기 단추를 지원하고 포커스를 돌려준다.
- 콘텐츠 언어는 관리자 UI 언어와 독립적이다. `draft`의 문구 값 변화를 비교해 편집 언어를 알리고, 요청 언어 → 사이트 기본 → en → 첫 유효 문구 순으로 고른다. `HeroPreviewLanguage`는 명시적으로 언어를 선택한다.
- `HeroPeriodHint`는 위치 설정 응답의 `editor.timezone`·`editor.server_now`를 이용한다. 시간대 없는 입력을 브라우저 현지 시간으로 파싱하지 않고 사이트 벽시계로 비교한다.
- 사용자 화면은 PC 1920×640·모바일 750×1000 표시 비율을 사용한다. `presentation`의 기기별 초점과 overlay를 미리보기·공개 화면에 같이 반영한다. hover·focusWithin·명시적 pause 중 하나라도 남으면 자동 넘김은 멈춘다.
- 수정 요청은 상세의 `revision`을 보낸다. HTTP 409이면 이동하지 않고 입력과 임시 이미지를 유지한다. 최신본을 다른 탭에서 열어 비교할 수 있다.
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

슬라이더의 넘김은 [Swiper](https://swiperjs.com) 14.3.0(`swiper/react`, 모듈 `Autoplay`·`EffectFade`)이 맡는다 — 플러그인 번들에 함께 빌드한다(정확한 버전 고정, React 는 코어 전역을 쓴다). 화면의 ‹ › 단추·「1 / 3」·일시정지 단추와 캐러셀 접근성 표시(region·slide 역할, 비활성 장 `aria-hidden`)는 컴포넌트가 그리고 Swiper 기본 화살표·점 표시는 쓰지 않는다. 2장 이상이면 무한 반복(`loop`)·손가락 넘기기를 켜고 PC 마우스 끌기(`simulateTouch`)는 끈다. 효과는 slide 500ms · fade 교차 600ms · none(=slide 0ms), 움직임 줄이기면 none·자동 재생 끔. 자동 재생은 Swiper autoplay(설정 간격 ms)이고 마우스·포커스·일시정지 단추에서 `autoplay.stop()` 한다 — 자동으로 넘어가는 동안 장 번호는 `aria-live="off"`. 무한 반복은 슬라이드 DOM 순서를 바꾸므로 각 장에 논리 순서 `data-index` 를 둔다. 전환(0.5초) 중 누른 단추는 Swiper 가 무시한다(`loopPreventsSliding` 기본값). 단위 시험은 `resources/js/__tests__/swiperMock.tsx` 대역으로 연결 계약만 보고, 실제 넘김은 E2E `hero-slider-swiper.spec.ts` 가 본다.
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
