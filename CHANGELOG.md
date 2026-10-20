# Changelog

All notable changes to this extension. Versions follow the `version` field in
`metadata.json`.

## 1.1.0

### Added

- **Undo last move.** Every layout move writes a journal before touching a row:
  the previous layout, each feed's previous category and priority, and every
  category the move deletes. Undo replays it in reverse, recreating deleted
  categories *before* moving feeds back into them.
- **Preview** reports the move without performing it.
- Twin layout is now reachable from the **Cross site counterpart** settings page
  as well as this extension's own page. Cross site forwards to these same
  methods; there is no second implementation.
- The move journal is covered by the stack backup script, which asserts the
  moves table exists and is non-empty.

### Fixed

- **Undo could not move feeds back.** Categories were recreated after the feed
  moves, so the foreign key rejected every update and the feeds stayed put.
  Categories are now recreated first.
- **Hand-filed categories were swept into the shared folder.** The protection
  rule was a list of exempt names, so every category not on it was fair game —
  a pol-ler feed filed under a topic folder was dragged into the shared
  category. The rule is now inverted: a category is managed only when it is the
  shared twin category or a `<Site> (pol-ler)` folder. Everything else, and
  every category created later, is left alone.
- **Hidden state was not inherited by feeds that did not move.** The sync sat
  behind an early return that fired whenever there was nothing to move — which
  is exactly the case where it is needed, because the cron files new twins
  straight into the shared category at normal priority. The sync now runs on
  every apply.
- **Duplicate subscriptions.** The per-feed-id index overwrote itself, so when a
  feed id appeared more than once only the last row survived in the index and
  the run could not see the others as duplicates — it then subscribed the URL
  again on the next poll. The index now keeps the lowest id and counts the
  rest, and an exact-URL check immediately before subscribing adopts an existing
  row instead of creating a second.
- Moving a template feed into the twin category of its own site no longer
  produces a duplicate subscription on the same URL.
- **New twins were subscribed visible inside a hidden group.** The subscribe
  path left the priority at the add-feed default, so a person added between
  layout runs appeared as the only visible feed in a hidden category — very
  obvious in a phone app. The priority is now decided from the target category
  at subscribe time, using the same rule as the layout sync: a hidden category
  means -10, an empty one means 10.

## 1.0.0

Initial release: per-site profiles, twin cloning, populate, health checks, and a
choice of one shared category or one category per site.
