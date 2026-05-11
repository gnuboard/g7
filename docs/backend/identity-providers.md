# IDV Provider 작성 가이드 (Identity Verification Providers)

새 본인인증 provider (이메일/SMS/KCP/PortOne/소셜 인증 등) 를 추가할 때 인프라 규약을 지켜야 미들웨어/listener/launcher 흐름이 정상 동작합니다.

## TL;DR (5초 요약)

```text
1. VerificationProviderInterface 구현 + IdentityProviderManager 에 등록
2. verify() 성공 시 IdentityVerificationLog 에 status=Verified + verification_token + target_hash 기록 필수
3. target_hash = SHA256(lower(이메일 또는 동등한 식별자)) — listener/middleware 가 이 키로 매칭
4. 외부 SDK 토큰은 metadata/properties 에 저장하고 verification_token 은 우리가 생성 (절대 외부 토큰 그대로 사용 X)
5. 새 provider 추가 후 EnforceIdentityPolicyTokenBypassTest 매트릭스 통과 확인 필수
```

## 1. 왜 이 규약이 필요한가

코어 인프라는 `IdentityVerificationLog` 단일 테이블을 SSoT 로 사용합니다:

- **EnforceIdentityPolicy 미들웨어**: 정책 라우트 진입 시 `verification_token` 으로 이 테이블을 조회해 재시도를 통과시킴
- **AssertIdentityVerifiedBeforeRegister listener**: 가입 직전 `target_hash` 매칭 + token consume
- **IdvTokenRule**: FormRequest 단계 토큰 강건 검증
- **IdentityGuardInterceptor (프론트엔드)**: 428 가로채기 → verify → token 부착하여 원 요청 재실행

provider 가 자기 저장소에만 토큰을 두고 `IdentityVerificationLog` 를 우회하면 위 4개 진입점이 모두 인식 못 하여 **무한 428 루프**가 발생합니다 (이슈 #297 참고).

## 2. 인터페이스 구현

```php
namespace Plugins\Vendor\IdentitySms;

use App\Contracts\Extension\VerificationProviderInterface;
use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;

final class SmsIdentityProvider implements VerificationProviderInterface
{
    public const ID = 'g7:plugin.sms';

    public function __construct(
        protected IdentityVerificationLogRepositoryInterface $logRepository,
    ) {}

    public function startChallenge(mixed $target, array $context = []): VerificationChallenge
    {
        // ... 외부 SDK 호출 또는 코드 발급 ...

        $log = $this->logRepository->create([
            'provider_id' => self::ID,
            'purpose' => $context['purpose'],
            'channel' => 'sms',
            'target_hash' => hash('sha256', mb_strtolower($phoneE164)),
            'status' => IdentityVerificationStatus::Sent->value,
            'metadata' => ['code_hash' => Hash::make($code), 'sdk_session_id' => $sdkSessionId],
            // ...
        ]);

        return new VerificationChallenge(/* ... */);
    }

    public function verify(string $challengeId, array $input, array $context = []): VerificationResult
    {
        $log = $this->logRepository->findById($challengeId);
        // ... 검증 로직 ...

        // ⚠️ 검증 통과 시 반드시 다음 4개 필드를 일관되게 갱신
        $verificationToken = $this->generateVerificationToken($log->purpose, $log->target_hash);
        $this->logRepository->updateById($log->id, [
            'status' => IdentityVerificationStatus::Verified->value,
            'verification_token' => $verificationToken,
            'verified_at' => now(),
            // consumed_at 은 절대 set 하지 않음 — 다운스트림 listener 가 1회 사용 시점에 set
        ]);

        return VerificationResult::success(/* token */ $verificationToken);
    }
}
```

## 3. 인프라 필드 규약 (CRITICAL)

`verify()` 성공 시 `IdentityVerificationLog` row 가 다음을 만족해야 합니다.

| 필드 | 값 | 미준수 시 발생 |
| --- | --- | --- |
| `status` | `IdentityVerificationStatus::Verified` | 미들웨어 통과 불가 → 무한 428 |
| `verification_token` | provider 가 생성한 서명 토큰 (외부 SDK 토큰 X) | IdentityGuardInterceptor 재시도 인식 불가 |
| `target_hash` | `SHA256(lower(식별자))` — challenge 시작 때 사용한 식별자 그대로 | 미들웨어 target 매칭 실패 → 428 / hijacking 차단 우회 위험 |
| `verified_at` | `now()` | grace_minutes 윈도우 미작동 |
| `purpose` | 정책의 purpose 와 동일 (signup/password_reset/sensitive_action 등) | 미들웨어 purpose 검사 실패 |
| `consumed_at` | `null` (verify 시점) — 1회 사용 시점에만 다운스트림이 set | 즉시 거부 |

**target 식별자 규약**: SMS 라면 E.164 정규화된 휴대폰번호, 이메일이라면 소문자, 외부 ID 라면 안정 식별자. 핵심은 challenge 시작과 verify 후 listener 가 같은 식별자를 SHA256 한다는 일관성입니다.

## 4. verification_token 생성 방법

외부 SDK 가 자체 토큰(거래번호 등)을 주더라도 **절대 그대로 client 에 전달하지 마세요**. 우리 인프라용 토큰을 별도로 생성합니다:

```php
protected function generateVerificationToken(string $purpose, string $targetHash): string
{
    return hash_hmac('sha256', $purpose.'|'.$targetHash.'|'.Str::random(32), config('app.key'));
}
```

외부 SDK 토큰은 `metadata` 또는 `properties` 컬럼에 저장하여 감사/refund 등에 활용:

```php
'metadata' => [
    'sdk_transaction_id' => $sdkResponse->txId,
    'sdk_provider_resp' => $sdkResponse->raw,
],
```

## 5. provider 등록

`IdentityProviderManager` 에 등록되어야 합니다 (모듈/플러그인 부트스트랩 시점):

```php
// modules/_bundled/vendor-identity-sms/src/Module.php
public function boot(): void
{
    parent::boot();

    app(\App\Services\IdentityProviderManager::class)
        ->register(new SmsIdentityProvider(app(IdentityVerificationLogRepositoryInterface::class)));
}
```

## 6. provider 추가 후 검증 매트릭스 (MANDATORY)

새 provider 추가 시 다음 테스트가 통과해야 합니다.

### 6.1 인프라 회귀 매트릭스

기존 코어 테스트가 provider 비종속성을 검증합니다 — 새 provider id 를 매트릭스에 추가:

```php
// tests/Feature/Identity/EnforceIdentityPolicyTokenBypassTest.php
public static function providerMatrix(): array
{
    return [
        'core.mail' => ['g7:core.mail'],
        'plugin.sms' => ['g7:plugin.sms'],   // ← 새 provider 추가
        // ...
    ];
}
```

`test_token_bypass_is_provider_agnostic` 가 자동으로 새 provider 도 검사합니다.

### 6.2 provider 자체 단위 테스트

각 provider 는 다음 케이스를 담은 자체 테스트를 작성:

- `startChallenge` 가 `IdentityVerificationLog` 에 올바른 target_hash + provider_id 로 row 생성
- `verify` 성공 시 status=Verified + verification_token != null + verified_at != null
- `verify` 실패 시 status 변화 없음 또는 attempts 증가 + token 미발급
- challenge expires_at 만료 처리
- max_attempts 초과 처리

## 7. 잘못된 패턴 (DO NOT)

| ❌ 금지 | ✅ 올바른 패턴 |
| --- | --- |
| 외부 SDK 토큰을 `verification_token` 컬럼에 그대로 저장 | provider 가 직접 생성한 서명 토큰 사용 |
| `IdentityVerificationLog` 미사용 + 자체 테이블에 토큰 저장 | 단일 SSoT 테이블 사용 (감사 로그/관리자 검색/통계 일원화) |
| `target_hash` 를 raw 식별자로 저장 | 반드시 SHA256(lower(식별자)) 로 PII 보호 |
| `verify()` 성공 시 `consumed_at = now()` 자동 set | 다운스트림 listener (가입/비번리셋 등) 가 사용 시점에 set |
| provider 가 listener 책임을 가져가서 직접 사용자 생성 | provider 는 verify 만 담당. 사용자 생성/세션은 도메인 service |
| `purpose` 를 정책 purpose 와 다르게 임의 부여 | 정책-provider purpose 일치 (`signup`, `password_reset`, `sensitive_action`, `self_update`) |

## 8. 관련 코드 진입점

- **VerificationProviderInterface**: `app/Contracts/Extension/VerificationProviderInterface.php`
- **MailIdentityProvider (reference 구현)**: `app/Extension/IdentityVerification/Providers/MailIdentityProvider.php`
- **IdentityVerificationLog**: `app/Models/IdentityVerificationLog.php`
- **IdentityVerificationLogRepository**: `app/Repositories/IdentityVerificationLogRepository.php`
- **EnforceIdentityPolicy 미들웨어**: `app/Http/Middleware/EnforceIdentityPolicy.php`
- **AssertIdentityVerifiedBeforeRegister listener**: `app/Listeners/Identity/AssertIdentityVerifiedBeforeRegister.php`
- **IdvTokenRule**: `app/Rules/IdvTokenRule.php`
- **회귀 매트릭스**: `tests/Feature/Identity/EnforceIdentityPolicyTokenBypassTest.php`

## 9. 관련 문서

- [identity-policies.md](identity-policies.md) — 정책 시스템 전체 흐름
- [identity-messages.md](identity-messages.md) — 메시지 템플릿 (provider 발송 콘텐츠)
- [../frontend/identity-verification-ui.md](../frontend/identity-verification-ui.md) — 프론트엔드 launcher/모달 UI
- [../extension/module-identity-settings.md](../extension/module-identity-settings.md) — 모듈/플러그인 IDV 정책 등록
