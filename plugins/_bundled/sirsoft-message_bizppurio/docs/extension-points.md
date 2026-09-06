# 비즈뿌리오 메시지 발송 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 1종 / 호출 지점 1곳. 이 중 1종은 `getHooks()` 선언에 없어 소스에서 자동 감지한 것입니다 — 선언에 추가하면 유형과 설명이 함께 실립니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-message_bizppurio.balance.low` | action | — | `src/Services/WebhookReportService.php:125` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
하나뿐이고, 그 하나가 **운영자 개입이 필요한 유일한 실패**를 알립니다.

`sirsoft-message_bizppurio.balance.low` 는 잔액 부족·후불 한도 초과로 발송이 실패했을 때
발화합니다. 인수는 `string $resultCode, string $channel` 이며 `$resultCode` 는 `9070`(문자
잔액 부족) · `7436`(알림톡 지갑 잔액 부족) · `9071`(후불 한도 초과) 중 하나입니다.

```php
use App\Extension\HookManager;

HookManager::addAction(
    'sirsoft-message_bizppurio.balance.low',
    function (string $resultCode, string $channel) {
        SlackNotifier::send("비즈뿌리오 잔액 부족: 채널={$channel}, 코드={$resultCode}");
    },
    priority: 10
);
```

이 훅은 관리자 자체 알림을 발화하는 지점과 **동일**하며, 채널별 쿨다운(기본 3600초) 안에서는
한 번만 실행됩니다. 대량 발송이 한꺼번에 실패해도 통지가 쏟아지지 않게 하는 장치입니다.

잔액 부족 통지를 **문자·알림톡이 아닌 경로로도** 받고 싶을 때 여기에 붙입니다 — 잔액이 없어서
실패한 상황이므로 같은 채널로 보내는 통지는 함께 실패합니다.

다른 실패(일시 오류·영구 실패)에는 훅이 없습니다. 일시 오류는 재시도가 해결하고, 영구 실패는
발송 이력에 사유가 남아 운영자가 확인할 수 있기 때문입니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.notification_log.after_log_failed` | action (미선언) | `LinkNotificationLogListener` | `linkLog` | 15 |
| `core.notification_log.after_log_sent` | action (미선언) | `LinkNotificationLogListener` | `linkLog` | 15 |
| `core.plugin_settings.after_save` | action | `InvalidateTokenOnSettingsSaveListener` | `invalidateToken` | 10 |
| `core.plugin_settings.update_rules` | filter | `ValidateBizppurioSettingsListener` | `addLiveModeRules` | 10 |
| `sirsoft-ecommerce.notification.extract_data` | filter | `GuestPhoneExtractListener` | `injectGuestPhone` | 20 |
| `sirsoft-message_bizppurio.notification.extract_data` | filter | `BalanceLowNotificationDataListener` | `injectBalanceLowData` | 10 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
6개가 각각 다른 이음매입니다. 하나가 빠지면 그 이음매만 조용히 끊기고 나머지는 정상
동작하므로, 리스너를 지우거나 이름을 바꿀 때는 그것이 무엇을 잇고 있었는지 먼저 확인합니다.

| 훅 | 무엇을 잇는가 |
|---|---|
| `core.notification_log.after_log_sent` · `after_log_failed` | 코어 알림 로그와 이 플러그인의 발송 기록을 연결 — 관리자 "알림 발송 이력" 에 문자·알림톡 결과가 함께 보이는 이유 |
| `core.plugin_settings.after_save` | 설정을 저장하면 비즈뿌리오 인증 토큰을 무효화 — 계정 정보를 바꿨는데 옛 토큰으로 계속 발송하는 것을 막습니다 |
| `core.plugin_settings.update_rules` | **운영 모드로 전환할 때만** 필수값(아이디·비밀번호·API 키·발신번호) 검증을 추가. 검수 모드에서는 비어 있어도 저장되어야 합니다 |
| `sirsoft-ecommerce.notification.extract_data` | 비회원 주문 알림에 주문 시 입력한 연락처를 주입 |
| `sirsoft-message_bizppurio.notification.extract_data` | 잔액부족 관리자 알림의 본문 변수 채우기 (자기 훅) |

`sirsoft-ecommerce` 는 **manifest 의존이 아닙니다.** 훅 구독은 상대가 없으면 발화하지 않으므로
이커머스가 없어도 이 플러그인은 정상 동작하고 비회원 문자 발송만 비어 있습니다. 대신 이커머스가
그 훅 이름이나 페이로드를 바꾸면 예외 없이 조용히 끊기고, 증상은 "비회원 주문 문자가 안 간다"
로만 나타납니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `BalanceLowNotificationDataListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/BalanceLowNotificationDataListener.php` |
| `GuestPhoneExtractListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/GuestPhoneExtractListener.php` |
| `InvalidateTokenOnSettingsSaveListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/InvalidateTokenOnSettingsSaveListener.php` |
| `LinkNotificationLogListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/LinkNotificationLogListener.php` |
| `RegisterNotificationChannelsListener` | 0개 | 명시 등록 | ✅ | `src/Listeners/RegisterNotificationChannelsListener.php` |
| `SeedChannelTemplatesListener` | 0개 | 명시 등록 | ✅ | `src/Listeners/SeedChannelTemplatesListener.php` |
| `ValidateBizppurioSettingsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ValidateBizppurioSettingsListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
7개 전부 `HookListenerInterface` 를 구현하고 `getSubscribedHooks()` 로 자기 구독을 선언합니다.

| 리스너 | 역할 |
|---|---|
| `RegisterNotificationChannelsListener` | 코어 알림 시스템에 문자·알림톡 채널 등록 |
| `SeedChannelTemplatesListener` | 그 채널의 템플릿 자리 시드 |
| `LinkNotificationLogListener` | 코어 알림 로그 ↔ 발송 기록 연결 |
| `InvalidateTokenOnSettingsSaveListener` | 설정 저장 시 인증 토큰 무효화 |
| `ValidateBizppurioSettingsListener` | 운영 모드 전환 시 필수값 검증 규칙 추가 |
| `GuestPhoneExtractListener` | 비회원 주문 알림에 연락처 주입 |
| `BalanceLowNotificationDataListener` | 잔액부족 알림 본문 변수 채우기 |

구독 수가 0인 둘(`RegisterNotificationChannelsListener` · `SeedChannelTemplatesListener`)은
훅이 아니라 **수명주기 시점**(설치·활성화)에 호출되는 리스너라 정적 훅 수집에 잡히지 않습니다.
이 둘이 빠지면 채널 자체가 알림 설정 화면에 나타나지 않습니다.

리스너에서 `Model::query()` · `DB::table()` · `$row->save()` 를 직접 부르지 않습니다 — 데이터
접근은 Repository 인터페이스 주입으로만 합니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/notification_log_result.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/notification_row_footer.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/notification_tab_board.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/notification_tab_core.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/notification_tab_ecommerce.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/notification_template_form_footer_actions.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/notification_template_form_sections.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
조각 7개가 이 플러그인의 **운영 UI 대부분**입니다. 자체 레이아웃은 플러그인 설정 화면 하나
뿐입니다.

| 조각 | 들어가는 자리 |
|---|---|
| `notification_tab_core.json` · `notification_tab_board.json` · `notification_tab_ecommerce.json` | 코어·게시판·이커머스 알림 설정 화면의 "비즈뿌리오" 탭 |
| `notification_row_footer.json` | 알림 목록 각 행 하단의 승인 여부·문자 설정 요약 |
| `notification_template_form_sections.json` | 알림 템플릿 편집 창의 알림톡·문자(SMS) 섹션 |
| `notification_template_form_footer_actions.json` | 그 편집 창 하단의 [저장] · [저장 후 검수 신청] 등 버튼 |
| `notification_log_result.json` | 발송 이력 화면의 결과 열 |

문자·알림톡 설정을 **알림 설정 화면 안에** 두는 것이 이 설계의 핵심입니다. 별도 화면으로
분리하면 운영자가 알림 하나를 완성하는 데 두 화면을 오가야 하고, 어느 쪽을 저장했는지 헷갈리게
됩니다.

같은 이유로 탭 조각이 셋(코어·게시판·이커머스)입니다 — 알림 설정 화면이 도메인마다 따로
있으므로 각각에 붙어야 합니다. **새 도메인이 알림 설정 화면을 갖게 되면 조각이 하나 더
필요합니다.**

대상 화면을 소유한 쪽이 그 자리를 없애면 조각은 오류 없이 사라집니다 — 코어·게시판·이커머스를
업그레이드한 뒤에는 일곱 자리를 눈으로 확인합니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 미들웨어 | 부착 대상(targets) | 우선순위 |
|---|---|---|
| `BizppurioWebhookIpWhitelist` | `api.plugins.sirsoft-message_bizppurio.webhook` | - |
<!-- @generated:middleware END -->

<!-- @intent START -->
하나뿐이고, 그것이 이 플러그인의 **유일한 보안 경계**입니다.

`BizppurioWebhookIpWhitelist` 는 `api.plugins.sirsoft-message_bizppurio.webhook` 라우트에만
붙어 발신 IP 를 검사합니다. webhook 은 외부 서비스가 부르는 경로라 로그인 인증을 쓸 수 없고,
그래서 발신자 검증이 IP 화이트리스트뿐입니다.

**webhook 라우트를 추가하거나 이름을 바꾸면 이 선언의 `targets` 도 함께 고쳐야 합니다.**
이름이 어긋나면 미들웨어가 붙지 않는데 정상 응답이 나가므로 오류도 로그도 남지 않습니다 —
위조된 통보로 발송 결과를 조작할 수 있는 상태가 조용히 만들어집니다.

미들웨어는 `getMiddleware()` 로 부착 대상을 스스로 선언합니다(self-gate). 커널 미들웨어 그룹을
직접 조작하거나 라우트 파일에 FQCN 을 붙이지 않습니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
없습니다. 발송은 서버가 외부 API 를 부르는 일이고, 결과는 나중에 webhook 으로 돌아와 이력에
기록됩니다 — 화면이 실시간으로 따라가야 할 사건이 아닙니다.

발송 진행 상황을 실시간으로 보여줘야 한다면 `balance.low` 같은 훅을 구독하는 쪽에서 자기
채널로 내보냅니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 스케줄 | 주기 | 설명 |
|---|---|---|
| `bizppurio:sync-template-status` | `everyThirtyMinutes` | 비즈뿌리오 알림톡 템플릿 검수 상태 동기화 |
<!-- @generated:schedules END -->

<!-- @intent START -->
하나뿐입니다. `bizppurio:sync-template-status` 가 **30분마다** 카카오 알림톡 템플릿의 검수
상태(승인·반려·중지·차단·휴면)를 비즈뿌리오에서 가져와 로컬(`bizppurio_templates`)에
반영합니다.

이 스케줄이 필요한 이유는 **검수 결과가 비동기이기 때문**입니다. 카카오가 승인을 알려 주는
통보 경로가 없어 주기적으로 물어봐야 하고, 승인 여부가 곧 발송 가능 여부이므로 이 동기화가
멈추면 승인이 났는데도 발송되지 않는 상태가 지속됩니다.

즉시 확인이 필요하면 관리자 화면의 [새로고침](`POST templates/{id}/sync`)으로 단건 조회할 수
있습니다. 스케줄러가 등록되지 않은 설치에서는 이 수동 경로가 유일한 갱신 수단입니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 알림 키 | 채널 |
|---|---|
| `bizppurio_balance_low` | `mail`, `database` |
<!-- @generated:notifications END -->

<!-- @intent START -->
하나뿐입니다. `bizppurio_balance_low` 는 지갑 잔액 부족·후불 한도 초과로 발송이 실패했을 때
**운영자에게** 보내는 알림입니다.

이 알림에는 순환 위험이 있습니다 — 잔액이 없어서 실패한 상황인데 이 통지를 문자·알림톡으로
보내면 그것도 함께 실패합니다. 그래서 채널은 `mail` + `database` 이며, 운영자에게 **다른
채널 병행**을 권합니다.

반복 발송을 막기 위해 채널별 쿨다운(기본 3600초)이 걸려 있습니다. 대량 발송이 한꺼번에
실패하면 실패 건마다 통지가 나가는 것을 막는 장치이며, 간격은 설정 파일의
`balance_low_notify_cooldown` 이 정합니다(설정 화면에는 노출되지 않습니다).

알림 본문의 변수는 `BalanceLowNotificationDataListener` 가 자기 훅
(`sirsoft-message_bizppurio.notification.extract_data`)으로 채웁니다.
<!-- @intent END -->
