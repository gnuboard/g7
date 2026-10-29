# 팝업 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 27종 / 호출 지점 27곳.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-popup.popup.after_bulk_copy` | action | 일괄 복사 직후. 인자: (array $copies — 원본 ID => 새 ID, array $failedIds) | `src/Services/PopupService.php:315` |
| `sirsoft-popup.popup.after_bulk_delete` | action | 일괄 삭제 직후. 인자: (array $ids, array $snapshots) | `src/Services/PopupService.php:345` |
| `sirsoft-popup.popup.after_bulk_status` | action | 일괄 노출·비노출 직후. 인자: (array $ids, bool $isActive, array $snapshots) | `src/Services/PopupService.php:246` |
| `sirsoft-popup.popup.after_create` | action | 팝업 등록 직후. 인자: (Popup $popup) | `src/Services/PopupService.php:142` |
| `sirsoft-popup.popup.after_delete` | action | 팝업 영구 삭제 직후. 인자: (int $popupId, array $snapshot) | `src/Services/PopupService.php:223` |
| `sirsoft-popup.popup.after_public_list` | action | 사용자 화면 노출 후보 조회 직후. 인자: (array $popups, array $context — user·locale) | `src/Services/PublicPopupService.php:85` |
| `sirsoft-popup.popup.after_reorder` | action | 순서 변경 직후. 인자: (array $ids) | `src/Services/PopupService.php:362` |
| `sirsoft-popup.popup.after_update` | action | 팝업 수정 직후. 인자: (Popup $popup, array $snapshot) | `src/Services/PopupService.php:194` |
| `sirsoft-popup.popup.before_bulk_copy` | action | 일괄 복사 직전. 인자: (array $ids) | `src/Services/PopupService.php:263` |
| `sirsoft-popup.popup.before_bulk_delete` | action | 일괄 삭제 직전. 인자: (array $ids) | `src/Services/PopupService.php:328` |
| `sirsoft-popup.popup.before_bulk_status` | action | 일괄 노출·비노출 직전. 인자: (array $ids, bool $isActive) | `src/Services/PopupService.php:236` |
| `sirsoft-popup.popup.before_create` | action | 팝업 등록 직전. 인자: (array $data) | `src/Services/PopupService.php:120` |
| `sirsoft-popup.popup.before_delete` | action | 팝업 영구 삭제 직전. 인자: (Popup $popup) | `src/Services/PopupService.php:209` |
| `sirsoft-popup.popup.before_public_list` | action | 사용자 화면 노출 후보 조회 직전(봇 제외). 인자: (array $context — user·locale) | `src/Services/PublicPopupService.php:70` |
| `sirsoft-popup.popup.before_reorder` | action | 순서 변경 직전. 인자: (array $ids) | `src/Services/PopupService.php:358` |
| `sirsoft-popup.popup.before_update` | action | 팝업 수정 직전. 인자: (Popup $popup, array $data) | `src/Services/PopupService.php:166` |
| `sirsoft-popup.popup.filter_create_data` | filter | 등록 값 가공. 인자: (array $data) | `src/Services/PopupService.php:121` |
| `sirsoft-popup.popup.filter_list_params` | filter | 관리자 목록 조건 가공. 인자: (array $filters) | `src/Services/PopupService.php:52` |
| `sirsoft-popup.popup.filter_public_list_result` | filter | 사용자 화면 노출 후보 가공(캐시에서 꺼내 기간을 다시 판정한 뒤). 인자: (array $popups, array $context — user·locale) | `src/Services/PublicPopupService.php:83` |
| `sirsoft-popup.popup.filter_sanitize_config` | filter | 직접 작성형 본문 정화 허용 목록 가공. 인자: (array $config — HTMLPurifier 설정 키 => 값) | `src/Services/PopupHtmlSanitizer.php:118` |
| `sirsoft-popup.popup.filter_update_data` | filter | 수정 값 가공. 인자: (array $data, Popup $popup) | `src/Services/PopupService.php:167` |
| `sirsoft-popup.popup.store_validation_rules` | filter | 등록 검증 규칙 가공. 인자: (array $rules, FormRequest $request) | `src/Http/Requests/Admin/StorePopupRequest.php:57` |
| `sirsoft-popup.popup.update_validation_rules` | filter | 수정 검증 규칙 가공. 인자: (array $rules, FormRequest $request) | `src/Http/Requests/Admin/UpdatePopupRequest.php:89` |
| `sirsoft-popup.popup_image.after_change` | action | 팝업 수정 저장으로 기기별 이미지가 바뀐 직후. 인자: (Popup $popup, string $device, string $change — replaced·removed) | `src/Services/PopupService.php:191` |
| `sirsoft-popup.popup_image.after_delete` | action | 팝업 이미지 삭제 직후. 인자: (array $snapshot) | `src/Services/PopupImageService.php:148` |
| `sirsoft-popup.popup_image.after_upload` | action | 팝업 이미지 임시 업로드 직후(팝업에는 저장 때 붙는다). 인자: (PopupImage $image) | `src/Services/PopupImageService.php:128` |
| `sirsoft-popup.popup_image.filter_upload_file` | filter | 올린 이미지 파일 가공(압축 등). 인자: (UploadedFile $file) | `src/Services/PopupImageService.php:72` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
`popup.*` before/after 훅은 서비스가 트랜잭션 밖에서 발행한다 — after 훅은 커밋된 결과를 본다. 다른 확장이 노출 후보를 바꾸려면 `sirsoft-popup.popup.filter_public_list_result`(인자 `$popups, $context` — `user`·`locale`)를, 본문 정화 허용 목록을 바꾸려면 `sirsoft-popup.popup.filter_sanitize_config` 를 잡는다. 공개 필터는 캐시에서 꺼내 기간을 다시 판정한 뒤에 불린다(캐시 안의 값을 바꾸지 않는다).
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.activity_log.filter_description_params` | filter | `ActivityLogDescriptionResolver` | `resolveDescriptionParams` | 10 |
| `core.plugin_settings.after_reset` | action (미선언) | `PopupCacheListener` | `onPluginSettingsSaved` | 5 |
| `core.plugin_settings.after_save` | action (미선언) | `PopupCacheListener` | `onPluginSettingsSaved` | 5 |
| `core.plugins.before_uninstall` | action (미선언) | `PluginLifecycleListener` | `beforeUninstall` | 10 |
| `sirsoft-gdpr.necessary_storage.filter_provided_keys` | filter | `ProvideNecessaryStorageKeysListener` | `provide` | 10 |
| `sirsoft-popup.popup.after_bulk_copy` | action (미선언) | `PopupActivityLogListener` | `handleBulkCopy` | 20 |
| `sirsoft-popup.popup.after_bulk_copy` | action (미선언) | `PopupCacheListener` | - | - |
| `sirsoft-popup.popup.after_bulk_delete` | action (미선언) | `PopupActivityLogListener` | `handleBulkDelete` | 20 |
| `sirsoft-popup.popup.after_bulk_delete` | action (미선언) | `PopupCacheListener` | - | - |
| `sirsoft-popup.popup.after_bulk_status` | action (미선언) | `PopupActivityLogListener` | `handleBulkStatus` | 20 |
| `sirsoft-popup.popup.after_bulk_status` | action (미선언) | `PopupCacheListener` | - | - |
| `sirsoft-popup.popup.after_create` | action (미선언) | `PopupActivityLogListener` | `handleCreated` | 20 |
| `sirsoft-popup.popup.after_create` | action (미선언) | `PopupCacheListener` | - | - |
| `sirsoft-popup.popup.after_delete` | action (미선언) | `PopupActivityLogListener` | `handleDeleted` | 20 |
| `sirsoft-popup.popup.after_delete` | action (미선언) | `PopupCacheListener` | - | - |
| `sirsoft-popup.popup.after_reorder` | action (미선언) | `PopupActivityLogListener` | `handleReordered` | 20 |
| `sirsoft-popup.popup.after_reorder` | action (미선언) | `PopupCacheListener` | - | - |
| `sirsoft-popup.popup.after_update` | action (미선언) | `PopupActivityLogListener` | `handleUpdated` | 20 |
| `sirsoft-popup.popup.after_update` | action (미선언) | `PopupCacheListener` | - | - |
| `sirsoft-popup.popup_image.after_change` | action (미선언) | `PopupActivityLogListener` | `handleImageChanged` | 20 |
| `sirsoft-popup.popup_image.after_change` | action (미선언) | `PopupCacheListener` | - | - |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
`sirsoft-gdpr.necessary_storage.filter_provided_keys` 는 GDPR 플러그인의 공개 계약 훅이다 — 「보지 않기」 쿠키 `g7_popup_dismissed` 를 필수 저장 항목으로 더할 뿐 GDPR 코드를 참조하지 않는다. GDPR 이 없거나 꺼져 있으면 불리지 않는다. `core.plugin_settings.after_save`·`after_reset` 은 이 플러그인 설정이 바뀌면 공개 캐시를 비우려고 구독한다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `ActivityLogDescriptionResolver` | 1개 | 명시 등록 | ✅ | `src/Listeners/ActivityLogDescriptionResolver.php` |
| `PluginLifecycleListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/PluginLifecycleListener.php` |
| `PopupActivityLogListener` | 8개 | 명시 등록 | ✅ | `src/Listeners/PopupActivityLogListener.php` |
| `PopupCacheListener` | 10개 | 명시 등록 | ✅ | `src/Listeners/PopupCacheListener.php` |
| `ProvideNecessaryStorageKeysListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ProvideNecessaryStorageKeysListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
리스너는 모두 `plugin.php` 의 `getHookListeners()` 에 올라 있다. 활동 로그는 항목별(일괄 처리는 건마다 한 줄), 캐시 비우기는 `sync` — 큐로 미루면 저장 직후 사용자 화면에 옛 팝업이 보인다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/popup_layer.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `user_global_overlay` |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
주입 조각 `popup_layer` 는 `user_global_overlay` 에 priority 60 으로 붙는다(GDPR 쿠키 배너 50 뒤). 레이어는 z-index 40 이라 GDPR 배너(z-50) 아래에 깔리고, 모바일 하단 시트는 GDPR 배너 요소(`#gdpr_cookie_banner` — GDPR 조각의 data-ref, 공개 계약이 아니다)의 높이만큼 올라간다. 그 요소가 없으면 바닥에 붙는다.

템플릿이 레이어를 바꾸려면 `src/components/overrides/sirsoft-popup/PopupLayer.tsx` 로 오버라이드한다. props 계약: `popups`(공개 API 배열, 서버 순서) · `settings`(표시 방식) · `path`(현재 경로 — 바뀔 때마다 다시 판정) · `className`.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 미들웨어가 없습니다._
<!-- @generated:middleware END -->

<!-- @intent START -->
이 플러그인은 자기 미들웨어를 선언하지 않는다. 공개 API 는 코어 `optional.sanctum`(토큰 있으면 회원, 없거나 정지 회원이면 손님)과 throttle 을 쓴다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
브로드캐스트를 쓰지 않는다 — 사용자 화면은 페이지를 열 때 공개 API 를 받는다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 스케줄 | 주기 | 설명 |
|---|---|---|
| `prune-temp-images` | `0 0 * * *` | 임시 팝업 이미지 삭제 |
<!-- @generated:schedules END -->

<!-- @intent START -->
`prune-temp-images`(매일 0시)는 등록·수정 화면에서 올렸지만 저장하지 않은 임시 이미지를 지운다(기본 2일 보존). 파일 삭제가 실패한 행은 남겨 다음 회차에 다시 시도한다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 알림 정의가 없습니다._
<!-- @generated:notifications END -->

<!-- @intent START -->
알림을 보내지 않는다.
<!-- @intent END -->
