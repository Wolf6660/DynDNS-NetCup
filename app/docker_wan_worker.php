<?php

require_once __DIR__ . '/docker_wan_updater.php';

try {
    $result = docker_wan_process_due_domains();
    if (($result['updated'] ?? 0) > 0 || ($result['unchanged'] ?? 0) > 0 || ($result['skipped'] ?? 0) > 0) {
        $domains = implode(', ', $result['domains'] ?? []);
        $unchangedDomains = implode(', ', $result['unchanged_domains'] ?? []);
        fwrite(STDOUT, sprintf(
            "[docker-wan-worker] updated=%d unchanged=%d skipped=%d ip=%s domains=%s unchanged_domains=%s\n",
            (int)($result['updated'] ?? 0),
            (int)($result['unchanged'] ?? 0),
            (int)($result['skipped'] ?? 0),
            (string)($result['ip'] ?? ''),
            $domains,
            $unchangedDomains
        ));
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[docker-wan-worker] error: " . $e->getMessage() . "\n");
    exit(1);
}
