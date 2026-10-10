# Changelog

이 언어팩의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.2] - 2026-10-10

### Added

- 환경설정 저장 오류 안내의 항목 이름(표시 방식 `settings.display_mode_attribute`, 팝업 너비 `settings.popup_width_attribute`, 팝업 높이 `settings.popup_height_attribute`, 테마 색상 `settings.theme_color_attribute`)을 일본어로 번역했습니다. 팝업 크기를 범위 밖으로 저장하면 일본어 화면에서도 일본어 항목 이름으로 안내합니다.
- 설정 수정 권한이 없을 때 플러그인 설정 화면에 뜨는 읽기 전용 안내(`plugin_read_only`)의 일본어 번역을 추가했습니다.

### Changed

- 환경설정 팝업 너비와 높이 안내(`settings.fields.popup_width.hint`, `popup_height.hint`)에 원문과 같이 입력 범위 (100~2000)을 넣었습니다.

## [1.0.1] - 2026-09-06

### Added

- 주소 검색을 불러오지 못했을 때 표시되는 안내 문구의 일본어 번역을 추가했습니다 — 주소를 직접 입력하라는 안내가 일본어 로케일에서 표시됩니다.

## [1.0.0] - 2026-06-11

### Added

- 다음 우편번호 플러그인(sirsoft-daum_postcode)의 일본어 번들 언어팩 초기 제공
