# Changelog

이 언어팩의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.1] - 2026-08-19

### Added

- 일괄 삭제 부분 실패 안내(`messages.uploads.bulk_partially_deleted`)와 참조 판정 불완전 시 정리 건너뜀 안내(`messages.cleanup.sources_incomplete`), 이미지 삭제 실패 토스트(`admin.uploads.delete.failed`)의 일본어 번역을 추가했습니다.
- 에디터 설정의 "공개 자산 디스크" 설정 문구 일본어 번역을 추가했습니다. (#100 @lyg-kaban 님께서 건의해주셨습니다.)
- 「에디터 업로드 이미지」 관리 화면의 문구 일본어 번역을 추가했습니다 (`admin.uploads.*`) — 목록 제목·열 이름·참조 상태 배지·필터·삭제 확인 창과 판정 범위 안내가 일본어 로케일에서 표시됩니다.
- 미사용 이미지 자동 정리 설정의 라벨·도움말 일본어 번역을 추가했습니다 (`settings.section_cleanup`, `settings.cleanup.*`). (#115 @Tuwasduliebst 님께서 건의해주셨습니다.)
- 업로드 이미지 삭제 시의 안내·오류 메시지 일본어 번역을 추가했습니다 (`messages.uploads.*`, `messages.cleanup.*`).
- 업로드 이미지 조회·삭제 권한의 이름과 설명 일본어 번역을 추가했습니다 — 역할 권한 화면에서 일본어 로케일로 표시됩니다.

## [1.0.0] - 2026-07-01

### Added

- 에디터 화면 프론트엔드 텍스트 일본어 번역 추가 — 에디터 UI 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.

## [1.0.0-beta.1] - 2026-05-11

### Added

- CKEditor5 플러그인(sirsoft-ckeditor5)의 일본어 번들 언어팩 초기 제공
