# NHN KCP 휴대폰 본인확인 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_이 확장은 훅을 발행하지 않습니다._
<!-- @generated:hooks-published END -->

<!-- @intent START -->
`sirsoft-verification_kginicis`는 같은 표에 3건이 잡히지만 실제로는 발행하는 훅이
아닙니다(코어 호출부를 보여주는 docblock 예시를 소스 자동 감지가 오인한 결과) — 이
플러그인은 그런 예시 서술 방식을 쓰지 않아 표가 정확히 비어 있습니다. 두 플러그인 모두
실제로 발행하는 도메인 전용 훅은 없습니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.auth.after_register` | action (미선언) | `CompleteKcpRecordAfterRegister` | `handle` | 50 |
| `core.auth.before_register` | action (미선언) | `AssertNoDuplicateKcpIdentity` | `handle` | 20 |
| `core.identity.registered_providers` | filter | `RegisterKcpProviderListener` | `register` | 20 |
| `core.plugin_settings.filter_save_data` | filter | `ValidateKcpSettingsListener` | `normalizeLiveSiteCd` | 10 |
| `core.plugin_settings.update_validation_rules` | filter | `ValidateKcpSettingsListener` | `addLiveModeRules` | 10 |
| `core.user.after_withdraw` | action (미선언) | `CleanKcpRecordOnUserWithdraw` | `handle` | 50 |
| `core.user.before_delete` | action (미선언) | `CleanKcpRecordOnUserDelete` | `handle` | 50 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
`ValidateKcpSettingsListener`가 2개 훅(`filter_save_data`/`update_validation_rules`)을
동시에 구독하는 것은 `sirsoft-verification_kginicis`의 `ValidateInicisSettingsListener`(1개
훅만 구독)와의 차이입니다 — kginicis 는 라이브 MID 프리픽스를 Provider 가 값을 쓸 때
동적으로 붙이는 반면, 이 플러그인은 저장 시점에 정규화해 DB 에 프리픽스 붙은 값을
남깁니다(§AGENTS.md "핵심 흐름"). `AssertNoDuplicateKcpIdentity`가
`core.auth.before_register`(가입 **전**)을 구독하는 이유와 `core.user.before_delete`가
`sync: true`인 이유는 kginicis 쪽과 동일합니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `AssertNoDuplicateKcpIdentity` | 1개 | 명시 등록 | ✅ | `src/Listeners/AssertNoDuplicateKcpIdentity.php` |
| `CleanKcpRecordOnUserDelete` | 1개 | 명시 등록 | ✅ | `src/Listeners/CleanKcpRecordOnUserDelete.php` |
| `CleanKcpRecordOnUserWithdraw` | 1개 | 명시 등록 | ✅ | `src/Listeners/CleanKcpRecordOnUserWithdraw.php` |
| `CompleteKcpRecordAfterRegister` | 1개 | 명시 등록 | ✅ | `src/Listeners/CompleteKcpRecordAfterRegister.php` |
| `RegisterKcpProviderListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RegisterKcpProviderListener.php` |
| `ValidateKcpSettingsListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/ValidateKcpSettingsListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
6개 리스너 중 3개(`CompleteKcpRecordAfterRegister`, `CleanKcpRecordOnUserWithdraw`,
`CleanKcpRecordOnUserDelete`)는 `sirsoft-verification_kginicis`와 동일하게 사용자
생명주기 각 단계에 맞춰 PII 레코드를 흡수하거나 정리하는 대칭 구조입니다 — 하나를 고칠
때 나머지 둘도 같은 PII 필드를 다루고 있는지 확인해야 합니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/identity_provider_nhnkcp.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/mypage_identity_card.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
`mypage_identity_card.json`은 `sirsoft-verification_kginicis`의 같은 이름 조각과 동일한
역할(마이페이지 "본인확인 완료" 상태 카드)입니다 — 여러 IDV provider 가 동시에 설치돼도
각자 자기 카드만 주입하므로 충돌하지 않습니다. `identity_provider_nhnkcp.json`은 코어
IDV 팝업이 이 provider 고유의 안내 문구·로고를 보여줄 때 쓰는 조각입니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 미들웨어가 없습니다._
<!-- @generated:middleware END -->

<!-- @intent START -->
결제 플러그인들과 달리 이 플러그인은 PG 서버가 직접 호출하는 웹훅/통보 엔드포인트가
없습니다 — 본인확인 결과는 팝업 콜백 또는 모바일 리다이렉트 콜백(둘 다 사용자 브라우저
경유)으로만 도달하므로 IP 화이트리스트 같은 서버간 통신 검증이 필요 없습니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
인증 결과는 팝업의 종료 감지 또는 모바일 리다이렉트 복귀로 프론트가 직접 회수합니다
(§AGENTS.md "핵심 흐름") — 서버가 다른 클라이언트에 실시간으로 알려야 할 상태 변화가
없어 브로드캐스트 채널이 필요 없습니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 스케줄이 없습니다._
<!-- @generated:schedules END -->

<!-- @intent START -->
`nhnkcp_cert_transactions`의 만료된 인증 거래나 `redirectStash`의 잔존 세션 데이터를
별도 배치로 청소하지 않습니다 — `sessionStorage`는 브라우저 세션 종료로 자연 소멸하고,
서버측 거래 레코드 정리는 사용자 삭제/탈퇴 시점 리스너가 담당합니다(§훅 리스너).
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 알림 정의가 없습니다._
<!-- @generated:notifications END -->

<!-- @intent START -->
본인확인 성공/실패는 사용자가 즉시 확인하는 동기적 상호작용이라, 별도 알림(이메일/SMS
등)을 발송할 지점이 없습니다.
<!-- @intent END -->
