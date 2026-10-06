# 동의 제공자 계약 (Consent Provider)

> 방문자가 쿠키·추적에 무엇을 동의했는지를 코어와 모든 확장이 같은 답으로 받는 계약입니다. 동의를 관리하는 확장(제공자)을
> 만드는 개발자와, 동의를 확인한 뒤 광고·분석 스크립트를 실행하려는 확장 개발자 모두를 위한 문서입니다.

## TL;DR (5초 요약)

```text
1. 제공자: ConsentProviderInterface 구현 → 필터 core.consent.registered_providers 로 등록 → 변경은 ConsentManager::reportChange()
2. 소비자(서버): app(ConsentManager::class)->snapshotFor($manager->subjectFor($request))->allows('marketing')
3. 소비자(브라우저): G7Core.consent.getSnapshot() + G7Core.consent.subscribe(fn) — 자체 조회·자체 이벤트 금지
4. status 가 'ready' 이고 needsRenewal 이 false 이고 해당 카테고리가 true 일 때만 실행한다. 'unknown' 은 미동의다(fail-closed)
5. 'none' 은 「사이트에 동의 관리 확장이 없음」이다 — 무엇을 할지는 소비 확장의 정책이다
```

## 목차

- [구성](#구성)
- [제공자 선택 규칙](#제공자-선택-규칙)
- [스냅샷](#스냅샷)
- [제공자 구현하기](#제공자-구현하기)
- [서버에서 동의 확인하기](#서버에서-동의-확인하기)
- [브라우저에서 동의 확인하기](#브라우저에서-동의-확인하기)
- [소비 규칙 (fail-closed)](#소비-규칙-fail-closed)
- [관련 문서](#관련-문서)

## 구성

| 부분 | 위치 | 하는 일 |
| --- | --- | --- |
| 계약 | `App\Contracts\Extension\ConsentProviderInterface` | 제공자가 구현할 메서드 6개 |
| 매니저 | `App\Extension\Consent\ConsentManager` (싱글턴) | 등록된 제공자 중 하나를 고르고, 주체의 스냅샷을 묻고, 변경 알림을 코어 액션으로 내보낸다 |
| 값 객체 | `ConsentSubject` · `ConsentSnapshot` · `ConsentSelection` (`App\Extension\Consent`) | 주체(회원 번호 또는 게스트 식별자), 스냅샷, 선택 상태 |
| 공개 API | `GET /api/consent` | 현재 방문자의 스냅샷 ([개인정보 API](../backend/api/privacy.md)) |
| 브라우저 런타임 | `_global.consent` · `G7Core.consent` · 핸들러 `refreshConsent` · 컴포넌트 이벤트 `consent:changed` | 스냅샷 보관, 로그인·로그아웃 때 다시 조회, 변경 알림 |
| 훅 | 필터 `core.consent.registered_providers`, 액션 `core.consent.changed` | 등록과 변경 알림 ([훅 시스템](hooks.md#동의-제공자-계약)) |

번들 플러그인 `sirsoft-gdpr` 가 이 계약의 제공자입니다. 다른 동의 관리 플러그인도 같은 계약으로 제공자가 될 수 있습니다.

## 제공자 선택 규칙

운영자는 환경설정 > 개인정보 탭의 「동의 관리 제공자」에서 고릅니다(설정 `privacy.consent_provider`, 빈 값은 「자동」).
「수집 중」은 제공자의 `isAvailable()` 이 `true` 라는 뜻입니다(예: GDPR 배너를 끄면 `false`).

| 상태 | 조건 | 결과 스냅샷 |
| --- | --- | --- |
| `none` (`no_provider`) | 자동인데 수집 중인 제공자가 0개 | `none` |
| `auto` | 자동인데 수집 중인 제공자가 1개 | 그 제공자의 답 |
| `ambiguous` | 자동인데 수집 중인 제공자가 2개 이상 | `unknown` — 운영자가 고를 때까지 모두 미동의 |
| `selected` | 고른 제공자가 등록돼 있고 수집 중 | 그 제공자의 답 |
| `none` (`provider_unavailable`) | 고른 제공자가 수집을 꺼 둠 | `none` |
| `missing` | 고른 제공자가 사라짐(비활성화·삭제) | `unknown` |

관리자 화면은 이 상태를 그대로 보여 주고(개인정보 탭 카드, 방문자 통계 설정의 동의 출처 배지), 설정 응답 `_meta.consent_selection`
(`state`·`reason`·`provider_id`·`provider_label`)과 `_meta.consent_providers` 로 받습니다.

## 스냅샷

서버 응답(snake_case)과 브라우저 `_global.consent`(camelCase)는 같은 값입니다.

| 필드 (브라우저) | 서버 | 의미 |
| --- | --- | --- |
| `contractVersion` | `contract_version` | 계약 버전. 지금은 `1`. 모르는 버전이면 브라우저 런타임은 `unknown` 으로 둔다 |
| `status` | `status` | `ready` 제공자가 답함, `unknown` 모름(부팅 중, 다시 조회 중, 조회 실패, 제공자 모호), `none` 동의 관리 제공자 없음 |
| `categories` | `categories` | 카테고리 → 동의 여부. 표준 어휘 `necessary`·`functional`·`analytics`·`marketing` 은 항상 실리고 `necessary` 는 항상 `true` |
| `needsRenewal` | `needs_renewal` | 정책이 바뀌어 다시 동의를 받아야 함. 이때 옛 동의는 쓰지 않는다 |
| `policyVersion` | `policy_version` | 현재 동의 정책 버전(표시용) |

스냅샷에는 회원 번호, 게스트 식별자, 토큰이 들어 있지 않습니다.

## 제공자 구현하기

### 1. 계약 구현

```php
use App\Contracts\Extension\ConsentProviderInterface;
use App\Extension\Consent\ConsentSnapshot;
use App\Extension\Consent\ConsentSubject;
use Illuminate\Http\Request;

class AcmeConsentProvider implements ConsentProviderInterface
{
    public function getId(): string { return 'acme-consent'; }          // 확장 식별자

    public function getLabel(): string { return __('acme-consent::messages.provider_label'); }

    public function isAvailable(): bool { return (bool) (plugin_settings('acme-consent')['banner_enabled'] ?? false); }

    public function getCategories(): array
    {
        return [['key' => 'necessary', 'label' => '필수', 'required' => true], /* ... */];
    }

    // 게스트를 찾는 식별자(서명 검증한 자기 쿠키 등). 회원 요청이면 null.
    public function resolveSubjectKey(Request $request): ?string { /* ... */ }

    public function getSnapshot(ConsentSubject $subject): ConsentSnapshot
    {
        $consents = $this->lookup($subject->userId, $subject->subjectKey);   // ['analytics' => true, ...]

        return ConsentSnapshot::ready($consents, needsRenewal: false, policyVersion: 'v3', providerId: $this->getId());
    }
}
```

- `getSnapshot()` 은 저장된 동의만 답합니다. 아직 아무것도 고르지 않은 게스트는 「모름」이 아니라 필수만 동의한 `ready` 로 답하는 것이
  자연스럽습니다(`ConsentSnapshot::ready([])`).
- 예외를 던지면 코어가 로그를 남기고 `unknown` 으로 닫습니다. 허용으로 새지 않습니다.
- 게스트 식별 규칙은 동의를 **저장할 때와 같아야** 합니다. 저장은 자기 쿠키 값으로 하고 조회는 라라벨 세션으로 하면, 동의한 게스트가
  늘 미동의로 보입니다.

### 2. 등록

```php
class RegisterAcmeConsentProviderListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return ['core.consent.registered_providers' => ['method' => 'register', 'type' => 'filter', 'priority' => 20, 'sync' => true]];
    }

    public function handle(...$args): void {}

    public function register(mixed $providers): array
    {
        $providers = is_array($providers) ? $providers : [];
        $providers[] = app(AcmeConsentProvider::class);

        return $providers;   // 앞선 구독자의 항목을 지우지 않는다
    }
}
```

확장을 비활성화하면 리스너가 부팅되지 않아 제공자도 사라집니다. 운영자가 그 제공자를 골라 두었으면 상태는 `missing` 이 됩니다.

### 3. 변경 알림

방문자가 동의를 바꾸면 코어에 알립니다. 코어는 지금 선택된 제공자의 알림만 액션 `core.consent.changed` 로 내보냅니다
(방문자 통계의 철회 집계 등이 이 액션을 구독합니다).

```php
app(ConsentManager::class)->reportChange('acme-consent', ConsentSubject::guest($key), ['analytics' => false]);
```

배너의 「모두 거부」처럼 한 번의 저장이 여러 항목을 바꾸면, 요청이 끝날 때 주체마다 한 번으로 묶어 알리세요.
`$changes` 에는 직전 결정과 **달라진** 카테고리만 넣습니다. 한 번도 동의하지 않은 방문자가 거부를 저장한 것을 `false` 로
알리면 철회 집계가 그 저장을 철회로 셉니다.

### 4. 브라우저

동의 저장 화면(배너, 마이페이지)은 저장 성공 뒤 코어 핸들러 `refreshConsent` 를 부릅니다. 런타임이 `/api/consent` 를 다시 읽어
`_global.consent` 를 바꾸고 `consent:changed` 를 냅니다. 제공자 확장의 차단 엔진도 이 이벤트로 따라갑니다 — 자체 조회나
자체 window 이벤트를 두면 로그인 직후처럼 둘 중 하나만 갱신되는 순간이 생깁니다.

```html
<api-call target="/api/plugins/acme-consent/consent" method="POST" body="...">
  <success>
    <action handler="refreshConsent"/>
  </success>
</api-call>
```

## 서버에서 동의 확인하기

```php
$manager = app(ConsentManager::class);
$snapshot = $manager->snapshotFor($manager->subjectFor($request));

if ($snapshot->allows('marketing')) {
    // 마케팅 동의가 확정된 방문자
}
```

- `allows($category)` 는 `ready` 이고 재동의가 필요 없고 그 카테고리가 `true` 일 때만 `true` 입니다.
- 방문자 통계용 판정은 `permitsAnalytics()`(분석), `permitsTracking()`(분석 + 기능 — 식별자 추적)입니다.
- 큐로 넘길 작업은 요청 시점에 `subjectFor($request)` 로 주체를 구해 함께 넘깁니다. 큐에는 요청이 없습니다.

## 브라우저에서 동의 확인하기

```ts
const consent = (window as any).G7Core?.consent;

function apply(snapshot) {
    if (snapshot?.status === 'ready' && !snapshot.needsRenewal && snapshot.categories?.marketing === true) {
        loadAdSdk();          // 여러 번 불려도 한 번만 실행되게 만든다
    }
}

const off = consent?.subscribe(apply);   // 해제 함수
apply(consent?.getSnapshot());           // 구독을 먼저 걸고 현재 값을 반영한다
```

- 동의 런타임은 사용자 화면에만 있습니다. 관리자 화면에서 `getSnapshot()` 은 `null` 입니다.
- 로그인·로그아웃은 페이지를 다시 띄우지 않지만, 런타임이 `unknown` 을 먼저 알리고 새 주체로 다시 읽습니다.
- 레이아웃에서는 `_global.consent` 를 바로 바인딩하고, 변경 시점이 필요하면 컴포넌트 이벤트 `consent:changed` 를 씁니다.

## 소비 규칙 (fail-closed)

| 스냅샷 | 광고·추적 실행 | 이유 |
| --- | --- | --- |
| `unknown` | 하지 않는다 | 아직 모르거나 조회가 실패했다. 모르는 것을 동의로 취급하지 않는다 |
| `ready` + 해당 카테고리 `false` | 하지 않는다 | 방문자가 거부했다 |
| `ready` + `needsRenewal: true` | 하지 않는다 | 옛 정책 동의는 새 정책에 쓰지 않는다 |
| `ready` + 해당 카테고리 `true` + `needsRenewal: false` | 한다 | 동의가 확정됐다 |
| `none` | 소비 확장의 정책 | 사이트에 동의 관리 확장이 없다. 코어 방문자 통계는 동의 모드에서 익명으로 수집한다 |

`unknown` 에서 `ready` 로 바뀌는 순간을 놓치지 않으려면 `subscribe` 를 씁니다. 반대로 이미 실행한 스크립트를 동의 철회 때
멈추는 것은 각 확장의 책임입니다(제공자의 차단 엔진은 새로 들어오는 스크립트를 막을 뿐입니다).

## 관련 문서

- [훅 시스템 — 동의 제공자 계약](hooks.md#동의-제공자-계약)
- [개인정보 API — `GET /api/consent`](../backend/api/privacy.md)
- [G7Core API — `G7Core.consent`](../frontend/g7core-api.md)
- [방문자 통계 확장 지점](analytics-extension-points.md)
