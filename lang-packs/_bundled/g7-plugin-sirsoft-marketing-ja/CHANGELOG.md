# Changelog

이 언어팩의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.3] - 2026-11-12

### Added

- 회원 항목으로 선언하는 동의 항목(마케팅 동의, 제3자 제공 동의, 정보 공개 동의)의 이름과 안내, 약관 「보기」 링크, 사용 여부 잠금 안내의 일본어 번역을 추가했습니다(`fields.*`) (#490)
- 내 정보 보기에서 동의한 항목 아래에 보이는 동의일 문구의 일본어 번역을 추가했습니다(`fields.consented_on`, 「:date 同意」) (#490)

### Removed

- 가입 화면, 내 정보, 관리자 회원 화면에 끼우던 마케팅 동의 조각이 회원 항목으로 바뀌면서 쓰지 않게 된 문구 19건을 정리했습니다. 동의 카드 제목, 항목별 설명과 「(선택) 동의」 문구, 동의일 표시와 미동의, 수정 안내, 약관 「보기」와 약관 창 제목, 약관 미등록 안내, 발행일, 정보 공개 경고, 편집기 데이터 소스 이름(`editor.data_source.marketing_settings`)입니다 (#490)

## [1.0.2] - 2026-10-21

### Removed

- 마케팅 설정 채널 추가·편집 창에서 더 이상 쓰지 않는 이름 칸 제목 번역 2건(`settings.channel_modal.label_ko`·`label_en`)을 정리했습니다.

## [1.0.1] - 2026-10-10

### Added

- 채널 저장이 거절될 때 어느 채널의 어느 칸인지 알려 주는 항목 이름(`attributes.channel_key`·`channel_label`·`channel_page_slug`)과 키 형식 안내(`channel_key_invalid`)를 일본어로 번역했습니다.
- 채널 추가·편집 창의 항목 키 형식·중복·항목명 누락 안내(`settings.channel_modal.key_invalid`·`key_duplicate`·`label_required`)와, 채널 사용 안 함을 되돌렸을 때의 안내(`settings.channel_deactivate_modal.restored`) 일본어 번역을 추가했습니다.
- 플러그인 설정 읽기 전용 안내의 일본어 번역을 추가했습니다.

## [1.0.0] - 2026-07-01

### Added

- 마케팅 동의 화면 프론트엔드 텍스트 일본어 번역 추가 (마케팅/제3자/정보공개 동의 항목, 광고성 이메일 수신 등) — 회원가입·마이페이지의 마케팅 동의 UI 가 일본어 로케일에서 자연스럽게 표시됩니다.

## [1.0.0-beta.1] - 2026-05-11

### Added

- 마케팅 플러그인(sirsoft-marketing)의 일본어 번들 언어팩 초기 제공
