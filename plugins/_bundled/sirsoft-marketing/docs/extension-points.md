# 마케팅 동의 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 4종 / 호출 지점 3곳. 훅 이름이 상수·변수로 조립된 호출이 1곳 있어 호출 위치가 표에 다 실리지 않을 수 있습니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-marketing.filter_consent_data` | filter | 동의 항목을 일괄 저장하기 전에 동의 데이터를 가공하는 필터 훅 | `src/Services/MarketingConsentService.php:382` |
| `sirsoft-marketing.user.consent_changed` | action | 사용자 마케팅 동의 변경 시 실행되는 액션 훅 | `src/Services/MarketingConsentService.php:364` |
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
| `core.auth.register_validation_rules` | filter | `MarketingConsentListener` | `addLegacyRegisterRules` | 10 |
| `core.field.filter_definitions` | filter | `MarketingConsentListener` | `filterFieldDefinitions` | 10 |
| `core.plugin_settings.after_save` | action (미선언) | `MarketingConsentListener` | `syncFieldDeclarations` | 10 |
| `core.plugin_settings.filter_save_data` | filter | `MarketingConsentListener` | `normalizeChannelsSaveData` | 10 |
| `core.user.before_delete` | action (미선언) | `MarketingConsentListener` | `beforeDelete` | 10 |
| `core.user.filter_resource_data` | filter | `MarketingConsentListener` | `filterResourceData` | 10 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
전부 코어 훅입니다. 동의 값의 검증과 저장은 회원 항목 선언(`Plugin::getFields()`)과 저장소 어댑터
(`Fields\MarketingConsentFieldHandler`)가 맡으므로, 구독은 그 주변만 잇습니다.

| 코어 훅 | 이 플러그인이 하는 일 |
|---|---|
| `field.filter_definitions` | 회원 항목 목록을 지금 설정에 맞춘다 — 설정에서 끈 항목 제외, 지금 받는 항목인데 행이 없는 채널을 요청 시점에 추가, 약관 페이지를 정한 항목에 약관 링크(`ui.link`) 부착, 내 정보 화면에서 본인이 동의한 항목에 동의일(`ui.notice`) 부착 |
| `auth.register_validation_rules` | 가입 경로의 옛 요청 키(`agree_` + 항목 키)에 규칙을 준다. 정식 키의 규칙이 있을 때만 주며, 그래서 가입 데이터(`registration_data`)에 정식 키와 옛 키가 함께 실린다 |
| `user.filter_resource_data` | 회원 API 응답에 지금 받는 동의 항목의 값과 동의 시각, 채널 목록, 동의 이력을 병합 |
| `user.before_delete` | 회원 삭제 시 동의 기록 정리 (**CASCADE 에 맡기지 않는다**) |
| `plugin_settings.filter_save_data` | 채널 목록 저장 형태 정규화 |
| `plugin_settings.after_save` | 이 플러그인의 설정이 저장되면 항목 선언을 다시 동기화 — 채널을 더하고 지우고 이름을 바꾸면 회원 항목 표의 행이 따라간다 |

회원 생성과 수정의 검증 규칙 필터(`user.{create,update,update_profile}_validation_rules`), 수정 데이터 필터
(`user.filter_update_data`), 저장 액션(`user.after_create`, `user.after_update`, `auth.after_register`)은
구독하지 않습니다. 다시 구독하면 같은 값이 두 번 검증되고 두 번 저장됩니다.

`before_delete` 구독이 빠지면 회원을 지워도 동의 행이 남을 수 있습니다. 코어의 회원 파기는 사용 중인 항목의
저장소 어댑터(`purge`)를 부르지만, 설정에서 동의 항목을 모두 꺼 둔 사이트에서는 불릴 항목이 없기 때문입니다.

코어 회원 항목 엔진의 계약이나 위 훅의 이름, 인자가 바뀌면 이 플러그인이 예외 없이 조용히 끊깁니다 —
증상은 "가입 화면에서 동의를 체크했는데 저장되지 않는다" 로만 나타납니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `MarketingConsentListener` | 6개 | 명시 등록 | ✅ | `src/Listeners/MarketingConsentListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
리스너는 `MarketingConsentListener` 하나입니다. 판정과 기록을 직접 하지 않고 `MarketingConsentService` 와
`Fields\MarketingConsentFields`(항목 선언, 지금 받는 항목, 약관 링크)에 위임합니다. 데이터 접근은
Repository 인터페이스 주입으로만 하며, `Model::query()`, `DB::table()`, `$row->save()` 를 직접 부르지 않습니다.

동의 이력의 출처(`register` / `admin` / `profile`)는 리스너가 아니라 저장소 어댑터가 회원 항목의 표면에서
정합니다(`signup` → `register`, `admin_create`와 `admin_update` → `admin`, `self_update`와 `required_info` →
`profile`). 요청 경로를 읽지 않으므로 화면 없는 경로에서도 출처가 맞습니다. 새 표면에서 동의를 받게 되면
그 표면의 출처를 어댑터에서 구분해야 합니다 — 구분하지 않은 표면은 요청한 사람이 본인이면 `profile`,
아니면 `admin` 으로 기록됩니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/user-marketing-detail.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_user_detail` |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
조각은 관리자 회원 상세의 동의 이력(`user-marketing-detail.g7.html`) 하나입니다. 탭 하나를 더하고 그 안에
동의 이력 표를 보입니다. 지금의 동의 값은 그리지 않습니다 — 값은 회원 항목으로 선언되어 있어 코어 회원
화면이 그리고, 조각이 또 그리면 같은 값이 두 번 보입니다.

가입 화면, 내 정보 수정과 보기, 관리자 회원 폼의 동의 칸은 이 플러그인의 조각이 아니라 코어 템플릿이
항목 계약대로 그립니다. 계약에서 이 플러그인이 싣는 표시 정보는 다음과 같습니다.

| 키 | 값 |
|---|---|
| `ui.group` | `agreements` — 약관 동의 묶음 |
| `ui.role` | `master`(전체 동의) / `channel`(수신 채널) / `legal`(제3자 제공, 정보 공개) |
| `ui.parent` | 채널 항목만 `marketing_consent` — 전체 동의 아래에 들여 그리거나 함께 켜고 끌 때 쓴다 |
| `ui.link` | 약관 페이지를 정한 항목만. `label`, `title`, `url`, `content_api`, `requires_module` |
| `ui.notice` | 내 정보 화면(표면 `self_update`)에서 본인이 동의한 항목만. 「2026-11-12 동의」처럼 사용자 시간대 날짜가 든 요청 언어 문구이며, 내 정보 보기가 값 아래 알림 줄로 그린다. 동의하지 않았거나 다른 화면이면 키가 없다 |

대상 화면을 소유한 쪽(관리자 템플릿)이 그 자리(슬롯)를 없애면 조각은 **오류 없이 사라집니다.** 로그에는
아무것도 남지 않으므로, 템플릿을 업그레이드한 뒤에는 관리자 회원 상세의 동의 이력 탭과 세 화면의 동의 칸을
눈으로 확인합니다.

내 정보 수정의 동의 칸은 **항상 나타나야** 합니다. 동의 항목의 수정 범위는 본인 수정(`edit_scope = self`)이며,
동의를 받은 경로와 철회 경로가 대칭이 아니면 그 동의는 법적 근거로 쓸 수 없습니다.
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
