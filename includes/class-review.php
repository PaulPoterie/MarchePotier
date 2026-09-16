<?php
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class Review {
	public static function hooks(): void {
		CsvExport::hooks();
		add_action( 'admin_menu', static function () {
			$hook = add_submenu_page( 'marche-potier', 'Gestion des candidatures', 'Gestion des candidatures', 'mp_review_applications', 'mp-gestion', array( self::class, 'table' ), 2 );
			add_action( 'load-' . $hook, static function () {
				add_screen_option( 'per_page', array( 'label' => 'Nombre d’éléments par page', 'default' => 25, 'option' => 'mp_applications_per_page' ) );
			} );
		} );
		add_filter( 'set_screen_option_mp_applications_per_page', static function ( $status, $option, $value ) {
			return Jury::can_review() ? max( 1, min( 999, (int) $value ) ) : false;
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
			wp_enqueue_style( 'mp-review', plugins_url( '../assets/review.css', __FILE__ ), array(), '0.19.0-beta.9.4' );
			wp_enqueue_script( 'mp-review', plugins_url( '../assets/review.js', __FILE__ ), array(), '0.19.0-beta.9.1', true );
		} );
	}

	public static function photos( int $id, string $target = '', bool $captions = false ): void {
		$files = Records::data( $id )['files'] ?? array();
		echo '<div class="mp-review-photos">';
		$found = false;
		foreach ( array( 'product1', 'product2', 'product3', 'stand' ) as $slot ) {
			if ( empty( $files[ $slot ] ) ) { continue; }
			$found = true; $url = PrivateFiles::url( $id, $slot );
			if ( $captions ) { echo '<figure>'; }
			echo '<a href="' . esc_url( $target ?: $url ) . '"' . ( $target ? '' : ' data-mp-viewer="image" data-label="' . esc_attr( PrivateFiles::slots()[ $slot ] ) . '" target="_blank" rel="noopener"' ) . '><img loading="lazy" src="' . esc_url( $url ) . '" alt="' . esc_attr( PrivateFiles::slots()[ $slot ] ) . '"></a>';
			if ( $captions ) { echo '<figcaption>' . esc_html( PrivateFiles::slots()[ $slot ] ) . '</figcaption></figure>'; }
		}
		if ( ! $found ) { echo '<p>Aucune photo.</p>'; }
		echo '</div>';
	}

	public static function highlights( array $activity ): void {
		$schema = Fields::activity();
		echo '<dl class="mp-examiner-facts">';
		foreach ( array( 'production' => 'Production', 'technique' => 'Techniques', 'stand_length' => 'Stand demandé' ) as $key => $label ) {
			$values = (array) ( $activity[ $key ] ?? array() );
			$value = implode( ', ', array_filter( array_map( static fn( $item ) => $schema[ $key ][3][ $item ] ?? $item, $values ) ) );
			if ( ! empty( $activity[ $key . '_other' ] ) ) { $value .= ( $value ? ' · ' : '' ) . $activity[ $key . '_other' ]; }
			if ( 'stand_length' === $key && '' !== $value ) { $value .= ' m'; }
			echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( '' !== $value ? $value : 'Non renseigné' ) . '</dd></div>';
		}
		echo '</dl>';
	}

	/** Masque uniquement les lignes vides dans les rubriques secondaires du dossier. */
	public static function details_summary( array $schema, array $data ): void {
		$data = Fields::normalize_status( $data );
		$schema = array_filter( $schema, static fn( $key ) => isset( $data[ $key ] ) && '' !== $data[ $key ] && array() !== $data[ $key ], ARRAY_FILTER_USE_KEY );
		if ( ! $schema ) { echo '<p>Aucune information renseignée.</p>'; return; }
		Fields::summary( $schema, $data );
	}

	public static function decision_form( int $id ): void {
		if ( ! current_user_can( 'mp_manage_applications' ) || ! current_user_can( 'mp_select_applications' ) ) { return; }
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

	/** Une information par ligne ; les précisions facultatives vides restent masquées. */
	private static function grouped_fields( array $schema, array $data, array $keys, array $labels = array() ): void {
		echo '<dl class="mp-review-lines">';
		foreach ( $keys as $key ) {
			$field = $schema[ $key ]; $raw = $data[ $key ] ?? '';
			if ( ( '' === $raw || array() === $raw ) && ! $field[2] ) { continue; }
			$value = self::value( $field, $raw );
			$html = nl2br( esc_html( '' !== $value ? $value : '—' ) );
			if ( 'url' === $field[1] && '' !== $raw ) {
				$url = esc_url( $value, array( 'http', 'https' ) );
				if ( $url ) { $html = '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . esc_html( $value ) . '</a>'; }
			} elseif ( 'email' === $field[1] && is_email( $value ) ) {
				$html = '<a href="' . esc_url( 'mailto:' . $value ) . '">' . esc_html( $value ) . '</a>';
			} elseif ( 'tel' === $field[1] && preg_match( '/[0-9]/', $value ) ) {
				$html = '<a href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $value ) ) . '">' . esc_html( $value ) . '</a>';
			}
			echo '<div><dt>' . esc_html( $labels[ $key ] ?? $field[0] ) . '</dt><dd>' . $html . '</dd></div>';
		}
		echo '</dl>';
	}

	public static function table(): void {
		if ( ! Jury::can_review() ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
		$context = Records::list_context(); $context['mp_from'] = 'gestion';
		$ids = Records::navigation_ids( $context );
		$per_page = max( 1, min( 999, (int) ( get_user_option( 'mp_applications_per_page' ) ?: 25 ) ) );
		$pages = max( 1, (int) ceil( count( $ids ) / $per_page ) );
		$page = min( $pages, max( 1, $context['paged'] ?? 1 ) ); $context['paged'] = $page;
		$trash_count = (int) ( wp_count_posts( 'mp_candidature', 'readable' )->trash ?? 0 );
		$trash_url = admin_url( 'edit.php?post_status=trash&post_type=mp_candidature' );
		$export_url = wp_nonce_url( add_query_arg( array_merge( $context, array( 'action' => 'mp_export_csv' ) ), admin_url( 'admin-post.php' ) ), 'mp_export_csv' );
		echo '<div class="wrap"><div class="mp-management-heading"><h1>Gestion des candidatures</h1>';
		if ( current_user_can( 'mp_manage_applications' ) ) { echo '<a class="button button-small mp-export-button" href="' . esc_url( $export_url ) . '">Exporter CSV</a>'; }
		echo '</div><hr class="wp-header-end">';
		if ( ! empty( $_GET['mp_trashed'] ) ) { echo '<div class="notice notice-success"><p>Candidature(s) placée(s) dans la corbeille. Vous pouvez les restaurer depuis le bouton Corbeille.</p></div>'; }
		if ( ! empty( $_GET['mp_deleted'] ) ) { echo '<div class="notice notice-success"><p>Candidature(s) supprimée(s) définitivement.</p></div>'; }
		if ( current_user_can( 'mp_manage_applications' ) ) { echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=mp_candidature' ) ) . '">Ajouter une candidature</a> <a class="button" href="' . esc_url( $trash_url ) . '">Corbeille (' . esc_html( (string) $trash_count ) . ')</a></p>'; }
		echo '<form method="get"><input type="hidden" name="page" value="mp-gestion">';
		if ( isset( $context['orderby'] ) ) { echo '<input type="hidden" name="orderby" value="' . esc_attr( $context['orderby'] ) . '"><input type="hidden" name="order" value="' . esc_attr( $context['order'] ?? 'DESC' ) . '">'; }
		echo '<label class="screen-reader-text" for="mp-search">Rechercher une candidature</label><input type="search" id="mp-search" name="s" value="' . esc_attr( $context['s'] ?? '' ) . '" placeholder="Nom, atelier, email, ville…"> ';
		Records::render_list_filters( 'mp_candidature' );
		echo ' <label class="screen-reader-text" for="mp-my-vote-filter">Filtrer par mon vote</label><select id="mp-my-vote-filter" name="mp_my_vote">';
		foreach ( array( '' => 'Tous (avec ou sans notes)', 'rated' => 'Déjà noté par moi', 'unrated' => 'À noter par moi' ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $context['mp_my_vote'] ?? '', $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo ' <button class="button">Rechercher / Filtrer</button></form><p>' . esc_html( count( $ids ) ) . ' candidature(s). Cliquez sur Examiner pour étudier le dossier et voter si l’édition utilise les votes multiples. Faites défiler le tableau horizontalement pour voir toutes les colonnes.</p>';
		echo '<p class="description">Les filtres de note concernent les éditions dans lesquelles vous participez aux votes multiples.</p>';
		if ( ! $ids ) { echo '<p>Aucune candidature pour ces filtres.</p></div>'; return; }
		$columns = array( 'person' => 'Potier / édition', 'photos' => 'Photos', 'selection' => 'Sélection et points', 'identity' => 'Identité', 'contact' => 'Contact', 'presentation' => 'Présentation', 'production' => 'Production et techniques', 'status' => 'Statuts et vie associative' );
		$identity_schema = Fields::identity(); $activity_schema = Fields::activity();
		$votes = Votes::all( array_slice( $ids, ( $page - 1 ) * $per_page, $per_page ) );
		echo '<div class="mp-review-scroll" tabindex="0" role="region" aria-label="Tableau des candidatures"><table class="widefat striped mp-review-table"><colgroup>';
		foreach ( $columns as $key => $label ) { echo '<col class="mp-column-' . esc_attr( $key ) . '">'; }
		echo '</colgroup><thead><tr>';
		foreach ( $columns as $label ) { echo '<th scope="col">' . esc_html( $label ) . '</th>'; }
		echo '</tr></thead><tbody>';
		foreach ( array_slice( $ids, ( $page - 1 ) * $per_page, $per_page ) as $id ) {
			$data = Records::data( $id ); $url = Records::view_url( $id, $context );
			$identity = $data['identity'] ?? array(); $activity = Fields::normalize_status( $data['activity'] ?? array() );
			echo '<tr data-mp-dossier="' . esc_url( $url ) . '"><th scope="row" class="mp-review-person">';
			$name = trim( ( $identity['last_name'] ?? '' ) . ' ' . ( $identity['first_name'] ?? '' ) );
			echo '<strong>' . esc_html( $name ?: get_the_title( $id ) ) . '</strong>';
			if ( ! empty( $data['edition_id'] ) ) { echo '<p class="mp-review-edition">' . esc_html( get_the_title( $data['edition_id'] ) ) . '</p>'; }
			echo '<p class="mp-review-actions"><a class="button button-primary" href="' . esc_url( $url ) . '">Examiner</a>';
			if ( current_user_can( 'mp_manage_applications' ) ) { echo ' <a class="button" href="' . esc_url( get_edit_post_link( $id, 'raw' ) ) . '">Modifier</a>'; }
			echo '</p>';
			$submitted = ! empty( $data['submitted_at'] ) ? strtotime( $data['submitted_at'] ) : false;
			$submitted_label = false !== $submitted ? wp_date( get_option( 'date_format' ) . ' à ' . get_option( 'time_format' ), $submitted ) : ( 'public' === ( $data['source'] ?? '' ) ? 'Non renseignée' : 'Saisie interne' );
			echo '<p class="mp-review-submitted"><strong>Soumission :</strong><br>' . esc_html( $submitted_label ) . '</p>';
			$mine = Votes::mine( $id, $votes[ $id ] ?? array() );
			if ( null !== $mine ) {
				echo '<p class="mp-my-vote">' . esc_html( 'Ma note (' . $mine['name'] . ') : ' ) . '<strong>' . esc_html( null === $mine['score'] ? 'À noter' : $mine['score'] . '/5' ) . '</strong></p>';
			}
			echo '</th><td>';
			self::photos( $id, $url );
			$decision = in_array( $data['decision'] ?? '', array( 'selected', 'rejected' ), true ) ? $data['decision'] : 'pending';
			echo '</td><td class="mp-review-selection"><p class="mp-selection-badge mp-selection-' . esc_attr( $decision ) . '">' . esc_html( Records::decisions()[ $decision ] ) . '</p>';
			$summary = Votes::summary( $id, $votes[ $id ] ?? array() );
			echo '<p class="mp-points">' . ( $summary['multiple'] ? '<strong>' . esc_html( $summary['count'] ? $summary['total'] . ' points' : '—' ) . '</strong><br><small>' . esc_html( $summary['count'] . ' vote(s) sur ' . $summary['expected'] ) . '</small>' : 'Sans notation' ) . '</p></td><td>';
			self::grouped_fields( $identity_schema, $identity, array( 'last_name', 'first_name', 'company', 'address' ) );
			$locality = trim( ( $identity['postcode'] ?? '' ) . ' ' . ( $identity['city'] ?? '' ) );
			$country = trim( $identity['country'] ?? '' );
			if ( '' !== $country ) { $locality .= ( '' !== $locality ? ', ' : '' ) . $country; }
			echo '<p class="mp-review-location">' . esc_html( '' !== $locality ? $locality : '—' ) . '</p></td><td>';
			self::grouped_fields( $identity_schema, $identity, array( 'phone', 'email', 'website', 'facebook', 'instagram' ) );
			echo '</td><td class="mp-review-presentation">' . nl2br( esc_html( ! empty( $activity['presentation'] ) ? $activity['presentation'] : '—' ) ) . '</td><td>';
			self::grouped_fields( $activity_schema, $activity, array( 'production', 'production_other', 'technique', 'technique_other' ) );
			echo '</td><td>';
			self::grouped_fields( $activity_schema, $activity, array( 'professional_status', 'status_other', 'aaf_member', 'association_member', 'association_names', 'association_details' ), array( 'aaf_member' => 'Ateliers d’Art de France', 'association_member' => 'Association professionnelle', 'association_names' => 'Association(s)', 'association_details' => 'Implication associative' ) );
			echo '</td></tr>';
		}
		echo '</tbody></table></div><p>Page ' . esc_html( $page . ' / ' . $pages ) . ' ';
		foreach ( array( $page - 1 => '← Précédente', $page + 1 => 'Suivante →' ) as $number => $label ) { if ( $number >= 1 && $number <= $pages ) { echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( $context, array( 'page' => 'mp-gestion', 'paged' => $number ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a> '; } }
		echo '</p></div>';
	}
}
