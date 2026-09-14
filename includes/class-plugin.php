<?php
/**
 * Point d'entrée de l'administration du plugin.
 */

namespace MarchePotier;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		add_action( 'init', array( Editions::class, 'register' ) );
		add_action( 'admin_init', array( Editions::class, 'install_permissions' ) );
		Editions::hooks();
		Records::hooks();
		Review::hooks();
		Gallery::hooks();
		GalleryMap::hooks();
		add_action( 'admin_init', array( IdentityMigration::class, 'run' ) );
		add_action( 'admin_init', array( Records::class, 'migrate_decision_index' ) );
		PrivateFiles::hooks();
		PublicForm::hooks();
		UploadDrafts::hooks();
		// Le parent doit exister avant les sous-pages pour que WordPress calcule leurs hooks.
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 5 );
	}

	public static function register_menu(): void {
		add_menu_page(
			__( 'Marché Potier', 'marche-potier' ),
			__( 'Marché Potier', 'marche-potier' ),
			'mp_access_market',
			'marche-potier',
			array( self::class, 'render_dashboard' ),
			'dashicons-store'
		);
	}

	public static function render_dashboard(): void {
		if ( ! current_user_can( 'mp_access_market' ) ) {
			wp_die( esc_html__( 'Vous ne pouvez pas accéder à cette page.', 'marche-potier' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Marché Potier', 'marche-potier' ); ?></h1>
			<p><?php esc_html_e( 'Créez vos éditions et définissez leurs périodes de candidature.', 'marche-potier' ); ?></p>
			<?php foreach ( array( 'mp_edition' => array( 'mp_manage_editions', 'Gérer les éditions' ), 'mp_candidature' => array( 'mp_manage_applications', 'Examiner les candidatures' ) ) as $type => $item ) : ?>
				<?php if ( current_user_can( $item[0] ) ) : ?>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'mp_candidature' === $type ? 'admin.php?page=mp-gestion' : 'edit.php?post_type=' . $type ) ); ?>"><?php echo esc_html( $item[1] ); ?></a></p>
				<?php endif; ?>
			<?php endforeach; ?>
			<p><?php esc_html_e( 'Pour recevoir les candidatures, ajoutez le shortcode [inscription_potier edition="2027"] dans une page WordPress. Pour présenter la sélection, utilisez [afficher_selection edition="2027"].', 'marche-potier' ); ?></p>
		</div>
		<?php
	}
}
