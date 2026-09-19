# API 레퍼런스 문서 목차

> **소유**: 플러그인 `sirsoft-pay_nicepayments` · 이 확장의 웹 라우트는 대부분 결제대행사(나이스페이먼츠)와 브라우저가 직접 주고받는 콜백/통보 경로이며, 표준 JSON API 표면이 아니다. 아래 문서는 그중 운영·보안상 명시가 필요한 경로를 사람이 서술한 레퍼런스다.

| 문서 | 도메인 | 설명 |
| --- | --- | --- |
| [vbank.md](vbank.md) | `payment` | 가상계좌 입금통보 수신 경로와 발신 서버(IP) 확인 + 입금 완료 건 관리자 환불 (JSON API) |
| [escrow.md](escrow.md) | `order` | 관리자 에스크로 결제 조회·배송 등록 (JSON API) |
| [transaction.md](transaction.md) | `order` | 관리자 거래 조회 — TID 직접 / 주문번호 자동 매핑 (JSON API) |
| [payment.md](payment.md) | `payment` | 결제창 서명값(SignData) 발급(web) · 결제창 닫힘 보고 close-report(api) · 브라우저 리턴 콜백(web) — 주문 상태를 바꾸는 경로 규약 |
| [orders.md](orders.md) | `order` | 주문 보조 조회 — 입금통보 이력·테스트모드/간편결제 맵·사용자 영수증 (JSON API) |
| [settings.md](settings.md) | `setting` | 관리자 설정 상태 조회 — 테스트 모드·입금통보 URL (JSON API) |

## 클레임 추가결제 (취소/반품/교환)

쇼핑몰 모듈의 클레임 추가결제(결제번호 `CP` 채번)는 별도 엔드포인트 없이 기존 결제 콜백이
함께 받습니다 — 주문번호 자리에 `CP` 결제번호가 실려 오면 주문이 아니라 클레임 결제 건으로
처리되고, 완료 후 신청서 상세로 리다이렉트합니다. 승인·환급 계약의 단일 출처는 쇼핑몰 모듈
`modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md` 의 "결제 플러그인 연동 계약 (필터 훅)" 절입니다.
