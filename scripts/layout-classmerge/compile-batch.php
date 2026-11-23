<?php

/**
 * classMerge 변환 도구의 컴파일 도우미 — `.g7.html` 소스를 실제 컴파일러로 JSON 으로 만든다.
 *
 * `php artisan layout:decompile <파일>` 과 같은 경로(MarkupCompileContextResolver → LayoutMarkupCompiler)를
 * 한 프로세스에서 여러 파일에 대해 돌린다. `source` 를 주면 디스크에 없는 문자열(변환 후보)을 그 파일 경로의
 * 문맥으로 컴파일한다 — dry-run 과 `--no-sync` 에서 verify 가 실제 컴파일 결과로 대조하려고 쓴다.
 * `write: true` 는 `layout:decompile --sync` 와 같은 직렬화기(LayoutJsonWriter::write)로 짝 `.json` 을 굽는다
 * (시험 픽스처 준비용). 앱 코드는 읽고 부르기만 한다.
 *
 * 입력(STDIN): [{"path": "<절대 경로>", "source"?: "<원문>", "write"?: true}, ...]
 * 출력(STDOUT): [{"path", "ok", "json", "outcome", "error"}, ...]
 */

use App\Services\LayoutMarkupService;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\LayoutJsonWriter;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 2);
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$requests = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$resolver = new MarkupCompileContextResolver;
$manifests = app(LayoutMarkupService::class)->installedExtensionManifests();
$out = [];

foreach ($requests as $request) {
    $file = (string) $request['path'];
    $entry = ['path' => $file, 'ok' => false, 'json' => null, 'outcome' => null, 'error' => null];

    try {
        $source = array_key_exists('source', $request) ? (string) $request['source'] : (string) file_get_contents($file);
        $registry = $resolver->registryFor($file, null, $manifests);
        $result = (new LayoutMarkupCompiler($registry))->compile($source, $file, $resolver->contextFor($file));
        $entry['ok'] = true;
        $entry['json'] = $result->json;
        if (($request['write'] ?? false) === true) {
            $entry['outcome'] = LayoutJsonWriter::write(LayoutJsonWriter::jsonSiblingOf($file), $result->json);
        }
    } catch (CompileError $error) {
        $entry['error'] = $error->getMessage();
    } catch (Throwable $error) {
        $entry['error'] = get_class($error).': '.$error->getMessage();
    }

    $out[] = $entry;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
