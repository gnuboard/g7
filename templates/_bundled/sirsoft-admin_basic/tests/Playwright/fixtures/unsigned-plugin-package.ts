/**
 * 관리자 템플릿 E2E — 서명 없는 최소 플러그인 패키지(ZIP) 픽스처 (#916 ④ 패키지 무결성).
 *
 * 수동 설치 창이 받는 ZIP 은 최상위 디렉터리 하나(`{identifier}-{version}/`) 안에 manifest 와 진입 파일을 둔다(GitHub zipball 과
 * 같은 구조). 서명 파일(.sig.json)을 함께 보내지 않으므로 서버는 「서명 없음」으로 판정해 409 를 돌려준다. 이진 파일을 저장소에
 * 두지 않으려고 원본을 코드로 담고, 시험 때 무압축(stored) ZIP 으로 만든다 — 의존 패키지 없이 CRC-32 와 머리말만 쓴다.
 */

/** 시험 플러그인 식별자 — vendor 는 sirsoft 가 아니어야 한다(내장 키 전용 vendor) */
export const UNSIGNED_PLUGIN_ID = 'glitter-e2e_unsigned';

/** 시험 플러그인 판본 */
export const UNSIGNED_PLUGIN_VERSION = '0.1.0';

const FILES: Record<string, string> = {
  'composer.json': JSON.stringify(
    {
      name: `plugins/${UNSIGNED_PLUGIN_ID}`,
      type: 'library',
      version: UNSIGNED_PLUGIN_VERSION,
      autoload: { 'psr-4': { 'Plugins\\Glitter\\E2eUnsigned\\': ['src/', './'] } },
      require: { php: '^8.2' },
    },
    null,
    4,
  ),
  'plugin.json': JSON.stringify(
    {
      identifier: UNSIGNED_PLUGIN_ID,
      vendor: 'glitter',
      name: { ko: 'E2E 미서명 설치 시험 플러그인', en: 'E2E unsigned install test plugin' },
      version: UNSIGNED_PLUGIN_VERSION,
      license: 'MIT',
      description: { ko: '수동 설치 창 미서명 흐름 E2E 시험용', en: 'For the manual install unsigned flow E2E test' },
      g7_version: '>=7.2.0',
      dependencies: { modules: {}, plugins: {} },
    },
    null,
    4,
  ),
  'plugin.php': [
    '<?php',
    '',
    'namespace Plugins\\Glitter\\E2eUnsigned;',
    '',
    'use App\\Extension\\AbstractPlugin;',
    '',
    '/**',
    ' * 수동 설치 창 미서명 흐름 E2E 시험 플러그인',
    ' */',
    'class Plugin extends AbstractPlugin',
    '{',
    '}',
    '',
  ].join('\n'),
};

const CRC_TABLE = (() => {
  const table = new Uint32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[n] = c >>> 0;
  }
  return table;
})();

/**
 * CRC-32 (ZIP 머리말용)
 *
 * @param data 바이트
 * @returns 부호 없는 32비트 값
 */
function crc32(data: Buffer): number {
  let c = 0xffffffff;
  for (const byte of data) c = CRC_TABLE[(c ^ byte) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}

/**
 * 서명 없는 시험 플러그인 ZIP 을 만든다(무압축).
 *
 * @returns ZIP 바이트와 파일 이름
 */
export function buildUnsignedPluginZip(): { name: string; buffer: Buffer } {
  const root = `${UNSIGNED_PLUGIN_ID}-${UNSIGNED_PLUGIN_VERSION}/`;
  const local: Buffer[] = [];
  const central: Buffer[] = [];
  let offset = 0;

  for (const [file, text] of Object.entries(FILES)) {
    const name = Buffer.from(root + file, 'utf8');
    const data = Buffer.from(text, 'utf8');
    const crc = crc32(data);

    const header = Buffer.alloc(30);
    header.writeUInt32LE(0x04034b50, 0);
    header.writeUInt16LE(20, 4); // 필요한 판
    header.writeUInt16LE(0x0800, 6); // UTF-8 이름
    header.writeUInt16LE(0, 8); // 무압축
    header.writeUInt32LE(0, 10); // 시각·날짜(0 = 1980-01-01)
    header.writeUInt32LE(crc, 14);
    header.writeUInt32LE(data.length, 18);
    header.writeUInt32LE(data.length, 22);
    header.writeUInt16LE(name.length, 26);
    header.writeUInt16LE(0, 28);
    local.push(header, name, data);

    const entry = Buffer.alloc(46);
    entry.writeUInt32LE(0x02014b50, 0);
    entry.writeUInt16LE(20, 4);
    entry.writeUInt16LE(20, 6);
    entry.writeUInt16LE(0x0800, 8);
    entry.writeUInt16LE(0, 10);
    entry.writeUInt32LE(0, 12);
    entry.writeUInt32LE(crc, 16);
    entry.writeUInt32LE(data.length, 20);
    entry.writeUInt32LE(data.length, 24);
    entry.writeUInt16LE(name.length, 28);
    entry.writeUInt32LE(offset, 42);
    central.push(entry, name);

    offset += header.length + name.length + data.length;
  }

  const centralBuffer = Buffer.concat(central);
  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0);
  end.writeUInt16LE(Object.keys(FILES).length, 8);
  end.writeUInt16LE(Object.keys(FILES).length, 10);
  end.writeUInt32LE(centralBuffer.length, 12);
  end.writeUInt32LE(offset, 16);

  return { name: `${UNSIGNED_PLUGIN_ID}-${UNSIGNED_PLUGIN_VERSION}.zip`, buffer: Buffer.concat([...local, centralBuffer, end]) };
}
