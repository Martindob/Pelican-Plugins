# Paper & Velocity Updater (by Martindob)

Automatically keeps Paper and Velocity servers up to date: every time a server is started or
restarted, the plugin checks [PaperMC's downloads service](https://docs.papermc.io/misc/downloads-service/)
for a newer build, downloads it and replaces the server jar *before* the server actually starts.

## Setup

No configuration needed. The plugin recognizes a server as Paper or Velocity from its own
startup variables (the ones the official Paper/Velocity eggs already ship with):

| Project  | Version variable                    | Build variable | Jar file variable |
|----------|--------------------------------------|-----------------|--------------------|
| Paper    | `MINECRAFT_VERSION` (or `MC_VERSION`) | `BUILD_NUMBER`  | `SERVER_JARFILE`   |
| Velocity | `VELOCITY_VERSION`                    | `BUILD_NUMBER`  | `SERVER_JARFILE`   |

A server is only recognized as Paper/Velocity if its egg defines **both** the version variable
and `BUILD_NUMBER` - a version variable alone is not enough, since e.g. Fabric/Forge/Quilt eggs
(handled by the sibling `minecraft-modrinth` plugin, if installed) can define the same
`MINECRAFT_VERSION`/`MC_VERSION` variable without any concept of a PaperMC build number. Any
other server (no matching variables at all) is left completely untouched, with no overhead.

## How it works

1. When a `start` or `restart` power action is sent to a server (from the console, the client
   API, or a scheduled task), the plugin intercepts it before it reaches Wings.
2. If `BUILD_NUMBER` is pinned to a specific number (not `latest`), the server is left alone -
   the admin explicitly chose that build.
3. Otherwise, the plugin resolves the target Minecraft/Velocity version and asks PaperMC for the
   newest `STABLE` build for it. **The version and build are resolved independently**: pinning
   `MINECRAFT_VERSION` to e.g. `26.2` with `BUILD_NUMBER` left at `latest` keeps the server on new
   `26.2` builds forever - it never jumps to `26.3` just because that becomes the newest version.
   A pinned version is a hard lock: if it can't be positively confirmed against PaperMC's own
   version list (typo, or PaperMC/the cache being temporarily unavailable), the update is skipped
   for that cycle rather than falling back to the latest version - a pinned server can only ever
   move to a version you explicitly asked for. Only leaving the variable at `latest` (or empty)
   resolves to the newest version - and "newest" specifically means the newest *release*:
   PaperMC's own version lists can put an in-development snapshot/pre-release ahead of the actual
   latest release (verified live against Velocity's version list, where `4.2.1-SNAPSHOT` is listed
   before `4.2.0`, and PaperMC labels that snapshot's own build `STABLE` too), so anything that
   looks like a snapshot/rc/pre/alpha/beta version is skipped when resolving `latest`.
4. If that build differs from the one last installed (tracked in a small `.paper-velocity-updater.json`
   marker file in the server's root), the jar is downloaded straight into the server directory
   (via the daemon's file-pull API, with a generous timeout - see below) and the marker is
   updated - all before the power signal is forwarded, so the server always boots on the version
   it just downloaded.

## Configuration

Under **Admin → Plugins → Paper & Velocity Updater → Settings** in the panel:

- **Enabled** - turn the whole plugin on/off.
- **Version/build cache (minutes)** - how long a resolved "latest version"/"latest build" lookup
  is cached before PaperMC is asked again (default `15`).
- **Download timeout (seconds)** - overrides the daemon client's normal 15 second timeout
  (`panel.guzzle.timeout`) for the actual jar download/write, which is far too short for a
  ~50-60MB Paper/Velocity jar. Raise it if your nodes have a slow link to PaperMC's CDN
  (default `300`, minimum `60` - enforced even if set directly in `.env` - so it can't be set
  back down to something too short for a real download).
- **Failure log throttle (minutes)** - the same failure (PaperMC or the daemon being
  unreachable, etc.) for a given server is only logged once per this many minutes, no matter how
  many times you restart the server in the meantime (e.g. while still configuring it). Set to `0`
  to log every occurrence (default `30`).

These are stored as environment variables (`PAPER_VELOCITY_UPDATER_ENABLED`,
`PAPER_VELOCITY_UPDATER_CACHE_MINUTES`, `PAPER_VELOCITY_UPDATER_DOWNLOAD_TIMEOUT_SECONDS`,
`PAPER_VELOCITY_UPDATER_REPORT_THROTTLE_MINUTES`) and can be set directly in `.env` instead if
you prefer - the settings page just writes to the same place.

## Performance

This plugin only ever does work for `start`/`restart` power actions on servers whose egg defines
both a version variable and `BUILD_NUMBER` (see Setup above) - every other server, and every
`stop`/`kill` action, is untouched with effectively zero overhead (a single cheap in-memory check
against the server's already-loaded variables, nothing more).

For a Paper/Velocity server itself, the steady state (server already on the target build) costs
nothing beyond the very first check: PaperMC's "latest version"/"latest build" lookups are cached
for **Version/build cache (minutes)**, and once a restart has actually confirmed the installed
build matches, that confirmation is *also* cached for the same window - so a server that's
already up to date can be restarted repeatedly (e.g. while configuring it) without hitting
PaperMC's API or the daemon's file API again until that window expires and it re-verifies once.
Only an actual pending update (or that periodic re-verification) touches the daemon at all.

## Limitations

- Restarting a server frequently is safe: repeated restarts within the version/build cache window
  reuse the already-resolved version/build instead of re-querying PaperMC, and a restart is skipped
  entirely once the marker file shows the currently installed build is already the target one -
  so it never re-downloads the same jar over and over.
- Firing power actions in quick succession (double-clicking restart, hitting start right after a
  restart, force-stopping and immediately starting again, ...) is safe too. `start`/`restart` share
  a per-server lock: a second `start`/`restart` that arrives while the first is still checking or
  downloading *waits* (up to 10 seconds - kept short on purpose so it can't itself cause a web
  server/reverse proxy gateway timeout) for it to finish rather than racing ahead, so it can never
  send its own power signal while the daemon might still be mid-write on the jar the server is
  about to run. If that short wait isn't enough, the action fails the same clean, retryable way
  described below rather than hanging. `stop`/`kill` are untouched by this plugin entirely (it
  only hooks `start`/`restart`), so they're always sent immediately and never wait on anything -
  they don't touch the jar file either way.
- This only runs for power actions sent through the panel (console, client API, scheduled
  tasks). If Wings itself restarts a crashed server without asking the panel, this hook is not
  triggered.
- Only `STABLE` channel builds are used for automatic updates. If a pinned version only has
  `BETA`/`ALPHA` builds, the newest available build is used instead.
- A failed *check* (e.g. PaperMC being unreachable, or a pinned version that can't be verified)
  never blocks the server from starting and never logs anything beyond the usual throttled failure
  entry (see above) - it just starts on the previously installed jar. A pinned version that can't
  be confirmed is treated exactly the same way: the update is silently skipped for that cycle,
  never substituted with a different version.
- A failed *download*, or waiting too long for another in-flight check/download on the same server
  to finish, deliberately fails the whole power action instead of proceeding: the daemon may still
  be mid-write on that exact jar file, so proceeding anyway could mean running a half-written jar.
  Whatever actually failed is surfaced as the same `ConnectionException` the panel already shows a
  proper error notification for elsewhere, so this shows up as a normal "couldn't reach the node"
  error instead of a broken page - the server keeps its previous state either way, just retry.
- This plugin only hooks `start`/`restart` power actions - a reinstall runs the egg's own install
  script as usual, unaffected by this plugin.
