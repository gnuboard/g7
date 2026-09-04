# NHN KCP — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_소유 모델이 없습니다._
<!-- @generated:models END -->

<!-- @intent START -->
결제 상태는 이 플러그인이 아니라 `sirsoft-ecommerce`의 `Order`/`OrderPayment` 모델이
소유합니다(§AGENTS.md "설계 원칙"). 이 플러그인은 그 모델을 직접 참조해 읽고 쓸 뿐, 자기
Repository 조차 두지 않았습니다(§Repository) — `sirsoft-pay_kginicis`가 CBT(일본) 정산용
Repository 2개를 갖는 것과 달리, 이 플러그인은 일본/CBT 결제를 아예 구현하지 않으므로
그 계층이 존재할 이유가 없습니다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_소유 테이블이 없습니다._
<!-- @generated:tables END -->

<!-- @intent START -->
가상계좌 발급 정보(은행·계좌번호·예금주·만료일)와 KCP 거래 정보(`tno` 등)는 이커머스
`OrderPayment` 테이블의 기존 컬럼/메타에 저장됩니다 — PG 마다 별도 결제상세 테이블을 두면
관리자 주문 상세가 PG 종류에 따라 다른 테이블을 조인해야 해 화면 로직이 PG 개수만큼
분기합니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_마이그레이션이 없습니다._
<!-- @generated:migrations END -->

<!-- @intent START -->
소유 테이블이 없으므로(§소유 테이블) 스키마 변경 자체가 발생하지 않습니다. 이 플러그인의
설정 스키마 변경(§settings.md)은 `config/settings/defaults.json` 갱신만으로 끝나며 DB
마이그레이션 대상이 아닙니다.
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_Enum 이 없습니다._
<!-- @generated:enums END -->

<!-- @intent START -->
KCP 공통통보의 `tx_cd`/`cl_status` 값은 Enum 대신 `EscrowCommonNotifyController`(§extension-points.md
"핵심 흐름"의 매핑표)의 조건 분기로 직접 처리합니다 — 이 값들은 이 플러그인 코드 어디에도
재사용되지 않는 KCP 고유 프로토콜 상수라, Enum 으로 승격해도 얻는 타입 안전성 대비 간접
계층만 늘어납니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_Repository 가 없습니다._
<!-- @generated:repositories END -->

<!-- @intent START -->
이 플러그인이 이커머스 `Order`/`OrderPayment`를 읽고 쓰는 지점(컨트롤러·리스너·`Concerns`
트레이트)은 모두 이커머스가 이미 노출한 Eloquent 모델을 직접 참조합니다 — 자기 소유
테이블이 없는 상태에서 남의 모델을 감싸는 Repository 를 새로 만드는 것은 위임만 하는
빈 계층입니다. `sirsoft-pay_kginicis`의 CBT Repository 2개는 이 플러그인에는 없는
일본 결제 전용 정산 데이터(자체 소유 테이블)를 다루기 위한 것이라 대칭이 아닙니다.
<!-- @intent END -->
