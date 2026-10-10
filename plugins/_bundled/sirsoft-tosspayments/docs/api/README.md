# API 레퍼런스 문서 목차

> **소유**: 플러그인 `sirsoft-tosspayments` · **생성**: `php artisan api:docgen` (실측 기반).
> 아래 표는 자동 생성됩니다. 각 문서를 열면 엔드포인트별 파라미터·응답·예시를 볼 수 있습니다.

<!-- @generated:start:api-readme-index -->
- **문서 수**: 2 · **엔드포인트 수**: 4

| 문서 | 도메인 | 엔드포인트 |
| --- | --- | --- |
| [payment.md](payment.md) | `payment` | 2 |
| [webhook.md](webhook.md) | `webhook` | 2 |

<!-- @generated:end -->

## 클레임 추가결제 (취소/반품/교환)

쇼핑몰 모듈의 클레임 추가결제(결제번호 `CP` 채번)는 별도 엔드포인트 없이 기존 결제 콜백이
함께 받습니다 — `orderId` 자리에 `CP` 결제번호가 실려 오면 주문이 아니라 클레임 결제 건으로
처리되고, 완료 후 주문 완료 화면이 아니라 신청서 상세(`?claim_payment=success|failed|cancelled`)로
리다이렉트합니다. 상태변경 웹훅도 같은 판별로 분기해 클레임 결제 건은 주문 조회 없이 종료합니다.
승인·환급 계약(인자·반환 키·`paid_amount` 최소 화폐단위·멱등 규칙)의 단일 출처는 쇼핑몰 모듈
`modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md` 의 "결제 플러그인 연동 계약 (필터 훅)" 절입니다 — 이 문서에는
계약을 복제해 적지 않습니다.
