<?php
/** Éditions privées et réglages de leur période de candidature. */

namespace MarchePotier;

defined( 'ABSPATH' ) || exit;

final class Editions {
	private const META = '_mp_edition_settings';
	private const DEFAULT_THANK_YOU = 'Votre candidature a bien été enregistrée. Merci pour votre participation ; votre dossier sera étudié par l’organisateur.';

	public static function hooks(): void {
		add_action( 'add_meta_boxes_mp_edition', array( self::class, 'add_box' ) );
		add_action( 'save_post_mp_edition', array( self::class, 'save' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_filter( 'manage_mp_edition_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_mp_edition_posts_custom_column', array( self::class, 'column' ), 10, 2 );
	}

	/** Migration idempotente, y compris lors d'une mise à jour sans réactivation. */
	public static function install_permissions(): void {
		if ( '2' === get_option( 'mp_permissions_version' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$admin = get_role( 'administrator' );
		if ( ! $admin ) {
			return;
		}
		$admin->add_cap( 'mp_manage_editions' );
		add_role( 'mp_organizer', __( 'Organisateur de marché', 'marche-potier' ), array( 'read' => true, 'mp_manage_editions' => true ) );
		$organizer = get_role( 'mp_organizer' );
		if ( $organizer ) {
			$organizer->add_cap( 'mp_manage_editions' );
			$organizer->add_cap( 'upload_files' );
		}
		update_option( 'mp_permissions_version', '2', false );
	}

	public static function register(): void {
		$caps = array_fill_keys(
			array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts', 'read' ),
			'mp_manage_editions'
		);
		register_post_type( 'mp_edition', array(
			'labels' => array(
				'name' => __( 'Éditions', 'marche-potier' ),
				'singular_name' => __( 'Édition', 'marche-potier' ),
				'add_new' => __( 'Ajouter une édition', 'marche-potier' ),
				'add_new_item' => __( 'Ajouter une édition', 'marche-potier' ),
				'edit_item' => __( 'Modifier l’édition', 'marche-potier' ),
				'not_found' => __( 'Aucune édition.', 'marche-potier' ),
			),
			'public' => false,
			'publicly_queryable' => false,
			'exclude_from_search' => true,
			'show_ui' => true,
			'show_in_menu' => 'marche-potier',
			'show_in_rest' => false,
			'show_in_nav_menus' => false,
			'has_archive' => false,
			'rewrite' => false,
			'query_var' => false,
			'can_export' => false,
			'supports' => array( 'title' ),
			'map_meta_cap' => false,
			'capabilities' => $caps,
		) );
	}

	public static function settings( int $id ): array {
		$value = get_post_meta( $id, self::META, true );
		return array_merge( array( 'year' => '', 'opens' => '', 'closes' => '', 'selection_public' => false, 'presentation' => '', 'thank_you' => '', 'organizer_email' => '', 'image' => 0, 'rules' => 0, 'application_document' => 0, 'stand_length' => '5', 'stand_editable' => true ), is_array( $value ) ? $value : array() );
	}

	public static function assets(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'mp_edition' !== $screen->post_type || ! in_array( $screen->base, array( 'post', 'edit' ), true ) || ! current_user_can( 'mp_manage_editions' ) ) { return; }
		global $wpdb;
		// Inclut les candidatures en corbeille, qui peuvent être restaurées.
		$linked = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s", '_mp_edition_id', 'mp_candidature' ) );
		wp_enqueue_script( 'mp-edition-trash', plugins_url( '../assets/edition-trash.js', __FILE__ ), array(), '0.15.1', true );
		wp_localize_script( 'mp-edition-trash', 'mpEditionTrash', array( 'linked' => array_map( 'strval', $linked ), 'message' => 'Attention, vous avez des candidatures rattachées à cette édition.' . "\n" . 'Êtes-vous sûr de vouloir mettre cette édition à la corbeille ?', 'bulkMessage' => 'Attention, des candidatures sont rattachées à une ou plusieurs des éditions sélectionnées.' . "\n" . 'Êtes-vous sûr de vouloir mettre ces éditions à la corbeille ?' ) );
		if ( 'post' !== $screen->base ) { return; }
		wp_enqueue_media();
		wp_enqueue_script( 'mp-edition', plugins_url( '../assets/edition.js', __FILE__ ), array( 'media-views' ), '0.10.1', true );
	}

	/** Documents publics distincts des justificatifs privés des candidats. */
	public static function introduction( int $edition ): string {
		$data = self::settings( $edition );
		$html = '<div class="mp-edition-introduction">';
		if ( '' !== $data['presentation'] ) { $html .= wpautop( wp_kses_post( $data['presentation'] ) ); }
		foreach ( array( 'rules' => 'Règlement intérieur', 'application_document' => 'Dossier de candidature' ) as $key => $label ) {
			$url = $data[ $key ] ? wp_get_attachment_url( $data[ $key ] ) : false;
			if ( $url ) { $html .= '<p><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . ' (PDF)</a></p>'; }
		}
		return $html . '</div>';
	}

	public static function stand_value( int $edition, mixed $submitted = null ): string {
		$data = self::settings( $edition );
		return ! $data['stand_editable'] || null === $submitted ? $data['stand_length'] : ( is_string( $submitted ) ? $submitted : '' );
	}

	public static function add_box(): void {
		add_meta_box( 'mp-edition-settings', __( 'Paramètres de l’édition', 'marche-potier' ), array( self::class, 'render_box' ), 'mp_edition', 'normal', 'high' );
	}

	public static function render_box( \WP_Post $post ): void {
		$data = self::settings( $post->ID );
		wp_nonce_field( 'mp_save_edition_' . $post->ID, 'mp_edition_nonce' );
		?>
		<p><?php echo esc_html( sprintf( __( 'Fuseau horaire du site : %s. La fermeture prend effet à l’heure exacte indiquée.', 'marche-potier' ), wp_timezone_string() ) ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th><label for="mp-year"><?php esc_html_e( 'Année', 'marche-potier' ); ?></label></th><td><input id="mp-year" name="mp_edition[year]" type="number" min="2000" max="9999" required value="<?php echo esc_attr( $data['year'] ); ?>"></td></tr>
			<?php foreach ( array( 'opens' => __( 'Ouverture des candidatures', 'marche-potier' ), 'closes' => __( 'Fermeture des candidatures', 'marche-potier' ) ) as $key => $label ) : ?>
			<tr><th><label for="mp-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input id="mp-<?php echo esc_attr( $key ); ?>" name="mp_edition[<?php echo esc_attr( $key ); ?>]" type="datetime-local" required value="<?php echo esc_attr( $data[ $key ] ); ?>"></td></tr>
			<?php endforeach; ?>
			<tr><th><?php esc_html_e( 'Sélection publique', 'marche-potier' ); ?></th><td><label><input name="mp_edition[selection_public]" type="checkbox" value="1" <?php checked( $data['selection_public'] ); ?>> <?php esc_html_e( 'Autoriser l’affichage public de la sélection', 'marche-potier' ); ?></label><p class="description"><?php esc_html_e( 'Désactivé par défaut. La galerie affiche les candidats sélectionnés ayant autorisé leur présentation. Le bouton WordPress « Publier » active l’édition, mais n’autorise pas à lui seul l’affichage des potiers.', 'marche-potier' ); ?></p></td></tr>
		</table>
		<h3>Afficher le formulaire de candidature</h3>
		<p><label for="mp-form-shortcode">Shortcode à copier dans votre page WordPress</label></p>
		<input id="mp-form-shortcode" class="large-text code" type="text" readonly value="<?php echo esc_attr( preg_match( '/^[2-9][0-9]{3}$/D', (string) $data['year'] ) ? '[inscription_potier edition="' . $data['year'] . '"]' : '' ); ?>" aria-describedby="mp-shortcode-help">
		<p class="description" id="mp-shortcode-help">Renseignez l’année et enregistrez l’édition, puis collez ce code dans un bloc « Code court » de la page qui accueillera les candidatures. L’édition doit être publiée ; le formulaire respecte les dates d’ouverture et de fermeture.</p>
		<h3>Présentation du marché</h3>
		<p><label for="mppresentation">Texte de présentation</label></p>
		<?php wp_editor( $data['presentation'], 'mppresentation', array(
			'textarea_name' => 'mp_edition[presentation]',
			'textarea_rows' => 10,
			'media_buttons' => false,
			'drag_drop_upload' => false,
			'quicktags' => false,
			'tinymce' => array(
				'toolbar1' => 'formatselect,bold,italic,bullist,numlist,link,unlink,removeformat,undo,redo',
				'toolbar2' => '',
				'block_formats' => 'Paragraphe=p;Titre 2=h2;Titre 3=h3',
			),
		) ); ?>
		<p class="description">Cette présentation et les documents sont publics et affichés avant le formulaire, même lorsque les candidatures sont fermées.</p>
		<?php foreach ( array( 'rules' => 'Règlement intérieur', 'application_document' => 'Dossier de candidature' ) as $key => $label ) : ?>
		<div class="mp-media-field">
			<p><strong><?php echo esc_html( $label ); ?></strong></p>
			<input type="hidden" name="mp_edition[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $data[ $key ] ); ?>">
			<p class="mp-media-name"><?php echo esc_html( $data[ $key ] ? get_the_title( $data[ $key ] ) : 'Aucun fichier sélectionné' ); ?></p>
			<button type="button" class="button mp-media-select" data-type="application/pdf">Choisir / téléverser un PDF</button>
			<button type="button" class="button mp-media-remove">Retirer</button>
		</div>
		<?php endforeach; ?>
		<h3>Confirmation de candidature</h3>
		<p><label for="mp-thank-you">Message de remerciement</label></p>
		<textarea class="large-text" rows="5" id="mp-thank-you" name="mp_edition[thank_you]" maxlength="5000" placeholder="<?php echo esc_attr( self::DEFAULT_THANK_YOU ); ?>"><?php echo esc_textarea( $data['thank_you'] ); ?></textarea>
		<p class="description">Affiché après validation et repris dans l’email au candidat. Si vide, un message de confirmation standard est utilisé.</p>
		<p><label for="mp-organizer-email">Email de l’organisateur</label><br><input class="regular-text" type="email" id="mp-organizer-email" name="mp_edition[organizer_email]" value="<?php echo esc_attr( $data['organizer_email'] ); ?>"></p>
		<p class="description">Destinataire des nouvelles candidatures et adresse de réponse des candidats. Si vide, l’adresse d’administration du site est utilisée : <?php echo esc_html( get_option( 'admin_email' ) ); ?>.</p>
		<h3>Stand</h3>
		<p><label for="mp-stand-length">Taille du stand par défaut (m)</label><br><input id="mp-stand-length" type="number" min="0.01" max="1000" step="0.01" required name="mp_edition[stand_length]" value="<?php echo esc_attr( $data['stand_length'] ); ?>"></p>
		<p><label for="mp-stand-editable">Modifiable par le potier</label><br><select id="mp-stand-editable" name="mp_edition[stand_editable]"><option value="1" <?php selected( $data['stand_editable'], true ); ?>>Oui</option><option value="0" <?php selected( $data['stand_editable'], false ); ?>>Non</option></select></p>
		<?php
	}

	/** Refuse les dates invalides et les heures inexistantes au passage à l'heure d'été. */
	public static function parse_date( string $value ): ?\DateTimeImmutable {
		if ( ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$/D', $value ) ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, wp_timezone() );
		return $date && $date->format( 'Y-m-d\TH:i' ) === $value ? $date : null;
	}

	public static function thank_you( int $edition ): string {
		$message = trim( self::settings( $edition )['thank_you'] );
		return $message ?: self::DEFAULT_THANK_YOU;
	}

	public static function validate( array $data ): ?array {
		foreach ( array( 'year', 'opens', 'closes' ) as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) {
				return null;
			}
		}
		if ( ! preg_match( '/^[2-9][0-9]{3}$/D', $data['year'] ) ) {
			return null;
		}
		$opens = self::parse_date( $data['opens'] );
		$closes = self::parse_date( $data['closes'] );
		if ( ! $opens || ! $closes || $closes <= $opens ) {
			return null;
		}
		foreach ( array( 'thank_you', 'organizer_email' ) as $key ) { if ( ! is_string( $data[ $key ] ?? '' ) ) { return null; } }
		if ( strlen( $data['thank_you'] ?? '' ) > 20000 || ( ! empty( $data['organizer_email'] ) && ! is_email( $data['organizer_email'] ) ) ) { return null; }
		$stand = $data['stand_length'] ?? '5';
		if ( ! is_string( $stand ) || ! preg_match( '/^\d+(?:[.,]\d{1,2})?$/D', $stand ) ) { return null; }
		$stand = str_replace( ',', '.', $stand );
		if ( (float) $stand < 0.01 || (float) $stand > 1000 || ! is_string( $data['presentation'] ?? '' ) || strlen( $data['presentation'] ?? '' ) > 80000 || ! in_array( $data['stand_editable'] ?? '1', array( '0', '1' ), true ) ) { return null; }
		$media = array();
		foreach ( array( 'image', 'rules', 'application_document' ) as $key ) {
			$value = $data[ $key ] ?? '0';
			if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) ) { return null; }
			$media[ $key ] = (int) $value;
			if ( $media[ $key ] && ( 'attachment' !== get_post_type( $media[ $key ] ) || ! in_array( get_post_mime_type( $media[ $key ] ), 'image' === $key ? array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' ) : array( 'application/pdf' ), true ) ) ) { return null; }
		}
		return array(
			'thank_you' => sanitize_textarea_field( $data['thank_you'] ?? '' ),
			'organizer_email' => sanitize_email( $data['organizer_email'] ?? '' ),
			'presentation' => isset( $data['presentation'] ) ? wp_kses_post( $data['presentation'] ) : '',
			'image' => $media['image'], 'rules' => $media['rules'], 'application_document' => $media['application_document'],
			'stand_length' => $stand, 'stand_editable' => '1' === ( $data['stand_editable'] ?? '1' ),
			'year' => $data['year'],
			'opens' => $data['opens'],
			'closes' => $data['closes'],
			'selection_public' => isset( $data['selection_public'] ) && '1' === $data['selection_public'],
		);
	}

	public static function save( int $id ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $id ) || ! current_user_can( 'mp_manage_editions' ) ) {
			return;
		}
		if ( ! isset( $_POST['mp_edition_nonce'] ) || ! is_string( $_POST['mp_edition_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mp_edition_nonce'] ) ), 'mp_save_edition_' . $id ) ) {
			return;
		}
		$data = isset( $_POST['mp_edition'] ) && is_array( $_POST['mp_edition'] ) ? self::validate( wp_unslash( $_POST['mp_edition'] ) ) : null;
		if ( null === $data ) {
			add_filter( 'redirect_post_location', static function ( $location ) {
				return add_query_arg( 'mp_edition_error', '1', $location );
			} );
			return;
		}
		// Un seul enregistrement pour ne pas mélanger anciens et nouveaux réglages.
		update_post_meta( $id, self::META, wp_slash( $data ) );
	}

	public static function notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'mp_edition' !== $screen->post_type || ! isset( $_GET['mp_edition_error'] ) || ! current_user_can( 'mp_manage_editions' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Paramètres non enregistrés : indiquez une année entre 2000 et 9999 et deux dates valides, avec une fermeture après l’ouverture. Vérifiez aussi la taille du stand (0,01 à 1000 m, deux décimales maximum), le texte et les médias (image ou PDF selon le champ). Les anciens paramètres sont conservés ; le titre et le statut WordPress peuvent avoir été enregistrés.', 'marche-potier' ) . '</p></div>';
	}

	/** À réutiliser côté serveur lors du dépôt, sans dépendre de WP-Cron. */
	public static function is_open( int $id, ?int $now = null ): bool {
		if ( 'mp_edition' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return false;
		}
		$data = self::settings( $id );
		$opens = self::parse_date( $data['opens'] );
		$closes = self::parse_date( $data['closes'] );
		$now = $now ?? time();
		return $opens && $closes && $now >= $opens->getTimestamp() && $now < $closes->getTimestamp();
	}

	public static function selection_is_public( int $id ): bool {
		return 'mp_edition' === get_post_type( $id ) && 'publish' === get_post_status( $id ) && true === self::settings( $id )['selection_public'];
	}

	public static function columns( array $columns ): array {
		return array_merge( $columns, array( 'mp_year' => __( 'Année', 'marche-potier' ), 'mp_period' => __( 'Période de candidature', 'marche-potier' ), 'mp_selection' => __( 'Sélection publique', 'marche-potier' ) ) );
	}

	public static function column( string $column, int $id ): void {
		$data = self::settings( $id );
		if ( 'mp_year' === $column ) {
			echo esc_html( $data['year'] );
		} elseif ( 'mp_period' === $column ) {
			foreach ( array( 'opens', 'closes' ) as $key ) {
				$date = self::parse_date( $data[ $key ] );
				echo esc_html( $date ? wp_date( 'd/m/Y H:i', $date->getTimestamp() ) : '—' ) . '<br>';
			}
			echo esc_html( self::is_open( $id ) ? __( 'Ouverte', 'marche-potier' ) : __( 'Fermée', 'marche-potier' ) );
		} elseif ( 'mp_selection' === $column ) {
			echo esc_html( self::selection_is_public( $id ) ? __( 'Autorisée', 'marche-potier' ) : __( 'Masquée', 'marche-potier' ) );
		}
	}
}
