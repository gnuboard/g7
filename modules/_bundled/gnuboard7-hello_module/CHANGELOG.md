# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [0.2.0] - 2026-07-17

### Added

- 모듈이 화면 컴포넌트를 제공하는 예시로 `MemoCard` 를 추가했습니다. 레이아웃에서는 모듈
  식별자가 앞에 붙은 태그로 씁니다 — `<gnuboard7-hello-module-memo-card title="..."/>`.
- 관리자 템플릿에서만 다른 모양으로 나오는 `MemoCard` 판본을 함께 넣었습니다. 같은 태그로
  저작하면 어느 템플릿을 쓰는지에 따라 알맞은 판본이 자동으로 선택됩니다.
- 모듈이 자기 컴포넌트를 코어에 등록하는 최소 코드를 프론트엔드 진입 파일에 담았습니다.
- `MemoCard` 를 레이아웃 편집기 팔레트에 등재했습니다. 모듈이 제공하는 컴포넌트를 편집기에서
  꺼내 쓸 수 있게 하는 최소 예시입니다.
- HTML 유사 저작 포맷(`.g7.html`)으로 작성한 관리자 화면 예시(`admin_markup_demo`)를 넣었습니다.
  저작 파일과 그 컴파일 결과가 나란히 설치되는 최소 사례입니다.

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
