# CloudFront CDN — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_이 확장은 훅을 발행하지 않습니다._
<!-- @generated:hooks-published END -->

<!-- @intent START -->
발행하는 훅이 없다. 무효화 결과를 다른 확장이 알아야 할 쓰임새가 생기면 그때 `sirsoft-cdn_cloudfront.purge.*` 로 추가한다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.plugin_settings.after_reset` | action | `SyncCloudFrontDiskListener` | `afterReset` | 10 |
| `core.plugin_settings.after_save` | action | `SyncCloudFrontDiskListener` | `afterSave` | 10 |
| `core.plugin_settings.update_validation_rules` | filter | `ValidatePluginSettingsListener` | `rules` | 10 |
| `core.plugins.after_deactivate` | action | `SyncCloudFrontDiskListener` | `afterDeactivate` | 10 |
| `core.settings.available_public_asset_drivers` | filter | `RegisterCloudFrontDiskListener` | `register` | 10 |
| `core.thumbnail.after_purge` | action (미선언) | `PurgeThumbnailsListener` | `purge` | 10 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
- `core.thumbnail.after_purge` 는 액션이라 큐 드라이버가 있으면 큐에서 돈다 — 사용자 응답을 CDN API 가 붙잡지 않는다.
- `core.settings.available_public_asset_drivers` 는 디스크 정의(config)가 있을 때만 항목을 올린다. 정의가 없는데 선택지만 있으면 고른 값이 기본 디스크로 바뀌어 쓰이기 때문이다.
- `core.plugin_settings.update_validation_rules` 는 CDN 주소(http, https, 쿼리, 조각 금지)와 배포 ID(영문 대문자, 숫자) 모양을 검증한다.
- `core.plugin_settings.after_save`, `after_reset`, `core.plugins.after_deactivate` 는 디스크 정의를 고치고 `queue:restart` 를 보낸다. 재시작을 그 워커가 처리하게 되지 않도록 동기(`sync`)로 돈다.
- 제거 훅은 구독하지 않는다. 코어가 켜진 플러그인을 제거하기 전에 비활성화를 거치므로 `core.plugins.after_deactivate` 에서 디스크 정의를 한 번 지우고 `queue:restart` 를 보내며, 제거가 끝나면 코어가 큐 워커에 재시작 신호를 한 번 더 보낸다. 이미 꺼진 플러그인의 제거는 끌 때 정리가 끝나 있다. 제거 완료 훅 때는 플러그인 디렉터리가 이미 지워져 리스너 클래스를 다시 읽을 수 없다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `PurgeThumbnailsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/PurgeThumbnailsListener.php` |
| `RegisterCloudFrontDiskListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RegisterCloudFrontDiskListener.php` |
| `SyncCloudFrontDiskListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/SyncCloudFrontDiskListener.php` |
| `ValidatePluginSettingsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ValidatePluginSettingsListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
리스너는 활성 플러그인만 등록된다. 그래서 끄면 카탈로그에서 선택지가 빠지고 무효화도 멈춘다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_레이아웃 확장이 없습니다._
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
레이아웃 확장이 없다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 미들웨어가 없습니다._
<!-- @generated:middleware END -->

<!-- @intent START -->
미들웨어가 없다. 보조 API 권한은 라우트 `permission` 미들웨어(코어)로 건다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
브로드캐스트 채널이 없다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 스케줄 | 주기 | 설명 |
|---|---|---|
| `retry-purges` | `*/5 * * * *` | CDN 캐시 무효화 재시도 |
<!-- @generated:schedules END -->

<!-- @intent START -->
`retry-purges` 는 5분마다 돌지만 대기열이 비면 API 를 부르지 않는다. 다시 보낸 건이 실패해도 명령은 성공으로 끝낸다 — 실패는 대기열에 남고, 명령 실패로 두면 스케줄 실패 알림이 5분마다 쌓인다. 실행 기록은 실패만 남긴다(`history_mode=failures_only`).
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 알림 정의가 없습니다._
<!-- @generated:notifications END -->

<!-- @intent START -->
알림 정의가 없다. 재시도 상한에 닿은 건은 설정 화면 「무효화 대기 현황」과 정기 작업 출력(경고)으로 알린다. 그 경고 문구(`messages.retry.abandoned`)는 건수에 따라 단수, 복수 형태가 갈리므로 `__()` 가 아니라 `trans_count()` 로 고른다. 영문 언어팩은 `단수|복수` 두 형태를 두고, 한국어처럼 형태가 하나인 언어는 한 형태만 둔다.
<!-- @intent END -->
