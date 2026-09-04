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

- 설정 파일이 존재하지 않는 스타일 파일을 선언해 검색엔진용 화면이 없는 주소를 참조하던 문제를 수정했습니다.

## [0.1.0] - 2026-07-01

### Changed

- 헤더 좌우 정렬 컨테이너 외형을 sirsoft-admin_basic 표준 시맨틱(.flex-between) 과 정합 — 다른 화면과 같은 결로 통일.

### Added

- 학습용 최소 샘플 사용자 템플릿 (`gnuboard7-hello_user_template`) 신규 생성
- Basic 8개 컴포넌트만 포함 (Div, Button, H1, H2, H3, A, Span, Img)
- 홈 라우트 1개 + 에러 페이지 6종 (401/403/404/500/503/maintenance)
- `_user_base` 레이아웃: 간단한 헤더(홈 링크) + 콘텐츠 슬롯 + 푸터
- 홈 레이아웃: `gnuboard7-hello_module` 의 Memo 목록을 data_sources 로 호출하여 iteration 렌더링
- 다국어 지원 (ko/en)
- `externals` 외부 리소스 선언 예시 (Font Awesome 스타일시트)
- `__tests__/layouts/home.test.tsx` — `createLayoutTest()` + `mockApi()` 로 Memo 목록 렌더링 검증
- `__tests__/components/Div.test.tsx` — Basic 컴포넌트 단위 테스트
