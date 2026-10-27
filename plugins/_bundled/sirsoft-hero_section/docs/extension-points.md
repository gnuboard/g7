# 히어로섹션 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 23종 / 호출 지점 23곳.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-hero_section.banner.after_bulk_delete` | action | 일괄 삭제 직후. 인자: (array $ids, array $snapshots) | `src/Services/BannerService.php:250` |
| `sirsoft-hero_section.banner.after_bulk_status` | action | 일괄 노출·비노출 직후. 인자: (array $ids, bool $isActive, array $snapshots) | `src/Services/BannerService.php:220` |
| `sirsoft-hero_section.banner.after_create` | action | 배너 등록 직후. 인자: (Banner $banner) | `src/Services/BannerService.php:124` |
| `sirsoft-hero_section.banner.after_delete` | action | 배너 영구 삭제 직후. 인자: (int $bannerId, array $snapshot) | `src/Services/BannerService.php:197` |
| `sirsoft-hero_section.banner.after_reorder` | action | 순서 변경 직후. 인자: (string $position, array $ids) | `src/Services/BannerService.php:268` |
| `sirsoft-hero_section.banner.after_update` | action | 배너 수정 직후. 인자: (Banner $banner, array $snapshot, string $previousPosition) | `src/Services/BannerService.php:168` |
| `sirsoft-hero_section.banner.before_bulk_delete` | action | 일괄 삭제 직전. 인자: (array $ids) | `src/Services/BannerService.php:233` |
| `sirsoft-hero_section.banner.before_bulk_status` | action | 일괄 노출·비노출 직전. 인자: (array $ids, bool $isActive) | `src/Services/BannerService.php:210` |
| `sirsoft-hero_section.banner.before_create` | action | 배너 등록 직전. 인자: (array $data) | `src/Services/BannerService.php:104` |
| `sirsoft-hero_section.banner.before_delete` | action | 배너 영구 삭제 직전. 인자: (Banner $banner) | `src/Services/BannerService.php:183` |
| `sirsoft-hero_section.banner.before_reorder` | action | 순서 변경 직전. 인자: (string $position, array $ids) | `src/Services/BannerService.php:264` |
| `sirsoft-hero_section.banner.before_update` | action | 배너 수정 직전. 인자: (Banner $banner, array $data) | `src/Services/BannerService.php:150` |
| `sirsoft-hero_section.banner.filter_create_data` | filter | 등록 값 가공. 인자: (array $data) | `src/Services/BannerService.php:105` |
| `sirsoft-hero_section.banner.filter_list_params` | filter | 관리자 목록 조건 가공. 인자: (array $filters) | `src/Services/BannerService.php:43` |
| `sirsoft-hero_section.banner.filter_update_data` | filter | 수정 값 가공. 인자: (array $data, Banner $banner) | `src/Services/BannerService.php:151` |
| `sirsoft-hero_section.banner.store_validation_rules` | filter | 등록 검증 규칙 가공. 인자: (array $rules, FormRequest $request) | `src/Http/Requests/Admin/StoreBannerRequest.php:55` |
| `sirsoft-hero_section.banner.update_validation_rules` | filter | 수정 검증 규칙 가공. 인자: (array $rules, FormRequest $request) | `src/Http/Requests/Admin/UpdateBannerRequest.php:66` |
| `sirsoft-hero_section.banner_image.after_change` | action | 배너 수정 저장으로 기기별 이미지가 바뀐 직후. 인자: (Banner $banner, string $device, string $change — replaced·removed) | `src/Services/BannerService.php:165` |
| `sirsoft-hero_section.banner_image.after_delete` | action | 배너 이미지 삭제 직후. 인자: (array $snapshot) | `src/Services/BannerImageService.php:121` |
| `sirsoft-hero_section.banner_image.after_upload` | action | 배너 이미지 임시 업로드 직후(배너에는 저장 때 붙는다). 인자: (BannerImage $image) | `src/Services/BannerImageService.php:101` |
| `sirsoft-hero_section.banner_image.filter_upload_file` | filter | 올린 이미지 파일 가공(압축 등). 인자: (UploadedFile $file) | `src/Services/BannerImageService.php:67` |
| `sirsoft-hero_section.hero.filter_public_banners` | filter | 사용자 화면 노출 배너 목록 가공. 인자: (array $banners, string $position, string $locale) | `src/Services/PublicHeroService.php:64` |
| `sirsoft-hero_section.position_setting.after_update` | action | 위치 슬라이더 설정 저장 직후. 인자: (PositionSetting $setting, array $snapshot) | `src/Services/PositionSettingService.php:87` |
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
| `core.plugin_settings.after_save` | action (미선언) | `HeroCacheListener` | `onPluginSettingsSaved` | - |
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
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
활동 로그에는 배너 id 만 저장하고 표시 때 이름으로 바꾼다 — 삭제된 배너도 기록 시점 제목 스냅샷으로 읽히게 하려는 것이다. 다른 확장의 설명 키는 건드리지 않는다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `ActivityLogDescriptionResolver` | 1개 | 명시 등록 | ✅ | `src/Listeners/ActivityLogDescriptionResolver.php` |
| `BannerActivityLogListener` | 8개 | 명시 등록 | ✅ | `src/Listeners/BannerActivityLogListener.php` |
| `HeroCacheListener` | 9개 | 명시 등록 | ✅ | `src/Listeners/HeroCacheListener.php` |
| `PluginLifecycleListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/PluginLifecycleListener.php` |
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

- 조각은 공개 API `GET /api/plugins/sirsoft-hero_section/hero?position=…` 를 data-source `heroSectionHome`·`heroSectionShop` 으로 받아(인증 없음) 배너가 있을 때만 슬라이더를 그린다. 0건·받기 실패면 숨긴다(fallback null). 언어를 바꾸면 화면이 새로 마운트되며 새 언어로 다시 받는다.
- 디자인을 바꾸려면 템플릿 오버라이드(템플릿 루트 기준 src/components/overrides/sirsoft-hero_section/HeroSlider.tsx)로 `sirsoft-hero_section.HeroSlider` 를 대체한다. props 계약: `banners`(공개 API 배열 — `id`·`title`·`description`·`button_text`·`image_alt`·`title_visible`·`title_color`·`title_align`·`description_*`·`link_url`·`link_new_window`·`images{pc{url,width,height},mobile|null}`), `settings`(`autoplay`·`interval_seconds`·`effect` slide·fade·none), `position`(위치 키), `className`.
- 검색 봇 화면은 확장 composite 를 그리지 않을 수 있다 — 배너는 색인할 본문이 아니라 허용한다.
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

`HeroSlider`의 배너 객체는 선택적 `presentation`을 받습니다. `pc_x`, `pc_y`, `mobile_x`, `mobile_y`는 0~100의 이미지 초점이며 `overlay`는 none/dark/light입니다. PC와 모바일 표시 비율은 각각 1920×640·750×1000으로 고정해 순서 변경이 영역 높이를 바꾸지 않습니다. 템플릿 오버라이드도 이 계약을 반영해야 합니다.

관리자 전용 `HeroBannerPreview`는 기존 banners뿐 아니라 원본 다국어 draft, locale, defaultLocale을 받습니다. `onContentLocaleChange`는 실제로 바뀐 언어 한 개를 알리고, 대상 배너가 바뀌는 초기화는 편집으로 알리지 않습니다. `HeroPeriodHint`·`HeroPreviewLanguage`도 플러그인이 등록하며 사용자 컴포넌트 팔레트에는 노출하지 않습니다.

`HeroBannerPreview`는 선택적 `frame`(위치 설정 응답 `editor.preview_frame`)과 `position`(`home_hero`·`shop_hero`)을 받아 실제 사이트와 같은 구좌 폭에서 그립니다. 주지 않으면 종전처럼 기기 폭 전체를 씁니다.

구좌 폭을 정하는 템플릿 제작자를 위한 제약: 미리보기 틀은 활성 사용자 템플릿에서 확장점(`home_hero`·`shop_hero`)까지 내려가는 **레이아웃 노드의 정적 `className`과 `responsive`의 `className` 덮어쓰기만** 읽어, 같은 템플릿 CSS(`css/components.css`)로 다시 그립니다. 다음은 반영되지 않으므로 미리보기 폭이 실제 사이트와 어긋날 수 있습니다.

- 합성 컴포넌트가 자기 안에서 주는 여백·최대 폭(예: 내부에 `max-w-*`·`px-*`를 가진 컨테이너 컴포넌트) — 미리보기는 컴포넌트를 렌더하지 않고 노드 클래스만 씁니다.
- 노드의 `style` 속성(인라인 폭·여백)과 `{{…}}` 바인딩이 든 클래스 조각(서버가 상태 값을 모르므로 지웁니다).
- 템플릿 CSS 밖에서 오는 규칙(레이아웃별 추가 스타일시트 등).

미리보기와 실제 구좌를 맞추려면 확장점을 감싸는 본문 상자의 폭·여백을 레이아웃 노드의 정적 클래스(필요하면 `responsive` 클래스)로 두세요. 확장점을 가진 레이아웃을 읽지 못하면 그 레이아웃은 건너뛰고 오류 로그(`error` 수준 — 템플릿·레이아웃·확장점·오류, 틀 캐시 주기마다 한 번)를 남기며, 쓸 수 있는 레이아웃이 없으면 기기 폭 전체로 그립니다. 관리자 레이아웃 편집기에서 저장하면 확장 캐시 버전이 올라 틀도 바로 다시 읽습니다.

관리자 전용 `HeroConflictResolver`(`base`·`mine`·`latest`·`locales`, `onApply(merged, nextBase)`)는 수정 충돌 뒤 비교·재적용에 쓰며 역시 팔레트에 노출하지 않습니다.

슬라이드 문구 마크업은 `.hs__text > .hs__scroll > (.hs__title, .hs__description, .hs__actions)` 입니다. 버튼 문구가 없어 슬라이드 전체가 링크인 배너는 `.hs__slide > (a.hs__link, .hs__picture, .hs__text)` 처럼 링크를 **비어 있는 형제**(슬라이드를 덮음, `aria-label`=제목)로 두고 문구는 그 위에 둡니다 — 문구를 눌러도 같은 링크로 가고, 링크 안에 다른 포커스 요소가 들어가지 않습니다. 긴 문구는 `.hs__scroll` 안에서만 스크롤하고 `.hs__text` 의 바깥 여백(넘김 단추 자리)으로는 지나가지 않습니다. 문구가 넘칠 때만(ResizeObserver 로 다시 잼) `.hs__scroll` 에 `role="region"`·`aria-label`(배너 제목, 없으면 대체 텍스트)·`tabIndex`(보이는 장 0, 나머지 -1)를 주어 키보드로 들어가 스크롤하게 하고, 넘치지 않으면 이 속성을 두지 않습니다. 휴대폰에서 문구 영역을 세로로 밀면 문구가 스크롤되고 가로로 밀면 장이 넘어갑니다. `HeroSlider` 를 오버라이드하는 템플릿은 문구를 자르지 말고 같은 구조(또는 같은 효과)를 유지해야 합니다.
