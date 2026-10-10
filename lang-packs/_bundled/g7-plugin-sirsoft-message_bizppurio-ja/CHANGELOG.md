# Changelog

이 언어팩의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.1] - 2026-10-10

### Added

- 알림 템플릿 편집 창 「사용 가능한 변수」 설명(`messages.notification_variables.*`)의 일본어 번역을 추가했습니다.
- 알림톡 템플릿 삭제 완료 안내를 사유별로 나눈 문구(카카오에 등록되지 않은 템플릿 `manage.delete.done_not_registered`, 카카오측 삭제 실패 `manage.delete.done_kakao_failed`)를 일본어로 번역했습니다.
- 레이아웃 편집기에 쓰이는 알림톡 템플릿·SMS 본문·반려 사유 창 이름(`template.form.editor_label`·`template.sms.editor_label`·`template.rejection.editor_label`)의 일본어 번역을 추가했습니다.
- 알림톡 템플릿 작성 창의 버튼·바로연결 「링크 유형」 선택칸 이름(몇 번째 항목인지 함께 읽히는 화면 낭독기용 이름)의 일본어 번역을 추가했습니다.
- 관리 응답 안내 문장(알림톡 템플릿 조회 `template.loaded`·없는 템플릿 `template.not_found`·분류 `template.categories_loaded`·발신 프로필 `template.profiles_loaded`·발송 결과 `dispatch_result.loaded`)을 일본어로 번역했습니다.
- 환경설정 저장 오류 안내의 항목 이름(검수 모드 `settings.is_test_mode_attribute`·API 키 `settings.api_key_attribute`·알림톡 발신프로필 키 `settings.sender_key_attribute`)을 일본어로 번역했습니다.
- 플러그인 설정 읽기 전용 안내와 알림톡 템플릿 관리 권한 없음 안내의 일본어 번역을 추가했습니다.

### Changed

- 잔액 부족 알림 메일 본문의 일본어 번역을 갱신했습니다. 한국어 원본에 설정 화면 주소 안내가 추가되어, 일본어 메일에도 충전·설정하러 갈 곳이 함께 적힙니다.
- 알림톡 이미지 등록 안내의 용량·가로 크기가 일본어 화면에서 숫자로 박혀 있던 것을 고쳤습니다. 한국어 원문이 그 숫자를 서버 제약에서 받아 오도록 바뀌면서 일본어 번역도 같은 형식으로 맞췄습니다.

### Fixed

- 알림 템플릿 검수 요청 화면의 변수 예시 안내에 적힌 주문번호 예시를 실제 채번 형식(날짜 뒤 10자리)에 맞춰 고쳤습니다.

## [1.0.0] - 2026-08-24

### Added

- 비즈뿌리오 메시지 발송 플러그인(sirsoft-message_bizppurio)의 일본어 번들 언어팩 초기 제공
- 관리자 환경설정 문구(비즈뿌리오 아이디·비밀번호·발신번호·알림톡 발신프로필키·결과 수신 주소, 검수 모드와 운영 전환 경고)의 일본어 번역
- 알림 편집 창에 통합된 알림톡 템플릿·문자(SMS) 섹션의 제목·안내 문구와 승인 여부 배지(승인됨 / 미승인)의 일본어 번역
- 검수자 전달 의견 입력란의 라벨·안내 문구의 일본어 번역
- 알림톡 템플릿 작성·검수 신청·승인 관리 화면 문구의 일본어 번역
- 템플릿 통합 관리 화면과 발송 이력 화면 문구의 일본어 번역
- 발송 결과 안내 문구의 일본어 번역
- 템플릿 작성 시 입력값 오류 안내의 일본어 번역 — 항목 이름까지 일본어로 표시됩니다.
