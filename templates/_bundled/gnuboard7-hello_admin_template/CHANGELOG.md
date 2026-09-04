# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [0.1.1] - 2026-09-04

### Added

- 아이콘(Font Awesome)을 템플릿에 함께 담아 외부 CDN 없이 동작합니다.
- 개발자와 AI 에이전트를 위한 문서를 추가했습니다. 확장 폴더의 `AGENTS.md`(설계 의도·확장점·수정 시 확인할 것)와 `README.md`(도입·운영 안내), `docs/`(상세 문서)로 구성됩니다.
- 확장 문서에 「레이아웃 편집기 스펙」 항목을 추가했습니다. 이 확장이 레이아웃 편집기에 무엇을 선언했는지와, 화면 요소나 데이터를 추가할 때 편집기 쪽에서 함께 해야 할 일을 담습니다.
- 문서의 제품 표기를 「그누보드7」로 통일했습니다.

### Fixed

- 설정 파일의 스크립트 경로가 실제 빌드 산출물과 달랐던 것을 정정했습니다.

### Changed

- 확장 문서(README · AGENTS.md · docs/README.md)의 제목에 「그누보드7」과 확장 유형을 함께 표기했습니다. 제목만 보고도 그누보드7의 어떤 종류 확장인지 알 수 있습니다.

## [0.1.0] - 2026-07-01

### Changed

- 사이드바 메뉴 링크 텍스트 톤을 sirsoft-admin_basic 표준 시맨틱(.text-body) 과 정합 — 다른 관리자 화면과 같은 결로 통일.

### Added

- 학습용 최소 Admin 템플릿 스켈레톤 초기 릴리즈
- Basic 8종 컴포넌트 (Div, Button, H1, H2, H3, A, Span, Img)
- `_admin_base` 베이스 레이아웃 (사이드바 + 헤더 + 콘텐츠 슬롯)
- `admin_dashboard` 레이아웃 (Hello G7 환영 카드)
- 에러 레이아웃 6종 (401/403/404/500/503/maintenance)
- 한국어/영어 다국어 지원
- `externals` 외부 리소스 선언 예시 (Font Awesome 스타일시트)
