<?php

declare(strict_types=1);

it('classifies every source class and documents exactly the public classes', function (): void {
    $root = dirname(__DIR__, 2);
    $source = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS));
    $public = [];

    foreach ($source as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root.'/src/'), -4);
        $name = 'Qiick\\Settings\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
        $declaration = new ReflectionClass($name);
        $comment = $declaration->getDocComment() ?: '';
        $isPublic = preg_match('/@api\b/', $comment) === 1;
        $isInternal = preg_match('/@internal\b/', $comment) === 1;

        expect($isPublic xor $isInternal)->toBeTrue("{$name} needs exactly one of @api and @internal.");

        if ($isPublic) {
            $public[] = $name;
        }
    }

    $page = (string) file_get_contents($root.'/docs/public-api.md');
    preg_match_all('/^- `(Qiick\\\\Settings\\\\[^`]+)` \((?:class|interface|enum|trait)\)$/m', $page, $matches);
    $listed = $matches[1];
    sort($listed);
    sort($public);

    expect($listed)->toBe($public);
});
