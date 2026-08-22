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
6. 응답에는 `getMessage()` 가 아니라 **메시지 키**를 넘긴다 (예외는 키를 들고 다닌다)
7. typed 예외 → 기존 4xx 유지 / generic catch → 5xx (장애를 입력 오류로 위장하지 않는다)
```

---

## 목차

1. [핵심 원칙](#핵심-원칙)
2. [다국어 파일 위치 및 구조](#다국어-파일-위치-및-구조)
3. [파라미터 치환 패턴](#파라미터-치환-패턴)
4. [예외 → 응답 매핑](#예외--응답-매핑)
5. [템플릿 엔진 Custom Exception 예시](#템플릿-엔진-custom-exception-예시)
6. [Custom Exception 개발 체크리스트](#custom-exception-개발-체크리스트)
7. [관련 문서](#관련-문서)

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

### 치환 자리는 비워 둘 수 없다

`:error` 같은 치환 자리를 가진 키를 파라미터 없이 부르면, 번역기는 그 자리를 **그대로 둔 문장**을
돌려준다. 그래서 운영자 화면에 `모듈 활성화에 실패했습니다: :error` 처럼 내부 자리표시자가 노출된다.
예외도 로그도 남지 않고 실패했을 때만 드러나므로, 정상 흐름만 보는 테스트로는 잡히지 않는다.

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| `error('x.activate_failed')` — 치환 자리를 가진 키를 파라미터 없이 | `error('x.activate_failed', 400, null, ['error' => $reason])` |
| 사유를 모른다고 자리를 비워 두기 | 사유를 알 수 없으면 일반 문구로 채운다 (`errors.unknown_error`) |
| 원인을 싣지 않기로 한 문구에 `:error` 자리를 남겨 두기 | 그 키에서 치환 자리 자체를 없앤다 |
| 하위 계층이 `false` 만 돌려주고 사유를 버리기 | 사유를 반환 경로에 실어 올린다 (배열 키 또는 선택적 out 파라미터) |

셋째 인자 `errors` 페이로드와 넷째 인자 `messageParams` 는 **다른 통로**다. `errors` 에 예외를 넘기는
것은 진단 정보이고(노출 폭은 `ResponseHelper` 가 `app.debug` 로 정한다), 문구의 치환 자리를 채우는
것은 `messageParams` 뿐이다. 한쪽만 채우면 자리표시자는 그대로 남는다.

응답 문구에 예외 원문을 싣지 않기로 정한 화면(언어팩 관리 등)은 **파라미터를 채우는 대신 키에서
치환 자리를 없앤다.** 자리를 남긴 채 일반 문구로 채우면 "…실패했습니다: 알 수 없는 오류" 처럼
의미 없는 꼬리가 붙고, 나중에 누군가 그 자리를 예외 원문으로 채우는 회귀를 부른다.

---

## 예외 → 응답 매핑

예외를 응답으로 바꾸는 자리에서 두 가지가 자주 어긋난다. 둘 다 오류도 로그도 남기지 않아 코드를 나란히 놓고 보기 전에는 드러나지 않는다.

시작하기 전에 통로부터 나눈다. `ResponseHelper::error($messageKey, $statusCode, $errors, $messageParams)` 는 인자마다 노출 규칙이 다르다.

| 인자 | 통로 | 노출 규칙 |
|---|---|---|
| `$messageKey` (1번째) | 사용자에게 보이는 안내 문구 | **다국어 키 전용.** 번역문·예외 원문을 넣으면 키 해석에 실패해 원문이 그대로 나간다 |
| `$errors` (3번째) — Throwable | 진단 | `app.debug` 에서만 `debug` 블록으로 펼쳐진다 |
| `$errors` (3번째) — 문자열 | 진단 | `500` 이상 + 비디버그에서 **차단**, 그 외 노출 |
| `$errors` (3번째) — 배열 | 구조화 페이로드 | **항상 노출** (검증 오류 구조가 이 통로를 쓴다) |
| `$messageParams` (4번째) | 문구 치환 | 응답 본문에 직접 실리지 않는다 |

그래서 "예외 원문을 응답에 싣느냐" 는 하나의 규칙으로 답할 수 없다. 판단 축은 셋이다.

- **누구에게** — 관리자 전용 면인가, 공개(비인증) 엔드포인트인가. 공개 면에 SQL 상태코드·경로가 나가면 정보 노출이다. 반대로 관리자에게 실패 사유를 감추면, 서버 로그 접근 권한이 없는 운영자는 "실패했습니다" 외에 아무 근거도 얻지 못한다.
- **무엇의 원문인가** — 우리 인프라 예외인가, 외부 시스템(결제대행사·배송사 API)이 돌려준 도메인 사유인가. 후자는 우리 스택 정보가 아니고 다국어 키로 옮길 수도 없다. 감추면 조치 가능한 정보가 사라진다.
- **어느 통로인가** — 위 표. 노출 폭은 호출부가 문자열로 조립하지 말고 헬퍼에 맡긴다.

다국어 키는 유한하고 실패 사유는 무한하다. 키만 고집하면 예상 못 한 실패가 전부 "작업에 실패했습니다" 하나로 붕괴하는데, 이는 아래 2번이 막으려는 "장애가 입력 오류로 위장" 과 같은 종류의 정보 손실이다.

### 1. 응답에는 메시지 키를 넘긴다

`ResponseHelper::error()` 의 첫 인자는 **다국어 키**다. 이미 번역된 `$e->getMessage()` 를 그 자리에 넘기면 키 해석에 실패해 원문이 그대로 사용자 화면에 나간다 — 원문에는 SQL 상태코드·경로·클래스명이 섞여 있을 수 있다.

그래서 도메인 예외는 **번역문이 아니라 키를 들고 다닌다**. 생성자에서 키와 치환 파라미터를 보관하고 `getMessageKey()` / `getMessageParams()` 로 노출한다.

```php
class BoardTypeOperationException extends Exception
{
    public function __construct(
        private string $messageKey,
        private array $replace = []
    ) {
        parent::__construct(__($messageKey, $replace));
    }

    public function getMessageKey(): string { return $this->messageKey; }

    public function getMessageParams(): array { return $this->replace; }
}
```

```php
// ❌ 번역문을 키 자리에 — 해석 실패 → 원문 노출
return $this->error($e->getMessage(), 422);

// ✅ 키 + 치환 파라미터
return $this->error($e->getMessageKey(), 422, null, $e->getMessageParams());
```

사유 식별자만 들고 다니는 예외(`getReason()`)라면 호출부가 그 식별자로 키를 조립한다 — 이때도 응답에 들어가는 것은 키다.

### 2. typed 는 기존 4xx, generic 은 5xx

`catch (\Exception)` / `catch (\Throwable)` 는 **도메인 예외가 아닌 것**을 잡는 자리다. 여기서 4xx 를 돌려주면 인프라 장애와 코드 결함이 "입력 오류" 로 위장되어, 사용자는 고칠 수 없는 안내를 보고 운영자는 장애를 늦게 안다.

```php
try {
    $this->service->doSomething($order, $data);
} catch (OrderModificationException $e) {
    // 도메인 규칙 위반 — 사용자가 고칠 수 있다. 기존 상태코드를 그대로 유지한다.
    return ResponseHelper::error($e->getMessageKey(), 422, null, $e->getMessageParams());
} catch (\Exception $e) {
    // 그 외 — 서버 결함/인프라 장애. 메시지 키 자리에는 원문을 넣지 않는다.
    // (진단이 필요하면 $errors 인자로 Throwable 을 넘겨 헬퍼의 debug 게이트에 맡긴다)
    Log::error('...', ['error' => $e->getMessage()]);

    return ResponseHelper::error('sirsoft-ecommerce::exceptions.operation_failed', 500);
}
```

규칙 셋:

- typed 예외를 잡는 분기의 상태코드는 **바꾸지 않는다**. 예외를 도입하는 작업이 사용자에게 보이는 계약을 함께 바꾸면 회귀다.
- 서비스가 typed 예외를 던지는데 컨트롤러에 그 typed catch 가 없으면, 도메인 사유가 generic 으로 흘러 500 이 된다. 서비스를 typed 로 승격할 때는 **호출하는 컨트롤러 메서드를 전수로** 함께 본다.
- 상태코드 인자를 생략한 `ResponseHelper::error('key')` / `moduleError($mod, 'key')` 는 기본값 **400** 이다. generic catch 안에서는 인자를 생략하지 않는다.
- **도메인 예외의 부모를 잡지 않는다.** 도메인 예외는 대개 `\RuntimeException` 을 상속하므로, 서비스를 typed 로 승격한 뒤에도 `catch (\RuntimeException)` 을 남겨 두면 그 자리는 도메인 예외를 잡는 것처럼 보이지만 실제로 남는 것은 인프라 예외뿐이다. 그것까지 4xx 로 나가면 승격 작업이 아무것도 고치지 못한 셈이 된다. 승격한 예외 이름으로 좁힌다.

의도적으로 4xx 를 유지하는 generic catch 도 있다 — 업로드 파일 해석처럼 실패 원인이 대부분 클라이언트 입력인 경우다. 그런 지점은 사유와 함께 명시적으로 선언하고, 판정에서 조용히 빠지게 두지 않는다.

`tests/Feature/Http/GenericCatchStatusCodeContractTest.php` 가 코어와 모든 번들 확장의 컨트롤러를 훑어 이 두 규칙을 고정한다. 예외 목록을 상수로 선언해 두었으므로, 새 예외를 만들려면 목록에 사유와 함께 추가해야 한다.

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
- [ ] **메시지 키와 치환 파라미터를 보관하고 `getMessageKey()` / `getMessageParams()` 로 노출** (응답에 번역문 대신 키를 넘기기 위함)
- [ ] **이 예외를 던지는 서비스 메서드를 호출하는 컨트롤러 메서드 전수에 typed catch 가 있는지 확인** (없으면 도메인 사유가 generic 으로 흘러 500 이 된다)
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
