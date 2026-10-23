#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Print a QA scope as a short ID list. Agents run this instead of reading cases/.
 *
 *   php qa/scope.php                 smoke (auto + smoke tag only)
 *   php qa/scope.php smoke
 *   php qa/scope.php change [base]
 *   php qa/scope.php feature seo
 *   php qa/scope.php ids T3AI-SEO-001,T3AI-SEO-007
 *   git diff --name-only | php qa/scope.php change --stdin
 */

$qaDir = __DIR__;
$pkgDir = dirname($qaDir);
$pkgKey = basename($pkgDir);
$argvList = array_values(array_slice($argv, 1));

$stdin = false;
$listManual = false;
$explicitPaths = [];
$filtered = [];
for ($i = 0; $i < count($argvList); $i++) {
    if ($argvList[$i] === '--stdin') {
        $stdin = true;
        continue;
    }
    if ($argvList[$i] === '--manual') {
        $listManual = true;
        continue;
    }
    if ($argvList[$i] === '--') {
        $explicitPaths = array_slice($argvList, $i + 1);
        break;
    }
    $filtered[] = $argvList[$i];
}
$argvList = $filtered;

$mode = $argvList[0] ?? 'smoke';
$modeArg = $argvList[1] ?? null;

$cases = loadCases($qaDir);
$slugs = array_keys($cases);
if ($cases === []) {
    fwrite(STDERR, "No case files in {$qaDir}/cases\n");
    exit(1);
}

$selected = [];
$open = [];
$edges = [];
$note = '';

switch ($mode) {
    case 'smoke':
        $selected = filterCases($cases, static function (array $c): bool {
            return $c['status'] === 'auto' && in_array('smoke', $c['tags'], true);
        });
        $note = 'smoke = auto cases tagged smoke only. No backend, no live AI, do not open cases/.';
        break;
    case 'change':
        $paths = $explicitPaths;
        if ($stdin || $paths === []) {
            $paths = array_merge($paths, readStdinPaths($stdin));
        }
        if ($paths === []) {
            $paths = gitChangedPaths($pkgDir, $modeArg);
        }
        if ($paths === []) {
            $note = 'No changed paths. Fell back to smoke-auto. Pass paths after -- or use --stdin.';
            $selected = filterCases($cases, static function (array $c): bool {
                return $c['status'] === 'auto' && in_array('smoke', $c['tags'], true);
            });
            break;
        }
        $mapped = mapImpact($qaDir . '/impact.md', $paths, $slugs);
        $featureSlugs = $mapped['slugs'];
        $edges = $mapped['edges'];
        $open = [];
        $selected = [];
        foreach ($cases as $slug => $list) {
            foreach ($list as $c) {
                $smokeAuto = $c['status'] === 'auto' && in_array('smoke', $c['tags'], true);
                $inFeature = in_array($slug, $featureSlugs, true);
                if ($smokeAuto || ($inFeature && $c['status'] === 'auto')) {
                    $selected[] = $c;
                } elseif ($listManual && $inFeature) {
                    $selected[] = $c;
                    $open[] = $slug;
                }
            }
        }
        $open = array_values(array_unique($open));
        $note = 'AUTO only (plus smoke-auto). Features: '
            . ($featureSlugs === [] ? '(none matched)' : implode(', ', $featureSlugs))
            . '. For manuals: php qa/scope.php feature <slug> --manual';
        break;
    case 'feature':
        if ($modeArg === null || !isset($cases[$modeArg])) {
            fwrite(STDERR, "Usage: php qa/scope.php feature <slug>\nSlugs: " . implode(', ', $slugs) . "\n");
            exit(1);
        }
        $open = $listManual ? [$modeArg] : [];
        $selected = $listManual
            ? $cases[$modeArg]
            : array_values(array_filter($cases[$modeArg], static fn(array $c): bool => $c['status'] === 'auto'));
        $note = $listManual
            ? 'One feature including MANUAL. Open only cases/' . $modeArg . '.md'
            : 'One feature, AUTO only. For manuals: php qa/scope.php feature ' . $modeArg . ' --manual';
        break;
    case 'ids':
        if ($modeArg === null) {
            fwrite(STDERR, "Usage: php qa/scope.php ids T3AI-SEO-001,T3AI-SEO-007\n");
            exit(1);
        }
        $want = array_filter(array_map('trim', explode(',', $modeArg)));
        foreach ($cases as $slug => $list) {
            foreach ($list as $c) {
                if (in_array($c['id'], $want, true)) {
                    $selected[] = $c;
                    $open[] = $slug;
                }
            }
        }
        $open = array_values(array_unique($open));
        $note = 'Exact IDs only.';
        break;
    default:
        fwrite(STDERR, "Unknown mode '{$mode}'. Use smoke|change|feature|ids\n");
        exit(1);
}

printScope($pkgKey, $mode, $selected, $open, $edges, $note);

function loadCases(string $qaDir): array
{
    $out = [];
    foreach (glob($qaDir . '/cases/*.md') ?: [] as $file) {
        $slug = basename($file, '.md');
        if ($slug === '_template') {
            continue;
        }
        $out[$slug] = parseCaseFile($file, $slug);
    }
    ksort($out);
    return $out;
}

function parseCaseFile(string $file, string $slug): array
{
    $cases = [];
    $id = null;
    $title = '';
    $meta = null;
    $test = '';
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^### (T3A[IF]-[A-Z]+-\d{3}) (.+)$/', $line, $m)) {
            if ($id !== null && $meta !== null) {
                $cases[] = buildCase($slug, $id, $title, $meta, $test);
            }
            $id = $m[1];
            $title = $m[2];
            $meta = null;
            $test = '';
            continue;
        }
        if ($id === null) {
            continue;
        }
        if ($meta === null && preg_match('/^P[0-3] · /', $line)) {
            $meta = $line;
            continue;
        }
        if ($test === '' && preg_match('/^Test:\s*(.+)$/', $line, $m)) {
            $test = trim($m[1], " `");
        }
    }
    if ($id !== null && $meta !== null) {
        $cases[] = buildCase($slug, $id, $title, $meta, $test);
    }
    return $cases;
}

function buildCase(string $slug, string $id, string $title, string $meta, string $test): array
{
    $parts = array_map('trim', explode('·', $meta));
    $status = 'manual';
    $tags = [];
    $kind = $parts[1] ?? '';
    if (isset($parts[3]) && in_array($parts[3], ['auto', 'manual', 'gap'], true)) {
        $status = $parts[3];
        $tags = parseTags($parts[2] ?? '');
    } elseif (isset($parts[2]) && in_array($parts[2], ['auto', 'manual', 'gap'], true)) {
        $status = $parts[2];
    }
    return [
        'id' => $id,
        'title' => $title,
        'slug' => $slug,
        'status' => $status,
        'kind' => $kind,
        'tags' => $tags,
        'test' => $test === 'none' ? '' : $test,
    ];
}

function parseTags(string $raw): array
{
    if ($raw === '' || $raw === '—') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function filterCases(array $cases, callable $keep): array
{
    $out = [];
    foreach ($cases as $list) {
        foreach ($list as $c) {
            if ($keep($c)) {
                $out[] = $c;
            }
        }
    }
    return $out;
}

function readStdinPaths(bool $force): array
{
    $paths = [];
    $stat = fstat(STDIN);
    $hasData = is_array($stat) && (($stat['mode'] & 0170000) === 0010000 || ($stat['size'] ?? 0) > 0);
    if (!$force && !$hasData) {
        return [];
    }
    if (!$force && stream_isatty(STDIN)) {
        return [];
    }
    while (($line = fgets(STDIN)) !== false) {
        $line = trim($line);
        if ($line !== '') {
            $paths[] = $line;
        }
    }
    return $paths;
}

function gitChangedPaths(string $pkgDir, ?string $base): array
{
    $paths = [];
    foreach ([['diff', '--name-only'], ['diff', '--name-only', '--cached']] as $args) {
        $paths = array_merge($paths, runGit($pkgDir, $args));
    }
    $rev = $base;
    if ($rev === null) {
        foreach (['main', 'master', 'origin/main'] as $candidate) {
            $ok = runGit($pkgDir, ['rev-parse', '--verify', $candidate]);
            if ($ok !== []) {
                $rev = $candidate;
                break;
            }
        }
    }
    if ($rev !== null) {
        $paths = array_merge($paths, runGit($pkgDir, ['diff', '--name-only', $rev . '...HEAD']));
    }
    return array_values(array_unique($paths));
}

function runGit(string $pkgDir, array $args): array
{
    $cmd = array_merge(['git', '-C', $pkgDir], $args);
    $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $pkgDir);
    if (!is_resource($proc)) {
        return [];
    }
    $out = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode("\n", $out))));
}

function mapImpact(string $impactFile, array $paths, array $slugs): array
{
    $normPaths = array_map('normalizeChangedPath', $paths);
    $matched = [];
    $edges = [];
    $section = 'code';
    foreach (file($impactFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (str_contains($line, '→ ns_t3ai') || str_contains($line, '→ child') || str_contains($line, 'ns_t3af →')) {
            $section = 'edges';
        }
        if (!str_starts_with($line, '| `')) {
            continue;
        }
        $cells = array_map('trim', explode('|', trim($line, '|')));
        if (count($cells) < 2) {
            continue;
        }
        preg_match_all('/`([^`]+)`/', $cells[0], $patterns);
        preg_match_all('/`([^`]+)`/', $cells[1], $refs);
        $hit = false;
        foreach ($normPaths as $path) {
            foreach ($patterns[1] as $pattern) {
                if (pathMatches($path, $pattern)) {
                    $hit = true;
                    break 2;
                }
            }
        }
        if (!$hit) {
            continue;
        }
        if ($section === 'edges') {
            $edges[] = trim($cells[0] . ' => ' . $cells[1]);
            continue;
        }
        foreach ($refs[1] as $ref) {
            if (in_array($ref, $slugs, true)) {
                $matched[] = $ref;
            }
        }
    }
    return [
        'slugs' => array_values(array_unique($matched)),
        'edges' => array_values(array_unique($edges)),
    ];
}

function normalizeChangedPath(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#^packages/ns[_-]t3a[if]/#', '', $path) ?? $path;
    return ltrim($path, '/');
}

function pathMatches(string $changed, string $pattern): bool
{
    $changed = str_replace('\\', '/', $changed);
    $pattern = str_replace('\\', '/', $pattern);
    if (str_ends_with($pattern, '/**') || str_ends_with($pattern, '/*')) {
        $prefix = rtrim(substr($pattern, 0, strrpos($pattern, '/') ?: strlen($pattern)), '/');
        return $changed === $prefix || str_starts_with($changed, $prefix . '/');
    }
    if (str_contains($pattern, '*')) {
        return fnmatch($pattern, $changed) || fnmatch($pattern, basename($changed));
    }
    if (!str_contains($pattern, '/')) {
        return basename($changed) === $pattern || str_ends_with($changed, '/' . $pattern);
    }
    return $changed === $pattern
        || str_ends_with($changed, '/' . $pattern)
        || str_starts_with($changed, rtrim($pattern, '/') . '/');
}

function printScope(string $pkgKey, string $mode, array $selected, array $open, array $edges, string $note): void
{
    $counts = ['auto' => 0, 'manual' => 0, 'gap' => 0];
    foreach ($selected as $c) {
        $counts[$c['status']] = ($counts[$c['status']] ?? 0) + 1;
    }
    $openFiles = array_map(static fn(string $s): string => 'cases/' . $s . '.md', $open);
    $filters = autoFilters($selected);
    $isT3af = $pkgKey === 'ns_t3af';

    echo 'PACKAGE: ' . $pkgKey . PHP_EOL;
    echo 'MODE: ' . $mode . PHP_EOL;
    echo 'NOTE: ' . $note . PHP_EOL;
    echo 'OPEN: ' . ($openFiles === [] ? 'none' : implode(', ', $openFiles)) . PHP_EOL;
    echo 'DO_NOT: load other qa/cases files, the other extension qa/ tree, or live AI' . PHP_EOL;
    echo 'COUNTS: auto=' . $counts['auto'] . ' manual=' . $counts['manual'] . ' gap=' . $counts['gap'] . PHP_EOL;
    echo 'COMMANDS:' . PHP_EOL;
    echo '  composer test' . ($filters === '' ? '' : " -- --filter '" . $filters . "'") . PHP_EOL;
    if ($isT3af) {
        if (hasFunctional($selected)) {
            echo '  composer test:functional' . PHP_EOL;
        }
        echo '  composer stan' . PHP_EOL;
    }
    echo '  composer cs:check' . PHP_EOL;
    if ($edges !== []) {
        echo 'EDGES (run the other package scope only if the user asked):' . PHP_EOL;
        foreach ($edges as $edge) {
            echo '  ' . $edge . PHP_EOL;
        }
    }
    echo 'AUTO:' . PHP_EOL;
    $any = false;
    foreach ($selected as $c) {
        if ($c['status'] !== 'auto') {
            continue;
        }
        $any = true;
        echo '  ' . $c['id'] . "\t" . $c['slug'] . "\t" . ($c['test'] !== '' ? $c['test'] : 'none') . PHP_EOL;
    }
    if (!$any) {
        echo '  (none)' . PHP_EOL;
    }
    if ($counts['manual'] > 0) {
        echo 'MANUAL: ' . $counts['manual'] . ' (listed because --manual)' . PHP_EOL;
        foreach ($selected as $c) {
            if ($c['status'] === 'manual') {
                echo '  ' . $c['id'] . "\t" . $c['slug'] . PHP_EOL;
            }
        }
    }
    if ($counts['gap'] > 0) {
        echo 'GAP: ' . $counts['gap'] . ' (not verified)' . PHP_EOL;
        foreach ($selected as $c) {
            if ($c['status'] === 'gap') {
                echo '  ' . $c['id'] . "\t" . $c['slug'] . PHP_EOL;
            }
        }
    }
}

function autoFilters(array $selected): string
{
    $classes = [];
    foreach ($selected as $c) {
        if ($c['status'] !== 'auto' || $c['test'] === '') {
            continue;
        }
        if (preg_match('#/([A-Za-z0-9_]+Test)\.php#', $c['test'], $m)) {
            $classes[$m[1]] = true;
        }
    }
    if ($classes === [] || count($classes) > 12) {
        return '';
    }
    return implode('|', array_keys($classes));
}

function hasFunctional(array $selected): bool
{
    foreach ($selected as $c) {
        if ($c['status'] === 'auto' && str_contains($c['test'], 'Tests/Functional/')) {
            return true;
        }
    }
    return false;
}
