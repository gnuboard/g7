# 개인정보 통지 이력 API

### GET /api/plugins/sirsoft-gdpr/admin/notice-log

인증: `auth:sanctum`, 권한: `permission:admin,sirsoft-gdpr.privacy.view`.
라우트명: `api.plugins.sirsoft-gdpr.admin.notice-log.index`.

| 요청 | 타입 | 기본값 | 제한 |
|---|---|---|---|
| page | integer | 1 | 1 이상 |
| per_page | integer | 20 | 1~100 |

| data.data[] 필드 | 타입 | 의미 |
|---|---|---|
| id | integer | 이력 ID |
| user_id | integer 또는 null | 수신 회원, 완전 삭제 후 null |
| status | string | queued 접수, sent 성공, failed 실패 |
| status_label | string | 현재 로케일의 상태 문구 |
| channels | string[] | 해당 이벤트의 채널 |
| channels_label | string | 화면용 채널 이름 목록(현재 언어, 쉼표 구분). 지금 없는 채널은 식별자 그대로 |
| is_test | boolean | 시험 발송 여부 |
| sent_at | string 또는 null | 사용자 시간대 기준 발송 시각 |
| created_at | string | 사용자 시간대 기준 이벤트 시각 |

`data.pagination`은 current_page, last_page, per_page, total, from, to를 반환합니다.
total은 수신 회원 수가 아니라 접수·채널 결과 이벤트의 합계입니다. 스냅샷 본문은 목록에서 제외합니다.

```http
GET /api/plugins/sirsoft-gdpr/admin/notice-log?page=1&per_page=20 HTTP/1.1
Authorization: Bearer {YOUR_TOKEN}
Accept: application/json
```

```json
{"success":true,"data":{"data":[],"pagination":{"current_page":1,"last_page":1,"per_page":20,"total":0,"from":null,"to":null}}}
```
