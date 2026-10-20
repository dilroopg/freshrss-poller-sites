# pol-ler Sites

Part of [hardlysleeping's FreshRSS extensions](https://github.com/hardlysleeping/freshrss-extensions) — see the hub for everything else.

A FreshRSS extension that scrapes sites which publish **no RSS at all**, by
driving a self-hosted [pol-ler](https://github.com/aldotroiano/pol-ler) instance
from inside FreshRSS.

Some sites you want to follow have no feed. pol-ler builds one by scraping the
page; this extension is the bridge — so you add a section of that site **by
address** instead of building and maintaining a separate scraper for every
single one.

```
   you: news.example/entertainment
         │
         ▼
   pol-ler Sites ── clones your template ──▶ pol-ler ── scrapes ──▶ RSS feed
         │                                                              │
         └──────────────── subscribes in FreshRSS ◀────────────────────┘

   (your template was built once, for news.example/world)
```

**Standalone.** Nothing else has to be installed, no other extension is
required, and nothing here calls one.

> This repo contains the extension only. **pol-ler itself is separate software**
> with its own repository — it is not bundled here.

## What it does

- **Per-site profiles.** Teach it a site once — a URL pattern and one template
  feed — then add any path on that site with a single click.
- **Twin cloning.** Each new feed is created by cloning your template's scraping
  rules, so you never write XPath by hand more than once.
- **Populate.** Walks your existing FreshRSS subscriptions and builds the
  matching pol-ler feeds.
- **Honest checks.** It verifies a page exists before building a scraper for it,
  and it reports pol-ler's health layer by layer — web server, database, and
  template feed — because those three fail for completely different reasons.
- **One category or many.** File every scraped feed under one shared category,
  or give each site its own. Reversible either way.
- **Reversible moves.** Every layout change is journalled before it touches a
  row, so **Undo the last move** puts every feed back exactly where it was —
  including categories it had to delete on the way, which are recreated.
- **Hidden state follows the group.** A feed filed into a hidden category is
  hidden; filed into a visible one, it is visible. Enforced on every run, not
  only when a feed moves, so a feed added later by the cron still inherits it.
- **Hand-filed categories are left alone.** Only the shared twin category and
  the `<Site> (pol-ler)` folders are managed. Anything else — uncategorised
  feeds, per-person folders, your own topic folders — is never moved into.
- **No duplicate subscriptions.** Feeds are matched on their pol-ler id, and a
  URL check immediately before subscribing means a re-run adopts the existing
  row instead of adding a second one.

## Install

```bash
cd /path/to/FreshRSS/extensions
git clone https://github.com/hardlysleeping/freshrss-poller-sites xExtension-PollerSites
```

Then **Extensions → enable pol-ler Sites**. That is the whole install — this
repository *is* the extension directory, so there is no second step and nothing
to copy anywhere else.

pol-ler itself is separate software and must be running where FreshRSS can reach
it by name. See the setup below.

---

## Requirements

- FreshRSS (developed against 1.28–1.30)
- Docker, same host as FreshRSS
- pol-ler running, reachable by name from inside the FreshRSS container

## Install

```bash
cd /path/to/FreshRSS/extensions
git clone https://github.com/hardlysleeping/freshrss-poller-sites _tmp
mv _tmp/xExtension-PollerSites . && rm -rf _tmp
```

Then **Extensions** → enable **pol-ler Sites**.

No web-root shims and no standalone pages — this extension adds a settings page
and a backend the rest of FreshRSS calls.

## About the optional pol-ler fork

The feed link pol-ler shows you is built from your browser's address bar. That
works on its own, but a feed reader fetches the URL **server-side from inside its
container**, where your browser's address may not resolve — so pasting that link
into FreshRSS can give you a dead feed.

There is a small fork of pol-ler that fixes this with one environment variable
(`FEED_URL_BASE`), so the displayed link is already the internal
`http://pol-ler:8088` a reader needs:

- Fork: <https://github.com/hardlysleeping/pol-ler>

**It is optional, and it is not maintained.** Two other ways to get the same
result, both lower-maintenance:

- Use upstream pol-ler and **correct the hostname once** when you add the feed to
  FreshRSS — every feed works, you just fix the address.
- Run pol-ler on an address **both** your browser and the container can reach
  (your host's LAN IP). Then the browser-derived link is already right.

Nothing in this extension requires the fork.

## 1. Start pol-ler

```bash
git clone https://github.com/aldotroiano/pol-ler
cd pol-ler
docker compose -f /path/to/xExtension-PollerSites/docker-compose.pol-ler.yml up -d
```

Use **the compose file shipped with this extension**, not upstream's. Upstream's
puts pol-ler on `pol-ler-network` alone; this one also joins the FreshRSS
network, which is what lets the containers find each other by name.

Creates two containers — `pol-ler` and `db-pol-ler` — on the bridge network
`pol-ler-network`.

The defaults in this extension (`pg_host=db-pol-ler`, `pg_db=politepol`,
`pg_user=poluser`, `http_url=http://pol-ler:8088`) match that setup. If you changed
them in pol-ler's compose, change them here too — they are all editable fields.

Set the database password rather than accepting the literal default:

```bash
POL_LER_DB_PASSWORD='something-long' docker compose -f docker-compose.pol-ler.yml up -d
```

## 2. Put FreshRSS on the same network

The shipped compose file handles this. If you started pol-ler some other way,
this is the step that gets missed, and it fails silently: the container names do
not resolve, every call to pol-ler fails, and features do nothing while the
settings page looks perfectly correct.

One-off:

```bash
docker network connect pol-ler-network freshrss
```

Declarative (survives container re-creation) — in FreshRSS's own compose file:

```yaml
services:
  freshrss:
    networks:
      - default
      - pol-ler-network

networks:
  pol-ler-network:
    external: true
```

Check which network FreshRSS is actually on:

```bash
docker inspect freshrss --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}'
```

If it is called something other than `freshrss_default`, pass it when starting
pol-ler:

```bash
FRESHRSS_NETWORK=name_from_above docker compose -f docker-compose.pol-ler.yml up -d
```

## 3. Verify before configuring anything

These must print an address, not "no such host":

```bash
docker exec freshrss getent hosts pol-ler
docker exec freshrss getent hosts db-pol-ler
```

Then open `http://<your-host>:8088` — pol-ler's own UI. If that does not load, the
network is still the problem; no setting in the extension will fix it.

## 4. Configure the extension

FreshRSS → Extensions → **pol-ler Sites** → the connection block. Fill in host, port,
database, user, password, and the web address. Save.

If your pol-ler Postgres password is not `polpassword`, set it here — a blank
password field means "use pol-ler's compose default".

## 5. Build one template by hand, then add paths

The extension does not invent scraping rules. **You make the first feed in
pol-ler yourself**, and that feed becomes the template everything else is cloned
from. The order is always:

1. **Open pol-ler** and build a feed for one page on the site you care about
   (a section, tag or listing page). You choose the XPath fields — for example,
   build a feed for `news.example/world`.
2. **Check it returns items.** A template that scrapes nothing produces nothing,
   however many paths you add afterwards.
3. **Note its number** — the `/feed/<N>` part of the address.
4. **Add the site here** using that number. The domain is derived; you do not
   type it.
5. **Add paths.** Each one clones the template. From a `news.example/world`
   template you can add `news.example/business` or `news.example/sport` and get
   a working scraped feed for each, with no extra XPath work.

### The "Open pol-ler" link

To make step 1 one click away, fill in **Browser address** in the connection
block. That is *your* address for pol-ler's UI — the one you open in a browser,
such as `http://192.168.1.10:8088` or `https://mynas.example:8444`.

It is deliberately separate from **Web address**, which is the Docker-internal
name (`http://pol-ler:8088`) the FreshRSS container uses. A browser cannot
resolve that name, so the two cannot be the same setting.

Leave **Browser address** blank if you do not want the link — nothing else
depends on it.

Every feed added for that domain afterwards gets a pol-ler twin cloned exactly
from the template.

## What this extension writes

- FreshRSS feeds (subscriptions) — the twins it creates
- FreshRSS categories — `<Site> (pol-ler)` and `<Site> (direct)`
- pol-ler's own database — one feed row plus its field rows per added path

It never deletes a pol-ler feed, subscription or category except through its own
Remove / Delete buttons, or through a layout move — and a move is always
recorded in the undo journal first.

## Layout moves and undo

The twin layout is chosen on the extension's own settings page, or from the
**Cross site counterpart** page if you have that extension installed (it gathers
settings from other extensions in one place, and calls these same methods).

| Control | What it does |
|---|---|
| **Layout** | *One category for all people* — every twin in a single shared category. *One per site* — twins filed into `<Site> (pol-ler)`. |
| **Preview** | Reports how many feeds would move, and where. Changes nothing. |
| **Move now** | Applies the layout. Writes the undo journal first. |
| **Undo last move** | Puts every feed back where it was, recreates any category the move deleted, and restores the previous layout. |

The journal lives in `data/users/<user>/extensions/CrossSite/state.sqlite`
(`moves`, `feed_before`, `cat_before`) and is checked by the stack backup script,
so an undo survives a restore from backup.

Moving into a shared category deletes the person folders it empties — an undo
recreates them. Empty `<Site> (pol-ler)` folders are kept, because the split
direction files into them by name.

## Protected categories

A category is only ever moved into or out of when it is the shared twin category
or one of the `<Site> (pol-ler)` folders. Everything else is treated as
hand-filed and is never touched: uncategorised feeds, `<Site> (direct)` folders,
and any topic or person folder you made yourself. New categories are protected
automatically for the same reason — the rule lists what is managed, not what is
exempt.

## Sites with no RSS

Some sites publish no feed for a given path at all (the feed URL is an HTML page).
For those, the **Make on fail** toggle builds the feed from the site's pol-ler
template instead of dropping that path. It is off by default and only ever
applies to sites that are registered here *and* whose direct feed genuinely
returns no items.

> ### ⚠️ `docker ps` will show pol-ler as "unhealthy" — this is expected, and it is fine
>
> pol-ler's Docker healthcheck runs `curl` inside its own container. The image
> does not include `curl`, so the check fails every time and Docker marks the
> container **unhealthy** forever — while the service itself answers perfectly
> normally.
>
> **Do not chase this.** If `curl -fsS http://localhost:8088/health` prints
> `OK` from your host, pol-ler is working. A permanently "unhealthy" status is
> **not** a problem with your install and **not** a problem with this extension.
>
> This extension's own health panel checks the actual HTTP response instead of
> trusting Docker's status, so it will show pol-ler as working.

## Troubleshooting

| Symptom | Cause |
|---|---|
| Settings look right, nothing happens | Not on the shared network — check step 3 |
| `no such host` in logs | Same as above |
| Site saves but `Populate` finds 0 | The site has no direct feeds to mirror (expected for no-RSS sites) |
| Path added, no feed appears | Its slug differs on that site — check the site's own URL and copy the exact path |
| ⚠️ `docker ps` says pol-ler is "unhealthy" | **Expected.** Its healthcheck needs `curl`, which is not in the image. Ignore it if `/health` returns `OK` |

## Development setup

Tested against the upstream pol-ler docker-compose on PostgreSQL 16. If the `discover`
step behaves oddly, `docker logs` shows the full trace.

```bash
docker compose -f docker-compose.pol-ler.yml logs -f pol-ler
```

## Licence

MIT.

## Support

[![Donate with PayPal](https://www.paypalobjects.com/en_US/i/btn/btn_donateCC_LG.gif)](https://www.paypal.com/donate/?hosted_button_id=RNT5W4XNC64EY)
