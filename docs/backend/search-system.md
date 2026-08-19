# Scout 검색 엔진 시스템 (Search System)

> **중요도**: 높음
> **관련 문서**: [service-repository.md](service-repository.md) | [hooks.md](../extension/hooks.md) | [database-guide.md](../database-guide.md)

---

## TL;DR (5초 요약)

```text
1. Laravel Scout + DatabaseFulltextEngine: MySQL FULLTEXT + ngram 기반 검색 (기본 드라이버)
2. FulltextSearchable 인터페이스: searchableColumns() + searchableWeights() 구현 필수
3. LIKE fallback 자동 적용: FULLTEXT 미지원 DBMS(SQLite, PostgreSQL) + 대상 테이블·컬럼
   조합의 FULLTEXT 인덱스 부재 시 (후자는 설치 결함 신호라 조합당 프로세스 1회 기록)
4. 확장 포인트: core.search.engine_drivers(엔진) + core.search.index_maintainers(인덱스 점검) 필터 훅
5. 인덱스 재생성은 언제나 선택 사항 — 자동 트리거 없음 (테이블 잠금·전체 재색인 비용)
6. AsUnicodeJson 캐스트: JSON 컬럼 FULLTEXT 검색 시 한글 \uXXXX 이스케이프 방지 필수
```

---

## 목차

1. [아키텍처 개요](#아키텍처-개요)
2. [FulltextSearchable 인터페이스](#fulltextsearchable-인터페이스)
3. [검색 엔진 드라이버](#검색-엔진-드라이버)
4. [확장 포인트](#확장-포인트)
5. [검색 목록의 페이지 이동](#검색-목록의-페이지-이동)
6. [마이그레이션](#마이그레이션)
7. [AsUnicodeJson 캐스트](#asunicodejson-캐스트)
8. [환경설정](#환경설정)
9. [관련 문서](#관련-문서)

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
| `app/Search/Contracts/SearchIndexMaintainer.php` | 인덱스 점검·재생성 계약 (엔진 중립) |
| `app/Search/SearchIndexMaintenanceManager.php` | 활성 드라이버의 점검기 해석 + 재생성 진입점 |
| `app/Search/Engines/Maintenance/FulltextIndexMaintainer.php` | mysql-fulltext 드라이버의 점검기 구현 |
| `app/Console/Commands/Search/SearchIndexCommand.php` | `search:index` 점검·재생성 커맨드 |

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

### `_ft_score` SELECT 와 ORDER BY 는 불가분

`_ft_score` 는 실제 컬럼이 아니라 **SELECT 별칭**이다. SELECT 에서 빠지면 정렬이 참조할 대상이 사라져 `SQLSTATE[42S22] Unknown column '_ft_score' in 'order clause'` 로 조회가 통째로 실패한다.

| ❌ 금지 | ✅ 올바른 사용 |
| ------- | -------------- |
| 결과 소비 메서드(`mapIds()`/`map()`/`lazyMap()`/`getTotalCount()`)가 넘겨받은 쿼리에 `select()`/`selectRaw()`/`addSelect()` 를 걸어 SELECT 를 재작성 | 소비 메서드는 실행만 한다. `pluck()` 은 기존 SELECT 를 보존하므로 좁힐 필요가 없다 |
| 스코어 별칭만 보존해 SELECT 를 재조립 | 재작성 자체를 하지 않는다 — 소비자가 `->query()` 로 얹은 별칭(`withCount()` 의 `*_count` 등)에도 정렬이 걸릴 수 있어 알려진 별칭 하나만 막으면 다른 이름으로 재발한다 |
| `reorder()` 로 정렬을 지워 회피 | `keys()` 는 Scout 공개 계약상 **관련도 순** 키 목록을 약속한다 — 정렬을 지우면 오류 없이 무순서 키를 돌려주는 무음 회귀가 된다 |

SELECT 를 재작성하는 지점은 엔진이 쿼리를 **조립하는 자리 하나뿐**이며(`applySelect()`), 키만 필요한 경로에서도 스코어를 함께 남긴다. 조립 지점이 남긴 것을 소비 지점이 되돌리면 두 지점이 정면으로 상충한다.

`->query()` 콜백의 `orderBy` 는 Scout `Builder::$orders` 를 채우지 않으므로 엔진이 `_ft_score DESC` 를 항상 tiebreak 으로 덧붙인다. 즉 **검색 쿼리의 ORDER BY 에는 사실상 언제나 `_ft_score` 가 있다** — "이 경로는 스코어 정렬을 안 쓰니 괜찮다" 는 판단은 성립하지 않는다.

정적 검사가 결과 소비 메서드 안의 SELECT·정렬 재작성을 차단한다.

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

### 인덱스 커버 판정 — MATCH 는 인덱스가 있어야만 조립한다

드라이버가 FULLTEXT 를 지원해도, MySQL 의 `MATCH(a, b)` 는 어떤 FULLTEXT 인덱스의
컬럼 **집합과 정확히 일치**(순서 무관)해야 실행된다. 복합 `(title, content)` 인덱스는
`MATCH(title)` 단독을 커버하지 못한다. 인덱스가 없는 채 MATCH 를 실행하면
`1191 Can't find FULLTEXT index` 가 되고, 그 오류가 소비처의 catch 에 삼켜지면
화면에는 "검색 결과 0건" 으로만 나타난다 (부분 실패 설치에서 실제 발생 — 공개 #103).

그래서 엔진은 MATCH 조립 전에 `fulltextIndexCoversColumns($table, $columns)` 로
커버 여부를 판정한다:

- 판정은 컬럼 한정자 제거·소문자·정렬 정규화 후 **집합 동등 비교**다. 순서가 달라도
  같은 집합이면 커버한다 (`['content','title']` ≡ `['title','content']`).
- Scout 경로(`performSearch`)는 컬럼마다 단일 `MATCH(col)` 를 조립하므로, **각 컬럼이
  개별 단일 인덱스로** 커버될 때만 MATCH 를 쓴다. 복합 인덱스만 있는 테이블은 LIKE 로
  내려간다.
- `applyAny()` 는 컬럼별 `apply(단일)` 재귀라 게이트도 단일 컬럼으로 판정한다 — 한 OR
  그룹 안에 MATCH 와 LIKE 가 혼재할 수 있으며, 유효한 SQL 이고 의도된 동작이다.
- 커버되지 않아 내려가는 폴백은 **설치 결함의 신호**이므로 테이블+컬럼 조합당 프로세스
  1회 `Log::warning` 을 남긴다 (복구 안내 포함). 드라이버 미지원 폴백은
  종전대로 무경고다 — 그 DBMS 에서는 부분일치가 정상 경로다.
- 이 경고의 복구 경로는 **해당 확장의 인덱스 생성 마이그레이션 재실행**이다.
  `search:index` 를 안내하지 않는다 — 그 점검은 `INFORMATION_SCHEMA` 에 **이미 존재하는**
  FULLTEXT 인덱스만 열거하고 `--repair` 는 그중 색인이 낡은 것만 재생성하므로, 인덱스가
  아예 없는 이 상황은 목록에 나타나지도 재생성 대상이 되지도 않는다. 안내가 그쪽을
  가리키면 운영자는 "이상 없음" 을 보고 원인 추적이 거기서 끊긴다.

인덱스 카탈로그는 `INFORMATION_SCHEMA.STATISTICS` 에서 lazy 적재하는 **프로세스(static)
캐시**다. 적재 실패(Throwable)는 캐시하지 않고 그 요청만 LIKE 로 내려간다 — 일시 장애가
워커 수명 내내 강등으로 굳지 않게 하기 위함이다. `addFulltextIndex()` 성공 직후에는
카탈로그를 무효화해 같은 프로세스에서 바로 반영된다. 그 외의 인덱스 변경(배포 등)은
FPM 워커 재시작 시점에 반영된다.

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

### 키워드 술어는 활성 엔진이 만든다

Scout 의 `Model::search()` 는 "엔진이 결과를 돌려준다" 모델입니다. 그래서 결과를 받은 뒤 그 ID
로 DB 를 다시 조회해야 하고, 매칭이 많으면 ID 전량을 메모리에 올려 무제한 `IN (...)` 을 만들게
됩니다. 페이지네이션·조인·필터를 DB 에 남긴 채 키워드 조건만 얹으려면 **엔진에게 술어를 달라고
요청할 통로**가 따로 필요합니다.

그 통로가 `App\Search\Contracts\KeywordPredicateProvider` 이고, 해석은
`App\Search\KeywordSearch` 가 단독으로 수행합니다.

```php
use App\Search\KeywordSearch;

// 컬럼들을 하나의 조건으로 함께 평가 (복합 인덱스가 있는 테이블)
KeywordSearch::apply($query, ['title', 'content'], $keyword);

// 컬럼마다 따로 평가해 OR 로 묶기 (컬럼별 단일 인덱스만 있는 테이블)
KeywordSearch::applyAny($query, ['name', 'description'], $keyword);
```

**저장소는 구체 엔진 클래스를 지목하지 않습니다.** 지목하는 순간 플러그인이 등록한 검색 엔진은
호출될 기회 자체를 잃고, 오류도 경고도 없이 그 사이트의 검색만 조용히 다른 방식으로 동작합니다.

활성 엔진이 이 계약을 구현하지 않으면 부분일치로 내려가며, 그 사실이 기록에 남습니다 — 폴백은
정상 동작처럼 보이므로 기록하지 않으면 "검색이 느리고 관련도가 이상하다" 는 증상만 남습니다.

#### 페이지네이션은 DB 가, 상한은 엔진이

엔진에게 페이지 번호를 넘기지 않습니다. 엔진이 자기 순서로 한 페이지 분량만 돌려주면, 그 뒤에
적용되는 DB 필터(분류·전시상태·조인)에 일부가 탈락해 페이지가 비고, DB 정렬과 엔진의 관련도
순서가 달라 페이지 경계도 어긋나기 때문입니다.

엔진이 책임지는 것은 **"얼마까지 돌려줄 것인가"** 하나이며, 그 값은 `KeywordSearchContext` 로
전달됩니다. 외부 검색 서버를 쓰는 엔진은 자기 서버에서 키 집합을 받아 조건으로 붙이는데,
상한을 지키지 않으면 매칭이 큰 검색어에서 그 집합 자체가 메모리 폭발이 됩니다.

```php
KeywordSearch::applyAny($query, ['name', 'description'], $keyword, 'and', 'search');
//                                                                        ↑ 상한 해석 컨텍스트
```

상한은 목록 총 건수 상한(`PaginationLimits::resultCap()`)과 **같은 값**을 씁니다. 두 기준이
갈라지면 "엔진이 돌려준 건수" 와 "화면이 보고하는 총 건수" 가 서로 다른 근거를 갖게 됩니다.
상한에 걸려 잘린 경우 그 이상은 도달 불가이고 총 건수는 "이상" 으로 보고됩니다.

이 의무는 정적 검사로 강제할 수 없습니다 — 외부 엔진 코드는 이 저장소 밖입니다. 코어가 할 수
있는 것은 **값을 손에 쥐어 주는 것**까지이며, 그 값이 실제로 도달하는지는 계약 테스트가
고정합니다.

#### 전문검색을 제공하지 않는 DBMS

부분일치 경로는 임시방편이 아닙니다. 전문검색을 제공하지 않는 DBMS 로 설치된 사이트에서는
**이것이 정상 검색 경로**이므로 이식성을 갖춥니다.

- 검색어의 `%` `_` `\` 를 escape 해 이용자가 입력한 글자 그대로 찾습니다.
- "대소문자를 구분하지 않는 부분일치" 를 어떤 연산자로 쓰는지는 DBMS 마다 다릅니다. 그 표는
  `config('core.search.like_operators')` 에 **선언형으로** 있고, 표에 없는 드라이버는
  `like_operator_default` 를 씁니다. 코어 코드에는 드라이버명을 적지 않습니다 — 적으면
  DBMS 가 공식 지원 목록에 추가될 때마다 코어를 고쳐야 합니다.
- 확장은 `core.search.like_operators` 필터 훅으로 새 DBMS 의 연산자를 선언합니다.

```php
HookManager::addFilter('core.search.like_operators', function (array $operators) {
    $operators['somedb'] = 'imatch';

    return $operators;
});
```

해당 DBMS 의 진짜 전문검색(예: PostgreSQL `tsvector`)은 그 엔진을 `core.search.engine_drivers`
로 등록하고 `KeywordPredicateProvider` 를 구현하면 코어 수정 없이 이 경로를 그대로 탑니다.

### 검색어 토큰은 OR 로 결합한다 (확정)

`sanitizeBooleanModeKeyword()` 는 BOOLEAN MODE 연산자를 제거한 뒤 남은 토큰을 각각
따옴표로 묶어 **공백으로 잇는다**. BOOLEAN MODE 에서 공백 결합은 OR 이므로, "빨간 운동화"
는 "빨간" 이 든 행과 "운동화" 가 든 행을 **모두** 매치한다.

각 토큰에 `+` 를 붙여 AND 로 바꾸면 매칭 집합 자체가 줄어 대용량에서 검색 시간이 줄어들
여지가 있다. 그럼에도 **OR 을 유지한다** — 이용자가 입력한 단어 중 하나만 든 문서가
결과에서 통째로 사라지는 것은 성능과 맞바꿀 수 없는 손실이기 때문이다. 한글은 ngram 파서가
2글자 단위로 토큰을 쪼개므로 AND 전환의 결과 축소 폭이 특히 크다.

성능 축이 문제가 되면 토큰 결합 방식이 아니라 전용 검색 엔진(`core.search.engine_drivers`
훅으로 교체)으로 해결한다. 상한 COUNT 가 매칭 시간을 줄이지 못하는 이유와 실측치는
[pagination.md](pagination.md) 참조.

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

등록 후 `.env`에서 `SCOUT_DRIVER=meilisearch`로 전환하거나, 관리자 환경설정 > 드라이버의
검색엔진 항목에서 선택할 수 있습니다.

#### 드라이버 폴백 가드

검색엔진은 다른 드라이버 카테고리(스토리지·캐시·세션·큐·로그·메일 등)와 같은 폴백 가드를
받습니다. 저장된 엔진을 제공하던 플러그인이 삭제되면, 부팅 시 그 값이 사용 불가로 판정되어
기본 엔진(`mysql-fulltext`)으로 되돌아갑니다. 이 가드가 없으면 `scout.driver` 가 죽은 값으로
남아 공개 검색이 오류로 멈춥니다.

카탈로그 조회는 **두 훅을 함께 읽습니다** — 위의 `core.search.engine_drivers`(Scout 엔진 맵)와
일반 드라이버 훅 `core.settings.available_search_drivers`(`{id, label}` 목록). 그래서 검색엔진
플러그인은 Scout 등록 훅 하나만 구현하면 되고, 관리자 화면 선택지에도 자동으로 나타납니다.
라벨은 `settings.drivers.search.{id}` 다국어 키에서 조회하며, 키가 없으면 ID 를 그대로 씁니다.

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

### core.search.index_maintainers 필터 훅

검색 엔진은 "인덱스가 있는데 내용이 색인되어 있지 않은" 상태가 될 수 있습니다. 이때 검색은
**오류 없이 0건**을 돌려주므로 예외도 로그도 남지 않고, 운영자는 "원래 검색이 안 되는 줄" 알고
지나갑니다. `SearchIndexMaintainer` 계약이 그 상태를 점검·복구하는 방법을 엔진마다 정의합니다.

인덱스의 실체가 엔진마다 다르므로(FULLTEXT = 테이블에 붙은 인덱스, Meilisearch/Elasticsearch =
외부 서버의 인덱스) 코어는 판정 방법을 알지 못한 채 계약만 호출하고, 등급(`SearchIndexStatus`)만
보고 재생성 대상을 고릅니다.

```php
use App\Extension\HookManager;
use App\Search\SearchIndexMaintenanceManager;

HookManager::addFilter(SearchIndexMaintenanceManager::MAINTAINERS_FILTER, function (array $maintainers) {
    $maintainers['meilisearch'] = MeilisearchIndexMaintainer::class;

    return $maintainers;
});
```

구현할 메서드

| 메서드 | 반환 | 설명 |
|--------|------|------|
| `driver()` | `string` | 담당 Scout 드라이버명 (`config('scout.driver')` 와 대조) |
| `isAvailable()` | `bool` | 현재 환경에서 점검 가능 여부 (미지원 DBMS·서버 미연결 등) |
| `unavailableReason()` | `?string` | 점검 불가 사유. **"점검 대상 0" 과 "점검할 수 없었음" 은 구분되어야 한다** |
| `inspect(array $filters)` | `SearchIndexHealth[]` | 인덱스별 판정. 엔진별 세부는 `details`, 재생성에 필요한 자기 정보는 `context` 에 담는다 |
| `rebuild(SearchIndexHealth $health)` | `void` | 재생성 (`context` 를 그대로 되돌려받는다) |

판정 등급 (`App\Enums\SearchIndexStatus`)

| 등급 | 뜻 | 재생성 대상 |
|------|-----|:---:|
| `healthy` | 색인된 내용으로 검색이 성립 | — |
| `degraded` | 일부만 성립. 토크나이저 특성(불용어 등)일 수 있다 | ✕ |
| `stale` | 검색이 성립하지 않음 | ✅ |
| `skipped` | 표본·연결 부재로 판정 불가 (사유 기재) | — |

유지보수기를 등록하지 않은 엔진은 점검 대상에서 빠질 뿐 **검색은 그대로 동작**합니다. 화면·커맨드는
그 경우 "이 엔진은 점검을 제공하지 않는다" 를 명시합니다.

### 재생성은 언제나 선택 사항이다

재생성 비용은 엔진에 따라 테이블 잠금(FULLTEXT)이나 전체 재색인(외부 엔진)입니다. **운영 중인
사이트에서 확장을 업데이트했다는 이유만으로 그 비용이 발생해서는 안 되므로**, 재생성은 어떤 자동
트리거에도 연결하지 않고 운영자가 명시적으로 선택했을 때만 수행합니다.

| 경로 | 선택 방법 | 기본값 |
|------|----------|:---:|
| `search:index --repair` | 옵션 + 확인 프롬프트 | 점검만 |
| `module:update` / `plugin:update` / `module:install` / `plugin:install` | `--rebuild-search-index` | 미수행 |
| `core:update` (번들 확장 일괄 업데이트 단계) | 대화형 확인 또는 `--rebuild-search-index` | 미수행 |
| 관리자 화면 확장 업데이트 모달 | 「업데이트 후 색인이 누락된 검색 인덱스를 재생성」 체크박스 | 미체크 |

선택하지 않아도 **누락 사실은 안내**합니다 — 알려주지 않으면 운영자가 알 방법이 없기 때문입니다.

`--force`(무인 실행)에서는 묻지도 재생성하지도 않습니다. 무인 실행이 대용량 테이블을 잠그는 일이
없어야 합니다.

### 선택은 그 창에서만 유효하다 — 화면 상태를 이월하지 않는다

재생성 체크는 **모달을 열 때마다 해제된 상태**로 시작해야 합니다. 체크 상태를 전역 상태에 남겨 두면
한 번 체크한 운영자가 다음 확장을 업데이트할 때 **아무것도 누르지 않았는데 재생성이 다시 수행**됩니다.
서버의 옵인 가드(요청에 실린 값만 신뢰)는 정상 동작하므로 HTTP 레벨 테스트로는 드러나지 않습니다 —
화면이 이미 체크된 값을 보내기 때문입니다.

| ❌ 금지 | ✅ 올바른 사용 |
| --- | --- |
| 모달을 여는 `setState` 시드에 재생성 키를 빼 둠 | 시드와 제출 후 초기화 **양쪽**에 `<x>RebuildSearchIndex: false` |
| 제출 성공 후 다른 상태만 되돌리고 재생성 체크는 그대로 둠 | `onSuccess` 초기화 목록에 재생성 키 포함 |
| 모듈·플러그인이 같은 전역 키를 공유 | 면마다 별도 키 (한쪽 체크가 다른 쪽으로 전이되면 안 됨) |

정적 고정: `templates/_bundled/sirsoft-admin_basic/__tests__/layouts/admin-extension-update-rebuild-optin.test.tsx`
종단 고정: `tests/Playwright/specs/admin/extension-update-search-index-optin.spec.ts`

### 점검 결과는 반드시 호출자에게 도달해야 한다

색인이 비면 검색은 **오류 없이 0건**을 돌려줍니다. 운영자가 알 수 있는 유일한 통로가 응답에 실린
`search_index` 페이로드이므로, 그 페이로드가 중간에서 사라지면 점검 기능 자체가 무의미해집니다.

| ❌ 금지 | ✅ 올바른 사용 |
| --- | --- |
| 응답 헬퍼가 `JsonResource::resolve()` 만 호출 (부가 데이터 유실) | `ResponseHelper::successWithResource` 가 `additional()` 을 응답 최상위에 병합 |
| 재생성 여부만 알리고 잔존 여부는 생략 | `rebuilt` / `stale`·`stale_count` (미수행) 또는 `repaired`·`failed`·`remaining` (수행) |
| 재생성 성공을 곧 복구로 간주 | **`remaining` 은 재생성 후 재점검 결과** — "재생성했다" 와 "복구됐다" 를 구분 |

### 점검의 비-0 종료는 "실패" 가 아니다

`search:index` 는 색인 누락이 남아 있으면 종료 코드 1 을 돌려줍니다. 점검 자체는 정상 수행된 것이며,
이는 CI 에서 이상을 감지하기 위한 신호입니다. 실행 결과를 화면에 표시하는 도구(개발 도구 대시보드 등)가
이를 「실행 실패」로 적으면 운영자는 도구가 고장난 것으로 읽고 **정작 발견된 색인 누락을 놓칩니다**.
종료 코드와 출력을 그대로 보여 주고, 실패로 단정하지 않습니다.

### mysql-fulltext 의 판정 방법 — 자기 매칭(self-match)

기본 드라이버의 유지보수기는 표본 행을 뽑아 **그 행 자신의 내용에서 만든 검색어로 그 행을 찾을 수
있는지** 봅니다. 특정 키워드를 사람이 골라 넣지 않으므로 어떤 테이블·언어에도 그대로 적용됩니다.

토큰은 행마다 여러 개 만들고, **한 행이 어떤 토큰으로도 자신을 찾지 못할 때만** 실패로 셉니다.
하나만 쓰면 토크나이저 특성을 색인 누락으로 오판합니다 — 예를 들어 ngram(`ngram_token_size=2`)은
`Basic` 을 `Ba/as/si/ic` 로 쪼개는데 그중 `as` 가 기본 불용어라 구문 검색이 깨집니다.

대상은 하드코딩하지 않고 `INFORMATION_SCHEMA` 에서 전수 수집하므로, 확장이 나중에 추가하는
FULLTEXT 인덱스도 커맨드 수정 없이 포함됩니다.

```bash
php artisan search:index                        # 활성 엔진의 인덱스 점검 (읽기 전용)
php artisan search:index --repair               # 색인 누락 인덱스 재생성
php artisan search:index --filter=table=pages   # 엔진별 필터 (FULLTEXT: table, index, samples)
php artisan search:index --json                 # 기계 판독용 출력
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

## 검색 목록의 페이지 이동

검색 결과는 컬렉션 리소스를 거치지 않고 리스너가 배열을 만들어 넘긴다. 그 조립과 판정을
도메인마다 손으로 하면 검색 모듈 수만큼 같은 코드가 복제되고, 나중에 필드가 하나 늘 때
어떤 화면은 받고 어떤 화면은 못 받는다. **코어가 계약을 소유하고 확장은 선언만 한다.**

| 코어가 소유하는 것 | 위치 |
|---|---|
| 커서 적용 가능 여부 판정 | `App\Search\SearchPagePolicy` |
| 카테고리 응답 페이로드 조립 | `App\Search\SearchCategoryPayload` |
| 커서 인코딩·디코딩 | `App\Support\Query\KeysetPaginator` |

확장이 선언하는 것은 **정렬 이름이 어떤 실제 컬럼인가** 둘뿐이다.

```php
/** 정렬 이름 → [실제 컬럼, 방향]. 여기에 없는 이름은 커서를 쓰지 않는다. */
public const SEARCH_SORT_MAP = [
    'latest' => ['created_at', 'desc'],
    'oldest' => ['created_at', 'asc'],
];

/** 커서 경계로 쓸 수 있는 실제 컬럼 */
public const SEARCH_CURSOR_COLUMNS = ['created_at'];
```

서비스는 규칙을 다시 쓰지 않고 코어에 판정을 위임한다. 적용할 수 없는 정렬이면 `null` 을
돌려주고, 호출자는 기존 offset 경로를 그대로 쓴다.

```php
$sortKeys = SearchPagePolicy::sortKeys($sort, self::SEARCH_SORT_MAP);

if (! SearchPagePolicy::usesCursor($cursor, $sortKeys, self::SEARCH_CURSOR_COLUMNS)) {
    return null;
}

return $this->repository->searchByKeywordWithCursor($keyword, $sortKeys, $perPage, $cursor);
```

### 관련도순에는 커서를 쓸 수 없다

커서는 정렬 키를 WHERE 절 경계로 삼으므로 정렬 키가 **실제 컬럼**이어야 한다. 관련도순은
전문 검색 점수라는 계산값으로 정렬하므로 경계로 쓸 수 없고, 그 정렬에서는 offset 을
유지한다. 이 판정은 선언에 그 이름이 없는 것으로 자연히 이루어진다.

### 응답 키 집합은 세 형태가 모두 같다

offset·커서·건수전용 세 응답은 채워지는 값만 다르고 키 구성은 동일하다. 화면이 분기 없이
같은 키를 읽을 수 있어야 하기 때문이다.

| 키 | offset | 커서 | 건수전용 |
|---|---|---|---|
| `total` / `total_relation` / `total_is_exact` / `result_cap` | 채움 | 채움(별도 집계) | 채움 |
| `last_page` | 상한 초과 시 `null` | 언제나 `null` | `null` |
| `has_more_pages` | 실측 | 실측 | `false` |
| `next_cursor` / `prev_cursor` | `null` | 채움 | `null` |
| `items` | 채움 | 채움 | 빈 배열 |

커서 응답에 `last_page` 가 없는 것은 결함이 아니라 **커서 방식에 마지막 페이지 번호라는
개념이 없기 때문**이다. 화면은 그때 마지막 페이지 점프만 감추고 "다음" 이동은 유지한다.

### 탭 배지에도 정확도가 따라간다

배지는 숫자 하나만 그리므로, 상한에 걸려 잘린 값이 정확한 것처럼 나가도 오류로 드러나지
않고 그냥 틀린 숫자로만 보인다. 코어가 카테고리별 정확도를 `counts_are_exact` 로 일괄
제공하며, 화면은 정확하지 않은 배지에 "이상" 표시를 붙인다.

---

## 관련 문서

- [대용량 목록 페이지네이션](pagination.md) - 상한 COUNT·커서 적용 기준
- [Service-Repository 패턴](service-repository.md) - Repository에서 whereFulltext() 사용
- [훅 시스템](../extension/hooks.md) - core.search.engine_drivers 필터 훅
- [데이터베이스 가이드](../database-guide.md) - 마이그레이션 규칙
- [플러그인 개발](../extension/plugin-development.md) - 검색 엔진 플러그인 개발
