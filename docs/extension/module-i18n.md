# 모듈 다국어 시스템

> **관련 문서**: [index.md](index.md) | [module-basics.md](module-basics.md) | [module-routing.md](module-routing.md)

---

## TL;DR (5초 요약)

```text
1. 백엔드: /lang/{locale}/*.php → __('vendor-module::key')
2. 프론트엔드: /resources/lang/{locale}.json → $t:key
3. JSON에 moduleIdentifier 포함 금지! (자동 병합됨)
4. 지원 언어: ko, en 필수
5. 키 충돌 방지: 모듈별 고유 prefix 사용 권장
```

---

## 목차

- [핵심 원칙](#핵심-원칙)
- [백엔드 다국어](#백엔드-다국어)
- [프론트엔드 다국어](#프론트엔드-다국어)
- [레이아웃 JSON에서 사용](#레이아웃-json에서-사용)

---

## 핵심 원칙

### 다국어 파일 구조

```
중요: 백엔드와 프론트엔드 다국어 파일 경로 구분
✅ 필수: resources/lang/*.json 파일에 moduleIdentifier 없이 작성
```

| 구분 | 파일 경로 | 형식 | 사용처 |
|------|----------|------|--------|
| 백엔드 | `/lang/{locale}/*.php` | PHP 배열 | Laravel `__()` 함수 |
| 프론트엔드 | `/resources/lang/{locale}.json` | JSON | 레이아웃 JSON `$t:` 문법 |

### moduleIdentifier 규칙

```
✅ 프론트엔드 다국어 파일에는 moduleIdentifier 없이 순수 키만 작성
✅ 템플릿 서빙 시 자동으로 moduleIdentifier를 최상위 키로 병합
❌ 내부 JSON 파일에 { "sirsoft-sample": { ... } } 형태로 작성 금지
```

---

## 백엔드 다국어

### 파일 위치

```
modules/sirsoft-sample/
└── src/
    └── lang/
        ├── ko/
        │   └── messages.php
        └── en/
            └── messages.php
```

**중요**: 백엔드 다국어 파일은 반드시 `src/lang/` 디렉토리에 위치해야 합니다. TranslationServiceProvider가 이 경로에서 파일을 자동으로 로드합니다.

### 파일 형식 (PHP 배열)

```php
// src/lang/ko/messages.php
return [
    'product_created' => '상품이 생성되었습니다.',
    'order_confirmed' => '주문이 확인되었습니다.',
    'validation' => [
        'name_required' => '상품명은 필수 항목입니다.',
        'price_min' => '가격은 0 이상이어야 합니다.',
    ],
];
```

```php
// lang/en/messages.php
return [
    'product_created' => 'Product has been created.',
    'order_confirmed' => 'Order has been confirmed.',
    'validation' => [
        'name_required' => 'Product name is required.',
        'price_min' => 'Price must be at least 0.',
    ],
];
```

### 사용법

Laravel의 `__()` 함수를 사용합니다. **모듈 다국어는 더블 콜론(::) 문법을 사용합니다**:

```php
// 모듈 네임스페이스 사용 (더블 콜론 ::)
__('sirsoft-sample::messages.product_created');
// 결과: '상품이 생성되었습니다.' (ko 로케일)

// 중첩 키
__('sirsoft-sample::messages.validation.name_required');
// 결과: '상품명은 필수 항목입니다.'

// 파라미터 치환
__('sirsoft-sample::messages.greeting', ['name' => '홍길동']);
// messages.php: 'greeting' => ':name님, 환영합니다.'
// 결과: '홍길동님, 환영합니다.'

// ❌ 잘못된 사용 (점 . 사용)
__('sirsoft-sample.messages.product_created');  // 작동하지 않음!

// ✅ 올바른 사용 (더블 콜론 :: 사용)
__('sirsoft-sample::messages.product_created');  // 정상 작동
```

---

## 프론트엔드 다국어

### 파일 위치

```
modules/sirsoft-sample/
└── resources/
    └── lang/
        ├── ko.json
        └── en.json
```

### 파일 형식 (JSON)

```json
// resources/lang/ko.json
{
  "admin": {
    "index": {
      "title": "샘플 항목 관리",
      "description": "샘플 모듈의 항목을 관리합니다"
    },
    "create": {
      "title": "새 항목 생성",
      "submit": "생성하기"
    }
  },
  "messages": {
    "save_success": "저장되었습니다.",
    "delete_confirm": "정말 삭제하시겠습니까?"
  }
}
```

```json
// resources/lang/en.json
{
  "admin": {
    "index": {
      "title": "Sample Item Management",
      "description": "Manage sample module items"
    },
    "create": {
      "title": "Create New Item",
      "submit": "Create"
    }
  },
  "messages": {
    "save_success": "Saved successfully.",
    "delete_confirm": "Are you sure you want to delete?"
  }
}
```

### moduleIdentifier 자동 병합

템플릿 서빙 시 시스템이 자동으로 moduleIdentifier를 최상위 키로 병합합니다:

```json
// 원본 파일 (resources/lang/ko.json)
{
  "admin": {
    "index": {
      "title": "샘플 항목 관리"
    }
  }
}

// 서빙 후 (자동 변환)
{
  "sirsoft-sample": {
    "admin": {
      "index": {
        "title": "샘플 항목 관리"
      }
    }
  }
}
```

---

## $partial Fragment 시스템

### 개요

프론트엔드 다국어 JSON 파일이 커지면 `$partial` 디렉티브를 사용하여 도메인별로 분리할 수 있습니다.
`ResolvesLanguageFragments` trait이 JSON 로드 시 fragment를 자동으로 병합합니다.

### 디렉토리 구조

```
modules/_bundled/vendor-module/
└── resources/lang/
    ├── ko.json                       ← 메인 JSON (fragment 참조 포함)
    ├── en.json
    └── partial/                      ← fragment 파일 디렉토리
        ├── ko/
        │   ├── common.json
        │   └── admin/
        │       ├── locale.json
        │       ├── products.json
        │       └── orders.json
        └── en/
            ├── common.json
            └── admin/
                └── ...
```

### $partial 디렉티브 문법

메인 JSON 파일에서 `$partial` 키로 fragment 파일을 참조합니다:

```json
{
    "common": {
        "$partial": "partial/ko/common.json"
    },
    "admin": {
        "locale": {
            "$partial": "partial/ko/admin/locale.json"
        },
        "products": {
            "$partial": "partial/ko/admin/products.json"
        }
    }
}
```

- `$partial` 값은 **`resources/lang/` 기준 상대 경로**
- `$partial`이 포함된 객체는 fragment 파일의 내용으로 **완전 교체**됨
- fragment 파일 내부에서 다시 `$partial`을 사용한 **중첩 참조 가능** (최대 깊이 제한 적용)

### Fragment 해석 규칙

| 규칙 | 설명 |
| ---- | ---- |
| 최대 깊이 | `MAX_FRAGMENT_DEPTH = 10` (초과 시 해석 중단) |
| 순환 참조 | `fragmentStack`으로 감지, 순환 시 해당 fragment 무시 |
| 파일 미존재 | 해당 `$partial` 객체가 빈 객체로 대체 |
| 재귀 해석 | fragment 내 `$partial`도 재귀적으로 해석 |

### 사용 기준

| 상황 | 권장 |
| ---- | ---- |
| JSON 파일 500줄 이하 | 단일 파일 유지 |
| JSON 파일 500줄 초과 | 도메인별 `$partial` 분리 |
| admin/user 섹션 분리 필요 | `partial/{locale}/admin/`, `partial/{locale}/user/` |

> **참고**: `$partial`은 프론트엔드 JSON(`resources/lang/`)에서만 사용됩니다.
> 백엔드 PHP(`src/lang/`)에서는 사용할 수 없습니다.

---

## 레이아웃 JSON에서 사용

### 기본 문법

레이아웃 JSON에서 `$t:` 접두사를 사용하여 다국어 키를 참조합니다:

```json
{
  "id": "page-title",
  "type": "basic",
  "name": "H1",
  "props": {
    "text": "$t:sirsoft-sample.admin.index.title"
  }
}
```

### 키 구조

```
$t:[moduleIdentifier].[섹션].[하위섹션].[키]

예시:
$t:sirsoft-sample.admin.index.title
$t:sirsoft-sample.messages.save_success
```

### 다양한 사용 예시

```json
{
  "id": "page-header",
  "type": "composite",
  "name": "PageHeader",
  "props": {
    "title": "$t:sirsoft-sample.admin.index.title",
    "description": "$t:sirsoft-sample.admin.index.description"
  }
}
```

```json
{
  "id": "submit-button",
  "type": "basic",
  "name": "Button",
  "props": {
    "text": "$t:sirsoft-sample.admin.create.submit",
    "variant": "primary"
  }
}
```

---

## 관련 문서

- [모듈 개발 기초](module-basics.md) - AbstractModule, 디렉토리 구조
- [모듈 라우트 규칙](module-routing.md) - 라우트 네이밍, 자동 Prefix
- [모듈 레이아웃 시스템](module-layouts.md) - 레이아웃 등록, 오버라이드
- [데이터 바인딩](../frontend/data-binding.md) - $t: 문법 상세
