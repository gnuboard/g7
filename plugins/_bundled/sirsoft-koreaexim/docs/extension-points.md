# 수출입은행 환율 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 2종 / 호출 지점 1곳.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-koreaexim.notification.extract_data` | filter | 실패 알림 변수 | 선언 (호출 위치 미확인) |
| `sirsoft-koreaexim.update.failed` | action | 환율 갱신 실패 | `src/Services/ExchangeRateUpdateService.php:223` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
실패 액션은 알림 대상 오류에만 발행하고 같은 상태는 하루에 한 번 알립니다. no_data와 연결 테스트는 발송하지 않습니다. extract_data는 코어 알림 발송 서비스가 호출하는 필터입니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.notification.filter_default_definitions` | filter | `UpdateFailedNotificationDataListener` | `defaults` | 20 |
| `core.plugin_settings.update_validation_rules` | filter | `ValidatePluginSettingsListener` | `rules` | 10 |
| `sirsoft-ecommerce.currency.registered_rate_providers` | filter | `RegisterExchangeRateProviderListener` | `registerProvider` | 10 |
| `sirsoft-ecommerce.settings.after_save` | action (미선언) | `ReapplyRatesOnCurrencyChangeListener` | `reapply` | 10 |
| `sirsoft-koreaexim.notification.extract_data` | filter | `UpdateFailedNotificationDataListener` | `extract` | 10 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
제공자 등록 필터는 현재 설정과 최신 성공 응답으로 관리 통화를 제공합니다. settings.after_save는 language_currency 저장일 때만 재적용하며, 일반 설정 저장과 환율 적용 훅을 분리해 재귀를 방지합니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `ReapplyRatesOnCurrencyChangeListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ReapplyRatesOnCurrencyChangeListener.php` |
| `RegisterExchangeRateProviderListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RegisterExchangeRateProviderListener.php` |
| `UpdateFailedNotificationDataListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/UpdateFailedNotificationDataListener.php` |
| `ValidatePluginSettingsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ValidatePluginSettingsListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
재적용 리스너는 sync=true로 등록하여 큐 워커 없이 저장 응답 전에 처리합니다. 리스너는 실행 서비스에 위임하며 DB나 은행 API를 직접 호출하지 않습니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_레이아웃 확장이 없습니다._
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
기존 모듈의 환율 입력은 중립적인 active_rate_provider 응답을 읽습니다. 이 플러그인은 특정 통화 행을 덮어쓰는 레이아웃 조각을 주입하지 않습니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 미들웨어 | 부착 대상(targets) | 우선순위 |
|---|---|---|
| `ManageExchangeRateSettings` | `/api/admin/plugins/sirsoft-koreaexim/settings` | - |
<!-- @generated:middleware END -->

<!-- @intent START -->
코어 플러그인 설정 PUT 경로에 manage 권한을 추가합니다. 다른 플러그인 경로와 GET 조회에는 영향을 주지 않으며 코어 권한 판정도 유지합니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
환율 갱신은 실시간 사용자 메시지를 요구하지 않아 브로드캐스트 채널을 만들지 않습니다. 관리자 화면은 실행 완료 후 관련 데이터소스를 다시 조회합니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 스케줄 | 주기 | 설명 |
|---|---|---|
| `sirsoft-koreaexim:update --scheduled` | `30 11 * * *` | 환율 정기 갱신 |
| `sirsoft-koreaexim:update --scheduled --retry` | `hourly` | 환율 재시도 |
| `sirsoft-koreaexim:prune-history` | `57 4 * * *` | 환율 이력 정리 |
<!-- @generated:schedules END -->

<!-- @intent START -->
KST 갱신 시각을 사이트 스케줄 시간대로 바꿉니다. 요일은 날짜가 밀릴 수 있으므로 cron이 아닌 실행 서비스에서 KST로 판정합니다. 재시도는 매시간 조건을 검사하고 조건 미충족이면 이력을 만들지 않습니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 알림 키 | 채널 |
|---|---|
| `koreaexim_update_failed` | `mail`, `database` |
<!-- @generated:notifications END -->

<!-- @intent START -->
koreaexim_update_failed 정의의 소유자는 이 플러그인입니다. 제목·본문·수신자는 공통 알림 템플릿 저장소와 API를 사용하며 플러그인 환경설정에서 편집합니다. 코어 환경설정의 알림 탭은 확장 필터 없이 조회하므로 이 정의도 함께 표시하며, 두 화면은 같은 API 를 쓰므로 어느 쪽에서 고쳐도 같은 값입니다. 플러그인 화면은 자기 정의만 보이도록 extension_type=plugin·extension_identifier=sirsoft-koreaexim 으로 필터합니다. 업데이트는 사용자 수정값을 보존합니다.
<!-- @intent END -->
