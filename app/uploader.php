<?php

function upload_via_ftp(array $cfg, string $localJson, string $localSig): void {
    $ftp = $cfg['ftp'];

    if (!function_exists('curl_init')) {
        throw new RuntimeException("cURL ist nicht verfügbar.");
    }

    $host = $ftp['host'];
    $port = (int)($ftp['port'] ?? 21);
    $user = $ftp['user'];
    $pass = $ftp['pass'];
    $ssl  = !empty($ftp['ssl']);

    $remoteJson = $cfg['remote']['json_path'];
    $remoteSig  = $cfg['remote']['sig_path'];

    // FTP URL bauen
    // Für FTPS (explicit) unterstützt cURL oft "ftps://"
    $scheme = $ssl ? 'ftps' : 'ftp';

    $jsonUrl = sprintf("%s://%s:%d%s", $scheme, $host, $port, $remoteJson);
    $sigUrl  = sprintf("%s://%s:%d%s", $scheme, $host, $port, $remoteSig);

    $auth = $user . ':' . $pass;

    $putFile = function(string $url, string $localFile) use ($auth, $ftp) {
        $fh = fopen($localFile, 'rb');
        if (!$fh) {
            throw new RuntimeException("Kann Datei nicht öffnen: $localFile");
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_USERPWD => $auth,
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $fh,
            CURLOPT_INFILESIZE => filesize($localFile),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,

            // Passive mode (meist nötig)
            CURLOPT_FTP_USE_EPSV => true,

            // Wenn FTPS zickt: (nur im LAN-Admin ok; besser wäre echtes TLS verify)
            // CURLOPT_SSL_VERIFYPEER => false,
            // CURLOPT_SSL_VERIFYHOST => false,
        ]);

        // Optional: explizit passive (cURL macht das meist selbst)
        if (isset($ftp['passive']) && $ftp['passive'] === true) {
            curl_setopt($ch, CURLOPT_FTPPORT, '-'); // signalisiert passive
        }

        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fh);

        if ($resp === false) {
            throw new RuntimeException("FTP Upload Fehler: $err");
        }

        // Manche Server geben 226/250, manche 2xx. Wir akzeptieren alles 2xx
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("FTP Upload fehlgeschlagen (HTTP-like $code) bei $url. Antwort: " . substr((string)$resp, 0, 200));
        }
    };

    $putFile($jsonUrl, $localJson);
    $putFile($sigUrl,  $localSig);
}


function upload_via_webdav(array $cfg, string $localJson, string $localSig): void {
    $wd = $cfg['webdav'];
    $remote = $cfg['remote'];

    if (empty($remote['json_url']) || empty($remote['sig_url'])) {
        throw new RuntimeException("WebDAV URLs fehlen in upload_config.php (json_url/sig_url).");
    }

    webdav_put($remote['json_url'], $wd['user'], $wd['pass'], $localJson);
    webdav_put($remote['sig_url'],  $wd['user'], $wd['pass'], $localSig);
}
