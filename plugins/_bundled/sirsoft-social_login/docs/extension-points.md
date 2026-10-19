# 소셜 로그인 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 2종 / 호출 지점 2곳. 훅 이름이 상수·변수로 조립된 호출이 2곳 있어 호출 위치가 표에 다 실리지 않을 수 있습니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-social_login.profile.filter_normalized` | filter | 제공자 프로필을 정규화한 직후 걸러 냅니다. 인자: (NormalizedProfile $profile, string $providerKey) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.provider.filter_registered` | filter | 제공자 드라이버 목록을 걸러 냅니다. 확장이 새 키로 ProviderDriverInterface 구현을 더할 수 있습니다(내장 키 덮어쓰기는 무시). 인자: (array<string, ProviderDriverInterface> $drivers) | 선언 (호출 위치 미확인) |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
`getHooks()` 에 필터 2개(`provider.filter_registered`, `profile.filter_normalized`)를 선언했습니다. 발화는 클래스 상수로 합니다 — `provider.filter_registered` 는 `ProviderRegistry::all()`(`ProviderRegistry::FILTER_REGISTERED`), `profile.filter_normalized` 는 OAuth 콜백에서 프로필을 정규화한 직후(`OAuthFlowService::FILTER_PROFILE`)입니다. 그래서 표의 발행 위치는 「선언」으로 보입니다. 훅 인자에는 토큰, 비밀값, 원본 프로필을 싣지 않습니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.plugin_settings.update_validation_rules` | filter | `ValidateSettingsListener` | `rules` | 10 |
| `sirsoft-social_login.provider.filter_registered` | filter | `RegisterMockProvidersListener` | `register` | 10 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
코어 `core.plugin_settings.update_validation_rules` 를 구독해 이 플러그인 식별자일 때만 설정 검증 규칙을 더합니다. 자기 필터 `provider.filter_registered` 도 구독합니다 — 모의 게이트가 켜졌을 때만 모의 제공자 5종을 더해, 다른 확장이 제공자를 등록하는 경로를 그대로 실증합니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `RegisterMockProvidersListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RegisterMockProvidersListener.php` |
| `ValidateSettingsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ValidateSettingsListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
`RegisterMockProvidersListener` 는 모의 게이트가 켜졌을 때만 모의 제공자 5종을 더하고, `ValidateSettingsListener` 는 설정 저장 규칙을 더합니다. 리스너는 컨테이너로 만들어지므로 생성자 주입을 씁니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_레이아웃 확장이 없습니다._
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
1.0.0 에는 레이아웃 확장이 없습니다. 로그인, 가입 화면 버튼은 코어 템플릿의 확장점(`auth_login_extensions`, `auth_register_extensions`)에 다음 단계에서 주입합니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 미들웨어가 없습니다._
<!-- @generated:middleware END -->

<!-- @intent START -->
미들웨어를 선언하지 않습니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
브로드캐스트 채널을 쓰지 않습니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 스케줄이 없습니다._
<!-- @generated:schedules END -->

<!-- @intent START -->
1.0.0 에는 스케줄이 없습니다. 보존 기간 정리 배치(`sirsoft-social_login:prune`)는 관리자 진단, 통계와 함께 들어옵니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 알림 정의가 없습니다._
<!-- @generated:notifications END -->

<!-- @intent START -->
1.0.0 에는 알림 정의가 없습니다.
<!-- @intent END -->
