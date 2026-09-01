# Hello 모듈 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 1종 / 호출 지점 1곳. 이 중 1종은 `getHooks()` 선언에 없어 소스에서 자동 감지한 것입니다 — 선언에 추가하면 유형과 설명이 함께 실립니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `gnuboard7-hello_module.memo.created` | action | — | `src/Services/MemoService.php:62` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
하나뿐입니다. 실제 모듈이라면 도메인마다 `before_*` → `filter_*_data` → `after_*` 3단을
두지만, 샘플에서는 **훅이 무엇이고 어떻게 발행하는가**만 보이면 되므로 하나로 줄였습니다.

`MemoService::create()` 가 저장 직후 이 액션을 발행합니다. 발행 지점이 컨트롤러가 아니라
Service 인 것이 규약입니다 — 컨트롤러에서 발행하면 같은 로직을 다른 경로(커맨드·시더·다른
서비스)에서 부를 때 훅이 발화하지 않습니다.

`getHooks()` 선언에 없어 소스에서 자동 감지된 상태입니다. 선언에 추가하면 유형과 설명이 표에
함께 실리며, 실제 모듈에서는 발행 훅을 선언하는 편이 구독하는 쪽에 계약을 드러냅니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `gnuboard7-hello_module.memo.created` | action (미선언) | `LogMemoCreatedListener` | `onMemoCreated` | 10 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
자기가 발행한 훅 하나를 자기가 구독합니다. 실제로는 다른 확장이 구독하는 것이 정상이지만,
**샘플 하나만 설치해도 훅 흐름이 눈에 보이도록** 리스너를 같이 넣었습니다.

`gnuboard7-hello_plugin` 을 함께 설치하면 같은 훅을 **바깥에서 구독하는** 모습을 볼 수
있습니다 — 그쪽이 확장 시스템의 실제 사용 형태입니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `LogMemoCreatedListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/LogMemoCreatedListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
`LogMemoCreatedListener` 하나이며 `HookListenerInterface` 를 구현하고
`getSubscribedHooks()` 로 자기 구독을 선언합니다(명시 등록).

하는 일은 로그 한 줄이지만, 그 자리가 중요합니다 — **부가 작업은 Service 안이 아니라 리스너로
뺀다**는 규약의 본보기입니다. Service 에 로그·알림을 쌓으면 그 Service 를 다른 맥락에서
재사용할 수 없습니다.

리스너에서 `Model::query()` · `DB::table()` · `$row->save()` 를 직접 부르지 않습니다 — 데이터
접근이 필요하면 Repository 인터페이스를 주입받습니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_레이아웃 확장이 없습니다._
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
없습니다. 이 샘플은 다른 확장의 화면에 조각을 주입하지 않습니다.

주입 예시가 필요하면 실제로 그렇게 하는 확장(이커머스의 관리자 대시보드 위젯, 마케팅의 회원가입
동의 항목)의 문서를 참고합니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 미들웨어가 없습니다._
<!-- @generated:middleware END -->

<!-- @intent START -->
없습니다. 코어가 제공하는 인증 미들웨어만 씁니다.

샘플에 미들웨어를 넣으면 계층 구조를 보러 온 사람이 읽어야 할 코드가 늘어납니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
없습니다. 같은 이유로 두지 않았습니다.

실시간이 필요하면 이 모듈이 발행하는 `memo.created` 를 구독해 소비하는 쪽에서
`HookManager::broadcast()` 로 자기 채널에 내보내는 것이 방향입니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 스케줄이 없습니다._
<!-- @generated:schedules END -->

<!-- @intent START -->
없습니다. 샘플에는 시간 축 동작이 없습니다.

스케줄 선언 형태(`command` · `schedule` · `description` · `enabled_config`)는 실제로 스케줄을
쓰는 확장의 문서를 참고합니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 알림 정의가 없습니다._
<!-- @generated:notifications END -->

<!-- @intent START -->
없습니다. 알림은 수신자 해석·채널 게이트·템플릿까지 함께 필요해 "하나씩만" 원칙으로 담기
어렵습니다.

알림이 필요한 예시는 실제로 알림을 발송하는 확장의 문서를 참고합니다.
<!-- @intent END -->
