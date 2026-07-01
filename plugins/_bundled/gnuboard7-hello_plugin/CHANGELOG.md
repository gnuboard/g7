# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [0.1.0] - 2026-07-01

### Changed

- 플러그인 환경설정 화면의 하단 저장 버튼이 스크롤 중에도 화면에 고정되도록 개선.
- 플러그인 표준 베이스를 따르도록 내부 구조 정비. 동작 변화 없음.
- 코어 최소 요구 버전을 7.0.0-beta.8 로 상향.
- 플러그인 환경설정 화면의 폼 라벨 / 보조 설명 / 에러 메시지 시각 시맨틱을 sirsoft-admin_basic 표준 시맨틱과 정합 — 다른 관리자 화면과 같은 결로 통일.

### Added

- Hello 학습용 샘플 플러그인 초기 구현
- Hello 모듈의 `gnuboard7-hello_module.memo.created` Action 훅 구독 및 로그 기록 데모
- Filter 훅 구독 패턴(`type: 'filter'`) 시연용 `FilterMemoTitleListener`
- 플러그인 자체 훅 발행 데모: `gnuboard7-hello_plugin.log.written`
- 최소 설정 UI (`log_enabled` 토글) + 다국어(ko/en)
- 레이아웃 편집기 데이터 소스 목록에서 이 확장이 제공하는 데이터 소스가 친화 명칭으로 표시되고, 어느 확장이 제공했는지 출처가 함께 표시됩니다.
