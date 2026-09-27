# 인증 앱 2단계 인증 (TOTP) — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 모델 | 테이블 | fillable | 관계 | 특성 |
|---|---|---|---|---|
| `TotpCredential` | `totp_credentials` | 5 | user→User | - |
<!-- @generated:models END -->

<!-- @intent START -->
모델이 하나뿐인 것은 이 플러그인이 challenge 상태를 소유하지 않기 때문입니다. 진행 중인
2단계 인증의 상태·시도 횟수·만료는 코어 `identity_verification_logs` 가 들고, 이
플러그인은 **사용자별 자격증명**만 소유합니다. 소유를 이렇게 가른 덕분에 평문 계열
(시크릿·백업 코드)이 코어 테이블로 넘어가지 않습니다.

`TotpCredential` 을 읽을 때 알아야 할 것은 네 가지입니다.

**`$hidden` 이 방어선입니다.** `secret` 과 `backup_codes` 는 `$hidden` 에 있어 어떤 직렬화
경로로도 응답에 실리지 않습니다. 모델을 그대로 돌려주는 컨트롤러가 실수로 생겨도 그
자리에서 막힙니다 — 평문이 노출되는 자리는 서비스가 **반환값으로** 돌려주는 발급·확정·
재발급 세 지점뿐이어야 합니다.

**cast 가 곧 저장 형식입니다.** `secret` 은 `encrypted` 라 DB 덤프만으로는 코드를 만들 수
없고, `backup_codes` 는 `array` cast 위에 서비스가 bcrypt 해시를 담습니다. 해시 배열이라
어느 항목이 맞았는지는 순회 비교로만 알 수 있으며, 그래서 `matchBackupCode()` 가 인덱스가
아니라 **남는 목록**을 돌려줍니다.

**`confirmed_at` 이 자격증명의 상태 전부입니다.** `isConfirmed()` 는 이 값이 null 이
아닌지만 봅니다. 미확정 자격증명은 "발급은 했지만 사용자가 앱에 시크릿을 넣었는지 모르는"
상태이고, 그 상태로 2단계 인증을 요구하면 계정이 잠깁니다. 그래서 로그인 경로는
`hasConfirmed()` · `findConfirmedByUserId()` 로 확정본만 봅니다. 별도 status 컬럼이나 Enum
을 두지 않은 이유는 상태가 둘뿐이고, 셋째 상태가 생기면 그때 컬럼이 아니라 의미가 먼저
필요하기 때문입니다.

**모델은 저장하지 않습니다.** `matchBackupCode()` 는 소진 후 남는 목록을 계산만 하고
`save()` 하지 않습니다. 영속은 `TotpCredentialRepository::update()` 몫입니다 — 모델이
스스로 저장하면 그 경로가 감사 대상 밖으로 샙니다. `normalizeBackupCode()` 가 모델에 있는
것은 저장 시점(서비스의 `hashBackupCodes()`)과 대조 시점이 **같은 규칙**을 거쳐야 하기
때문입니다. 사용자는 화면에 보인 `abcde-fghij` 를 하이픈 없이 또는 대문자로 옮겨 적습니다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 테이블 | 모델 |
|---|---|
| `totp_credentials` | `TotpCredential` |
<!-- @generated:tables END -->

<!-- @intent START -->
테이블 하나가 사용자와 1:1 입니다 — `user_id` 에 UNIQUE 가 걸려 있어 한 사용자가 인증 앱
자격증명을 둘 이상 가질 수 없습니다. 인증 앱을 여러 개 등록하는 시나리오를 열지 않은 것은,
등록된 앱 중 하나만 뚫려도 2단계 인증이 뚫리는데 사용자는 자기가 몇 개를 등록해 뒀는지
기억하지 못하기 때문입니다. 단말을 바꾸려면 해제 후 재등록합니다.

**컬럼별 소유 이유**

| 컬럼 | 왜 필요한가 |
|---|---|
| `secret` | TOTP 계산의 입력. `text` + `encrypted` cast — 암호문 길이가 원문보다 길어지므로 `string` 이 아니다 |
| `confirmed_at` | 확정 여부. null 이면 로그인에 쓰이지 않는다 |
| `last_used_timestep` | 리플레이 가드. 이 값 이하의 timestep 은 `Totp::verify()` 가 거부한다 |
| `backup_codes` | 단말 분실 시의 유일한 자력 구제 수단. 1회용이라 사용 시 항목이 빠진다 |

**CASCADE 를 걸지 않았습니다.** `user_id` 는 `constrained('users')` 로 외래키만 걸고
`cascadeOnDelete()` 를 붙이지 않았습니다(database-guide 규정). 정리는
`CleanTotpCredentialOnUserWithdraw` · `CleanTotpCredentialOnUserDelete` 가 명시
삭제합니다. 그래서 삭제 리스너가 `core.user.before_delete` 를 구독해야 합니다 — 사용자 행이
먼저 사라지면 외래키 제약으로 삭제 자체가 실패합니다.

**이 테이블은 uninstall 에서 드랍되지 않습니다.** `plugin.php::uninstall()` 은 설정 매핑만
거두고 테이블은 남깁니다. 플러그인을 잠시 껐다 켜는 운영에서 회원 전원의 등록이 사라지면
재등록을 강요하게 되기 때문입니다. PII 정리는 사용자 이벤트 리스너 소관입니다.

`timestamps` 가 있지만 `updated_at` 을 인증 판정에 쓰지 않습니다 — 마지막 사용 시각을
알고 싶다면 코어 `identity_verification_logs` 가 challenge 단위로 더 정확히 답합니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
마이그레이션 1개.

| 파일 | 생성 테이블 | 변경 테이블 | down() |
|---|---|---|---|
| `2026_09_17_000001_create_totp_credentials_table.php` | `totp_credentials` | `totp_credentials` | ✅ |
<!-- @generated:migrations END -->

<!-- @intent START -->
마이그레이션이 하나뿐인 것은 이 플러그인이 아직 스키마를 바꾼 적이 없다는 뜻입니다.
기설치본을 고려할 필요가 없으므로 백필 업그레이드 스텝도 없습니다.

**컬럼 comment 가 한국어로 달려 있습니다** (database-guide 규정). 특히
`last_used_timestep` 과 `confirmed_at` 의 comment 는 컬럼 이름만으로는 알 수 없는 계약
(리플레이 차단 / 미확정 상태의 의미)을 DB 스키마 자체에 남깁니다. MySQL 에서는 테이블
comment 도 별도 `Schema::table()` 로 답니다 — Laravel 의 `Blueprint::comment()` 는
`create` 안에서 드라이버에 따라 반영되지 않기 때문에 드라이버를 확인한 뒤 붙입니다.

**`down()` 은 있지만 일반 제거 경로에서 실행되지 않습니다.** `migrate:rollback` 시점에만
돌고, `plugin:uninstall` 은 이 테이블을 드랍하지 않습니다(위 「소유 테이블」 참조).
`Schema::hasTable()` 로 존재를 확인한 뒤 드랍하는 것은 database-guide 규정입니다.

스키마를 바꾼다면 이 플러그인 고유의 제약이 둘 있습니다. 시크릿 컬럼의 타입을 줄이면
`encrypted` cast 의 암호문이 잘리고, 그 손상은 **다음 로그인 실패로만** 드러납니다.
그리고 `user_id` 의 UNIQUE 를 풀면 `findByUserId()` · `hasConfirmed()` 계열이 조용히
첫 행만 보게 되므로, 다중 등록을 여는 변경은 저장소 계약부터 함께 바꿔야 합니다.
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_Enum 이 없습니다._
<!-- @generated:enums END -->

<!-- @intent START -->
Enum 을 두지 않았습니다.

이 플러그인이 다루는 상태값은 전부 **코어 Enum** 입니다 —
`App\Enums\IdentityVerificationStatus`(challenge 상태) ·
`App\Enums\IdentityVerificationChannel`(`App` 채널) ·
`App\Enums\IdentityVerificationPurpose`(`Login` 목적). 코어가 소유한 어휘를 플러그인이
자기 Enum 으로 다시 선언하면 코어가 값을 늘렸을 때 두 정의가 어긋나고, 어긋난 사실은
런타임 비교 실패로만 드러납니다.

플러그인 고유의 값은 Enum 이 아니라 **클래스 상수**로 둡니다.

| 상수 | 값 | 어디에 |
|---|---|---|
| `PROVIDER_ID` | `totp` | `TotpIdentityProvider` |
| `BACKUP_CODE_MIN_LENGTH` | 8 | `TotpIdentityProvider` — 6자리 TOTP 와 백업 코드를 가르는 하한 |
| `DIGITS` · `PERIOD` · `ALGORITHM` | 6 · 30 · `sha1` | `Support\Totp` |
| `BACKUP_CODE_COUNT` · `BACKUP_CODE_LENGTH` | 10 · 10 | `TotpEnrollmentService` |

Enum 으로 만들 만한 후보가 아닌 이유는 이들이 **분류가 아니라 수치·식별자**이기
때문입니다. 열거할 다른 경우의 수가 없고, 특히 `DIGITS` · `PERIOD` · `ALGORITHM` 은
인증 앱 호환성 때문에 다른 값을 가질 수 없는 고정값입니다.

`TotpCredential` 의 상태도 Enum 이 아니라 `confirmed_at` 의 null 여부입니다(위 「모델」
참조) — 상태가 둘뿐이면 Enum 은 어휘만 늘립니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 클래스 | 종류 | 설명 |
|---|---|---|
| `TotpCredentialRepository` | 구현 | 인증 앱 자격증명 저장소 구현. |
| `TotpCredentialRepositoryInterface` | 인터페이스 | 인증 앱 자격증명 저장소 계약. |
<!-- @generated:repositories END -->

<!-- @intent START -->
저장소가 하나뿐이고, 인터페이스가 곧 "시크릿에 손댈 수 있는 동작의 전수 목록" 입니다.
`Service` · `Listener` · `Identity` 는 이 계약만 주입받고 `TotpCredential` 모델이나 `DB`
파사드를 직접 만지지 않습니다 — 저장 경로가 한곳에 모여야 "어디서 시크릿이 쓰이는가" 를
한 파일로 답할 수 있습니다.

메서드 여섯 개 중 조회가 셋인 것은 **호출자가 필요한 만큼만 읽게** 하기 위해서입니다.

| 메서드 | 쓰는 곳 | 왜 이 모양인가 |
|---|---|---|
| `hasConfirmed()` | `canChallengeUser()` | `exists()` 로 답한다 — 판정만 필요한 로그인 경로가 시크릿을 메모리에 올리지 않는다 |
| `findConfirmedByUserId()` | `verify()` | 대조에 시크릿이 필요하므로 행을 읽되 확정본만 |
| `findByUserId()` | 등록 서비스 | 미확정본도 봐야 한다 — 등록 재개·확정 판정에 쓰인다 |
| `issue()` | `start()` | `updateOrCreate` 로 발급·재발급을 한 동작으로 |
| `update()` | 확정·리플레이 갱신·백업 코드 소진 | 부분 갱신 하나로 통일 |
| `deleteByUserId()` | 해제·강제 해제·리스너 정리 | 삭제 행 수를 돌려준다 — `reset()` 이 "지울 것이 있었는가" 를 판정한다 |

**`issue()` 가 세 컬럼을 함께 비웁니다.** 새 시크릿을 넣을 때 `confirmed_at` ·
`last_used_timestep` · `backup_codes` 를 모두 null 로 되돌립니다. 새 시크릿에 옛 상태가
따라붙으면 이전 앱으로 만든 코드가 통과하거나 옛 백업 코드가 살아남습니다. 이 초기화를
호출부에 맡기지 않고 저장소가 강제하는 이유는, 빠뜨려도 즉시 드러나지 않고 인증이 느슨해진
상태로만 남기 때문입니다.

바인딩은 `TotpServiceProvider::$repositories` 한 줄이며, 구현체 `TotpCredentialRepository`
는 Eloquent 쿼리만 씁니다. 캐시를 두지 않는 것은 자격증명이 인증 판정의 입력이라
무효화가 한 박자 늦으면 해제된 자격증명으로 로그인이 통과할 수 있기 때문입니다.
<!-- @intent END -->
