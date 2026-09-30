<?php
/** Explicit site-administrator authorization for optional mapping providers. */
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class ExternalServices {
	public const OPTION = 'marcpo_external_services';

	public static function hooks(): void {
		add_action( 'admin_init', array( self::class, 'register' ) );
		add_action( 'admin_menu', static function () {
			add_submenu_page( 'marche-potier', 'Services externes', 'Services externes', 'manage_options', 'marcpo-services', array( self::class, 'render' ) );
		} );
		add_action( 'update_option_' . self::OPTION, static function ( $old, $new ) {
			if ( ! is_array( $new ) || true !== ( $new['ign'] ?? false ) ) { wp_unschedule_hook( 'marcpo_locate_city' ); }
		}, 10, 2 );
	}

	public static function register(): void {
		register_setting( 'marcpo_services', self::OPTION, array(
			'type' => 'array',
			'default' => array( 'ign' => false, 'osm' => false ),
			'sanitize_callback' => array( self::class, 'sanitize' ),
		) );
	}

	/** Exact checkbox values; remains idempotent when the Settings API sanitizes twice. */
	public static function sanitize( mixed $input ): array {
		$clean = array( 'ign' => false, 'osm' => false );
		if ( ! is_array( $input ) ) { return $clean; }
		foreach ( $clean as $service => $value ) {
			$clean[ $service ] = in_array( $input[ $service ] ?? null, array( '1', true ), true );
		}
		return $clean;
	}

	public static function enabled( string $service ): bool {
		if ( ! in_array( $service, array( 'ign', 'osm' ), true ) ) { return false; }
		$settings = get_option( self::OPTION, array() );
		return is_array( $settings ) && true === ( $settings[ $service ] ?? false );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Accès refusé.', 'poterie-navarraise-market-manager' ), '', array( 'response' => 403 ) ); }
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Services externes — Cartographie', 'poterie-navarraise-market-manager' ); ?></h1>
			<p>Ces deux services sont désactivés par défaut. Les candidatures, la sélection et la galerie fonctionnent sans carte. Seul un administrateur WordPress peut les autoriser.</p>
			<?php settings_errors( self::OPTION ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'marcpo_services' ); ?>
				<input type="hidden" name="marcpo_external_services[ign]" value="0">
				<input type="hidden" name="marcpo_external_services[osm]" value="0">
				<h2>IGN / Géoplateforme — Localisation des adresses</h2>
				<p>Le serveur du site transmet à l’IGN la rue, le code postal et la ville des candidats sélectionnés qui ont autorisé la cartographie, uniquement si la sélection est publique. L’IGN reçoit aussi l’adresse IP du serveur et l’URL du site dans l’identification du plugin. Aucun téléphone, email, nom de candidat, document ou photo n’est ajouté à la requête ; le texte de l’adresse est transmis tel qu’il a été renseigné.</p>
				<p><a href="https://cartes.gouv.fr/cgu/" target="_blank" rel="noopener noreferrer">Conditions d’utilisation IGN</a> · <a href="https://cartes.gouv.fr/donnees-personnelles/" target="_blank" rel="noopener noreferrer">Données personnelles IGN</a></p>
				<p><label><input type="checkbox" name="marcpo_external_services[ign]" value="1" <?php checked( self::enabled( 'ign' ) ); ?>> J’autorise l’envoi de ces données à l’IGN pour localiser les adresses.</label></p>
				<h2>OpenStreetMap — Fond de carte public</h2>
				<p>Le navigateur du visiteur contacte directement les serveurs de tuiles OpenStreetMap lorsque la carte devient visible. OpenStreetMap reçoit l’adresse IP du visiteur, les informations HTTP du navigateur, la zone géographique consultée et éventuellement l’origine du site. Les noms et adresses des marqueurs ne sont pas envoyés comme champs dans ces requêtes.</p>
				<p><a href="https://operations.osmfoundation.org/policies/tiles/" target="_blank" rel="noopener noreferrer">Conditions du service de tuiles</a> · <a href="https://osmfoundation.org/wiki/Privacy_Policy" target="_blank" rel="noopener noreferrer">Confidentialité OpenStreetMap</a></p>
				<p><label><input type="checkbox" name="marcpo_external_services[osm]" value="1" <?php checked( self::enabled( 'osm' ) ); ?>> J’autorise le chargement du fond de carte OpenStreetMap par les visiteurs du site.</label></p>
				<p>La bibliothèque Leaflet est incluse dans le plugin et servie par ce site. Désactiver IGN arrête les nouvelles localisations ; désactiver OpenStreetMap masque la carte lors des prochains chargements de page. Après une modification, purgez les caches de pages du site ou du CDN pour actualiser les pages déjà mises en cache.</p>
				<?php submit_button( 'Enregistrer les autorisations' ); ?>
			</form>
		</div>
		<?php
	}
}
