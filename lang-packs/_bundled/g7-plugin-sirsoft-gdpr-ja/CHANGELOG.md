# Changelog

이 언어팩의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.1.0] - 2026-10-10

### Added

#### 개인정보 연간 통지
- 개인정보처리방침 주소 설정(`settings.fields.privacy_policy_url.*`)과 그 검증 문구, 스냅샷의 옛 슬러그 기록 안내(`legacy_slug_note`), 주소 미등록 안내(`no_policy_url`), 연간 통지 메일의 처리방침 링크 문구의 일본어 번역을 추가했습니다.
- 개인정보 연간 통지 「지금 발송」 직권 실행의 일본어 번역을 추가했습니다 — 확인 창·진행 상태 줄·남은 건수 문구(`annual_notice.manual_run.*`)와 직권 실행 대상 이름(`messages.annual_notice.manual_run_label`), 연간 통지 대상 줄 이름(`annual_notice.targets.row_label`)입니다.
- 개인정보 연간 통지 설정의 통지 대상 건수와 「대상 보기」 명단 창 문구(`annual_notice.targets.*`), 명단 열 이름(이름·이메일·가입일, `annual_notice.target_columns.*`), 레이아웃 편집기의 데이터 소스 이름(`editor.data_source.privacyTargets`)의 일본어 번역을 추가했습니다.
- 개인정보 연간 통지 설정·발송 이력·알림 본문과 통지 이력 메뉴의 일본어 번역을 추가했습니다.
  - 사이트내 알림 본문은 원본과 같은 평문 변수(`{introduction_text}`·`{notice_sections_text}`)를 써 알림함에 HTML 태그가 글자로 보이지 않습니다.
  - 「개인정보 통지 이력」 관리자 메뉴 이름은 메뉴 시드에 들어 있어 언어팩을 켜면 그 메뉴도 일본어로 보입니다.

#### 설정·저장 검증
- 환경설정 「동의 관리 제공자」 목록에 보이는 이 플러그인의 이름(`messages.consent_provider.label`, 「クッキー同意バナー (sirsoft-gdpr)」)의 일본어 번역을 추가했습니다.
- 필수 저장 항목의 「확장이 제공하는 항목(자동)」 라벨·안내(`necessary_storage_allowlist.provided_label`·`provided_hint`)와 레이아웃 편집기에 쓰이는 연간 통지 대상·직권 발송 창 이름의 일본어 번역을 추가했습니다.
- 저장 오류 문구의 항목명 12건 일본어 번역을 추가했습니다 — 통지 이력 조회·시험 발송·쿠키 카테고리·차단 도메인 입력칸의 이름입니다.
- 설정 저장 검증의 새 문구를 일본어로 번역했습니다.
  - 자동 통지를 켠 채 채널이나 상한을 비웠을 때의 안내(`settings.fields.annual_notice_channels.required_when_enabled`, `annual_notice_batch_size.required_when_enabled`)
  - 서문 칸 이름의 언어 표기(`annual_notice_introduction.label_with_language`), 괄호 조사 없는 대체 문구(`validation.fallback.*`)
  - 알림 템플릿 카드의 권한 없음과 불러오기 실패 안내(`notification_definitions.forbidden_message`, `load_failed_message`)
- 개인정보 설정 읽기 전용 안내의 일본어 번역을 추가했습니다.

#### 알림 템플릿·메뉴
- 알림 템플릿 편집 창 「사용 가능한 변수」 설명(`messages.notification_variables.*`)의 일본어 번역을 추가했습니다.
- 알림 템플릿 카드와 편집·미리보기·기본값 복원 창의 일본어 번역을 추가했습니다 — 채널별 제목·본문·수신자를 고치는 화면 문구와 0건 안내 문구입니다. 없으면 일본어 로케일에서 이 카드가 통째로 한국어로 남습니다.
- 메뉴 시드를 신설해 `sirsoft-gdpr-consent-log`(GDPR 동의 이력)의 일본어 번역을 추가했습니다 — 종전에는 플러그인 언어팩의 메뉴 시드가 적용 대상에서 제외돼 있어 이 항목만 번역 경로가 없었습니다.

### Changed

- 운영 정보 설명·저장 항목 찾기 안내·정책 버전 스냅샷 설명과 항목명을 한국어 원문 변경(페이지 슬러그 → 처리방침 주소, 확장 제공 항목 자동 표시)에 맞춰 다시 번역했습니다.
- 정책 버전 스냅샷 창 안내(`settings.policy_version.snapshot_modal.description`)에서 내부 설정 이름(settings)을 빼고 원문 변경에 맞춰 다시 번역했습니다.

### Removed

- 페이지 슬러그 설정(`settings.fields.privacy_policy_slug.*`)과 슬러그 미등록 안내(`snapshot_modal.no_policy_slug`) 번역을 정리했습니다.

### Fixed

- 동의 일괄 갱신 API 응답 문구(`consent.renew_all_success`)에 갱신 수가 채워지지 않고 「アクティブな項目 {renewed} 個を…」처럼 자리표시자가 그대로 나오던 문제를 수정했습니다(`{renewed}` → `:renewed`).
- 문자 메시지 플러그인을 쓰는 일본어 사이트에서 연간 개인정보 이용내역 통지의 문자·알림톡 제목과 본문이 일본어 없이 남던 문제를 수정했습니다.

## [1.0.2] - 2026-09-06

### Added

- 동의 이력의 출처 "会員退会"(회원탈퇴) 일본어 라벨 추가
- 「必須の保存項目」(필수 저장 항목) 설정 화면의 일본어 문구 추가 — 카드 제목·설명, 저장소 구분 3종(ブラウザストレージ / セッションストレージ / クッキー) 라벨과 설명, 안내 4항목(목적·와일드카드 표기·항목 이름 찾는 법·필수 항목만 등록), 잠금 항목 라벨·안내, 입력 placeholder
- 필수 저장 항목 허용목록의 검증 메시지 일본어 추가 — 저장소 구분별 형식 위반·길이 초과 메시지, 배열 형식 오류, 알 수 없는 저장소 구분

### Changed

- 자동 차단 정책 안내의 기능 카테고리 범위 문구에서 다크모드를 뺐습니다 — 화면 테마가 필수 항목으로 재분류되어 동의 여부와 무관하게 저장됩니다.

## [1.0.1] - 2026-08-10

### Added

- 쿠키 거부 기능 및 마이페이지 「쿠키 동의 관리」 재설계에 따른 신규 문구(상태 배지, 동의·철회 확인 모달, 거부 안내 등) 일본어 번역 추가
- 동의 이력의 출처 "会員登録" / "マイページ一括再同意" 일본어 라벨 추가
- 정책 버전 이력 페이지 이동(前へ / 次へ)과 총 건수 표시 일본어 번역 추가
- 자동 차단 정책 카드에 보호 범위 한계 안내 문구(운영자가 직접 삽입한 외부 스크립트는 보호 범위 밖) 일본어 번역 추가

### Changed

- 초기 토글 방식 설계에서 쓰였던 미사용 문구(항상 켜짐/켜짐/꺼짐) 번역 제거

## [1.0.0] - 2026-07-01

### Added

- 쿠키 동의 배너·약관·데이터 요청 등 사용자 화면 프론트엔드 텍스트 일본어 번역 추가 — 관련 화면이 일본어 로케일에서 자연스럽게 표시됩니다.
- GDPR 플러그인(sirsoft-gdpr)의 일본어 번들 언어팩 초기 제공
