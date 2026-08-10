# 성능 계측 시스템 (Benchmark)

> **백엔드 가이드** | [목차로 돌아가기](README.md)

---

## TL;DR (5초 요약)

```text
1. `g7:bench` 가 4축(list/screen/write/batch)을 잰다 — 계측 대상은 커맨드에 하드코딩하지 않는다
2. 선언 지점: 코어 `config/benchmark.php`, 확장 `getBenchmarkProfiles()` — 스키마 동일
3. 화면(screen) 축은 프로파일이 선언한 권한만 가진 임시 계정으로 내부 요청 → 롤백. `--as=` 로 기존 계정 지정 가능
4. 데이터를 변경하는 축(write/batch/비-GET screen)은 `--allow-write` 없이 실행 거부
5. 문서에 옮길 수치는 `--report` 산출물에서만 옮긴다 (환경 정보 동반 — 눈대중 기재 금지)
```

---

## 목차

- [무엇을 재는가](#무엇을-재는가)
- [선언 지점](#선언-지점)
- [축별 스키마](#축별-스키마)
- [실행](#실행)
- [안전장치](#안전장치)
- [리포트](#리포트)
- [관련 문서](#관련-문서)

---

## 무엇을 재는가

| 축 | 재는 것 | 실행 방식 |
| ------ | ------ | ------ |
| `list` | 목록 SELECT 비용 (전체 컬럼 / 목록 컬럼 / 키 컬럼 3축) | 선언된 필터·정렬로 쿼리를 조립해 OFFSET 별 반복 측정 |
| `screen` | 화면 1장 응답 시간 + 실행 쿼리 건수 + N+1 후보 | 라우트를 HTTP 커널로 내부 요청 처리 |
| `write` | 저장 경로 1회 소요 시간 + 쿼리 건수 | 확장이 선언한 콜백 실행 |
| `batch` | 배치 커맨드 소요 시간 + 피크 메모리 | artisan 커맨드 실행 감싸기 |

`screen` 축이 값어치가 가장 크다 — 사용자가 체감하는 단위이고, 목록 SELECT 는 빨라도 N+1 로 느려지는 경우를 잡는다. `list` 축의 세 값 배수는 지연 조인(`PaginatesWithDeferredJoin`) 적용의 기대 효과에 대응한다 (셋째 축이 지연 조인의 inner 쿼리).

## 선언 지점

계측 대상은 **소유자가 선언한다**. 커맨드에 대상을 하드코딩하지 않는 이유는, 확장이 설치·제거되는 설치본마다 실제로 존재하는 대상이 다르기 때문이다.

| 소유자 | 선언 위치 |
| ------ | ------ |
| 코어 | `config/benchmark.php` 의 `profiles` 배열 |
| 모듈 | `module.php` 의 `getBenchmarkProfiles()` 오버라이드 |
| 플러그인 | `plugin.php` 의 `getBenchmarkProfiles()` 오버라이드 |

프로파일 키는 확장 내부에서만 고유하면 된다. 서로 다른 확장이 같은 키를 쓰면 커맨드가 `{식별자}/{키}` 로 지목하며, 짧은 키가 모호할 때는 후보를 나열하고 실행을 거부한다 (임의로 하나를 고르면 어느 확장의 목록을 잰 것인지 알 수 없게 된다).

```php
// modules/_bundled/sirsoft-ecommerce/module.php
public function getBenchmarkProfiles(): array
{
    return [
        'orders' => [
            'type' => 'list',
            'label' => '주문 목록',
            'table' => 'ecommerce_orders',
            // 컬럼을 다시 적지 않고 Repository 상수를 참조한다 — 다시 적으면 계측이 조용히 낡는다
            'columns' => OrderRepository::LIST_COLUMNS,
            'order' => [['ordered_at', 'desc'], ['id', 'desc']],
            'soft_delete' => true,
        ],
    ];
}
```

선언이 잘못된 프로파일은 **조용히 버리지 않는다** — 사유와 함께 경고로 드러내고 목록에서 제외한다 (버려진 선언이 곧 계측 사각이 된다).

## 축별 스키마

공통 필드는 `type`(필수)과 `label`(선택)이다.

### type=list

| 필드 | 설명 |
| ------ | ------ |
| `table` | 대상 테이블 (필수) |
| `columns` | 목록이 실제로 select 하는 컬럼. `['*']` 는 "응답 계약상 전 컬럼 노출이라 프루닝 불가" 선언이며 이때 비교축은 `select *` vs `select id` |
| `order` | `[[컬럼, 방향], ...]` |
| `filters` | 화면이 실제로 거는 필터. 필터 없이 재면 인덱스 선택이 달라져 화면에서 일어나는 일과 다른 것을 잰다 (아래 형식) |
| `soft_delete` | true 면 `deleted_at IS NULL` 부착 |
| `seed_overrides` | 합성 시딩 시 고정할 컬럼값 (`filters` 와 맞출 용도) |

`filters` 는 등가 비교와 연산자 형태를 함께 받는다. 등가 비교만 지원하면 화면이 실제로 거는 필터를 선언할 수 없는 목록이 생긴다 — 주문 목록은 상태 미지정 시 임시 주문 상태를 `NOT IN` 으로 제외한다.

```php
'filters' => [
    'board_id' => 1,                                              // 등가 비교
    'order_status' => ['not in', OrderStatusEnum::listHiddenValues()],  // 연산자 형태
    'created_at' => ['>=', '2026-01-01'],
],
```

연산자는 닫힌 집합이다 — `=` `!=` `<>` `<` `<=` `>` `>=` `like` `in` `not in`. 목록에 없는 연산자를 선언하면 측정 전에 사유와 함께 거부한다(조용히 빠진 필터로 측정하면 화면과 다른 것을 재면서 정상 측정으로 보고된다). 값을 Enum·상수로 참조해 도메인 SSoT 와 어긋나지 않게 한다.

선언형 필터로 재현할 수 없는 술어(상관 서브쿼리, 권한 스코프 등)가 목록의 지배적 조건이라면 **프로파일을 두지 않는다** — 어느 화면도 내지 않는 수치가 되기 때문이다.

### type=screen

| 필드 | 설명 |
| ------ | ------ |
| `route` | 라우트명 (권장). 라우트가 사라지면 조용히 404 를 재는 대신 즉시 실패한다 |
| `route_params` | 라우트 파라미터 |
| `uri` | 라우트명이 없을 때만 쓰는 원시 경로 |
| `method` | HTTP 메서드 (기본 `GET`) |
| `query` | 쿼리스트링 `[키 => 값]` |
| `permissions` | 계측용 임시 계정에 부여할 권한 식별자 목록 |

권한을 프로파일에서 받는 이유는, 계측 대상 화면이 요구하는 권한만 주어야 미들웨어 통과 여부까지 실제와 같아지기 때문이다 (전권 계정으로 재면 권한 검사 비용과 분기가 달라진다).

### type=write

| 필드 | 설명 |
| ------ | ------ |
| `prepare` | 회차별 선행 준비 콜백 (**계측 구간 밖**에서 실행, 회차 번호를 인자로 받음) |
| `callback` | 계측 대상 콜백 (`prepare` 반환값을 인자로 받음) |
| `cleanup` | 트랜잭션 롤백으로 되돌지 않는 잔여물(파일·캐시) 정리 |

콜백은 `'Fqcn'`(invokable) 또는 `['Fqcn', 'method']` 형식만 허용한다 — 코어 선언이 `config/benchmark.php` 에 있고 이 파일은 `config:cache` 대상이라 클로저를 담을 수 없으므로, 확장 선언도 같은 형식으로 통일한다. 클로저를 선언하면 레지스트리가 사유와 함께 거부한다.

`prepare` 를 계측 구간 밖에 두는 이유는, 저장 경로에는 선행 상태가 필요한 경우가 많고(주문 생성에는 임시 주문이 필요하고 임시 주문은 1회만 전환됨) 그 준비 비용이 측정값에 섞이면 재려던 것을 재지 못하기 때문이다.

### type=batch

| 필드 | 설명 |
| ------ | ------ |
| `command` | artisan 커맨드명 |
| `arguments` | 커맨드 인자/옵션 `[키 => 값]` |

## 실행

```bash
# 등록된 프로파일 목록
php artisan g7:bench --list-profiles

# 프로파일 단위
php artisan g7:bench --profile=core/users_screen
php artisan g7:bench --profile=sirsoft-ecommerce/orders --offsets=0,20000,50000,199980 --runs=3 --explain

# 축 단위 / 전체
php artisan g7:bench --axis=screen
php artisan g7:bench --all --allow-write

# 깊은 OFFSET 계측 (대량 합성 행 시딩 — 폐기 가능한 DB 에서만)
php artisan --env=testing g7:bench --profile=sirsoft-board/board_posts --fresh --seed=200000
php artisan g7:bench --profile=sirsoft-board/board_posts --database=g7_bench --seed=200000

# 기계 판독 / 리포트
php artisan g7:bench --axis=list --json
php artisan g7:bench --all --allow-write --report
php artisan g7:bench --all --allow-write --report=C:/tmp/before.md
```

`g7:bench:pagination` 은 이전 이름의 별칭으로 남아 있어 그대로 호출된다 (옵션은 `--profile=` 기준).

## 안전장치

| 장치 | 동작 |
| ------ | ------ |
| `--allow-write` | 데이터를 변경하는 축(`write`/`batch`/비-GET `screen`)은 이 플래그 없이 실행을 거부 |
| 롤백 트랜잭션 | `screen`/`write` 축은 계측 계정 생성부터 요청·저장 처리까지 롤백되는 트랜잭션 안에서 실행 → 운영 DB 에서도 잔여 데이터가 남지 않음 |
| 운영 환경 시딩 거부 | `--seed`/`--fresh` 는 `production` 에서 거부 |
| `--database=` | 계측용 DB 지정. config 가 캐시된 환경에서 연결을 바꿀 유일한 수단 |

트랜잭션이 열려 있으면 Laravel 이 읽기 쿼리도 write PDO 로 보내므로(`Connection::getReadPdo` 의 `transactions > 0` 분기), 읽기/쓰기 분리 환경에서도 계측 요청이 임시 계정을 인증할 수 있다.

`batch` 축은 트랜잭션으로 감싸지 않는다 — 배치 커맨드는 내부에서 커밋하거나 DDL 을 실행할 수 있고, 대량 처리를 긴 트랜잭션에 담으면 락 보유 시간이 계측 자체보다 위험해진다.

## 리포트

`--report` 는 마크다운 리포트를 `storage/app/benchmarks/` 에 저장한다 (`--report=<경로>` 로 임의 경로 지정). 이 디렉토리는 Git 미추적이다 — 계측값은 실행 머신·DB 버전·OPcache 여부에 종속되므로 저장소에 축적하면 서로 비교 불가능한 수치가 섞인다.

리포트는 환경 정보(APP_ENV, DB 버전, PHP 버전, OPcache, `config:cache`, 실행 머신)와 실행 조건을 축별 표 앞에 함께 적는다. **문서에 옮길 수치는 이 산출물에서만 옮긴다** — 눈대중·기억으로 적은 수치는 어느 환경에서 나온 값인지 확인할 수 없다.

측정하지 못한 프로파일은 목록에서 빠지지 않고 사유와 함께 남는다. 전부 건너뛴 실행은 종료 코드 1 로 끝난다 (성공으로 보고하면 "측정했다"로 읽힌다).

## 관련 문서

- [service-repository.md](service-repository.md) — 목록 조회 컬럼 프루닝과 지연 조인
- [cheatsheet.md](../cheatsheet.md) — 커맨드 요약
- [hooks.md](../extension/hooks.md) — 확장 선언 훅 일반
