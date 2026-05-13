# Changelog

이 언어팩의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.0-beta.2] - 2026-05-12

### Added

- 본인인증 활동 로그 액션 라벨 일본어 번역 추가 (`verify`, `verify_failed`)

### Changed

- 본인인증 관리자 권한 시드를 코어의 조회/수정 분리 정책에 맞춰 갱신
  - `core.admin.identity.manage` → `core.admin.identity.providers.update`
  - `core.admin.identity.policies.manage` → `core.admin.identity.policies.update`
  - `core.admin.identity.providers.read`, `core.admin.identity.policies.read` 신설
- 코어 최소 요구 버전을 `>=7.0.0-beta.5` 로 상향 (신 권한 키가 코어 beta.5 시드에 의존)

## [1.0.0-beta.1] - 2026-05-11

### Added

- 코어의 일본어 번들 언어팩 초기 제공
