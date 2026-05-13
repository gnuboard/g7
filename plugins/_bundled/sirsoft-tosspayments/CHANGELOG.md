# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.0-beta.3] - 2026-05-11

### Added

- 결제 취소 전후에 외부 확장이 본인인증 등 추가 로직을 붙일 수 있는 확장점 제공

### Changed

- sirsoft-ecommerce 의존성을 `>=1.0.0-beta.3` 으로 정비

## [1.0.0-beta.2] - 2026-04-20

### Changed

- 코어 최소 요구 버전을 7.0.0-beta.2 로 상향
- sirsoft-ecommerce 의존성 버전 제약을 실제 릴리스 버전에 맞춰 정비
- 플러그인 의존성 관리를 manifest(plugin.json) 기준으로 일원화

## [1.0.0-beta.1] - 2026-04-01

### Changed

- 오픈 베타 릴리즈

## [0.2.2] - 2026-03-30

### Changed

- frontend_schema에서 `is_test_mode`, `redirect_success_url`, `redirect_fail_url`을 `expose: false`로 변경 — 프론트엔드 미참조 설정의 보안 노출 차단

## [0.2.1] - 2026-03-25

### Changed

- app(PluginSettingsService::class) 직접 호출을 plugin_settings() 헬퍼로 교체 (SuccessCallbackRequest, PaymentRefundListener, RegisterPgProviderListener)
- 불필요한 PluginSettingsService use문 제거 (3개 파일)

## [0.2.0] - 2026-03-24

### Added

- 환불 리스너 추가 (PaymentRefundListener): 주문 취소 시 토스페이먼츠 환불 API 연동
- 다국어 메시지 파일 추가 (ko/en messages.php)

## [0.1.3] - 2026-03-16

### Changed

- 라이선스 프로그램 명칭 정비

## [0.1.2] - 2026-03-13

### Added

- manifest에 license 필드 및 LICENSE 파일 추가

### Changed

- 설정 레이아웃 경로를 `resources/layouts/settings.json` → `resources/layouts/admin/plugin_settings.json`으로 이동 (모듈과 동일한 구조 통일)

## [0.1.1] - 2026-02-24

### Changed
- 버전 체계 조정 (정식 출시 전 0.x 체계로 변경)

## [0.1.0] - 2026-02-23

### Added
- 토스페이먼츠 결제 플러그인 초기 구현
- 카드/계좌이체/가상계좌/무통장입금 결제 지원
- 결제 위젯 (TossPayments SDK) 통합
- 결제 확인 (confirm) 프로세스
- 결제 성공/실패 콜백 처리
- 결제 상태 웹훅 처리
- 플러그인 설정 UI (API 키, 시크릿 키 관리)
- sirsoft-ecommerce 모듈 결제 인터페이스 (PaymentInterface) 연동
- 다국어 지원 (ko, en)
