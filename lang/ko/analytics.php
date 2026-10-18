<?php

// changelog:allow 미출시 7.1.0 방문자 통계의 누락 키 보충 — 배포된 적이 없어 운영자가
// 원문 키를 본 화면 자체가 없다. 기능 자체는 같은 절의 Added 항목이 이미 서술한다.

return [
    // 개인정보 수집 모드
    'privacy_mode' => [
        'full' => 'IP 저장',
        'consent' => '동의 연동',
        'anonymous' => '익명',
    ],

    // 방문자 유형
    'visitor_type' => [
        'guest' => '비회원',
        'member' => '회원',
        'admin' => '관리자',
        'bot' => '봇·크롤러',
    ],

    // 수집 경로
    'visit_source' => [
        'beacon' => '브라우저',
        'server' => '서버',
    ],

    // 액션 종류
    'action_type' => [
        'pageview' => '페이지 열람',
        'download' => '파일 내려받기',
        'outlink' => '외부 링크',
        'site_search' => '사이트 검색',
        'event' => '이벤트',
        'goal' => '전환',
    ],

    // 유입 종류
    'referrer_type' => [
        'direct' => '직접 유입',
        'search' => '검색엔진',
        'website' => '다른 사이트',
        'campaign' => '캠페인',
    ],

    // 기기 종류
    'device_type' => [
        'desktop' => '데스크톱',
        'mobile' => '휴대전화',
        'tablet' => '태블릿',
        'other' => '기타',
    ],

    // 배치 실행 종류
    'archive_run_type' => [
        'hour' => '시간 집계',
        'day' => '일 집계',
        'reaggregate' => '구간 재집계',
        'anonymize' => '소급 익명화',
        'purge' => '전체 삭제',
        'extension_purge' => '확장 데이터 정리',
    ],

    // 저장 현황 표 이름 (설정 › 보존 — 표 이름 옆에 보인다)
    'storage_table' => [
        'analytics_visits' => '방문 기록',
        'analytics_actions' => '페이지뷰·이벤트 기록',
        'analytics_action_names' => '페이지·이벤트 이름',
        'analytics_conversions' => '전환 기록',
        'analytics_bot_hits' => '봇 방문 기록',
        'analytics_stats_hourly' => '시간별 집계',
        'analytics_stats_daily' => '일별 집계',
        'analytics_dimension_stats' => '확장별 분류 집계',
        'analytics_salts' => '익명 키 재료',
        'analytics_archive_runs' => '집계 실행 이력',
        'analytics_collect_failures' => '수집 실패 기록',
    ],

    // 배치 실행 상태
    'archive_run_status' => [
        'running' => '실행 중',
        'success' => '성공',
        'failed' => '실패',
        'skipped' => '건너뜀',
    ],

    // 적재 실패 사유
    'collect_failure_reason' => [
        'validation' => '요청 형식 오류',
        'max_actions' => '방문당 액션 상한 초과',
        'db_error' => '데이터베이스 오류',
        'listener_exception' => '확장 리스너 오류',
        'unknown_goal' => '등록되지 않은 목표',
        'unknown_dimension' => '등록되지 않은 분류 기준',
    ],

    // 수집 제외 사유
    'exclusion_reason' => [
        'disabled' => '수집 꺼짐',
        'prefetch' => '미리 가져오기',
        'dnt' => '추적 거부',
        'opt_out' => '방문자 제외 선택',
        'url_pattern' => '제외 경로',
        'ip_rule' => '제외 IP',
        'ua_rule' => '제외 User-Agent',
        'headless' => '자동화 브라우저',
        'cloud_ip' => '클라우드 IP 대역',
        'country' => '국가 필터',
        'bot' => '봇',
        'consent' => '동의 없음',
    ],

    // 국가 필터 방식
    'country_filter_mode' => [
        'none' => '사용 안 함',
        'include' => '지정 국가만 수집',
        'exclude' => '지정 국가 제외',
    ],

    // 유입 URL 익명화
    'referrer_anonymization' => [
        'none' => '원본 유지',
        'domain_only' => '도메인만 저장',
    ],

    // 집계 차원
    'dimension' => [
        'page_url' => '페이지 주소',
        'page_title' => '페이지 제목',
        'entry_page' => '진입 페이지',
        'exit_page' => '이탈 페이지',
        'referrer_type' => '유입 종류',
        'referrer_name' => '유입처',
        'referrer_keyword' => '검색 키워드',
        'campaign' => '캠페인',
        'campaign_source' => '캠페인 유입처',
        'browser' => '브라우저',
        'browser_version' => '브라우저 버전',
        'os' => '운영체제',
        'device_type' => '기기',
        'resolution' => '화면 해상도',
        'browser_language' => '브라우저 언어',
        'locale' => '사이트 언어',
        'country' => '국가',
        'region' => '지역',
        'city' => '도시',
        'site_search' => '사이트 검색어',
        'download' => '내려받은 파일',
        'outlink' => '외부 링크',
        'goal' => '목표',
        'bot_name' => '봇 이름',
        'bot_page' => '봇이 가져간 페이지',
        'campaign_medium' => '캠페인 매체',
        'search_tab' => '검색 탭',
        'event_name' => '이벤트',
        'visit_duration' => '체류시간 구간',
        'visit_pages' => '본 페이지 수 구간',
    ],

    // 체류시간·본 페이지 수 구간 라벨
    'bucket' => [
        'duration_range' => ':from ~ :to',
        'duration_over' => ':from 초과',
        'seconds' => ':n초',
        'minutes' => ':n분',
        'minutes_seconds' => ':m분 :s초',
        'hours' => ':n시간',
        'pages_one' => ':n페이지',
        'pages_range' => ':from~:to페이지',
        'pages_over' => ':from페이지 이상',
    ],

    // 사이트 검색 탭
    'search_tab' => [
        'all' => '전체',
        'posts' => '게시글',
        'products' => '상품',
        'pages' => '페이지',
    ],

    // 집계 배치
    'archive' => [
        'skipped_locked' => '같은 기간을 이미 집계하고 있어 건너뛰었습니다.',
        'skipped_beyond_retention' => '원장 보존 기간이 지나 다시 계산할 기록이 없어 건너뛰었습니다. 이미 있는 집계는 그대로 둡니다.',
        'skipped_finalized' => '이미 확정된 날이라 건너뛰었습니다. 다시 계산하려면 「이미 확정된 날도 다시 집계」를 켜세요.',
        'stale_run' => '끝을 알리지 못하고 멈춘 실행이라 실패로 닫았습니다(워커 종료·시간 초과 등). 필요하면 다시 실행하세요.',
        'job_failed' => '작업이 실패해 실행을 닫았습니다: :reason',
    ],

    // 대시보드 알림
    'alerts' => [
        'ledger_limit_title' => '방문자 통계 기록이 많이 쌓였습니다',
        'ledger_limit_message' => '방문 :visits행, 액션 :actions행이 쌓였습니다. 현재 보존 기간은 :days일입니다 — 보존 기간을 줄이면 오래된 기록이 다음 정리에서 사라집니다.',
        'ledger_limit_action' => '보존 설정 열기',
    ],

    // 정보주체 요청
    'privacy' => [
        'ip_lookup_limited' => '익명 모드로 모은 구간은 최근 :days일치만 IP 로 찾을 수 있습니다 — 그보다 이전 기록은 주소를 되돌릴 수 없습니다.',
        'subjects_fetch_success' => '정보주체 기록을 불러왔습니다.',
        'subject_delete_success' => '정보주체 기록을 삭제했습니다.',
        'anonymize_queued' => '소급 익명화를 시작했습니다.',
    ],

    // API 응답 메시지
    'overview' => [
        'fetch_success' => '방문자 통계 개요를 불러왔습니다.',
    ],
    'report' => [
        'fetch_success' => '리포트를 불러왔습니다.',
        'inactive_dimension' => ':key (꺼진 확장)',
    ],
    'series' => [
        'fetch_success' => '추이를 불러왔습니다.',
    ],
    'patterns' => [
        'fetch_success' => '이용 패턴을 불러왔습니다.',
    ],
    'realtime' => [
        'fetch_success' => '현재 접속자를 불러왔습니다.',
    ],
    'diagnostics' => [
        'fetch_success' => '수집 진단 결과를 불러왔습니다.',
    ],
    'collect_failures' => [
        'fetch_success' => '적재 실패 기록을 불러왔습니다.',
    ],
    'archive_runs' => [
        'fetch_success' => '집계 실행 이력을 불러왔습니다.',
        'not_found' => '집계 실행 이력을 찾을 수 없습니다.',
        'queued' => '집계를 시작했습니다.',
    ],
    'settings' => [
        'fetch_success' => '방문자 통계 설정을 불러왔습니다.',
        'save_success' => '방문자 통계 설정을 저장했습니다.',
    ],
    'opt_out' => [
        'fetch_success' => '수집 제외 상태를 불러왔습니다.',
        'enabled' => '이 브라우저를 방문자 통계에서 제외했습니다.',
        'disabled' => '이 브라우저의 수집 제외를 해제했습니다.',
    ],
    'data' => [
        'fetch_success' => '저장 용량 현황을 불러왔습니다.',
        'purge_success' => '방문자 통계 기록을 모두 삭제했습니다.',
    ],
    'cloud_ranges' => [
        'refresh_success' => '클라우드 IP 대역 목록을 갱신했습니다.',
        'refresh_failed' => '대역 목록을 받지 못해 기존 목록을 그대로 두었습니다.',
    ],
    'visitors' => [
        'fetch_success' => '방문자 기록을 불러왔습니다.',
        'index_success' => '방문자 목록을 불러왔습니다.',
        'anonymous_mode_notice' => '익명 모드에서는 방문자 키가 날마다 바뀝니다 — 같은 사람의 방문은 하루 안에서만 묶입니다.',
    ],
    'summary' => [
        'fetch_success' => '방문자 요약을 불러왔습니다.',
        'disabled' => '공개 방문자 요약이 꺼져 있습니다.',
    ],
    // 검증 오류 문구의 항목명 — 설정 필드는 관리자 「방문자 통계 설정」 화면의 이름과 같다
    'attributes' => [
        'enabled' => '방문자 통계 수집',
        'privacy_mode' => '개인정보 수준',
        'respect_dnt' => 'DNT 존중',
        'visit_timeout_minutes' => '방문 종료 기준(분)',
        'max_actions_per_visit' => '방문당 액션 상한',
        'ip_mask_octets' => 'IP 마스킹',
        'geo_before_mask' => '마스킹 전에 지역 조회',
        'anonymize_referrer' => '유입 URL 익명화',
        'pseudonymize_user_id' => '회원 번호 가명화',
        'visitor_cookie_days' => '방문자 쿠키 유지',
        'excluded_url_patterns' => '제외 URL 패턴',
        'excluded_ips' => '제외 IP·대역',
        'excluded_user_agents' => '제외 UA 문자열',
        'ip_allowlist' => '허용 IP',
        'block_headless' => '자동화 브라우저 제외',
        'block_cloud_ip_ranges' => '클라우드 대역 차단',
        'cloud_ip_ranges_updated_at' => '클라우드 대역 갱신 시각',
        'country_filter_mode' => '국가 필터',
        'country_filter_codes' => '국가 코드',
        'exclude_prefetch' => '프리페치 제외',
        'bot_detector_enabled' => 'SEO 봇 감지 사용',
        'bot_tracking_enabled' => '봇 요청 기록',
        'site_search_enabled' => '사이트 내부 검색어 기록',
        'track_downloads' => '내려받기 기록',
        'track_outlinks' => '외부 링크 클릭 기록',
        'download_extensions' => '내려받기로 볼 확장자',
        'retention_days' => '원장 보존',
        'hourly_retention_days' => '시간 집계 보존',
        'bot_hits_retention_days' => '봇 기록 보존(일)',
        'failure_log_retention_days' => '실패 로그 보존(일)',
        'ledger_warning_actions' => '액션 경고 행수',
        'ledger_warning_visits' => '방문 경고 행수',
        'archive_chunk_size' => '집계 청크(방문)',
        'archive_on_view_enabled' => '화면 열 때 집계',
        'archive_on_view_max_visits' => '화면 열 때 집계 — 오늘 방문 상한',
        'duration_bucket_bounds' => '체류시간 구간 경계(초)',
        'pages_bucket_bounds' => '본 페이지 수 구간 경계',
        'realtime_poll_seconds' => '실시간 폴링(초)',
        'realtime_window_minutes' => '현재 접속자 창(분)',
        'top_n_overview' => '개요 목록 개수',
        'top_n_dashboard' => '대시보드 목록 개수',
        'top_n_detail' => '상세 목록 개수',
        'report_weekly_enabled' => '주간 리포트 메일',
        'report_monthly_enabled' => '월간 리포트 메일',
        'public_summary_enabled' => '사용자 화면 방문자 카운터',
        'preset' => '기간 프리셋',
        'from' => '시작일',
        'to' => '종료일',
        'unit' => '집계 단위',
        'exclude' => '제외 대상',
        'format' => '내보내기 형식',
        'q' => '검색어',
        'sort' => '정렬 기준',
        'label' => '항목',
        'type' => '방문자 유형',
        'minutes' => '조회 창(분)',
        'reason' => '실패 사유',
        'run_type' => '집계 종류',
        'force' => '확정된 날 다시 집계',
        'ip' => 'IP 주소',
        'visitor_key' => '방문자 키',
        'mask_octets' => '가릴 옥텟 수',
        'hash' => '해시 처리',
        'confirm' => '되돌릴 수 없음 확인',
        't' => '액션 종류',
        'url' => '방문 주소',
        'title' => '페이지 제목',
        'ref' => '유입 주소',
        'res' => '화면 해상도',
        'lang' => '브라우저 언어',
        'locale' => '사이트 언어',
        'prev' => '이전 화면 체류 시간(초)',
        'init' => '첫 화면 여부',
        'target' => '대상 주소',
        'name' => '이벤트 이름',
        'goal' => '전환 목표',
        'attrs' => '액션 속성',
        'dims' => '분류 값',
        'ch' => '브라우저 힌트',
        'wd' => '자동화 브라우저 표시',
    ],
];
