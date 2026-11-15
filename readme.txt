=== PeeStream Video Player & DooPlay Integration ===
Contributors: peestream
Donate link: https://peestream.com
Tags: video, player, streaming, dooplay, embed
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.5-beta.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Official high-performance video player, auto-publish automation, and competitor video migrator for PeeStream.

== Description ==

**PeeStream Video Player & DooPlay Integration** connects your WordPress website directly to the **PeeStream** high-performance video hosting and streaming platform ([peestream.com](https://peestream.com)).

Tailored specifically for movie, anime, and TV show streaming portals running the **DooPlay** theme (2.5.x) or any standard WordPress theme via shortcodes.

### Key Features

* **Native DooPlay Integration:** Seamlessly attaches PeeStream video players into DooPlay custom repeatable fields (`repeatable_fields`) without modifying theme source files.
* **AutoPublish V2 Receiver:** Receives signed HMAC-SHA256 webhooks from PeeStream to automatically create or update movies, TV series, seasons, and episodes the instant they become ready.
* **Automated Competitor Link Migrator:** Effortlessly migrates slow or dead video links from competitors (Streamtape, Mixdrop, VOE, Filemoon, Doodstream, etc.) to PeeStream with backup snapshots and atomic rollback.
* **Universal Shortcode:** Embed responsive PeeStream video players anywhere using `[peestream id="VIDEO_ID"]`.
* **Zero Overhead:** Clean, modular PHP architecture with atomic queue locking and transaction protection.

### Notice of External Service

This plugin relies on the 3rd-party SaaS platform **PeeStream** ([peestream.com](https://peestream.com)) to host, encode, and deliver video streams. 
By connecting your site using your PeeStream API key, video upload requests, playback URLs, and auto-publishing webhooks are processed by PeeStream servers.

* Service URL: [https://peestream.com](https://peestream.com)
* Terms of Service: [https://peestream.com/terms](https://peestream.com/terms)
* Privacy Policy: [https://peestream.com/privacy](https://peestream.com/privacy)

== Installation ==

1. Upload the `peestream-wp-dooplay` folder to your `/wp-content/plugins/` directory, or upload the ZIP file directly via **Plugins > Add New > Upload Plugin**.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **PeeStream > Settings** in the WordPress admin sidebar.
4. Enter your **PeeStream API Key** (obtainable from your account dashboard at `peestream.com`).
5. Configure your player settings, AutoPublish site ID, and migration preferences.

== Frequently Asked Questions ==

= Do I need a PeeStream account? =
Yes, an active account on [peestream.com](https://peestream.com) is required to generate API credentials and host video content.

= Does this work with themes other than DooPlay? =
Yes. While native tab integration is designed for DooPlay 2.5.x, you can embed the PeeStream player on any theme using the `[peestream id="FILE_CODE"]` shortcode.

= How does the competitor migrator work? =
The migrator scans your selected post types for competitor hoster URLs, sends remote import jobs to PeeStream, and atomically replaces the player links once encoding is confirmed. Original links are backed up for safe one-click rollback.

== Screenshots ==

1. PeeStream General Settings panel in WordPress Admin.
2. DooPlay post editor showing the integrated PeeStream player selector.
3. Automated competitor video link migration dashboard with live progress.

== Changelog ==

= 1.0.5 =
* Implemented raw-body HMAC-SHA256 signature verification for AutoPublish V2 webhooks.
* Added binary-safe case-sensitive URL deduplication with fail-safe archive migration.
* Strengthened queue worker heartbeat lease extensions and atomic lock releases.
* Full compatibility with WordPress 6.7 and DooPlay 2.5.x.

= 1.0.3 =
* Unified manual migration locking with heartbeat lease extension.
* Multi-server post atomicity and isolated rollback snapshots.
* UTC timestamp standardization across all queue operations.

= 1.0.2 =
* Added task execution budgeting and post meta persistence verification.

= 1.0.1 =
* Strict hostname matching and editorial conflict detection.

= 1.0.0 =
* Initial public release with DooPlay player integration and shortcode support.
