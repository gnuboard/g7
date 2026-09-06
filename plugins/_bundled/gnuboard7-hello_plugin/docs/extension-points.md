# Hello 플러그인 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 1종 / 호출 지점 1곳.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `gnuboard7-hello_plugin.log.written` | action | Hello 플러그인이 로그 파일에 기록을 남긴 직후 실행되는 액션 훅 | `src/Listeners/LogMemoCreatedListener.php:83` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
하나이며, **구독한 플러그인이 다시 발행하는** 형태를 보이기 위한 것입니다.

`LogMemoCreatedListener` 가 로그를 기록한 직후 `gnuboard7-hello_plugin.log.written` 을
발행합니다. 훅은 한 번 받고 끝나는 것이 아니라 연쇄를 이루며, 또 다른 확장이 이 플러그인의
동작에 반응할 수 있습니다.

`getHooks()` 선언이 있어 표에 유형과 설명이 함께 실립니다 — 발행 훅을 선언하면 구독하려는
쪽에 계약이 드러납니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `gnuboard7-hello_module.memo.created` | action (미선언) | `LogMemoCreatedListener` | `onMemoCreated` | 10 |
| `gnuboard7-hello_module.memo.title.filter` | filter | `FilterMemoTitleListener` | `prependHelloPrefix` | 10 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
둘이며 **두 종류를 하나씩** 보여줍니다.

| 훅 | 종류 | 무엇을 보여주는가 |
|---|---|---|
| `gnuboard7-hello_module.memo.created` | Action | 흐름에 부가 작업을 붙인다. 반환값은 흐름에 영향을 주지 않는다 |
| `gnuboard7-hello_module.memo.title.filter` | Filter | 흐름 중간의 값을 가공해 **반환**한다. 반환값이 다시 흐름에 들어간다 |

**Filter 구독에는 `'type' => 'filter'` 선언이 반드시 필요합니다.** 빠뜨리면 코어가 Action 으로
취급해 반환값을 버립니다 — 리스너는 정상 실행되고 오류도 없는데 가공만 반영되지 않습니다.

Filter 쪽 훅은 **학습용 모듈이 실제로 발행하지 않습니다.** 리스너 docblock 이 "발행한다고
가정하고" 라고 밝히고 있으며, 그 자체가 학습 포인트입니다 — 훅이 발행되지 않아도 리스너 등록은
유효하고, 나중에 발행 지점이 생기면 그때부터 자동으로 호출됩니다. 구독은 발행자에게 아무 부담을
주지 않으므로 확장이 서로를 몰라도 됩니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `FilterMemoTitleListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/FilterMemoTitleListener.php` |
| `LogMemoCreatedListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/LogMemoCreatedListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
둘 다 `HookListenerInterface` 를 구현하고 `getSubscribedHooks()` 로 자기 구독을 선언합니다
(명시 등록).

`LogMemoCreatedListener` 는 **설정을 먼저 확인**한 뒤 동작합니다 —
`log_enabled` 가 `false` 면 조용히 건너뜁니다. 부가 동작을 설정 뒤에 두는 것이 규약이며, 설정
없이 무조건 동작하면 그 확장을 설치한 사이트가 멈출 방법이 없습니다.

`FilterMemoTitleListener` 는 `'type' => 'filter'` 를 선언합니다. 이 선언이 없으면 반환값이
버려집니다.

두 리스너 모두 데이터에 직접 접근하지 않습니다. 접근이 필요하면 `Model::query()` 나
`DB::table()` 이 아니라 Repository 인터페이스를 주입받습니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_레이아웃 확장이 없습니다._
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
없습니다. 이 샘플은 다른 화면에 조각을 주입하지 않습니다.

플러그인이 화면에 관여하는 통로는 둘뿐입니다 — 설정 화면(`plugin_settings.json`)과
`layout_extensions`(다른 확장·템플릿 화면에 끼워 넣는 조각). **완전한 페이지 레이아웃은 등록할
수 없습니다** — 페이지 소유권은 모듈·템플릿에 있고, 경로를 다투면 어느 쪽이 이기는지가 설치
순서에 좌우됩니다.

주입 예시가 필요하면 실제로 그렇게 하는 플러그인(마케팅의 회원가입 동의 항목, 편집기의 본문
입력 자리)의 문서를 참고합니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 미들웨어가 없습니다._
<!-- @generated:middleware END -->

<!-- @intent START -->
없습니다. 이 샘플은 요청 흐름에 개입하지 않습니다.

플러그인이 미들웨어를 등록할 때는 `getMiddleware()` 로 **부착 대상(targets)을 스스로 선언**
합니다(self-gate). 커널 미들웨어 그룹을 직접 조작하거나 라우트 파일에 FQCN 을 붙이지 않습니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
없습니다. 샘플에 넣으면 훅을 보러 온 사람이 읽어야 할 코드가 늘어납니다.

채널을 등록할 때는 `getChannels()` 를 오버라이드합니다 — `routes/channels.php` 에 하드코딩
하지 않습니다. 채널명에는 확장 프리픽스(`plugin.{id}.*`)를 붙입니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 스케줄이 없습니다._
<!-- @generated:schedules END -->

<!-- @intent START -->
없습니다. 샘플에는 시간 축 동작이 없습니다.

스케줄 선언 형태(`command` · `schedule` · `description` · `enabled_config`)는 실제로 스케줄을
쓰는 확장의 문서를 참고합니다. `schedule` 키를 빠뜨리면 코어 등록부가 그 항목을 건너뛰는데
예외도 경고도 남지 않습니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 알림 정의가 없습니다._
<!-- @generated:notifications END -->

<!-- @intent START -->
없습니다. 알림은 수신자 해석·채널 게이트·템플릿까지 함께 필요해 "하나씩만" 원칙으로 담기
어렵습니다.

알림이 필요한 예시는 실제로 알림을 발송하는 확장의 문서를 참고합니다. 코어
`GenericNotification` 범용 클래스 하나로 처리하며 개별 Notification 클래스를 만들지 않습니다.
<!-- @intent END -->
