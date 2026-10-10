# Changelog

이 언어팩의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.1.0] - 2026-10-10

### Added

#### 방문자 통계
- 코어에 새로 들어온 **방문자 통계**의 일본어 번역을 추가했습니다. 없으면 일본어 로케일의 통계 화면에 키가 그대로 노출됩니다.
  - 개인정보 수집 모드 3종·방문자 유형·액션 종류·유입 경로·기기·집계 차원 25종·수집 제외 사유 12종의 라벨
  - 비콘 검증 오류 문구, 통계 관련 활동 로그 문구, 정보주체 요청 3종(조회·삭제·소급 익명화)의 응답 문구
  - 메뉴·권한·알림 시드도 함께 갱신했습니다(관리자 메뉴 「방문자 통계」와 하위 13항목, 권한 3건, 알림 정의 5건).
- 방문자 통계 요청·설정의 검증 항목명 일본어 번역을 추가했습니다(`analytics.attributes` 75키 · `validation.attributes.settings_tab_analytics`).
  없으면 일본어 로케일의 검증 오류 문구에 필드 키(`retention_days` 등)가 그대로 나옵니다.
- 방문자 통계의 **집계·보존·관리자 API** 단계에서 늘어난 문구의 일본어 번역을 추가했습니다. 없으면 일본어 로케일에서 이 자리에 키가 그대로 노출됩니다.
  - 집계가 겹쳐 돌아 건너뛰었을 때와 기간이 잘못됐을 때의 안내
  - 기록이 한계를 넘었을 때 대시보드·메일에 뜨는 경고와 그 조치 링크, IP 로 정보주체를 찾을 때의 한계 안내
  - 조회·저장·삭제의 결과 알림
  - 조회 기간이 너무 길거나(`range_too_long`) 없는 리포트를 부르거나(`report_unknown`) 되돌릴 수 없는 작업에서 확인을 빠뜨렸을 때(`confirm_required`)의 검증 문구
- 방문자 통계 설정 › 보존의 저장 현황 표 이름(`analytics.storage_table.*`, 11종) 일본어 번역을 추가했습니다 — 없으면
  일본어 로케일에서 표 이름(`analytics_visits` 등)이 그대로 보입니다.
- 방문자 통계 집계가 **이미 확정된 날을 건너뛸 때**의 안내 문구 일본어 번역을 추가했습니다
  (`analytics.archive.skipped_finalized`). 없으면 일본어 로케일의 집계 실행 이력에서 그 행만
  번역 키 원문이 보입니다.
- 재집계가 보존 기간이 지난 날을 건너뛸 때의 안내 문구 일본어 번역을 추가했습니다
  (`analytics.archive.skipped_beyond_retention`). 없으면 일본어 로케일의 집계 실행 이력에
  키가 그대로 노출됩니다.
- 꺼진 확장의 커스텀 차원 이름 표시(`analytics.report.inactive_dimension`, 「:key（無効な拡張機能）」)
  일본어 번역을 추가했습니다. 행동 › 커스텀 차원 탭의 선택 목록에 보입니다.
- 방문자 통계 새 리포트 이름(캠페인 매체·검색 탭·이벤트·체류시간 구간·본 페이지 수 구간)과 구간 라벨(「0秒 ~ 10秒」·
  「21ページ以上」 등)·검색 탭 이름, 설정 항목명 4개와 구간 경계 오류 문구(`validation.analytics.bucket_bounds`),
  추이 요청의 「項目」 항목명의 일본어 문구를 채웠습니다.
- 활동 로그 목록 「동작」 칸의 방문자 통계 운영 행위 라벨 일본어 번역을 추가했습니다(`activity_log.action.analytics.*` 9종). 없으면 동작 칸에 `analytics.extension_purged` 같은 원문 키가 보입니다.

#### 개인정보·휴면·동의
- 개인정보 조치 관리자 직권 실행의 코어 문구 일본어 번역을 추가했습니다. 없으면 일본어 관리자 화면에서 직권 실행 결과와 활동 로그가 폴백 문구로 나옵니다.
  - 접수·처리 상태와 결과 안내(`privacy.manual_run.*`), 같은 대상에 작업이 이미 있을 때의 거절 문구(`exceptions.privacy_manual_run_conflict`)
  - 요청 검증 문구와 항목명(`validation.privacy_manual_run.*`·`validation.attributes.skip_waiting`·`targets`·`since`)
  - 접수·완료·실패 활동 로그 문구(`activity_log.*.privacy_manual_run_*`)
  - 휴면 단계 이름(`privacy.manual_run.dormant_stages.*`)은 다른 휴면 문구와 같은 「休眠」 표기로 맞췄습니다.
- 개인정보 조치 대상 명단 창의 코어 문구 일본어 번역을 추가했습니다. 없으면 일본어 로케일에서 환경설정 > 개인정보의 「대상 보기」 창 열 이름이 한국어로 보입니다.
  - 회원 명단의 열 이름(이름·이메일·탈퇴일·마지막 활동일·휴면 전환일, `privacy.targets.columns.*`)
  - 등록되지 않은 처리 대상이나 알 수 없는 휴면 단계를 요청했을 때의 안내(`privacy.targets.unknown_*`)
  - 명단을 쪽 단위로 불러올 때의 검증 문구(`validation.privacy_targets.*`·`validation.attributes.page`·`validation.attributes.per_page`)
  - 휴면은 팩 전체와 같은 「休眠」으로 적었습니다.
- 휴면 계정과 개인정보 보유기간 파기 관련 코어 문구의 일본어 번역을 추가했습니다. 없으면 일본어 로케일에서 이 문구들이 한국어로 남습니다.
  - 휴면 전환·해제·자동 탈퇴가 남는 활동 로그 문구와 설명(`activity_log.action.dormant_*`·`activity_log.description.user_dormant_*`)
  - 휴면 계정이 기존 토큰으로 API 를 부를 때 받는 안내(`auth.account_dormant`), 회원 상태 라벨 「휴면」과 「가입대기」(`user.status.dormant`·`pending_verification`)
  - 환경설정 > 개인정보 탭의 검증 문구와 항목명(`validation.settings.privacy_*`·`validation.attributes.privacy_*`), 파기 처리 대상 이름(`privacy.handlers.user_withdrawn`)
  - 휴면 해제 안내는 코어가 아니라 템플릿 문구(`auth.dormancy_restored`)라 `g7-template-sirsoft-basic-ja` 팩이 담당합니다.
- 휴면 생명주기 알림 3종(전환 사전 안내·전환 완료·탈퇴 예정)의 일본어 메일·사이트내 알림 템플릿을 시드에 추가했습니다. 없으면 일본어 사이트의 회원이 법정 사전 안내와 탈퇴 예고를 한국어로 받습니다.
- 확장 상태 계약 이름 충돌 안내(`extensions.errors.state_contract_conflict`), 방문자 동의 상태 조회 응답 문구(`privacy.consent.fetch_success`), 환경설정 「동의 관리 제공자」 저장 검증 문구와 항목명(`validation.settings.privacy_consent_provider_unknown`·`validation.attributes.privacy_consent_provider`), 레이아웃 편집기 액션 「訪問者の同意状態を再読み込み」(`layout_editor.action_recipe.refresh_consent.label`)의 일본어 번역을 추가했습니다.
- 방문자 동의 상태 조회 응답 문구(`analytics.messages` 동의 확인)와 편집기 설치본 누락 파일 안내(`templates.messages` 파일 없음) 일본어 번역을 추가했습니다.

#### 로그인·비밀번호·보안
- 비밀번호 정책(작성 규칙·재사용 금지·만료·로그인 변경 단계) 문구의 일본어 번역을 추가했습니다.
  - 검증 메시지(`validation.password_policy.*`·보안 설정 항목명·`validation.auth.password_change.*`)
  - 회원 문구(`user.password_expiry_*`·`user.password_change_*`), 로그인 안내(`auth.password_change_*`)
  - 활동 로그(`password_change`·`password_change_postpone`·`password_expiry_logout`), 직권 실행 단계 이름
  - 기능이 꺼져 있어 직권 실행을 거절할 때의 안내(`exceptions.privacy_manual_run_disabled`)
  - 알림 정의 2종(비밀번호 만료 사전 안내·비밀번호 만료)의 메일·사이트 알림 문구
  - `user.validation.password_min` 은 설정한 길이를 보여 주도록 `:min` 으로 바꿨습니다.
- 비밀번호 만료 사전 안내 사이트 알림 본문을 받는 회원 이름으로 시작하는 문구(「{name}様、パスワードは…」)로 맞췄습니다.
- 비밀번호 만료 사전 안내 대상 명단의 날짜 열 이름 「有効期限予定日」(`user.password_expiry_target.column_expires_due`)의 일본어 번역을 추가했습니다. 없으면 일본어 로케일에서 이 열 이름만 한국어(「만료 예정일」)로 보입니다. 만료 처리 명단의 날짜 열은 이미 지난 날짜라 「有効期限切れ日」로 맞췄습니다(`column_expires_at`).
- 본인인증 메일 링크로 확인한 결과를 처음 화면이 받아 갈 때의 안내·오류 문구와 입력 항목 이름의 일본어 번역을 추가했습니다.
- 인증 앱(OTP) 2단계 인증 관련 코어 문구의 일본어 번역을 추가했습니다. 없으면 일본어 로케일에서 이 문구들이 한국어로 남습니다.
  - 인증 앱으로 코드를 받을 때의 로그인 안내(`auth.two_factor_required_app`), 인증 앱 등록이 필요해 로그인이 막혔을 때의 안내(`auth.two_factor_enrollment_required`)
  - 본인인증 채널 「인증 앱」 이름(`identity.channels.app`), 환경설정의 2단계 인증 적용 범위 항목명(`validation.attributes.two_factor_scope`)
- 관리자 2단계 인증 전용 「로그인 인증번호 안내」 메일 번역과, 정책별 문구 추가·삭제·검증 안내, 채널을 쓸 수 없어 보내지 않은 사유 문구의 일본어 번역을 추가했습니다.
- IP 접근 제한 문구의 일본어 번역을 추가했습니다. 없으면 일본어 로케일에서 차단 화면과 저장 오류가 한국어로 남습니다.
  - 허용되지 않은 IP 가 받는 403 화면의 제목·설명·접속 IP 표시(`errors.ip_restricted.*`)
  - 환경설정 > 보안에서 목록·규칙을 저장할 때의 검증 문구와 항목명(`validation.settings.ip_*`·`validation.attributes.*`)
  - 차단과 비상 해제가 남는 활동 로그 문구(`activity_log.action.ip_blocked`·`ip_restriction_disabled`와 그 설명)
- 비밀번호 재설정으로 계정 잠금이 풀렸을 때의 활동 로그 문구(`activity_log.description.auth_account_unlocked_by_password_reset`)를 추가했습니다.
- 첨부파일 업로드에서 파일 내용이 허용 형식과 다를 때의 거부 문구(`attachment.validation.file_mimetype_invalid`)와 빈 파일(0바이트) 거부 문구(`attachment.validation.file_empty`) 일본어 번역을 추가했습니다. 없으면 일본어 로케일에서 이름만 이미지로 바꾼 파일이나 빈 파일을 올렸을 때 안내가 번역되지 않은 채 나옵니다. (KISA 측에서 제보해주셨습니다 — KVE-2026-2366)
- 저장소 경로 거부 안내 문구의 일본어 번역을 추가했습니다(`exceptions.storage.unsafe_path`·`destructive_on_root`·`local_safe_option_package`) — 허용되지 않는 저장 경로나 저장소 최상위 폴더 삭제 요청이 거부될 때, 저장소 디스크 설정에 필요한 패키지가 없을 때의 안내입니다. 없으면 일본어 로케일에서 이 안내가 한국어로 나옵니다.

#### 회원·역할·권한
- 지원 국가 48개의 일본어 이름(「韓国」「アメリカ」「日本」 등)을 추가했습니다. 없으면 일본어 화면의 회원 국가 이름이 한국어로 보입니다.
- 관리자 회원 목록 필터의 요청 검증 문구와 항목명 일본어 번역을 추가했습니다. 없으면 일본어 관리자 화면에서 필터 오류 문구가 한국어로 나옵니다.
  - 계정 상태·권한 그룹·관리자 여부·잠금·최근 로그인·휴면 전환일·상태 변경일·인증 여부·확장 필터 조건이 잘못됐을 때의 안내(`user.validation.list_*`)
  - 오류 문구에 들어가는 항목명(`user.list_filter_attributes.*`)
- 역할↔메뉴 일괄 지정·회원 역할 일괄 변경의 코어 문구 일본어 번역을 추가했습니다.
  - 메뉴 역할 일괄 변경 결과·실패 안내(`menu.bulk_roles_*`·`menu.admin_role_required`)
  - 회원 역할 일괄 변경 결과·권한 상한 거부 안내(`user.bulk_roles_*`)
  - 활동 로그 「ロール一括変更」(`activity_log.action.bulk_roles`·`activity_log.description.user_bulk_roles`)
  - 요청 검증 문구와 항목명(`validation.role.menu_ids.*`·`validation.attributes.menu_ids`·`role_ids`·`mode`·`uuids`)
- 회원 역할 일괄 변경 결과에 「반영済みのため変更なし」 수를 함께 알리는 문구(`user.bulk_roles_updated_with_unchanged`)의 일본어 번역을 추가했습니다.
- 관리 범위 밖의 회원이 섞인 일괄 상태 변경의 제외 사유(`user.bulk_status_out_of_scope`)와, 유형별 마지막 활성 템플릿의 비활성화·삭제 거절 문구(`templates.errors.last_active_deactivate`·`last_active_uninstall`) 일본어 번역을 추가했습니다.
- 메뉴·역할·권한·알림 템플릿 저장의 다국어 필수 문구에 쓰는 칸 이름(`validation.menu.attributes.name` 「メニュー名」, `validation.role.attributes.*` 「ロール名」·「ロールの説明」, `validation.permission.attributes.*` 「権限名」·「権限の説明」, `notification.validation.attributes.*` 「通知の件名」·「通知の本文」)의 일본어 번역을 추가했습니다.
- 코어 권한 2건(`core.system`·`core.system.update`)의 일본어 번역을 추가했습니다 — 일본어 로케일의 역할 수정 화면에서 「시스템 관리」·「코어 업데이트」가 한국어로 남아 있었습니다.
- 확장 공통 권한 2건(`core.extensions`·`core.extensions.custom_assets.manage`)의 일본어 번역을 추가했습니다 — 모듈·플러그인·템플릿에 운영자 CSS·JS·폰트·이미지를 추가하는 권한으로, 일본어 로케일의 역할 수정 화면에서 한국어로 남아 있었습니다.
- 사용자 API 의 내 정보 권한 2종(`core.profile.read` 「マイ情報閲覧」·`core.profile.update` 「マイ情報編集」)과 그 묶음 이름(`core.profile`)의 일본어 번역을 권한 시드에 추가했습니다. 없으면 일본어 관리자 화면의 역할 편집에서 이 권한 이름이 한국어로 보입니다.
- 7.1.0 권한 정비에 맞춘 안내 문구의 일본어 번역을 추가했습니다 — 관리자 화면에서 본인 계정 삭제 거절(`exceptions.cannot_delete_self`), 최고관리자 역할 사용 여부 변경 거절(`exceptions.role_active_state_locked`), 확장 설치 때 함께 설치할 확장·언어팩의 권한 부족 안내(`extensions.errors.cascade_permission_denied`)와 그 종류 이름(`extensions.cascade_types.*` 「モジュール」「プラグイン」「言語パック」)입니다. 없으면 일본어 화면에서 이 안내가 한국어로 보입니다.

#### 알림
- 알림 템플릿 편집 창 「사용 가능한 변수」 설명(`notification.variables.*`)의 일본어 번역을 추가했습니다. 없으면 일본어 화면에서 코어 알림의 변수 설명이 한국어로 보였습니다.
- 알림 발송 이력의 「건너뜀」 사유에 들어가는 수신자 규칙 이름(`notification.recipient_rules.*`)의 일본어 번역을 추가했습니다. 없으면 일본어 화면에서 사유의 규칙 이름이 한국어로 나옵니다.
- 알림 템플릿 수신자 칸 검증 안내의 항목 이름(`notification.validation.attributes.*` — 受信者ルール·受信者の値·関連ユーザーの関係 등)의 일본어 번역을 추가했습니다.
- 알림 템플릿의 역할 수신자 값이 잘못된 형태로 저장되려 할 때 표시되는 안내 문구의 일본어 번역을 추가했습니다.
- 알림이 아무에게도 가지 않아 발송 이력에 「건너뜀」으로 남을 때 표시되는 사유 문구의 일본어 번역을 추가했습니다 — 수신자를 찾지 못한 경우와 비회원 이메일이 비어 있는 경우가 일본어 로케일에서 각각 구분되어 표시됩니다.
- 코어에 새로 생긴 두 안내의 일본어 문구를 채웠습니다 — 본인확인 코드 발송 실패(`identity.errors.challenge_dispatch_failed`) ·
  탈퇴 회원 외부 채널 발송 제외(`notification.skipped.withdrawn_recipient`). 없으면 ja 화면에 원문 키가 그대로 보입니다.
- 「삭제된 회원이라 발송하지 않음」 발송 이력 사유(`notification.skipped.member_account_missing`)의 일본어 문구를 채웠습니다.
- 회원 알림함 관리 기능의 일본어 번역을 추가했습니다. 관리자가 회원에게 나간 알림을 목록으로 보고 지울 때 뜨는 안내 문구(`member_notification.*` 7건)와, 좌측 메뉴 「회원 알림함」, 권한 3종(`core.member-notifications`·`.read`·`.delete`)이 일본어 로케일에서 자연스럽게 표시됩니다.
- 관리자 회원 알림함 목록 조회의 검증 오류 문구에 쓰는 항목 이름(`member_notification.list_attributes.*` 11개) 일본어 번역을 추가했습니다. 없으면 일본어 로케일의 안내 문구에 내부 키가 그대로 나옵니다.

#### 활동 로그·환경설정
- 회원 등록·수정의 역할 변경 활동 로그(`activity_log.action.roles_update`「ロール変更」·`activity_log.description.user_roles_update`)와 활동 로그 보관 기간 설정의 검증 문구·항목명(`validation.settings.activity_log_retention_days_min`·`validation.attributes.activity_log_retention_days`)의 일본어 번역을 추가했습니다. 없으면 일본어 로케일에서 역할 변경 로그와 보관 기간 저장 오류가 한국어로 나옵니다.
- 활동 로그 행위자 라벨 「システム(CLI)」 일본어 번역을 추가했습니다(`common.system_cli`). Artisan 커맨드·스케줄러가 남긴 활동 로그의 행위자 칸에 쓰이며, 없으면 일본어 로케일에서 이 라벨만 한국어로 보입니다.
- 환경설정 저장 오류 문구의 항목명 일본어 번역을 추가했습니다 — 탭 묶음 10건(`validation.attributes.settings_tab*`)과 알림 채널 3건(`validation.attributes.notification_channel_*`)입니다. 없으면 일본어 로케일에서 저장 오류 문구에 화면 라벨 대신 내부 필드 이름이 그대로 나옵니다.

#### 확장 관리
- 확장 업데이트 명령어(`module:update`·`plugin:update`·`template:update`·번들 일괄 업데이트)의 터미널 안내 문구와 설치·업데이트·제거 진행 문구의 일본어 번역을 추가했습니다(`extensions.commands.update.*`·`extensions.commands.bundled_update.*`·`extensions.progress.*`, 71키).
- 확장을 「수정 유지」로 업데이트했을 때의 레이아웃 처리 결과(업데이트 완료 알림 요약, CLI 결과 표, 활동 로그 변경 내용) 일본어 번역을 추가했습니다.
- 확장 업데이트 결과의 레이아웃 처리 요약이 쓰는 「수+단위」 문구(`extensions.layout_sync.counted.*`)의 일본어 번역을 추가했습니다 — 영어 요약이 수마다 단수·복수를 고르려고 새로 둔 키라 코어 원본 키 집합에 맞춘 것이며, 일본어 요약 문장은 그대로입니다.
- 확장 설치·활성화·업데이트가 서버 내부 오류로 실패했을 때의 일반 안내(`extensions.errors.internal_error`)와, 이름 없는 내장 언어팩의 표시 이름(`language_packs.built_in_name`·`language_packs.core_target`)의 일본어 번역을 추가했습니다.
- 켜진 확장이 의존하는 모듈·플러그인을 제거하려 할 때의 거절 문구 일본어 번역을 추가했습니다(`modules.errors.uninstall_blocked_by_dependents`·`plugins.errors.uninstall_blocked_by_dependents`). 없으면 일본어 로케일에서 제거가 막힐 때 이 안내만 한국어로 보입니다.
- 같은 확장의 업데이트가 이미 진행 중일 때의 안내(`extensions.errors.update_already_running`)와 `template:uninstall --delete-data` 확인 안내(`templates.commands.uninstall.delete_data_notice`) 일본어 번역을 추가했습니다. 없으면 일본어 로케일에서 이 두 안내만 한국어로 보입니다.
- 하위 계층이 이미 번역해 둔 실패 사유를 응답 문구로 그대로 쓰는 키(`common.failure_reason`, 값 `:reason`)를 추가했습니다. 확장 설치·업데이트·제거 실패 안내가 이 키를 거칩니다.
- 확인 화면과 확장 아이콘 안내 문구(아이콘 규격 경고 25건)의 일본어 번역을 추가했습니다.
- 확장 업데이트 때 의존 확장 버전이 모자라면 보여 주는 경고 문구, 업데이트가 실패했을 때 실제 결과(되돌리지 못함·새 버전 유지·변경 없음)를 알리는 안내, 번들 일괄 업데이트가 언어팩 단계를 새 작업으로 시작하지 못할 때의 안내 문구의 일본어 번역을 추가했습니다.

#### 레이아웃 편집기
- 화면 편집기 [동작] 탭에서 값을 그대로 넘기는 부품의 칸 위에 바뀐 값을 `{{$args[0]}}` 로 읽는다는 안내의 일본어 문구를 추가했습니다.
- 화면 편집기 [화면 동작] 탭의 「검색어를 입력하면」 항목 이름의 일본어 번역을 추가했습니다.
- 레이아웃 편집기 확인·입력 창의 기본 단추(`layout_editor.dialog.confirm`·`cancel` 「OK」·「キャンセル」), [동작] 탭의 디바운스 혼재 경고(`layout_editor.action.mixed_debounce_warning`), 코드 편집기 검사 경고(`exceptions.layout_markup_lint.mixed_debounce_flushed`)의 일본어 번역을 추가했습니다.
- 레이아웃 편집기의 새 액션 2종(비밀번호 변경 단계 완료·연기 — `layout_editor.action_recipe.login_password_change*`)의 일본어 번역을 추가했습니다.
- 레이아웃 편집기 「다른 페이지로 이동」 폼의 새 칸 이름(`layout_editor.action_recipe.navigate.param_refetch_data_sources` 「再取得するデータソース(カンマ区切り)」)을 일본어로 번역했습니다.
- 확장 카드가 새로 내보내는 동작 3종(주 버튼·보조 버튼·더보기 메뉴)의 편집기 라벨
  일본어 번역을 추가했습니다. 없으면 동작 탭에 키가 그대로 노출됩니다.
- 레이아웃 파일 생성·삭제·이름변경 안내 문구 일본어 번역 추가 (`layouts.messages.*`·`layouts.errors.*`·`layouts.reference_kinds.*`) — 코드 편집기에서 화면 파일을 직접 만들고 지우고 옮길 수 있게 되면서 새로 생긴 안내입니다.
  - 쓸 수 없는 이름(`invalid_name`)·이미 있는 이름(`name_taken`) 같은 입력 오류
  - 지우거나 옮길 수 없는 사유 — 모듈·플러그인이 제공한 원본이라서(`delete_blocked_source_type`·`rename_blocked_source_type`), 다른 화면이 상속·조각으로 참조하고 있어서(`delete_blocked_by_reference`·`rename_blocked_by_reference`)
  - 라우트가 연결된 화면을 지울 때 한 번 더 묻는 안내(`delete_requires_confirmation`)
  - 참조 목록에 붙는 갈래 이름(상속·조각 참조·라우트 연결)도 함께 번역했습니다.
- 커스텀 자산 파일 이름변경 안내 문구 일본어 번역 추가 (`custom_assets.*`) — 확장의 CSS·JS 파일을 편집기에서 옮기고 이름을 바꿀 수 있게 되면서 생긴 안내입니다.
  - 같은 이름이 이미 있을 때(`rename_target_exists`), 현재와 같은 경로를 넣었을 때(`rename_same_path`)의 안내와 성공 알림
  - 확장자를 바꾸려 했을 때(`rename_extension_changed`)의 안내 — 확장자가 바뀌면 그 파일이 더 이상 같은 방식으로 서빙되지 않습니다.
- 커스텀 자산 편집 모달의 새 문구 일본어 번역 추가 (`layout_editor.custom_assets.*`) — 파일을 경로별 트리로 보여 주고 파일명·내용을 검색할 수 있게 되면서 생긴 문구입니다. 검색 입력 안내와 결과 없음 안내, 미저장 표시, 이름변경 버튼과 입력 안내, 대상을 바꿀 때 미저장 편집을 버릴지 묻는 확인, 자동 줄바꿈 토글 라벨입니다.
- 활동 로그 문구 4건 일본어 번역 추가 (`activity_log.description.layout_create`·`layout_rename`·`layout_delete`·`custom_asset_rename`) — 레이아웃 파일 생성·이름변경·삭제와 커스텀 자산 이름변경이 활동 로그에 남게 되면서 생긴 문구입니다.

#### 레이아웃 마크업 검사
- 레이아웃 저장 검사가 조건·순서·결과 처리 안쪽 액션의 `debounce` 가 적용되지 않는다고 알리는 경고(`exceptions.nested_action_debounce_ignored`)의 일본어 번역을 추가했습니다.
- 레이아웃 마크업 컴파일 오류·경고 4건의 일본어 번역을 추가했습니다 — 종류 표기(`layout_markup.invalid_component_type`)·조각 루트 meta 위치(`layout_markup.partial_root_meta_not_allowed`)·종류 불일치(`layout_markup_lint.component_type_mismatch`)·설치되지 않은 확장 컴포넌트(`layout_markup_lint.extension_component_not_installed`) 문구입니다.
- 레이아웃 소스 파일 검사 문구 일본어 번역 추가 — 태그·속성·주석·중첩 규칙 위반을 알려 주는 안내가 줄·열 위치와 함께 일본어로 표시됩니다.
- 레이아웃 확장 정의 검사 문구 일본어 번역 추가 — overlay 확장에 필요한 항목을 알려 주는 안내입니다.
- 레이아웃 코드 편집기의 HTML 저장·변환에서 쓰는 검사 문구 일본어 번역 추가 — 마크업 컴파일 실패(`validation.layout.content.markup_compile_failed`·`validation.layout_extension.content.markup_compile_failed`), 변환 요청의 `markup` 필수 안내, 그리고 `<modal src>` 에 `name` 을 함께 쓴 경우의 안내(`exceptions.layout_markup.modal_name_with_src`)입니다.
- 화면의 일부 조각을 불러오지 못했을 때의 오류 안내 문구 일본어 번역 추가 (`exceptions.layout.invalid_include_markup`) — 조각 파일이 없거나 내용이 깨졌을 때 어느 조각이 왜 실패했는지 알리는 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 확장이 화면에 끼워 넣는 조각(오버레이)의 검증 안내 문구 일본어 번역 갱신 (`validation.layout_extension.contribution_required`) — 종전에는 주입 목록이 반드시 있어야 한다고 안내했으나, 실제로는 모달·데이터·스크립트·초기 동작 중 하나만 있어도 성립합니다. 안내 문구를 그 기준에 맞춰 바로잡았고, 더 이상 쓰이지 않는 옛 안내는 정리했습니다.
- 아직 공개하지 않은 레이아웃 마크업 형식의 오류 안내 문구 일본어 번역 추가 (`exceptions.layout_markup.*`) — 향후 정식 공개에 대비한 것으로, 작성한 화면 파일에 잘못된 표기가 있을 때 어느 줄을 어떻게 고쳐야 하는지 알리는 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
  - 표기 오류·태그 오타 제안·속성 표기·컴포넌트 목록 오류 안내
  - 확장이 제공하는 컴포넌트를 잘못 선언했을 때의 안내(주인 표기 누락·대체 권한·전용 판본·태그 앞자리 겹침)
  - 화면에서 일어나는 동작(이벤트 연결·조건 분기·확인창·초기화)을 잘못 표기했을 때의 안내
  - 표(그리드)의 셀·확장 주입 지점처럼 정해진 자리에만 두는 조각을 엉뚱한 위치에 놓았을 때의 안내
  - 화면 파일의 종류를 감싸는 바깥 태그와 상단 설정(메타·데이터·초기값 등)을 잘못 놓았을 때의 안내
- 위 레이아웃 마크업 형식의 안전 검사·주의 안내 문구 일본어 번역 추가 (`exceptions.layout_markup.*`, `exceptions.layout_markup_lint.*`) — 작성한 화면 파일의 문제를 일본어 로케일에서 자연스럽게 알립니다.
  - 저장하기 전 걸러 주는 안전 검사: 허용되지 않은 서버 주소 호출·외부 사이트 주소 사용·허용 목록 밖 외부 스크립트 불러오기·화면 로딩 완료를 기다릴 수 없는 데이터를 대기 대상으로 지정한 경우의 거부 안내
  - 막지는 않되 권하지 않는 마크업에 대한 주의 안내: 등록되지 않은 동작 이름·플러그인이 완전한 화면을 정의한 경우·담을 수 없는 위치에 배치·화면 크기 구간을 겹치게 설정·알림/모달을 띄우면서 표시 영역을 두지 않은 경우
- 위 레이아웃 마크업 형식을 설치할 때의 안내 문구 일본어 번역 추가 (`exceptions.layout_markup.registry_not_found`) — 작성한 화면 파일을 설치 시점에 곧바로 변환할 때, 대상 화면틀(템플릿)의 컴포넌트 구성 정보를 찾지 못한 경우 무엇을 확인해야 하는지를 일본어 로케일에서 자연스럽게 안내합니다. (설치는 소스 파일을 설치 시점에 그대로 변환해 반영하므로, 별도의 결과 파일을 두지 않습니다.)
- 화면 파일 설치·업데이트 시의 안내 문구 일본어 번역 추가 (`templates.errors.layout_validation_markup_error`, `modules.errors.layout_validation_markup_error`, `templates.layout_install_source.*`) — 작성한 화면 파일의 변환이 실패했을 때 어느 파일에서 무엇이 잘못됐는지 알리는 안내와, 화면 파일을 어느 형식 기준으로 설치할지 고르는 옵션의 안내(잘못된 값 입력·기존 형식 기준 설치로 전환)가 일본어 로케일에서 자연스럽게 표시됩니다.
- 위 마크업 형식의 주의 안내에 한 종류 추가 (`exceptions.layout_markup_lint.switch_value_not_consumed`) — 화면에서 여러 갈래로 동작을 나눌 때, 갈래를 고르는 기준값을 엔진이 읽지 않는 자리에 적으면 그 사실을 알리고 올바른 표기를 안내합니다.
- 위 마크업 형식의 주의 안내에 두 종류 추가 (`exceptions.layout_markup_lint.action_param_misplaced_key`, `exceptions.layout_markup_lint.hoist_prop_not_supported_by_template`) — 화면에서 일어나는 동작에 조건·이벤트 같은 값을 그 동작이 읽지 않는 자리에 적었을 때 그 사실과 올바른 자리를 알리고, 창(모달)의 아래쪽 버튼줄이나 제목 앞뒤 영역을 그 화면틀(템플릿)이 지원하지 않는데 사용했을 때 작성한 내용이 표시되지 않는다는 사실을 미리 알립니다.
- 위 마크업 형식에서 창(모달)을 불러오는 표기를 잘못된 자리에 적었을 때의 안내 일본어 번역 추가 (`exceptions.layout_markup.modal_src_in_node_position`) — 창을 불러오는 표기는 화면 파일이 창 목록을 선언하는 자리에서만 쓸 수 있는데, 종전에는 다른 자리에 적어도 아무 안내 없이 넘어가 화면에 창이 뜨지 않았습니다. 이제 그 사실과 옮겨야 할 자리를 일본어 로케일에서 자연스럽게 알립니다.
- 위 마크업 형식의 조각 참조 표기 안내 일본어 번역 추가 (`exceptions.layout_markup.partial_src_not_authoring`) — 작성한 화면 파일이 다른 조각을 불러올 때 결과 파일이 아니라 소스 파일을 가리키도록 안내합니다. 잘못된 표기를 적으면 어떻게 고쳐야 하는지를 일본어 로케일에서 자연스럽게 알립니다.
- 위 마크업 형식의 설명(주석) 위치 안내 일본어 번역 추가 (`exceptions.layout_markup.comment_not_attachable`, `exceptions.layout_markup.comment_hint.*`) — 작성한 화면 파일에 남긴 설명이 담길 자리가 없는 위치에 적혀 있으면, 종전에는 아무 안내 없이 사라졌습니다. 이제 그 사실을 알리고 어디로 옮겨 적어야 하는지(동작 설명은 그 동작 바로 앞, 동작이 둘 이상이면 하나로 묶은 뒤 그 앞)를 일본어 로케일에서 자연스럽게 안내합니다.
- 7.0 에서 만든 JSON 레이아웃의 깨진 조각 참조를 빈 자리로 등록할 때 남기는 경고(`exceptions.layout.include_skipped`), 레이아웃 편집기가 저장본에 이미 있던 목록 밖 컴포넌트를 알리는 경고(`exceptions.layout_markup_lint.template_component_kept`), 플러그인 레이아웃 마크업 컴파일 실패(`plugins.errors.layout_validation_markup_error`), 템플릿 레이아웃 검증 실패 요약(`templates.errors.layout_validation_validation_failed`)의 일본어 문구를 추가했습니다.

#### 화면 공통
- 공통 오류 화면 문구(`core.errors.page_load_failed`·`init_failed`·`reload`·`layout_forbidden`·`layout_not_found`) 일본어를 추가했습니다.
- 레이아웃 편집기 번들을 받지 못했을 때의 오류 화면 문구 3건(`core.errors.layout_editor_load_failed`·`layout_editor_bundle_failed`·`layout_editor_init_failed`)과 회원 언어 이름 `user.language.ja`(「日本語」)의 일본어를 추가했습니다.
- 레이아웃 스크립트를 불러오지 못했을 때 안내에 쓰는 일반 이름(`core.assets.layout_script` 「画面機能スクリプト」)의 일본어 번역을 추가했습니다.
- 입력 오류가 두 개 이상일 때 오류 안내 끝에 붙는 나머지 오류 수 문구(`validation.summary_more_error`, `validation.summary_more_errors`)의 일본어 번역을 추가했습니다. 일본어 화면의 오류 안내가 「(and 1 more error)」 대신 「(他 1 件のエラー)」로 끝납니다.

### Changed

- 여러 언어로 적는 입력칸에서 필수 언어(사이트 기본 언어)의 값을 비웠을 때의 오류 문구가 칸 이름과 언어 이름을 함께 알리도록(「:attributeの:language(:locale)の値は必須です。」) 원본과 함께 바꿨습니다. 칸 표시 이름이 없을 때 쓰는 문구(`validation.translatable.current_locale_required_unnamed`「:language(:locale)の値は必須です。」)도 추가했습니다.
- 템플릿 업데이트 때의 덮어쓰기 경고 문구를 레이아웃과 확장 조각을 함께 가리키는 표현으로 바꿨습니다.
- 편집기 빌드 파일 누락 안내(`templates.messages.editor_assets_missing`)를 한국어 원문 변경에 맞춰 다시 번역했습니다.
- 계정 잠김 안내(`auth.account_locked`·`auth.account_locked_permanently`)와 무기한 잠금 활동 로그 문구(`activity_log.description.auth_account_locked_permanently`)가 비밀번호 재설정으로도 풀 수 있음을 알리도록 고쳤습니다 — 코어가 재설정 완료 시 잠금을 풀도록 바뀌었습니다.
- 본인인증 메일 문구의 일본어 번역을 알림 템플릿 번역으로 옮겼습니다(코어 7.1.0 의 본인인증 메일 통합).

### Removed

- 코어 원문에서 사라진 레이아웃 마크업 오류 4건(`unknown_tag`·`unknown_tag_with_suggestion`·`extension_id_tag_collision`·`registry_not_found`)의 번역을 정리했습니다 — 마크업 컴파일이 템플릿과 무관해지며 쓰이지 않습니다.
- 더 이상 사용하지 않는 검사 문구를 정리했습니다 — 항목명이 바뀌었거나 검사 자체가 없어진 자리입니다.
- 방문자 통계 「적재 방식」 라벨 2종(`analytics.dispatch_mode.queue`·`.sync`)의 일본어 번역을
  지웠습니다 — 방문 기록이 언제나 요청 안에서 끝나게 바뀌어 설정 항목 자체가 없어졌습니다.
- 본인인증 메시지 기본 문구와 본인인증 메시지 관리 화면·권한(`core.admin.identity.messages.*`)용 번역 — 코어에서 그 기능이 없어졌습니다.

### Fixed

- 모듈 비활성화 경고(`modules.deactivate_warning`)의 일본어 문구가 의존하는 것이 템플릿뿐인 것처럼 「アクティブなテンプレート」라고 하던 것을 「有効化された拡張」로 바로잡았습니다(코어 원문과 같은 수정).
- 모듈·플러그인 레이아웃 검증 실패 요약(`modules.errors.layout_validation_validation_failed`, `plugins.errors.layout_validation_validation_failed`) 끝의 `: :error` 를 ko 원문에 맞춰 뺐습니다. 호출부가 더 이상 오류 문구를 넘기지 않아 일본어 화면에 `:error` 가 글자 그대로 보였습니다.
- 레이아웃 편집기 커스텀 자산 창의 바이너리 파일 안내 문구(`layout_editor.custom_assets.binary_hint`)의 치환 자리를 `:extension` 에서 `{extension}` 으로 바꿨습니다(코어 원문과 같은 수정). 화면 번역기는 `{이름}` 만 바꿔 넣어, 「このフォーマット(:extension)」처럼 자리 이름이 글자 그대로 보였습니다.
- 활동 로그의 로그인·로그아웃 설명을 관리자 경로와 나눴습니다 — 관리자 로그인·로그아웃은 「管理者ログイン」·「管理者ログアウト」(`activity_log.description.auth_admin_login`·`auth_admin_logout`), 일반 회원과 이전 기록은 「ログイン」·「ログアウト」(`auth_login`·`auth_logout`)입니다. 종전에는 일반 회원의 로그인도 「管理者ログイン」으로 보였습니다.
- 레이아웃 동시 저장 충돌 안내(`exceptions.concurrent_modification`)에서 내부 표기(`:resource`)를 빼고 버전 번호만 보이도록 바꿨습니다.
- 사이트맵 생성 시각 검증 안내(`validation.settings.sitemap_schedule_time_invalid`)가 허용 범위(00:00〜23:59)를 함께 알리도록 고쳤습니다.
- 커스텀 자산 폴더를 만들지 못했을 때의 일본어 안내가 경로만 보여 주고 사유·소유자·권한·실행 계정·조치 방법을 빠뜨리던 문제를 고쳤습니다. 이제 한국어 원문처럼 원인과 조치까지 안내합니다.
- 문자 메시지 플러그인을 쓰는 일본어 사이트에서 회원 가입 축하·비밀번호 재설정·휴면 안내·본인인증 코드 등 회원 알림의 문자·알림톡 제목과 본문이 일본어 없이 남던 문제를 수정했습니다.

## [1.0.10] - 2026-09-09

### Added

- 로그인 시도가 많아 잠시 차단될 때의 안내와, 본인인증 화면에서 로그인용 인증 요청을 처리할 수 없다는 안내의 일본어 번역을 추가했습니다.
- 레이아웃 편집기 [화면 동작] 탭의 「인증번호 확인」·「인증번호 다시 받기」 항목 이름의 일본어 번역을 추가했습니다.
- 레이아웃 편집기에서 환경설정과 연결된 항목에 표시되는 안내와 「직접 지정으로 바꾸기」·「데이터 연결로 되돌리기」 버튼의 일본어 번역을 추가했습니다.
- 레이아웃 편집기 숫자 입력 항목의 입력 자리 표시 문구의 일본어 번역을 추가했습니다.
- 레이아웃 편집기 「확장 편집」 저장이 끼워 넣은 요소를 되돌릴 수 없어 막혔을 때 표시되는 안내의 일본어 번역을 추가했습니다.

## [1.0.9] - 2026-09-06

### Added

- 화면에 필요한 파일을 불러오지 못했을 때 표시되는 안내 문구의 일본어 번역을 추가했습니다 — 안내 제목, 여러 항목이 실패했을 때의 합산 문구, [다시 시도]·[닫기] 버튼과 재시도 실패 안내가 일본어 로케일에서 표시됩니다.
- 운영자가 덧붙인 CSS·JS·글꼴·이미지를 다루는 관리 화면의 일본어 번역을 추가했습니다 — 파일 목록과 본문 편집, 파일 올리기·삭제, 관리 대상 확장 선택, 권한이 없을 때의 안내가 일본어 로케일에서 표시됩니다.
- 레이아웃 편집기 툴바에서 운영자 추가 에셋을 잠시 끄고 켜는 항목의 일본어 번역을 추가했습니다 — 추가한 파일이 화면을 깨뜨렸을 때 그 파일 없이 화면을 다시 여는 안내가 함께 표시됩니다.
- 확장을 제거할 때 운영자가 넣어 둔 파일의 사본을 보관했다는 안내의 일본어 번역을 추가했습니다 — 모듈·플러그인·템플릿 제거 결과 화면에서 보관 경로가 일본어로 표시됩니다.
- 운영자 추가 에셋의 저장·올리기·삭제가 활동 로그에 남을 때 표시되는 문구의 일본어 번역을 추가했습니다.
- 초기 화면 파일 생성이 거듭 실패할 때 관리자 대시보드에 표시되는 안내의 일본어 번역을 추가했습니다 — 폴더 권한·디스크 공간·캐시 저장소 중 어느 쪽이 원인인지에 따라 다른 안내가 일본어 로케일에서 표시됩니다.
- 리버스 프록시 뒤에서 신뢰 프록시가 지정되지 않았을 때 관리자 대시보드에 표시되는 안내의 일본어 번역을 추가했습니다.
- 사이트 설정 문제로 화면 구성 파일이 차단됐을 때 표시되는 안내 문구의 일본어 번역을 추가했습니다 — 방문자 쪽 문제가 아니라는 안내가 일본어 로케일에서 표시됩니다.
- 확장 프로그램의 스타일 묶음을 끝내 불러오지 못했을 때 안내에 표시되는 항목명의 일본어 번역을 추가했습니다 — 종전에는 그 자리에 내부 구분 이름이 그대로 보였고, 이제 「모듈 스타일」·「플러그인 스타일」에 해당하는 일본어가 표시됩니다.
- 관리자 환경설정 > 일반의 「초기 화면 정적 파일」 상태·재작성 결과 안내 문구의 일본어 번역을 추가했습니다.
- 초기 화면 파일 생성 실패 알림의 [다시 만들기] 버튼 라벨과 복구 완료 안내의 일본어 번역을 추가했습니다.
- 운영자 추가 파일을 둘 폴더를 만들지 못했을 때 표시되는 실패 사유(같은 이름의 파일이 있음·상위 폴더에 쓸 수 없음 등)와 조치 안내의 일본어 번역을 추가했습니다.

### Changed

- 코어 업데이트 완료 안내문(`settings.core_update.apply_mode_incremental_prune_hint`)의 일본어 번역을 코어 문구 변경에 맞춰 갱신했습니다 — 성공한 업데이트 뒤에는 동작하지 않는 정리 명령 안내를 걷어내고 `--prune` 재실행 안내만 남깁니다.

## [1.0.8] - 2026-08-24

### Added

- 사이트 기본 공유 이미지(og:image) 설정의 검증 속성 라벨 번역을 추가했습니다.

## [1.0.7] - 2026-08-22

### Added

- 아웃바운드 프록시 설정의 검증 메시지와 항목명 일본어 번역을 추가했습니다 (`settings.outbound_proxy_*`, `attributes.outbound_proxy*`).
- 아웃바운드 프록시 연결 테스트 결과 안내 문구의 일본어 번역을 추가했습니다 (`settings.outbound_proxy_test_*`).
- 브라우저가 지원 범위보다 오래되어 화면 구성 스크립트를 실행하지 못한 경우의 안내 문구 일본어 번역을 추가했습니다 (`errors.bootstrap.incompatible_title`, `errors.bootstrap.incompatible_message`).

### Changed

- 언어팩 관리(조회·설치·활성화·비활성화·제거·업데이트 확인·업데이트·캐시 갱신·manifest 미리보기) 실패 안내에서 오류 원문 노출(`:error`)이 제거된 것에 맞춰, 해당 실패 문구의 일본어 번역을 갱신했습니다 (`language_packs.*_failed`).

## [1.0.6] - 2026-08-19

### Changed

- 회원 관리(생성·수정·삭제·탈퇴) 실패 안내에서 오류 원문 노출(`:error`)이 제거된 것에 맞춰, 해당 실패 문구의 일본어 번역을 재시도 안내 문구로 갱신했습니다 (`user.create_failed`, `user.update_failed`, `user.delete_failed`, `user.withdraw_failed`).
- S3 리전 검증 메시지를 목록 선택 안내에서 형식 안내(소문자 영숫자·하이픈, R2 는 `auto`)로 개정했습니다.

### Added

- 검색엔진 드라이버 라벨 일본어 번역을 추가했습니다 (`settings.drivers.search.*`) — 환경설정 > 드라이버 탭의 검색엔진 선택지가 일본어 로케일에서 표시됩니다.
- S3 호환 스토리지 설정 신설 항목(엔드포인트 URL·Path-style 주소)과 리전 형식 안내의 검증 메시지 일본어 번역 추가 (`validation.settings.s3_endpoint_*`, `s3_use_path_style_boolean`, `s3_region_*`) — 드라이버 설정 저장 시 오류 안내가 일본어 로케일에서 자연스럽게 표시됩니다. (#99 @lyg-kaban 님께서 제보해주셨습니다.)
- 드라이버 사용 불능 안내 문구 일본어 번역 추가 (`validation.settings.driver_unusable`, `settings.driver_unusable_*`, `settings.s3_adapter_missing`) — 필요한 라이브러리·PHP 확장이 없는 드라이버를 저장·테스트할 때의 사유 안내가 일본어 로케일에서 표시됩니다.
- 웹소켓 서버(백엔드 발송용) endpoint 연결 실패 안내 일본어 번역 추가 (`settings.websocket_server_test_failed`).
- 공개 자산 스토리지(공개 이미지 직접 URL 서빙) 설정의 드라이버 라벨과 검증 메시지 일본어 번역을 추가했습니다. 환경설정 > 드라이버 탭의 새 설정이 일본어 로케일에서 자연스럽게 표시됩니다. (#100 @lyg-kaban 님께서 건의해주셨습니다.)
- 설정 항목 단위 저장의 값 형식 안내 일본어 번역을 추가했습니다 (`validation.setting.value.*`) — 켜기/끄기·숫자 설정에 맞지 않는 값을 저장할 때의 안내가 일본어 로케일에서 표시됩니다.
- 슈퍼 관리자 계정·역할 수정 상한 및 권한 부여 상한 위반 시 표시되는 예외 메시지(`cannot_modify_super_admin`, `cannot_grant_unheld_permission`, `cannot_modify_protected_role`)의 일본어 번역을 추가했습니다.
- 레이아웃 저장 시 위험 표현식·외부 리소스 URL 거부 안내(`layout.dangerous_expression`, `layout.external_resource_url`)의 일본어 번역을 추가했습니다.
- 고아 첨부 정리 설정의 검증 메시지와 항목명 일본어 번역을 추가했습니다 (`validation.settings.orphan_*`) — 환경설정 > 업로드의 자동 정리·보존기간을 잘못 저장할 때의 안내가 일본어 로케일에서 항목 이름과 함께 표시됩니다.
- 데이터베이스 백업 미제공 안내 문구의 일본어 번역을 추가했습니다 (`settings.database_backup_unavailable`).
- 셸 예약 작업 허용목록에 인터프리터를 등록했을 때 인라인 명령 실행·안전하지 않은 스크립트 경로를 거부하는 안내 문구의 일본어 번역을 추가했습니다 (`validation.schedule_command.shell_interpreter_denied`).

### Removed

- 참조처가 사라진 S3 리전 선택 검증 메시지(`validation.settings.s3_region_required`)를 원본(ko/en)과 함께 정리했습니다.

### Fixed

- 범용 입력 항목의 검증 문구가 특정 화면 전용 이름(SMTP 비밀번호·기본 언어·기본 통화 등)으로 표시되던 문제를 수정했습니다 — 전역 라벨을 범용 문구로 되돌리고 SMTP·사용자 목록 등 도메인 전용 라벨을 별도 키로 분리했습니다. (#113 @Tuwasduliebst 님께서 제보해주셨습니다.)

## [1.0.5] - 2026-08-10

### Added

- 자산 파일 주소 방식 확인·전환 명령(`g7:asset-url-mode`) 출력 문구 일본어 번역 추가 (`asset_url_mode.*`) — 현재 방식과 서버 진단 안내, 전환 결과 메시지가 일본어 로케일에서 자연스럽게 표시됩니다.
- 데이터베이스 계정 설정 오류 안내 화면의 문구 일본어 번역 추가 (`database_credential.*`) — 최고 권한 계정을 사용하거나 사용자명이 비어 있을 때 표시되는 안내와 복구 방법이 일본어 로케일에서 자연스럽게 표시됩니다.
- 사이트맵 생성이 실패했을 때 표시되는 안내 문구 일본어 번역 추가 (`exceptions.seo.*`) — 사이트맵 파일을 저장하지 못하거나 압축에 실패한 경우의 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 사이트맵 재생성 예약·준비 중·생성 실패 안내 문구 일본어 번역 추가 (`seo.sitemap_*`) — 재생성을 예약했을 때, 아직 준비 중일 때, 생성에 실패했을 때의 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- SEO 설정의 사이트맵 분할 기준·압축·수집기 상한과 SEO/사이트맵 캐시 유지 시간 항목의 검증 안내 문구 일본어 번역 추가 (`validation.settings.*`) — 잘못된 값을 입력했을 때의 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- SEO 설정의 사이트맵 다국어 대체 링크(hreflang) 항목의 검증 안내 문구 일본어 번역 추가 (`validation.settings.sitemap_hreflang_enabled_boolean`) — 잘못된 값을 입력했을 때의 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 관리자 사이트맵 수동 재생성 완료·실패 알림 템플릿 일본어 번역 추가 (`sitemap_regenerated`, `sitemap_regenerate_failed`) — 재생성을 실행한 관리자가 받는 앱 내 알림·메일이 일본어 로케일에서 자연스럽게 표시됩니다.
- 알림 채널 선택 항목의 검증 안내 문구 일본어 번역 추가 (`validation.notification_channel.*`) — 사용할 수 없는 알림 채널을 선택했을 때의 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 계정이 무기한 잠겼을 때의 로그인 안내와 관리자 잠금 해제 안내, 그리고 해당 활동 기록 문구의 일본어 번역 추가.
- 허용되지 않는 파일 형식을 업로드했을 때의 안내 문구 일본어 번역 추가.
- 확장 식별자·메뉴 역할·활동 로그 검색 조건의 안내 문구 일본어 번역 추가 — 잘못된 값을 입력했을 때의 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 모듈·플러그인 파일 업로드 시 허용되지 않는 파일 형식 안내 문구 일본어 번역 추가.
- 플러그인을 GitHub 주소 또는 ZIP 파일로 설치할 때의 입력 검증 안내 문구 일본어 번역 추가 — 주소 형식이 잘못됐거나 ZIP 이 아닌 파일을 올렸을 때, 파일 용량을 초과했을 때의 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 회원가입 검증 메시지의 휴대폰·전화번호 항목(`validation.auth.mobile.*`, `validation.auth.phone.*`) 일본어 번역 추가 — 회원가입 시 연락처 형식 오류 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 환경설정 저장 시 오류 안내에 나오는 항목 이름의 일본어 번역 추가 — 보안·업로드·SEO·고급·드라이버 등 각 설정 항목이 내부 식별자 대신 일본어 항목명으로 표시됩니다.
- 로그인 2단계 인증 안내(인증번호 발송·불일치·요청 만료)와 인증번호 입력 검증 문구의 일본어 번역 추가.
- 검색 인덱스 점검·재생성(`search:index`) 화면 문구 일본어 번역 추가 — 판정 등급(정상/부분적/미반영/판정불가), 점검 결과 표, 재생성 확인·경고 문구가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 버전 이력의 "더 보기" 버튼과 변경내용이 너무 클 때의 안내 문구 일본어 번역 추가.
- 버전 목록을 한 번에 몇 개까지 가져올지 정하는 값의 입력 안내 문구 일본어 번역 추가.
- 통신이 끊겼을 때 표시되는 네트워크 오류 안내 문구 일본어 번역 추가 — 종전에는 내부 식별 문구가 영문 그대로 노출됐습니다.
- 목록·검색의 총 건수 정확도 안내 문구(`pagination.*`) 일본어 번역 추가 — 총 건수를 상한까지만 센 경우의 "N건 이상" 표기와 검색어를 좁히라는 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 검색 결과 건수 안내와 검색어 구체화 안내(`search.results_found_at_least`, `search.refine_query_hint`) 일본어 번역 추가.
- 페이지 번호 상한 초과 시의 검증 오류 문구(`validation.page_max`) 일본어 번역 추가.
- 예약 작업에 등록할 수 없는 Artisan 명령·옵션을 안내하는 문구 일본어 번역 추가 — 왜 등록이 거부됐는지(허용 목록 밖 / 형식 오류 / 허용되지 않은 옵션 / 추가 인자 불가)가 사유별로 표시됩니다.
- 언어팩 설치 시 거부 사유를 안내하는 문구 일본어 번역 추가 — 허용되지 않는 파일 형식, 번역 배열이 아닌 PHP 파일(줄 번호 포함), 심볼릭 링크, 그리고 설치 직후 활성화에 필요한 권한 안내입니다.
- 환경설정 고급 탭의 목록 상한 항목명(총 건수 집계 상한·페이지 번호 상한) 일본어 번역 추가 — 값이 범위를 벗어났을 때의 안내에 내부 식별자 대신 항목명이 표시됩니다.
- 공통 응답 메시지 일본어 번역 추가 (`common.created`·`common.updated`·`common.deleted`) — 생성·수정·삭제 성공 응답이 일본어 로케일에서 번역문으로 표시됩니다.
- 본인인증 정책 삭제 제한 안내 메시지 일본어 번역 추가 (`identity.errors.cannot_delete_system_policy`) — 시스템이 선언한 정책은 삭제할 수 없다는 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 개발자 도구 응답 문구 일본어 번역 추가 — 디버그 모드 접근 거부, 연결 확인, 덤프 데이터 없음 안내, 데이터 삭제 완료 안내가 일본어 로케일에서 번역문으로 표시됩니다. 종전에는 사이트 언어와 무관하게 항상 한국어로만 나왔습니다.

### Changed

- PHP 파일 위치 안내 문구를 실제 허용 위치(`backend/{언어코드}/{그룹}.php`)를 알려주도록 다듬었습니다.

### Removed

- 더 이상 표시되지 않는 코드 패턴 경고 문구를 제거했습니다 — 같은 상황에서 어느 줄이 문제인지 알려주는 안내로 대체됐습니다.

## [1.0.4] - 2026-07-16

### Added

- 화면 구성에 필요한 스크립트를 끝내 불러오지 못했을 때 표시되는 안내 문구 일본어 번역 추가 (`errors.bootstrap.*`) — 네트워크 문제로 페이지를 열지 못한 경우의 안내와 새로고침 버튼이 일본어 로케일에서 자연스럽게 표시됩니다.
- 내부 네트워크 주소 호출 차단 관련 안내 문구 일본어 번역 추가 (`validation.outbound_url.*`, `schedule.url_not_public`, `language_packs.errors.download_url_not_public`, `settings.websocket_invalid_host`) — 예약 작업·언어팩 설치·웹소켓 설정에서 내부 주소가 거부될 때의 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 아직 공개하지 않은 예약 작업 기능의 쉘·Artisan 명령 실행 제한 안내 문구 일본어 번역 추가 (`schedule.shell_not_allowed`, `schedule.artisan_not_allowed`, `validation.schedule_command.*`) — 향후 정식 공개에 대비한 것으로, 허용되지 않은 쉘 명령이나 위험한 Artisan 명령이 거부될 때의 안내가 일본어 로케일에서 자연스럽게 표시됩니다.

## [1.0.3] - 2026-07-11

### Added

- vendor-bundle 빌드 결과 반영 실패 안내 메시지 일본어 번역 추가 (`exceptions.vendor.bundle_build_promote_failed`) — 빌드 산출물을 최종 경로로 원자적 교체하는 데 실패했을 때의 안내가 일본어 로케일에서 자연스럽게 표시됩니다.

## [1.0.2] - 2026-07-08

### Added

- 코어 업데이트 파일 적용 방식 안내 메시지 일본어 번역 추가 (`settings.core_update.apply_mode_incremental`·`apply_mode_incremental_prune_hint`·`apply_mode_prune`·`apply_mode_fallback`) — 코어가 실제 변경한 파일만 적용하는 기본 방식·`--prune` 전체 덮어쓰기·백업 부재 회귀 안내가 일본어 로케일에서 자연스럽게 표시됩니다.

## [1.0.1] - 2026-07-03

### Added

- 설정 복원 관련 안내 메시지 일본어 번역 추가 (`settings.backup_path_required`·`restore_success`·`restore_failed`·`restore_error`) — 백업 경로 미입력 안내와 설정 복원 성공/실패 결과가 일본어 로케일에서 자연스럽게 표시됩니다.
- 훅 매핑 캐시 관리 안내 메시지 일본어 번역 추가 (`hooks.cache_generated`·`cache_cleared`·`cache_absent`) — 부팅 성능을 위한 훅 캐시 생성·삭제 결과가 일본어 로케일에서 자연스럽게 표시됩니다.

## [1.0.0] - 2026-07-01

### Added

- 비회원 알림 채널 차단 안내 메시지 일본어 번역 추가 (`notification.channel_guest_not_allowed`) — 비회원 발송을 허용하지 않는 채널(사이트내 알림 등)로 발송이 건너뛰어질 때의 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 "요소 ID" 컨트롤의 데이터 칩 연동 안내 문구 일본어 번역 추가 (`layout_editor.core_props.id.add_data` / `binding_hint`) — 반복 목록 항목에 고유 ID 를 부여하는 데이터 칩 안내가 일본어 로케일에서 표시됩니다.
- 레이아웃 편집기 "조건에 따라 분기" 동작 라벨 일본어 번역 추가 (`layout_editor.action_recipe.conditions.*`) — 동작 추가 목록의 분기 동작과 분기별 실행조건·동작·추가/삭제·순서이동 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.

## [1.0.0-beta.4] - 2026-06-14

### Added

- 관리자 대시보드 "최근 알림" 위젯 백엔드 응답 메시지 일본어 번역 추가 (`dashboard.recent_notifications_loaded`·`recent_notifications_failed`) — 최근 발송 알림 조회 성공/실패 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 검색엔진 설정에서 확장 제공 SEO 자동값 연결 칩 안내 문구 일본어 번역 추가 (`layout_editor.page_settings.seo.auto_chip_source`·`auto_chip_replace`) — 확장이 제공하는 공유 이미지·제목 등의 데이터 출처 표시와 "다른 데이터로 변경" 동작이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 [화면 동작] 탭 동작 입력칸 친화화 신규 라벨 일본어 번역 추가 (`layout_editor.action_param.*` 4건 + `layout_editor.init_actions.base_readonly_hint`) — 동작 입력칸의 값·이름 placeholder, "값 추가" 버튼, 공통 레이아웃 동작 읽기 전용 안내가 일본어 로케일에서 표시됩니다.
- "모달 열기" 동작 대상 선택칸 placeholder 일본어 번역 추가 (`layout_editor.recipe.modal_placeholder`).
- 이미 식으로 작성된 복잡한 값의 친화 편집 불가 안내 문구 일본어 번역 추가 (`layout_editor.value_tree.raw_expression_hint`).
- 레이아웃 편집기 [화면 동작] 탭의 본인인증 대상 입력칸·실패 처리 안내 신규 라벨 일본어 번역 추가 (`layout_editor.action_param.identity_target_*`·`param_on_error`) — 본인인증 동작의 이메일/전화 대상 지정과 실패 시 처리 입력이 일본어 로케일에서 표시됩니다.
- 본인인증 정책 우선순위 동률 차단 안내 문구 일본어 번역 추가 (`identity_policy.priority_duplicate`) — 같은 적용 위치에 동일 우선순위의 활성 정책을 저장하려 할 때의 안내가 일본어 로케일에서 표시됩니다.

### Removed

- 제거된 "공통 레이아웃에서 수정" 라벨(`layout_editor.init_actions.edit_in_base`)의 일본어 번역을 정리했습니다.

## [1.0.0-beta.3] - 2026-05-22

### Changed

- 레이아웃 편집기 동작 목록 순서 변경 라벨을 끌어서 순서 변경으로 통일 (`layout_editor.action_list.drag_reorder` 추가, `layout_editor.{action_list,init_actions}.{move_up,move_down}` 제거) — 동작 순서를 ▲▼ 버튼 대신 손잡이를 끌어 바꾸도록 통일하면서, 끌기 손잡이 안내 문구가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 요소 연결(영역 선택)·검색엔진 데이터 선택 안내 문구 일본어 번역 추가 (`layout_editor.target_picker.needs_id`·`layout_editor.page_settings.seo.data_sources_hint`) — 직접 ID를 부여한 요소만 연결 대상으로 고를 수 있다는 안내와, 검색엔진 변수·구조화 데이터가 참조할 데이터 선택 안내가 일본어 로케일에서 자연스럽게 표시됩니다.

### Added

- 레이아웃 편집기 폼 항목 편집 필드 캡션 일본어 번역 추가 (`layout_editor.list_editor.{item_label,item_placeholder}`) — 폼 속성 편집 목록에서 각 항목의 라벨/안내 문구 입력란 위 캡션이 일본어 로케일에서 자연스럽게 표시됩니다.

- 레이아웃 편집기 라우트 트리 버전 배지·슬롯 영역 라벨 일본어 번역 추가 (`layout_editor.chrome.route_tree.badge.version_tooltip`·`layout_editor.preview.slot_area_label`) — 좌측 트리의 현재 저장 버전 배지 툴팁과 공통 레이아웃 편집 캔버스의 "콘텐츠 영역" 슬롯 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 별도 편집 모드 + 확장 주입 시각 편집 라벨 일본어 번역 추가 (`layout_editor.chrome.route_tree.group.extension`·`layout_editor.chrome.route_tree.badge.{extension_point,overlay,priority,inactive}`·`layout_editor.property_modal.injected_props.*`) — 라우트 트리의 "확장 주입" 그룹·확장 타입/우선순위/비활성 배지와, 호스트 노드 속성 모달의 "확장이 주입한 속성" 섹션 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 속성 패널 텍스트 다국어 위젯·데이터 소스 라벨 일본어 번역 추가 (`layout_editor.prop_i18n.*`·`layout_editor.data_sources.field.label_key*` 등) — 안내 문구·라벨 등 속성 텍스트를 언어별로 편집하는 위젯과 데이터 소스 표시 명칭 입력 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 데이터 연결 라벨 일본어 번역 추가 (`layout_editor.binding.*` — 데이터 연결 섹션·검색·연결 상태·shape(단일값/배열)·필수 표시 등) — 컴포넌트 속성을 데이터소스/상태에 연결할 때의 검색형 피커 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 표(Table) 빌트인 편집기 라벨 일본어 번역 추가 (`layout_editor.table_editor.{title,cell_text,cell_class_placeholder,move_col_left,move_col_right,structural_cell,grid_invalid,select_hint}`) — 속성 탭에서 표의 행/열 추가·삭제·이동, 셀 병합/해제, 셀 테두리, 셀 텍스트 편집 라벨과 그리드 정합성 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 표 캔버스 인플레이스 편집 라벨 일본어 번역 추가 (`layout_editor.table_inplace.*` — 셀 선택·행/열 추가·삭제·이동·병합·해제·영역 선택·테두리 일괄 등) — 캔버스에서 표를 직접 편집할 때의 어포던스 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 표 셀 배경색·내부 여백 컨트롤 라벨 일본어 번역 추가 (`layout_editor.table_editor.{cell_fill,cell_fill_none,cell_fill_custom,cell_padding,cell_padding_custom}`) — 셀 배경색(없음/프리셋/직접 선택)과 내부 여백(단계/직접 px) 컨트롤 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 표 셀 색상 라이트/다크 컨트롤 라벨 일본어 번역 추가 (`layout_editor.table_editor.{cell_color,dark_free_color_hint}`) — 셀 테두리·배경 색상의 라이트/다크 단일 공용 탭 라벨과 다크 모드 자유색 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 동작 탭 이벤트 명칭 일본어 번역 추가 (`layout_editor.action.event.*` — 버튼 클릭·항목 추가/삭제·언어 변경·정렬 변경 등 15종) — 동작 탭 이벤트 슬롯 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 라우트 트리 검색·템플릿 전환 라벨 일본어 번역 추가 (`layout_editor.chrome.route_tree.{search_placeholder,search_clear,search_no_results}`·`layout_editor.chrome.toolbar.switch_template`) — 좌측 화면/라우트 패널의 검색 입력칸과 상단 툴바의 템플릿 전환 드롭다운 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 데이터 정의 빌트인 편집기 라벨 일본어 번역 추가 (`layout_editor.array_editor.number_list_placeholder`·`layout_editor.cell_tree_editor.*`) — 차트 데이터셋의 수치 목록 입력 안내와 카드 그리드 중첩 컬럼/셀 트리 편집 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 텍스트 데이터 연결 라벨 일본어 번역 추가 (`layout_editor.inline_binding.*`·`layout_editor.translation.{token_mismatch,placeholder_mismatch,placeholder_locked}`) — 텍스트에 박힌 데이터를 조각 단위로 교체/해제/추가하는 [속성] 탭 영역과, 번역 시 데이터 자리표시 보호 안내 메시지가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 버전 기록·미리보기 라벨 일본어 번역 추가 (`layout_editor.version_history.*`·`layout_editor.preview_action.*`·`layout_editor.chrome.toolbar.{previewing,version_history}`) — 툴바 버전 기록 모달(버전 목록·복원·비교 diff)과 실데이터 미리보기 버튼 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 확장 편집·미리보기 검증 메시지 일본어 번역 추가 (`validation.layout_extension.*`)
- 모듈·플러그인 업데이트 커맨드의 레이아웃 전략 안내 메시지 일본어 번역 추가
- 코어 프론트엔드 다국어 자원 일본어 번역 추가 (`core.errors.*`) — 템플릿 엔진 에러 메시지가 일본어 로케일에서 일본어로 노출됩니다.
- 레이아웃 편집기 셸 일본어 번역 추가 (`layout_editor.chrome.*`) — 툴바·라우트 트리·진입 FAB·권한 거부·dirty guard·캔버스 placeholder 가 일본어 로케일에서 자연스럽게 표시됩니다.
- 템플릿 자산 서빙 메시지 일본어 번역 추가 (`templates.messages.editor_*`, `templates.messages.language_*`) — 편집기 자산 매니페스트·다국어 데이터 응답 메시지가 일본어로 노출됩니다.
- 레이아웃 편집기 낙관적 잠금 안내 일본어 번역 추가 (`exceptions.concurrent_modification`, `validation.layout(.layout_extension).expected_lock_version.*`) — 코드 편집기 ↔ 레이아웃 편집기 동시 저장 충돌 시 사용자 안내 메시지가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 chrome 일본어 번역 추가 (`layout_editor.{palette,insertion,context_menu,overlay,save}.*`) — 요소 추가 팔레트·컨텍스트 메뉴·오버레이 어포던스·저장 안내·동시 저장 충돌 안내 모달이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 여백 방향·정렬 박스 해제·표 라벨 일본어 번역 추가 (`layout_editor.control.spacing.*`, `layout_editor.flex.unmake_flex`, `layout_editor.palette.table.*`) — 여백 측별 편집(일괄/개별·상하좌우)·정렬 박스 해제·표 팔레트 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 페이지 상태 토글 라벨 일본어 번역 추가 (`layout_editor.toolbar.state`) — 캔버스 툴바의 페이지 상태 드롭다운 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 반복 항목 편집 모드 라벨 일본어 번역 추가 (`layout_editor.chrome.toolbar.{exit_iteration_item_edit,mode_badge.iteration_item}`, `layout_editor.overlay.edit_iteration_item`) — 반복 영역의 "반복 항목 편집" 진입·종료·모드 배지가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 스타일 탭 색 모드·디바이스별 편집 라벨 일본어 번역 추가 (`layout_editor.property_modal.scope.*`, `layout_editor.property_modal.dark_code_only`, `layout_editor.toolbar.color_scheme_*`) — 라이트/다크·기본값/PC/태블릿/모바일·커스텀 크기 세부탭, 기본값 초기화, 다크 읽기전용 안내, 프리뷰 색상 테마 토글이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 색 컨트롤 다크 프리셋 안내 일본어 번역 추가 (`layout_editor.property_modal.dark_preset_only`, `layout_editor.property_modal.scope.reset_to_base`) — 다크 모드에서 자유 색 대신 프리셋 색을 고르라는 안내와 기본값 초기화 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 속성 탭 선택지 목록 편집 라벨 일본어 번역 추가 (`layout_editor.list_editor.{option_value,option_label,bound_degraded}`) — 속성 탭에서 선택지(options) 배열을 값·라벨로 편집하는 입력 라벨과, 데이터에 연결된 목록의 "코드 편집" 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 목록 빌트인(자식 항목 편집) 라벨 일본어 번역 추가 (`layout_editor.list_editor.{items_title,item_text,new_item,add_child,no_child_component}`) — 목록/컨테이너 컴포넌트(Ul/Ol/Nav/Form/Li)의 자식 항목 추가·텍스트 편집 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 겹친 부모 선택 라벨 일본어 번역 추가 (`layout_editor.overlay.select_parent`) — 부모/자식 크기가 같아 겹친 경우 부모 요소를 선택하는 타입 칩 툴팁이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 커스텀 다국어 키 검증 메시지 일본어 번역 추가 (`validation.custom_translation.*`) — 인라인 편집으로 등록하는 동적 다국어 키의 입력 검증 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 환경설정 로그 드라이버·드라이버 조건부 필수·드라이버 필드명 검증 메시지 일본어 번역 추가 (`validation.settings.{log_*,*_required}`, `validation.attributes.*`) — 로그/스토리지/캐시/세션/웹소켓 드라이버 설정 입력 검증 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 다국어 인라인 편집·콘텐츠 로케일 전환·서식 툴바·번역 탭 라벨 일본어 번역 추가 (`layout_editor.{locale,inline_edit,translation}.*`) — 콘텐츠 언어 전환 토글, 더블클릭 인라인 편집 힌트·서식 툴바(굵기/기울임/밑줄/정렬/크기/색상), 속성 모달 번역 탭의 로케일별 일괄 편집 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 커스텀 다국어 키 관리 모달 라벨 일본어 번역 추가 (`layout_editor.chrome.toolbar.translations`, `layout_editor.translation_manager.*`, `validation.custom_translation.ids.*`) — 상단 🌐 다국어 버튼, 키 목록·상태 필터(전체/사용중/미사용)·미사용 배지·번역 일괄 편집·삭제/일괄 삭제 안내와 일괄 삭제 입력 검증이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 디바이스 분기 편집 라벨 일본어 번역 추가 (`layout_editor.device.portable`·`layout_editor.context_menu.{separate_branch*,merge_branch*,device_portable}`·`layout_editor.overlay.jump_device*`·`layout_editor.property_modal.{scope.device_portable,branch_badge}`) — 디바이스 토글의 "모바일+태블릿" 구간, 디바이스 전용 구성 분리/해제·다른 디바이스 구성으로 이동 버튼과 안내, 특정 디바이스 전용 구성 안내 배지가 일본어 로케일에서 자연스럽게 표시됩니다.

## [1.0.0-beta.2] - 2026-05-12

### Added

- 본인인증 활동 로그 액션 라벨 일본어 번역 추가 (`verify`, `verify_failed`)

### Changed

- 본인인증 관리자 권한 시드를 코어의 조회/수정 분리 정책에 맞춰 갱신
  - `core.admin.identity.manage` → `core.admin.identity.providers.update`
  - `core.admin.identity.policies.manage` → `core.admin.identity.policies.update`
  - `core.admin.identity.providers.read`, `core.admin.identity.policies.read` 신설
- 코어 최소 요구 버전을 `>=7.0.0-beta.5` 로 상향 (신 권한 키가 코어 beta.5 시드에 의존)

## [1.0.0-beta.1] - 2026-05-11

### Added

- 코어의 일본어 번들 언어팩 초기 제공
