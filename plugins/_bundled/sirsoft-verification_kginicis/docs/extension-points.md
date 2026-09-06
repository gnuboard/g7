# KG이니시스 본인인증 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 3종 / 호출 지점 3곳. 이 중 3종은 `getHooks()` 선언에 없어 소스에서 자동 감지한 것입니다 — 선언에 추가하면 유형과 설명이 함께 실립니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `core.auth.after_register` | action | — | `src/Listeners/CompleteInicisRecordAfterRegister.php:64` |
| `core.user.after_withdraw` | action | — | `src/Listeners/CleanInicisRecordOnUserWithdraw.php:18` |
| `core.user.before_delete` | action | — | `src/Listeners/CleanInicisRecordOnUserDelete.php:23` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
이 3종은 이 플러그인이 실제로 발행하는 훅이 아닙니다 — 세 리스너 파일 모두 코어가 그
훅을 어떻게 호출하는지 보여주는 **docblock 예시**(`HookManager::doAction('core.user.before_delete', $user);`
형태의 주석)를 갖고 있는데, 소스 자동 감지가 그 주석 텍스트를 실제 발행 호출로 오인해
잡아낸 결과입니다. 실제 발행 주체는 코어이며, 이 플러그인은 §구독 훅에서 같은 이름으로
**구독**만 합니다. 이 확장의 리스너에 이런 docblock 예시를 새로 추가할 때는 이 표에
가짜 항목이 늘어난다는 점을 감안합니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.auth.after_register` | action (미선언) | `CompleteInicisRecordAfterRegister` | `handle` | 50 |
| `core.auth.before_register` | action (미선언) | `AssertNoDuplicateInicisIdentity` | `handle` | 20 |
| `core.identity.registered_providers` | filter | `RegisterInicisProviderListener` | `register` | 20 |
| `core.plugin_settings.update_validation_rules` | filter | `ValidateInicisSettingsListener` | `addLiveModeRules` | 10 |
| `core.user.after_withdraw` | action (미선언) | `CleanInicisRecordOnUserWithdraw` | `handle` | 50 |
| `core.user.before_delete` | action (미선언) | `CleanInicisRecordOnUserDelete` | `handle` | 50 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
`AssertNoDuplicateInicisIdentity`가 `core.auth.before_register`(가입 **전**)을 구독하는
것은 중복 가입을 막으려면 가입 트랜잭션이 커밋되기 전에 차단해야 하기 때문입니다 —
`after_register`에서 잡으면 이미 중복 계정이 생성된 뒤라 롤백이 더 복잡해집니다.
`core.user.before_delete`는 `sync: true`로 동기 실행됩니다 — 이 시점 정리가 실패하면
FK 제약(1451)으로 사용자 삭제 자체가 실패해야 하므로, 비동기 큐로 미뤄지면 삭제가 먼저
끝나버릴 수 있습니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `AssertNoDuplicateInicisIdentity` | 1개 | 명시 등록 | ✅ | `src/Listeners/AssertNoDuplicateInicisIdentity.php` |
| `CleanInicisRecordOnUserDelete` | 1개 | 명시 등록 | ✅ | `src/Listeners/CleanInicisRecordOnUserDelete.php` |
| `CleanInicisRecordOnUserWithdraw` | 1개 | 명시 등록 | ✅ | `src/Listeners/CleanInicisRecordOnUserWithdraw.php` |
| `CompleteInicisRecordAfterRegister` | 1개 | 명시 등록 | ✅ | `src/Listeners/CompleteInicisRecordAfterRegister.php` |
| `RegisterInicisProviderListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RegisterInicisProviderListener.php` |
| `ValidateInicisSettingsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ValidateInicisSettingsListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
6개 리스너 중 3개(`CompleteInicisRecordAfterRegister`, `CleanInicisRecordOnUserWithdraw`,
`CleanInicisRecordOnUserDelete`)는 사용자 생명주기 각 단계(가입 완료·탈퇴·삭제)에 맞춰
PII 레코드를 흡수하거나 정리하는 대칭 구조입니다 — 하나를 고칠 때 나머지 둘도 같은 PII
필드를 다루고 있는지 확인해야 합니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/identity_provider_inicis.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/mypage_identity_card.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
`identity_provider_inicis.json`은 코어 IDV 팝업(§코어 AGENTS.md "본인인증(IDV) 공통 UI 가이드")이
provider 별로 다른 안내 문구·로고를 보여줘야 할 때 이 플러그인이 자기 몫을 주입하는
조각입니다. `mypage_identity_card.json`은 마이페이지에 "본인확인 완료" 상태 카드를
보여주는 조각으로, `sirsoft-verification_nhnkcp`도 동일한 명명 규칙의 자기 조각을 갖습니다
— 여러 IDV provider 가 동시에 설치돼도 각자 자기 카드만 주입하므로 충돌하지 않습니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 미들웨어가 없습니다._
<!-- @generated:middleware END -->

<!-- @intent START -->
결제 플러그인들과 달리 이 플러그인은 PG 서버가 직접 호출하는 웹훅/통보 엔드포인트가
없습니다 — 본인확인 결과는 팝업 콜백(사용자 브라우저 경유)으로만 도달하므로 IP
화이트리스트 같은 서버간 통신 검증이 필요 없습니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
인증 결과는 팝업의 postMessage 또는 팝업 종료 감지로 프론트가 직접 회수합니다(§AGENTS.md
"핵심 흐름") — 서버가 다른 클라이언트에 실시간으로 알려야 할 상태 변화가 없어 브로드캐스트
채널이 필요 없습니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 스케줄이 없습니다._
<!-- @generated:schedules END -->

<!-- @intent START -->
mTxId ↔ challenge_id 매핑(`inicis_challenge_mappings`)이나 pending PII 캐시는 만료된
항목을 별도 배치로 청소하지 않습니다 — 캐시는 TTL 로 자연 소멸하고, 매핑 테이블의 정리는
사용자 삭제/탈퇴 시점 리스너가 담당합니다(§훅 리스너).
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 알림 정의가 없습니다._
<!-- @generated:notifications END -->

<!-- @intent START -->
본인확인 성공/실패는 사용자가 팝업 화면에서 즉시 확인하는 동기적 상호작용이라, 별도
알림(이메일/SMS 등)을 발송할 지점이 없습니다.
<!-- @intent END -->
