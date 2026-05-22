=== CAW Plugin Builder ===
Contributors: pluginfactory
Tags: ai, code generation, developer tools, anthropic, claude
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Describe a plugin in natural language; an Anthropic Managed Agent authors and tests it in a sandbox; a host-side safety gauntlet validates it before optional install.

== Description ==

CAW Plugin Builder lets a WordPress administrator describe a plugin in plain
English. An Anthropic Managed Agent then authors and tests that plugin inside a
hosted sandbox, and the result becomes a validated, downloadable artifact. The
admin may optionally install it into the live site — but only after it survives
a host-side, three-gate safety gauntlet designed so that no parse error, fatal,
or exception can white-screen the site or lock an admin out of wp-admin.

**Build and CI happen only in the Anthropic sandbox, never on the host.**

The host gauntlet:

* **Gate 1 — separate-process lint.** Every PHP file is checked with `php -l`
  in its own process, while the code is still in staging.
* **Gate 2 — isolated runtime probe.** The candidate is loaded and its
  activation hook run inside a throwaway process, so a crash there kills only
  that process.
* **Gate 3 — guarded activation.** Activation goes through WordPress's own
  guarded path; the active_plugins option is never written directly.

A must-use watchdog plugin provides lockout recovery if an activation ever goes
wrong. The agent runs continuous integration in the sandbox, but the host —
not the agent — computes the pass/fail verdict from the structured results.

This is a v0 alpha. It requires an Anthropic API key with Managed Agents
access. "Install on this site" is offered only on hosts that can run the
safety gates (it needs the `exec()` function and a CLI PHP binary).

== Installation ==

1. Download `caw-plugin-builder.zip` from the latest release.
2. In wp-admin, go to Plugins → Add New → Upload Plugin, choose the zip, and
   install it.
3. Activate **CAW Plugin Builder**. Activation creates the builds table,
   provisions the working directories, installs the watchdog must-use plugin,
   and schedules the background poller.
4. Go to **Plugin Builder → Settings** and enter an Anthropic API key with
   Managed Agents access. The key can also be supplied via the
   `ANTHROPIC_API_KEY` environment variable or PHP constant, which take
   precedence over the stored option.
5. Open **Plugin Builder**, describe a plugin, and start a build.

Installing from a Git checkout instead of the release zip requires running
`composer install` inside the `caw-plugin-builder/` directory first.

== Frequently Asked Questions ==

= Does my site's code get sent anywhere? =

No. The agent authors and tests new plugins inside an Anthropic-hosted sandbox.
Your site is only ever the destination of an optional, gated install.

= What if a generated plugin is broken? =

It cannot reach your live site without passing all three host gates. If any
gate fails, the candidate is removed and the site is left unchanged. The
watchdog must-use plugin recovers wp-admin if an activation hangs or crashes.

== Changelog ==

= 0.1.0 =
* Initial v0 alpha release.
* Natural-language build requests fulfilled by an Anthropic Managed Agent.
* Three-gate host safety gauntlet plus a lockout-recovery watchdog.
* WP-Cron build poller, validated artifact packaging, and a build review UI.
