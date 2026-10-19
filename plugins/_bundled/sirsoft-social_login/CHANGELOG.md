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
