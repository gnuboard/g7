# 히어로섹션 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 23종 / 호출 지점 23곳.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-hero_section.banner.after_bulk_delete` | action | 일괄 삭제 직후. 인자: (array $ids, array $snapshots) | `src/Services/BannerService.php:252` |
| `sirsoft-hero_section.banner.after_bulk_status` | action | 일괄 노출·비노출 직후. 인자: (array $ids, bool $isActive, array $snapshots) | `src/Services/BannerService.php:227` |
| `sirsoft-hero_section.banner.after_create` | action | 배너 등록 직후. 인자: (Banner $banner) | `src/Services/BannerService.php:132` |
| `sirsoft-hero_section.banner.after_delete` | action | 배너 영구 삭제 직후. 인자: (int $bannerId, array $snapshot) | `src/Services/BannerService.php:205` |
| `sirsoft-hero_section.banner.after_reorder` | action | 순서 변경 직후. 인자: (string $position, array $ids) | `src/Services/BannerService.php:270` |
| `sirsoft-hero_section.banner.after_update` | action | 배너 수정 직후. 인자: (Banner $banner, array $snapshot, string $previousPosition) | `src/Services/BannerService.php:176` |
| `sirsoft-hero_section.banner.before_bulk_delete` | action | 일괄 삭제 직전. 인자: (array $ids) | `src/Services/BannerService.php:240` |
| `sirsoft-hero_section.banner.before_bulk_status` | action | 일괄 노출·비노출 직전. 인자: (array $ids, bool $isActive) | `src/Services/BannerService.php:218` |
| `sirsoft-hero_section.banner.before_create` | action | 배너 등록 직전. 인자: (array $data) | `src/Services/BannerService.php:107` |
| `sirsoft-hero_section.banner.before_delete` | action | 배너 영구 삭제 직전. 인자: (Banner $banner) | `src/Services/BannerService.php:191` |
| `sirsoft-hero_section.banner.before_reorder` | action | 순서 변경 직전. 인자: (string $position, array $ids) | `src/Services/BannerService.php:266` |
| `sirsoft-hero_section.banner.before_update` | action | 배너 수정 직전. 인자: (Banner $banner, array $data) | `src/Services/BannerService.php:158` |
| `sirsoft-hero_section.banner.filter_create_data` | filter | 등록 값 가공. 인자: (array $data) | `src/Services/BannerService.php:108` |
| `sirsoft-hero_section.banner.filter_list_params` | filter | 관리자 목록 조건 가공. 인자: (array $filters) | `src/Services/BannerService.php:44` |
| `sirsoft-hero_section.banner.filter_update_data` | filter | 수정 값 가공. 인자: (array $data, Banner $banner) | `src/Services/BannerService.php:159` |
| `sirsoft-hero_section.banner.store_validation_rules` | filter | 등록 검증 규칙 가공. 인자: (array $rules, FormRequest $request) | `src/Http/Requests/Admin/StoreBannerRequest.php:56` |
| `sirsoft-hero_section.banner.update_validation_rules` | filter | 수정 검증 규칙 가공. 인자: (array $rules, FormRequest $request) | `src/Http/Requests/Admin/UpdateBannerRequest.php:66` |
| `sirsoft-hero_section.banner_image.after_change` | action | 배너 수정 저장으로 기기별 이미지가 바뀐 직후. 인자: (Banner $banner, string $device, string $change — replaced·removed) | `src/Services/BannerService.php:173` |
| `sirsoft-hero_section.banner_image.after_delete` | action | 배너 이미지 삭제 직후. 인자: (array $snapshot) | `src/Services/BannerImageService.php:148` |
| `sirsoft-hero_section.banner_image.after_upload` | action | 배너 이미지 임시 업로드 직후(배너에는 저장 때 붙는다). 인자: (BannerImage $image) | `src/Services/BannerImageService.php:128` |
| `sirsoft-hero_section.banner_image.filter_upload_file` | filter | 올린 이미지 파일 가공(압축 등). 인자: (UploadedFile $file) | `src/Services/BannerImageService.php:80` |
| `sirsoft-hero_section.hero.filter_public_banners` | filter | 사용자 화면 노출 배너 목록 가공. 인자: (array $banners, string $position, string $locale) | `src/Services/PublicHeroService.php:69` |
| `sirsoft-hero_section.position_setting.after_update` | action | 위치 슬라이더 설정 저장 직후. 인자: (PositionSetting $setting, array $snapshot) | `src/Services/PositionSettingService.php:131` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
배너 서비스가 `sirsoft-hero_section.banner.{before,after}_{create,update,delete,bulk_status,bulk_delete,reorder}`·`filter_create_data`·`filter_update_data`·`filter_list_params`, 공개 노출 가공용 `sirsoft-hero_section.hero.filter_public_banners` 를 발행한다 — 다른 확장은 이 훅으로 배너 저장을 막거나 노출 목록을 바꾼다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.activity_log.filter_description_params` | filter | `ActivityLogDescriptionResolver` | `resolveDescriptionParams` | 10 |
| `core.plugin_settings.after_reset` | action (미선언) | `HeroCacheListener` | `onPluginSettingsSaved` | - |
| `core.plugin_settings.after_reset` | action (미선언) | `SlotWidthHintListener` | `onPluginEvent` | 20 |
| `core.plugin_settings.after_save` | action (미선언) | `HeroCacheListener` | `onPluginSettingsSaved` | - |
| `core.plugin_settings.filter_save_data` | filter | `SlotWidthHintListener` | `fillHint` | 20 |
| `core.plugins.after_activate` | action (미선언) | `SlotWidthHintListener` | `onPluginEvent` | 20 |
| `core.plugins.after_update` | action (미선언) | `SlotWidthHintListener` | `onPluginEvent` | 20 |
| `core.plugins.before_uninstall` | action (미선언) | `PluginLifecycleListener` | `beforeUninstall` | 10 |
| `sirsoft-hero_section.banner.after_bulk_delete` | action (미선언) | `BannerActivityLogListener` | `handleBulkDelete` | 20 |
| `sirsoft-hero_section.banner.after_bulk_delete` | action (미선언) | `HeroCacheListener` | `onBulkDelete` | - |
| `sirsoft-hero_section.banner.after_bulk_status` | action (미선언) | `BannerActivityLogListener` | `handleBulkStatus` | 20 |
| `sirsoft-hero_section.banner.after_bulk_status` | action (미선언) | `HeroCacheListener` | `onBulkStatus` | - |
| `sirsoft-hero_section.banner.after_create` | action (미선언) | `BannerActivityLogListener` | `handleCreated` | 20 |
| `sirsoft-hero_section.banner.after_create` | action (미선언) | `HeroCacheListener` | `onBannerSaved` | - |
| `sirsoft-hero_section.banner.after_delete` | action (미선언) | `BannerActivityLogListener` | `handleDeleted` | 20 |
| `sirsoft-hero_section.banner.after_delete` | action (미선언) | `HeroCacheListener` | `onBannerDeleted` | - |
| `sirsoft-hero_section.banner.after_reorder` | action (미선언) | `BannerActivityLogListener` | `handleReordered` | 20 |
| `sirsoft-hero_section.banner.after_reorder` | action (미선언) | `HeroCacheListener` | `onReorder` | - |
| `sirsoft-hero_section.banner.after_update` | action (미선언) | `BannerActivityLogListener` | `handleUpdated` | 20 |
| `sirsoft-hero_section.banner.after_update` | action (미선언) | `HeroCacheListener` | `onBannerUpdated` | - |
| `sirsoft-hero_section.banner_image.after_change` | action (미선언) | `BannerActivityLogListener` | `handleImageChanged` | 20 |
| `sirsoft-hero_section.position_setting.after_update` | action (미선언) | `BannerActivityLogListener` | `handlePositionSetting` | 20 |
| `sirsoft-hero_section.position_setting.after_update` | action (미선언) | `HeroCacheListener` | `onPositionSetting` | - |
| `sirsoft-hero_section.position_setting.after_update` | action (미선언) | `SlotWidthHintListener` | `onPositionSetting` | 20 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
활동 로그에는 배너 id 만 저장하고 표시 때 이름으로 바꾼다 — 삭제된 배너도 기록 시점 제목 스냅샷으로 읽히게 하려는 것이다. 다른 확장의 설명 키는 건드리지 않는다.

활동 로그 리스너는 큐로 돈다(워커가 돌 때 실행). 그래서 일괄 노출·비노출과 일괄 삭제는 DB 를 다시 읽지 않고 훅의 변경 전 스냅숏(`after_bulk_status`·`after_bulk_delete` 의 마지막 인자)으로 건마다 기록한다 — 워커가 돌기 전에 지우거나 다시 고친 배너도 바뀐 그때의 값으로 남는다. 이 훅을 구독하는 다른 확장도 같은 인자를 쓴다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `ActivityLogDescriptionResolver` | 1개 | 명시 등록 | ✅ | `src/Listeners/ActivityLogDescriptionResolver.php` |
| `BannerActivityLogListener` | 8개 | 명시 등록 | ✅ | `src/Listeners/BannerActivityLogListener.php` |
| `HeroCacheListener` | 9개 | 명시 등록 | ✅ | `src/Listeners/HeroCacheListener.php` |
| `PluginLifecycleListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/PluginLifecycleListener.php` |
| `SlotWidthHintListener` | 5개 | 명시 등록 | ✅ | `src/Listeners/SlotWidthHintListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
플러그인 리스너는 자동 발견되지 않으므로 `plugin.php` `getHookListeners()` 에 올린 것만 동작한다. 공개 캐시 비우기(`HeroCacheListener`)와 데이터 삭제 시 이미지 폴더 정리(`PluginLifecycleListener`)는 `sync` 로 즉시 돈다 — 저장 직후 사용자 화면에 옛 배너가 남지 않게.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/home_hero.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `home_hero` |
| `resources/extensions/shop_hero.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `shop_hero` |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
사용자 템플릿 확장점 `home_hero`(사이트 홈 상단)·`shop_hero`(쇼핑몰 메인 상단)에 슬라이더 조각을 `append` 로 주입한다. 확장점 이름 = 배너 노출 위치 키다(플러그인 고정 목록). 다른 템플릿도 본문 맨 앞에 같은 이름의 빈 슬롯 `<extension-point name="home_hero"><default/></extension-point>` 만 두면 배너가 붙는다 — 주입이 없으면 아무것도 그리지 않는다.

- **표시 폭(본문 폭·화면 전체)** — 운영자가 위치마다 고른다(위치 설정 `width`, 기본 `content`). 화면 전체를 쓰려면 템플릿이 자리를 본문 상자(여백·최대 폭) **밖**에 두고, 본문 상자 클래스를 확장점 속성으로 넘긴다: `contentClass`(기본 폭) · `contentClassDesktop`(데스크톱 1024px 이상, 없으면 `contentClass`). 예(sirsoft-basic): `<extension-point name="home_hero" contentClass="px-4" contentClassDesktop="max-w-7xl mx-auto px-8"><default/></extension-point>` 를 `_user_base` 의 본문 앞 전폭 슬롯 `page_top` 에 둔다. 조각은 이 값을 `HeroSlider` 의 `contentClass` 로 넘기고(`<responsive on="desktop">` 로 데스크톱 값), 슬라이더는 본문 폭이면 그 상자로 감싸고, 화면 전체면 끝까지 그리되 제목·넘김 단추만 그 상자에 맞춘다(높이 상한 640px). 클래스는 템플릿 자기 CSS 에 있는 값이어야 한다. 자리를 본문 상자 **안**에 두고 속성을 넘기지 않는 템플릿은 두 설정 모두 자리 폭 그대로다(종전 동작).

- **메뉴 겹침(템플릿 계약)** — 위치 설정 `header_overlay` 를 켜고 표시 폭이 화면 전체면 사용자 슬라이더가 문서 뿌리에 `data-header-overlay="dark|light"`(지금 장의 바탕 밝기 `presentation.tone`)를 달고, 사라지거나 0건이면 지운다(편집 미리보기는 달지 않는다). 받는 동안은 힌트 `_global.plugins['sirsoft-hero_section'].slot_overlays.{위치}`(조각이 `skeletonOverlay` 로 넘김)를 따른다. 이 신호를 읽는 템플릿은 헤더를 배너 위에 투명하게 겹치고 보이는 헤더 높이를 CSS 변수 `--g7-header-height`(px)로 문서 뿌리에 알린다 — 슬라이더는 메뉴 겹침일 때 문구 띠 위 여백에 이 값을 더한다. 신호를 읽지 않는 템플릿은 변수가 없어(0) 화면이 겹침 끔과 같다. sirsoft-basic 구현: 자리 `page_top`(`g7-page-top`)을 헤더 높이만큼 위로 당기고, 화면 맨 위에서만 `data-header-overlay-active` 로 데스크톱 헤더·모바일 툴바(`g7-site-header`)를 투명하게 한다.
- 조각은 공개 API `GET /api/plugins/sirsoft-hero_section/hero?position=…` 를 data-source `heroSectionHome`·`heroSectionShop` 으로 받아(인증 없음) 배너가 있을 때만 슬라이더를 그린다. 0건·받기 실패면 숨긴다(fallback null). 언어를 바꾸면 화면이 새로 마운트되며 새 언어로 다시 받는다.
- 디자인을 바꾸려면 템플릿 오버라이드(템플릿 루트 기준 src/components/overrides/sirsoft-hero_section/HeroSlider.tsx)로 `sirsoft-hero_section.HeroSlider` 를 대체한다. props 계약: `banners`(공개 API 배열 — `id`·`title`·`description`·`button_text`·`image_alt`·`title_visible`·`title_color`·`title_align`·`description_*`·`link_url`·`link_new_window`·`images{pc{url,width,height},mobile|null}`), `settings`(`autoplay`·`interval_seconds`·`effect` slide·fade·cube·flip·coverflow·creative·none·`width` content·full·`header_overlay`), `position`(위치 키), `contentClass`(템플릿 본문 상자 클래스 — 지금 화면 폭에 맞는 값), `loading`(공개 API 응답 전이면 true — 배너가 없으면 같은 크기의 스켈레톤 `section.hs-slider.hs-slider--skeleton` 을 그린다), `skeletonWidth`(받는 동안의 표시 폭 힌트 `content`·`full`), `skeletonOverlay`(받는 동안 메뉴 겹침 힌트), `className`. 배너 객체에는 머리글 `eyebrow`·`eyebrow_visible`·`eyebrow_color` 도 있다. 배너가 0건이면 컴포넌트가 스스로 아무것도 그리지 않는다(조각에 `if` 를 두지 않는다 — 두면 받는 동안 자리를 잡지 못한다). 문구는 띠 `.hs-slider__text`(세로 위치) 안의 상자 `.hs-slider__textbox`(폭·가로 위치·등장 효과 `hs-slider__textbox--{효과}`) > `.hs-slider__scroll` > 줄 `.hs-slider__line`(머리글 `.hs-slider__eyebrow`·제목·설명·버튼) 구조이고, 화면 전체 폭에서는 `.hs-slider__text.hs-slider__text--boxed > .hs-slider__box > .hs-slider__textbox > .hs-slider__scroll`, 일시정지 단추는 `.hs-slider__controls-layer > .hs-slider__controls-box > .hs-slider__controls--boxed` 구조다. 좌우 화살표 `button.swiper-button-prev.hs-slider__prev`·`button.swiper-button-next.hs-slider__next`(1024px 이상만 보임)와 점 표시 `.hs-slider__pagination.swiper-pagination > button.swiper-pagination-bullet`(현재 장 `aria-current`)는 `section.hs-slider` 바로 아래에 둔다.
- 검색 봇 화면은 확장 composite 를 그리지 않을 수 있다 — 배너는 색인할 본문이 아니라 허용한다.
- **7.2.0 전 기본 템플릿을 복사해 쓰는 사이트** — 복사본의 사이트 홈·쇼핑몰 메인에는 `home_hero`·`shop_hero` 자리가 없어 배너를 등록해도 보이지 않는다(오류는 없고 화면은 그대로다). 코어가 자리를 대신 끼워 주지 않으므로, 복사한 템플릿의 `home` 레이아웃과 `shop/index` 레이아웃 본문 맨 앞에 빈 슬롯 `<extension-point name="home_hero"><default/></extension-point>`·`<extension-point name="shop_hero"><default/></extension-point>` 를 직접 넣는다. 화면 전체 폭과 메뉴 겹침까지 쓰려면 위 「표시 폭」·「메뉴 겹침」 계약대로 자리를 본문 상자 밖에 두고 헤더가 신호를 읽게 해야 한다 — 7.2.0 sirsoft-basic 의 `_user_base`·`home`·`shop/index` 레이아웃과 헤더 컴포넌트가 그 예다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 미들웨어가 없습니다._
<!-- @generated:middleware END -->

<!-- @intent START -->
자체 미들웨어가 필요 없다 — 관리자 API 는 코어 `permission:` 미들웨어, 공개 API 는 throttle 만 쓴다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
실시간 갱신이 필요 없어 채널을 쓰지 않는다 — 배너 변경은 다음 조회 때 반영되고, 공개 캐시는 변경 훅에서 지운다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 스케줄 | 주기 | 설명 |
|---|---|---|
| `prune-temp-images` | `0 0 * * *` | 임시 배너 이미지 삭제 |
<!-- @generated:schedules END -->

<!-- @intent START -->
고아 임시 이미지 정리(`sirsoft-hero_section:prune-temp-images`, 매일 0시, 이커머스 임시 상품 이미지 정리와 같은 방식)를 등록한다. 노출 시작·종료는 스케줄이 아니라 조회 시점 계산이라 cron 이 필요 없다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 알림 정의가 없습니다._
<!-- @generated:notifications END -->

<!-- @intent START -->
알림을 보내지 않는다 — 배너 노출은 운영자가 화면에서 확인한다.
<!-- @intent END -->

## 표시 설정·관리자 미리보기 계약

`HeroSlider`의 배너 객체는 선택적 `presentation`을 받습니다. `pc_x`, `pc_y`, `mobile_x`, `mobile_y`는 0~100의 이미지 초점이며 `overlay`는 none/dark/light입니다. PC와 모바일 표시 비율은 각각 1920×640·750×1000으로 고정해 순서 변경이 영역 높이를 바꾸지 않습니다. 문구 값은 `text_{pc|mobile}_{x|y}`(0~100 — 띠의 세로 위치와 상자의 가로 위치), `text_width`(30~100%), `animation`(none·rise·fade·left·right·zoom·blur·letters)·`animation_speed`(slow 1초·normal 0.6초·fast 0.35초)·`animation_delay`(0~2초), `tone`(dark·light)입니다. 등장 효과는 활성 장이 될 때마다 줄마다 0.2초씩 늦게 재생하고, 움직임 줄이기면 끕니다. 템플릿 오버라이드도 이 계약을 반영해야 합니다.

`HeroSlider` 의 편집 전용 속성: `onTextMove(spot, device)`(주면 문구 상자를 끌거나 화살표 1%·Shift 10% 로 옮기고 놓을 때 알린다 — `forceDevice` 와 함께), `replay`(값이 바뀌면 등장 효과를 다시 재생). `HeroBannerPreview` 는 이를 `onTextPositionChange(patch)`(`{ text_{기기}_x, text_{기기}_y }`)로 편집 화면에 알리고 효과가 있으면 「효과 다시 보기」 단추를 둔다. `HeroTextPositionPad`(9칸 빠른 선택·가로/세로 숫자·기기 전환, `onChange(patch)`·`onDeviceChange(device)`)도 플러그인이 등록한다.

관리자 전용 `HeroBannerPreview`는 기존 banners뿐 아니라 원본 다국어 draft, locale, defaultLocale을 받습니다. `onContentLocaleChange`는 실제로 바뀐 언어 한 개를 알리고, 대상 배너가 바뀌는 초기화는 편집으로 알리지 않습니다. `HeroPeriodHint`·`HeroPreviewLanguage`도 플러그인이 등록하며 사용자 컴포넌트 팔레트에는 노출하지 않습니다.

`HeroBannerPreview`는 선택적 `frame`(위치 설정 응답 `editor.preview_frame`)과 `position`(`home_hero`·`shop_hero`)을 받아 실제 사이트와 같은 구좌 폭에서 그립니다. 주지 않으면 종전처럼 기기 폭 전체를 씁니다.

구좌 폭을 정하는 템플릿 제작자를 위한 제약: 미리보기 틀은 활성 사용자 템플릿에서 확장점(`home_hero`·`shop_hero`)까지 내려가는 **레이아웃 노드의 정적 `className`과 `responsive`의 `className` 덮어쓰기만** 읽어, 같은 템플릿 CSS(sirsoft-basic 이면 `templates/_bundled/sirsoft-basic/dist/css/components.css`)로 다시 그립니다. 다음은 반영되지 않으므로 미리보기 폭이 실제 사이트와 어긋날 수 있습니다.

- 합성 컴포넌트가 자기 안에서 주는 여백·최대 폭(예: 내부에 `max-w-*`·`px-*`를 가진 컨테이너 컴포넌트) — 미리보기는 컴포넌트를 렌더하지 않고 노드 클래스만 씁니다.
- 노드의 `style` 속성(인라인 폭·여백)과 `{{…}}` 바인딩이 든 클래스 조각(서버가 상태 값을 모르므로 지웁니다).
- 템플릿 CSS 밖에서 오는 규칙(레이아웃별 추가 스타일시트 등).

미리보기와 실제 구좌를 맞추려면 확장점을 감싸는 본문 상자의 폭·여백을 레이아웃 노드의 정적 클래스(필요하면 `responsive` 클래스)로 두세요. 확장점을 가진 레이아웃을 읽지 못하면 그 레이아웃은 건너뛰고 오류 로그(`error` 수준 — 템플릿·레이아웃·확장점·오류, 틀 캐시 주기마다 한 번)를 남기며, 쓸 수 있는 레이아웃이 없으면 기기 폭 전체로 그립니다. 관리자 레이아웃 편집기에서 저장하면 확장 캐시 버전이 올라 틀도 바로 다시 읽습니다.

관리자 전용 `HeroConflictResolver`(`base`·`mine`·`latest`·`locales`, `onApply(merged, nextBase)`)는 수정 충돌 뒤 비교·재적용에 쓰며 역시 팔레트에 노출하지 않습니다.

슬라이드 문구 마크업은 `.hs-slider__text > .hs-slider__scroll > (.hs-slider__title, .hs-slider__description, [data-hs-line="actions"])` 입니다. 버튼 문구가 없어 슬라이드 전체가 링크인 배너는 `.hs-slider__slide > (a.hs-slider__link, .hs-slider__picture, .hs-slider__text)` 처럼 링크를 **비어 있는 형제**(슬라이드를 덮음, `aria-label`=제목)로 두고 문구는 그 위에 둡니다 — 문구를 눌러도 같은 링크로 가고, 링크 안에 다른 포커스 요소가 들어가지 않습니다. 긴 문구는 `.hs-slider__scroll` 안에서만 스크롤하고 `.hs-slider__text` 의 바깥 여백(넘김 단추 자리)으로는 지나가지 않습니다. 문구가 넘칠 때만(ResizeObserver 로 다시 잼) `.hs-slider__scroll` 에 `role="region"`·`aria-label`(배너 제목, 없으면 대체 텍스트)·`tabIndex`(보이는 장 0, 나머지 -1)를 주어 키보드로 들어가 스크롤하게 하고, 넘치지 않으면 이 속성을 두지 않습니다. 휴대폰에서 문구 영역을 세로로 밀면 문구가 스크롤되고 가로로 밀면 장이 넘어갑니다. `HeroSlider` 를 오버라이드하는 템플릿은 문구를 자르지 말고 같은 구조(또는 같은 효과)를 유지해야 합니다.
