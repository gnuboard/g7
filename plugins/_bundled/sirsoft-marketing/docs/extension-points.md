# 마케팅 동의 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 4종 / 호출 지점 2곳. 훅 이름이 상수·변수로 조립된 호출이 1곳 있어 호출 위치가 표에 다 실리지 않을 수 있습니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-marketing.filter_consent_data` | filter | 마케팅 동의 데이터를 필터링하는 훅 | 선언 (호출 위치 미확인) |
| `sirsoft-marketing.user.consent_changed` | action | 사용자 마케팅 동의 변경 시 실행되는 액션 훅 | `src/Services/MarketingConsentService.php:205` |
| `sirsoft-marketing.user.subscribed` | action | 사용자 마케팅 동의 필드가 동의(granted)로 변경될 때 실행되는 액션 훅 | 선언 (호출 위치 미확인) |
| `sirsoft-marketing.user.unsubscribed` | action | 사용자 마케팅 동의 필드가 철회(revoked)로 변경될 때 실행되는 액션 훅 | 선언 (호출 위치 미확인) |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
4종 전부 **동의 상태 변화를 바깥에 알리는** 용도입니다. 이 플러그인은 동의를 관리할 뿐 발송을
하지 않으므로, 실제 수신 목록 반영은 이 훅을 구독하는 쪽이 합니다.

| 훅 | 언제 쓰는가 |
|---|---|
| `user.consent_changed` | 동의 상태가 바뀔 때마다. 외부 마케팅 도구와 동기화하는 지점 |
| `user.subscribed` | 미동의 → 동의 전이. 수신 목록에 **추가**하는 자리 |
| `user.unsubscribed` | 동의 → 철회 전이. 수신 목록에서 **제거**하는 자리 |
| `filter_consent_data` | 동의 데이터를 다른 확장이 가공해야 할 때 |

`subscribed`/`unsubscribed` 가 `consent_changed` 와 별도로 있는 이유는, 대부분의 소비자가
"바뀌었다" 가 아니라 "켜졌다/꺼졌다" 에 따라 **다른 동작**을 하기 때문입니다. 한 훅에서
전후 값을 비교하게 하면 그 비교 코드가 소비자마다 복제됩니다.

발행 위치가 "선언(호출 위치 미확인)" 인 셋은 훅 이름이 상수·변수로 조립되어 정적 수집에
잡히지 않은 것입니다 — 선언에는 있으므로 실제로 발행됩니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.auth.register` | action (미선언) | `MarketingConsentListener` | `afterRegister` | 10 |
| `core.auth.register_validation_rules` | filter | `MarketingConsentListener` | `addRegisterValidationRules` | 10 |
| `core.plugin_settings.filter_save_data` | filter | `MarketingConsentListener` | `normalizeChannelsSaveData` | 10 |
| `core.user.after_create` | action (미선언) | `MarketingConsentListener` | `afterCreate` | 10 |
| `core.user.after_update` | action (미선언) | `MarketingConsentListener` | `afterUpdate` | 10 |
| `core.user.before_delete` | action (미선언) | `MarketingConsentListener` | `beforeDelete` | 10 |
| `core.user.create_validation_rules` | filter | `MarketingConsentListener` | `addValidationRules` | 10 |
| `core.user.filter_resource_data` | filter | `MarketingConsentListener` | `filterResourceData` | 10 |
| `core.user.filter_update_data` | filter | `MarketingConsentListener` | `filterUpdateData` | 10 |
| `core.user.update_profile_validation_rules` | filter | `MarketingConsentListener` | `addValidationRules` | 10 |
| `core.user.update_validation_rules` | filter | `MarketingConsentListener` | `addValidationRules` | 10 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
11개 전부 코어 것이며, 이 목록 자체가 **"코어를 고치지 않고 회원 도메인에 필드를 더하는 법"의
완결된 선례**입니다. 회원에 자기 필드를 붙이려는 확장은 이 표를 그대로 따라 하면 됩니다.

| 코어 훅 | 이 플러그인이 하는 일 |
|---|---|
| `auth.register_validation_rules` · `user.{create,update,update_profile}_validation_rules` | 동의 필드의 검증 규칙을 각 폼 흐름에 주입 |
| `auth.register` | 가입 완료 후 동의 값을 기록. `AuthService::register()` 에는 `filter_create_data` 가 없어 이 액션에서 요청을 직접 읽습니다 |
| `user.after_create` · `user.after_update` | 회원 생성·수정 시 동의 상태 반영 + 이력 적재 |
| `user.filter_update_data` | 저장 데이터에서 동의 필드를 분리 (코어 `User` 의 `$fillable` 로 새지 않도록) |
| `user.filter_resource_data` | 회원 API 응답에 동의 상태 병합 — 화면이 조건부 렌더링할 수 있도록 활성 키 목록도 함께 |
| `user.before_delete` | 회원 삭제 시 동의 기록 정리 (**CASCADE 에 맡기지 않는다**) |
| `plugin_settings.filter_save_data` | 채널 목록 저장 형태 정규화 |

`before_delete` 구독이 빠지면 회원을 지워도 동의 행이 남습니다. 코어 회원 삭제는 이 플러그인의
테이블을 알지 못하므로 아무 오류도 나지 않고, 고아 행만 조용히 쌓입니다.

코어 회원·가입 흐름의 훅 이름이나 페이로드가 바뀌면 이 플러그인이 예외 없이 조용히 끊깁니다 —
증상은 "가입 폼에서 동의를 체크했는데 저장되지 않는다" 로만 나타납니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `MarketingConsentListener` | 11개 | 명시 등록 | ✅ | `src/Listeners/MarketingConsentListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
`MarketingConsentListener` 하나가 11개 훅을 전부 받습니다. 훅마다 리스너를 나누지 않은 것은
의도적입니다 — 나누면 "회원 도메인에 필드를 더하려면 어디를 봐야 하는가" 의 답이 흩어집니다.

리스너는 판정과 기록을 직접 하지 않고 `MarketingConsentService` 에 위임합니다. 데이터 접근은
Repository 인터페이스 주입으로만 하며, `Model::query()` · `DB::table()` · `$row->save()` 를
직접 부르지 않습니다.

`detectSource()` 가 현재 라우트로 출처(`admin` / `profile`)를 판정해 이력에 남깁니다. 새 변경
경로(예: 일괄 처리·외부 연동)를 추가하면 그 출처도 여기서 구분해야 합니다 — 모든 변경이
`profile` 로 기록되면 이력이 "누가 바꿨는가" 를 답하지 못합니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/user-marketing-detail.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/user-marketing-form.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/user-marketing-profile-view.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/user-marketing-profile.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/user-marketing-register.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
조각 5개가 이 플러그인의 **UI 전부**입니다. 관리자 설정 화면 하나를 빼면 자기 레이아웃이
없습니다.

| 조각 | 들어가는 자리 |
|---|---|
| `user-marketing-register.json` | 회원가입 폼 |
| `user-marketing-form.json` | 관리자 회원 수정 폼 |
| `user-marketing-detail.json` | 관리자 회원 상세 |
| `user-marketing-profile.json` · `user-marketing-profile-view.json` | 마이페이지 (수정·보기) |

대상 화면을 소유한 쪽(템플릿·코어)이 그 자리(슬롯)를 없애면 조각은 **오류 없이 사라집니다.**
증상은 "가입 폼에 동의 항목이 안 보인다" 뿐이고 로그에는 아무것도 남지 않으므로, 템플릿이나
코어 회원 화면을 업그레이드한 뒤에는 다섯 자리를 눈으로 확인합니다.

마이페이지 조각은 **동의 이력이 있는 회원에게 항상 노출**되어야 합니다. 동의를 받은 경로와
철회 경로가 대칭이 아니면 그 동의는 법적 근거로 쓸 수 없습니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 미들웨어가 없습니다._
<!-- @generated:middleware END -->

<!-- @intent START -->
없습니다. 이 플러그인은 요청 흐름에 개입하지 않고 코어 회원 흐름의 훅 지점에서만 동작합니다.

관리자 채널 저장 라우트는 미들웨어 대신 코어 권한 미들웨어
(`permission:admin,core.plugins.update`)를 직접 지정합니다 — 이 플러그인이 자기 권한을
선언하지 않고 "플러그인 설정을 고칠 수 있는 사람" 이라는 코어 권한에 얹는 방식입니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
없습니다. 동의 변경은 그 회원 자신의 조작이므로 다른 접속자에게 실시간으로 알릴 사건이
없습니다.

외부 마케팅 도구와의 실시간 동기화가 필요하면 `user.consent_changed` 를 구독해 그 확장에서
자기 채널이나 외부 API 호출로 처리합니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 스케줄이 없습니다._
<!-- @generated:schedules END -->

<!-- @intent START -->
없습니다. 동의는 회원의 조작으로만 바뀌므로 주기적으로 훑을 대상이 없습니다.

동의 만료(예: "2년마다 재동의")가 필요해지면 스케줄이 생길 자리입니다. 그때도 만료 판정은
`consented_at` 과 설정값으로 하고, 만료 처리 자체는 일반 철회와 같은 경로(상태 갱신 + 이력
적재 + `user.unsubscribed` 발행)를 타야 합니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 알림 정의가 없습니다._
<!-- @generated:notifications END -->

<!-- @intent START -->
없습니다. 이 플러그인은 동의를 관리할 뿐 발송을 하지 않으므로, 자기 이름으로 보낼 알림이
없습니다.

"마케팅 수신 동의 처리 완료" 같은 확인 메일이 필요하면 `user.subscribed` 를 구독하는 확장이
코어 `GenericNotification` 으로 보냅니다. 동의 관리와 발송을 한 확장에 묶으면 발송 수단을
바꿀 때 동의 이력까지 함께 흔들립니다.
<!-- @intent END -->
