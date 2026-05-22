# CAW Plugin Builder

A WordPress plugin that lets an admin describe a plugin in natural language,
has an **Anthropic Managed Agent author and test it in a hosted sandbox**, and
turns the result into a validated, downloadable artifact — which the admin may
*optionally* install into the host WordPress, behind a safety gauntlet designed
so that **no failure mode (parse error, fatal, exception) can white-screen the
site or lock the admin out of wp-admin**.

The installable plugin lives in [`caw-plugin-builder/`](caw-plugin-builder).
This repository also contains its test suite, CI, and tooling.

---

## The idea in one paragraph

Moving machine-authored PHP into a live WordPress process is the dangerous part,
and it is the part this plugin takes seriously. Code is **built and tested only
in the Anthropic sandbox**, never on the host. What comes back is treated as
untrusted data until it has passed a **three-gate host gauntlet**. A **watchdog
mu-plugin** stands by to recover the site if activation ever goes wrong. Local
installation is always opt-in, and is disabled outright on hosts that cannot run
the gates.

---

## Architecture: three stages, hard stops between them

```
   A. BUILD + CI            B. ARTIFACT              C. DESTINATIONS
 ┌──────────────────┐    ┌──────────────────┐    ┌────────────────────────┐
 │ Anthropic        │    │ validated plugin │    │ Download zip           │
 │ Managed Agent    │ -> │ zip, with        │ -> │ View authored files    │
 │ authors + tests  │    │ VALIDATION.md +  │    │ Validation report      │
 │ in a sandbox     │    │ report bundled   │    │ Install on this site * │
 └──────────────────┘    └──────────────────┘    └────────────────────────┘
        sandbox only            host disk          * opt-in, gated, can be
                                                     disabled by the host
```

- **A — Build + CI.** The WP-Cron poller hands a `BuildSpec` to a `BuildProvider`.
  The Anthropic provider provisions a reproducible sandbox environment, runs the
  agent, and harvests the result. The environment definition *is* the CI config:
  it pins the toolchain and is created once per fingerprint and reused.
- **B — Artifact.** A build that passes CI is packaged into a plugin zip with
  `VALIDATION.md` and `caw-validation.json` bundled **inside** it. This is the
  "completed" deliverable.
- **C — Destinations.** Opt-in actions on a completed artifact: **Download**,
  **View authored files**, **Validation report** (always available), and
  **Install on this site** (the host gate gauntlet). *Install to another site*
  and *push to Git* are intentionally future stubs only.

The stages have hard stops: an artifact only exists after CI is judged to pass;
installation only happens on an explicit, separate confirmation.

---

## "The agent runs CI — our code passes CI"

This is the rule that makes the sandbox CI trustworthy:

> The agent **runs** continuous integration in the sandbox. It does **not** get
> to **pass** it.

The agent submits its work through a single structured custom tool,
`caw_submit_build`, carrying the authored files and the **raw** CI artifacts:
per-file `php -l` exit codes, the PHPUnit **JUnit XML**, and the PHPStan **JSON**.
`CiResultsHarvester` parses those artifacts and `CiReport::passed()` computes the
verdict **on the host, in our code**. The agent's prose ("all tests pass") is
never parsed and never trusted. A missing JUnit report, a malformed artifact, or
zero tests run all resolve to *fail*, not pass-by-default.

The sandbox CI does **not** replace the host gates. Host Gate 1 and Gate 2 run
again, independently, because they execute somewhere the agent's CI never did:
on the real host.

---

## The host safety gates

When — and only when — an admin chooses **Install on this site**, the artifact
runs the gauntlet in [`Gates/HostGatePipeline.php`](caw-plugin-builder/includes/Gates/HostGatePipeline.php).
The ordering is itself a safety property:

| Stage | What happens | Why here |
|------|---------------|----------|
| **Gate 1 — lint** | `php -l` on every file, each in a **separate process**, while the code is still in `wp-content/uploads/caw-staging/`. | A parse error (`E_PARSE`) is a compile-time fatal that no `try/catch` can catch. It must be excluded **before** the code is anywhere near `wp-content/plugins`. |
| *(copy)* | Only lint-clean code is copied into `wp-content/plugins/`. OPcache is invalidated for every file. | An *inactive* plugin sitting in `plugins/` does not load on normal requests, so this is safe — and it makes Gate 2/3 probe exactly what will be activated. |
| **Gate 2 — runtime probe** | A throwaway process loads the candidate and runs its activation hook, wrapped in `try/catch(\Throwable)` **and** a `register_shutdown_function`. | Proves the code *runs*, not just parses. The shutdown function catches even uncatchable fatals; the activation hook is run so side effects (table creation, cron) surface. Runs in its own process, so a crash there kills only that process. |
| **Gate 3 — guarded activation** | `activate_plugin()` with a non-empty `$redirect`, so WordPress's own guarded path runs. `active_plugins` is **never** written directly. | The final, real activation — performed through WordPress's own protections, with the watchdog armed. |

If any gate fails, the copy is removed from `wp-content/plugins/` and the host is
left exactly as it was found.

### The watchdog mu-plugin

[`mu-plugin/caw-watchdog.php`](caw-plugin-builder/mu-plugin/caw-watchdog.php) is
installed into `wp-content/mu-plugins/` and loads on **every** request, before
any breakable regular plugin. It is fully self-contained — no namespaces, no
autoloader, no dependency on the main plugin, which may itself be broken.

- **Staleness sweep.** Gate 3 arms a `caw_activating` sentinel option before
  activating. If activation hangs or crashes, the sentinel goes stale; the next
  request's watchdog sweep (~10s threshold) deactivates the offending plugin
  *before it loads*.
- **Fatal-error shutdown handler.** A fatal during an armed activation, or
  inside any builder-installed plugin, is caught and the plugin removed from
  `active_plugins` so the next request is clean.
- **Emergency control surface.** A token-protected URL
  (`?caw_panic=1&caw_token=…`) deactivates every builder-installed plugin at
  once. It needs no login — because the lock-out being recovered from might
  block logging in.

---

## Capability detection — how the plugin degrades

The plugin never assumes a modern host. See
[`Capabilities.php`](caw-plugin-builder/includes/Capabilities.php).

| Capability | Detected by | If absent |
|-----------|-------------|-----------|
| **WP 7.0 Connectors API** | `function_exists('wp_get_connector')` — *not* a version check, so a back-port still works | Falls back to a bespoke API-key field on the plugin's own settings screen |
| **`exec()`** | `function_exists` + `disable_functions` scan | **"Install on this site" is disabled.** Gate 1 and Gate 2 run code in separate processes; if they cannot run, agent-authored code must never reach the live process. Builds and downloads still work. |
| **CLI PHP binary** | probed via `php -v` | Local install disabled |
| **`ZipArchive`** | `class_exists` | Local install disabled (artifacts cannot be unpacked) |

---

## API key resolution — WordPress 7.0 Connectors API

There is **one** key resolver,
[`KeyResolver.php`](caw-plugin-builder/includes/KeyResolver.php), and it mirrors
WordPress core's own documented precedence
(`_wp_connectors_get_api_key_source()` in `wp-includes/connectors.php`):

1. Environment variable `ANTHROPIC_API_KEY`
2. PHP constant `ANTHROPIC_API_KEY`
3. Connectors API database setting `connectors_ai_anthropic_api_key` — consulted
   **only** when the `anthropic` connector is registered
4. Legacy plugin option — a pre-7.0 fallback **only**

WordPress 7.0 ships **no public accessor that returns a resolved connector key**
(the only core helper, `_wp_connectors_get_api_key_source()`, is private and
returns the *source*, not the value), so the resolver does the resolution
itself. It reads the env/constant/setting **names** from the connector metadata
(`wp_get_connector('anthropic')`) rather than hard-coding them, so it tracks core.

Connector functions only exist after the `init` action, so the resolver is never
called at plugin-load time. The resolved key is handed to the agent service as a
constructor argument; the service does not know — and must not care — where the
key came from. The key is never logged or echoed, even masked.

---

## Provider abstraction

The agent layer sits behind [`BuildProvider`](caw-plugin-builder/includes/Agent/BuildProvider.php),
an interface described in terms of a **capability** — "author and test a
WordPress plugin in an isolated sandbox, then return structured CI results" —
not in terms of "an LLM".

Exactly one provider ships: `AnthropicProvider`, backed by Anthropic Managed
Agents (SDK `anthropic-ai/sdk`, beta `managed-agents-2026-04-01`). The seam
exists for a future provider; none is built, because no PHP-callable
hosted-agent equivalent exists today.

---

## Requirements

- WordPress 6.4+ (the Connectors API integration activates on 7.0+)
- PHP 8.2+ (the floor of currently security-supported PHP)
- For **local installation**: `exec()`, a CLI PHP binary, and the `zip` extension
- An Anthropic API key with Managed Agents access

## Installation

```sh
cd caw-plugin-builder
composer install --no-dev   # bundle runtime dependencies
```

Then copy `caw-plugin-builder/` into `wp-content/plugins/` and activate it.
Activation creates the builds table, provisions the staging/artifact
directories, installs the watchdog mu-plugin, and schedules the poller.

---

## Development

The test suite is, by design, an **integration** suite: Gate 2 and Gate 3 only
mean something against a real WordPress, so the suite boots a genuine WordPress
7.0 and exercises the plugin inside it — including deliberately broken fixtures
(a parse error, catchable and uncatchable load fatals, an activation-hook crash).

```sh
# 1. Provision WordPress 7.0 and a database (see .github/workflows/ci.yml).
# 2. Install dependencies:
cd caw-plugin-builder && composer install

# 3. Point the suite at the WordPress install and run it:
CAW_WP_PATH=/path/to/wordpress vendor/bin/phpunit -c ../phpunit.xml.dist

# Static analysis:
vendor/bin/phpstan analyse -c phpstan.neon.dist
```

CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) runs three jobs:
multi-version lint, PHPStan, and the full integration suite against WordPress 7.0.

---

## Known limitations

- Multisite is not yet supported by the gate pipeline (single-site activation).
- The agent submits the authored plugin inline via a custom tool; very large
  plugins may exceed tool-input limits.
- Toolchain version pinning is enforced by the sandbox environment's package set
  plus the agent's CI assertions; non-default PHP versions depend on package
  availability in the environment image.
- "Install to another site" and "Push to Git" are deliberately stubs.

## Repository layout

```
caw-plugin-builder/        The installable WordPress plugin
  includes/                Namespaced source (CAW\PluginBuilder\*)
    Agent/                 Provider interface + Anthropic Managed Agents provider
    Gates/                 The three host gates + the runtime-probe harness
    Build/ Artifact/ Cron/ Admin/ Support/
  mu-plugin/caw-watchdog.php   The lockout-recovery watchdog
tests/                     Integration test suite (real WordPress 7.0)
.github/workflows/ci.yml   Repository CI
```
