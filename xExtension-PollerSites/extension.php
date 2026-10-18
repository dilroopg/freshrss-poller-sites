<?php

/**
 * pol-ler Sites
 *
 * Paste a pol-ler feed you built by hand for a site plus that site's domain.
 * From then on every feed added from that domain gets its own pol-ler feed
 * cloned exactly from the template, and is filed into that site's categories —
 * for any number of sites, from the UI.
 *
 * Deleting a profile only stops this extension managing that site. It never
 * deletes pol-ler feeds, subscriptions or categories: the site keeps working on
 * its native feed exactly as it did before the profile existed.
 */
class PollerSitesExtension extends Minz_Extension
{
	/** wall time a single site run may take */
	private const RUN_BUDGET = 25;

	/** a run lock older than this is stale */
	private const LOCK_SECONDS = 180;

	/** total wall time the page/CLI hook may spend across all sites */
	private const HOOK_BUDGET = 20;

	private const POOL_NAME = 'Direct feeds (unused)';

	/** days a deleted profile stays recoverable */
	private const BIN_DAYS = 10;

	private static ?PDO $pdo = null;
	private static string $pdoKey = '';

	public function init()
	{
		$this->registerHook(Minz_HookType::FreshrssUserMaintenance, [$this, 'tick']);
		$this->registerHook(Minz_HookType::JsVars, [$this, 'injectJsVars']);
		// Badge lives in its own file and is attached to every page, the same way
		// Stock UI extensions do it. Nothing in core is edited, so this keeps
		// working through FreshRSS upgrades and on any other install.
		Minz_View::appendScript($this->getFileUrl('badge.js', 'js', true));
		// Config-page behaviour must live in a file, not inline in configure.phtml:
		// FreshRSS loads that view into the AJAX slider (configureAction nulls the
		// layout), and inline <script> in an injected fragment does not execute —
		// which left sites_json empty and made every save fail its own guard.
		Minz_View::appendScript($this->getFileUrl('config.js', 'js', true));
	}

	/**
	 * Data the badge needs, in the page as a plain global.
	 *
	 * Keyed by FreshRSS feed id:
	 *   { "<id>": {"role":"twin","template":24,"domain":"example.com"} }
	 *   { "<id>": {"role":"direct","template":24,"domain":"example.com"} }
	 *
	 * Only feeds this extension actually manages appear here, so the badge never
	 * claims a feed is handled when it is not.
	 */
	public function injectJsVars($vars = [])
	{
		if (!is_array($vars)) { $vars = []; }
		$vars['pollerSites'] = $this->badgeMap();
		return $vars;
	}

	/** @return array<string,array{role:string,template:int,domain:string}> */
	public function badgeMap(): array
	{
		$sites = $this->sites();
		if ($sites === []) { return []; }

		// template id -> site, so a twin can report which template built it
		$byTemplate = [];
		foreach ($sites as $s) { $byTemplate[(int)$s['template']] = $s; }

		$map = [];
		try {
			$feedDAO = FreshRSS_Factory::createFeedDao();
			foreach ($feedDAO->selectAll() as $f) {
				$url = (string)($f['url'] ?? '');
				$id = (int)($f['id'] ?? 0);
				if ($id <= 0 || $url === '') { continue; }

				if (preg_match('~pol-ler(?::\d+)?/feed/(\d+)~', $url, $m)) {
					$domain = $this->domainForTwin((int)$m[1]);
					if ($domain !== null && isset($byTemplate[$domain['template']])) {
						$map[(string)$id] = [
							'role' => 'twin',
							'template' => (int)$domain['template'],
							'domain' => (string)$byTemplate[$domain['template']]['domain'],
						];
					}
					continue;
				}

				$host = strtolower((string)parse_url($url, PHP_URL_HOST));
				if ($host === '') { continue; }
				$host = (string)preg_replace('~^www\.~', '', $host);
				foreach ($sites as $s) {
					if ($s['domain'] !== $host) { continue; }
					$map[(string)$id] = [
						'role' => 'direct',
						'template' => (int)$s['template'],
						'domain' => (string)$s['domain'],
					];
					break;
				}
			}
		} catch (Throwable $e) {
			return [];
		}
		return $map;
	}

	/**
	 * Which site profile (if any) owns a pol-ler feed id?
	 *
	 * Resolved from the scrape URL's host, so a twin is always attributed to the
	 * profile whose domain it scrapes rather than to whichever profile happened
	 * to hold a matching template number.
	 */
	private function domainForTwin(int $pid): ?array
	{
		$db = $this->pg();
		if ($db === null) { return null; }
		$st = $db->prepare('SELECT uri FROM frontend_feed WHERE id = ?');
		$st->execute([$pid]);
		$uri = $st->fetchColumn();
		if ($uri === false) { return null; }
		$host = strtolower((string)parse_url((string)$uri, PHP_URL_HOST));
		$host = (string)preg_replace('~^www\.~', '', $host);

		foreach ($this->sites() as $s) {
			if ($s['domain'] !== $host) { continue; }
			return ['template' => (int)$s['template'], 'domain' => (string)$s['domain']];
		}
		return null;
	}

	// ------------------------------------------------------------- settings

	/** Connection settings, defaulted to this box. */
	/**
	 * Called on install/enable. Creates nothing and writes nothing: the
	 * extension only reads what the user configures, so there is no state to
	 * seed, and installing it must not create categories on someone's instance.
	 */
	public function install()
	{
		return true;
	}

	/**
	 * Called on disable/remove. Returns true so a disable is never blocked.
	 *
	 * Nothing is deleted here by design: pol-ler feeds, the twins, and the
	 * per-site categories all stay. Disabling an extension is not consent to
	 * destroy feeds, and pol-ler's database is separately the user's.
	 */
	public function uninstall()
	{
		try {
			$sites = $this->sites();
			Minz_Log::notice('pol-ler Sites: disabled with ' . count($sites)
				. ' site profile(s). Feeds, categories and pol-ler data were left untouched.');
		} catch (Throwable $e) {
			// Never block a disable.
		}
		return true;
	}

	/**
	 * Connection defaults, in ONE place.
	 *
	 * Every part of this extension reads them through settings() rather than
	 * repeating the literals. Duplicated values were how a different install
	 * could silently fail: a user whose containers are named differently would
	 * get working config fields and stale hardcoded values behind them.
	 *
	 * These match the compose file shipped alongside this extension
	 * (docker-compose.pol-ler.yml), so a fresh install needs no editing.
	 */
	/** Default name of the one-category layout. */
	public const SINGLE_CAT_DEFAULT = 'Scraped feeds (pol-ler)';

	public const DEFAULTS = [
		'pg_host'  => 'db-pol-ler',
		'pg_port'  => 5432,
		'pg_db'    => 'politepol',
		'pg_user'  => 'poluser',
		'pg_pass'  => '',
		'http_url' => 'http://pol-ler:8088',
		// Browser-reachable address of pol-ler's own UI, used only to build the
		// "Open pol-ler" link. Empty = no link. Deliberately separate from
		// http_url, which is a Docker-internal name no browser can resolve.
		'ui_url'   => '',
	];

	/**
	 * Save connection/layout settings from outside the settings page.
	 *
	 * Minz_Extension::setUserConfiguration() is protected, and saveAll() is
	 * private, so nothing outside this class could persist a layout change.
	 */
	public function saveSettings(array $patch): void
	{
		$cur = $this->settings();
		foreach ($patch as $k => $v) { $cur[$k] = $v; }
		$this->saveAll($this->sites(), $cur, $this->bin());
	}

	public function settings(): array
	{
		$raw = $this->getUserConfigurationValue('settings', '{}');
		$s = is_array($raw) ? $raw : json_decode((string)$raw, true);
		if (!is_array($s)) { $s = []; }
		$d = self::DEFAULTS;
		return [
			'pg_host' => (string)($s['pg_host'] ?? $d['pg_host']),
			'pg_port' => (int)($s['pg_port'] ?? $d['pg_port']),
			'pg_db' => (string)($s['pg_db'] ?? $d['pg_db']),
			'pg_user' => (string)($s['pg_user'] ?? $d['pg_user']),
			'pg_pass' => (string)($s['pg_pass'] ?? $d['pg_pass']),
			'http_url' => rtrim((string)($s['http_url'] ?? $d['http_url']), '/'),
			// Browser-reachable address, for the "Open pol-ler" link only. This
			// array is literal too: a key missing here reads back as absent even
			// when it was saved correctly.
			'ui_url' => rtrim(trim((string)($s['ui_url'] ?? $d['ui_url'])), '/'),
			// Off by default. When a person is added and no direct feed exists on
			// any site, fall back to building the pol-ler twin instead, so sites
			// that only exist in pol-ler still produce a feed.
			'fallback_twin' => !empty($s['fallback_twin']),
			// Where pol-ler twins are filed:
			//   'split'  - one "<Site> (pol-ler)" per site (the original layout)
			//   'single' - every twin in one shared category
			// Stored as a choice, not inferred, so switching back and forth is
			// deterministic. Nothing is ever deleted by changing it.
			'twin_layout' => ((string)($s['twin_layout'] ?? 'split') === 'single') ? 'single' : 'split',
			// Name of the shared category when 'single' is chosen.
			'twin_single_name' => trim((string)($s['twin_single_name'] ?? '')) !== ''
				? trim((string)$s['twin_single_name']) : self::SINGLE_CAT_DEFAULT,
		];
	}

	public function handleConfigureAction()
	{
		if (!Minz_Request::isPost()) { return; }

		$action = Minz_Request::paramString('act', true);

		if ($action === 'restore') {
			$this->restoreFromBin(Minz_Request::paramString('key', true));
			return;
		}
		if ($action === 'purge') {
			$this->saveAll($this->sites(), $this->settings(), []);
			Minz_Request::good('Deleted profiles purged.', $this->selfUrl());
			return;
		}
		if ($action === 'twinlayout') {
			// Save the choice, and only the choice. Switching a dropdown must not
			// move 370 feeds as a side effect — migrating is a separate, explicit
			// button with a preview.
			$cur = $this->settings();
			$want = Minz_Request::paramString('twin_layout', true) === 'single' ? 'single' : 'split';
			$name = trim(Minz_Request::paramString('twin_single_name', true));
			$cur['twin_layout'] = $want;
			if ($name !== '') { $cur['twin_single_name'] = $name; }
			$this->saveAll($this->sites(), $cur, $this->bin());
			Minz_Request::good(
				'Layout set to ' . ($want === 'single' ? 'one shared category' : 'one per site')
				. '. Nothing has moved yet — press the migrate button when you want the feeds moved.',
				$this->selfUrl()
			);
			return;
		}
		if ($action === 'twinmigrate') {
			$apply = Minz_Request::paramString('apply', true) === '1';
			$r = $this->migrateTwinLayout($apply);
			Minz_Request::good($r['message'], $this->selfUrl());
			return;
		}
		if ($action === 'run') {
			$line = $this->runNow(Minz_Request::paramString('key', true));
			Minz_Request::good('Ran now: ' . $line, $this->selfUrl());
			return;
		}

		// A Run-now POST also carries the site table (so the request is a valid
		// form). It must not be applied as a save: the user asked to run, not to
		// change configuration.

		$settings = $this->postedSettings();
		$prev = [];
		foreach ($this->sites() as $p) { $prev[$p['domain'] . '#' . $p['template']] = $p; }

		$sites = [];
		$seen = [];
		$errors = [];

		// A save with NO sites_json field at all is a malformed request, not a
		// request to delete everything. Treating "field missing" as "user removed
		// every row" is how a stray submit button once binned every profile.
		//
		// This also covers the case where the page's JS did not run (FreshRSS
		// injects the config view into the AJAX slider): the field arrives blank,
		// so we keep the existing profiles and say why, instead of erroring out
		// with nothing saved.
		$rawJson = Minz_Request::paramStringNull('sites_json', true);
		if ($rawJson === null || trim($rawJson) === '') {
			if ($prev === []) {
				Minz_Request::bad('Save failed: the form did not include the site list, so nothing was changed.', $this->selfUrl());
			} else {
				Minz_Request::bad('Save failed: the page script did not run, so the site list was empty. Nothing was changed.', $this->selfUrl());
			}
			return;
		}
		$decoded = json_decode($rawJson, true);
		if (!is_array($decoded) && $prev !== []) {
			Minz_Request::bad('Save failed: the site list could not be read, so nothing was changed.', $this->selfUrl());
			return;
		}
		if (is_array($decoded)) {
			foreach ($decoded as $row) {
				$n = self::normaliseSite($row);
				if ($n === null) { continue; }
				$key = $n['domain'] . '#' . $n['template'];
				$seen[$key] = true;

				if (isset($prev[$key])) {
					foreach (['twin_cat', 'direct_cat', 'direct_pool', 'last_run'] as $k) {
						$n[$k] = (int)$prev[$key][$k];
					}
					$n['last_summary'] = (string)$prev[$key]['last_summary'];
				}

				// Changing a profile's template stops the OLD twins being managed:
				// their pol-ler feeds and subscriptions stay, but nothing updates
				// them again. Say so rather than letting it happen silently.
				if (isset($prev[$key]) && (int)$prev[$key]['template'] !== $n['template']) {
					$orphans = $this->countTwinsFor((int)$prev[$key]['template']);
					if ($orphans > 0) {
						$errors[] = sprintf('%s — template changed from %d to %d, so %d existing twin(s) are no longer managed',
							$n['domain'], (int)$prev[$key]['template'], $n['template'], $orphans);
					}
				}

				// Validated here so a bad row never waits a cycle to fail.
				$v = $this->validateSite($n, $settings);
				$n['valid'] = $v['ok'];
				$n['valid_msg'] = $v['msg'];
				if (!$v['ok']) { $errors[] = $n['domain'] . ' — ' . $v['msg']; }
				$sites[] = $n;
			}
		}

		// a row the user removed goes to the bin, not oblivion
		$bin = $this->bin();
		foreach ($prev as $key => $p) {
			if (!isset($seen[$key])) { $bin[] = ['site' => $p, 'deleted' => time()]; }
		}

		$this->saveAll($sites, $settings, $bin);

		if ($errors !== []) {
			Minz_Request::bad(implode(' • ', array_slice($errors, 0, 3)), $this->selfUrl());
		} else {
			Minz_Request::good('Saved ' . count($sites) . ' site(s).', $this->selfUrl());
		}
	}

	/**
	 * Redirect target for feedback messages.
	 *
	 * The extension name MUST be nested under 'params'. Minz_Request::forward()
	 * only carries 'params' through the redirect; a top-level 'e' is dropped, so
	 * the redirect lands on c=extension&a=configure with no name and 404s.
	 */
	private function selfUrl(): array
	{
		return ['c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName()]];
	}

	/**
	 * Pause or resume one site. Wraps the 'disabled' flag the extension already
	 * honours, so the cron tick skips it and nothing else changes.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function setSiteDisabled(string $domain, bool $disabled): array
	{
		$sites = $this->sites();
		$hit = false;
		foreach ($sites as $i => $s) {
			if ($s['domain'] !== $domain) { continue; }
			$sites[$i]['disabled'] = $disabled;
			$hit = true;
			break;
		}
		if (!$hit) {
			return ['ok' => false, 'message' => 'No such site: ' . $domain];
		}
		$this->saveAll($sites, $this->settings(), $this->bin());
		return ['ok' => true, 'message' => ($disabled ? 'Paused ' : 'Resumed ') . $domain];
	}

	/**
	 * Patch one site's options from outside the settings page.
	 *
	 * Only the keys given are touched, so a control that changes one switch
	 * cannot silently reset the others — the reason this is not just "post the
	 * whole site row". Values are clamped exactly as normaliseSite() clamps
	 * them, so both entry points agree.
	 *
	 * @param array<string,mixed> $patch
	 * @return array{ok:bool,message:string}
	 */
	public function patchSite(string $domain, array $patch): array
	{
		$sites = $this->sites();
		$hit = false;
		$changed = [];
		foreach ($sites as $i => $s) {
			if ($s['domain'] !== $domain) { continue; }
			$hit = true;
			if (isset($patch['mode'])) {
				$mode = ((string)$patch['mode'] === 'poller') ? 'poller' : 'both';
				if ($mode !== $s['mode']) { $sites[$i]['mode'] = $mode; $changed[] = 'mode'; }
			}
			if (isset($patch['interval'])) {
				$iv = max(1, min(1440, (int)$patch['interval']));
				if ($iv !== (int)$s['interval']) { $sites[$i]['interval'] = $iv; $changed[] = 'every ' . $iv . 'm'; }
			}
			if (array_key_exists('watch', $patch)) {
				$w = !empty($patch['watch']);
				if ($w !== !empty($s['watch'])) { $sites[$i]['watch'] = $w; $changed[] = $w ? 'watch on' : 'watch off'; }
			}
			break;
		}
		if (!$hit) {
			return ['ok' => false, 'message' => 'No such site: ' . $domain];
		}
		if ($changed === []) {
			return ['ok' => true, 'message' => 'No change.'];
		}
		$this->saveAll($sites, $this->settings(), $this->bin());
		return ['ok' => true, 'message' => $domain . ': ' . implode(', ', $changed)];
	}

	/**
	 * Save the pol-ler connection settings from outside the settings page.
	 *
	 * @param array<string,mixed> $patch
	 * @return array{ok:bool,message:string}
	 */
	public function patchSettings(array $patch): array
	{
		$cur = $this->settings();
		$next = $cur;
		foreach (['pg_host', 'pg_db', 'pg_user', 'pg_pass', 'http_url', 'ui_url'] as $k) {
			if (array_key_exists($k, $patch)) { $next[$k] = (string)$patch[$k]; }
		}
		if (array_key_exists('pg_port', $patch)) {
			$next['pg_port'] = max(1, min(65535, (int)$patch['pg_port']));
		}
		if (array_key_exists('fallback_twin', $patch)) {
			$next['fallback_twin'] = !empty($patch['fallback_twin']);
		}
		$next['http_url'] = rtrim($next['http_url'], '/');
		$next['ui_url'] = rtrim(trim((string)($next['ui_url'] ?? '')), '/');
		if ($next['ui_url'] !== '' && !preg_match('~^https?://[^\s]+$~i', $next['ui_url'])) {
			$next['ui_url'] = '';
		}

		// Do not save a connection that does not work: the next cron tick would
		// fail silently and the site would just look idle.
		$prevSettings = $cur;
		$this->saveAll($this->sites(), $next, $this->bin());
		if ($this->pg($next) === null) {
			$this->saveAll($this->sites(), $prevSettings, $this->bin());
			return ['ok' => false, 'message' => 'Could not connect with those settings — put back what was there.'];
		}
		return ['ok' => true, 'message' => 'Connection settings saved and tested.'];
	}

	/**
	 * Profiles in the bin (removed sites kept for a while, so a Remove is
	 * recoverable).
	 *
	 * @return array<int,array{key:string,domain:string,template:int,deleted:int}>
	 */
	public function deletedSites(): array
	{
		$out = [];
		foreach ($this->bin() as $b) {
			$s = $b['site'] ?? [];
			if (!isset($s['domain'])) { continue; }
			$out[] = [
				'key' => (string)$s['domain'] . '#' . (int)($s['template'] ?? 0),
				'domain' => (string)$s['domain'],
				'template' => (int)($s['template'] ?? 0),
				'deleted' => (int)($b['deleted'] ?? 0),
			];
		}
		return $out;
	}

	/**
	 * The category a feed added for this site should be filed in.
	 *
	 * runSite() has always created "<Site> (pol-ler)" and "<Site> (direct)" and
	 * filed the 720 bulk twins there. Feeds added BY NAME went to Uncategorized
	 * instead, because the add path used a hardcoded TARGET_CATEGORY — so those
	 * categories existed but sat empty for every name added by hand.
	 *
	 * $kind is 'poller' for a scraped twin, 'direct' for a real site feed.
	 * Returns 0 when the site has no profile, so the caller can fall back.
	 */
	public function targetCategoryFor(string $domain, string $kind = 'direct'): int
	{
		$sites = $this->sites();
		$site = null;
		foreach ($sites as $s) {
			if ($s['domain'] === $domain) { $site = $s; break; }
		}
		if ($site === null) { return 0; }

		$label = self::siteLabel($domain);
		$dao = FreshRSS_Factory::createCategoryDao();
		// With the one-category layout, every twin — whatever the site — belongs
		// in the shared category, so a newly added person lands where the
		// migrated ones already are.
		if ($kind === 'poller') {
			$shared = $this->twinCategoryId($domain, $dao);
			if ($shared > 0) { return $shared; }
		}
		$want = $kind === 'poller' ? $label . ' (pol-ler)' : $label . ' (direct)';

		$id = $this->categoryFor($dao, $want);
		if ($id > 0) { return $id; }

		// No site profile naming this host yet: fall back to whatever the
		// profile already recorded, so an unexpected domain still lands sensibly.
		return (int)($kind === 'poller' ? ($site['twin_cat'] ?? 0) : ($site['direct_cat'] ?? 0));
	}

	/**
	 * The category every pol-ler twin belongs in, given the current layout.
	 *
	 * 'split' needs the site (one category each); 'single' ignores it. Returning
	 * 0 means "caller should use its per-site twin_cat as before" — which keeps
	 * old installs working untouched until the layout is deliberately changed.
	 */
	public function twinCategoryId(string $domain, FreshRSS_CategoryDAO $catDAO): int
	{
		$st = $this->settings();
		if ($st['twin_layout'] !== 'single') { return 0; }
		return $this->categoryFor($catDAO, (string)$st['twin_single_name']);
	}

	/**
	 * Where twins should live right now. Returns [categoryId, label].
	 *
	 * Used by the migration and by the add path so the stored layout and the
	 * actual feed rows cannot drift apart.
	 *
	 * @return array{id:int,name:string}
	 */
	public function twinTarget(string $domain): array
	{
		$st = $this->settings();
		$catDAO = FreshRSS_Factory::createCategoryDao();
		if ($st['twin_layout'] === 'single') {
			$name = (string)$st['twin_single_name'];
			return ['id' => $this->categoryFor($catDAO, $name), 'name' => $name];
		}
		$name = self::siteLabel($domain) . ' (pol-ler)';
		return ['id' => $this->categoryFor($catDAO, $name), 'name' => $name];
	}

	/**
	 * Migrate every managed twin to the current layout. Reversible in both
	 * directions, and never deletes a feed or a category.
	 *
	 * "Managed twin" means a feed whose URL points at pol-ler AND whose current
	 * category is one of this extension's pol-ler categories. Feeds people filed
	 * elsewhere by hand (Uncategorized, or their own folders) are left alone: moving
	 * them would silently re-file things the extension never put there.
	 *
	 * @return array{ok:bool,message:string,moved:int,skipped:int,detail:string}
	 */
	public function migrateTwinLayout(bool $apply = false): array
	{
		$st = $this->settings();
		$mode = $st['twin_layout'];
		$catDAO = FreshRSS_Factory::createCategoryDao();
		$feedDAO = FreshRSS_Factory::createFeedDao();

		// Recognise this extension's pol-ler categories: "<anything> (pol-ler)"
		// plus the shared name.
		$pollerCatIds = [];
		$singleName = (string)$st['twin_single_name'];
		foreach ($catDAO->listCategories(false, false) as $c) {
			$n = (string)$c->name();
			if (preg_match('~\(pol-ler\)$~i', $n) || strcasecmp($n, $singleName) === 0) {
				$pollerCatIds[(int)$c->id()] = $n;
			}
		}

		$sites = $this->sites();
		$moves = [];
		$skipped = 0;
		foreach ($feedDAO->selectAll() as $f) {
			$id = (int)($f['id'] ?? 0);
			$url = (string)($f['url'] ?? '');
			$cat = (int)($f['category'] ?? 0);
			if ($id <= 0 || !preg_match('~pol-ler(?::\d+)?/feed/(\d+)~', $url)) { continue; }
			if (!isset($pollerCatIds[$cat])) { $skipped++; continue; }

			// Which site does this twin belong to? Match its pol-ler feed id back
			// to a configured site's template, else fall back to the name prefix.
			$target = null;
			if ($mode === 'single') {
				$target = $this->twinTarget('');
			} else {
				$host = $this->hostForTwinUrl($url, $sites);
				if ($host === '') { $skipped++; continue; }
				$target = $this->twinTarget($host);
			}
			if ($target['id'] <= 0) { $skipped++; continue; }
			if ($target['id'] === $cat) { continue; }
			$moves[] = ['id' => $id, 'name' => (string)($f['name'] ?? ''), 'from' => $cat, 'to' => $target['id'], 'toName' => $target['name']];
		}

		$byTo = [];
		foreach ($moves as $m) { $byTo[$m['toName']] = ($byTo[$m['toName']] ?? 0) + 1; }
		$detail = [];
		foreach ($byTo as $n => $c) { $detail[] = $c . ' → ' . $n; }

		$summary = sprintf(
			'%s: %d twin(s) would move%s%s',
			$mode === 'single' ? 'single category' : 'per-site split',
			count($moves),
			$detail !== [] ? ' (' . implode(', ', $detail) . ')' : '',
			$skipped > 0 ? ' · ' . $skipped . ' non-twin feed(s) left alone' : ''
		);

		if (!$apply || $moves === []) {
			return ['ok' => true, 'message' => $summary . ($apply ? '' : ' — preview only'),
				'moved' => 0, 'skipped' => $skipped, 'detail' => implode('; ', $detail)];
		}

		$done = 0;
		foreach ($moves as $m) {
			if ($feedDAO->updateFeed((int)$m['id'], ['category' => (int)$m['to']])) { $done++; }
		}

		// Record the per-site twin_cat so runSite() agrees with the rows.
		$sitesOut = $sites;
		foreach ($sitesOut as $i => $site) {
			$t = $this->twinTarget((string)$site['domain']);
			$sitesOut[$i]['twin_cat'] = (int)$t['id'];
		}
		$this->saveAll($sitesOut, $st, $this->bin());

		return ['ok' => true,
			'message' => sprintf('%d twin(s) moved · %s', $done, implode(', ', $detail)),
			'moved' => $done, 'skipped' => $skipped, 'detail' => implode('; ', $detail)];
	}

	/** Which configured site a pol-ler twin URL belongs to, from its name. */
	private function hostForTwinUrl(string $url, array $sites): string
	{
		$m = new ReflectionMethod($this, 'pg');
		$m->setAccessible(true);
		$pg = $m->invoke($this);
		if ($pg !== null && preg_match('~pol-ler(?::\d+)?/feed/(\d+)~', $url, $idm)) {
			try {
				$st = $pg->prepare('SELECT uri FROM frontend_feed WHERE id = ?');
				$st->execute([(int)$idm[1]]);
				$page = (string)$st->fetchColumn();
				$host = strtolower((string)parse_url($page, PHP_URL_HOST));
				$host = (string)preg_replace('~^www\.~', '', $host);
				foreach ($sites as $s) {
					if (strcasecmp((string)$s['domain'], $host) === 0) { return (string)$s['domain']; }
				}
			} catch (Throwable $e) { /* fall through */ }
		}
		return '';
	}

	/**
	 * The page a pol-ler template scrapes, e.g. for feed 24 ->
	 * https://example.com/people/some-person/
	 *
	 * frontend_feed.uri holds exactly what was pasted into pol-ler when the
	 * template was made, so the operator never has to retype it: give the feed
	 * number and the address comes back. Returns null if the feed is missing.
	 *
	 * @return array{ok:bool,uri:string,domain:string,fields:int,message:string}
	 */
	public function pageForTemplate(string $template): array
	{
		$tpl = self::parseTemplateId($template);
		if ($tpl <= 0) {
			return ['ok' => false, 'uri' => '', 'domain' => '', 'fields' => 0,
				'message' => 'That does not look like a pol-ler feed number.'];
		}
		if ($this->pg() === null) {
			return ['ok' => false, 'uri' => '', 'domain' => '', 'fields' => 0,
				'message' => 'Cannot reach the pol-ler database.'];
		}
		$cfg = $this->templateConfig($tpl);
		if ($cfg === null) {
			return ['ok' => false, 'uri' => '', 'domain' => '', 'fields' => 0,
				'message' => 'pol-ler has no feed ' . $tpl . '.'];
		}
		$uri = (string)$cfg['uri'];
		return [
			'ok' => true,
			'uri' => $uri,
			'domain' => self::cleanDomain($uri),
			'fields' => count($cfg['fields']),
			'message' => 'Feed ' . $tpl . ' scrapes ' . $uri,
		];
	}

	/**
	 * Add OR update one site profile from the inputs a human actually has.
	 *
	 * Only the template number is required. The page address and the domain
	 * both come from pol-ler itself: frontend_feed.uri holds the page the
	 * template was built from, so asking the operator to paste it again is just
	 * an invitation to paste it wrong. Pass $pageUrlOrDomain when you have a
	 * page pol-ler does NOT know about (a new person page for an existing site).
	 *
	 * Passing an existing domain updates that profile in place instead of
	 * refusing a duplicate, so this one call is both "add" and "edit".
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function addSiteProfile(string $pageUrlOrDomain, string $template, array $extra = []): array
	{
		$input = trim($pageUrlOrDomain);
		$pageUrl = preg_match('~^https?://~i', $input) ? $input : '';
		$domain = self::cleanDomain($input);

		if ($this->pg() === null) {
			return ['ok' => false, 'message' => 'Cannot reach the pol-ler database, so the template cannot be checked.'];
		}

		// No page given: take it from the template itself. This is the normal
		// path now — one field in, everything else derived.
		if ($pageUrl === '' && $domain === '') {
			$lookup = $this->pageForTemplate($template);
			if (!$lookup['ok']) {
				return ['ok' => false, 'message' => $lookup['message']];
			}
			$pageUrl = $lookup['uri'];
			$domain = $lookup['domain'];
		}

		$site = self::normaliseSite(array_merge($extra, [
			'domain' => $domain,
			'template' => $template,
		]));
		if ($site === null) {
			return ['ok' => false, 'message' => 'Need a pol-ler feed number, e.g. 401.'];
		}

		// Check the template against its own scrape URL.
		$v = $this->validateSite($site);
		if (!$v['ok']) {
			return ['ok' => false, 'message' => 'Not saved — ' . $v['msg']];
		}

		// If a page URL was given, confirm it names the same person page the
		// template scrapes. A template built from one person filed against a
		// different person still "validates" by host, and then clones the wrong
		// shape for everyone.
		$warn = '';
		if ($pageUrl !== '') {
			$tpl = $this->templateConfig((int)$site['template']);
			$tplUri = (string)($tpl['uri'] ?? '');
			$samePath = rtrim((string)parse_url($tplUri, PHP_URL_PATH), '/')
				=== rtrim((string)parse_url($pageUrl, PHP_URL_PATH), '/');
			if (!$samePath) {
				$warn = ' Note: the template scrapes ' . $tplUri . ', not the page you pasted.';
			}
		}

		$sites = $this->sites();
		$updated = false;
		foreach ($sites as $i => $existing) {
			if ($existing['domain'] !== $site['domain']) { continue; }
			// Same domain: update in place. Keep the runtime counters and any
			// per-domain settings the caller did not pass.
			foreach (['twin_cat', 'direct_cat', 'direct_pool', 'last_run', 'last_summary', 'interval', 'disabled', 'watch', 'mode'] as $k) {
				if (!array_key_exists($k, $extra) && isset($existing[$k])) { $site[$k] = $existing[$k]; }
			}
			$sites[$i] = $site;
			$updated = true;
			break;
		}
		$site['valid'] = true;
		$site['valid_msg'] = (string)$v['msg'];
		if ($updated) {
			foreach ($sites as $i => $s) {
				if ($s['domain'] === $site['domain']) { $sites[$i] = $site; }
			}
		} else {
			$sites[] = $site;
		}
		$this->saveAll($sites, $this->settings(), $this->bin());

		return [
			'ok' => true,
			'message' => ($updated ? 'Updated ' : 'Added ') . $site['domain']
				. ' with template ' . $site['template'] . ' — ' . $v['msg'] . $warn,
		];
	}

	/**
	 * Drop one profile from the list (feeds already cloned from it are left
	 * alone — pol-ler feeds are never deleted from here).
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function removeSiteProfile(string $domain): array
	{
		$domain = self::cleanDomain($domain);
		$sites = $this->sites();
		$kept = [];
		$hit = null;
		foreach ($sites as $s) {
			if ($s['domain'] === $domain) { $hit = $s; continue; }
			$kept[] = $s;
		}
		if ($hit === null) { return ['ok' => false, 'message' => 'No profile for ' . $domain . '.']; }
		$this->saveAll($kept, $this->settings(), $this->bin());
		return ['ok' => true, 'message' => 'Removed ' . $domain . ' — its pol-ler feeds were left alone.'];
	}

	private function saveAll(array $sites, array $settings, array $bin): void
	{
		$this->setUserConfiguration([
			'sites' => json_encode(array_values($sites), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			'settings' => json_encode($settings, JSON_UNESCAPED_SLASHES),
			// trimBin() enforces the 10-day window, so an old entry cannot survive
			// just because nobody opened this page
			'bin' => json_encode(self::trimBin($bin), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		]);
	}

	private function postedSettings(): array
	{
		$cur = $this->settings();
		$p = fn(string $k) => Minz_Request::paramStringNull($k, true);
		return [
			'pg_host' => trim((string)($p('pg_host') ?? $cur['pg_host'])),
			'pg_port' => (int)($p('pg_port') ?? $cur['pg_port']),
			'pg_db' => trim((string)($p('pg_db') ?? $cur['pg_db'])),
			'pg_user' => trim((string)($p('pg_user') ?? $cur['pg_user'])),
			'pg_pass' => (string)($p('pg_pass') ?? $cur['pg_pass']),
			'http_url' => rtrim(trim((string)($p('http_url') ?? $cur['http_url'])), '/'),
			// Browser address for the "Open pol-ler" link. Validated as http(s) so
			// a stray string cannot end up in an href. This array is literal, not
			// built from DEFAULTS — a key missing here is silently dropped on save,
			// which is exactly what happened to this field.
			'ui_url' => (function () use ($p, $cur) {
				$v = rtrim(trim((string)($p('ui_url') ?? ($cur['ui_url'] ?? ''))), '/');
				return preg_match('~^https?://[^\s]+$~i', $v) ? $v : '';
			})(),
		];
	}

	/** @return array<int,array<string,mixed>> */
	public function sites(): array
	{
		$raw = $this->getUserConfigurationValue('sites', '[]');
		if (is_array($raw)) { return $raw; }
		$arr = json_decode((string)$raw, true);
		return is_array($arr) ? $arr : [];
	}

	// ------------------------------------------------------------ the bin

	/** @return array<int,array{site:array,deleted:int}> */
	public function bin(): array
	{
		$raw = $this->getUserConfigurationValue('bin', '[]');
		$arr = is_array($raw) ? $raw : json_decode((string)$raw, true);
		return self::trimBin(is_array($arr) ? $arr : []);
	}

	private static function trimBin(array $bin): array
	{
		$cutoff = time() - self::BIN_DAYS * 86400;
		$out = [];
		foreach ($bin as $e) {
			if (!isset($e['site']['domain'], $e['site']['template'], $e['deleted'])) { continue; }
			if ((int)$e['deleted'] < $cutoff) { continue; }
			$out[] = $e;
		}
		return array_slice($out, -50);
	}

	private function restoreFromBin(string $key): void
	{
		$sites = $this->sites();
		$bin = $this->bin();
		foreach ($bin as $i => $e) {
			$k = $e['site']['domain'] . '#' . $e['site']['template'];
			if ($k !== $key) { continue; }
			foreach ($sites as $s) {
				if ($s['domain'] . '#' . $s['template'] === $key) {
					Minz_Request::good('That site is already configured.', $this->selfUrl());
					return;
				}
			}
			unset($bin[$i]);
			$sites[] = $e['site'];
			$this->saveAll($sites, $this->settings(), array_values($bin));
			Minz_Request::good('Restored ' . $k . '.', $this->selfUrl());
			return;
		}
		Minz_Request::bad('That profile is no longer in the bin.', $this->selfUrl());
	}

	// -------------------------------------------------------------- pol-ler

	private function pg(?array $settings = null): ?PDO
	{
		$s = $settings ?? $this->settings();
		$key = $s['pg_host'] . ':' . $s['pg_port'] . '/' . $s['pg_db'] . '#' . $s['pg_user'];
		if (self::$pdo !== null && self::$pdoKey === $key) { return self::$pdo; }

		// An explicit password is authoritative. When it is blank we try the
		// pol-ler default from its own docker-compose, but nothing site-specific
		// is baked into this extension — a blank setting on another install
		// reports "cannot reach the database" until it is filled in.
		$tries = $s['pg_pass'] !== '' ? [$s['pg_pass']] : ['polpassword'];

		foreach ($tries as $pass) {
			try {
				self::$pdo = new PDO(
					sprintf('pgsql:host=%s;port=%d;dbname=%s', $s['pg_host'], $s['pg_port'], $s['pg_db']),
					$s['pg_user'],
					$pass,
					[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
				);
				self::$pdoKey = $key;
				return self::$pdo;
			} catch (Throwable $e) {
				self::$pdo = null;
			}
		}
		return null;
	}

	/**
	 * Health of the pol-ler connection, layer by layer.
	 *
	 * Reported separately on purpose, because the three failures look identical
	 * from the outside — "it doesn't work" — but have completely different
	 * causes and fixes:
	 *
	 *   http : the web UI is up and answering (catches: container not running,
	 *          wrong port, not on the shared network so the name does not resolve)
	 *   db   : PostgreSQL is reachable with these credentials (catches: wrong
	 *          password, wrong db/user, database container stopped)
	 *   feed : an actual feed URL returns XML with items (catches: wrong schema,
	 *          pol-ler running but the scraper never populated)
	 *
	 * A single "connected / not connected" flag hides all of that, and the first
	 * failure a new install hits is the network one — which no setting can fix.
	 *
	 * @return array{http: array, db: array, feed: array, ok: bool}
	 */
	public function health(?int $feedId = null): array
	{
		$s = $this->settings();
		$out = [
			'http' => ['ok' => false, 'detail' => ''],
			'db'   => ['ok' => false, 'detail' => ''],
			'feed' => ['ok' => false, 'detail' => ''],
		];

		// --- layer 1: can we reach pol-ler's web server at all?
		$url = rtrim((string)$s['http_url'], '/');
		$host = parse_url($url, PHP_URL_HOST);
		$port = parse_url($url, PHP_URL_PORT) ?: 80;

		$probe = $url . '/health';

		// curl, NOT file_get_contents. FreshRSS unregisters the http:// stream
		// wrapper (stream_get_wrappers() returns only "php,file"), so any URL
		// fetch through file_get_contents fails with the misleading
		// "Failed to open stream: No such file or directory" — which reads like
		// a missing file, not a blocked protocol. The same call works in a bare
		// PHP script, which made this look like a pol-ler problem for a while.
		$code = 0;
		$body = false;
		$err = '';

		if (function_exists('curl_init')) {
			$ch = curl_init($probe);
			curl_setopt_array($ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT => 5,
				CURLOPT_CONNECTTIMEOUT => 4,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_USERAGENT => 'FreshRSS-pol-ler-Sites',
			]);
			$body = curl_exec($ch);
			$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$err = (string)curl_error($ch);
			curl_close($ch);
		}

		if ($body !== false && $code > 0 && $code < 500) {
			// pol-ler's own Docker healthcheck is unreliable — it shells out to
			// `curl`, which is not in the image, so the container shows
			// "unhealthy" forever while the service answers perfectly. Do not
			// inherit that verdict; judge by the response.
			$out['http'] = ['ok' => true, 'detail' => "HTTP $code from $probe"];
		} elseif ($code > 0) {
			$out['http'] = ['ok' => false, 'detail' => "HTTP $code from $probe — pol-ler is answering but not healthy"];
		} else {
			// Distinguish "name does not resolve" from "nothing listening": the
			// first is the shared-network mistake, the second a stopped container.
			$ip = $host !== null ? @gethostbyname($host) : '';
			$resolves = $ip !== '' && $ip !== $host;

			$out['http'] = [
				'ok' => false,
				'detail' => $resolves
					? "Cannot connect to $url — nothing is listening on $host:$port" . ($err !== '' ? " ($err)" : '')
					: "Cannot resolve \"$host\" — pol-ler is not on this container's Docker network",
			];
		}

		// --- layer 2: is the database usable with these credentials?
		$pdo = $this->pg();
		if ($pdo === null) {
			$out['db'] = ['ok' => false, 'detail' => sprintf(
				'Cannot open PostgreSQL at %s:%d/%s as %s. Either db-pol-ler is not running, or the password is wrong — it is whatever POL_LER_DB_PASSWORD was set to when pol-ler was started (polpassword if it was not set at all).',
				$s['pg_host'], $s['pg_port'], $s['pg_db'], $s['pg_user']
			)];
		} else {
			try {
				$n = (int)$pdo->query('SELECT count(*) FROM frontend_feed')->fetchColumn();
				$out['db'] = ['ok' => true, 'detail' => "Connected — $n feed(s) in the pol-ler database"];
			} catch (Throwable $e) {
				$out['db'] = ['ok' => false, 'detail' => 'Connected, but cannot read the feeds table: ' . $e->getMessage()];
			}
		}

		// --- layer 3: does a real feed URL produce items?
		$fid = $feedId ?? 0;
		if ($fid <= 0) {
			// Fall back to whatever template the configured sites point at.
			foreach ($this->sites() as $site) {
				$cand = (int)($site['template'] ?? 0);
				if ($cand > 0) { $fid = $cand; break; }
			}
		}
		if ($fid <= 0) {
			$out['feed'] = ['ok' => true, 'detail' => 'No template feed configured yet — nothing to test'];
		} else {
			$n = $this->probeFeed($fid);
			$out['feed'] = $n > 0
				? ['ok' => true, 'detail' => "Feed $fid returns $n item(s)"]
				: ['ok' => false, 'detail' => "Feed $fid returned no items — the template may be scraping nothing"];
		}

		$out['ok'] = $out['http']['ok'] && $out['db']['ok'] && $out['feed']['ok'];
		return $out;
	}

	/** Accept "65", "http://pol-ler:8088/feed/65", "/feed/65?sanitize=Y". */
	public static function parseTemplateId(string $raw): int
	{
		$raw = trim($raw);
		if ($raw === '') { return 0; }
		if (ctype_digit($raw)) { return (int)$raw; }
		if (preg_match('~/feed/(\d+)~', $raw, $m)) { return (int)$m[1]; }
		if (preg_match('~(\d+)\s*$~', $raw, $m)) { return (int)$m[1]; }
		return 0;
	}

	/**
	 * Render a stored template id back as a full URL for the input box.
	 *
	 * The box is a paste target, so it always shows the complete /feed/<id> URL
	 * rather than a bare number — the number alone is easy to misread and gives
	 * no hint about which feed it belongs to.
	 */
	public function templateDisplay(int $id): string
	{
		if ($id <= 0) { return ''; }
		return $this->settings()['http_url'] . '/feed/' . $id;
	}

	/** @return array{uri:string,xpath:string,fields:array<int,string>}|null */
	private function templateConfig(int $id, ?array $settings = null): ?array
	{
		$db = $this->pg($settings);
		if ($db === null) { return null; }
		$st = $db->prepare('SELECT uri, xpath FROM frontend_feed WHERE id = ?');
		$st->execute([$id]);
		$feed = $st->fetch(PDO::FETCH_ASSOC);
		if ($feed === false) { return null; }
		$st = $db->prepare('SELECT field_id, xpath FROM frontend_feedfield WHERE feed_id = ? ORDER BY field_id');
		$st->execute([$id]);
		$fields = [];
		foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$fields[(int)$r['field_id']] = (string)$r['xpath'];
		}
		if ($fields === []) { return null; }
		return ['uri' => (string)$feed['uri'], 'xpath' => (string)$feed['xpath'], 'fields' => $fields];
	}

	private function validateSite(array $site, ?array $settings = null): array
	{
		if ($this->pg($settings) === null) {
			return ['ok' => false, 'msg' => 'cannot reach the pol-ler database (check the connection settings)'];
		}
		$tpl = $this->templateConfig((int)$site['template'], $settings);
		if ($tpl === null) {
			return ['ok' => false, 'msg' => 'pol-ler feed ' . $site['template'] . ' does not exist, or has no field mappings'];
		}
		$host = strtolower((string)parse_url($tpl['uri'], PHP_URL_HOST));
		$host = (string)preg_replace('~^www\.~', '', $host);
		if ($host !== (string)$site['domain']) {
			return ['ok' => false, 'msg' => 'template ' . $site['template'] . ' scrapes ' . $host . ', not ' . $site['domain']];
		}
		return ['ok' => true, 'msg' => count($tpl['fields']) . ' field(s), scrapes ' . $host];
	}

	private function findFeedFor(string $uri, string $xpath): ?int
	{
		$db = $this->pg();
		if ($db === null) { return null; }
		$st = $db->prepare('SELECT id FROM frontend_feed WHERE uri = ? AND xpath = ? ORDER BY id DESC LIMIT 1');
		$st->execute([$uri, $xpath]);
		$id = $st->fetchColumn();
		return $id === false ? null : (int)$id;
	}

	private function createFeed(string $uri, string $xpath, string $name): ?int
	{
		$db = $this->pg();
		if ($db === null) { return null; }
		$st = $db->prepare('INSERT INTO frontend_feed (uri, xpath, created, edited, name) VALUES (?, ?, now(), false, ?) RETURNING id');
		$st->execute([$uri, $xpath, $name]);
		$id = $st->fetchColumn();
		return $id === false ? null : (int)$id;
	}

	private function ensureFields(int $feedId, array $fields): void
	{
		$db = $this->pg();
		if ($db === null) { return; }
		$st = $db->prepare('SELECT field_id, xpath FROM frontend_feedfield WHERE feed_id = ?');
		$st->execute([$feedId]);
		$have = [];
		foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$have[(int)$r['field_id']] = (string)$r['xpath'];
		}
		foreach ($fields as $fid => $xp) {
			if (!array_key_exists($fid, $have)) {
				$db->prepare('INSERT INTO frontend_feedfield (xpath, feed_id, field_id) VALUES (?, ?, ?)')->execute([$xp, $feedId, $fid]);
			} elseif ($have[$fid] !== $xp) {
				$db->prepare('UPDATE frontend_feedfield SET xpath = ? WHERE feed_id = ? AND field_id = ?')->execute([$xp, $feedId, $fid]);
			}
		}
	}

	private function fieldCount(int $feedId): int
	{
		$db = $this->pg();
		if ($db === null) { return 0; }
		$st = $db->prepare('SELECT count(*) FROM frontend_feedfield WHERE feed_id = ?');
		$st->execute([$feedId]);
		return (int)$st->fetchColumn();
	}

	/**
	 * How many subscribed twins were cloned from this template?
	 *
	 * Twins are identified by the scrape URL sharing the template's host and
	 * xpath — the same test findFeedFor() uses, applied in bulk.
	 */
	public function countTwinsFor(int $templateId): int
	{
		$tpl = $this->templateConfig($templateId);
		if ($tpl === null) { return 0; }
		$db = $this->pg();
		if ($db === null) { return 0; }
		$st = $db->prepare('SELECT id FROM frontend_feed WHERE xpath = ? AND uri <> ? AND uri LIKE ?');
		$hostPart = '%' . (string)parse_url($tpl['uri'], PHP_URL_HOST) . '%';
		$st->execute([$tpl['xpath'], $tpl['uri'], $hostPart]);
		$ids = [];
		foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $ids[(int)$r['id']] = true; }

		$feedDAO = FreshRSS_Factory::createFeedDao();
		$n = 0;
		foreach ($feedDAO->selectAll() as $f) {
			$u = (string)($f['url'] ?? '');
			if (!preg_match('~pol-ler(?::\d+)?/feed/(\d+)~', $u, $m)) { continue; }
			if (isset($ids[(int)$m[1]])) { $n++; }
		}
		return $n;
	}

	/**
	 * Items this pol-ler feed currently renders. pol-ler builds the feed on
	 * request, so this also primes it. -1 means the check itself could not run.
	 */
	private function probeFeed(int $pid): int
	{
		if (!function_exists('curl_init')) { return -1; }
		$c = curl_init($this->twinUrl($pid));
		curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 5]);
		$body = curl_exec($c);
		$code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
		curl_close($c);
		if ($body === false || $code !== 200) { return -1; }
		return substr_count((string)$body, '<item>') + substr_count((string)$body, '<entry>');
	}

	/** The URL stored in FreshRSS for a twin. Host comes from settings. */
	private function twinUrl(int $pid): string
	{
		return $this->settings()['http_url'] . '/feed/' . $pid . '?sanitize=Y';
	}

	// ------------------------------------------------------------ slug rule

	/**
	 * Build the scrape URL for a new feed from the template's own URL.
	 *
	 * Rather than swapping "the segment at the same index", this keeps the
	 * LONGEST SHARED PREFIX of both paths and swaps everything after it. That
	 * works for shallow paths (/tag/x/) and nested ones
	 * (/category/section/subsection/) with one rule, instead of
	 * depending on the template and the feed having identical depth.
	 */
	public function scrapeUrlFor(string $templateUri, string $directUrl): ?string
	{
		$tPath = (string)parse_url($templateUri, PHP_URL_PATH);
		$tSegs = self::splitPath($tPath);
		if ($tSegs === []) { return null; }

		$dSegs = self::pathSegments($directUrl);
		if ($dSegs === []) { return null; }

		// shared prefix, compared case-insensitively (sites are not consistent)
		$shared = 0;
		$max = min(count($tSegs), count($dSegs));
		while ($shared < $max && strtolower($tSegs[$shared]) === strtolower($dSegs[$shared])) {
			$shared++;
		}

		// nothing in common means this feed is not the same shape as the template;
		// guessing would produce a URL that scrapes an unrelated page
		if ($shared === 0) { return null; }

		// identical paths: the feed IS the template page
		if ($shared === count($tSegs) && $shared === count($dSegs)) { return $templateUri; }

		$newSegs = array_merge(
			array_slice($tSegs, 0, $shared),
			array_slice($dSegs, $shared)
		);
		$parts = parse_url($templateUri);
		$path = '/' . implode('/', $newSegs) . (str_ends_with($tPath, '/') || $newSegs === [] ? '/' : '');
		$url = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $path;
		if (isset($parts['query'])) { $url .= '?' . $parts['query']; }
		return $url;
	}

	/** Split a path into segments, keeping empty strings out. */
	private static function splitPath(string $path): array
	{
		return array_values(array_filter(explode('/', $path), fn($s) => $s !== ''));
	}

	/** URL path segments, trailing feed/rss marker removed. */
	private static function pathSegments(string $url): array
	{
		$path = rtrim((string)parse_url($url, PHP_URL_PATH), '/');
		$segs = array_values(array_filter(explode('/', $path), fn($s) => $s !== ''));
		while ($segs !== [] && in_array(strtolower((string)end($segs)), ['feed', 'rss', 'atom', 'rss.xml', 'index.xml', 'feed.xml'], true)) {
			array_pop($segs);
		}
		return $segs;
	}

	/**
	 * Twin display name: the direct feed's name with its site suffix removed.
	 * Falls back to the slug when the name says nothing about the person.
	 */
	public function twinName(string $directName, string $slug, string $domain = ''): string
	{
		$n = trim($directName);
		$slugName = ucwords(str_replace(['-', '_'], ' ', $slug));
		if ($n === '') { return $slugName; }

		$n = (string)preg_replace('~\s*[–—|]\s*.*$~u', '', $n);
		$n = (string)preg_replace('~\s+-\s+.*$~u', '', $n);
		$n = (string)preg_replace('~\s*\(pol-ler\)\s*$~i', '', $n);
		$n = (string)preg_replace('~^Comments for\s+~i', '', trim($n));
		$n = trim($n);

		// a name that is only the site's own name says nothing about which
		// person the feed is for, so the slug is more useful
		$norm = static fn(string $s): string => (string)preg_replace('~[^a-z0-9]~', '', strtolower($s));
		$siteName = $norm((string)preg_replace('~\.[a-z]{2,}$~i', '', $domain));
		if ($n === '' || preg_match('~^pol-ler feed \d+$~i', $n)
			|| ($siteName !== '' && str_starts_with($norm($n), $siteName))) {
			return $slugName;
		}
		return $n;
	}

	/**
	 * Create the pol-ler twin for ONE person on a site that has no direct RSS.
	 *
	 * This is the niche case the fallback toggle exists for: a site whose only
	 * working feed is pol-ler (the site publishes a comment feed and nothing
	 * else). Normally a twin is cloned by runSite() while scanning an index;
	 * here we clone for a single person the caller already knows about.
	 *
	 * Honours the 'fallback_twin' setting: when it is off, this does nothing and
	 * says so, so the default behaviour of the extension is unchanged.
	 *
	 * @return array{ok:bool,message:string,url:string}
	 */
	public function makeTwinFor(string $domain, string $slug): array
	{
		$settings = $this->settings();
		if (empty($settings['fallback_twin'])) {
			return ['ok' => false, 'url' => '',
				'message' => 'pol-ler fallback is off (turn it on in Options)'];
		}

		$site = null;
		foreach ($this->sites() as $s) {
			if ($s['domain'] === $domain) { $site = $s; break; }
		}
		if ($site === null) {
			return ['ok' => false, 'url' => '', 'message' => 'No pol-ler site profile for ' . $domain];
		}

		$tpl = $this->templateConfig((int)$site['template']);
		if ($tpl === null) {
			return ['ok' => false, 'url' => '', 'message' => 'Template ' . $site['template'] . ' unreadable'];
		}

		$slug = trim($slug);
		if ($slug === '') {
			return ['ok' => false, 'url' => '', 'message' => 'No slug given'];
		}

		// Build the person's page from the template's own page, swapping the
		// person segment. scrapeUrlFor() already does this without guessing:
		// it refuses when the two paths share no prefix.
		$directUrl = 'https://' . $domain . '/' . $slug . '/';
		$page = $this->scrapeUrlFor((string)$tpl['uri'], $directUrl);
		if ($page === null) {
			// The guessed page shares nothing with the template, which is normal
			// for sites with a deeper path (/section/<name>/). Fall back to
			// swapping the segment in the template that matches the slug shape.
			$page = $this->swapPersonSegment((string)$tpl['uri'], $slug);
		}
		if ($page === null) {
			return ['ok' => false, 'url' => '',
				'message' => 'Could not work out the page for ' . $slug . ' on ' . $domain];
		}

		// Does the page actually exist? A person whose name differs on this site
		// (hyphenated vs not, initial vs full) produces a 404, and pol-ler would
		// happily build a scraper for it that returns nothing forever.
		$chk = @get_headers($page, true);
		$status = 0;
		if (is_array($chk) && isset($chk[0]) && preg_match('~\s(\d{3})\s~', (string)$chk[0], $m)) {
			$status = (int)$m[1];
		}
		if ($status >= 400) {
			return ['ok' => false, 'url' => '',
				'message' => 'No page for ' . $slug . ' on ' . $domain . ' (HTTP ' . $status . ')'];
		}

		// Reuse an existing feed for this page instead of creating a duplicate.
		// Without this, adding the same person twice builds a second scraper feed
		// hammering the same URL, which is exactly what pol-ler's own UI prevents.
		//
		// This also covers the template's OWN person: when the slug matches the
		// page the template was built from, $page IS the template URI, so the
		// template feed (feed 401) is reused rather than cloned. That
		// person otherwise ends up with no feed on this site at all, because
		// they only ever existed as the template row.
		$existing = $this->existingPollerFeed($page);
		if ($existing > 0) {
			// Includes the template's own person: their page IS the template
			// page, so the template feed itself is handed back to
			// be subscribed like any other. They are usually the first person
			// you want, and previously got skipped entirely.
			return ['ok' => true, 'url' => $settings['http_url'] . '/feed/' . $existing,
				'message' => 'feed ' . $existing . ' already existed for ' . $slug,
				'existing' => true];
		}

		try {
			$id = $this->createPollerFeed($page, $tpl, self::displayFromSlug($slug));
		} catch (Throwable $e) {
			return ['ok' => false, 'url' => '', 'message' => 'pol-ler refused: ' . $e->getMessage()];
		}
		if ($id <= 0) {
			return ['ok' => false, 'url' => '', 'message' => 'pol-ler returned no feed id'];
		}

		$url = $settings['http_url'] . '/feed/' . $id;
		return ['ok' => true, 'url' => $url,
			'message' => 'built pol-ler feed ' . $id . ' for ' . $slug];
	}

	/**
	 * Replace the person-looking segment of a template page with a new slug.
	 *
	 * pol-ler templates store one real person's page, so the person is the
	 * segment that is not a fixed word. Picks the LAST segment that looks like a
	 * name (contains a dash or is alphabetic and not a known path word), which
	 * is what /people/some-person/ and /tag/some-person/ both need.
	 */
	private function swapPersonSegment(string $templateUri, string $slug): ?string
	{
		$parts = parse_url($templateUri);
		if (!is_array($parts) || empty($parts['host'])) { return null; }
		$path = (string)($parts['path'] ?? '/');
		$segs = array_values(array_filter(explode('/', $path), fn($x) => $x !== ''));
		if ($segs === []) { return null; }

		$fixed = ['celebrities', 'celebrity', 'tag', 'tags', 'category', 'categories',
			'person', 'people', 'star', 'stars', 'profile', 'profiles', 'news', 'feed'];
		$idx = null;
		for ($i = count($segs) - 1; $i >= 0; $i--) {
			$seg = strtolower((string)$segs[$i]);
			if (in_array($seg, $fixed, true)) { continue; }
			if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $seg)) { continue; }
			$idx = $i;
			break;
		}
		if ($idx === null) { return null; }

		$segs[$idx] = $slug;
		$out = ($parts['scheme'] ?? 'https') . '://' . $parts['host']
			. '/' . implode('/', $segs) . (str_ends_with($path, '/') ? '/' : '');
		if (isset($parts['query'])) { $out .= '?' . $parts['query']; }
		return $out;
	}

	/** "sophie-duker" -> "Sophie Duker", matching how twins are named elsewhere. */
	private static function displayFromSlug(string $slug): string
	{
		$n = ucwords(str_replace(['-', '_'], ' ', trim($slug)));
		return trim((string)preg_replace('~\s+~', ' ', $n));
	}

	/** Feed id already scraping this exact page, or 0. */
	private function existingPollerFeed(string $uri): int
	{
		$db = $this->pg();
		if ($db === null) { return 0; }
		$st = $db->prepare('SELECT id FROM frontend_feed WHERE uri = ? ORDER BY id LIMIT 1');
		$st->execute([$uri]);
		return (int)$st->fetchColumn();
	}

	/**
	 * Insert a feed row into pol-ler's own database, cloning a template.
	 *
	 * Done directly against Postgres because that is where pol-ler keeps feeds;
	 * its web API is not required and may not be reachable from here.
	 *
	 * @return int new feed id, 0 on failure
	 */
	private function createPollerFeed(string $uri, array $tpl, string $name = ''): int
	{
		$db = $this->pg();
		if ($db === null) { throw new RuntimeException('pol-ler database unreachable'); }

		// Set the name too. runSite() names its clones, but this path did not, so
		// every feed built by the no-RSS fallback sat nameless in pol-ler's UI
		// while the other two sites' feeds were all labelled.
		$st = $db->prepare('INSERT INTO frontend_feed (uri, xpath, name, created, edited)
			VALUES (?, ?, ?, NOW(), false) RETURNING id');
		$st->execute([$uri, (string)$tpl['xpath'], $name]);
		$id = (int)$st->fetchColumn();
		if ($id <= 0) { return 0; }

		// Clone the field xpaths so the new feed extracts the same things.
		$ins = $db->prepare('INSERT INTO frontend_feedfield (feed_id, field_id, xpath) VALUES (?, ?, ?)');
		foreach ($tpl['fields'] as $fieldId => $xp) {
			$ins->execute([$id, (int)$fieldId, (string)$xp]);
		}
		return $id;
	}

	/**
	 * Human label for a domain: "example.com" -> "Example".
	 *
	 * Category names are the only stable handle across runs, so this must never
	 * vary. Only the first letter is touched; categoryFor() prefers an existing
	 * category over the computed name.
	 */
	private static function siteLabel(string $domain): string
	{
		$base = (string)preg_replace('~\.[a-z]{2,}$~i', '', $domain);
		$base = str_replace(['.', '_'], ' ', $base);
		$base = (string)preg_replace('~\s+~', ' ', $base);
		return ucfirst(trim($base));
	}

	/**
	 * Resolve a category by name, tolerating a case/punctuation difference from a
	 * category the user already has. Creating a near-duplicate would move every
	 * feed into a second category and look like data loss.
	 */
	private function categoryFor(FreshRSS_CategoryDAO $dao, string $want, bool $create = true): int
	{
		$cat = $dao->searchByName($want);
		if ($cat !== null) { return (int)$cat->id(); }

		$norm = static fn(string $s): string => (string)preg_replace('~[^a-z0-9]+~', '', strtolower($s));
		$target = $norm($want);
		foreach ($dao->listCategories(false, false) as $c) {
			if ($norm($c->name()) === $target) { return (int)$c->id(); }
		}

		if (!$create) { return 0; }
		$id = $dao->addCategory(['name' => $want]);
		return $id === false ? 0 : (int)$id;
	}

	// ----------------------------------------------------------------- run

	public function isDue(array $site): bool
	{
		if (!empty($site['disabled'])) { return false; }
		if (array_key_exists('valid', $site) && $site['valid'] === false) { return false; }
		$last = (int)($site['last_run'] ?? 0);
		$interval = max(1, (int)($site['interval'] ?? 15)) * 60;
		return (time() - $last) >= $interval;
	}

	/** @return array{ok:bool,summary:string} */
	public function runSite(array &$site): array
	{
		$started = microtime(true);
		$domain = (string)$site['domain'];
		$tplId = (int)$site['template'];

		$v = $this->validateSite($site);
		if (!$v['ok']) { return ['ok' => false, 'summary' => $v['msg']]; }
		$tpl = $this->templateConfig($tplId);
		if ($tpl === null) { return ['ok' => false, 'summary' => "template $tplId unreadable"]; }

		$feedDAO = FreshRSS_Factory::createFeedDao();
		$catDAO = FreshRSS_Factory::createCategoryDao();

		$label = self::siteLabel($domain);
		$sharedTwin = $this->twinCategoryId($domain, $catDAO);
		if ($sharedTwin > 0) {
			$site['twin_cat'] = $sharedTwin;
		} elseif ((int)$site['twin_cat'] <= 0) {
			$site['twin_cat'] = $this->categoryFor($catDAO, $label . ' (pol-ler)');
		}
		if ($site['mode'] === 'poller') {
			if ((int)$site['direct_pool'] <= 0) {
				$site['direct_pool'] = $this->categoryFor($catDAO, self::POOL_NAME);
			}
		} elseif ((int)$site['direct_cat'] <= 0) {
			$site['direct_cat'] = $this->categoryFor($catDAO, $label . ' (direct)');
		}

		$all = [];
		foreach ($feedDAO->selectAll() as $f) { $all[] = $f; }

		// Index twins by pol-ler feed id: older subscriptions were made without
		// ?sanitize=Y, so an exact-URL match would create duplicate subscriptions.
		//
		// The template feed is excluded ONLY when it is not already sitting in this
		// site's twin category. A template subscribed into the twin category IS
		// that profile's twin for its own page (the template feed is one person
		// Qualley and belongs in cat 11) — excluding it unconditionally created a
		// duplicate subscription on the same URL.
		$twinByPid = [];
		foreach ($all as $f) {
			$u = (string)($f['url'] ?? '');
			if (preg_match('~pol-ler(?::\d+)?/feed/(\d+)~', $u, $m)) {
				$pid = (int)$m[1];
				if ($pid === (int)$site['template'] && (int)($f['category'] ?? 0) !== (int)$site['twin_cat']) {
					continue;
				}
				$twinByPid[$pid] = $f;
			}
		}

		$scanned = 0; $created = 0; $existing = 0; $subscribed = 0; $moved = 0; $failed = 0; $renamed = 0;
		$skipped = 0; $skipList = [];
		$errors = [];

		foreach ($all as $f) {
			if ((microtime(true) - $started) > self::RUN_BUDGET) {
				$errors[] = 'time budget reached, rest deferred';
				break;
			}
			$url = (string)($f['url'] ?? '');
			if ($url === '' || str_contains($url, 'pol-ler')) { continue; }
			$host = strtolower((string)parse_url($url, PHP_URL_HOST));
			if ($host === '') { continue; }
			if (preg_replace('~^www\.~', '', $host) !== $domain) { continue; }

			$scrape = $this->scrapeUrlFor($tpl['uri'], $url);
			if ($scrape === null) {
				// the feed is on this domain but its path does not share a prefix
				// with the template, so we cannot derive a scrape URL. Say so
				// instead of silently skipping it.
				$skipped++;
				$skipList[] = $url;
				continue;
			}
			$scanned++;

			$segs = self::pathSegments($url);
			$slug = (string)(end($segs) ?: '');
			$name = $this->twinName((string)($f['name'] ?? ''), $slug, $domain);

			// The DIRECT feed's own name is often just the site's name, which tells
			// you nothing about the person. Give it a name
			// derived from its own slug so the two categories are distinguishable.
			$directName = trim((string)($f['name'] ?? ''));
			$directSlugName = ucwords(str_replace(['-', '_'], ' ', $slug));
			$normN = static fn(string $s): string => (string)preg_replace('~[^a-z0-9]~', '', strtolower($s));
			$siteNorm = $normN((string)preg_replace('~\.[a-z]{2,}$~i', '', $domain));
			$needsRename = $directName === ''
				|| preg_match('~^pol-ler feed \d+$~i', $directName)
				|| ($siteNorm !== '' && str_starts_with($normN($directName), $siteNorm));
			if ($needsRename && $directSlugName !== '' && $directName !== $directSlugName) {
				$feedDAO->updateFeed((int)$f['id'], ['name' => $directSlugName]);
				$f['name'] = $directSlugName;
				$renamed++;
			}

			$pid = $this->findFeedFor($scrape, $tpl['xpath']);
			$isNew = false;
			if ($pid === null) {
				$pid = $this->createFeed($scrape, $tpl['xpath'], $name);
				if ($pid === null) { $failed++; $errors[] = "pol-ler create failed for $slug"; continue; }
				$created++;
				$isNew = true;
			} else {
				$existing++;
			}
			// a feed missing field rows renders 0 items forever — always verify
			$this->ensureFields($pid, $tpl['fields']);
			if ($this->fieldCount($pid) < count($tpl['fields'])) {
				$failed++;
				$errors[] = "pol-ler $pid has an incomplete field set";
				continue;
			}

			$twin = $twinByPid[$pid] ?? null;
			$twinId = 0;
			if ($twin !== null) {
				// An existing twin is corrected in place: name, category, AND url.
				// A twin subscribed by hand as ".../feed/69" works, but without
				// ?sanitize=Y it emits the raw scrape instead of the cleaned body,
				// and it never gets renamed later. Fixing the url here makes a
				// hand-added twin behave exactly like one the extension created.
				$wantUrl = $this->twinUrl($pid);
				$fix = [];
				if ((string)($twin['name'] ?? '') !== $name) { $fix['name'] = $name; }
				if ((int)($twin['category'] ?? 0) !== (int)$site['twin_cat']) { $fix['category'] = (int)$site['twin_cat']; }
				if ((string)($twin['url'] ?? '') !== $wantUrl) { $fix['url'] = $wantUrl; }
				if ($fix !== []) { $feedDAO->updateFeed((int)$twin['id'], $fix); }
				$twinId = (int)$twin['id'];
			} else {
				$added = $feedDAO->addFeed([
					'url' => $this->twinUrl($pid),
					'kind' => 0,
					'category' => (int)$site['twin_cat'],
					'name' => $name,
					'website' => 'https://' . $domain . '/',
					'description' => 'pol-ler scrape of ' . $scrape,
					'lastUpdate' => 0,
					'error' => 0,
				]);
				if ($added !== false && $added > 0) {
					$twinId = (int)$added;
					$subscribed++;
					$twinByPid[$pid] = ['id' => $twinId, 'name' => $name, 'category' => (int)$site['twin_cat']];
				} else {
					$failed++;
					$errors[] = "subscribe failed for $slug";
					continue;
				}
			}

			// only file the direct feed once its twin exists, so a failure never
			// leaves an empty slot and no readable original
			$want = ($site['mode'] === 'poller') ? (int)$site['direct_pool'] : (int)$site['direct_cat'];
			if ($want > 0 && (int)($f['category'] ?? 0) !== $want && $twinId > 0) {
				$feedDAO->updateFeed((int)$f['id'], ['category' => $want]);
				$moved++;
			}

			// a mismatched selector returns HTTP 200 with 0 items forever. Probing
			// on create costs a full live scrape, so it only happens when the user
			// asked for health checks on this site (see verify()).
			if ($isNew && !empty($site['watch'])) {
				if ($this->probeFeed($pid) === 0) {
					$errors[] = "pol-ler $pid renders 0 items — selector does not match this page";
				}
			}
		}

		// optional: does one existing twin still render? One request per site per
		// run, only for sites where the user ticked "watch". Catches a template
		// whose selectors broke, which otherwise shows up only as stale feeds.
		if (!empty($site['watch']) && $twinByPid !== [] && (microtime(true) - $started) < self::RUN_BUDGET) {
			$probeIds = array_slice(array_keys($twinByPid), 0, 1);
			foreach ($probeIds as $probeId) {
				$items = $this->probeFeed($probeId);
				if ($items === 0) {
					$errors[] = "pol-ler $probeId renders 0 items — template selectors may have broken";
				}
			}
		}

		$summary = sprintf('feeds=%d created=%d existing=%d new=%d moved=%d renamed=%d failed=%d',
			$scanned, $created, $existing, $subscribed, $moved, $renamed, $failed);
		if ($skipped > 0) {
			$summary .= sprintf(' | skipped=%d (no shared path with template: %s)',
				$skipped, (string)parse_url($skipList[0], PHP_URL_PATH));
		}
		if ($errors !== []) { $summary .= ' | ' . implode('; ', array_slice($errors, 0, 3)); }
		return ['ok' => $failed === 0, 'summary' => $summary];
	}

	/** Force one site to run now, regardless of interval. Returns the summary. */
	public function runNow(string $key): string
	{
		$sites = $this->sites();
		foreach ($sites as $i => $s) {
			if ($s['domain'] . '#' . $s['template'] !== $key) { continue; }
			try {
				$res = $this->runSite($s);
				$line = $res['summary'];
			} catch (Throwable $e) {
				$line = 'error: ' . $e->getMessage();
				Minz_Log::warning('pol-ler Sites [run now ' . $key . ']: ' . $e->getMessage());
			}
			$sites[$i] = $s;
			$sites[$i]['last_run'] = time();
			$sites[$i]['last_summary'] = $line;
			$this->saveAll($sites, $this->settings(), $this->bin());
			Minz_Log::notice('pol-ler Sites [run now ' . $key . ']: ' . $line);
			return $line;
		}
		return 'site not found';
	}

	/** Hook: runs alongside FreshRSS's own refresh, so nothing extra is needed. */
	public function tick(): void
	{
		if (!FreshRSS_Auth::hasAccess() && PHP_SAPI !== 'cli') { return; }
		$sites = $this->sites();
		if ($sites === []) { return; }

		$due = [];
		foreach ($sites as $i => $s) {
			if ($this->isDue($s)) { $due[] = $i; }
		}
		if ($due === []) { return; }
		if (!$this->acquireLock()) { return; }

		$deadline = microtime(true) + self::HOOK_BUDGET;
		try {
			foreach ($due as $i) {
				// runs during a page load or CLI refresh: stop between sites rather
				// than stalling the request
				if (microtime(true) > $deadline) {
					Minz_Log::notice('pol-ler Sites: budget reached, remaining sites deferred');
					break;
				}
				$site = $sites[$i];
				try {
					$res = $this->runSite($site);
					$line = $res['summary'];
				} catch (Throwable $e) {
					$line = 'error: ' . $e->getMessage();
					Minz_Log::warning('pol-ler Sites [' . $site['domain'] . ']: ' . $e->getMessage());
				}
				$site['last_run'] = time();
				$site['last_summary'] = $line;
				$sites[$i] = $site;
				Minz_Log::notice('pol-ler Sites [' . $site['domain'] . ']: ' . $line);
				$this->saveAll($sites, $this->settings(), $this->bin());
			}
		} finally {
			$this->releaseLock();
		}
	}

	private function acquireLock(): bool
	{
		$path = sys_get_temp_dir() . '/poller-sites.lock';
		if (file_exists($path) && (time() - (int)@filemtime($path)) < self::LOCK_SECONDS) { return false; }
		@file_put_contents($path, (string)time());
		return true;
	}

	private function releaseLock(): void
	{
		@unlink(sys_get_temp_dir() . '/poller-sites.lock');
	}

	private static function normaliseSite($s): ?array
	{
		if (!is_array($s)) { return null; }
		$domain = self::cleanDomain((string)($s['domain'] ?? ''));
		$tpl = self::parseTemplateId((string)($s['template'] ?? ''));
		if ($domain === '' || $tpl <= 0) { return null; }
		return [
			'domain' => $domain,
			'template' => $tpl,
			'mode' => (($s['mode'] ?? 'both') === 'poller') ? 'poller' : 'both',
			'interval' => max(1, min(1440, (int)($s['interval'] ?? 15))),
			'disabled' => !empty($s['disabled']),
			'watch' => !empty($s['watch']),
			'twin_cat' => (int)($s['twin_cat'] ?? 0),
			'direct_cat' => (int)($s['direct_cat'] ?? 0),
			'direct_pool' => (int)($s['direct_pool'] ?? 0),
			'last_run' => (int)($s['last_run'] ?? 0),
			'last_summary' => (string)($s['last_summary'] ?? ''),
			'valid' => $s['valid'] ?? null,
			'valid_msg' => (string)($s['valid_msg'] ?? ''),
		];
	}

	/** Reduce a user-entered domain to a bare host. */
	public static function cleanDomain(string $d): string
	{
		$d = trim($d);
		$d = (string)preg_replace('~^https?://~i', '', $d);
		$d = (string)preg_replace('~/.*$~', '', $d);
		$d = (string)preg_replace('~^www\.~i', '', $d);
		return strtolower(rtrim($d, '/'));
	}
}
