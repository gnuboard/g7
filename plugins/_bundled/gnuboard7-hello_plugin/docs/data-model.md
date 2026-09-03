# Hello 플러그인 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_소유 모델이 없습니다._
<!-- @generated:models END -->

<!-- @intent START -->
없습니다. 이 샘플은 자기 데이터를 갖지 않습니다.

플러그인이 모델과 테이블을 가질 수는 있고 실제로 그런 플러그인이 많습니다(결제 이력·동의 기록·
메시지 발송 기록 등). 다만 이 샘플의 목적은 **훅 구독**을 보이는 것이라, 데이터 계층을 두면
읽어야 할 코드만 늘어납니다.

모델·Repository·마이그레이션이 있는 플러그인 예시가 필요하면 실제 도메인 플러그인의 문서를
참고합니다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_소유 테이블이 없습니다._
<!-- @generated:tables END -->

<!-- @intent START -->
없습니다. 저장하는 데이터가 없습니다.

플러그인이 테이블을 가질 때는 **확장 식별자를 접두사로** 붙입니다 — 확장은 같은 데이터베이스를
공유하므로 짧은 이름을 쓰면 다른 확장과 충돌합니다. 그리고 플러그인 제거 시 정리 대상임을
`getDynamicTables()` 로 코어에 알립니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_마이그레이션이 없습니다._
<!-- @generated:migrations END -->

<!-- @intent START -->
없습니다. 스키마가 없으므로 마이그레이션도 없습니다.

플러그인이 마이그레이션을 가질 때의 규약은 모듈과 같습니다 — 한국어 `comment` 와 `down()`
필수, 초기 `create_*` 파일을 나중에 고치지 않기, 기존 행을 손봐야 하는 변경에는 `upgrades/`
업그레이드 스텝 백필 동반.
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_Enum 이 없습니다._
<!-- @generated:enums END -->

<!-- @intent START -->
없습니다. 이 샘플에는 상태도 분류도 없습니다.

실제 확장에서 상태·타입·분류를 다룰 때는 문자열 리터럴이 아니라 Enum 을 단일 출처로 둡니다 —
화면 필터 옵션·검증 게이트·실제 기록 값 셋이 같은 Enum 에서 파생되지 않으면, 빠진 값으로
기록된 행이 어떤 필터로도 도달할 수 없게 됩니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_Repository 가 없습니다._
<!-- @generated:repositories END -->

<!-- @intent START -->
없습니다. 데이터 접근 자체가 없습니다.

리스너에서 데이터에 접근해야 한다면 `Model::query()` · `DB::table()` · `$row->save()` 를 직접
부르지 않고 **Repository 인터페이스를 주입**받습니다. 리스너가 데이터 접근 규약의 예외가 되면
그 예외가 다른 리스너로 번집니다.
<!-- @intent END -->
