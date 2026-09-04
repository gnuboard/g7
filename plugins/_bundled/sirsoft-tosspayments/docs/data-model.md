# 토스페이먼츠 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_소유 모델이 없습니다._
<!-- @generated:models END -->

<!-- @intent START -->
결제 상태는 이 플러그인이 아니라 `sirsoft-ecommerce`의 `Order`/`OrderPayment` 모델이
소유합니다(§AGENTS.md "설계 원칙"). 가상계좌 웹훅 검증에 쓰는 secret 조차 별도 테이블이
아니라 `OrderPayment.payment_meta`(JSON 컬럼)의 `toss_secret` 키에 저장됩니다 — 이 값은
그 주문 하나에만 의미가 있어 독립 테이블을 둘 이유가 없습니다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_소유 테이블이 없습니다._
<!-- @generated:tables END -->

<!-- @intent START -->
가상계좌 발급 정보와 토스 거래키(`paymentKey`)는 이커머스 `OrderPayment` 테이블의 기존
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
`use_escrow`의 3-상태(`off`/`on`/`buyer_choice`)는 Enum이 아니라
`ValidateTossSettingsListener::USE_ESCROW_VALUES` 상수 배열로 검증합니다 — 설정값 하나에만
쓰이는 닫힌 어휘라 Enum 승격의 이득(여러 곳에서 타입으로 재사용)이 없습니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_Repository 가 없습니다._
<!-- @generated:repositories END -->

<!-- @intent START -->
이 플러그인이 이커머스 `Order`/`OrderPayment`를 읽고 쓰는 지점(컨트롤러·리스너)은 모두
이커머스가 이미 노출한 Eloquent 모델을 직접 참조합니다 — 자기 소유 테이블이 없는 상태에서
남의 모델을 감싸는 Repository 를 새로 만드는 것은 위임만 하는 빈 계층입니다.
<!-- @intent END -->
