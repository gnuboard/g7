# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [0.1.0-beta.1] - 2026-05-11

### Added

- 학습용 최소 샘플 사용자 템플릿 (`gnuboard7-hello_user_template`) 신규 생성
- Basic 8개 컴포넌트만 포함 (Div, Button, H1, H2, H3, A, Span, Img)
- 홈 라우트 1개 + 에러 페이지 6종 (401/403/404/500/503/maintenance)
- `_user_base` 레이아웃: 간단한 헤더(홈 링크) + 콘텐츠 슬롯 + 푸터
- 홈 레이아웃: `gnuboard7-hello_module` 의 Memo 목록을 data_sources 로 호출하여 iteration 렌더링
- 다국어 지원 (ko/en)
- `__tests__/layouts/home.test.tsx` — `createLayoutTest()` + `mockApi()` 로 Memo 목록 렌더링 검증
- `__tests__/components/Div.test.tsx` — Basic 컴포넌트 단위 테스트
