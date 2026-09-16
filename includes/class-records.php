<?php
/** Fiches privées et consultation des candidatures dans l'administration. */
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class Records {
	public const META = '_mp_record';
	private const STATUSES = array( 'publish', 'private', 'draft', 'pending', 'future' );
	private static bool $updating_title = false;

	public static function hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'admin_init', array( self::class, 'permissions' ) );
		foreach ( array( 'mp_potier', 'mp_candidature' ) as $type ) {
			add_action( 'add_meta_boxes_' . $type, array( self::class, 'boxes' ) );
			add_action( 'save_post_' . $type, array( self::class, 'save' ), 10, 2 );
		}
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'restrict_manage_posts', array( self::class, 'render_list_filters' ) );
		add_action( 'pre_get_posts', array( self::class, 'apply_list_filters' ) );
		add_filter( 'manage_mp_candidature_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_mp_candidature_posts_custom_column', array( self::class, 'column_content' ), 10, 2 );
		add_action( 'quick_edit_custom_box', array( self::class, 'quick_edit' ), 10, 2 );
		add_action( 'save_post_mp_candidature', array( self::class, 'save_inline_decision' ), 20 );
		add_action( 'admin_footer-edit.php', array( self::class, 'quick_edit_script' ) );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	public static function permissions(): void {
		if ( '1' === get_option( 'mp_records_permissions_version' ) || ! current_user_can( 'manage_options' ) ) { return; }
		foreach ( array( 'administrator', 'mp_organizer' ) as $name ) {
			$role = get_role( $name );
			if ( ! $role ) { return; }
			foreach ( array( 'mp_access_market', 'mp_manage_potiers', 'mp_manage_applications', 'mp_select_applications' ) as $cap ) {
				$role->add_cap( $cap );
			}
		}
		update_option( 'mp_records_permissions_version', '1', false );
	}

	/** Ajoute l'index de décision aux dossiers créés avant les filtres. */
	public static function migrate_decision_index(): void {
		if ( '1' === get_option( 'mp_decision_index_version' ) || ! current_user_can( 'mp_manage_applications' ) ) {
			return;
		}
		foreach ( get_posts( array( 'post_type' => 'mp_candidature', 'post_status' => array_merge( self::STATUSES, array( 'trash' ) ), 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
			$data = self::data( $id );
			$decision = $data['decision'] ?? 'pending';
			if ( isset( self::decisions()[ $decision ] ) ) {
				update_post_meta( $id, '_mp_decision', $decision );
			}
		}
		update_option( 'mp_decision_index_version', '1', false );
	}

	public static function register(): void {
		foreach ( array( 'mp_potier' => array( 'Identités (historique)', 'Identité', 'mp_manage_potiers' ), 'mp_candidature' => array( 'Candidatures', 'Candidature', 'mp_manage_applications' ) ) as $type => $config ) {
			register_post_type( $type, array(
				'labels' => array( 'name' => $config[0], 'singular_name' => $config[1], 'add_new' => 'Ajouter', 'add_new_item' => 'Ajouter : ' . $config[1], 'edit_item' => 'Modifier : ' . $config[1], 'not_found' => 'Aucun dossier.' ),
				'public' => false, 'publicly_queryable' => false, 'exclude_from_search' => true,
				'show_ui' => true, 'show_in_menu' => false, 'show_in_rest' => false,
				'show_in_nav_menus' => false, 'has_archive' => false, 'rewrite' => false,
				'query_var' => false, 'can_export' => false, 'supports' => 'mp_potier' === $type ? array() : array( 'title' ),
				'map_meta_cap' => false,
				'capabilities' => array_merge( array_fill_keys( array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts', 'read' ), $config[2] ), 'mp_potier' === $type ? array( 'create_posts' => 'do_not_allow', 'delete_post' => 'do_not_allow', 'delete_posts' => 'do_not_allow' ) : array() ),
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
		$id = wp_insert_post( wp_slash( array( 'post_type' => 'mp_potier', 'post_status' => 'publish', 'post_title' => trim( $identity['last_name'] . ' ' . $identity['first_name'] ) ) ), true );
		if ( is_wp_error( $id ) ) { return $id; }
		if ( ! update_post_meta( $id, self::META, wp_slash( array( 'identity' => self::minimal_identity( $identity ) ) ) ) ) {
			wp_delete_post( $id, true );
			return new \WP_Error( 'mp_identity', 'Impossible d’enregistrer l’identité du potier.' );
		}
		return $id;
	}

	public static function decisions(): array {
		return array( 'pending' => 'À examiner', 'selected' => 'Sélectionné', 'rejected' => 'Non sélectionné' );
	}

	public static function render_list_filters( string $post_type ): void {
		if ( 'mp_candidature' !== $post_type || ! Jury::can_review() ) {
			return;
		}
		$edition = isset( $_GET['mp_edition'] ) && is_string( $_GET['mp_edition'] ) ? absint( $_GET['mp_edition'] ) : 0;
		$decision = isset( $_GET['mp_decision'] ) && is_string( $_GET['mp_decision'] ) ? $_GET['mp_decision'] : '';
		echo '<label class="screen-reader-text" for="mp-edition-filter">Filtrer par édition</label><select id="mp-edition-filter" name="mp_edition"><option value="">Toutes les éditions</option>';
		foreach ( get_posts( array( 'post_type' => 'mp_edition', 'post_status' => self::STATUSES, 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) ) as $item ) {
			if ( ! Jury::can_view_edition( $item->ID ) ) { continue; }
			echo '<option value="' . esc_attr( $item->ID ) . '" ' . selected( $edition, $item->ID, false ) . '>' . esc_html( $item->post_title ) . '</option>';
		}
		echo '</select> ';
		echo '<label class="screen-reader-text" for="mp-decision-filter">Filtrer par décision</label><select id="mp-decision-filter" name="mp_decision"><option value="">Toutes les décisions</option>';
		foreach ( self::decisions() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $decision, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public static function apply_list_filters( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'mp_candidature' !== $query->get( 'post_type' ) || ! current_user_can( 'mp_manage_applications' ) ) {
			return;
		}
		$meta_query = (array) $query->get( 'meta_query' );
		$edition = isset( $_GET['mp_edition'] ) && is_string( $_GET['mp_edition'] ) ? absint( $_GET['mp_edition'] ) : 0;
		$decision = isset( $_GET['mp_decision'] ) && is_string( $_GET['mp_decision'] ) ? $_GET['mp_decision'] : '';
		if ( $edition && self::valid_reference( $edition, 'mp_edition' ) ) {
			$meta_query[] = array( 'key' => '_mp_edition_id', 'value' => $edition, 'type' => 'NUMERIC' );
		}
		if ( isset( self::decisions()[ $decision ] ) ) {
			$meta_query[] = array( 'key' => '_mp_decision', 'value' => $decision );
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
			if ( 'title' === $key ) { $result['mp_decision'] = 'Sélection'; }
		}
		return $result;
	}

	public static function column_content( string $column, int $id ): void {
		if ( 'mp_decision' !== $column ) { return; }
		$decision = self::data( $id )['decision'] ?? 'pending';
		echo '<span data-mp-decision="' . esc_attr( $decision ) . '">' . esc_html( self::decisions()[ $decision ] ?? 'À examiner' ) . '</span>';
	}

	public static function quick_edit( string $column, string $post_type ): void {
		if ( 'mp_decision' !== $column || 'mp_candidature' !== $post_type || ! current_user_can( 'mp_select_applications' ) ) { return; }
		echo '<fieldset class="inline-edit-col-right"><div class="inline-edit-col"><label><span class="title">Sélection</span><select name="mp_inline_decision">';
		foreach ( self::decisions() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>'; }
		echo '</select></label></div></fieldset>';
	}

	public static function quick_edit_script(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'mp_candidature' !== $screen->post_type || ! current_user_can( 'mp_select_applications' ) ) { return; }
		echo '<script>document.addEventListener("click",function(e){var b=e.target.closest(".editinline");if(!b)return;var id=b.getAttribute("id").replace("edit-","");setTimeout(function(){var v=document.querySelector("#post-"+id+" [data-mp-decision]");var s=document.querySelector("#edit-"+id+" select[name=mp_inline_decision]");if(v&&s)s.value=v.dataset.mpDecision;},0);});</script>';
	}

	public static function save_inline_decision( int $id ): void {
		if ( self::$updating_title || ! current_user_can( 'mp_select_applications' ) ) { return; }
		$nonce = $_POST['_inline_edit'] ?? null;
		$decision = $_POST['mp_inline_decision'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), 'inlineeditnonce' ) || ! is_string( $decision ) || ! isset( self::decisions()[ $decision ] ) ) { return; }
		$data = self::data( $id );
		if ( empty( $data['potier_id'] ) || empty( $data['edition_id'] ) ) { return; }
		$data['decision'] = $decision;
		update_post_meta( $id, self::META, wp_slash( $data ) );
		update_post_meta( $id, '_mp_decision', $decision );
	}

	public static function boxes( \WP_Post $post ): void {
		add_meta_box( 'mp-record', 'mp_potier' === $post->post_type ? 'Identité du potier' : 'Dossier de candidature', array( self::class, 'edit' ), $post->post_type, 'normal', 'high' );
	}

	private static function selector( string $key, string $type, string $label ): void {
		echo '<p><label for="mp-' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>';
		echo '<select required id="mp-' . esc_attr( $key ) . '" name="mp_record[' . esc_attr( $key ) . ']"><option value="">Choisir…</option>';
		foreach ( get_posts( array( 'post_type' => $type, 'post_status' => self::STATUSES, 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) ) as $item ) {
			echo '<option value="' . esc_attr( $item->ID ) . '">' . esc_html( $item->post_title . ' (#' . $item->ID . ')' ) . '</option>';
		}
		echo '</select>';
		echo '</p>';
	}

	public static function edit( \WP_Post $post ): void {
		$data = self::data( $post->ID );
		wp_nonce_field( 'mp_record_' . $post->ID, 'mp_record_nonce' );
		echo '<p>Les champs marqués * seront obligatoires pour le dépôt public. La saisie interne peut rester incomplète. Les boutons WordPress enregistrent le dossier sans le rendre public.</p>';
		if ( 'mp_potier' === $post->post_type ) {
			Fields::summary( self::potier_schema(), $data['identity'] ?? array() );
			echo '<p>Cette identité sert uniquement à l’historique. Pour corriger un nom, un prénom ou un email, modifiez la candidature concernée.</p>';
			return;
		}
		if ( empty( $data['edition_id'] ) ) {
			self::selector( 'edition_id', 'mp_edition', 'Édition' );
		} else {
			echo '<p><strong>Édition :</strong> ' . esc_html( get_the_title( $data['edition_id'] ) ) . '</p>';
		}
		echo '<h3>Coordonnées de cette candidature</h3><p>Nom, prénom et email sont obligatoires et servent au rapprochement dans l’historique. Leur correction ne modifie pas les autres années.</p>';
		Fields::render( Fields::identity(), $data['identity'] ?? array(), 'identity' );
		echo '<h3>Présentation et activité</h3>';
		Fields::render( Fields::activity(), $data['activity'] ?? array(), 'activity' );
		echo '<h3>Suivi organisateur</h3>';
		Fields::render( Fields::internal(), $data['internal'] ?? array(), 'internal' );
		if ( current_user_can( 'mp_select_applications' ) ) {
			echo '<p><label for="mp-decision">Décision de sélection</label> <select id="mp-decision" name="mp_record[decision]">';
			foreach ( self::decisions() as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '" ' . selected( $data['decision'] ?? 'pending', $key, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></p>';
		} else {
			echo '<p>Décision : ' . esc_html( self::decisions()[ $data['decision'] ?? 'pending' ] ?? 'À examiner' ) . '</p>';
		}
		echo '<input type="hidden" name="mp_record[consent_present]" value="1"><p><label><input type="checkbox" name="mp_record[publication_consent]" value="1" ' . checked( ! empty( $data['publication_consent'] ), true, false ) . '> Autorisation de présentation publique obtenue du candidat</label></p>';
		$map_error = get_post_meta( $post->ID, '_mp_map_error', true );
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
			if ( ! is_scalar( $edition ) || ! ctype_digit( (string) $edition ) || ! self::valid_reference( (int) $edition, 'mp_edition' ) ) { return new \WP_Error( 'mp_relation', 'Choisissez une édition valide.' ); }
			$result['edition_id'] = (int) $edition;
			$result['potier_id'] = 0; // Résolu sous verrou après validation des réponses.
		}
		$groups = array( 'identity' => $application ? Fields::identity() : self::potier_schema() );
		if ( $application ) { $groups += array( 'activity' => Fields::activity(), 'internal' => Fields::internal() ); }
		foreach ( $groups as $group => $schema ) {
			if ( ! isset( $raw[ $group ] ) || ! is_array( $raw[ $group ] ) ) { return new \WP_Error( 'mp_group', 'Données de formulaire incomplètes. Rechargez le dossier.' ); }
			$value = Fields::validate( $raw[ $group ], $schema );
			if ( is_wp_error( $value ) ) { return $value; }
			$result[ $group ] = $value;
		}
		$key_identity = Fields::validate( $result['identity'], self::potier_schema(), true );
		if ( is_wp_error( $key_identity ) ) { return $key_identity; }
		if ( $application ) {
			$result['decision'] = $old['decision'] ?? 'pending';
			if ( '1' === ( $raw['consent_present'] ?? '' ) ) { $result['publication_consent'] = '1' === ( $raw['publication_consent'] ?? '' ); }
			if ( current_user_can( 'mp_select_applications' ) ) {
				$decision = $raw['decision'] ?? $result['decision'];
				if ( ! is_string( $decision ) || ! isset( self::decisions()[ $decision ] ) ) { return new \WP_Error( 'mp_decision', 'Décision invalide.' ); }
				$result['decision'] = $decision;
			}
		}
		return $result;
	}

	public static function save( int $id, \WP_Post $post ): void {
		$application = 'mp_candidature' === $post->post_type;
		if ( ! $application ) { return; }
		if ( self::$updating_title || ! in_array( $post->post_type, array( 'mp_potier', 'mp_candidature' ), true ) || wp_is_post_revision( $id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( $application ? 'mp_manage_applications' : 'mp_manage_potiers' ) ) { return; }
		$nonce = $_POST['mp_record_nonce'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), 'mp_record_' . $id ) ) { return; }
		$raw = $_POST['mp_record'] ?? null;
		$data = is_array( $raw ) ? self::prepare( wp_unslash( $raw ), self::data( $id ), $application ) : new \WP_Error( 'mp_form', 'Formulaire invalide.' );
		$locked = false;
		if ( ! is_wp_error( $data ) && class_exists( SubmissionLock::class ) ) {
			$locked = SubmissionLock::acquire();
			if ( ! $locked ) { $data = new \WP_Error( 'mp_busy', 'Un autre enregistrement est en cours. Réessayez.' ); }
		}
		try {
		if ( ! is_wp_error( $data ) && $application ) {
			$data['potier_id'] = PublicForm::find_potier( $data['identity'] );
			$duplicates = $data['potier_id'] ? get_posts( array( 'post_type' => 'mp_candidature', 'post_status' => array_merge( self::STATUSES, array( 'trash' ) ), 'posts_per_page' => 1, 'fields' => 'ids', 'post__not_in' => array( $id ), 'meta_query' => array( array( 'key' => '_mp_potier_id', 'value' => $data['potier_id'] ), array( 'key' => '_mp_edition_id', 'value' => $data['edition_id'] ) ) ) ) : array();
			if ( $duplicates ) { $data = new \WP_Error( 'mp_duplicate', 'Ce potier possède déjà une candidature pour cette édition, éventuellement dans la corbeille. Retrouvez le dossier existant.' ); }
			if ( ! is_wp_error( $data ) && class_exists( PublicForm::class ) && ! empty( $data['identity']['email'] ) && PublicForm::duplicate( $data['edition_id'], $data['identity']['email'], $id ) ) { $data = new \WP_Error( 'mp_duplicate_email', 'Cette adresse email possède déjà une candidature pour cette édition.' ); }
		}
		if ( is_wp_error( $data ) ) {
			set_transient( 'mp_record_error_' . get_current_user_id() . '_' . $id, $data->get_error_message(), 120 );
			return;
		}
		$new_files = array();
		$replaced = array();
		if ( $application ) {
			// Relire sous verrou : un formulaire ouvert avant un remplacement ne doit pas le perdre.
			$current_files = self::data( $id )['files'] ?? array();
			$uploads = array();
			foreach ( PrivateFiles::slots() as $slot => $label ) {
				if ( isset( $_FILES[ 'mp_admin_' . $slot ] ) ) { $uploads[ $slot ] = $_FILES[ 'mp_admin_' . $slot ]; }
			}
			if ( $uploads ) { $new_files = PrivateFiles::store( $uploads, true ); }
			if ( is_wp_error( $new_files ) ) {
				set_transient( 'mp_record_error_' . get_current_user_id() . '_' . $id, $new_files->get_error_message(), 120 );
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
				set_transient( 'mp_record_error_' . get_current_user_id() . '_' . $id, $created_potier->get_error_message(), 120 );
				return;
			}
			$data['potier_id'] = $created_potier;
		}
		$saved = update_post_meta( $id, self::META, wp_slash( $data ) );
		if ( ! $saved && self::data( $id ) !== $data ) {
			PrivateFiles::remove( $new_files );
			if ( $created_potier ) { wp_delete_post( $created_potier, true ); }
			set_transient( 'mp_record_error_' . get_current_user_id() . '_' . $id, 'Enregistrement impossible. Les fichiers précédents sont conservés.', 120 );
			return;
		}
		PrivateFiles::remove( $replaced );
		if ( $application ) {
			// Index de recherche, les réponses restent réunies dans META.
			update_post_meta( $id, '_mp_potier_id', $data['potier_id'] );
			update_post_meta( $id, '_mp_edition_id', $data['edition_id'] );
			update_post_meta( $id, '_mp_decision', $data['decision'] );
		}
		self::sync_title( $id, $post->post_type, $data );
		delete_transient( 'mp_record_error_' . get_current_user_id() . '_' . $id );
		} finally { if ( $locked ) { SubmissionLock::release(); } }
	}

	private static function sync_title( int $id, string $type, array $data ): void {
		if ( 'mp_potier' === $type ) {
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
		$id = isset( $_GET['post'] ) && is_string( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! $screen || ! in_array( $screen->post_type, array( 'mp_potier', 'mp_candidature' ), true ) || ! current_user_can( 'mp_candidature' === $screen->post_type ? 'mp_manage_applications' : 'mp_manage_potiers' ) ) { return; }
		$key = 'mp_record_error_' . get_current_user_id() . '_' . $id;
		$error = get_transient( $key );
		if ( $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error . ' Les champs du dossier n’ont pas été enregistrés. Le titre et le statut WordPress peuvent avoir changé ; les valeurs précédentes sont affichées.' ) . '</p></div>';
			delete_transient( $key );
		}
	}

	public static function menu(): void {
		// Page accessible par les liens des dossiers, sans entrée autonome dans le menu.
		$hook = add_submenu_page( '', 'Consulter une candidature', 'Consulter une candidature', 'mp_review_applications', 'mp-dossier', array( self::class, 'view' ) );
		// Une page sans parent n’est pas retrouvée par get_admin_page_title().
		add_action( 'load-' . $hook, static function () { $GLOBALS['title'] = 'Consulter une candidature'; } );
		add_submenu_page( 'marche-potier', 'Historique des sélections', 'Historique des sélections', 'mp_review_applications', 'mp-historique', array( self::class, 'history' ) );
	}

	public static function view_url( int $id, array $context = array() ): string {
		return add_query_arg( array_merge( $context, array( 'page' => 'mp-dossier', 'candidature' => $id ) ), admin_url( 'admin.php' ) );
	}

	/** Seuls les paramètres connus de la liste sont conservés, jamais une URL de retour libre. */
	public static function list_context(): array {
		$context = array();
		if ( in_array( $_GET['mp_from'] ?? '', array( 'gestion', 'votes' ), true ) ) { $context['mp_from'] = $_GET['mp_from']; }
		foreach ( array( 'mp_edition', 'paged', 'm', 'author' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) && absint( $_GET[ $key ] ) ) { $context[ $key ] = absint( $_GET[ $key ] ); }
		}
		foreach ( array( 'mp_decision' => array_keys( self::decisions() ), 'mp_my_vote' => array( 'rated', 'unrated' ), 'post_status' => self::STATUSES, 'orderby' => array( 'title', 'date', 'modified', 'ID', 'author', 'points' ), 'order' => array( 'asc', 'desc', 'ASC', 'DESC' ) ) as $key => $allowed ) {
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) && in_array( $_GET[ $key ], $allowed, true ) ) { $context[ $key ] = $_GET[ $key ]; }
		}
		if ( isset( $_GET['s'] ) && is_string( $_GET['s'] ) ) { $context['s'] = sanitize_text_field( wp_unslash( $_GET['s'] ) ); }
		return $context;
	}

	public static function navigation_ids( array $context ): array {
		if ( ! Jury::can_review() ) { return array(); }
		$args = array( 'post_type' => 'mp_candidature', 'post_status' => self::STATUSES, 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC' );
		foreach ( array( 'post_status', 'orderby', 'order', 'm', 'author' ) as $key ) {
			if ( isset( $context[ $key ] ) ) { $args[ $key ] = $context[ $key ]; }
		}
		if ( 'points' === $args['orderby'] ) { $args['orderby'] = 'date'; }
		// Un second critère garantit un parcours stable lorsque deux dossiers ont la même date.
		$args['orderby'] = array( $args['orderby'] => strtoupper( $args['order'] ), 'ID' => strtoupper( $args['order'] ) );
		if ( ! empty( $context['mp_edition'] ) ) {
			if ( ! Jury::can_view_edition( (int) $context['mp_edition'] ) ) { return array(); }
			$args['meta_query'][] = array( 'key' => '_mp_edition_id', 'value' => $context['mp_edition'], 'type' => 'NUMERIC' );
		}
		if ( ! current_user_can( 'mp_manage_applications' ) ) {
			$editions = Jury::edition_ids();
			if ( ! $editions ) { return array(); }
			$args['meta_query'][] = array( 'key' => '_mp_edition_id', 'value' => $editions, 'compare' => 'IN', 'type' => 'NUMERIC' );
		}
		if ( isset( self::decisions()[ $context['mp_decision'] ?? '' ] ) ) { $args['meta_query'][] = array( 'key' => '_mp_decision', 'value' => $context['mp_decision'] ); }
		$ids = array_map( 'intval', get_posts( $args ) );
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
		if ( in_array( $context['mp_my_vote'] ?? '', array( 'rated', 'unrated' ), true ) ) {
			$votes = Votes::all( $ids ); $rated = 'rated' === $context['mp_my_vote'];
			$ids = array_values( array_filter( $ids, static function ( $id ) use ( $votes, $rated ) {
				$mine = Votes::mine( $id, $votes[ $id ] ?? array() );
				return null !== $mine && $rated === ( null !== $mine['score'] );
			} ) );
		}
		return 'points' === ( $context['orderby'] ?? '' ) ? Votes::sort_ids( $ids, $context['order'] ?? 'DESC' ) : $ids;
	}

	private static function navigation( int $id ): void {
		$context = self::list_context();
		$ids = self::navigation_ids( $context );
		$position = array_search( $id, $ids, true );
		$list_url = add_query_arg( array_merge( $context, array( 'page' => 'mp-gestion' ) ), admin_url( 'admin.php' ) );
		if ( 'votes' === ( $context['mp_from'] ?? '' ) ) { $list_url = VoteTracking::url( (int) ( self::data( $id )['edition_id'] ?? 0 ) ); }
		echo '<nav aria-label="Navigation des candidatures"><p><a class="button" href="' . esc_url( $list_url ) . '">Retour à la liste</a> ';
		if ( false !== $position ) {
			if ( $position > 0 ) { echo '<a class="button" href="' . esc_url( self::view_url( $ids[ $position - 1 ], $context ) ) . '">← Dossier précédent</a> '; }
			echo '<span> Dossier ' . esc_html( ( $position + 1 ) . ' sur ' . count( $ids ) ) . ' </span> ';
			if ( isset( $ids[ $position + 1 ] ) ) { echo '<a class="button" href="' . esc_url( self::view_url( $ids[ $position + 1 ], $context ) ) . '">Dossier suivant →</a> '; }
		} else { echo '<span>Ce dossier ne correspond plus aux filtres de la liste.</span> '; }
		echo '<a class="button" href="' . esc_url( VoteTracking::url( (int) ( self::data( $id )['edition_id'] ?? 0 ) ) ) . '">Suivi des votes</a> ';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=mp-historique' ) ) . '">Historique des sélections</a></p></nav>';
	}

	public static function row_actions( array $actions, \WP_Post $post ): array {
		if ( 'mp_candidature' === $post->post_type && current_user_can( 'mp_manage_applications' ) ) {
			$actions['mp_view'] = '<a href="' . esc_url( self::view_url( $post->ID, self::list_context() ) ) . '">Consulter le dossier</a>';
		}
		return $actions;
	}

	public static function view(): void {
		if ( ! Jury::can_review() ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
		$id = isset( $_GET['candidature'] ) && is_string( $_GET['candidature'] ) ? absint( $_GET['candidature'] ) : 0;
		if ( ! self::valid_reference( $id, 'mp_candidature' ) ) { wp_die( 'Candidature introuvable.', '', array( 'response' => 404 ) ); }
		if ( ! Jury::can_view_application( $id ) ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
		$data = self::data( $id );
		echo '<div class="wrap"><h1>' . esc_html( get_the_title( $id ) ) . '</h1>';
		echo '<div class="mp-review-heading"><div>';
		self::navigation( $id );
		if ( current_user_can( 'mp_manage_applications' ) ) { echo '<p><a class="button" href="' . esc_url( get_edit_post_link( $id, 'raw' ) ) . '">Modifier ce dossier</a></p>'; }
		if ( empty( $data['potier_id'] ) ) { echo '<p>Ce dossier ne possède pas encore de données enregistrées.</p></div></div></div>'; return; }
		echo '<p><strong>Édition :</strong> ' . esc_html( get_the_title( $data['edition_id'] ) ) . ' — <strong>Décision :</strong> ' . esc_html( self::decisions()[ $data['decision'] ] ?? 'À examiner' ) . '</p>';
		Review::decision_form( $id );
		echo '</div>';
		Votes::render( $id );
		echo '</div>';
		echo '<div class="mp-review-layout"><div><h2>Photos du potier</h2>';
		Review::photos( $id );
		PrivateFiles::render( $id, true );
		echo '</div><div><h2>Coordonnées transmises pour cette édition</h2>';
		Fields::summary( Fields::identity(), $data['identity'] );
		echo '<h2>Présentation, activité et stand</h2>';
		Fields::summary( Fields::activity(), $data['activity'] );
		if ( 'public' === ( $data['source'] ?? '' ) ) { echo '<p>Déposé depuis le formulaire public. Adresse email déclarée, non vérifiée. Autorisation de présentation publique : ' . esc_html( ! empty( $data['publication_consent'] ) ? 'Oui' : 'Non' ) . '.</p>'; }
		echo '<h2>Suivi interne</h2>';
		Fields::summary( Fields::internal(), $data['internal'] );
		echo '</div></div>';
		echo '<h2>Autres candidatures de ce potier</h2>';
		$history = get_posts( array( 'post_type' => 'mp_candidature', 'post_status' => self::STATUSES, 'posts_per_page' => 50, 'post__not_in' => array( $id ), 'meta_key' => '_mp_potier_id', 'meta_value' => $data['potier_id'], 'orderby' => 'date', 'order' => 'DESC' ) );
		$history = array_filter( $history, static fn( $previous ) => Jury::can_view_application( $previous->ID ) );
		if ( ! $history ) { echo '<p>Aucune autre candidature enregistrée.</p>'; }
		echo '<ul>';
		foreach ( $history as $previous ) {
			$record = self::data( $previous->ID );
			if ( empty( $record['edition_id'] ) ) { continue; }
			echo '<li><a href="' . esc_url( self::view_url( $previous->ID ) ) . '">' . esc_html( get_the_title( $record['edition_id'] ) ) . '</a> — ' . esc_html( self::decisions()[ $record['decision'] ?? 'pending' ] ?? 'À examiner' ) . '</li>';
		}
		echo '</ul><p class="description">Les 50 autres dossiers les plus récents au maximum sont présentés ici.</p></div>';
	}

	public static function history(): void {
		if ( ! Jury::can_review() ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
		$editions = get_posts( array( 'post_type' => 'mp_edition', 'post_status' => self::STATUSES, 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$applications = get_posts( array( 'post_type' => 'mp_candidature', 'post_status' => self::STATUSES, 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'DESC' ) );
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
		echo '<p class="mp-history-help">Faites défiler les éditions horizontalement. Nom, prénom et email restent visibles à gauche.</p><div class="mp-history-scroll" role="region" aria-label="Historique par édition, tableau défilant" tabindex="0"><table class="widefat mp-history-table" style="--mp-editions:' . count( $editions ) . '"><thead><tr><th scope="col">Nom</th><th scope="col">Prénom</th><th scope="col">Email</th>';
		foreach ( $editions as $edition ) {
			$total = $totals[ $edition->ID ];
			$places = Editions::settings( $edition->ID )['exhibitors'];
			echo '<th scope="col">' . esc_html( $edition->post_title ) . '<span class="mp-history-counts"><span>' . esc_html( $total['selected'] . ' / ' . ( '' !== $places ? $places : '—' ) ) . '</span><span>' . esc_html( $total['applications'] . ( 1 === $total['applications'] ? ' candidature' : ' candidatures' ) ) . '</span></span></th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$identity = $row['identity'];
			echo '<tr><td>' . esc_html( $identity['last_name'] ?? '' ) . '</td><td>' . esc_html( $identity['first_name'] ?? '' ) . '</td><td>' . esc_html( $identity['email'] ?? '' ) . '</td>';
		foreach ( $editions as $edition ) {
			$application_id = $row['applications'][ $edition->ID ] ?? 0;
			$decision = $row['decisions'][ $edition->ID ] ?? 'pending';
			$state = $application_id ? ( in_array( $decision, array( 'selected', 'rejected' ), true ) ? $decision : 'pending' ) : 'empty';
			echo '<td class="mp-history-' . esc_attr( $state ) . '">';
			if ( $application_id ) { echo '<a href="' . esc_url( self::view_url( $application_id, array( 'mp_edition' => $edition->ID ) ) ) . '">' . esc_html( self::decisions()[ $row['decisions'][ $edition->ID ] ?? '' ] ?? 'À examiner' ) . '</a>'; }
			else { echo '—'; }
			echo '</td>';
		}
			echo '</tr>';
		}
		echo '</tbody></table></div></div>';
	}
}
