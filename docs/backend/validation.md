# 검증 (Validation)

> 상위 문서: [백엔드 가이드 인덱스](index.md)

---

## TL;DR (5초 요약)

```text
1. 필수: FormRequest에서 검증 (Service에 검증 로직 배치 금지)
2. 복잡한 검증은 Custom Rule 클래스로 분리
3. 다국어 필드: LocaleRequiredTranslatable (필수) / TranslatableField (선택) 사용
4. 다국어 메시지: __('validation.xxx') 함수 사용
5. 훅 확장: HookManager::applyFilters()로 동적 규칙 추가
6. 필수: Rule::exists(Model::class, 'col') 사용 (문자열 테이블명 사용 금지)
7. 런타임 조건부 검증: 드라이버/모드별 분기 시 메서드 추출 패턴 사용
```

---

## 목차

- [검증 로직 구현 원칙](#검증-로직-구현-원칙)
- [검증 계층 구조](#검증-계층-구조)
- [FormRequest 검증](#formrequest-검증)
- [prepareForValidation() 데이터 전처리](#prepareforvalidation-데이터-전처리)
- [훅 기반 동적 Validation Rules 확장](#훅-기반-동적-validation-rules-확장)
- [런타임 조건부 Validation Rules (드라이버/모드별 분기)](#런타임-조건부-validation-rules-드라이버모드별-분기)
- [권한 체크 방식](#권한-체크-방식)
- [Custom Rule 검증 메시지 다국어 처리](#custom-rule-검증-메시지-다국어-처리)
- [다국어 필드 검증 규칙](#다국어-필드-검증-규칙)
- [사용자 입력 다국어 필드 정규화 (prepareForValidation)](#사용자-입력-다국어-필드-정규화-prepareforvalidation)
- [동적 스키마 기반 FormRequest 패턴](#동적-스키마-기반-formrequest-패턴)
- [exists/unique 검증 규칙](#existsunique-검증-규칙)
- [Custom Rule 개발 체크리스트](#custom-rule-개발-체크리스트)

---

## 검증 로직 구현 원칙

```
필수: FormRequest + Custom Rule 패턴 사용 (Service에 검증 로직 배치 금지)
```

**핵심 원칙**:
- **검증은 Controller 진입 전에 완료**: FormRequest가 자동으로 검증 수행
- **Service는 순수 비즈니스 로직만**: Service에 도달하는 데이터는 이미 검증 완료됨
- **재사용성**: Custom Rule은 여러 FormRequest에서 재사용 가능
- **Laravel 표준 준수**: Laravel 공식 검증 패턴 따름

---

## 검증 계층 구조

```
Controller → FormRequest (검증) → Service (비즈니스 로직) → Repository
              ↓
         Custom Rules (복잡한 검증)
```

**잘못된 예시 (❌ DON'T)**:
```php
// ❌ Service에 검증 로직 - 금지
class LayoutService
{
    public function createLayout(array $data)
    {
        // 검증 로직이 Service에 있음 - 잘못됨
        if (!isset($data['version'])) {
            throw new Exception('version is required');
        }

        if (!$this->isValidEndpoint($data['endpoint'])) {
            throw new Exception('Invalid endpoint');
        }

        // 비즈니스 로직
        return $this->repository->create($data);
    }
}
```

**올바른 예시 (✅ DO)**:
```php
// ✅ FormRequest로 검증
class StoreLayoutRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'version' => ['required', 'string'],
            'endpoint' => ['required', new WhitelistedEndpoint],
            'components' => ['required', 'array', new ValidLayoutStructure],
        ];
    }
}

// ✅ Custom Rule로 복잡한 검증
class WhitelistedEndpoint implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!preg_match('/^\/api\/(admin|auth|public)\//', $value)) {
            $fail(__('validation.custom.whitelisted_endpoint'));
        }
    }
}

// ✅ Service는 순수 비즈니스 로직만
class LayoutService
{
    public function createLayout(array $validatedData)
    {
        // 검증 완료된 데이터로 비즈니스 로직만 수행
        HookManager::doAction('layout.before_create', $validatedData);
        $layout = $this->repository->create($validatedData);
        HookManager::doAction('layout.after_create', $layout);
        return $layout;
    }
}
```

---

## FormRequest 검증

**규칙**:
- 모든 검증 로직은 FormRequest 클래스로 분리
- 컨트롤러에서 인라인 검증 금지
- **권한 검증은 라우트의 `permission` 미들웨어에서 수행**
- FormRequest의 `authorize()` 메서드는 항상 `true` 반환
- 복잡한 검증은 Custom Rule 클래스로 분리
- **exists/unique 규칙**: `Rule::exists(Model::class, 'column')` / `Rule::unique(Model::class, 'column')` 사용 필수 (문자열 테이블명 사용 금지)

**패턴**:
```php
<?php

namespace Modules\Sirsoft\Ecommerce\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'category_id' => ['required', Rule::exists(Category::class, 'id')],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'name.required' => '상품명을 입력해주세요.',
            'price.required' => '가격을 입력해주세요.',
            'price.min' => '가격은 0 이상이어야 합니다.',
            'category_id.exists' => '존재하지 않는 카테고리입니다.',
        ];
    }
}
```

---

## prepareForValidation() 데이터 전처리

FormRequest의 `prepareForValidation()` 메서드를 사용하여 검증 **전**에 데이터를 전처리합니다.

### 주요 용도

| 용도 | 예시 |
| ------ | ------ |
| 기본값 설정 | `collection` 미전송 시 `'default'` 설정 |
| 라우트 파라미터 병합 | `$this->route('identifier')`를 검증 대상에 포함 |
| 데이터 형식 변환 | JSON 문자열 → 배열 변환 |
| 역호환성 처리 | 문자열 `name` → 다국어 배열 `name` 변환 |
| 충돌 필드 제거 | `roles` 객체 배열이 있으면 `role_ids`로 변환 |

### 패턴 예시

```php
protected function prepareForValidation(): void
{
    // 1. 기본값 설정
    $this->merge([
        'collection' => $this->collection ?? 'default',
        'source_type' => $this->source_type ?? AttachmentSourceType::Core->value,
    ]);
}
```

```php
protected function prepareForValidation(): void
{
    // 2. 라우트 파라미터 병합 (검증 대상에 포함)
    $this->merge([
        'identifier' => $this->route('identifier'),
        'path' => $this->route('path'),
    ]);
}
```

```php
protected function prepareForValidation(): void
{
    // 3. JSON 문자열 → 배열 변환
    $content = $this->input('content');
    if (is_string($content)) {
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $this->merge(['content' => $decoded]);
        }
    }
}
```

### prepareForValidation 주의사항

- `prepareForValidation()`은 `rules()` 호출 **전**에 실행됨
- `$this->merge()`로 값을 병합 (기존 값 덮어쓰기)
- 검증 로직은 넣지 않음 — 검증은 `rules()`에서 처리

---

## 훅 기반 동적 Validation Rules 확장

```
필수: 모든 코어 FormRequest의 rules()는 훅을 통해 확장 가능해야 함 (코어에 모듈/플러그인 필드 하드코딩 금지)
필수: 모듈/플러그인은 훅을 통해 자체 필드의 validation rules 추가
```

### 배경

Laravel의 `$request->validated()`는 `rules()`에 정의된 필드만 반환합니다. 모듈/플러그인에서 동적으로 추가한 필드가 코어 FormRequest의 rules에 없으면 `validated()` 결과에서 제외되어 Service 계층까지 전달되지 않습니다.

### 코어 FormRequest 필수 패턴

**모든 코어 FormRequest는 다음 패턴을 따라야 합니다**:

```php
<?php

namespace App\Http\Requests\User;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            // ... 기본 필드들
        ];

        // ✅ 필수: 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.user.update_validation_rules', $rules, $this);
    }
}
```

### 훅 네이밍 규칙

```
core.[entity].[action]_validation_rules

예시:
core.user.create_validation_rules      # 사용자 생성
core.user.update_validation_rules      # 사용자 수정
core.role.store_validation_rules       # 역할 저장
core.permission.update_validation_rules # 권한 수정
```

### 모듈/플러그인에서의 필드 확장

**모듈/플러그인이 코어 엔티티에 필드를 추가하려면**:

1. **HookListenerInterface 구현**
2. **validation rules 훅 구독**
3. **addValidationRules 메서드로 필드 추가**

```php
<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;

class UserNotificationSettingsListener implements HookListenerInterface
{
    /**
     * 알림 설정 필드 목록
     */
    private const NOTIFICATION_FIELDS = [
        'notify_post_complete',
        'notify_post_reply',
        'notify_comment',
        'notify_reply_comment',
    ];

    public static function getSubscribedHooks(): array
    {
        return [
            // ✅ Validation Rules 확장 훅
            'core.user.create_validation_rules' => [
                'method' => 'addValidationRules',
                'priority' => 10,
                'type' => 'filter',
            ],
            'core.user.update_validation_rules' => [
                'method' => 'addValidationRules',
                'priority' => 10,
                'type' => 'filter',
            ],

            // 데이터 필터 훅 (검증 후 처리)
            'core.user.filter_update_data' => [
                'method' => 'filterUpdateData',
                'priority' => 10,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * FormRequest validation rules에 알림 설정 필드 추가
     *
     * @param array $rules 기존 validation rules
     * @return array 알림 설정 필드가 추가된 rules
     */
    public function addValidationRules(array $rules): array
    {
        return array_merge($rules, [
            'notify_post_complete' => 'nullable|boolean',
            'notify_post_reply' => 'nullable|boolean',
            'notify_comment' => 'nullable|boolean',
            'notify_reply_comment' => 'nullable|boolean',
        ]);
    }

    /**
     * 수정 데이터 필터: 알림 설정 필드 추출 후 모듈 테이블에 저장
     */
    public function filterUpdateData(array $data, User $user): array
    {
        $notificationData = $this->extractNotificationData($data);
        if (!empty($notificationData)) {
            $this->service->createOrUpdate($user->id, $notificationData);
        }

        // 코어 테이블에 저장되지 않도록 필드 제거
        return $this->removeNotificationFields($data);
    }
}
```

### 데이터 흐름

```
Request
   ↓
FormRequest.rules()
   ↓
HookManager::applyFilters('core.entity.action_validation_rules', $rules)
   ↓
모듈 Listener: addValidationRules() → 필드 추가
   ↓
validated() → 모듈 필드 포함된 데이터 반환
   ↓
Service
   ↓
HookManager::applyFilters('core.entity.filter_update_data', $data)
   ↓
모듈 Listener: filterUpdateData() → 모듈 테이블에 저장 후 필드 제거
   ↓
Repository → 코어 테이블에 저장
```

### 주의사항

1. **훅 우선순위**: 여러 모듈이 같은 훅을 구독할 수 있으므로 `priority` 값을 적절히 설정
2. **필드 충돌 방지**: 모듈별로 고유한 필드명 사용 권장 (예: `board_notify_*`, `shop_option_*`)
3. **모듈 비활성화 시 자동 제거**: 모듈이 비활성화되면 훅이 등록되지 않아 validation rules도 자동 제거됨

### 코어 FormRequest 체크리스트

신규 코어 FormRequest 생성 시:

- [ ] `use App\Extension\HookManager;` import 추가
- [ ] `rules()` 메서드 마지막에 `HookManager::applyFilters()` 호출
- [ ] 훅 이름: `core.[entity].[action]_validation_rules` 형식
- [ ] 두 번째 인자로 `$this` (FormRequest 인스턴스) 전달
- [ ] **exists/unique 규칙에 문자열 테이블명 미사용 확인** → `Rule::exists(Model::class, 'column')` 형태만 사용 ([상세](#existsunique-검증-규칙))

---

## 런타임 조건부 Validation Rules (드라이버/모드별 분기)

```
주의: 런타임 입력값에 따라 검증 규칙이 달라지는 경우 별도 메서드로 분리
✅ 필수: 조건부 규칙은 메서드 추출 패턴 사용 (가독성 + 재사용성)
✅ 필수: 비활성 드라이버 필드는 nullable 유지 (데이터 보존)
```

### 배경

설정 화면처럼 **드라이버/모드 선택에 따라 필수 필드가 달라지는** 경우가 있습니다. 예: SMTP 선택 시 host/port 필수, Mailgun 선택 시 domain/secret 필수.

### 패턴: 메서드 추출

```php
class SaveSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            'mail.mailer' => ['sometimes', 'string', 'in:smtp,mailgun,ses'],
            'mail.from_address' => ['sometimes', 'email', 'max:255'],
            // ... 공통 필드
        ];

        // ✅ 드라이버별 조건부 규칙은 별도 메서드로 분리
        $rules = array_merge($rules, $this->getMailerRules());

        return HookManager::applyFilters('core.settings.save_validation_rules', $rules, $this);
    }

    /**
     * 메일 드라이버별 조건부 검증 규칙을 반환합니다.
     *
     * @return array<string, array<mixed>>
     */
    private function getMailerRules(): array
    {
        $tab = $this->input('tab');
        $mailer = $this->input('mail.mailer', 'smtp');

        return match ($mailer) {
            'smtp' => [
                'mail.host' => $this->getTabRules($tab, 'mail', 'string|max:255'),
                'mail.port' => $this->getTabRules($tab, 'mail', 'integer|min:1|max:65535'),
                // Mailgun/SES 필드는 nullable
                'mail.mailgun_domain' => ['nullable', 'string', 'max:255'],
            ],
            'mailgun' => [
                'mail.mailgun_domain' => $this->getTabRules($tab, 'mail', 'string|max:255'),
                'mail.mailgun_secret' => $this->getTabRules($tab, 'mail', 'string|max:255'),
                // SMTP/SES 필드는 nullable
                'mail.host' => ['nullable', 'string', 'max:255'],
            ],
            // ... 다른 드라이버
        };
    }
}
```

### 테스트 메일처럼 독립 요청인 경우

설정 저장이 아닌 **테스트 실행용 요청**에서도 동일 패턴을 적용합니다:

```php
class TestMailRequest extends FormRequest
{
    public function rules(): array
    {
        $mailer = $this->input('mailer', 'smtp');

        return [
            'to_email' => ['required', 'email', 'max:255'],
            'from_address' => ['required', 'email', 'max:255'],
            // SMTP 필드: smtp일 때만 required
            'host' => $mailer === 'smtp'
                ? ['required', 'string', 'max:255']
                : ['sometimes', 'nullable', 'string', 'max:255'],
            // Mailgun 필드: mailgun일 때만 required
            'mailgun_domain' => $mailer === 'mailgun'
                ? ['required', 'string', 'max:255']
                : ['sometimes', 'nullable', 'string', 'max:255'],
            // ...
        ];
    }
}
```

### 조건부 Validation 원칙

| 원칙 | 설명 |
|------|------|
| 비활성 필드는 `nullable` | 다른 드라이버 필드는 삭제하지 않고 nullable 유지 (데이터 보존) |
| `$this->input()` 사용 | 런타임 입력값으로 분기 (request body에서 직접 읽음) |
| 메서드 추출 | 규칙이 3개 이상 분기되면 `getXxxRules()` 메서드로 분리 |
| 기본값 제공 | `$this->input('mailer', 'smtp')` — 미전송 시 기본 드라이버 적용 |

---

## 권한 체크 방식

```php
// ✅ DO: 라우트에서 permission 미들웨어로 권한 체크
Route::post('/products', [ProductController::class, 'store'])
    ->middleware('permission:sirsoft-ecommerce.products.create')
    ->name('api.admin.products.store');

// ❌ DON'T: FormRequest의 authorize()에서 권한 체크
public function authorize(): bool
{
    return $this->user()->can('sirsoft-ecommerce.products.create'); // 사용 금지
}
```

---

## Custom Rule 검증 메시지 다국어 처리

```
필수: __() 함수를 사용한 다국어 처리 (오류 메시지 하드코딩 금지)
```

**핵심 원칙**:

- **모든 검증 오류 메시지는 다국어 파일에 정의**: `/lang/ko/validation.php`, `/lang/en/validation.php`
- **Custom Rule에서 __() 함수 사용 필수** (하드코딩된 문자열 사용 금지)
- **파라미터 치환 지원**: `:attribute`, `:field`, `:value` 등의 동적 값 지원
- **일관성 유지**: 모든 검증 규칙이 동일한 패턴 사용

**다국어 파일 위치**:
```
/lang/ko/validation.php  # 한국어 검증 메시지
/lang/en/validation.php  # 영어 검증 메시지
```

**잘못된 예시 (❌ DON'T)**:
```php
// ❌ 하드코딩된 오류 메시지 - 금지
class ValidLayoutStructure implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_array($value)) {
            $fail('레이아웃 데이터는 배열이어야 합니다.');  // 한국어만 지원
            return;
        }

        if (!isset($value['version'])) {
            $fail("필수 필드 'version'이 누락되었습니다.");  // 다국어 불가
            return;
        }
    }
}
```

**올바른 예시 (✅ DO)**:

**1. 다국어 파일 정의**:
```php
// /lang/ko/validation.php
return [
    'layout' => [
        'must_be_array' => '레이아웃 데이터는 배열이어야 합니다.',
        'required_field_missing' => "필수 필드 ':field'가 누락되었습니다.",
        'invalid_json' => '유효하지 않은 JSON 형식입니다.',
        'max_depth_exceeded' => '컴포넌트 중첩 깊이가 최대 허용 깊이(:max)를 초과했습니다.',
    ],
];

// /lang/en/validation.php
return [
    'layout' => [
        'must_be_array' => 'Layout data must be an array.',
        'required_field_missing' => "Required field ':field' is missing.",
        'invalid_json' => 'Invalid JSON format.',
        'max_depth_exceeded' => 'Component nesting depth exceeds maximum allowed depth (:max).',
    ],
];
```

**2. Custom Rule에서 사용**:
```php
class ValidLayoutStructure implements ValidationRule
{
    private const MAX_DEPTH = 10;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // ✅ 다국어 함수 사용 (파라미터 없음)
        if (!is_array($value)) {
            $fail(__('validation.layout.must_be_array'));
            return;
        }

        // ✅ 다국어 함수 사용 (파라미터 치환)
        if (!isset($value['version'])) {
            $fail(__('validation.layout.required_field_missing', ['field' => 'version']));
            return;
        }

        // ✅ 다국어 함수 사용 (다중 파라미터)
        if ($depth > self::MAX_DEPTH) {
            $fail(__('validation.layout.max_depth_exceeded', ['max' => self::MAX_DEPTH]));
            return;
        }
    }
}
```

**3. 동적 인덱스/키 처리**:
```php
// ✅ 배열 인덱스를 파라미터로 전달
$fail(__('validation.layout.component_must_be_array', ['index' => $index]));

// 다국어 파일: "components[:index]는 배열이어야 합니다."
// 결과: "components[0]는 배열이어야 합니다."
```

---

## 다국어 필드 검증 규칙

```
필수: LocaleRequiredTranslatable 또는 TranslatableField Rule 사용 (로케일 하드코딩 금지)
```

### 핵심 원칙

- **현재 로케일 동적 결정**: `app()->getLocale()`로 현재 요청의 로케일 자동 감지
- **현재 로케일만 필수**: 사용자의 언어만 required, 다른 로케일은 nullable
- **일관된 검증**: 모든 다국어 필드에 동일한 규칙 적용

### Rule 종류

| Rule | 용도 | 현재 로케일 | 다른 로케일 |
|------|------|------------|------------|
| `LocaleRequiredTranslatable` | 필수 다국어 필드 | **required** | nullable + 조건 검증 |
| `TranslatableField` | 선택 다국어 필드 | 하나 이상 값 존재 시 OK | nullable + 조건 검증 |

### LocaleRequiredTranslatable 사용법

**파일 위치**: `app/Rules/LocaleRequiredTranslatable.php`

```php
use App\Rules\LocaleRequiredTranslatable;

// 기본 사용 (maxLength: 255)
'name' => ['required', 'array', new LocaleRequiredTranslatable()],

// maxLength 지정
'name' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 100)],

// minLength + maxLength 지정
'title' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 200, minLength: 2)],

// 특정 로케일 강제 지정 (테스트 또는 특수 케이스)
'name' => ['required', 'array', new LocaleRequiredTranslatable(
    maxLength: 100,
    requiredLocale: 'en'  // 영어 필수
)],
```

**동작 방식**:
1. `app()->getLocale()` → 현재 요청 로케일 결정 (예: 'ko', 'en')
2. 현재 로케일 값 필수 검증 (빈 문자열, null, whitespace 불가)
3. 모든 로케일에 대해 string, min, max 검증

### TranslatableField 사용법

**파일 위치**: `app/Rules/TranslatableField.php`

```php
use App\Rules\TranslatableField;

// 기본 사용 (nullable, 하나 이상 값 있으면 OK)
'description' => ['nullable', 'array', new TranslatableField()],

// maxLength 지정
'description' => ['nullable', 'array', new TranslatableField(maxLength: 1000)],
```

### 잘못된 예시 (❌ DON'T)

```php
// ❌ 로케일 하드코딩 - 금지
$locales = config('app.translatable_locales');
foreach ($locales as $locale) {
    $rules["name.{$locale}"] = $locale === 'ko'
        ? 'required|string|max:100'
        : 'nullable|string|max:100';
}

// ❌ 첫 번째 로케일 하드코딩
$rules["name.{$locales[0]}"] = 'required|string|max:100';

// ❌ messages()에 로케일별 키 하드코딩
'name.ko.required' => '한국어 이름을 입력해주세요.',
```

### 올바른 예시 (✅ DO)

```php
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;

public function rules(): array
{
    return [
        // 필수 다국어 필드
        'name' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 100)],

        // 선택 다국어 필드
        'description' => ['nullable', 'array', new TranslatableField(maxLength: 1000)],
    ];
}

public function messages(): array
{
    return [
        // Rule 내부에서 다국어 메시지 처리하므로 로케일별 키 불필요
        'name.required' => __('validation.name_required'),
    ];
}
```

### 다국어 메시지

**파일 위치**: `lang/ko/validation.php`, `lang/en/validation.php`

```php
// lang/ko/validation.php
'translatable' => [
    'must_be_array' => '다국어 필드는 배열이어야 합니다.',
    'current_locale_required' => ':locale 언어의 값은 필수입니다.',
    'unsupported_language' => "지원하지 않는 언어 코드(':lang')입니다.",
    'must_be_string' => "':lang' 번역은 문자열이어야 합니다.",
    'max_length' => "':lang' 번역은 최대 :max자까지 가능합니다.",
    'min_length' => "':lang' 번역은 최소 :min자 이상이어야 합니다.",
    'at_least_one_required' => '최소 하나의 언어에 값을 입력해야 합니다.',
],

// lang/en/validation.php
'translatable' => [
    'must_be_array' => 'The translatable field must be an array.',
    'current_locale_required' => 'The :locale language value is required.',
    'unsupported_language' => "Unsupported language code: ':lang'.",
    'must_be_string' => "The ':lang' translation must be a string.",
    'max_length' => "The ':lang' translation must not exceed :max characters.",
    'min_length' => "The ':lang' translation must be at least :min characters.",
    'at_least_one_required' => 'At least one language must have a value.',
],
```

### MultilingualDefaultLocaleRequiredRule과의 차이

| Rule | 로케일 결정 | 용도 |
|------|------------|------|
| `LocaleRequiredTranslatable` | `app()->getLocale()` (동적) | 사용자 요청 기반 검증 |
| `MultilingualDefaultLocaleRequiredRule` | `config('app.supported_locales')[0]` (고정) | 시스템 기본 로케일 기준 검증 |

**사용 시나리오**:
- 일반 API (사용자 언어 기반): `LocaleRequiredTranslatable` 사용
- 관리자 시스템 설정 (시스템 기본 언어 기준): `MultilingualDefaultLocaleRequiredRule` 사용

---

## 사용자 입력 다국어 필드 정규화 (prepareForValidation)

관리자(Admin) 폼은 `{"ko": "집", "en": "Home"}` 형태의 다국어 배열을 직접 제출합니다.
반면, **사용자(User) 폼**은 단일 문자열만 입력하지만 DB 컬럼은 JSON(다국어) 형식인 경우가 있습니다.

```
필수: 사용자 입력 문자열 → 다국어 배열 변환은 FormRequest의 prepareForValidation()에서 수행
FormRequest가 데이터 정규화의 Single Source of Truth (Service에서 변환 금지)
```

### 패턴

```php
// ✅ 올바른 패턴: FormRequest에서 정규화
class StoreUserAddressRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $name = $this->input('name');
            $locales = config('app.supported_locales', [config('app.locale', 'ko')]);
            $localized = [];

            foreach ($locales as $locale) {
                $localized[$locale] = $name;
            }

            $this->merge(['name' => $localized]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'array'],
            'name.*' => ['required', 'string', 'max:50'],
            // ...
        ];
    }
}
```

```php
// ❌ 잘못된 패턴: Service에서 변환
class UserAddressService
{
    public function createAddress(array $data): UserAddress
    {
        // ❌ Service는 검증/정규화를 하지 않음
        if (is_string($data['name'])) {
            $data['name'] = ['ko' => $data['name'], 'en' => $data['name']];
        }
    }
}
```

### Repository에서 JSON 컬럼 비교

다국어 JSON 컬럼에 대한 중복 확인 등 비교 시, Laravel JSON arrow 문법을 사용합니다:

```php
// ✅ 현재 로케일 기준으로 JSON 컬럼 비교
public function findByUserIdAndName(int $userId, string $name): ?UserAddress
{
    $locale = app()->getLocale();

    return $this->model
        ->where('user_id', $userId)
        ->where("name->{$locale}", $name)
        ->first();
}
```

### 적용 시나리오

| 폼 유형 | name 제출 형태 | FormRequest | 정규화 |
|--------|--------------|-------------|--------|
| Admin 폼 | `{"ko": "집", "en": "Home"}` | `LocaleRequiredTranslatable` | 불필요 |
| User 폼 | `"집"` (문자열) | `prepareForValidation()` + `array` 검증 | 문자열 → 다국어 배열 |

---

## 동적 스키마 기반 FormRequest 패턴

### 문제: 빈 validated() 반환

플러그인 설정처럼 동적 스키마 기반 검증에서, 플러그인이 등록되지 않았거나 스키마가 없으면 `rules()`가 빈 배열을 반환합니다. 이 경우 `validated()`도 빈 배열을 반환하여 설정값이 전달되지 않습니다.

```php
// 문제 상황: rules()가 빈 배열 반환 시
class UpdatePluginSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        $plugin = $this->getPlugin();

        if (!$plugin) {
            return [];  // 플러그인이 없으면 빈 배열
        }

        // 스키마 기반 동적 규칙 생성
        return $this->buildRulesFromSchema($plugin->getSettingsSchema());
    }
}

// Controller에서
$settings = $request->validated();  // 빈 배열 반환!
```

### 해결: Controller에서 fallback 처리

```php
/**
 * 플러그인 설정 업데이트
 *
 * validated()가 빈 배열이면 all()에서 설정값을 가져옵니다.
 * (PluginManager에 등록되지 않은 플러그인의 경우)
 */
public function update(UpdatePluginSettingsRequest $request, string $identifier): JsonResponse
{
    // ✅ validated()가 빈 배열이면 all()로 fallback
    $settings = $request->validated();
    if (empty($settings)) {
        $settings = $request->all();
    }

    $result = $this->pluginSettingsService->save($identifier, $settings);

    return ResponseHelper::success('plugins.settings.updated', $settings);
}
```

### 적용 대상

이 패턴은 다음 경우에 적용합니다:

| 케이스 | 설명 |
|--------|------|
| 플러그인 설정 | 플러그인별로 다른 설정 스키마 |
| 모듈 설정 | 모듈별로 다른 설정 스키마 |
| 동적 폼 | 런타임에 필드가 결정되는 폼 |

### 주의사항

```
주의: all() fallback은 신뢰할 수 있는 데이터에만 사용
✅ 플러그인/모듈 설정처럼 관리자만 접근하는 API에 적합
❌ 일반 사용자 입력에는 사용 금지 (검증 우회 위험)
```

---

## exists/unique 검증 규칙

```
필수: Rule::exists(Model::class, 'column') / Rule::unique(Model::class, 'column') 사용 (문자열 테이블명 금지)
```

### 핵심 원칙

- **모델 기반 테이블 식별**: 테이블명이 변경되어도 모델의 `$table` 속성이 자동 반영
- **타입 안전성**: IDE에서 모델 클래스 참조 추적 및 리팩토링 지원
- **버그 예방**: 문자열 테이블명 오타로 인한 런타임 오류 방지

### 잘못된 예시 (❌ DON'T)

```php
// ❌ 문자열 테이블명 - 금지
'user_id' => 'required|integer|exists:users,id',
'email' => 'required|email|unique:users,email',
'role_id' => ['required', 'integer', 'exists:roles,id'],

// ❌ Rule 클래스에 문자열 테이블명 - 금지
Rule::exists('users', 'id'),
Rule::unique('users', 'email'),
```

### 올바른 예시 (✅ DO)

```php
use App\Models\User;
use App\Models\Role;
use Illuminate\Validation\Rule;

// ✅ 모델 클래스 기반
'user_uuid' => ['required', 'uuid', Rule::exists(User::class, 'uuid')],
'email' => ['required', 'email', Rule::unique(User::class, 'email')],
'role_id' => ['required', 'integer', Rule::exists(Role::class, 'id')],

// ✅ unique with ignore (수정 시 자기 자신 제외)
'email' => [
    'required', 'email',
    Rule::unique(User::class, 'email')->ignore($userId),
],

// ✅ unique with where (복합 조건)
'name' => [
    'required', 'string',
    Rule::unique(TemplateLayout::class, 'name')
        ->where('template_id', $this->input('template_id')),
],
```

### 변환 패턴 참조

| Before | After |
|--------|-------|
| `'exists:users,uuid'` (배열 내) | `Rule::exists(User::class, 'uuid')` |
| `'required\|exists:users,id'` (파이프) | `['required', Rule::exists(User::class, 'id')]` |
| `Rule::unique('users', 'email')` | `Rule::unique(User::class, 'email')` |
| `'unique:users,email,'.$id` | `Rule::unique(User::class, 'email')->ignore($id)` |
| `'unique:users'` (컬럼 없음) | `Rule::unique(User::class)` |

### 동적 테이블 예외

모델 클래스로 변환할 수 없는 동적 테이블명은 `Rule::exists()` 형태만 사용합니다:

```php
// ✅ 동적 테이블 - 문자열 테이블명 허용 (Model::class 불가)
Rule::exists("board_{$slug}_posts", 'id'),
```

---

## Custom Rule 개발 체크리스트

- [ ] `/lang/ko/validation.php`에 한국어 메시지 추가
- [ ] `/lang/en/validation.php`에 영어 메시지 추가
- [ ] Custom Rule에서 모든 `$fail()` 호출 시 `__()` 함수 사용
- [ ] 동적 값은 파라미터 배열로 전달 (예: `['field' => $fieldName]`)
- [ ] 두 언어 모두에서 테스트 수행

---

## 관련 문서

- [예외 처리 (exceptions.md)](exceptions.md) - Custom Exception 다국어 처리
- [컨트롤러 (controllers.md)](controllers.md) - 컨트롤러 계층 구조
- [Service-Repository (service-repository.md)](service-repository.md) - 비즈니스 로직 분리
- [라우팅 (routing.md)](routing.md) - permission 미들웨어 설정
- [훅 시스템 (../extension/hooks.md)](../extension/hooks.md) - HookManager 사용법
