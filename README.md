# Poterie Navarraise Pottery Market Manager

Manage pottery market applications, review supporting documents, collect jury ratings and publish selected exhibitors on a WordPress site.

[Official plugin website — in French](https://gestion-marche-potier.poterie-navarraise.info/)

**Latest version: 0.19.4.** Download the installable ZIP from [GitHub Releases](https://github.com/PaulPoterie/MarchePotier/releases/latest). WordPress.org hosting has been approved. The assigned directory slug and translation text domain are `poterie-navarraise-market-manager`. The internal `marcpo_` prefix remains unchanged.

Stable GitHub releases with an attached distribution ZIP are published to WordPress.org through GitHub Actions and SVN. See the [release procedure (in French)](docs/PUBLICATION-WORDPRESS.md). Ordinary commits do not publish updates.

This introduction and the [WordPress.org readme](readme.txt) are in English. The plugin interface, application form and outgoing messages are currently mostly in French. Its French presentation is **Poterie Navarraise — Organisation de marchés potiers**.

The development code now uses WordPress image editors with either Imagick or GD for social copies. This change is not included in the published 0.19.4 ZIP, which still requires GD for those copies.

## What the plugin does

- Manage multiple market editions, application periods, venue information, prices, PDF rules and stand settings.
- Collect applications without requiring WordPress accounts, including contact details and six photo/document uploads.
- Review, search and sort applications; make selection decisions directly or collect ratings from 0 to 5 from assigned jury members.
- Follow voting progress, consult selection history and export filtered applications as CSV.
- Send team invitations and application confirmations through the site's WordPress email system.
- Publish selected exhibitors through a gallery block, with an optional address map.

Each edition has one market administrator and can have several assigned jurors. Ratings do not select candidates automatically. No ACF plugin or form builder is required.

## Install and get started

1. Use WordPress 6.6 or later and PHP 8.2 or later, with Fileinfo and a WordPress image editor (Imagick or GD) supporting JPEG/PNG/WebP for social copies. Use MySQL/MariaDB or SQLite through the official WordPress SQLite Database Integration plugin. Local checks use WordPress 7.1 and PHP 8.3; the declared minimum versions are not a fully tested compatibility matrix.
2. Upload the distribution ZIP through **Plugins → Add New → Upload Plugin**, then activate it. The package folder must be `poterie-navarraise-market-manager`. Keep development folders such as `docs` and `tests` out of the installable package.
3. Open **Gestion Marché Potier**, create an edition, configure its application dates and assign its required market administrator.
   The **Enregistrer l’édition et envoyer l’invitation** button saves the edition and sends the administrator's invitation after validation. A new edition remains a draft until published. New accounts also receive an invitation automatically when first saved; existing accounts keep their passwords.
4. Add the **Formulaire de candidature** block to a page and select the edition. Use one application form per page and exclude that page from page/CDN caching.
5. Test a complete application and email delivery before opening applications to real candidates.
6. Add the **Présentation de la sélection** block to another page and enable the edition's public selection when ready.

Updating from 0.19.1, 0.19.2 or 0.19.3 preserves editions, applications, team assignments and ratings. Existing editions without a city remain usable; enter their market city when next saving their settings. Back up the database and uploads, then upload the new distribution ZIP and replace the installed version. Before upgrading to the new lock, stop plugin writes (including scheduled tasks and CLI jobs) and allow requests running the old version to finish.

Version 0.19.4 includes the portable write lock, tested with MySQL and the official SQLite integration; MariaDB has not been run separately, and other database adapters are not validated. See the [database compatibility and recovery notes](readme.txt). An orphaned lock after a hard server termination requires maintenance with all plugin writes stopped; a running operation is never displaced by a timeout.

When switching from the former `marche-potier` package, deactivate the old plugin before activating this one. The internal identifiers introduced in 0.19.1 do not migrate previous 0.19.0 test records or team assignments. Create fresh editions and team assignments.

## Mapping, consent and files

**IGN geocoding and OpenStreetMap tiles are both off by default.** A WordPress site administrator can enable each provider separately under **Gestion Marché Potier → Services externes**. Applicants must also give separate, optional consent before their address is sent for geocoding or displayed on the map. Refusing mapping does not prevent an application or an eligible exhibitor's gallery entry. The bundled Leaflet 1.9.4 library is served locally.

Photos and supporting documents are ordinary WordPress Media Library attachments: **their file URLs are public and do not require sign-in**. Application management and CSV export require appropriate permissions. Supporting documents are not displayed in the public gallery. Replacing a file or deleting an application preserves confirmed media; unwanted files must be removed explicitly from the Media Library.

Temporary answers and transfers can be recovered for up to 24 hours, with physical cleanup dependent on browser activity and WordPress scheduled tasks. Back up the database and uploads together. Consult the [data, privacy, external services and Leaflet source documentation](readme.txt) before collecting real applications.

## Documentation and development

The WordPress admin menu is **Gestion Marché Potier**. Its home page provides shortcuts, a setup guide and a support email link with the installed plugin version. In simple selection mode the voter section is hidden while existing assignments and scores are preserved. Selection history can be searched by surname, first name and email only.

Editions require a **Ville** (market city) below the year. This internal field is not displayed in the public application form. The **Ville du marché** history filter shows only editions and applications for that market city and can be combined with the identity search. City choices come from editions the current user can access. Existing editions without a city remain available under **Ville non renseignée**; complete the city when next editing them. Applicant addresses and the public venue text are separate fields.

- [Contribution rules and WordPress.org regression checklist — French](AGENTS.md)
- [Detailed user guide — French](docs/GUIDE-UTILISATEUR-FR.md)
- [Voting guide — French](docs/VOTES.md)
- [Architecture, business rules and test instructions — French](docs/DEVELOPPEMENT.md)
- [Latest WordPress.org review work and validation results — French](docs/REVUE-WORDPRESS-2026-09-29.md)
- [WordPress.org description, installation, FAQ and changelog — English](readme.txt)

PHP, JavaScript and CSS are provided in readable form. No Composer or npm build is required to run the plugin. Development documentation and tests belong in this GitHub repository; the distribution contains the plugin entry point, `includes`, `assets` and `readme.txt`.

Plugin license: [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html). Leaflet: [BSD-2-Clause](assets/vendor/leaflet/LICENSE), with matching source and build links in the WordPress.org readme.

For French maps, postal-code spaces, st/ST abbreviations and configured Basque country aliases are normalized without changing applications. An unsuitable street result falls back to an IGN municipality point, explicitly labelled as approximate.
