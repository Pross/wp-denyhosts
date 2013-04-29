=== WP-DenyHosts ===
Contributors: pross
Tags: spam, bruteforce, login, block
Requires at least: 3.5
Tested up to: 3.6
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Distributed anti bruteforce plugin.

== Description ==

How it works.

The plugin monitors failed login attempts, if the limit is reached the users IP is added to a local banlist.
The user is now blocked and can no longer attempt to login.

Every 24 hours the plugin will upload the blocker IPs and download a fresh list of all IPs blocked accross the network in the last 7 days.

If an IP is blocked on 3 or more servers its added the the global ban list and will be blocked on all servers using the plugin.

== Changelog ==

= 1.0 =
* First release.