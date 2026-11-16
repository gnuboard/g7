<?php

return [
    // 검증 상태 이름 (배지·명령 출력)
    'statuses' => [
        'verified' => '서명 확인됨',
        'unsigned' => '서명 없음',
        'invalid' => '서명 불일치',
        'untrusted' => '신뢰하지 않는 키',
        'bundled' => '번들 원본',
        'unverifiable' => '서명 검증 불가',
        'unknown' => '기록 없음',
    ],

    // 검증 사유 (PackageVerification::$reason)
    'reasons' => [
        'unsigned' => '이 패키지에는 서명 파일이 없습니다.',
        'format_unknown' => '서명 파일의 형식을 알 수 없습니다(지원하지 않는 형식·알고리즘이거나 필수 항목이 빠졌습니다).',
        'type_mismatch' => '서명 파일의 패키지 종류(:actual)가 설치하려는 종류(:expected)와 다릅니다.',
        'identifier_mismatch' => '서명 파일의 식별자(:actual)가 설치하려는 식별자(:expected)와 다릅니다.',
        'version_mismatch' => '서명 파일의 버전(:actual)이 받으려는 버전(:expected)과 다릅니다.',
        'size_mismatch' => '패키지 파일 크기가 서명 파일에 적힌 크기와 다릅니다. 파일이 바뀌었거나 덜 받아졌습니다.',
        'digest_mismatch' => '패키지 파일의 SHA-256 값이 서명 파일과 다릅니다. 파일이 바뀌었습니다.',
        'key_unknown' => '서명한 키(:key)가 신뢰 키 목록에 없습니다.',
        'key_revoked' => '서명한 키가 폐기된 키입니다.',
        'key_id_mismatch' => '서명 키 식별자가 등록된 공개키와 맞지 않습니다.',
        'core_requires_builtin_key' => '코어 패키지는 코어에 내장된 공식 키로 서명돼야 합니다. 제작자 키로 서명한 코어는 받지 않습니다.',
        'vendor_out_of_scope' => '서명한 제작자 키가 이 패키지의 vendor(:vendor)를 믿도록 등록돼 있지 않습니다.',
        'sodium_missing' => '서명 파일은 있지만 이 서버에 PHP sodium 확장이 없어 서명을 확인할 수 없습니다.',
        'signature_mismatch' => '서명 값이 패키지 내용과 맞지 않습니다. 파일 또는 서명이 바뀌었습니다.',
        'manifest_identifier_mismatch' => '패키지 안 manifest 의 식별자(:actual)가 서명된 식별자(:expected)와 다릅니다.',
        'manifest_version_mismatch' => '패키지 안 manifest 의 버전(:actual)이 서명된 버전(:expected)과 다릅니다.',
    ],

    'errors' => [
        'package_unsigned' => '서명되지 않은 패키지입니다. 내용을 확인했다면 「미서명으로 계속」을 눌러 설치하세요. :reason',
        'package_unverifiable' => '이 서버에서 패키지 서명을 확인할 수 없습니다. 내용을 확인했다면 「미서명으로 계속」을 눌러 설치하세요. :reason',
        'package_unsigned_blocked' => '환경설정 > 보안 「미서명 패키지 차단」이 켜져 있어 서명되지 않은 패키지를 설치할 수 없습니다. :reason',
        'package_signature_invalid' => '패키지 서명이 맞지 않아 설치를 멈췄습니다. :reason',
        'package_signer_untrusted' => '신뢰하지 않는 키로 서명된 패키지라 설치를 멈췄습니다. :reason',
        'signature_url_not_public' => '서명 파일 주소는 공개 http·https 주소여야 합니다.',
        'signature_download_failed' => '서명 파일을 내려받지 못했습니다. (HTTP :status)',
        'signature_too_large' => '서명 파일이 너무 큽니다(최대 64KB).',
        'signature_file_not_found' => '서명 파일을 찾을 수 없습니다: :path',
        'label_invalid' => '서명자 이름은 영문·숫자·_·- 로 32자 이내여야 합니다.',
    ],

    // 신뢰 키 관리 (환경설정 > 보안 > 확장 신뢰 키, API)
    'trust_keys' => [
        'fetch_success' => '확장 신뢰 키 목록을 불러왔습니다.',
        'created' => '신뢰 키를 추가했습니다.',
        'deleted' => '신뢰 키를 삭제했습니다.',
        'not_found' => '신뢰 키를 찾을 수 없습니다.',
        'password_invalid' => '비밀번호가 맞지 않습니다.',
        'reauth_invalid' => '본인 확인이 만료됐거나 올바르지 않습니다. 다시 확인하세요.',
        'create_failed' => '신뢰 키를 추가하지 못했습니다.',
        'delete_failed' => '신뢰 키를 삭제하지 못했습니다.',
        'fetch_failed' => '신뢰 키 목록을 불러오지 못했습니다.',
    ],

    // 신뢰 키 입력 검증
    'validation' => [
        'public_key_required' => '공개키를 입력하세요.',
        'public_key_invalid' => '공개키는 Ed25519 공개키(32바이트)의 표준 base64 값이어야 합니다.',
        'label_required' => '서명자 이름을 입력하세요.',
        'label_invalid' => '서명자 이름은 영문·숫자·_·- 로 32자 이내여야 합니다.',
        'label_reserved' => '서명자 이름 sirsoft 는 코어 내장 키 전용이라 쓸 수 없습니다.',
        'vendor_scope_required' => '이 키를 믿을 vendor 를 하나 이상 지정하세요.',
        'vendor_invalid' => 'vendor 는 영문 소문자·숫자로 시작하는 확장 식별자 앞부분이어야 합니다.',
        'vendor_reserved' => 'vendor sirsoft 는 코어 내장 키 전용이라 제작자 키에 지정할 수 없습니다.',
        'duplicate_key' => '이미 등록된 키입니다.',
        'builtin_conflict' => '코어 내장 키와 같은 키는 등록할 수 없습니다.',
        'password_required' => '본인 확인을 위해 비밀번호를 입력하세요.',
        'allow_unsigned_boolean' => '미서명 계속 여부는 true 또는 false 값이어야 합니다.',
        'integrity_aware_boolean' => '패키지 서명 확인 화면 표식은 true 또는 false 값이어야 합니다.',
        'signature_file' => '서명 파일(.sig.json)을 올리세요.',
        'signature_max' => '서명 파일은 64KB 를 넘을 수 없습니다.',
        'signature_url_invalid' => '서명 파일 주소 형식이 올바르지 않습니다.',
        'block_unsigned_boolean' => '미서명 패키지 차단은 true 또는 false 값이어야 합니다.',
    ],

    'attributes' => [
        'public_key' => '공개키',
        'label' => '서명자 이름',
        'vendor_scope' => '신뢰 vendor',
        'password' => '비밀번호',
        'allow_unsigned' => '미서명으로 계속',
        'integrity_aware' => '패키지 서명 확인 화면 표식',
        'signature' => '서명 파일',
        'signature_url' => '서명 파일 주소',
        'extension_block_unsigned' => '미서명 패키지 차단',
    ],

    'commands' => [
        'status_line' => '패키지 서명: :status',
        'verified_line' => '패키지 서명 확인됨 — 서명자 :label (:key)',
        'unsigned_confirm' => '이 패키지는 서명되지 않았습니다(:reason). 그래도 계속할까요?',
        'unsigned_noninteractive_hint' => '내용을 확인했다면 --allow-unsigned 를 붙여 다시 실행하세요. --force 는 미서명 확인을 대신하지 않습니다.',
        'unsigned_blocked_hint' => '환경설정 > 보안 「미서명 패키지 차단」을 끄기 전에는 --allow-unsigned 로도 진행할 수 없습니다.',
        'signature_not_found' => '서명 파일을 찾을 수 없습니다: :path',
    ],

    // php artisan about 의 패키지 무결성 구획
    'about' => [
        'section' => '패키지 무결성',
        'builtin_keys' => '내장 신뢰 키',
        'sodium' => 'PHP sodium 확장',
        'block_unsigned' => '미서명 패키지 차단',
        'available' => '사용 가능',
        'missing' => '없음 — 서명을 검증할 수 없어 미서명 패키지로 다룹니다',
        'on' => '켜짐',
        'off' => '꺼짐',
        'unknown' => '알 수 없음',
    ],
];
