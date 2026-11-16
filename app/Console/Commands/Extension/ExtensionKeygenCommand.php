<?php

namespace App\Console\Commands\Extension;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Extension\Integrity\PackageSigner;
use Illuminate\Console\Command;

/**
 * 확장 제작자용 패키지 서명 키쌍을 만듭니다 (7.2.0).
 *
 * 비밀키 파일(JSON — 시드·공개키·key_id)을 권한 0600 으로 쓰고 공개키(base64)와 key_id 를 출력한다. 사이트 운영자는
 * 이 공개키를 「확장 신뢰 키」로 등록한다. 비밀키 파일은 저장소·배포 파일 밖에 둔다.
 */
class ExtensionKeygenCommand extends Command
{
    use LocalizesDescription;

    /**
     * 커맨드 시그니처
     *
     * @var string
     */
    protected $signature = 'extension:keygen
        {--out= : 비밀키 파일 경로 (기본: 현재 디렉터리의 {label}-signing-key.json, 이미 있으면 실패)}
        {--label= : 서명자 이름 (영문·숫자·_·-, 32자 이내 — key_id 앞부분이 됨)}';

    /**
     * 커맨드 실행
     *
     * @param  PackageSigner  $signer  서명 도구
     * @return int 실행 결과 코드
     */
    public function handle(PackageSigner $signer): int
    {
        if (! function_exists('sodium_crypto_sign_seed_keypair')) {
            $this->error('❌ '.__('extensions.commands.keygen.sodium_missing'));

            return Command::FAILURE;
        }

        $label = (string) ($this->option('label') ?? '');

        try {
            $key = $signer->generateKey($label);
        } catch (\InvalidArgumentException $e) {
            $this->error('❌ '.$e->getMessage());

            return Command::FAILURE;
        }

        $out = (string) ($this->option('out') ?? '');
        if ($out === '') {
            $out = getcwd().DIRECTORY_SEPARATOR.strtolower($label).'-signing-key.json';
        }

        try {
            $signer->writeKeyFile($key, $out);
        } catch (\RuntimeException $e) {
            $this->error('❌ '.$e->getMessage());

            return Command::FAILURE;
        }

        $this->info('✅ '.__('extensions.commands.keygen.created'));
        $this->line(__('extensions.commands.keygen.key_file', ['path' => $out]));
        $this->line(__('extensions.commands.keygen.public_key', ['key' => $key['public_key']]));
        $this->line(__('extensions.commands.keygen.key_id', ['key' => $key['key_id']]));
        $this->line(__('extensions.commands.keygen.register_hint'));

        return Command::SUCCESS;
    }
}
