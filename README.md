# pol-ler Sites

A FreshRSS extension that scrapes sites which publish **no RSS at all**, by
driving a self-hosted [pol-ler](https://github.com/aldotroiano/pol-ler) instance
from inside FreshRSS.

Some sites you want to follow have no feed. pol-ler builds one by scraping the
page; this extension is the bridge — so you add a person or a page **by name**
instead of building and maintaining a separate scraper for every single one.

```
   you: "news.example/entertainment"
         │
         ▼
   pol-ler Sites ── clones your template ──▶ pol-ler ── scrapes ──▶ RSS feed
         │                                                              │
         └──────────────── subscribes in FreshRSS ◀────────────────────┘
```

**Standalone.** Nothing else has to be installed, no other extension is
required, and nothing here calls one.

> This repo contains the extension only. **pol-ler itself is separate software**
> with its own repository — it is not bundled here.

## What it does

- **Per-site profiles.** Teach it a site once — a URL pattern and one template
  feed — then add any name on that site with a single click.
- **Twin cloning.** Each person's feed is created by cloning your template's
  scraping rules, so you never write XPath by hand more than once.
- **Populate.** Walks your existing FreshRSS subscriptions and builds the
  matching pol-ler feeds.
- **Honest checks.** It verifies a page exists before building a scraper for it,
  and it reports pol-ler's health layer by layer — web server, database, and
  template feed — because those three fail for completely different reasons.
- **One category or many.** File every scraped feed under one shared category,
  or give each site its own. Reversible either way.

## Quick start

```bash
# 1. the extension
cd /path/to/FreshRSS/extensions
git clone https://github.com/dilroopg/freshrss-poller-sites _tmp
mv _tmp/xExtension-PollerSites . && rm -rf _tmp
```

Then **Extensions → enable pol-ler Sites**. Nothing else needs copying — no
files go into the web root.

pol-ler itself must be running where the FreshRSS container can reach it by
name. The compose file in this repo does that wiring for you:

```bash
git clone https://github.com/aldotroiano/pol-ler
cd pol-ler
docker compose -f /path/to/xExtension-PollerSites/docker-compose.pol-ler.yml up -d
```

Full setup, including the shared-Docker-network step that most often goes wrong:
**[`xExtension-PollerSites/README.md`](xExtension-PollerSites/README.md)**

## The workflow in one line

Build **one** feed by hand in pol-ler → note its `/feed/<N>` → add the site here
with that number → every name you add afterwards is a clone of it.

## Requirements

- FreshRSS (developed against 1.28–1.30)
- Docker, on the same host as FreshRSS
- A running pol-ler reachable by name from inside the FreshRSS container

## ⚠️ One thing that is not a bug

`docker ps` will show pol-ler as **unhealthy**, forever. Its healthcheck runs
`curl`, which is not in pol-ler's image. If `curl -fsS http://localhost:8088/health`
prints `OK`, pol-ler is fine — ignore the status. This extension checks the real
HTTP response rather than trusting Docker's verdict.

## Licence

MIT.
