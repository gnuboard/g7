# 대용량 목록 페이지네이션 (Pagination)

> **관련 문서**: [Service-Repository 패턴](./service-repository.md) | [API 리소스](./api-resources.md) | [검색 시스템](./search-system.md)

---

## TL;DR (5초 요약)

```text
1. 총 건수만 상한을 받는다 — 상한 이하면 정확, 초과면 "이상"(total_relation=at_least)
2. "다음" 이동은 상한과 무관하게 끝까지 열려 있다 (per_page + 1 실측)
3. 계산이 불가능해지는 것은 마지막 페이지 번호 하나뿐 → last_page = null
4. 최신순 등 실제 컬럼 정렬은 커서(키셋)로 전환 가능, 관련도순은 offset 유지
5. 한계값은 관리자 환경설정 > 고급이 SSoT — 확장은 필터 훅으로만 조정
```

---

## 목차

- [왜 상한을 두는가](#왜-상한을-두는가)
- [상한과 페이지 이동의 관계](#상한과-페이지-이동의-관계)
- [구성 요소](#구성-요소)
- [저장소에서 쓰는 법](#저장소에서-쓰는-법)
- [상한 없는 전량 조회](#상한-없는-전량-조회)
- [건수만 필요할 때](#건수만-필요할-때)
- [응답 계약](#응답-계약)
- [커서(키셋) 페이지네이션](#커서키셋-페이지네이션)
- [한계값 설정](#한계값-설정)
- [화면 쪽 규약](#화면-쪽-규약)
- [체크리스트](#체크리스트)

---

## 왜 상한을 두는가

목록 화면은 보통 한 페이지 분량만 보여 주면서 총 건수를 함께 표시한다. 그 총 건수를 구하는
`COUNT(*)` 는 조건에 맞는 행을 **전부** 세야 하므로, 매칭이 많을수록 한 페이지를 그리는 데
드는 비용이 커진다. 검색처럼 매칭이 수십만 건에 이를 수 있는 목록에서는 페이지 조회보다
건수 집계가 더 비싸지는 역전이 일어난다.

한편 사용자가 총 건수에서 실제로 얻는 정보는 "많다/적다" 와 "마지막 페이지가 어디쯤인가"
정도다. 12,842건과 "10,000건 이상" 은 실질적으로 같은 정보를 준다. 그래서 **세는 범위에만
상한을 두고**, 상한을 넘으면 정확한 수 대신 하한을 보고한다.

### 상한이 줄여 주지 못하는 것 — FULLTEXT 술어

상한은 **세는 행 수**를 줄인다. 그러나 조건을 만족하는 행을 찾는 일 자체는 줄이지 못한다.
FULLTEXT(`MATCH ... AGAINST`) 검색에서 이 차이가 크게 드러난다.

30만 행 전부가 한 토큰에 매칭되는 조건으로 실측한 결과다.

| 쿼리 | 소요 |
|------|------|
| 상한 COUNT (`LIMIT 10001` 로 감싼 파생 테이블) | 588.96초 |
| 상한 없는 COUNT | 560.48초 |
| 페이지 조회 (`LIMIT 21`) | 594.76초 |

세 경우가 사실상 같다. InnoDB 전문 검색은 매칭 집합을 먼저 구성하고 그 뒤에 `LIMIT` 을
적용하므로, 몇 건을 돌려주든 매칭 집합을 만드는 비용은 동일하게 든다. 같은 조건에서
실행계획을 보면 상한 COUNT 는 파생 테이블로 감싸는 과정에서 FULLTEXT 인덱스 사용
계획을 잃고 전체 스캔이 되기도 한다.

따라서 검색 목록에서 상한이 주는 이득은 **PHP 메모리·전송량·중복 실행 제거**이며,
데이터베이스가 매칭을 찾는 시간은 상한으로 해결되지 않는다. 이 축이 문제라면 전용 검색
엔진(`core.search.engine_drivers` 훅으로 교체)을 쓰는 것이 남은 경로다.

토큰 결합을 OR 에서 AND 로 바꿔 매칭 집합 자체를 줄이는 안은 **채택하지 않는다**. 입력한
단어 중 하나만 든 문서가 결과에서 통째로 사라지는 손실이 성능 이득보다 크다고 판단했다
(한글은 ngram 파서가 2글자 단위로 토큰을 쪼개 축소 폭이 특히 크다). 상세는
[search-system.md "검색어 토큰은 OR 로 결합한다"](search-system.md) 참조.

측정 한계도 함께 적는다. 위 수치는 한 토큰이 전 행에 걸리도록 만든 인위적 조건이고,
단일 개발 장비 기준이다. 매칭이 적은 일반적인 검색어에는 해당하지 않는다.
`innodb_ft_result_cache_limit` 에 대한 메모리 증가분은 기준선을 확보하지 못해 확정하지
못했다 — 확정된 것은 위 시간 축뿐이다.

---

## 상한과 페이지 이동의 관계

총 건수 상한과 페이지 이동 범위는 **별개 결정**이다. 묶으면 필요 없이 기능이 깎인다.

| 항목 | 상한을 넘었을 때 |
|---|---|
| 총 건수 | "N건 이상" 으로 표기 (`total_relation = at_least`) |
| 다음 페이지 이동 | **그대로 가능** — `per_page + 1` 조회로 정확히 판정 |
| 이전 페이지 이동 | 그대로 가능 |
| 마지막 페이지 점프 | **감춤** — 총 건수를 알아야 계산되는 유일한 값 |

마지막 페이지 점프를 감추는 것은 기능 축소가 아니라 **계산 불가 사실의 정직한 표시**다.
그 버튼은 대량 매칭에서 초대형 OFFSET 을 실행하므로, 눌러도 정상 응답이 오지 않는 경우가 많다.

---

## 구성 요소

| 구성 요소 | 위치 | 역할 |
|---|---|---|
| `TotalRelation` | `app/Enums/TotalRelation.php` | 총 건수 정확도 (`Exact` / `AtLeast`) |
| `BoundedPaginator` | `app/Support/Query/BoundedPaginator.php` | `per_page + 1` 조회 + 상한 파생 테이블 COUNT |
| `BoundedPage` | `app/Support/Query/BoundedPage.php` | 페이지 결과. 표준 페이지네이터 인터페이스를 그대로 만족 |
| `KeysetPaginator` | `app/Support/Query/KeysetPaginator.php` | 커서 인코딩/디코딩 표준 |
| `PaginationLimits` | `app/Support/Query/PaginationLimits.php` | 한계값 해석 (설정 → 필터 훅) |
| `BoundedTotalAware` | `app/Contracts/Pagination/BoundedTotalAware.php` | 정확도를 밝히는 페이지 결과 계약 |

`BoundedPage` 는 `LengthAwarePaginator` 를 상속한다. 기존 컬렉션·리소스·컨트롤러가 전부
페이지네이터 인터페이스에 맞춰져 있으므로, 별도 타입을 새로 정의하면 소비 지점마다 언랩
코드가 생긴다. 상속하면 기존 코드가 그대로 받고 정확도 메타까지 자동으로 얻는다.

---

## 저장소에서 쓰는 법

```php
use App\Support\Query\BoundedPaginator;
use App\Support\Query\PaginationLimits;

public function searchByKeyword(string $keyword, int $perPage = 10, int $page = 1): BoundedPage
{
    $query = $this->model->newQuery()
        ->where('published', true)
        ->orderBy('created_at', 'desc')
        // 전순서 보장 — 정렬 컬럼이 비고유면 페이지 경계에서 행이 겹치거나 샌다
        ->orderBy('id', 'desc');

    return BoundedPaginator::paginate(
        $query,
        perPage: $perPage,
        page: $page,
        resultCap: PaginationLimits::resultCap('search'),
        // 목록이 실제로 쓰는 컬럼만 명시 (프루닝)
        columns: ['id', 'slug', 'title', 'created_at'],
    );
}
```

건수만 필요한 경우(탭 배지 등)는 조회 없이 집계만 한다.

```php
[$total, $relation] = BoundedPaginator::countWithCap($query, PaginationLimits::resultCap('search'));
```

`GROUP BY` 가 걸린 쿼리도 파생 테이블 안에서 그룹이 만들어지므로 **그룹 수**가 그대로 센다.
표준 `count()` 가 그룹별 건수를 돌려주는 문제가 없다.

### 받는 입력은 표준 `paginate()` 와 같다

Eloquent 빌더뿐 아니라 쿼리 빌더(`DB::table(...)`)와 **관계**(`$user->notifications()`)도
그대로 넘길 수 있다.

```php
// 관계를 그대로 넘겨도 된다 — 소속 조건은 그 밑 빌더에 이미 들어가 있어 보존된다
BoundedPaginator::paginate($user->notifications(), perPage: 15, resultCap: $cap);
```

이 폭은 **좁히면 안 된다.** 이 계약은 기존 `->paginate()` 호출을 대체하려고 만든 것이라,
받는 형태가 표준보다 좁으면 바꾸는 것만으로 멀쩡하던 목록이 죽는다. 관계에서 `->paginate()`
가 되는 이유는 관계가 빌더로 호출을 전달해 주기 때문인데, 정적 메서드는 그 전달을 받지
못하므로 계약이 직접 이해해야 한다.

`PaginatesWithDeferredJoin` 은 빌더와 관계를 받는다. 이쪽은 모델의 키 컬럼과 eager load 를
다루므로 Eloquent 가 전제이며, 쿼리 빌더는 받지 않는다.

### 하지 말 것

| ❌ 금지 | ✅ 올바른 사용 |
|---|---|
| 같은 술어로 `count()` 한 번, `get()` 한 번 | `BoundedPaginator::paginate()` 한 번 |
| `paginate(PHP_INT_MAX)` 후 PHP `array_slice` | 실제 `page`/`per_page` 를 저장소까지 하달 |
| `forPage($page, $perPage + 1)` | offset 은 `per_page` 기준으로 따로 계산 |
| 총 건수를 모르는데 `last_page` 를 1 로 채움 | `null` 로 내보내 화면이 감추게 한다 |
| 정렬 마지막이 비고유 컬럼 | 기본키를 덧붙여 전순서 보장 |
| 컬렉션에서 `'pagination' => [...]` 를 손으로 조립 | `...$this->paginationMeta()` — 형태를 스스로 판정한다 |

---

## 상한 없는 전량 조회

`->get()` / `->pluck()` 자체가 문제인 것은 아니다. 문제는 **결과 크기가 데이터 증가에
비례해 커지는데 아무 상한도 없는** 경우다. 그런 조회는 개발 데이터에서 늘 빠르고, 운영
데이터가 쌓인 뒤에야 메모리와 응답 시간을 함께 무너뜨린다. 예외도 경고도 없이 느려지기만
하므로 테스트로는 드러나지 않는다.

한 문장 안에 결과 크기를 묶는 근거가 하나는 있어야 한다.

| 상황 | 쓸 것 |
|---|---|
| 화면에 목록으로 보여 준다 | `BoundedPaginator::paginate()` / `paginateWithDeferredJoin()` |
| 전량을 순회해 처리한다 | `chunkById()` / `lazyById()` (키셋 — OFFSET 밀림이 없다) |
| 몇 건만 필요하다 | `limit()` / `take()` |
| 이미 좁혀진 키 집합이다 | `whereIn($key, $ids)` / `find()` |

행 수가 사용량이 아니라 **운영자의 등록 수**에 묶이는 설정성 테이블(역할·언어팩·정책 등)은
예외다. 그 경우 근거를 코드에 남긴다.

```php
// audit:allow query-unbounded-get reason: 역할은 운영자가 정의한 수만큼만 존재한다 (회원 수와 무관)
return Role::where('is_active', true)->orderBy('id')->get();
```

---

## 건수만 필요할 때

목록을 조회하지 않고 건수만 필요한 자리(탭 배지, 요약 수치)는 `BoundedPaginator::count()`
를 쓴다. `int` 하나만 돌려주면 **상한에 걸려 잘린 값과 정확히 센 값이 구분되지 않는다** —
잘린 10,000 이 "정확히 10,000 건" 으로 화면에 나가는 것은 오류로 드러나지 않고 그냥 틀린
숫자로만 보인다.

```php
$count = BoundedPaginator::count($query, PaginationLimits::resultCap('search'));

return $count->toArray();
// ['total' => 10000, 'total_relation' => 'at_least', 'total_is_exact' => false, 'result_cap' => 10000]
```

여러 카테고리의 건수를 합칠 때는 **하나라도 부정확하면 합계도 부정확**하다. 정확한
카테고리 몇 개를 더해 봐야 전체가 정확해지지 않는다. 반대로 특정 탭 하나만 보는
중이라면 그 카테고리의 정확도만 본다 — 다른 카테고리가 잘렸다는 이유로 정확한 값을
"이상" 이라고 말하지 않는다.

---

## 응답 계약

`BaseApiCollection::paginationMeta()` 가 페이지 결과의 형태를 스스로 판정해 그 형태가 실제로
아는 값만 내보낸다. 모르는 값을 0 이나 1 로 채우면 화면이 그것을 사실로 읽는다.

| 형태 | 예 | `total` | `last_page` | 추가 필드 |
|---|---|---|---|---|
| 전체건수형 | `paginate()` | 정확 | 정확 | — |
| 상한형 | `BoundedPage` | 정확 또는 하한 | 하한이면 `null` | `total_relation` `total_is_exact` `result_cap` |
| 단순형 | `simplePaginate()` | 없음 | 없음 | — |
| 커서형 | `cursorPaginate()` | 없음 | 없음 | `next_cursor` `prev_cursor` |

표준 `paginate()` 를 쓰는 기존 컬렉션의 응답은 필드 단위로 이전과 완전히 같다.

컬렉션이 이 블록을 손으로 조립하면 두 가지가 함께 깨진다. 커서 결과에는 `total()` 과
`lastPage()` 가 없어 **그 요청만 500** 이 되고, 상한형에서는 정확도 필드가 빠져 잘린 건수가
정확한 값처럼 화면에 나간다. 둘 다 표준 `paginate()` 만 쓰던 시절에는 드러나지 않던 형태라,
목록 하나를 상한형이나 커서형으로 바꾸는 순간 처음 나타난다.

```json
{
  "pagination": {
    "current_page": 3,
    "per_page": 20,
    "from": 41,
    "to": 60,
    "has_more_pages": true,
    "last_page": null,
    "total": 10000,
    "total_relation": "at_least",
    "total_is_exact": false,
    "result_cap": 10000
  }
}
```

---

## 커서(키셋) 페이지네이션

OFFSET 방식은 건너뛸 행을 실제로 읽어야 해서 페이지가 깊어질수록 느려진다. 커서 방식은
직전 페이지 마지막 행의 정렬 키를 WHERE 절 경계로 삼으므로 깊이와 무관하게 일정하다.

```php
use App\Support\Query\KeysetPaginator;

$page = KeysetPaginator::paginate(
    $query,
    perPage: 20,
    sortKeys: [['created_at', 'desc']],
    uniqueKey: 'id',
    cursor: $request->query(KeysetPaginator::CURSOR_PARAM),
);
```

### 관련도순에는 적용할 수 없다

커서는 정렬 키를 WHERE 절 경계로 쓴다. 따라서 정렬 키가 **실제 컬럼**이어야 한다.
FULLTEXT 관련도 점수(`_ft_score`)는 행마다 계산되는 값이고 컬럼이 아니므로 경계로 쓸 수 없다.

`sort=relevance` 는 이 제약 때문에 OFFSET 을 유지한다. 판정은
`KeysetPaginator::supports($sortKeys, $columnWhitelist)` 가 담당하며, 허용 컬럼 목록 밖의
정렬 키가 하나라도 있으면 `false` 를 돌려준다.

깨진 커서 문자열은 예외 대신 첫 페이지로 처리한다. 사용자가 주소를 손으로 고쳤다는 이유로
목록 화면이 오류를 띄우게 두지 않는다.

---

## 한계값 설정

| 설정 | 기본값 | 의미 |
|---|---|---|
| `pagination.result_cap` | 10000 | 총 건수를 정확히 세는 상한 (0 = 무제한) |
| `pagination.max_page` | 1000 | 직접 요청할 수 있는 페이지 번호 상한 (0 = 무제한) |

해석 순서는 **관리자 환경설정 > 고급 → `config/core.php` 기본값 → 확장 필터 훅** 이다.
해석은 `PaginationLimits` 한 곳에서만 하며, 확장은 값을 리터럴로 다시 적지 않고 훅으로 조정한다.

```php
HookManager::addFilter('core.pagination.filter_result_cap', function (int $cap, ?string $context) {
    return $context === 'search' ? 50000 : $cap;
});
```

`max_page` 는 남용 차단용이다. 정상 탐색은 `has_more_pages` 와 커서로 계속 열려 있고,
이 값은 임의의 큰 페이지 번호를 직접 던져 초대형 OFFSET 을 만드는 것만 막는다.

---

## 화면 쪽 규약

`Pagination` 컴포넌트는 세 가지 입력으로 동작한다.

| Prop | 의미 |
|---|---|
| `totalPages` | 마지막 페이지. **`null` 이면 페이지 번호 목록과 마지막 페이지 점프가 사라진다** |
| `hasMorePages` | 다음 페이지 존재 여부. 총 건수를 몰라도 정확하다 |
| `showFirst` / `showLast` | 첫/마지막 버튼을 따로 제어 (미지정 시 `showFirstLast` 를 따름) |

총 건수 표기는 정확도에 따라 문구가 갈린다.

```text
정확: 총 1,234건
하한: 총 10,000건 이상  (+ 검색어를 더 구체적으로 입력하면 정확한 건수를 볼 수 있다는 안내)
```

세지 않은 값을 정확한 것처럼 말하지 않는다.

---

## 체크리스트

- [ ] 목록 조회가 같은 술어를 `count()` + `get()` 으로 두 번 실행하지 않는가
- [ ] `paginate()` 에 조회 컬럼을 명시했는가 (`['*']` 방치 금지)
- [ ] 정렬 마지막에 기본키를 덧붙여 전순서를 보장했는가
- [ ] 총 건수를 모를 때 `last_page` 를 `null` 로 내보내는가
- [ ] 화면이 `total_is_exact` 를 보고 문구를 바꾸는가
- [ ] 상한값을 리터럴로 적지 않고 `PaginationLimits` 를 거치는가
- [ ] 커서를 쓴다면 정렬 키가 전부 실제 컬럼인가
