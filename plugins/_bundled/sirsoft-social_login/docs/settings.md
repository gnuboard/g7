# 소셜 로그인 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 키 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `email_conflict_policy` | `string` | `verify_link` | 같은 이메일의 기존 회원 |
| `extra_info_mode` | `string` | `when_missing` | 가입 추가 정보 화면 |
| `two_factor_enabled` | `boolean` | `true` | 소셜 로그인에도 2단계 인증 |
| `allow_admin_social_login` | `boolean` | `false` | 관리자 소셜 로그인 허용 |
| `allow_unlink_last_method` | `boolean` | `false` | 마지막 로그인 수단 해제 허용 |
| `webhook_unlink_policy` | `string` | `unlink_only` | 제공자 연결 해제 알림 처리 |
| `revoke_on_unlink` | `boolean` | `true` | 해제 때 제공자 권한 회수 |
| `profile_raw_retention_days` | `integer` | `14` | 원본 프로필 보존(일) |
| `attempt_log_retention_days` | `integer` | `90` | 시도 기록 보존(일) |
| `webhook_event_retention_days` | `integer` | `90` | 웹훅 기록 보존(일) |
| `signup_surge_threshold_per_hour` | `integer` | `100` | 가입 급증 알림 기준(시간당) |
| `provider_failure_streak_threshold` | `integer` | `10` | 제공자 연속 실패 알림 기준 |
| `pending_signup_ttl_minutes` | `integer` | `30` | 가입 대기 유효 시간(분) |
| `state_ttl_minutes` | `integer` | `10` | 로그인 왕복 유효 시간(분) |
| `custom_provider_limit` | `integer` | `10` | 커스텀 제공자 최대 수 |

기본값 파일: `config/settings/defaults.json` · 설정 화면 레이아웃: `resources/layouts/admin/plugin_settings.g7.html`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
설정은 사이트 전체 정책만 둡니다. 제공자별 자격증명은 제공자 테이블에 암호화해 두며, 어느 값도 공개 설정(frontend_schema)에 내보내지 않습니다. 범위는 `ValidateSettingsListener::INTEGER_RANGES` 가 SSoT 입니다.
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 카테고리 | 이름 | 액션 | 라우트 키 |
|---|---|---|---|
| `settings` | 소셜 로그인 설정 | `view`, `update` | - |
| `accounts` | 회원 소셜 연결 | `view`, `unlink` | - |
<!-- @generated:permissions END -->

<!-- @intent START -->
설정 화면 자체는 코어 `core.plugins.update` 권한으로 열리고 정책 탭 저장도 코어 설정 API 라 같은 권한을 따릅니다. 이 플러그인의 권한 4종은 제공자 카드와 진단 통계 조회(`settings.view`), 제공자 변경과 연결 테스트(`settings.update`), 관리자 회원 상세 탭과 목록 가입경로 필터와 「소셜 연결」 열(`accounts.view`), 강제 해제(`accounts.unlink`)에 씁니다. `settings.update` 가 없으면 제공자 카드는 읽기 전용 안내와 함께 편집 단추가 꺼집니다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 구분 | slug | 이름 | URL | 하위 |
|---|---|---|---|---|
| 관리자 | `sirsoft-social_login` | 소셜 로그인 | `/admin/plugins/sirsoft-social_login/diagnostics` | 2개 |
<!-- @generated:menus END -->

<!-- @intent START -->
관리자 메뉴는 부모 「소셜 로그인」(진단 화면으로 간다)과 자식 「진단」 「통계」 셋입니다. 설정 화면은 코어 「플러그인 관리 → 설정」 자동 경로라 메뉴를 두지 않습니다. 코어가 설치와 업데이트 때 메뉴를 맞추고, 활성화 때는 선언에서 빠진 옛 메뉴를 거둡니다. 메뉴 동기화가 메뉴별 권한 키를 받지 않으므로 권한은 화면 라우트(`routes.json` 의 `settings.view`)와 API 미들웨어가 집행합니다.
<!-- @intent END -->

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 파일 | URL prefix |
|---|---|---|
| `api` | `src/routes/api.php` | `/api/plugins/sirsoft-social_login/...` |
| `web` | `src/routes/web.php` | `/plugins/sirsoft-social_login/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
웹 라우트는 브라우저가 오가는 주소라 JSON 이 아니라 302 와 단독 HTML 로 답합니다. 콜백과 모의 엔드포인트는 CSRF 면제이고, 콜백의 위조 방어는 1회성 state 가 맡습니다. 제공자 웹훅 4개도 CSRF 면제이며 위조 방어는 제공자별 서명과 키 대조(드라이버 `verifyWebhook`)가 맡습니다. 응답은 제공자 규약을 따르므로 JSON 봉투를 쓰지 않습니다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

없음 — 코어만으로 동작합니다.

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

없음.
<!-- @generated:dependencies END -->

<!-- @intent START -->
다른 확장에 의존하지 않습니다. 코어 7.2.0 의 로그인 인계(`POST /api/auth/login/handoff`)와 비밀번호 없는 회원을 전제로 합니다.
<!-- @intent END -->
