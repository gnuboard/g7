# Daum 우편번호 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_소유 모델이 없습니다._
<!-- @generated:models END -->

<!-- @intent START -->
없습니다. 이 플러그인은 아무것도 저장하지 않습니다.

선택된 주소는 화면의 입력 칸에 채워질 뿐이며, 그 값을 어디에 어떻게 저장할지는 주소 입력
화면을 소유한 확장(이커머스의 배송지 등)이 정합니다. 여기서 저장을 맡으면 소비하는 확장마다
저장 형식 분기가 늘어나고, 그 확장의 데이터 소유권도 흐려집니다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_소유 테이블이 없습니다._
<!-- @generated:tables END -->

<!-- @intent START -->
없습니다. 저장하는 데이터가 없으므로 테이블도 없습니다.

이 플러그인을 삭제해도 정리할 데이터가 없다는 뜻이기도 합니다 — 주소 검색만 사라지고 이미
입력된 주소는 그 주소를 소유한 확장에 그대로 남습니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_마이그레이션이 없습니다._
<!-- @generated:migrations END -->

<!-- @intent START -->
없습니다. 스키마가 없으므로 마이그레이션도 없습니다.

나중에 저장할 것이 생긴다면(예: 검색 사용 통계) 먼저 "그것이 정말 이 플러그인의 데이터인가"를
따져야 합니다 — 이 플러그인이 상태를 갖지 않는 것은 누락이 아니라 설계입니다.
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_Enum 이 없습니다._
<!-- @generated:enums END -->

<!-- @intent START -->
없습니다. 설정의 `display_mode`(`layer` / `popup`)만 닫힌 어휘를 갖는데, 설정 스키마의 `enum`
타입으로 선언되어 있어 별도 PHP Enum 을 두지 않았습니다.

이 값을 코드에서 분기로 비교하는 자리가 프론트 핸들러 한 곳뿐이라 어휘가 갈라질 여지가
없습니다. 비교 지점이 늘어나기 시작하면 그때 Enum 으로 올리는 것이 맞습니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_Repository 가 없습니다._
<!-- @generated:repositories END -->

<!-- @intent START -->
없습니다. 데이터 접근 자체가 없습니다.

이 플러그인의 PHP 코드는 `plugin.php` 하나이며, 메서드 넷(`getMetadata` ·
`getSettingsSchema` · `getConfigValues` · `getHooks`)이 전부입니다 — 설정 제공과 훅 선언
외에 서버가 하는 일이 없다는 사실이 그대로 드러납니다.
<!-- @intent END -->
