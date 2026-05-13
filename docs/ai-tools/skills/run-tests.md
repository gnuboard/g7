# 테스트 실행 (run-tests)

그누보드7 프로젝트의 테스트를 실행하고 결과를 검증합니다.

## 1단계: 규정 문서 읽기

다음 규정 문서를 읽어 최신 규칙을 확인합니다:

- `docs/testing-guide.md` - 테스트 규칙
- `docs/frontend/layout-testing.md` - 레이아웃 렌더링 테스트 규칙

## 2단계: .env.testing 확인 (백엔드 테스트 시)

```text
⚠️ CRITICAL: 백엔드 테스트 실행 전 .env.testing 존재 여부 확인 필수
```

1. `.env.testing` 미존재 시:
   - `.env.testing.example` 복사: `cp .env.testing.example .env.testing`
   - `APP_KEY` 생성: `php artisan key:generate --env=testing`
   - PO에게 DB 접속 정보 확인 요청

## 3단계: 테스트 타입 결정

$ARGUMENTS 값에 따라 테스트 타입을 결정합니다:

| 인자 | 테스트 타입 | 명령어 |
|------|-------------|--------|
| `backend` 또는 `php` | 백엔드 테스트 | `php artisan test` |
| `frontend` 또는 `npm` | 프론트엔드 테스트 | `powershell -Command "npm run test:run"` |
| `layout` | 레이아웃 렌더링 테스트 | `powershell -Command "npm run test:run -- layouts"` |
| `all` 또는 미지정 | 전체 테스트 | 백엔드 + 프론트엔드 순차 실행 |
| 특정 파일/클래스명 | 필터 테스트 | `php artisan test --filter=$ARGUMENTS` |

```text
⚠️ CRITICAL: 전체 테스트(all) 지양 → 관련 테스트만 실행 (시간 절약)
⚠️ CRITICAL: 동일 테스트를 결과 확인 목적으로 재실행 금지 → 저장된 출력 확인
```

## 4단계: 테스트 실행

### 백엔드 테스트 (PHPUnit)

```bash
# 관련 테스트만 실행 (권장)
php artisan test --filter=TestClassName 2>&1 | tee /tmp/test-result.txt

# 특정 디렉토리
php vendor/bin/phpunit tests/Feature/Api/Admin/ 2>&1 | tee /tmp/test-result.txt

# _bundled 모듈 테스트 (활성 디렉토리 복사 불필요)
php vendor/bin/phpunit modules/_bundled/sirsoft-ecommerce/tests 2>&1 | tee /tmp/test-result.txt
php vendor/bin/phpunit --filter=TestClassName modules/_bundled/sirsoft-ecommerce/tests 2>&1 | tee /tmp/test-result.txt
```

### 프론트엔드 테스트 (Vitest)

```powershell
# 프로젝트 루트에서 (코어 테스트)
powershell -Command "npm run test:run -- template-engine"

# 템플릿 디렉토리에서 (해당 템플릿만)
powershell -Command "cd templates/sirsoft-admin_basic; npm run test:run"
powershell -Command "cd templates/sirsoft-admin_basic; npm run test:run -- DataGrid"

# _bundled 모듈 프론트엔드 테스트
powershell -Command "cd modules/_bundled/sirsoft-ecommerce; npm run test:run"
```

### 레이아웃 렌더링 테스트 (Vitest + createLayoutTest)

```text
⚠️ CRITICAL: 레이아웃 렌더링 테스트는 Vitest + createLayoutTest() 유틸리티 기반
⚠️ CRITICAL: 브라우저 기반 E2E가 아님 — "인프라 부족" 이유로 건너뛰기 절대 금지
```

```powershell
# 코어 레이아웃 테스트
powershell -Command "npm run test:run -- layouts"

# 모듈 레이아웃 테스트
powershell -Command "cd modules/_bundled/sirsoft-ecommerce; npm run test:run -- layouts"

# 템플릿 레이아웃 테스트
powershell -Command "cd templates/_bundled/sirsoft-admin_basic; npm run test:run -- layouts"
```

**기본 패턴**:

```typescript
import { createLayoutTest, screen } from '../utils/layoutTestUtils';

const testUtils = createLayoutTest(layoutJson);
testUtils.mockApi('products', { response: { data: [] } });
await testUtils.render();
expect(screen.getByTestId('element')).toBeInTheDocument();
testUtils.cleanup();
```

```text
⚠️ CRITICAL: 테스트 출력은 반드시 파일로 저장
✅ 패턴: 명령어 2>&1 | tee /tmp/test-result.txt
❌ 실패 후 결과 확인 목적 재실행 금지 → /tmp/test-result.txt 확인
```

## 5단계: 다계층 테스트 확인 (CRITICAL)

기능 구현 시 **모든 관련 계층**의 테스트가 실행되었는지 확인합니다.

| 작업 유형 | 백엔드 (PHPUnit) | 프론트엔드 (Vitest) | 레이아웃 렌더링 (Vitest) |
|----------|-----------------|-------------------|----------------------|
| 새 화면 구현 | API 엔드포인트 테스트 | 컴포넌트 테스트 | 레이아웃 JSON 렌더링 테스트 |
| 기존 화면 수정 | 변경된 API 테스트 | 변경된 컴포넌트 테스트 | 레이아웃 렌더링 회귀 테스트 |
| 데이터 흐름 변경 | Service/Repository 테스트 | 상태 관리 테스트 | 데이터 바인딩 렌더링 테스트 |

```text
❌ 단일 계층 테스트만으로 기능 구현 완료 선언 금지
❌ 백엔드 API만 테스트하고 프론트엔드/레이아웃 테스트 누락
❌ 컴포넌트만 테스트하고 레이아웃 렌더링 테스트 누락
```

## 6단계: 결과 분석 및 보고

테스트 결과를 다음 형식으로 보고합니다:

```
## 테스트 결과

### 실행 환경
- 테스트 타입: [backend/frontend/layout/all]
- 실행 명령어: [실행한 명령어]

### 결과 요약
- 총 테스트: [N]개
- 통과: [N]개 ✅
- 실패: [N]개 ❌
- 스킵: [N]개 ⏭️

### 다계층 테스트 확인
- □ 백엔드 테스트: [실행/미해당]
- □ 프론트엔드 테스트: [실행/미해당]
- □ 레이아웃 렌더링 테스트: [실행/미해당]

### 실패 테스트 상세 (있는 경우)
- ❌ [테스트명]
  - 파일: [파일 경로]
  - 원인: [실패 원인]
  - 수정 방향: [제안]
```

## 중요 원칙

```text
⚠️ CRITICAL:
- Windows 환경에서 프론트엔드 테스트는 반드시 PowerShell 래퍼 사용
- 테스트 작성 ≠ 완료, 테스트 통과 = 완료
- 템플릿 엔진/컴포넌트 수정 시 테스트 필수
- 기능 구현 시 다계층 테스트 필수 (백엔드 + 프론트엔드 + 레이아웃 렌더링)
- 레이아웃 렌더링 테스트는 createLayoutTest() 유틸리티로 실행 (브라우저 불필요)
- "인프라 부족" 이유로 레이아웃 테스트 건너뛰기 절대 금지
- 테스트 출력은 파일로 저장 → 동일 테스트 재실행 금지
- 전체 테스트 실행 지양 → 관련 테스트만 실행
```

## 테스트 파일 위치 매핑

| 수정 대상 | 테스트 파일 위치 | 테스트 유형 |
|----------|-----------------|-------------|
| `app/Models/*.php` | `tests/Unit/Models/*Test.php` | 모델 메서드, 관계, 스코프 |
| `app/Services/*.php` | `tests/Unit/Services/*Test.php` | 비즈니스 로직 |
| `app/Enums/*.php` | `tests/Unit/Enums/*Test.php` | Enum 메서드 |
| `app/Http/Controllers/**/*.php` | `tests/Feature/**/*Test.php` | API 엔드포인트 |
| `database/migrations/*.php` | 해당 모델/서비스 테스트 | 스키마 변경 |
| `templates/**/src/components/**/*.tsx` | `templates/**/__tests__/*.test.tsx` | 컴포넌트 |
| `resources/js/core/**/*.ts` | `resources/js/core/__tests__/*.test.ts` | 템플릿 엔진 |
| `resources/layouts/**/*.json` | `resources/js/core/template-engine/__tests__/layouts/*.test.tsx` | 코어 레이아웃 렌더링 |
| `modules/**/resources/layouts/**/*.json` | `modules/_bundled/{id}/resources/js/__tests__/layouts/*.test.tsx` | 모듈 레이아웃 렌더링 |
| `templates/**/layouts/**/*.json` | `templates/_bundled/{id}/__tests__/layouts/*.test.tsx` | 템플릿 레이아웃 렌더링 |

## 자동 감지

다음 파일이 수정된 경우 관련 테스트를 자동으로 실행합니다:

- `resources/js/core/` 변경 → 템플릿 엔진 테스트
- `resources/js/core/template-engine/` 변경 → 코어 렌더링 테스트
- `app/Http/Controllers/` 변경 → 해당 컨트롤러 테스트
- `app/Services/` 변경 → 해당 서비스 테스트
- `resources/layouts/**/*.json` 변경 → 코어 레이아웃 렌더링 테스트
- `modules/**/resources/layouts/**/*.json` 변경 → 해당 모듈 레이아웃 렌더링 테스트
- `templates/**/layouts/**/*.json` 변경 → 해당 템플릿 레이아웃 렌더링 테스트
- `templates/**/src/components/**/*.tsx` 변경 → 해당 컴포넌트 테스트
- `modules/_bundled/**/src/**` 변경 → 해당 모듈 테스트
