<?php

declare(strict_types=1);

namespace Qiick\Settings\Tests\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Qiick\Settings\Settings;
use Qiick\Settings\StaleSetting;
use RuntimeException;
use Throwable;

/** Coordinates two PHP processes that read and write through separate database connections. */
final class SettingsWriteRace
{
    /** @return list<string> */
    public function outcomes(): array
    {
        // A child must never inherit the parent's open database connection.
        DB::disconnect();
        $children = [];
        $outcomes = [];

        try {
            foreach (['left', 'right'] as $key) {
                [$pid, $socket] = $this->start($key, $children);
                $children[$pid] = $socket;
            }

            foreach ($children as $socket) {
                if ($this->message($socket) !== 'ready') {
                    throw new RuntimeException('The database writer could not start.');
                }
            }

            foreach ($children as $socket) {
                fwrite($socket, "start\n");
            }

            $pending = $children;
            $this->collectReaders($pending, $outcomes, 8);
            // Without a group lock both transactions reach the read barrier.
            // With the lock, time out the second reader and let the first finish.
            $this->collectReaders($pending, $outcomes, 2);

            foreach (array_diff_key($children, $outcomes) as $pid => $socket) {
                fwrite($socket, "release\n");
            }

            foreach (array_diff_key($children, $outcomes) as $pid => $socket) {
                do {
                    $message = $this->message($socket);
                } while ($message === 'read');

                $outcomes[$pid] = $message;
            }
        } finally {
            foreach ($children as $pid => $socket) {
                fclose($socket);
                pcntl_waitpid($pid, $status);
            }
        }

        sort($outcomes);

        return $outcomes;
    }

    /**
     * @param  array<int, resource>  $existing
     * @return array{int, resource}
     */
    private function start(string $key, array $existing): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException('Could not create a socket pair for the settings race.');
        }

        [$parent, $child] = $sockets;
        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($parent);
            fclose($child);
            throw new RuntimeException('Could not fork a settings writer.');
        }

        if ($pid === 0) {
            fclose($parent);

            foreach ($existing as $socket) {
                fclose($socket);
            }

            stream_set_timeout($child, 15);
            $this->write($child, $key);
            fclose($child);
            exit(0);
        }

        fclose($child);
        stream_set_timeout($parent, 15);

        return [$pid, $parent];
    }

    /** @param resource $socket */
    private function write($socket, string $key): void
    {
        try {
            // With READ COMMITTED, MySQL/MariaDB do not incidentally serialize
            // empty-group reads through next-key locks on the settings table.
            DB::statement(DB::connection()->getDriverName() === 'pgsql'
                ? 'SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ COMMITTED'
                : 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $hasRead = false;
            DB::listen(function (QueryExecuted $query) use ($socket, &$hasRead): void {
                if (! $hasRead && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'revision')) {
                    $hasRead = true;
                    fwrite($socket, "read\n");
                    $this->message($socket);
                }
            });
            fwrite($socket, "ready\n");
            $this->message($socket);
            app(Settings::class)->set([$key => $key], expectedRevision: 0);
            fwrite($socket, "saved\n");
        } catch (StaleSetting) {
            fwrite($socket, "stale\n");
        } catch (Throwable $exception) {
            fwrite($socket, 'error '.$exception::class."\n");
        } finally {
            DB::disconnect();
        }
    }

    /**
     * @param  array<int, resource>  $pending
     * @param  array<int, string>  $outcomes
     */
    private function collectReaders(array &$pending, array &$outcomes, int $seconds): void
    {
        if ($pending === []) {
            return;
        }

        $ready = $pending;
        $write = null;
        $except = null;
        stream_select($ready, $write, $except, $seconds);

        foreach ($ready as $pid => $socket) {
            $message = $this->message($socket);
            unset($pending[$pid]);

            if ($message !== 'read') {
                $outcomes[$pid] = $message;
            }
        }
    }

    /** @param resource $socket */
    private function message($socket): string
    {
        $line = fgets($socket);

        if ($line === false) {
            throw new RuntimeException('The writer closed its socket or timed out.');
        }

        return trim($line);
    }
}
