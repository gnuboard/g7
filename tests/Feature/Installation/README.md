# Pre-release Smoke Suite

> 릴리스 전 clean-install/업그레이드 경로 전수 검증용 스위트

## 목적

- 마이그레이션 실행 후 핵심 사용자 여정 1회전 검증 (end-to-end)
- 마이그레이션–Repository/Service 결합 회귀 방지 (beta.2 #12 유형)
- 업그레이드 경로에서 기존 데이터 호환성 검증

## 실행

```bash
# 전체 스모크 스위트
composer test-smoke

# 첫 실패에서 중단 (CI 모드)
composer test-smoke-ci

# 특정 테스트만
php artisan test tests/Feature/Installation/FreshInstallSmokeTest.php
```

## 스코프

phpunit testsuite `Installation`은 다음 2개 경로를 커버합니다:

```xml
<testsuite name="Installation">
    <directory>tests/Feature/Installation</directory>
    <directory>modules/_bundled/*/tests/Feature/Installation</directory>
</testsuite>
```

| 위치 | 담당 범위 | 예시 |
|------|-----------|------|
| `tests/Feature/Installation/` (이 디렉토리) | 코어·크로스 모듈 스모크 | `FreshInstallSmokeTest.php`, `UpgradeSmokeTest.php` |
| `modules/_bundled/{id}/tests/Feature/Installation/` | 모듈별 설치·회귀 | `BoardFreshInstallTest.php`, `EcommerceFreshInstallTest.php` |

## 작성 대상 (TODO — 담당자 배정 대기)

### 코어·크로스 모듈 (이 디렉토리)

- [ ] `FreshInstallSmokeTest.php` — 마이그레이션 전수 실행 + 코어 헬스체크 (users/roles/modules 테이블 생성, 기본 역할/권한 시드)
- [ ] `UpgradeSmokeTest.php` — 이전 릴리스 시드 → 최신 마이그레이션 → 기존 데이터 접근 1회전

### 모듈별 (각 모듈 `tests/Feature/Installation/` 아래)

- [ ] `modules/_bundled/sirsoft-board/tests/Feature/Installation/BoardFreshInstallTest.php`
  - `#12` 회귀: fresh install 후 `BoardService::createBoard()` 성공 검증 (`ensureBoardPartitions()` 우회 사용 금지)
  - `#11` 회귀: 댓글 생성 → `board_posts.comments_count` 증가 검증 (HTTP 레이어 포함)
- [ ] `modules/_bundled/sirsoft-ecommerce/tests/Feature/Installation/EcommerceFreshInstallTest.php`
  - 상품 생성 → 주문 → 결제 mock 1회전

## 작성 규칙

- `RefreshDatabase` + 실제 마이그레이션 전수 실행 (mock DB 금지)
- 모듈/플러그인 install artisan 커맨드 호출 경로 포함
- 각 단계에 명확한 assertion (`assertTrue(true)` 같은 무의미 assert 금지)
- 사용자 여정 1개를 end-to-end로 (HTTP 레이어까지)

> 상세: [docs/testing-guide.md — Pre-release Smoke Suite](../../../docs/testing-guide.md#pre-release-smoke-suite)
