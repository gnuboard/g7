<?php

namespace App\Console\Commands\Extension;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Extension\Integrity\BuiltinTrustKeys;
use App\Extension\Integrity\TrustKeyStatus;
use App\Http\Requests\ExtensionTrustKey\StoreExtensionTrustKeyRequest;
use App\Services\ExtensionTrustKeyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * 확장 신뢰 키(제작자 공개키)를 관리합니다 (7.2.0).
 *
 * - `add {public_key} --label= --vendor=*`: vendor 범위 필수(하나 이상), `sirsoft` 는 내장 키 전용이라 거부.
 * - `remove {key_id}`: 제작자 키 삭제 (내장 키는 코드 상수라 지울 수 없다).
 * - `list`: 내장 키와 제작자 키 목록.
 *
 * 관리자 화면(환경설정 > 보안 「확장 신뢰 키」)과 같은 서비스·같은 입력 규칙을 쓴다. 서버 셸 접근이 곧 본인 확인이라
 * 비밀번호를 묻지 않는다.
 */
class ExtensionTrustKeyCommand extends Command
{
    use LocalizesDescription;

    /**
     * 커맨드 시그니처
     *
     * @var string
     */
    protected $signature = 'extension:trust-key
        {action : 동작 (add|remove|list)}
        {value? : add 는 공개키(base64), remove 는 key_id}
        {--label= : add — 서명자 이름 (영문·숫자·_·-, key_id 앞부분)}
        {--vendor=* : add — 이 키를 믿을 vendor (여러 번 지정 가능, 하나 이상 필수, sirsoft 불가)}';

    /**
     * 커맨드를 만듭니다.
     *
     * @param  ExtensionTrustKeyService  $service  신뢰 키 서비스
     */
    public function __construct(
        private readonly ExtensionTrustKeyService $service,
    ) {
        parent::__construct();
    }

    /**
     * 커맨드 실행
     *
     * @return int 실행 결과 코드
     */
    public function handle(): int
    {
        return match ((string) $this->argument('action')) {
            'add' => $this->add(),
            'remove' => $this->remove(),
            'list' => $this->list(),
            default => $this->failWith(__('extensions.commands.trust_key.invalid_action')),
        };
    }

    /**
     * 제작자 키를 추가합니다.
     *
     * @return int 실행 결과 코드
     */
    private function add(): int
    {
        $publicKey = (string) ($this->argument('value') ?? '');
        if ($publicKey === '') {
            return $this->failWith(__('extensions.commands.trust_key.public_key_missing'));
        }

        $vendors = [];
        foreach ((array) $this->option('vendor') as $value) {
            foreach (explode(',', (string) $value) as $vendor) {
                if (trim($vendor) !== '') {
                    $vendors[] = trim($vendor);
                }
            }
        }

        $data = [
            'public_key' => $publicKey,
            'label' => (string) ($this->option('label') ?? ''),
            'vendor_scope' => $vendors,
        ];

        $validator = Validator::make($data, StoreExtensionTrustKeyRequest::keyRules(), StoreExtensionTrustKeyRequest::keyMessages());
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error('❌ '.$message);
            }

            return Command::FAILURE;
        }

        try {
            $key = $this->service->create($validator->validated());
        } catch (ValidationException $e) {
            foreach (collect($e->errors())->flatten() as $message) {
                $this->error('❌ '.$message);
            }

            return Command::FAILURE;
        }

        $this->info('✅ '.__('extensions.commands.trust_key.added', [
            'key' => $key->key_id,
            'vendors' => implode(', ', (array) $key->vendor_scope),
        ]));

        return Command::SUCCESS;
    }

    /**
     * 제작자 키를 삭제합니다.
     *
     * @return int 실행 결과 코드
     */
    private function remove(): int
    {
        $keyId = (string) ($this->argument('value') ?? '');
        if ($keyId === '') {
            return $this->failWith(__('extensions.commands.trust_key.key_id_missing'));
        }

        $key = $this->service->findByKeyId($keyId);
        if ($key === null) {
            return $this->failWith(__('extensions.commands.trust_key.not_found', ['key' => $keyId]));
        }

        $this->service->delete($key);
        $this->info('✅ '.__('extensions.commands.trust_key.removed', ['key' => $keyId]));

        return Command::SUCCESS;
    }

    /**
     * 내장 키와 제작자 키 목록을 출력합니다.
     *
     * @return int 실행 결과 코드
     */
    private function list(): int
    {
        $rows = [];

        foreach (BuiltinTrustKeys::all() as $key) {
            $rows[] = [
                __('extensions.commands.trust_key.source_builtin'),
                (string) ($key['key_id'] ?? ''),
                (string) ($key['label'] ?? ''),
                __('extensions.commands.trust_key.all_vendors'),
                (string) ($key['status'] ?? ''),
            ];
        }

        foreach ($this->service->all() as $key) {
            $rows[] = [
                __('extensions.commands.trust_key.source_vendor'),
                $key->key_id,
                $key->label,
                implode(', ', (array) $key->vendor_scope),
                TrustKeyStatus::Active->value,
            ];
        }

        // 쓸 수 있는 내장 키가 없으면(비었거나 전부 폐기) 경고 — about·core:check-updates 와 같은 판정
        if (! BuiltinTrustKeys::hasUsableKey()) {
            $this->warn('⚠️  '.__('extensions.commands.trust_key.builtin_empty'));
        }

        if ($rows === []) {
            $this->line(__('extensions.commands.trust_key.empty'));

            return Command::SUCCESS;
        }

        $this->table([
            __('extensions.commands.trust_key.header_source'),
            __('extensions.commands.trust_key.header_key_id'),
            __('extensions.commands.trust_key.header_label'),
            __('extensions.commands.trust_key.header_vendors'),
            __('extensions.commands.trust_key.header_status'),
        ], $rows);

        return Command::SUCCESS;
    }

    /**
     * 오류를 출력하고 실패 코드를 돌려줍니다.
     *
     * @param  string  $message  오류 문장
     * @return int Command::FAILURE
     */
    private function failWith(string $message): int
    {
        $this->error('❌ '.$message);

        return Command::FAILURE;
    }
}
