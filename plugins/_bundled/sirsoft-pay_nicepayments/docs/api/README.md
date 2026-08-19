# API 레퍼런스 문서 목차

> **소유**: 플러그인 `sirsoft-pay_nicepayments` · 이 확장의 웹 라우트는 대부분 결제대행사(나이스페이먼츠)와 브라우저가 직접 주고받는 콜백/통보 경로이며, 표준 JSON API 표면이 아니다. 아래 문서는 그중 운영·보안상 명시가 필요한 경로를 사람이 서술한 레퍼런스다.

| 문서 | 도메인 | 설명 |
| --- | --- | --- |
| [vbank.md](vbank.md) | `payment` | 가상계좌 입금통보 수신 경로와 발신 서버(IP) 확인 + 입금 완료 건 관리자 환불 (JSON API) |
| [escrow.md](escrow.md) | `order` | 관리자 에스크로 결제 조회·배송 등록 (JSON API) |
| [transaction.md](transaction.md) | `order` | 관리자 거래 조회 — TID 직접 / 주문번호 자동 매핑 (JSON API) |
| [payment.md](payment.md) | `payment` | 결제창 서명값(SignData) 발급 경로 (web, 결제창 SDK 계약) |
| [orders.md](orders.md) | `order` | 주문 보조 조회 — 입금통보 이력·테스트모드/간편결제 맵·사용자 영수증 (JSON API) |
| [settings.md](settings.md) | `setting` | 관리자 설정 상태 조회 — 테스트 모드·입금통보 URL (JSON API) |
