# 인증 앱 2단계 인증 (TOTP) — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 1종 / 호출 지점 1곳. 이 중 1종은 `getHooks()` 선언에 없어 소스에서 자동 감지한 것입니다 — 선언에 추가하면 유형과 설명이 함께 실립니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-totp.credential.admin_reset` | action | — | `src/Services/TotpEnrollmentService.php:207` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
이 플러그인이 발행하는 훅은 하나뿐이며, 관리자 강제 해제에만 붙어 있습니다.

`sirsoft-totp.credential.admin_reset` 은 `TotpAdminCredentialController::destroy()` 가
`TotpEnrollmentService::reset()` 을 호출해 **실제로 자격증명이 지워진 경우에만** 발행합니다
(`$removed` 가 거짓이면 발행하지 않습니다 — 지울 것이 없었던 호출까지 해제 사건으로 기록되면
감사 기록에 존재하지 않는 사건이 쌓입니다). 인자는 `(User $user, array $context)` 이고
`$context` 에는 `actor_id`(해제를 실행한 관리자 id)와 `ip_address` 가 실립니다.

이 자리에 훅을 둔 이유는 강제 해제가 **코드 확인 없이 남의 2단계 인증을 푸는 유일한 경로**
이기 때문입니다. 누가 누구의 2단계 인증을 언제 풀었는지 추적할 수 없으면 이 경로 자체가
권한 상승 수단이 됩니다. 추가 감사 로그를 남기거나 당사자에게 메일·알림을 보내는 확장은
이 훅을 잡습니다.

`actor_id` 는 관리자 API 경로에서만 채워집니다 — `php artisan totp:reset` 은 컨트롤러를
거치지 않으므로 이 훅을 발행하지 않습니다. 셸 접근 자체가 권한인 최후 구제 경로이며,
그 사용 흔적은 서버 접근 기록에 남습니다.

본인 등록·확정·해제·백업 코드 재발급에는 훅을 두지 않았습니다. 본인이 자기 인증 수단을
다루는 행위는 코드 확인을 통과해야만 성립하므로, 강제 해제와 같은 수준의 감사 대상이
아닙니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.identity.purposes` | filter | `ExtendLoginPurposeChannels` | `extend` | 20 |
| `core.identity.registered_providers` | filter | `RegisterTotpProviderListener` | `register` | 20 |
| `core.user.after_withdraw` | action (미선언) | `CleanTotpCredentialOnUserWithdraw` | `handle` | 50 |
| `core.user.before_delete` | action (미선언) | `CleanTotpCredentialOnUserDelete` | `handle` | 50 |
| `core.user.filter_resource_data` | filter | `UserTotpEnrollmentInfoListener` | `injectEnrollmentStatus` | 40 |
| `sirsoft-totp.credential.admin_reset` | action (미선언) | `TotpAdminResetActivityLogListener` | `handle` | 50 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
구독 훅 4건은 전부 **코어가 소유한 훅**이라 이 플러그인의 계약이 아닙니다. 다만 어느 훅을
골랐는지는 설계 결정이므로 그 이유가 여기에 있습니다.

`core.identity.registered_providers` (filter, priority 20) — `RegisterTotpProviderListener`
가 `TotpIdentityProvider` 인스턴스를 레지스트리 배열에 `totp` 키로 더합니다. 코어는 이
플러그인의 존재를 모르고, 플러그인이 비활성이면 이 필터가 걸리지 않아 수단도 함께
사라집니다. 인스턴스는 `app()->makeWith()` 로 만들며 설정은
`PluginSettingsService::get('sirsoft-totp')` 로 **매 요청 직접 조회**합니다 — 코어
`PluginSettingsService::save()` 가 인메모리 캐시만 비우므로 `config()` 로 읽으면 변경된
설정이 다음 요청에 반영되지 않습니다 (nhnkcp 선례와 같은 이유).

`core.identity.purposes` (filter, priority 20) — `ExtendLoginPurposeChannels` 가 login
목적의 `allowed_channels` 에 `app` 을 더합니다. 코어 `IdentityVerificationManager` 의
`$corePurposes` 는 login 을 `['email']` 로 선언하는데, 코어가 소유한 값이므로 직접 고치지
않고 필터로 덧붙입니다. 이 채널이 없으면 관리자 「설정 > 본인인증」의 login 목적 행이 인증
앱 수단을 고를 수 있는 것으로 렌더하지 않습니다. login 목적이 아직 레지스트리에 없으면
(순서 의존) 아무것도 하지 않습니다 — 없는 목적을 플러그인이 만들어 내면 코어 레지스트리의
소유권이 흐려집니다.

`core.user.after_withdraw` / `core.user.before_delete` (action, priority 50) — 자격증명
파기 두 건이 **서로 다른 훅**을 잡는 것이 중요합니다. 삭제는 `before_delete` 여야 합니다.
사용자 행이 사라진 뒤에는 외래키가 걸린 자격증명을 지울 수 없어 삭제 자체가 실패합니다.
탈퇴는 반대로 `after_withdraw` 여야 합니다. 탈퇴 트랜잭션이 끝난 뒤 호출되므로 탈퇴가
실패한 경우에 자격증명만 지워지는 일이 없습니다.

우선순위 20 은 코어 기본값을 받아 **덧붙이는** 위치입니다. 다른 확장이 더 이른 우선순위로
같은 필터에서 `totp` 키를 덮어쓰거나 login 목적을 제거하면 이 플러그인은 조용히
무력화됩니다 — 인증 수단이 사라진 것은 오류로 드러나지 않으므로, 두 필터를 함께 구독하는
확장을 붙일 때는 이 점을 확인하세요.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `CleanTotpCredentialOnUserDelete` | 1개 | 명시 등록 | ✅ | `src/Listeners/CleanTotpCredentialOnUserDelete.php` |
| `CleanTotpCredentialOnUserWithdraw` | 1개 | 명시 등록 | ✅ | `src/Listeners/CleanTotpCredentialOnUserWithdraw.php` |
| `ExtendLoginPurposeChannels` | 1개 | 명시 등록 | ✅ | `src/Listeners/ExtendLoginPurposeChannels.php` |
| `RegisterTotpProviderListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RegisterTotpProviderListener.php` |
| `TotpAdminResetActivityLogListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/TotpAdminResetActivityLogListener.php` |
| `UserTotpEnrollmentInfoListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/UserTotpEnrollmentInfoListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
리스너 6개는 전부 `plugin.php` 의 `getHookListeners()` 에 명시 등록되어 있습니다. 자동
스캔에 맡기지 않으므로 목록 자체가 "이 플러그인이 코어의 어디에 손을 대는가" 의 SSoT 입니다.

두 부류로 갈립니다.

**필터 리스너** (`RegisterTotpProviderListener` · `ExtendLoginPurposeChannels`) — 둘 다
`getSubscribedHooks()` 에서 `method` 를 `register` · `extend` 로 명시하므로
`HookListenerInterface::handle()` 은 no-op 입니다. 인터페이스가 요구해서 남아 있는
자리이며, 여기에 로직을 넣으면 실제 진입점이 두 곳이 되어 어느 쪽이 실행되는지 읽어서는
알 수 없게 됩니다. 두 리스너는 상태를 갖지 않고 생성자 주입도 없습니다 —
설정과 provider 인스턴스를 호출 시점에 해석하기 때문입니다.

**정리 리스너** (`CleanTotpCredentialOnUserWithdraw` · `CleanTotpCredentialOnUserDelete`)
— 생성자로 `TotpCredentialRepositoryInterface` 를 주입받고 `handle()` 이 진입점입니다.
두 리스너의 본문은 같지만 한 클래스로 합치지 않았습니다. 구독 훅이 다르고(`after_withdraw`
대 `before_delete`) 그 차이가 곧 타이밍 계약이라, 한 클래스가 두 훅을 겸하면 어느 훅의
타이밍 제약 때문에 이 코드가 이렇게 생겼는지 읽히지 않습니다.

두 정리 리스너 모두 `$args[0]` 이 `User` 인스턴스인지, id 가 0보다 큰지 확인한 뒤에만
삭제합니다. 훅 인자가 기대와 다르게 올 때 조용히 지나가는 편이, 정리 리스너가 던진 예외로
탈퇴·삭제 트랜잭션 전체를 깨뜨리는 것보다 낫습니다.

`totp_credentials.user_id` 에 CASCADE 를 걸지 않고 이 두 리스너가 명시 삭제하는 것은
database-guide 규정입니다. 자격증명 파기 경로를 새로 만든다면 CASCADE 를 추가하는 대신
리스너를 늘리세요.

**응답 병합 리스너** (`UserTotpEnrollmentInfoListener`) — `core.user.filter_resource_data` 필터로 관리자 회원 상세
응답에 `totp_enrollment {enrolled, enrolled_at}` 를 싣습니다. 회원 상세 「인증 앱」 카드가 이 값으로 등록됨/미등록을
보이고 등록된 회원에게만 [강제 해제] 를 냅니다. **관리자 컨트롤러 요청이면서 요청자가 해제 권한
(`sirsoft-totp.credentials.delete`)을 가진 경우에만** 싣습니다 — 해제 라우트와 같은 경계입니다. 「등록됨」은 확인까지
마친 등록(`confirmed_at`)만이며, 조회가 실패하면 값을 싣지 않습니다(카드는 「권한 없음」 안내로 떨어지고 단추를 내지 않음).
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/admin_user_totp_card.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_user_detail` |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
이 플러그인은 다른 확장의 레이아웃에 조각을 주입하지 않습니다.

관리자 인증 앱 등록 화면은 코어 「플러그인 관리 → 설정」 자동 경로
(`/admin/plugins/sirsoft-totp/settings`)로 진입합니다 — 이 화면은 플러그인 환경설정이지
별도 메뉴로 세울 사항이 아니라는 설계 확정(2026-09-18)에 따라 전용 메뉴를 두지 않습니다.
레이아웃 파일명이 `resources/layouts/admin/plugin_settings.g7.html` 이면 코어
`AbstractPlugin::getSettingsLayout()` 이 그대로 해석하므로 `routes.json` 도 필요 없습니다
(형제 13종이 모두 이 규약을 씁니다). 관리자 템플릿의 기존 레이아웃을 고치지 않으므로,
플러그인을 끄면 화면도 함께 사라집니다.

로그인 2단계 인증 화면은 코어 소유이고 이 플러그인이 주입할 대상이 아닙니다. 대신
`requestChallenge()` 가 challenge 의 `publicPayload` 에 `delivery: 'none'` 과
`code_length: 6` 을 실어, 코어 화면이 「인증번호 다시 받기」를 숨기고 안내 문구를 바꿀
근거를 데이터로 넘깁니다 — 화면 구조가 아니라 데이터로 소통합니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 미들웨어가 없습니다._
<!-- @generated:middleware END -->

<!-- @intent START -->
미들웨어를 등록하지 않습니다. 이 플러그인이 쓰는 미들웨어는 전부 코어 것이며
`src/routes/api.php` 에서 라우트에 직접 걸립니다.

- `auth:sanctum` — 본인 등록 5개, 관리자 1개 전부에 걸립니다.
- `permission:admin,sirsoft-totp.credentials.delete` — 관리자 강제 해제 1개에만 추가로
  걸립니다.

권한 검사를 컨트롤러가 아니라 라우트에서 강제하는 것은, 컨트롤러 메서드가 늘어날 때
검사가 빠진 메서드가 생기지 않게 하기 위해서입니다. 본인 등록 경로에는 권한 미들웨어가
없습니다 — 대상이 항상 호출자 본인이라 인증만으로 충분하고, 경로에 사용자 식별자를 두지
않아 남의 자격증명을 가리킬 표면 자체가 없습니다.

2단계 인증을 강제하는 미들웨어도 두지 않습니다. 그 판정은 코어 `AuthService` 가 로그인
시점에 수행합니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
브로드캐스트 채널을 등록하지 않습니다.

등록·확정·해제는 전부 요청-응답 한 번으로 끝나고, 결과를 기다리며 실시간으로 밀어 줄
상태가 없습니다. 인증 앱이 코드를 만드는 동안 서버는 아무 일도 하지 않으므로 진행 상황을
전할 대상도 없습니다 — 외부 인증기관의 콜백을 기다리는 수단과 다른 점입니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 스케줄이 없습니다._
<!-- @generated:schedules END -->

<!-- @intent START -->
스케줄을 등록하지 않습니다.

주기적으로 정리할 자원이 없습니다. challenge 로그는 코어가 소유하고 만료는
`expires_at` 과 `verify()` 의 만료 가드로 처리되므로 배치가 필요하지 않습니다. 자격증명은
스스로 만료되지 않고(등록은 해제하기 전까지 유효합니다) 파기는 탈퇴·삭제·해제 시점에
이벤트로 일어납니다.

미확정 자격증명(`confirmed_at IS NULL`)도 청소하지 않습니다. 남아 있어도 로그인에 쓰이지
않고(`canChallengeUser()` 가 확정본만 봅니다), 같은 사용자가 등록을 다시 시작하면
`TotpCredentialRepository::issue()` 의 `updateOrCreate` 가 덮어씁니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 알림 정의가 없습니다._
<!-- @generated:notifications END -->

<!-- @intent START -->
알림 정의를 등록하지 않습니다 — 본인인증 알림(`category = 'identity'`)도 없고, `plugin.php` 의
@deprecated `getIdentityMessages()` 오버라이드도 빈 배열입니다.

서버가 아무것도 보내지 않는 수단이기 때문입니다. 코드는 사용자 단말의 앱이 만들고, 서버는
challenge 를 열어 두고 제출된 코드를 대조할 뿐이라 발송할 메시지가 없습니다.

등록·해제 같은 계정 보안 사건에 대한 통지도 이 플러그인이 직접 보내지 않습니다. 관리자
강제 해제는 `sirsoft-totp.credential.admin_reset` 훅으로 열려 있으므로, 당사자 통지가
필요한 사이트는 그 훅을 잡는 확장에서 자기 통지 체계로 보냅니다 — 통지 수단·문구·수신
정책은 사이트마다 다르고, 인증 수단 플러그인이 그것까지 정하면 끄기 어려운 결합이
생깁니다.
<!-- @intent END -->

## 활동 로그 훅

> 이 플러그인이 코어 활동 로그(`activity_logs`)에 기록을 남기기 위해 구독하는 훅 1개입니다.
> 기록은 `ResolvesActivityLogType::logActivity()` 로 남기며, 로그 유형은 요청 문맥에서 판정합니다
> (관리자 화면의 강제 해제이므로 Admin). 설명 문구는 `sirsoft-totp::activity_log.description.credential_admin_reset`
> 이고 이 플러그인의 `lang/{ko,en}/activity_log.php` 와 번들 일본어 팩이 함께 정의합니다.

### 인증 앱 훅 (TotpAdminResetActivityLogListener)

**파일**: `plugins/_bundled/sirsoft-totp/src/Listeners/TotpAdminResetActivityLogListener.php`
**총 1훅**

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-totp.credential.admin_reset` | `handle` | `credential.admin_reset` | Admin | User |
