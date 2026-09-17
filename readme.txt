=== Marché Potier ===
Contributors: pauligno
Tags: pottery, applications, jury, events, gallery
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.19.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage pottery markets: applications, supporting documents, jury ratings, selection, CSV exports and an exhibitor gallery with a map.

== Description ==

Marché Potier helps pottery market organizers collect applications, review candidates and present selected exhibitors. The interface and outgoing messages are currently mostly in French.

Version 0.19.0 brings edition administrators, assigned jurors and WordPress Media Library storage together. Read the document storage information below.

= Features =

* Multiple editions with application dates, venue, prices, PDF rules and stand settings.
* Application and selection gallery blocks linked to specific editions.
* Applications without WordPress accounts: contact details, presentation, activity and six uploads.
* Separate file transfers and temporary recovery of answers in the browser.
* Application search, filters and sorting by name, submission date or points.
* Direct selection or ratings from 0 to 5 by active jury members.
* Voting progress table, selection history and filtered CSV exports.
* Invitations and submission confirmations through WordPress email delivery.
* Public exhibitor photographs, techniques, links and a map of geocoded addresses.

No ACF or form builder is required. Blocks use the WordPress editor. Scripts and styles, including Leaflet, are bundled. Mapping providers are documented under External services.

= Team and permissions =

Each edition has one administrator identified by name and email. The "[MP] Administrateur marché" role can manage editions, applications, pages and posts across the site, including other accounts' content. It cannot access technical settings, plugins or native user management.

The "[MP] Votant sélection" role can review applications in assigned editions. In multiple voting mode, active jurors can see jury ratings and update their own rating at any time. Application closing dates do not close voting. Point totals never select a candidate automatically.

Replacing an edition administrator does not revoke their site-wide WordPress permissions. A site administrator must remove those permissions when necessary.

= Public selection =

The gallery requires a published edition, its "Autoriser l'affichage public de la sélection" setting, a selected application and the applicant's publication authorization. Public selection is disabled by default.

The gallery displays names, town, postal code, techniques, website links and product photographs. The map exposes geocoded addresses. Email, telephone, supporting documents and stand photographs are not displayed in the gallery.

The application form requires authorization for public presentation and address mapping if selected and identifies IGN as the geocoding provider. There is no separate choice for each mapping provider and no visitor consent button before the map loads.

= Data and privacy =

Contact details, answers, decisions, authorizations, team assignments, ratings and email status are stored in the WordPress database. Organizers and jurors use WordPress accounts; applicants do not receive accounts.

Photos and supporting documents are WordPress Media Library attachments with ordinary public file URLs. Files follow the configured WordPress uploads directory. JPEG copies at 1080 by 1350 pixels are generated locally after an application is saved and are also available in the Media Library. Nothing is posted automatically to Instagram or Facebook.

Supporting documents have no special access protection. Their URLs can be opened without signing in. This does not display them in the selection gallery: that gallery only shows the selected product photographs and public presentation. Access to application management and CSV export still requires the appropriate WordPress permissions.

The technical mp_form_session cookie links browser sessions to transfers and is renewed for 24 hours. Answers and choices may be saved in browser localStorage under mp-candidate-draft: followed by the edition ID, with a maximum logical lifetime of 24 hours. Expired drafts are removed when the code next runs; a closed browser may retain them physically until another visit or manual deletion.

Temporary uploads expire after 24 hours. Physical cleanup depends on subsequent reads and an hourly WordPress task; delayed WP-Cron execution can prolong storage. On confirmation, the existing attachments are assigned to the application without copying them; only the draft metadata is removed. Cleanup only deletes explicitly marked provisional media with no application reference. Ordinary unattached media are never collected.

Rate limiting stores counters associated with an HMAC of the IP address, with a fifteen-minute expiry renewed on counted attempts. Missing or invalid IP addresses share a fallback bucket. These counters do not contain raw IP addresses; hosting or provider logs may independently retain them.

Confirmed applications, identity records and votes have no automatic retention period. Replacing a file or permanently deleting an application preserves its confirmed media for reuse. Delete unwanted confirmed media explicitly from the Media Library. Deleting an application removes its votes but does not erase every record associated with the person. This version does not integrate WordPress personal data export or erasure tools and does not purge data on uninstall.

Organizers must define retention periods and update their site's privacy policy for their practices, backups and providers. The form links to that policy when configured in WordPress.

== Installation ==

1. Use WordPress 6.6 or later and PHP 8.2 or later, Fileinfo and GD supporting JPEG, PNG and WebP. MySQL/MariaDB must support named locks.
2. On staging, upload the ZIP containing the marche-potier folder through Plugins > Add New > Upload Plugin, then activate it.
3. Sign in as a WordPress administrator and open Marché Potier. Verify document storage protection and email delivery before collecting real applications.
4. Create an edition, complete its settings and required administrator, then publish it.
5. Add the "Formulaire de candidature" block to a page and choose the edition. Use one form per page.
6. Exclude the application page from page/CDN caching, ensure WP-Cron works and test a complete application.
7. Add the "Présentation de la sélection" block, choose the edition and enable public selection when ready.

Documented local tests use WordPress 7.1 and PHP 8.3. Declared minimum versions do not represent a fully tested compatibility matrix.

== Frequently Asked Questions ==

= Which files are accepted? =

Three product photographs, one stand photograph, proof of professional status and professional liability insurance are required. Photos accept JPEG, PNG and WebP; documents also accept PDF. The limit is 20 MB per file, reduced when server limits are lower. HEIC is not supported.

= Can applications work without mapping services? =

Applications, reviews, ratings and exports do not depend on geocoding. An address that cannot be geocoded does not prevent an eligible exhibitor appearing in the gallery. Markers require successful geocoding; the background requires the tile provider. There is no separate setting to enable the gallery while disabling its map.

= Is an API key required? =

No API key or registration with IGN or OpenStreetMap is required by this version. Provider terms still apply.

= Are selection decisions emailed automatically? =

No. Invitations and submission confirmations are sent, but not automatic acceptance or rejection messages. Failed emails are not automatically retried. Delivery depends on the site's email system.

= How do I back up or uninstall? =

Back up the database and uploads directory together. Deactivating or deleting the plugin does not automatically remove its records, files, votes, options or roles. Manage these separately according to your retention policy.

== External services ==

= IGN / Géoplateforme: French address geocoding =

Provider: Institut national de l'information géographique et forestière (IGN).
Purpose: convert a French address into coordinates for the public workshop map.

The WordPress server requests https://data.geopf.fr/geocodage/search over HTTPS with q (street, postal code and town), index=address and limit=2. The User-Agent includes the plugin name and site's home URL. IGN also receives the server IP. The plugin does not attach email, telephone, photographs, documents, ratings or the applicant's name as a separate field; address text is transmitted as stored.

Scheduled WordPress tasks make requests only for selected applications with publication authorization in editions allowing public selection. Addresses must pass French format checks. Coordinates already recorded for the same address avoid another call. Responses may be cached locally for 180 days; accepted coordinates remain in application metadata without automatic expiry.

* Documentation: https://ignf.github.io/cartes.gouv.fr-documentation/fr/guides-utilisateur/utiliser-les-services-de-la-geoplateforme/geocodage/
* Terms: https://cartes.gouv.fr/cgu/
* Personal data: https://cartes.gouv.fr/donnees-personnelles/

= OpenStreetMap: map tiles =

Provider: OpenStreetMap Foundation (OSMF).
Purpose: display the gallery map background using https://tile.openstreetmap.org/{z}/{x}/{y}.png tiles.

The visitor's browser requests tiles directly when the map enters the visible area, or during initialization if IntersectionObserver is unavailable. Panning and zooming may trigger more requests. No separate acceptance click is requested beforehand.

OSMF receives the visitor's IP address, browser HTTP information, tile coordinates and zoom level. The code sets strict-origin-when-cross-origin referrer policy: the site's origin may be transmitted on external HTTPS requests according to browser rules. Marker names and addresses are rendered locally and are not included as fields in tile requests, but those requests reveal the viewed geographical area.

The service is subject to its usage policy and has no availability guarantee. Preserve attribution and comply with provider terms.

* Service: https://www.openstreetmap.org/
* Tile usage policy: https://operations.osmfoundation.org/policies/tiles/
* Privacy: https://osmfoundation.org/wiki/Privacy_Policy
* Map data attribution and licensing: https://www.openstreetmap.org/copyright

= Custom providers =

Developers can override provider URLs through mp_address_geocoder_url and mp_map_tile_url. The information above describes default providers. Sites using replacements must document the actual services and update their privacy policy accordingly.

= Email and external links =

Emails use wp_mail() and the site's configured transport. No particular third-party email provider is required. Submission confirmations include answers and filenames without attachments. Recipients are the applicant and edition administrator; active jurors also receive them in multiple voting mode. Invitations contain login information and a password setup link for new accounts.

The form's DATA INPI link and applicants' website, Instagram and Facebook links open those sites when clicked. They do not trigger automatic imports or posts.

== Source code and third-party libraries ==

= Plugin source =

The plugin's PHP, JavaScript and CSS are distributed in readable form under GPL-2.0-or-later. No build step, Composer or npm is required to run it.

* Repository: https://github.com/PaulPoterie/MarchePotier
* License: https://www.gnu.org/licenses/gpl-2.0.html

= Leaflet 1.9.4 =

Leaflet draws the map, markers and popups in the browser. Its JavaScript and CSS are bundled in assets/vendor/leaflet/ and served by the WordPress site. No Leaflet CDN is used.

Leaflet uses the BSD-2-Clause license. Its copyright notice and license text are preserved in assets/vendor/leaflet/LICENSE.

* Project: https://leafletjs.com/
* Matching source version: https://github.com/Leaflet/Leaflet/tree/v1.9.4
* Uncompressed JavaScript sources: https://github.com/Leaflet/Leaflet/tree/v1.9.4/src
* Distribution and CSS: https://github.com/Leaflet/Leaflet/tree/v1.9.4/dist
* License: https://github.com/Leaflet/Leaflet/blob/v1.9.4/LICENSE
* Contribution and build instructions: https://github.com/Leaflet/Leaflet/blob/v1.9.4/CONTRIBUTING.md

Leaflet is a local library, separate from the IGN and OpenStreetMap services above. Its license does not replace service terms or map data licensing.

== Changelog ==

= 0.19.0 =

* Edition administrators and assigned jurors in one plugin.
* Ratings from 0 to 5, totals, personal filters and voting progress.
* Application and selection blocks linked to editions by ID.
* Search, filters and sorting by points, submission date and name.
* WordPress.org readme, explicit output escaping, prepared vote queries and typed HTTP inputs.
* WordPress Media Library storage, resumable file transfers and safe temporary media cleanup.

== Upgrade Notice ==

= 0.19.0 =

Back up the database and uploads before upgrading and test on staging. Photos and supporting documents use ordinary public Media Library URLs.
