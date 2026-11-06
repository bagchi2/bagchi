<?php
/**
 * Domain Monitor — single-file PHP application
 * PHP 8.0+, cURL (recommended), JSON/file write permissions, Apache mod_rewrite.
 */
declare(strict_types=1);

$cfg = __DIR__ . '/config.php';
if (!is_file($cfg)) { http_response_code(500); exit('Missing config.php'); }
require_once $cfg;

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'secure' => $https,
    'httponly' => true, 'samesite' => 'Lax'
]);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

foreach ([STORAGE_PATH, STORAGE_PATH . '/domains', STORAGE_PATH . '/cache', STORAGE_PATH . '/rate', STORAGE_PATH . '/locks'] as $dir) {
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        http_response_code(500); exit('Storage directory is not writable. Check folder permissions.');
    }
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function now_iso(): string { return gmdate('c'); }
function pretty_date($value): string {
    if (!$value) return 'Not published';
    try { return (new DateTime((string)$value))->format('d M Y, H:i T'); } catch (Throwable $e) { return (string)$value; }
}
function json_read(string $path, $default = null) {
    if (!is_file($path)) return $default;
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return $default;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}
function json_write(string $path, array $data): bool {
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return false;
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
}
function domain_hash(string $domain): string { return hash('sha256', strtolower($domain)); }
function domain_file(string $domain): string { return STORAGE_PATH . '/domains/' . domain_hash($domain) . '.json'; }
function acquire_domain_lock(string $domain) {
    $path = STORAGE_PATH . '/locks/' . domain_hash($domain) . '.lock';
    $handle = @fopen($path, 'c');
    if (!$handle || !@flock($handle, LOCK_EX)) { if ($handle) fclose($handle); return false; }
    return $handle;
}
function release_domain_lock($handle): void { if (is_resource($handle)) { @flock($handle, LOCK_UN); @fclose($handle); } }
function app_base_url(): string {
    if (defined('APP_BASE_URL') && APP_BASE_URL !== '') return rtrim(APP_BASE_URL, '/');
    $host = preg_replace('/[^a-zA-Z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host;
}
function permalink(string $domain): string { return app_base_url() . '/monitor/' . rawurlencode($domain); }
function normalise_domain(string $input): string {
    $input = trim(function_exists('mb_strtolower') ? mb_strtolower($input, 'UTF-8') : strtolower($input));
    if ($input === '') return '';
    $input = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $input);
    $input = preg_replace('#^www\.#i', '', $input);
    $input = preg_split('/[\/?#]/', $input)[0] ?? '';
    $input = rtrim(trim($input), '.');
    if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7E]/', $input)) {
        $ascii = idn_to_ascii($input, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii !== false) $input = strtolower($ascii);
    }
    return $input;
}
function valid_domain(string $domain): bool {
    if (strlen($domain) < 4 || strlen($domain) > 253 || strpos($domain, '.') === false) return false;
    if (filter_var($domain, FILTER_VALIDATE_IP)) return false;
    $labels = explode('.', $domain);
    foreach ($labels as $label) {
        if ($label === '' || strlen($label) > 63) return false;
        if (!preg_match('/^(?!-)[a-z0-9-]+(?<!-)$/i', $label)) return false;
    }
    $tld = end($labels);
    return (bool)preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{2,59})$/i', $tld);
}
function http_get_json(string $url, array $headers = [], int $timeout = 12): array {
    $response = ['ok' => false, 'status' => 0, 'json' => null, 'body' => '', 'error' => ''];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'DomainMonitor/1.0 (+domain registration monitoring)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $response['body'] = is_string($body) ? $body : '';
        $response['status'] = $status;
        $response['error'] = $err;
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => $timeout,
            'header' => implode("\r\n", array_merge($headers, ['User-Agent: DomainMonitor/1.0'])) . "\r\n",
            'ignore_errors' => true,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $body = @file_get_contents($url, false, $context);
        $response['body'] = is_string($body) ? $body : '';
        foreach (($http_response_header ?? []) as $head) if (preg_match('/^HTTP\/\S+\s+(\d+)/', $head, $m)) $response['status'] = (int)$m[1];
        if ($body === false) $response['error'] = 'HTTP request failed. Enable cURL or check outbound HTTPS access.';
    }
    $response['json'] = json_decode($response['body'], true);
    $response['ok'] = ($response['status'] >= 200 && $response['status'] < 300 && is_array($response['json']));
    return $response;
}
function tryapi_lookup(string $domain): array {
    if (!defined('TRYAPI_KEY') || TRYAPI_KEY === '' || strpos(TRYAPI_KEY, 'PASTE_') === 0) {
        return ['ok' => false, 'error' => 'TryAPI key is not configured.'];
    }
    $url = TRYAPI_URL . '?domain=' . rawurlencode($domain);
    $r = http_get_json($url, ['Authorization: Bearer ' . TRYAPI_KEY, 'Accept: application/json'], 12);
    if (!$r['ok']) {
        $j = is_array($r['json']) ? $r['json'] : [];
        return ['ok' => false, 'error' => (string)($j['error'] ?? $j['message'] ?? $r['error'] ?? ('TryAPI returned HTTP ' . $r['status'])), 'http_status' => $r['status']];
    }
    $j = $r['json'];
    if (array_key_exists('success', $j) && $j['success'] !== true) {
        return ['ok' => false, 'error' => (string)($j['error'] ?? 'TryAPI reported an unsuccessful lookup.'), 'http_status' => $r['status']];
    }
    $data = $j['data'] ?? $j;
    if (!is_array($data) || !array_key_exists('available', $data)) {
        return ['ok' => false, 'error' => 'TryAPI response did not contain a recognised availability field.', 'http_status' => $r['status']];
    }
    return ['ok' => true, 'available' => is_bool($data['available']) ? $data['available'] : filter_var($data['available'], FILTER_VALIDATE_BOOLEAN), 'data' => $data, 'request_id' => $j['request_id'] ?? null];
}
function rdap_bootstrap(): array {
    $cacheFile = STORAGE_PATH . '/cache/iana-rdap-dns.json';
    $cached = json_read($cacheFile, []);
    if (!empty($cached['saved_at']) && (time() - (int)$cached['saved_at'] < RDAP_BOOTSTRAP_CACHE_SECONDS) && !empty($cached['data']['services'])) return $cached['data'];
    $r = http_get_json(RDAP_BOOTSTRAP_URL, ['Accept: application/json'], 15);
    if ($r['ok'] && !empty($r['json']['services'])) {
        json_write($cacheFile, ['saved_at' => time(), 'data' => $r['json']]);
        return $r['json'];
    }
    return $cached['data'] ?? [];
}
function rdap_endpoint_for(string $domain): string {
    $parts = explode('.', $domain);
    $tld = strtolower((string)end($parts));
    $boot = rdap_bootstrap();
    foreach (($boot['services'] ?? []) as $service) {
        $tlds = $service[0] ?? []; $urls = $service[1] ?? [];
        if (in_array($tld, array_map('strtolower', $tlds), true) && !empty($urls[0])) return rtrim((string)$urls[0], '/') . '/';
    }
    return '';
}
function rdap_lookup(string $domain): array {
    $base = rdap_endpoint_for($domain);
    if ($base === '') return ['ok' => false, 'http_status' => 0, 'error' => 'No RDAP service was found in the IANA bootstrap directory for this TLD.'];
    $r = http_get_json($base . 'domain/' . rawurlencode($domain), ['Accept: application/rdap+json, application/json'], 12);
    if ($r['status'] === 404) return ['ok' => false, 'not_found' => true, 'http_status' => 404, 'endpoint' => $base];
    if (!$r['ok']) return ['ok' => false, 'http_status' => $r['status'], 'error' => $r['error'] ?: 'RDAP lookup failed.', 'endpoint' => $base];
    return ['ok' => true, 'data' => $r['json'], 'http_status' => $r['status'], 'endpoint' => $base];
}
function rdap_events(array $rdap): array {
    $out = ['registered' => null, 'expires' => null, 'updated' => null, 'last_changed' => null];
    foreach (($rdap['events'] ?? []) as $event) {
        $action = strtolower((string)($event['eventAction'] ?? ''));
        $date = $event['eventDate'] ?? null;
        if (!$date) continue;
        if (in_array($action, ['registration', 'registered'], true)) $out['registered'] = $date;
        if (in_array($action, ['expiration', 'expiry'], true)) $out['expires'] = $date;
        if (in_array($action, ['last changed', 'last update of rdap database', 'last update'], true)) $out['updated'] = $date;
    }
    $out['last_changed'] = $out['updated'];
    return $out;
}
function rdap_registrar(array $rdap): string {
    foreach (($rdap['entities'] ?? []) as $entity) {
        if (!in_array('registrar', $entity['roles'] ?? [], true)) continue;
        foreach (($entity['vcardArray'][1] ?? []) as $v) {
            if (($v[0] ?? '') === 'fn' && !empty($v[3])) return is_array($v[3]) ? implode(' ', $v[3]) : (string)$v[3];
        }
        if (!empty($entity['handle'])) return (string)$entity['handle'];
    }
    return '';
}
function build_lookup(string $domain): array {
    $api = tryapi_lookup($domain);
    $rdap = rdap_lookup($domain);
    $available = null;
    $source = 'unknown';
    $status = 'Status unavailable';
    if (!empty($rdap['ok'])) {
        $available = false; $source = 'RDAP'; $status = 'Active (Registered)';
    } elseif ($api['ok']) {
        $available = (bool)$api['available'];
        $source = 'TryAPI / ' . (string)($api['data']['source'] ?? 'registry lookup');
        $status = $available ? 'Available / Unregistered' : 'Active (Registered)';
    } elseif (!empty($rdap['not_found'])) {
        $source = 'RDAP (404)'; $status = 'Not found in RDAP — availability unconfirmed';
    }
    $raw = !empty($rdap['ok']) ? $rdap['data'] : [];
    $statuses = is_array($raw['status'] ?? null) ? $raw['status'] : [];
    $events = rdap_events($raw);
    if ($available === false && $statuses) {
        // ICANN notes that pendingDelete can be returned alongside redemptionPeriod;
        // when both are present, the redemptionPeriod description takes precedence.
        $statusMap = [
            'redemptionperiod' => 'Redemption Period', 'pendingdelete' => 'Pending Delete',
            'autorenewperiod' => 'Grace Period', 'renewperiod' => 'Grace Period',
            'transferperiod' => 'Transfer Period', 'pendingtransfer' => 'Pending Transfer',
            'clienthold' => 'Hold — Client Hold', 'serverhold' => 'Hold — Server Hold',
            'clienttransferprohibited' => 'Transfer Lock — Client Transfer Prohibited',
            'servertransferprohibited' => 'Transfer Lock — Server Transfer Prohibited',
        ];
        $normalisedStatuses = array_map(static fn($v) => strtolower(preg_replace('/[^a-z]/i', '', (string)$v)), $statuses);
        foreach (array_keys($statusMap) as $priorityStatus) {
            if (in_array($priorityStatus, $normalisedStatuses, true)) { $status = $statusMap[$priorityStatus]; break; }
        }
    }
    $registrar = rdap_registrar($raw);
    if ($registrar === '' && $api['ok']) $registrar = (string)($api['data']['registrar'] ?? '');
    $expires = $events['expires'] ?: ($api['data']['expiresAt'] ?? null);
    $nameservers = [];
    foreach (($raw['nameservers'] ?? []) as $ns) if (!empty($ns['ldhName'])) $nameservers[] = strtolower((string)$ns['ldhName']);
    return [
        'ok' => $api['ok'] || !empty($rdap['ok']) || !empty($rdap['not_found']),
        'status' => $status,
        'available' => $available,
        'source' => $source,
        'registrar' => $registrar,
        'expires_at' => $expires,
        'registered_at' => $events['registered'],
        'updated_at' => $events['updated'],
        'statuses' => $statuses,
        'nameservers' => array_values(array_unique($nameservers)),
        'dnssec' => isset($raw['secureDNS']['delegationSigned']) ? ($raw['secureDNS']['delegationSigned'] ? 'Signed' : 'Unsigned') : 'Not published',
        'rdap' => $raw,
        'api_error' => $api['ok'] ? '' : (string)($api['error'] ?? ''),
        'rdap_error' => (!empty($rdap['ok']) || !empty($rdap['not_found'])) ? '' : (string)($rdap['error'] ?? ''),
        'checked_at' => now_iso(),
        'request_id' => $api['request_id'] ?? null,
    ];
}
function status_class(string $status): string {
    $s = strtolower($status);
    if (strpos($s, 'available') !== false) return 'available';
    if (strpos($s, 'registered') !== false) return 'registered';
    if (strpos($s, 'delete') !== false || strpos($s, 'redemption') !== false || strpos($s, 'grace') !== false || strpos($s, 'hold') !== false || strpos($s, 'transfer') !== false) return 'warning';
    return 'unknown';
}
function lifecycle_timestamp($value): ?int {
    if (!$value) return null;
    $timestamp = strtotime((string)$value);
    return $timestamp === false ? null : $timestamp;
}
function lifecycle_date_label(?int $timestamp): string {
    if (!$timestamp) return 'Not published';
    return gmdate('M j, Y', $timestamp);
}
function lifecycle_relative(?int $timestamp): string {
    if (!$timestamp) return '';
    $delta = $timestamp - time();
    if (abs($delta) < 86400) return 'today';
    $days = (int)ceil(abs($delta) / 86400);
    if ($delta > 0) return 'in ' . $days . ' day' . ($days === 1 ? '' : 's');
    return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
}
function lifecycle_current_stage(array $record): string {
    $statuses = array_map(static fn($v) => strtolower(preg_replace('/[^a-z]/i', '', (string)$v)), (array)($record['rdap_statuses'] ?? []));
    $current = strtolower((string)($record['current_status'] ?? ''));
    if (($record['available'] ?? null) === true || strpos($current, 'available') !== false) return 'available';
    if (in_array('redemptionperiod', $statuses, true) || strpos($current, 'redemption') !== false) return 'redemption';
    if (in_array('pendingdelete', $statuses, true) || strpos($current, 'pending delete') !== false) return 'pending_delete';
    if (in_array('autorenewperiod', $statuses, true) || strpos($current, 'grace period') !== false) return 'registrar_grace';
    $expiry = lifecycle_timestamp($record['expires_at'] ?? null);
    if ($expiry !== null && $expiry < time()) return 'expired';
    return 'registered';
}
function lifecycle_markup(array $record, string $domain): string {
    $expiry = lifecycle_timestamp($record['expires_at'] ?? null);
    $registered = lifecycle_timestamp($record['registered_at'] ?? null);
    $lastChanged = lifecycle_timestamp($record['updated_at_registry'] ?? null);
    $today = time();
    $stage = lifecycle_current_stage($record);
    $stageLabels = [
        'registered' => 'Registered', 'expired' => 'Expired / renewal window',
        'registrar_grace' => 'Registrar grace period', 'redemption' => 'Redemption period',
        'pending_delete' => 'Pending delete', 'available' => 'Available / dropped'
    ];
    $isComNet = in_array(strtolower((string)substr(strrchr('.' . $domain, '.'), 1)), ['com', 'net'], true);
    $projected = ($expiry !== null && $isComNet);
    // This is an outer-bound planning scenario, not a guaranteed drop date:
    // registrars may delete an expired gTLD registration earlier than 45 days.
    $graceEnd = $projected ? $expiry + (45 * 86400) : null;
    $redemptionEnd = $projected ? $expiry + (75 * 86400) : null;
    $dropDate = $projected ? $expiry + (80 * 86400) : null;
    $tld = strtoupper((string)substr(strrchr('.' . $domain, '.'), 1));
    $summary = [
        'registered' => 'The registry currently reports this domain as registered.',
        'expired' => 'The published expiry date has passed, but the registry still returns a domain record. Renewal or deletion timing is controlled by the registrar and registry.',
        'registrar_grace' => 'The registry reports an auto-renew or grace-period status. The current owner may still be able to renew through the registrar.',
        'redemption' => 'The registry reports Redemption Period. The former registrant may be able to restore the domain through the registrar, subject to its fees and rules.',
        'pending_delete' => 'The registry reports Pending Delete. Restoration is generally no longer available at this stage; the registry must complete its deletion process.',
        'available' => 'The latest lookup reports the domain as available. Re-check immediately before attempting registration.'
    ][$stage];

    $items = [];
    if ($registered !== null) $items[] = ['label' => 'Registered', 'ts' => $registered, 'kind' => 'record', 'note' => 'Registration event from RDAP'];
    if ($expiry !== null) $items[] = ['label' => 'Expired', 'ts' => $expiry, 'kind' => 'record', 'note' => 'Published registry expiration date'];
    if ($lastChanged !== null) $items[] = ['label' => 'Last changed', 'ts' => $lastChanged, 'kind' => 'record', 'note' => 'RDAP last-changed event'];
    $items[] = ['label' => 'Today', 'ts' => $today, 'kind' => 'today', 'note' => 'Current date'];
    if ($expiry !== null) {
        if ($projected) {
            $items[] = ['label' => 'Registrar grace', 'ts' => $graceEnd, 'kind' => 'projected', 'note' => '45-day outer-bound scenario after expiry'];
            $items[] = ['label' => 'Redemption ends', 'ts' => $redemptionEnd, 'kind' => 'projected', 'note' => 'Assumes 30-day registry redemption period'];
            $items[] = ['label' => 'Pending delete ends', 'ts' => $dropDate, 'kind' => 'projected', 'note' => 'Assumes a further 5 calendar days'];
            $items[] = ['label' => 'Estimated free again', 'ts' => $dropDate, 'kind' => 'projected', 'note' => 'Earliest planning estimate; recheck the registry'];
        } else {
            $items[] = ['label' => 'Registrar grace', 'ts' => null, 'kind' => 'projected', 'note' => 'Registrar-specific; may be 1–45 days or shorter'];
            $items[] = ['label' => 'Redemption ends', 'ts' => null, 'kind' => 'projected', 'note' => '30 days after deletion into RGP, where applicable'];
            $items[] = ['label' => 'Pending delete ends', 'ts' => null, 'kind' => 'projected', 'note' => 'Typically 5 calendar days after redemption'];
            $items[] = ['label' => 'Estimated free again', 'ts' => null, 'kind' => 'projected', 'note' => 'Not reliably predictable for this TLD'];
        }
    } else {
        $items[] = ['label' => 'Expiration', 'ts' => null, 'kind' => 'projected', 'note' => 'No expiration event published by RDAP'];
        $items[] = ['label' => 'Next lifecycle stages', 'ts' => null, 'kind' => 'projected', 'note' => 'Registry and registrar rules apply'];
    }
    usort($items, static function ($a, $b) {
        $at = $a['ts'] ?? PHP_INT_MAX; $bt = $b['ts'] ?? PHP_INT_MAX;
        if ($at === $bt) return ($a['kind'] === 'today' ? 1 : 0) <=> ($b['kind'] === 'today' ? 1 : 0);
        return $at <=> $bt;
    });

    $html = '<section class="lifecycle-panel reveal"><div class="lifecycle-head"><div><div class="lifecycle-kicker">DOMAIN LIFECYCLE <span class="lifecycle-tld">.' . h(strtolower($tld)) . '</span></div><h2>' . h($domain) . ' <span>— ' . h($stageLabels[$stage]) . '</span></h2><p>' . h($summary) . '</p></div><div class="lifecycle-side"><span class="lifecycle-state">' . h(strtoupper(str_replace('_', ' ', $stage))) . '</span><span class="lifecycle-source">' . h($record['source'] ?? 'RDAP') . '</span></div></div>';
    $html .= '<div class="lifecycle-legend"><span><i class="legend-dot recorded"></i> From registry record</span><span><i class="legend-line"></i> Projected, if not renewed</span><span><i class="legend-dot today"></i> Today</span></div>';
    $html .= '<div class="lifecycle-scroll"><div class="lifecycle-track">';
    foreach ($items as $index => $item) {
        $kind = $item['kind'];
        $isPast = ($item['ts'] !== null && $item['ts'] < $today && $kind !== 'today');
        $classes = 'lifecycle-item ' . ($kind === 'projected' ? 'projected ' : 'recorded ') . ($kind === 'today' ? 'today-item ' : '') . ($isPast ? 'past ' : '');
        $icon = $kind === 'today' ? '●' : ($kind === 'projected' ? '＋' : '✓');
        $date = $item['ts'] !== null ? lifecycle_date_label((int)$item['ts']) : 'Date varies';
        $relative = $item['ts'] !== null && $kind !== 'today' ? lifecycle_relative((int)$item['ts']) : ($kind === 'today' ? 'Current point' : 'Registry-dependent');
        $html .= '<article class="' . $classes . '"><div class="lifecycle-node">' . h($icon) . '</div><div class="lifecycle-card"><div class="lifecycle-type">' . ($kind === 'projected' ? 'PROJECTED' : ($kind === 'today' ? 'CURRENT' : 'RECORDED')) . '</div><div class="lifecycle-label">' . h($item['label']) . '</div><strong>' . h($date) . '</strong><small>' . h($relative) . '</small><p>' . h($item['note']) . '</p></div></article>';
    }
    $html .= '</div></div>';
    $assumption = $projected
        ? 'Projection model for .' . strtolower($tld) . ': assumes the full 45-day registrar auto-renew grace window, followed by 30 days of redemption and 5 days of pending delete. A registrar may delete an expired name sooner, so the actual release can be earlier. The RDAP status is authoritative over this estimate.'
        : 'Dates after expiration are not calculated because this TLD may use a different lifecycle. For gTLDs, registrar deletion timing can vary; a 30-day redemption period applies after a qualifying registry deletion, followed by the registry pending-delete process. Confirm every stage with a fresh registry lookup.';
    $html .= '<div class="lifecycle-footnote"><span>ⓘ</span><p>' . h($assumption) . '</p></div></section>';
    return $html;
}

function public_rdap($value, string $key = '') {
    $sensitive = ['email', 'tel', 'adr', 'street', 'postalcode', 'phone', 'fax', 'contact'];
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $lk = strtolower((string)$k);
            if (in_array($lk, $sensitive, true)) { $out[$k] = '[redacted for public display]'; continue; }
            if ($lk === 'vcardarray' && is_array($v)) {
                $out[$k] = $v;
                if (isset($out[$k][1]) && is_array($out[$k][1])) {
                    foreach ($out[$k][1] as &$item) if (isset($item[0]) && in_array(strtolower((string)$item[0]), ['email','tel','adr'], true)) $item[3] = '[redacted for public display]';
                    unset($item);
                }
                continue;
            }
            $out[$k] = public_rdap($v, $lk);
        }
        return $out;
    }
    return $value;
}
function minimum_interval(array $record): int {
    $values = [];
    foreach (($record['subscribers'] ?? []) as $s) if (!empty($s['active'])) $values[] = max(MIN_INTERVAL_MINUTES, (int)($s['interval'] ?? DEFAULT_INTERVAL_MINUTES));
    return $values ? min($values) : DEFAULT_INTERVAL_MINUTES;
}
function add_history(array &$record, string $type, string $message, string $status = ''): void {
    $record['history'] = $record['history'] ?? [];
    array_unshift($record['history'], ['at' => now_iso(), 'type' => $type, 'message' => $message, 'status' => $status]);
    $record['history'] = array_slice($record['history'], 0, 300);
}
function smtp_read_response($socket): array {
    $response = '';
    while (($line = @fgets($socket, 515)) !== false) {
        $response .= $line;
        if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
            if ($m[2] === ' ') return ['code' => (int)$m[1], 'message' => trim($response)];
        }
    }
    $meta = is_resource($socket) ? stream_get_meta_data($socket) : [];
    $reason = !empty($meta['timed_out']) ? 'SMTP connection timed out while waiting for a response.' : 'SMTP server closed the connection unexpectedly.';
    throw new RuntimeException($reason . ($response !== '' ? ' Server response: ' . trim($response) : ''));
}
function smtp_expect($socket, array $acceptedCodes, string $stage): array {
    $reply = smtp_read_response($socket);
    if (!in_array($reply['code'], $acceptedCodes, true)) {
        throw new RuntimeException($stage . ' failed (SMTP ' . $reply['code'] . '): ' . $reply['message']);
    }
    return $reply;
}
function smtp_command($socket, string $command, array $acceptedCodes, string $stage): array {
    if (@fwrite($socket, $command . "\r\n") === false) throw new RuntimeException($stage . ': could not write to SMTP server.');
    return smtp_expect($socket, $acceptedCodes, $stage);
}
function smtp_send_message(string $to, string $subject, string $html, string $text): bool {
    $host = defined('SMTP_HOST') ? trim(SMTP_HOST) : '';
    $port = defined('SMTP_PORT') ? (int)SMTP_PORT : 0;
    $encryption = defined('SMTP_ENCRYPTION') ? strtolower(trim(SMTP_ENCRYPTION)) : '';
    $username = defined('SMTP_USERNAME') ? trim(SMTP_USERNAME) : '';
    $password = defined('SMTP_PASSWORD') ? (string)SMTP_PASSWORD : '';
    $from = defined('APP_FROM_EMAIL') ? trim(APP_FROM_EMAIL) : '';
    $timeout = defined('SMTP_TIMEOUT') ? max(5, (int)SMTP_TIMEOUT) : 20;
    if ($host === '' || $port < 1 || $username === '' || $password === '' || $from === '') {
        throw new RuntimeException('SMTP configuration is incomplete. Check SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD and APP_FROM_EMAIL in config.php.');
    }
    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($username, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The sender, SMTP username or recipient email address is invalid.');
    }
    if (!in_array($encryption, ['ssl', 'tls'], true)) throw new RuntimeException('SMTP_ENCRYPTION must be ssl or tls.');
    $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
    $context = stream_context_create(['ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'peer_name' => $host,
        'SNI_enabled' => true,
    ]]);
    $errno = 0; $errstr = '';
    $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) throw new RuntimeException('Could not connect to Hostinger SMTP (' . $errno . '): ' . $errstr);
    stream_set_timeout($socket, $timeout);
    try {
        smtp_expect($socket, [220], 'SMTP greeting');
        $serverName = (string)(parse_url(app_base_url(), PHP_URL_HOST) ?: 'localhost');
        smtp_command($socket, 'EHLO ' . preg_replace('/[^a-zA-Z0-9.-]/', '', $serverName), [250], 'EHLO');
        if ($encryption === 'tls') {
            smtp_command($socket, 'STARTTLS', [220], 'STARTTLS');
            $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) throw new RuntimeException('Could not establish TLS encryption with Hostinger SMTP.');
            smtp_command($socket, 'EHLO ' . preg_replace('/[^a-zA-Z0-9.-]/', '', $serverName), [250], 'EHLO after STARTTLS');
        }
        smtp_command($socket, 'AUTH LOGIN', [334], 'SMTP authentication');
        smtp_command($socket, base64_encode($username), [334], 'SMTP username authentication');
        smtp_command($socket, base64_encode($password), [235], 'SMTP password authentication');
        smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250], 'MAIL FROM');
        smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251], 'RCPT TO');
        smtp_command($socket, 'DATA', [354], 'DATA');

        $boundary = '=_domain_monitor_' . bin2hex(random_bytes(14));
        $fromName = defined('APP_FROM_NAME') ? APP_FROM_NAME : APP_NAME;
        $encodedFromName = '=?UTF-8?B?' . base64_encode((string)$fromName) . '?=';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $date = gmdate('D, d M Y H:i:s') . ' +0000';
        $messageIdHost = preg_replace('/[^a-zA-Z0-9.-]/', '', (string)(parse_url(app_base_url(), PHP_URL_HOST) ?: 'localhost'));
        $headers = [
            'Date: ' . $date,
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $messageIdHost . '>',
            'From: ' . $encodedFromName . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Reply-To: ' . $from,
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: Domain Monitor SMTP',
        ];
        $body = '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text), 76, "\r\n");
        $body .= '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html), 76, "\r\n");
        $body .= '--' . $boundary . "--\r\n";
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $payload = preg_replace('/(^|\r\n)\./', '$1..', $payload);
        if (@fwrite($socket, $payload . ".\r\n") === false) throw new RuntimeException('Could not transmit the email content to Hostinger SMTP.');
        smtp_expect($socket, [250], 'Message submission');
        @fwrite($socket, "QUIT\r\n");
        return true;
    } finally {
        if (is_resource($socket)) @fclose($socket);
    }
}
function send_email(string $to, string $subject, string $html, string $text): bool {
    try {
        return smtp_send_message($to, $subject, $html, $text);
    } catch (Throwable $e) {
        // Never log SMTP credentials. The diagnostic contains only the failed stage and server reply.
        $safeDiagnostic = str_replace(["\r", "\n"], ' ', $e->getMessage());
        error_log('[Domain Monitor SMTP] ' . $safeDiagnostic);
        @file_put_contents(STORAGE_PATH . '/smtp-errors.log', gmdate('c') . ' ' . $safeDiagnostic . PHP_EOL, FILE_APPEND | LOCK_EX);
        return false;
    }
}
function email_shell(string $title, string $content): string {
    return '<!doctype html><html><body style="margin:0;background:#f4f6fb;font-family:Arial,sans-serif;color:#172033"><div style="max-width:620px;margin:30px auto;background:#fff;border:1px solid #e6eaf1;border-radius:18px;overflow:hidden"><div style="padding:24px 28px;background:#111a32;color:#fff;font-weight:700;letter-spacing:.3px">' . h(APP_NAME) . '</div><div style="padding:30px"><h1 style="font-size:22px;margin:0 0 14px">' . h($title) . '</h1>' . $content . '</div><div style="padding:18px 28px;background:#f8f9fc;color:#6d7789;font-size:12px">Automated domain registration monitoring. Registration data may be delayed or redacted by the registry.</div></div></body></html>';
}
function find_subscriber(array &$record, string $email): ?int {
    foreach (($record['subscribers'] ?? []) as $i => $s) if (strtolower((string)($s['email'] ?? '')) === strtolower($email)) return (int)$i;
    return null;
}
function enforce_rate_limit(): bool {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $file = STORAGE_PATH . '/rate/' . hash('sha256', $ip) . '.json';
    $data = json_read($file, ['times' => []]);
    $now = time();
    $times = array_values(array_filter($data['times'] ?? [], fn($t) => ((int)$t) > $now - 3600));
    if (count($times) >= 12) return false;
    $times[] = $now;
    json_write($file, ['times' => $times]);
    return true;
}
function create_or_update_subscription(string $domain, string $email, int $interval): array {
    $path = domain_file($domain);
    $domainLock = acquire_domain_lock($domain);
    if (!$domainLock) return ['ok' => false, 'message' => 'Could not lock this domain record. Please retry.'];
    $record = json_read($path, null);
    if (!is_array($record)) {
        $lookup = build_lookup($domain);
        $record = [
            'domain' => $domain, 'created_at' => now_iso(), 'updated_at' => now_iso(),
            'current_status' => $lookup['status'] ?? 'Status unavailable', 'available' => $lookup['available'] ?? null,
            'registrar' => $lookup['registrar'] ?? '', 'expires_at' => $lookup['expires_at'] ?? null,
            'registered_at' => $lookup['registered_at'] ?? null, 'updated_at_registry' => $lookup['updated_at'] ?? null,
            'rdap_statuses' => $lookup['statuses'] ?? [], 'nameservers' => $lookup['nameservers'] ?? [],
            'dnssec' => $lookup['dnssec'] ?? 'Not published', 'rdap' => $lookup['rdap'] ?? [],
            'source' => $lookup['source'] ?? 'unknown', 'last_checked' => $lookup['checked_at'] ?? null,
            'status_first_observed_at' => $lookup['checked_at'] ?? now_iso(), 'status_first_observed' => $lookup['status'] ?? 'Status unavailable',
            'next_check' => null, 'monitoring_active' => false, 'last_error' => $lookup['ok'] ? '' : trim(($lookup['api_error'] ?? '') . ' ' . ($lookup['rdap_error'] ?? '')),
            'emails_sent' => 0, 'email_attempts' => 0, 'check_count' => $lookup['ok'] ? 1 : 0,
            'subscribers' => [], 'history' => [], 'last_change_at' => null,
        ];
        add_history($record, 'created', 'Monitoring record created for this domain.', $record['current_status']);
        if ($lookup['ok']) add_history($record, 'check', 'Initial registry lookup completed.', $record['current_status']);
    }
    $idx = find_subscriber($record, $email);
    $token = bin2hex(random_bytes(24));
    $needsVerification = false;
    if ($idx !== null) {
        $record['subscribers'][$idx]['interval'] = $interval;
        if (empty($record['subscribers'][$idx]['active'])) {
            $record['subscribers'][$idx]['token_hash'] = hash('sha256', $token);
            $record['subscribers'][$idx]['manage_token'] = $token;
            $record['subscribers'][$idx]['verify_expires_at'] = time() + 86400;
            $needsVerification = true;
        }
        $message = $needsVerification ? 'Existing pending subscription updated; verification email will be resent.' : 'Existing subscriber preference updated. No duplicate subscription was created.';
    } else {
        $record['subscribers'][] = [
            'email' => $email, 'interval' => $interval, 'active' => false,
            'token_hash' => hash('sha256', $token), 'manage_token' => $token, 'created_at' => now_iso(),
            'verify_expires_at' => time() + 86400, 'last_email_at' => null,
            'emails_sent' => 0, 'email_attempts' => 0,
        ];
        $needsVerification = true;
        $message = 'Domain record created or reused. Verify your email to activate notifications.';
    }
    $record['updated_at'] = now_iso();
    if (!json_write($path, $record)) { release_domain_lock($domainLock); return ['ok' => false, 'message' => 'Could not save the monitoring record. Check storage folder permissions.']; }
    release_domain_lock($domainLock);
    if ($needsVerification) {
        $verifyUrl = app_base_url() . '/?verify=' . rawurlencode($domain) . '&token=' . rawurlencode($token);
        $content = '<p>Please confirm that you want domain status notifications for <strong>' . h($domain) . '</strong>.</p><p style="margin:24px 0"><a href="' . h($verifyUrl) . '" style="display:inline-block;background:#6657e8;color:#fff;text-decoration:none;padding:13px 20px;border-radius:10px;font-weight:700">Verify email address</a></p><p style="font-size:13px;color:#687386">This link expires in 24 hours. If you did not request this, you can ignore this email.</p>';
        $text = "Verify your email for {$domain}: {$verifyUrl}\nThis link expires in 24 hours.";
        $sent = send_email($email, 'Confirm domain monitoring for ' . $domain, email_shell('Confirm your email', $content), $text);
        return ['ok' => true, 'mail' => $sent, 'message' => $sent ? $message : ($message . ' However, Hostinger SMTP could not accept the verification message. Check the SMTP settings and PHP error log.')];
    }
    return ['ok' => true, 'mail' => true, 'message' => $message];
}
function verify_or_unsubscribe(string $domain, string $token, bool $unsubscribe = false): string {
    if (!valid_domain($domain) || !preg_match('/^[a-f0-9]{48}$/', $token)) return 'Invalid link.';
    $path = domain_file($domain); $domainLock = acquire_domain_lock($domain);
    if (!$domainLock) return 'Could not access this monitoring record. Please retry.';
    $record = json_read($path, null);
    if (!is_array($record)) { release_domain_lock($domainLock); return 'Monitoring record not found.'; }
    $hash = hash('sha256', $token);
    foreach (($record['subscribers'] ?? []) as $i => $s) {
        if (hash_equals((string)($s['token_hash'] ?? ''), $hash)) {
            if ($unsubscribe) {
                $record['subscribers'][$i]['active'] = false;
                $record['subscribers'][$i]['unsubscribed_at'] = now_iso();
                add_history($record, 'subscriber', 'A subscriber disabled email notifications.');
                $active = false;
                foreach ($record['subscribers'] as $sub) if (!empty($sub['active'])) $active = true;
                $record['monitoring_active'] = $active;
                $record['next_check'] = $active ? gmdate('c', time() + minimum_interval($record) * 60) : null;
                json_write($path, $record);
                release_domain_lock($domainLock);
                return 'Your email notifications have been stopped.';
            }
            if ((int)($s['verify_expires_at'] ?? 0) < time()) { release_domain_lock($domainLock); return 'This verification link has expired. Submit the form again to receive a new link.'; }
            $record['subscribers'][$i]['active'] = true;
            $record['subscribers'][$i]['verified_at'] = now_iso();
            $record['subscribers'][$i]['last_email_at'] = time();
            $record['monitoring_active'] = true;
            $record['next_check'] = gmdate('c', time() + minimum_interval($record) * 60);
            $record['updated_at'] = now_iso();
            add_history($record, 'subscriber', 'A new email subscriber verified their address.');
            json_write($path, $record);
            release_domain_lock($domainLock);
            return 'Your email is verified. Domain monitoring notifications are now active.';
        }
    }
    release_domain_lock($domainLock);
    return 'This link is invalid or has already been used.';
}
function perform_due_checks(): void {
    static $ran = false;
    if ($ran) return; $ran = true;
    $lockPath = STORAGE_PATH . '/engine.lock';
    $lock = @fopen($lockPath, 'c');
    if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); return; }
    $processed = 0; $now = time();
    foreach (glob(STORAGE_PATH . '/domains/*.json') ?: [] as $path) {
        if ($processed >= MAX_CHECKS_PER_REQUEST) break;
        $preRecord = json_read($path, null);
        $domain = (string)($preRecord['domain'] ?? '');
        if (!valid_domain($domain)) continue;
        $domainLock = acquire_domain_lock($domain);
        if (!$domainLock) continue;
        $record = json_read($path, null);
        if (!is_array($record) || empty($record['monitoring_active'])) { release_domain_lock($domainLock); continue; }
        if (empty($record['next_check']) || strtotime((string)$record['next_check']) > $now) { release_domain_lock($domainLock); continue; }
        $lookup = build_lookup($domain); $processed++;
        if (!$lookup['ok']) {
            $record['last_error'] = trim(($lookup['api_error'] ?? '') . ' ' . ($lookup['rdap_error'] ?? ''));
            $record['failure_count'] = (int)($record['failure_count'] ?? 0) + 1;
            $record['next_check'] = gmdate('c', $now + max(300, minimum_interval($record) * 60));
            $record['updated_at'] = now_iso();
            add_history($record, 'error', 'Lookup failed: ' . substr((string)$record['last_error'], 0, 180));
            json_write($path, $record);
            release_domain_lock($domainLock);
            continue;
        }
        $oldStatus = (string)($record['current_status'] ?? 'Unknown');
        $newStatus = (string)$lookup['status'];
        $changed = $oldStatus !== $newStatus;
        $record['current_status'] = $newStatus;
        $record['available'] = $lookup['available'];
        $record['registrar'] = $lookup['registrar'];
        $record['expires_at'] = $lookup['expires_at'];
        $record['registered_at'] = $lookup['registered_at'];
        $record['updated_at_registry'] = $lookup['updated_at'];
        $record['rdap_statuses'] = $lookup['statuses'];
        $record['nameservers'] = $lookup['nameservers'];
        $record['dnssec'] = $lookup['dnssec'];
        if (!empty($lookup['rdap'])) $record['rdap'] = $lookup['rdap'];
        $record['source'] = $lookup['source'];
        $record['last_checked'] = $lookup['checked_at'];
        $record['check_count'] = (int)($record['check_count'] ?? 0) + 1;
        $record['last_error'] = '';
        if ($changed) {
            $record['last_change_at'] = now_iso();
            $record['status_first_observed_at'] = now_iso();
            $record['status_first_observed'] = $newStatus;
            add_history($record, 'change', 'Domain status changed from ' . $oldStatus . ' to ' . $newStatus . '.', $newStatus);
        } else {
            if (empty($record['status_first_observed_at'])) { $record['status_first_observed_at'] = $record['last_checked'] ?? now_iso(); $record['status_first_observed'] = $newStatus; }
            add_history($record, 'check', 'Domain checked. Status unchanged: ' . $newStatus . '.', $newStatus);
        }
        foreach (($record['subscribers'] ?? []) as $i => $subscriber) {
            if (empty($subscriber['active'])) continue;
            $interval = max(MIN_INTERVAL_MINUTES, (int)($subscriber['interval'] ?? DEFAULT_INTERVAL_MINUTES));
            $lastEmail = (int)($subscriber['last_email_at'] ?? 0);
            if ($lastEmail > 0 && ($now - $lastEmail) < $interval * 60) continue;
            $token = '';
            // token_hash is retained for the subscriber's permanent unsubscribe URL; the raw token is only mailed at verification.
            $manageUrl = app_base_url() . '/?unsubscribe=' . rawurlencode($domain) . '&token=' . rawurlencode((string)($subscriber['manage_token'] ?? ''));
            $content = '<p>There is a new monitoring update for <strong>' . h($domain) . '</strong>.</p><div style="padding:16px;background:#f4f6fb;border-radius:12px;margin:18px 0"><div style="font-size:12px;color:#667085">CURRENT STATUS</div><div style="font-size:22px;font-weight:700;margin-top:5px">' . h($newStatus) . '</div><div style="font-size:13px;color:#667085;margin-top:8px">Previous status: ' . h($oldStatus) . '</div></div><p>Checked: ' . h(pretty_date($lookup['checked_at'])) . '<br>Next target check: ' . h(pretty_date($now + minimum_interval($record) * 60)) . '</p><p><a href="' . h(permalink($domain)) . '" style="color:#6657e8">View public monitoring page</a></p><p style="font-size:12px;color:#687386"><a href="' . h($manageUrl) . '">Stop these email notifications</a></p>';
            $text = "Domain: {$domain}\nCurrent status: {$newStatus}\nPrevious status: {$oldStatus}\nChecked: {$lookup['checked_at']}\n" . permalink($domain) . "\nUnsubscribe: {$manageUrl}";
            $sent = send_email((string)$subscriber['email'], 'Domain monitoring update: ' . $domain . ' — ' . $newStatus, email_shell('Domain status update', $content), $text);
            $record['subscribers'][$i]['email_attempts'] = (int)($record['subscribers'][$i]['email_attempts'] ?? 0) + 1;
            $record['email_attempts'] = (int)($record['email_attempts'] ?? 0) + 1;
            if ($sent) {
                $record['subscribers'][$i]['emails_sent'] = (int)($record['subscribers'][$i]['emails_sent'] ?? 0) + 1;
                $record['emails_sent'] = (int)($record['emails_sent'] ?? 0) + 1;
                $record['subscribers'][$i]['last_email_at'] = $now;
                add_history($record, 'email', 'Notification accepted by the Hostinger SMTP server for a subscriber.', $newStatus);
            } else {
                add_history($record, 'email_error', 'Hostinger SMTP could not accept a notification for a subscriber.', $newStatus);
            }
        }
        $record['updated_at'] = now_iso();
        if (STOP_ON_AVAILABLE && $lookup['available'] === true) {
            $record['monitoring_active'] = false; $record['next_check'] = null;
            add_history($record, 'stopped', 'Monitoring automatically stopped after the registry reported the domain as available.', $newStatus);
        } else {
            $anyActive = false; foreach ($record['subscribers'] as $sub) if (!empty($sub['active'])) $anyActive = true;
            $record['monitoring_active'] = $anyActive;
            $record['next_check'] = $anyActive ? gmdate('c', $now + minimum_interval($record) * 60) : null;
        }
        json_write($path, $record);
        release_domain_lock($domainLock);
    }
    @flock($lock, LOCK_UN); @fclose($lock);
}
function public_counts(array $record): array {
    $active = 0; foreach (($record['subscribers'] ?? []) as $s) if (!empty($s['active'])) $active++;
    return ['subscribers' => $active, 'interval' => $active ? minimum_interval($record) : null];
}
function flash(string $message, string $type = 'info'): void { $_SESSION['flash'] = ['message' => $message, 'type' => $type]; }
function take_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return is_array($f) ? $f : null; }

// CSRF token used by the subscription form.
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));

// Verify / unsubscribe links.
if (isset($_GET['verify'], $_GET['token'])) {
    $d = normalise_domain((string)$_GET['verify']);
    $msg = verify_or_unsubscribe($d, (string)$_GET['token'], false);
    flash($msg, strpos($msg, 'now active') !== false ? 'success' : 'warning');
    header('Location: ' . permalink($d), true, 303); exit;
}
if (isset($_GET['unsubscribe'], $_GET['token'])) {
    $d = normalise_domain((string)$_GET['unsubscribe']);
    $msg = verify_or_unsubscribe($d, (string)$_GET['token'], true);
    flash($msg, 'info');
    header('Location: ' . permalink($d), true, 303); exit;
}

// Subscription submission.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'subscribe') {
    if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        flash('Security token expired. Refresh the page and try again.', 'error');
        header('Location: ' . app_base_url() . '/', true, 303); exit;
    }
    if (!empty($_POST['website'])) { flash('Request rejected.', 'error'); header('Location: ' . app_base_url() . '/', true, 303); exit; }
    if (!enforce_rate_limit()) { flash('Too many requests from this network. Please try again later.', 'error'); header('Location: ' . app_base_url() . '/', true, 303); exit; }
    $domain = normalise_domain((string)($_POST['domain'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $interval = (int)($_POST['interval'] ?? DEFAULT_INTERVAL_MINUTES);
    if (!valid_domain($domain)) { flash('Enter a valid domain name, such as example.com.', 'error'); header('Location: ' . app_base_url() . '/', true, 303); exit; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) { flash('Enter a valid email address.', 'error'); header('Location: ' . app_base_url() . '/', true, 303); exit; }
    if ($interval < MIN_INTERVAL_MINUTES || $interval > MAX_INTERVAL_MINUTES) { flash('Choose an interval between 15 minutes and 30 days.', 'error'); header('Location: ' . app_base_url() . '/', true, 303); exit; }
    $result = create_or_update_subscription($domain, $email, $interval);
    flash($result['message'], !empty($result['ok']) ? (!empty($result['mail']) ? 'success' : 'warning') : 'error');
    header('Location: ' . permalink($domain), true, 303); exit;
}

// Plan B: process a small number of due checks on every genuine page request.
perform_due_checks();

$route = (string)($_GET['route'] ?? '');
$requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
if ($route !== 'monitor' && preg_match('#^/monitor/([^/]+)/?$#', $requestPath, $matches)) {
    $route = 'monitor'; $_GET['domain'] = rawurldecode($matches[1]);
}
$domain = $route === 'monitor' ? normalise_domain((string)($_GET['domain'] ?? '')) : '';
$record = ($route === 'monitor' && valid_domain($domain)) ? json_read(domain_file($domain), null) : null;
$flashMessage = take_flash();
$mode = $record ? 'dashboard' : 'home';
if ($route === 'monitor' && !$record) { http_response_code(404); }

function render_header(string $title, string $description, bool $dashboard = false): void {
    $canonical = $dashboard ? permalink(normalise_domain((string)($_GET['domain'] ?? ''))) : app_base_url() . '/';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="description" content="' . h($description) . '"><meta name="theme-color" content="#10172d"><link rel="canonical" href="' . h($canonical) . '"><title>' . h($title) . '</title><style>';
    echo CSS_CONTENT;
    echo '</style></head><body><div class="ambient ambient-a"></div><div class="ambient ambient-b"></div><header class="topbar"><a class="brand" href="' . h(app_base_url() . '/') . '"><span class="brand-mark"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg></span><span>domain<span class="brand-accent">monitor</span></span></a><nav class="nav"><a href="' . h(app_base_url() . '/') . '#how-it-works">How it works</a><a href="' . h(app_base_url() . '/') . '#coverage">Coverage</a><a class="nav-cta" href="' . h(app_base_url() . '/') . '#start">Monitor a domain <span>↗</span></a><button class="theme-toggle" id="themeToggle" type="button" aria-label="Toggle color theme">◐</button></nav></header><main class="page-wrap">';
}
function render_footer(): void {
    echo '</main><footer class="footer"><div class="footer-inner"><span>© ' . date('Y') . ' Domain Monitor</span><span>Registry data via RDAP and TryAPI · Email delivery depends on your hosting mail service</span></div></footer><script>';
    echo JS_CONTENT;
    echo '</script></body></html>';
}

// CSS and JS are embedded to keep the application to a single PHP source file.
const CSS_CONTENT = <<<'CSS'
:root{--bg:#f5f7fc;--surface:rgba(255,255,255,.88);--surface-solid:#fff;--ink:#131b31;--muted:#6c7890;--line:#e4e9f2;--primary:#6957ef;--primary-dark:#5142d3;--mint:#16a982;--amber:#d38a20;--red:#d74b60;--shadow:0 22px 70px rgba(24,35,65,.09);--soft:0 8px 28px rgba(24,35,65,.06)}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.55;overflow-x:hidden}a{color:inherit;text-decoration:none}button,input,select{font:inherit}.ambient{position:fixed;z-index:-1;pointer-events:none;filter:blur(70px);opacity:.32;border-radius:50%}.ambient-a{width:420px;height:420px;background:#b9b5ff;top:-180px;left:-130px}.ambient-b{width:440px;height:440px;background:#a6e8d9;right:-180px;top:380px}.topbar{width:min(1160px,calc(100% - 48px));height:82px;margin:auto;display:flex;align-items:center;justify-content:space-between;position:relative;z-index:5}.brand{display:flex;align-items:center;gap:11px;font-size:19px;font-weight:800;letter-spacing:-.8px}.brand-mark{width:35px;height:35px;background:var(--ink);border-radius:12px;display:grid;place-items:center;color:#fff}.brand-mark svg{width:21px;fill:none;stroke:currentColor;stroke-width:1.5}.brand-accent{color:var(--primary)}.nav{display:flex;align-items:center;gap:28px;color:var(--muted);font-size:13px;font-weight:650}.nav a:hover{color:var(--ink)}.nav-cta{padding:11px 16px;border-radius:11px;background:var(--ink);color:#fff!important}.nav-cta span{margin-left:7px;color:#b8b0ff}.theme-toggle{border:1px solid var(--line);background:var(--surface);color:var(--ink);border-radius:11px;width:38px;height:38px;cursor:pointer}.page-wrap{width:min(1160px,calc(100% - 48px));margin:0 auto;min-height:calc(100vh - 150px)}.hero{padding:88px 0 64px;display:grid;grid-template-columns:1.1fr .9fr;align-items:center;gap:55px}.eyebrow{display:inline-flex;align-items:center;gap:8px;color:var(--primary);font-size:11px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase}.eyebrow:before{content:"";width:7px;height:7px;border-radius:50%;background:var(--mint);box-shadow:0 0 0 5px rgba(22,169,130,.12)}h1,h2,h3,p{margin-top:0}.hero h1{font-size:clamp(43px,6vw,70px);line-height:1.02;letter-spacing:-4px;margin:20px 0 20px;max-width:690px}.gradient-text{background:linear-gradient(100deg,#6655ed 0%,#9a72ee 48%,#19a989 100%);-webkit-background-clip:text;background-clip:text;color:transparent}.hero-copy{font-size:16px;color:var(--muted);max-width:550px;line-height:1.8}.hero-points{display:flex;flex-wrap:wrap;gap:18px;margin-top:25px;color:var(--muted);font-size:12px}.hero-points span:before{content:"✓";color:var(--mint);font-weight:900;margin-right:7px}.search-card{background:var(--surface);border:1px solid rgba(255,255,255,.9);box-shadow:var(--shadow);border-radius:24px;padding:27px;backdrop-filter:blur(18px);position:relative;animation:floatIn .7s cubic-bezier(.2,.8,.2,1) both}.search-card:before{content:"";position:absolute;inset:-1px;border-radius:24px;padding:1px;background:linear-gradient(140deg,rgba(105,87,239,.28),transparent 45%,rgba(22,169,130,.24));-webkit-mask:linear-gradient(#fff 0 0) content-box,linear-gradient(#fff 0 0);-webkit-mask-composite:xor;mask-composite:exclude;pointer-events:none}.card-kicker{font-size:11px;text-transform:uppercase;letter-spacing:1.2px;color:var(--muted);font-weight:800}.search-card h2{font-size:23px;letter-spacing:-.8px;margin:8px 0 6px}.search-card>p{font-size:13px;color:var(--muted);margin-bottom:22px}.field{display:block;margin-bottom:15px}.field label{display:block;font-size:11px;font-weight:800;color:var(--muted);margin-bottom:7px;letter-spacing:.45px}.input-wrap{display:flex;align-items:center;border:1px solid var(--line);border-radius:12px;background:var(--surface-solid);transition:.22s}.input-wrap:focus-within{border-color:#8b7df4;box-shadow:0 0 0 4px rgba(105,87,239,.1)}.input-wrap .prefix{padding-left:14px;color:#97a0b1;font-size:14px}.input-wrap input,.input-wrap select{width:100%;min-width:0;border:0;outline:0;background:transparent;color:var(--ink);padding:14px 13px;font-size:14px}.input-wrap select{cursor:pointer}.field-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.primary-btn{border:0;background:linear-gradient(110deg,#6957ef,#7865f4);color:#fff;border-radius:12px;padding:15px 18px;width:100%;font-size:13px;font-weight:800;cursor:pointer;box-shadow:0 9px 22px rgba(105,87,239,.23);transition:transform .2s,box-shadow .2s}.primary-btn:hover{transform:translateY(-2px);box-shadow:0 14px 27px rgba(105,87,239,.29)}.primary-btn:disabled{opacity:.65;cursor:wait;transform:none}.form-note{font-size:10px;color:#929caf;text-align:center;margin:12px 0 0}.hp{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}.mini-proof{display:flex;justify-content:space-between;margin-top:18px;padding-top:16px;border-top:1px solid var(--line);color:var(--muted);font-size:10px}.mini-proof strong{color:var(--ink)}.section{padding:60px 0}.section-head{text-align:center;max-width:610px;margin:0 auto 32px}.section-head h2{font-size:clamp(28px,4vw,39px);letter-spacing:-1.8px;margin:12px 0}.section-head p{color:var(--muted);font-size:14px;line-height:1.8}.feature-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:15px}.feature{background:var(--surface);border:1px solid var(--line);border-radius:17px;padding:23px;transition:transform .22s,box-shadow .22s}.feature:hover{transform:translateY(-5px);box-shadow:var(--soft)}.feature-icon{width:41px;height:41px;display:grid;place-items:center;background:#efedff;color:var(--primary);border-radius:13px;font-size:18px;margin-bottom:18px}.feature h3{font-size:15px;margin-bottom:8px}.feature p{font-size:12px;color:var(--muted);line-height:1.75;margin:0}.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}.step{padding:21px;border-top:1px solid var(--line)}.step-num{font-size:11px;color:var(--primary);font-weight:900;letter-spacing:1px}.step h3{font-size:15px;margin:12px 0 7px}.step p{font-size:12px;color:var(--muted);line-height:1.7}.coverage-note{margin:20px auto 0;max-width:760px;background:rgba(105,87,239,.06);border:1px solid rgba(105,87,239,.13);padding:15px 18px;border-radius:13px;color:var(--muted);font-size:12px}.flash{margin:18px 0;padding:14px 17px;border-radius:12px;font-size:13px;border:1px solid var(--line);background:var(--surface)}.flash.success{border-color:#bcebdd;background:#edfbf6;color:#087858}.flash.error{border-color:#f2c8cf;background:#fff3f4;color:#aa3344}.flash.warning{border-color:#f0d7aa;background:#fff9ed;color:#8d5b08}.flash.info{background:#f1f3ff;color:#5142d3}.dashboard-head{padding:35px 0 24px}.crumb{font-size:11px;color:var(--muted);margin-bottom:18px}.crumb a{color:var(--primary)}.domain-title-row{display:flex;justify-content:space-between;align-items:flex-start;gap:20px}.domain-title-row h1{font-size:clamp(34px,5vw,48px);letter-spacing:-2px;margin:0 0 9px;overflow-wrap:anywhere}.domain-sub{color:var(--muted);font-size:12px}.status-pill{display:inline-flex;align-items:center;gap:8px;border-radius:99px;padding:8px 12px;font-size:11px;font-weight:800;white-space:nowrap}.status-pill:before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}.status-pill.available{background:#e6faf2;color:#10815f}.status-pill.registered{background:#eef0ff;color:#594bd4}.status-pill.warning{background:#fff5df;color:#a66b10}.status-pill.unknown{background:#edf0f4;color:#687386}.dashboard-grid{display:grid;grid-template-columns:1.55fr .85fr;gap:17px;margin-bottom:20px}.panel{background:var(--surface);border:1px solid var(--line);border-radius:18px;padding:22px;box-shadow:var(--soft);min-width:0}.panel-title{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:16px}.panel-title h2{font-size:14px;margin:0;letter-spacing:-.2px}.panel-title small{color:var(--muted);font-size:10px}.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:17px}.stat{background:var(--surface);border:1px solid var(--line);border-radius:15px;padding:17px;min-width:0}.stat-label{font-size:10px;font-weight:750;letter-spacing:.5px;text-transform:uppercase;color:var(--muted)}.stat-value{font-size:24px;font-weight:850;letter-spacing:-1px;margin-top:8px;overflow-wrap:anywhere}.stat-foot{font-size:10px;color:var(--muted);margin-top:3px}.detail-list{display:grid;grid-template-columns:1fr 1fr;gap:0 22px}.detail-row{display:flex;justify-content:space-between;gap:15px;padding:12px 0;border-bottom:1px solid var(--line);font-size:11px}.detail-row span:first-child{color:var(--muted)}.detail-row strong{text-align:right;overflow-wrap:anywhere;font-weight:700}.tag-list{display:flex;flex-wrap:wrap;gap:7px}.tag{font-size:10px;padding:6px 9px;border-radius:8px;background:#f0efff;color:#5c50c5;font-weight:700;overflow-wrap:anywhere}.dark .tag{background:#28294b;color:#c4bdff}.timeline{list-style:none;padding:0;margin:0}.timeline li{position:relative;padding:0 0 17px 22px;border-left:1px solid var(--line);margin-left:5px}.timeline li:last-child{border-left-color:transparent;padding-bottom:0}.timeline li:before{content:"";position:absolute;width:8px;height:8px;background:var(--primary);border:3px solid #eeecff;border-radius:50%;left:-6px;top:3px}.timeline strong{font-size:11px;display:block}.timeline small{font-size:10px;color:var(--muted);display:block;margin-top:3px;line-height:1.6}.raw-box{max-height:360px;overflow:auto;background:#10172d;color:#d8e1f6;border-radius:12px;padding:16px;font:11px/1.65 ui-monospace,SFMono-Regular,Menlo,monospace;white-space:pre-wrap;overflow-wrap:anywhere}.action-row{display:flex;gap:10px;flex-wrap:wrap}.secondary-btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 13px;border:1px solid var(--line);border-radius:10px;background:var(--surface-solid);color:var(--ink);font-size:11px;font-weight:750;cursor:pointer}.secondary-btn:hover{border-color:#a69df5;color:var(--primary)}.empty{padding:24px;text-align:center;border:1px dashed var(--line);border-radius:13px;color:var(--muted);font-size:12px}.footer{border-top:1px solid var(--line);margin-top:60px}.footer-inner{width:min(1160px,calc(100% - 48px));margin:auto;min-height:72px;display:flex;justify-content:space-between;align-items:center;gap:20px;color:var(--muted);font-size:10px}.not-found{text-align:center;padding:90px 0}.not-found h1{font-size:42px;letter-spacing:-2px}.dark{--bg:#0c1120;--surface:rgba(21,29,49,.9);--surface-solid:#151d31;--ink:#edf1fb;--muted:#9ca8bd;--line:#29344b;--shadow:0 22px 70px rgba(0,0,0,.27);--soft:0 8px 28px rgba(0,0,0,.18)}.dark .ambient{opacity:.18}.dark .brand-mark{background:#e9ecff;color:#11172a}.dark .nav-cta{background:#e9ecff;color:#131b31!important}.dark .feature-icon{background:#28294b}.dark .status-pill.available{background:#123c35;color:#75e3bc}.dark .status-pill.registered{background:#28294b;color:#c4bdff}.dark .status-pill.warning{background:#49371d;color:#f4c879}.dark .status-pill.unknown{background:#273044;color:#bac3d1}.dark .flash.success{background:#12382f;color:#8ce5c5;border-color:#225846}.dark .flash.error{background:#43242d;color:#ffacb7;border-color:#63313e}.dark .flash.warning{background:#43351e;color:#f1cb80;border-color:#68512b}.dark .flash.info{background:#28294b;color:#c5bdff}.dark .coverage-note{background:#211f40;border-color:#3b3863}.dark .search-card{border-color:#27314a}.dark .timeline li:before{border-color:#28294b}.dark .input-wrap{background:#111a2c}.dark .nav{color:var(--muted)}
.lifecycle-panel{margin:0 0 22px;padding:25px 24px 20px;border-radius:18px;background:#0d1b36;color:#edf4ff;box-shadow:0 18px 44px rgba(7,18,42,.18);overflow:hidden;position:relative}.lifecycle-panel:before{content:"";position:absolute;inset:0;pointer-events:none;opacity:.22;background-image:radial-gradient(#7890b5 1px,transparent 1px);background-size:14px 14px}.lifecycle-head,.lifecycle-legend,.lifecycle-scroll,.lifecycle-footnote{position:relative;z-index:1}.lifecycle-head{display:flex;align-items:flex-start;justify-content:space-between;gap:20px}.lifecycle-kicker{font-size:10px;letter-spacing:1.5px;color:#9aacc9;font-weight:850;display:flex;align-items:center;gap:10px}.lifecycle-tld{background:#1260b2;color:#fff;padding:3px 9px;border-radius:6px;letter-spacing:.5px}.lifecycle-head h2{font-size:clamp(18px,2.4vw,26px);letter-spacing:-.7px;margin:13px 0 6px;color:#f7faff}.lifecycle-head h2 span{font-weight:650}.lifecycle-head p{font-size:12px;color:#b1bfd5;max-width:770px;margin:0;line-height:1.7}.lifecycle-side{display:flex;flex-direction:column;align-items:flex-end;gap:7px;flex-shrink:0}.lifecycle-state{border:1px solid rgba(255,255,255,.22);border-radius:8px;padding:6px 9px;font-size:9px;font-weight:850;letter-spacing:.8px;color:#f8fafc}.lifecycle-source{font-size:10px;color:#a7bad8}.lifecycle-legend{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:17px;margin:18px 0 5px;color:#a7b6cf;font-size:10px}.lifecycle-legend span{display:inline-flex;align-items:center;gap:7px}.legend-dot{width:8px;height:8px;border-radius:50%;display:inline-block}.legend-dot.recorded{background:#21c7dc}.legend-dot.today{background:#15c6dd;box-shadow:0 0 0 3px rgba(21,198,221,.18)}.legend-line{width:17px;border-top:2px dashed #8d9db8;display:inline-block}.lifecycle-scroll{overflow-x:auto;padding:15px 0 5px;scrollbar-width:thin;scrollbar-color:#405577 transparent}.lifecycle-track{display:flex;align-items:flex-start;gap:9px;min-width:max-content;position:relative;padding:0 4px}.lifecycle-track:before{content:"";height:2px;position:absolute;top:15px;left:16px;right:16px;background:linear-gradient(90deg,#1ac4da 0%,#1ac4da 34%,#f0877e 60%,#647693 100%);opacity:.85}.lifecycle-item{position:relative;width:132px;flex:0 0 132px;padding-top:0}.lifecycle-node{position:relative;z-index:1;width:31px;height:31px;margin:0 0 7px 0;border-radius:10px;border:2px solid #27c7dc;background:#102b4e;color:#e9fbff;display:grid;place-items:center;font-size:13px;font-weight:900;box-shadow:0 0 0 4px rgba(39,199,220,.08)}.lifecycle-item.projected .lifecycle-node{border:1.5px dashed #8b9ab2;color:#afbdd2;background:#14213b;box-shadow:none}.lifecycle-item.today-item .lifecycle-node{width:36px;height:36px;margin-top:-3px;margin-bottom:5px;border-radius:50%;border:2px solid #1bd0e7;background:#0f3b5a;color:#1bd0e7;box-shadow:0 0 0 5px rgba(27,208,231,.13);font-size:16px}.lifecycle-card{min-height:116px;border:1px solid rgba(124,151,191,.35);border-bottom:3px solid #21c6dc;border-radius:9px;padding:9px 9px 8px;background:rgba(20,42,73,.88)}.lifecycle-item.projected .lifecycle-card{border-style:dashed;border-bottom:1px dashed rgba(143,160,187,.6);background:rgba(18,32,56,.65)}.lifecycle-item.today-item .lifecycle-card{border-color:#14c5df;border-bottom:3px solid #14c5df;background:rgba(7,68,94,.68)}.lifecycle-item.past .lifecycle-card{opacity:.82}.lifecycle-type{font-size:8px;letter-spacing:1px;font-weight:850;color:#62d9e8;margin-bottom:5px}.lifecycle-item.projected .lifecycle-type{color:#9babc4}.lifecycle-item.today-item .lifecycle-type{color:#45e3f2}.lifecycle-label{font-size:10px;line-height:1.35;color:#becce0;min-height:27px}.lifecycle-card strong{display:block;font-size:11px;color:#f6f9ff;margin-top:5px;line-height:1.3}.lifecycle-card small{display:block;color:#f0a09a;font-size:9px;margin-top:3px}.lifecycle-item.projected .lifecycle-card small{color:#a9b8d0}.lifecycle-item.today-item .lifecycle-card small{color:#5ae4f0}.lifecycle-card p{font-size:8px;line-height:1.45;color:#9fb0ca;margin:6px 0 0}.lifecycle-footnote{display:flex;align-items:flex-start;gap:9px;margin-top:12px;padding-top:12px;border-top:1px solid rgba(136,158,192,.2);color:#aab9d0}.lifecycle-footnote span{color:#61d5e5;font-size:14px}.lifecycle-footnote p{font-size:9px;line-height:1.6;margin:0}.dashboard-actions{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-top:15px}.domain-meta-chips{display:flex;flex-wrap:wrap;gap:7px;margin-top:14px}.domain-meta-chip{display:inline-flex;align-items:center;gap:6px;border-radius:7px;border:1px solid var(--line);background:var(--surface);padding:6px 9px;color:var(--muted);font-size:10px}.domain-meta-chip strong{color:var(--ink);font-weight:800}.dark .domain-meta-chip{border-color:var(--line);background:var(--surface-solid)}@keyframes floatIn{from{opacity:0;transform:translateY(18px) scale(.99)}to{opacity:1;transform:translateY(0) scale(1)}}@keyframes rise{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}.reveal{animation:rise .65s both}.delay-1{animation-delay:.08s}.delay-2{animation-delay:.16s}.delay-3{animation-delay:.24s}@media(max-width:900px){.hero{grid-template-columns:1fr;gap:35px;padding-top:55px}.hero-copy{max-width:700px}.search-card{max-width:650px}.dashboard-grid{grid-template-columns:1fr}.stats-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:640px){.topbar,.page-wrap,.footer-inner{width:min(100% - 30px,1160px)}.topbar{height:68px}.nav{gap:10px}.nav>a:not(.nav-cta){display:none}.nav-cta{font-size:10px;padding:9px 10px}.brand{font-size:16px}.hero{padding:48px 0 40px}.hero h1{font-size:45px;letter-spacing:-2.8px}.hero-copy{font-size:14px}.search-card{padding:20px;border-radius:19px}.field-grid{grid-template-columns:1fr}.feature-grid,.steps{grid-template-columns:1fr}.section{padding:42px 0}.domain-title-row{display:block}.domain-title-row .status-pill{margin-top:13px}.stats-grid{gap:8px}.stat{padding:13px}.stat-value{font-size:21px}.panel{padding:17px}.detail-list{grid-template-columns:1fr}.footer-inner{padding:18px 0;display:block;line-height:2}.mini-proof{gap:12px}.mini-proof span:last-child{text-align:right}}@media(prefers-reduced-motion:reduce){*,*:before,*:after{scroll-behavior:auto!important;animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}}
CSS;
const JS_CONTENT = <<<'JS'
(function(){
  const root=document.documentElement, saved=localStorage.getItem('dm-theme');
  if(saved==='dark')root.classList.add('dark');
  const toggle=document.getElementById('themeToggle');
  if(toggle)toggle.addEventListener('click',()=>{root.classList.toggle('dark');localStorage.setItem('dm-theme',root.classList.contains('dark')?'dark':'light');});
  const form=document.getElementById('monitorForm'),btn=document.getElementById('submitBtn');
  if(form&&btn)form.addEventListener('submit',()=>{btn.disabled=true;btn.textContent='Preparing your monitor…';});
  document.querySelectorAll('[data-copy]').forEach(el=>el.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(el.getAttribute('data-copy'));const old=el.textContent;el.textContent='Copied';setTimeout(()=>el.textContent=old,1400);}catch(e){el.textContent='Copy unavailable';}}));
})();
JS;

if ($mode === 'dashboard' && is_array($record)) {
    render_header($domain . ' — Domain Monitor', 'Public domain registration monitoring dashboard, RDAP information and status history.', true);
    if ($flashMessage) echo '<div class="flash ' . h($flashMessage['type']) . '">' . h($flashMessage['message']) . '</div>';
    $counts = public_counts($record); $status = (string)($record['current_status'] ?? 'Status unavailable');
    $active = !empty($record['monitoring_active']); $sc = status_class($status);
    $history = $record['history'] ?? []; $rdapPublic = public_rdap($record['rdap'] ?? []);
    $rawJson = json_encode($rdapPublic, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    $tldLabel = '.' . strtolower((string)substr(strrchr('.' . $domain, '.'), 1));
    $registrarLabel = (string)($record['registrar'] ?? 'Registrar not published');
    $dnssecLabel = (string)($record['dnssec'] ?? 'Not published');
    $copyAllText = $domain . "\nStatus: " . $status . "\nRegistrar: " . $registrarLabel . "\nRegistered: " . (string)($record['registered_at'] ?? 'Not published') . "\nExpires: " . (string)($record['expires_at'] ?? 'Not published') . "\nLast updated: " . (string)($record['updated_at_registry'] ?? 'Not published') . "\nDNSSEC: " . $dnssecLabel . "\nNameservers: " . implode(', ', (array)($record['nameservers'] ?? [])) . "\nRDAP status: " . implode(', ', (array)($record['rdap_statuses'] ?? [])) . "\nPublic monitor: " . permalink($domain);
    echo '<section class="dashboard-head reveal"><div class="crumb"><a href="' . h(app_base_url() . '/') . '">Home</a> <span> / </span> Domain monitoring</div><div class="domain-title-row"><div><div class="eyebrow">PUBLIC MONITORING RECORD</div><h1>' . h($domain) . '</h1><div class="domain-sub">Unique domain record · Created ' . h(pretty_date($record['created_at'] ?? '')) . ' · <a href="' . h(permalink($domain)) . '" style="color:var(--primary)">Permanent permalink</a></div><div class="domain-meta-chips"><span class="domain-meta-chip"><strong>' . h($tldLabel) . '</strong> TLD</span><span class="domain-meta-chip"><strong>RDAP</strong> registry data</span><span class="domain-meta-chip"><strong>' . h($dnssecLabel) . '</strong> DNSSEC</span><span class="domain-meta-chip"><strong>' . (int)count((array)($record['nameservers'] ?? [])) . '</strong> nameservers</span><span class="domain-meta-chip"><strong>' . h($registrarLabel) . '</strong> registrar</span></div><div class="dashboard-actions"><a class="secondary-btn" href="' . h(app_base_url() . '/') . '#start">◎ Watch this domain</a><button class="secondary-btn" type="button" data-copy="' . h(permalink($domain)) . '">▢ Copy link</button><button class="secondary-btn" type="button" data-copy="' . h($copyAllText) . '">▢ Copy all</button></div></div><span class="status-pill ' . h($sc) . '">' . h($status) . '</span></div></section>';
    echo lifecycle_markup($record, $domain);
    echo '<section class="stats-grid reveal delay-1"><div class="stat"><div class="stat-label">Current status</div><div class="stat-value" style="font-size:17px">' . h($status) . '</div><div class="stat-foot">Source: ' . h($record['source'] ?? 'Unknown') . '</div></div><div class="stat"><div class="stat-label">Active subscribers</div><div class="stat-value">' . (int)$counts['subscribers'] . '</div><div class="stat-foot">Email addresses verified</div></div><div class="stat"><div class="stat-label">Emails accepted</div><div class="stat-value">' . (int)($record['emails_sent'] ?? 0) . '</div><div class="stat-foot">Hostinger SMTP accepted</div></div><div class="stat"><div class="stat-label">Registry checks</div><div class="stat-value">' . (int)($record['check_count'] ?? 0) . '</div><div class="stat-foot">Successful lookups recorded</div></div></section>';
    echo '<section class="dashboard-grid"><div><div class="panel reveal delay-1"><div class="panel-title"><h2>Registration overview</h2><small>Latest available registry data</small></div><div class="detail-list">';
    $details = [
        'Registrar' => $record['registrar'] ?? '', 'Registration date' => $record['registered_at'] ?? '',
        'Expiration date' => $record['expires_at'] ?? '', 'Registry last updated' => $record['updated_at_registry'] ?? '',
        'Last checked' => $record['last_checked'] ?? '', 'Next target check' => $record['next_check'] ?? '',
        'Monitoring' => $active ? 'Active (visitor-triggered)' : 'Paused / stopped', 'DNSSEC' => $record['dnssec'] ?? 'Not published',
        'Lookup source' => $record['source'] ?? '', 'Last status change' => $record['last_change_at'] ?? '',
    ];
    foreach ($details as $label => $value) echo '<div class="detail-row"><span>' . h($label) . '</span><strong>' . h($value ? (in_array($label, ['Last checked','Next target check','Registration date','Expiration date','Registry last updated','Last status change'], true) ? pretty_date($value) : $value) : 'Not published') . '</strong></div>';
    echo '</div></div><div class="panel reveal delay-2" style="margin-top:16px"><div class="panel-title"><h2>Domain status codes</h2><small>RDAP / EPP status values</small></div>';
    if (!empty($record['rdap_statuses'])) { echo '<div class="tag-list">'; foreach ($record['rdap_statuses'] as $s) echo '<span class="tag">' . h($s) . '</span>'; echo '</div>'; } else echo '<div class="empty">No status codes were returned by the registry.</div>';
    echo '<div class="panel-title" style="margin-top:22px"><h2>Nameservers</h2><small>' . count($record['nameservers'] ?? []) . ' records</small></div>';
    if (!empty($record['nameservers'])) { echo '<div class="tag-list">'; foreach ($record['nameservers'] as $ns) echo '<span class="tag">' . h($ns) . '</span>'; echo '</div>'; } else echo '<div class="empty">Nameservers are not published in the available RDAP response.</div>';
    echo '</div><div class="panel reveal delay-3" style="margin-top:16px"><div class="panel-title"><h2>Public RDAP response</h2><small>Contact fields redacted</small></div><details><summary class="secondary-btn">View available raw registration data</summary><pre class="raw-box" style="margin-top:14px">' . h($rawJson) . '</pre></details><p class="form-note" style="text-align:left">RDAP fields depend on the registry. Personal contact fields such as email, phone and postal address are redacted on this public page.</p></div></div>';
    echo '<aside><div class="panel reveal delay-1"><div class="panel-title"><h2>Monitoring health</h2><span class="status-pill ' . ($active ? 'available' : 'unknown') . '">' . ($active ? 'Enabled' : 'Inactive') . '</span></div><div class="detail-row"><span>Fastest interval</span><strong>' . ($counts['interval'] ? (int)$counts['interval'] . ' minutes' : '—') . '</strong></div><div class="detail-row"><span>Email attempts</span><strong>' . (int)($record['email_attempts'] ?? 0) . '</strong></div><div class="detail-row"><span>Last API error</span><strong>' . h($record['last_error'] ?: 'None recorded') . '</strong></div><p class="form-note" style="text-align:left;margin-top:13px">Plan B monitoring runs when a visitor request executes PHP. Checks can be late during periods of low traffic.</p><div class="action-row" style="margin-top:15px"><button class="secondary-btn" type="button" data-copy="' . h(permalink($domain)) . '">Copy permalink</button><a class="secondary-btn" href="' . h(app_base_url() . '/') . '#start">Add subscriber</a></div></div>';
    echo '<div class="panel reveal delay-2" style="margin-top:16px"><div class="panel-title"><h2>Activity timeline</h2><small>Latest 12 events</small></div>';
    if ($history) { echo '<ul class="timeline">'; foreach (array_slice($history, 0, 12) as $event) echo '<li><strong>' . h($event['message'] ?? '') . '</strong><small>' . h(pretty_date($event['at'] ?? '')) . '</small></li>'; echo '</ul>'; } else echo '<div class="empty">No activity has been recorded yet.</div>';
    echo '</div><div class="panel reveal delay-3" style="margin-top:16px"><div class="panel-title"><h2>Monitor another domain</h2></div><form method="post" action="' . h(app_base_url() . '/') . '" id="monitorForm"><input type="hidden" name="action" value="subscribe"><input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '"><label class="field"><span class="field-label">Domain name</span><span class="input-wrap"><input name="domain" value="' . h($domain) . '" required></span></label><label class="field"><span class="field-label">Notification email</span><span class="input-wrap"><input type="email" name="email" placeholder="you@example.com" required></span></label><label class="field"><span class="field-label">Check interval</span><span class="input-wrap"><select name="interval"><option value="15">Every 15 minutes</option><option value="30">Every 30 minutes</option><option value="60">Every hour</option><option value="120">Every 2 hours</option><option value="360">Every 6 hours</option><option value="720">Every 12 hours</option><option value="1440">Every 24 hours</option></select></span></label><input class="hp" name="website" tabindex="-1" autocomplete="off"><button class="primary-btn" id="submitBtn">Add email notification</button></form></div></aside></section>';
    echo '<div class="coverage-note">Availability is based on the API/registry response and is not a reservation. A domain can be registered by another party before you complete checkout. RDAP may omit or redact data.</div>';
    render_footer(); exit;
}

render_header('Domain Monitor — Track Domain Availability', 'Monitor domain registration status, receive email alerts and view public RDAP registration history.', false);
if ($flashMessage) echo '<div class="flash ' . h($flashMessage['type']) . '">' . h($flashMessage['message']) . '</div>';
if ($route === 'monitor' && !$record) {
    echo '<section class="not-found"><div class="eyebrow">RECORD NOT FOUND</div><h1>We could not find that monitor.</h1><p class="hero-copy" style="margin:0 auto 25px">This domain does not have a monitoring record yet. Create one to start tracking its registration status.</p><a class="primary-btn" style="display:inline-block;width:auto" href="' . h(app_base_url() . '/') . '#start">Create a monitor</a></section>';
} else {
    echo '<section class="hero"><div class="reveal"><div class="eyebrow">DOMAIN INTELLIGENCE, WITHOUT THE NOISE</div><h1>Know when your next domain is <span class="gradient-text">within reach.</span></h1><p class="hero-copy">Track registration status, follow domain lifecycle changes and receive email notifications when your target domain moves closer to normal registration.</p><div class="hero-points"><span>One record per domain</span><span>Shareable monitoring page</span><span>RDAP-powered details</span></div></div>';
    echo '<div class="search-card" id="start"><div class="card-kicker">Start monitoring</div><h2>Keep an eye on a domain.</h2><p>Enter a domain, choose your check interval and add an email address.</p><form method="post" action="' . h(app_base_url() . '/') . '" id="monitorForm"><input type="hidden" name="action" value="subscribe"><input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '"><label class="field"><span class="field-label">DOMAIN NAME</span><span class="input-wrap"><span class="prefix">⌕</span><input type="text" name="domain" placeholder="example.com" autocomplete="off" required maxlength="253"></span></label><div class="field-grid"><label class="field"><span class="field-label">CHECK INTERVAL</span><span class="input-wrap"><select name="interval"><option value="15">15 minutes</option><option value="30">30 minutes</option><option value="60">60 minutes</option><option value="120">120 minutes</option><option value="360">6 hours</option><option value="720">12 hours</option><option value="1440">24 hours</option><option value="2880">48 hours</option><option value="10080">7 days</option></select></span></label><label class="field"><span class="field-label">EMAIL ADDRESS</span><span class="input-wrap"><input type="email" name="email" placeholder="you@example.com" required maxlength="190"></span></label></div><label class="hp">Leave this field empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label><button class="primary-btn" id="submitBtn" type="submit">Create domain monitor <span style="margin-left:7px">→</span></button><div class="form-note">We send a verification email before notifications begin.</div></form><div class="mini-proof"><span><strong>JSON storage</strong> · No database</span><span><strong>Private</strong> API key stays server-side</span></div></div></section>';
    echo '<section class="section" id="how-it-works"><div class="section-head"><div class="eyebrow">SIMPLE BY DESIGN</div><h2>One domain. One record. Clear signals.</h2><p>Returning visitors subscribe to the existing domain monitor. The system does not create a separate domain record for every email address.</p></div><div class="feature-grid"><article class="feature reveal"><div class="feature-icon">◎</div><h3>Registry-aware status</h3><p>Availability is checked through TryAPI, with direct RDAP lookups used to enrich the public registration record.</p></article><article class="feature reveal delay-1"><div class="feature-icon">↗</div><h3>One permanent page</h3><p>Every monitored domain receives a shareable permalink with status, available registration details and activity history.</p></article><article class="feature reveal delay-2"><div class="feature-icon">✉</div><h3>Subscriber-level alerts</h3><p>Multiple people can follow the same domain with their own email addresses and preferred notification intervals.</p></article></div></section>';
    echo '<section class="section" id="coverage"><div class="section-head"><div class="eyebrow">HOW IT WORKS</div><h2>From search to notification.</h2><p>The lightweight workflow uses PHP, JSON files and visitor-triggered execution. No SQL database or admin panel is required.</p></div><div class="steps"><div class="step"><div class="step-num">01 / SUBSCRIBE</div><h3>Submit a domain</h3><p>The application normalises the domain and checks whether a monitoring record already exists. Your email is verified before notifications activate.</p></div><div class="step"><div class="step-num">02 / MONITOR</div><h3>Check when due</h3><p>When a visitor request executes PHP, due domain records are processed. A quiet website means checks can be delayed; missed historical checks are not replayed.</p></div><div class="step"><div class="step-num">03 / NOTIFY</div><h3>Send an update</h3><p>Authenticated Hostinger SMTP sends the current status to subscribers whose notification interval is due. Monitoring stops after confirmed availability by default.</p></div></div><div class="coverage-note"><strong>Important:</strong> This build uses traffic-triggered monitoring (Plan B), not a background scheduler. It cannot guarantee a 15-minute check when nobody visits the site. TryAPI&#39;s availability API is labelled free per call, but the provider currently documents 1,000 introductory calls for new accounts; confirm your account allowance before scaling.</div></section>';
}
render_footer();
