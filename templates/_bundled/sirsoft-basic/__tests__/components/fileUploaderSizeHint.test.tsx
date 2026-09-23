import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * 첨부 드롭존은 **자기가 지키지 않는 상한을 말하지 않는다** (SA-D5)
 *
 * 클레임 신청서는 용량 상한을 컴포지트에 넘기지 않는다 — 넘기면 컴포지트가 초과 파일을
 * 자기가 먼저 걸러낸 뒤 change 를 쏘므로, 거절된 파일이 핸들러에 닿지 않아 사용자는
 * 사진을 골랐는데 화면에 아무 변화가 없다(S2-D10). 용량 판정과 안내는 서버 설정
 * `claim.image_max_size_kb` 를 읽는 핸들러가 한다.
 *
 * 그런데 컴포지트가 `maxSize` 기본값 10 을 들고 있어서, 상한을 **넘기지 않은** 화면에도
 * 드롭존이 「최대 5개, 각 10MB까지」를 찍었다. 같은 화면 바로 위 안내는 서버값 그대로
 * 「1장당 2048KB」라 한 화면에서 두 상한이 맞선다(#599 세션A 실측 2026-09-21 · 비회원·회원
 * 신청서 양쪽 재현 · 관리자 상세는 둘 다 2MB 로 일관). 원 계획서가 고정한 서버 설정은
 * `image_max_size_kb=2048`(= 2MB)이므로 **2MB 가 참**이고 10MB 는 근거 없는 문구다.
 *
 * 계약: 호출부가 상한을 선언하지 않았으면 드롭존은 **장수만** 말한다.
 */
describe('FileUploader — 선언하지 않은 용량 상한을 말하지 않는다 (SA-D5)', () => {
  const read = (rel: string) => readFileSync(resolve(__dirname, '../../', rel), 'utf8');

  // @scenario max_size_declared=omitted
  // @effects dropzone_states_only_the_limit_it_enforces
  it('maxSize 에 리터럴 기본값 10 을 박아 두지 않는다', () => {
    const source = read('src/components/composite/FileUploader/FileUploader.tsx');

    // `maxSize = 10` 이 살아 있으면 상한을 넘기지 않은 화면이 10MB 를 광고한다.
    expect(source).not.toMatch(/maxSize\s*=\s*10\s*,/);
  });

  // @scenario max_size_declared=declared
  // @effects dropzone_states_only_the_limit_it_enforces
  it('드롭존은 상한이 있을 때만 용량 문구를 쓰고, 없으면 장수만 말한다', () => {
    const source = read('src/components/composite/FileUploader/FileDropZone.tsx');

    // 상한 유무로 갈리는 분기가 있어야 한다 — 무조건 upload_limit 를 찍으면 안 된다.
    expect(source).toMatch(/attachment\.upload_limit_count/);
    expect(source).toMatch(/maxSize\s*(!=|!==)\s*(null|undefined)/);
  });

  // @scenario max_size_declared=omitted
  // @effects composite_filters_only_when_limit_declared
  it('용량 필터는 상한이 선언됐을 때만 돈다', () => {
    const source = read('src/components/composite/FileUploader/useFileUploader.ts');

    // 상한 미선언이면 컴포지트가 조용히 거르지 않고 핸들러가 판정한다(S2-D10 계약).
    expect(source).toMatch(/maxSize\s*(!=|!==)\s*(null|undefined)[\s\S]{0,80}file\.size\s*>/);
  });

  // @scenario max_size_declared=omitted
  // @effects count_only_copy_exists_in_all_locales
  it('장수 전용 문구가 ko·en·ja 세 언어에 모두 있다', () => {
    // ko·en 은 템플릿 자체 랭팩, ja 는 별도 언어팩 확장이 채운다.
    const packs: Array<[string, string]> = [
      ['ko', 'lang/partial/ko/attachment.json'],
      ['en', 'lang/partial/en/attachment.json'],
      ['ja', '../../../lang-packs/_bundled/g7-template-sirsoft-basic-ja/frontend/partial/attachment.json'],
    ];

    for (const [locale, rel] of packs) {
      const pack = JSON.parse(read(rel));
      expect(pack.upload_limit_count, `[${locale}] attachment.upload_limit_count 누락`).toBeTruthy();
      expect(pack.upload_limit_count).toContain('{{maxFiles}}');
      // 장수만 말하는 문구이므로 용량 자리표시자가 들어가면 안 된다.
      expect(pack.upload_limit_count).not.toContain('{{maxSize}}');
    }
  });

  /**
   * 클레임 신청서는 **장수와 용량을 둘 다** 넘기고, 컴포지트가 거절한 사유를 받는다. (T2-D2)
   *
   * 종전(S2-D10)에는 용량을 넘기지 않았다. 근거는 「넘기면 컴포지트가 초과 파일을 먼저 걸러
   * change 를 쏘지 않아, 사용자는 사진을 골랐는데 화면에 아무 변화가 없다」였다. 그런데 실은
   * 컴포지트가 그 자리에서 `onUploadError` 를 부르고 있었고 **화면이 그 이벤트를 받지 않았을
   * 뿐**이다 — 받으면 사유가 그 자리에 뜨므로 그 근거가 사라진다.
   *
   * 넘기지 않은 쪽의 대가가 더 컸다. 거절된 파일이 컴포지트 목록에 `big.jpg 8.52 MB` ·
   * `1/5개 첨부됨` 으로 남아 **첨부된 것처럼 보였고**, 토스트가 5초 뒤 사라지면 화면과 실제가
   * 갈렸다(2026-09-22 #599 3차 실측 T2-D2).
   *
   * 위 SA-D5 가 리터럴 기본값 10 을 없애고 드롭존을 「상한이 있을 때만 용량을 말한다」로
   * 바꿔 둔 덕에, 지금 넘기는 값은 **서버 설정 그대로**(2MB)라 안내와 거절선이 갈리지 않는다.
   */
  // @scenario max_size_declared=declared
  // @effects claim_form_declares_the_limit_and_takes_the_rejection
  it('클레임 신청서는 용량 상한을 넘기고 거절 사유를 받는다 (T2-D2)', () => {
    const layout = read('layouts/partials/mypage/claims/_apply_reason.g7.html');
    const tag = layout.match(/<file-uploader[^>]*>/)?.[0] ?? '';

    expect(tag).toBeTruthy();
    expect(tag).toMatch(/maxFiles/);
    // 리터럴이 아니라 같은 설정값에서 온다 — 컴포지트가 MB 를 받으므로 서버가 같은 상한을 MB 로도 내려 준다.
    expect(tag).toMatch(/maxSize/);
    expect(tag).toContain('image_max_size_mb');

    // 거절 사유를 받지 않으면 종전 근거(「아무 일도 없어 보인다」)가 되살아난다.
    const block = layout.slice(layout.indexOf('<file-uploader'), layout.indexOf('</file-uploader>'));
    expect(block).toContain('onUploadError');
  });
});
