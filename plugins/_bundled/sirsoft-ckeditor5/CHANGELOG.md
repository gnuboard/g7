# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.0-beta.3] - 2026-05-11

### Added

- 이미지 업로드 전후에 외부 확장이 본인인증·정책 가드를 붙일 수 있는 확장점 제공

## [1.0.0-beta.2] - 2026-04-22

### Fixed

- CKEditor5 사용 게시판 글쓰기 화면에서 제목과 내용을 함께 입력해 저장할 때 "제목이 비어있다" 오류가 나던 문제 수정 (#17 @laelbe 님께서 제보해주셨습니다.)

## [1.0.0-beta.1] - 2026-04-09

### Added

- CKEditor 5 WYSIWYG 에디터 플러그인 최초 릴리즈
- extension_point를 통한 HtmlEditor/HtmlContent 자동 교체 (비활성화 시 폴백)
- 이미지 업로드/서빙 API
- 다국어 탭, 소스 편집, 다크모드 지원
- 플러그인 설정 UI (툴바, 에디터 높이, 이미지 업로드)