# 비즈뿌리오 메시지 발송 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 키 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `is_test_mode` | `boolean` | `true` | 검수 모드 |
| `bizppurio_id` | `string` | - | 비즈뿌리오 아이디 |
| `password` | `string` | - | 비밀번호 |
| `api_key` | `string` | - | API 키 |
| `sender_number` | `string` | - | 발신번호 |
| `sender_key` | `string` | - | 알림톡 발신프로필 키 |

기본값 파일: `config/settings/defaults.json` · 설정 화면 레이아웃: `resources/layouts/admin/plugin_settings.json`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
6개 항목이 두 무리입니다 — **검수/운영 전환 토글** 하나와 **비즈뿌리오 크리덴셜** 다섯.

`is_test_mode` 의 기본값이 `true` 인 것이 이 플러그인의 안전장치입니다. 설치 직후에는 실제
발송이 일어나지 않고, 운영자가 명시적으로 끄는 순간부터 **발송과 비용이 발생**합니다. 그
전환 시점에만 필수값 검증이 걸리는 것도 같은 이유입니다 —
`ValidateBizppurioSettingsListener` 가 `core.plugin_settings.update_rules` 로 운영 모드일
때만 아이디·비밀번호·API 키·발신번호를 요구합니다. 검수 모드에서는 비어 있어도 저장되어야
합니다.

크리덴셜 셋(`password` · `api_key` · `sender_key`)은 `frontend_schema` 에서 `expose: false`
+ `sensitive: true` 로 선언되어 **프론트엔드로 나가지 않습니다.** 관리자 설정 화면은 코어의
`/api/admin/plugins/{id}/settings` 로 서버에서 직접 조회하므로 `window.G7Config` 노출이
필요 없고, 그래서 전 필드가 `expose: false` 입니다.

**설정 화면에 없는 값이 하나 있습니다.** `balance_low_notify_cooldown`(기본 3600초)은
`defaults` 에만 있고 `getSettingsSchema()` 에도 `frontend_schema` 에도 없어 화면에서 편집할 수
없습니다. 잔액부족 알림의 반복을 막는 값이며, 조정하려면 설치본의 설정 파일을 고칩니다. 화면
입력을 추가하려면 스키마와 `frontend_schema` 양쪽에 함께 선언해야 합니다.

설정을 저장하면 `InvalidateTokenOnSettingsSaveListener` 가 비즈뿌리오 인증 토큰을 무효화
합니다 — 계정 정보를 바꿨는데 옛 토큰으로 계속 발송하는 것을 막는 장치입니다.
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 카테고리 | 이름 | 액션 | 라우트 키 |
|---|---|---|---|
| `messaging` | 메시지 발송 | `view`, `manage` | - |
<!-- @generated:permissions END -->

<!-- @intent START -->
`messaging` 하나에 `view` / `manage` 두 액션입니다.

| 권한 | 무엇을 할 수 있는가 |
|---|---|
| `sirsoft-message_bizppurio.messaging.view` | 발송 이력·알림톡 템플릿·발송 결과 조회 |
| `sirsoft-message_bizppurio.messaging.manage` | 템플릿 작성·검수 신청·신청/승인 취소·상태 동기화·삭제, 대체 SMS 설정 |

라우트 키가 없는 것은 이 플러그인의 관리 API 가 `admin` 미들웨어와 개별 권한 지정으로 보호
되기 때문입니다.

`manage` 는 **비용과 발송 중단에 직결**됩니다 — 승인 취소는 그 즉시 알림톡 발송을 멈추고,
템플릿 삭제는 되돌릴 수 없습니다. 넓게 부여하지 않습니다.

설정 변경(크리덴셜·검수 모드)은 이 권한이 아니라 코어의 플러그인 설정 권한이 관장합니다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 메뉴가 없습니다._
<!-- @generated:menus END -->

<!-- @intent START -->
등록하지 않습니다. 이 플러그인의 운영 UI 가 **다른 화면 안에** 있기 때문입니다 — 알림 설정
화면의 비즈뿌리오 탭, 알림 템플릿 편집 창, 발송 이력 화면.

문자·알림톡 설정을 별도 메뉴로 분리하면 운영자가 알림 하나를 완성하는 데 두 화면을 오가야
하고, 어느 쪽을 저장했는지 헷갈리게 됩니다.

전체 템플릿 상태를 한눈에 보는 화면은 **플러그인 설정 안**의 "알림 템플릿 관리" 탭에
있습니다. 설정 화면은 코어의 플러그인 목록에서 들어가는 공통 경로를 씁니다.
<!-- @intent END -->

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 파일 | URL prefix |
|---|---|---|
| `api` | `src/routes/api.php` | `/api/plugins/sirsoft-message_bizppurio/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
21개가 두 무리로 갈리며, **성격 차이가 큽니다.**

| 무리 | 경로 | 보호 |
|---|---|---|
| webhook | `POST /webhook` | **인증 없음** — 외부 서비스가 부르므로 `BizppurioWebhookIpWhitelist` 가 유일한 경계 |
| 관리자 | `admin/*` (템플릿 수명주기 · 알림톡 카테고리/발신프로필 조회 · 발송 결과 조회 · 리포트 URL · 토큰 점검 · 템플릿 준비 상태) | `auth:sanctum` + `admin` + 개별 권한 |

**webhook 라우트가 이 플러그인의 유일한 공개 경로**입니다. 라우트를 추가하거나 이름을 바꾸면
`getMiddleware()` 의 `targets` 도 함께 고쳐야 합니다 — 이름이 어긋나면 미들웨어가 붙지 않는데
정상 응답이 나가므로 오류도 로그도 남지 않고, 위조된 통보로 발송 결과를 조작할 수 있는 상태가
조용히 만들어집니다.

템플릿 수명주기 라우트가 많은 것은 상태 전이가 많기 때문입니다 — 작성·수정·검수 신청·신청
취소·승인 취소·해제·동기화·삭제가 각각 별도 엔드포인트입니다. 상태를 하나의 `PATCH` 로
합치지 않은 것은 각 전이의 권한·검증·부작용이 다르기 때문입니다(승인 취소는 발송을 즉시
멈춥니다).

라우트를 바꾼 뒤에는 라우트 캐시를 다시 굽습니다. 확장 라우트는 활성 상태인 확장의 것만
등록되고, 캐시에 없는 라우트는 예외도 경고도 없이 404 가 됩니다 — webhook 이 404 가 되면
발송 결과가 영영 기록되지 않습니다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

없음 — 코어만으로 동작합니다.

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

없음.
<!-- @generated:dependencies END -->

<!-- @intent START -->
manifest 상 양방향 모두 비어 있습니다. 코어만으로 동작하고, 이 플러그인을 요구하는 확장도
없습니다.

**실제로 맞물리는 확장이 셋** 있습니다 — 전부 훅 구독 또는 레이아웃 조각이라 manifest 의존이
아닙니다:

| 확장 | 무엇으로 | 없으면 |
|---|---|---|
| `sirsoft-ecommerce` | `notification.extract_data` 훅 구독 + 알림 설정 탭 조각 | 비회원 주문 문자 발송만 비고 나머지는 정상 |
| `sirsoft-board` | 알림 설정 탭 조각 | 게시판 알림에 비즈뿌리오 탭만 안 보임 |
| 코어 알림 시스템 | 채널 등록 · 알림 로그 훅 2종 · 설정 훅 2종 | 이것이 없으면 플러그인 자체가 성립하지 않음 |

의존으로 올리지 않은 판단은 맞습니다 — 이커머스나 게시판이 없어도 코어 알림을 문자로 보내는
기능은 그대로 동작합니다. 대신 그 대가로 **상대가 훅 이름이나 확장점을 바꾸면 예외 없이
조용히 끊깁니다.**

**새 도메인이 알림 설정 화면을 갖게 되면** 그 화면용 탭 조각이 하나 더 필요합니다. 조각이
없으면 그 도메인의 알림은 비즈뿌리오 채널을 설정할 수 없는데, 화면에 탭이 없을 뿐이라 오류가
나지 않습니다.
<!-- @intent END -->
