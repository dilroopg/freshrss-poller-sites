<?php
/**
 * pol-ler Sites — run one due profile without a browser.
 *
 * FreshRSS's own cron (app/actualize_script.php) fires at :03 and :33 only, so
 * relying on the maintenance hook gives a 30-minute floor no matter what
 * interval the UI offers. This CLI entry point lets a systemd timer poll as
 * often as the settings ask for, and it honours each profile's own interval and
 * disabled flag, so a tight poll is cheap: nothing is written when nothing is due.
 *
 * Usage: php poller-sites-cron.php [--force]
 *   --force  run every enabled profile now, ignoring its interval
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}

// Not hardcoded: this script ships in the repo and must run on any install.
// Fall back to the standard container path only when nothing else says otherwise.
$root = getenv('FRESHRSS_ROOT') ?: '/var/www/FreshRSS';
chdir($root);
require $root . '/constants.php';
require LIB_PATH . '/lib_rss.php';
require LIB_PATH . '/lib_install.php';

$force = in_array('--force', $argv, true);

$user = null;
foreach (glob($root . '/data/users/*/config.php') ?: [] as $f) {
	$user = basename(dirname($f));
	break;
}
if ($user === null) {
	fwrite(STDERR, "ERROR: no FreshRSS user found\n");
	exit(1);
}

Minz_Session::init('FreshRSS', true);
FreshRSS_Context::initSystem();
Minz_ExtensionManager::init();
FreshRSS_Context::initUser($user);
Minz_ExtensionManager::enableByList(FreshRSS_Context::userConf()->extensions_enabled, 'user');

$ext = Minz_ExtensionManager::findExtension('pol-ler Sites');
if ($ext === null) {
	fwrite(STDERR, "ERROR: extension 'pol-ler Sites' not found\n");
	exit(1);
}
if (!$ext->isEnabled()) {
	// Enabled check reads user config; a disabled extension is a no-op, not an error.
	echo "pol-ler Sites is not enabled for this user; nothing to do\n";
	exit(0);
}

$sites = $ext->sites();
if ($sites === []) {
	echo "no sites configured\n";
	exit(0);
}

$ran = 0;
$skipped = 0;
foreach ($sites as $i => $site) {
	$due = $force || $ext->isDue($site);
	if (!$due) {
		$skipped++;
		continue;
	}
	$t0 = microtime(true);
	try {
		$res = $ext->runSite($site);
		$line = $res['summary'];
	} catch (Throwable $e) {
		$line = 'error: ' . $e->getMessage();
	}
	$sites[$i] = $site;
	$sites[$i]['last_run'] = time();
	$sites[$i]['last_summary'] = $line;
	printf("[%s] %.2fs %s\n", $site['domain'], microtime(true) - $t0, $line);
	$ran++;

	// persist immediately so a later failure cannot lose an earlier result
	$rc = new ReflectionMethod($ext, 'saveAll');
	$rc->setAccessible(true);
	$rc->invoke($ext, $sites, $ext->settings(), $ext->bin());
}

if ($ran === 0) {
	echo "nothing due ($skipped site(s) skipped)\n";
}
