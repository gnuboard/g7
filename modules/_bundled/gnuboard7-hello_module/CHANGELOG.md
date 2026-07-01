# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [0.1.0] - 2026-07-01

### Changed

- 메모 관리 화면의 콘텐츠 카드 외형을 sirsoft-admin_basic 표준 시맨틱 (.admin-card) 과 정합 — 다른 관리자 화면과 같은 결로 통일.
- 관리자 화면 좌우 정렬 컨테이너(섹션 헤더 · 액션바 · 카드 상단부 등) 외형을 표준 시맨틱(.flex-between)으로 정리 — 시각 변경 없음, 향후 톤 조정이 한 곳에서 가능.

### Added

- 학습용 최소 샘플 모듈 초기 릴리즈
- Memo 엔티티 Admin CRUD 및 공개 읽기 API
- 훅 발행 데모 (`gnuboard7-hello_module.memo.created`)
- 기본 권한 4종 (read, create, update, delete) 및 관리자 메뉴 등록
- 다국어 지원 (ko, en)
- 레이아웃 편집기 데이터 소스 목록에서 이 확장이 제공하는 데이터 소스가 친화 명칭으로 표시되고, 어느 확장이 제공했는지 출처가 함께 표시됩니다.
