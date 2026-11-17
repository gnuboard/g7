# IDV Provider 작성 가이드 (Identity Verification Providers)

새 본인인증 provider (이메일/SMS/KCP/PortOne/소셜 인증 등) 를 추가할 때 인프라 규약을 지켜야 미들웨어/listener/launcher 흐름이 정상 동작합니다.

## TL;DR (5초 요약)

```text
1. IdentityVerificationInterface 구현 + 필터 훅 core.identity.registered_providers 로 등록 (회원마다 쓸 수 있는지 다르면 UserScopedIdentityProviderInterface 도)
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

use App\Contracts\Extension\IdentityVerificationInterface;
use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;

final class SmsIdentityProvider implements IdentityVerificationInterface
{
    public const ID = 'g7:plugin.sms';

    public function __construct(
        protected IdentityVerificationLogRepositoryInterface $logRepository,
    ) {}

    // getId()·getLabel()·getChannels()·getChannelLabels()·getRenderHint()·supportsPurpose()·isAvailable()·cancel()·
    // getSettingsSchema()·withConfig() 도 구현한다 — 계약 전문은 app/Contracts/Extension/IdentityVerificationInterface.php

    public function requestChallenge(User|array $target, array $context = []): VerificationChallenge
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

## 3.1 VerificationChallenge DTO 필드 규약

`requestChallenge()` 가 반환하는 `VerificationChallenge` DTO 는 프론트 launcher 가 모달 상태 초기화에 그대로 사용합니다. 모든 필드는 named arguments 로 호출하며, 향후 코어가 신규 필드를 추가해도 기본값으로 자동 처리되도록 named args 패턴을 유지합니다.

| 필드 | 타입 | 의미 |
| --- | --- | --- |
| `id` | string | challenge UUID |
| `providerId` | string | provider 식별자 |
| `purpose` | string | signup / password_reset / self_update / sensitive_action / login / 확장 정의 |
| `channel` | string | email / sms / ipin / 확장 채널 |
| `targetHash` | string | SHA256(식별자) |
| `expiresAt` | CarbonInterface | 만료 시각 |
| `renderHint` | string | text_code / link / external_redirect |
| `redirectUrl` | ?string | external_redirect 분기 시 외부 URL |
| `publicPayload` | array | 프론트 노출용 공개 페이로드 (민감정보 제외) |
| `metadata` | array | 서버 내부 참조용 |
| `maxAttempts` | int (기본 0) | 허용 최대 시도 횟수. 0 은 무제한(popup/SDK 형 provider 의 기본) |

`maxAttempts` 는 `toArray()` 출력의 `max_attempts` 키로 노출되어 `ChallengeResource` 응답에 그대로 전달됩니다. 프론트 launcher 는 이 값으로 모달의 "남은 시도 횟수" UI 를 초기화합니다.

**코어 mail provider 패턴**:

```php
return new VerificationChallenge(
    id: $log->id,
    providerId: self::ID,
    // ... 기존 필드
    maxAttempts: $maxAttempts,  // config('settings.identity.max_attempts', 5)
);
```

**popup/SDK 형 provider 패턴** (KG이니시스 등):

```php
return new VerificationChallenge(
    id: $challengeId,
    providerId: self::PROVIDER_ID,
    // ... 기존 필드
    // maxAttempts 미전달 시 DTO 기본값 0 (무제한)
);
```

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

코어 레지스트리(`AppExtensionIdentityVerificationIdentityVerificationManager`)는 `all()` 을 부를 때마다 필터 훅
`core.identity.registered_providers` 를 통과시킨다. 확장은 이 필터를 구독하는 리스너에서 `$providers[$id] = $provider` 로 더해 돌려준다
(계약 미구현 값은 버려진다). 설정을 매 요청 읽으므로 설정을 바꾸면 다음 요청부터 반영된다.

```php
// plugins/_bundled/vendor-identity_sms/src/Listeners/RegisterSmsProviderListener.php
public static function getSubscribedHooks(): array
{
    return [
        'core.identity.registered_providers' => ['method' => 'register', 'priority' => 20, 'type' => 'filter', 'sync' => true],
    ];
}

public function register(array $providers): array
{
    $provider = app()->makeWith(SmsIdentityProvider::class, ['config' => (array) app(PluginSettingsService::class)->get(self::IDENTIFIER)]);
    $providers[$provider->getId()] = $provider;

    return $providers;
}
```

번들 선례: `plugins/_bundled/sirsoft-verification_nhnkcp/src/Listeners/RegisterKcpProviderListener.php`.

### 5.1 회원마다 쓸 수 있는지 다른 수단 (7.2.0)

목적별 제공자를 고를 때(`IdentityVerificationManager::resolveForPurpose($purpose, $providerId, $user)`) 대상 회원을 알면, 고른 제공자가
`UserScopedIdentityProviderInterface` 이고 `canChallengeUser($user)` 가 거짓일 때 **같은 목적을 지원하고 그 회원에게 쓸 수 있는 다음
제공자**(등록 순서)로 넘어간다. 코어 메일 제공자는 받을 수 없는 대체 이메일(`.invalid`) 회원에게 거짓을 답한다 — 그래서 화면은
challenge 요청에 `provider_id` 를 고정하지 않는다. 판정은 조회만 해야 한다(로그를 남기거나 challenge 를 만들지 않는다).

넘어갈 다음 제공자는 `UserScopedIdentityProviderInterface` 를 구현하고 그 회원에게 참을 답하는 제공자뿐이다. 이 계약을 구현하지 않은
제공자(휴대폰 본인확인처럼 누구의 신원이든 확인해 주는 제공자)는 등록 순서가 앞서도 폴백으로 고르지 않는다 — 그 회원에게 맞는 수단인지
알 수 없고, 고르면 그 회원과 무관한 사람의 본인확인으로 재확인이 통과할 수 있기 때문이다. 그런 제공자는 운영자가 목적별 제공자·정책·기본
제공자로 지정했을 때만 쓰인다. 회원의 연결 계정이나 등록한 인증 수단으로 확인하는 제공자라면 이 계약을 구현해 폴백 후보가 된다.

외부 화면을 거치는 수단은 `getRenderHint()` 가 `external_redirect` 를 돌려주고 challenge 응답의 `redirect_url` 로 보낸다.

화면 안내가 「이 회원의 재확인이 어느 수단으로 가는가」를 실제 시작과 같은 판정으로 알아야 하면
`IdentityVerificationManager::resolveUsableForUser($purpose, $user)` 를 쓴다 — 위와 같은 순서로 고르되, 고른 수단이 그 회원에게 쓸 수
없으면 null 이다. 코어 마이페이지 응답의 `reauth_provider`(비밀번호가 없고 메일을 받을 수 없는 회원만)가 이 값이며, 확장도 자기 안내
조건(예: 「연결한 소셜 계정으로 다시 로그인」)을 이 판정과 맞춘다 — 다른 수단이 앞서는 회원에게 자기 안내를 보이면 실제 수단과 어긋난다.

외부 화면을 거치는 수단은 확인 뒤 요청 맥락의 `return_path`(같은 출처 경로만, 코어가 정규화)로 돌려보낸다. 정보 수정 화면과 템플릿의
본인인증 launcher(428 경로)는 지금 화면 경로를 싣는다.

### 5.2 로그를 참조하는 자기 테이블은 파기 훅에서 지운다 (7.2.0)

제공자가 challenge 를 자기 테이블(거래 매핑 등)에 이어 두면, 보관주기 파기(`IdentityLogService::purge()`)가 로그를 지울 때 그 행도
함께 사라져야 한다. 코어는 확장 테이블을 모르므로 배치마다 지우기 **직전에** action 훅 `core.identity_logs.before_purge` 를 그 배치의
로그 ID 배열(`array<int, string>`) 하나로 발화한다. 제공자는 이 훅을 구독해 자기 행을 명시적으로 지운다 — 외래키의
`cascadeOnDelete()` 는 안전망일 뿐 삭제 경로가 아니다([database-guide.md](../database-guide.md)).

```php
public static function getSubscribedHooks(): array
{
    return [
        'core.identity_logs.before_purge' => [
            'method' => 'purgeChallengeRows',
            'priority' => 10,
            'sync' => true, // 로그 행이 지워지기 전에 같은 요청 안에서 끝나야 한다
        ],
    ];
}

public function purgeChallengeRows(array $logIds): void
{
    $this->mappingRepository->deleteByChallengeIds($logIds);
}
```

`'sync' => true` 는 빠뜨리면 안 된다. Action 훅 리스너는 기본이 큐 디스패치라, 큐 드라이버가 `sync` 가 아닌 사이트에서는 리스너가
코어가 그 배치의 로그를 지운 **뒤에** 따로 돈다. 그 사이 외래키가 있으면 연쇄 삭제가 먼저 일어나 리스너가 할 일이 없어지고(정리 경로가
리스너가 아니게 된다), 외래키가 꺼진 환경이면 로그 없는 자기 행이 남는다. 동기로 구독해야 파기와 같은 요청·같은 배치 안에서 로그보다
먼저 지워진다. 번들 예: `sirsoft-verification_kginicis` 의 `CleanInicisChallengeMappingsOnLogPurge`,
`sirsoft-verification_nhnkcp` 의 `CleanKcpCertTransactionsOnLogPurge`.

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

- `requestChallenge` 가 `IdentityVerificationLog` 에 올바른 target_hash + provider_id 로 row 생성
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
| `purpose` 를 정책 purpose 와 다르게 임의 부여 | 정책-provider purpose 일치 (`signup`, `password_reset`, `sensitive_action`, `self_update`, `login`) |
| 콜백·브리지·제공자 전송 주소를 `url()`·`route()`·요청 Host 로 생성 | `SiteUrl::absolute()` — 사이트 주소 기준 |

### 콜백·브리지·제공자 전송 주소는 `SiteUrl`

제공자에게 넘기는 복귀·콜백 주소, 결과를 창 밖으로 전달하는 브리지 화면의 대상 출처(`postMessage` targetOrigin), 메일 링크는 모두 `App\Support\SiteUrl` 로 사이트 주소 기준으로 만든다. `url()`·`route()` 는 요청 Host 를 따르므로 Host 를 바꿔 보낸 요청에서 시작한 인증은 결과와 `verification_token` 을 그 호스트로 넘기게 된다. 프런트에 내려주는 출처(`siteOrigin`)도 서버가 `SiteUrl::origin()` 으로 정해 주고, 클라이언트는 받은 메시지의 `origin` 을 그 값과 비교한다. 상세: [routing.md 「절대 URL 은 사이트 주소로 만든다」](routing.md#절대-url-은-사이트-주소로-만든다).

## 8. 관련 코드 진입점

- **IdentityVerificationInterface**: `app/Contracts/Extension/IdentityVerificationInterface.php`
- **UserScopedIdentityProviderInterface**: `app/Contracts/Extension/UserScopedIdentityProviderInterface.php`
- **IdentityVerificationManager (레지스트리)**: `app/Extension/IdentityVerification/IdentityVerificationManager.php`
- **MailIdentityProvider (reference 구현)**: `app/Extension/IdentityVerification/Providers/MailIdentityProvider.php`
- **IdentityVerificationLog**: `app/Models/IdentityVerificationLog.php`
- **IdentityVerificationLogRepository**: `app/Repositories/IdentityVerificationLogRepository.php`
- **EnforceIdentityPolicy 미들웨어**: `app/Http/Middleware/EnforceIdentityPolicy.php`
- **AssertIdentityVerifiedBeforeRegister listener**: `app/Listeners/Identity/AssertIdentityVerifiedBeforeRegister.php`
- **IdvTokenRule**: `app/Rules/IdvTokenRule.php`
- **회귀 매트릭스**: `tests/Feature/Identity/EnforceIdentityPolicyTokenBypassTest.php`

## 9. 관련 문서

- [identity-policies.md](identity-policies.md) — 정책 시스템 전체 흐름
- [routing.md 「절대 URL 은 사이트 주소로 만든다」](routing.md#절대-url-은-사이트-주소로-만든다) — 콜백·링크 주소 생성 규칙
- [개발자 화면 가이드 9-2](../custom-development.md#9-2-본인인증) — 엔진 없는 화면·외부 화면의 본인인증 순서, 범용 콜백 `return` 출처(사이트 주소 또는 「사용자 화면 URL」)
- [identity-messages.md](identity-messages.md) — 본인인증 알림 (메일 provider 발송 문구 — 알림 정의 category=identity)
- [../frontend/identity-verification-ui.md](../frontend/identity-verification-ui.md) — 프론트엔드 launcher/모달 UI
- [../extension/module-identity-settings.md](../extension/module-identity-settings.md) — 모듈/플러그인 IDV 정책 등록
