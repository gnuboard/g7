<?php

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
        'dictionary' => '이름 사전 기록 실패',
        'db_error' => '데이터베이스 오류',
        'listener_exception' => '확장 리스너 오류',
        'unknown_goal' => '등록되지 않은 목표',
        'unknown_dimension' => '등록되지 않은 차원',
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

    // 적재 방식
    'dispatch_mode' => [
        'queue' => '큐로 적재',
        'sync' => '즉시 적재',
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
    ],
];
