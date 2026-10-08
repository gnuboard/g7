# 개인정보 연간 통지

플러그인 설정의 개인정보 연간 통지에서 자동 발송을 켜고 채널·실행당 상한·다국어 서문을 설정합니다. 기본값은 비활성, 이메일과 사이트 알림, 500명이며 상한 범위는 1~1,000명입니다. 개인정보처리방침에 운영 방식을 먼저 안내하고 실제 수집 항목·목적·보유 기간과 통지문을 맞추세요.

`sirsoft-gdpr:send-annual-notices --scheduled`가 매일 실행됩니다. 커맨드 자체도 비활성 설정을 재확인합니다. `UserStatus::Active`인 가입 1년 경과 회원만 조회하며, 채널별 최근 1년 성공 이력과 처리 중 접수를 제외합니다. 실패 채널은 다음 실행에서 재시도하고 이미 성공한 채널은 재발송하지 않습니다. 메모리에는 회원 100명 단위로 읽으며 실행당 접수 회원 수를 설정값으로 제한합니다.

접수는 회원 행 잠금 아래에서 기록한 뒤 `sirsoft-gdpr.privacy.annual_notice_due` 훅으로 코어 알림 큐에 전달합니다. 큐 워커가 필요하며 접수만으로 성공을 표시하지 않습니다. `gdpr_privacy_notice_logs`는 queued 접수와 sent/failed 채널 결과를 각각 추가합니다. 이력의 행 수는 회원 수가 아닌 이벤트 수입니다. 워커 실행 시 채널·템플릿이 비활성이거나 준비되지 않았으면 failed로 닫아 복구 후 재선정합니다. 큐 처리 자체가 중단된 접수는 코어 큐 실패 내역에서 원 작업을 재시도해야 합니다. 외부 메일 전송과 DB 기록 사이 프로세스 장애까지 정확히 한 번 전달을 보장하지 않습니다.

통지 카드의 「이번 주기 대상 N명」은 발송 판정과 같은 조건(활성 · 가입 1년 경과 · 설정 채널 중 하나라도 최근 1년 성공 이력과 처리 중 접수가 없음)으로 센 수이며, **자동 발송이 꺼져 있어도** 보입니다. 한 번 실행에 보내는 수는 발송 상한으로 잘리므로 이 수가 한 번에 다 나가지는 않습니다. 옆 「대상 보기」는 그 회원 명단(이름·이메일·가입일, 회원 상세 새 탭 링크)을 무한 스크롤 모달로 엽니다(대상이 0명이어도 버튼은 보이고, 창은 빈 명단을 안내합니다). 명단에는 이름·이메일이 그대로 나오므로 `sirsoft-gdpr.privacy.view` 에 더해 `core.users.read` 가 있어야 보입니다(API: `GET …/admin/annual-notice/preview` · `…/targets`). 행 모양은 코어의 개인정보 조치 대상 명단과 같습니다 — [개인정보 보유기간 처리 가이드](../../../../docs/backend/privacy-retention.md) 참조.

「나에게 시험 발송」은 저장된 설정으로 현재 인증된 관리자 자신에게만 보냅니다. 기본 토글이 꺼져 있거나 가입 후 1년이 지나지 않아도 시험할 수 있으며 정기 통지 주기에는 영향을 주지 않습니다. 통지 이력 조회에는 `sirsoft-gdpr.privacy.view`, 시험 발송에는 `sirsoft-gdpr.privacy.update` 권한이 필요합니다.

`core.privacy.notice_sections` 필터는 `(sections, locale)`를 받습니다. 각 항목은 `title`, `body` 문자열이며 수신자 언어로 작성합니다. 플러그인은 이 코어 계약의 소비자로서 섹션을 취합하고 HTML 이스케이프 후 메일과 사이트 알림 본문에 넣습니다. 섹션에 개인 식별정보를 넣지 마세요. 번들 확장 가운데 이커머스가 「주문 정보」 섹션(주문 기록의 법정 보존기간과, 보유기간 처리를 켰다면 그 뒤의 익명화·삭제 방식)을 기여합니다 — 이커머스를 켜 두면 통지문 마지막에 그 섹션이 실립니다. 회원 완전 삭제 시 증적 행은 유지하고 `user_id`만 익명화합니다. 정책 버전 발행·재동의는 이 기능과 독립입니다.

통지문 자체는 알림 정의 `annual_privacy_notice` 의 채널별 템플릿입니다. 코어 관리자의 「알림 정의」 화면은 `extension_identifier=core` 로 코어 정의만 받으므로, 확장이 들인 정의는 그 확장의 설정 화면에서 편집합니다 — 이 플러그인은 설정 화면 「알림 템플릿」 카드가 그 자리입니다. 발송 채널을 고르고(저장 버튼으로 반영), 채널별 템플릿을 켜고 끄며, 제목·본문·수신자를 고치고 치환 결과를 미리 볼 수 있습니다. 「기본값 복원」은 `core.notification.filter_default_definitions` 로 플러그인이 제공하는 원본을 되돌립니다. 정의는 `audience` 를 `customer` 로 선언합니다 — 수신자가 통지 대상 회원 본인(`trigger_user`)이라서이고, 역할(`role`) 수신자를 두지 않는다는 뜻입니다(코어 알림 정의 계약: 모든 정의가 `audience` 를 선언하고 수신자 규칙과 맞아야 합니다).

템플릿 편집 창(`resources/layouts/admin/partials/plugin_settings/_modal_notification_template_edit.g7.html`)의 수신자 검색은 코어 API 를 그대로 부릅니다 — 역할은 `GET /api/admin/roles`, 특정 회원은 `GET /api/admin/users/search`. 회원 검색칸을 비우면 드롭다운이 빈 검색어로 `onSearch` 를 부르는데, 코어 회원 검색은 `keyword` 가 비면(공백뿐이어도) 422 로 거절하므로 이 호출에는 `if="{{String($args[0] ?? '').trim() !== ''}}"` 가드를 둡니다. 가드를 빼면 검색어를 지울 때마다 422 가 납니다. 관련 사용자 규칙의 관계는 정의 응답의 `relation_labels` 이름으로 보입니다(이 플러그인의 정의는 관계를 선언하지 않아 지금은 나타나지 않습니다). 역할·특정 회원은 여러 개를 고르는 칸이고, 수신자 종류를 바꾸면 그 줄의 앞 값이 비워집니다.

이 창은 코어 알림 템플릿 창(`sirsoft-admin_basic` `_modal_notification_template_form`)과 같은 저장 API·같은 계약을 씁니다 — 코어 창을 고치면 이 창도 함께 고칩니다(계약 테스트 `resources/js/__tests__/layouts/gdprNotificationTemplateCoreContract.test.ts`). 본문 편집기는 채널 선언(`template_editor`)을 따릅니다. 메일(`rich`)은 `html_editor` 확장 슬롯이라 CKEditor 가 설치돼 있으면 그 편집기가 붙고, 쓴 본문은 `writeScope="global"`·`writePath="gdpr_notification_template_form_modal.template.body.{언어}"` 로 이 창이 저장하는 자리에 바로 담깁니다. 슬롯은 설치 언어마다 하나씩 두고 편집 중인 언어의 것만 세웁니다 — 편집기는 마운트 때 본문과 쓰기 경로를 한 번만 받으므로, 한 편집기를 언어 사이에 돌려 쓰면 언어 탭을 바꿔도 이전 언어 본문이 보이고 입력이 이전 언어 칸에 저장됩니다. 사이트내 알림·문자(`plain`)는 평문 입력입니다. 알림톡처럼 채널이 자기 양식을 대는 경우(`none`)는 코어 언어 탭·제목·본문·이동 경로·변수 안내와 미리보기·저장 버튼을 숨기고 그 확장이 `notification_template_form_sections`·`notification_template_form_footer_actions` 슬롯에 넣은 양식과 저장 버튼만 남깁니다. 창·검색 드롭다운·토글·본문 편집기에는 `data-type="composite"` 가 붙어 있어 레이아웃 컴파일이 템플릿의 컴포넌트 목록과 무관하게 종류를 판정합니다.

통지문의 처리방침 링크는 설정 「개인정보처리방침 주소」(`privacy_policy_url`)를 씁니다. 메일은 사이트 밖에서 열리므로 `/` 로 시작하는 사이트 경로는 요청 Host 가 아니라 사이트 주소(`APP_URL`)를 붙인 절대 주소로, `https://` 주소는 그대로 접수 시점 스냅샷에 얼립니다. 기본 메일 템플릿은 링크 줄 전체를 변수 `{privacy_policy_link}` 로 싣습니다 — 주소가 비어 있으면 이 값이 빈 문자열이라 링크 줄 없이 발송됩니다(누를 수 없는 링크를 보내지 않음). 주소만 필요한 사용자 정의 템플릿은 `{privacy_policy_url}` 을 쓸 수 있지만, 그 경우 주소가 비면 빈 링크가 되므로 직접 처리하세요.

카드의 대상 행(`annual_notice_target_row` — 이름 · 이번 주기 대상 · 대상 보기 · 결과 줄)에 있는 **「지금 실행」**은 코어 관리자 직권 실행입니다. 플러그인은 `GdprAnnualNoticeListener` 가
`core.privacy.manual_run_targets` 필터로 `Privacy\AnnualNoticeManualRunner`(키 `sirsoft-gdpr:annual_notice`)를
등록할 뿐이고, 접수·큐 작업·실행 기록·상태 조회는 코어가 합니다. 러너는 `GdprAnnualNoticeService::run(force: true)`
— 활성 토글만 건너뛰고 실행당 상한과 「이번 주기 수신자 제외」는 정기 실행과 같습니다 — 를 부르고, 처리 뒤
`dueCount()` 를 「남은 대상」으로 기록합니다. 권한은 `sirsoft-gdpr.privacy.update`. 접수는 회원 행 잠금으로
직렬화되어 정기 실행과 겹쳐도 중복 발송이 없으므로 공유 락은 두지 않습니다(`lockKey()` 가 `null`).
확인 창(`_modal_privacy_run`)의 실행 버튼은 `lockRepeatClick` 으로 첫 클릭 즉시 잠급니다 — `disabled` 의 `submitting` 은
다시 그려진 뒤에야 걸려, 빠른 두 번째 클릭이 접수를 한 번 더 넣을 수 있습니다(코어 확인 창과 같은 규칙).

자동 통지를 끄면 채널·상한·서문 입력이 비활성화되고 저장 검증도 그 항목을 강제하지 않습니다. 저장해 둔 채널·상한은 유지되므로 다시 켜면 그대로 쓸 수 있습니다.

기설치본에는 신규 마이그레이션과 1.1.0 업그레이드 데이터 작업이 테이블을 멱등 생성합니다. 공개 설정 페이로드에는 통지 설정을 노출하지 않습니다.
