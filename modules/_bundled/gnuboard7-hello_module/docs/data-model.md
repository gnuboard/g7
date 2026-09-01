# Hello 모듈 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 모델 | 테이블 | fillable | 관계 | 특성 |
|---|---|---|---|---|
| `Memo` | `gnuboard7_hello_module_memos` | 3 | - | - |
<!-- @generated:models END -->

<!-- @intent START -->
`Memo` 하나이며 fillable 이 셋뿐입니다. 관계도 특성(SoftDeletes·검색 색인 등)도 없습니다 —
"모델은 이런 모양이다" 를 보이는 데 그 이상이 필요하지 않기 때문입니다.

실제 모듈이 모델에 붙이는 것들(관계·캐스팅·스코프·SoftDeletes·검색 색인·`HasUserOverrides`)은
그것을 실제로 쓰는 확장의 문서를 참고합니다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 테이블 | 모델 |
|---|---|
| `gnuboard7_hello_module_memos` | `Memo` |
<!-- @generated:tables END -->

<!-- @intent START -->
`gnuboard7_hello_module_memos` 하나입니다. 테이블 이름에 **확장 식별자 전체가 접두사로**
들어가는 것에 주의합니다 — 확장은 같은 데이터베이스를 공유하므로, 짧은 이름(`memos`)을 쓰면
다른 확장과 충돌합니다.

복제해서 새 모듈을 만들 때 이 접두사도 함께 바꿔야 합니다. 마이그레이션 파일명·클래스 안의
테이블 이름·모델의 `$table` 이 모두 대상입니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
마이그레이션 1개.

| 파일 | 생성 테이블 | 변경 테이블 | down() |
|---|---|---|---|
| `2026_04_21_000001_create_gnuboard7_hello_module_memos_table.php` | `gnuboard7_hello_module_memos` | `gnuboard7_hello_module_memos` | ✅ |
<!-- @generated:migrations END -->

<!-- @intent START -->
하나이며 테이블 생성뿐입니다. 한국어 `comment` 와 `down()` 이 붙어 있는 것이 규약의
본보기입니다.

실제 모듈에서 새 컬럼을 더할 때는 이 `create_*` 파일을 고치지 않습니다 — 이미 설치된 사이트는
그 파일을 다시 실행하지 않으므로 반영되지 않습니다. 새 `add_*` 파일을 더하고, 기존 행을
손봐야 하면 `upgrades/` 의 업그레이드 스텝 백필을 함께 씁니다.
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_Enum 이 없습니다._
<!-- @generated:enums END -->

<!-- @intent START -->
없습니다. 메모에는 상태도 분류도 없어 닫힌 어휘가 생기지 않았습니다.

실제 모듈에서 상태·타입·분류를 다룰 때는 문자열 리터럴이 아니라 Enum 을 단일 출처로 둡니다 —
화면 필터 옵션·검증 게이트·실제 기록 값 셋이 같은 Enum 에서 파생되지 않으면, 빠진 값으로
기록된 행이 어떤 필터로도 도달할 수 없게 됩니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 클래스 | 종류 | 설명 |
|---|---|---|
| `MemoRepository` | 구현 | 메모 Repository 구현체 |
| `MemoRepositoryInterface` | 인터페이스 | 메모 Repository 인터페이스 |
<!-- @generated:repositories END -->

<!-- @intent START -->
인터페이스와 구현이 1:1 로 짝을 이룹니다. **`MemoService` 는 인터페이스만 주입받습니다** —
구체 클래스를 타입힌트하면 그 Service 를 다른 구현으로 바꿀 수 없고, 테스트에서 대역을 끼울
수도 없습니다.

바인딩은 모듈 서비스 프로바이더가 담당합니다. 새 Repository 를 더할 때는 인터페이스·구현·
바인딩 셋을 함께 만듭니다 — 바인딩을 빠뜨리면 주입 시점에 해결 실패로 드러납니다.
<!-- @intent END -->
