<?php
// careerhack.ru/pull.php
// Pulls a subdirectory from a GitHub repo and overwrites the directory this file lives in.
// Configuration lives in ./pull-config.php (next to this file). Delete pull-config.php to re-run setup.
//
// Usage: open https://<your-host>/pull.php in a browser.
// Append ?plain=1 for unstyled text/plain output (useful for cron / curl).
// Append ?watch=1 for the tracking screen: it polls the tracked ref and can deploy on its own.
// Append ?check=1 to deploy only when the tracked ref moved (the cron-friendly entry point).
// Append ?status=1 for a JSON snapshot of "what is live vs. what is on GitHub".
// Append ?history=1 for the deploy log: every version that stood here, with the
//   date it was deployed and a button to roll back to it.
// Append ?logout=1 to forget a remembered password on this browser.
//
// What gets deployed is picked during setup: the head of a branch, or the head of a
// pull request (deploy previews). The deployed commit is remembered in ./pull-state.json,
// which is what makes "only pull when something changed" possible.
//
// Optional password gate (set during setup): the password is stored hashed and
// can be remembered in a cookie. For cron, send it as the X-Pull-Password header.
// Optional purge: after copying, delete everything that is no longer in the repo.

declare(strict_types=1);

const CONFIG_FILE = 'pull-config.php';
const STATE_FILE  = 'pull-state.json';
const ALWAYS_KEEP = ['pull.php', 'pull-config.php', 'pull-state.json'];

// Prefilled fine-grained token form: everything but the repository picker can be
// set from the URL. See docs.github.com -> "Pre-filling personal access token details".
const PAT_NEW_URL     = 'https://github.com/settings/personal-access-tokens/new';
const PAT_CLASSIC_URL = 'https://github.com/settings/tokens/new?scopes=repo&description=pull.php+deploy';

const AUTO_INTERVAL_MIN = 15;
const AUTO_INTERVAL_DEF = 60;

// How many past deploys stay in the log, and so how far back a rollback can reach.
const HISTORY_MAX = 20;

const AUTH_COOKIE       = 'pull_auth';
const AUTH_REMEMBER_TTL = 2592000; // "remember me" cookie lifetime: 30 days
const AUTH_SESSION_TTL  = 43200;   // without "remember me": 12 hours, session cookie

$configPath = __DIR__ . '/' . CONFIG_FILE;
$plain      = isset($_GET['plain']);

// First-run setup: render form (GET) or write config (POST)
if (!file_exists($configPath)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handle_setup_post($configPath);
    } else {
        render_setup_form();
    }
    exit;
}

$config = load_config($configPath);
date_default_timezone_set($config['timezone'] ?: 'UTC');

ignore_user_abort(true);
@set_time_limit(300);

// Optional IP allowlist (KRASKI_PULL_ALLOW_IPS, comma-separated) restricts who
// may trigger a pull. Empty = no restriction.
$denied = null;
$allow = array_filter(array_map(
    'trim',
    explode(',', (string)(getenv('KRASKI_PULL_ALLOW_IPS') ?: ''))
));
if ($allow !== [] && !in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), $allow, true)) {
    $denied = 'forbidden: IP not allowed';
}
if ($denied !== null) {
    http_response_code(403);
    if ($plain) {
        header('Content-Type: text/plain; charset=utf-8');
        exit($denied . "\n");
    }
    header('Content-Type: text/html; charset=utf-8');
    exit("<!doctype html><meta charset=\"utf-8\"><title>403</title><body style=\"background:#1e1e1e;color:#ff6b6b;font-family:monospace;padding:2rem\">"
        . htmlspecialchars($denied, ENT_QUOTES) . "</body>");
}

$mode = isset($_GET['status']) ? 'json' : ($plain ? 'plain' : 'html');

// Optional password gate. An empty password_hash means open access.
if ($config['password_hash'] !== '') {
    require_auth($config['password_hash'], $mode);
}

$ghToken = (string)(getenv('GITHUB_TOKEN') ?: $config['gh_token']);

// Read-only endpoints. None of them touch the target directory.
if (isset($_GET['status']))  { emit_status_json($config, $ghToken); exit; }
if (isset($_GET['watch']))   { render_watch_page($config);          exit; }
if (isset($_GET['history'])) { render_history_page($config);        exit; }

// ?check=1 deploys only when the tracked ref moved since the last successful pull.
$checkOnly = isset($_GET['check']);

// A rollback re-deploys a commit that already stood on this server. The version is
// looked up in the deploy log, so only what actually ran here can be rolled back to.
$rollbackTo = trim((string)($_POST['rollback'] ?? $_GET['rollback'] ?? ''));

// Resolve what to deploy before any output, so a hard failure can still answer with
// a real HTTP status instead of a 200 with an error inside it. Streaming starts
// right after, as it always did.
$state    = state_read();
$deployed = (string)($state['sha'] ?? '');
$track    = $rollbackTo !== ''
    ? resolve_rollback($rollbackTo, $state)
    : resolve_target($config, $ghToken);
if ($track['fatal']) http_response_code(502);

start_output($plain);

$tz       = date_default_timezone_get();
$startTs  = microtime(true);
$startStr = date('Y-m-d H:i:s');

term("================================================");
term("  START:    {$startStr} ({$tz})");
term("================================================");
term("");

$isRollback = $track['mode'] === 'rollback';

term(($isRollback ? "rollback:  " : "tracking:  ") . $track['label']);
if ($track['sha'] !== '') {
    term(($isRollback ? "version:   " : "head:      ") . short_sha($track['sha'])
        . ($deployed !== ''
            ? "   (deployed: " . short_sha($deployed) . ")"
            : "   (nothing deployed from here yet)"));
}
if ($track['error'] !== '') term("warning: {$track['error']}");

// A pull request that cannot be resolved has no safe fallback — stop before the copy.
if ($track['fatal']) {
    term("error: cannot resolve what to deploy — nothing was touched");
    // A missing version is a bad parameter, not a permissions problem: no token hints.
    if ($isRollback) {
        term("hint: pull.php?history=1 lists the versions that can be rolled back to");
    } else {
        foreach (token_hint_lines($config) as $line) term($line);
    }
    print_finish_banner($startTs, $startStr, $tz, false);
    end_output();
    exit;
}

// A rollback pins this directory to an old commit. Automatic deploys stand down
// until someone deploys the tracked head again, otherwise the next check would
// immediately undo the rollback.
if ($checkOnly && !$isRollback && !empty($state['pinned'])) {
    term("check: pinned to " . short_sha($deployed) . " by a rollback on " . (string)($state['at'] ?? '?'));
    term("       automatic deploys are paused — open pull.php to deploy the tracked head and resume");
    print_finish_banner($startTs, $startStr, $tz, true, 0, null, 'PINNED');
    end_output();
    exit;
}

if ($checkOnly) {
    if ($track['sha'] === '') {
        term("check: head commit unknown — deploying anyway");
    } elseif ($track['sha'] === $deployed) {
        term("check: already up to date — nothing to do");
        print_finish_banner($startTs, $startStr, $tz, true, 0, null, 'UP-TO-DATE');
        end_output();
        exit;
    } else {
        term("check: new commit found — deploying");
    }
}
term("");

$target = __DIR__;
$tmp    = sys_get_temp_dir() . '/pull_' . bin2hex(random_bytes(6));
if (!mkdir($tmp, 0755, true)) {
    http_response_code(500);
    term("error: cannot create temp dir");
    print_finish_banner($startTs, $startStr, $tz, false);
    end_output();
    exit;
}

$authHeaders = $ghToken !== '' ? ["Authorization: Bearer {$ghToken}"] : [];

// With a resolved commit we download that exact sha: the deploy then matches what
// was reported above, and PR heads are reachable this way while branch names are not.
$zipUrls = $track['sha'] !== ''
    ? [
        sprintf('https://api.github.com/repos/%s/zipball/%s', $config['repo'], $track['sha']),
        sprintf('https://codeload.github.com/%s/zip/%s', $config['repo'], $track['sha']),
    ]
    : [
        sprintf('https://api.github.com/repos/%s/zipball/%s', $config['repo'], $config['branch']),
        sprintf('https://codeload.github.com/%s/zip/refs/heads/%s', $config['repo'], $config['branch']),
        sprintf('https://github.com/%s/archive/refs/heads/%s.zip', $config['repo'], $config['branch']),
    ];
$zipFile = $tmp . '/repo.zip';

if ($ghToken === '') {
    term("warning: no GitHub token set — private repo downloads will return 404");
}

$bytes = 0;
$lastErr = '';
foreach ($zipUrls as $zipUrl) {
    term("downloading {$zipUrl}");
    [$bytes, $lastErr] = download($zipUrl, $zipFile, $authHeaders);
    if ($bytes > 0) break;
    term("  failed: {$lastErr}");
}
if ($bytes <= 0) {
    cleanup($tmp);
    http_response_code(502);
    term("download failed: {$lastErr}");
    if (strpos($lastErr, 'http 404') !== false || strpos($lastErr, 'http 401') !== false) {
        foreach (token_hint_lines($config) as $line) term($line);
    }
    print_finish_banner($startTs, $startStr, $tz, false);
    end_output();
    exit;
}
term("downloaded {$bytes} bytes");

if (!class_exists('ZipArchive')) {
    cleanup($tmp);
    http_response_code(500);
    term("error: ZipArchive extension not available");
    print_finish_banner($startTs, $startStr, $tz, false);
    end_output();
    exit;
}

$zip = new ZipArchive();
if ($zip->open($zipFile) !== true) {
    cleanup($tmp);
    http_response_code(500);
    term("error: cannot open zip");
    print_finish_banner($startTs, $startStr, $tz, false);
    end_output();
    exit;
}
if (!$zip->extractTo($tmp)) {
    $zip->close();
    cleanup($tmp);
    http_response_code(500);
    term("error: extract failed");
    print_finish_banner($startTs, $startStr, $tz, false);
    end_output();
    exit;
}
$zip->close();
term("extracted archive");

$src = null;
$subdir = $config['subdir'] !== '' ? $config['subdir'] : '.';
foreach (scandir($tmp) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $candidate = $subdir === '.' ? $tmp . '/' . $entry : $tmp . '/' . $entry . '/' . $subdir;
    if (is_dir($candidate)) { $src = $candidate; break; }
}
if ($src === null) {
    cleanup($tmp);
    http_response_code(500);
    term("error: '{$subdir}' not found inside the archive");
    print_finish_banner($startTs, $startStr, $tz, false);
    end_output();
    exit;
}

$keep = array_values(array_unique(array_merge(ALWAYS_KEEP, $config['keep_files'])));
term("copying into {$target} (preserving: " . implode(', ', $keep) . ")");
$copied = 0;
copyTree($src, $target, $keep, $copied);

term("copied {$copied} files");

// Purge runs only after a successful copy, so a failed download never deletes anything.
$deleted = null;
if ($config['purge']) {
    term("purge: on — deleting everything that is no longer in the repository (irreversible)");
    $deletedFiles = 0;
    $deletedDirs  = 0;
    purgeExtra($src, $target, $keep, $deletedFiles, $deletedDirs);
    $deleted = $deletedFiles;
    term("deleted {$deletedFiles} files, {$deletedDirs} directories");
} else {
    term("purge: off — files removed from the repository stay on the server");
}

cleanup($tmp); // only now — purge compares the target against $src

// Remember what is live and append to the deploy log: that is what the next
// ?check=1 compares against, and what a rollback can later reach back to.
state_record_deploy([
    'sha'      => $track['sha'],
    'ref'      => $track['ref'],
    'mode'     => $isRollback ? (string)($track['from']['mode'] ?? 'rollback') : $track['mode'],
    'label'    => $isRollback ? (string)($track['from']['label'] ?? $track['label']) : $track['label'],
    'pr'       => $track['pr'],
    'at'       => date('Y-m-d H:i:s'),
    'at_utc'   => gmdate('c'),
    'status'   => 'ok',
    'files'    => $copied,
    'deleted'  => $deleted,
    'rollback' => $isRollback,
], $isRollback);

if ($track['sha'] !== '') term("deployed commit " . short_sha($track['sha']) . " recorded in " . STATE_FILE);
if ($isRollback) {
    term("pinned: automatic deploys are paused until the tracked head is deployed from pull.php");
} elseif (!empty($state['pinned'])) {
    term("unpinned: back on the tracked ref, automatic deploys resume");
}

print_finish_banner($startTs, $startStr, $tz, true, $copied, $deleted, 'DONE', $track);
end_output();
exit;

// ---------- output helpers ----------

function start_output(bool $plain): void {
    global $TERM_PLAIN;
    $TERM_PLAIN = $plain;

    @header('Cache-Control: no-cache, no-store, must-revalidate');
    @header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) @ob_end_flush();
    @ob_implicit_flush(true);

    if ($plain) {
        @header('Content-Type: text/plain; charset=utf-8');
        return;
    }

    @header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">";
    echo "<title>pull.php</title>";
    echo terminal_css();
    echo "</head><body><div class=\"term\"><div class=\"bar\">";
    echo "<span class=\"dot r\"></span><span class=\"dot y\"></span><span class=\"dot g\"></span>";
    echo "<span class=\"title\">pull.php — " . htmlspecialchars(gethostname() ?: 'localhost', ENT_QUOTES, 'UTF-8') . "</span>";
    echo "<a class=\"home\" href=\"?watch=1\" title=\"Live tracking screen\">◉ tracking</a>";
    echo "<a class=\"home\" href=\"?history=1\" title=\"Deployed versions and rollback\">⟲ versions</a>";
    echo "<a class=\"home\" href=\"/\" title=\"Go to site root\">⌂ site root</a></div>";
    echo "<pre id=\"out\" class=\"out\"></pre></div>";
    echo terminal_js();
    echo str_repeat(' ', 1024); // flush kicker for some buffering proxies
    flush();
}

function end_output(): void {
    global $TERM_PLAIN;
    if (!$TERM_PLAIN) {
        echo "<script>window.__pullDone=true;</script></body></html>";
    }
    flush();
}

function term(string $msg): void {
    global $TERM_PLAIN;
    if ($TERM_PLAIN) {
        echo $msg . "\n";
        flush();
        return;
    }
    $safe = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    echo "<i class=\"line\" data-t=\"" . $safe . "\"></i>";
    echo str_repeat(' ', 256);
    flush();
}

function print_finish_banner(
    float $startTs, string $startStr, string $tz, bool $ok,
    int $copied = 0, ?int $deleted = null, string $label = '', ?array $track = null
): void {
    $endTs   = microtime(true);
    $endStr  = date('Y-m-d H:i:s');
    $dur     = $endTs - $startTs;
    $status  = $label !== '' ? $label : ($ok ? 'DONE' : 'FAILED');
    term("");
    term("================================================");
    term("  STATUS:   {$status}");
    term("  START:    {$startStr} ({$tz})");
    term("  END:      {$endStr} ({$tz})");
    term("  DURATION: " . number_format($dur, 2) . " seconds");
    if ($track !== null && $track['sha'] !== '') {
        term("  COMMIT:   " . short_sha($track['sha']) . "  (" . $track['ref'] . ")");
    }
    if ($ok) {
        term("  FILES:    {$copied}");
        if ($deleted !== null) {
            term("  DELETED:  {$deleted}");
        }
    }
    term("================================================");
}

// ---------- config loader ----------

function load_config(string $path): array {
    $raw = require $path;
    if (!is_array($raw)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit("pull-config.php must return an array\n");
    }
    return [
        'repo'       => (string)($raw['repo']       ?? ''),
        'branch'     => (string)($raw['branch']     ?? 'main'),
        'subdir'     => (string)($raw['subdir']     ?? '.'),
        'gh_token'   => (string)($raw['gh_token']   ?? ''),
        'keep_files' => is_array($raw['keep_files'] ?? null) ? array_values($raw['keep_files']) : [],
        'timezone'   => (string)($raw['timezone']   ?? 'UTC'),
        // Both default to "off" when the key is missing, so a config written by an
        // older version keeps working and never starts deleting files by surprise.
        'password_hash' => (string)($raw['password_hash'] ?? ''),
        'purge'         => (bool)($raw['purge'] ?? false),
        // Tracking. A config written before tracking existed has no 'source' key,
        // so it keeps behaving exactly as it did: plain branch deploys, no auto-pull.
        'source'        => ((string)($raw['source'] ?? '') === 'pr' || (int)($raw['pr_number'] ?? 0) > 0) ? 'pr' : 'branch',
        'pr_number'     => max(0, (int)($raw['pr_number'] ?? 0)),
        'auto_pull'     => (bool)($raw['auto_pull'] ?? false),
        'auto_interval' => max(AUTO_INTERVAL_MIN, (int)($raw['auto_interval'] ?? AUTO_INTERVAL_DEF)),
    ];
}

// ---------- tracking: what is on GitHub vs. what is deployed ----------

function short_sha(string $sha): string {
    return $sha === '' ? '-' : substr($sha, 0, 7);
}

function state_path(): string {
    return __DIR__ . '/' . STATE_FILE;
}

// The deploy log: which commit is currently live. Missing or unreadable = nothing deployed yet.
function state_read(): array {
    if (!is_file(state_path())) return [];
    $raw = json_decode((string)@file_get_contents(state_path()), true);
    return is_array($raw) ? $raw : [];
}

function state_write(array $state): void {
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return;
    @file_put_contents(state_path(), $json . "\n", LOCK_EX);
    @chmod(state_path(), 0600); // it lives in the web root — keep PR titles out of public reach
}

// Appends a deploy to the log and marks what is live. $pinned is set by a rollback:
// it tells the automatic paths to stand down so they do not undo it.
function state_record_deploy(array $entry, bool $pinned): void {
    $state   = state_read();
    $history = isset($state['history']) && is_array($state['history']) ? $state['history'] : [];

    // Deploying the same commit twice in a row refreshes the top entry instead of
    // filling the log with repeats of one version.
    if (!empty($history) && (string)($history[0]['sha'] ?? '') === (string)$entry['sha'] && empty($entry['rollback'])) {
        $history[0] = $entry;
    } else {
        array_unshift($history, $entry);
    }

    $state            = $entry;
    $state['pinned']  = $pinned;
    $state['history'] = array_slice($history, 0, HISTORY_MAX);
    state_write($state);
}

// The log is a deploy journal, but what a rollback picks from is a list of versions:
// one entry per commit, carrying the last date it was deployed.
function history_versions(array $state): array {
    $seen = [];
    $out  = [];
    foreach (($state['history'] ?? []) as $entry) {
        if (!is_array($entry)) continue;
        $sha = (string)($entry['sha'] ?? '');
        if ($sha === '' || isset($seen[$sha])) continue;
        $seen[$sha] = true;
        $out[] = $entry;
    }
    return $out;
}

// "3 d ago" next to an exact timestamp — the age is what tells you at a glance
// how old the version you are about to roll back to actually is.
function human_age(string $iso): string {
    $t = strtotime($iso);
    if ($t === false) return '';
    $d = time() - $t;
    if ($d < 90)     return 'just now';
    if ($d < 5400)   return (int)floor($d / 60) . ' min ago';
    if ($d < 172800) return (int)floor($d / 3600) . ' h ago';
    return (int)floor($d / 86400) . ' d ago';
}

// Looks a rollback target up in the deploy log. Only versions that actually stood
// here can be reached, so a stray parameter cannot deploy an arbitrary commit.
function resolve_rollback(string $wanted, array $state): array {
    $out = [
        'mode' => 'rollback', 'ref' => '', 'sha' => '', 'label' => '',
        'error' => '', 'fatal' => false, 'pr' => null, 'commit' => null, 'from' => null,
    ];
    $wanted = strtolower((string)preg_replace('~[^0-9a-fA-F]~', '', $wanted));
    $out['label'] = $wanted === '' ? 'requested version is empty' : "requested version {$wanted}";
    if (strlen($wanted) < 7) {
        $out['error'] = 'a rollback needs at least the first 7 characters of the commit id';
        $out['fatal'] = true;
        return $out;
    }

    foreach (($state['history'] ?? []) as $entry) {
        if (!is_array($entry)) continue;
        $sha = strtolower((string)($entry['sha'] ?? ''));
        if ($sha !== '' && strpos($sha, $wanted) === 0) {
            $out['sha']   = (string)$entry['sha'];
            $out['ref']   = (string)($entry['ref'] ?? '');
            $out['pr']    = $entry['pr'] ?? null;
            $out['from']  = $entry;
            $out['label'] = "back to " . short_sha($out['sha']) . " — "
                          . (string)($entry['label'] ?? $entry['ref'] ?? 'unknown source')
                          . ", deployed " . (string)($entry['at'] ?? 'at an unknown time');
            return $out;
        }
    }

    $out['error'] = "{$wanted} is not in the deploy log — only versions listed at "
                  . "pull.php?history=1 can be rolled back to";
    $out['fatal'] = true;
    return $out;
}

// Minimal GitHub REST client. Returns [decoded body, ''] or [null, 'reason'].
function gh_api(string $path, string $token): array {
    $url     = 'https://api.github.com' . $path;
    $headers = [
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: pull.php',
    ];
    if ($token !== '') $headers[] = "Authorization: Bearer {$token}";

    $body = '';
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body  = (string)curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0) return [null, "curl errno {$errno}: {$err}"];
    } else {
        if (!ini_get('allow_url_fopen')) return [null, 'no curl and allow_url_fopen=Off'];
        $hdr = '';
        foreach ($headers as $h) $hdr .= $h . "\r\n";
        $ctx  = stream_context_create(['http' => [
            'header'         => $hdr,
            'timeout'        => 20,
            'ignore_errors'  => true,
            'follow_location'=> 1,
        ]]);
        $body = (string)@file_get_contents($url, false, $ctx);
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) $code = (int)$m[1];
        }
    }

    if ($code === 401) return [null, 'http 401 — the token is invalid, expired or revoked'];
    if ($code === 403) return [null, 'http 403 — rate limited, or the token is not allowed here'];
    if ($code === 404) return [null, 'http 404 — no such repo/ref, or the token cannot see it'];
    if ($code >= 400)  return [null, "http {$code}"];

    $data = json_decode($body, true);
    if (!is_array($data)) return [null, 'unreadable answer from api.github.com'];
    return [$data, ''];
}

// Works out the exact commit to deploy. 'fatal' means there is no safe fallback
// and the caller must abort; a soft 'error' means "deploy the branch tip blindly".
function resolve_target(array $config, string $token): array {
    $out = [
        'mode' => $config['source'], 'ref' => '', 'sha' => '', 'label' => '',
        'error' => '', 'fatal' => false, 'pr' => null, 'commit' => null,
    ];

    if ($config['source'] === 'pr') {
        $n = $config['pr_number'];
        $out['ref']   = "refs/pull/{$n}/head";
        $out['label'] = "pull request #{$n} of {$config['repo']}";

        [$pr, $err] = gh_api("/repos/{$config['repo']}/pulls/{$n}", $token);
        if ($pr === null) {
            $out['error'] = "cannot read pull request #{$n}: {$err}";
            $out['fatal'] = true; // deploying the branch instead would ship the wrong code
            return $out;
        }
        $out['pr'] = [
            'number'  => $n,
            'title'   => (string)($pr['title'] ?? ''),
            'state'   => !empty($pr['merged']) ? 'merged' : (string)($pr['state'] ?? ''),
            'draft'   => (bool)($pr['draft'] ?? false),
            'author'  => (string)($pr['user']['login'] ?? ''),
            'head'    => (string)($pr['head']['ref'] ?? ''),
            'base'    => (string)($pr['base']['ref'] ?? ''),
            'updated' => (string)($pr['updated_at'] ?? ''),
            'url'     => (string)($pr['html_url'] ?? ''),
        ];
        $out['sha']   = (string)($pr['head']['sha'] ?? '');
        $out['label'] = "PR #{$n} \"{$out['pr']['title']}\" [{$out['pr']['state']}"
                      . ($out['pr']['draft'] ? ', draft' : '') . "] "
                      . "{$out['pr']['head']} -> {$out['pr']['base']} by {$out['pr']['author']}";
        if ($out['sha'] === '') {
            $out['error'] = "pull request #{$n} has no head commit (deleted branch?)";
            $out['fatal'] = true;
        }
        return $out;
    }

    $out['ref']   = "refs/heads/{$config['branch']}";
    $out['label'] = "branch {$config['branch']} of {$config['repo']}";

    [$commit, $err] = gh_api("/repos/{$config['repo']}/commits/{$config['branch']}", $token);
    if ($commit === null) {
        // Not fatal: the branch zip is downloadable without the API, we just lose
        // change detection, so ?check=1 will pull every time instead of skipping.
        $out['error'] = "cannot read the head of {$config['branch']}: {$err} — "
                      . "deploying the branch tip without change tracking";
        return $out;
    }
    $out['sha']    = (string)($commit['sha'] ?? '');
    $message       = (string)($commit['commit']['message'] ?? '');
    $out['commit'] = [
        'message' => trim(explode("\n", $message)[0]),
        'author'  => (string)($commit['author']['login'] ?? ($commit['commit']['author']['name'] ?? '')),
        'date'    => (string)($commit['commit']['author']['date'] ?? ''),
        'url'     => (string)($commit['html_url'] ?? ''),
    ];
    return $out;
}

// The prefilled "create a fine-grained token" URL. Everything except the repository
// picker can be set from the query string, so the user only ticks the repo itself.
function pat_url(string $repo, bool $needsPullRequests): string {
    $owner  = trim(explode('/', $repo)[0] ?? '');
    $name   = substr('pull.php deploy ' . $repo, 0, 40); // GitHub caps the name at 40 chars
    $params = [
        'name'        => $name,
        'description' => 'Read-only token for pull.php. Under "Repository access" pick '
                       . ($repo !== '' ? $repo : 'the repository to deploy') . '.',
        'expires_in'  => 'none',
        'contents'    => 'read',
        'metadata'    => 'read',
    ];
    if ($needsPullRequests) $params['pull_requests'] = 'read';
    if ($owner !== '')      $params['target_name']   = $owner;
    return PAT_NEW_URL . '?' . http_build_query($params);
}

// Shown wherever a download or an API call comes back 401/404.
function token_hint_lines(array $config): array {
    $needsPr = $config['source'] === 'pr';
    return array_filter([
        "hint: 401/404 usually means the token cannot see this repository.",
        "  fine-grained PAT — needs 'Repository access' to include {$config['repo']},",
        "    plus Contents: Read" . ($needsPr ? " and Pull requests: Read (for PR tracking)" : ""),
        "  ready-made link (permissions preselected):",
        "    " . pat_url($config['repo'], $needsPr),
        "  classic PAT — needs the full 'repo' scope, not just 'public_repo'",
        "  also check the token is neither expired nor revoked",
    ]);
}

// Compact deploy log for JSON consumers: enough to show it, not the whole record.
function history_summary(array $state): array {
    $out = [];
    foreach (history_versions($state) as $entry) {
        $out[] = [
            'sha'      => (string)($entry['sha'] ?? ''),
            'short'    => short_sha((string)($entry['sha'] ?? '')),
            'at'       => (string)($entry['at'] ?? ''),
            'age'      => human_age((string)($entry['at_utc'] ?? '')),
            'label'    => (string)($entry['label'] ?? ''),
            'ref'      => (string)($entry['ref'] ?? ''),
            'rollback' => !empty($entry['rollback']),
        ];
    }
    return $out;
}

// JSON snapshot for the tracking screen and for anything else that wants to poll.
function emit_status_json(array $config, string $token): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $track = resolve_target($config, $token);
    $state = state_read();
    $live  = (string)($state['sha'] ?? '');

    echo json_encode([
        'ok'            => $track['error'] === '',
        'error'         => $track['error'],
        'repo'          => $config['repo'],
        'mode'          => $track['mode'],
        'ref'           => $track['ref'],
        'label'         => $track['label'],
        'sha'           => $track['sha'],
        'short'         => short_sha($track['sha']),
        'commit'        => $track['commit'],
        'pr'            => $track['pr'],
        'deployed'      => $live,
        'deployed_short'=> short_sha($live),
        'deployed_at'   => (string)($state['at'] ?? ''),
        'deployed_files'=> $state['files'] ?? null,
        // Unknown head = cannot compare, so report "no change" rather than looping on pulls.
        'changed'       => $track['sha'] !== '' && $track['sha'] !== $live,
        'auto'          => $config['auto_pull'],
        'interval'      => $config['auto_interval'],
        'purge'         => $config['purge'],
        // A rollback pins the directory: the tracked head then sits ahead on purpose.
        'pinned'        => !empty($state['pinned']),
        'pinned_at'     => (string)($state['at'] ?? ''),
        'history'       => history_summary($state),
        'now'           => date('Y-m-d H:i:s'),
        'tz'            => date_default_timezone_get(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// ---------- deploy log / rollback screen ----------

// Every version that stood in this directory, newest first, with the date it was
// deployed and a button that puts it back.
function render_history_page(array $config): void {
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $state   = state_read();
    $live    = (string)($state['sha'] ?? '');
    $pinned  = !empty($state['pinned']);
    $history = history_versions($state);
    // Post to the bare script name, never back to ?history=1 — and keep working
    // when the file has been renamed to something unguessable.
    $self    = $h(basename((string)($_SERVER['SCRIPT_NAME'] ?? 'pull.php')));

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">";
    echo "<meta name=\"robots\" content=\"noindex,nofollow\">";
    echo "<title>pull.php — versions</title>";
    echo terminal_css();
    echo "</head><body><div class=\"term\"><div class=\"bar\">";
    echo "<span class=\"dot r\"></span><span class=\"dot y\"></span><span class=\"dot g\"></span>";
    echo "<span class=\"title\">pull.php — versions of " . $h($config['repo']) . "</span>";
    echo "<a class=\"home\" href=\"?watch=1\" title=\"Live tracking screen\">◉ tracking</a>";
    echo "<a class=\"home\" href=\"/\" title=\"Go to site root\">⌂ site root</a></div>";

    echo "<div class=\"panel\">";
    echo "<div class=\"row\"><span class=\"k\">live now</span><span class=\"v" . ($live === '' ? ' is-warn' : '') . "\">"
        . ($live === ''
            ? "nothing deployed from here yet"
            : $h(short_sha($live)) . " <span class=\"mute\">— " . $h((string)($state['label'] ?? '')) . "</span>")
        . "</span></div>";
    if ($live !== '') {
        echo "<div class=\"row\"><span class=\"k\">deployed</span><span class=\"v\">"
            . $h((string)($state['at'] ?? '')) . " <span class=\"mute\">" . $h(human_age((string)($state['at_utc'] ?? ''))) . "</span></span></div>";
    }
    echo "<div class=\"row\"><span class=\"k\">auto-deploy</span><span class=\"v" . ($pinned ? ' is-warn' : '') . "\">"
        . ($pinned
            ? "paused — this directory is pinned to a rolled-back version"
            : ($config['auto_pull'] ? "follows the tracked ref" : "off"))
        . "</span></div>";
    echo "</div>";

    echo "<div class=\"ctl\">";
    echo "<a class=\"btn\" href=\"{$self}\">$ deploy_tracked_head</a>";
    echo "<span class=\"mute\">" . ($pinned
        ? "deploying the tracked head lifts the pin and lets automatic deploys resume"
        : "deploys the head of the tracked ref, as usual") . "</span>";
    echo "</div>";

    if ($history === []) {
        echo "<pre class=\"out\">$ no deploys recorded yet\n"
            . "<span class=\"is-cmt\"># " . STATE_FILE . " fills up as deploys run; a rollback can reach\n"
            . "# back through the last " . HISTORY_MAX . " of them.</span></pre>";
        echo "</div></body></html>";
        return;
    }

    echo "<div class=\"vlist\">";
    foreach ($history as $entry) {
        $sha   = (string)($entry['sha'] ?? '');
        $isNow = $sha !== '' && $sha === $live;
        echo "<div class=\"ventry" . ($isNow ? ' live' : '') . "\">";
        echo "<span class=\"when\">" . $h((string)($entry['at'] ?? '')) . "<span class=\"age\">"
            . $h(human_age((string)($entry['at_utc'] ?? ''))) . "</span></span>";
        echo "<span class=\"sha\">" . $h(short_sha($sha)) . "</span>";
        echo "<span class=\"what\">" . $h((string)($entry['label'] ?? $entry['ref'] ?? ''))
            . (!empty($entry['rollback']) ? " <span class=\"tag\">rollback</span>" : "")
            . "<span class=\"files\">" . (int)($entry['files'] ?? 0) . " files"
            . (isset($entry['deleted']) && $entry['deleted'] !== null ? ", " . (int)$entry['deleted'] . " deleted" : "")
            . "</span></span>";
        if ($isNow) {
            echo "<span class=\"nowtag\">live now</span>";
        } else {
            echo "<form method=\"post\" action=\"{$self}\" class=\"rb\">";
            echo "<input type=\"hidden\" name=\"rollback\" value=\"" . $h($sha) . "\">";
            echo "<button type=\"submit\" class=\"btn\" data-sha=\"" . $h(short_sha($sha))
                . "\" data-at=\"" . $h((string)($entry['at'] ?? '')) . "\">$ rollback</button>";
            echo "</form>";
        }
        echo "</div>";
    }
    echo "</div>";

    echo "<pre class=\"out\"><span class=\"is-cmt\">"
        . "# Each version is listed once, dated by its most recent deploy.\n"
        . "# A rollback re-deploys that exact commit and pins this directory to it,\n"
        . "# so scheduled and automatic deploys stand down until you deploy the\n"
        . "# tracked head again. The last " . HISTORY_MAX . " deploys are kept.</span></pre>";
    echo "</div>";
    echo history_js();
    echo "</body></html>";
}

// ---------- tracking screen ----------

// Live view of "what is on GitHub vs. what is deployed". Polls ?status=1 and,
// when auto-deploy is on, streams a pull as soon as the tracked head moves.
function render_watch_page(array $config): void {
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $cfg = json_encode([
        'interval' => $config['auto_interval'],
        'auto'     => $config['auto_pull'],
        'pinned'   => !empty(state_read()['pinned']),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">";
    echo "<meta name=\"robots\" content=\"noindex,nofollow\">";
    echo "<title>pull.php — tracking</title>";
    echo terminal_css();
    echo "</head><body><div class=\"term\"><div class=\"bar\">";
    echo "<span class=\"dot r\"></span><span class=\"dot y\"></span><span class=\"dot g\"></span>";
    echo "<span class=\"title\">pull.php — tracking " . $h($config['repo']) . "</span>";
    echo "<a class=\"home\" href=\"?history=1\" title=\"Deployed versions and rollback\">⟲ versions</a>";
    echo "<a class=\"home\" href=\"/\" title=\"Go to site root\">⌂ site root</a></div>";

    echo "<div class=\"panel\" id=\"panel\"><div class=\"mute\">connecting…</div></div>";

    echo "<div class=\"ctl\">";
    echo "<label class=\"chk\"><input id=\"auto\" type=\"checkbox\"" . ($config['auto_pull'] ? ' checked' : '') . ">";
    echo "<span class=\"t\">auto-deploy when the tracked head moves</span></label>";
    echo "<button id=\"now\" class=\"btn\" type=\"button\">$ deploy_now</button>";
    echo "<span class=\"mute\" id=\"tick\"></span>";
    echo "</div>";

    echo "<pre id=\"out\" class=\"out\"></pre></div>";
    echo "<script>window.__watch = {$cfg};</script>";
    echo watch_js();
    echo "</body></html>";
}

// ---------- download / copy ----------

function download(string $url, string $dest, array $extraHeaders = []): array {
    @unlink($dest);
    if (function_exists('curl_init')) {
        $fp = fopen($dest, 'wb');
        if (!$fp) return [0, 'cannot open destination file'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'pull.php',
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER     => $extraHeaders,
        ]);
        $ok       = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errstr   = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $errno !== 0) {
            return [0, "curl errno {$errno}: {$errstr} (http {$httpCode})"];
        }
        if ($httpCode >= 400) {
            return [0, "http {$httpCode}"];
        }
        $size = (int)@filesize($dest);
        return [$size, $size > 0 ? '' : 'empty file'];
    }
    if (!ini_get('allow_url_fopen')) {
        return [0, 'no curl and allow_url_fopen=Off'];
    }
    $hdr = "User-Agent: pull.php\r\n";
    foreach ($extraHeaders as $h) $hdr .= $h . "\r\n";
    $ctx = stream_context_create(['http' => [
        'header'          => $hdr,
        'timeout'         => 300,
        'follow_location' => 1,
    ]]);
    $err = '';
    set_error_handler(function ($_, $msg) use (&$err) { $err = $msg; return true; });
    $ok = copy($url, $dest, $ctx);
    restore_error_handler();
    if (!$ok) return [0, $err ?: 'copy() failed'];
    $size = (int)@filesize($dest);
    return [$size, $size > 0 ? '' : 'empty file'];
}

function copyTree(string $from, string $to, array $keepTopLevel, int &$copied): void {
    if (!is_dir($to) && !mkdir($to, 0755, true) && !is_dir($to)) return;
    foreach (new DirectoryIterator($from) as $f) {
        if ($f->isDot()) continue;
        $name = $f->getFilename();
        if ($keepTopLevel && in_array($name, $keepTopLevel, true)) continue;
        $s = $f->getPathname();
        $d = $to . '/' . $name;
        if ($f->isDir()) {
            copyTree($s, $d, [], $copied);
        } else {
            @copy($s, $d);
            $copied++;
        }
    }
}

// Mirror mode: delete everything in $dst that has no counterpart in $src.
// Top-level names in $keepTopLevel are skipped entirely. Symlinks are never
// followed — the link itself is removed, whatever it points at stays untouched.
function purgeExtra(string $src, string $dst, array $keepTopLevel, int &$files, int &$dirs): void {
    // A missing source would make every target file look obsolete — refuse to delete.
    if (!is_dir($src) || !is_dir($dst)) return;
    foreach (new DirectoryIterator($dst) as $f) {
        if ($f->isDot()) continue;
        $name = $f->getFilename();
        if ($keepTopLevel && in_array($name, $keepTopLevel, true)) continue;
        $d = $f->getPathname();
        $s = $src . '/' . $name;
        if ($f->isLink()) {
            if (!file_exists($s)) { @unlink($d); $files++; }
            continue;
        }
        if ($f->isDir()) {
            if (is_dir($s)) {
                purgeExtra($s, $d, [], $files, $dirs);
            } else {
                rmTree($d, $files, $dirs);
            }
        } elseif (!is_file($s)) {
            @unlink($d);
            $files++;
        }
    }
}

function rmTree(string $dir, int &$files, int &$dirs): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isDir() && !$f->isLink()) { @rmdir($f->getPathname()); $dirs++; }
        else { @unlink($f->getPathname()); $files++; }
    }
    if (@rmdir($dir)) $dirs++;
}

function cleanup(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

// ---------- password gate ----------

// Returns when the request is authorised; renders the unlock screen and exits otherwise.
// $mode is 'html' (unlock form), 'plain' (cron/curl) or 'json' (the ?status=1 poller).
function require_auth(string $hash, string $mode): void {
    if (isset($_GET['logout'])) {
        auth_cookie_clear();
        if ($mode === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['ok' => false, 'auth' => false, 'error' => 'logged out']));
        }
        if ($mode === 'plain') {
            header('Content-Type: text/plain; charset=utf-8');
            exit("logged out\n");
        }
        render_login_form(['logged out — enter the password to continue']);
        exit;
    }

    $cookie = (string)($_COOKIE[AUTH_COOKIE] ?? '');
    if ($cookie !== '' && auth_token_valid($cookie, $hash)) return;

    // Password may also arrive from a form post, a query param, or a header (cron).
    $supplied = null;
    foreach ([
        $_POST['password'] ?? null,
        $_GET['password'] ?? null,
        $_SERVER['HTTP_X_PULL_PASSWORD'] ?? null,
    ] as $candidate) {
        if (is_string($candidate) && $candidate !== '') { $supplied = $candidate; break; }
    }

    if ($supplied !== null && password_verify($supplied, $hash)) {
        auth_cookie_set($hash, isset($_POST['remember']) || isset($_GET['remember']));
        return;
    }

    if ($supplied !== null) usleep(400000); // small brake on password guessing

    http_response_code(401);
    if ($mode === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        // The tracking screen reads this and stops polling instead of hammering the gate.
        exit(json_encode(['ok' => false, 'auth' => false, 'error' => 'password required']));
    }
    if ($mode === 'plain') {
        header('Content-Type: text/plain; charset=utf-8');
        exit(($supplied !== null ? "unauthorized: wrong password\n" : "unauthorized: password required\n")
            . "hint: send it as a header, e.g.\n"
            . "  curl -s -H \"X-Pull-Password: \$PULL_PASSWORD\" \"https://host/pull.php?plain=1\"\n");
    }
    render_login_form($supplied !== null ? ['wrong password'] : []);
    exit;
}

// Token = expiry + HMAC over it, keyed by the stored password hash. Changing the
// password changes the hash, which invalidates every cookie handed out before.
function auth_token(string $hash, int $expires): string {
    return $expires . '|' . hash_hmac('sha256', 'pull-auth|' . $expires, $hash);
}

function auth_token_valid(string $token, string $hash): bool {
    $parts = explode('|', $token, 2);
    if (count($parts) !== 2) return false;
    $expires = (int)$parts[0];
    if ($expires <= time()) return false;
    return hash_equals(hash_hmac('sha256', 'pull-auth|' . $expires, $hash), $parts[1]);
}

function auth_cookie_set(string $hash, bool $remember): void {
    $ttl     = $remember ? AUTH_REMEMBER_TTL : AUTH_SESSION_TTL;
    $expires = time() + $ttl;
    setcookie(AUTH_COOKIE, auth_token($hash, $expires), [
        'expires'  => $remember ? $expires : 0, // 0 = cookie dies with the browser session
        'path'     => auth_cookie_path(),
        'secure'   => auth_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function auth_cookie_clear(): void {
    setcookie(AUTH_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => auth_cookie_path(),
        'secure'   => auth_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Scope the cookie to the directory the script lives in, not the whole host.
function auth_cookie_path(): string {
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/pull.php')));
    return ($dir === '' || $dir === '.' || $dir === '/') ? '/' : rtrim($dir, '/') . '/';
}

function auth_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function render_login_form(array $errors = []): void {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">";
    echo "<meta name=\"robots\" content=\"noindex,nofollow\">";
    echo "<title>pull.php — locked</title>";
    echo terminal_css();
    echo "</head><body><div class=\"term\"><div class=\"bar\">";
    echo "<span class=\"dot r\"></span><span class=\"dot y\"></span><span class=\"dot g\"></span>";
    echo "<span class=\"title\">pull.php — locked</span>";
    echo "<a class=\"home\" href=\"/\" title=\"Go to site root\">⌂ site root</a></div>";
    echo "<pre class=\"out\">$ pull.php\n<span class=\"is-cmt\"># This deploy is password protected.</span>\n";
    foreach ($errors as $e) {
        echo "<span class=\"is-err\">! " . htmlspecialchars($e, ENT_QUOTES, 'UTF-8') . "</span>\n";
    }
    echo "</pre>";
    echo "<form method=\"post\" class=\"form\">";
    echo "<div class=\"fld\">";
    echo "<label for=\"f_password\"><span class=\"prompt\">&gt;</span> Password</label>";
    echo "<input id=\"f_password\" name=\"password\" type=\"password\" autocomplete=\"current-password\" autofocus>";
    echo "</div>";
    echo "<label class=\"chk\"><input type=\"checkbox\" name=\"remember\" value=\"1\" checked>";
    echo "<span class=\"t\">remember me on this browser"
        . "<span class=\"note\">Stores a signed cookie for 30 days so you are not asked again. "
        . "Uncheck on a shared computer — then the cookie is dropped when the browser closes. "
        . "Open <code>pull.php?logout=1</code> to forget it.</span></span></label>";
    echo "<button type=\"submit\" class=\"btn\">$ unlock</button>";
    echo "</form>";
    echo "</div></body></html>";
}

// ---------- first-run setup ----------

function handle_setup_post(string $configPath): void {
    $repo     = trim((string)($_POST['repo']     ?? ''));
    $branch   = trim((string)($_POST['branch']   ?? 'main'));
    $subdir   = trim((string)($_POST['subdir']   ?? '.'));
    $ghToken  = (string)($_POST['gh_token'] ?? '');
    $timezone = trim((string)($_POST['timezone'] ?? 'UTC'));
    $keepRaw  = (string)($_POST['keep_files'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $purge    = isset($_POST['purge']);
    $prRaw    = trim((string)($_POST['pr_number'] ?? ''));
    $autoPull = isset($_POST['auto_pull']);
    $intRaw   = trim((string)($_POST['auto_interval'] ?? ''));

    $errors = [];
    if ($password !== '' && strlen($password) < 6) {
        $errors[] = 'password must be at least 6 characters (or empty for no password)';
    }
    $prNumber = (int)$prRaw;
    if ($prRaw !== '' && !preg_match('~^[0-9]+$~', $prRaw)) {
        $errors[] = 'pull request must be a number (e.g. 42), or empty to track the branch';
    } elseif ($prRaw !== '' && $prNumber < 1) {
        $errors[] = 'pull request number must be 1 or greater';
    }
    // An empty PR field means branch tracking — that is the pre-existing behaviour.
    $source = $prNumber > 0 ? 'pr' : 'branch';
    // GITHUB_TOKEN from the environment counts too — it wins over the config value.
    if ($source === 'pr' && $ghToken === '' && (string)(getenv('GITHUB_TOKEN') ?: '') === '') {
        $errors[] = 'pull request tracking needs a token with Pull requests: Read — use the link above to create one';
    }
    $interval = $intRaw === '' ? AUTO_INTERVAL_DEF : (int)$intRaw;
    if ($interval < AUTO_INTERVAL_MIN) {
        $errors[] = 'check interval must be at least ' . AUTO_INTERVAL_MIN . ' seconds';
    }
    if ($repo === '' || !preg_match('~^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$~', $repo)) {
        $errors[] = 'repo must look like "owner/name"';
    }
    if ($branch === '') $branch = 'main';
    if ($subdir === '') $subdir = '.';
    if ($timezone === '' || @timezone_open($timezone) === false) {
        $errors[] = 'invalid timezone (e.g. "Europe/Moscow", "UTC")';
    }

    $keepFiles = [];
    foreach (preg_split('/[\s,]+/', $keepRaw) ?: [] as $name) {
        $name = trim($name);
        if ($name !== '') $keepFiles[] = $name;
    }
    foreach (ALWAYS_KEEP as $must) {
        if (!in_array($must, $keepFiles, true)) $keepFiles[] = $must;
    }

    if ($errors) {
        render_setup_form([
            'repo' => $repo, 'branch' => $branch, 'subdir' => $subdir,
            'gh_token' => $ghToken, 'purge' => $purge,
            'timezone' => $timezone, 'keep_files' => implode(', ', $keepFiles),
            'pr_number' => $prRaw, 'auto_pull' => $autoPull, 'auto_interval' => $interval,
        ], $errors);
        return;
    }

    $config = [
        'repo'          => $repo,
        'branch'        => $branch,
        'subdir'        => $subdir,
        'gh_token'      => $ghToken,
        'keep_files'    => $keepFiles,
        'timezone'      => $timezone,
        'password_hash' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : '',
        'purge'         => $purge,
        'source'        => $source,
        'pr_number'     => $prNumber,
        'auto_pull'     => $autoPull,
        'auto_interval' => $interval,
    ];

    $body = "<?php\n"
          . "// pull.php config — generated " . gmdate('c') . "\n"
          . "// Edit values below or delete this file to re-run the setup form.\n\n"
          . "return " . var_export($config, true) . ";\n";

    if (@file_put_contents($configPath, $body, LOCK_EX) === false) {
        render_setup_form([
            'repo' => $repo, 'branch' => $branch, 'subdir' => $subdir,
            'gh_token' => $ghToken, 'purge' => $purge,
            'timezone' => $timezone, 'keep_files' => implode(', ', $keepFiles),
            'pr_number' => $prRaw, 'auto_pull' => $autoPull, 'auto_interval' => $interval,
        ], ['cannot write pull-config.php — check directory permissions']);
        return;
    }

    @chmod($configPath, 0600);
    render_setup_done($config);
}

function render_setup_form(array $values = [], array $errors = []): void {
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $repo     = $h($values['repo']     ?? '');
    $branch   = $h($values['branch']   ?? 'main');
    $subdir   = $h($values['subdir']   ?? '.');
    $ghToken  = $h($values['gh_token'] ?? '');
    $timezone = $h($values['timezone'] ?? 'UTC');
    $keep     = $h($values['keep_files'] ?? 'pull.php, pull-config.php');
    $prNumber = $h($values['pr_number'] ?? '');
    $interval = $h($values['auto_interval'] ?? AUTO_INTERVAL_DEF);
    // Password is never echoed back into the form; purge defaults to on for new installs.
    $purge    = (bool)($values['purge'] ?? true);
    $autoPull = (bool)($values['auto_pull'] ?? true);

    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">";
    echo "<title>pull.php — first-run setup</title>";
    echo terminal_css();
    echo "</head><body>";
    echo "<div class=\"term\"><div class=\"bar\">";
    echo "<span class=\"dot r\"></span><span class=\"dot y\"></span><span class=\"dot g\"></span>";
    echo "<span class=\"title\">pull.php — first-run setup</span>";
    echo "<a class=\"home\" href=\"/\" title=\"Go to site root\">⌂ site root</a></div>";
    echo "<div id=\"intro\" class=\"out\"></div>";

    // The intro text typed out by JS. data-intro carries the script.
    $intro = [
        ['p',   '$ pull.php — first-run setup'],
        ['',    ''],
        ['c',   '# No pull-config.php found in this directory.'],
        ['c',   '# Fill the form below to generate it. Each field is explained.'],
        ['c',   '# Tip: click anywhere to skip the typing animation.'],
        ['',    ''],
    ];
    if ($errors) {
        $intro[] = ['e', '! Please fix the following:'];
        foreach ($errors as $e) $intro[] = ['e', '!   - ' . $e];
        $intro[] = ['', ''];
    }
    // htmlspecialchars would be wrong here: a <script> body is raw text, so &quot;
    // never gets decoded and the whole block dies with a syntax error. The JSON_HEX_*
    // flags escape the same characters in a way that stays valid JavaScript.
    $introJson = json_encode($intro, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    echo "<form id=\"setup\" method=\"post\" class=\"form hidden\">";
    echo "<input type=\"hidden\" name=\"_setup\" value=\"1\">";

    setup_field(
        'repo', 'GitHub repository',
        $repo, 'owner/name', false,
        [
            'Format: <code>owner/name</code> (e.g. <code>torvalds/linux</code>).',
            'Where to get it: open the repo on github.com — the URL is <code>github.com/&lt;owner&gt;/&lt;name&gt;</code>. Copy the <code>owner/name</code> part.',
        ]
    );

    setup_field(
        'branch', 'Branch',
        $branch, 'main', false,
        [
            'Branch to pull from. Usually <code>main</code> (modern repos) or <code>master</code> (older repos).',
            'Where to get it: on the repo page on github.com, the branch picker (top-left of the file list) shows the default.',
        ]
    );

    setup_field(
        'pr_number', 'Pull request to track',
        $prNumber, 'e.g. 42 — leave empty to track the branch', false,
        [
            'Leave empty to deploy the branch above. Enter a number to deploy the <strong>head commit of that pull request</strong> instead, so the change can be looked at on a real server before it is merged.',
            'Where to get it: the number in the pull request URL — <code>github.com/&lt;owner&gt;/&lt;name&gt;/pull/<strong>42</strong></code>.',
            'The head of a pull request is read through the GitHub API, so the token below needs <strong>Pull requests: Read</strong> on top of Contents: Read. The button on the token field adds it for you as soon as this field is filled in.',
            'Merging the pull request freezes its head — from then on it keeps deploying the same commit. Clear <code>pr_number</code> in <code>pull-config.php</code> to go back to the branch.',
        ]
    );

    setup_field(
        'subdir', 'Subdirectory in the repo',
        $subdir, '.', false,
        [
            'Folder inside the repo whose contents will replace the directory <code>pull.php</code> lives in.',
            'Use <code>.</code> to mirror the entire repo root. Use a path like <code>website/public</code> to mirror just that folder.',
        ]
    );

    setup_field(
        'gh_token', 'GitHub Personal Access Token',
        $ghToken, 'github_pat_... or ghp_...', true,
        [
            '<strong>Required for private repos and for pull request tracking.</strong> A public repo deployed by branch needs no token.',
            '<a class="patbtn" id="patbtn" target="_blank" rel="noopener" href="' . htmlspecialchars(pat_url('', false), ENT_QUOTES, 'UTF-8') . '">$ create_fine_grained_token &#8599;</a>',
            '<span class="perms" id="patperms"></span>',
            'The link opens GitHub&rsquo;s token form with the name, description, lifetime and permissions already filled in — it follows the repository and pull request fields above, so fill those in first.',
            'One thing the link cannot preselect: on that page choose <strong>Repository access &rarr; Only select repositories</strong> and tick your repository. Then press <em>Generate token</em> and paste the <code>github_pat_...</code> string here — GitHub shows it only once.',
            'Prefer a classic token? <a href="' . PAT_CLASSIC_URL . '" target="_blank" rel="noopener">this link</a> preselects the full <code>repo</code> scope (<code>public_repo</code> alone is not enough).',
        ]
    );

    setup_field(
        'timezone', 'Timezone',
        $timezone, 'UTC', false,
        [
            'IANA timezone name. Used for the timestamps in the run banner.',
            'Common values: <code>UTC</code>, <code>Europe/Moscow</code>, <code>Europe/London</code>, <code>America/New_York</code>, <code>Asia/Tokyo</code>.',
            'Full list: <code>php.net/manual/en/timezones.php</code>.',
        ]
    );

    setup_field(
        'keep_files', 'Files to preserve',
        $keep, 'pull.php, pull-config.php', false,
        [
            'Comma- or space-separated top-level filenames in this directory that must NOT be overwritten on pull.',
            '<code>pull.php</code> and <code>pull-config.php</code> are always preserved automatically — list any other names here.',
            'Examples: <code>.htaccess</code>, <code>index.html</code>, uploaded media folder names.',
        ],
        true
    );

    setup_field(
        'password', 'Password to run the deploy',
        '', 'leave empty for no password', true,
        [
            'Asked before every pull. Stored hashed in <code>pull-config.php</code> — the password itself is never written to disk.',
            'The unlock screen offers <strong>&laquo;remember me&raquo;</strong>: a signed cookie valid 30 days, so you are not asked again on this browser. Unchecked, it lasts only until the browser closes.',
            'From cron, send it as a header instead: <code>curl -H "X-Pull-Password: ..." "https://host/pull.php?plain=1"</code>.',
            'Leave empty to keep the deploy open to anyone who knows the URL.',
        ]
    );

    setup_check(
        'auto_pull', 'Track the ref and deploy on its own when it moves',
        $autoPull,
        [
            'Adds the tracking screen at <code>pull.php?watch=1</code>: it keeps comparing the deployed commit with the head on GitHub and starts a deploy the moment a new commit shows up. It runs for as long as that page stays open.',
            'For an unattended server use cron instead: <code>curl -s "https://host/pull.php?check=1&amp;plain=1"</code>. With <code>check=1</code> nothing is downloaded while the commit has not changed.',
            'The commit that is currently live is written to <code>pull-state.json</code> next to this script — that file is what makes &laquo;deploy only what changed&raquo; possible, and it is never overwritten by a pull.',
            'Off: nothing happens on its own, the tracking screen still works but only deploys when you press the button.',
        ]
    );

    setup_field(
        'auto_interval', 'Check interval, seconds',
        $interval, '60', false,
        [
            'How often the tracking screen asks GitHub for the current head commit. Minimum ' . AUTO_INTERVAL_MIN . ' seconds.',
            'One check is one API request. A token is allowed 5000 requests per hour, so 60 seconds (60 per hour) leaves plenty of room.',
        ]
    );

    setup_check(
        'purge', 'Delete files that are gone from the repository',
        $purge,
        [
            'Runs after each copy and makes this directory an exact mirror of the repo folder: anything not in the repo is deleted.',
            'Off: files deleted from the repo stay on the server forever and pile up.',
            'Files listed above under &laquo;Files to preserve&raquo; are never touched. Deletion is skipped entirely if the download or unpack fails.',
        ],
        'Irreversible: deleted files go straight from disk, not to a recycle bin. Anything created on the server and absent from the repo — uploads, caches, logs — is lost unless it is in the preserve list.'
    );

    echo "<button type=\"submit\" class=\"btn\">$ save_config</button>";
    echo "</form>";
    echo "</div>"; // .term
    echo "<script>window.__intro = {$introJson};</script>";
    echo terminal_js(true);
    echo "</body></html>";
}

function setup_field(string $name, string $label, string $value, string $placeholder, bool $password, array $help, bool $textarea = false): void {
    echo "<div class=\"fld\">";
    echo "<label for=\"f_{$name}\"><span class=\"prompt\">&gt;</span> {$label}</label>";
    echo "<div class=\"help\">";
    foreach ($help as $h) echo "<div>" . $h . "</div>";
    echo "</div>";
    if ($textarea) {
        echo "<textarea id=\"f_{$name}\" name=\"{$name}\" rows=\"2\" placeholder=\"{$placeholder}\">{$value}</textarea>";
    } else {
        $type = $password ? 'password' : 'text';
        $auto = $password ? ' autocomplete="new-password"' : '';
        echo "<input id=\"f_{$name}\" name=\"{$name}\" type=\"{$type}\" placeholder=\"{$placeholder}\" value=\"{$value}\"{$auto}>";
    }
    echo "</div>";
}

function setup_check(string $name, string $label, bool $checked, array $help, string $warning = ''): void {
    $on = $checked ? ' checked' : '';
    echo "<div class=\"fld\">";
    echo "<label class=\"chk\" for=\"f_{$name}\">";
    echo "<input id=\"f_{$name}\" name=\"{$name}\" type=\"checkbox\" value=\"1\"{$on}>";
    echo "<span class=\"t\">{$label}</span></label>";
    echo "<div class=\"help\">";
    foreach ($help as $h) echo "<div>" . $h . "</div>";
    if ($warning !== '') echo "<div class=\"warn\">&#9888; " . $warning . "</div>";
    echo "</div>";
    echo "</div>";
}

function render_setup_done(array $config): void {
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">";
    echo "<title>pull.php — setup complete</title>";
    echo terminal_css();
    echo "</head><body><div class=\"term\"><div class=\"bar\">";
    echo "<span class=\"dot r\"></span><span class=\"dot y\"></span><span class=\"dot g\"></span>";
    echo "<span class=\"title\">pull.php — setup complete</span>";
    echo "<a class=\"home\" href=\"/\" title=\"Go to site root\">⌂ site root</a></div>";
    echo "<pre class=\"out\">";
    echo "$ pull-config.php written\n";
    echo "  repo:     " . $h($config['repo']) . "\n";
    echo "  branch:   " . $h($config['branch']) . "\n";
    echo "  subdir:   " . $h($config['subdir']) . "\n";
    echo "  timezone: " . $h($config['timezone']) . "\n";
    echo "  password: " . ($config['password_hash'] !== '' ? "set (asked before every pull)" : "not set (anyone with the URL can deploy)") . "\n";
    echo "  purge:    " . ($config['purge'] ? "on — files missing from the repo are deleted, irreversibly" : "off — obsolete files stay in place") . "\n";
    echo "  tracking: " . ($config['source'] === 'pr'
        ? "pull request #" . $h($config['pr_number']) . " (its head commit is deployed)"
        : "branch " . $h($config['branch'])) . "\n";
    echo "  auto:     " . ($config['auto_pull']
        ? "on — the tracking screen deploys a new commit within " . $h($config['auto_interval']) . "s"
        : "off — deploys only when you ask for one") . "\n\n";
    echo "# tracking screen:  pull.php?watch=1\n";
    echo "# deployed versions / rollback:  pull.php?history=1\n";
    echo "# deploy if changed: curl -s \"https://host/pull.php?check=1&plain=1\"   (for cron)\n";
    echo "# delete pull-config.php to re-run setup.\n";
    echo "</pre>";
    echo "<a class=\"btn\" href=\"pull.php\">$ run_pull_now</a>";
    echo "<a class=\"btn\" href=\"pull.php?watch=1\">$ open_tracking</a>";
    echo "</div></body></html>";
}

// ---------- CSS / JS ----------

function terminal_css(): string {
    return <<<CSS
<style>
:root{
  --bg:#2b2b2b;--bg2:#1f1f1f;--bar:#3a3a3a;--fg:#e6e6e6;--mute:#9aa0a6;
  --green:#7CFFB2;--cyan:#5fd7ff;--yellow:#ffd866;--red:#ff6b6b;--accent:#7CFFB2;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:var(--bg2);color:var(--fg);
  font-family:'JetBrains Mono','Fira Code',Menlo,Consolas,'Courier New',monospace;
  font-size:14px;line-height:1.55}
.term{max-width:880px;margin:2rem auto;background:var(--bg);
  border:1px solid #444;border-radius:8px;
  box-shadow:0 8px 28px rgba(0,0,0,.45);overflow:hidden}
.bar{display:flex;align-items:center;gap:6px;background:var(--bar);
  padding:8px 12px;border-bottom:1px solid #4a4a4a}
.dot{width:12px;height:12px;border-radius:50%}
.dot.r{background:#ff5f56}.dot.y{background:#ffbd2e}.dot.g{background:#27c93f}
.bar .title{margin-left:10px;color:#cfcfcf;font-size:12px;letter-spacing:.2px}
.bar .home{margin-left:auto;color:var(--accent);text-decoration:none;font-size:12px;
  border:1px solid var(--accent);border-radius:4px;padding:2px 8px;white-space:nowrap}
.bar .home:hover{background:var(--accent);color:#1a1a1a}
.out{margin:0;padding:18px 20px;white-space:pre-wrap;word-break:break-word;
  color:var(--fg);min-height:60px}
.out .line{display:block;white-space:pre-wrap}
.out .line::before{content:""}
.out .line.is-cmd{color:var(--accent)}
.out .line.is-err{color:var(--red)}
.out .line.is-cmt{color:var(--mute)}
.out .line.is-info{color:var(--cyan)}
.out .line.is-warn{color:var(--yellow)}
.out .caret{display:inline-block;width:8px;height:1em;
  background:var(--accent);vertical-align:-2px;
  animation:blink 1s steps(1) infinite}
@keyframes blink{50%{opacity:0}}
.form{padding:6px 20px 22px}
.form.hidden{display:none}
.fld{margin:18px 0}
.fld label{display:block;color:var(--accent);font-weight:600;margin-bottom:4px}
.fld .prompt{color:var(--cyan);margin-right:6px}
.fld .help{color:var(--mute);font-size:12.5px;margin:2px 0 8px;padding-left:18px}
.fld .help code{background:#1a1a1a;padding:1px 6px;border-radius:3px;color:#d7d7d7}
.fld .help a{color:var(--cyan)}
.fld input,.fld textarea{
  width:100%;background:#1a1a1a;color:var(--fg);
  border:1px solid #4a4a4a;border-radius:4px;
  padding:9px 11px;font:inherit;outline:none;caret-color:var(--accent)}
.fld input:focus,.fld textarea:focus{border-color:var(--accent)}
.fld textarea{resize:vertical}
.chk{display:flex;align-items:flex-start;gap:10px;margin:0 0 6px;
  color:var(--accent);font-weight:600;cursor:pointer}
.chk input[type=checkbox]{width:16px;height:16px;flex:0 0 auto;margin:3px 0 0;
  accent-color:var(--accent);cursor:pointer}
.chk .t{display:block;font-weight:600}
.chk .note{display:block;color:var(--mute);font-weight:400;font-size:12.5px;margin-top:4px}
.chk .note code{background:#1a1a1a;padding:1px 6px;border-radius:3px;color:#d7d7d7}
.fld .help .warn{color:var(--yellow);margin-top:6px}
.out .is-cmt{color:var(--mute)}
.out .is-err{color:var(--red)}
.btn{display:inline-block;margin:8px 20px 22px;padding:10px 18px;
  background:#1a1a1a;color:var(--accent);
  border:1px solid var(--accent);border-radius:4px;
  font:inherit;cursor:pointer;text-decoration:none}
.btn:hover{background:var(--accent);color:#1a1a1a}
.btn:disabled{opacity:.45;cursor:default}
.btn:disabled:hover{background:#1a1a1a;color:var(--accent)}
.bar .home + .home{margin-left:8px}
.mute{color:var(--mute)}
/* prefilled token link in the setup form */
.help .patbtn{display:inline-block;margin:8px 0 2px;padding:7px 13px;
  background:#1a1a1a;color:var(--accent);border:1px solid var(--accent);
  border-radius:4px;text-decoration:none;font-size:12.5px}
.help .patbtn:hover{background:var(--accent);color:#1a1a1a}
.help .perms{display:block;color:var(--yellow);margin-top:4px}
/* tracking screen */
.panel{padding:14px 20px;background:#262626;border-bottom:1px solid #3a3a3a}
.panel .row{display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;padding:2px 0}
.panel .k{flex:0 0 108px;color:var(--mute);white-space:nowrap}
.panel .v{flex:1 1 240px;word-break:break-word}
.panel .v a{color:var(--cyan)}
.panel .is-ok{color:var(--green)}
.panel .is-warn{color:var(--yellow)}
.panel .is-err{color:var(--red)}
.ctl{display:flex;align-items:center;gap:18px;flex-wrap:wrap;
  padding:12px 20px;border-bottom:1px solid #3a3a3a}
.ctl .btn{margin:0;padding:7px 14px}
.ctl .chk{margin:0;font-size:13px}
.panel ~ .out{max-height:55vh;overflow:auto}
.vlist ~ .out{max-height:none}
/* deploy log / rollback */
.vlist{padding:4px 20px 10px}
.ventry{display:flex;gap:14px;align-items:baseline;flex-wrap:wrap;
  padding:11px 0;border-bottom:1px solid #383838}
.ventry:last-child{border-bottom:none}
.ventry.live{background:#252f28;margin:0 -20px;padding-left:20px;padding-right:20px}
.ventry .when{flex:0 0 170px;color:var(--cyan)}
.ventry .when .age{display:block;color:var(--mute);font-size:12px}
.ventry .sha{flex:0 0 70px;color:var(--fg)}
.ventry .what{flex:1 1 220px;color:var(--mute);word-break:break-word}
.ventry .what .files{display:block;font-size:12px;color:#7a7f85}
.ventry .tag{color:var(--yellow);border:1px solid var(--yellow);border-radius:3px;
  padding:0 5px;font-size:11px;margin-left:4px}
.ventry .nowtag{flex:0 0 auto;color:var(--green);border:1px solid var(--green);
  border-radius:4px;padding:5px 12px;font-size:12.5px}
.ventry form{margin:0;flex:0 0 auto}
.ventry .btn{margin:0;padding:5px 12px;font-size:12.5px}
</style>
CSS;
}

function history_js(): string {
    return <<<'JS'
<script>
(function(){
  // Rolling back overwrites the live site, so make it a deliberate click.
  document.querySelectorAll('form.rb button').forEach(btn => {
    btn.addEventListener('click', ev => {
      const ok = confirm('Roll back to ' + btn.dataset.sha + ' (deployed ' + btn.dataset.at + ')?\n\n' +
        'That commit is deployed again over this directory, and automatic deploys ' +
        'pause until you deploy the tracked head.');
      if (!ok) ev.preventDefault();
    });
  });
})();
</script>
JS;
}

function watch_js(): string {
    return <<<'JS'
<script>
(function(){
  const cfg    = window.__watch || {interval: 60, auto: false};
  const panel  = document.getElementById('panel');
  const out    = document.getElementById('out');
  const autoEl = document.getElementById('auto');
  const nowEl  = document.getElementById('now');
  const tickEl = document.getElementById('tick');
  const every  = Math.max(15, cfg.interval | 0);
  let running = false, left = 0, lastSha = null;

  const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g,
    c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

  function line(text, cls){
    const n = document.createElement('span');
    n.className = 'line' + (cls || '');
    n.textContent = text;
    out.appendChild(n);
    out.appendChild(document.createTextNode('\n'));
    out.scrollTop = out.scrollHeight;
  }

  function classify(t){
    const s = t.trim();
    if (!s) return '';
    if (/^(error:|!)/.test(s))            return ' is-err';
    if (s.startsWith('warning:'))         return ' is-err';
    if (/^(purge:|deleted|DELETED:)/.test(s)) return ' is-warn';
    if (s.startsWith('#'))                return ' is-cmt';
    if (s.startsWith('==='))              return ' is-cmd';
    if (/^(START|END|STATUS|DURATION|FILES|COMMIT|tracking|head|check):/.test(s)) return ' is-info';
    return '';
  }

  function row(k, v, cls){
    return '<div class="row"><span class="k">' + esc(k) + '</span>' +
           '<span class="v' + (cls || '') + '">' + v + '</span></div>';
  }

  function render(s){
    if (s.auth === false){
      panel.innerHTML = row('status', '<span class="is-err">session expired — reload and unlock</span>');
      return false;
    }
    let head = esc(s.short);
    if (s.pr){
      head += ' — ' + esc(s.pr.title || '');
    } else if (s.commit){
      head += ' — ' + esc(s.commit.message || '');
    }

    let badge, cls;
    if (s.error)           { badge = 'error';            cls = ' is-err'; }
    else if (s.pinned)     { badge = 'pinned to a rolled-back version'; cls = ' is-warn'; }
    else if (!s.deployed)  { badge = 'never deployed';   cls = ' is-warn'; }
    else if (s.changed)    { badge = 'new commit ahead'; cls = ' is-warn'; }
    else                   { badge = 'up to date';       cls = ' is-ok'; }

    let target;
    if (s.pr){
      const meta = '[' + esc(s.pr.state) + (s.pr.draft ? ', draft' : '') + '] ' +
                   esc(s.pr.head) + ' &rarr; ' + esc(s.pr.base) + ' by ' + esc(s.pr.author);
      target = (s.pr.url ? '<a href="' + esc(s.pr.url) + '" target="_blank" rel="noopener">PR #' + esc(s.pr.number) + '</a>'
                         : 'PR #' + esc(s.pr.number)) + ' ' + meta;
    } else {
      target = 'branch ' + esc(String(s.ref || '').replace('refs/heads/', ''));
    }

    panel.innerHTML =
      row('state',    esc(badge), cls) +
      row('repo',     esc(s.repo)) +
      row('tracking', target) +
      row('head',     head) +
      row('deployed', s.deployed
            ? esc(s.deployed_short) + ' <span class="mute">at ' + esc(s.deployed_at) + '</span>'
            : '<span class="mute">nothing deployed from here yet</span>') +
      (s.pinned ? row('pinned', 'rolled back on ' + esc(s.pinned_at) +
            ' — auto-deploy paused. <a href="?history=1">versions</a>, or press deploy_now to return to the head.',
            ' is-warn') : '') +
      (s.error ? row('note', esc(s.error), ' is-err') : '') +
      row('checked',  esc(s.now) + ' <span class="mute">(' + esc(s.tz) + ')</span>');

    // Announce a moving head once, so the log reads as a timeline rather than a poll dump.
    if (s.sha && lastSha && s.sha !== lastSha){
      line('head moved: ' + lastSha.slice(0,7) + ' -> ' + s.sha.slice(0,7), ' is-warn');
    }
    if (s.sha) lastSha = s.sha;
    return true;
  }

  async function poll(){
    let res;
    try {
      res = await fetch('?status=1&_=' + Date.now(), {cache:'no-store', headers:{'Accept':'application/json'}});
    } catch (e){
      line('status check failed: ' + e.message, ' is-err');
      return null;
    }
    let data;
    try { data = await res.json(); }
    catch (e){ line('status check returned no JSON (HTTP ' + res.status + ')', ' is-err'); return null; }
    render(data);
    return data;
  }

  async function deploy(reason){
    if (running) return;
    running = true;
    nowEl.disabled = true;
    line('');
    line('$ pull.php — ' + reason, ' is-cmd');
    try {
      const res = await fetch('?plain=1&_=' + Date.now(), {cache:'no-store'});
      if (res.status === 401){
        line('unauthorized — reload the page and unlock', ' is-err');
      } else if (res.body && res.body.getReader){
        const reader = res.body.getReader();
        const dec = new TextDecoder();
        let buf = '';
        for (;;){
          const {value, done} = await reader.read();
          if (done) break;
          buf += dec.decode(value, {stream:true});
          let i;
          while ((i = buf.indexOf('\n')) >= 0){
            const t = buf.slice(0, i);
            buf = buf.slice(i + 1);
            line(t, classify(t));
          }
        }
        if (buf) line(buf, classify(buf));
      } else {
        // Older browsers: no streaming, so show the whole run once it finishes.
        (await res.text()).split('\n').forEach(t => line(t, classify(t)));
      }
    } catch (e){
      line('deploy request failed: ' + e.message, ' is-err');
    }
    running = false;
    nowEl.disabled = false;
    await poll();
  }

  async function cycle(){
    const s = await poll();
    // A pinned directory is sitting on a rollback on purpose — never deploy over it
    // by ourselves; only the explicit deploy_now button leaves that state.
    if (s && s.changed && !s.pinned && autoEl.checked && !running){
      await deploy('auto-deploy: tracked head moved');
    }
    left = every;
  }

  nowEl.addEventListener('click', () => deploy('manual deploy'));
  autoEl.addEventListener('change', () => {
    line(autoEl.checked ? 'auto-deploy: on' : 'auto-deploy: off (this browser tab only)', ' is-cmt');
    if (autoEl.checked) cycle();
  });

  setInterval(() => {
    tickEl.textContent = running
      ? 'deploying…'
      : 'next check in ' + Math.max(0, left) + 's';
    if (--left <= 0 && !running) cycle();
  }, 1000);

  line('$ watching every ' + every + 's — auto-deploy is ' + (autoEl.checked ? 'on' : 'off'), ' is-cmd');
  if (cfg.pinned) line('pinned to a rolled-back version — auto-deploy stays paused until you deploy the head', ' is-warn');
  cycle();
})();
</script>
JS;
}

function terminal_js(bool $isForm = false): string {
    if ($isForm) {
        // Setup form: type the intro lines, then reveal the form.
        return <<<'JS'
<script>
(function(){
  const intro = window.__intro || [];
  const host  = document.getElementById('intro');
  const form  = document.getElementById('setup');
  let speed = 7, skipped = false;

  function skip(){ skipped = true; speed = 0; }
  document.addEventListener('click', skip, {once:false});
  document.addEventListener('keydown', skip, {once:false});

  async function typeLine(node, text){
    for (let i = 0; i < text.length; i++){
      node.append(text[i]);
      if (!skipped) await new Promise(r => setTimeout(r, speed));
    }
  }

  async function run(){
    for (const [kind, text] of intro){
      const line = document.createElement('span');
      line.className = 'line' +
        (kind === 'c' ? ' is-cmt' :
         kind === 'e' ? ' is-err' :
         kind === 'p' ? ' is-cmd' :
         kind === 'i' ? ' is-info' : '');
      host.appendChild(line);
      await typeLine(line, text);
      host.appendChild(document.createTextNode('\n'));
    }
    form.classList.remove('hidden');
    const first = form.querySelector('input,textarea');
    if (first) first.focus();
  }

  // Keep the "create a token" link in step with what this deploy will actually need:
  // the owner comes from the repo field, Pull requests: Read appears once a PR is tracked.
  const patBtn   = document.getElementById('patbtn');
  const patPerms = document.getElementById('patperms');
  const val = id => ((document.getElementById(id) || {}).value || '').trim();

  function syncPat(){
    if (!patBtn) return;
    const repo  = val('f_repo');
    const pr    = val('f_pr_number');
    const owner = repo.split('/')[0].trim();
    const p = new URLSearchParams();
    p.set('name', ('pull.php deploy ' + repo).trim().slice(0, 40));
    p.set('description', 'Read-only token for pull.php. Under "Repository access" pick ' +
      (repo || 'the repository to deploy') + '.');
    p.set('expires_in', 'none');
    p.set('contents', 'read');
    p.set('metadata', 'read');
    if (pr)    p.set('pull_requests', 'read');
    if (owner) p.set('target_name', owner);
    patBtn.href = 'https://github.com/settings/personal-access-tokens/new?' + p.toString();

    if (patPerms){
      patPerms.textContent = 'Preselects Contents: Read + Metadata: Read' +
        (pr ? ', plus Pull requests: Read (to follow PR #' + pr + ')' : '') +
        (owner ? '. Resource owner: ' + owner + '.'
               : '. Fill in the repository above and the owner gets preselected too.');
    }
  }
  ['f_repo', 'f_pr_number'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', syncPat);
  });
  syncPat();

  run();
})();
</script>
JS;
    }

    // Run-output page: type out streaming lines as they arrive from the server.
    return <<<'JS'
<script>
(function(){
  const out = document.getElementById('out');
  if (!out) return;
  const queue = [];
  let busy = false, speed = 4;

  function classify(t){
    const s = t.trim();
    if (!s) return '';
    if (s.startsWith('error:') || s.startsWith('!')) return ' is-err';
    if (s.startsWith('warning:')) return ' is-err';
    if (s.startsWith('purge:') || s.startsWith('deleted')) return ' is-warn';
    if (s.startsWith('#'))   return ' is-cmt';
    if (s.startsWith('==='))  return ' is-cmd';
    if (/^(START|END|STATUS|DURATION|FILES):/.test(s)) return ' is-info';
    if (/^DELETED:/.test(s)) return ' is-warn';
    if (s.startsWith('downloading') || s.startsWith('downloaded') ||
        s.startsWith('extracted')   || s.startsWith('copied')     ||
        s.startsWith('copying'))    return ' is-info';
    return '';
  }

  async function drain(){
    busy = true;
    while (queue.length){
      const {text, cls} = queue.shift();
      const node = document.createElement('span');
      node.className = 'line' + cls;
      out.appendChild(node);
      for (let i = 0; i < text.length; i++){
        node.append(text[i]);
        if (speed) await new Promise(r => setTimeout(r, speed));
      }
      out.appendChild(document.createTextNode('\n'));
      window.scrollTo(0, document.body.scrollHeight);
      if (queue.length > 30) speed = 0;
      else if (queue.length > 8) speed = 1;
      else speed = 4;
    }
    busy = false;
    if (window.__pullDone){
      const caret = document.createElement('span');
      caret.className = 'caret';
      out.appendChild(caret);
    }
  }

  function enqueue(node){
    queue.push({text: node.dataset.t || '', cls: classify(node.dataset.t || '')});
    node.remove();
    if (!busy) drain();
  }

  // Process anything already in the DOM, then watch for more.
  document.querySelectorAll('.line').forEach(enqueue);
  const obs = new MutationObserver(muts => {
    for (const m of muts) for (const n of m.addedNodes){
      if (n.nodeType === 1 && n.tagName === 'I' && n.classList.contains('line')){
        enqueue(n);
      }
    }
  });
  obs.observe(document.body, {childList:true, subtree:true});

  // Periodically poll in case MutationObserver misses chunks.
  setInterval(() => {
    document.querySelectorAll('i.line').forEach(enqueue);
    if (window.__pullDone && !busy && queue.length === 0){
      // already finished
    }
  }, 250);
})();
</script>
JS;
}
