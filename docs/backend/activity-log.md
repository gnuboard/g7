# 활동 로그 시스템 (Activity Log System)

G7의 활동 로그는 Monolog 기반 커스텀 채널을 통해 DB에 기록됩니다.
Service 훅 이벤트를 Listener가 수신하여 `Log::channel('activity')` 로 전달하고,
ActivityLogHandler가 `activity_logs` 테이블에 저장합니다.

## TL;DR (5초 요약)

```text
1. Monolog 기반: Service 훅 → Listener → Log::channel('activity') → Handler → DB (3단계)
2. ActivityLogService는 조회 전용 (기록 메서드 없음 — Log::channel 직접 사용)
3. description_params에 ID 저장 → ActivityLogDescriptionResolver가 표시 시 이름 변환
4. 변경 추적: $activityLogFields 정의 필수 + ChangeDetector → changes JSON (미정의 시 null)
5. Bulk 작업 Per-Item 필수: N건 처리 시 N건 개별 로그 (집계 1건 금지), 삭제 시 loggable_type/loggable_id 직접 지정
6. 새 모듈 추가 시: Listener + DescriptionResolver + module.php 등록 + lang + $activityLogFields
```

---

## 목차

1. [아키텍처](#아키텍처)
2. [로깅 흐름](#로깅-흐름)
3. [Listener 작성 패턴](#listener-작성-패턴)
4. [변경 추적 (ChangeDetector)](#변경-추적-changedetector)
5. [다국어 키 규칙](#다국어-키-규칙)
6. [description_params 저장 정책](#description_params-저장-정책)
7. [ActivityLogDescriptionResolver 규정](#activitylogdescriptionresolver-규정)
8. [모듈 리스너 등록](#모듈-리스너-등록)
9. [모델별 로그 조회 API 패턴](#모델별-로그-조회-api-패턴)
10. [Bulk 로깅 패턴 (Per-Item 필수)](#bulk-로깅-패턴-per-item-필수)
11. [DB 스키마](#db-스키마)
12. [인덱스](#인덱스)
13. [금지 사항](#금지-사항)
14. [개발자 체크리스트](#개발자-체크리스트)

---

## 아키텍처

### Monolog 기반 3단계 파이프라인

```text
Service (doAction 훅 발행)
    ↓
Listener (CoreActivityLogListener / 모듈 XxxActivityLogListener)
    ↓
Log::channel('activity')->info($action, $context)
    ↓
ActivityLogProcessor (user_id, ip_address, user_agent 자동 주입)
    ↓
ActivityLogHandler (Monolog Handler → activity_logs 테이블 INSERT)
```

### 핵심 컴포넌트

| 컴포넌트 | 위치 | 역할 |
|----------|------|------|
| `ActivityLogChannel` | `app/ActivityLog/ActivityLogChannel.php` | Monolog Logger 팩토리 (`config/logging.php`의 `via` 클래스) |
| `ActivityLogHandler` | `app/ActivityLog/ActivityLogHandler.php` | Monolog Handler — context에서 데이터 추출 후 DB 저장 |
| `ActivityLogProcessor` | `app/ActivityLog/ActivityLogProcessor.php` | Monolog Processor — `user_id`, `ip_address`, `user_agent` 자동 주입 |
| `ResolvesActivityLogType` | `app/ActivityLog/Traits/ResolvesActivityLogType.php` | log_type 자동 결정 트레이트 — `Auth::user()` 기반 Admin/User/System 분류 |
| `ChangeDetector` | `app/ActivityLog/ChangeDetector.php` | 모델 스냅샷 비교 → 구조화된 변경 이력 생성 |
| `CoreActivityLogListener` | `app/Listeners/CoreActivityLogListener.php` | 코어 서비스 훅 구독 리스너 |
| `ActivityLog` | `app/Models/ActivityLog.php` | Eloquent 모델 (`localized_description` 접근자 포함) |
| `ActivityLogService` | `app/Services/ActivityLogService.php` | **조회 전용** 서비스 (기록 메서드 없음) |

### config/logging.php 채널 설정

```php
'activity' => [
    'driver' => 'custom',
    'via' => \App\ActivityLog\ActivityLogChannel::class,
    'level' => 'debug',
],
```

### config/activity_log.php

```php
return [
    'enabled' => env('ACTIVITY_LOG_ENABLED', true),  // false 시 Handler에서 DB 저장 건너뜀
    'channel' => env('ACTIVITY_LOG_CHANNEL', 'activity'),
];
```

---

## 로깅 흐름

### 1단계: Service에서 훅 발행

Service 계층에서 비즈니스 로직 수행 후 `doAction` 으로 훅을 발행합니다.

```php
// Service 내부 (예: UserService)
$this->hookManager->doAction('core.user.after_create', $user, $data);
```

### 2단계: Listener에서 로그 기록

Listener가 훅을 수신하여 `Log::channel('activity')->info()` 를 호출합니다.

```php
use Illuminate\Support\Facades\Log;

public function handleUserAfterCreate(User $user, array $data): void
{
    $this->logActivity('user.create', [
        'log_type' => ActivityLogType::Admin,
        'loggable' => $user,
        'description_key' => 'activity_log.description.user_create',
        'description_params' => ['user_id' => $user->uuid],
        'properties' => ['email' => $user->email, 'name' => $user->name],
    ]);
}

private function logActivity(string $action, array $context): void
{
    try {
        Log::channel('activity')->info($action, $context);
    } catch (\Exception $e) {
        Log::error('Failed to record activity log', [
            'action' => $action,
            'error' => $e->getMessage(),
        ]);
    }
}
```

### 3단계: Processor + Handler

1. **ActivityLogProcessor**: 리스너에서 명시적으로 전달하지 않은 `user_id`, `ip_address`, `user_agent`를 자동 주입
2. **ActivityLogHandler**: context에서 구조화 데이터를 추출하여 `ActivityLog::create()` 호출

### Context 배열 구조

| 키 | 타입 | 필수 | 설명 |
|----|------|------|------|
| `log_type` | `ActivityLogType` | O | 로그 유형 (Admin/User/System) |
| `loggable` | `Model` | - | 대상 모델 (morph 관계). 삭제 엔티티는 `loggable_type` + `loggable_id` 직접 지정 |
| `loggable_type` | `string` | - | `loggable` 대신 morph 타입 직접 지정 (삭제된 엔티티용) |
| `loggable_id` | `int` | - | `loggable` 대신 morph ID 직접 지정 (삭제된 엔티티용) |
| `description_key` | `string` | O | 다국어 번역 키 |
| `description_params` | `array` | - | 번역 파라미터 |
| `properties` | `array` | - | 추가 속성 (JSON) |
| `changes` | `array` | - | 변경 이력 (ChangeDetector 결과) |
| `user_id` | `int` | - | 자동 주입 (Processor) |
| `ip_address` | `string` | - | 자동 주입 (Processor) |
| `user_agent` | `string` | - | 자동 주입 (Processor, 500자 제한) |

---

## Listener 작성 패턴

### 기본 구조

```php
<?php

namespace Modules\Vendor\Module\Listeners;

use App\ActivityLog\ChangeDetector;
use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;

class XxxActivityLogListener implements HookListenerInterface
{
    use ResolvesActivityLogType;

    /** @var array<string, array> 스냅샷 저장소 */
    private array $snapshots = [];

    public static function getSubscribedHooks(): array
    {
        return [
            // before_update: 스냅샷 캡처 (priority 5 — 다른 리스너보다 먼저 실행)
            'vendor-module.entity.before_update' => ['method' => 'captureEntitySnapshot', 'priority' => 5],

            // after_xxx: 로그 기록 (priority 20)
            'vendor-module.entity.after_create' => ['method' => 'handleEntityAfterCreate', 'priority' => 20],
            'vendor-module.entity.after_update' => ['method' => 'handleEntityAfterUpdate', 'priority' => 20],
            'vendor-module.entity.after_delete' => ['method' => 'handleEntityAfterDelete', 'priority' => 20],
        ];
    }

    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    // ─── 스냅샷 캡처 ───

    public function captureEntitySnapshot(Model $entity, array $data): void
    {
        $this->snapshots['entity_' . $entity->id] = $entity->toArray();
    }

    // ─── 핸들러 ───

    public function handleEntityAfterCreate(Model $entity, array $data): void
    {
        // log_type 미지정 시 ResolvesActivityLogType 트레이트가 Auth::user() 기반 자동 결정
        $this->logActivity('entity.create', [
            'loggable' => $entity,
            'description_key' => 'vendor-module::activity_log.description.entity_create',
            'description_params' => ['entity_name' => $entity->name ?? ''],
            'properties' => ['id' => $entity->id],
        ]);
    }

    public function handleEntityAfterUpdate(Model $entity, array $data): void
    {
        $snapshot = $this->snapshots['entity_' . $entity->id] ?? null;
        $changes = ChangeDetector::detect($entity, $snapshot);

        $this->logActivity('entity.update', [
            'loggable' => $entity,
            'description_key' => 'vendor-module::activity_log.description.entity_update',
            'description_params' => ['entity_name' => $entity->name ?? ''],
            'changes' => $changes,
        ]);

        unset($this->snapshots['entity_' . $entity->id]);
    }

    public function handleEntityAfterDelete(Model $entity): void
    {
        $this->logActivity('entity.delete', [
            'loggable' => $entity,
            'description_key' => 'vendor-module::activity_log.description.entity_delete',
            'description_params' => ['entity_name' => $entity->name ?? ''],
        ]);
    }
}
```

### ResolvesActivityLogType 트레이트

모든 ActivityLog 리스너는 `ResolvesActivityLogType` 트레이트를 사용합니다.
이 트레이트는 두 가지 메서드를 제공합니다:

- **`resolveLogType()`** — `Auth::user()` 기반으로 log_type을 동적 결정:
  - Admin 역할 보유자 → `ActivityLogType::Admin`
  - 인증된 비관리자 → `ActivityLogType::User`
  - 비인증(비회원/CLI/시스템) → `ActivityLogType::System`

- **`logActivity(string $action, array $context)`** — `Log::channel('activity')->info()` 래퍼:
  - context에 `log_type`이 없으면 `resolveLogType()`으로 자동 주입
  - try-catch로 로그 실패가 비즈니스 로직을 중단하지 않도록 보호

**log_type 명시가 필요한 경우** (예: 시스템 자동 작업):

```php
$this->logActivity('schedule.auto_run', [
    'log_type' => ActivityLogType::System,  // 명시 시 auto-resolve 건너뜀
    'description_key' => 'activity_log.description.schedule_auto_run',
]);
```

### 핵심 규칙

| 규칙 | 설명 |
|------|------|
| `HookListenerInterface` 구현 필수 | `getSubscribedHooks()` + `handle()` 메서드 |
| `use ResolvesActivityLogType` 필수 | 모든 ActivityLog 리스너에 트레이트 적용 |
| before_update priority 5 | 다른 리스너보다 먼저 실행하여 변경 전 스냅샷 확보 |
| after_xxx priority 20 | 실제 로직 완료 후 기록 |
| `log_type` 하드코딩 금지 | 트레이트가 `Auth::user()` 기반으로 자동 결정 (필요 시 명시 가능) |
| 스냅샷 사용 후 `unset` | 메모리 누수 방지 |

---

## 변경 추적 (ChangeDetector)

### 모델에 $activityLogFields 정의 (필수)

> **주의**: ChangeDetector를 호출하는 모든 모델은 `$activityLogFields` 정의 필수입니다.
> 미정의 시 `ChangeDetector::detect()` 가 항상 `null` 을 반환하여 changes 컬럼이 빈 채로 기록됩니다.

추적할 필드를 모델에 `public static array $activityLogFields` 로 선언합니다.

```php
class Product extends Model
{
    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'name' => [
            'label_key' => 'sirsoft-ecommerce::product.name',
            'type' => 'text',
        ],
        'price' => [
            'label_key' => 'sirsoft-ecommerce::product.price',
            'type' => 'currency',
        ],
        'status' => [
            'label_key' => 'sirsoft-ecommerce::product.status',
            'type' => 'enum',
            'enum' => ProductStatus::class,  // Backed Enum 클래스 (labelKey() 메서드 필수)
        ],
        'is_visible' => [
            'label_key' => 'sirsoft-ecommerce::product.is_visible',
            'type' => 'boolean',
        ],
        'published_at' => [
            'label_key' => 'sirsoft-ecommerce::product.published_at',
            'type' => 'datetime',
        ],
    ];
}
```

### 지원 타입

| type | 설명 | 비고 |
|------|------|------|
| `text` | 일반 문자열 | 기본값 (type 생략 시) |
| `number` | 숫자 | 정수/실수 |
| `currency` | 통화 금액 | 프론트엔드에서 포맷팅 시 사용 |
| `date` | 날짜 | Y-m-d |
| `datetime` | 날짜+시간 | Y-m-d H:i:s |
| `enum` | Enum 값 | `enum` 키에 Backed Enum 클래스 지정 필수 |
| `boolean` | 참/거짓 | true/false |
| `json` | JSON 데이터 | 객체/배열 |

### ChangeDetector 사용법

```php
// 1. before_update 훅에서 스냅샷 캡처
public function captureSnapshot(Model $model, array $data): void
{
    $this->snapshots['model_' . $model->id] = $model->toArray();
}

// 2. after_update 훅에서 변경 감지
public function handleAfterUpdate(Model $model, array $data): void
{
    $snapshot = $this->snapshots['model_' . $model->id] ?? null;
    $changes = ChangeDetector::detect($model, $snapshot);
    // $changes 는 null (변경 없음) 또는 변경 배열
}
```

### changes 배열 구조

```json
[
    {
        "field": "status",
        "label_key": "sirsoft-ecommerce::product.status",
        "old": "draft",
        "new": "active",
        "type": "enum",
        "old_label_key": "sirsoft-ecommerce::enums.product_status.draft",
        "new_label_key": "sirsoft-ecommerce::enums.product_status.active"
    },
    {
        "field": "name",
        "label_key": "sirsoft-ecommerce::product.name",
        "old": "기존 상품명",
        "new": "새 상품명",
        "type": "text"
    }
]
```

- `old_label_key` / `new_label_key` 는 `type: "enum"` 일 때만 추가됩니다.
- Enum 클래스에 `labelKey(): string` 메서드가 필수입니다.
- Backed Enum 값은 자동으로 `->value` 로 변환하여 비교합니다.

---

## 다국어 키 규칙

### 코어

```text
activity_log.description.{entity}_{verb}
```

| 예시 키 | 설명 |
|--------|------|
| `activity_log.description.user_create` | 사용자 생성 |
| `activity_log.description.user_update` | 사용자 수정 |
| `activity_log.description.user_delete` | 사용자 삭제 |
| `activity_log.description.role_create` | 역할 생성 |
| `activity_log.description.settings_save` | 설정 저장 |

lang 파일 위치: `lang/ko/activity_log.php`, `lang/en/activity_log.php`

```php
// lang/ko/activity_log.php
return [
    'description' => [
        'user_create' => '회원 :user_id 생성',
        'user_update' => '회원 :user_id 정보 수정',
        'user_delete' => '회원 :user_id 삭제',
    ],
];
```

### 모듈

```text
{module-id}::activity_log.description.{entity}_{verb}
```

| 예시 키 | 설명 |
|--------|------|
| `sirsoft-ecommerce::activity_log.description.order_create` | 주문 생성 |
| `sirsoft-page::activity_log.description.page_update` | 페이지 수정 |
| `sirsoft-board::activity_log.description.post_delete` | 게시글 삭제 |

lang 파일 위치: `modules/_bundled/{id}/resources/lang/ko/activity_log.php`

```php
// modules/_bundled/sirsoft-page/resources/lang/ko/activity_log.php
return [
    'description' => [
        'page_create' => '페이지 ":page_title" 생성',
        'page_update' => '페이지 ":page_title" 수정',
        'page_delete' => '페이지 ":page_title" 삭제',
        'page_publish' => '페이지 ":page_title" 공개',
        'page_unpublish' => '페이지 ":page_title" 비공개 전환',
    ],
];
```

### 규칙

- 파라미터는 Laravel 표준 `:param` 문법 사용 (예: `:user_id`, `:page_title`)
- `ko` 와 `en` lang 파일 모두 정의 필수
- DB에는 번역 키(`description_key`)와 파라미터(`description_params`)만 저장
- 실시간 번역은 `ActivityLog::localized_description` 접근자에서 수행

---

## description_params 저장 정책

### 핵심 규칙: ID를 저장하고, 표시 시 이름으로 변환

`description_params` 에는 표시용 이름이 아닌 **ID** 를 저장합니다.
이름 변경 시 과거 로그도 최신 이름으로 표시되어야 하기 때문입니다.

```php
// ✅ 올바른 사용 — ID 저장
'description_params' => ['brand_id' => $brand->id],

// ❌ 금지 — 이름 직접 저장
'description_params' => ['brand_name' => $brand->name],
```

### 삭제 시 예외: properties에 이름 스냅샷 보존

엔티티 삭제 시에는 DB 조회가 불가하므로 `properties` 에 이름 스냅샷을 함께 저장합니다.
`ActivityLogDescriptionResolver` 가 `properties.name` 을 우선 사용합니다.

```php
public function handleEntityAfterDelete(Model $entity): void
{
    $this->logActivity('entity.delete', [
        'loggable' => $entity,
        'description_key' => 'vendor-module::activity_log.description.entity_delete',
        'description_params' => ['entity_id' => $entity->id],
        'properties' => ['name' => $entity->name],  // 삭제 후 DB 조회 불가 → 스냅샷 보존
    ]);
}
```

### properties vs changes vs description_params 역할 구분

| 컬럼 | 용도 | 저장 내용 |
|------|------|----------|
| `description_params` | 번역 키의 `:placeholder` 치환용 | 엔티티 ID (`ActivityLogDescriptionResolver` 가 표시 시 이름으로 변환) |
| `changes` | `ChangeDetector` 결과 | 필드별 `old`/`new` 구조화 데이터 (자동 생성) |
| `properties` | 추가 메타데이터 | bulk IDs, 삭제 시 이름 스냅샷, 기타 컨텍스트 데이터 |

---

## ActivityLogDescriptionResolver 규정

### 역할

`core.activity_log.filter_description_params` 필터 훅을 구독하는 리스너입니다.
활동 로그에 저장된 엔티티 ID를 **표시 시점에** 사람이 읽을 수 있는 이름으로 변환합니다.

### 해석 우선순위

1. **`properties.name` 스냅샷** — 로그 기록 시점의 이름 (삭제된 엔티티도 처리 가능)
2. **DB 조회** — `description_params` 의 ID로 모델 조회 → 현재 이름 반환
3. **Fallback** — DB 조회 실패 시 `"ID: {id}"` 문자열 반환

### 모듈별 구현 패턴

각 모듈은 자체 `ActivityLogDescriptionResolver` 를 구현합니다.

```php
<?php

namespace Modules\Vendor\Module\Listeners;

use App\Contracts\Extension\HookListenerInterface;

class ActivityLogDescriptionResolver implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            'core.activity_log.filter_description_params' => [
                'method' => 'resolveDescriptionParams',
                'type' => 'filter',   // ← Filter 훅 — type 명시 필수
                'priority' => 10,
            ],
        ];
    }

    public function handle(...$args): void
    {
        // 기본 핸들러는 사용하지 않음
    }

    public function resolveDescriptionParams(array $params, string $descriptionKey, array $properties): array
    {
        $prefix = 'vendor-module::activity_log.description.';
        if (! str_starts_with($descriptionKey, $prefix)) {
            return $params;  // 다른 모듈의 키 → 무시
        }

        $keySuffix = str_replace($prefix, '', $descriptionKey);

        return match (true) {
            str_starts_with($keySuffix, 'entity_') => $this->resolveEntityName($params, $properties),
            default => $params,
        };
    }

    private function resolveEntityName(array $params, array $properties): array
    {
        if (! empty($params['entity_name'])) {
            return $params;  // 이미 해석됨
        }

        // 1순위: properties.name 스냅샷
        if (! empty($properties['name'])) {
            $params['entity_name'] = $properties['name'];
            return $params;
        }

        // 2순위: DB 조회
        $id = $params['entity_id'] ?? null;
        if ($id) {
            $entity = Entity::find($id);
            $params['entity_name'] = $entity?->name ?? "ID: {$id}";
        }

        return $params;
    }
}
```

### 등록

모듈의 `module.php` 에서 `getHookListeners()` 에 등록합니다.

```php
public function getHookListeners(): array
{
    return [
        XxxActivityLogListener::class,
        ActivityLogDescriptionResolver::class,  // ← 추가
    ];
}
```

### 다국어 이름 처리

이름이 다국어 배열(`['ko' => '한국어', 'en' => 'English']`)인 경우 현재 로케일에 맞게 해석합니다.

```php
private function resolveI18nName(mixed $name): string
{
    if (is_string($name)) {
        return $name;
    }
    if (is_array($name)) {
        $locale = app()->getLocale();
        return $name[$locale] ?? $name['ko'] ?? (array_values($name)[0] ?? '');
    }
    return '';
}
```

---

## 모듈 리스너 등록

모듈의 `module.php` (AbstractModule 상속) 에서 `getHookListeners()` 메서드에 등록합니다.

```php
// modules/_bundled/sirsoft-page/module.php

namespace Modules\Sirsoft\Page;

use App\Extension\AbstractModule;
use Modules\Sirsoft\Page\Listeners\PageActivityLogListener;

class Module extends AbstractModule
{
    public function getHookListeners(): array
    {
        return [
            PageActivityLogListener::class,
            // 기타 리스너...
        ];
    }
}
```

AbstractModule이 `boot()` 시점에 `HookManager::registerListener()` 를 자동 호출합니다.
별도의 `HookManager::registerListener()` 직접 호출은 불필요합니다.

---

## 모델별 로그 조회 API 패턴

특정 모델(상품, 주문 등)에 대한 활동 로그를 조회하는 API 패턴입니다.

### Controller에 `logs()` 메서드 추가

```php
use App\Helpers\ResponseHelper;
use App\Http\Resources\ActivityLogResource;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends AdminBaseController
{
    public function __construct(
        private ActivityLogService $activityLogService,
        // ...
    ) {}

    /**
     * 상품 활동 로그 목록 조회
     *
     * @param Request $request 요청
     * @param Product $product 대상 상품
     * @return JsonResponse 로그 목록 응답
     */
    public function logs(Request $request, Product $product): JsonResponse
    {
        try {
            $filters = [
                'per_page' => (int) ($request->query('per_page', 10)),
                'page' => (int) ($request->query('page', 1)),
                'sort_order' => $request->query('sort_order', 'desc'),
            ];

            $logs = $this->activityLogService->getLogsForModel($product, $filters);

            return ResponseHelper::moduleSuccess(
                'vendor-module',
                'messages.entity.logs_fetch_success',
                ActivityLogResource::collection($logs)->response()->getData(true)
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'vendor-module',
                'messages.entity.logs_fetch_failed',
                500
            );
        }
    }
}
```

### 라우트 등록

```php
// routes/api.php (모듈)
Route::get('products/{product}/logs', [ProductController::class, 'logs']);
```

### 레이아웃 JSON에서 처리로그 탭 구현

```json
{
    "id": "activity_logs",
    "endpoint": "/api/admin/vendor-module/products/{{route.id}}/logs",
    "method": "GET",
    "autoFetch": false,
    "params": { "per_page": 10, "page": 1 }
}
```

---

## Bulk 로깅 패턴 (Per-Item 필수)

### 핵심 원칙

> **모든 bulk 작업은 Per-Item 로깅 필수** — 변경된 엔티티 건별로 `loggable_type`/`loggable_id`가 기록되어야 합니다.
> N건 일괄 처리 시 N건의 개별 로그가 생성됩니다.

```text
필수: bulk 작업은 Per-Item 개별 로그 기록 (1건 집계 로그 금지)
필수: 각 엔티티별 개별 로그 기록 (loggable => $model 또는 loggable_type/loggable_id 직접 지정)
```

### 패턴 1: Bulk Update — Service에서 스냅샷 + 개별 모델 update

```php
// Service 내부
public function bulkUpdateStatus(array $ids, string $newStatus): array
{
    $models = Model::whereIn('id', $ids)->get();
    $snapshots = [];

    foreach ($models as $model) {
        $snapshots[$model->id] = $model->toArray();
        $model->update(['status' => $newStatus]);
    }

    // 훅에 ids + snapshots 전달
    $this->hookManager->doAction('module.entity.after_bulk_status_update', $ids, count($models), $snapshots);

    return $snapshots;
}
```

### 패턴 2: Bulk Update — Listener에서 per-item 로그 기록

```php
public function handleAfterBulkStatusUpdate(array $ids, int $updatedCount, array $snapshots = []): void
{
    $models = Model::whereIn('id', $ids)->get()->keyBy('id');

    foreach ($ids as $id) {
        $model = $models->get($id);
        if (! $model) {
            continue;  // 존재하지 않는 ID는 건너뜀
        }

        $snapshot = $snapshots[$id] ?? null;
        $changes = $snapshot ? ChangeDetector::detect($model, $snapshot) : null;

        $this->logActivity('entity.bulk_status_update', [
            'loggable' => $model,                    // ← 개별 모델 지정 필수
            'description_key' => 'vendor-module::activity_log.description.entity_status_update',
            'description_params' => ['entity_id' => $id],
            'changes' => $changes,
            'properties' => ['entity_id' => $id],
        ]);
    }
}
```

### 패턴 3: Bulk Delete — 삭제된 엔티티의 per-item 로깅

엔티티가 이미 삭제되어 DB 조회가 불가한 경우, `loggable_type`/`loggable_id`를 직접 지정합니다.
`ActivityLogHandler`가 `loggable` 모델이 없을 때 이 값을 fallback으로 사용합니다.

```php
public function handleAfterBulkDelete(array $ids, int $deletedCount, array $snapshots = []): void
{
    foreach ($ids as $id) {
        $snapshot = $snapshots[$id] ?? null;

        $this->logActivity('entity.bulk_delete', [
            'loggable_type' => Model::class,         // ← morph 타입 직접 지정
            'loggable_id' => $id,                    // ← morph ID 직접 지정
            'description_key' => 'vendor-module::activity_log.description.entity_delete',
            'description_params' => ['entity_id' => $id],
            'properties' => [
                'entity_id' => $id,
                'snapshot' => $snapshot,              // 삭제 전 데이터 보존
            ],
        ]);
    }
}
```

### 패턴 4: Bulk Toggle — 모델 조회 후 per-item 로깅

```php
public function handleAfterBulkToggleActive(array $ids, bool $isActive, int $count, array $snapshots = []): void
{
    $models = Model::whereIn('id', $ids)->get()->keyBy('id');

    foreach ($ids as $id) {
        $model = $models->get($id);
        if (! $model) {
            continue;
        }

        $snapshot = $snapshots[$id] ?? null;
        $changes = $snapshot ? ChangeDetector::detect($model, $snapshot) : null;

        $this->logActivity('entity.bulk_toggle_active', [
            'loggable' => $model,
            'description_key' => 'vendor-module::activity_log.description.entity_toggle_active',
            'description_params' => ['entity_id' => $id],
            'changes' => $changes,
            'properties' => ['entity_id' => $id, 'is_active' => $isActive],
        ]);
    }
}
```

### loggable vs loggable_type/loggable_id 선택 기준

| 상황 | 사용 방식 |
| --- | --- |
| 모델이 존재함 (update/toggle) | `'loggable' => $model` |
| 모델이 삭제됨 (delete) | `'loggable_type' => Model::class, 'loggable_id' => $id` |
| 두 값 모두 존재 시 | `loggable` 우선 (Handler에서 `loggable instanceof Model` 체크) |

### 삭제 작업 ChangeDetector

bulk delete 는 변경 전후 비교가 불필요하므로 ChangeDetector를 사용하지 않습니다.
대신 `properties.snapshot` 에 삭제 전 데이터를 보존합니다.

---

## DB 스키마

### activity_logs 테이블

| 컬럼 | 타입 | Nullable | 설명 |
|------|------|----------|------|
| `id` | bigint (PK) | N | 활동 로그 ID |
| `log_type` | varchar(20) | N | 로그 유형 (admin/user/system) |
| `loggable_type` | varchar | Y | 대상 모델 morph 타입 |
| `loggable_id` | bigint | Y | 대상 모델 ID |
| `user_id` | bigint (FK) | Y | 행위자 사용자 ID (삭제 시 NULL) |
| `action` | varchar(50) | N | 액션 유형 (예: user.create, page.update) |
| `description_key` | varchar(150) | Y | 다국어 번역 키 |
| `description_params` | json | Y | 다국어 번역 파라미터 |
| `properties` | json | Y | 추가 속성 데이터 |
| `changes` | json | Y | 구조화된 변경 이력 (ChangeDetector 결과) |
| `ip_address` | varchar(45) | Y | IP 주소 (IPv6 대응) |
| `user_agent` | varchar(500) | Y | User Agent (500자 제한) |
| `created_at` | timestamp | N | 생성일시 (updated_at 없음) |

- `timestamps = false` — `created_at` 만 사용 (boot 시 자동 설정)
- `user_id` 는 `nullOnDelete` — 사용자 삭제 시 NULL 처리

---

## 인덱스

### 단일 인덱스

| 컬럼 | 용도 |
|------|------|
| `log_type` | 유형별 필터링 |
| `user_id` | 사용자별 조회 |
| `action` | 액션별 필터링 |
| `created_at` | 시간순 정렬/범위 조회 |
| `loggable_type` + `loggable_id` | morphs 자동 생성 인덱스 |

### 복합 인덱스

| 인덱스명 | 컬럼 | 용도 |
|----------|------|------|
| `idx_activity_logs_loggable` | (`loggable_type`, `loggable_id`, `created_at`) | 특정 모델의 로그 시간순 조회 |
| `idx_activity_logs_type_action_date` | (`log_type`, `action`, `created_at`) | 유형+액션 기반 필터링 |

---

## 금지 사항

| 금지 | 올바른 사용 |
|------|-------------|
| `ActivityLogService->log()` / `logAdmin()` / `logUser()` / `logSystem()` | 제거됨 — `Log::channel('activity')->info()` 사용 |
| `ActivityLogManager` / `Driver` 패턴 | 제거됨 — Monolog 기반 아키텍처로 전환 |
| Controller에서 직접 로그 기록 | Listener에서만 기록 (Service 훅 → Listener → Log) |
| description 컬럼에 번역된 텍스트 저장 | `description_key` + `description_params` 사용 |
| ActivityLogService를 기록용으로 사용 | 조회 전용 — 기록은 `Log::channel('activity')` |
| Listener 밖에서 `ActivityLog::create()` 직접 호출 | `Log::channel('activity')` 를 통해 기록 (Processor 자동 주입 보장) |
| `$activityLogFields` 미정의 상태에서 ChangeDetector 호출 | ChangeDetector를 사용하는 모든 모델에 `$activityLogFields` 정의 필수 |
| `description_params` 에 표시용 이름 직접 저장 | ID 저장 + `ActivityLogDescriptionResolver` 에서 표시 시 변환 |
| 별도 로그 테이블/서비스 운영 (예: `ecommerce_product_logs`) | `activity_logs` 단일 테이블로 통합 |
| Bulk 작업을 1건의 집계 로그로 기록 (loggable 없이 count만 저장) | Per-Item: 변경된 각 엔티티별 개별 로그 기록 필수 |
| Bulk delete에서 loggable 미지정 | `loggable_type` + `loggable_id` 직접 지정 (삭제 엔티티 대응) |

---

## 참고 파일

| 파일 | 경로 |
|------|------|
| ActivityLogChannel | `app/ActivityLog/ActivityLogChannel.php` |
| ActivityLogHandler | `app/ActivityLog/ActivityLogHandler.php` |
| ActivityLogProcessor | `app/ActivityLog/ActivityLogProcessor.php` |
| ChangeDetector | `app/ActivityLog/ChangeDetector.php` |
| CoreActivityLogListener | `app/Listeners/CoreActivityLogListener.php` |
| ActivityLog 모델 | `app/Models/ActivityLog.php` |
| ActivityLogService | `app/Services/ActivityLogService.php` |
| ActivityLogType Enum | `app/Enums/ActivityLogType.php` |
| config/activity_log.php | `config/activity_log.php` |
| config/logging.php (activity 채널) | `config/logging.php` |
| 마이그레이션 (생성) | `database/migrations/2026_04_01_000027_create_activity_logs_table.php` |
| 마이그레이션 (i18n 전환) | `database/migrations/2026_04_01_000657_update_i18n_fields_in_activity_logs_table.php` |

---

## 개발자 체크리스트

### 새 모델 추가 시

```text
□ 모델에 public static array $activityLogFields 정의
□ ActivityLogListener에 before_update (스냅샷 캡처) + after_create/update/delete 훅 등록
□ description_params에 ID 저장 (이름 직접 저장 금지)
□ ActivityLogDescriptionResolver에 해당 모델의 ID→이름 해석 로직 추가
□ lang/{ko,en}/activity_log.php에 description 키 추가
□ (선택) Controller에 logs() 메서드 + 라우트 추가 (모델별 로그 조회 필요 시)
```

### CRUD 훅 추가 시

```text
□ before_update 훅: priority 5 (스냅샷 캡처용)
□ after_xxx 훅: priority 20 (로그 기록)
□ update 핸들러에서 ChangeDetector::detect() 호출
□ delete 핸들러에서 properties에 이름 스냅샷 보존
□ ko/en 다국어 키 모두 추가
```

### Bulk Update 추가 시

```text
□ Service: 변경 전 모델을 일괄 로드 (whereIn()->get())
□ Service: 각 모델별 toArray() 스냅샷 캡처 → $snapshots 배열
□ Service: 개별 모델 update 실행
□ Service: 훅에 $ids + $count + $snapshots 전달
□ Listener: per-item 루프 — 각 ID별 모델 조회 + ChangeDetector + 개별 logActivity 호출
□ Listener: 존재하지 않는 ID는 continue로 건너뜀
□ 1건의 집계 로그가 아닌, N건의 개별 로그 생성 확인
□ 각 로그에 loggable => $model (또는 loggable_type/loggable_id) 포함 확인
□ ko/en 다국어 키 모두 추가
□ 테스트: N건 처리 시 N건 로그 생성 검증 + loggable 정확성 검증
```

### Bulk Delete 추가 시

```text
□ Service: 삭제 전 스냅샷 캡처 → $snapshots 배열 (keyBy id)
□ Service: 훅에 $ids + $count + $snapshots 전달
□ Listener: per-item 루프 — 각 ID별 loggable_type/loggable_id 직접 지정
□ Listener: properties.snapshot에 삭제 전 데이터 보존
□ 테스트: loggable_type/loggable_id 정확성 검증
```
