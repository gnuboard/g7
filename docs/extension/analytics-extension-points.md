# 방문자 통계 확장 기여 지점

> 모듈·플러그인이 코어 방문자 통계에 전환(목표)·분류 기준(dimension)·집계 행·판정표·제외 규칙·개인정보
> 처리·출력 열을 보태는 방법입니다. 설계 전반은 [방문자 통계](../backend/analytics.md), 브라우저
> API 는 [방문자 통계 트래커](../frontend/analytics-tracker.md) 를 봅니다.

---

## TL;DR (5초 요약)

```text
1. 전환·분류 기준은 먼저 선언한다 — 진입 클래스의 getAnalyticsGoals()·getAnalyticsDimensions()
2. 선언은 활성 확장에서 매번 모은다 — 확장을 끄면 선언은 사라지고 쌓인 데이터는 남는다
3. 선언 밖의 목표·분류 기준은 버리고 unknown_goal·unknown_dimension 으로 실패 목록에 남긴다
4. 판정표 필터는 받은 목록에 보태서 돌려준다(뺀 항목은 판정에서 빠진다) · 출력 훅은 코어 값을 덮지 못한다
5. 개인정보 삭제는 코어와 같은 트랜잭션에 참여한다 — 던지면 코어 삭제도 되돌아간다
6. 동의는 코어 동의 제공자 계약에 묻는다 — 방문자 통계 전용 동의 훅은 없다
```

---

## 전환(목표) 선언

```php
// modules/_bundled/sirsoft-ecommerce/module.php (예시 — 번들 확장에 아직 적용되지 않음)
public function getAnalyticsGoals(): array
{
    return [[
        'key' => 'order_completed',                      // 영소문자·숫자·밑줄 1~50자
        'label' => ['ko' => '주문 완료', 'en' => 'Order completed'],
        'description' => ['ko' => '결제가 승인된 시점', 'en' => 'When payment is approved'],
        'category' => 'purchase',                         // purchase | signup | engagement | custom
        'dedupe' => 'once_per_visit',                     // every_time | once_per_visit | once_per_visitor
        'attributes' => [                                 // 기록 때 코어가 이 스키마로 검증한다
            'revenue' => ['type' => 'decimal', 'required' => true, 'min' => 0],
            'currency' => ['type' => 'enum', 'required' => true, 'values' => ['KRW', 'USD', 'JPY']],
            'label' => ['type' => 'string', 'max' => 255],
        ],
        'attribution_window_days' => 30,                  // 표시용 메타데이터(아래 한계)
        'dimensions' => ['category_id'],                  // 함께 볼 자기 분류 기준 키
    ]];
}
```

- 기록 키는 `{식별자}.{key}` 입니다(`sirsoft-ecommerce.order_completed`). 코어 목표는 `core.{key}` —
  회원가입(`core.user_registered`, 방문자당 1회)·로그인(`core.user_logged_in`, 방문당 1회) 두 건이
  `config/analytics.php` 에 같은 스키마로 있습니다. 코어 목표는 **서버만** 기록합니다 — 브라우저 비콘으로
  온 코어 목표는 버립니다(방문자가 콘솔에서 가입 수를 부풀리지 못하게).
- 속성 `type` 은 `decimal`·`int`·`string`·`enum`·`bool`. 스키마에 없는 속성은 버립니다. `revenue`·
  `currency`·`label` 은 전환 원장의 칸으로 가고, 통화는 대문자 3자리가 아니면 비웁니다.
- 중복 규칙은 코어가 판정합니다 — 확장이 중복 방지를 직접 구현하지 않습니다.

기록은 브라우저 `G7Core.analytics?.trackGoal(key, attrs)`(수집이 꺼져 있으면 전역이 없다) 또는 서버 기록기입니다.

```php
use App\Contracts\Analytics\ConversionRecorderInterface;

// 결제 승인 리스너 안에서 — 현재 요청의 쿠키·IP 로 방문을 찾아 붙는다. 예외를 던지지 않는다.
app(ConversionRecorderInterface::class)->record('sirsoft-ecommerce.order_completed', [
    'revenue' => $order->total_amount,
    'currency' => $order->currency,
    'label' => $order->order_number,
]);
```

서버 기록기는 **요청 안에서** 불러야 합니다(리스너라면 `'sync' => true`). 큐 워커에는 방문을 찾을
요청이 없습니다. 전환이 일어난 화면 주소는 같은 사이트 `Referer` 로 잡습니다.

## 분류 기준 선언

```php
// modules/_bundled/sirsoft-board/module.php (예시)
public function getAnalyticsDimensions(): array
{
    return [[
        'key' => 'board_id',
        'scope' => 'action',              // action: 액션마다 · visit: 방문에 한 번(나중 값이 이긴다)
        'label' => ['ko' => '게시판', 'en' => 'Board'],
        'value_type' => 'int',            // int | string | enum(values 필수)
        'max_cardinality' => 500,         // 하루 집계에 남길 라벨 수(방문 많은 순). 상한 10000
        'label_resolver' => 'resolveBoardLabels',
        'pii' => false,                   // true 면 집계하지 않고(원장에만), 익명 모드에서 싣지 않고, 소급 익명화 때 지운다
    ]];
}

/**
 * 게시판 번호를 이름으로 풉니다 (리포트 label_display 용).
 *
 * @param  array<int, string>  $values  원값 목록
 * @return array<string, string> 원값 → 이름
 */
public function resolveBoardLabels(array $values): array
{
    // 게시판 이름은 로케일 맵(JSON 캐스트)이라 문자열로 바로 바꿀 수 없다 — 현재 로케일 이름을 고른다.
    return Board::whereIn('id', $values)->get()
        ->mapWithKeys(fn (Board $board) => [(string) $board->id => $board->getLocalizedName()])
        ->all();
}
```

- 값 주입: 브라우저 `G7Core.analytics?.setDimension('sirsoft-board:board_id', '12')` 또는 서버 필터
  `core.analytics.resolve_dimensions`(인자 `($dims, $dto)`). 짧은 키(`board_id`)는 그 키를 선언한
  확장이 하나일 때만 받습니다. 브라우저에서 넣은 값은 그 화면의 비콘에만 실리고 다른 화면으로
  이동하면 비워집니다 — 화면이 뜰 때마다 다시 넣습니다.
- 원장에는 `{식별자}:{key}` 로, 집계에는 `ext:{식별자}:{key}` 분류 기준 행으로 남습니다. 리포트는
  `GET /api/admin/analytics/reports/ext:sirsoft-board:board_id` — 관리자 › 방문자 통계 ›
  검색어·다운로드·전환 › 「확장별 분류」 탭이 등록 목록에서 고르게 합니다.
- 라벨 풀이 함수가 던지면 원값을 그대로 보입니다. 확장이 꺼져 있으면 풀이도 없습니다.

## 잘못된 선언

선언이 틀린 항목은 **조용히 버리지 않습니다** — 그 항목만 사유와 함께 등록부의 `warnings()` 로
드러내고 나머지는 살립니다. 그 사유는 진단 응답 `declaration_warnings` 로 실려 **수집 진단 화면 맨 위 경고 줄**에
한 줄씩 보입니다. getter 가 던지거나 한 항목의 필드 형식이 어긋나도(문자열 자리에 배열 등) 그
항목만 빠지고 다른 선언·확장과 코어 목표는 그대로입니다. 목표·분류 기준이
왜 안 쌓이는지 모르겠으면 수집 진단 화면 「최근 적재 실패」의 「미등록 목표」(`unknown_goal`)·
「미등록 분류 기준」(`unknown_dimension`)·「형식 오류」(`validation`) 사유부터 봅니다.

## 생애주기

| 사건 | 선언 | 이미 쌓인 데이터 |
| --- | --- | --- |
| 비활성화 | 등록부에서 빠진다. 새 기여는 `unknown_*` 로 거부 | 보존 — 과거 리포트 수치가 남는다 |
| 삭제 + 「데이터도 삭제」 | 동일 | 전환·그 확장 목표의 전환 집계·`ext:` 분류 기준 집계 제거, 진단 실행 이력 `extension_purge` |
| 삭제 + 데이터 보존 | 동일 | 보존. 라벨은 원값으로 보인다 |

## 집계 기여 — 자기 기준의 행을 얹을 때

등록된 분류 기준은 코어가 알아서 집계합니다. 원장에 없는 자기 기준(예: 주문 표에서 분류별 매출)을
집계 표에 얹으려면 `core.analytics.archive.day` 를 **`'sync' => true`** 로 구독합니다.

```php
public static function getSubscribedHooks(): array
{
    return [
        'core.analytics.archive.day' => ['method' => 'contribute', 'priority' => 20, 'sync' => true],
    ];
}

public function contribute(array $payload): void
{
    // $payload = ['date' => 'Y-m-d', 'collector' => ArchiveCollector, 'is_final' => bool, 'run_type' => string]
    // date 는 사이트 시간대(관리자 환경설정) 기준 날짜다 — 자기 표를 UTC 시각으로 셀 때는 그날의
    // 사이트 시간대 0시~24시를 UTC 로 바꿔 거른다(whereDate 로 세면 시간대만큼 다른 하루가 얹힌다).
    foreach ($this->salesByCategory($payload['date']) as $row) {
        $payload['collector']->addDimensionRow('ext:sirsoft-ecommerce:category', $row->name, 'guest', [
            'visits' => $row->visits,
            'hits' => $row->orders,
            'extra' => ['revenue_cents' => $row->revenue_cents],
        ]);
    }
}
```

집계는 「지우고 넣기」라 집계 표에 직접 쓰면 다음 집계가 지웁니다. 큐로 구독하면 수집기가 사본이
되어 행이 돌아오지 않고, 그 순간 경고 로그가 남습니다. 리포트 API 로 읽으려면 같은 이름의 분류 기준을
선언해 두어야 합니다(등록도 집계도 없는 `ext:` 이름은 404, 확장을 끈 뒤에도 쌓인 집계는 읽힙니다).
분류 기준 이름은 소문자·앞뒤 공백 없는 꼴로 맞춰 저장합니다(집계 표가 대소문자·끝 공백만 다른 이름을
같은 이름으로 보기 때문). 같은 라벨·방문자 유형으로 두 번 넘긴 행은 수집기가 합산하고, 코어가 원장에서 이미 집계한 등록
분류 기준과 겹치는 행은 코어 행을 남기고 버리며, 집계 표 열 폭(310자)을 넘는 분류 기준 이름은 경고 후
버립니다. 지표는 음수면 0, 열이 담는 최댓값(방문·액션·진입·이탈·이탈 방문 4,294,967,295)을 넘으면
경고 후 최댓값으로 넣고, `extra` 의 깨진 UTF-8(한글을 바이트로 자른 문자열 등)은 대체 문자로
바꿉니다 — 행 하나 때문에 그날 집계가 실패하지 않습니다.

## 판정·제외·개인정보·출력 훅

전부 필터입니다. 판정표(검색엔진·캠페인·추적 파라미터·UA)는 반환값이 그대로 쓰이므로 **받은 목록에 보태서** 돌려줍니다 —
받은 항목을 빼면 코어 항목도 판정에서 빠집니다. 제외·출력·개인정보 훅은 코어 값을 덮지 못합니다. 페이로드 표는 [hooks.md](hooks.md#방문자-통계-확장-기여-훅-페이로드).

| 무엇을 | 훅 | 주의 |
| --- | --- | --- |
| 검색엔진·캠페인 파라미터·추적 파라미터·UA 패턴 | `core.analytics.search_engines` · `campaign_params` · `stripped_query_params` · `ua_rules` | UA 판정은 앞에서부터 — 인앱 브라우저는 앞에 끼운다. 이름은 브라우저·OS 50자, 검색엔진 100자까지 저장된다(넘치면 잘리고 경고 로그) |
| 방문 제외 | `core.analytics.exclusion_rules` + `registered_exclusion_reasons` | 코어 판정(봇·DNT·수집 거부)을 통과한 요청에만 불린다. 등록한 사유로만 제외된다 |
| 정보주체 삭제 | `core.analytics.subject_delete` | 코어와 같은 트랜잭션. 코어 원장에 방문이 없어도 불린다. 운영자가 고른 기간(시작일·종료일, 없으면 `null`)을 받으니 방문 ID 로 묶이지 않는 표는 그 기간만 지운다 |
| 정보주체 내보내기 | `core.analytics.subject_export` | 파일의 `extensions.{식별자}` 로 실린다 |
| 전체 삭제 | `core.analytics.purge_all` | 코어보다 먼저, 트랜잭션. 던지면 코어 삭제 전에 멈춘다 |
| 소급 익명화 | `core.analytics.anonymize_attributes` | 방문마다 불린다 — 자기 표의 같은 방문 기록도 이때 익명화한다 |
| CSV 열 | `core.analytics.csv_columns` · `csv_row` | 코어 아홉 열 이름은 받지 않는다 |
| 진단·실시간·저장소 | `core.analytics.diagnostics_sections` · `realtime_fields` · `storage_tables` | 응답의 `extensions` 칸에 붙는다 |

수집 요청 안에서 도는 세 훅(`exclusion_rules`·`resolve_dimensions`·`after_track`)은 확장이 던져도
방문을 버리지 않습니다 — 그 확장의 기여만 빠진 채(제외하지 않음·분류 값 그대로·알림 없음) 적재가 이어지고,
수집 진단의 실패 목록에 사유 `listener_exception` 과 훅 이름이 남습니다. 확장 하나의 결함이 사이트 통계
전체를 멈추지 않게 하려는 것입니다. 반대로 개인정보 훅(`subject_delete`·`purge_all`)은 던지면 코어
작업도 멈춥니다 — 한쪽만 지워지고 완료로 보이는 것이 더 나쁘기 때문입니다.

## 동의 — 코어 동의 제공자 계약의 소비자

방문자 통계는 동의를 직접 관리하지 않고, 어느 확장이 동의를 관리하는지도 모릅니다. 서버는 코어 동의 제공자 계약
(`App\Extension\Consent\ConsentManager`)에 묻고, 브라우저 트래커는 코어 동의 런타임을 읽습니다. 동의를 관리하는
확장은 방문자 통계에 따로 연결하지 않고 [동의 제공자](consent-provider.md)로 등록하면 됩니다.

| 판정 | 규칙 |
| --- | --- |
| 분석 동의 | 스냅샷이 `ready` 이고 재동의 대상이 아니며 `analytics` 에 동의 |
| 식별자(IP, 방문자 쿠키) 추적 허용 | 분석 동의이면서 `functional` 에도 동의 |
| 판단할 수 없음 (`unknown`, 제공자 모호나 누락, 조회 실패) | 동의하지 않은 것으로 본다 |
| 동의 관리 제공자 없음 (`none`) | 동의 연동 모드면 익명 모드로 내려간다 |

- 게스트의 동의 식별자는 수집 요청을 조립할 때 `ConsentManager::subjectKey()` 로 묻고, 답이 없으면 라라벨 세션 ID 를 씁니다.
- 동의 철회는 코어 액션 `core.consent.changed` 로 받습니다. 바뀐 카테고리에 `analytics => false` 가 실리면 수집 진단의
  계수 `consent_revoked` 를 하나 올립니다. 이미 쌓인 기록은 지우지 않습니다. 지난 기록 삭제는 정보주체 요청이 따로 처리합니다.
- 예전의 필터 `core.analytics.visitor_has_consent`, `core.analytics.tracking_permission`, `core.analytics.consent_session_id` 와
  액션 `core.analytics.consent_revoked` 는 없어졌습니다. 같은 일은 동의 제공자 계약이 합니다.

## 화면 조각

통계 화면 11면(개요·페이지·유입·환경·지역·패턴·검색어·다운로드·전환·실시간·봇·방문자·진단)과 대시보드 카드에
레이아웃 확장 지점이 있습니다 — 이름은 `analytics_{화면}_widgets`(개요는 `analytics_overview_widgets`,
대시보드는 `admin_dashboard_analytics`). 데이터는 확장 자기 API 로 읽습니다. 진단 화면 조각은
진단 응답의 `extensions.{식별자}` 를 읽으면 됩니다.

## 이벤트 이름 — 선언 없이 세고 싶을 때

선언 없이 행동 이름만 세고 싶으면 브라우저에서 `G7Core.analytics.trackEvent('coupon_click')` 을 부릅니다. 이름이
액션 이름 사전의 `event` 종류로 저장되고, 관리자 「검색어·다운로드·전환 → 이벤트」 탭(리포트 `event_name`)이 이름별
방문·횟수와 행 끝 추이 그래프를 보여 줍니다. 공개 수집 API 로 들어오는 자유 문자열이라 하루 500종까지만 집계합니다 —
이름에 회원 번호·주문 번호처럼 끝없이 달라지는 값을 넣지 마세요(상위 500종 밖은 그날 집계에서 빠집니다). 속성은
저장하지 않으므로, 값으로 갈라 보고 싶으면 분류 기준을, 전환율·매출로 보고 싶으면 목표를 선언합니다.

## 한계

- `attribution_window_days` 는 선언·표시용입니다. 코어는 여러 방문에 걸친 귀속을 계산하지 않고,
  전환은 그 전환이 일어난 방문의 유입에 붙습니다.
- 확장 설정은 확장 자기 저장소에서 읽습니다 — 통계 설정 묶음에 확장 키를 싣는 자리는 없습니다.
- 기간 프리셋(오늘·7일 등)과 알림 본문은 확장이 늘릴 수 없습니다.
