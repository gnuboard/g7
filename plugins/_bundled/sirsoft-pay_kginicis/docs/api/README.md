# API 레퍼런스 문서 목차

> **소유**: 플러그인 `sirsoft-pay_kginicis` · **생성**: `php artisan api:docgen` (실측 기반).
> 아래 표는 자동 생성됩니다. 각 문서를 열면 엔드포인트별 파라미터·응답·예시를 볼 수 있습니다.

<!-- @generated:start:api-readme-index -->
- **문서 수**: 6 · **엔드포인트 수**: 37

| 문서 | 도메인 | 엔드포인트 |
| --- | --- | --- |
| [cbt.md](cbt.md) | `cbt` | 2 |
| [orders.md](orders.md) | `orders` | 12 |
| [payment.md](payment.md) | `payment` | 20 |
| [settings.md](settings.md) | `settings` | 1 |
| [transaction.md](transaction.md) | `transaction` | 1 |
| [vbank.md](vbank.md) | `vbank` | 1 |

<!-- @generated:end -->

## 관리자 경로의 주문 권한 범위

관리자 주문 연동 경로는 이커머스 주문 권한(`sirsoft-ecommerce.orders.read`·`orders.update`·`claims.execute-refund`)을
요구하고, 그 권한을 「본인만」·「소유역할」 범위로 받은 관리자에게는 범위 안 주문만 허용한다.

| 경로 | 판정 대상 | 범위 밖일 때 |
| --- | --- | --- |
| `orders/{orderNumber}/*` (거래 상태·해외 결제 정산·환불 재시도·편의점 결제·에스크로 배송·구매거절 확인) | 주문번호의 주문 | 403 `auth.scope_denied` — PG 를 부르지 않는다 |
| `transaction/query` | 거래번호(`tid`)의 주문. 범위가 좁혀진 관리자에게는 이 사이트 주문에 없는 거래번호도 거부 | 403 `auth.scope_denied` |
| `orders/test-mode-map` | 주문번호마다 | 범위 밖 주문번호를 맵에서 뺀다 |

주문이 없는 주문번호는 범위로 판정하지 않고 각 경로의 기존 응답(빈 결과·404·422)을 따른다.

