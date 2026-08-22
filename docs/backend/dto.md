# DTO (Data Transfer Object) 사용 규칙

> **백엔드 가이드** | [목차로 돌아가기](README.md)

---

## TL;DR (5초 요약)

```text
1. DTO 두 패턴 — Value Object(불변 1회 전달) vs Data Carrier(다단계 변형/누적). 도메인 본질에 맞게 선택
2. 위치: app/{도메인}/DTO/ 또는 app/Extension/{영역}/DTO/, 모듈/플러그인은 src/DTO/. 네이밍 접미사(Dto/Data) 금지
3. 외부 라이브러리(Spatie Data 등) 도입 금지 — readonly class + constructor promotion 으로 충분
4. Eloquent 모델 직렬화는 ApiResource, 모델 아닌 값 묶음만 DTO. 두 계층 겹치지 않음
5. 모든 DTO 는 toArray() 정의. fromArray() 는 캐시·스냅샷 재구성 필요할 때만
```

---

## 목차

- [개요](#개요)
- [DTO vs ApiResource vs Eloquent Model 경계](#dto-vs-apiresource-vs-eloquent-model-경계)
- [두 가지 DTO 패턴](#두-가지-dto-패턴)
  - [패턴 A: Value Object (VO)](#패턴-a-value-object-vo)
  - [패턴 B: Data Carrier](#패턴-b-data-carrier)
- [패턴 선택 기준](#패턴-선택-기준)
- [공통 강제 규칙](#공통-강제-규칙)
- [관련 문서](#관련-문서)

---

## 개요

DTO 는 계층 간 데이터 전달용 객체로, **Eloquent 모델이 아닌 값 묶음**을 표현합니다. G7 에는 두 종류의 DTO 가 본질적으로 다른 도메인에 사용되며, 두 패턴 모두 정당합니다 — 한 패턴을 강제하면 다른 도메인을 망칩니다.

**현재 G7 의 DTO 사용처**:

| 위치 | 개수 | 패턴 | 용도 |
|------|------|------|------|
| `app/Extension/IdentityVerification/DTO/` | 2 | Value Object | 본인인증 challenge/result — provider 가 만들어 한 번 전달 |
| `modules/_bundled/sirsoft-ecommerce/src/DTO/` | 21 | Data Carrier | 주문 9단계 계산 파이프라인 — 단계마다 변형/누적, 캐시·스냅샷 재구성 |

---

## DTO vs ApiResource vs Eloquent Model 경계

| 계층 | 표현 대상 | G7 표준 |
|------|----------|---------|
| **Eloquent Model** | DB 테이블 1행 | `Illuminate\Database\Eloquent\Model` 상속 |
| **ApiResource** | 모델의 API 직렬화 | `BaseApiResource` / `BaseApiCollection` 상속 ([api-resources.md](api-resources.md)) |
| **DTO** | 모델이 아닌 값 묶음 (계산 결과, 외부 응답, 다단계 입력 등) | 본 가이드 |

**원칙**: 같은 데이터에 두 계층이 겹치면 안 됩니다.

```php
// ❌ 금지: Eloquent 모델을 DTO 로 한 번 더 감싸기
$dto = new ProductDto($product->toArray());

// ❌ 금지: DTO 를 ApiResource 로 한 번 더 감싸기
return new ProductResource($dto);  // ApiResource 는 모델 전용

// ✅ 모델은 ApiResource 로 직렬화
return new ProductResource($product);

// ✅ DTO 는 toArray() 로 직접 직렬화
return ResponseHelper::success('messages.ok', $orderCalculationResult->toArray());
```

---

## 두 가지 DTO 패턴

### 패턴 A: Value Object (VO)

**언제 쓰는가**: 한 번 만들어서 한 번 전달하고 끝. 변형 없음. 외부 입력 → 내부 처리 → 응답까지 단방향 흐름.

**적합 도메인 예시**:
- 본인인증 Challenge/Result (provider 가 발급 → 컨트롤러 → 응답)
- 외부 API 응답 파싱 결과 (PG 응답, 외부 인증 callback 등)
- 도메인 이벤트 페이로드
- 예외에 첨부하는 컨텍스트 정보

**골격 코드** (실제 레퍼런스: [app/Extension/IdentityVerification/DTO/VerificationChallenge.php](../../app/Extension/IdentityVerification/DTO/VerificationChallenge.php))

```php
namespace App\Extension\IdentityVerification\DTO;

use Carbon\CarbonInterface;

final readonly class VerificationChallenge
{
    /**
     * @param  string  $id  challenge UUID
     * @param  string  $providerId  프로바이더 식별자
     * @param  CarbonInterface  $expiresAt  만료 시각
     * @param  array  $publicPayload  프론트에 노출되는 공개 페이로드
     */
    public function __construct(
        public string $id,
        public string $providerId,
        public CarbonInterface $expiresAt,
        public array $publicPayload = [],
    ) {}

    /** 프론트/ResponseHelper 에 그대로 넘길 수 있는 직렬화 배열 */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->providerId,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'public_payload' => $this->publicPayload,
        ];
    }
}
```

**규칙**:
- `final readonly class` 필수 (PHP 8.2+)
- 모든 속성 `public readonly` (constructor promotion)
- `toArray()` 만 정의 (단방향)
- `fromArray()` 추가 금지 (필요해지는 순간 패턴 B 후보)
- 비즈니스 메서드 추가 금지 (순수 값)

### 패턴 B: Data Carrier

**언제 쓰는가**: 다단계 파이프라인에서 단계마다 변형·누적, 캐시/스냅샷에서 재구성, 중간 헬퍼 메서드 필요.

**적합 도메인 예시**:
- 주문 9단계 계산 결과 (각 단계가 누적 변형)
- 환불 재계산 (스냅샷에서 DTO 재구성)
- 장바구니 적용 프로모션 누적 합산

**골격 코드** (실제 레퍼런스: [modules/_bundled/sirsoft-ecommerce/src/DTO/OrderCalculationResult.php](../../modules/_bundled/sirsoft-ecommerce/src/DTO/OrderCalculationResult.php))

```php
namespace Modules\Sirsoft\Ecommerce\DTO;

class OrderCalculationResult
{
    /**
     * @param  ItemCalculation[]  $items  아이템별 계산 결과
     * @param  Summary|null  $summary  합계 정보 (생성자에서 기본값 정규화)
     * @param  array  $metadata  플러그인 확장용
     */
    public function __construct(
        public array $items = [],
        public ?Summary $summary = null,
        public array $metadata = [],
    ) {
        $this->summary = $summary ?? new Summary;
    }

    /** 도메인 헬퍼 — 검증 오류 존재 여부 확인 */
    public function hasValidationErrors(): bool
    {
        return count($this->validationErrors) > 0;
    }

    public function toArray(): array
    {
        return [
            'items' => array_map(fn ($i) => $i->toArray(), $this->items),
            'summary' => $this->summary->toArray(),
            'metadata' => $this->metadata,
        ];
    }

    /** 캐시·스냅샷에서 재구성 */
    public static function fromArray(array $data): self
    {
        return new self(
            items: array_map(fn ($i) => ItemCalculation::fromArray($i), $data['items'] ?? []),
            summary: isset($data['summary']) ? Summary::fromArray($data['summary']) : null,
            metadata: $data['metadata'] ?? [],
        );
    }
}
```

**규칙**:
- `class` (final/readonly 없음 — 변형 허용)
- 모든 속성 `public` (mutable, constructor promotion)
- `toArray()` + `static fromArray()` 양방향 (캐시/스냅샷 왕복)
- 도메인 헬퍼 메서드 허용 (`hasXxx`, `getXxx`, 집계 등)
- 생성자 본문에서 nullable 객체 기본값 정규화 패턴 허용 (`$this->summary = $summary ?? new Summary;`)
- 상속 허용 (예: `CancellationAdjustment extends OrderAdjustment`)

---

## 패턴 선택 기준

아래 표의 항목 중 **하나라도 "예"** 이면 패턴 B (Data Carrier).

| 질문 | "예" → 패턴 B |
|------|---------------|
| 객체가 만들어진 후 단계별로 값이 누적/변형되는가? | Carrier |
| 캐시·스냅샷에서 다시 객체로 재구성되는가? | Carrier |
| 객체에 대해 자주 호출되는 도메인 헬퍼 메서드가 있는가? (`hasXxx`, `getXxx`) | Carrier |
| 동일 계열 객체들의 상속 트리가 자연스러운가? | Carrier |
| 플러그인이 이 객체에 추가 데이터를 끼워넣을 수 있어야 하는가? (`metadata` 확장 슬롯) | Carrier |
| 한 번 생성 후 변형 없이 응답까지 흘러가는가? | **VO** |

**VO 가 자라서 Carrier 가 되어야 할 신호**: 처음엔 VO 였는데 시간이 지나며 `with()` 메서드가 늘어나거나, 캐시 직렬화가 필요해지거나, 헬퍼 메서드가 3개 이상 추가되면 패턴 B 로 전환을 검토합니다.

---

## 공통 강제 규칙

### 위치

| 코드 위치 | DTO 위치 |
|----------|---------|
| 코어 도메인 | `app/{도메인}/DTO/` (예: `app/Order/DTO/`) |
| 코어 확장 영역 | `app/Extension/{영역}/DTO/` (예: `app/Extension/IdentityVerification/DTO/`) |
| 코어 공통 | `app/DTO/` (도메인 분류가 모호한 범용 DTO 한정 — 가급적 도메인 하위로) |
| 모듈/플러그인 | `{확장 루트}/src/DTO/` (예: `modules/_bundled/sirsoft-ecommerce/src/DTO/`) |

### 네이밍

- ✅ 의미 단어만: `VerificationChallenge`, `OrderCalculationResult`, `ShippingAddress`
- ❌ 접미사 금지: `VerificationChallengeDto`, `OrderCalculationData`, `ShippingAddressObject`

### 외부 라이브러리

- ❌ Spatie Laravel Data, Spatie Data Transfer Object 등 **외부 패키지 도입 금지**
- ✅ PHP 8.2+ readonly class + constructor promotion 으로 충분
- 사유: 한 번 도입되면 코어 의존성 영구화. 단순 DTO 는 외부 라이브러리 가치보다 의존성 비용이 큼

### 직렬화

- 모든 DTO 는 `toArray(): array` 정의 — `ResponseHelper::success(..., $dto->toArray())` 패턴 통일
- `JsonSerializable` 인터페이스 구현은 **필요 시점에만** (DTO 가 직접 `json_encode()` 대상이 되어야 할 때)
- `fromArray()` 는 캐시·스냅샷 재구성 필요 시에만 (패턴 B 한정 권장)

### 검증

- DTO 자체에 검증 로직 두지 않음 — 검증은 [FormRequest](validation.md) 단계의 책임
- 생성자에서 던지는 예외는 **타입 강제(타입힌트)** 수준만 허용. 비즈니스 검증 금지

### 불변성 선택

| 패턴 | 키워드 | 사유 |
|------|--------|------|
| VO | `final readonly class` + `public readonly` | 변형 차단, 재사용 안전 |
| Carrier | `class` + `public` | 다단계 변형 허용, 생성자 본문 정규화 가능 |

---

## 관련 문서

- [README.md](README.md) — 백엔드 가이드 인덱스
- [api-resources.md](api-resources.md) — Eloquent 모델 직렬화 (DTO 와 경계)
- [service-repository.md](service-repository.md) — Service 가 DTO 를 만들고 반환하는 패턴
- [validation.md](validation.md) — DTO 입력 단계의 검증 책임
