# 마이그레이션 검증 (validate-migration)

마이그레이션 파일 및 **작업계획서 내 마이그레이션 코드 블록**이 그누보드7 규정을 준수하는지 검증합니다.

## 0단계: 검증 대상 유형 판별 (CRITICAL)

```text
⚠️ CRITICAL: 검증 대상이 소스코드인지 작업계획서인지 먼저 판별
```

### 판별 기준

| 파일 확장자 | 유형             | 검증 방식                                      |
| ----------- | ---------------- | ---------------------------------------------- |
| `*.php`     | 소스코드         | 파일 전체를 마이그레이션 코드로 검증           |
| `*.md`      | **작업계획서**   | 마크다운 내 PHP 코드 블록 추출 후 검증         |

### 작업계획서 검증 시 추가 단계

작업계획서(`.md`) 파일인 경우:

1. **코드 블록 추출**: 마크다운에서 \`\`\`php 코드 블록 중 마이그레이션 관련 코드 추출
2. **컨텍스트 파악**: `Schema::create`, `Schema::table`, `up()`, `down()` 등의 키워드로 마이그레이션 코드 식별
3. **규정 적용**: database-guide.md의 마이그레이션 규칙 적용

```text
⚠️ 작업계획서 검증 시 주의사항:
- 코드 블록이 예시/설명 목적인지, 실제 구현 계획인지 구분
- "예시:", "Example:", "// 예시" 등이 포함된 코드는 참고용으로 처리
- 실제 구현 계획 코드만 엄격하게 검증
```

## 1단계: 규정 문서 읽기

다음 규정 문서를 읽어 최신 규칙을 확인합니다:

- `docs/database-guide.md` - 데이터베이스 규칙
- `docs/extension/extension-update-system.md` - 업그레이드 스크립트 규칙

## 2단계: 검증 대상 파일 읽기

$ARGUMENTS 경로의 마이그레이션 파일을 읽습니다.

경로가 지정되지 않은 경우, 최근 생성/수정된 마이그레이션 파일을 대상으로 합니다:

- `database/migrations/**/*.php` - 코어 마이그레이션
- `modules/**/database/migrations/**/*.php` - 모듈 마이그레이션
- `modules/_bundled/**/database/migrations/**/*.php` - _bundled 모듈 마이그레이션

## 3단계: 규정 기반 자동 검증 (CRITICAL)

```text
⚠️ CRITICAL: 1단계에서 읽은 규정 문서의 모든 규칙을 검증 항목으로 사용합니다.
스킬에 하드코딩된 검증 항목이 아닌, 규정 문서가 Single Source of Truth입니다.
```

### 3.1 규정에서 검증 패턴 추출

1단계에서 읽은 규정 문서에서 다음 패턴을 추출합니다:

| 추출 대상 | 검증 유형 |
| --------- | --------- |
| `❌` 또는 `잘못된` 키워드가 포함된 코드 블록 | **금지 패턴** - 발견 시 에러 |
| `✅` 또는 `올바른` 키워드가 포함된 코드 블록 | **필수 패턴** - 누락 시 경고 |
| `⚠️ 절대 금지`, `CRITICAL` 등 강조된 규칙 | **필수 검증 항목** |
| `TL;DR` 섹션의 핵심 포인트 | **우선 검증 항목** |

### 3.2 검증 방법

**금지 패턴 검증**:

```bash
# 규정 문서의 ❌ 예시에서 추출한 패턴을 grep으로 검사
grep -rn "[금지 패턴]" [대상 파일]
```

발견 시: 에러로 보고하고 규정 문서의 해당 섹션 참조 안내

**필수 패턴 검증**:

- 해당 컨텍스트에서 필수 패턴이 사용되어야 하는 경우, 누락 여부 확인
- 누락 시: 경고로 보고하고 규정 문서의 올바른 예시 안내

### 3.3 마이그레이션 특화 검증

규정 문서의 마이그레이션 섹션에서 추출한 규칙으로 다음을 검증합니다:

1. **파일명 규칙**: 규정에 정의된 파일명 패턴과 일치 여부
2. **컬럼 정의**: `comment()` 사용 여부 및 내용 적절성
3. **down() 메서드**: 완전한 롤백 구현 여부
4. **외래키**: `constrained()` 등 규정에 명시된 메서드 사용 여부

### 3.4 핵심 규칙 검증

규정 문서에 명시된 절대 규칙을 검증합니다.

#### 3.4.1 CASCADE 삭제 금지 검증

```text
⚠️ CRITICAL: DB CASCADE에 의존한 삭제 절대 금지 → Service에서 명시적 삭제 (훅/파일/로깅 보장)
```

| ❌ 금지 | ✅ 올바른 사용 |
| --- | --- |
| `->onDelete('cascade')` (단독 의존) | Service에서 명시적 삭제 + 훅 실행 |
| CASCADE만으로 연관 데이터 정리 | `->onDelete('restrict')` 또는 `->onDelete('set null')` + Service 삭제 |

**검증 항목**:

- `onDelete('cascade')` 사용 시 해당 Service에서 명시적 삭제 로직이 존재하는지 확인
- CASCADE만 의존하면 훅/파일정리/로깅이 누락됨 → 경고 필수

#### 3.4.2 exists/unique 검증 규칙 사용 검증

```php
// ❌ 금지 - 문자열 테이블명
'email' => 'unique:users,email'
'category_id' => 'exists:categories,id'

// ✅ 올바른 패턴 - Rule 클래스 사용
'email' => [Rule::unique(User::class, 'email')]
'category_id' => [Rule::exists(Category::class, 'id')]
```

#### 3.4.3 모듈 마이그레이션 업그레이드 스크립트 확인

모듈 마이그레이션 수정 시 기존 설치 환경에 대한 업그레이드 경로 확인:

| 상황 | 필요 작업 |
| --- | --- |
| 신규 테이블 생성 | 마이그레이션만 (업그레이드 스크립트 불필요) |
| 기존 테이블 스키마 변경 | 마이그레이션 + 업그레이드 스크립트 (`upgrades/Upgrade_X_Y_Z.php`) |
| 기존 데이터 백필/변환 | 업그레이드 스크립트만 (마이그레이션 아님) |

```text
⚠️ 기존 DB 데이터 백필/변환은 마이그레이션이 아닌 업그레이드 스크립트로 작성
```

## 4단계: 결과 보고

검증 결과를 다음 형식으로 보고합니다:

```text
## 마이그레이션 검증 결과

### 검증 파일
- [파일 경로]

### [규정 문서명] - [섹션명] 검증 결과

#### 금지 패턴 검사
- ❌ 금지 패턴 발견: [패턴] (파일:라인)
  - 규정: [규정 문서명] > [섹션명]
  - 수정 방법: [올바른 패턴으로 변경]
- ✅ 금지 패턴 없음

#### 필수 패턴 검사
- ❌ 필수 패턴 누락: [컨텍스트]에서 [패턴] 필요
  - 규정: [규정 문서명] > [섹션명]
- ✅ 필수 패턴 준수

#### 컬럼 comment 검증
- ✅ comment 있음: [컬럼명]
- ❌ comment 없음: [컬럼명] → 추가 필요

#### down() 메서드 검증
- ✅ 완전 구현
- ❌ 미구현 또는 불완전
```

---

## 5단계: 작업계획서 전용 검증 (마크다운 파일인 경우)

검증 대상이 작업계획서(`.md`)인 경우, 다음 추가 검증을 수행합니다.

### 5.1 마이그레이션 코드 블록 식별

PHP 코드 블록 중 다음 키워드가 포함된 블록을 마이그레이션 코드로 식별:

- `Schema::create`
- `Schema::table`
- `Schema::drop`
- `public function up()`
- `public function down()`
- `$table->` (컬럼 정의)

### 5.2 파일명 규칙 검증

작업계획서에 마이그레이션 파일명이 명시된 경우 검증:

```text
✅ 올바른 파일명:
- create_products_table
- add_price_and_stock_to_products_table
- remove_category_from_products_table

❌ 잘못된 파일명:
- update_products  (동작 불명확)
- products_migration  (규칙 위반)
```

### 5.3 컬럼 정의 검증

```php
// ✅ 올바른 패턴 - comment 포함
$table->string('name')->comment('상품명');
$table->decimal('price', 12, 2)->comment('판매가격');
$table->boolean('is_active')->default(true)->comment('활성화 여부 (true: 활성, false: 비활성)');
$table->enum('status', ['draft', 'published', 'archived'])->comment('상태 (draft: 임시저장, published: 게시됨, archived: 보관됨)');

// ❌ 잘못된 패턴 - comment 누락
$table->string('name');
$table->boolean('is_active')->default(true);
```

**검증 항목**:

- ❌ comment 누락
- ❌ boolean/enum 값 설명 누락
- ✅ 한국어 comment 사용

### 5.4 down() 메서드 검증

```php
// ✅ 올바른 패턴 - 완전한 롤백
public function down(): void
{
    Schema::dropIfExists('products');
}

// ❌ 잘못된 패턴 - 빈 down() 메서드
public function down(): void
{
    //
}
```

### 5.5 외래키 검증

```php
// ✅ 올바른 패턴
$table->foreignId('category_id')->constrained()->onDelete('cascade');

// ❌ 잘못된 패턴
$table->unsignedBigInteger('category_id');
$table->foreign('category_id')->references('id')->on('categories');  // 구식 문법
```

### 5.6 작업계획서 검증 결과 보고 형식

```text
## 작업계획서 마이그레이션 검증 결과

### 검증 파일
- [마크다운 파일 경로]

### 마이그레이션 코드 블록 요약
- 테이블 생성: X개
- 컬럼 추가/수정: Y개
- 총 검증 대상: Z개

### 파일명 규칙 검증
- ✅ 규칙 준수: [파일명]
- ❌ 규칙 위반: [파일명] → [수정 제안]

### 컬럼 comment 검증
- ✅ comment 있음: [컬럼명]
- ❌ comment 없음: [컬럼명] (코드 블록 위치)
  - boolean/enum인 경우: 값 설명도 필요

### down() 메서드 검증
- ✅ 완전 구현
- ❌ 미구현 또는 불완전: [위치]

### 외래키 검증
- ✅ constrained() 사용
- ❌ 구식 문법 사용: [위치]
```

---

## 핵심 원칙

```text
⚠️ CRITICAL:
- 규정 문서가 Single Source of Truth - 스킬에 검증 항목 하드코딩 금지
- 규정 문서의 ❌/✅ 예시를 자동으로 검증 패턴으로 사용
- 규정이 변경되면 검증도 자동으로 변경됨
- 작업계획서(.md) 검증 시 코드 블록 추출 후 동일한 규정 적용
- DB CASCADE 삭제 의존 금지 → Service에서 명시적 삭제 필수
- exists/unique 검증: Rule::exists(Model::class, 'col') 패턴 사용
- 모듈 마이그레이션 시 업그레이드 스크립트 필요 여부 확인
```
