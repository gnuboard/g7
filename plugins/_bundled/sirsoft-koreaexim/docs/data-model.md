# 수출입은행 환율 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 모델 | 테이블 | fillable | 관계 | 특성 |
|---|---|---|---|---|
| `KoreaeximRateRun` | `koreaexim_rate_runs` | 16 | items→KoreaeximRateRunItem | - |
| `KoreaeximRateRunItem` | `koreaexim_rate_run_items` | 10 | run→KoreaeximRateRun | - |
<!-- @generated:models END -->

<!-- @intent START -->
실행 모델은 기준통화·환율 종류·조회일·결과·요청 횟수를 보존합니다. 항목 모델은 통화마다 이전값·새값·은행 단위와 적용 여부를 보존하여 당시의 계산을 확인할 수 있게 합니다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 테이블 | 모델 |
|---|---|
| `koreaexim_rate_run_items` | `KoreaeximRateRunItem` |
| `koreaexim_rate_runs` | `KoreaeximRateRun` |
<!-- @generated:tables END -->

<!-- @intent START -->
테이블명에 koreaexim 접두사를 붙여 다른 환율 제공자와 충돌하지 않습니다. 최신 성공 응답만 재적용 원천으로 사용하고 연결 테스트는 성공 원천을 바꾸지 않습니다. 인증키는 이력 테이블에 저장하지 않습니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
마이그레이션 2개.

| 파일 | 생성 테이블 | 변경 테이블 | down() |
|---|---|---|---|
| `2026_09_14_000000_create_koreaexim_rate_runs_table.php` | `koreaexim_rate_runs` | - | ✅ |
| `2026_09_14_000001_create_koreaexim_rate_run_items_table.php` | `koreaexim_rate_run_items` | - | ✅ |
<!-- @generated:migrations END -->

<!-- @intent START -->
신규 설치 마이그레이션은 테이블이 존재하면 보존합니다. 항목은 실행에 속하며 삭제 시 항목을 명시적으로 먼저 제거합니다. 최신 성공 실행 1건은 보존기간을 넘어도 남겨 통화 변경 재계산을 지원합니다.
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| Enum | backing | case 수 | case |
|---|---|---|---|
| `ItemStatus` | `string` | 5 | `applied`, `unchanged`, `unsupported`, `invalid_rate`, `base_currency` |
| `RateType` | `string` | 3 | `deal_bas_r`, `ttb`, `tts` |
| `RunStatus` | `string` | 12 | `success`, `partial`, `no_data`, `skipped`, `dry_run`, `auth_missing`, `auth_error`, `quota_exceeded`, `외 4개` |
| `RunTrigger` | `string` | 5 | `scheduled`, `retry`, `manual`, `dry_run`, `currency_changed` |
<!-- @generated:enums END -->

<!-- @intent START -->
상태·실행 계기·환율 종류를 Enum으로 공유합니다. no_data와 skipped는 실패와 구분하며 invalid_rate 항목은 종전 환율을 유지합니다. 표시 라벨은 각 Enum의 번역 키를 사용합니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 클래스 | 종류 | 설명 |
|---|---|---|
| `RateRunRepository` | 구현 | 이력 조회·통화 스냅샷 저장·보존기간 정리. |
| `RateRunRepositoryInterface` | 인터페이스 | - |
<!-- @generated:repositories END -->

<!-- @intent START -->
목록은 요약 컬럼만 조회하고 상세만 항목을 로드합니다. 페이지 이동은 지연 조인으로 처리합니다. KST 당일 구간은 UTC 저장 시각으로 변환하여 호출 예산과 재시도 판정에 사용합니다.
<!-- @intent END -->
