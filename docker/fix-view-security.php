<?php
/**
 * Converts any SQL SECURITY DEFINER view in the app's own schema to
 * SQL SECURITY INVOKER.
 *
 * sysPass's own schema defines account_search_v/account_data_v (used by
 * every account search and password view) with
 * DEFINER=`sp_admin`@`localhost` SQL SECURITY DEFINER. A schema-only
 * import (mysqldump without --all-databases - the recommended way, see
 * MIGRATION.md) copies that DEFINER clause verbatim but never copies
 * MySQL *users*, so sp_admin@localhost never exists on the new server.
 * MySQL/MariaDB then denies access to the view for the real,
 * correctly-privileged connecting user, with a plain "Access denied"
 * that gives no hint the view/definer is the actual problem - looks
 * exactly like a wrong DB password or grant, but isn't one.
 *
 * Idempotent and safe to run on every boot: SQL SECURITY INVOKER just
 * means "run with the querying user's own privileges", which this
 * image's app DB user already has (GRANT ALL on its own schema) - no
 * separate elevated definer account is ever needed here.
 */

$configFile = getenv('CONFIG_FILE') ?: '/var/www/html/sysPass/app/config/config.xml';

if (!is_file($configFile)) {
    fwrite(STDERR, "fix-view-security: no config.xml yet, skipping.\n");
    exit(0);
}

$doc = new DOMDocument();
$doc->substituteEntities = false;

if (!@$doc->load($configFile)) {
    fwrite(STDERR, "fix-view-security: could not parse config.xml, skipping.\n");
    exit(0);
}

function xmlValue(DOMDocument $doc, string $tag): ?string
{
    $nodes = $doc->getElementsByTagName($tag);

    return $nodes->length > 0 ? $nodes->item(0)->nodeValue : null;
}

$dbHost = xmlValue($doc, 'dbHost');
$dbName = xmlValue($doc, 'dbName');
$dbUser = xmlValue($doc, 'dbUser');
$dbPass = xmlValue($doc, 'dbPass');
$dbPort = xmlValue($doc, 'dbPort') ?: '3306';

if (!$dbHost || !$dbName || !$dbUser) {
    fwrite(STDERR, "fix-view-security: incomplete DB config, skipping.\n");
    exit(0);
}

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'fix-view-security: could not connect (' . $e->getMessage() . "), skipping.\n");
    exit(0);
}

$stmt = $pdo->prepare(
    "SELECT TABLE_NAME FROM information_schema.VIEWS
     WHERE TABLE_SCHEMA = ? AND SECURITY_TYPE = 'DEFINER'"
);
$stmt->execute([$dbName]);
$views = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($views as $view) {
    try {
        $row = $pdo->query('SHOW CREATE VIEW `' . $view . '`')->fetch(PDO::FETCH_ASSOC);
        $createSql = $row['Create View'];

        if (!preg_match('/\bAS\s+(select.*)$/is', $createSql, $m)) {
            fwrite(STDERR, "fix-view-security: could not parse SELECT for {$view}, skipping.\n");
            continue;
        }

        // DEFINER=CURRENT_USER must be explicit: an ALTER VIEW that omits
        // it entirely still checks privileges against the view's existing
        // (stale) definer and fails with "you need the SUPER privilege",
        // even though the app's own DB user has every privilege it needs
        // on its own schema.
        $pdo->exec("ALTER ALGORITHM=UNDEFINED DEFINER=CURRENT_USER SQL SECURITY INVOKER VIEW `{$view}` AS {$m[1]}");
        echo "fix-view-security: converted {$view} to SQL SECURITY INVOKER.\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "fix-view-security: failed on {$view} (" . $e->getMessage() . "), leaving it as-is.\n");
    }
}
