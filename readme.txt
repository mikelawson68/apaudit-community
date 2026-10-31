=== apAudit ===
Contributors: artistpromusic
Tags: activity log, audit, security
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keep a local history of WordPress account, content, plugin, theme, and update activity.

== Description ==

apAudit records selected WordPress activity in a local database table and provides an administrator-only history and CSV export. Events include user sign-ins and account changes, content saves and deletions, plugin activation and deactivation, theme switches and completed software updates.

The history contains usernames for successful sign-ins, event details and the remote IP address seen by WordPress. Failed sign-in attempts are recorded without the entered username. It is stored on your site. Records are retained for 180 days by default; administrators can choose 30 to 3650 days, and a scheduled cleanup removes older records. A suggested disclosure is available in the WordPress privacy policy guide. WordPress personal-data export and erasure requests include activity associated with an existing user account; erasure removes identifying fields while retaining the activity record. Process requests before deleting the account, since apAudit cannot match an email address after its WordPress account is removed. Review your privacy notice and site policies for this data. Deactivation stops scheduled cleanup; existing records remain on your site.

== Installation ==

Upload and activate apAudit, then visit Tools > apAudit. Only administrators can view or export the history.

== Changelog ==

= 0.1.5 =
* Report database errors before sending an incomplete CSV export.

= 0.1.4 =
* Maintenance release.

= 0.1.3 =
* Report account lookup failures during privacy requests.

= 0.1.2 =
* Report privacy export and erasure database failures so administrators can retry.

= 0.1.1 =
* Add administrator-only CSV export, retention controls, and WordPress personal-data request support.
