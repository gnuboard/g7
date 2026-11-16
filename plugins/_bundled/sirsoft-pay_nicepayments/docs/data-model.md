# 나이스페이먼츠 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_소유 모델이 없습니다._
<!-- @generated:models END -->

<!-- @intent START -->
결제 상태는 이 플러그인이 아니라 `sirsoft-ecommerce`의 `Order`/`OrderPayment` 모델이
소유합니다(§AGENTS.md "설계 원칙"). 이 플러그인은 이커머스 공개 결제 계약(`PaymentGatewayBridge`)으로
읽고 쓸 뿐, 자기 Repository 조차 두지 않았습니다(§Repository) — 인증→승인 2단계 흐름의 중간 상태도 별도
테이블에 저장하지 않고, 인증 결과를 받은 요청 컨텍스트 안에서만 다루다가 승인이 확정되는
순간 계약으로 이커머스 테이블에 반영합니다. 이커머스 Model 은 훅이 넘긴 인자로만 받고 속성만 읽습니다
(이커머스 manifest `public_api.readonly_models`).

이 플러그인이 결제 행 meta 에 쓰는 키와 쓰는 경로(모두 계약 경유):

| 키 | 쓰는 곳 | 계약 메서드 |
|---|---|---|
| `failure_code`·`failure_message`·`failed_at`·`failure_source` | 결제창 닫힘 보고 | `markPaymentWindowClosed`(`PaymentFailure`) |
| `vbank_tid`·`vbank_num`·`vbank_exp_date`·`pg_raw_response` 등 발급 정보 | 가상계좌 발급 | `onVbankAccountIssued`(meta 교체) |
| `vbank_notifications`·`vbank_notification_summary` | 입금통보 | `mutatePaymentMetaWithLock`, 입금 완료는 `completePayment`(잠금 안 덧붙임) |
| `vbank_refund_status`·`vbank_refund_*` | 관리자 가상계좌 환불 | `mutatePaymentMetaWithLock`(선점), `mergePaymentMeta`(완료·실패) |
| 주문 meta `nicepayments_retry_failures`·`nicepayments_retry_count`·`nicepayments_retry_started_at`, 결제 meta `retry_count`·`retry_started_at` | 재결제 복구(SignData) | `restoreForRetry`(판정은 이 플러그인, 기록은 이커머스) |
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_소유 테이블이 없습니다._
<!-- @generated:tables END -->

<!-- @intent START -->
가상계좌 발급 정보와 나이스페이먼츠 거래번호(TID)는 이커머스 `OrderPayment` 테이블의 기존
컬럼/메타에 저장됩니다 — PG 마다 별도 결제상세 테이블을 두면 관리자 주문 상세가 PG
종류에 따라 다른 테이블을 조인해야 합니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_마이그레이션이 없습니다._
<!-- @generated:migrations END -->

<!-- @intent START -->
소유 테이블이 없으므로(§소유 테이블) 스키마 변경 자체가 발생하지 않습니다. 설정 스키마
변경(§settings.md)은 `config/settings/defaults.json` 갱신만으로 끝나며 DB 마이그레이션
대상이 아닙니다.
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_Enum 이 없습니다._
<!-- @generated:enums END -->

<!-- @intent START -->
`AuthResultCode`(`'0000'` = 성공)와 PG 응답 코드는 Enum 대신 컨트롤러의 조건 분기로 직접
판정합니다 — 나이스페이먼츠 고유 프로토콜 상수라 이 플러그인 코드 어디에도 재사용되지
않으며, Enum 으로 승격해도 얻는 타입 안전성 대비 간접 계층만 늘어납니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_Repository 가 없습니다._
<!-- @generated:repositories END -->

<!-- @intent START -->
이 플러그인이 이커머스 `Order`/`OrderPayment`를 읽고 쓰는 지점(컨트롤러·리스너)은 모두
이커머스 공개 결제 계약을 씁니다 — 계약이 이커머스 쪽 저장소와 잠금 순서(주문 → 결제 행)를 맡으므로
자기 소유 테이블이 없는 이 플러그인이 Repository 를 따로 둘 이유가 없습니다. 이커머스 Model 정적 조회,
`DB::table`, 테이블 이름 리터럴은 쓰지 않습니다 — 이커머스 내부 구현은 예고 없이 바뀔 수 있고, 공개 계약만 하위 호환을 약속합니다.
<!-- @intent END -->
