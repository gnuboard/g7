# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.0-beta.2] - 2026-04-20

### Changed

- 코어 최소 요구 버전을 7.0.0-beta.2 로 상향
- sirsoft-page 의존성 버전 제약을 실제 릴리스 버전에 맞춰 정비
- 플러그인 의존성 관리를 manifest(plugin.json) 기준으로 일원화

## [1.0.0-beta.1] - 2026-04-01

### Changed

- 오픈 베타 릴리즈

## [0.3.3] - 2026-03-30

### Fixed

- 관리자 다크모드 레이아웃 수정 — plugin_settings.json에서 누락된 `dark:` variant 약 10건 추가 (text-gray, focus:ring 등)

## [0.3.2] - 2026-03-30

### Added

- `config/settings/defaults.json` 추가 — frontend_schema 정의로 프론트엔드 설정 노출 제어
- `marketing_consent_terms_slug`, `third_party_consent_terms_slug`, `info_disclosure_terms_slug`, `channels`를 `expose: false` 처리 — 프론트엔드 미참조 설정의 보안 노출 차단

## [0.3.1] - 2026-03-30

### Fixed

- 인덱스명 약어 수정 — `idx_mkt_consent_hist_user_created` → `idx_marketing_consent_histories_user_created` (네이밍 규칙 준수)

## [0.3.0] - 2026-03-29

### Added

- 검색/필터/정렬 성능 향상을 위한 누락 인덱스 추가 — user_marketing_consent_histories([user_id+created_at] 사용자별 시간순 이력 조회)

## [0.2.1] - 2026-03-28

### Fixed

- 채널 삭제 시 즉시 API 호출로 반영되던 문제 수정 — 삭제 후 최종 저장 버튼 클릭 시에만 반영되도록 변경
- 저장 버튼이 channels API 검증을 거치도록 2단계 순차 호출로 변경 (channels 검증 → 설정 저장)
- 비활성화 모달(channel_deactivate_modal)이 즉시 API 호출 대신 상태만 변경하도록 수정

## [0.2.0] - 2026-03-24

### Added

- 마케팅 동의 데이터를 EAV 구조 기반 전용 테이블(`user_marketing_consents`, `user_marketing_consent_histories`)로 전환 — 동의 항목별 독립 저장 및 이력 관리
- 각 동의 항목의 활성화 여부를 독립 설정(`*_enabled`)으로 분리하고 비활성 항목은 가입/수정 폼에서 자동 제외
- 마케팅 동의 현황 조회 및 채널별 동의 현황 조회 공개 API 추가
- 관리자 사용자 상세 화면에서 마케팅 동의 현황 및 이력 조회 기능 추가 (MarketingAdminController)
- 동의항목 레이아웃을 1열 구성으로 변경하고 약관 모달에서 실제 약관 내용 표시 구현
- 채널 관리 UI 추가 — 채널 추가/편집/삭제 및 활성화 토글 기능
- 데이터 접근 계층을 Repository 패턴으로 분리하여 테스트 용이성과 유지보수성 개선
- PHP 백엔드에서 다국어 메시지를 사용할 수 있도록 PHP 전용 lang 파일 추가 (ko/en)
- 플러그인 레이아웃 JSON 파일 전체를 대상으로 렌더링 테스트 56개 작성 — 구조, 데이터 바인딩, 다국어, 다크모드 항목 검증
- 플러그인 프론트엔드 테스트 환경(Vitest) 구성
- 동의자가 있는 채널을 삭제하려 할 때 즉시 삭제 대신 비활성화를 안내하는 모달 추가
- 채널 이름을 한국어/영어로 각각 표시할 수 있도록 다국어 처리 추가

### Fixed

- 채널 편집 모달 바깥 영역 클릭 시 실수로 닫히는 문제 수정
- 스피너·배지 컴포넌트에서 다크모드 색상이 적용되지 않던 문제 수정
- 마이페이지 프로필 저장 시 국가·언어 미선택 상태에서 오류가 발생하던 문제 수정
- 동일 이벤트가 여러 번 발생할 때 동의 이력이 중복 저장되던 문제 수정

### Changed

- 관리자 화면 사용자 상세 폼의 다크모드 스타일 전반 개선

## [0.1.3] - 2026-03-16

### Changed

- 마이그레이션 통합 — 증분 마이그레이션을 테이블당 1개 create 마이그레이션으로 정리
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
- 마케팅 동의 플러그인 초기 구현
- 이메일 구독, 마케팅 동의, 제3자 제공 동의 관리
- MarketingConsent, MarketingConsentHistory 모델
- MarketingConsentService 비즈니스 로직
- MarketingConsentListener 훅 리스너
- 사용자 관리 화면 레이아웃 확장 (마케팅 동의 상세/폼/프로필)
- 회원가입 폼 마케팅 동의 항목 확장
- 플러그인 설정 UI
- 역할 기반 접근 제어 (마케팅 관리자)
- 다국어 지원 (ko, en)
