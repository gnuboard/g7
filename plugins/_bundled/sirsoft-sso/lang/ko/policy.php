<?php

return [
    'ldap_unavailable' => '이 이메일은 사내 계정으로 로그인합니다. 지금은 사내 계정 서버에 연결할 수 없습니다. 잠시 뒤 다시 시도해 주세요.',
    'site_enforced' => '이 사이트는 회사 계정으로만 로그인합니다. 관리자에게 문의해 주세요.',
    'account_enforced' => '이 계정은 회사 계정으로만 로그인합니다. 로그인 방법은 관리자에게 문의해 주세요.',
    'directory_login_username' => '이 계정은 이 사이트 비밀번호 대신 회사 계정으로 로그인합니다. 아이디 칸에 사내 아이디를, 비밀번호 칸에 회사 비밀번호를 넣어 로그인하세요.',
    'directory_login_email' => '이 계정은 이 사이트 비밀번호 대신 회사 계정으로 로그인합니다. 아이디 칸에 회사 이메일을, 비밀번호 칸에 회사 비밀번호를 넣어 로그인하세요.',
    'password_set_disabled' => '회사 계정으로 로그인하는 회원은 이 사이트 비밀번호를 만들 수 없습니다.',
    'cli' => [
        'item' => '항목',
        'value' => '값',
        'on' => '켜짐',
        'off' => '꺼짐',
        'enforce_site' => '사이트 전체 강제',
        'enforce_roles' => '역할 강제',
        'enforce_linked' => 'SSO 연결 회원 전원 강제',
        'enforced_idps' => '도메인 비밀번호 로그인을 막은 IdP',
        'emergency_users' => '비상 계정 회원 ID',
        'backup' => '해제 전 값 보관',
        'disabled' => 'SSO 강제를 모두 껐습니다. 이전 값은 보관했습니다(--restore 로 되돌림).',
        'restored' => '보관한 SSO 강제를 되돌렸습니다.',
        'no_backup' => '되돌릴 보관 값이 없습니다. --disable 로 끈 적이 없습니다.',
    ],
];
