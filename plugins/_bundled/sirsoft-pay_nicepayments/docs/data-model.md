# 나이스페이먼츠 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_소유 모델이 없습니다._
<!-- @generated:models END -->

<!-- @intent START -->
결제 상태는 이 플러그인이 아니라 `sirsoft-ecommerce`의 `Order`/`OrderPayment` 모델이
소유합니다(§AGENTS.md "설계 원칙"). 이 플러그인은 그 모델을 직접 참조해 읽고 쓸 뿐, 자기
Repository 조차 두지 않았습니다(§Repository) — 인증→승인 2단계 흐름의 중간 상태도 별도
테이블에 저장하지 않고, 인증 결과를 받은 요청 컨텍스트 안에서만 다루다가 승인이 확정되는
순간 이커머스 테이블에 반영합니다.
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
이커머스가 이미 노출한 Eloquent 모델을 직접 참조합니다 — 자기 소유 테이블이 없는 상태에서
남의 모델을 감싸는 Repository 를 새로 만드는 것은 위임만 하는 빈 계층입니다.
<!-- @intent END -->
