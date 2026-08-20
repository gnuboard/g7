# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.2] - 2026-08-19

### Changed

- 코어 7.0.7의 외부 스크립트 보안 정책 강화에 맞춰, 주소 검색에 쓰는 Daum 우편번호 스크립트 주소(`t1.daumcdn.net`)를 이 플러그인의 신뢰 출처로 선언했습니다. 정책이 강화된 이후에도 주소 검색 창이 정상적으로 열립니다.
- 신뢰 출처 선언 기능이 코어 7.0.7에서 도입되었으므로, 설치에 필요한 최소 코어 버전을 7.0.7로 조정했습니다.

### Fixed

- 플러그인 설정 저장이 실패했을 때 실패 사유 대신 일반 안내 문구만 표시되던 문제를 수정했습니다. 이제 서버가 알려준 사유가 그대로 안내됩니다.

## [1.0.1] - 2026-08-10

### Fixed

- 오류 안내가 뜨기는 하지만 내용이 비어 있던 문제를 수정했습니다. 설정 저장에 실패하면 서버가 알려 준 사유가 그대로 표시됩니다.
- 설정 화면의 아이콘이 의도한 크기보다 크거나 작게 보이던 문제를 수정했습니다.

## [1.0.0] - 2026-07-01

### Added

- 레이아웃 편집기 데이터 소스 목록에서 이 확장이 제공하는 데이터 소스가 친화 명칭으로 표시되고, 어느 확장이 제공했는지 출처가 함께 표시됩니다.

### Changed

- 플러그인 환경설정 화면의 하단 저장 버튼이 스크롤 중에도 화면에 고정되도록 개선.

## [1.0.0-beta.2] - 2026-04-20

### Changed

- 주소 검색 영역의 콘텐츠 카드 / 세로 정렬 컨테이너 외형을 sirsoft-admin_basic 표준 시맨틱과 정합 — 다른 화면과 같은 결로 통일.
- 코어 최소 요구 버전을 7.0.0-beta.2 로 상향
- extension JSON: `extensionPointProps.onAddressSelect` → `extensionPointCallbacks.onAddressSelect` 참조 변경 (extension_point props/callbacks 분리)
- 플러그인 환경설정 화면의 하단 저장 버튼이 스크롤 중에도 화면에 고정되도록 개선.
- 플러그인 환경설정 화면의 폼 라벨 / 보조 설명 / 에러 메시지 시각 시맨틱을 sirsoft-admin_basic 표준 시맨틱과 정합 — 다른 관리자 화면과 같은 결로 통일.
- 플러그인 환경설정 화면 곳곳의 텍스트 톤 (보조 설명 · 라벨 · 본문 · 강조 · 작은 보조) 시각 시맨틱을 관리자 표준 시맨틱과 정합 — 같은 결의 글자 톤이 한 곳에서 일괄 조정 가능.
- 플러그인 환경설정 화면의 세로 정렬 컨테이너 / 입력 박스를 sirsoft-admin_basic 표준 시맨틱 (.stack / .input) 과 정합 — 표준 간격과 외형이 일관 표시되도록 정리.

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
