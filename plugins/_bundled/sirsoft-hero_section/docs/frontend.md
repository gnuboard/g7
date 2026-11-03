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
- 편집(draft) 미리보기는 안내 두 가지를 띄운다 — 지금 기기에서 `.hs-slider__scroll` 에 스크롤이 생기면(배너 1장 그대로, 또는 넘김 단추 자리 `.hs-slider--controlled` 를 잠깐 비워 잰 값) 줄이기 권유, 사진 평균색(16×16 축소)과 문구 배경 절반 세기로 어림한 대비가 제목 3:1·설명 4.5:1 미만이면 추정값과 권장 배경. 둘 다 `role="status"` 안내일 뿐 값을 바꾸거나 저장을 막지 않는다.
- 콘텐츠 언어는 관리자 UI 언어와 독립적이다. `draft`의 문구 값 변화를 비교해 편집 언어를 알리고, 요청 언어 → 사이트 기본 → en → 첫 유효 문구 순으로 고른다. `HeroPreviewLanguage`는 명시적으로 언어를 선택한다.
- `HeroPeriodHint`는 위치 설정 응답의 `editor.timezone`·`editor.server_now`를 이용한다. 시간대 없는 입력을 브라우저 현지 시간으로 파싱하지 않고 사이트 벽시계로 비교한다.
- 위치별 슬라이더 설정에 표시 폭(`width`: `content` 본문 폭 기본 · `full` 화면 전체)을 둔다. 목록에서 고르면 작업본(`_local.sliderForm`)에 담기고 미리보기는 같은 위치의 작업본을 먼저 보므로 저장 전에 바뀐 폭을 본다. 저장 단추는 넷(자동 넘김·간격·효과·표시 폭) 모두 그대로면 잠긴다. 등록·수정 미리보기는 그 위치의 저장된 표시 폭을 쓴다. 미리보기는 구좌 틀의 `content`(확장점이 넘긴 본문 상자)를 기기 폭에 맞는 클래스로 골라 `HeroSlider` 의 `contentClass` 로 넘긴다.
- 사용자 조각은 확장점 속성 `contentClass`·`contentClassDesktop` 을 `HeroSlider` 의 `contentClass` 로 넘긴다(`<responsive on="desktop">` 덮어쓰기). 슬라이더는 본문 폭이면 그 상자(`div.hs-frame[data-hs-width=content]`)로 감싸고, 화면 전체면 `section.hs-slider--full` 로 끝까지 그리되 문구(`.hs-slider__box`)·넘김 단추(`.hs-slider__controls-box`)만 그 상자로 감싼다. 화면 전체 높이는 640px 에서 멈추고(`--hs-max-height-full`) 더 넓은 화면은 초점 기준으로 위아래를 자른다. 상자를 받지 못하면 두 설정 모두 자리 폭 그대로 그린다.
- 받는 중 스켈레톤: 조각은 슬라이더를 늘 붙이고(`if` 없음) `loading="{{heroSectionHome == null}}"`(응답 전 값 없음)·`skeletonWidth`(첫 로드 플러그인 설정 `_global.plugins['sirsoft-hero_section'].slot_widths.{위치}`)를 넘긴다. 슬라이더는 받는 중이고 배너가 없으면 같은 비율·폭·높이 상한의 `section.hs-slider.hs-slider--skeleton`(`aria-hidden`, 물결 빛 `::after`, 다크 모드 색)을 그리고, 받은 뒤 0건이면 아무것도 그리지 않는다. 실제 슬라이더는 `hs-appear` 0.4초로 나타나며 움직임 줄이기에서는 두 애니메이션을 끈다. 폭 우선순위는 힌트 → 브라우저 저장소의 직전 모양(`sirsoft-hero_section:slot:{위치}` = `{banners, width}`) → 본문 폭이고, 직전에 0건이던 위치는 스켈레톤을 그리지 않는다. 직전 모양은 응답이 온 때(설정에 `width` 가 있음)만 남긴다 — 받기에 실패한 방문을 0건으로 남기면 다음 방문에 스켈레톤 없이 배너가 끼어든다. 저장소는 개인정보 동의 전 정리(GDPR 플러그인)로 지워질 수 있어 보조로만 쓰고, 읽기·쓰기 실패는 조용히 넘긴다. 위치를 넘기지 않는 관리자 미리보기는 저장소를 쓰지 않는다.
- 영상 배너: 공개 응답의 `media_type` 이 `video` 면 `videos.{pc,mobile}`(모바일 없으면 PC, 767px 경계를 `matchMedia` 로 구독) 영상을 대표 이미지 위에 `muted`·`playsInline`·`aria-hidden` 으로 겹치고, `youtube` 면 `youtube_id` 로 `youtube-nocookie.com` 플레이어를 띄운다(`resources/js/support/media.tsx`). 유튜브는 코어 동의 파사드 `G7Core.consent` 스냅샷이 `none` 이거나 `ready` + `marketing` 일 때만 바로 불러오고, 그 밖(모름·미동의·런타임 없음 — 관리자 미리보기 포함)은 대표 이미지와 재생 단추만 그린다. 단추를 누르면 그 배너만 불러온다. iframe 은 React 가 아니라 컴포넌트가 직접 만든다 — 개인정보 플러그인 차단기가 iframe 을 바꿔 끼워도 React 트리가 깨지지 않게. 재생·멈춤·끝 감지는 플레이어 메시지(`listening` 등록 → `onStateChange`/`infoDelivery` 의 상태 0)로 하고, 출처가 `https://www.youtube-nocookie.com` 이고 그 iframe 창이 보낸 메시지만 받는다. 활성 장 영상이 재생을 맡는 동안 Swiper 자동 재생을 멈추고 끝나면 `slideNext`(마우스·포커스·일시정지로 막혀 있으면 표시만 하고 간격 넘김에 맡김), 한 장이면 반복한다. 파일 오류·자동 재생 거부·유튜브 10초 무응답은 그 장을 이미지처럼 간격으로 넘긴다. 유튜브는 「재생 중」 신호를 받는 즉시 드러낸다. 플레이어가 재생 시작(되감기·다시 재생 포함) 때마다 약 4초 띄우는 안내 화면은 `controls=0` 으로도 끌 수 없어, iframe 을 배너 높이의 1.45배 이상으로 키워 위 제목·아래 로고 띠를 잘라 내고 가운데 재생 표시는 그대로 둔다(재생 표시가 잠깐 보이더라도 빨리 보이는 쪽을 택함). 지금 장과 다음 장(eager)이 될 때만 iframe 을 만들고 한 번 만든 것은 유지하며, 영상 대표 이미지(poster)는 기기 이미지로 지금·다음 장에만 단다. 일시정지 단추는 영상이 하나라도 있으면 한 장이어도 보이고(이름 「배너 일시정지」·「배너 다시 재생」) 자동 넘김과 영상을 함께 멈춘다. 움직임 줄이기면 방문자가 단추를 누르기 전까지 멈춘 채다 — 받는 중(배너 없음)으로 먼저 그리므로 첫 값으로 굳히지 않는다.
- 문구 자리·효과(`resources/js/support/text.tsx`): 문구 띠 `.hs-slider__text` 는 `top: y%` + `translateY(-y%)` 로 슬라이드 안 세로 위치를 잡고(기준점이 같아 끝값도 배너 밖으로 넘치지 않는다), 안의 상자 `.hs-slider__textbox` 는 폭 `w%`·왼쪽 여백 `(100% - w%) × x / 100` 로 가로 위치를 잡는다. 값은 CSS 변수 `--hs-tx|ty-{pc|mobile}`·`--hs-tw`(767px 경계에서 휴대폰 값)이며, 문구 배경 그라데이션은 위쪽 문구면 위로 짙어진다(`--hs-shade`). 기본값(0·100·100)은 종전 아래쪽 전폭 문구와 같은 화면이다. 줄(머리글→제목→설명→버튼)은 그려지는 줄만 세어 `--hs-line-delay`(시작 지연 + 순번 × 0.2초)를 받고, 활성 장(`.hs-slider__slide--active`)에서만 효과 keyframes 를 돈다. 「글자 하나씩」은 머리글·제목을 낱말 묶음(`.hs-slider__word`, 줄바꿈 안 함) 안의 글자(`.hs-slider__letter`)로 나누고 원문은 `.hs-slider__sr` 로 한 번만 읽힌다. 움직임 줄이기면 효과 클래스가 `none` 이고 CSS 도 끈다.
- 관리자 미리보기(iframe 문서): Swiper 14 의 `mount` 가 요소를 바깥 창의 `HTMLElement` 로만 확인해 iframe 요소면 조용히 초기화를 건너뛴다(요소 없는 인스턴스의 자동 넘김 타이머가 `slideNext` 에서 TypeError). 그래서 `HeroSlider` 는 자기 문서가 바깥 문서가 아니면(`framed`) Swiper 를 부르지 않는다 — 자동 넘김 없음, 화살표·점·탭·키보드는 장 번호만 바꾸고 섹션 `hs-slider--static` + `--hs-index` 로 `.swiper-wrapper` 를 `translateX(-index × 100%)`(전환 효과 없음). 모든 Swiper 호출은 `initialized` 도 확인한다.
- 페이징 모양(`settings.pagination`, 모르는 값은 `dots`): 섹션에 `hs-slider--pagination-{값}`. `dots` 는 종전 점(10px, 누를 자리 24px). `progress` 는 같은 점에서 지금 장 점이 24px 막대로 길어지고 그 안 `.hs-slider__bullet-fill` 이 Swiper `onAutoplayTimeLeft`(남은 비율)로 섹션에 흘린 `--hs-progress`(지난 비율, 다시 그리기 없음)만큼 찬다 — 자동 넘김이 멈추면(섹션에 `hs-slider--autoplaying` 없음: 마우스·포커스·일시정지·움직임 줄이기·자동 넘김 끔·영상 재생 중) 가득 찬 채. `tabs` 는 `.hs-slider__tabs` 안 장마다 `button.hs-slider__tab`(위 3px 막대 + 배너 제목 = 단추 이름, 제목이 없으면 대체 문구 → 「n번째 배너로 이동」), 휴대폰(767px 이하)은 이름을 화면 읽기용으로만 남기고 막대 단추(높이 24px)만, 일시정지 단추 자리(오른쪽 52px)를 비운다. `fraction` 은 「01 / 05」(`aria-hidden` — 장 번호는 슬라이드 이름이 읽는다).
- 메뉴 겹침: 화면 전체 폭 + `settings.header_overlay` 면 `useHeaderOverlay` 가 `<html data-header-overlay>` 를 지금 장의 `tone` 으로 달고(나중에 단 슬라이더만 지운다), 섹션에 `hs-slider--header-overlay` 를 붙여 문구 띠 위 여백에 템플릿이 알려 준 `--g7-header-height` 를 더한다. 받는 동안은 `skeletonOverlay`(힌트) → 직전 방문 기억(`{banners, width, overlay, tone}`) 순, 바탕은 직전 첫 장(없으면 사이트 다크 모드면 어두움).
- 편집 화면 「문구 위치·효과」 카드: `HeroTextPositionPad`(기기 = `_local.previewDevice`, 미리보기와 같은 값)와 미리보기 끌기(`onTextPositionChange`)가 둘 다 `form.presentation` 에 조각을 합치고 `hasChanges` 를 켠다. 폭·효과·속도·지연·바탕은 `presentation.*` 이름 바인딩이다. 목록 화면 위치 설정에 「메뉴 겹치기」 체크(작업본 `header_overlay`, 본문 폭이면 안내)를 둔다.
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

슬라이더의 넘김은 [Swiper](https://swiperjs.com) 14.3.0(`swiper/react`, 모듈 `Autoplay`·`EffectFade`·`EffectCube`·`EffectFlip`·`EffectCoverflow`·`EffectCreative`)이 맡는다 — JS 는 플러그인 번들에 함께 빌드한다(정확한 버전 고정, React 는 코어 전역을 쓴다). Swiper 라이브러리 CSS(`swiper-*` 클래스 규칙)는 확장 CSS 에 넣지 않고 동봉 자산 `dist/vendor/swiper/14.3.0/swiper-bundle.min.css`(+`LICENSE`)로 두어 `resources/js/support/swiperStyles.ts` 가 코어 `G7Core.asset.loadStylesheet` 로 한 번 싣는다 — 확장 CSS 는 자기 앞글자 선택자만 담는 규약이라서다. 실은 `<link id="hs-swiper-css">` 는 `<head>` 맨 앞으로 옮긴다(예전 번들에서처럼 Swiper 규칙이 슬라이더 손 CSS 앞에 있어야 특이도가 같은 규칙에서 손 CSS 가 이긴다). 실릴 때까지 슬라이더는 같은 비율·폭의 스켈레톤을 두고(장들이 세로로 쌓였다 펴지며 본문을 밀지 않게), 끝내 못 실으면 코어 실패 알림(`G7Core.assets.notifyFailure`, 이름 `slider.styles_label`)에 다시 시도를 걸고 전환 효과 없이라도 그린다. 코어 자산 API 가 없으면(단위 시험) 싣지 않는다. 미리보기 iframe 은 띄울 때 바깥 스타일을 복사하고, 그 뒤에 실리면 `copySwiperStyles` 로 맨 앞에 넣는다. Swiper 판을 올리면 `package.json`·`SWIPER_VERSION`·동봉 파일을 함께 바꾼다(시험이 대조). 좌우 화살표·점 표시는 Swiper 기본 모양(navigation·pagination CSS 의 선언을 손 CSS 의 `hs-slider__arrow`·`hs-slider__bullets`·`hs-slider__bullet(--active)` 로 옮김, 같은 화살표 SVG `hs-slider__arrow-icon`)이지만 Swiper Navigation·Pagination 모듈 대신 이름 붙은 `<button>` 으로 컴포넌트가 그린다 — 두 모듈은 요소를 바깥 `document` 에서 찾아 편집 미리보기 iframe 에서는 붙지 않는다. 정적 미리보기가 옮기는 장 줄은 Swiper `wrapperClass` 로 붙인 `hs-slider__wrapper` 다. 화살표는 1024px 이상에서만 보이고(좁은 화면에서는 문구 여백보다 넓어 글자를 가린다) 점은 `slideToLoop` 로 그 장에 간다(`aria-current`). 장 번호는 「3개 중 1번째」(`slider.slide_label`)처럼 사이트 언어로 슬라이드 이름(`aria-label`)에 두고, 화면에서 감춘 알림 칸(`.hs-slider__counter`, `aria-live`)은 방문자가 넘긴 때만 3초 채웠다가 비운다 — 늘 채워 두면 훑어 읽기에서 같은 번호를 두 번 듣는다. 일시정지 단추와 캐러셀 접근성 표시(region·slide 역할 — 역할 이름 `aria-roledescription` 도 `slider.carousel_role`·`slide_role` 로 번역, 비활성 장 `aria-hidden`)도 컴포넌트가 그린다. 제목은 한 번만 읽힌다 — 전체 링크면 링크 이름이 제목이라 보이는 제목 줄은 `aria-hidden`, 이미지 대체 문구가 제목·링크 이름과 같으면(대체 문구를 비우면 공개 응답이 제목으로 채운다) `alt=""`. 점은 10px 로 보이되 `::before`(inset −7px)로 누를 자리를 24px 로 넓히고 점 사이를 14px 로 벌려 이웃 누를 자리와 겹치지 않는다(WCAG 2.5.8). 2장 이상이면 무한 반복(`loop`)·손가락 넘기기·PC 마우스 끌기(`simulateTouch`·`grabCursor`, 이미지 `draggable=false`)를 켠다. 효과는 slide 500ms · fade 교차 600ms · cube 800ms · flip 700ms · coverflow 600ms · creative(겹쳐 넘기기) 700ms · none(=slide 0ms), 효과 그림자는 끈다(배너 밖으로 번져 잘린다). 모르는 효과 값은 slide. 움직임 줄이기면 none·자동 재생 끔. 자동 재생은 Swiper autoplay(설정 간격 ms)이고 마우스·포커스·일시정지 단추에서 `autoplay.stop()` 한다 — 자동으로 넘어가는 동안 장 번호는 `aria-live="off"`. 무한 반복은 슬라이드 DOM 순서를 바꾸므로 각 장에 논리 순서 `data-index` 를 둔다. 전환 중 누른 단추는 Swiper 가 무시한다(`loopPreventsSliding` 기본값) — 장 번호는 전환이 시작될 때 바뀌므로 E2E 는 `animating` 이 풀릴 때까지 기다린다. 단위 시험은 `resources/js/__tests__/swiperMock.tsx` 대역으로 연결 계약만 보고, 실제 넘김은 E2E `hero-slider-swiper.spec.ts`, 화살표·점·마우스 끌기·추가 효과는 `swiper-ui.spec.ts` 가 본다. 긴 문구 영역 `.hs-slider__scroll` 은 넘칠 때만 세로 스크롤(`hs-slider__scroll--overflow`, 평소 `overflow-y: hidden`)과 탭 순서에 들어오는 이름 붙은 영역(「배너 문구」 — 제목과 겹쳐 읽히지 않게)이 된다. 넘침은 `scrollHeight` 가 아니라 줄의 배치 높이(`offsetTop`·`offsetHeight`, `resources/js/support/text.tsx` `textOverflows`)로 잰다 — 「떠오르기」·「글자 하나씩」이 줄을 아래에서 올리는 동안의 이동(transform)은 `scrollHeight` 에 잡혀, 넘치지 않는 문구에도 스크롤 막대가 잠깐 생기고(막대가 자리를 차지하는 Windows 크롬) 그 값이 굳어 탭 진입 영역으로 남았다. 편집 미리보기의 문구 넘침 안내도 같은 함수를 쓴다(보이는 장 `tabIndex=0`, 나머지 -1), 손가락 세로 쓸기는 문구 스크롤·가로 쓸기는 장 넘김으로 갈린다 — Swiper 가로 넘기기의 `touch-action: pan-y` 그대로 충돌이 없음을 E2E `long-text.spec.ts`(CDP 터치)가 본다. 버튼 문구가 없는 전체 링크 배너는 `a.hs-slider__link` 를 슬라이드를 덮는 빈 형제(z-index 1)로, `.hs-slider__text` 를 그 위(z-index 2)에 두고 문구 클릭을 링크 `click()` 으로 넘긴다 — 링크 안에 문구 영역이 들어가 중첩 상호작용이 되지 않게 한다(편집 미리보기 iframe 은 `a` 클릭을 막으므로 넘긴 클릭도 막힌다).
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `dist/css/plugin.css` | 빌드 산출물 (커밋 대상) |
| `dist/js/plugin.iife.js` | 빌드 산출물 (커밋 대상) |
| `dist/vendor/swiper/14.3.0` | 동봉 제3자 자산 (자체 제공) |
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
`resources/js/index.ts` → `dist/js/plugin.iife.js`(React external — 코어가 실은 인스턴스를 빌려 쓴다), `resources/css/main.css` → `dist/css/plugin.css`. CSS 는 확장 CSS 자기 공급 규약을 따른다 — manifest `assets.css.namespace` 는 `hs`(종전 앞글자). 슬라이더 손 CSS 는 `hs-slider`·`hs-slider__…`·`hs-slider--…`, 편집 보조는 `hs-pad`·`hs-preview`·`hs-conflict`·`hs-editor-*` 이고, 관리자 레이아웃의 유틸리티·의미 클래스는 `scripts/extension-css/convert-layout-classes.mjs` 가 `hs:…`(접두 Tailwind 빌드, `vite.config.ts` 의 `@tailwindcss/vite`)·`hs-…`(템플릿 정의를 복사한 `resources/css/g7-extension.generated.css`, `@layer g7-extension`)로 바꿨다. `main.css` 머리의 `g7-extension-css` 블록과 생성 파일은 변환기가 다시 만든다. 다크는 `.dark` 가 아니라 중립 표식 `[data-g7-color-scheme]` 규격이다(미리보기 iframe 도 이 표식을 바깥 문서에서 옮긴다). 관리자 `Select` 기본 컴포넌트는 `.select` 를 덧붙이므로 네이티브 select 에는 `hs-input` 과 함께 `hs-select` 를 준다 — `hs-input` 만 주면 높은 층의 `hs-input` 이 `.select` 의 화살표 자리 여백을 이긴다. 시험이 찾는 표지는 클래스가 아니라 data 속성(`data-hs-control="toggle"`·`data-hs-fraction`·`data-hs-line`·`data-hs-width`·`data-hs-frame`)이다 — 자기 CSS 에 없는 클래스는 걸지 않는다. 빌드는 `php artisan plugin:build sirsoft-hero_section --production` 이고 산출물(`dist/`·`components.json`)을 함께 커밋한다.
<!-- @intent END -->
