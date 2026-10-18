# pol-ler Sites — a FreshRSS extension

Scrape sites that publish **no RSS at all**, by driving a self-hosted
[pol-ler](https://github.com/aldotroiano/pol-ler) instance from inside FreshRSS.

Some sites you want to follow have no feed. pol-ler builds one by scraping the
page; this extension is the bridge, so you can add a person or a page by name
instead of building a scraper by hand for each one.

**This extension is standalone.** Nothing else has to be installed, no other
extension is required, and nothing here calls one. Install it, enable it, done.

This extension does **not** include pol-ler. It drives an instance you run
yourself (upstream: <https://github.com/aldotroiano/pol-ler>, MIT).

## Requirements

- FreshRSS (developed against 1.28–1.30)
- Docker, same host as FreshRSS
- pol-ler running, reachable by name from inside the FreshRSS container

## Install

```bash
cd /path/to/FreshRSS/extensions
git clone https://github.com/dilroopg/freshrss-poller-sites _tmp
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

- Fork: <https://github.com/dilroopg/pol-ler>

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

## 5. Build one template by hand, then add names

The extension does not invent scraping rules. **You make the first feed in
pol-ler yourself**, and that feed becomes the template everything else is cloned
from. The order is always:

1. **Open pol-ler** and build a feed for one person on the site you care about
   (their category, tag or profile page). You choose the XPath fields.
2. **Check it returns items.** A template that scrapes nothing produces nothing,
   however many names you add afterwards.
3. **Note its number** — the `/feed/<N>` part of the address.
4. **Add the site here** using that number. The domain is derived; you do not
   type it.
5. **Add names.** Each one clones the template.

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
- pol-ler's own database — one feed row plus its field rows per person

It never deletes a pol-ler feed, subscription or category except through its own
Remove / Delete buttons.

## Sites with no RSS

Some sites publish no per-person feed at all (their feed URL is an HTML page). For
those, the **Make on fail** toggle builds the feed from the site's pol-ler template
instead of dropping the person. It is off by default and only ever applies to sites
that are registered here *and* whose direct feed genuinely returns no items.

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
| Person added, no feed appears | Their slug differs on that site — check the site's own URL for them |
| ⚠️ `docker ps` says pol-ler is "unhealthy" | **Expected.** Its healthcheck needs `curl`, which is not in the image. Ignore it if `/health` returns `OK` |

## Development setup

Tested against the upstream pol-ler docker-compose on PostgreSQL 16. If the `discover`
step behaves oddly, `docker logs` shows the full trace.

```bash
docker compose -f docker-compose.pol-ler.yml logs -f pol-ler
```

## Licence

MIT.
