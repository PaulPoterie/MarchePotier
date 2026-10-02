<?php
/**
 * Enregistrement des hooks publics et d’administration, puis accueil du plugin.
 */

namespace MarchePotier;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** Point d'entrée appelé sur plugins_loaded ; les callbacks enregistrés ici vivent ensuite via WordPress. */
	public static function boot(): void {
		add_action( 'init', array( Editions::class, 'register' ) );
		add_action( 'admin_init', array( Editions::class, 'install_permissions' ) );
		Editions::hooks();
		Blocks::hooks();
		Records::hooks();
		Jury::hooks();
		Votes::hooks();
		VoteTracking::hooks();
		Review::hooks();
		Gallery::hooks();
		ExternalServices::hooks();
		GalleryMap::hooks();
		add_action( 'admin_init', array( IdentityMigration::class, 'run' ) );
		add_action( 'admin_init', array( Records::class, 'migrate_decision_index' ) );
		PrivateFiles::hooks();
		PublicForm::hooks();
		UploadDrafts::hooks();
		// Le parent doit exister avant les sous-pages pour que WordPress calcule leurs hooks.
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 5 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'dashboard_assets' ) );
	}

	public static function register_menu(): void {
		add_menu_page(
			__( 'Gestion de Marché Potier', 'poterie-navarraise-market-manager' ),
			__( 'Gestion Marché Potier', 'poterie-navarraise-market-manager' ),
			'marcpo_access_market',
			'marche-potier',
			array( self::class, 'render_dashboard' ),
			'dashicons-store'
		);
	}

	public static function dashboard_assets( string $hook ): void {
		if ( 'toplevel_page_marche-potier' !== $hook || ! current_user_can( 'marcpo_access_market' ) ) { return; }
		wp_enqueue_style( 'marcpo-dashboard', plugins_url( '../assets/dashboard.css', __FILE__ ), array(), '0.19.1.3' );
	}

	private static function dashboard_card( string $icon, string $title, string $description, string $url ): void {
		echo '<a class="marcpo-dashboard-card" href="' . esc_url( $url ) . '"><span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $description ) . '</p></a>';
	}

	public static function render_dashboard(): void {
		if ( ! current_user_can( 'marcpo_access_market' ) ) {
			wp_die( esc_html__( 'Vous ne pouvez pas accéder à cette page.', 'poterie-navarraise-market-manager' ) );
		}
		$headers = get_file_data( dirname( __DIR__ ) . '/marche-potier.php', array( 'Version' => 'Version', 'PluginURI' => 'Plugin URI' ), 'plugin' );
		$version = sanitize_text_field( $headers['Version'] );
		$mailto = 'mailto:paul@poterie-navarraise.info?subject=' . rawurlencode( 'Gestion de Marché Potier — version ' . $version );
		?>
		<div class="wrap marcpo-dashboard">
			<header class="marcpo-dashboard-hero">
				<div><h1><?php esc_html_e( 'Gestion de Marché Potier', 'poterie-navarraise-market-manager' ); ?></h1>
				<p class="marcpo-dashboard-byline"><?php esc_html_e( 'fait par Poterie Navarraise', 'poterie-navarraise-market-manager' ); ?></p>
				<p class="marcpo-dashboard-intro"><?php esc_html_e( 'De la première candidature à la sélection finale, retrouvez les outils pour organiser votre marché potier.', 'poterie-navarraise-market-manager' ); ?></p></div>
				<span class="marcpo-dashboard-version"><?php echo esc_html( 'Version ' . $version ); ?></span>
			</header>
			<nav class="marcpo-dashboard-cards" aria-label="<?php esc_attr_e( 'Outils du marché', 'poterie-navarraise-market-manager' ); ?>">
			<?php
			if ( current_user_can( 'marcpo_manage_editions' ) ) { self::dashboard_card( 'dashicons-calendar-alt', __( 'Gérer les éditions', 'poterie-navarraise-market-manager' ), __( 'Dates, informations pratiques et équipe de chaque marché.', 'poterie-navarraise-market-manager' ), admin_url( 'edit.php?post_type=marcpo_edition' ) ); }
			if ( Jury::can_review() ) {
				self::dashboard_card( 'dashicons-portfolio', __( 'Examiner les candidatures', 'poterie-navarraise-market-manager' ), __( 'Consultez les dossiers et participez à la sélection.', 'poterie-navarraise-market-manager' ), admin_url( 'admin.php?page=marcpo-gestion' ) );
				self::dashboard_card( 'dashicons-chart-bar', __( 'Suivi des votes', 'poterie-navarraise-market-manager' ), __( 'Retrouvez les notes et l’avancement du jury.', 'poterie-navarraise-market-manager' ), VoteTracking::url() );
				self::dashboard_card( 'dashicons-backup', __( 'Historique des sélections', 'poterie-navarraise-market-manager' ), __( 'Recherchez un potier et comparez ses participations.', 'poterie-navarraise-market-manager' ), admin_url( 'admin.php?page=marcpo-historique' ) );
			}
			?>
			</nav>
			<section class="marcpo-dashboard-guide" aria-labelledby="marcpo-dashboard-guide-title">
			<?php if ( current_user_can( 'marcpo_manage_editions' ) ) : ?>
			<h2 id="marcpo-dashboard-guide-title"><?php esc_html_e( 'Votre marché, étape par étape', 'poterie-navarraise-market-manager' ); ?></h2>
			<ol class="marcpo-dashboard-steps">
				<li>
					<p><strong><?php esc_html_e( 'Créer une édition', 'poterie-navarraise-market-manager' ); ?></strong><br>
					<?php esc_html_e( 'Dans « Gérer les éditions », ajoutez une édition, renseignez « Édition de l’année », les informations du marché, les dates d’inscription et l’administrateur unique obligatoire dans « Organisateur et votes », puis publiez-la.', 'poterie-navarraise-market-manager' ); ?></p>
				</li>
				<li>
					<p><strong><?php esc_html_e( 'Faire apparaître le formulaire', 'poterie-navarraise-market-manager' ); ?></strong><br>
					<?php esc_html_e( 'Dans une page WordPress, cliquez sur « + », ajoutez le bloc « Formulaire de candidature », puis choisissez votre édition dans la liste « Titre (Année) ». Publiez la page. Le formulaire accepte les candidatures pendant la période définie dans l’édition.', 'poterie-navarraise-market-manager' ); ?></p>
				</li>
				<li>
					<p><strong><?php esc_html_e( 'Examiner les candidatures', 'poterie-navarraise-market-manager' ); ?></strong><br>
					<?php esc_html_e( 'Dans « Examiner les candidatures », choisissez l’édition, consultez les dossiers et leurs pièces, puis enregistrez vos décisions : « À examiner », « Sélectionné » ou « Non sélectionné ». L’historique permet de retrouver les candidatures des différentes éditions.', 'poterie-navarraise-market-manager' ); ?></p>
				</li>
				<li>
					<p><strong><?php esc_html_e( 'Faire apparaître la sélection', 'poterie-navarraise-market-manager' ); ?></strong><br>
					<?php esc_html_e( 'Ajoutez le bloc « Présentation de la sélection » dans une page WordPress, choisissez votre édition et publiez la page. Lorsque la sélection est prête, ouvrez « Affichage sur le site », en bas de l’édition, cochez « Autoriser l’affichage public de la sélection » et enregistrez.', 'poterie-navarraise-market-manager' ); ?></p>
				</li>
			</ol>
			<p class="description"><?php esc_html_e( 'Chaque bloc conserve l’édition choisie, même si son titre ou son année change. Plusieurs éditions peuvent partager la même année.', 'poterie-navarraise-market-manager' ); ?></p>
			<p><?php esc_html_e( 'Dans « Organisateur et votes » de l’édition, renseignez l’administrateur unique du marché (nom et email obligatoires). Il gère le marché, les pages et les articles, et prend la décision finale. Pour noter les dossiers à plusieurs, choisissez « Votes multiples » et complétez le tableau « Votant pour la sélection ». L’administrateur participe aussi aux votes. Chaque personne note dans « Examiner » ; la colonne « Sélection et points » affiche le total et la participation.', 'poterie-navarraise-market-manager' ); ?></p>
			<?php else : ?>
			<h2 id="marcpo-dashboard-guide-title"><?php esc_html_e( 'Examiner et voter', 'poterie-navarraise-market-manager' ); ?></h2>
			<p><?php esc_html_e( 'Ouvrez « Examiner les candidatures », choisissez votre édition puis un dossier. Dans « Ma note », choisissez votre note de 0 à 5 et cliquez sur « Valider ma note ». En mode votes multiples, vous pouvez consulter les autres notes et modifier la vôtre à tout moment, indépendamment des dates d’inscription.', 'poterie-navarraise-market-manager' ); ?></p>
			<?php endif; ?>
			</section>
			<section class="marcpo-dashboard-contact" aria-labelledby="marcpo-dashboard-contact-title">
				<div><h2 id="marcpo-dashboard-contact-title"><?php esc_html_e( 'Besoin d’aide ?', 'poterie-navarraise-market-manager' ); ?></h2>
				<p><?php esc_html_e( 'En cas de souci, contactez-nous en précisant la version du plugin et le problème rencontré.', 'poterie-navarraise-market-manager' ); ?></p>
				<p><a href="<?php echo esc_url( $headers['PluginURI'] ); ?>"><?php esc_html_e( 'Visiter le site du plugin', 'poterie-navarraise-market-manager' ); ?></a></p>
				<p class="description"><?php echo esc_html( 'Poterie Navarraise Pottery Market Manager · ' . $version ); ?></p></div>
				<a class="button button-secondary" href="<?php echo esc_url( $mailto ); ?>">paul@poterie-navarraise.info</a>
			</section>
		</div>
		<?php
	}
}
