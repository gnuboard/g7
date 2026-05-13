# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.0-beta.2] - 2026-04-20

### Changed

- 코어 최소 요구 버전을 7.0.0-beta.2 로 상향
- extension JSON: `extensionPointProps.onAddressSelect` → `extensionPointCallbacks.onAddressSelect` 참조 변경 (extension_point props/callbacks 분리)

## [1.0.0-beta.1] - 2026-04-01

### Changed

- 오픈 베타 릴리즈

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
- Daum 우편번호 검색 플러그인 초기 구현
- Daum 우편번호 서비스 API 연동 (API 키 불필요)
- 주소 검색 팝업/레이어 표시 모드 설정
- 커스텀 핸들러 (openPostcode, setFieldReadOnly)
- 이커머스 주소 검색 레이아웃 확장 (ecommerce-address-search)
- 플러그인 설정 UI (표시 모드, 테마 설정)
- 다국어 지원 (ko, en)
