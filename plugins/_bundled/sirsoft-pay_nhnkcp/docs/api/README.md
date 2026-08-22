# API 레퍼런스 문서 목차

> **소유**: 플러그인 `sirsoft-pay_nhnkcp` · 이 확장의 웹 라우트는 대부분 결제대행사(NHN KCP)와 브라우저가 직접 주고받는 콜백/통보 경로이며, 표준 JSON API 표면이 아니다. 아래 문서는 그중 운영·보안상 명시가 필요한 경로를 사람이 서술한 레퍼런스다.

| 문서 | 도메인 | 설명 |
| --- | --- | --- |
| [vbank.md](vbank.md) | `payment` | 가상계좌 입금통보·에스크로 공통통보 수신 경로와 발신 서버(IP) 확인 |
| [admin-orders.md](admin-orders.md) | `admin` | 관리자 주문 연동 경로 전체와 각 경로의 요구 권한 |
| [transaction-status.md](transaction-status.md) | `admin` | 관리자 주문 상세의 거래 상태·취소·환불 조회 |
