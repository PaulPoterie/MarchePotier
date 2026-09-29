<?php
/**
 * Enregistrement des hooks publics et d’administration, puis accueil du plugin.
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
	}

	public static function register_menu(): void {
		add_menu_page(
			__( 'Poterie Navarraise — Organisation de marchés potiers', 'poterie-navarraise-market-manager' ),
			__( 'Poterie Navarraise', 'poterie-navarraise-market-manager' ),
			'marcpo_access_market',
			'marche-potier',
			array( self::class, 'render_dashboard' ),
			'dashicons-store'
		);
	}

	public static function render_dashboard(): void {
		if ( ! current_user_can( 'marcpo_access_market' ) ) {
			wp_die( esc_html__( 'Vous ne pouvez pas accéder à cette page.', 'poterie-navarraise-market-manager' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Poterie Navarraise — Organisation de marchés potiers', 'poterie-navarraise-market-manager' ); ?></h1>
			<p><?php esc_html_e( 'Retrouvez ici les outils pour organiser votre marché, de l’ouverture des candidatures à la publication des potiers sélectionnés.', 'poterie-navarraise-market-manager' ); ?></p>
			<?php foreach ( array( 'marcpo_edition' => array( 'marcpo_manage_editions', 'Gérer les éditions' ), 'marcpo_candidature' => array( 'marcpo_review_applications', 'Examiner les candidatures' ) ) as $type => $item ) : ?>
				<?php if ( current_user_can( $item[0] ) ) : ?>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'marcpo_candidature' === $type ? 'admin.php?page=marcpo-gestion' : 'edit.php?post_type=' . $type ) ); ?>"><?php echo esc_html( $item[1] ); ?></a></p>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php if ( Jury::can_review() ) : ?>
				<p><a class="button" href="<?php echo esc_url( VoteTracking::url() ); ?>"><?php esc_html_e( 'Suivi des votes', 'poterie-navarraise-market-manager' ); ?></a></p>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=marcpo-historique' ) ); ?>"><?php esc_html_e( 'Historique des sélections', 'poterie-navarraise-market-manager' ); ?></a></p>
			<?php endif; ?>
			<?php if ( current_user_can( 'marcpo_manage_editions' ) ) : ?>
			<h2><?php esc_html_e( 'Comment organiser une édition ?', 'poterie-navarraise-market-manager' ); ?></h2>
			<ol>
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
			<h2><?php esc_html_e( 'Examiner et voter', 'poterie-navarraise-market-manager' ); ?></h2>
			<p><?php esc_html_e( 'Ouvrez « Examiner les candidatures », choisissez votre édition puis un dossier. Dans « Ma note », choisissez votre note de 0 à 5 et cliquez sur « Valider ma note ». En mode votes multiples, vous pouvez consulter les autres notes et modifier la vôtre à tout moment, indépendamment des dates d’inscription.', 'poterie-navarraise-market-manager' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
