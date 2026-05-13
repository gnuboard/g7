# Enum 사용 규칙

> **관련 문서**: [백엔드 가이드 인덱스](./index.md) | [검증 로직](./validation.md)

---

## TL;DR (5초 요약)

```text
1. 상태/타입/분류 = Enum 필수 (PHP 8.1+ Backed Enum)
2. 위치: app/Enums/ 또는 modules/*/src/Enums/
3. DB: enum 컬럼 또는 string + 검증
4. FormRequest: Rule::enum(StatusEnum::class) 사용
5. cases(): 모든 값 조회, tryFrom(): 안전한 변환
```

---

## 목차

- [핵심 원칙](#핵심-원칙)
- [Enum 사용 대상](#enum-사용-대상)
- [잘못된 예시](#잘못된-예시)
- [올바른 예시](#올바른-예시)
- [파일 위치](#파일-위치)
- [데이터베이스 연동](#데이터베이스-연동)
- [FormRequest 검증에서 사용](#formrequest-검증에서-사용)
- [개발 체크리스트](#개발-체크리스트)

---

## 핵심 원칙

```text
상태, 타입, 분류 등 제한된 값 집합은 반드시 Enum으로 정의
✅ 필수: PHP 8.1+ Backed Enum 사용
```

**핵심 원칙**:

- **타입 안전성**: 문자열/정수 대신 Enum 사용으로 타입 안전성 확보
- **자동완성**: IDE의 자동완성 지원으로 개발 생산성 향상
- **유지보수**: 값 변경 시 한 곳에서만 수정
- **유효성 검증**: 잘못된 값 입력 방지

---

## Enum 사용 대상

| 상황 | 예시 | Enum 사용 |
|------|------|----------|
| 상태 값 | active, inactive, pending | ✅ 필수 |
| 타입 구분 | template, module, plugin | ✅ 필수 |
| 고정 옵션 | admin, user, guest | ✅ 필수 |
| 가변 데이터 | 사용자 입력, 동적 값 | ❌ 불필요 |

---

## 잘못된 예시

```php
// ❌ 문자열 직접 사용 - 타입 안전성 없음
class Module
{
    public function setStatus(string $status): void
    {
        $this->status = $status; // 'actve' 오타 가능
    }
}

// ❌ 상수로 정의 - 타입 체크 불가
class ModuleStatus
{
    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';
}

$module->setStatus('invalid'); // 오류 없이 통과
```

---

## 올바른 예시

```php
// ✅ Backed Enum 정의
<?php

namespace App\Enums;

/**
 * 확장(모듈, 플러그인, 템플릿) 상태 Enum
 */
enum ExtensionStatus: string
{
    /**
     * 활성화됨
     */
    case Active = 'active';

    /**
     * 비활성화됨
     */
    case Inactive = 'inactive';

    /**
     * 설치 중
     */
    case Installing = 'installing';

    /**
     * 제거 중
     */
    case Uninstalling = 'uninstalling';

    /**
     * 제거됨 (미설치 상태)
     */
    case Uninstalled = 'uninstalled';

    /**
     * 업데이트 중
     */
    case Updating = 'updating';

    /**
     * 모든 상태 값을 문자열 배열로 반환
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 상태 값인지 확인
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}

// ✅ 타입 힌팅으로 안전하게 사용
class Module
{
    public function setStatus(ExtensionStatus $status): void
    {
        $this->status = $status->value;
    }
}

$module->setStatus(ExtensionStatus::Active); // 타입 안전
$module->setStatus('invalid'); // 컴파일 오류!
```

---

## 파일 위치

```text
app/Enums/                    # 코어 Enum
├── ExtensionStatus.php       # 확장 상태
├── LayoutSourceType.php      # 레이아웃 소스 타입
├── ScopeType.php             # 권한 스코프 타입
└── UserRole.php              # 사용자 역할

modules/[vendor-module]/src/Enums/  # 모듈 Enum
```

### ScopeType Enum — 권한 스코프

역할별 권한의 접근 범위를 정의합니다. `role_permissions` 피벗 테이블의 `scope_type` 컬럼에서 사용됩니다.

```php
enum ScopeType: string
{
    /** 본인 리소스만 접근 */
    case Self = 'self';

    /** 소유 역할 공유 사용자의 리소스 접근 */
    case Role = 'role';
}
// null = 전체 접근 (제한 없음)
```

**scope_type 값과 의미**:

| 값 | 의미 | 목록 필터링 | 상세 접근 체크 |
| ---- | -------------- | ----------------------------------------- | -------------- |
| `null` | 전체 접근 | 필터 미적용 | 항상 통과 |
| `'self'` | 본인 리소스만 | `WHERE {owner_key} = {user_id}` | 소유자 일치 체크 |
| `'role'` | 소유역할 범위 | `WHERE {owner_key} IN (역할 공유 사용자)` | 역할 공유 체크 |

**union 정책** (복수 역할 보유 시): `null`(전체) > `'role'`(소유역할) > `'self'`(본인) — 가장 넓은 범위 적용

> 상세: [permissions.md](../extension/permissions.md) scope_type 시스템 참조

---

## 데이터베이스 연동

```php
// 마이그레이션에서 Enum 값 사용
use App\Enums\ExtensionStatus;

$table->string('status')->default(ExtensionStatus::Inactive->value)
    ->comment('상태: ' . implode(', ', ExtensionStatus::values()));

// 모델에서 캐스팅
protected $casts = [
    'status' => ExtensionStatus::class,
];
```

---

## FormRequest 검증에서 사용

```php
use App\Enums\ExtensionStatus;
use Illuminate\Validation\Rule;

public function rules(): array
{
    return [
        'status' => ['required', Rule::enum(ExtensionStatus::class)],
    ];
}
```

---

## Enum 표준 헬퍼 메서드

G7에서 Enum에 추가하는 표준 헬퍼 메서드입니다.

### 필수 메서드

```php
enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Blocked = 'blocked';
    case Withdrawn = 'withdrawn';

    /**
     * 모든 상태 값을 문자열 배열로 반환
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 값인지 확인
     *
     * @param string $value 검증할 값
     * @return bool
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 다국어 라벨 반환
     *
     * @return string
     */
    public function label(): string
    {
        return __('user.status.' . $this->value);
    }
}
```

### 선택 메서드 (UI 연동 시)

```php
    /**
     * CSS variant 반환 (Badge, Tag 등에서 사용)
     *
     * @return string
     */
    public function variant(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'warning',
            self::Blocked => 'danger',
            self::Withdrawn => 'secondary',
        };
    }

    /**
     * 아이콘 클래스 반환
     *
     * @return string
     */
    public function icon(): string
    {
        return match ($this) {
            self::Active => 'fa-check-circle',
            self::Inactive => 'fa-pause-circle',
            self::Blocked => 'fa-ban',
            self::Withdrawn => 'fa-user-slash',
        };
    }
```

### 메서드 요약

| 메서드 | 유형 | 필수 | 용도 |
| ------ | ------ | ------ | ------ |
| `values()` | static | ✅ | 마이그레이션 comment, 검증 등 |
| `isValid()` | static | ✅ | 값 유효성 검증 |
| `label()` | instance | ✅ | API 응답의 다국어 라벨 |
| `variant()` | instance | 선택 | UI Badge/Tag 스타일 매핑 |
| `icon()` | instance | 선택 | UI 아이콘 매핑 |

---

## 개발 체크리스트

- [ ] Backed Enum 사용 (string 또는 int)
- [ ] 각 case에 한국어 PHPDoc 주석 추가
- [ ] `values()` 헬퍼 메서드 구현
- [ ] `isValid()` 헬퍼 메서드 구현 (필요시)
- [ ] 마이그레이션 comment에 가능한 값 명시
- [ ] 모델에서 Enum 캐스팅 설정

---

## 관련 문서

- [검증 로직](./validation.md) - FormRequest에서 Enum 검증
- [데이터베이스 가이드](../database-guide.md) - 마이그레이션 규칙
- [백엔드 가이드 인덱스](./index.md) - 전체 목차
