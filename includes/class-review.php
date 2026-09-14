<?php
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class Review {
	public static function hooks(): void {
		CsvExport::hooks();
		add_action( 'admin_menu', static function () {
			$hook = add_submenu_page( 'marche-potier', 'Gestion des candidatures', 'Gestion des candidatures', 'mp_manage_applications', 'mp-gestion', array( self::class, 'table' ), 2 );
			add_action( 'load-' . $hook, static function () {
				add_screen_option( 'per_page', array( 'label' => 'Nombre d’éléments par page', 'default' => 25, 'option' => 'mp_applications_per_page' ) );
			} );
		} );
		add_filter( 'set_screen_option_mp_applications_per_page', static function ( $status, $option, $value ) {
			return current_user_can( 'mp_manage_applications' ) ? max( 1, min( 999, (int) $value ) ) : false;
		}, 10, 3 );
		add_filter( 'views_edit-mp_candidature', static function ( array $views ): array {
			if ( current_user_can( 'mp_manage_applications' ) ) {
				$views = array( 'mp_management' => '<a href="' . esc_url( admin_url( 'admin.php?page=mp-gestion' ) ) . '">← Gestion des candidatures</a>' ) + $views;
			}
			return $views;
		} );
		// La suppression native conserve ses contrôles ; seul le retour change.
		add_filter( 'wp_redirect', static function ( string $location ): string {
			if ( ! is_admin() || ! current_user_can( 'mp_manage_applications' ) ) { return $location; }
			$parts = wp_parse_url( $location );
			if ( ! is_array( $parts ) || ( $parts['path'] ?? '' ) !== wp_parse_url( admin_url( 'edit.php' ), PHP_URL_PATH ) ) { return $location; }
			parse_str( $parts['query'] ?? '', $query );
			if ( 'mp_candidature' !== ( $query['post_type'] ?? '' ) ) { return $location; }
			foreach ( array( 'trashed', 'deleted' ) as $action ) {
				if ( isset( $query[ $action ] ) && is_scalar( $query[ $action ] ) && absint( $query[ $action ] ) > 0 ) {
					return add_query_arg( 'mp_' . $action, absint( $query[ $action ] ), admin_url( 'admin.php?page=mp-gestion' ) );
				}
			}
			return $location;
		} );
		add_action( 'admin_post_mp_review_decision', array( self::class, 'save_decision' ) );
		add_action( 'admin_enqueue_scripts', static function () {
			if ( 'mp-historique' === ( $_GET['page'] ?? '' ) ) {
				wp_enqueue_style( 'mp-history', plugins_url( '../assets/history.css', __FILE__ ), array(), '0.17.4' );
				return;
			}
			if ( ! in_array( $_GET['page'] ?? '', array( 'mp-gestion', 'mp-dossier' ), true ) ) { return; }
			if ( 'mp-dossier' === ( $_GET['page'] ?? '' ) ) {
				wp_enqueue_style( 'mp-viewer', plugins_url( '../assets/viewer.css', __FILE__ ), array(), '0.12.0' );
				wp_enqueue_script( 'mp-viewer', plugins_url( '../assets/viewer.js', __FILE__ ), array(), '0.12.0', true );
			}
			wp_enqueue_style( 'mp-review', plugins_url( '../assets/review.css', __FILE__ ), array(), '0.12.4' );
			wp_enqueue_script( 'mp-review', plugins_url( '../assets/review.js', __FILE__ ), array(), '0.12.2', true );
		} );
	}

	public static function photos( int $id, string $target = '' ): void {
		$files = Records::data( $id )['files'] ?? array();
		echo '<div class="mp-review-photos">';
		$found = false;
		foreach ( array( 'product1', 'product2', 'product3', 'stand' ) as $slot ) {
			if ( empty( $files[ $slot ] ) ) { continue; }
			$found = true; $url = PrivateFiles::url( $id, $slot );
			echo '<a href="' . esc_url( $target ?: $url ) . '"' . ( $target ? '' : ' data-mp-viewer="image" data-label="' . esc_attr( PrivateFiles::slots()[ $slot ] ) . '" target="_blank" rel="noopener"' ) . '><img loading="lazy" src="' . esc_url( $url ) . '" alt="' . esc_attr( PrivateFiles::slots()[ $slot ] ) . '"></a>';
		}
		if ( ! $found ) { echo '<p>Aucune photo.</p>'; }
		echo '</div>';
	}

	public static function decision_form( int $id ): void {
		if ( ! current_user_can( 'mp_select_applications' ) ) { return; }
		if ( isset( $_GET['mp_decision_saved'] ) ) { echo '<div class="notice notice-success inline"><p>Sélection enregistrée.</p></div>'; }
		echo '<form method="post" action="' . esc_url( add_query_arg( Records::list_context(), admin_url( 'admin-post.php' ) ) ) . '" class="mp-review-decision"><input type="hidden" name="action" value="mp_review_decision"><input type="hidden" name="candidature" value="' . esc_attr( $id ) . '">';
		wp_nonce_field( 'mp_review_decision_' . $id );
		echo '<label for="mp-review-decision"><strong>Sélection pour cette édition</strong></label> <select id="mp-review-decision" name="decision">';
		foreach ( Records::decisions() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( Records::data( $id )['decision'] ?? 'pending', $key, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select> <button class="button button-primary">Enregistrer la sélection</button></form>';
	}

	public static function save_decision(): void {
		if ( ! current_user_can( 'mp_manage_applications' ) || ! current_user_can( 'mp_select_applications' ) ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
		$id = is_string( $_POST['candidature'] ?? null ) ? absint( $_POST['candidature'] ) : 0;
		check_admin_referer( 'mp_review_decision_' . $id );
		$decision = $_POST['decision'] ?? '';
		if ( ! is_string( $decision ) || ! isset( Records::decisions()[ $decision ] ) || 'mp_candidature' !== get_post_type( $id ) || ! in_array( get_post_status( $id ), array( 'publish', 'private', 'draft', 'pending', 'future' ), true ) ) { wp_die( 'Candidature ou décision invalide.', '', array( 'response' => 400 ) ); }
		if ( ! SubmissionLock::acquire() ) { wp_die( 'Un enregistrement est en cours. Réessayez.', '', array( 'response' => 409 ) ); }
		try {
			$data = Records::data( $id );
			if ( empty( $data['potier_id'] ) ) { wp_die( 'Complétez d’abord ce dossier.', '', array( 'response' => 400 ) ); }
			$data['decision'] = $decision;
			update_post_meta( $id, Records::META, wp_slash( $data ) );
			if ( Records::data( $id ) !== $data ) { wp_die( 'Enregistrement impossible.', '', array( 'response' => 500 ) ); }
			update_post_meta( $id, '_mp_decision', $decision );
			if ( get_post_meta( $id, '_mp_decision', true ) !== $decision ) { wp_die( 'Index de sélection non enregistré. Réessayez.', '', array( 'response' => 500 ) ); }
		} finally { SubmissionLock::release(); }
		wp_safe_redirect( add_query_arg( 'mp_decision_saved', '1', Records::view_url( $id, Records::list_context() ) ), 303 );
		exit;
	}

	private static function value( array $field, mixed $value ): string {
		if ( is_array( $value ) ) { return implode( ', ', array_map( static fn( $key ) => $field[3][ $key ] ?? $key, $value ) ); }
		return (string) ( $field[3][ $value ] ?? ( '' === (string) $value ? '—' : $value ) );
	}

	public static function table(): void {
		if ( ! current_user_can( 'mp_manage_applications' ) ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
		$context = Records::list_context(); $context['mp_from'] = 'gestion';
		$ids = Records::navigation_ids( $context );
		$per_page = max( 1, min( 999, (int) ( get_user_option( 'mp_applications_per_page' ) ?: 25 ) ) );
		$pages = max( 1, (int) ceil( count( $ids ) / $per_page ) );
		$page = min( $pages, max( 1, $context['paged'] ?? 1 ) ); $context['paged'] = $page;
		$trash_count = (int) ( wp_count_posts( 'mp_candidature', 'readable' )->trash ?? 0 );
		$trash_url = admin_url( 'edit.php?post_status=trash&post_type=mp_candidature' );
		$export_url = wp_nonce_url( add_query_arg( array_merge( $context, array( 'action' => 'mp_export_csv' ) ), admin_url( 'admin-post.php' ) ), 'mp_export_csv' );
		echo '<div class="wrap"><div class="mp-management-heading"><h1>Gestion des candidatures</h1><a class="button button-small mp-export-button" href="' . esc_url( $export_url ) . '" aria-label="Exporter les candidatures filtrées au format CSV, toutes les pages" title="Exporter les candidatures filtrées, toutes les pages">Exporter CSV</a></div><hr class="wp-header-end">';
		if ( ! empty( $_GET['mp_trashed'] ) ) { echo '<div class="notice notice-success"><p>Candidature(s) placée(s) dans la corbeille. Vous pouvez les restaurer depuis le bouton Corbeille.</p></div>'; }
		if ( ! empty( $_GET['mp_deleted'] ) ) { echo '<div class="notice notice-success"><p>Candidature(s) supprimée(s) définitivement.</p></div>'; }
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=mp_candidature' ) ) . '">Ajouter une candidature</a> <a class="button" href="' . esc_url( $trash_url ) . '">Corbeille (' . esc_html( (string) $trash_count ) . ')</a></p><form method="get"><input type="hidden" name="page" value="mp-gestion">';
		echo '<label class="screen-reader-text" for="mp-search">Rechercher une candidature</label><input type="search" id="mp-search" name="s" value="' . esc_attr( $context['s'] ?? '' ) . '" placeholder="Nom, atelier, email, ville…"> ';
		Records::render_list_filters( 'mp_candidature' );
		echo ' <button class="button">Rechercher / Filtrer</button></form><p>' . esc_html( count( $ids ) ) . ' candidature(s). Cliquez sur Examiner pour étudier le dossier, ou sur Modifier pour éditer ses coordonnées et sa candidature. Faites défiler le tableau horizontalement pour voir tous les champs.</p>';
		$groups = array( 'identity' => Fields::identity(), 'activity' => Fields::activity(), 'internal' => Fields::internal() );
		if ( ! $ids ) { echo '<p>Aucune candidature pour ces filtres.</p></div>'; return; }
		echo '<div class="mp-review-scroll" tabindex="0" role="region" aria-label="Tableau des candidatures"><table class="widefat striped mp-review-table"><thead><tr><th scope="col">Potier / édition</th><th scope="col">Photos</th><th scope="col">Sélection</th>';
		foreach ( $groups as $schema ) { foreach ( $schema as $field ) { echo '<th scope="col">' . esc_html( $field[0] ) . '</th>'; } }
		echo '<th scope="col">Justificatifs</th><th scope="col">Dépôt / autorisation de présentation</th></tr></thead><tbody>';
		foreach ( array_slice( $ids, ( $page - 1 ) * $per_page, $per_page ) as $id ) {
			$data = Records::data( $id ); $url = Records::view_url( $id, $context );
			$name = trim( ( $data['identity']['last_name'] ?? '' ) . ' ' . ( $data['identity']['first_name'] ?? '' ) );
			echo '<tr data-mp-dossier="' . esc_url( $url ) . '"><th scope="row" class="mp-review-person"><strong>' . esc_html( $name ?: get_the_title( $id ) ) . '</strong>';
			if ( ! empty( $data['edition_id'] ) ) { echo '<br><small>' . esc_html( get_the_title( $data['edition_id'] ) ) . '</small>'; }
			echo '<p class="mp-review-actions"><a class="button button-primary" href="' . esc_url( $url ) . '">Examiner</a> <a class="button" href="' . esc_url( get_edit_post_link( $id, 'raw' ) ) . '">Modifier</a></p></th><td>';
			self::photos( $id, $url );
			echo '</td><td>' . esc_html( Records::decisions()[ $data['decision'] ?? 'pending' ] ?? 'À examiner' ) . '</td>';
			foreach ( $groups as $group => $schema ) { foreach ( $schema as $key => $field ) { echo '<td>' . nl2br( esc_html( self::value( $field, $data[ $group ][ $key ] ?? '' ) ) ) . '</td>'; } }
			echo '<td>';
			foreach ( array( 'status', 'insurance' ) as $slot ) { if ( ! empty( $data['files'][ $slot ] ) ) { echo '<p><a href="' . esc_url( PrivateFiles::url( $id, $slot ) ) . '" target="_blank" rel="noopener">' . esc_html( PrivateFiles::slots()[ $slot ] ) . '</a></p>'; } }
			echo '</td><td>' . esc_html( $data['submitted_at'] ?? 'Saisie interne' ) . '<br>Présentation publique : ' . ( ! empty( $data['publication_consent'] ) ? 'Oui' : 'Non' ) . '</td></tr>';
		}
		echo '</tbody></table></div><p>Page ' . esc_html( $page . ' / ' . $pages ) . ' ';
		foreach ( array( $page - 1 => '← Précédente', $page + 1 => 'Suivante →' ) as $number => $label ) { if ( $number >= 1 && $number <= $pages ) { echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( $context, array( 'page' => 'mp-gestion', 'paged' => $number ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a> '; } }
		echo '</p></div>';
	}
}
