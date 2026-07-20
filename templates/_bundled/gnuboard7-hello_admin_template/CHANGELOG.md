# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [0.2.0] - 2026-07-20

### Added

- 모든 레이아웃을 `.g7.html` 저작 포맷으로도 제공합니다 (대시보드 1종 + 에러 6종). HTML 에 가까운
  문법으로 화면을 작성하는 방법을 보여 주는 예시이며, 기존 JSON 레이아웃도 그대로 함께 들어 있어
  두 표기를 나란히 비교할 수 있습니다.

### Changed

- 설치 시 `.g7.html` 이 있으면 그것을 우선 사용합니다. 짝이 되는 JSON 과 결과가 완전히 같으므로
  화면 동작은 달라지지 않으며, JSON 만 쓰던 환경에서도 종전과 동일하게 설치됩니다.

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
