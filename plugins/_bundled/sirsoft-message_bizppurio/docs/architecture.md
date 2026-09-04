# 비즈뿌리오 메시지 발송 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
이 플러그인은 **채널 구현체**입니다 — 코어 알림 시스템이 정의한 "알림을 어떤 수단으로
내보내는가" 의 자리를 채웁니다. 알림 자체(무엇을 언제 누구에게)는 코어와 각 도메인 모듈이
소유하며, 이 경계 덕분에 플러그인을 삭제해도 알림과 그 이력은 남습니다.

그 위에 이 도메인 고유의 제약 셋이 설계를 결정했습니다.

- **결과가 비동기로 돌아온다.** 비즈뿌리오는 발송 API 응답이 아니라 webhook 통보로 최종
  결과를 줍니다. 그래서 발송 기록(`bizppurio_dispatches`)이 먼저 생기고 결과가 나중에 채워지는
  2단 구조이며, webhook 이 등록되지 않은 사이트에서는 그 칸이 영영 비어 있습니다 — 오류가
  아니라 **결과 미상**이라는 상태입니다.
- **외부 승인이 발송 조건이다.** 알림톡은 카카오가 승인한 템플릿으로만 보낼 수 있습니다.
  승인 시점의 내용을 로컬(`bizppurio_templates`)에 박제해 두고 발송하며, 발송 경로에서 외부를
  조회하지 않습니다 — 조회를 넣으면 그 서비스의 지연이 곧 발송 지연이 됩니다. 대신 승인 상태
  변화를 30분 주기 스케줄로 따라잡습니다.
- **실패에 종류가 있다.** 결과 코드를 성공 / 재시도(일시 오류) / 잔액 부족 / 영구 실패 넷으로
  분류합니다. 잔액 부족은 재시도해도 소용없고 **운영자 개입이 필요한** 유일한 분류라, 이것만
  관리자 알림과 확장 훅(`balance.low`)을 갖습니다.

**의도적으로 하지 않는 것**: 알림 정의·수신자 해석·자체 관리 메뉴. UI 는 다른 화면에 끼워 넣는
조각 7개와 플러그인 설정 화면 하나뿐입니다 — 문자·알림톡 설정이 알림 설정 화면 **안에** 있어야
운영자가 한자리에서 알림을 완성할 수 있기 때문입니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```
[등록]  RegisterNotificationChannelsListener → 코어 알림 시스템에 문자·알림톡 채널 등록
        SeedChannelTemplatesListener        → 그 채널의 템플릿 자리 시드

[발송]  코어 알림 발화 → 채널 발송 작업
            │
            ▼
        Services (발송 조립 · 알림톡/문자 선택 · 대체발송 판정)
            │  비즈뿌리오 인증 토큰 (설정 저장 시 InvalidateTokenOnSettingsSaveListener 가 무효화)
            ▼
        비즈뿌리오 발송 API  →  bizppurio_dispatches 적재

[결과]  POST /webhook
            │  BizppurioWebhookIpWhitelist (발신 IP 검사 — 인증이 없는 공개 경로다)
            ▼
        WebhookReportService
            │  결과 코드 4분류 → 발송 기록 갱신
            │  잔액 부족이면 balance.low 발행 + 관리자 알림 (채널별 쿨다운)
            ▼
        LinkNotificationLogListener → 코어 알림 로그와 발송 기록 연결

[템플릿] Admin API (작성·검수신청·취소·승인취소·동기화·삭제)
            │
            ▼
        bizppurio_templates  ←  bizppurio:sync-template-status (30분)
```

리스너 7종이 각각 다른 이음매를 맡습니다 — 채널 등록 2 · 결과 연결 1 · 설정 반응 2(토큰
무효화 · 운영 모드 필수값 검증) · 데이터 주입 2(비회원 연락처 · 잔액부족 알림 데이터). 하나가
빠지면 그 이음매만 조용히 끊기고 나머지는 정상 동작하므로, 리스너를 지우거나 이름을 바꿀 때는
그 이음매가 무엇이었는지 먼저 확인합니다.

`ValidateBizppurioSettingsListener` 만 성격이 다릅니다 — `core.plugin_settings.update_rules`
필터로 **운영 모드로 전환할 때만** 필수값(아이디·비밀번호·API 키·발신번호) 검증을 추가합니다.
검수 모드에서는 그 값들이 비어 있어도 저장되어야 하기 때문입니다.
<!-- @intent END -->

## 디렉토리

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `plugin.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `plugin.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Controllers/` | 컨트롤러 | API 표면 변경 시 `api:docgen` 재실행 |
| `src/Http/Requests/` | FormRequest (검증 SSoT) | 검증 규칙은 Service 가 아니라 여기에 둔다 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Repositories/` | 데이터 접근 | 목록 쿼리는 컬럼 프루닝·정렬 화이트리스트 확인 |
| `src/Models/` | Eloquent 모델 | 스키마 변경 시 마이그레이션 + 업그레이드 스텝 동반 |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/Enums/` | 상태·타입·분류 | 문자열 리터럴 대신 Enum 을 SSoT 로 둔다 |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `database/migrations/` | 마이그레이션 | 한국어 comment + `down()` 필수, 기설치본은 업그레이드 스텝으로 백필 |
| `resources/layouts/` | 레이아웃 JSON | `php artisan plugin:update sirsoft-message_bizppurio --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-message_bizppurio --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-message_bizppurio --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan plugin:update sirsoft-message_bizppurio --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->
