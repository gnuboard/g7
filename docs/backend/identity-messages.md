# 본인인증 알림 (Identity Notifications)

> 본인인증 메일(인증번호·인증 링크)의 문구 정의 · 고르기 · 발송 — 7.1.0 부터 알림 시스템의 한 분류(`category = 'identity'`)

## TL;DR (5초 요약)

```text
1. 본인인증 메일 문구 = 알림 정의(notification_definitions) 중 category='identity' 행 + 메일 템플릿(notification_templates)
2. type: identity.default · identity.purpose.{purpose} · identity.policy.{policy_key}(100자 넘으면 identity.policy.~{sha1 20자})
3. 발송 시 정책 → 목적 → 기본 순으로 「활성 + 메일 템플릿 있음」인 첫 정의를 쓴다
4. 메일 채널 토글·readiness·발송 로그를 일반 알림과 공유한다 — 메일을 끄면 본인인증 메일도 멈춘다
5. 발송 로그에서 code·action_url 값은 ****** 로 가린다
6. 확장은 getNotificationDefinitions() 에 category='identity' 로 선언한다 (getIdentityMessages() 는 @deprecated)
```

규칙·폴백·마스킹·복구법의 정본은 [notification-system.md](notification-system.md) 「본인인증 알림」 절입니다. 이 문서는 확장·코어 개발자가
본인인증 문구를 다룰 때 필요한 것만 모았습니다.

---

## 데이터

| 위치 | 내용 |
|---|---|
| `notification_definitions` | `type`(아래 규칙) · `category = 'identity'` · `hooks = []` · `channels = ['mail']` · `variables` |
| `notification_templates` | `channel = 'mail'` 의 다국어 `subject`·`body` (HTML) · `user_overrides`(운영자 수정 필드) |
| `notification_logs` | 발송 결과 — 본인인증 분류는 `code`·`action_url` 값이 가려진 채 저장 |

`identity_message_definitions`·`identity_message_templates` 테이블과 `config('core.identity_messages')` 키는 7.1.0 에서 없어졌습니다.

## type 규칙

| type | 뜻 | 소유 |
|---|---|---|
| `identity.default` | 공통 기본 문구 | 코어 |
| `identity.purpose.signup` · `password_reset` · `self_update` · `sensitive_action` · `login` | 코어 인증 목적별 문구 (`login` = 관리자 2단계 인증) | 코어 |
| `identity.purpose.{확장 목적}` | 확장이 선언한 목적의 문구 (예: `identity.purpose.checkout_verification`) | 확장 |
| `identity.policy.{policy_key}` | 운영자 정책(`identity_policies.source_type = admin`)에 붙인 전용 문구 | 코어 (`extension_identifier = 'admin'`) |

정책 후보는 challenge 를 연 정책(`origin_policy_key`)이 있을 때만 생깁니다. 서버가 직접 여는 challenge(가입 직후 인증)는 정책을
스스로 싣고, 라우트·훅 정책의 428 뒤 화면이 여는 challenge 는 응답의 `verification.policy_key` 를 `POST /api/identity/challenges` 의
`policy_key` 로 실어 보냅니다 — 서버는 그 정책이 있고 켜져 있으며 목적이 같을 때만 받아들입니다. 직접 화면 처리기를 만드는 템플릿은
이 값을 빠뜨리면 정책별 문구가 쓰이지 않습니다.

type 은 `App\Notifications\Identity\IdentityNotificationType` 으로 만듭니다 — `forPurpose()`·`forPolicy()`·`candidates()`. `forPolicy()` 는
결과가 100자(컬럼 길이)를 넘으면 `identity.policy.~{sha1(policy_key) 앞 20자}` 로 줄이므로, 문자열을 직접 이어 붙이면 긴 정책 키에서 조회가 빗나갑니다.

## 발송 변수

모든 본인인증 정의가 같은 변수를 노출합니다(`IdentityNotificationType::commonVariables()`).

| 변수 | 설명 |
|------|------|
| `{code}` | 인증 코드 (코드 입력 흐름) — 로그에서 가림 |
| `{action_url}` | 검증 링크 URL (링크 흐름, 예: 비밀번호 재설정) — 로그에서 가림 |
| `{expire_minutes}` | 만료까지 남은 분 |
| `{purpose_label}` | 인증 목적 라벨 (다국어) |
| `{app_name}` | 사이트명 |
| `{site_url}` | 사이트 URL |
| `{recipient_email}` | 받는 사람 이메일 |

평문 코드는 메일 본문 밖에 저장되지 않습니다 — 인증 이력(`identity_verification_logs.metadata`)은 `code_hash` 만, 발송 로그는 가린 본문만 남깁니다.

## 발송

`MailIdentityProvider` 가 `IdentityMessageDispatcher::dispatch($providerId, $purpose, $policyKey, $renderHint, $channel, $target, $data, $context)` 를
부르고, 디스패처는 정의를 고른 뒤 `GenericNotification` 을 `NotificationDispatcher` 로 보냅니다. 결과는 `DeliveryReport` 로 판정해 실제로 메일이
나갔을 때만 `true` 를 돌려줍니다. 정의를 고른 뒤 보내기 직전에 훅 `core.identity.message.before_send` 가 발화합니다.

| 상황 | 결과 |
|---|---|
| 정책·목적·기본 후보가 모두 정의가 없거나 꺼짐, 또는 그 채널 템플릿이 없거나 꺼짐 | `false` + 훅 `core.identity.message.resolve_failed` |
| 메일 채널 꺼짐 · SMTP 미설정 등으로 건너뜀 | `false` + 훅 `core.identity.message.send_failed`(`delivery_status` = `skipped`, `error_message` = 건너뛴 사유) + 발송 로그 skipped |
| 전송 중 예외 | `false` + 훅 `core.identity.message.send_failed`(`delivery_status` = `failed`) |
| 성공 | `true` + 훅 `core.identity.message.after_send` |

훅 인자는 7.0.x 와 모양이 다릅니다 — `template_id`·`rendered`(렌더된 제목·본문)는 더 넘어가지 않고(렌더링은 발송 단계에서 알림이 합니다),
`definition_id` 는 알림 정의(`notification_definitions`) ID 이며 `notification_type`(예: `identity.purpose.signup`)이 추가됐습니다.
렌더된 내용이 필요하면 `core.notification.before_channel_send` 를 구독합니다.

받는 사람은 컨텍스트 `user_id` 회원의 이메일이 대상 주소와 같으면 그 회원(`User`), 아니면 대상 주소의 `GuestNotifiable` 입니다.
`core.identity.message.*` 훅 4종은 호환용으로 계속 발화하지만 @deprecated 이며, 새 코드는 알림 공통 훅을 씁니다.

## 코어 선언

`config/core.php` 의 `notification_definitions` 배열에 일반 알림과 나란히 6건(`identity.default` + 목적 5건)이 있고,
`NotificationDefinitionSeeder` 가 함께 시드합니다. `Database\Seeders\IdentityMessageDefinitionSeeder` 는 아무것도 하지 않는 호환 심입니다.

## 확장의 선언

```php
use App\Notifications\Identity\IdentityNotificationType;

public function getNotificationDefinitions(): array
{
    return [
        [
            'type' => IdentityNotificationType::forPurpose('checkout_verification'),
            'category' => IdentityNotificationType::CATEGORY,          // 'identity'
            'hook_prefix' => 'vendor-module',
            'name' => ['ko' => '주문 시 본인확인', 'en' => 'Checkout Verification'],
            'channels' => ['mail'],
            'hooks' => [],                                            // 훅으로 보내지 않는다
            'variables' => IdentityNotificationType::commonVariables(),
            'templates' => [
                ['channel' => 'mail', 'subject' => ['ko' => '...', 'en' => '...'], 'body' => ['ko' => '...', 'en' => '...']],
            ],
        ],
    ];
}
```

목적 키 자체(`checkout_verification`)는 [identity-policies.md](identity-policies.md) 의 목적 선언(`getIdentityPurposes()`)에 따로 등록합니다.

`getIdentityMessages()` 는 @deprecated 입니다. 아직 구현한 확장은 설치·업데이트 때 코어가 알림 정의 선언으로 바꿔 병합합니다
(메일이 아닌 provider 선언은 경고 로그 후 메일 템플릿으로 흡수, 알 수 없는 범위·다른 소유자의 type 과 겹치는 선언은 경고 로그 후 건너뜀). 바꾸는 규칙은 옛 scope → type 입니다:
`provider_default` → `identity.default`, `purpose:{p}` → `identity.purpose.{p}`, `policy:{key}` → `forPolicy(key)`.

## 운영자 화면

「환경설정 > 알림 설정」 분류 칩 「본인인증」에서 편집·활성 토글·기본값 복원을 하고, 운영자 정책에 정책별 문구를 추가·삭제합니다.
확장이 선언한 문구는 그 확장의 설정 > 알림 화면에 있습니다. 「환경설정 > 본인인증 > 메시지」에는 이 자리로 가는 안내만 남았습니다.

## 언어팩

본인인증 문구의 번역은 일반 알림과 같은 언어팩 `seed/notifications.json` 에 type 을 키로 넣습니다(`identity.default`, `identity.purpose.signup` …).
`seed/identity_messages.json` 은 7.1.0 에서 폐지되었습니다 — 규칙은 [언어팩](../extension/language-packs.md) 의 알림 seed 절과 같습니다.

---

## 참고

- [notification-system.md](notification-system.md) — 알림 시스템 (「본인인증 알림」 절이 정본)
- [identity-policies.md](identity-policies.md) — 본인인증 정책 · 목적
- [identity-providers.md](identity-providers.md) — 본인인증 provider (메일 provider 가 이 문구를 보낸다)
- [docs/extension/module-identity-settings.md](../extension/module-identity-settings.md) — 모듈/플러그인 본인인증 설정
