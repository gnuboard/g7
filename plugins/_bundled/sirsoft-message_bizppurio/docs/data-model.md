# 비즈뿌리오 메시지 발송 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 모델 | 테이블 | fillable | 관계 | 특성 |
|---|---|---|---|---|
| `BizppurioDispatch` | `bizppurio_dispatches` | 20 | user→User | - |
| `BizppurioTemplate` | `bizppurio_templates` | 15 | - | - |
<!-- @generated:models END -->

<!-- @intent START -->
둘의 역할이 **발송 기록**과 **템플릿 상태**로 갈립니다.

- **`BizppurioDispatch`** (`bizppurio_dispatches`) — 발송 한 건의 기록입니다. fillable 이 20개로
  큰 이유는 **발송 시점의 상태를 그대로 남기기** 때문입니다: 대상·채널·본문·비즈뿌리오 응답·
  webhook 으로 나중에 채워지는 결과 코드와 사유. 발송과 결과가 시점이 다르므로 한 행이 두
  번에 걸쳐 완성됩니다.
- **`BizppurioTemplate`** (`bizppurio_templates`) — 알림별 알림톡 템플릿과 그 검수 상태입니다.
  **카카오 승인 시점의 내용이 여기 박제**되며, 발송할 때마다 카카오를 조회하지 않습니다.
  문자(SMS) 본문도 같은 행에 함께 있습니다 — 대체 SMS 와 SMS 단독이 같은 본문을 공유하기
  때문입니다.

`BizppurioTemplate` 에 관계가 없는 것은 **알림 종류를 문자열 키로 참조**하기 때문입니다. 코어와
게시판·이커머스가 각자 알림을 정의하므로 외래키로 묶을 단일 테이블이 없고, 알림이 사라지면 그
템플릿 행은 참조 대상 없이 남습니다.

`BizppurioDispatch` 의 `user→User` 관계는 회원 발송에만 채워집니다 — **비회원 발송은
`user_id` 가 없고** 주문 시 입력한 연락처만 남습니다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 테이블 | 모델 |
|---|---|
| `bizppurio_dispatches` | `BizppurioDispatch` |
| `bizppurio_templates` | `BizppurioTemplate` |
<!-- @generated:tables END -->

<!-- @intent START -->
둘 다 `bizppurio_` 접두사를 갖습니다.

`bizppurio_dispatches` 는 **계속 쌓이는 로그성 테이블**입니다. 발송량에 비례해 늘어나므로 조회는
반드시 페이지네이션을 거쳐야 하고, 목록에 본문 같은 큰 컬럼을 싣지 않습니다.

`bizppurio_templates` 는 알림 종류 수만큼만 존재하는 **설정성 테이블**입니다. 알림 하나에 행
하나이므로 크기가 데이터 증가에 비례하지 않습니다.

발송 기록은 코어 알림 로그(`notification_logs`)와 **별개**이며,
`LinkNotificationLogListener` 가 둘을 연결합니다. 두 로그가 따로 있는 이유는 코어 로그가
"알림이 발송 요청되었다" 를, 이쪽이 "그 요청이 비즈뿌리오에서 어떻게 끝났다" 를 담기 때문입니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
마이그레이션 2개.

| 파일 | 생성 테이블 | 변경 테이블 | down() |
|---|---|---|---|
| `2026_07_13_000001_create_bizppurio_dispatches_table.php` | `bizppurio_dispatches` | `bizppurio_dispatches` | ✅ |
| `2026_07_13_000002_create_bizppurio_templates_table.php` | `bizppurio_templates` | `bizppurio_templates` | ✅ |
<!-- @generated:migrations END -->

<!-- @intent START -->
2개이며 둘 다 초기 테이블 생성입니다 — 아직 스키마 변경 이력이 없는 젊은 확장입니다.

발송 기록 테이블에 컬럼을 더할 때는 **로그성 테이블이라는 점**을 염두에 둡니다. 이미 쌓인 행에
기본값을 채우는 백필은 행 수에 비례하므로, `chunkById()` 로 순회하고 상한 없는 `get()` 을
쓰지 않습니다(`chunk()` 계열은 OFFSET 기반이라 갱신된 행이 필터에서 이탈한 만큼 커서가 밀려
미처리 행을 조용히 건너뜁니다).

새 컬럼을 더할 때 초기 `create_*` 파일을 고치지 않습니다 — 이미 설치된 사이트는 그 파일을
다시 실행하지 않으므로 반영되지 않으며, 기존 행을 손봐야 하는 변경은 `upgrades/` 의 업그레이드
스텝 백필이 함께 필요합니다. 한국어 `comment` 와 `down()` 은 필수입니다.
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| Enum | backing | case 수 | case |
|---|---|---|---|
| `BizppurioTemplateStatus` | `string` | 7 | `draft`, `requested`, `approved`, `rejected`, `stopped`, `blocked`, `dormant` |
| `DispatchChannel` | `string` | 3 | `sms`, `lms`, `alimtalk` |
| `DispatchSource` | `string` | 3 | `auto`, `manual`, `bulk` |
| `DispatchStatus` | `string` | 4 | `pending`, `sent`, `success`, `failed` |
| `ResultCategory` | `string` | 4 | `success`, `retry`, `permanent_failure`, `balance_low` |
<!-- @generated:enums END -->

<!-- @intent START -->
PHP Enum 은 없지만 **닫힌 어휘가 셋** 있습니다.

| 어휘 | 값 | SSoT |
|---|---|---|
| 템플릿 상태 | 미작성·작성중·검수중·승인·반려·중지·차단·휴면 | 비즈뿌리오/카카오가 정의 — 이쪽에서 만들 수 없습니다 |
| 결과 코드 분류 | 성공 / 재시도 / 잔액 부족 / 영구 실패 | 이 플러그인이 정의 |
| 결과 코드 자체 | `1000` `9070` `7436` `4400` … | 비즈뿌리오/카카오가 정의, 사유 문구는 `lang/{ko,en}/result_codes.php` |

앞의 둘은 **외부가 정하는 어휘**라 Enum 으로 굳히면 상대가 값을 추가할 때마다 이쪽이 깨집니다.
그래서 문자열로 다루고, 모르는 값이 오면 코드만 그대로 표시합니다 — "알 수 없는 코드" 로
뭉뚱그리면 운영자가 비즈뿌리오에 문의할 근거를 잃습니다.

가운데 분류만 이 플러그인이 정하므로 **여기가 확장 지점**입니다. 새 코드가 어느 분류에
들어가는지 판정이 바뀌면 재시도 여부와 관리자 알림 발화가 함께 바뀝니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 클래스 | 종류 | 설명 |
|---|---|---|
| `BizppurioDispatchRepository` | 구현 | 비즈뿌리오 발송 이력 Repository 구현체. |
| `BizppurioDispatchRepositoryInterface` | 인터페이스 | 비즈뿌리오 발송 이력 Repository 계약. |
| `BizppurioTemplateRepository` | 구현 | 비즈뿌리오 알림 템플릿 Repository 구현체 (#597). |
| `BizppurioTemplateRepositoryInterface` | 인터페이스 | 비즈뿌리오 알림 템플릿 Repository 인터페이스 (#597). |
<!-- @generated:repositories END -->

<!-- @intent START -->
발송 기록과 템플릿 각각에 Repository 가 있고, 서비스는 **인터페이스만 주입**받습니다(구체
클래스 타입힌트 금지).

발송 기록 Repository 에서 특히 걸리는 것 둘:

- **목록 조회는 페이지네이션과 컬럼 프루닝.** 로그성 테이블이라 발송량에 비례해 늘어나고,
  본문 컬럼이 큽니다. 목록이 실제로 그리는 것만 싣습니다.
- **결과 갱신은 발송 기록을 특정해서.** webhook 통보는 비즈뿌리오가 준 식별자로 해당 발송을
  찾아 갱신합니다 — 찾지 못한 통보를 조용히 버리지 않고 그 사실이 남아야 합니다.

리스너는 Repository 를 거쳐 데이터에 접근합니다. `Model::query()` · `DB::table()` ·
`$row->save()` 를 직접 부르지 않습니다.
<!-- @intent END -->
