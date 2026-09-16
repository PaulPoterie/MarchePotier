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
		Blocks::hooks();
		Records::hooks();
		Jury::hooks();
		Votes::hooks();
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
			<p><?php esc_html_e( 'Retrouvez ici les outils pour organiser votre marché, de l’ouverture des candidatures à la publication des potiers sélectionnés.', 'marche-potier' ); ?></p>
			<?php foreach ( array( 'mp_edition' => array( 'mp_manage_editions', 'Gérer les éditions' ), 'mp_candidature' => array( 'mp_review_applications', 'Examiner les candidatures' ) ) as $type => $item ) : ?>
				<?php if ( current_user_can( $item[0] ) ) : ?>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'mp_candidature' === $type ? 'admin.php?page=mp-gestion' : 'edit.php?post_type=' . $type ) ); ?>"><?php echo esc_html( $item[1] ); ?></a></p>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php if ( Jury::can_review() ) : ?>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mp-historique' ) ); ?>"><?php esc_html_e( 'Historique des candidatures', 'marche-potier' ); ?></a></p>
			<?php endif; ?>
			<?php if ( current_user_can( 'mp_manage_editions' ) ) : ?>
			<h2><?php esc_html_e( 'Comment organiser une édition ?', 'marche-potier' ); ?></h2>
			<ol>
				<li>
					<p><strong><?php esc_html_e( 'Créer une édition', 'marche-potier' ); ?></strong><br>
					<?php esc_html_e( 'Dans « Gérer les éditions », ajoutez une édition, renseignez « Édition de l’année », les informations du marché, les dates d’inscription et l’administrateur unique obligatoire dans « Organisateur et votes », puis publiez-la.', 'marche-potier' ); ?></p>
				</li>
				<li>
					<p><strong><?php esc_html_e( 'Faire apparaître le formulaire', 'marche-potier' ); ?></strong><br>
					<?php esc_html_e( 'Dans une page WordPress, cliquez sur « + », ajoutez le bloc « Formulaire de candidature », puis choisissez votre édition dans la liste « Titre (Année) ». Publiez la page. Le formulaire accepte les candidatures pendant la période définie dans l’édition.', 'marche-potier' ); ?></p>
				</li>
				<li>
					<p><strong><?php esc_html_e( 'Examiner les candidatures', 'marche-potier' ); ?></strong><br>
					<?php esc_html_e( 'Dans « Examiner les candidatures », choisissez l’édition, consultez les dossiers et leurs pièces, puis enregistrez vos décisions : « À examiner », « Sélectionné » ou « Non sélectionné ». L’historique permet de retrouver les candidatures des différentes éditions.', 'marche-potier' ); ?></p>
				</li>
				<li>
					<p><strong><?php esc_html_e( 'Faire apparaître la sélection', 'marche-potier' ); ?></strong><br>
					<?php esc_html_e( 'Ajoutez le bloc « Présentation de la sélection » dans une page WordPress, choisissez votre édition et publiez la page. Lorsque la sélection est prête, ouvrez « Affichage sur le site », en bas de l’édition, cochez « Autoriser l’affichage public de la sélection » et enregistrez.', 'marche-potier' ); ?></p>
				</li>
			</ol>
			<p class="description"><?php esc_html_e( 'Chaque bloc conserve l’édition choisie, même si son titre ou son année change. Plusieurs éditions peuvent partager la même année.', 'marche-potier' ); ?></p>
			<p><?php esc_html_e( 'Dans « Organisateur et votes » de l’édition, renseignez l’administrateur unique du marché (nom et email obligatoires). Il gère le marché, les pages et les articles, et prend la décision finale. Pour noter les dossiers à plusieurs, choisissez « Votes multiples » et complétez le tableau « Votant pour la sélection ». L’administrateur participe aussi aux votes. Chaque personne note dans « Examiner » ; la colonne « Point » affiche le total et la participation.', 'marche-potier' ); ?></p>
			<?php else : ?>
			<h2><?php esc_html_e( 'Examiner et voter', 'marche-potier' ); ?></h2>
			<p><?php esc_html_e( 'Ouvrez « Examiner les candidatures », choisissez votre édition puis un dossier. Dans « Votes pour la sélection », choisissez votre note de 0 à 5 et cliquez sur « Valider ». En mode votes multiples, vous pouvez consulter les autres notes et modifier la vôtre à tout moment, indépendamment des dates d’inscription.', 'marche-potier' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
