# Scout 검색 엔진 시스템 (Search System)

> **중요도**: 높음
> **관련 문서**: [service-repository.md](service-repository.md) | [hooks.md](../extension/hooks.md) | [database-guide.md](../database-guide.md)

---

## TL;DR (5초 요약)

```text
1. Laravel Scout + DatabaseFulltextEngine: MySQL FULLTEXT + ngram 기반 검색 (기본 드라이버)
2. FulltextSearchable 인터페이스: searchableColumns() + searchableWeights() 구현 필수
3. LIKE fallback 자동 적용: FULLTEXT 미지원 DBMS(SQLite, PostgreSQL)에서 자동 전환
4. 확장 포인트: core.search.engine_drivers 필터 훅으로 Meilisearch 등 커스텀 엔진 등록 가능
5. AsUnicodeJson 캐스트: JSON 컬럼 FULLTEXT 검색 시 한글 \uXXXX 이스케이프 방지 필수
```

---

## 목차

1. [아키텍처 개요](#아키텍처-개요)
2. [FulltextSearchable 인터페이스](#fulltextsearchable-인터페이스)
3. [검색 엔진 드라이버](#검색-엔진-드라이버)
4. [확장 포인트](#확장-포인트)
5. [마이그레이션](#마이그레이션)
6. [AsUnicodeJson 캐스트](#asunicodejson-캐스트)
7. [환경설정](#환경설정)
8. [관련 문서](#관련-문서)

---

## 아키텍처 개요

G7은 **Laravel Scout**를 통해 검색 기능을 제공하며, 기본 검색 엔진으로 **DatabaseFulltextEngine**을 사용합니다.

**핵심 구조**:

```
Controller/Service
    ↓ Model::search($keyword)
Laravel Scout (EngineManager)
    ↓ SCOUT_DRIVER 기반 엔진 선택
DatabaseFulltextEngine
    ↓ FulltextSearchable 인터페이스 참조
    ├── MySQL/MariaDB → MATCH...AGAINST IN BOOLEAN MODE
    └── SQLite/PostgreSQL → LIKE fallback
```

**핵심 컴포넌트**:

| 파일 | 역할 |
|------|------|
| `app/Search/Contracts/FulltextSearchable.php` | 검색 대상 컬럼/가중치 정의 인터페이스 |
| `app/Search/Engines/DatabaseFulltextEngine.php` | MySQL FULLTEXT + ngram Scout 엔진 |
| `app/Providers/ScoutServiceProvider.php` | 엔진 등록 + 필터 훅 처리 |
| `app/Casts/AsUnicodeJson.php` | FULLTEXT ngram용 UTF-8 JSON 캐스트 |
| `config/scout.php` | Scout 설정 (드라이버, 큐, 소프트삭제 등) |

**설계 원칙**:

- MySQL 테이블 자체가 인덱스 소스 -- 외부 검색 서버 불필요
- `update()`, `delete()`, `flush()`, `createIndex()`, `deleteIndex()`는 모두 **no-op** (MySQL이 자동 관리)
- FULLTEXT 미지원 DBMS에서 LIKE fallback 자동 적용 (테스트 환경 SQLite 호환)

---

## FulltextSearchable 인터페이스

`App\Search\Contracts\FulltextSearchable` 인터페이스를 구현한 모델만 DatabaseFulltextEngine에서 검색됩니다.

### 필수 메서드

| 메서드 | 반환 타입 | 설명 |
|--------|----------|------|
| `searchableColumns()` | `array<string>` | FULLTEXT 검색 대상 컬럼명 배열 |
| `searchableWeights()` | `array<string, float>` | 컬럼별 검색 가중치 (높을수록 상위 노출) |

### 구현 예시 (Product 모델)

```php
use App\Search\Contracts\FulltextSearchable;
use Laravel\Scout\Searchable;

class Product extends Model implements FulltextSearchable
{
    use Searchable;

    // FULLTEXT 검색 대상 컬럼
    public function searchableColumns(): array
    {
        return ['name', 'description'];
    }

    // 컬럼별 가중치 (name 매칭이 description보다 2배 높은 점수)
    public function searchableWeights(): array
    {
        return [
            'name' => 2.0,
            'description' => 1.0,
        ];
    }
}
```

### 가중치 기반 스코어 계산

검색 결과는 `_ft_score` 가상 컬럼으로 관련성 점수가 부여됩니다:

```sql
-- MySQL에서 생성되는 쿼리 (예시)
SELECT products.*,
  (MATCH(`name`) AGAINST(? IN BOOLEAN MODE) * 2.0
   + MATCH(`description`) AGAINST(? IN BOOLEAN MODE) * 1.0) as _ft_score
FROM products
WHERE (MATCH(`name`) AGAINST(? IN BOOLEAN MODE)
   OR MATCH(`description`) AGAINST(? IN BOOLEAN MODE))
ORDER BY _ft_score DESC
```

LIKE fallback 시 `_ft_score`는 항상 0 (관련성 순위 불가).

### 현재 FulltextSearchable 구현 모델

| 모듈 | 모델 | 검색 컬럼 |
|------|------|----------|
| sirsoft-ecommerce | Product | name, description |
| sirsoft-ecommerce | Category | name |
| sirsoft-ecommerce | Brand | name |
| sirsoft-ecommerce | Coupon | name |
| sirsoft-ecommerce | ProductCommonInfo | name |
| sirsoft-board | Post | (모델 참조) |
| sirsoft-page | Page | (모델 참조) |

---

## 검색 엔진 드라이버

### 기본 드라이버: mysql-fulltext

`DatabaseFulltextEngine`은 MySQL FULLTEXT + ngram 파서를 활용합니다:

- **MySQL 8.0+**: `MATCH...AGAINST IN BOOLEAN MODE` + **ngram 파서** (한글 2글자 토큰 분리)
- **MariaDB**: `MATCH...AGAINST IN BOOLEAN MODE` + 기본 파서 (ngram 미지원)
- **SQLite/PostgreSQL**: `LIKE %keyword%` fallback (개발/테스트 환경 호환)

### DBMS 지원 판단

```php
// FULLTEXT 지원 여부 (MySQL, MariaDB만 true)
DatabaseFulltextEngine::supportsFulltext();

// ngram 파서 지원 여부 (MySQL만 true, MariaDB는 false)
DatabaseFulltextEngine::supportsNgramParser();
```

### SCOUT_DRIVER 전환

`.env`에서 드라이버를 변경하면 즉시 적용됩니다:

```env
# 기본값: MySQL FULLTEXT
SCOUT_DRIVER=mysql-fulltext

# 플러그인에서 등록한 드라이버로 전환
SCOUT_DRIVER=meilisearch
```

### whereFulltext() 정적 헬퍼

Scout Builder를 사용할 수 없는 곳 (관계 검색, 서브쿼리 등)에서 FULLTEXT 조건을 직접 추가합니다:

```php
use App\Search\Engines\DatabaseFulltextEngine;

// Repository에서 사용 예시
$query = Post::query();
DatabaseFulltextEngine::whereFulltext($query, 'content', $keyword);
DatabaseFulltextEngine::whereFulltext($query, 'title', $keyword, 'or');
```

DBMS에 따라 자동 분기:
- MySQL/MariaDB: `WHERE MATCH(\`content\`) AGAINST(? IN BOOLEAN MODE)`
- 그 외: `WHERE content LIKE '%keyword%'`

---

## 확장 포인트

### core.search.engine_drivers 필터 훅

`ScoutServiceProvider`에서 `core.search.engine_drivers` 필터 훅을 통해 플러그인이 추가 검색 엔진을 등록할 수 있습니다.

```php
// 플러그인 ServiceProvider에서 등록
use App\Extension\HookManager;

public function boot(): void
{
    HookManager::addFilter('core.search.engine_drivers', function (array $drivers) {
        $drivers['meilisearch'] = \App\Search\Engines\MeilisearchEngine::class;
        return $drivers;
    });
}
```

등록 후 `.env`에서 `SCOUT_DRIVER=meilisearch`로 전환하면 해당 엔진이 사용됩니다.

### ScoutServiceProvider 동작 흐름

```php
// app/Providers/ScoutServiceProvider.php

// 1. 기본 드라이버 맵
$drivers = ['mysql-fulltext' => DatabaseFulltextEngine::class];

// 2. 필터 훅으로 플러그인 드라이버 수집
$drivers = HookManager::applyFilters('core.search.engine_drivers', $drivers);

// 3. EngineManager에 모든 드라이버 등록
$this->app->resolving(EngineManager::class, function (EngineManager $manager) use ($drivers) {
    foreach ($drivers as $name => $engineClass) {
        $manager->extend($name, fn () => $this->app->make($engineClass));
    }
});
```

---

## 마이그레이션

### addFulltextIndex() 헬퍼

`DatabaseFulltextEngine::addFulltextIndex()`는 DBMS별 조건부 DDL을 처리합니다:

```php
use App\Search\Engines\DatabaseFulltextEngine;

// 마이그레이션 up()에서 사용
public function up(): void
{
    DatabaseFulltextEngine::addFulltextIndex(
        'ecommerce_products',       // 테이블명 (prefix 제외)
        'ft_ecommerce_products_name', // 인덱스명
        'name'                        // 대상 컬럼 (string 또는 array)
    );
}
```

**DBMS별 동작**:

| DBMS | 생성되는 DDL |
|------|-------------|
| MySQL 8.0+ | `ALTER TABLE ... ADD FULLTEXT INDEX ... WITH PARSER ngram` |
| MariaDB | `ALTER TABLE ... ADD FULLTEXT INDEX ...` (ngram 없음) |
| SQLite/PostgreSQL | 스킵 (no-op) |

### 마이그레이션 down() 패턴

```php
public function down(): void
{
    if (! Schema::hasTable('ecommerce_products')) {
        return;
    }

    $indexes = array_column(Schema::getIndexes('ecommerce_products'), 'name');

    Schema::table('ecommerce_products', function (Blueprint $table) use ($indexes) {
        if (in_array('ft_ecommerce_products_name', $indexes)) {
            $table->dropIndex('ft_ecommerce_products_name');
        }
    });
}
```

### 인덱스 네이밍 규칙

```
ft_{테이블명}_{컬럼명}
```

예: `ft_ecommerce_products_name`, `ft_ecommerce_products_description`

### 복합 컬럼 인덱스

```php
// 여러 컬럼을 하나의 FULLTEXT 인덱스로 생성
DatabaseFulltextEngine::addFulltextIndex(
    'posts',
    'ft_posts_title_content',
    ['title', 'content']  // 배열 전달
);
```

---

## AsUnicodeJson 캐스트

### 문제

Laravel 기본 `array` 캐스트는 `json_encode()`로 한글을 `\uXXXX`로 이스케이프합니다:

```json
// 기본 array 캐스트: \uXXXX 이스케이프
{"ko": "\uc0c1\ud488\uba85"}

// AsUnicodeJson 캐스트: 실제 UTF-8
{"ko": "상품명"}
```

MySQL ngram 토크나이저는 **실제 UTF-8 문자**를 기준으로 토큰을 생성하므로, `\uXXXX` 이스케이프된 데이터에서는 한글 검색이 동작하지 않습니다.

### 사용법

```php
use App\Casts\AsUnicodeJson;

class Product extends Model
{
    protected $casts = [
        'name' => AsUnicodeJson::class,        // FULLTEXT 검색 대상 JSON 컬럼
        'description' => AsUnicodeJson::class,  // FULLTEXT 검색 대상 JSON 컬럼
        'meta_keywords' => 'array',             // 검색 대상 아닌 컬럼은 기본 캐스트 사용 가능
    ];
}
```

### 적용 대상

FULLTEXT 인덱스가 걸리는 JSON 타입 컬럼에는 반드시 `AsUnicodeJson` 캐스트를 사용합니다. 내부적으로 `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` 플래그를 사용합니다.

---

## 환경설정

### config/scout.php 주요 설정

| 키 | 기본값 | 설명 |
|----|--------|------|
| `driver` | `mysql-fulltext` | 검색 엔진 드라이버 (`SCOUT_DRIVER` 환경변수) |
| `prefix` | `''` | 인덱스 접두사 |
| `queue` | `false` | 인덱스 동기화 큐 사용 여부 |
| `soft_delete` | `true` | 소프트 삭제 레코드 필터링 |
| `after_commit` | `false` | DB 트랜잭션 커밋 후 인덱스 동기화 |
| `chunk.searchable` | `500` | 대량 인덱싱 시 청크 크기 |

### .env 설정

```env
# 검색 엔진 드라이버 (기본: mysql-fulltext)
SCOUT_DRIVER=mysql-fulltext

# 인덱스 접두사 (선택)
SCOUT_PREFIX=

# 인덱스 동기화 큐 사용 (선택)
SCOUT_QUEUE=false
```

> **참고**: `mysql-fulltext` 드라이버는 MySQL 테이블 자체가 인덱스이므로 `SCOUT_QUEUE`, `SCOUT_PREFIX`는 실질적으로 사용되지 않습니다. 외부 검색 엔진(Meilisearch 등)으로 전환 시 의미가 있습니다.

---

## 관련 문서

- [Service-Repository 패턴](service-repository.md) - Repository에서 whereFulltext() 사용
- [훅 시스템](../extension/hooks.md) - core.search.engine_drivers 필터 훅
- [데이터베이스 가이드](../database-guide.md) - 마이그레이션 규칙
- [플러그인 개발](../extension/plugin-development.md) - 검색 엔진 플러그인 개발
