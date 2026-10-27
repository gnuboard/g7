# 히어로섹션 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 23종 / 호출 지점 23곳.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-hero_section.banner.after_bulk_delete` | action | 일괄 삭제 직후. 인자: (array $ids, array $snapshots) | `src/Services/BannerService.php:238` |
| `sirsoft-hero_section.banner.after_bulk_status` | action | 일괄 노출·비노출 직후. 인자: (array $ids, bool $isActive, array $snapshots) | `src/Services/BannerService.php:209` |
| `sirsoft-hero_section.banner.after_create` | action | 배너 등록 직후. 인자: (Banner $banner) | `src/Services/BannerService.php:123` |
| `sirsoft-hero_section.banner.after_delete` | action | 배너 영구 삭제 직후. 인자: (int $bannerId, array $snapshot) | `src/Services/BannerService.php:191` |
| `sirsoft-hero_section.banner.after_reorder` | action | 순서 변경 직후. 인자: (string $position, array $ids) | `src/Services/BannerService.php:256` |
| `sirsoft-hero_section.banner.after_update` | action | 배너 수정 직후. 인자: (Banner $banner, array $snapshot, string $previousPosition) | `src/Services/BannerService.php:163` |
| `sirsoft-hero_section.banner.before_bulk_delete` | action | 일괄 삭제 직전. 인자: (array $ids) | `src/Services/BannerService.php:222` |
| `sirsoft-hero_section.banner.before_bulk_status` | action | 일괄 노출·비노출 직전. 인자: (array $ids, bool $isActive) | `src/Services/BannerService.php:204` |
| `sirsoft-hero_section.banner.before_create` | action | 배너 등록 직전. 인자: (array $data) | `src/Services/BannerService.php:103` |
| `sirsoft-hero_section.banner.before_delete` | action | 배너 영구 삭제 직전. 인자: (Banner $banner) | `src/Services/BannerService.php:178` |
| `sirsoft-hero_section.banner.before_reorder` | action | 순서 변경 직전. 인자: (string $position, array $ids) | `src/Services/BannerService.php:252` |
| `sirsoft-hero_section.banner.before_update` | action | 배너 수정 직전. 인자: (Banner $banner, array $data) | `src/Services/BannerService.php:140` |
| `sirsoft-hero_section.banner.filter_create_data` | filter | 등록 값 가공. 인자: (array $data) | `src/Services/BannerService.php:104` |
| `sirsoft-hero_section.banner.filter_list_params` | filter | 관리자 목록 조건 가공. 인자: (array $filters) | `src/Services/BannerService.php:42` |
| `sirsoft-hero_section.banner.filter_update_data` | filter | 수정 값 가공. 인자: (array $data, Banner $banner) | `src/Services/BannerService.php:141` |
| `sirsoft-hero_section.banner.store_validation_rules` | filter | 등록 검증 규칙 가공. 인자: (array $rules, FormRequest $request) | `src/Http/Requests/Admin/StoreBannerRequest.php:55` |
| `sirsoft-hero_section.banner.update_validation_rules` | filter | 수정 검증 규칙 가공. 인자: (array $rules, FormRequest $request) | `src/Http/Requests/Admin/UpdateBannerRequest.php:59` |
| `sirsoft-hero_section.banner_image.after_change` | action | 배너 수정 저장으로 기기별 이미지가 바뀐 직후. 인자: (Banner $banner, string $device, string $change — replaced·removed) | `src/Services/BannerService.php:160` |
| `sirsoft-hero_section.banner_image.after_delete` | action | 배너 이미지 삭제 직후. 인자: (array $snapshot) | `src/Services/BannerImageService.php:121` |
| `sirsoft-hero_section.banner_image.after_upload` | action | 배너 이미지 임시 업로드 직후(배너에는 저장 때 붙는다). 인자: (BannerImage $image) | `src/Services/BannerImageService.php:101` |
| `sirsoft-hero_section.banner_image.filter_upload_file` | filter | 올린 이미지 파일 가공(압축 등). 인자: (UploadedFile $file) | `src/Services/BannerImageService.php:67` |
| `sirsoft-hero_section.hero.filter_public_banners` | filter | 사용자 화면 노출 배너 목록 가공. 인자: (array $banners, string $position, string $locale) | `src/Services/PublicHeroService.php:64` |
| `sirsoft-hero_section.position_setting.after_update` | action | 위치 슬라이더 설정 저장 직후. 인자: (PositionSetting $setting, array $snapshot) | `src/Services/PositionSettingService.php:72` |
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
