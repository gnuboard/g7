<?php

return [
    'label' => 'OpenID Connect',
    'config' => [
        'discovery_url' => ['label' => '디스커버리 주소', 'hint' => 'IdP 의 .well-known/openid-configuration 주소입니다.'],
        'client_id' => ['label' => '클라이언트 ID', 'hint' => 'IdP 콘솔에서 이 사이트를 앱으로 등록하고 받은 값입니다.'],
        'client_secret' => ['label' => '클라이언트 비밀값', 'hint' => '저장하면 다시 보이지 않습니다. 바꿀 때만 새로 입력하세요.'],
        'scopes' => ['label' => '범위(scope)', 'hint' => '공백으로 구분합니다. 주기 점검을 켜면 offline_access 를 더하세요.'],
        'subject_claim' => ['label' => '고유 식별자 클레임', 'hint' => '기본 sub. Entra ID 는 앱마다 sub 가 달라 oid 를 고르세요(SCIM externalId 와 같은 값).'],
        'subject_claim_custom' => ['label' => '지정 클레임 이름', 'hint' => '고유 식별자 클레임이 「지정」일 때 쓸 클레임 이름입니다.'],
        'claim_email' => ['label' => '이메일 클레임', 'hint' => '기본 email.'],
        'claim_email_verified' => ['label' => '이메일 검증 클레임', 'hint' => '기본 email_verified. 소유 도메인 자동 연결은 이 값이 참일 때만 합니다.'],
        'claim_name' => ['label' => '이름 클레임', 'hint' => '기본 name.'],
        'claim_groups' => ['label' => '그룹 클레임', 'hint' => '기본 groups. 배열 또는 공백 구분 문자열을 받습니다.'],
        'mfa_acr_values' => ['label' => '다중 인증으로 볼 acr 값', 'hint' => 'IdP 가 amr 대신 acr 로 다중 인증을 알릴 때 그 값을 적습니다.'],
        'use_pkce' => ['label' => 'PKCE 사용', 'hint' => '켜 두기를 권장합니다(S256).'],
        'token_endpoint_auth' => ['label' => '토큰 주소 인증 방식', 'hint' => 'client_secret_post 또는 client_secret_basic.'],
        'fetch_userinfo' => ['label' => 'userinfo 조회', 'hint' => 'id_token 에 이메일이 없을 때만 userinfo 를 조회합니다.'],
    ],
    'sp' => [
        'redirect_uri' => 'Redirect URI (로그인 콜백)',
        'post_logout_redirect_uri' => '로그아웃 뒤 복귀 주소',
        'backchannel_logout_uri' => 'Back-channel 로그아웃 주소',
        'scopes' => '요청 범위',
    ],
    'test' => [
        'discovery_ok' => '디스커버리 문서를 읽었습니다.',
        'discovery_failed' => '디스커버리 문서를 읽지 못했습니다.',
        'metadata_ok' => '필수 메타 키가 모두 있습니다.',
        'jwks_ok' => '서명 키를 읽었습니다.',
        'jwks_empty' => '서명 키 목록이 비어 있습니다.',
        'jwks_failed' => '서명 키를 읽지 못했습니다.',
        'redirect_uri' => 'IdP 콘솔에 아래 Redirect URI 가 등록되어 있어야 합니다.',
    ],
    'mock' => [
        'notice' => '개발·검수용 모의 IdP 입니다. 응답 변종을 고르고 승인하세요.',
    ],
];
