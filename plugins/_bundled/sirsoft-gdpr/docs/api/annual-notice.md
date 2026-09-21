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
