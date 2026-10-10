# 백엔드 일반 규칙

루트 AGENTS.md 의 절대 금지 패턴 중 **백엔드 일반 규칙** 영역. 표의 왼쪽은 금지, 오른쪽이 올바른 사용이다.

## TL;DR (5초 요약)

```text
1. 백엔드 핵심 원칙
2. 데이터베이스 규칙
3. 설정 주입과 `.env` 우선
4. 입력 크기에 비례해 커지는 메모리는 PHP 기본 한계 안에서 잰다
5. 통화 단위는 설정이 정한다 / 보조 통화 줄
```

> 원본: 이 규칙들은 2026-09-10 까지 루트 AGENTS.md 「CRITICAL RULES」 에 있었다. 제목·표는 그대로 옮겼고 앵커는 바뀌지 않았다. 색인: [docs/rules/README.md](README.md)

## 백엔드 핵심 원칙

> 상세: [docs/backend/](../backend/) | [database-guide.md](../database-guide.md)

```text
절대 금지: Service 클래스에 검증 로직 구현 → FormRequest + Custom Rule 사용
절대 금지: FormRequest authorize()에서 인증/권한 로직 → permission 미들웨어 사용
필수: __() 함수를 사용한 다국어 처리
필수: 상태/타입/분류는 Enum으로 정의
절대 금지: 인증 필요 미들웨어를 append()로 전역 등록 → appendToGroup('api') 사용
절대 금지: 로케일 하드코딩 → config('app.supported_locales') 사용
주의: ResponseHelper::success($messageKey, $data) — 메시지가 첫 번째 인수
```

갱신값이 항상 필터 소속을 유지해 안전한 경우(예: `whereNotNull` + 갱신값이 항상 non-null)만 예외이며, 그 근거를 코드 주석에 남긴다. 자동 검증이 이 패턴을 검출한다.

> 상세 규칙 (API 리소스, ServiceProvider, validation, 인증, 활동 로그 등): [docs/backend/](../backend/) 각 문서 참조

### 컨트롤러 계층

```text
BaseApiController (최상위)
├── AdminBaseController (관리자 전용)
├── AuthBaseController (인증된 사용자)
└── PublicBaseController (공개 API)
```

### 파사드 사용

```text
✅ use Illuminate\Support\Facades\Log; → Log::info()
❌ \Log::info(), auth()->user() 금지
```

## 데이터베이스 규칙

```text
절대 금지: DB CASCADE에 의존한 삭제 → Service에서 명시적 삭제 (훅/파일/로깅 보장)
필수: 마이그레이션 한국어 comment 필수, down() 구현 필수
필수: FK 컬럼의 ->comment() 는 ->constrained()/->references()/->on() 앞에 둔다 (뒤에 두면 comment 가 컬럼이 아닌 FK 정의에 부착되어 조용히 사라진다)
필수: 소스 교정만으로는 기설치본이 낫지 않는다 — 마이그레이션은 재실행되지 않으므로 업그레이드 스텝 백필을 함께 작성
필수: 재실행 가드는 컬럼뿐 아니라 인덱스·외래키까지 up() 의 전 DDL 단계에 건다 — 컬럼만 가드하면 인덱스 단계 실패 후 재실행이 영구 실패한다
필수: 필터가 걸린 쿼리를 순회하며 그 행을 update/delete 하면 chunkById() (키셋 순회)
절대 금지: 그 경우 chunk()/each()/lazy() 사용 — OFFSET 기반이라 처리된 행이 결과에서 이탈한 만큼 커서가 밀려 미처리 행을 조용히 건너뛴다 (250건/청크 100 → 100건 누락, 예외·로그 없음)
```

MySQL 은 DDL 을 롤백하지 않는다. 한 마이그레이션이 `컬럼 → 인덱스 → 외래키` 를 함께 만드는데 인덱스 단계에서 실패하면 컬럼은 만들어진 채 남고, 재실행 시 컬럼은 가드에 막혀 건너뛰지만 인덱스가 다시 시도되어 `Duplicate key name` 으로 또 실패한다 — 그 설치본은 재실행으로 복구되지 않는다. 오류 메시지가 첫 실패와 달라져 원인을 컬럼 쪽에서 찾게 되는 것도 이 결함의 특징이다. 자동 검증이 컬럼 축과 인덱스·외래키 축을 각각 검출한다.

> 상세: [database-guide.md](../database-guide.md)

## 운영자가 지울 수 있는 어휘의 필터 게이트

목록 필터의 허용 어휘가 **Enum 이 아니라 운영자 설정**에서 나오는 축이 있다 — 통화, 결제수단, 사유 카탈로그처럼 운영자가 추가하고 **내릴 수 있는** 것들이다. 그런데 그 값으로 기록된 행은 내려도 남는다. 거래 시점 통화처럼 **스냅샷으로 동결**된 값이면 더더욱 남는다. 그래서 게이트가 현재 설정만 보고 판정하면, 그 값으로 기록된 행이 **어떤 필터 조합으로도 도달 불가**가 되고, 그 값이 실린 북마크·뒤로가기 URL 은 목록 전체를 422 로 만든다.

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| `Rule::in(현재 설정 어휘)` 단독 | `설정 어휘(등록 ∪ 내린 기본 제공) → 실제 기록값` 2단 — 설정 어휘를 벗어난 값에서만 저장소를 조회한다 |
| 기록 조회를 **저장소 필터와 다른 컬럼**에서 수행 | 저장소가 그 필터로 거르는 **같은 컬럼**을 본다 (유형마다 컬럼이 다르면 유형별로) |
| 어휘 검사를 없애고 전부 통과 | 어디에도 쓰인 적 없는 값은 **여전히 422** — 오타가 "결과 없음" 으로 위장하면 운영자는 필터가 걸린 줄 안다 |
| FormRequest 클로저에서 모델 직접 조회 | Repository Interface 에 `hasRecorded*()` 를 두고 규칙은 `Rule::in` 선언형 유지 (메시지 키가 살아 있어야 안내가 다국어로 나간다) |
| 같은 화면의 어휘 축마다 다른 강도 | 형제 축은 같은 2단 규칙 — 한쪽만 길이 검사(`string\|max:50`)면 그 축의 오타는 조용히 0건이 된다 |
| tombstone(내린 기본 제공 목록)만 믿기 | 운영자가 **직접 추가한 뒤 삭제한** 값은 tombstone 이 남지 않는다 — 마지막 근거는 언제나 실제 기록값이다 |

정상 조합에는 추가 쿼리가 없다(설정 어휘 안이면 1단에서 끝난다). 이 결함은 예외도 로그도 남기지 않는다 — 목록이 통째로 422 가 되고 화면에는 직전 목록이 그대로 남는 것이 유일한 증상이다.

> 상세: [validation.md](../backend/validation.md) "운영자가 지울 수 있는 어휘의 필터 게이트"

## 설정 주입과 `.env` 우선

관리자 환경설정(`storage/app/settings/*.json`)은 `SettingsServiceProvider` 를 거쳐 `config()` 에 주입되어 `.env` 유래 값을 덮는다. `.env` 를 배포 기준값으로 관리하는 설치(컨테이너·IaC·다중 서버)를 위해 그 소유권을 **키 단위**로 되돌리는 옵트인 스위치(`G7_ENV_PRIORITY`)가 있고, 판정은 `App\Support\EnvPriority` 가 단독으로 소유한다.

| 금지 | 올바른 사용 |
|--------|---------------|
| `SettingsServiceProvider` 에 settings → config 주입을 추가하면서 `EnvPriority::MAP`(또는 `EXEMPT`) 미등재 | 맵을 동반 갱신 — 등재를 잊으면 그 키는 영원히 settings 승으로 남고, 화면상 "그 키가 `.env` 에 없다"와 구분되지 않는다 |
| env 명시 여부를 런타임 `env()` 로 판별 | config 빌드 시점 캡처(`config/env-priority.php`, `disk_explicit` 패턴) — `config:cache` 에서 `env()` 는 null 이라 판정이 영구 미발동한다 |
| 잠긴 키 표시값에 sensitive 값(`.env` 비밀값) 노출 | 잠금 표시만 — 민감 키는 유효값 오버레이 대상에서 제외한다 |
| 제거된 키의 **부재를 값으로 읽는 지점**을 그대로 둠 (게이트·기본값 주입·형제 폴백) | 그 자리는 유효값(런타임 config)으로 보정하거나 잠금일 때만 건너뛴다 — `array_key_exists`/`empty()` 로 판정하면 "저장값이 없는 호출"과 "잠겨서 제거된 호출"이 구분되지 않는다 |

명시 판별은 strict 다 — `KEY=`(빈 값)·`KEY=null`·미설정만 미명시이고, `APP_DEBUG=false`·`REDIS_DB=0` 같은 falsy 명시는 명시로 취급한다(`?:` 를 쓰면 그 값들이 미명시로 오판된다).

잠금은 **그 키의 주입만** 건너뛰는 것이다. 그런데 제거된 키를 읽는 자리가 그 부재를 값으로 해석하면 잠금이 형제 설정까지 무너뜨린다 — 다섯 형태가 있고 전부 오류도 로그도 남기지 않는다: ① 마스터 토글의 OFF 강제(웹소켓) ② 게이트가 되는 키(디버그 모드 → 로그 레벨 강제·프록시 적용, 메일 드라이버 → mailgun/ses 하위 주입) ③ 저장값이 비어도 박히던 기본값(`services.{mailgun.endpoint,ses.region}`) ④ 형제 값으로의 폴백(웹소켓 server endpoint 가 관리자 소유 client 값을 `.env` 자리에 덮어씀) ⑤ 파생 판정(`storage_driver=s3` → `attachment.disk`). 판정에 쓰는 자리는 유효값으로 보정하고, 주입을 건너뛰어야 하는 자리는 `EnvPriority::isLocked()` 를 직접 묻는다.

> 상세: [admin-settings-access.md](../backend/admin-settings-access.md) "env 우선 모드(G7_ENV_PRIORITY)"

## 입력 크기에 비례해 커지는 메모리는 PHP 기본 한계 안에서 잰다

브라우저에서 잘 돌던 알고리즘·상수를 PHP 로 옮길 때 시간 상한(O(n·m) 가드)만 함께 오고 **메모리 상한은 오지 않는다.** PHP 배열은 원소당 수십 바이트라 (줄 수)² 표는 2,350줄에서 약 150MB 이고, 운영 서버의 기본 `memory_limit` 은 128M 이다. 개발 머신(512M)에서는 통과하고 서버에서만 500 이 되며, 예외는 `FatalError` 한 줄뿐이라 어느 요청의 어떤 입력이었는지 로그에 남지 않는다.

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| 카운트·길이만 쓰는 LCS/DP 에 전체 표 + backtrack | 두 행(또는 한 행) DP 로 길이만 구한다 — 추가 = 새 줄 − LCS, 삭제 = 옛 줄 − LCS 라 숫자가 같다 |
| 브라우저 구현의 임계값(`DIFF_MAX_LINES` 등)을 그대로 이식하고 "가드가 있다" 고 간주 | 그 임계에서의 PHP 메모리를 실측하고 128M 아래인지 확인 — 임계가 시간 축만 막는 경우가 있다 |
| "인접 버전 비교는 변경 영역이 작다" 는 가정으로 최악 경로를 비워 둠 | 변경 영역은 양끝이 동시에 바뀌면 파일 전체다 — 편집기가 `comment` 키를 떼어내는 첫 저장이 정확히 그 형태 |
| 메모리 회귀 테스트를 "통과했다" 로만 잠금 | `memory_get_peak_usage()` 증가량 상한을 단언하고, 수정 전 값(146MB)을 테스트 메시지에 남긴다 |

> 상세: [service-repository.md "입력 크기에 비례하는 메모리"](../backend/service-repository.md)

## 통화 단위는 설정이 정한다

G7 은 **기본 통화**(상품·쿠폰·배송비 저장 기준), **표시 통화**(구매자가 고른 통화), **결제 통화**(PG 청구 통화)를 각각 따로 설정한다. 셋은 같을 수도, 모두 다를 수도 있다. 금액을 다루는 지점이 특정 통화를 전제하면 값은 맞고 **단위만 틀린** 금액이 나가며, 예외도 경고도 없다.

| 금지 | 올바른 사용 |
|--------|---------------|
| 다국어 문구에 `:amount원` / `:amount円` | 문구는 `:amount` 로 중립, 호출부가 `ecommerce_format_price($amount, $currency)` 로 포맷해 전달 |
| 레이아웃에서 `{{금액.toLocaleString()}}원` 조립 | 서버가 준 `*_formatted` / `multi_currency_*[통화].formatted` 를 그대로 출력 |
| `formatCurrencyPrice($price, 'KRW')` (통화 코드 리터럴) | `formatBaseCurrency()` / `formatOrderCurrency()` (설정·주문 스냅샷이 통화를 정한다) |
| `number_format($amount).'원'` | 같은 도메인의 통화 인지 헬퍼(`formatOrderChargeAmount()` 등) 경유 |
| `_global.preferredCurrency ?? 'KRW'` | `_global.preferredCurrency ?? _global.defaultCurrency` (둘 다 없으면 `*_formatted` 로 내려간다) |
| 통화표를 코드에 고정(기호·자릿수 5종 표 + 특정 통화 폴백) | 설정의 `symbol` / `decimal_places` 를 읽고, 미설정 시에만 폴백표 |
| `code === 'KRW' ? 0자리 : 2자리` 식 코드 분기 | `decimal_places` 로 판정 (운영자가 추가한 0자리 통화도 포함) |
| 통화 선택 입력의 기본값을 `"KRW"` 로 시드 | 설정의 `default_currency` — 마일리지처럼 **통화별 원장**을 쓰는 도메인은 표시가 아니라 **기록이 틀어진다** |

언어는 통화가 아니다. 한국어 문구에 `원`, 일본어에 `円` 을 박으면 기본 통화가 다른 상점에서 UI 언어가 통화를 결정하게 된다 — 영어 문구가 `:amount` 로 중립인 것이 정답이다.

주문·결제·환불 금액은 **거래 시점 통화로 동결**한다(`currency_snapshot.base_currency`). 운영자가 이후 기본 통화를 바꿔도 과거 주문의 표기는 불변이어야 한다.

동결 대상은 환율만이 아니다 — **소수 자릿수·절사 규칙·환산 분모(base_unit)까지 스냅샷이 SSoT** 다. 이 값들을 현재 설정에서 조회하면, 운영자가 그 통화를 삭제하는 순간 설정에서 사라져 폴백(자릿수 2)이 적용된다. 소수 0자리 통화의 과거 주문 표기가 `¥14,835` → `¥14,835.00` 으로 바뀌고, 3자리 이상으로 설정했던 통화는 표시 금액이 절사된다. 금액 계산은 스냅샷을 쓰는데 표기만 현재 설정을 따라가면 같은 화면 안에서 근거가 갈린다.

| ❌ 금지 | ✅ 올바른 사용 |
| --- | --- |
| 주문·환불 표시에서 `getDecimalPlaces($code)` 를 스냅샷 없이 호출 | `getDecimalPlaces($code, $currencySnapshot)` — 스냅샷이 있으면 그것이 우선 |
| 리소스가 주문 스냅샷을 자식에게 전파하지 않음 | `withOrderCurrency()` 를 전파하는 지점마다 `withCurrencySnapshot()` 도 함께 전파 |
| 상품·카탈로그 표시까지 스냅샷으로 고정 | 현재 판매가는 **현재 설정**이 정답 — 스냅샷 없이 호출한다 |
| 자릿수를 박제하지 않은 구형 스냅샷에서 예외/0 자리 강제 | 박제값이 없으면 현재 설정 폴백을 그대로 탄다 (하위호환) |

> 상세: [api-resources.md](../backend/api-resources.md), [service-repository.md](../backend/service-repository.md)

## 보조 통화 줄은 바로 위 기준 통화 줄의 다른 표기다

다통화 상점의 금액 자리는 두 줄이다 — 위는 기준 통화, 아래는 나머지 통화(`mc_*`)를 나열한 줄.
아래 줄은 **위 줄과 같은 값의 다른 표기**여야 하는데, 읽는 필드나 표시 조건이 어긋나도 양쪽 다
정상 서식이라 응답에도 화면에도 흔적이 남지 않는다. 구매자만 두 금액이 다른 것을 본다.

| 금지 | 올바른 사용 |
| --- | --- |
| 기준 줄이 합계인데 `mc_*` 는 한쪽만 (환불 총액 = 현금+마일리지 ↔ `mc_refund_amount` 는 현금분만) | 합계를 서버가 만들어 내려보낸다 (`sumMultiCurrency()`) — 화면이 두 맵을 더하면 그 규칙이 화면마다 갈린다 |
| 기준 줄에 `if` 게이트가 있는데 `mc_*` iteration 에는 없음 | **같은 게이트**를 건다 — 기준 줄이 안 서는데 iteration 만 돌면 라벨 없는 `$0.00 ¥0 元0.00` 만 남는다 |
| 유형(교환/반품/환불/취소)마다 기준 줄의 필드가 갈리는데 `mc_*` 는 한 필드 고정 | 기준 줄과 **같은 분기**로 필드를 고른다 |
| 기준 줄은 있는데 `mc_*` 줄이 아예 없음 | 서버가 그 `mc_*` 를 이미 싣고 있으면 병기한다 (없으면 리소스에 노출부터) |
| 수량이 다른 소계를 원 주문의 `mc_subtotal_price` 로 (부분 반품·부분 교환) | 통화별 단가 × 그 클레임 수량 (`multiplyMultiCurrency()`) |
| 화면이 통화별 금액을 직접 곱하거나 더함 | 서버가 만든 맵을 그대로 나열만 한다 — 환율을 다시 보지 않는다 |

`mc_*` 를 **참조**하는 자리만 훑으면 "줄이 통째로 없는 자리"는 보이지 않는다. 모집단은 금액 줄
(`*_formatted`) 전수 × 서버의 `mc_*` 제공 여부로 잡는다.

> 정적 검사 불가 — "바로 위 줄" 이 무엇이고 그 줄의 게이트·필드가 같은지는 의미 판정 영역이다.

## 예외를 응답으로 바꾸는 자리

`catch (\Exception)` / `catch (\Throwable)` 는 **도메인 예외가 아닌 것**을 잡는 자리다. 여기서 4xx 를 돌려주면 인프라 장애·코드 결함이 "입력 오류" 로 위장되어 사용자는 고칠 수 없는 안내를 보고 운영자는 장애를 늦게 안다. 그리고 이미 번역된 `$e->getMessage()` 를 응답의 메시지 **키** 자리에 넘기면 키 해석에 실패해 원문(SQL 상태코드·경로 포함 가능)이 그대로 화면에 나간다. 둘 다 예외도 로그도 남기지 않는다.

| 금지 | 올바른 사용 |
|--------|---------------|
| generic catch 가 4xx 반환 | 5xx — 의도적 4xx 는 사유와 함께 판정 테스트의 상수에 선언 |
| generic catch 에서 상태코드 인자 생략 (`moduleError($mod,'key')`) | 상태코드 명시 — 생략 시 `ResponseHelper` 기본값 **400** 이 조용히 적용된다 |
| 도메인 예외를 typed 로 승격한 뒤에도 `catch (\RuntimeException)` 유지 | 승격한 예외로 좁힌다 — 도메인 예외의 **부모**를 잡으면 남는 것은 인프라 예외뿐인데 그것까지 4xx 가 된다 |
| `error($e->getMessage(), 422)` | `error($e->getMessageKey(), 422, null, $e->getMessageParams())` |
| 도메인 예외가 번역문만 들고 다님 | 생성자에 키+치환 파라미터 보관 → `getMessageKey()` / `getMessageParams()` |
| 서비스를 typed 예외로 승격하고 컨트롤러는 generic 만 유지 | 그 서비스 메서드를 호출하는 **컨트롤러 메서드 전수**에 typed catch 추가 (없으면 도메인 사유가 500 이 된다) |
| typed 예외 도입하면서 그 분기의 상태코드도 변경 | typed 는 **기존 상태코드 유지** — 예외 도입이 사용자 계약을 함께 바꾸면 회귀다 |
| 공개(비인증) 엔드포인트 응답에 예외 원문 포함 | 원문은 `Log::error` 로만 — 관리자 전용 면의 `errors` 페이로드는 진단 정보로 허용된다 |
| 원문을 직접 문자열로 조립해 노출 폭을 호출부가 정함 | 노출 폭은 `ResponseHelper` 가 정한다 — Throwable 을 넘기면 `app.debug` 에서만 펼쳐진다 |
| 치환 자리(`:error`)를 가진 키를 파라미터 없이 호출 | 넷째 인자 `messageParams` 로 채운다 — 비워 두면 번역기가 자리표시자를 **그대로 둔 문장**을 돌려줘 운영자 화면에 `:error` 가 노출된다 (실패했을 때만 드러나 정상 흐름 테스트로는 안 잡힌다) |
| 사유를 모른다고 치환 자리를 비워 두기 | 알 수 없으면 일반 문구(`errors.unknown_error`)로 채운다 |
| 원문을 싣지 않기로 한 문구에 `:error` 자리를 남겨 두기 | 그 키에서 **치환 자리 자체를 없앤다** — 자리를 남기면 나중에 예외 원문으로 채우는 회귀를 부른다 |
| 하위 계층이 `false`/`null` 만 돌려주고 실패 사유를 버림 | 사유를 반환 경로에 실어 올린다 (배열 키 `reason` 또는 **뒤에 붙인 선택적 out 파라미터**) — 기존 호출부를 깨지 않는다 |
| 확장 수명주기 훅이 사유 없이 `false` 반환 | `AbstractModule`/`AbstractPlugin` 의 `failWith(__('...'))` — 코어가 그 사유를 원인 자리에 싣는다 |

`message`(첫 인자)와 `errors`(셋째 인자)는 다른 통로다. **키 자리에 원문을 넘기는 것은 언제나 금지**지만, `errors` 페이로드의 원문은 금지 대상이 아니다 — `ResponseHelper::error` 가 문자열 `errors` 를 `500+` 비디버그에서만 차단하고 배열은 통과시키는 것은 `tests/Unit/Helpers/ResponseHelperTest.php` 가 고정한 의도다. 관리자에게 결제대행사·외부 시스템이 돌려준 사유를 감추면 조치 근거가 사라지고, 다국어 키는 유한해서 예상 못 한 실패를 담지 못한다. 판단 축은 "원문이냐 키냐" 가 아니라 **누구에게 / 무엇의 원문인가 / 어느 통로인가** 셋이다.

상세: [exceptions.md "예외 → 응답 매핑"](../backend/exceptions.md). `tests/Feature/Http/GenericCatchStatusCodeContractTest.php` 가 코어와 모든 번들 확장의 컨트롤러를 전수 스캔해 두 규칙을 고정한다. 판정기를 한 확장 안에 두면 그 확장 밖의 동형 결함이 검출되지 않는다. 치환 자리 축은 `tests/Feature/Http/ErrorMessageParamSubstitutionTest.php` 가 고정한다 — 호출부를 열거하지 않고 `->error(...)` 전수를 괄호 균형으로 잘라, 키를 실제로 번역해 `:error` 를 요구하는지 판정한다. 같은 판정기가 `new *OperationException(...)` 생성자 축도 덮는다(파라미터 배열이 넷째가 아니라 둘째 인자다). 이 축이 없으면 키를 들고 다니는 예외로 던지는 경로가 통째로 사각이 된다.

## Listener 데이터 접근

| 금지 | 올바른 사용 |
|------|------------|
| Listener 에서 `Model::query/find/where/create` 직접 호출 | Repository 인터페이스 주입 후 위임 |
| Listener 에서 `DB::table()->update(...)` | Repository 의 도메인 의도 메서드 (recalculate*/anonymize* 등) |
| Listener 에서 `$row->save()` / `saveQuietly()` / `delete()` | Repository 의 update/save/delete 호출 |
| Listener 생성자에 구체 Repository 직접 주입 | Repository Interface 주입 |
| Listener 에서 `request()` / `$_POST` 직접 접근 | Service 가 검증 후 도메인 객체로 전달 받기 |
| Filter 훅에 `'type' => 'filter'` 누락 | type 명시 필수 (반환값 무시 회귀 차단) |
| 실패 시 호출자 트랜잭션을 되돌려야 하는 Action 훅에 `'sync' => true` 누락 | 금전 이동(쿠폰 차감·복원, 적립금 차감·복원)은 `sync` 필수. 기본값은 큐 래핑 + `afterCommit` 이라 **커밋 뒤에** 실행되어, 예외를 던져도 롤백되지 않고 오류 응답만 나간 채 데이터가 남는다 (큐 드라이버가 `sync` 여도 동일) |
| 훅 회귀 테스트에서 리스너를 손으로 `addAction` 등록 | `HookListenerRegistrar::register()` 로 **실제 등록 경로**를 태운다 — 손으로 등록하면 큐 래핑을 건너뛰어 커밋 이후 실행 문제를 통과시킨다 |
| Listener 가 `HookListenerInterface` 미구현 (auto-discovery 대상) | implements + `getSubscribedHooks()` 정적 메서드 |

> 상세: [hooks.md "Listener 데이터 접근 규정"](../extension/hooks.md), [service-repository.md](../backend/service-repository.md)
