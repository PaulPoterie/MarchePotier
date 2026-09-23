<?php
/** Fiches privées et consultation des candidatures dans l'administration. */
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class Records {
	public const META = '_marcpo_record';
	private const STATUSES = array( 'publish', 'private', 'draft', 'pending', 'future' );
	private static bool $updating_title = false;

	public static function hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'admin_init', array( self::class, 'permissions' ) );
		foreach ( array( 'marcpo_potier', 'marcpo_candidature' ) as $type ) {
			add_action( 'add_meta_boxes_' . $type, array( self::class, 'boxes' ) );
			add_action( 'save_post_' . $type, array( self::class, 'save' ), 10, 2 );
		}
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'restrict_manage_posts', array( self::class, 'render_list_filters' ) );
		add_action( 'pre_get_posts', array( self::class, 'apply_list_filters' ) );
		add_filter( 'manage_marcpo_candidature_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_marcpo_candidature_posts_custom_column', array( self::class, 'column_content' ), 10, 2 );
		add_action( 'quick_edit_custom_box', array( self::class, 'quick_edit' ), 10, 2 );
		add_action( 'save_post_marcpo_candidature', array( self::class, 'save_inline_decision' ), 20 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'quick_edit_script' ) );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	public static function permissions(): void {
		if ( '1' === get_option( 'marcpo_records_permissions_version' ) || ! current_user_can( 'manage_options' ) ) { return; }
		foreach ( array( 'administrator', 'marcpo_organizer' ) as $name ) {
			$role = get_role( $name );
			if ( ! $role ) { return; }
			foreach ( array( 'marcpo_access_market', 'marcpo_manage_potiers', 'marcpo_manage_applications', 'marcpo_select_applications' ) as $cap ) {
				$role->add_cap( $cap );
			}
		}
		update_option( 'marcpo_records_permissions_version', '1', false );
	}

	/** Ajoute l'index de décision aux dossiers créés avant les filtres. */
	public static function migrate_decision_index(): void {
		if ( '1' === get_option( 'marcpo_decision_index_version' ) || ! current_user_can( 'marcpo_manage_applications' ) ) {
			return;
		}
		foreach ( get_posts( array( 'post_type' => 'marcpo_candidature', 'post_status' => array_merge( self::STATUSES, array( 'trash' ) ), 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
			$data = self::data( $id );
			$decision = $data['decision'] ?? 'pending';
			if ( isset( self::decisions()[ $decision ] ) ) {
				update_post_meta( $id, '_marcpo_decision', $decision );
			}
		}
		update_option( 'marcpo_decision_index_version', '1', false );
	}

	public static function register(): void {
		foreach ( array( 'marcpo_potier' => array( 'Identités (historique)', 'Identité', 'marcpo_manage_potiers' ), 'marcpo_candidature' => array( 'Candidatures', 'Candidature', 'marcpo_manage_applications' ) ) as $type => $config ) {
			register_post_type( $type, array(
				'labels' => array( 'name' => $config[0], 'singular_name' => $config[1], 'add_new' => 'Ajouter', 'add_new_item' => 'Ajouter : ' . $config[1], 'edit_item' => 'Modifier : ' . $config[1], 'not_found' => 'Aucun dossier.' ),
				'public' => false, 'publicly_queryable' => false, 'exclude_from_search' => true,
				'show_ui' => true, 'show_in_menu' => false, 'show_in_rest' => false,
				'show_in_nav_menus' => false, 'has_archive' => false, 'rewrite' => false,
				'query_var' => false, 'can_export' => false, 'supports' => 'marcpo_potier' === $type ? array() : array( 'title' ),
				'map_meta_cap' => false,
				'capabilities' => array_merge( array_fill_keys( array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts', 'read' ), $config[2] ), 'marcpo_potier' === $type ? array( 'create_posts' => 'do_not_allow', 'delete_post' => 'do_not_allow', 'delete_posts' => 'do_not_allow' ) : array() ),
			) );
		}
	}

	public static function data( int $id ): array {
		$data = get_post_meta( $id, self::META, true );
		if ( is_array( $data ) && isset( $data['activity'] ) && is_array( $data['activity'] ) ) { $data['activity'] = Fields::normalize_status( $data['activity'] ); }
		return is_array( $data ) ? $data : array();
	}


	public static function potier_schema(): array {
		return array_intersect_key( Fields::identity(), array_flip( array( 'last_name', 'first_name', 'email' ) ) );
	}
	public static function minimal_identity( array $identity ): array {
		return array_intersect_key( $identity, self::potier_schema() );
	}
	/** Appelé sous le verrou commun des écritures. */
	public static function create_potier( array $identity ): int|\WP_Error {
		$id = wp_insert_post( wp_slash( array( 'post_type' => 'marcpo_potier', 'post_status' => 'publish', 'post_title' => trim( $identity['last_name'] . ' ' . $identity['first_name'] ) ) ), true );
		if ( is_wp_error( $id ) ) { return $id; }
		if ( ! update_post_meta( $id, self::META, wp_slash( array( 'identity' => self::minimal_identity( $identity ) ) ) ) ) {
			wp_delete_post( $id, true );
			return new \WP_Error( 'marcpo_identity', 'Impossible d’enregistrer l’identité du potier.' );
		}
		return $id;
	}

	public static function decisions(): array {
		return array( 'pending' => 'À examiner', 'selected' => 'Sélectionné', 'rejected' => 'Non sélectionné' );
	}

	public static function render_list_filters( string $post_type, bool $grouped = false ): void {
		if ( 'marcpo_candidature' !== $post_type || ! Jury::can_review() ) {
			return;
		}
		$edition = (int) ( Request::query( 'marcpo_edition' ) ?? 0 );
		$decision = Request::query( 'marcpo_decision' ) ?? '';
		if ( $grouped ) { echo '<div class="marcpo-filter-field">'; }
		echo '<label' . ( $grouped ? '' : ' class="screen-reader-text"' ) . ' for="marcpo-edition-filter">' . ( $grouped ? 'Édition' : 'Filtrer par édition' ) . '</label><select id="marcpo-edition-filter" name="marcpo_edition"><option value="">Toutes les éditions</option>';
		foreach ( get_posts( array( 'post_type' => 'marcpo_edition', 'post_status' => self::STATUSES, 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) ) as $item ) {
			if ( ! Jury::can_view_edition( $item->ID ) ) { continue; }
			echo '<option value="' . esc_attr( $item->ID ) . '" ' . selected( $edition, $item->ID, false ) . '>' . esc_html( $item->post_title ) . '</option>';
		}
		echo '</select> ';
		if ( $grouped ) { echo '</div><div class="marcpo-filter-field">'; }
		echo '<label' . ( $grouped ? '' : ' class="screen-reader-text"' ) . ' for="marcpo-decision-filter">' . ( $grouped ? 'Sélection' : 'Filtrer par décision' ) . '</label><select id="marcpo-decision-filter" name="marcpo_decision"><option value="">Toutes les décisions</option>';
		foreach ( self::decisions() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $decision, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		if ( $grouped ) { echo '</div>'; }
	}

	public static function apply_list_filters( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'marcpo_candidature' !== $query->get( 'post_type' ) || ! current_user_can( 'marcpo_manage_applications' ) ) {
			return;
		}
		$meta_query = (array) $query->get( 'meta_query' );
		$edition = (int) ( Request::query( 'marcpo_edition' ) ?? 0 );
		$decision = Request::query( 'marcpo_decision' ) ?? '';
		if ( $edition && self::valid_reference( $edition, 'marcpo_edition' ) ) {
			$meta_query[] = array( 'key' => '_marcpo_edition_id', 'value' => $edition, 'type' => 'NUMERIC' );
		}
		if ( isset( self::decisions()[ $decision ] ) ) {
			$meta_query[] = array( 'key' => '_marcpo_decision', 'value' => $decision );
		}
		if ( $meta_query ) {
			$query->set( 'meta_query', $meta_query );
		}
		$orderby = $query->get( 'orderby' ) ?: 'date';
		if ( is_string( $orderby ) && in_array( $orderby, array( 'title', 'date', 'modified', 'ID', 'author' ), true ) ) {
			$order = strtoupper( $query->get( 'order' ) ?: 'DESC' );
			$query->set( 'orderby', array( $orderby => $order, 'ID' => $order ) );
		}
	}

	public static function columns( array $columns ): array {
		$result = array();
		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'title' === $key ) { $result['marcpo_decision'] = 'Sélection'; }
		}
		return $result;
	}

	public static function column_content( string $column, int $id ): void {
		if ( 'marcpo_decision' !== $column ) { return; }
		$decision = self::data( $id )['decision'] ?? 'pending';
		echo '<span data-marcpo-decision="' . esc_attr( $decision ) . '">' . esc_html( self::decisions()[ $decision ] ?? 'À examiner' ) . '</span>';
	}

	public static function quick_edit( string $column, string $post_type ): void {
		if ( 'marcpo_decision' !== $column || 'marcpo_candidature' !== $post_type || ! current_user_can( 'marcpo_select_applications' ) ) { return; }
		echo '<fieldset class="inline-edit-col-right"><div class="inline-edit-col"><label><span class="title">Sélection</span><select name="marcpo_inline_decision">';
		foreach ( self::decisions() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>'; }
		echo '</select></label></div></fieldset>';
	}

	public static function quick_edit_script(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base || 'marcpo_candidature' !== $screen->post_type || ! current_user_can( 'marcpo_select_applications' ) ) { return; }
		wp_enqueue_script( 'marcpo-quick-edit', plugins_url( '../assets/quick-edit.js', __FILE__ ), array( 'inline-edit-post' ), '0.19.1', true );
	}

	public static function save_inline_decision( int $id ): void {
		if ( self::$updating_title || wp_is_post_revision( $id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || 'marcpo_candidature' !== get_post_type( $id ) || ! current_user_can( 'marcpo_select_applications' ) || ! current_user_can( 'edit_post', $id ) ) { return; }
		$nonce = Request::post( '_inline_edit' ) ?? null;
		$decision = Request::post( 'marcpo_inline_decision' ) ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( sanitize_text_field( $nonce ), 'inlineeditnonce' ) || ! is_string( $decision ) || ! isset( self::decisions()[ $decision ] ) ) { return; }
		$data = self::data( $id );
		if ( empty( $data['potier_id'] ) || empty( $data['edition_id'] ) ) { return; }
		$data['decision'] = $decision;
		update_post_meta( $id, self::META, wp_slash( $data ) );
		update_post_meta( $id, '_marcpo_decision', $decision );
	}

	public static function boxes( \WP_Post $post ): void {
		add_meta_box( 'marcpo-record', 'marcpo_potier' === $post->post_type ? 'Identité du potier' : 'Dossier de candidature', array( self::class, 'edit' ), $post->post_type, 'normal', 'high' );
	}

	private static function selector( string $key, string $type, string $label ): void {
		echo '<p><label for="marcpo-' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>';
		echo '<select required id="marcpo-' . esc_attr( $key ) . '" name="marcpo_record[' . esc_attr( $key ) . ']"><option value="">Choisir…</option>';
		foreach ( get_posts( array( 'post_type' => $type, 'post_status' => self::STATUSES, 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) ) as $item ) {
			echo '<option value="' . esc_attr( $item->ID ) . '">' . esc_html( $item->post_title . ' (#' . $item->ID . ')' ) . '</option>';
		}
		echo '</select>';
		echo '</p>';
	}

	public static function edit( \WP_Post $post ): void {
		$data = self::data( $post->ID );
		wp_nonce_field( 'marcpo_record_' . $post->ID, 'marcpo_record_nonce' );
		echo '<p>Les champs marqués * seront obligatoires pour le dépôt public. La saisie interne peut rester incomplète. Les boutons WordPress enregistrent le dossier sans le rendre public.</p>';
		if ( 'marcpo_potier' === $post->post_type ) {
			Fields::summary( self::potier_schema(), $data['identity'] ?? array() );
			echo '<p>Cette identité sert uniquement à l’historique. Pour corriger un nom, un prénom ou un email, modifiez la candidature concernée.</p>';
			return;
		}
		if ( empty( $data['edition_id'] ) ) {
			self::selector( 'edition_id', 'marcpo_edition', 'Édition' );
		} else {
			echo '<p><strong>Édition :</strong> ' . esc_html( get_the_title( $data['edition_id'] ) ) . '</p>';
		}
		echo '<h3>Coordonnées de cette candidature</h3><p>Nom, prénom et email sont obligatoires et servent au rapprochement dans l’historique. Leur correction ne modifie pas les autres années.</p>';
		Fields::render( Fields::identity(), $data['identity'] ?? array(), 'identity' );
		echo '<h3>Présentation et activité</h3>';
		Fields::render( Fields::activity(), $data['activity'] ?? array(), 'activity' );
		echo '<h3>Suivi organisateur</h3>';
		Fields::render( Fields::internal(), $data['internal'] ?? array(), 'internal' );
		if ( current_user_can( 'marcpo_select_applications' ) ) {
			echo '<p><label for="marcpo-decision">Décision de sélection</label> <select id="marcpo-decision" name="marcpo_record[decision]">';
			foreach ( self::decisions() as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '" ' . selected( $data['decision'] ?? 'pending', $key, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></p>';
		} else {
			echo '<p>Décision : ' . esc_html( self::decisions()[ $data['decision'] ?? 'pending' ] ?? 'À examiner' ) . '</p>';
		}
		echo '<input type="hidden" name="marcpo_record[consent_present]" value="1"><p><label><input type="checkbox" name="marcpo_record[publication_consent]" value="1" ' . checked( ! empty( $data['publication_consent'] ), true, false ) . '> Autorisation de présentation publique obtenue du candidat</label></p>';
		$map_error = get_post_meta( $post->ID, '_marcpo_map_error', true );
		if ( $map_error ) { echo '<p class="description">Carte : ' . esc_html( $map_error ) . ' Enregistrez le dossier corrigé pour relancer la localisation.</p>'; }
		PrivateFiles::edit( $post->ID );
	}

	private static function valid_reference( int $id, string $type ): bool {
		return $id > 0 && get_post_type( $id ) === $type && in_array( get_post_status( $id ), self::STATUSES, true );
	}

	/** Construit le dossier sans écriture ; une erreur ne remplace aucune réponse. */
	public static function prepare( array $raw, array $old, bool $application ): array|\WP_Error {
		$result = $old;
		if ( $application ) {
			$edition = $old['edition_id'] ?? ( $raw['edition_id'] ?? '' );
			if ( ! is_scalar( $edition ) || ! ctype_digit( (string) $edition ) || ! self::valid_reference( (int) $edition, 'marcpo_edition' ) ) { return new \WP_Error( 'marcpo_relation', 'Choisissez une édition valide.' ); }
			$result['edition_id'] = (int) $edition;
			$result['potier_id'] = 0; // Résolu sous verrou après validation des réponses.
		}
		$groups = array( 'identity' => $application ? Fields::identity() : self::potier_schema() );
		if ( $application ) { $groups += array( 'activity' => Fields::activity(), 'internal' => Fields::internal() ); }
		foreach ( $groups as $group => $schema ) {
			if ( ! isset( $raw[ $group ] ) || ! is_array( $raw[ $group ] ) ) { return new \WP_Error( 'marcpo_group', 'Données de formulaire incomplètes. Rechargez le dossier.' ); }
			$value = Fields::validate( $raw[ $group ], $schema );
			if ( is_wp_error( $value ) ) { return $value; }
			$result[ $group ] = $value;
		}
		$key_identity = Fields::validate( $result['identity'], self::potier_schema(), true );
		if ( is_wp_error( $key_identity ) ) { return $key_identity; }
		if ( $application ) {
			$result['decision'] = $old['decision'] ?? 'pending';
			if ( '1' === ( $raw['consent_present'] ?? '' ) ) { $result['publication_consent'] = '1' === ( $raw['publication_consent'] ?? '' ); }
			if ( current_user_can( 'marcpo_select_applications' ) ) {
				$decision = $raw['decision'] ?? $result['decision'];
				if ( ! is_string( $decision ) || ! isset( self::decisions()[ $decision ] ) ) { return new \WP_Error( 'marcpo_decision', 'Décision invalide.' ); }
				$result['decision'] = $decision;
			}
		}
		return $result;
	}

	public static function save( int $id, \WP_Post $post ): void {
		$application = 'marcpo_candidature' === $post->post_type;
		if ( ! $application ) { return; }
		if ( self::$updating_title || ! in_array( $post->post_type, array( 'marcpo_potier', 'marcpo_candidature' ), true ) || wp_is_post_revision( $id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( $application ? 'marcpo_manage_applications' : 'marcpo_manage_potiers' ) ) { return; }
		$nonce = Request::post( 'marcpo_record_nonce' ) ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( sanitize_text_field( $nonce ), 'marcpo_record_' . $id ) ) { return; }
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Structured answers are validated by prepare() below, after the nonce and permission checks.
		$raw = isset( $_POST['marcpo_record'] ) && is_array( $_POST['marcpo_record'] ) ? wp_unslash( $_POST['marcpo_record'] ) : null;
		$data = is_array( $raw ) ? self::prepare( $raw, self::data( $id ), $application ) : new \WP_Error( 'marcpo_form', 'Formulaire invalide.' );
		$locked = false;
		if ( ! is_wp_error( $data ) && class_exists( SubmissionLock::class ) ) {
			$locked = SubmissionLock::acquire();
			if ( ! $locked ) { $data = new \WP_Error( 'marcpo_busy', 'Un autre enregistrement est en cours. Réessayez.' ); }
		}
		try {
		if ( ! is_wp_error( $data ) && $application ) {
			$data['potier_id'] = PublicForm::find_potier( $data['identity'] );
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Existence check limited to one ID for a potter and edition; exclude only the current application.
			$duplicates = $data['potier_id'] ? get_posts( array( 'post_type' => 'marcpo_candidature', 'post_status' => array_merge( self::STATUSES, array( 'trash' ) ), 'posts_per_page' => 1, 'fields' => 'ids', 'post__not_in' => array( $id ), 'meta_query' => array( array( 'key' => '_marcpo_potier_id', 'value' => $data['potier_id'] ), array( 'key' => '_marcpo_edition_id', 'value' => $data['edition_id'] ) ) ) ) : array();
			if ( $duplicates ) { $data = new \WP_Error( 'marcpo_duplicate', 'Ce potier possède déjà une candidature pour cette édition, éventuellement dans la corbeille. Retrouvez le dossier existant.' ); }
			if ( ! is_wp_error( $data ) && class_exists( PublicForm::class ) && ! empty( $data['identity']['email'] ) && PublicForm::duplicate( $data['edition_id'], $data['identity']['email'], $id ) ) { $data = new \WP_Error( 'marcpo_duplicate_email', 'Cette adresse email possède déjà une candidature pour cette édition.' ); }
		}
		if ( is_wp_error( $data ) ) {
			set_transient( 'marcpo_record_error_' . get_current_user_id() . '_' . $id, $data->get_error_message(), 120 );
			return;
		}
		$new_files = array();
		$replaced = array();
		if ( $application ) {
			// Relire sous verrou : un formulaire ouvert avant un remplacement ne doit pas le perdre.
			$current_files = self::data( $id )['files'] ?? array();
			$uploads = array();
			foreach ( PrivateFiles::slots() as $slot => $label ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Upload metadata is checked by PrivateFiles::store; never unslash uploaded bytes or PHP temporary paths.
				if ( isset( $_FILES[ 'marcpo_admin_' . $slot ] ) ) { $uploads[ $slot ] = $_FILES[ 'marcpo_admin_' . $slot ]; }
			}
			if ( $uploads ) { $new_files = PrivateFiles::store( $uploads, true ); }
			if ( is_wp_error( $new_files ) ) {
				set_transient( 'marcpo_record_error_' . get_current_user_id() . '_' . $id, $new_files->get_error_message(), 120 );
				return;
			}
			if ( $new_files || $current_files ) { $data['files'] = array_replace( $current_files, $new_files ); }
			$replaced = array_intersect_key( $current_files, $new_files );
		}
		$created_potier = 0;
		if ( $application && ! $data['potier_id'] ) {
			$created_potier = self::create_potier( $data['identity'] );
			if ( is_wp_error( $created_potier ) ) {
				PrivateFiles::remove( $new_files );
				set_transient( 'marcpo_record_error_' . get_current_user_id() . '_' . $id, $created_potier->get_error_message(), 120 );
				return;
			}
			$data['potier_id'] = $created_potier;
		}
		$saved = update_post_meta( $id, self::META, wp_slash( $data ) );
		if ( ! $saved && self::data( $id ) !== $data ) {
			PrivateFiles::remove( $new_files );
			if ( $created_potier ) { wp_delete_post( $created_potier, true ); }
			set_transient( 'marcpo_record_error_' . get_current_user_id() . '_' . $id, 'Enregistrement impossible. Les fichiers précédents sont conservés.', 120 );
			return;
		}
		MediaLibrary::finalize( $id );
		if ( $application ) {
			// Index de recherche, les réponses restent réunies dans META.
			update_post_meta( $id, '_marcpo_potier_id', $data['potier_id'] );
			update_post_meta( $id, '_marcpo_edition_id', $data['edition_id'] );
			update_post_meta( $id, '_marcpo_decision', $data['decision'] );
		}
		self::sync_title( $id, $post->post_type, $data );
		delete_transient( 'marcpo_record_error_' . get_current_user_id() . '_' . $id );
		} finally { if ( $locked ) { SubmissionLock::release(); } }
	}

	private static function sync_title( int $id, string $type, array $data ): void {
		if ( 'marcpo_potier' === $type ) {
			$title = trim( ( $data['identity']['last_name'] ?? '' ) . ' ' . ( $data['identity']['first_name'] ?? '' ) );
		} else {
			$title = trim( ( $data['identity']['last_name'] ?? '' ) . ' ' . ( $data['identity']['first_name'] ?? '' ) ) . ' — ' . get_the_title( $data['edition_id'] ?? 0 );
		}
		if ( '' === $title || $title === get_the_title( $id ) ) { return; }
		self::$updating_title = true;
		wp_update_post( array( 'ID' => $id, 'post_title' => $title ) );
		self::$updating_title = false;
	}

	public static function notice(): void {
		$screen = get_current_screen();
		$id = (int) ( Request::query( 'post' ) ?? 0 );
		if ( ! $screen || ! in_array( $screen->post_type, array( 'marcpo_potier', 'marcpo_candidature' ), true ) || ! current_user_can( 'marcpo_candidature' === $screen->post_type ? 'marcpo_manage_applications' : 'marcpo_manage_potiers' ) ) { return; }
		$key = 'marcpo_record_error_' . get_current_user_id() . '_' . $id;
		$error = get_transient( $key );
		if ( $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error . ' Les champs du dossier n’ont pas été enregistrés. Le titre et le statut WordPress peuvent avoir changé ; les valeurs précédentes sont affichées.' ) . '</p></div>';
			delete_transient( $key );
		}
	}

	public static function menu(): void {
		// Page accessible par les liens des dossiers, sans entrée autonome dans le menu.
		$hook = add_submenu_page( '', 'Consulter une candidature', 'Consulter une candidature', 'marcpo_review_applications', 'marcpo-dossier', array( self::class, 'view' ) );
		// Une page sans parent n’est pas retrouvée par get_admin_page_title().
		add_action( 'load-' . $hook, static function () { $GLOBALS['title'] = 'Consulter une candidature'; } );
		add_submenu_page( 'marche-potier', 'Historique des sélections', 'Historique des sélections', 'marcpo_review_applications', 'marcpo-historique', array( self::class, 'history' ) );
	}

	public static function view_url( int $id, array $context = array() ): string {
		return add_query_arg( array_merge( $context, array( 'page' => 'marcpo-dossier', 'candidature' => $id ) ), admin_url( 'admin.php' ) );
	}

	/**
	 * Liste blanche GET partagée par gestion, examen, votes et export.
	 * marcpo_sort est normalisé en orderby/order ; ces deux clés circulent ensuite dans les liens.
	 * marcpo_from indique l’écran de retour, jamais une URL libre fournie par le navigateur.
	 */
	public static function list_context(): array {
		$context = array();
		$from = Request::query( 'marcpo_from' );
		if ( in_array( $from, array( 'gestion', 'votes' ), true ) ) { $context['marcpo_from'] = $from; }
		foreach ( array( 'marcpo_edition', 'paged', 'm', 'author' ) as $key ) {
			$value = Request::query( $key );
			if ( null !== $value && ctype_digit( $value ) && (int) $value > 0 ) { $context[ $key ] = (int) $value; }
		}
		foreach ( array( 'marcpo_decision' => array_keys( self::decisions() ), 'marcpo_my_vote' => array( 'rated', 'unrated' ), 'post_status' => self::STATUSES, 'orderby' => array( 'title', 'date', 'modified', 'ID', 'author', 'points', 'submitted', 'name' ), 'order' => array( 'asc', 'desc', 'ASC', 'DESC' ) ) as $key => $allowed ) {
			$value = Request::query( $key );
			if ( in_array( $value, $allowed, true ) ) { $context[ $key ] = $value; }
		}
		$search = Request::query( 's' );
		if ( null !== $search ) { $context['s'] = $search; }
		$sort = Request::query( 'marcpo_sort' );
		if ( null !== $sort && isset( self::sort_options()[ $sort ] ) ) {
			[ $context['orderby'], $direction ] = explode( '_', $sort );
			$context['order'] = strtoupper( $direction );
		}
		return $context;
	}

	public static function sort_options(): array {
		return array(
			'points_desc' => 'Points — du plus élevé au plus faible',
			'points_asc' => 'Points — du plus faible au plus élevé',
			'submitted_desc' => 'Soumission — plus récentes d’abord',
			'submitted_asc' => 'Soumission — plus anciennes d’abord',
			'name_asc' => 'Nom — A à Z',
			'name_desc' => 'Nom — Z à A',
		);
	}

	/** Tri des données du dossier, distinctes du titre et de la date de création WordPress. */
	private static function sort_ids( array $ids, string $by, string $order ): array {
		if ( 'points' === $by ) { return Votes::sort_ids( $ids, $order ); }
		if ( ! in_array( $by, array( 'submitted', 'name' ), true ) ) { return $ids; }
		$values = array();
		foreach ( $ids as $id ) {
			$data = self::data( $id );
			$values[ $id ] = 'submitted' === $by
				? ( ! empty( $data['submitted_at'] ) ? strtotime( $data['submitted_at'] ) : false )
				: array_map( static fn( $value ) => strtolower( remove_accents( trim( $value ) ) ), array( $data['identity']['last_name'] ?? '', $data['identity']['first_name'] ?? '' ) );
		}
		$direction = 'ASC' === strtoupper( $order ) ? 1 : -1;
		usort( $ids, static function ( int $a, int $b ) use ( $values, $by, $direction ): int {
			$left = $values[ $a ]; $right = $values[ $b ];
			$missing_left = 'submitted' === $by ? false === $left : '' === $left[0];
			$missing_right = 'submitted' === $by ? false === $right : '' === $right[0];
			// Une valeur absente reste à la fin, quel que soit le sens du tri.
			if ( $missing_left !== $missing_right ) { return $missing_left ? 1 : -1; }
			$comparison = 'submitted' === $by ? $left <=> $right : ( strcmp( $left[0], $right[0] ) ?: strcmp( $left[1], $right[1] ) );
			return $direction * ( $comparison ?: ( $a <=> $b ) );
		} );
		return $ids;
	}

	/**
	 * Tous les IDs consultables par la session, filtrés puis triés ; aucune pagination ici.
	 * La gestion découpe cette liste, l’export la prend entière, Examiner y trouve ses voisins.
	 * Les tris title/date restent reconnus pour les liens issus des écrans WordPress natifs.
	 */
	public static function navigation_ids( array $context ): array {
		if ( ! Jury::can_review() ) { return array(); }
		$args = array( 'post_type' => 'marcpo_candidature', 'post_status' => self::STATUSES, 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC' );
		foreach ( array( 'post_status', 'orderby', 'order', 'm', 'author' ) as $key ) {
			if ( isset( $context[ $key ] ) ) { $args[ $key ] = $context[ $key ]; }
		}
		if ( in_array( $args['orderby'], array( 'points', 'submitted', 'name' ), true ) ) { $args['orderby'] = 'date'; }
		// Un second critère garantit un parcours stable lorsque deux dossiers ont la même date.
		$args['orderby'] = array( $args['orderby'] => strtoupper( $args['order'] ), 'ID' => strtoupper( $args['order'] ) );
		if ( ! empty( $context['marcpo_edition'] ) ) {
			if ( ! Jury::can_view_edition( (int) $context['marcpo_edition'] ) ) { return array(); }
			$args['meta_query'][] = array( 'key' => '_marcpo_edition_id', 'value' => $context['marcpo_edition'], 'type' => 'NUMERIC' );
		}
		if ( ! current_user_can( 'marcpo_manage_applications' ) ) {
			$editions = Jury::edition_ids();
			if ( ! $editions ) { return array(); }
			$args['meta_query'][] = array( 'key' => '_marcpo_edition_id', 'value' => $editions, 'compare' => 'IN', 'type' => 'NUMERIC' );
		}
		if ( isset( self::decisions()[ $context['marcpo_decision'] ?? '' ] ) ) { $args['meta_query'][] = array( 'key' => '_marcpo_decision', 'value' => $context['marcpo_decision'] ); }
		$ids = array_map( 'intval', get_posts( $args ) );
		update_meta_cache( 'post', $ids );
		$ids = array_values( array_filter( $ids, array( Jury::class, 'can_view_application' ) ) );
		$search = trim( (string) ( $context['s'] ?? '' ) );
		if ( '' !== $search ) {
			$terms = preg_split( '/\s+/u', strtolower( remove_accents( $search ) ), -1, PREG_SPLIT_NO_EMPTY );
			$ids = array_values( array_filter( $ids, static function ( int $id ) use ( $terms ): bool {
				$identity = self::data( $id )['identity'] ?? array();
				$values = array();
				foreach ( array( 'last_name', 'first_name', 'company', 'email', 'city', 'postcode' ) as $key ) { $values[] = $identity[ $key ] ?? ''; }
				$text = strtolower( remove_accents( implode( ' ', $values ) ) );
				foreach ( $terms as $term ) { if ( ! str_contains( $text, $term ) ) { return false; } }
				return true;
			} ) );
		}
		if ( in_array( $context['marcpo_my_vote'] ?? '', array( 'rated', 'unrated' ), true ) ) {
			$votes = Votes::all( $ids ); $rated = 'rated' === $context['marcpo_my_vote'];
			$ids = array_values( array_filter( $ids, static function ( $id ) use ( $votes, $rated ) {
				$mine = Votes::mine( $id, $votes[ $id ] ?? array() );
				return null !== $mine && $rated === ( null !== $mine['score'] );
			} ) );
		}
		return self::sort_ids( $ids, $context['orderby'] ?? 'submitted', $context['order'] ?? 'DESC' );
	}

	private static function navigation( int $id ): void {
		$context = self::list_context();
		$ids = self::navigation_ids( $context );
		$position = array_search( $id, $ids, true );
		$list_url = add_query_arg( array_merge( $context, array( 'page' => 'marcpo-gestion' ) ), admin_url( 'admin.php' ) );
		if ( 'votes' === ( $context['marcpo_from'] ?? '' ) ) { $list_url = VoteTracking::url( (int) ( self::data( $id )['edition_id'] ?? 0 ) ); }
		echo '<nav class="marcpo-examiner-nav" aria-label="Navigation des candidatures"><div class="marcpo-examiner-nav-main"><a class="button" href="' . esc_url( $list_url ) . '">Retour à la liste</a> ';
		if ( false !== $position ) {
			if ( $position > 0 ) { echo '<a class="button" href="' . esc_url( self::view_url( $ids[ $position - 1 ], $context ) ) . '">← Dossier précédent</a> '; }
			echo '<span class="marcpo-examiner-position">Dossier ' . esc_html( ( $position + 1 ) . ' sur ' . count( $ids ) ) . '</span> ';
			if ( isset( $ids[ $position + 1 ] ) ) { echo '<a class="button" href="' . esc_url( self::view_url( $ids[ $position + 1 ], $context ) ) . '">Dossier suivant →</a> '; }
		} else { echo '<span>Ce dossier ne correspond plus aux filtres de la liste.</span> '; }
		echo '</div><div class="marcpo-examiner-nav-tools"><a class="button" href="' . esc_url( VoteTracking::url( (int) ( self::data( $id )['edition_id'] ?? 0 ) ) ) . '">Suivi des votes</a> ';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=marcpo-historique' ) ) . '">Historique des sélections</a></div></nav>';
	}

	public static function row_actions( array $actions, \WP_Post $post ): array {
		if ( 'marcpo_candidature' === $post->post_type && current_user_can( 'marcpo_manage_applications' ) ) {
			$actions['marcpo_view'] = '<a href="' . esc_url( self::view_url( $post->ID, self::list_context() ) ) . '">Consulter le dossier</a>';
		}
		return $actions;
	}

	public static function view(): void {
		if ( ! Jury::can_review() ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
		$id = (int) ( Request::query( 'candidature' ) ?? 0 );
		if ( ! self::valid_reference( $id, 'marcpo_candidature' ) ) { wp_die( 'Candidature introuvable.', '', array( 'response' => 404 ) ); }
		if ( ! Jury::can_view_application( $id ) ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
		$data = self::data( $id );
		$identity = $data['identity'] ?? array(); $activity = $data['activity'] ?? array();
		$name = trim( ( $identity['first_name'] ?? '' ) . ' ' . ( $identity['last_name'] ?? '' ) );
		$decision = in_array( $data['decision'] ?? '', array( 'selected', 'rejected' ), true ) ? $data['decision'] : 'pending';
		echo '<div class="wrap marcpo-examiner"><header class="marcpo-examiner-heading"><div><p class="marcpo-examiner-edition">' . esc_html( ! empty( $data['edition_id'] ) ? get_the_title( $data['edition_id'] ) : 'Édition non renseignée' ) . '</p><h1>' . esc_html( $name ?: get_the_title( $id ) ) . '</h1>';
		$subtitle = array_filter( array( $identity['company'] ?? '', trim( ( $identity['postcode'] ?? '' ) . ' ' . ( $identity['city'] ?? '' ) ), $identity['country'] ?? '' ) );
		if ( $subtitle ) { echo '<p class="marcpo-examiner-subtitle">' . esc_html( implode( ' · ', $subtitle ) ) . '</p>'; }
		echo '</div><span class="marcpo-examiner-status marcpo-examiner-status-' . esc_attr( $decision ) . '">Sélection : ' . esc_html( self::decisions()[ $decision ] ) . '</span></header>';
		self::navigation( $id );
		if ( empty( $data['potier_id'] ) ) {
			echo '<p>Ce dossier ne possède pas encore de données enregistrées.</p>';
			if ( current_user_can( 'marcpo_manage_applications' ) ) { echo '<p><a class="button" href="' . esc_url( get_edit_post_link( $id, 'raw' ) ) . '">Modifier ce dossier</a></p>'; }
			echo '</div>'; return;
		}
		$has_actions = Jury::multiple( (int) $data['edition_id'] ) || current_user_can( 'marcpo_manage_applications' );
		echo '<div class="marcpo-examiner-grid' . ( $has_actions ? '' : ' marcpo-examiner-grid-solo' ) . '">';
		if ( $has_actions ) { echo '<aside class="marcpo-examiner-sidebar" aria-label="Notation et sélection">'; }
		Votes::render( $id );
		if ( current_user_can( 'marcpo_manage_applications' ) ) {
			echo '<section class="marcpo-examiner-card marcpo-examiner-decision"><h2>Décision de l’administrateur</h2>';
			Review::decision_form( $id );
			echo '<a class="button" href="' . esc_url( get_edit_post_link( $id, 'raw' ) ) . '">Modifier ce dossier</a></section>';
		}
		if ( $has_actions ) { echo '</aside>'; }
		echo '<div class="marcpo-examiner-content">';
		Review::highlights( $activity );
		echo '<section class="marcpo-examiner-card marcpo-examiner-photos"><div class="marcpo-examiner-section-heading"><h2>Photos du potier</h2><span>Cliquez pour agrandir</span></div>';
		Review::photos( $id, '', true );
		echo '</section><section class="marcpo-examiner-card"><h2>Présentation de l’atelier</h2><div class="marcpo-examiner-presentation">' . nl2br( esc_html( ! empty( $activity['presentation'] ) ? $activity['presentation'] : 'Aucune présentation renseignée.' ) ) . '</div></section>';
		echo '<details class="marcpo-examiner-card marcpo-examiner-details" open><summary>Coordonnées et liens</summary><div class="marcpo-examiner-details-body">';
		Review::details_summary( Fields::identity(), $identity );
		echo '</div></details><details class="marcpo-examiner-card marcpo-examiner-details" open><summary>Informations professionnelles et vie associative</summary><div class="marcpo-examiner-details-body">';
		$secondary_fields = array_diff_key( Fields::activity(), array_fill_keys( array( 'presentation', 'production', 'production_other', 'technique', 'technique_other', 'stand_length' ), true ) );
		Review::details_summary( $secondary_fields, $activity );
		echo '</div></details><details class="marcpo-examiner-card marcpo-examiner-details" open><summary>Justificatifs et autorisation de présentation</summary><div class="marcpo-examiner-details-body">';
		PrivateFiles::render( $id, true );
		if ( 'public' === ( $data['source'] ?? '' ) ) { echo '<p>Déposé depuis le formulaire public. Adresse email déclarée, non vérifiée. Autorisation de présentation publique : ' . esc_html( ! empty( $data['publication_consent'] ) ? 'Oui' : 'Non' ) . '.</p>'; }
		echo '</div></details><details class="marcpo-examiner-card marcpo-examiner-details" open><summary>Suivi interne</summary><div class="marcpo-examiner-details-body">';
		Review::details_summary( Fields::internal(), $data['internal'] ?? array() );
		echo '</div></details><details class="marcpo-examiner-card marcpo-examiner-details" open><summary>Autres candidatures de ce potier</summary><div class="marcpo-examiner-details-body">';
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Bounded 50-item history scoped to one potter, excluding only the open dossier; WordPress primes post metadata.
		$history = get_posts( array( 'post_type' => 'marcpo_candidature', 'post_status' => self::STATUSES, 'posts_per_page' => 50, 'post__not_in' => array( $id ), 'meta_key' => '_marcpo_potier_id', 'meta_value' => $data['potier_id'], 'orderby' => 'date', 'order' => 'DESC' ) );
		$history = array_filter( $history, static fn( $previous ) => Jury::can_view_application( $previous->ID ) );
		if ( ! $history ) { echo '<p>Aucune autre candidature enregistrée.</p>'; }
		echo '<ul>';
		foreach ( $history as $previous ) {
			$record = self::data( $previous->ID );
			if ( empty( $record['edition_id'] ) ) { continue; }
			echo '<li><a href="' . esc_url( self::view_url( $previous->ID ) ) . '">' . esc_html( get_the_title( $record['edition_id'] ) ) . '</a> — ' . esc_html( self::decisions()[ $record['decision'] ?? 'pending' ] ?? 'À examiner' ) . '</li>';
		}
		echo '</ul><p class="description">Les 50 autres dossiers les plus récents au maximum sont présentés ici.</p></div></details></div></div></div>';
	}

	public static function history(): void {
		if ( ! Jury::can_review() ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
		$editions = get_posts( array( 'post_type' => 'marcpo_edition', 'post_status' => self::STATUSES, 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$applications = get_posts( array( 'post_type' => 'marcpo_candidature', 'post_status' => self::STATUSES, 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'DESC' ) );
		$editions = array_values( array_filter( $editions, static fn( $edition ) => Jury::can_view_edition( $edition->ID ) ) );
		$applications = array_values( array_filter( $applications, static fn( $application ) => Jury::can_view_application( $application->ID ) ) );
		$edition_ids = array_fill_keys( wp_list_pluck( $editions, 'ID' ), true );
		$totals = array_fill_keys( array_keys( $edition_ids ), array( 'selected' => 0, 'applications' => 0 ) );
		$rows = array();
		foreach ( $applications as $application ) {
			$data = self::data( $application->ID );
			$potier_id = absint( $data['potier_id'] ?? 0 );
			$edition_id = absint( $data['edition_id'] ?? 0 );
			if ( ! isset( $edition_ids[ $edition_id ] ) ) { continue; }
			++$totals[ $edition_id ]['applications'];
			if ( 'selected' === ( $data['decision'] ?? 'pending' ) ) { ++$totals[ $edition_id ]['selected']; }
			if ( ! $potier_id ) { continue; }
			if ( ! isset( $rows[ $potier_id ] ) ) {
				$identity = self::data( $potier_id )['identity'] ?? $data['identity'] ?? array();
				$rows[ $potier_id ] = array( 'identity' => $identity, 'decisions' => array() );
			}
			$rows[ $potier_id ]['decisions'][ $edition_id ] = $data['decision'] ?? 'pending';
			$rows[ $potier_id ]['applications'][ $edition_id ] = $application->ID;
		}
		usort( $rows, static function ( array $left, array $right ): int {
			return strcmp( ( $left['identity']['last_name'] ?? '' ) . "\0" . ( $left['identity']['first_name'] ?? '' ), ( $right['identity']['last_name'] ?? '' ) . "\0" . ( $right['identity']['first_name'] ?? '' ) );
		} );
		echo '<div class="wrap"><h1>Historique des sélections</h1><p>Chaque ligne correspond à un potier et chaque colonne à une édition. Un tiret signifie qu’aucune candidature n’a été déposée pour cette édition.</p>';
		if ( ! $editions ) { echo '<p>Aucune édition enregistrée.</p></div>'; return; }
		echo '<p class="marcpo-history-help">Faites défiler les éditions horizontalement. Nom, prénom et email restent visibles à gauche.</p><div class="marcpo-history-scroll" role="region" aria-label="Historique par édition, tableau défilant" tabindex="0"><table class="widefat marcpo-history-table" style="--marcpo-editions:' . count( $editions ) . '"><thead><tr><th scope="col">Nom</th><th scope="col">Prénom</th><th scope="col">Email</th>';
		foreach ( $editions as $edition ) {
			$total = $totals[ $edition->ID ];
			$places = Editions::settings( $edition->ID )['exhibitors'];
			echo '<th scope="col">' . esc_html( $edition->post_title ) . '<span class="marcpo-history-counts"><span>' . esc_html( $total['selected'] . ' / ' . ( '' !== $places ? $places : '—' ) ) . '</span><span>' . esc_html( $total['applications'] . ( 1 === $total['applications'] ? ' candidature' : ' candidatures' ) ) . '</span></span></th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$identity = $row['identity'];
			echo '<tr><td>' . esc_html( $identity['last_name'] ?? '' ) . '</td><td>' . esc_html( $identity['first_name'] ?? '' ) . '</td><td>' . esc_html( $identity['email'] ?? '' ) . '</td>';
		foreach ( $editions as $edition ) {
			$application_id = $row['applications'][ $edition->ID ] ?? 0;
			$decision = $row['decisions'][ $edition->ID ] ?? 'pending';
			$state = $application_id ? ( in_array( $decision, array( 'selected', 'rejected' ), true ) ? $decision : 'pending' ) : 'empty';
			echo '<td class="marcpo-history-' . esc_attr( $state ) . '">';
			if ( $application_id ) { echo '<a href="' . esc_url( self::view_url( $application_id, array( 'marcpo_edition' => $edition->ID ) ) ) . '">' . esc_html( self::decisions()[ $row['decisions'][ $edition->ID ] ?? '' ] ?? 'À examiner' ) . '</a>'; }
			else { echo '—'; }
			echo '</td>';
		}
			echo '</tr>';
		}
		echo '</tbody></table></div></div>';
	}
}
