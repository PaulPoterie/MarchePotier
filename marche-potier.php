<?php
/**
 * Plugin Name: Marché Potier
 * Description: Organisation des éditions, candidatures et sélections de marchés de potiers.
 * Version: 0.16.0
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Text Domain: marche-potier
 * License: GPL-2.0-or-later
 */

namespace MarchePotier;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-plugin.php';
require_once __DIR__ . '/includes/class-editions.php';
require_once __DIR__ . '/includes/class-fields.php';
require_once __DIR__ . '/includes/class-records.php';
require_once __DIR__ . '/includes/class-review.php';
require_once __DIR__ . '/includes/class-csv-export.php';
require_once __DIR__ . '/includes/class-gallery.php';
require_once __DIR__ . '/includes/class-gallery-map.php';
require_once __DIR__ . '/includes/class-identity-migration.php';
require_once __DIR__ . '/includes/class-submission-lock.php';
require_once __DIR__ . '/includes/class-private-files.php';
require_once __DIR__ . '/includes/class-social-images.php';
require_once __DIR__ . '/includes/class-upload-drafts.php';
require_once __DIR__ . '/includes/class-notifications.php';
require_once __DIR__ . '/includes/class-public-form.php';

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
