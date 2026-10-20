# Changelog

All notable changes to this plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.0.0] - 2026-10-19

### Added

- 소셜 로그인 플러그인 최초 릴리즈. 기본 언어는 한국어·영어이고 일본어는 번들 언어팩으로 제공한다. 코어 7.2.0 이상이 필요하다(`g7_version >=7.2.0`)
- 카카오, 네이버, 구글, 페이스북, 일반 OIDC 제공자 연동. 다른 확장은 필터 훅 `sirsoft-social_login.provider.filter_registered` 로 새 제공자를 더할 수 있다(내장 제공자 덮어쓰기는 무시)
- id_token 서명 검증(RS256 고정, JWKS `kid` 선택과 1회 재조회, 발급자·수신자·만료·nonce 검사)
- OAuth 왕복: state 1회성 보관(PKCE, nonce), 같은 출처 복귀 주소만 허용, 콜백 뒤 세션 ID 재발급. 관리자 연결 테스트는 로그인하지 않고 일회용 결과 화면(받은 항목·가입에 빠진 항목·제공자 원본 키 이름, 60초 안에 한 번)을 보여 준다
- 제공자 설정, 회원 연결, 시도 기록, 웹훅 수신 기록 테이블 4종과 내장 제공자 4행 시딩(처음에는 모두 꺼짐)
- 모의 제공자(카카오, 네이버, 구글, 페이스북, OIDC 응답 모양과 변종 9종) — `php artisan sirsoft-social_login:mock --enable --minutes=N` 으로만 켠다(최대 240분)
- 사이트 전체 정책 설정 화면과 권한 4종(설정 열람·변경, 회원 연결 열람·해제)
- 소셜 로그인과 가입: 연결된 회원은 코어 로그인 인계로 비밀번호 로그인과 같은 관문(회원 상태, 2단계 인증, 비밀번호 변경 강제)을 지나 로그인한다. 연결이 없으면 가입 화면의 모달에서 이메일 확인과 약관 동의를 받고 비밀번호 없이 가입한다. 제공자가 이메일을 주지 않으면 인증번호로 확인하거나 메일을 받지 않는 대체 주소로 가입한다(제공자별 설정)
- 같은 이메일의 기존 계정이 있으면 비밀번호 또는 그 계정 메일로 받은 인증번호로 확인한 뒤 연결한다(5번 틀리면 처음부터). 사이트 정책을 「검증된 이메일 자동 연결」로 바꾸고 제공자가 확인한 이메일을 믿도록 설정한 제공자에서 그 이메일이 확인된 경우에만 확인 없이 연결한다(기본은 본인확인 후 연결). 「연결하지 않음」 정책이면 다른 이메일로 새로 가입한다
- 로그인, 가입 화면의 제공자 버튼(카카오, 네이버, 구글, 페이스북 브랜드 색과 기호)과 마이페이지 「연결된 소셜 계정」 카드(줄마다 제공자 기호, 연결, 해제). 비밀번호도 없고 연결이 하나뿐이면 해제를 막고 비밀번호 설정을 안내한다(설정으로 허용)
- 대체 이메일로 가입해 메일도 받을 수 없고 비밀번호도 없는 회원은 연결된 제공자로 다시 로그인해 정보 수정과 비밀번호 첫 설정의 본인 재확인을 통과한다. 코어 본인인증 수단으로 붙고 확인 토큰은 주소에 싣지 않는다
- 관리자 계정의 소셜 로그인 차단 설정, 시간당 소셜 가입 급증 알림(`sirsoft-social_login.signup.surge`), 회원 삭제와 탈퇴 시 연결 정리, 회원 응답의 연결 제공자 목록(`social_providers`), 연결, 해제, 가입 활동 기록
- 확장 훅: 제공자 응답 정규화 필터 `profile.filter_normalized`, 가입 데이터 필터 `signup.filter_registration_data`, 연결 전후 `account.after_link`, `account.before_unlink`, `account.after_unlink`, 로그인과 가입 완료 `auth.after_social_login`, `auth.after_social_signup`
- 공개 API 와 마이페이지 API 13종([docs/api/social-login.md](docs/api/social-login.md)). 요청 제한은 라우트마다 따로 센다
