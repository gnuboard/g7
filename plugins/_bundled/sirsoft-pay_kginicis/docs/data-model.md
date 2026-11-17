# KG 이니시스 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_소유 모델이 없습니다._
<!-- @generated:models END -->

<!-- @intent START -->
결제 상태(주문·결제 성공/실패/취소/금액)는 전부 `sirsoft-ecommerce`의 `Order`/결제 모델에
있습니다. 이 플러그인이 자체 모델을 두지 않는 것은 실수나 미완성이 아니라 설계입니다(§AGENTS.md
"이 확장은 무엇인가") — PG 마다 결제 기록 테이블을 따로 두면 "이 주문이 지금 실제로 어떤
상태인가"를 물을 때 여러 테이블을 조인해야 하고, PG 를 교체하면 과거 주문의 결제 이력을
조회할 방법이 갈라집니다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_소유 테이블이 없습니다._
<!-- @generated:tables END -->

<!-- @intent START -->
KG 이니시스 고유 정보(MID·서명키 등)는 코어 `PluginSettingsService`(설정 스키마)에, 가상계좌
계좌정보·CBT 승인 정보 같은 거래별 데이터는 이커머스 주문/결제 레코드의 JSON 컬럼 또는
연관 필드에 함께 저장됩니다 — 이 플러그인이 그 값을 "소유"하지 않고 이커머스 테이블에
"기록"만 남기는 형태입니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_마이그레이션이 없습니다._
<!-- @generated:migrations END -->

<!-- @intent START -->
소유 테이블이 없으므로 마이그레이션도 없습니다. 이 플러그인이 설정 스키마를 바꿀 때는
마이그레이션이 아니라 `config/settings/defaults.json`(§settings.md)과 필요 시 업그레이드
스텝(과거 설정값 정정용)을 씁니다.
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_Enum 이 없습니다._
<!-- @generated:enums END -->

<!-- @intent START -->
결제수단·상태 분류(카드/계좌이체/가상계좌/휴대폰 등)는 이 플러그인이 아니라 이커머스가 소유한
결제수단 Enum 을 그대로 따릅니다 — PG 마다 결제수단 이름을 다시 정의하면 이커머스가 PG 를
교체 가능한 형태로 다룰 수 없습니다. KG 이니시스 API 고유의 코드값(예: `acceptmethod` 문자열
조합)은 Enum 이 아니라 KG 이니시스 API 스펙에 맞춘 문자열 상수로만 존재합니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 클래스 | 종류 | 설명 |
|---|---|---|
| `CbtReconciliationRepository` | 구현 | CBT 대사 레코드 저장소 — 주문 meta 의 이 결제사 키(`kginicis_cbt_reconciliation`)만 읽고 쓴다. |
| `CbtReconciliationRepositoryInterface` | 인터페이스 | - |
| `EscrowDeliveryRepository` | 구현 | 에스크로 배송 등록 기록 저장소 — 결제 meta `escrow_delivery` 의 수령인 칸을 지운다. |
| `EscrowDeliveryRepositoryInterface` | 인터페이스 | - |
<!-- @generated:repositories END -->

<!-- @intent START -->
두 Repository 모두 자체 테이블이 없고 `sirsoft-ecommerce` 의 공개 결제 계약
(`Contracts\Payment\PaymentGatewayBridge`)을 감쌉니다 — `CbtReconciliationRepository` 는 주문 meta 의 자기 키
(`kginicis_cbt_reconciliation`)를 `getOrderMeta`·`mutateOrderMetaWithLock` 으로, `EscrowDeliveryRepository` 는 결제 meta
`escrow_delivery` 의 수령인 칸을 `scrubPaymentMetaKeys` 로 다룹니다(#916). 이커머스 Model 을 직접 조회하던 종전
`CbtCvsOperationsRepository` 는 없앴고, `CbtCvsOperationsService` 가 같은 계약을 바로 씁니다. 소유 데이터가 없는데도
이 둘을 남긴 이유는 Service 가 쓰는 의미 단위(대사 기록 읽기·덮어쓰기·잠금 변경, 수령인 파기)를 한 곳에 모으기
위해서입니다 — 일반적인 "내 테이블 CRUD" Repository 와는 쓰임이 다릅니다.
<!-- @intent END -->
