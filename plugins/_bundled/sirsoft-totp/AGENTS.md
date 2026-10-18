# 그누보드7 인증 앱 2단계 인증 (TOTP) 플러그인 — 에이전트 가이드

> 이 문서는 이 플러그인을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 플러그인 (sirsoft-totp) — RFC 6238 TOTP 코드를 로그인 2단계 인증 수단으로 더하는 IDV Provider. 사용자별 자격증명(`totp_credentials`) 소유, 로그인 흐름 자체는 코어 소유
2. 확장 방식: `RegisterTotpProviderListener` 가 코어 `core.identity.registered_providers` 필터에 provider 를 얹고, `ExtendLoginPurposeChannels` 가 `core.identity.purposes` 필터로 login 목적에 `app` 채널을 더한다 — 코어는 이 플러그인의 존재를 모른다. 관리자 강제 해제는 `sirsoft-totp.credential.admin_reset` action 훅으로 잡는다
3. 건드리면 안 되는 것: `requestChallenge()` 의 `Sent` 즉시 기록, `Totp::DIGITS`/`PERIOD`/`ALGORITHM` 고정값(6/30/sha1), `last_used_timestep` 리플레이 가드, `totp_credentials.user_id` 의 CASCADE 미설정
4. 작업 위치: `plugins/_bundled/sirsoft-totp` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan plugin:update sirsoft-totp --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
인증 앱(Google Authenticator·Authy·1Password 등)이 만드는 6자리 코드를 코어 본인인증(IDV)
체계에 Provider 로 등록해, 로그인 2단계 인증 수단으로 더하는 플러그인입니다.

**해결하는 문제**: 코어가 소유한 2단계 인증 수단은 메일(`mail` provider) 하나뿐입니다.
메일 수단은 메일 서버가 죽으면 같이 죽고, 코드가 메일함을 거치므로 메일 계정이 뚫리면
2단계가 무력화됩니다. TOTP 는 **서버가 아무것도 보내지 않고** 사용자 단말이 코드를
스스로 만들므로 두 취약점이 모두 없습니다.

**설계 원칙**:

- **로그인 흐름은 코어 소유다.** 이 플러그인은 `IdentityVerificationInterface` 를 구현한
  수단 하나를 레지스트리에 얹을 뿐이고, 코드 확인 엔드포인트
  (`POST /api/auth/login/two-factor`, `POST /api/admin/login/two-factor`)를 따로 열지
  않습니다. 로그인 경로를 두 벌 만들면 잠금·시도 횟수 같은 코어 통제가 한쪽에만 걸립니다.
- **발송 단계가 없다는 사실을 상태로 표현한다.** `TotpIdentityProvider::requestChallenge()`
  는 로그를 `IdentityVerificationStatus::Sent` 로 **즉시** 기록합니다. 코어
  `AuthService::startTwoFactorChallenge()` 는 challenge 발행 직후 상태가 `Failed` 면
  발송 실패로 읽고 `TwoFactorDeliveryFailedException` 으로 로그인을 차단하는데, TOTP 는
  확인할 "전달" 자체가 없습니다. 여기서 `Sent` 는 "보냈다"가 아니라 "사용자가 코드를 얻을
  수 있는 상태가 됐다"를 뜻합니다.
- **사용자별 등록 여부를 코어에 알려 준다.** 코어의 기존 판정 규칙은
  `filled($user->email)` 이므로 "설정은 켜졌지만 이 사용자는 아직 앱을 등록하지 않았다"를
  구분하지 못합니다. 그대로 두면 미등록 사용자에게 완료할 수 없는 challenge 가 발행되어
  계정이 잠깁니다. 그래서 코어 보조 계약 두 개를 구현합니다 —
  `App\Contracts\Extension\UserScopedIdentityProviderInterface::canChallengeUser()` 는
  확정 자격증명 보유 여부를, `App\Contracts\Extension\MailFallbackAwareProviderInterface::allowsMailFallback()`
  은 미등록자를 메일로 되돌려 보내도 되는지를 답합니다. 폴백 허용 여부를 코어가 아니라
  이 플러그인이 답하는 이유는, 미등록자를 어떻게 다룰지 아는 주체이자 그 설정
  (`allow_mail_fallback`)을 소유한 주체가 이쪽이기 때문입니다.
- **파라미터를 열지 않는다.** `Support\Totp` 의 `DIGITS`(6) · `PERIOD`(30) ·
  `ALGORITHM`(sha1) 은 상수이며 설정 스키마에 없습니다. 주요 인증 앱이 이 조합만 QR 로
  정확히 읽으므로, 열어 두면 운영자가 바꾼 뒤 "앱이 만든 코드가 안 맞는다" 로만 드러나는
  고장이 됩니다.
- **평문은 한 번만 보여 준다.** 시크릿은 `encrypted` cast, 백업 코드는 bcrypt 해시 배열로
  저장되고 `TotpCredential::$hidden` 이 둘 다 직렬화에서 제외합니다. 평문이 응답에 실리는
  자리는 발급(`POST enrollment`)·확정(`POST enrollment/confirm`)·재발급
  (`POST backup-codes/regenerate`) 세 곳뿐이며, 조회로는 다시 내려가지 않습니다.

**의도적으로 하지 않는 것**:

- **login 이외의 purpose 를 지원하지 않습니다.** `supportsPurpose()` 는
  `IdentityVerificationPurpose::Login` 만 참입니다. 가입·비밀번호 재설정은 아직 자격증명이
  없는 시점이라 인증 앱으로 확인할 수 없고, 열어 두면 운영자가 그 목적에 매핑해 흐름을
  막을 수 있습니다.
- **새 purpose·본인인증 알림을 만들지 않습니다.** `getIdentityPurposes()` 는 빈 배열이고
  `getNotificationDefinitions()` 에 본인인증 알림(`category = 'identity'`)도 선언하지 않습니다
  (@deprecated `getIdentityMessages()` 오버라이드도 빈 배열) — 코어 login 목적에 수단을 더할
  뿐이고, 서버가 아무것도 보내지 않으므로 보낼 메시지도 없습니다.
- **QR 이미지를 서버에서 굽지 않습니다.** 서버는 `Totp::otpauthUri()` 가 만든 otpauth URI
  문자열만 내려주고, QR 렌더는 화면 몫입니다.
- **자격증명 테이블을 CASCADE 로 묶지 않습니다.** 탈퇴·삭제 시 정리는
  `CleanTotpCredentialOnUserWithdraw` / `CleanTotpCredentialOnUserDelete` 가 명시
  삭제합니다 (database-guide 규정).
- **`uninstall()` 이 테이블을 드랍하지 않습니다.** 플러그인을 잠시 껐다 켜는 운영에서
  회원 전원의 등록이 사라지면 재등록을 강요하게 됩니다.
<!-- @intent END -->

## 2. 디렉토리 지도

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `plugin.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `plugin.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Http/Controllers/` | 컨트롤러 | API 표면 변경 시 `api:docgen` 재실행 |
| `src/Http/Requests/` | FormRequest (검증 SSoT) | 검증 규칙은 Service 가 아니라 여기에 둔다 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Repositories/` | 데이터 접근 | 목록 쿼리는 컬럼 프루닝·정렬 화이트리스트 확인 |
| `src/Models/` | Eloquent 모델 | 스키마 변경 시 마이그레이션 + 업그레이드 스텝 동반 |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `database/migrations/` | 마이그레이션 | 한국어 comment + `down()` 필수, 기설치본은 업그레이드 스텝으로 백필 |
| `resources/layouts/` | 레이아웃 소스 `.g7.html` (짝 `.json` 은 굽기 산출. 컴포넌트 종류는 `data-type`, 컴파일은 템플릿과 무관) | `php artisan plugin:update sirsoft-totp --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-totp --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-totp --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-totp --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 작성자가 읽는 props 계약) | `php artisan plugin:update sirsoft-totp --force` |
| `assets/icon.svg` | 확장 아이콘 (관리자 목록·설치 마법사 카드) | 정사각·100KB 이하·스크립트 없는 SVG — 교체 후 `php artisan plugin:update sirsoft-totp --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->

## 3. 핵심 흐름

<!-- @intent START -->
**등록 시작 → 확정**: `POST /api/plugins/sirsoft-totp/enrollment` →
`TotpEnrollmentController::store()` 가 `PluginSettingsService::get('sirsoft-totp')` 로
`issuer` 를 해석(비어 있으면 `config('app.name')` 폴백) → `TotpEnrollmentService::start()`
가 확정 자격증명이 이미 있으면 `AlreadyEnrolledException`(→ 409), 없으면
`Base32::randomSecret()`(20바이트=160비트)로 시크릿 발급 →
`TotpCredentialRepository::issue()` 가 `updateOrCreate` 로 `confirmed_at` ·
`last_used_timestep` · `backup_codes` 를 함께 비우고 저장 → 평문 시크릿과
`Totp::otpauthUri()` 결과를 응답에 **1회** 노출. 이어서
`POST /api/plugins/sirsoft-totp/enrollment/confirm` → `TotpCodeRequest` 가 `code` 를
검증(`required|string|min:6|max:32`) → `TotpEnrollmentService::confirm()` 가
`Totp::verify()` 로 첫 코드를 대조하고, 통과한 timestep 을 `last_used_timestep` 에 새긴 뒤
(확정에 쓴 코드를 곧바로 로그인에 재사용하지 못하게 한다) 백업 코드 10개를 만들어
bcrypt 해시로 저장 → 평문 백업 코드를 응답에 **1회** 노출. 코드가 틀리면 422.

**로그인 2단계 인증**: 코어 `AuthService::attemptLogin()` →
`resolveTwoFactorProviderId()` → `resolveTwoFactorProvider()` 가 login purpose 의
프로바이더를 해석하고 `canProviderChallengeUser()` 를 통해
`TotpIdentityProvider::canChallengeUser()`(→ `TotpCredentialRepository::hasConfirmed()`)
를 묻는다 → 확정 자격증명이 있으면 `totp` 채택, 없으면 `allowsMailFallback()` 이 참일 때만
코어 `mail` 프로바이더로 폴백 → 코어 `startTwoFactorChallenge()` 가
`TotpIdentityProvider::requestChallenge()` 를 호출, 여기서 로그가 `Sent` 로 즉시 기록되고
`publicPayload` 에 `code_length=6` · `delivery=none` 이 실린다(프론트가 「인증번호 다시
받기」를 숨기는 근거) → 사용자가 코어 `POST /api/auth/login/two-factor` 로 코드 제출 →
`TotpIdentityProvider::verify()` 가 상태 가드(NOT_FOUND / WRONG_PROVIDER / INVALID_STATE /
EXPIRED / MAX_ATTEMPTS)를 통과시킨 뒤 `attempts` 를 먼저 올리고
`matchCredential()` 로 대조 → 6자리 숫자면 TOTP(성공 시 `last_used_timestep` 갱신),
8자 이상이면 백업 코드(성공 시 해당 항목 제거) → 성공하면 로그를 `Verified` 로 바꾸고
`verification_token` 을 claims 로 반환.

**관리자 강제 해제**: `DELETE /api/plugins/sirsoft-totp/admin/credentials/{user}` →
라우트 미들웨어 `permission:admin,sirsoft-totp.credentials.delete` →
`TotpAdminCredentialController::destroy()` 가 `UserRepositoryInterface::findById()` 로 대상
확인(없으면 404) → `TotpEnrollmentService::reset()` 이 코드 확인 없이
`deleteByUserId()` → 실제로 지워진 경우에만
`HookManager::doAction('sirsoft-totp.credential.admin_reset', $user, ['actor_id', 'ip_address'])`
발행. 관리자 본인이 단말과 백업 코드를 모두 잃어 관리자 화면에 들어갈 수 없을 때는
`php artisan totp:reset {email}` 이 같은 `reset()` 을 서버 셸에서 호출한다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 1개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 6개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 6개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 1개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 0개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 0개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 0개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
이 플러그인을 고치지 않고 동작을 바꾸려면 잡을 것은 하나뿐입니다 —
`sirsoft-totp.credential.admin_reset` (action, 인자 `(User $user, array $context)`).
관리자나 `totp:reset` 이 회원의 등록을 강제 해제한 **직후**, 실제로 지워진 경우에만
발행됩니다. 추가 감사 로그를 남기거나 당사자에게 알림을 보내는 확장이 이 자리를 씁니다.
`$context` 에는 `actor_id`(관리자 API 경로에서만 채워짐)와 `ip_address` 가 실립니다.

구독 쪽 4건은 **코어가 소유한 훅**이라 이 플러그인의 계약이 아닙니다. 다만 우선순위는
계약처럼 다뤄야 합니다 — `RegisterTotpProviderListener` 와 `ExtendLoginPurposeChannels` 는
둘 다 priority 20 으로 코어 기본값을 받아 **덧붙이는** 위치에 있습니다. 다른 확장이 더
이른 우선순위로 같은 필터에서 `totp` 키를 덮어쓰거나 login 목적 자체를 제거하면 이
플러그인은 조용히 무력화됩니다.

프로바이더 동작 자체(코드 검증·리플레이 가드·백업 코드 소진)를 바꾸는 확장점은 두지
않았습니다. 인증 통과 조건을 외부에서 갈아 끼울 수 있는 자리는 그 자체가 2단계 인증을
우회하는 표면이 됩니다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan plugin:update sirsoft-totp --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 스키마 변경 시 마이그레이션(한국어 comment + `down()`) + 기설치본 백필용 업그레이드 스텝
- [ ] 발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=plugin:sirsoft-totp` 재실행 + `docs/api/**` 갱신
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] `requestChallenge()` 의 로그 상태를 바꿨다면 코어 `AuthService::startTwoFactorChallenge()` 의 `Failed` 판정과 함께 확인 — `Requested` 로 두면 로그인이 차단됩니다
- [ ] `canChallengeUser()` 의 판정을 바꿨다면 **부작용이 없는지**(조회만 하는지) 확인 — 코어 로그인 판정 경로에서 호출되므로 challenge 나 로그를 만들면 안 됩니다
- [ ] `Totp::DIGITS` · `PERIOD` · `ALGORITHM` 은 상수로 유지하고 설정 스키마에 노출하지 않습니다 — 인증 앱 호환성
- [ ] 자격증명 컬럼을 늘렸다면 `TotpCredential::$hidden` 과 `casts()` 를 함께 검토 — 시크릿·백업 코드 계열은 어떤 응답에도 실리면 안 됩니다
- [ ] 자격증명 파기 경로를 늘렸다면 `totp_credentials.user_id` 는 CASCADE 가 아니므로 리스너 명시 삭제가 따라와야 합니다
- [ ] 평문 시크릿·백업 코드를 내려주는 응답을 추가·변경했다면 "1회 노출" 계약이 깨지지 않는지 확인 — 조회 경로로는 절대 내려가지 않습니다
- [ ] 프론트 핸들러를 추가했다면 `resources/js/handlers/index.ts` 의 `handlerMap` 에 넣는다 — 부팅 등록과 로케일 전환 뒤 재등록(`window.__SirsoftTotp.initPlugin()`)이 모두 이 맵을 읽는다. `initPlugin()` 에는 QR 관측자 같은 1회성 부팅 작업을 넣지 않는다
- [ ] `resources/js/` 를 고쳤다면 `php artisan plugin:build --production` 후 `dist/js/plugin.iife.js` 를 함께 커밋 (`sourceMappingURL` 잔존 금지)

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| `requestChallenge()` 가 로그를 `Requested` 로 기록 | `IdentityVerificationStatus::Sent` 로 즉시 기록 | 코어 `AuthService::startTwoFactorChallenge()` 는 `Failed` 만 발송 실패로 읽고 나머지는 통과시키지만, 발송 단계가 없는 수단이 `Requested` 로 남으면 "전달 가능 상태"를 표현할 방법이 없다. `Sent` 는 여기서 "사용자가 코드를 얻을 수 있다"는 뜻이다 |
| digits·period·algorithm 을 설정 스키마에 노출 | `Totp::DIGITS`(6) · `PERIOD`(30) · `ALGORITHM`(sha1) 상수 유지 | 주요 인증 앱이 이 조합만 QR 로 정확히 읽는다. 바꾸면 "앱이 만든 코드가 안 맞는다" 로만 드러나는 고장이 된다 |
| `Totp::verify()` 를 `notBeforeTimestep` 없이 호출 | `$credential->last_used_timestep` 을 넘기고 성공 시 갱신 | 없으면 방금 통과한 코드를 30초 안에 다시 제출해 재사용할 수 있다 (리플레이) |
| 미확정 자격증명(`confirmed_at IS NULL`)으로 challenge 발행 | `canChallengeUser()` → `hasConfirmed()` 로 확정본만 | 앱에 시크릿을 넣지 않은 사용자에게 완료할 수 없는 challenge 가 발행되어 계정이 잠긴다 |
| 확정된 자격증명 위에 새 시크릿 덮어쓰기 | `AlreadyEnrolledException` → 409, 해제를 먼저 요구 | 덮어쓰면 기존 앱이 조용히 무효가 되고 사용자는 다음 로그인에서야 안다 |
| 검증 실패 사유를 코드 오답/백업 코드 오답으로 구분해 응답 | 둘 다 `INVALID_CODE` | 구분해 주면 어떤 입력이 "형식은 맞다" 인지 흘러 나간다 |
| 백업 코드를 소진하지 않고 통과시키기 | `matchBackupCode()` 가 돌려준 남은 목록으로 즉시 덮어쓰기 | 1회용이 아니면 유출된 백업 코드 하나가 영구 마스터키가 된다 |
| `TotpCredential` 을 모델·`DB` 파사드로 직접 조회·저장 | `TotpCredentialRepositoryInterface` 경유 | 저장 경로가 한곳에 모여야 "어디서 시크릿이 쓰이는가" 를 한 파일로 답할 수 있다 |
| 모델이 스스로 `save()` 해 백업 코드 상태를 영속 | `matchBackupCode()` 는 남는 목록만 돌려주고 저장은 Repository | 모델이 저장하면 그 경로가 감사 대상 밖으로 샌다 |
| `totp_credentials.user_id` 에 CASCADE 를 걸어 정리 | `CleanTotpCredentialOnUser*` 리스너 명시 삭제 | database-guide 규정. 삭제는 `core.user.before_delete`(외래키가 살아 있을 때), 탈퇴는 `core.user.after_withdraw`(트랜잭션 종료 후)로 훅이 다르다 |
| 관리자 강제 해제를 회원 관리 권한으로 허용 | `permission:admin,sirsoft-totp.credentials.delete` 전용 권한 | 회원 정보를 볼 수 있다는 것과 그 사람의 2단계 인증을 해제할 수 있다는 것은 다른 신뢰 수준이다 |
| 본인 등록 API 경로에 사용자 식별자를 두기 | `/enrollment` — 대상은 항상 호출자 본인 | 남의 자격증명을 가리킬 수 있는 표면 자체를 만들지 않는다 |
| `install()` 에서 `TotpIdentityProvider::PROVIDER_ID` 등 `src/` 클래스 상수 참조 | 리터럴 `'totp'` 사용 | `install()` 은 코어 PluginManager 가 이 플러그인의 PSR-4 오토로드를 등록하기 전에 호출된다 |
| provider 설정을 `config()` 로 읽기 | `PluginSettingsService::get('sirsoft-totp')` 로 매 요청 직접 조회 | 코어 `PluginSettingsService::save()` 는 인메모리 캐시만 비우므로 `config()` 로 읽으면 변경된 설정이 다음 요청에 반영되지 않는다 |
| `dispatcher.registerHandler` 를 변수로 떼어 수신자 없이 호출 | `register.call(dispatcher, …)` 로 수신자를 붙인다 | `registerHandler` 는 내부에서 `this.customHandlers` 를 쓴다. 수신자 없이 부르면 예외가 나고, 그 예외가 부팅 함수를 끊어 QR 관측자까지 설치되지 않았다 |
| `window.__SirsoftTotp` 를 지우거나 `initPlugin` 이름을 바꾸기 | 전역 `{ identifier, initPlugin }` 을 그대로 둔다 | 로케일 전환 뒤 코어는 `__` 로 시작하는 전역에서 `initPlugin()` 을 찾아 부른다. 없으면 언어를 바꾼 뒤 「전체 복사」가 동작하지 않는다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 8개 | `plugins/_bundled/sirsoft-totp/tests` |
| Vitest | 8개 | `vitest.config.ts` |
| Playwright | 0개 | — |
| 시나리오 매니페스트 | 6개 | `tests/scenarios` |

기저 TestCase: `tests/PluginTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit plugins/_bundled/sirsoft-totp/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd plugins/_bundled/sirsoft-totp && powershell -Command "npm run test:run -- <대상>"

```

무필터 전체 실행은 금지되어 있습니다 — 변경 범위에 걸리는 대상만 지정해 실행합니다.
<!-- @generated:test-commands END -->

## 8. 문서 목차

<!-- @generated:docs-index START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 문서 | 내용 | 상태 |
|---|---|---|
| [docs/README.md](docs/README.md) | 문서 통합 목차와 실측 집계 | ✅ |
| [docs/architecture.md](docs/architecture.md) | 설계 의도·계층 지도·디렉토리 맵 | ✅ |
| [docs/extension-points.md](docs/extension-points.md) | 발행/구독 훅·미들웨어·채널·스케줄 | ✅ |
| [docs/data-model.md](docs/data-model.md) | 모델·소유 테이블·마이그레이션·Enum | ✅ |
| [docs/settings.md](docs/settings.md) | 설정 스키마·권한·메뉴·라우트·의존 관계 | ✅ |
| [docs/frontend.md](docs/frontend.md) | 레이아웃·액션 핸들러·전역 진입점·에셋 | ✅ |
| [docs/editor-spec.md](docs/editor-spec.md) | 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 | ✅ |
| [docs/api/](docs/api/README.md) | API 레퍼런스 (엔드포인트별 파라미터·응답 필드) | ✅ |
| [CHANGELOG.md](CHANGELOG.md) | 변경 이력 | ✅ |
<!-- @generated:docs-index END -->
