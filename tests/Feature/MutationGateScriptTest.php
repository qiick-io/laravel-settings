<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * The mutation gate's no-mutant allowlist (scripts/mutate-per-class.sh): a
 * file that creates no mutant passes only when it is listed, and a listed file
 * that creates mutants fails, so the list can neither hide a mis-pathed class
 * nor go stale. A stub stands in for pest and prints what pest prints.
 */

function runMutationGate(string $file, string $pestOutput): Process
{
    $stub = tempnam(sys_get_temp_dir(), 'pest-stub');
    file_put_contents($stub, "#!/usr/bin/env bash\nprintf '%s\\n' ".escapeshellarg($pestOutput)."\n");
    chmod($stub, 0700);

    $process = new Process(['bash', 'scripts/mutate-per-class.sh'], dirname(__DIR__, 2), [
        'MUTATE_SCOPE' => 'changed',
        'MUTATE_CLASSES' => json_encode([$file]),
        'PEST_BINARY' => $stub,
    ]);
    $process->run();
    unlink($stub);

    return $process;
}

it('passes a listed file that creates no mutant', function (): void {
    expect(runMutationGate('src/StaleSetting.php', 'INFO  No mutations created.')->getExitCode())->toBe(0);
});

it('fails a file that creates no mutant and is not listed', function (): void {
    $process = runMutationGate('src/ScopeRef.php', 'INFO  No mutations created.');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('src/ScopeRef.php creates no mutant');
});

it('fails a listed file that now creates mutants', function (): void {
    $process = runMutationGate('src/StaleSetting.php', 'Score:     100.00%');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('src/StaleSetting.php now creates mutants');
});

it('passes a file with mutants that is not listed', function (): void {
    expect(runMutationGate('src/ScopeRef.php', 'Score:     100.00%')->getExitCode())->toBe(0);
});
