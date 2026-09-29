<?php

use App\Extension\HookContract\HookContractGuard;

/**
 * 훅 계약 — 사용 중단(deprecated)된 훅 이름과 대체 이름 (손으로 관리).
 *
 * 공개 훅의 이름·인자 모양은 마이너 버전에서 바꾸지 않는다(설계 결정 2026-09-29). 바꿔야 하면 새 훅을 더하고
 * 옛 훅을 여기에 적어 deprecated 로 두며, 제거는 메이저 버전에서만 한다. 이 목록에 있는 훅을 구독하면
 * 등록 시점 가드(HookContractGuard)가 대체 이름을 안내하는 경고를 남긴다.
 *
 * `applyFiltersWithLegacyName` 의 옛 이름은 스캐너가 발화처에서 자동으로 대체 이름을 적으므로 여기 적지 않는다.
 *
 * - replacement: 대신 구독할 훅 (없으면 null — 더 이상 제공하지 않음)
 * - since: 사용 중단된 코어 버전
 * - published: 공개 배포본에서 실제로 발화한 적이 있는가(false 면 옛 이름을 되살려 병행 발화하지 않는다)
 * - note: 사유
 *
 * @see HookContractGuard
 */

return [
    // ── 확장 생명주기 구훅 (식별자 1개만 전달) — 정규 훅이 CLI·어드민 UI 공통으로 발화한다 ──
    'core.modules.installed' => ['replacement' => 'core.modules.after_install', 'since' => '7.1.0', 'published' => true, 'note' => '식별자 1개만 전달하는 구훅'],
    'core.modules.activated' => ['replacement' => 'core.modules.after_activate', 'since' => '7.1.0', 'published' => true, 'note' => '식별자 1개만 전달하는 구훅'],
    'core.modules.updated' => ['replacement' => 'core.modules.after_update', 'since' => '7.1.0', 'published' => true, 'note' => '식별자 1개만 전달하는 구훅'],
    'core.plugins.installed' => ['replacement' => 'core.plugins.after_install', 'since' => '7.1.0', 'published' => true, 'note' => '식별자 1개만 전달하는 구훅'],
    'core.plugins.activated' => ['replacement' => 'core.plugins.after_activate', 'since' => '7.1.0', 'published' => true, 'note' => '식별자 1개만 전달하는 구훅'],
    'core.plugins.updated' => ['replacement' => 'core.plugins.after_update', 'since' => '7.1.0', 'published' => true, 'note' => '식별자 1개만 전달하는 구훅'],
    'core.templates.installed' => ['replacement' => 'core.templates.after_install', 'since' => '7.1.0', 'published' => true, 'note' => '식별자 1개만 전달하는 구훅'],
    'core.templates.activated' => ['replacement' => 'core.templates.after_activate', 'since' => '7.1.0', 'published' => true, 'note' => '식별자 1개만 전달하는 구훅'],
    'core.templates.updated' => ['replacement' => 'core.templates.after_version_update', 'since' => '7.1.0', 'published' => true, 'note' => '식별자 1개만 전달하는 구훅 (레코드 수정용 after_update 와 다르다)'],

    // ── 공개 배포본에서 발화한 적이 없는 이름 — 구독해도 한 번도 실행되지 않는다 ──
    'core.auth.register' => ['replacement' => 'core.auth.after_register', 'since' => '7.1.0', 'published' => false, 'note' => '발화 이름은 처음부터 after_register 였다'],
    'core.auth.forgot_password' => ['replacement' => 'core.auth.after_reset_password_request', 'since' => '7.1.0', 'published' => false, 'note' => '발화 이름은 after_reset_password_request 다'],
    'core.auth.reset_password' => ['replacement' => 'core.auth.after_password_changed', 'since' => '7.1.0', 'published' => false, 'note' => '발화 이름은 after_password_changed 다'],
    'core.module.after_activate' => ['replacement' => 'core.modules.after_activate', 'since' => '7.1.0', 'published' => false, 'note' => '생명주기 훅은 복수형(core.modules.*)이다'],
    'core.module.after_deactivate' => ['replacement' => 'core.modules.after_deactivate', 'since' => '7.1.0', 'published' => false, 'note' => '생명주기 훅은 복수형(core.modules.*)이다'],
    'core.plugin.after_activate' => ['replacement' => 'core.plugins.after_activate', 'since' => '7.1.0', 'published' => false, 'note' => '생명주기 훅은 복수형(core.plugins.*)이다'],
    'core.plugin.after_deactivate' => ['replacement' => 'core.plugins.after_deactivate', 'since' => '7.1.0', 'published' => false, 'note' => '생명주기 훅은 복수형(core.plugins.*)이다'],

    // ── 제공을 중단한 선언 — 주소 선택은 브라우저 흐름이고 프론트엔드 엔진에 확장 훅 체계가 없다 ──
    'sirsoft-daum_postcode.address.selected' => ['replacement' => null, 'since' => '7.1.0', 'published' => false, 'note' => '선언만 있고 발화처가 없었다 — 선언에서 뺐다'],
    'sirsoft-daum_postcode.filter_address_data' => ['replacement' => null, 'since' => '7.1.0', 'published' => false, 'note' => '선언만 있고 발화처가 없었다 — 선언에서 뺐다'],
];
