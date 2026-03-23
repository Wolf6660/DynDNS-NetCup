<?php

require_once __DIR__ . '/docker_wan_updater.php';

try {
    $result = docker_wan_process_due_domains();
    if (($result['updated'] ?? 0) > 0 || ($result['skipped'] ?? 0) > 0) {
        $domains = implode(', ', $result['domains'] ?? []);
        fwrite(STDOUT, sprintf(
            "[docker-wan-worker] updated=%d skipped=%d ip=%s domains=%s\n",
            (int)($result['updated'] ?? 0),
            (int)($result['skipped'] ?? 0),
            (string)($result['ip'] ?? ''),
            $domains
        ));
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[docker-wan-worker] error: " . $e->getMessage() . "\n");
    exit(1);
}
