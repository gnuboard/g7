# 검증 (Validation)

> 상위 문서: [백엔드 가이드 인덱스](README.md)

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
8. Store/Update 조건부 규칙은 대칭 — 한쪽 누락 시 과잉 검증(수정 실패) 또는 검증 우회
9. min/size 가 붙은 필드는 required_if 로 조건부화 불가 → exclude_if (조건 필드가 nullable 이면 exclude_unless)
```

---

## 목차

- [검증 로직 구현 원칙](#검증-로직-구현-원칙)
- [검증 계층 구조](#검증-계층-구조)
- [FormRequest 검증](#formrequest-검증)
- [prepareForValidation() 데이터 전처리](#prepareforvalidation-데이터-전처리)
- [훅 기반 동적 Validation Rules 확장](#훅-기반-동적-validation-rules-확장)
- [런타임 조건부 Validation Rules (드라이버/모드별 분기)](#런타임-조건부-validation-rules-드라이버모드별-분기)
  - [조건부 Validation 원칙](#조건부-validation-원칙)
  - [`exclude_if` vs `exclude_unless` — 조건 필드가 nullable 일 때](#exclude_if-vs-exclude_unless--조건-필드가-nullable-일-때)
  - [조건 필드가 요청에 없을 때 — 저장값 주입](#조건-필드가-요청에-없을-때--저장값-주입)
- [권한 체크 방식](#권한-체크-방식)
- [Custom Rule 검증 메시지 다국어 처리](#custom-rule-검증-메시지-다국어-처리)
- [다국어 필드 검증 규칙](#다국어-필드-검증-규칙)
- [사용자 입력 다국어 필드 정규화 (prepareForValidation)](#사용자-입력-다국어-필드-정규화-prepareforvalidation)
- [동적 스키마 기반 FormRequest 패턴](#동적-스키마-기반-formrequest-패턴)
- [exists/unique 검증 규칙](#existsunique-검증-규칙)
- [계층 리소스 순환 참조](#계층-리소스-순환-참조)
- [배열 항목의 상위 스코프](#배열-항목의-상위-스코프)
- [관대한 기존 동작을 규칙으로 승격하지 않는다](#관대한-기존-동작을-규칙으로-승격하지-않는다)
- [운영자가 지울 수 있는 어휘의 필터 게이트](#운영자가-지울-수-있는-어휘의-필터-게이트)
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
        // 실제 룰은 /api/(modules|plugins)/{vendor-id}/ 확장 프리픽스도 허용 — app/Rules/WhitelistedEndpoint.php 참조
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
- **요청이 보내지 않은 키는 merge 하지 않는다.** 설정 화면처럼 한 구획만 보내는 부분 저장에서, 정규화 결과(빈 값이면 `null`·`[]`)를 무조건 merge 하면 그 키가 「비우라」는 값으로 저장돼 이미 저장된 값이 지워진다. 정규화한 값은 `array_key_exists($key, $this->all())` 일 때만 넣는다.

```php
// ❌ 키가 없어도 null 로 채워 저장 — 다른 항목만 고친 저장이 카탈로그를 지운다
$this->merge(['cookie_categories' => $normalized]);

// ✅ 보낸 키만 정규화해 넣는다
if (array_key_exists('cookie_categories', $this->all())) {
    $this->merge(['cookie_categories' => $normalized]);
}
```

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
| Store/Update 정책 일치 | 같은 리소스의 Store 와 Update 는 동일 필드에 동일한 조건부 규칙을 적용한다. 한쪽에만 `exclude_if` 등이 있으면 다른 쪽에서만 저장이 막힌다 |
| `sometimes` 는 부분 수정 지원 수단이 아니다 | 관리자 폼은 조회 응답 전체를 그대로 PUT 하므로 요청에는 항상 모든 키가 존재한다. 토글 OFF 상태의 종속 필드는 `sometimes` 가 아니라 `exclude_if` 로 검증에서 배제한다 |
| 상호 필수 조합은 결과 상태로 판정 | `required_with`/`required_without` 는 "상대 필드 부재" 자체가 발화 조건이라 부분 수정 요청과 상충한다. Update 에서는 페이로드 단독이 아니라 "기존 레코드 + 페이로드" 결과 상태를 기준으로 판정한다 |

`exclude_if` 는 규칙 배열의 **첫 번째**에 둔다. 배제는 규칙 루프 도중 발동하며 이미 추가된 실패 메시지는 회수되지 않으므로, 앞선 규칙이 먼저 실패하면 배제되어도 오류가 남는다.

```php
// ❌ DON'T: 요청에 키가 항상 있으므로 sometimes 가 발동하지 않아 빈 값이 차단된다
'allowed_extensions' => ['sometimes', 'array', 'min:1'],

// ✅ DO: 토글이 꺼져 있으면 필드 자체를 검증에서 제외한다
'allowed_extensions' => ['exclude_if:use_file_upload,false', 'sometimes', 'required', 'array', 'min:1'],
```

조건부 규칙의 종류에 따라 Update 로 옮기는 방식이 달라진다.

| Store 규칙 | Update 에 옮기는 방식 |
| ------ | ------ |
| `required_if:other,value` | 그대로 복사한다. 조건 필드가 요청에 없으면 발화하지 않아 부분 수정과 충돌하지 않는다 |
| `exclude_if` / `exclude_unless` | 그대로 복사한다. 조건 필드가 요청에 없으면 배제가 동작하지 않으므로(`ValidatesAttributes::validateExcludeIf`), 부분 수정 경로가 있으면 `prepareForValidation()` 에서 기존 레코드 값을 주입해 판정 기준을 세운다 |
| `required_with` / `required_without` | 그대로 복사하면 안 된다. 상대 필드가 요청에 없다는 사실만으로 발화하므로, 그 조합과 무관한 필드 하나만 바꾸는 요청이 전부 422 가 된다 |

`required_with`/`required_without` 는 "요청에 무엇이 실려 왔는지"가 아니라 "저장 후 레코드가 유효한지"를 묻는 규칙이다. 따라서 Update 에서는 요청에 없는 필드를 저장된 값으로 메운 뒤 판정한다. 이때 폼 제출 키와 저장 컬럼명이 다르면(예: 해외 주소를 `intl_city` 로 제출하고 `city` 컬럼에 저장) 메우는 쪽에서 그 매핑을 반영해야 한다 — 매핑을 빠뜨리면 저장된 값이 있는데도 빈 값으로 보여 정상 수정 경로가 막힌다.

```php
// ❌ DON'T: Store 규칙을 그대로 복사 — 기본 배송지 토글만 바꾸는 요청이 422
'zipcode' => 'required_without:intl_postal_code|nullable|string|max:10',

// ✅ DO: 결과 상태(기존 레코드 + 페이로드) 기준으로 withValidator() 에서 판정
$validator->after(function (Validator $validator) {
    $existing = app(UserAddressRepositoryInterface::class)->findByUserIdAndId($userId, $addressId);
    $effective = array_key_exists('zipcode', $payload) ? $payload['zipcode'] : $existing?->zipcode;
    // ... 국가별 필수 조합 판정 후 $validator->errors()->add(...)
});
```

이 방식은 `formrequest-store-update-conditional-symmetry` 룰의 토큰 비교로는 "누락"으로 보이므로, 해당 필드에 `// audit:allow formrequest-store-update-conditional-symmetry reason: ...` 로 판정 위치를 남긴다.

### 규칙 계산·사후 검증에서 입력을 읽을 때 — 형식을 믿지 않는다

`rules()` 는 검증보다 **먼저** 돌고(입력으로 규칙을 고르는 `ClaimTypeEnum::tryFrom($this->input('type'))` 같은
코드), `withValidator()` 의 `after` 콜백은 규칙 오류가 난 요청에서도 **실행된다**. 두 자리에서 입력을
형 변환하거나 문자열 함수에 넘기면(`(string)`·`trim()`) 규칙이 막으려던 형식(문자열 칸에 목록)에서 PHP
오류가 나 422 대신 500 이 된다. 문자열이 아니면 형 변환하지 않고 건너뛰거나 빈 값으로 읽는다 — 그 칸의
형식 오류는 규칙이 422 로 돌려준다.

```php
// ❌ DON'T: status 에 배열이 오면 Array to string conversion → 500
if (! $this->requiresTransitionTracking((string) $this->input('status'))) {
    return;
}

// ✅ DO: 문자열이 아니면 건너뛴다
$status = $this->input('status');

if (! is_string($status) || ! $this->requiresTransitionTracking($status)) {
    return;
}
```

### `exclude_if` vs `exclude_unless` — 조건 필드가 nullable 일 때

`exclude_if:other,false` 의 값 비교는 strict 다. 조건 필드가 `nullable|boolean` 이라 `null` 이 올 수 있으면 `null !== false` 라 매칭되지 않아 배제가 일어나지 않는다. "true 가 아니면 제외" 를 뜻하는 `exclude_unless:other,true` 는 `null`/`false`/미전송을 모두 포괄한다.

```php
// 조건 필드가 boolean 고정 — false 가 확실히 온다
'allowed_extensions' => ['exclude_if:use_file_upload,false', 'sometimes', 'required', 'array', 'min:1'],

// 조건 필드가 nullable — null 이 올 수 있다
'basic_defaults.allowed_extensions' => ['exclude_unless:basic_defaults.use_file_upload,true', 'sometimes', 'required', 'array', 'min:1'],
```

**중첩 배열의 조건 필드는 dot notation 으로 쓴다.** `exclude_unless:use_file_upload,true` 처럼 최상위 키만 적으면 요청 데이터에 그 키가 없어 조건이 성립하지 않고, 배제가 조용히 동작하지 않는다. 검증 대상 필드가 `basic_defaults.allowed_extensions` 라면 조건 필드도 `basic_defaults.use_file_upload` 여야 한다.

### 조건 필드가 요청에 없을 때 — 저장값 주입

`exclude_if`/`exclude_unless` 는 **조건 필드가 데이터에 없으면 배제하지 않는다**(`ValidatesAttributes::validateExcludeIf`). 부분 수정 경로가 있는 Update 요청은 종속 필드만 전송되고 조건 토글은 빠질 수 있으므로, `prepareForValidation()` 에서 기존 레코드의 값을 주입해 판정 기준을 세운다.

```php
protected function prepareForValidation(): void
{
    $data = $this->all();

    // 종속 필드가 왔는데 조건 토글이 없으면 저장값으로 판정 기준을 세운다
    if (array_key_exists('allowed_extensions', $data) && ! array_key_exists('use_file_upload', $data)) {
        $board = $this->route('board');

        if ($board !== null && isset($board->use_file_upload)) {
            $data['use_file_upload'] = (bool) $board->use_file_upload;
        }
    }

    $this->merge($data);
}
```

주입 조건에 **종속 필드가 실제로 전송되었는지**를 반드시 포함한다. 무조건 주입하면 사용자가 보내지도 않은 토글이 검증 대상으로 올라와, 그 필드와 무관한 부분 수정이 조건부 규칙의 영향을 받는다.


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

**4. 수가 들어가는 검증 문구 (영어 단·복수)**:

수 규칙(`min`·`max`·`size`·`gt`·`gte`·`lt`·`lte`·`digits`·`min_digits`·`max_digits`·`decimal`)의 영어 표준 문구는
`단수|복수` 로 적혀 있고, 코어가 규칙값으로 한 문장을 고릅니다 — `min:1` 이면 `The tags field must have at least 1 item.`,
`min:2` 면 `… at least 2 items.` 입니다. 범위 규칙(`between`·`digits_between`)은 고르지 않습니다(늘 복수).
`messages()` 에 이 규칙의 커스텀 문구를 줄 때도 `단수|복수` 값은 같은 방식으로 골라지지만, 표준 키를 `__()` 로 미리
번역해 넘기면 두 문장이 이어져 나옵니다 — `trans_choice('validation.min.array', 1, ['attribute' => …, 'min' => 1])` 처럼
규칙값으로 고릅니다. 규칙 상세는 [module-i18n.md 「수에 따른 단수·복수 (백엔드)」](../extension/module-i18n.md#수에-따른-단수복수-백엔드).

---

## 다국어 필드 검증 규칙

```
필수: LocaleRequiredTranslatable 또는 TranslatableField Rule 사용 (로케일 하드코딩 금지)
```

### 핵심 원칙

- **사이트 기본 언어만 필수**: 환경설정 「기본 언어」(`config('app.site_locale')`)의 값만 required, 다른 로케일은 nullable
- **보는 사람의 언어와 무관**: 요청 언어(`app()->getLocale()`)를 필수로 보지 않는다 — 필수 언어가 보는 사람마다 달라지면 다른 언어 관리자가 만든 레코드를 이어 고칠 수 없다
- **일관된 검증**: 모든 다국어 필드에 동일한 규칙 적용. 관리자 다국어 입력(MultilingualInput·HtmlEditor·MultilingualTagInput)도 같은 언어 칸에 필수 표시(`*`)를 붙인다(프론트 `_global.appConfig.siteLocale`)

### Rule 종류

| Rule | 용도 | 사이트 기본 언어 | 다른 로케일 |
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
1. `requiredLocale` 인자 → 없으면 `config('app.site_locale')`(부팅 때 환경설정 「기본 언어」로 채워짐) 로 필수 언어 결정. 다국어 허용 목록(`app.translatable_locales`)에 없으면 첫 언어
2. 그 언어 값 필수 검증 (빈 문자열, null, whitespace 불가)
3. 모든 로케일에 대해 string, min, max 검증

> `app.locale` 은 요청마다 `SetLocale` 미들웨어가 보는 사람의 언어로 덮으므로 필수 언어 판정에 쓰지 않는다. 규칙 밖에서 이름 필수를 직접 판정하는 코드도 `config('app.site_locale', config('app.locale'))` 를 쓴다.

**칸 표시 이름 필수**: 이 규칙을 붙인 칸은 요청의 `attributes()` 에 표시 이름을 둔다. 필수 문구가
「메뉴 이름의 한국어(ko) 값은 필수입니다.」처럼 그 이름을 싣는다. 표시 이름이 없으면 필드 키(`name`)를 내보이지
않으려고 칸 이름 없이 「한국어(ko) 값은 필수입니다.」만 나오므로, 어느 칸인지 알 수 없다(7.1.0 실측 — 메뉴·역할·권한·
알림 템플릿·게시판 유형·페이지 요청에 매핑을 넣었다).

```php
public function attributes(): array
{
    return [
        'name' => __('validation.menu.attributes.name'), // 「메뉴 이름」 / "menu name"
    ];
}
```

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
    'current_locale_required' => ':attribute의 :language(:locale) 값은 필수입니다.',
    'current_locale_required_unnamed' => ':language(:locale) 값은 필수입니다.', // 칸 표시 이름이 없을 때
    'unsupported_language' => "지원하지 않는 언어 코드(':lang')입니다.",
    'must_be_string' => "':lang' 번역은 문자열이어야 합니다.",
    'max_length' => "':lang' 번역은 최대 :max자까지 가능합니다.",
    'min_length' => "':lang' 번역은 최소 :min자 이상이어야 합니다.",
    'at_least_one_required' => '최소 하나의 언어에 값을 입력해야 합니다.',
],

// lang/en/validation.php
'translatable' => [
    'must_be_array' => 'The translatable field must be an array.',
    'current_locale_required' => 'The :language (:locale) value of the :attribute field is required.',
    'current_locale_required_unnamed' => 'The :language (:locale) value is required.', // when the field has no display name
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

### 설정 항목 이름을 요청 언어로 등록 (플러그인 설정)

플러그인 설정 스키마의 `label` 은 보통 ko/en 만 있다. 이것만 믿으면 ja 화면의 검증 오류가 항목 이름을 기본 언어(ko)나 영문 키(`live site cd`)로 낸다. 설정 검증을 거는 리스너(`core.plugin_settings.update_validation_rules` filter)는 규칙을 돌려주는 시점에 **현재 요청 언어**로 `validation.attributes.{field}` 를 등록한다. 코어 검증기는 이 번역선을 스키마 `label` 보다 먼저 쓰므로, ja 이름은 플러그인 lang 파일(언어팩이 주는 `ja` 포함)에서 온다.

```php
private function registerSettingsAttributeLabels(): void
{
    $locale = app()->getLocale();

    // validation 그룹을 먼저 읽힌다 — addLines 가 attributes 만 든 그룹을 먼저 캐시하면 required 등 기본 문구가 로드되지 않는다
    Lang::get('validation.required', [], $locale);

    $lines = [];
    foreach (self::SETTINGS_FIELDS as $field) {
        $key = "vendor-plugin::messages.settings.{$field}_attribute";
        if (Lang::has($key, $locale)) { // 번역이 없는 언어는 등록하지 않고 스키마 label 에 맡긴다
            $lines["validation.attributes.{$field}"] = __($key, [], $locale);
        }
    }

    if ($lines !== []) {
        Lang::addLines($lines, $locale);
    }
}
```

- 서비스 프로바이더 부팅 때가 아니라 요청 처리 중(규칙 filter 안)에 등록한다. 그때라야 요청 언어가 정해져 있고 플러그인 lang 네임스페이스와 언어팩이 준비돼 있다.
- 라이브 모드 같은 조건부 항목만이 아니라 설정 항목 전부를 등록한다. 형식 오류(길이 등)도 같은 항목 이름을 쓴다.
- 번들 예: `sirsoft-message_bizppurio`(`ValidateBizppurioSettingsListener`), `sirsoft-verification_kginicis`(`ValidateInicisSettingsListener`), `sirsoft-verification_nhnkcp`(`ValidateKcpSettingsListener`).

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

## 계층 리소스 순환 참조

`parent_id` 로 자기 자신을 참조하는 계층 리소스(메뉴, 카테고리 등)의 **수정·순서 변경**
요청은 `Rule::exists` 만으로 부족하다. `exists` 는 행의 존재만 보장하므로 자기 자신이나
자신의 자손을 부모로 지정해 사이클을 만들 수 있고, DB 의 FK 제약도 이를 막지 못한다.

사이클이 생기면 `path`/`depth` 재계산 재귀가 종료되지 않아 요청이 스택 오버플로로 실패하고,
사이클 노드는 루트에서 도달할 수 없어 트리에서 사라진다. 관리자 화면으로는 복구할 수 없다.

```php
// ❌ 존재만 확인 — 자기 자신/자손을 부모로 지정 가능
'parent_id' => ['nullable', Rule::exists(Category::class, 'id')],

// ✅ 자손 전체를 검사하는 Rule 부착
'parent_id' => [
    'nullable',
    Rule::exists(Category::class, 'id'),
    new NotCircularCategoryParent($categoryId),
],
```

### 규율

| 항목 | 규칙 |
| --- | --- |
| 적용 대상 | `Update*Request` / `Reorder*Request`. 생성 요청은 자기 자신이 아직 없어 순환이 불가하나, 두 엔드포인트의 규칙 구성을 같게 두면 검증 강도가 갈라지지 않는다 |
| 검사 범위 | 자기 자신만이 아니라 **자손 전체**. 자기참조만 막는 규칙(`NotSelfParent`)은 `A → B → A` 를 통과시킨다 |
| 동일 리소스 일관성 | 한 리소스의 모든 부모 변경 경로(수정 / 순서 변경 / 일괄 이동)가 같은 강도를 가져야 한다. 한 곳만 강화하면 나머지가 우회로가 된다 |
| 자손 판정 구현 | `path` 머티리얼라이즈드 컬럼이 있으면 prefix 매칭(쿼리 1회). 없으면 `parent_id` 재귀 — 이때 방문 ID 집합으로 유한 종료를 보장한다 |
| Service 2차 방어 | 검증을 우회하는 경로(시더 / 훅 / 기존 오염 데이터)를 위해 재귀 함수에 방문 ID 가드를 둔다. 무한 루프 대신 유한 실패 + 로그 |
| 코어 Rule 재사용 | `App\Rules\NotCircularParent` 는 `App\Models\Menu` 하드코딩이다. 다른 모델에는 모듈 로컬 Rule 을 신설한다 (코어 시그니처 변경 = 확장 버전 제약 연쇄) |


---

## 배열 항목의 상위 스코프

요청 본문의 배열 항목이 하위 리소스 ID 를 담고, 라우트에 상위 리소스 ID 가 있다면
`Rule::exists` 에 상위 스코프 `where` 절을 붙인다. 붙이지 않으면 A 의 경로로 B 의
하위 리소스를 조작할 수 있다.

```php
// ❌ 전역 존재 확인 — 다른 주문의 옵션도 통과
'items.*.option_id' => ['required', 'integer', Rule::exists(OrderOption::class, 'id')],

// ✅ 경로의 상위 리소스로 스코프
$orderId = $this->route('order')?->id;
'items.*.option_id' => [
    'required',
    'integer',
    Rule::exists(OrderOption::class, 'id')->where('order_id', $orderId),
],
```

422 가 의미적으로 옳다 — 입력값이 이 요청 맥락에서 유효하지 않다는 뜻이기 때문이다.
Service 에도 동일한 검증을 두어 FormRequest 를 우회한 내부/훅 호출을 막는다.

---

## 관대한 기존 동작을 규칙으로 승격하지 않는다

컨트롤러가 base `Request` 를 주입받던 코드를 FormRequest 로 옮길 때, 그 컨트롤러가 하던 일이
**거부**였는지 **수용**이었는지 먼저 읽는다. 수용하던 동작(클램핑·폴백·별칭 매핑)을 `rules()` 로
올리면 200 이던 응답이 422 가 된다. 위반을 없애는 것이 목적이지 계약을 바꾸는 것이 아니다.

```php
// 원본 컨트롤러 — 상한 초과를 거부하지 않고 상한까지 반환한다
$limit = min((int) $request->input('limit', 20), 50);

// ❌ 규칙으로 승격 — `?limit=100` 이 200 → 422 (기존 링크·북마크가 깨진다)
public function rules(): array
{
    return ['limit' => ['nullable', 'integer', 'max:50']];
}

// ✅ 규칙은 타입만 닫고, 상한은 접근자가 클램프한다
public function rules(): array
{
    return ['limit' => ['nullable', 'integer', 'min:0']];
}

public function limit(): int
{
    return min((int) $this->validated('limit', 20), self::MAX_LIMIT);
}
```

같은 원칙이 어휘에도 적용된다. 저장소가 미지원 값을 기본 분기로 처리해 왔다면 `Rule::in` 으로
막는 순간 그 값을 담은 URL 이 전부 깨진다. 어휘를 닫아야 할 별도 이유(예: 요청 값이 캐시 키에
그대로 들어가 키 공간이 무한히 늘어남)가 있어도, **접근자가 닫힌 집합만 반환**하게 하면 응답
계약을 건드리지 않고 목적을 달성한다.

```php
// ✅ 응답은 종전과 동일(미지원 값 → year), 캐시 키만 정규화된다
public function period(): string
{
    $period = $this->validated('period');

    if ($period === null || $period === '') {
        return self::DEFAULT_PERIOD;
    }

    return in_array((string) $period, self::RESOLVED_PERIODS, true) ? (string) $period : 'year';
}
```

`Rule::in` 승격이 적절한 경우는 **입력원이 화면 컨트롤 하나뿐**일 때다 — 관리자 DataGrid 의 필터
select 처럼 사용자가 URL 을 손으로 만들 일이 없는 면. 판정 기준은 "북마크 가능한 공개 경로인가".

기존 테스트가 이 전환에서 RED 가 되면 그 테스트가 무엇을 고정하고 있는지 먼저 읽는다. 리팩토링에서
테스트가 깨질 때의 기본 해석은 "테스트가 낡았다" 가 아니라 "계약을 바꿨다" 이다.

---

## 운영자가 지울 수 있는 어휘의 필터 게이트

목록 필터의 허용 어휘가 Enum 이 아니라 **운영자 설정**에서 나오는 축이 있다 — 통화, 결제수단, 사유 카탈로그처럼 운영자가 추가하고 **내릴 수 있는** 것들이다.

Enum 은 닫힌 어휘라 코드가 바뀌지 않는 한 값이 사라지지 않는다. 설정 어휘는 다르다. **값으로 기록된 행은 남는데 어휘에서는 사라진다.** 거래 시점 통화처럼 스냅샷으로 동결된 값이면 더더욱 남는다.

그래서 게이트가 현재 설정만 보고 판정하면 두 가지가 동시에 깨진다.

1. 그 값으로 기록된 행이 **어떤 필터 조합으로도 도달 불가**가 된다
2. 그 값이 실린 북마크·뒤로가기·수기 URL 이 **목록 전체를 422** 로 만든다 — 화면에는 직전 목록이 그대로 남아 있어 필터가 걸린 것처럼 보인다

### 2단 판정

어휘 판정을 `설정 → 실제 기록값` 두 단계로 둔다.

```php
'currency_code' => ['nullable', 'string', Rule::in($this->allowedCurrencies())],

/**
 * 통화 필터의 허용 어휘.
 *
 * 1단 — 설정 어휘: 등록 통화 ∪ 내린 기본 제공 통화(tombstone)
 * 2단 — 그 밖의 코드는 실제 기록이 있을 때만 통과
 */
protected function allowedCurrencies(): array
{
    $configured = [...$this->registeredCurrencies(), ...$this->removedDefaultCurrencies()];

    $requested = (string) $this->query('currency_code');

    // 설정 어휘 안이면 여기서 끝난다 — 정상 조합에는 추가 쿼리가 없다.
    if ($requested === '' || in_array($requested, $configured, true)) {
        return $configured;
    }

    return $this->currencyRecorded($requested) ? [...$configured, $requested] : $configured;
}
```

### 규칙

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| `Rule::in(현재 설정 어휘)` 단독 | 위의 2단 판정 |
| 기록 조회를 **저장소 필터와 다른 컬럼**에서 수행 | 저장소가 그 필터로 거르는 **같은 컬럼**을 본다. 유형마다 컬럼이 다르면 유형별로 (예: 교환·반품은 자기 `currency_code`, 환불·취소는 주문의 `currency`) |
| 어휘 검사를 없애고 전부 통과시키기 | 어디에도 쓰인 적 없는 값은 **여전히 거부**한다 — 오타가 "결과 없음" 으로 위장하면 운영자는 필터가 걸린 줄 안다 |
| FormRequest 클로저에서 모델 직접 조회 | Repository Interface 에 `hasRecorded*()` 를 두고 규칙은 `Rule::in` **선언형**을 유지한다. 클로저로 바꾸면 메시지 키(`.in`)가 사라져 안내가 다국어로 나가지 않는다 |
| 같은 화면의 어휘 축마다 다른 강도 | 형제 축은 같은 2단 규칙. 한쪽만 길이 검사(`string\|max:50`)면 그 축의 오타는 조용히 0건이 된다 |
| tombstone(내린 기본 제공 목록)만 믿기 | 운영자가 **직접 추가한 뒤 삭제한** 값은 tombstone 이 남지 않는다 — 마지막 근거는 언제나 실제 기록값이다 |

### 어휘가 사라지는 경로는 하나가 아니다

같은 결함이 provenance 만 달리해 여러 번 재발한다. 최소 세 경로를 함께 본다.

| 경로 | 흔적 |
|---|---|
| 기본 제공 항목을 내림 | tombstone 이 남는다 (`removed_default_*`) |
| 운영자가 추가한 항목을 삭제 | **아무 흔적도 남지 않는다** |
| 항목을 비활성화(삭제 아님) | 설정에는 있지만 활성 목록에서 빠진다 |

세 번째는 사유 카탈로그처럼 비활성 개념이 있는 축에서 나타난다. 비활성 사유로 기록된 신청서를 조회할 수 있어야 하는지는 그 화면의 정책 문제이므로, 어휘에 넣기 전에 카탈로그 화면의 결정과 함께 판정한다.

### 성능

정상 조합에는 추가 쿼리가 **없다** — 설정 어휘 안이면 1단에서 끝난다. 2단 조회는 설정 어휘를 벗어난 값에서만 실행되고, 그 경로는 인증된 관리자만 도달한다. 조회 컬럼에 인덱스가 없으면 미존재 값에서 풀스캔이 되므로, 그 축이 자주 쓰인다면 인덱스를 함께 검토한다.

이 결함은 예외도 로그도 남기지 않는다. 목록이 통째로 거부되고 화면에는 직전 목록이 남는 것이 유일한 증상이다.

## 보안 게이트 대칭성 (KVE-2026-1914/1915/1919)

같은 리소스를 다루는 두 엔드포인트가 서로 다른 검증 강도를 가지면, **약한 쪽이 우회로**가
된다. 부모를 변경·서빙하는 모든 경로는 동일 강도의 검증을 거쳐야 한다.

### 같은 리소스, 같은 검증 강도

수정·순서변경·상태변경·권한부여처럼 같은 리소스를 바꾸는 경로가 여럿이면, 그중 하나라도
검증이 약하면 공격자는 그 경로로 우회한다. 예: 삭제 FormRequest 만 등급 상한을 검사하고
수정 FormRequest 는 검사하지 않으면, 수정 경로로 슈퍼 관리자를 조작할 수 있다. 판정 규칙은
한 곳(게이트/Rule)에 두고 모든 경로가 그것을 재사용한다.

### 레이아웃 표현식 검증은 표현식 트리에 부착한다

`SafeLayoutExpressions` 처럼 값의 구조를 재귀 탐색하는 저장측 규칙은, **표현식이 실릴 수
있는 배열/객체 트리 전체**(레이아웃의 `content`)에 부착해야 한다. 문자열 하위 필드
(`content.endpoint` 등)에만 부착하면 규칙이 비-배열 값에서 조기 반환(`is_array` 가드)해
**no-op** 이 된다 — 검증이 걸려 있는 것처럼 보이지만 실제로는 아무것도 검사하지 않는다.

```php
// ❌ 문자열 endpoint 에만 부착 — is_array 가드로 무력화(no-op)
'content.endpoint' => ['string', new SafeLayoutExpressions],

// ✅ 표현식 트리를 담는 content 배열에 부착
'content' => ['required', 'array', new ValidLayoutStructure, new SafeLayoutExpressions],
```

부착 위치는 "어느 필드가 표현식 트리를 담는가" 라는 도메인 판정이라 정적으로 강제하기 어렵다 —
레이아웃 저장 FormRequest(Store/Update/UpdateContent/UpdateExtensionContent) 4종의 부착을
wiring 테스트로 회귀 고정한다.

> 서비스/리포지토리 계층의 비밀 게이트 재적용·hash 서빙 게이트·등급 상한 대칭은 [service-repository.md "보안 게이트 대칭성"](service-repository.md) 참조.

---

## 검증 문구의 필드 라벨

검증 메시지의 `:attribute` 자리는 **운영자가 화면에서 보는 라벨**이어야 한다. 라벨을 주지 않으면
Laravel 이 필드 키를 풀어쓴 영문을 그대로 넣는다.

```
annual notice.batch size 필드는 1000를 초과하면 안 됩니다.      ❌ 키가 그대로 노출
seo.bot_user_agents.0 필드는 100자를 초과하면 안 됩니다.        ❌ 인덱스까지 노출
1회 실행당 발송 상한 필드는 1000를 초과하면 안 됩니다.           ✅
```

화면 라벨은 한국어인데 오류 문구만 영문 키면 운영자는 어느 칸을 고쳐야 하는지 알 수 없다.

### 라벨을 주는 방법

- **`FormRequest::attributes()`** — 기본 경로. 중첩·배열 키는 `'a.b.*' => …` 와일드카드 한 줄이
  모든 인덱스를 덮는다(`'a.b.0'`, `'a.b.1'` 을 따로 쓰지 않는다).
- **`lang/{ko,en}/validation.php` 의 `attributes`** — 코어의 **단일 세그먼트 키**만 해소된다.
  점이 포함된 키(`security.max_login_attempts`)는 이 배열에 넣어도 적용되지 않으므로
  `attributes()` 에서 `__('validation.attributes.…')` 로 가리킨다.
- **확장의 lang 은 이 경로에 합류하지 않는다** — 네임스페이스(`sirsoft-gdpr::messages.…`)로
  격리돼 있으므로 확장 FormRequest 는 반드시 `attributes()` 에서 명시 참조한다.

### 훅으로 규칙을 얹을 때 (확장)

`core.settings.save_validation_rules` 처럼 **필터 훅으로 규칙 키를 더하는** 경로는 라벨을 함께
주어야 한다. 규칙만 더하면 그 필드의 오류 문구에 원시 키가 그대로 나가고, 화면은 확장이
그렸는데 문구는 코어가 만들므로 어디를 고칠지도 보이지 않는다.

- 규칙을 더하는 리스너는 **같은 자리에서 `core.settings.save_validation_attributes` 에도 라벨을
  더한다** — 둘을 한 리스너 안에 두어 한쪽만 바뀌는 일을 막는다.
- 라벨 값은 그 확장의 네임스페이스 문구(`__('sirsoft-x::messages.…')`)를 쓴다. 확장 lang 은
  코어 `validation.attributes` 경로에 합류하지 않는다(위 항목).

**자동 검사는 이 경로를 재지 못한다** — 감사 룰과 PHPUnit 은 FormRequest 의 **정적 리터럴**만
읽으므로 훅이 런타임에 얹는 키는 잔량 집계에도 잡히지 않는다. 그래서 이 절이 규율이고,
확장 리뷰에서 눈으로 대조한다.

### 트레이트로 규칙을 나눠 쓸 때

여러 요청이 트레이트에서 규칙을 받으면(`adminClaimRules()` 같은 메서드) 그 키도 요청 파일에 글자로
적히지 않아 감사 룰이 보지 못한다. 요청 파일의 키에만 라벨을 달고 끝내면 트레이트 키의 오류 문구에
원시 키가 그대로 나간다(`refund bank.holder 필드는 50자를 초과하면 안 됩니다`).

- 규칙을 주는 트레이트가 **라벨도 같은 자리에서 준다** — `adminClaimRules()` · `adminClaimMessages()`
  옆에 `adminClaimAttributes()` 를 두고, 요청의 `attributes()` 는 자기 키 배열에 `+ $this->adminClaimAttributes()`
  로 합친다(요청 고유 키가 앞서므로 같은 키를 요청이 다시 정할 수 있다).
- 감사 룰 대신 **요청을 실제로 만들어** `rules()` 가 돌려준 키 전부를 `attributes()` 와 대조하는 테스트로
  막는다 — 예: 이커머스 `ClaimRequestAttributeLabelsTest::test_trait_provided_rule_keys_have_labels`.
- 확장 lang 의 라벨 키에는 **점을 넣지 않는다** — `__()` 는 점을 중첩 경로로 읽으므로
  `'claim.max_images' => …` 같은 평면 키를 찾지 못하고 키 문자열을 그대로 돌려준다(예외·경고 없음).
  밑줄 키로 된 전용 그룹(`validation.request_attributes` 등)에 둔다. 점은 요청 쪽 규칙 키에만 쓴다.

### 라벨이 필요 없는 경우

그 키에 걸린 모든 메시지가 `:attribute` 를 쓰지 않는 완성 문장일 때다(커스텀 Rule 이
`:value` 만 쓰는 경우 등). 이때는 `// audit:allow formrequest-attribute-label-coverage reason: …`
로 사유를 남긴다.

### 자동 검사

감사 룰 `formrequest-attribute-label-coverage` 가 파일별 잔량을 기준선과 대조해 **늘어나면 차단**한다.
기존 부채는 기준선으로 고정돼 있어 무관한 파일을 건드릴 때 막히지 않는다. 기준선은 **낮추는
방향으로만** 갱신한다 — 새 누락을 통과시키려고 올리지 않는다.

---

## Custom Rule 개발 체크리스트

- [ ] `/lang/ko/validation.php`에 한국어 메시지 추가
- [ ] `/lang/en/validation.php`에 영어 메시지 추가
- [ ] Custom Rule에서 모든 `$fail()` 호출 시 `__()` 함수 사용
- [ ] 동적 값은 파라미터 배열로 전달 (예: `['field' => $fieldName]`)
- [ ] 두 언어 모두에서 테스트 수행

---

## Service-Repository 패턴 {#service-repository-패턴}

FormRequest 의 검증 로직(closure rule, `prepareForValidation`, `withValidator`,
`messages` 등)이 데이터 조회를 필요로 할 때, Service 와 동일하게 Repository
Interface 경유 패턴을 따른다.

### 금지 — Model facade 직접 호출

```php
// ❌ Closure rule 안에서 Model::where 직접 호출
protected function uniqueRule(): Closure
{
    return function ($attribute, $value, $fail) {
        $exists = User::where('email', $value)->exists(); // ❌
        if ($exists) { $fail(__('...')); }
    };
}

// ❌ prepareForValidation 안에서 Model facade
protected function prepareForValidation(): void
{
    $template = Template::where('identifier', $name)->first(); // ❌
}
```

### 필수 — Repository Interface 경유

FormRequest 는 생성자 주입 대신 `app(Interface::class)` 로 해석한다 (Laravel
의 FormRequest 는 컨테이너 해석 시점이 다르며, 모든 검증 closure 가 인스턴스
스코프 안에서 동작하므로 helper 호출이 가장 단순).

```php
use App\Contracts\Repositories\UserRepositoryInterface;

protected function uniqueRule(): Closure
{
    return function ($attribute, $value, $fail) {
        $exists = app(UserRepositoryInterface::class)->existsByEmail($value);
        if ($exists) { $fail(__('...')); }
    };
}

protected function prepareForValidation(): void
{
    $template = app(TemplateRepositoryInterface::class)
        ->findByIdentifier($this->route('templateName'));
}
```

조회에 필요한 메서드가 Repository Interface 에 없으면 **Interface 에 메서드를
추가** 한 뒤 호출한다 (검증 로직 자체를 Service 로 옮기지 않는다 — 검증은
FormRequest 책임).

### 자동 검증

자동 검증이 FormRequest 내 DB facade / Model
정적 호출 / 영속 메서드(`save`/`saveQuietly`/`forceDelete`) 직접 호출을 자동
차단한다. `Rule::exists(Model::class, ...)` 같은 validation rule helper 는 면제.

---

## 관련 문서

- [예외 처리 (exceptions.md)](exceptions.md) - Custom Exception 다국어 처리
- [컨트롤러 (controllers.md)](controllers.md) - 컨트롤러 계층 구조
- [Service-Repository (service-repository.md)](service-repository.md) - 비즈니스 로직 분리
- [라우팅 (routing.md)](routing.md) - permission 미들웨어 설정
- [훅 시스템 (../extension/hooks.md)](../extension/hooks.md) - HookManager 사용법
