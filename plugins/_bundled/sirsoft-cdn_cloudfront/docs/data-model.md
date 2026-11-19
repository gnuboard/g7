# CloudFront CDN — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 모델 | 테이블 | fillable | 관계 | 특성 |
|---|---|---|---|---|
| `CloudfrontPurge` | `cloudfront_purges` | 5 | - | - |
<!-- @generated:models END -->

<!-- @intent START -->
`CloudfrontPurge` 한 행이 실패한 무효화 요청 한 건(경로 묶음)이다. 성공하면 지우므로 남은 행은 모두 실패 이력이다. 재시도를 멈춘 행(`next_attempt_at` 이 null)은 마지막 실패 뒤 30일이 지나면 정기 작업이 지운다. `caller_reference`(UUID, 고유)는 첫 시도에 보낸 CallerReference 라서 건별 재시도가 같은 값으로 다시 보낸다. 비어 있으면 모델(`HasUuids`)이 채운다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 테이블 | 모델 |
|---|---|
| `cloudfront_purges` | `CloudfrontPurge` |
<!-- @generated:tables END -->

<!-- @intent START -->
`cloudfront_purges` 는 데이터 삭제를 고른 제거 때 지워진다(`getDynamicTables()` + 마이그레이션 `down()`). 데이터를 남기는 제거, 비활성화는 대기열을 보존하고, 다시 켜면 이어서 재시도한다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
마이그레이션 1개.

| 파일 | 생성 테이블 | 변경 테이블 | down() |
|---|---|---|---|
| `2026_11_05_000000_create_cloudfront_purges_table.php` | `cloudfront_purges` | - | ✅ |
<!-- @generated:migrations END -->

<!-- @intent START -->
`next_attempt_at` 인덱스는 재시도 정기 작업의 「시각이 된 건」 조회용이다. 플러그인이 아직 출시 전(1.0.0)이라 `caller_reference` 컬럼은 생성 마이그레이션에 바로 넣었다. 상태 컬럼을 따로 두지 않고 `next_attempt_at` 의 null 여부로 대기, 중단을 가른다.
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_Enum 이 없습니다._
<!-- @generated:enums END -->

<!-- @intent START -->
상태 Enum 이 없다 — 상태가 「재시도 대기」(next_attempt_at 있음)와 「중단」(null) 둘뿐이고 컬럼 값에서 바로 파생된다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 클래스 | 종류 | 설명 |
|---|---|---|
| `PurgeQueueRepository` | 구현 | 실패한 캐시 무효화 대기열 저장소 (Eloquent) |
| `PurgeQueueRepositoryInterface` | 인터페이스 | 실패한 캐시 무효화 대기열 저장소 |
<!-- @generated:repositories END -->

<!-- @intent START -->
대기열 접근은 `PurgeQueueRepositoryInterface` 로만 한다. 서비스는 시도 횟수, 다음 시각 계산을, 저장소는 저장, 조회만 맡는다.
<!-- @intent END -->
