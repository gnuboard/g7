/**
 * 마이페이지 「내 정보」 ↔ 「내 정보 수정」 항목 정합 (#599).
 *
 * 요구사항(2026-09-21): 「회원상세보기에 나오는 것은 수정하기 화면에서 모두 수정이 가능해야 한다.
 * 위치도 동일하고 양식도 동일해야 한다. … 관리자만 해당되는 것이 아니다. 사용자도 포함이며」
 *
 * 관리자 쪽은 `templates/_bundled/sirsoft-admin_basic/__tests__/layouts/admin-user-detail-form-parity.test.ts`
 * 가 같은 일을 한다. 사용자 쪽은 두 화면이 `data-ref` 를 거의 쓰지 않아 id 순서로는 짝을 지을 수
 * 없어서, **화면이 실제로 쓰는 언어 키**를 짝짓는 축으로 삼는다 — 라벨이 같으면 같은 항목이다.
 *
 * 그래서 이 시험이 막는 것은: 보기 화면에 항목을 하나 더하고 수정 화면을 잊는 일. 실제로 그렇게
 * 아바타·계정 상태·가입일·마지막 로그인·탈퇴일시가 수정 화면에서 빠져 있었다.
 *
 * @vitest-environment jsdom
 */
import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

const root = path.resolve(__dirname, '../../layouts/partials/mypage/profile');
const raw = (file: string) => fs.readFileSync(path.join(root, file), 'utf8');

const view = raw('_view.json');
const edit = raw('_edit.json');

const keysIn = (source: string) => [...new Set(source.match(/mypage\.[a-z_0-9.]+/g) ?? [])].sort();

/**
 * 보기 화면에만 있어도 되는 언어 키 — **항목이 아니라 그 화면의 장치**다.
 *
 * 화면 제목과 두 입구(비밀번호 변경·수정하기)는 보기 화면이 수정 화면으로 가는 길이라 수정
 * 화면에 다시 둘 자리가 없다. 통화·배송국가의 `edit_hint` 는 「고치려면 수정 화면으로」 라는
 * 안내라서, 정작 그 수정 화면에서는 같은 자리에 진짜 선택칸이 선다.
 *
 * 회원 탈퇴는 **일부러** 보기 화면에만 둔다. 되돌릴 수 없는 행위라 편집 중인 폼 옆에 두면
 * 저장하려던 손이 그 버튼에 닿는다 — 항목(고칠 값)이 아니라 행위이고, 그 행위의 확인 절차는
 * `_modal_withdraw` 가 따로 소유한다.
 */
const VIEW_ONLY = [
  'mypage.profile.view_title',
  'mypage.profile.edit_button',
  'mypage.profile.change_password_button',
  'mypage.currency_settings.edit_hint',
  'mypage.shipping_country_settings.edit_hint',
  'mypage.profile.withdraw',
  'mypage.profile.withdraw_button',
  'mypage.profile.withdraw_warning',
  'mypage.profile.admin_cannot_withdraw',
];

describe('마이페이지 내 정보 보기 ↔ 수정 정합 (#599)', () => {
  // @scenario surface=mypage_profile
  // @effects mypage_profile_edit_carries_every_view_item
  it('보기 화면의 항목이 수정 화면에도 모두 있다', () => {
    const missing = keysIn(view)
      .filter((key) => !VIEW_ONLY.includes(key))
      .filter((key) => !edit.includes(key));

    expect(missing).toEqual([]);
  });

  // @scenario surface=mypage_profile
  // @effects mypage_profile_edit_shows_account_status
  it('수정 화면이 계정 상태를 말한다 — 배지와 세 가지 안내', () => {
    // 차단·탈퇴 상태면 저장이 거절되는데, 종전에는 그 이유가 보기 화면에만 있었다.
    expect(edit).toContain('mypage.profile.status_inactive_notice');
    expect(edit).toContain('mypage.profile.status_blocked_notice');
    expect(edit).toContain('mypage.profile.status_withdrawn_notice');
    expect(edit).toContain('status_label');
  });

  // @scenario surface=mypage_profile
  // @effects mypage_profile_edit_carries_the_avatar_uploader
  it('사진은 수정 화면에서 바꾼다 — 보기 화면과 같은 업로더가 선다', () => {
    // 종전에는 「수정하기」 로 들어온 화면에서 사진만은 되돌아 나가야 바꿀 수 있었다.
    expect(edit).toContain('AvatarUploader');
    expect(view).toContain('AvatarUploader');
  });

  // @scenario surface=mypage_profile
  // @effects mypage_profile_edit_carries_record_only_dates
  it('가입일·마지막 로그인·탈퇴일시가 수정 화면에도 선다 (읽기 전용)', () => {
    for (const key of ['mypage.profile.joined_at', 'mypage.profile.last_login', 'mypage.profile.withdrawn_at']) {
      expect(edit).toContain(key);
    }
    // 고칠 수 있는 값이 아니므로 폼에 실리는 `name` 바인딩이 붙어서는 안 된다.
    expect(edit).not.toMatch(/"name":\s*"(created_at|last_login_at|withdrawn_at)"/);
  });
});
