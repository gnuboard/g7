# 훅 시스템 (Hook System)

> G7의 훅 시스템은 WordPress 스타일로 구현되어 코어 수정 없이 비즈니스 로직을 확장할 수 있습니다.

---

## TL;DR (5초 요약)

```text
1. Action 훅: doAction() - 부가 작업 (로그, 알림, 캐시)
2. Filter 훅: applyFilters() - 데이터 변형 (type => 'filter' 필수!)
3. 네이밍: [vendor-module].[entity].[action]_[timing]
4. 우선순위: 1-5(높음), 10(기본), 15+(낮음)
5. Service 패턴: before_* → filter_* → 로직 → after_*
6. 공개 훅의 이름·인자 모양은 마이너에서 바꾸지 않는다 — 새 훅 추가 + 옛 훅 deprecated, 제거는 메이저
```

---

## 목차

- [개요](#개요)
- [훅 타입](#훅-타입)
- [훅 네이밍 규칙](#훅-네이밍-규칙)
- [리스너 구현](#리스너-구현)
- [코어 리스너 자동 발견](#코어-리스너-자동-발견)
- [우선순위 가이드라인](#우선순위-가이드라인)
- [모듈에 리스너 등록](#모듈에-리스너-등록)
- [Service에서 훅 사용 패턴](#service에서-훅-사용-패턴)
- [필터 훅 리스너 예시](#필터-훅-리스너-예시)
- [훅 권한 시스템](#훅-권한-시스템)
- [관련 문서](#관련-문서)

---

## 개요

G7의 훅 시스템은 WordPress 스타일로 구현되어 코어 수정 없이 비즈니스 로직을 확장할 수 있습니다.

**핵심 파일 위치**: `app/Extension/HookManager.php`

---

## 훅 타입

### 환경설정 검증 규칙과 라벨 계약

`core.settings.save_validation_rules`(규칙)와 `core.settings.save_validation_attributes`(라벨)는
코어 소유 필터이며 **짝으로 씁니다**. 각각 `(array $rules, SaveSettingsRequest $request)` ·
`(array $attributes, SaveSettingsRequest $request)` 를 받아 같은 형태로 반환합니다.

확장이 규칙만 더하면 그 필드의 오류 문구에 **원시 키가 그대로** 나갑니다(`x.y 필드는 …`) —
화면은 확장이 그렸는데 문구는 코어가 만들므로 운영자에게 고칠 자리가 보이지 않습니다.
라벨 값은 확장 네임스페이스 문구(`__('sirsoft-x::messages.…')`)를 씁니다. 확장 lang 은 코어
`validation.attributes` 경로에 합류하지 않습니다. 규율과 배경은 `docs/backend/validation.md`
「검증 문구의 필드 라벨」 절에 있습니다.

### 개인정보 통지 섹션 계약

`core.privacy.notice_sections`는 코어 소유 필터이며 개인정보 통지 소비자가 호출합니다.
입력은 `(array $sections, string $locale)`, 반환은 `array<array{title: string, body: string}>`입니다.
확장은 수신자 로케일에 맞춘 수집 항목·이용 목적·보유 기간 섹션을 추가합니다.
반환 텍스트는 소비자가 HTML 이스케이프합니다. 개인 식별정보를 포함하지 않습니다.
통지 플러그인 설치 여부와 관계없이 확장이 구독할 수 있으며 통지 발행자는 독립적으로 선택합니다.

### 동의 제공자 계약

방문자가 쿠키·추적에 무엇을 동의했는지는 **동의 관리 확장(제공자)** 이 답하고, 코어와 다른 확장은 코어 계약으로만 묻습니다.
코어는 어느 확장이 동의를 관리하는지 모르며, 코어 코드는 특정 확장의 식별자·클래스·API 경로·이벤트명을 참조하지 않습니다.
구현·소비 가이드 전문은 [동의 제공자 계약](consent-provider.md)입니다.

| 훅 | 유형 | 인자 → 반환 | 의미 |
| --- | --- | --- | --- |
| `core.consent.registered_providers` | filter | `(array $providers)` → `array<ConsentProviderInterface>` | 제공자 등록. 앞선 구독자의 항목을 지우지 말고 덧붙입니다 |
| `core.consent.changed` | action | `(string $providerId, ConsentSubject $subject, array<string, bool> $changes)` | 선택된 제공자가 알린 동의 변경(카테고리 → 새 동의 여부). 선택되지 않은 제공자의 알림은 코어가 버립니다 |

- 제공자는 `App\Contracts\Extension\ConsentProviderInterface` 를 구현합니다(`getId`·`getLabel`·`isAvailable`·
  `getCategories`·`resolveSubjectKey`·`getSnapshot`).
- 제공자가 여럿이면 운영자가 환경설정 > 개인정보 탭에서 고릅니다(설정 `privacy.consent_provider`, 빈 값은 자동).
  수집 중인 제공자가 하나면 자동으로 선택되고, 둘 이상인데 고르지 않았거나 고른 제공자가 사라지면 모든 방문자가 「모름」입니다.
- 브라우저는 코어 API `GET /api/consent` 의 스냅샷을 코어 동의 런타임(`_global.consent`, `G7Core.consent`)으로 받습니다.
  확장은 자체 조회·자체 window 이벤트를 두지 말고 코어 이벤트 `consent:changed` 를 구독합니다.
- 코어 방문자 통계도 이 계약의 소비자입니다(분석 동의 = `ready` 이면서 재동의 필요 없음 + `analytics`, 식별자 추적 = 분석 + `functional`).
  예전의 분석 전용 훅(`core.analytics.consent_session_id`·`visitor_has_consent`·`tracking_permission`·`consent_revoked`)과
  `GET /api/analytics/consent`, 브라우저 이벤트 `g7:analytics-permission-changed` 는 이 계약으로 대체되어 없어졌습니다.
- 스크립트 로드 지연: 동의 전 스크립트를 막는 확장은 `<script>` src setter 에서 코어 속성 `data-g7-script-deferred`
  를 붙입니다. 코어 `loadScript` 는 그 태그를 붙이지 않고 미완료(`false`)로 돌려주며 캐시하지 않습니다.

### 개인정보 보유기간 핸들러 계약

`core.privacy.retention_handlers`는 코어 소유 필터이며 보유기간 처리 진입점
(`App\Privacy\PrivacyRetentionManager`)이 호출합니다. 입력·반환 모두
`array<int, class-string>`(핸들러 클래스명 목록)이고, 각 클래스는
`App\Privacy\Contracts\PrivacyRetentionHandlerInterface`를 구현해야 합니다.

```php
HookManager::addFilter('core.privacy.retention_handlers', function (array $handlers) {
    $handlers[] = OrderRetentionHandler::class;

    return $handlers;
});
```

계약을 구현하지 않았거나 생성·식별자 조회(`id()`)에 실패한 클래스는 **기록 후 건너뜁니다** — 확장 하나의
잘못된 등록이 다른 도메인의 법정 파기를 함께 멈추면 안 되기 때문입니다. 반대로 조용히
버리지도 않습니다: 건너뛴 사실이 로그에 남지 않으면 운영자는 그 도메인이 처리되고 있다고
믿게 됩니다. 같은 `id()` 가 둘이면 먼저 등록된 쪽만 남습니다.

구현 시 지켜야 할 것:

- `isEnabled()`는 **자기 도메인 설정을 기본 false 폴백으로** 읽습니다. 확장을 설치했다는
  사실만으로 회원 데이터가 지워지면 안 됩니다.
- `process()`는 수집기가 이미 걸러 주더라도 **자기 토글을 다시 봅니다.** 되돌릴 수 없는
  파기라 호출자를 믿지 않습니다. 단 관리자 직권 실행(`process($dryRun, force: true)`)은 토글만
  건너뜁니다 — 처리 조건식은 그대로입니다.
- `runPermissions()`는 설정 화면 「지금 실행」의 권한(**설정 수정 + 그 도메인 수정**, 행을 지우는
  방식이면 도메인 삭제까지)을 돌려줍니다. 핸들러를 등록하면 코어가 `retention:{id}` 직권 실행 대상을
  자동으로 만듭니다.
- `preview()`·`targets()`·`process()`는 **같은 조건식**을 공유합니다. 어긋나면 운영자가 화면에서 본
  숫자·명단과 실제로 지워진 양이 달라집니다. 멱등(이미 처리된 행 제외)도 그 조건식이 책임집니다.
- `preview()`·`targets()`는 기능이 **꺼져 있어도** 저장된 기간값으로 셉니다 — 운영자는 켜기 전에
  규모와 명단을 확인합니다. 꺼짐 여부는 수집기가 `enabled` 로 따로 알립니다.
- `targets()`는 대상 명단 한 쪽(`LengthAwarePaginator`, 항목은 `App\Privacy\DTO\PrivacyRetentionTarget`)을,
  `targetColumns()`는 쓰는 열의 라벨을, `targetPermissions()`는 명단을 볼 권한(**설정 읽기 + 그 도메인
  읽기**, 모두 필요)을 돌려줍니다. 코어의 대상 명단 API(`GET /api/admin/privacy/retention-targets/{handler}`)가
  핸들러를 식별자로 찾아 그 권한을 요청 시점에 검사하므로, 확장은 등록만으로 설정 화면의 「대상 보기」
  모달을 얻습니다.
- 대량 순회는 `chunkById`입니다 — 처리하면서 조건에서 빠지는 행을 다루므로 OFFSET 순회는
  커서가 밀려 건을 건너뜁니다.

각 핸들러는 처리 전후로 자기 도메인 prune 훅을 발행합니다(7.0.7 규약):
`{prefix}.before_prune`(보존일) / `{prefix}.after_prune`(보존일, 건수).
**`--dry-run` 에서는 발행하지 않습니다** — 리허설에서 자기 리스너가 돌았다고 착각하지 않도록
실제 처리 때만 발행합니다. 번들 핸들러의 접두사 전수는 아래 「보존 기간 자동 파기」 카탈로그에 있습니다.
코어 탈퇴 회원 핸들러의 접두사는 `core.privacy.withdrawn_user`입니다.
구현 예시·규약 표·검증 매트릭스는 [개인정보 보유기간 처리 가이드](../backend/privacy-retention.md)에 있습니다.

### 개인정보 조치 직권 실행 대상·알림 훅

`core.privacy.manual_run_targets`는 코어 소유 필터입니다. 입력·반환 모두 `array<int, class-string>`이고,
각 클래스는 `App\Privacy\Contracts\PrivacyManualRunnerInterface`를 구현합니다. 보유기간 핸들러와 휴면
단계는 코어가 이미 등록하므로, **핸들러가 아닌 조치**(예: gdpr 연간 통지 발송)만 이 필터로 더합니다.
리스너는 DB 에 접근하지 않고 클래스명만 더하며, 대상 키는 `{확장 식별자}:{이름}` 으로 전역 유일해야
합니다(같은 키가 둘이면 먼저 등록된 쪽만 남습니다 — 확장이 코어 대상을 가로채지 못하게).

```php
HookManager::addFilter('core.privacy.manual_run_targets', function (array $runners) {
    $runners[] = AnnualNoticeManualRunner::class;

    return $runners;
});
```

접수·종료는 코어 소유 액션 훅으로 알립니다. 인자는 `(PrivacyManualRun $run, string $label)` 이고 코어 활동
로그 리스너가 구독합니다.

| 훅 | 발행 시점 | 비고 |
| --- | --- | --- |
| `core.privacy.manual_run_queued` | 「지금 실행」 접수 직후(관리자 요청 안) | `$run->skip_waiting` 로 휴면 대기기간 생략 여부를 안다 |
| `core.privacy.manual_run_finished` | 큐 작업이 완료·실패로 기록을 닫은 뒤(버려진 기록을 닫을 때 포함) | 인증 사용자가 없다 — 접수자는 `$run->requested_by` |

계약·운영 규약은 [개인정보 보유기간 처리 가이드 — 관리자 직권 실행](../backend/privacy-retention.md#10-관리자-직권-실행)에 있습니다.

### 휴면 계정 생명주기 알림 훅

`core.privacy.dormant_pre_notice` · `core.privacy.dormant_converted` ·
`core.privacy.dormant_withdraw_notice`는 코어 소유 액션 훅이며 인자는 `(User $user, array $extra)`
입니다. 코어 알림 정의 3종이 이 훅을 구독하고, 치환값은 `core.privacy.notification.extract_data`
필터(`CorePrivacyNotificationDataListener`)가 채웁니다.

`$extra`의 내용은 **훅마다 다릅니다** — 구독 측은 키가 있다고 가정하지 말고 없을 때를 함께
다루십시오.

| 훅                                     | `$extra`                                                    |
| -------------------------------------- | ----------------------------------------------------------- |
| `core.privacy.dormant_pre_notice`      | `dormant_days` · `scheduled_date`                            |
| `core.privacy.dormant_converted`       | 없음(빈 배열) — 전환은 이미 일어난 일이라 예정일이 없습니다  |
| `core.privacy.dormant_withdraw_notice` | `scheduled_date`                                             |

예정일은 커맨드가 계산해 실어 보냅니다 — 리스너가 다시 계산하면 하루 차이로 어긋나
"오늘 전환된다" 는 메일을 받은 다음 날 전환되는 식의 불일치가 생깁니다. 계산 기준은 **집행
쿼리와 같은 시점**(통지 시각 + `privacy.dormant_notice_days`)입니다.

### 비밀번호 정책 훅 (7.1.0)

비밀번호를 **바꾸는** 경로(재설정·마이페이지·정보수정·관리자 수정·로그인 변경 단계)는 코어의 변경 기록 지점
한 곳을 지나며 `core.auth.password_change_recorded` 를 발행합니다. 새 회원을 **만드는** 경로(가입·관리자 등록·설치)는
변경일만 기록하고 이 훅을 발행하지 않습니다 — 그 시점이 필요하면 `core.auth.after_register`(가입)·`core.user.after_create`
(관리자 등록)를 구독합니다. 기존 `core.auth.after_password_changed($user)` 는 인자를 바꾸지 않고 바꾸는 경로 5곳에서
그대로 발행합니다.

| 훅 | 종류 | 인자 | 발행 시점 |
| --- | --- | --- | --- |
| `core.auth.password_change_recorded` | action | `(User $user, array $context)` — `source`(`self`·`reset`·`admin`·`login_required`) · `actor_id` · `sessions_revoked`(폐기한 토큰 수) · 로그인 변경 단계면 `reason` | 비밀번호가 바뀐 뒤 — 트랜잭션 안이면 커밋 뒤 |
| `core.auth.password_change_required` | action | `(User $user, array $context)` — `reason`(`expired`·`admin_assigned`) · `guard`(`user`·`admin`) | 로그인 관문이 변경 단계 식별자를 발급할 때 |
| `core.auth.password_change_postponed` | action | `(User $user, array $context)` — `until`(ISO8601) · `until_display`(수신자 시간대 날짜) | '다음에 변경 허용' 모드에서 회원이 변경을 미뤘을 때 |
| `core.auth.password_expiry_notice` | action | `(User $user, array $context)` — `expires_at`(수신자 시간대 `Y-m-d`) · `days_left` | 만료 처리 배치의 사전 안내 (주기당 1회) |
| `core.auth.password_expired` | action | `(User $user, array $context)` — `expired_at`(수신자 시간대 `Y-m-d`) · `sessions_revoked`(폐기한 토큰 수 — '다음에 변경 허용' 모드면 0) | 만료 처리 배치의 만료일 처리 (주기당 1회) |
| `core.auth.password_change_validation_rules` | filter | `(array $rules, FormRequest $request)` | 로그인 변경 단계 완료 요청 검증 |
| `core.auth.password_change_postpone_validation_rules` | filter | `(array $rules, FormRequest $request)` | 로그인 변경 단계 연기 요청 검증 |
| `core.settings.password_expiry_preview_validation_rules` | filter | `(array $rules, FormRequest $request)` | 보안 탭 만료 대상 건수 요청 검증 |

코어 알림 정의 `password_expiry_notice`·`password_expired` 가 두 배치 훅을 구독하고, 치환값은
`core.auth.notification.extract_data` 필터가 채웁니다. 날짜는 배치가 수신자 시간대로 계산해 싣습니다 — 리스너가
다시 계산하면 집행 시점과 하루 어긋날 수 있습니다. 배치 훅은 표지(이번 주기 처리 기록)를 먼저 남긴 뒤 발행하고,
발행이 던지면 표지를 되돌립니다(휴면 사전 안내와 같은 규칙) — 큐 안에서 실패한 발송은 표지를 되돌리지 못합니다.

### 회원 역할 변경 훅 (7.1.0)

회원 등록·수정(`UserService::createUser`·`updateUser`)으로 역할이나 최고관리자 지정이 **실제로 바뀌면**
`core.user.after_update_roles` 를 발행합니다. 바뀐 것이 없으면(같은 역할로 다시 저장, 역할을 싣지 않는 정보수정) 발행하지
않습니다. 여러 회원의 역할을 한꺼번에 바꾸는 일괄 경로는 짝 훅 `core.user.after_bulk_update_roles` 가 따로 알립니다.
코어 활동 로그가 이 훅으로 `user.roles_update` 를 남기며, 이 기록은 권한 부여·변경 기록이라 활동 로그 보관 기간과
관계없이 최소 3년 보관됩니다(`config('core.activity_log.long_retention_actions')`).

| 훅 | 종류 | 인자 | 발행 시점 |
| --- | --- | --- | --- |
| `core.user.after_update_roles` | action | `(User $user, array $changes, string $source)` — `$changes` = `attached`(붙은 역할 ID 목록) · `detached`(떨어진 역할 ID 목록) · `is_super`(최고관리자 지정이 바뀌었으면 `['from' => bool, 'to' => bool]`, 아니면 `null`), `$source` = `create`·`update` | 등록은 `core.user.after_create` 뒤, 수정은 커밋 뒤 `core.user.after_update` 뒤 |

### 관리자 회원 목록 확장 필터 훅

관리자 회원 목록(`GET /api/admin/users`)에 모듈·플러그인이 자기 필터를 더하는 경로다. 값은 화면이
`ext_filters[]` 배열 한 칸으로 보내고(원소 `{확장id}.{키}={값}`), `UserListRequest` 가 검증 전에
`ext.{확장id}.{키}` 중첩 배열로 편다. 코어 조건을 다 건 **뒤에** 확장 조건이 붙으므로 확장은 결과를
좁히기만 할 수 있다.

| 훅 | 타입 | 인자 | 용도 |
| --- | --- | --- | --- |
| `core.user.list_validation_rules` | filter | `(array $rules, UserListRequest $request)` | `ext.{확장id}.{키}` 규칙을 더한다. 다른 키와의 비교 규칙(`gte:` 등)은 대상 값이 있을 때만 건다 — 비교 대상이 없으면 실패한다 |
| `core.user.list_validation_attributes` | filter | `(array $attributes, UserListRequest $request)` | 같은 키의 오류 문구 항목명. 없으면 422 문구에 내부 키가 그대로 나온다 |
| `core.user.filter_list_params` | filter | `(array $filters)` | 검증된 조건의 정규화·기본값 |
| `core.user.list_query_extensions` | filter | `(array $closures, array $filters)` | `fn(Builder $query, array $filters)` 클로저를 더한다. 확장 값은 `$filters['ext'][확장id][키]` |

규칙:

- 쿼리 클로저는 **저장소 서브쿼리**(`whereIn('id', …)`)로 조건을 건다. 행마다 판정하는 방식(`whereHas` 의 상관
  서브쿼리, 모델 메서드 호출)은 목록 비용을 회원 수에 비례시킨다.
- 그 데이터를 볼 권한이 없는 운영자에게는 조건을 **적용하지 않는다**(화면 행도 같은 권한의 노드 `<permissions>`
  로 숨긴다). 조건을 적용하면 결과 건수로 값을 추정할 수 있다.
- 목록 응답 행에 확장 값을 싣는 `core.user.filter_resource_data` 는 쓰지 않는다 — 행마다 발화해 N+1 이 된다.
  목록에 값을 보여야 하면 자기 데이터소스로 현재 페이지 id 를 배치 조회한다.

예제: 이커머스 `UserListMileageFilterListener`(마일리지 잔액 범위)·`UserListAddressFilterListener`(배송지 국가·등록 여부).
화면 쪽 규약은 [관리자 목록 필터 컨벤션](../frontend/admin-list-filter-convention.md) 을 본다.

### 알림 추출의 선택 채널

`{hookPrefix}.notification.extract_data` 반환의 `context.channels`에 채널 ID 배열을 지정하면
코어 알림 리스너가 해당 채널의 활성 템플릿만 발송합니다. 키가 없으면 기존 전체 채널 동작을
유지하며 빈 배열은 발송하지 않습니다. `context.skip`은 기존처럼 전체 발송을 중단합니다.

### 알림 채널 후보 계약 (7.1.0)

문자·알림톡·앱 푸시처럼 **채널을 제공하는 확장은 도메인을 알지 못한 채** 코어 훅 하나에 답합니다.
코어는 채널을 정하지 않은 알림(다채널 결정)마다 후보를 다음 순서로 거칩니다 —
`NotificationChannelService::resolveChannelCandidates()` 가 단일 진입점입니다.

| 순서 | 훅 | 종류 | 인자 | 답하는 쪽 |
| --- | --- | --- | --- | --- |
| 1 | `core.notification.channels` | filter | `(array $channels, string $type, ?object $notifiable, string $hookPrefix)` | 채널 제공 확장 — 모든 도메인 공통으로 후보를 더함 |
| 2 | `{hookPrefix}.notification.channels` | filter | `(array $channels, string $type, ?object $notifiable)` | 알림을 보내는 도메인 — 자기 설정으로 후보를 좁히거나 더함 |

- 채널 제공 확장은 `core.auth`·게시판·이커머스 같은 **도메인 접두사를 구독하지 않습니다.** 접두사 목록을
  적으면 목록에 없는 도메인(새로 생긴 모듈 등)에서 채널이 빠지고, 그 도메인이 없는 설치에서도 이름을
  들고 있게 됩니다.
- 도메인이 채널 후보를 화면 표시 등으로 직접 계산해야 하면 자기 훅을 발화하지 말고 코어 진입점을 부릅니다
  (`resolveChannelCandidates($hookPrefix, $channels, $type)`) — 그래야 채널 제공 확장의 후보가 같이 보입니다.
- 후보에 올라도 실제 발송 여부는 이어지는 확장 단위 활성(`core.notification.channel_enabled`)·준비 상태
  (`core.notification.channel_readiness`)·게스트 허용 판정이 정합니다.

### 수신 전화번호 표준 키 (7.1.0)

수신자 객체에 전화번호(`mobile`)가 없는 알림(비회원 주문 등)은 **알림을 보내는 도메인이**
`{hookPrefix}.notification.extract_data` 반환의 `data` 에 코어 표준 키
`GenericNotification::RECIPIENT_PHONE_KEY`(`'_recipient_phone'`)로 수신 번호를 싣습니다. 문자·알림톡 채널은
도메인의 모델을 읽지 않고 이 키만 읽습니다. 번호가 없으면 키를 넣지 않습니다(빈 문자열 금지).

```php
// 도메인의 extract_data 리스너
if ($order->isGuestOrder() && $phone !== '') {
    $data[GenericNotification::RECIPIENT_PHONE_KEY] = $phone;
}
```

### 알림 정의 시딩 계약 (7.1.0)

채널을 제공하는 확장이 **모든 도메인의 알림 정의에 자기 채널 템플릿을 더할 때** 답하는 코어 훅입니다.
코어 알림 정의 시딩과 모든 모듈·플러그인의 알림 정의 동기화(설치·업데이트·재동기화)가 DB 에 쓰기 직전
이 필터를 한 번 통과시킵니다 — 상수 `NotificationSyncHelper::SEED_DEFINITIONS_FILTER`.

| 훅 | 종류 | 인자 | 답하는 쪽 |
| --- | --- | --- | --- |
| `core.notification.filter_seed_definitions` | filter | `(array $definitions, array $context)` | 채널 제공 확장 — 반환한 정의 목록이 그대로 저장됨 |

- `$definitions` 는 순차 배열이고 각 항목에 `type`·`extension_type`·`extension_identifier` 가 들어 있습니다.
  `$context` 는 `['extension_type' => 'core'|'module'|'plugin', 'extension_identifier' => 식별자]`(코어는 `'core'`)입니다.
- 도메인별 언어팩 훅(`seed.{id}.notifications.translations`) **다음에** 발화합니다. 받은 본문에는 언어팩 로케일이
  이미 병합돼 있습니다. 도메인별 언어팩 훅은 언어팩 병합 전용이라 채널 확장이 구독하지 않습니다 — 도메인 목록을
  적으면 목록에 없는 새 모듈의 알림에 채널 템플릿이 생기지 않습니다.
- 더한 템플릿은 시딩 주체가 자기 선언처럼 저장하므로 운영자 수정 보존·재동기화 시 정리 규칙을 그대로 따릅니다.
  정의의 소유자는 바꿀 수 없고(시딩 주체가 다시 채움), 빈 목록을 반환하면 원본으로 시딩합니다.
- 같은 증강을 [기본값 복원] 경로 `core.notification.filter_default_definitions` 에도 걸어야 복원이 시드값과 같아집니다.
- 채널 확장이 켜지는 순간 이미 저장된 정의에도 훅을 다시 태우려면, 활성화 처리에서 코어 시더와 함께
  `ModuleManager::resyncActiveModuleNotificationDefinitions()` · `PluginManager::resyncActivePluginNotificationDefinitions()`
  를 부릅니다. 설치·활성 확장의 **알림 정의만** 설치본 기준으로 다시 동기화하며(`_bundled` 를 읽지 않고 역할·권한·메뉴는
  건드리지 않음), 운영자가 고친 내용(`user_overrides`)은 보존됩니다. 대상 확장을 고르지 않습니다 — 활성 목록 전체입니다.

```php
public static function getSubscribedHooks(): array
{
    return [
        'core.notification.filter_seed_definitions' => ['method' => 'augment', 'priority' => 50, 'type' => 'filter'],
        'core.notification.filter_default_definitions' => ['method' => 'augment', 'priority' => 50, 'type' => 'filter'],
    ];
}
```

### 1. Action Hooks (액션 훅)

**목적**: 특정 시점에 부가 작업 실행

**사용 예시**:
- 이메일 발송
- 활동 로그 기록 (→ [활동 로그 시스템](../backend/activity-log.md) 참조)
- 캐시 무효화
- 알림 전송

**패턴**:

```php
// Service에서 훅 실행
HookManager::doAction('sirsoft-ecommerce.product.after_create', $product, $data);
```

### 2. Filter Hooks (필터 훅)

**목적**: 데이터 변형 및 가공

**사용 예시**:
- 가격 계산
- 데이터 포맷 변경
- 권한 확인

**패턴**:

```php
// Service에서 필터 적용
$finalPrice = HookManager::applyFilters('sirsoft-ecommerce.product.calculate_price', $basePrice, $product);
```

---

## 훅 네이밍 규칙

### 표준 패턴

```text
[vendor-module].[entity].[action]_[timing]
```

### 타이밍 접미사

| 접미사     | 설명               |
| ---------- | ------------------ |
| `before_*` | 작업 실행 전       |
| `after_*`  | 작업 실행 후       |
| `on_*`     | 특정 이벤트 발생 시 |
| `filter_*` | 데이터 변형용 필터 |

### 예시

```text
# 생성/수정/삭제 (변경 작업)
sirsoft-ecommerce.product.before_create
sirsoft-ecommerce.product.after_create
sirsoft-ecommerce.product.after_update
sirsoft-ecommerce.product.filter_create_data

# 조회 작업
sirsoft-ecommerce.product.before_list
sirsoft-ecommerce.product.after_list
sirsoft-ecommerce.product.filter_list_query
sirsoft-ecommerce.product.filter_list_result
sirsoft-ecommerce.product.before_show
sirsoft-ecommerce.product.after_show
sirsoft-ecommerce.product.filter_show_result

# 이벤트/기타
sirsoft-ecommerce.order.on_payment_success
sirsoft-ecommerce.product.calculate_price

# 코어 훅
core.attachment.download
core.attachment.update
core.attachment.delete

# 스토리지 공개 URL 훅 (Filter) — StorageInterface::url() 결과 공급/수정/차단 (공개#100)
# 컨텍스트 6키는 아래 "스토리지·드라이버 확장 훅 페이로드" 표 참조
core.storage.filter_url

# 보존 기간 자동 파기 (7.0.7) — 운영자 일괄 삭제 훅과 별개로 발행한다.
# 운영자 삭제 훅에는 본인인증 같은 대화형 가드가 물려 있어 무인 예약이 탈 수 없고,
# 그렇다고 훅 없이 지우면 확장이 가장 큰 삭제 경로(첫 실행의 누적분)를 볼 수 없다.
# 인자는 (보존일) / (보존일, 삭제건수) — 대상을 ID 로 지목하지 않는다.
core.activity_log.before_prune          core.activity_log.after_prune
core.notification_log.before_prune      core.notification_log.after_prune
core.schedule.before_prune_history      core.schedule.after_prune_history

# 개인정보 보유기간 파기 (7.1.0, privacy:process-retention) — 핸들러마다 자기 접두사로 발행한다.
# 같은 (보존일) / (보존일, 처리건수) 인자 규약이다. --dry-run 에서는 발행하지 않는다 —
# 리허설에서 리스너가 돌았다고 착각하지 않도록 실제 처리 때만 발행한다.
core.privacy.withdrawn_user.before_prune        core.privacy.withdrawn_user.after_prune
sirsoft-ecommerce.order.before_prune            sirsoft-ecommerce.order.after_prune
sirsoft-board.author_ip.before_prune            sirsoft-board.author_ip.after_prune
sirsoft-board.guest_author.before_prune         sirsoft-board.guest_author.after_prune

# 본문 첫 내부 이미지 썸네일 캐시 (Filter, 공개#22) — 각 모델 saving 이벤트가 발행.
# 값 = 추출된 첫 내부 이미지 URL(없으면 null), 인자 = (값, 모델, 전체 후보 src 배열).
# 확장이 후보를 대체(CDN prefix 승격 등)하거나 차단(null 반환)할 수 있다.
# 특정 에디터 확장에 의존하지 않는다 — 페이로드는 일반 HTML 파싱 결과뿐이다.
sirsoft-board.post.filter_content_thumbnail
sirsoft-ecommerce.product.filter_content_thumbnail
sirsoft-page.page.filter_content_thumbnail

# 업로드 트라이어드 — 사용자 첨부 업로드 지점의 표준 3훅 패턴
# before_upload(액션) → filter_upload_file(필터: UploadedFile 을 받아 변형본을 반환.
# 저장 파일명·MIME·크기가 모두 반환 파일 기준이 된다) → after_upload(액션)
# 포맷 변환(예: jpg → webp) 시에는 바이트만 제자리 덮어쓰지 말고, 변환된 임시 파일 경로와
# 새 원본 파일명(xxx.webp)으로 UploadedFile 을 재구성해 반환할 것 — 일부 소비처는 저장
# 확장자를 원본 파일명에서 얻으므로, 이름을 갱신하지 않으면 확장자와 내용이 어긋난다.
core.attachment.filter_upload_file                      # 코어 첨부 (아바타 포함)
core.template_layout_attachment.before_upload           # 레이아웃 편집기 첨부 (7.0.7)
core.template_layout_attachment.filter_upload_file      # 레이아웃 편집기 첨부 (7.0.7)
core.template_layout_attachment.after_upload            # 레이아웃 편집기 첨부 (7.0.7)
sirsoft-board.attachment.filter_upload_file             # 게시판 첨부
sirsoft-page.attachment.filter_upload_file              # 페이지 첨부
sirsoft-ecommerce.product-image.filter_upload_file      # 상품 이미지
sirsoft-ecommerce.category-image.filter_upload_file     # 카테고리 이미지
sirsoft-ecommerce.review-image.filter_upload_file       # 리뷰 이미지 (공개 #96)
sirsoft-ecommerce.claim-image.before_upload             # 클레임 첨부 (교환·반품·환불 증빙)
sirsoft-ecommerce.claim-image.filter_upload_file        # 클레임 첨부
sirsoft-ecommerce.claim-image.after_upload              # 클레임 첨부
sirsoft-ckeditor5.image.before_upload                   # 에디터 이미지
sirsoft-ckeditor5.image.filter_upload_file              # 에디터 이미지
sirsoft-ckeditor5.image.after_upload                    # 에디터 이미지

# 업로드 잔존물 회수 — 어떤 콘텐츠에서도 쓰이지 않는 에디터 이미지의 참조 판정 대상 선언 (필터)
# 자기 콘텐츠를 가진 확장이 이 훅으로 테이블/컬럼을 등록하지 않으면, 그 확장에서만 쓰이는
# 이미지가 "미참조" 로 판정돼 자동 정리 대상이 된다.
sirsoft-ckeditor5.image.filter_reference_sources        # 에디터 이미지 참조 소스

# FormRequest Validation Rules 훅 (Filter)
core.user.create_validation_rules
core.user.update_validation_rules
core.user.update_profile_validation_rules          # 본인 프로필 수정 (PUT /api/me)
core.role.store_validation_rules
core.role.update_validation_rules
core.permission.store_validation_rules
core.permission.update_validation_rules
core.menu.store_validation_rules
core.menu.update_validation_rules
core.privacy.target_list_validation_rules           # 개인정보 조치 대상 명단 조회 (page · per_page)
core.settings.privacy_preview_validation_rules      # 개인정보 탭 대상 건수 미리보기 (기본 규칙 없음)
core.privacy.manual_run_validation_rules            # 개인정보 조치 직권 실행 접수·건수 확인 (skip_waiting)
core.privacy.manual_run_status_validation_rules     # 개인정보 조치 직권 실행 상태 조회 (targets · since)

# 구 이름에서 표준 이름으로 옮긴 훅 (7.0.6) — 구 이름도 함께 발행되므로 기존 구독은 유지되나,
# 새로 구독할 때는 표준 이름을 쓴다. 구 이름에 구독자가 있으면 로그에 1회 안내가 남는다.
core.plugin_settings.update_validation_rules        # 구: core.plugin_settings.update_rules
core.auth.validate_reset_token_validation_rules     # 구: core.auth.validate_reset_token_rules
core.auth.verify_password_validation_rules          # 구: core.auth.verify_password_rules
core.extension.changelog_validation_rules           # 구: core.extension.changelog_rules
core.search.index_validation_rules                  # 구: core.search.validation_rules
core.user.upload_avatar_validation_rules            # 구: core.user.upload_avatar_rules

# Layout Extension 훅
core.layout_extension.before_apply
core.layout_extension.after_apply

# 레이아웃 파일 관리 훅 (Action) — 관리자 코드 편집기에서 파일을 만들고·옮기고·지운 전후
core.layout.before_create    # doAction($templateId, $name, $content)
core.layout.after_create     # doAction($layout, $templateId, $name)  — 지워진 같은 이름을 되살린 경우도 같은 훅
core.layout.before_rename    # doAction($templateId, $name, $newName)
core.layout.after_rename     # doAction($layout, $templateId, $name, $newName)  — 같은 행(판본 이력 승계)
core.layout.before_delete    # doAction($templateId, $name)
core.layout.after_delete     # doAction($layout, $templateId, $name)  — soft delete, 행은 남는다

# 레이아웃 파일 관리 검증 훅 (Filter) — FormRequest 규칙 확장
core.layout.create_validation_rules
core.layout.rename_validation_rules
core.layout.search_validation_rules

# 드라이버 확장 훅 (Filter) — 플러그인이 새 드라이버를 등록
# 항목 3키 구조는 아래 "스토리지·드라이버 확장 훅 페이로드" 표 참조
core.settings.available_storage_drivers
core.settings.available_public_asset_drivers   # 공개 자산 직접 URL 서빙 디스크 (공개#100)
core.settings.available_cache_drivers
core.settings.available_session_drivers
core.settings.available_queue_drivers
core.settings.available_log_drivers
core.settings.available_websocket_drivers
core.settings.available_mail_drivers

# 드라이버 확장 훅 (Action) — 플러그인 드라이버 선택 시 Config 적용
core.settings.apply_driver_config

# IP 접근 제한 제외 경로 훅 (Filter) — 확장이 자기 인바운드 콜백 경로를 제외 목록에 추가
# 아래 "IP 접근 제한 제외 경로 훅 페이로드" 참조
core.security.ip_restriction_exempt_paths   # applyFilters($patterns)

# 사용자 추가 에셋 훅 (Filter) — 운영자가 확장에 덧붙인 CSS·JS 목록을 보정/추가
core.assets.custom_assets   # applyFilters($assets, $extensionType, $identifier)

# 사용자 추가 에셋 관리 훅 (Action) — 화면에서 파일을 저장/업로드/삭제한 직후
core.custom_assets.after_change   # doAction($extensionType, $identifier, $operation, $path)

# 사용자 추가 에셋 관리 검증 훅 (Filter) — 관리 API 의 FormRequest 규칙 확장
core.extension_custom_asset.read_validation_rules
core.extension_custom_asset.save_validation_rules
core.extension_custom_asset.upload_validation_rules

# SEO 렌더링 훅 (Filter)
core.seo.filter_context        # DataSource 결합 후 컨텍스트 보강 ($context 배열)
core.seo.filter_og_data         # OG 태그 분기별 hook ($og 배열) — image_width/site_name/extra 주입
core.seo.filter_twitter_data    # Twitter 카드 분기별 hook ($twitter 배열)
core.seo.filter_structured_data # JSON-LD 분기별 hook ($structuredData 배열)
core.seo.filter_meta            # 통합 hook (모든 분기 결합 후 $meta 전체)
core.seo.filter_view_data       # View 직전 ($viewData) — extraHeadTags / extraBodyEnd

# SEO 봇 감지 훅 (Filter) — null 반환 시 라이브러리 평가로 fallthrough
core.seo.resolve_is_bot

# Sitemap 증분 인덱싱 훅 (Filter) — 리소스→sitemap 항목 가공/추가
sitemap.index.collect_for_resource   # SitemapIndexer 가 리소스 index 시 발화 — applyFilters($entries, $type, $id, $contributor)

# Sitemap 재생성 훅 (Action)
core.seo.sitemap.before_regenerate       # 재생성 시작 직전
core.seo.sitemap.after_regenerate        # 재생성 성공 후 ($meta)
core.seo.sitemap.after_regenerate_failed # 재생성 실패 시 (['status'=>'failed', 'success'=>false, ...])

# 방문자 통계 확장 기여 훅 — 페이로드는 아래 "방문자 통계 확장 기여 훅 페이로드" 표 참조
core.analytics.registered_goals          # (Filter) 모은 목표 선언 보정
core.analytics.registered_dimensions     # (Filter) 모은 분류 기준 선언 보정
core.analytics.resolve_dimensions        # (Filter) 수집 요청의 분류 값을 서버가 보탠다
core.analytics.search_engines            # (Filter) 검색엔진 표
core.analytics.campaign_params           # (Filter) 캠페인 파라미터 이름
core.analytics.stripped_query_params     # (Filter) URL 정규화 때 지울 추적 파라미터
core.analytics.ua_rules                  # (Filter) 브라우저·OS 판정 패턴
core.analytics.exclusion_rules           # (Filter) 코어 판정 통과 요청을 더 뺀다
core.analytics.registered_exclusion_reasons # (Filter) 확장 제외 사유 등록
core.analytics.archive.day               # (Action, sync 필수) 하루 집계에 확장 분류 기준 행을 얹는다
core.analytics.subject_delete            # (Filter) 정보주체 삭제 — 코어와 같은 트랜잭션
core.analytics.subject_export            # (Filter) 정보주체 내보내기에 확장 데이터 병합
core.analytics.purge_all                 # (Filter) 전체 삭제 — 코어보다 먼저, 트랜잭션
core.analytics.anonymize_attributes      # (Filter) 소급 익명화가 방문 하나에 덮어쓸 값
core.analytics.csv_columns / csv_row     # (Filter) 리포트 CSV 확장 열
core.analytics.diagnostics_sections      # (Filter) 진단 응답의 확장 섹션
core.analytics.realtime_fields           # (Filter) 실시간 행의 확장 값
core.analytics.storage_tables            # (Filter) 저장소 현황에 확장 표
core.analytics.extension_purged          # (Action) 확장을 데이터까지 지우며 제거하거나 analytics:purge-extension 을 돌린 뒤 — {extension_type, extension_identifier, counts, failed}
```

> `sitemap.index.collect_for_resource` 는 제3자 확장이 리소스 하나에 대한 sitemap 항목을 추가/보정할 때 씁니다(filter — `'type' => 'filter'` 명시 필수). `core.seo.sitemap.after_regenerate_failed` 는 재생성 잡이 실패했을 때 확장이 알림/복구를 걸 수 있는 action 훅입니다.

### 방문자 통계 확장 기여 훅 페이로드

전환·분류 기준(dimension) **선언**은 훅이 아니라 진입 클래스의 `getAnalyticsGoals()`·`getAnalyticsDimensions()`
오버라이드다 — 선언 스키마와 예시는 [analytics-extension-points.md](analytics-extension-points.md).
아래 훅은 선언 뒤의 판정·집계·개인정보·출력에 끼어드는 자리다. 판정표·등록부 필터(`search_engines`·`campaign_params`·
`stripped_query_params`·`ua_rules`·`registered_goals`·`registered_dimensions`)는 반환값이 그대로 쓰이므로 **받은 목록에
보태서** 돌려준다 — 받은 항목을 빼면 코어 항목도 판정에서 빠진다. `exclusion_rules` 와 출력·개인정보 필터(`csv_columns`·
`diagnostics_sections`·`realtime_fields`·`storage_tables`·`anonymize_attributes`)는 코어 값을 덮지 못한다. 형식이 틀린
반환은 그 항목만 버린다.

| 훅 | 값 (첫 인자) | 나머지 인자 | 반환 |
| --- | --- | --- | --- |
| `core.analytics.registered_goals` | `array<키, GoalDefinition>` | — | 같은 모양. 정의 객체가 아닌 항목은 버려지고 경고 |
| `core.analytics.registered_dimensions` | `array<원장이름, DimensionDefinition>` | — | 같은 모양 |
| `core.analytics.resolve_dimensions` | `array<분류 기준 이름, 값>` | 수집 요청 배열 | 보탠 맵 — 결과는 등록부로 다시 걸러진다 |
| `core.analytics.search_engines` | `[{name, hosts[], params[]}]` | — | 항목을 더한 목록 — 이름이 100자를 넘으면 잘려 저장된다 |
| `core.analytics.campaign_params` | `{name, source, medium}` | — | 이름을 바꾼 맵 |
| `core.analytics.stripped_query_params` | `string[]` | — | 더한 목록 |
| `core.analytics.ua_rules` | `{browsers: [[찾을값, 이름]], os: [[…]], hint_brands: {브랜드: 이름}}` | — | 판정은 앞에서부터 — 인앱 브라우저는 앞에 끼운다. 이름이 50자를 넘으면 잘려 저장된다 |
| `core.analytics.exclusion_rules` | `null` | 수집 요청 배열 | 제외할 때 `'{식별자}:{사유}'`, 아니면 그대로 |
| `core.analytics.registered_exclusion_reasons` | `[]` | — | `'{식별자}:{사유}' => 이름` 을 더한 맵. 등록하지 않은 사유로는 제외되지 않는다 |
| `core.analytics.subject_delete` | `[]` | 찾을 기준(마스킹·해시 반영), 방문 ID 목록, 시작일, 종료일(`Y-m-d`, 운영자가 기간을 고르지 않으면 `null` = 전 기간) | `{식별자: 지운 건수}` — 던지면 코어 삭제도 롤백. 방문 ID 로 묶이지 않는 표는 기간 안의 것만 지운다 |
| `core.analytics.subject_export` | `[]` | 찾을 기준, 방문 ID 목록, 시작일, 종료일(삭제와 같음) | `{식별자: 내용}` |
| `core.analytics.purge_all` | `[]` | — | `{식별자: 지운 건수}` — 던지면 코어 삭제 전에 멈춤 |
| `core.analytics.anonymize_attributes` | 덮어쓸 속성 배열 | 방문 모델, `{mask_octets, hash}` | 보탠 배열 — 코어가 정한 키는 되돌려지지 않는다 |
| `core.analytics.csv_columns` | `[]` | 집계 이름(코어 리포트 또는 `ext:` 분류 기준), 리포트 이름 | `{헤더: extra 키}` — 코어 열 이름은 받지 않는다 |
| `core.analytics.csv_row` | 한 줄 배열 | 화면용 행, 집계 이름 | 같은 길이의 배열 |
| `core.analytics.diagnostics_sections` | `[]` | 설정 | `{식별자: 섹션 배열}` → 응답 `extensions` |
| `core.analytics.realtime_fields` | `[]` | 방문 모델 | `{식별자: 값}` → 행의 `extensions` |
| `core.analytics.storage_tables` | `[]` | — | 표 이름 목록(영소문자·숫자·밑줄) |

`core.analytics.archive.day` 는 액션이며 인자는 `{date, collector, is_final, run_type}` 이다. `date` 는 사이트
시간대(관리자 환경설정) 기준 날짜다 — 앱 시간대(UTC)가 아니다.
`collector->addDimensionRow('ext:{식별자}:{키}', 라벨, 방문자유형, 지표)` 로 행을 얹는다 — 집계가
「지우고 넣기」라 확장이 집계 표에 직접 쓰면 다음 집계가 지운다. 이 훅은 반드시 `'sync' => true` 로
구독한다(아래 판정표).

### 스토리지·드라이버 확장 훅 페이로드

#### `core.storage.filter_url`

`StorageInterface::url()` 의 결과를 공급·수정·차단합니다. 첫 인자는 생성된 URL(`?string`)이며, **디스크 종류와 무관하게 항상 발화**합니다 — 직접 URL 을 만들 수 없어 `null` 인 경우에도 발화하므로 확장이 서명 URL 등을 공급할 수 있습니다. 반환이 문자열이 아니거나 빈 문자열/공백이면 호출측이 스트리밍으로 폴백합니다.

두 번째 인자는 컨텍스트 배열입니다.

| 키 | 타입 | 값 |
| --- | --- | --- |
| `scope` | string | `core` / `module` / `plugin` — 호출한 드라이버 종류 |
| `identifier` | ?string | 확장 식별자 (`sirsoft-ecommerce` 등). 코어 드라이버는 `null` |
| `disk` | string | 대상 디스크명 |
| `category` | string | 스토리지 카테고리 (`images`, `settings` 등) |
| `path` | string | 카테고리 하위 상대 경로 |
| `full_path` | string | 디스크 루트 기준 전체 경로 — `Storage::temporaryUrl()` 등에 그대로 사용 가능 |

#### `core.settings.available_{category}_drivers`

플러그인이 드라이버/디스크 선택지를 카탈로그에 추가합니다. 첫 인자인 드라이버 배열에 다음 구조의 항목을 append 합니다.

| 키 | 타입 | 값 |
| --- | --- | --- |
| `id` | string | 드라이버/디스크 식별자 — 저장값이자 config 조회 키 |
| `label` | array | 로케일별 표시 라벨 (예: `['ko' => 'CDN', 'en' => 'CDN']`) |
| `provider` | string | 공급 플러그인 식별자 — 비활성화 시 "사용 중 드라이버" 경고 판정에 사용 (코어 기본 항목에는 없음) |

`core.settings.available_public_asset_drivers` 로 등록하는 디스크는 플러그인 ServiceProvider 에서 `filesystems.disks.{id}` 정의가 함께 있어야 하며, 그 정의에 `url` 키가 있어야 직접 URL 이 생성됩니다(없으면 스트리밍 폴백). 플러그인이 비활성화되어 디스크 정의가 사라지면 저장값은 보존된 채 스트리밍으로 자동 폴백합니다.

#### `core.security.ip_restriction_exempt_paths`

운영자가 켠 **IP 접근 제한**(환경설정 > 보안)이 확장의 인바운드 콜백을 끊지 않도록, 확장이 자기 콜백 경로를 제외 목록에 추가합니다. 첫 인자는 URI 패턴 문자열 배열이며 같은 모양의 문자열을 append 합니다.

| 항목 | 값 |
| --- | --- |
| 패턴 문법 | `Str::is()` 글롭 — `*` 는 `/` 를 포함해 무엇이든 맞습니다 (예: `plugins/my-pay/notify/*`) |
| 기준 경로 | 앞뒤 `/` 를 뗀 요청 경로 (`$request->path()` 와 같은 모양) |
| 적용 시점 | 규칙 판정 **이전**. 제외에 걸리면 그 요청은 규칙을 보지 않고 통과합니다 |
| 적용 한계 | **관리자 영역은 이 훅으로 열 수 없습니다** — 관리자 화면·관리자 API·관리자 로그인 API 는 제외 판정 자체를 건너뜁니다 |

코어는 관례적인 콜백 경로(`plugins/*/payment/*`, `plugins/*/webhook`, `api/plugins/*/webhook*`, `plugins/*/plugin/*/callback`)를 이미 제외하므로, 그 관례를 따르는 확장은 훅을 구독하지 않아도 됩니다. 관례 밖 경로로 외부 통보를 받는 확장만 등록하면 됩니다 — 등록하지 않으면 운영자가 `plugins/*` 류 규칙을 넣는 순간 결제 승인·입금 통보가 403 으로 끊기고, 실패는 상대편 재시도 로그에만 남습니다.

```php
public static function getSubscribedHooks(): array
{
    return [
        'core.security.ip_restriction_exempt_paths' => [
            'method' => 'exemptCallbacks',
            'priority' => 10,
        ],
    ];
}

/**
 * 이 플러그인이 외부에서 통보받는 경로를 IP 제한 제외 목록에 더합니다.
 *
 * @param  array<int, string>  $patterns  현재 제외 패턴 목록
 * @return array<int, string> 패턴 목록
 */
public static function exemptCallbacks(array $patterns): array
{
    $patterns[] = 'pay/my-gateway/notify';
    $patterns[] = 'pay/my-gateway/notify/*';

    return $patterns;
}
```

#### `sirsoft-ckeditor5.image.filter_reference_sources`

에디터 업로드 이미지가 "어디선가 쓰이고 있는지" 판정할 때 훑을 테이블/컬럼 목록을 확장합니다. 첫 인자인 소스 배열에 다음 구조의 항목을 append 합니다.

| 키 | 타입 | 값 |
| --- | --- | --- |
| `table` | string | 테이블명 — **프리픽스 제외 원시 이름** (`board_posts`) |
| `columns` | list\<string\> | 본문이 담긴 컬럼명 목록 (`['content']`) |

자기 콘텐츠에 에디터를 노출하는 확장은 이 훅을 반드시 구독해야 합니다. 등록하지 않으면 그 확장에서만 쓰이는 이미지가 "미참조" 로 판정돼 자동 정리 대상이 됩니다.

리스너는 테이블명 문자열만 덧붙이고 DB 에 접근하지 않습니다 — 실재 검증(테이블·컬럼 존재)과 조회는 플러그인이 수행하며, 존재하지 않는 선언은 경고만 남기고 건너뜁니다(한 확장의 잘못된 선언이 판정 전체를 멈추지 않습니다).

로그 사본 테이블(발송 이력·신고 스냅샷 등)은 등록하지 않습니다. 이들은 자체 보존기간으로 삭제되므로 참조 소스로 삼으면 "로그가 지워지는 순간 이미지가 고아가 되는" 역전이 생깁니다.

```php
public static function getSubscribedHooks(): array
{
    return [
        'sirsoft-ckeditor5.image.filter_reference_sources' => [
            'method' => 'addSources',
            'priority' => 10,
            'type' => 'filter',
        ],
    ];
}

public function addSources(array $sources): array
{
    $sources[] = ['table' => 'my_documents', 'columns' => ['body']];

    return $sources;
}
```

---

## 리스너 구현

### HookListenerInterface 사용

```php
<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProductCacheInvalidationListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // Action 훅: type 생략 시 기본값 'action'
            'sirsoft-ecommerce.product.after_create' => ['method' => 'handleProductChange', 'priority' => 10],
            'sirsoft-ecommerce.product.after_update' => ['method' => 'handleProductChange', 'priority' => 10],
            'sirsoft-ecommerce.product.after_delete' => ['method' => 'handleProductChange', 'priority' => 10],
        ];
    }

    /**
     * 상품 변경 시 캐시 무효화
     *
     * @param mixed ...$args
     * @return void
     */
    public function handleProductChange(...$args): void
    {
        // CacheInterface 를 컨테이너에서 lazy resolve (모듈 리스너면 ModuleCacheDriver)
        $cache = app(\App\Contracts\Extension\CacheInterface::class);

        // 모든 상품 목록 캐시 삭제
        $cache->forget('products.all');
        $cache->forget('products.active');

        // 특정 상품 캐시 삭제
        if (isset($args[0]->id)) {
            $cache->forget("product.{$args[0]->id}");
        }

        Log::info('상품 캐시가 무효화되었습니다.', [
            'product_id' => $args[0]->id ?? null,
        ]);
    }
}
```

### Listener 데이터 접근 규정

Listener 는 thin orchestrator 로 동작하며 영속/도메인 책임은 Service / Repository 가 갖는다. 다음 패턴은 자동 검증이 차단한다.

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| `Model::query()->where(...)->get()` | `$this->modelRepository->findByXxx()` 위임 |
| `Model::find($id)`, `Model::create($data)` | Repository 의 `findById/create` 메서드 |
| `DB::table('xxx')->update(...)` | Repository 의 도메인 의도 메서드 (`recalculateXxxCount`, `anonymizeUser` 등) |
| `$row->save()`, `$row->saveQuietly()`, `$row->delete()` | Repository 의 `update/save/delete` |
| 생성자에 구체 Repository 클래스 (`UserRepository $r`) | Repository Interface (`UserRepositoryInterface $r`) |
| `request()->input(...)`, `$_POST` | Service 가 검증 후 도메인 객체로 전달 — Listener 는 받기만 |
| Filter 훅 `'method' => '...'` 만 (`type` 누락) | `'type' => 'filter'` 명시 (반환값 무시 회귀 차단) |

#### Service-Repository 위임 패턴 예시 — 카운트 동기화

```php
class BoardCommentsCountSyncListener implements HookListenerInterface
{
    public function __construct(
        protected BoardRepositoryInterface $boardRepository,
    ) {}

    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.comment.after_create' => ['method' => 'syncCommentsCount', 'priority' => 10, 'sync' => true],
            'sirsoft-board.comment.after_delete' => ['method' => 'syncCommentsCount', 'priority' => 10, 'sync' => true],
        ];
    }

    public function syncCommentsCount(Comment $comment, string $slug): void
    {
        // ❌ 금지: DB::table('board_comments')->where(...)->count() + DB::table('boards')->update(...)
        // ✅ 위임: 카운트 재계산은 Repository 의 단일 진입점
        $this->boardRepository->recalculateCommentsCount((int) $comment->board_id);
    }
}
```

#### Bulk lookup 패턴 — `findByIdsKeyed`

ActivityLogListener 등에서 ID 목록으로 모델을 일괄 조회 후 ID 키 맵으로 사용하는 패턴은 Repository 의 `findByIdsKeyed(array $ids): Collection` 으로 캡슐화한다.

```php
// ❌ 금지
$products = Product::whereIn('id', $ids)->get()->keyBy('id');

// ✅ 위임
$products = $this->productRepository->findByIdsKeyed($ids);
```

#### 면제 (allowlist)

다음과 같은 의도된 예외는 인라인 주석으로 명시한다 (자동 검증이 해당 라인을 건너뜀).

```php
// audit:allow listener-direct-db-facade reason: 동적 modelClass dispatch (description resolver 의 unified ID→name 변환)
$entity = $modelClass::find($id);
```

면제는 *실제로 Repository 추상화가 부적절한* 경우에만 (예: 동적 `$modelClass::find` dispatcher, boot 컨텍스트 정적 메서드) 사용하며, reason 을 반드시 작성한다.

#### 명시 등록 패턴 (HookListenerInterface 면제)

대부분의 Listener 는 `HookListenerInterface` 구현 + auto-discovery 로 등록되지만, ServiceProvider 가 직접 `HookManager::addAction/addFilter` 로 등록하는 명시 등록 패턴 (예: LanguagePack 라이프사이클 listeners) 은 인터페이스 미구현이 합법이다. 이 경우 클래스 선언 직전에 다음 면제를 추가:

```php
// audit:allow listener-must-implement-hooklistenerinterface reason: ServiceProvider 가 HookManager::addAction 으로 직접 등록하는 명시 등록 패턴
class SyncDatabaseTranslations
{
    public function __construct(
        private readonly LanguagePackTranslationRepositoryInterface $translationRepository,
    ) {}

    public function handleActivated(LanguagePack $pack): void
    {
        // Service / Repository 위임만 수행
        $audit = DB::transaction(fn () => $this->translationRepository->applySeedFromPack($pack, $seedBundle));
    }
}
```

---

## 큐 자동 실행

Action 훅 리스너는 환경설정의 **큐 드라이버** 설정에 따라 자동으로 동기/비동기 실행됩니다. 리스너 개발자가 별도로 설정할 필요 없습니다.

### 큐 드라이버별 동작

| 큐 드라이버 | 리스너 실행 방식 | 큐 워커 필요 |
|------------|-----------------|-------------|
| `sync` | 즉시 실행 (동기) | 불필요 |
| `database` | jobs 테이블 저장 → 워커 처리 | 필요 (`php artisan queue:work`) |
| `redis` | Redis 큐 저장 → 워커 처리 | 필요 (`php artisan queue:work`) |
| 커스텀 (플러그인 추가) | 해당 드라이버 큐 → 워커 처리 | 필요 |

> **Filter 훅**은 반환값 체인이므로 **항상 동기 실행**됩니다 (큐 드라이버 무관).

### 동기 실행 강제 (opt-out)

리스너가 반드시 HTTP 요청 스레드에서 동기 실행되어야 하는 경우, `'sync' => true`를 선언합니다.

```php
public static function getSubscribedHooks(): array
{
    return [
        'core.user.after_create' => [
            'method' => 'handleUserCreated',
            'priority' => 10,
            'sync' => true,  // 큐 드라이버 무관하게 항상 즉시 실행
        ],
    ];
}
```

#### 호출자 트랜잭션 안에서 끝나야 하는 처리는 `sync` 가 필수다

기본값(큐 래핑)은 `DispatchHookListenerJob` 의 `afterCommit` 정책을 탄다. 즉 **호출자 트랜잭션이 커밋된 뒤에** 실행된다 — 큐 드라이버가 `sync` 여도 마찬가지다(같은 요청 안에서, 커밋 이후에 실행된다).

따라서 훅 안에서 실패했을 때 **호출자의 작업을 되돌려야 하는 처리**는 기본값으로 두면 안 된다. 되돌릴 대상이 이미 커밋된 뒤라 예외를 던져도 롤백되지 않고, 호출자는 오류 응답을 받는데 데이터는 남는다.

| 판정 | 예 |
|------|-----|
| `sync` 필수 | 쿠폰 차감·복원, 적립금 차감·복원 등 실패 시 호출자 트랜잭션을 되돌려야 하는 처리 · 호출자가 넘긴 객체에 값을 채워 돌려받는 처리(방문자 통계 `core.analytics.archive.day` 의 수집기 — 큐로 가면 사본에 채워져 집계에 돌아오지 않는다) · 다음 요청의 판정에 바로 쓰이는 보안 카운터(로그인 실패 횟수·계정 잠금과 성공 시 초기화 — 큐로 가면 워커가 없거나 밀린 동안 실패가 세지지 않아 잠금이 걸리지 않는다) |
| 기본값(큐) 유지 | 활동 로그, 알림 발송, 통계 갱신 등 실패해도 호출자를 되돌리지 않는 후속 처리 |

선언만으로는 검증되지 않는다 — 회귀 테스트는 리스너를 손으로 `addAction` 하지 말고 실제 등록 경로(`HookListenerRegistrar::register()`)를 태운 뒤, **호출자 트랜잭션 안에서 반영되는지**와 **예외가 호출자를 롤백시키는지**를 단언한다. 손으로 등록하면 프로덕션이 쓰지 않는 경로를 검증하게 되어, 커밋 이후 실행 문제를 그대로 통과시킨다.

### getSubscribedHooks() 옵션 요약

| 옵션 | 타입 | 기본값 | 설명 |
|------|------|--------|------|
| `method` | string | `'handle'` | 실행할 메서드명 |
| `priority` | int | `10` | 실행 우선순위 (낮을수록 먼저) |
| `type` | string | `'action'` | `'action'` 또는 `'filter'` |
| `sync` | bool | `false` | `true`: 큐 드라이버 무관하게 동기 실행 |

### 내부 구현

`HookListenerRegistrar`가 리스너 등록 시 큐/동기 분기를 처리합니다:

- Action + `sync: false` (기본) → `DispatchHookListenerJob`으로 래핑하여 `dispatch()`
- Action + `sync: true` → 기존 방식 동기 실행
- Filter → 항상 동기 실행

`DispatchHookListenerJob`은 리스너 클래스명과 메서드명을 직렬화하고, 큐 워커에서 DI 컨테이너로 리스너를 재생성하여 호출합니다.

### 사용자 컨텍스트 자동 복원

큐 워커는 별도 프로세스이므로 `Auth::user()`, `request()->ip()`, `App::getLocale()` 등이 모두 리셋됩니다. 이를 보완하기 위해 `HookContextCapture`가 디스패치 시점에 다음 항목을 자동 캡처하고, 워커에서 복원합니다:

| 항목 | 캡처 출처 | 복원 효과 |
| ---- | --------- | --------- |
| `user_id` | `Auth::id()` | 워커에서 `Auth::user()` 사용 가능 (활동로그 actor 정상 기록) |
| `ip_address` | `request()->ip()` | 워커에서 `request()->ip()` 사용 가능 |
| `user_agent` | `request()->userAgent()` | 워커에서 `request()->userAgent()` 사용 가능 |
| `locale` | `App::getLocale()` | 워커에서 다국어 메시지가 원래 요청 로케일로 발송됨 |
| `path` | `request()->path()` | `ResolvesActivityLogType`이 워커에서 정상 동작 |

리스너 코드는 변경 불필요 — 평소처럼 `Auth::user()`, `request()->ip()` 호출하면 됩니다.

#### 확장 컨텍스트 추가 (플러그인)

플러그인이 `tenant_id`, `trace_id` 등 추가 컨텍스트를 캡처/복원하려면 코어 수정 없이 훅으로 확장 가능:

```php
// 캡처 시 키 추가
HookManager::addFilter('hook.context.capture', function (array $context) {
    $context['tenant_id'] = app('tenant')->id;
    return $context;
});

// 복원 시 처리
HookManager::addAction('hook.context.restore', function (array $context) {
    if (! empty($context['tenant_id'])) {
        app('tenant')->setId($context['tenant_id']);
    }
});
```

### 주의사항

- 큐 디스패치 시 인자는 `HookArgumentSerializer`로 직렬화됩니다. Eloquent Model은 PK로 변환 후 워커에서 DB 재조회합니다.
- Closure 등 직렬화 불가능한 인자는 null로 대체되므로, 훅 인자로 Closure를 전달하지 마세요.
- 큐 드라이버가 `database`/`redis`일 때 큐 워커가 미실행이면 작업이 적체됩니다.
- 사용자 컨텍스트는 자동 복원되지만, `request()->session()` 등 세션 의존 정보는 복원되지 않습니다 (큐 컨텍스트는 단발 실행).
- 훅 인자에 비밀번호·토큰 같은 비밀값 원문을 싣지 마세요. 큐 구독자가 하나라도 있으면 인자 사본이 작업 대기 표(`jobs`, 처리에 실패하면 `failed_jobs`)에 남습니다. 요청 데이터를 통째로 넘기는 훅은 `Arr::except($data, ['password', 'password_confirmation'])` 처럼 비밀값을 빼고 넘깁니다(`core.auth.after_register`·`core.auth.record_consents`·`core.user.after_create`·`core.user.after_update` 가 이렇게 합니다). 비밀값이 꼭 필요한 구독자는 `'sync' => true` 로 받고, 그 훅의 인자 표에 비밀값이 있다고 적습니다(`core.auth.after_reset_password_request` 의 재설정 토큰).

---

## 코어 리스너 자동 발견

코어 애플리케이션의 훅 리스너는 `app/Listeners/` 디렉토리에서 **자동 발견**됩니다. `HookListenerInterface`를 구현한 클래스는 별도 등록 없이 자동으로 HookManager에 등록됩니다.

### 자동 발견 조건

| 조건 | 설명 |
|------|------|
| 위치 | `app/Listeners/` 및 모든 하위 디렉토리 |
| 인터페이스 | `HookListenerInterface` 구현 필수 |
| 등록 방식 | `CoreServiceProvider::boot()`에서 재귀 스캔 |

### 디렉토리 구조 예시

```text
app/Listeners/
├── ActivityLogListener.php           ✅ 자동 발견
├── CoreActivityLogListener.php       ✅ 자동 발견
├── ExtensionCompatibilityAlertListener.php  ✅ 자동 발견
├── Dashboard/
│   ├── DashboardModuleListener.php   ✅ 자동 발견 (하위 디렉토리)
│   └── DashboardStatsListener.php    ✅ 자동 발견 (하위 디렉토리)
└── UserLogin/
    └── UpdateLastLoginListener.php   ✅ 자동 발견 (하위 디렉토리)
```

### CoreServiceProvider 구현

```php
// app/Providers/CoreServiceProvider.php

private function registerCoreHookListeners(): void
{
    $listenersPath = app_path('Listeners');

    if (! is_dir($listenersPath)) {
        return;
    }

    // 재귀적으로 모든 PHP 파일 스캔
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($listenersPath, \RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // 파일 경로에서 클래스명 추출
        $relativePath = str_replace($listenersPath.DIRECTORY_SEPARATOR, '', $file->getPathname());
        $relativePath = str_replace('.php', '', $relativePath);
        $relativePath = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);
        $listenerClass = 'App\\Listeners\\'.$relativePath;

        // 클래스 존재 여부 확인
        if (! class_exists($listenerClass)) {
            Log::warning("코어 훅 리스너 클래스를 찾을 수 없습니다: {$listenerClass}");
            continue;
        }

        // HookListenerInterface 구현 여부 확인
        if (! in_array(HookListenerInterface::class, class_implements($listenerClass))) {
            continue;
        }

        $this->registerCoreHookListener($listenerClass);
    }
}
```

### 장점

| 장점 | 설명 |
|------|------|
| 자동 활성화 | 리스너 파일 생성만으로 훅 구독 시작 |
| ServiceProvider 수정 불필요 | 새 리스너 추가 시 코드 변경 없음 |
| 모듈/플러그인과 동일 패턴 | 일관된 아키텍처 |
| 메모리 효율 | 클로저 래퍼로 지연 인스턴스 생성 |

### 모듈/플러그인과의 차이점

| 항목 | 코어 리스너 | 모듈/플러그인 리스너 |
|------|------------|-------------------|
| 발견 방식 | 디렉토리 재귀 스캔 | `getHookListeners()` 메서드 |
| 위치 | `app/Listeners/**/*.php` | `modules/**/Listeners/`, `plugins/**/Listeners/` |
| 등록 주체 | `CoreServiceProvider` | `ModuleServiceProvider`, `PluginServiceProvider` |

---

## 정적 훅 매핑 캐시 (Static Hook Cache)

매 요청 부팅 시 코어(`app/Listeners` 재귀 스캔) + 모듈/플러그인(`getHookListeners()`)의 정적 훅 리스너를 발견·리플렉션·`getSubscribedHooks()` 클래스 로딩하는 비용을 제거하기 위해, 사전 계산한 훅 매핑을 `bootstrap/cache/hooks.php` 에 캐시합니다 (오토로드 캐시 `autoload-extensions.php` 와 동일 위치·생명주기).

캐시는 "무엇을 등록할지 목록" 만 제공하며 **등록 자체는 여전히 부팅에서 수행** 되므로 등록↔발화 순서 계약은 불변입니다. 캐시 경로 등록 결과는 스캔 경로와 **훅 매핑이 바이트 동일** 합니다 (`HookListenerRegistrar::registerFromCache()` 가 `register()` 와 동일한 `applySubscribedHooks()` 에 위임).

### 동작

| 상태 | 부팅 시 동작 |
|------|-------------|
| 캐시 존재 (`bootstrap/cache/hooks.php`) | 스캔·리플렉션·클래스 로딩 없이 캐시 매핑으로 등록 |
| 캐시 부재 / 손상 / 구조 불일치 | 기존 스캔 경로로 **안전 폴백** (항상 동작) |
| 테스트 환경 (`APP_ENV=testing`) | 캐시 미사용 — 매 setUp 스캔이 정확·격리 우선 |

- 동적 훅(알림 등 `registerDynamicHooks()`)은 캐시 대상이 아니며 코드 변경 없이 그대로 동작합니다. 캐시에는 각 리스너의 `dynamic` 플래그만 저장하여, 동적 훅 보유 코어 리스너의 boot 후반부 지연 실행 순서를 스캔 경로와 동일하게 유지합니다.
- 동적 훅의 DB 조회(`NotificationDefinitionService::getAllActive()`)는 이미 `['notification']` 태그로 캐시되어 있으므로 첫 요청(캐시 워밍) 이후 DB 조회가 발생하지 않습니다.

### 재생성 (무효화 = 재생성)

정적 훅 매핑은 **확장 변경 또는 코어 리스너 코드 배포 시에만** 바뀝니다. 해당 시점에 자동/수동 재생성됩니다:

| 사건 | 재생성 경로 |
|------|-----------|
| 확장 install / activate / deactivate / uninstall / update | `ExtensionManager::updateComposerAutoload()` 가 오토로드 캐시와 나란히 재생성 (자동) |
| 코어 업데이트 (`core:update`) | `clearAllCaches()` → `extension:update-autoload` 가 오토로드 + 훅 캐시 함께 재생성 (자동). 코어 리스너 추가/변경/삭제 반영 |
| 코어 리스너 코드 배포 | `php artisan hooks:cache` (배포 파이프라인 — `route:cache` 동형). 구독 옵션(`sync` 등)만 바뀐 배포도 해당한다 — 캐시는 파일 부재·손상에만 폴백하므로 굽지 않으면 이전 구독이 조용히 계속 쓰인다 |

```bash
php artisan hooks:cache   # 정적 훅 매핑 캐시 생성 (bootstrap/cache/hooks.php)
php artisan hooks:clear   # 캐시 삭제 (삭제 후 스캔 폴백 — 항상 안전)
```

캐시 파일은 Git 미추적(`bootstrap/cache/*`) 이며 배포 환경마다 생성됩니다. 캐시가 없어도 스캔 폴백으로 정상 동작하므로 필수는 아니지만, 프로덕션 배포 시 부팅 비용 절감을 위해 `config:cache`/`route:cache` 와 함께 실행하는 것을 권장합니다.

**핵심 파일**: `app/Extension/HookCacheManager.php`, `app/Extension/HookListenerRegistrar.php` (`registerFromCache`)

### 동적 훅 리스너 (DB 기반)

DB 설정에 따라 훅 구독 대상이 동적으로 변하는 경우, 리스너에 `registerDynamicHooks()` 메서드를 구현합니다.
자동 발견 시스템이 이 메서드를 감지하여 `boot()` 후반부(DB 접근 가능 시점)에서 자동 호출합니다.

```php
class NotificationHookListener implements HookListenerInterface
{
    // 정적 훅은 빈 배열 (DB 기반이므로)
    public static function getSubscribedHooks(): array
    {
        return [];
    }

    public function handle(...$args): void {}

    /**
     * DB 기반 동적 훅을 등록합니다.
     * CoreServiceProvider 자동 발견 시스템이 boot 후반부에서 자동 호출합니다.
     */
    public function registerDynamicHooks(): void
    {
        // notification_definitions 테이블에서 훅 목록 조회 후
        // 각 훅을 HookListenerRegistrar::registerDynamicAction() 으로 등록
        foreach ($definitions as $definition) {
            foreach ($definition->hooks as $hook) {
                HookListenerRegistrar::registerDynamicAction(
                    hookName: $hook,
                    listenerClass: self::class,
                    method: 'dispatchForDefinition',
                    boundArgs: [$definition],  // 워커가 dispatchForDefinition($definition, ...$hookArgs) 로 복원 호출
                    priority: 30,
                );
            }
        }
    }
}
```

동적 훅도 **큐 디스패치가 기본**이다. 직접 `HookManager::addAction($hook, fn() => $this->send(...))` 로 등록하면
콜백이 훅 발화 시점(HTTP 요청 스레드)에서 동기 실행되어, 발송 대상이 많을 때 요청이 전체 처리 완료까지 막힌다
(정적 `getSubscribedHooks` 리스너는 `HookListenerRegistrar` 가 자동으로 큐 래핑하지만, 동적 등록은 Registrar 를
거치지 않으므로 이 누락이 발생하기 쉽다). 동적 등록은 반드시 `HookListenerRegistrar::registerDynamicAction()` 에
위임해 정적 등록과 동일한 큐/동기 정책을 적용한다. 직접 `dispatch(new DispatchHookListenerJob(...))` 를 리스너에
작성하지 않는다 — 큐 래핑 로직의 소유권은 Registrar 한 곳에 둔다.

| `registerDynamicAction` 인자 | 설명 |
|------|------|
| `hookName` | 구독할 훅 이름 |
| `listenerClass` / `method` | 큐 워커가 복원해 호출할 리스너 FQCN·public 메서드 |
| `boundArgs` | 훅 발화 인자 앞에 고정으로 붙일 인자 (예: DB 정의 모델). 직렬화 가능해야 함 |
| `priority` | 실행 우선순위 (기본 10) |
| `sync` | `true` 면 큐 래핑 없이 즉시 동기 실행 (큐 드라이버 무관). IDV 가드 등 요청 스레드 동기 실행이 필수일 때만 |

| 조건 | 설명 |
|------|------|
| 메서드명 | `registerDynamicHooks()` (덕 타이핑) |
| 호출 시점 | `CoreServiceProvider::boot()` 후반부 (DB 유효성 검증 후) |
| 별도 인터페이스 | 불필요 — `method_exists()` 체크 |
| 안전성 | 테이블 미존재 시 `Schema::hasTable()` 체크 필수 |
| 큐 정책 | `HookListenerRegistrar::registerDynamicAction()` 위임 (기본 큐, `sync: true` opt-out) — 직접 `addAction` 으로 동기 발송 금지 |

---

### HookManager::broadcast() — WebSocket 브로드캐스트

훅 리스너에서 WebSocket 브로드캐스트를 실행할 때 사용합니다.

```php
use App\Extension\HookManager;

HookManager::broadcast(
    'user.notifications.123',     // 채널
    'notification.received',      // 이벤트명
    ['subject' => '새 알림']      // 데이터
);
```

개별 Event 클래스(`app/Events/`)를 직접 생성하지 않습니다. `HookManager::broadcast()`가 내부적으로 `GenericBroadcastEvent`를 사용합니다.

---

## 우선순위 가이드라인

**우선순위 범위**: 1 ~ 100 (낮을수록 먼저 실행)

| 우선순위 | 용도       | 예시               |
| -------- | ---------- | ------------------ |
| 1-5      | 매우 높음  | 데이터 검증, 보안 체크 |
| 10       | 기본값     | 일반 리스너        |
| 15-20    | 낮음       | 알림, 로깅         |
| 25+      | 매우 낮음  | 분석, 통계         |

### 정렬 메커니즘

HookManager는 `ksort()`를 사용하여 우선순위별 정렬 후 실행합니다:

| 규칙 | 설명 |
| ---- | ---- |
| 정렬 방식 | `ksort()` — 숫자 오름차순 (낮을수록 먼저 실행) |
| 기본값 | `$priority = 10` (`addAction`, `addFilter` 공통) |
| 동일 우선순위 | 등록 순서대로 실행 (FIFO — 배열 push 순서 유지) |

```php
// 우선순위 5 → 10 → 20 순서로 실행
HookManager::addAction('order.created', $securityCheck, 5);
HookManager::addAction('order.created', $businessLogic, 10);
HookManager::addAction('order.created', $notification, 20);
```

---

## 모듈에 리스너 등록

### module.php에서 등록

```php
<?php

namespace Modules\Sirsoft\Ecommerce;

use App\Contracts\Extension\ModuleInterface;
use Modules\Sirsoft\Ecommerce\Listeners\ProductCacheInvalidationListener;
use Modules\Sirsoft\Ecommerce\Listeners\OrderNotificationListener;

class Module implements ModuleInterface
{
    // ... 기타 메서드들 ...

    /**
     * 훅 리스너 목록 반환
     *
     * @return array
     */
    public function getHookListeners(): array
    {
        return [
            ProductCacheInvalidationListener::class,
            OrderNotificationListener::class,
        ];
    }
}
```

---

## Service에서 훅 사용 패턴

```php
<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Repositories\ProductRepository;

class ProductService
{
    public function __construct(
        private ProductRepository $productRepository
    ) {}

    public function createProduct(array $data): Product
    {
        // 1. Before 훅 - 데이터 검증, 전처리
        HookManager::doAction('sirsoft-ecommerce.product.before_create', $data);

        // 2. 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-ecommerce.product.filter_create_data', $data);

        // 3. 비즈니스 로직 실행
        $product = $this->productRepository->create($data);

        // 4. After 훅 - 후처리, 알림, 캐시 등
        HookManager::doAction('sirsoft-ecommerce.product.after_create', $product, $data);

        return $product;
    }

    public function calculateProductPrice(Product $product): float
    {
        $basePrice = $product->price;

        // 필터 훅으로 가격 계산 (할인, 세금 등)
        $finalPrice = HookManager::applyFilters(
            'sirsoft-ecommerce.product.calculate_price',
            $basePrice,
            $product
        );

        return $finalPrice;
    }
}
```

### After 훅은 상태를 확정한 뒤 발화한다

`after_*` 훅의 소비자(알림·활동 로그)는 넘겨받은 모델의 **현재 상태를 읽는다**. 그래서 상태 전이와 훅 발화의 순서가 계약이다 — 훅을 먼저 발화하면 정상 경로에서도 소비자가 보는 값이 전이 **전** 값이라, '완료' 를 통지하면서 본문에는 '대기' 라고 적는 알림이 나간다. 예외도 오류도 남지 않고 문구만 어긋난다.

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| `doAction(...)` 뒤에 상태 전이 | 전이 → `doAction(...)` |
| 전이 전 인스턴스를 훅 인자로 전달 | 전이가 돌려준 인스턴스(또는 `refresh()` 한 모델)를 전달 |
| 전이가 거절됐는데 훅은 그대로 발화 | 전이 실패 시 훅도 발화하지 않는다 — 훅 이름이 약속하는 사실이 성립하지 않았다 |
| 문서가 "옮겨지고 … 훅이 발행됩니다" 로 약속하는데 구현은 반대 순서 | 문서의 순서 서술과 구현을 일치시킨다(어느 쪽을 정본으로 삼을지 먼저 정한다) |

전이 결과를 되받지 않으면 순서만 바꾸고 효과가 없다 — 서비스의 `transition()` 이 새 인스턴스를 돌려주는 구조에서는 지역 변수가 낡은 채로 남는다.

```php
// ✅ 전이 → (성공한 경우에만) 훅. 훅 인자는 전이 결과 인스턴스.
if ($this->statusOf($claim) === 'payment_pending') {
    try {
        $claim = $this->transition($claim, 'paid');
    } catch (DomainException $e) {
        Log::info('전이 거절 — 상태 유지', ['reason' => $e->getMessageKey()]);

        return $payment;   // 훅도 발화하지 않는다
    }
}

HookManager::doAction('...payment_completed', $claim, $payment);
```

### 완료 훅은 모든 진입점이 거치는 계층에서 한 번 발화한다

같은 작업을 여러 진입점(어드민 UI·Artisan 커맨드·스케줄러·큐 작업)이 수행하면, 완료 훅은 **그 진입점들이 모두 거치는 가장 안쪽 계층**에서 발화한다. 바깥 계층(Service·Controller)에서만 발화하면 그 계층을 거치지 않는 진입점에서 구독 리스너가 조용히 누락된다 — 확장 생명주기 훅이 Service 에서만 발화해, CLI 로 확장을 업데이트하면 큐 워커 재시작·활동 로그·언어팩 동기화가 모두 빠졌던 것이 그 사례다(공개 이슈 #138). 같은 사건을 두 계층에서 발화하면 리스너가 두 번 돌아 활동 로그가 두 줄 남으므로, 안쪽으로 옮길 때는 바깥 발화를 **같은 변경에서** 제거한다.

### 확장 생명주기 훅 계약

모듈·플러그인·템플릿의 설치·활성화·업데이트·제거 완료 훅은 Manager(`ModuleManager`·`PluginManager`·`TemplateManager`)가 발화한다. 어드민 UI(Service 경유)와 Artisan 커맨드(Manager 직접) 모두에서 **정확히 한 번** 발화한다.

| 사건 | 훅 | 인자 | 비고 |
|------|-----|------|------|
| 모듈 설치 | `core.modules.after_install` | `(string $identifier, array $info)` | |
| 모듈 활성화 | `core.modules.after_activate` | `(string $identifier, array $info)` | |
| 모듈 비활성화 | `core.modules.after_deactivate` | `(string $identifier)` | |
| 모듈 업데이트 | `core.modules.after_update` | `(string $identifier, array $result, array $info)` | 업데이트할 것이 없을 때도 `result.success = false` 로 발화 |
| 모듈 제거 | `core.modules.after_uninstall` | `(string $identifier, array $info, bool $deleteData)` | `$info` 는 제거 전 캡처 |
| 플러그인 설치 | `core.plugins.after_install` | `(string $identifier, array $info)` | |
| 플러그인 활성화 | `core.plugins.after_activate` | `(string $identifier, array $info)` | |
| 플러그인 비활성화 | `core.plugins.after_deactivate` | `(string $identifier)` | |
| 플러그인 업데이트 | `core.plugins.after_update` | `(string $identifier, array $result, array $info)` | 업데이트할 것이 없을 때도 발화 |
| 플러그인 제거 | `core.plugins.after_uninstall` | `(string $identifier, bool $deleteData, bool $result)` | **인자 순서가 다르다.** 실패(`$result = false`)에도 발화 |
| 템플릿 설치 | `core.templates.after_install` | `(string $identifier, array $info)` | DB 트랜잭션 밖에서 발화 |
| 템플릿 활성화 | `core.templates.after_activate` | `(array $info)` | **식별자 없이** 정보 배열 1개(`$info['identifier']`) |
| 템플릿 비활성화 | `core.templates.after_deactivate` | `(string $identifier)` | |
| 템플릿 버전 업데이트 | `core.templates.after_version_update` | `(string $identifier, array $result, array $info)` | 업데이트할 것이 없을 때도 발화 |
| 템플릿 제거 | `core.templates.after_uninstall` | `(string $identifier, array $info, bool $deleteData)` | `$info` 는 제거 전 캡처 |

- `core.templates.after_update` 는 템플릿 **레코드 수정**(`TemplateService::updateTemplate()`) 훅이다. 템플릿 버전 업데이트를 구독하려면 `after_version_update` 를 쓴다.
- `$info` 는 확장 정보 배열이며 정보를 구할 수 없으면 빈 배열이다(템플릿 활성화는 `['identifier' => ...]`). 리스너는 `$info['version'] ?? null` 처럼 키 부재를 전제로 읽는다.
- 모듈·플러그인 `$info['permissions']` 는 권한 분류 목록(분류마다 `identifier`·`name`·`permissions[]`)이다. 플러그인은 7.1.0 부터 이 모양이며, 종전에는 권한 선언 전체(`{name, description, categories}`)였다 — 그때 `$info['permissions']['categories']` 를 읽던 리스너는 `$info['permissions']` 를 그대로 읽는다.
- 직전 훅도 Manager 가 CLI·어드민 UI 공통으로 한 번 발화한다(7.1.0~). 인자는 종전 Service 발화와 같다 — 모듈 `before_install(id)`·`before_activate(id, force)`·`before_deactivate(id, force)`·`before_update(id)`·`before_uninstall(id, deleteData)`, 플러그인 `before_install|activate|deactivate|update(id)`·`before_uninstall(id, deleteData)`, 템플릿 `before_install|activate|deactivate|version_update(id)`·`before_uninstall(id, deleteData)`.
- 직전 훅은 "시도" 시점에 발화하므로 없는 식별자처럼 실패할 호출에서도 발화한다. 직전 훅 리스너가 예외를 던지면 그 작업이 멈춘다 — 이제 CLI 설치·업데이트에도 똑같이 적용된다.

**구훅 (deprecated)** — `core.{modules,plugins,templates}.installed` · `activated` · `updated` 9개는 식별자 1개만 넘기는 이전 이름이다. 코어·번들 확장은 모두 정규 훅으로 옮겼고, 사외 확장 호환을 위해 성공 시 계속 발화하지만 다음 메이저에서 제거할 예정이다. 새 구독은 위 정규 훅을 쓴다(구훅 목록은 아래 「사용 중단 훅」). 구훅은 "업데이트할 것 없음" 에서는 발화하지 않는다.

### 공개 훅 호환 원칙

공개 훅(코어·번들 확장이 발화하는 훅)의 **이름과 인자 위치는 서드파티 확장이 코드에 박아 쓰는 계약**이다. 바꾸면 업데이트한 사이트에서 서드파티 리스너가 조용히 멈추거나 오류를 낸다.

- 마이너 버전에서는 공개 훅의 이름을 바꾸거나 지우지 않고, 인자를 빼거나 순서를 바꾸지 않는다. 뒤에 선택 인자를 더하는 것만 허용된다.
- 바꿔야 하면 **새 이름의 훅을 더하고 옛 훅은 지금 모양 그대로 계속 발화**한다. 옛 훅은 아래 「사용 중단 훅」 표(기본 제공 확장의 훅은 그 확장 문서)와 CHANGELOG 에 대체 이름과 함께 적는다. 제거는 메이저 버전에서만 한다.
- 같은 훅이 발화처마다 다른 모양으로 나가고 있으면 기존 훅을 "정리" 하지 않는다 — 모양을 이름에 드러낸 새 훅(예: `core.templates.after_list_paginated`, `core.menu.after_update_order_flat`)을 나란히 발화한다.
- 문서·선언에는 있는데 발화처가 없는 훅은 문서에서 지우기보다 **발화를 만든다**(그 문서를 보고 구독한 확장이 있다). 발화가 불가능하면(예: 브라우저에서 일어나는 흐름) 선언·문서에서 빼고 CHANGELOG 에 확장 개발자 영향으로 적는다.
- 이름을 옮길 때 액션은 `HookManager::doActionWithLegacyName($new, $old, ...$args)`, 필터는 `applyFiltersWithLegacyName()` 을 쓴다. 옛 이름은 구독자가 있을 때만 발화하고 개명 경고를 한 번 남긴다. **공개 배포본에서 한 번도 발화된 적 없는 옛 이름은 되살리지 않는다**(한 번도 돈 적 없는 코드가 갑자기 돈다).

**장치**

| 장치 | 위치 | 하는 일 |
|------|------|---------|
| 공개 훅 목록 | [hook-catalog.md](hook-catalog.md) (자동 생성) | 코어가 발화하는 훅 이름·유형·인자 개수. 기본 제공 확장의 훅은 각 확장의 확장 지점 문서(`docs/extension-points.md` 「발행 훅」)에 있다 |
| 옛 이름 병행 발화 | `HookManager::doActionWithLegacyName()` · `applyFiltersWithLegacyName()` | 표준 이름을 먼저 발화하고, 옛 이름에 구독자가 있을 때만 같은 인자로 한 번 더 발화한다 |
| 변경 검사 | G7 개발 과정(공개판에는 포함하지 않는다) | 직전 공개 릴리스와 현재 코드를 각각 훑어, 공개 훅의 이름 삭제·인자 개수 감소를 막는다. 아직 배포되지 않은 훅은 개발 중에 바꿀 수 있다 |

### 사용 중단 훅

새 구독에 쓰지 않는다. 대체 훅이 있으면 그쪽으로 옮긴다. 「공개 배포 발화 이력」 이 「있음」 인 훅은 다음 메이저까지 계속 발화한다. 「없음」 인 이름은 공개 배포본에서 한 번도 발화된 적이 없어 되살리지 않는다.

| 훅 | 대체 | 공개 배포 발화 이력 | 사유 |
|----|------|--------------------|------|
| `core.modules.installed` | `core.modules.after_install` | 있음 | 식별자 1개만 전달하는 구훅 |
| `core.modules.activated` | `core.modules.after_activate` | 있음 | 식별자 1개만 전달하는 구훅 |
| `core.modules.updated` | `core.modules.after_update` | 있음 | 식별자 1개만 전달하는 구훅 |
| `core.plugins.installed` | `core.plugins.after_install` | 있음 | 식별자 1개만 전달하는 구훅 |
| `core.plugins.activated` | `core.plugins.after_activate` | 있음 | 식별자 1개만 전달하는 구훅 |
| `core.plugins.updated` | `core.plugins.after_update` | 있음 | 식별자 1개만 전달하는 구훅 |
| `core.templates.installed` | `core.templates.after_install` | 있음 | 식별자 1개만 전달하는 구훅 |
| `core.templates.activated` | `core.templates.after_activate` | 있음 | 식별자 1개만 전달하는 구훅 |
| `core.templates.updated` | `core.templates.after_version_update` | 있음 | 식별자 1개만 전달하는 구훅 (레코드 수정용 after_update 와 다르다) |
| `core.auth.register` | `core.auth.after_register` | 없음 | 발화 이름은 처음부터 after_register 였다 |
| `core.auth.forgot_password` | `core.auth.after_reset_password_request` | 없음 | 발화 이름은 after_reset_password_request 다 |
| `core.auth.reset_password` | `core.auth.after_password_changed` | 없음 | 발화 이름은 after_password_changed 다 |
| `core.module.after_activate` | `core.modules.after_activate` | 없음 | 생명주기 훅은 복수형(core.modules.*)이다 |
| `core.module.after_deactivate` | `core.modules.after_deactivate` | 없음 | 생명주기 훅은 복수형(core.modules.*)이다 |
| `core.plugin.after_activate` | `core.plugins.after_activate` | 없음 | 생명주기 훅은 복수형(core.plugins.*)이다 |
| `core.plugin.after_deactivate` | `core.plugins.after_deactivate` | 없음 | 생명주기 훅은 복수형(core.plugins.*)이다 |
| `core.identity.message.before_send` | `core.notification.before_channel_send` | 있음 | 본인인증 메일이 알림 시스템으로 통합됨(7.1.0) — 호환용으로 계속 발화. 4종 공통으로 인자에서 `template_id`·`rendered` 가 빠지고 `definition_id` 는 알림 정의 ID, `notification_type` 추가. 건너뛴 발송도 `send_failed`(`delivery_status` = `skipped`) |
| `core.identity.message.after_send` | `core.notification.after_channel_send` | 있음 | 〃 |
| `core.identity.message.send_failed` | `core.notification.channel_send_failed` | 있음 | 〃 |
| `core.identity.message.resolve_failed` | — | 있음 | 〃 — 정책·목적·기본 문구가 모두 없을 때만 발화하며 알림 쪽 대응 훅은 없다 |

**7.1.0 사용 중단 — 본인인증 메시지 관리 훅 23종** — 본인인증 메시지 전용 관리 기능이 알림 정의(`category = identity`)로 합쳐졌다.
옛 이름은 **본인인증 분류의 정의·템플릿을 다룰 때만** 새 알림 훅과 같은 시점·같은 인자 개수로 계속 발화한다(공개 배포 발화 이력 있음,
8.0 제거). 인자로 넘어가는 대상이 옛 본인인증 메시지 모델에서 알림 정의(`NotificationDefinition`)·템플릿(`NotificationTemplate`) 모델로
바뀌었으므로, 모델 속성을 읽는 구독자는 그에 맞게 고친다. 새 구독은 오른쪽 알림 훅으로 한다.

| 사용 중단 훅 (본인인증 분류에서만 발화) | 대체 |
|---------|---------|
| `core.identity.message_definition.{before,after}_create` · `filter_create_data` · `store_validation_rules` | `core.notification_definition.{before,after}_create` · `filter_create_data` · `store_validation_rules` |
| `core.identity.message_definition.{before,after}_delete` | `core.notification_definition.{before,after}_delete` |
| `core.identity.message_definition.{before,after}_update` · `filter_update_data` · `update_validation_rules` · `index_validation_rules`(목록이 `category=identity` 일 때) · `{before,after}_toggle_active` | `core.notification_definition.*` 같은 이름 |
| `core.identity.message_template.{before,after}_update` · `filter_update_data` · `update_validation_rules` · `{before,after}_toggle_active` | `core.notification_template.*` 같은 이름 |
| `core.identity.message_template.{before,after}_reset` · `reset_no_default` | 알림 템플릿·정의 기본값 복원 경로(대응 알림 훅 없음) |
| `core.identity.filter_default_message_definitions` | `core.notification.filter_default_definitions` (옛 이름은 본인인증 분류 기본값 목록만 받는다) |

**7.1.0 에서 발화가 멈춘 이름** — 이름을 조립하던 발화처라 공개 훅 목록의 고정 이름이 아니다.

| 멈춘 이름 | 옮길 곳 |
|---------|---------|
| `core.identity.message.send_{channel}` | `core.notification.before_channel_send` / `after_channel_send` |
| `seed.identity_messages.translations` · `seed.{확장}.identity_messages.translations` | `seed.notifications.translations` · `seed.{확장}.notifications.translations` |

---

### 수신자별 알림 값 — `{prefix}.notification.personalize_data`

코어가 알림 한 건을 만들 때 데이터는 **수신자를 알기 전에 한 벌만** 만들어진다. 그래서 같은 알림을 관리자와 구매자가 함께 받으면 링크도 한 벌이고, 관리자가 자기 화면 대신 마이페이지로 가게 된다.

코어는 수신자 루프 **안에서** 아래 필터를 발행한다(`NotificationHookListener::personalize()`). 구독자가 없으면 값이 그대로이므로 비용은 0이다.

```php
// 발행: 수신자 한 사람마다 1회
HookManager::applyFilters("{$prefix}.notification.personalize_data", $data, $notifiable, $definition->type, $context, $args);
```

| 인자 | 무엇인가 |
|------|----------|
| `$data` | 이 수신자에게 나갈 치환 값 배열 (반환값이 그대로 쓰인다) |
| `$notifiable` | 이번 수신자 — `User` 또는 게스트(`GuestNotifiable`) |
| `$type` | 알림 정의의 유형 문자열(`$definition->type`) — 정의 모델이 아니다. 구독자는 이 값으로 자기 알림인지 가른다 |
| `$context` | 발행 측이 `extract_data` 에서 실어 둔 **스칼라** 컨텍스트 |
| `$args` | 원래 훅 인자 |

**규약 — `personalize` 는 `$context` 의 스칼라만 읽는다.** `$args` 의 모델을 다시 읽으면 안 된다. 알림은 큐를 타고 나중에 실행되므로 그 시점의 모델은 **"지금" 상태**다. 과거 사건을 알리는 알림에 현재 상태가 실리고, 오류도 로그도 남지 않는다. 필요한 값은 발행 시점에 `$context` 에 스칼라로 실어 둔다.

**링크 승격은 역할 이름이 아니라 권한으로 가른다.** "이 사람에게 관리자 주소를 줘도 되는가" 는 그 사람이 실제로 그 화면을 열 수 있는지(`NotificationRecipientCapability::can()`)로 판정한다. 역할 이름을 리스너에 박으면 상점이 새 역할을 만들 때마다 리스너를 고쳐야 하고, 권한 없는 사람에게 관리자 주소가 간다.

---

## 필터 훅 리스너 예시

### `type` 필드 사용법

`getSubscribedHooks()` 메서드에서 `type` 필드를 사용하여 훅 타입을 명시해야 합니다.

| type 값 | 설명 | 기본값 |
|---------|------|--------|
| `action` | Action 훅 - 반환값 없음 | ✅ (생략 시 기본값) |
| `filter` | Filter 훅 - 반환값 필수 | |

**중요**: Filter 훅 리스너는 반드시 `'type' => 'filter'`를 명시해야 합니다. 명시하지 않으면 Action 훅으로 처리되어 **반환값이 무시**됩니다.

### Filter 훅 리스너 구현

```php
<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;

class ProductPriceCalculationListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            // Filter 훅은 반드시 'type' => 'filter' 명시
            'sirsoft-ecommerce.product.calculate_price' => [
                'method' => 'applyDiscounts',
                'priority' => 10,
                'type' => 'filter',  // 필수!
            ],
        ];
    }

    /**
     * 할인 적용
     *
     * @param float $price 기본 가격
     * @param Product $product 상품 객체
     * @return float 할인 적용된 가격
     */
    public function applyDiscounts(float $price, $product): float
    {
        // 10% 할인 적용 (예시)
        if ($product->category->name === 'Special') {
            $price = $price * 0.9;
        }

        return $price;  // 반드시 값을 반환해야 함
    }
}
```

### Action과 Filter 혼합 사용

하나의 리스너에서 Action 훅과 Filter 훅을 함께 구독할 수 있습니다:

```php
<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;

class UserNotificationSettingsListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            // Filter 훅: 데이터 변형 (type 필수)
            'core.user.filter_create_data' => [
                'method' => 'filterCreateData',
                'priority' => 10,
                'type' => 'filter',
            ],
            'core.user.filter_update_data' => [
                'method' => 'filterUpdateData',
                'priority' => 10,
                'type' => 'filter',
            ],
            'core.user.filter_resource_data' => [
                'method' => 'filterResourceData',
                'priority' => 10,
                'type' => 'filter',
            ],

            // Action 훅: 부가 작업 (type 생략 가능)
            'core.user.after_create' => [
                'method' => 'afterCreate',
                'priority' => 10,
                // type 생략 = 'action'
            ],
        ];
    }

    /**
     * 생성 데이터 필터: 모듈 필드 추출 후 제거
     *
     * @param array $data 요청 데이터
     * @return array 모듈 필드가 제거된 데이터
     */
    public function filterCreateData(array $data): array
    {
        // 모듈 필드 추출 및 임시 저장
        $moduleData = $this->extractModuleData($data);
        session(['module_data' => $moduleData]);

        // 모듈 필드 제거 후 반환
        return $this->removeModuleFields($data);
    }

    /**
     * 생성 후 액션: 임시 저장된 데이터로 모듈 데이터 저장
     *
     * @param User $user 생성된 사용자
     * @param array $originalData 원본 요청 데이터
     * @return void
     */
    public function afterCreate(User $user, array $originalData): void
    {
        $moduleData = session('module_data', []);
        session()->forget('module_data');

        if (!empty($moduleData)) {
            $this->saveModuleData($user->id, $moduleData);
        }
    }

    /**
     * API 응답 필터: 모듈 데이터 병합
     *
     * @param array $data API 응답 데이터
     * @param User $user 조회 대상 사용자
     * @return array 모듈 데이터가 병합된 응답
     */
    public function filterResourceData(array $data, User $user): array
    {
        $moduleData = $this->getModuleData($user->id);
        return array_merge($data, $moduleData);
    }
}
```

### 회원 수정 화면에 확장 필드를 싣는 계약

회원 수정 화면(관리자 회원 수정, 마이페이지 프로필 수정)은 **코어 회원 저장 API 하나만** 호출합니다
(`PUT /api/admin/users/{user}`, `PUT /api/me`). 확장이 화면에 주입한 칸은 `name` 바인딩으로 같은 요청 본문에
실리고, 확장은 아래 코어 훅으로 자기 필드를 같은 요청 안에서 검증·저장합니다. 저장 성공 뒤 확장 전용
엔드포인트를 따로 부르면 한 번의 [저장]이 두 요청으로 갈라져 한쪽만 실패하는 상태가 생깁니다.

| 단계 | 훅 | 확장이 할 일 |
| --- | --- | --- |
| 검증 | `core.user.update_validation_rules`(관리자) · `core.user.update_profile_validation_rules`(본인) | 자기 필드 규칙을 추가한다. 두 번째 인자는 검증 중인 FormRequest 다. 구독하지 않은 키는 `validated()` 에 들어오지 않아 무시된다 |
| 분리 | `core.user.filter_update_data` | 자기 키를 데이터에서 제거해 반환한다 — `users` 쓰기에 섞이지 않게 |
| 저장 | `core.user.after_update` (`'sync' => true`) | 두 번째 인자(필터 적용 전 원본 데이터)에서 값을 읽어 저장한다. 코어 저장이 커밋된 뒤, 응답을 만들기 전에 실행된다 |

- 검증 오류는 필드 이름 키로 돌아오므로 화면은 주입 칸 아래에 `_local.errors.{필드}`(관리자) ·
  `_local.profileErrors.{필드}`(마이페이지)로 표시한다.
- 폼이 조회 응답으로 시드되면 저장된 값이 그대로 다시 전송된다. 저장된 값과 같은 값은 다시 검증하지 않아야
  운영자가 선택지를 나중에 비활성화했을 때도 다른 칸만 고친 저장이 막히지 않는다.
- 관리자 경로에서 확장 고유 권한이 필요하면 값이 **바뀔 때만** 확인하고, 없으면 그 필드 키로 검증 오류를 낸다.
- 등록(`POST /api/admin/users`)은 별도 훅(`core.user.create_validation_rules` · `core.user.filter_create_data` ·
  `core.user.after_create`)을 같은 방식으로 쓴다.
- 선례: sirsoft-ecommerce `UserPreferenceCoreSaveListener`(결제 통화·배송국가).

### SEO 훅 활용 예시 — 리뷰 플러그인이 JSON-LD에 리뷰 데이터 주입

```php
<?php

namespace Plugins\Sirsoft\Reviews\Listeners;

use App\Extension\Contracts\HookListenerInterface;

class SeoReviewMetaListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            'core.seo.filter_meta' => [
                'method' => 'injectReviewJsonLd',
                'priority' => 10,
                'type' => 'filter',  // 필수!
            ],
        ];
    }

    /**
     * SEO 메타에 리뷰 JSON-LD를 주입합니다.
     *
     * @param array $meta 메타 태그 배열
     * @param array $hookMeta 훅 메타 정보 (layoutName, moduleIdentifier 등)
     * @return array 수정된 메타 배열
     */
    public function injectReviewJsonLd(array $meta, array $hookMeta): array
    {
        // 상품 상세 페이지에서만 동작
        if ($hookMeta['moduleIdentifier'] !== 'sirsoft-ecommerce') {
            return $meta;
        }

        $context = $hookMeta['context'] ?? [];
        $productId = $context['route']['id'] ?? null;
        if (! $productId) {
            return $meta;
        }

        // 리뷰 데이터 조회 및 JSON-LD 주입
        $reviews = $this->getReviewAggregate($productId);
        if ($reviews && $meta['jsonLd']) {
            $jsonLd = json_decode($meta['jsonLd'], true);
            $jsonLd['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => $reviews['average'],
                'reviewCount' => $reviews['count'],
            ];
            $meta['jsonLd'] = json_encode($jsonLd, JSON_UNESCAPED_UNICODE);
        }

        return $meta;
    }
}
```

### 훅 타입별 동작 비교

| 구분 | Action 훅 | Filter 훅 |
|------|----------|-----------|
| `type` 필드 | 생략 가능 (`'action'` 기본값) | **필수** (`'type' => 'filter'`) |
| 반환값 | 무시됨 | **필수** (다음 필터로 전달) |
| HookManager 메서드 | `doAction()` | `applyFilters()` |
| 사용 목적 | 부가 작업 (로그, 알림, 캐시 등) | 데이터 변형 (필터링, 병합, 제거 등) |

---

## 훅 내부 메커니즘

### 중복 실행 방지 (가드 플래그)

HookManager는 `$dispatching` 배열을 사용하여 동일 훅의 중복 실행을 방지합니다. Laravel Event 시스템과의 하이브리드 동작에서 발생할 수 있는 무한 루프를 차단합니다.

```text
doAction('hook.name')
  → $dispatching['hook.name'] = true  (가드 설정)
  → Event::dispatch('hook.name')
  → 리스너 실행 중 doAction('hook.name') 재호출 시
    → $dispatching 체크 → 스킵 (중복 방지)
  → unset($dispatching['hook.name'])  (가드 해제)
```

### addAction/addFilter 내부 동작

```text
addAction()  → Event::listen() 등록 + 리스너 배열 관리
addFilter()  → Event::listen() 등록 + 리스너 배열 관리 (동일)
removeAction() / removeFilter() → Event::forget() + 배열에서 제거
```

---

## 훅 권한 시스템

G7은 훅 실행 시 **권한 체크**를 지원합니다. `permission_hooks` 테이블을 통해 특정 훅을 실행하려면 어떤 권한이 필요한지 매핑할 수 있습니다.

### 하이브리드 권한 체계

```text
┌─────────────────────────────────────────────────────────────┐
│                    권한 체크 레이어                          │
├─────────────────────────────────────────────────────────────┤
│  1단계: permission_hooks (기능 레벨)                        │
│         → 'core.attachment.download' 훅 실행 권한           │
│         → 이 기능 자체를 사용할 수 있는가?                   │
│         → 미매핑 시 모든 사용자 허용                         │
│         → 매핑 시 해당 퍼미션 보유자만 허용                  │
├─────────────────────────────────────────────────────────────┤
│  2단계: role_* (리소스 레벨)                                │
│         → 특정 파일/메뉴에 대한 접근 권한                    │
│         → 이 리소스를 읽을/수정할/삭제할 수 있는가?          │
│         → 현행 로직 유지                                    │
└─────────────────────────────────────────────────────────────┘
```

### permission_hooks 테이블

```php
Schema::create('permission_hooks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
    $table->string('hook_name')->comment('훅 이름 (예: core.attachment.download)');
    $table->timestamps();

    $table->unique(['permission_id', 'hook_name']);
    $table->index('hook_name');
});
```

### PermissionHook 모델

```php
// app/Models/PermissionHook.php

class PermissionHook extends Model
{
    protected $fillable = ['permission_id', 'hook_name'];

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    /**
     * 특정 훅에 매핑된 권한 ID 목록 조회
     */
    public static function getPermissionsForHook(string $hookName): array
    {
        return static::where('hook_name', $hookName)
            ->pluck('permission_id')
            ->toArray();
    }

    /**
     * 훅에 권한이 매핑되어 있는지 확인
     */
    public static function hasPermissionMapping(string $hookName): bool
    {
        return static::where('hook_name', $hookName)->exists();
    }
}
```

### HookManager의 권한 체크 메서드

```php
// app/Extension/HookManager.php

class HookManager
{
    /**
     * 훅 실행 전 권한 체크
     *
     * @param string $hookName 훅 이름
     * @param User|null $user 사용자 (null이면 비로그인)
     * @return bool 실행 허용 여부
     */
    public static function checkHookPermission(string $hookName, ?User $user): bool
    {
        // 1. 훅에 매핑된 권한이 없으면 모든 사용자 허용
        if (!PermissionHook::hasPermissionMapping($hookName)) {
            return true;
        }

        // 2. 비로그인 사용자는 권한 매핑된 훅 실행 불가
        if ($user === null) {
            return false;
        }

        // 3. 관리자는 모든 훅 실행 가능
        if ($user->hasRole('admin')) {
            return true;
        }

        // 4. 사용자의 역할에 해당 권한이 있는지 확인
        $requiredPermissionIds = PermissionHook::getPermissionsForHook($hookName);

        return $user->roles()
            ->whereHas('permissions', function ($query) use ($requiredPermissionIds) {
                $query->whereIn('permissions.id', $requiredPermissionIds);
            })
            ->exists();
    }

    /**
     * 권한 체크 후 Action 실행
     *
     * @param string $hookName 훅 이름
     * @param User|null $user 사용자
     * @param mixed ...$args 훅 인자
     * @return bool 실행 성공 여부
     */
    public static function doActionWithPermission(string $hookName, ?User $user, ...$args): bool
    {
        if (!static::checkHookPermission($hookName, $user)) {
            return false;
        }

        static::doAction($hookName, ...$args);
        return true;
    }

    /**
     * 권한 체크 후 Filter 적용
     *
     * @param string $hookName 훅 이름
     * @param User|null $user 사용자
     * @param mixed $value 필터링할 값
     * @param mixed ...$args 추가 인자
     * @return mixed 필터링된 값 (권한 없으면 원본 반환)
     */
    public static function applyFiltersWithPermission(string $hookName, ?User $user, $value, ...$args)
    {
        if (!static::checkHookPermission($hookName, $user)) {
            return $value;
        }

        return static::applyFilters($hookName, $value, ...$args);
    }
}
```

### 훅에 권한 매핑하기

```php
// 시더 또는 관리자 기능에서

// 'attachment.download' 권한을 'core.attachment.download' 훅에 매핑
$permission = Permission::where('identifier', 'attachment.download')->first();

PermissionHook::create([
    'permission_id' => $permission->id,
    'hook_name' => 'core.attachment.download',
]);
```

### 권한 매핑 해제 (모든 사용자 허용)

```php
PermissionHook::where('hook_name', 'core.attachment.download')->delete();
```

### Service에서 사용 예시

```php
// app/Services/AttachmentService.php

public function download(int $id, ?User $user): ?Attachment
{
    // 1단계: 기능 레벨 권한 체크 (permission_hooks)
    if (!HookManager::checkHookPermission('core.attachment.download', $user)) {
        throw new AuthorizationException(__('attachment.download_permission_denied'));
    }

    $attachment = $this->repository->findById($id);

    // 2단계: 리소스 레벨 권한 체크 (role_attachments)
    if (!$this->checkResourcePermission($user, $attachment, AttachmentPermissionType::Read)) {
        throw new AuthorizationException(__('attachment.resource_access_denied'));
    }

    return $attachment;
}
```

### 권한 체크 흐름 요약

```text
┌─────────────────────────────────────────────────────────────┐
│  checkHookPermission() 흐름                                 │
├─────────────────────────────────────────────────────────────┤
│  1. permission_hooks에 훅 매핑 확인                         │
│     → 매핑 없음: 모든 사용자 허용 (return true)             │
├─────────────────────────────────────────────────────────────┤
│  2. 비로그인 사용자                                          │
│     → 매핑 있으면 거부 (return false)                       │
├─────────────────────────────────────────────────────────────┤
│  3. 관리자(admin) 역할                                       │
│     → 모든 훅 실행 허용 (return true)                       │
├─────────────────────────────────────────────────────────────┤
│  4. 사용자 역할의 권한 확인                                  │
│     → 매핑된 권한 보유 여부 반환                            │
└─────────────────────────────────────────────────────────────┘
```

---

### SEO 캐시 무효화 리스너

모듈의 CRUD 훅에서 SEO 캐시를 무효화하는 패턴:

| 훅 | 리스너 메서드 | 무효화 대상 |
|----|-------------|-----------|
| `[module].product.after_create` | `onProductChange` | 해당 URL + 목록/카테고리 |
| `[module].product.after_update` | `onProductUpdate` | 해당 URL + 목록 |
| `[module].*.after_delete` | `onProductChange` | 전체 관련 캐시 |

캐시 무효화 시 `app(CacheInterface::class)->forget('seo.sitemap')` 도 함께 호출 (드라이버가 `g7:core:` 접두사 자동 적용)

> 구현 상세: [seo-system.md](../backend/seo-system.md)

---

## 관련 문서

- [공개 훅 목록](hook-catalog.md) - 코어·번들 확장이 발화하는 훅 전부 (자동 생성)
- [모듈 개발 기초](module-basics.md) - 모듈에서 훅 리스너 등록
- [Service-Repository 패턴](../backend/service-repository.md) - Service에서 훅 실행 패턴
- [활동 로그 시스템](../backend/activity-log.md) - 활동 로그 Listener 작성 패턴, Per-Item Bulk 로깅 규칙
- [권한 시스템](permissions.md) - Permission, Role 체계
- [확장 시스템 인덱스](README.md) - 전체 확장 시스템 개요
