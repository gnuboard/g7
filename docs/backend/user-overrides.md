# 사용자 수정 보존 (HasUserOverrides Trait)

> **배경**: 그누보드7 은 업그레이드/시더 재실행 시 사용자가 UI/API 로 수정한 필드를 **자동 보존**하는 시스템을 제공합니다. 본 문서는 `HasUserOverrides` trait 의 사용법과 보장 범위를 정의합니다.

## TL;DR (5초 요약)

```text
1. 모델에 `use HasUserOverrides;` + `protected array $trackableFields = [...]` 선언
2. 다국어 JSON 컬럼은 `protected array $translatableTrackableFields = ['name']` 추가 — sub-key dot-path 단위 보존
3. `user_overrides` (JSON/array) 컬럼 마이그레이션 필수
4. 사용자가 trackable 필드를 수정 → user_overrides 자동 누적 기록 (다국어는 'name.ko' 형태)
5. 시더가 `syncOrCreateFromUpgrade()` 호출 → 기록된 키만 보존, 나머지 locale 은 자동 동기화
6. mass update `Model::where(...)->update(...)` 도 **투명하게 자동 추적**
7. 언어팩 활성/비활성 시 `RunSeedersOnLanguagePackLifecycle` 가 entity 시더 자동 재실행 (scope 별 라우팅)
```

## 1. 기본 사용법

### 1.1 모델 선언

```php
use App\Models\Concerns\HasUserOverrides;
use Illuminate\Database\Eloquent\Model;

class ShippingType extends Model
{
    use HasUserOverrides;

    /**
     * 사용자 수정 보존 대상 필드 (전체 컬럼 단위).
     *
     * @var array<int, string>
     */
    protected array $trackableFields = ['name', 'category', 'is_active', 'sort_order'];

    /**
     * 다국어 JSON 컬럼 — sub-key dot-path 단위로 user_overrides 보존.
     *
     * 사용자가 ko 라벨만 수정 → user_overrides=['name.ko'] 로 기록되어
     * 시더 재실행/언어팩 활성 시 ja/en 키는 자동 동기화 가능.
     *
     * 정책: trackableFields 의 부분집합. 일반 scalar 필드(category 등)는 등록 금지.
     *
     * @var array<int, string>
     */
    protected array $translatableTrackableFields = ['name'];

    protected $fillable = [
        'code', 'name', 'category', 'is_active', 'sort_order',
        'user_overrides',  // 필수
    ];

    protected $casts = [
        'name' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'user_overrides' => 'array',  // 필수 (JSON ↔ array 자동 변환)
    ];
}
```

### 1.2 다국어 JSON 컬럼 정책 (translatableTrackableFields)

7.0.0-beta.4 이후 다국어 JSON 컬럼은 sub-key dot-path 단위로 user_overrides 가 기록됩니다.

| 시나리오 | user_overrides 기록 형식 |
|---------|-------------------------|
| 사용자가 `name.ko` 만 수정 | `['name.ko']` |
| 사용자가 `name.ko, name.en` 동시 수정 | `['name.ko', 'name.en']` |
| 사용자가 scalar 필드 `is_active` 수정 | `['is_active']` (컬럼명 단위) |
| 혼재 수정 | `['name.ko', 'is_active']` |

시더가 재실행될 때:

| 상황 | 동작 |
|------|------|
| `user_overrides=['name.ko']` 인 row + 시더가 `{ko, en, ja}` 신규 데이터 제공 | ko 보존, en/ja 신규 값으로 갱신 |
| `user_overrides=['is_active']` 인 row + 시더가 is_active=true 제공 | 갱신 SKIP (기존 동작) |
| `user_overrides=['name']` legacy 컬럼명 형식 | 컬럼 전체 보존 (역호환 — beta.4 upgrade step 이 dot-path 로 자동 변환) |

### 1.2 마이그레이션

```php
Schema::table('ecommerce_shipping_types', function (Blueprint $table) {
    $table->text('user_overrides')->nullable()
        ->comment('유저가 수정한 필드명 목록 (예: ["name", "category"])');
});
```

### 1.3 Seeder (업그레이드 경로)

```php
public function run(): void
{
    $helper = app(\App\Extension\Helpers\GenericEntitySyncHelper::class);
    $definedCodes = [];

    foreach ($types as $data) {
        $helper->sync(ShippingType::class, ['code' => $data['code']], $data);
        $definedCodes[] = $data['code'];
    }

    // 완전 동기화: seeder 에 없는 row 삭제 (user_overrides 무관)
    $helper->cleanupStale(ShippingType::class, [], 'code', $definedCodes);
}
```

## 2. 동작 메커니즘

### 2.1 사용자 수정 기록 (자동)

사용자가 수정할 때 trait 의 `updating` 이벤트 핸들러가 자동으로 변경된 trackable 필드를 `user_overrides` 배열에 추가합니다.

```php
$shippingType = ShippingType::find(1);
$shippingType->name = ['ko' => '사용자 수정'];
$shippingType->save();

// → user_overrides = ['name'] 자동 기록
```

### 2.2 시더 재실행 시 보존

```php
// 시더/업그레이드에서 호출
$helper->sync(ShippingType::class, ['code' => 'parcel'], [
    'name' => ['ko' => '택배'],  // 사용자가 수정했으므로 건너뜀
    'sort_order' => 1,           // user_overrides 에 없으므로 갱신
]);
```

`syncOrCreateFromUpgrade()` 내부는:

- 기존 row 가 있으면 `user_overrides` 에 등록된 trait 필드는 갱신 건너뜀
- 나머지 필드는 갱신
- 시더 컨텍스트(`user_overrides.seeding` 플래그) 에서는 `updating` 이벤트가 자동 bypass 되어 user_overrides 가 추가 기록되지 않음

## 3. Mass Update 투명 추적

### 3.1 지원 경로

다음 수정 경로 모두에서 user_overrides 가 자동 기록됩니다:

| 경로 | 예시 | 기록 여부 |
|---|---|---|
| 인스턴스 update | `$model->update([...])` | ✅ 기록 |
| 인스턴스 save | `$model->name = 'x'; $model->save()` | ✅ 기록 |
| **mass update** | `Model::where(...)->update([...])` | ✅ **기록** |
| `whereIn` mass update | `Model::whereIn('id', [...])->update([...])` | ✅ 기록 (행별 계산) |
| Query Builder 우회 | `DB::table('...')->update(...)` | ❌ 기록 안 됨 (escape hatch) |

### 3.2 내부 구현

`HasUserOverrides` trait 이 커스텀 `UserOverridesAwareBuilder` 를 반환하여 mass update 를 가로챕니다:

1. 시더 컨텍스트면 기본 경로 유지
2. 입력 `$values` 에 trackable 필드가 없으면 기본 경로 유지 (성능 최적화)
3. 그 외: 대상 행 preload → 행별 `calculateUserOverridesFor($values)` 계산 → user_overrides 포함한 per-row UPDATE

### 3.3 성능 고려

| 상황 | 쿼리 수 |
|---|---|
| trackable 필드 미포함 update | 1 UPDATE (기존) |
| trackable 필드 포함 mass update | 1 SELECT + N UPDATE |
| 대량 처리 필요 (수천~수만 행) | **`DB::table(...)->update(...)` 사용 권장** (Eloquent 우회) |

## 4. 공개 API

### 4.1 `calculateUserOverridesFor(array $incoming): array`

현재 모델 상태와 신규 입력을 비교하여 user_overrides 배열을 계산합니다.

```php
$model = ShippingType::find(1);
$overrides = $model->calculateUserOverridesFor(['name' => ['ko' => '새 이름']]);
// 결과: ['name'] (현재 값과 다른 trackable 필드만 포함)
```

주로 `UserOverridesAwareBuilder` 가 내부적으로 사용하며, 복잡한 커스텀 sync 로직에서도 재사용 가능합니다.

### 4.2 `syncOrCreateFromUpgrade(array $finder, array $attributes): self`

시더/업그레이드에서 직접 호출하거나, helper 에 위임합니다.

```php
ShippingType::syncOrCreateFromUpgrade(
    ['code' => 'parcel'],
    ['name' => ['ko' => '택배'], 'category' => 'domestic']
);
```

### 4.3 `getTrackableFields(): array`

`$trackableFields` 프로퍼티 반환. 커스텀 로직에서 참조.

## 5. 정책과 원칙

### 5.1 row 존재 여부 vs 필드 값

| 구분 | 정책 |
|---|---|
| **row 존재** | 오직 config/seeder 정의 기준 (user_overrides 무관) — stale cleanup 은 무조건 삭제 |
| **필드 값 (유지되는 row)** | user_overrides 에 등록된 trackable 필드는 보존, 나머지는 갱신 |

즉 "사용자가 수정한 row 이니 보존" 이 아니라 "config 에 있으면 유지, 있으면서 사용자가 수정한 필드는 유지" 입니다.

### 5.2 Trait 적용 시점의 한계

Trait 이 적용되기 **이전에** 사용자가 수정한 row 는 user_overrides 가 비어있어 추적 불가. Trait 적용 이후 수정부터 보존 효과 발생.

해결: 마이그레이션 + trait 적용을 포함한 업그레이드 후 사용자에게 설정 재확인을 안내.

## 6. 적용 현황

| 모델 | 트랙 필드 |
|---|---|
| `Menu` | `name`, `icon`, `order`, `url` |
| `Role` | `name`, `description` |
| `NotificationDefinition` | `name`, `is_active` |
| `NotificationTemplate` | `subject`, `body`, `click_url`, `recipients`, `is_active` |
| `Schedule` | `expression`, `command`, `timeout`, `is_active` |
| `BoardType` | `name` |
| `ClaimReason` | `name`, `sort_order`, `is_active` |
| `ShippingType` | `name`, `category`, `is_active`, `sort_order` |

## 7. 참고

- 완전 동기화 원칙: [core-config.md](core-config.md#완전-동기화-원칙)
- Helper 5종 사용 가이드: [data-sync-helpers.md](data-sync-helpers.md)
- 업그레이드 스텝: [extension/upgrade-step-guide.md](../extension/upgrade-step-guide.md)
