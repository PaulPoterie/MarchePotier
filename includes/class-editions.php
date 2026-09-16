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
		if ( '3' === get_option( 'mp_permissions_version' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$admin = get_role( 'administrator' );
		if ( ! $admin ) {
			return;
		}
		$admin->add_cap( 'mp_manage_editions' );
		add_role( 'mp_organizer', '[MP] Administrateur marché', array( 'read' => true, 'mp_manage_editions' => true ) );
		$organizer = get_role( 'mp_organizer' );
		if ( $organizer ) {
			$organizer->add_cap( 'mp_manage_editions' );
			$organizer->add_cap( 'upload_files' );
			foreach ( array( 'posts', 'pages' ) as $type ) {
				foreach ( array( 'edit_', 'edit_others_', 'edit_private_', 'edit_published_', 'publish_', 'read_private_', 'delete_', 'delete_others_', 'delete_private_', 'delete_published_' ) as $prefix ) {
					$organizer->add_cap( $prefix . $type );
				}
			}
			$organizer->add_cap( 'manage_categories' );
		}
		self::rename_role( 'mp_organizer', '[MP] Administrateur marché' );
		update_option( 'mp_permissions_version', '3', false );
		wp_get_current_user()->get_role_caps();
	}

	/** add_role ne renomme pas les rôles existants ; leurs identifiants restent stables. */
	public static function rename_role( string $slug, string $label ): void {
		$roles = wp_roles();
		if ( ! isset( $roles->roles[ $slug ] ) ) { return; }
		$roles->roles[ $slug ]['name'] = $label;
		$roles->role_names[ $slug ] = $label;
		update_option( $roles->role_key, $roles->roles );
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
		return array_merge( array( 'market_start' => '', 'market_end' => '', 'venue' => '', 'exhibitors' => '', 'price' => '', 'reduced_price' => '', 'reduced_description' => '', 'year' => '', 'opens' => '', 'closes' => '', 'selection_public' => false, 'presentation' => '', 'thank_you' => '', 'image' => 0, 'rules' => 0, 'application_document' => 0, 'stand_length' => '5', 'stand_editable' => true ), is_array( $value ) ? $value : array() );
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
		wp_enqueue_script( 'mp-edition', plugins_url( '../assets/edition.js', __FILE__ ), array( 'media-views' ), '0.19.0-beta.4', true );
	}

	/** Documents publics distincts des justificatifs privés des candidats. */
	public static function introduction( int $edition ): string {
		$data = self::settings( $edition );
		$html = '<div class="mp-edition-introduction">';
		$details = array();
		if ( $data['market_start'] && $data['market_end'] ) {
			$start = self::parse_date( $data['market_start'] . 'T00:00' ); $end = self::parse_date( $data['market_end'] . 'T00:00' );
			if ( $start && $end ) { $details['Dates du marché'] = $data['market_start'] === $data['market_end'] ? 'Le ' . wp_date( 'j F Y', $start->getTimestamp() ) : 'Du ' . wp_date( 'j F Y', $start->getTimestamp() ) . ' au ' . wp_date( 'j F Y', $end->getTimestamp() ); }
		}
		if ( $data['venue'] ) { $details['Lieu d’exposition'] = $data['venue']; }
		if ( $data['exhibitors'] ) { $details['Nombre d’exposants'] = $data['exhibitors']; }
		if ( '' !== $data['price'] ) { $details['Prix de l’emplacement'] = 0.0 === (float) $data['price'] ? 'Gratuit' : number_format_i18n( (float) $data['price'], 2 ) . ' €'; }
		if ( '' !== $data['reduced_price'] ) { $details['Tarif réduit'] = ( 0.0 === (float) $data['reduced_price'] ? 'Gratuit' : number_format_i18n( (float) $data['reduced_price'], 2 ) . ' €' ) . ' — ' . $data['reduced_description']; }
		if ( $details ) {
			$html .= '<dl class="mp-market-details">';
			foreach ( $details as $label => $value ) { $html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . nl2br( esc_html( $value ) ) . '</dd></div>'; }
			$html .= '</dl>';
		}
		$opens = self::parse_date( $data['opens'] );
		$closes = self::parse_date( $data['closes'] );
		if ( $opens && $closes ) {
			$html .= '<p class="mp-application-period"><strong>Période de candidature</strong><span>' . esc_html( 'Du ' . wp_date( 'j F Y', $opens->getTimestamp() ) . ' au ' . wp_date( 'j F Y', $closes->getTimestamp() ) ) . '</span></p>';
		}
		if ( '' !== $data['presentation'] ) { $html .= wpautop( wp_kses_post( $data['presentation'] ) ); }
		foreach ( array( 'rules' => 'Règlement intérieur' ) as $key => $label ) {
			$url = $data[ $key ] ? wp_get_attachment_url( $data[ $key ] ) : false;
			if ( $url ) { $html .= '<p><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . ' (PDF)</a></p>'; }
		}
		return $html . '</div>';
	}

	public static function market_fields( array $data ): void {
		echo '<h3>Informations pratiques du marché</h3><p>Affichées au-dessus du formulaire public. Les dates du marché sont distinctes de la période de candidature. Les prix sont en euros, pour un emplacement sur l’ensemble du marché.</p><table class="form-table" role="presentation">';
		echo '<tr><th><label for="mp-year">Édition de l’année *</label></th><td><input class="regular-text" id="mp-year" name="mp_edition[year]" type="number" min="2000" max="9999" required value="' . esc_attr( $data['year'] ) . '"></td></tr>';
		$fields = array( 'market_start' => array( 'Date de début du marché', 'date' ), 'market_end' => array( 'Date de fin du marché', 'date' ), 'venue' => array( 'Lieu d’exposition', 'text' ), 'exhibitors' => array( 'Nombre d’exposants', 'number' ), 'price' => array( 'Prix de l’emplacement (€)', 'number' ), 'reduced_price' => array( 'Prix de l’emplacement — tarif réduit (€)', 'number' ) );
		foreach ( $fields as $key => [ $label, $type ] ) {
			$optional = 'reduced_price' === $key;
			$attrs = 'number' === $type ? ( 'exhibitors' === $key ? ' min="1" max="100000" step="1"' : ' min="0" max="1000000" step="0.01"' ) : '';
			echo '<tr><th><label for="mp-' . esc_attr( $key ) . '">' . esc_html( $label ) . ( $optional ? ' (facultatif)' : ' *' ) . '</label></th><td><input class="regular-text" id="mp-' . esc_attr( $key ) . '" name="mp_edition[' . esc_attr( $key ) . ']" type="' . esc_attr( $type ) . '" value="' . esc_attr( $data[ $key ] ) . '"' . $attrs . ( $optional ? '' : ' required' ) . '>';
			if ( 'venue' === $key ) { echo '<p class="description">Nom du lieu, adresse et commune.</p>'; }
			if ( 'price' === $key ) { echo '<p class="description">Indiquez 0 pour un emplacement gratuit. Précisez les conditions ou suppléments dans la présentation ou le règlement.</p>'; }
			if ( $optional ) { echo '<p class="description">Laissez vide si aucun tarif réduit n’est proposé. Indiquez 0 pour la gratuité.</p>'; }
			echo '</td></tr>';
		}
		echo '<tr><th><label for="mp-reduced_description">Conditions du tarif réduit</label></th><td><textarea class="large-text" rows="3" maxlength="2000" id="mp-reduced_description" name="mp_edition[reduced_description]">' . esc_textarea( $data['reduced_description'] ) . '</textarea><p class="description">Obligatoire si un tarif réduit est renseigné. Exemple : réservé aux adhérents de l’association XXX.</p></td></tr></table>';
	}

	public static function validate_market( array $data ): ?array {
		$result = array();
		$keys = array( 'market_start', 'market_end', 'venue', 'exhibitors', 'price', 'reduced_price', 'reduced_description' );
		foreach ( $keys as $key ) {
			if ( ! is_string( $data[ $key ] ?? '' ) ) { return null; }
			$result[ $key ] = trim( $data[ $key ] ?? '' );
		}
		// Les anciennes éditions restent lisibles ; le nouvel écran demande de compléter les champs.
		if ( ! array_intersect( $keys, array_keys( $data ) ) ) { return $result; }
		foreach ( array( 'market_start', 'market_end' ) as $key ) { if ( ! self::parse_date( $result[ $key ] . 'T00:00' ) ) { return null; } }
		if ( $result['market_end'] < $result['market_start'] || '' === $result['venue'] || strlen( $result['venue'] ) > 1000 || ! preg_match( '/^[1-9][0-9]{0,5}$/D', $result['exhibitors'] ) || (int) $result['exhibitors'] > 100000 ) { return null; }
		foreach ( array( 'price', 'reduced_price' ) as $key ) {
			if ( 'reduced_price' === $key && '' === $result[ $key ] ) { continue; }
			if ( ! preg_match( '/^[0-9]+(?:[.,][0-9]{1,2})?$/D', $result[ $key ] ) ) { return null; }
			$result[ $key ] = str_replace( ',', '.', $result[ $key ] );
			if ( (float) $result[ $key ] > 1000000 ) { return null; }
		}
		$result['venue'] = sanitize_text_field( $result['venue'] );
		$result['reduced_description'] = sanitize_textarea_field( $result['reduced_description'] );
		if ( '' === $result['venue'] || strlen( $result['reduced_description'] ) > 2000 ) { return null; }
		if ( '' !== $result['reduced_price'] && ( '' === $result['reduced_description'] || (float) $result['reduced_price'] > (float) $result['price'] ) ) { return null; }
		if ( '' === $result['reduced_price'] ) { $result['reduced_description'] = ''; }
		return $result;
	}

	public static function stand_value( int $edition, mixed $submitted = null ): string {
		$data = self::settings( $edition );
		return ! $data['stand_editable'] || null === $submitted ? $data['stand_length'] : ( is_string( $submitted ) ? $submitted : '' );
	}

	public static function add_box(): void {
		add_meta_box( 'mp-edition-settings', __( 'Paramètres de l’édition', 'marche-potier' ), array( self::class, 'render_box' ), 'mp_edition', 'normal', 'high' );
		add_meta_box( 'mp-edition-publication', 'Affichage sur le site', array( self::class, 'publication_box' ), 'mp_edition', 'normal', 'low' );
	}

	public static function publication_box( \WP_Post $post ): void {
		$data = self::settings( $post->ID );
		echo '<h3>Afficher le formulaire de candidature</h3><ol><li>Enregistrez et publiez cette édition.</li><li>Ouvrez ou créez une page WordPress, cliquez sur « + » puis ajoutez le bloc <strong>Formulaire de candidature</strong>.</li><li>Dans le bloc, choisissez <strong>' . esc_html( Blocks::edition_label( $post->ID ) ) . '</strong> dans la liste des éditions, puis publiez ou mettez à jour la page.</li></ol>';
		echo '<p>Le formulaire respecte les dates d’ouverture et de fermeture des inscriptions. Vous pouvez noter les candidatures à tout moment en mode votes multiples, indépendamment de ces dates.</p>';
		echo '<h3>Afficher la sélection</h3><ol><li>Ajoutez le bloc <strong>Présentation de la sélection</strong> dans la page de votre choix.</li><li>Choisissez cette édition dans le bloc et publiez la page.</li><li>Lorsque la sélection est prête, cochez l’autorisation ci-dessous et enregistrez l’édition.</li></ol>';
		echo '<p><label><input name="mp_edition[selection_public]" type="checkbox" value="1" ' . checked( $data['selection_public'], true, false ) . '> <strong>Autoriser l’affichage public de la sélection</strong></label></p>';
		echo '<p class="description">Désactivé par défaut. Seuls les candidats sélectionnés ayant autorisé leur présentation sont affichés. Publier l’édition ne publie pas automatiquement la sélection.</p>';
	}

	public static function render_box( \WP_Post $post ): void {
		$data = self::settings( $post->ID );
		wp_nonce_field( 'mp_save_edition_' . $post->ID, 'mp_edition_nonce' );
		self::market_fields( $data );
		?>
		<h3>Période de candidature</h3>
		<p><?php echo esc_html( sprintf( __( 'Fuseau horaire du site : %s. La fermeture prend effet à l’heure exacte indiquée.', 'marche-potier' ), wp_timezone_string() ) ); ?></p>
		<table class="form-table" role="presentation">
			<?php foreach ( array( 'opens' => __( 'Ouverture des candidatures', 'marche-potier' ), 'closes' => __( 'Fermeture des candidatures', 'marche-potier' ) ) as $key => $label ) : ?>
			<tr><th><label for="mp-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input id="mp-<?php echo esc_attr( $key ); ?>" name="mp_edition[<?php echo esc_attr( $key ); ?>]" type="datetime-local" required value="<?php echo esc_attr( $data[ $key ] ); ?>"></td></tr>
			<?php endforeach; ?>
		</table>
		<h3>Informations complémentaires</h3>
		<p><label for="mppresentation">Texte de présentation complémentaire</label></p>
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
		<p class="description">Les informations du marché, cette présentation et le règlement sont publics et affichés avant le formulaire, même lorsque les candidatures sont fermées.</p>
		<?php foreach ( array( 'rules' => 'Règlement intérieur' ) as $key => $label ) : ?>
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
		<p class="description">Le destinataire des candidatures se définit dans « Organisateur et votes », avec le compte de l’administrateur du marché. En votes multiples, les votants actifs reçoivent aussi les dossiers.</p>
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
		$market = self::validate_market( $data );
		if ( null === $market ) { return null; }
		$opens = self::parse_date( $data['opens'] );
		$closes = self::parse_date( $data['closes'] );
		if ( ! $opens || ! $closes || $closes <= $opens ) {
			return null;
		}
		if ( ! is_string( $data['thank_you'] ?? '' ) || strlen( $data['thank_you'] ?? '' ) > 20000 ) { return null; }
		$stand = $data['stand_length'] ?? '5';
		if ( ! is_string( $stand ) || ! preg_match( '/^\d+(?:[.,]\d{1,2})?$/D', $stand ) ) { return null; }
		$stand = str_replace( ',', '.', $stand );
		if ( (float) $stand < 0.01 || (float) $stand > 1000 || ! is_string( $data['presentation'] ?? '' ) || strlen( $data['presentation'] ?? '' ) > 80000 || ! in_array( $data['stand_editable'] ?? '1', array( '0', '1' ), true ) ) { return null; }
		$media = array();
		foreach ( array( 'image', 'rules' ) as $key ) {
			$value = $data[ $key ] ?? '0';
			if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) ) { return null; }
			$media[ $key ] = (int) $value;
			if ( $media[ $key ] && ( 'attachment' !== get_post_type( $media[ $key ] ) || ! in_array( get_post_mime_type( $media[ $key ] ), 'image' === $key ? array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' ) : array( 'application/pdf' ), true ) ) ) { return null; }
		}
		return $market + array(
			'thank_you' => sanitize_textarea_field( $data['thank_you'] ?? '' ),
			'presentation' => isset( $data['presentation'] ) ? wp_kses_post( $data['presentation'] ) : '',
			'image' => $media['image'], 'rules' => $media['rules'], 'application_document' => 0,
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
		// Valider les paramètres avant toute création de compte ou invitation.
		if ( ! Jury::save( $id ) ) { return; }
		// Un seul enregistrement pour ne pas mélanger anciens et nouveaux réglages.
		update_post_meta( $id, self::META, wp_slash( $data ) );
	}

	public static function notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'mp_edition' !== $screen->post_type || ! isset( $_GET['mp_edition_error'] ) || ! current_user_can( 'mp_manage_editions' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Paramètres non enregistrés : indiquez une année entre 2000 et 9999 et deux dates valides, avec une fermeture après l’ouverture. Vérifiez aussi les informations du marché (dates, lieu, exposants, prix et conditions du tarif réduit), la taille du stand (0,01 à 1000 m, deux décimales maximum), le texte et les médias (image ou PDF selon le champ). Les anciens paramètres sont conservés ; le titre et le statut WordPress peuvent avoir été enregistrés.', 'marche-potier' ) . '</p></div>';
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
