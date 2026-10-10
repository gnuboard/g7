# Changelog

이 언어팩의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.0] - 2026-10-10

### Added

- 수출입은행 환율 플러그인 일본어 언어팩 최초 릴리즈. 코어 7.1.0 이상이 필요하며, 환경설정(인증키, 갱신 정책), 갱신 이력, 갱신 실패 알림, 권한과 역할 이름을 일본어로 제공합니다.
- 환율 갱신 실패 알림 템플릿 편집 창 「사용 가능한 변수」 설명(`messages.notification_variables.*`)의 일본어 번역을 제공합니다.
- 발송 수단 복수 지정 화면의 일본어 번역을 제공합니다.
- 환율 이력 조회에서 종료일이 시작일보다 앞설 때의 안내(`date_range_invalid`) 일본어 번역을 제공합니다.
- 이력 조회 검증 오류 문구에 쓰는 칸 이름(`validation.attributes`) 일본어 번역을 제공합니다.
- 플러그인 설정·알림 설정을 볼 권한이 없을 때와 플러그인 설정을 수정할 권한이 없을 때(`plugin_read_only`)의 안내 일본어 번역을 추가했습니다.

