# API 레퍼런스 문서 목차

> **소유**: 플러그인 `sirsoft-pay_nhnkcp` · 이 확장의 웹 라우트는 대부분 결제대행사(NHN KCP)와 브라우저가 직접 주고받는 콜백/통보 경로이며, 표준 JSON API 표면이 아니다. 아래 문서는 그중 운영·보안상 명시가 필요한 경로를 사람이 서술한 레퍼런스다.

| 문서 | 도메인 | 설명 |
| --- | --- | --- |
| [payment.md](payment.md) | `payment` | 브라우저 리턴 콜백·결제창 닫힘 보고·결제 재시도. 주문 상태를 바꾸는 경로가 하나뿐인 이유 |
| [vbank.md](vbank.md) | `payment` | 가상계좌 입금통보·에스크로 공통통보 수신 경로와 발신 서버(IP) 확인 |
| [admin-orders.md](admin-orders.md) | `admin` | 관리자 주문 연동 경로 전체와 각 경로의 요구 권한 |
| [user-orders.md](user-orders.md) | `user` | 사용자 영수증 URL 조회·테스트 모드 가상계좌 모의입금 정보 (JSON, 소유자·게스트 토큰·영수증 쿠키 접근 제어) |
| [transaction-status.md](transaction-status.md) | `admin` | 관리자 주문 상세의 거래 상태·취소·환불 조회 |

## 클레임 추가결제 (취소/반품/교환)

쇼핑몰 모듈의 클레임 추가결제(결제번호 `CP` 채번)는 별도 엔드포인트 없이 기존 결제 콜백이
함께 받습니다 — 주문번호 자리에 `CP` 결제번호가 실려 오면 주문이 아니라 클레임 결제 건으로
처리되고, 완료 후 신청서 상세로 리다이렉트합니다. 승인·환급 계약의 단일 출처는 쇼핑몰 모듈
`modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md` 의 "결제 플러그인 연동 계약 (필터 훅)" 절입니다.
