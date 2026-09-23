# 개인정보 통지 시험 발송 API

### POST /api/plugins/sirsoft-gdpr/admin/annual-notice/test

인증: `auth:sanctum`, 권한: `permission:admin,sirsoft-gdpr.privacy.update`.
라우트명: `api.plugins.sirsoft-gdpr.admin.annual-notice.test`.
현재 인증된 활성 회원 자신에게 저장된 채널로 시험 발송을 접수합니다. 수신자 ID를 입력받지 않습니다.
시험 발송은 자동 통지 토글·가입일과 독립이며 정기 통지 이력 계산에서 제외됩니다.
분당 6회 제한은 시험 발송 전용 접두사로 다른 API의 제한과 분리됩니다.

요청 본문은 빈 객체입니다. 응답 `data.attempt_id`는 UUID 접수 식별자이며 성공 응답은 전달 완료가 아닌 접수 완료입니다.
저장된 채널이 비었거나 활성 회원이 아니면 422, 권한이 없으면 403, 미인증은 401입니다.

```http
POST /api/plugins/sirsoft-gdpr/admin/annual-notice/test HTTP/1.1
Authorization: Bearer {YOUR_TOKEN}
Accept: application/json
Content-Type: application/json

{}
```

```json
{"success":true,"message":"시험 발송을 접수했습니다. 이력에서 결과를 확인하세요.","data":{"attempt_id":"00000000-0000-4000-8000-000000000001"}}
```

### GET /api/plugins/sirsoft-gdpr/admin/annual-notice/preview

인증: `auth:sanctum`, 권한: `permission:admin,sirsoft-gdpr.privacy.view`.
라우트명: `api.plugins.sirsoft-gdpr.admin.annual-notice.preview`.
이번 주기 연간 통지 대상 회원 수를 돌려줍니다. 통지 탭의 「대상 N명」 배지가 씁니다. **자동 통지가 꺼져 있어도 셉니다** — 켜기 전에 규모를 확인하는 자리입니다.
대상은 발송 판정과 같습니다: 활성 회원 · 가입 1년 경과 · 설정된 채널 중 하나라도 최근 1년 안에 발송 완료 기록이 없고 결과를 기다리는 접수도 없는 회원. 한 번 실행에 보내는 수는 발송 상한(`batch_size`)으로 잘리므로 이 숫자가 한 번에 다 나가지는 않습니다.

| 필드 | 타입 | 용도 |
| --- | --- | --- |
| enabled | boolean | 자동 통지가 켜져 있는지 |
| count | integer | 이번 주기 대상 회원 수 |
| can_view_targets | boolean | 요청 관리자가 명단을 볼 권한(`sirsoft-gdpr.privacy.view` + `core.users.read`)이 있는지 — 없으면 화면이 「더보기」를 그리지 않는다 |

```http
GET /api/plugins/sirsoft-gdpr/admin/annual-notice/preview HTTP/1.1
Authorization: Bearer {YOUR_TOKEN}
Accept: application/json
```

```json
{"success":true,"message":"성공적으로 처리되었습니다.","data":{"enabled":false,"count":128,"can_view_targets":true}}
```

권한이 없으면 403, 미인증은 401입니다.

### GET /api/plugins/sirsoft-gdpr/admin/annual-notice/targets

인증: `auth:sanctum`, 권한: `permission:admin,sirsoft-gdpr.privacy.view|core.users.read`(둘 다 필요).
라우트명: `api.plugins.sirsoft-gdpr.admin.annual-notice.targets`.
이번 주기 연간 통지 대상 명단을 한 페이지씩 돌려줍니다. 통지 탭의 「더보기」 모달이 무한스크롤로 부릅니다. 명단에 회원 이름·이메일이 그대로 나오므로 회원 읽기 권한까지 요구합니다.
행 형태는 코어의 개인정보 조치 대상 목록과 같습니다(`data[]`: `id`·`title`(이름)·`subtitle`(이메일)·`context`·`date`(가입일, 사용자 시간대 `Y-m-d`)·`url`(회원 상세) · `columns` · `pagination` 표준 메타). 회원 ID 순 — 발송 루프와 같은 순서입니다.

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | 1 이상 | 페이지 번호 (기본 1) |
| per_page | query | integer | 아니오 | 1~100 | 페이지 크기 (기본 20) |

```http
GET /api/plugins/sirsoft-gdpr/admin/annual-notice/targets?page=1&per_page=20 HTTP/1.1
Authorization: Bearer {YOUR_TOKEN}
Accept: application/json
```

```json
{"success":true,"message":"성공적으로 처리되었습니다.","data":{"data":[{"id":7,"title":"홍길동","subtitle":"hong@example.com","context":null,"date":"2024-03-02","url":"/admin/users/9f0c1b2e-4a57-4b8e-9d0a-6f3e2c1d0a11"}],"columns":{"title":"이름","subtitle":"이메일","date":"가입일"},"pagination":{"current_page":1,"per_page":20,"from":1,"to":1,"has_more_pages":false,"last_page":1,"total":1}}}
```

`page`·`per_page` 가 범위를 벗어나면 422, 권한이 없으면 403, 미인증은 401입니다. 두 권한은 적용 범위가 **전체**여야 합니다 — 회원 조회를 「본인만」·「같은 역할만」으로 위임받은 관리자는 건수만 보고(`can_view_targets=false`) 이 API 는 403 입니다(코어 `PrivacyRetentionManager::canViewTargetList()` 판정).
