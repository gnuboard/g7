# Custom Exception 다국어 처리

> 이 문서는 G7에서 Custom Exception 클래스의 다국어 처리 방법을 설명합니다.

---

## TL;DR (5초 요약)

```text
1. 예외 메시지 하드코딩 금지 → __() 함수 필수
2. 메시지 위치: lang/ko/exceptions.php, lang/en/exceptions.php
3. 파라미터 치환: __('exceptions.key', ['param' => $value])
4. 코어 예외: exceptions.template_engine.* 형식
5. 모듈 예외: vendor-module::exceptions.* 형식
```

---

## 목차

1. [핵심 원칙](#핵심-원칙)
2. [다국어 파일 위치 및 구조](#다국어-파일-위치-및-구조)
3. [파라미터 치환 패턴](#파라미터-치환-패턴)
4. [템플릿 엔진 Custom Exception 예시](#템플릿-엔진-custom-exception-예시)
5. [Custom Exception 개발 체크리스트](#custom-exception-개발-체크리스트)
6. [관련 문서](#관련-문서)

---

## 핵심 원칙

```
필수: __() 함수 사용 (예외 메시지 하드코딩 금지)
필수: __() 함수를 사용한 다국어 처리
```

### 필수 규칙

- **모든 예외 메시지는 다국어 파일에 정의**: `/lang/ko/exceptions.php`, `/lang/en/exceptions.php`
- **Exception 생성자에서 __() 함수 사용**: 하드코딩된 문자열 사용 금지
- **파라미터 치환 지원**: `:trace`, `:max`, `:id` 등의 동적 값 지원
- **일관성 유지**: 모든 커스텀 예외가 동일한 패턴 사용

---

## 다국어 파일 위치 및 구조

### 파일 위치

```
/lang/ko/exceptions.php  # 한국어 예외 메시지
/lang/en/exceptions.php  # 영어 예외 메시지
```

### 파일 구조 예시

```php
// /lang/ko/exceptions.php
return [
    'circular_reference' => '레이아웃 순환 참조 감지: :trace',
    'max_depth_exceeded' => '레이아웃 중첩 깊이가 최대 허용 깊이(:max)를 초과했습니다.',
    'resource_not_found' => ':resource를 찾을 수 없습니다.',
];

// /lang/en/exceptions.php
return [
    'circular_reference' => 'Layout circular reference detected: :trace',
    'max_depth_exceeded' => 'Layout nesting depth exceeds maximum allowed depth (:max).',
    'resource_not_found' => ':resource not found.',
];
```

### 중첩 구조 (네임스페이스 그룹화)

```php
// /lang/ko/exceptions.php
return [
    'template' => [
        'circular_reference' => '레이아웃 순환 참조 감지: :trace',
        'max_depth_exceeded' => '레이아웃 중첩 깊이(:current)가 최대 허용 깊이(:max)를 초과했습니다.',
        'template_not_found' => '템플릿을 찾을 수 없습니다: :identifier',
        'layout_not_found' => '레이아웃을 찾을 수 없습니다: :layout_name',
        'component_not_found' => '컴포넌트를 찾을 수 없습니다: :component_name',
        'invalid_layout_structure' => '유효하지 않은 레이아웃 구조입니다.',
    ],
];

// /lang/en/exceptions.php
return [
    'template' => [
        'circular_reference' => 'Layout circular reference detected: :trace',
        'max_depth_exceeded' => 'Layout nesting depth (:current) exceeds maximum allowed depth (:max).',
        'template_not_found' => 'Template not found: :identifier',
        'layout_not_found' => 'Layout not found: :layout_name',
        'component_not_found' => 'Component not found: :component_name',
        'invalid_layout_structure' => 'Invalid layout structure.',
    ],
];
```

---

## 파라미터 치환 패턴

### 잘못된 예시 (DON'T)

```php
// ❌ 하드코딩된 예외 메시지 - 금지
class CircularReferenceException extends Exception
{
    public function __construct(array $stack, string $currentLayout)
    {
        $stackTrace = implode(' → ', $stack) . " → {$currentLayout}";
        $message = "레이아웃 순환 참조 감지: {$stackTrace}";  // 한국어만 지원, 다국어 불가
        parent::__construct($message);
    }
}
```

### 올바른 예시 (DO)

```php
// ✅ 다국어 함수 사용 (파라미터 치환)
class CircularReferenceException extends Exception
{
    private array $stack;

    public function __construct(array $stack, string $currentLayout)
    {
        $this->stack = $stack;

        // ✅ 다국어 함수 사용 (파라미터 치환)
        $stackTrace = implode(' → ', $stack) . " → {$currentLayout}";
        $message = __('exceptions.circular_reference', ['trace' => $stackTrace]);

        parent::__construct($message);
    }

    public function getStack(): array
    {
        return $this->stack;
    }
}
```

### 다중 파라미터 처리

```php
class MaxDepthExceededException extends Exception
{
    public function __construct(int $currentDepth, int $maxDepth)
    {
        // ✅ 다국어 함수 사용 (다중 파라미터)
        $message = __('exceptions.max_depth_exceeded', [
            'current' => $currentDepth,
            'max' => $maxDepth
        ]);

        parent::__construct($message);
    }
}
```

---

## 템플릿 엔진 Custom Exception 예시

### CircularReferenceException (레이아웃 순환 참조)

템플릿 레이아웃 상속 시 순환 참조가 발생하면 이 예외를 발생시킵니다.

```php
<?php

namespace App\Exceptions\Template;

use Exception;

/**
 * 레이아웃 순환 참조 예외
 */
class CircularReferenceException extends Exception
{
    private array $stack;

    public function __construct(array $stack, string $currentLayout)
    {
        $this->stack = $stack;

        // ✅ 다국어 함수 사용
        $stackTrace = implode(' → ', $stack) . " → {$currentLayout}";
        $message = __('exceptions.template.circular_reference', ['trace' => $stackTrace]);

        parent::__construct($message);
    }

    /**
     * 순환 참조 스택 반환
     */
    public function getStack(): array
    {
        return $this->stack;
    }
}
```

### MaxDepthExceededException (레이아웃 깊이 초과)

레이아웃 상속 깊이가 최대 허용 깊이(10)를 초과하면 이 예외를 발생시킵니다.

```php
<?php

namespace App\Exceptions\Template;

use Exception;

/**
 * 레이아웃 최대 깊이 초과 예외
 */
class MaxDepthExceededException extends Exception
{
    private int $currentDepth;
    private int $maxDepth;

    public function __construct(int $currentDepth, int $maxDepth = 10)
    {
        $this->currentDepth = $currentDepth;
        $this->maxDepth = $maxDepth;

        // ✅ 다국어 함수 사용 (다중 파라미터)
        $message = __('exceptions.template.max_depth_exceeded', [
            'current' => $currentDepth,
            'max' => $maxDepth
        ]);

        parent::__construct($message);
    }

    /**
     * 현재 깊이 반환
     */
    public function getCurrentDepth(): int
    {
        return $this->currentDepth;
    }

    /**
     * 최대 허용 깊이 반환
     */
    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }
}
```

### Service에서 사용 예시 (LayoutService)

```php
<?php

namespace App\Services\Template;

use App\Exceptions\Template\CircularReferenceException;
use App\Exceptions\Template\MaxDepthExceededException;

class LayoutService
{
    private const MAX_DEPTH = 10;

    /**
     * 레이아웃 병합 (상속 체인 처리)
     *
     * @throws CircularReferenceException 순환 참조 발생 시
     * @throws MaxDepthExceededException 깊이 초과 시
     */
    public function mergeLayouts(string $layoutName, array $stack = [], int $depth = 0): array
    {
        // 순환 참조 검사
        if (in_array($layoutName, $stack)) {
            throw new CircularReferenceException($stack, $layoutName);
        }

        // 깊이 검사
        if ($depth > self::MAX_DEPTH) {
            throw new MaxDepthExceededException($depth, self::MAX_DEPTH);
        }

        // 재귀적으로 레이아웃 병합
        // ...
    }
}
```

---

## Custom Exception 개발 체크리스트

새로운 Custom Exception을 개발할 때 다음 항목을 확인하세요:

- [ ] `/lang/ko/exceptions.php`에 한국어 메시지 추가
- [ ] `/lang/en/exceptions.php`에 영어 메시지 추가
- [ ] Exception 생성자에서 모든 메시지는 `__()` 함수 사용
- [ ] 동적 값은 파라미터 배열로 전달 (예: `['trace' => $stackTrace]`)
- [ ] 두 언어 모두에서 테스트 수행
- [ ] 예외 메시지가 사용자에게 노출될 경우 보안 정보 포함 금지

### 새 Exception 추가 절차

1. **다국어 키 정의**: 적절한 네임스페이스로 그룹화
2. **Exception 클래스 생성**: `__()` 함수로 메시지 생성
3. **필요한 속성 저장**: 디버깅이나 로깅에 필요한 값 보관
4. **getter 메서드 제공**: 저장된 속성에 접근할 수 있도록 제공
5. **테스트 작성**: 두 언어 환경에서 메시지 확인

---

## 관련 문서

- [validation.md](./validation.md) - Custom Rule 다국어 처리
- [index.md](./index.md) - 백엔드 가이드 인덱스
