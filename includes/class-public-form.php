<?php
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class PublicForm {
	private static ?\WP_Error $error = null;
	private static array $values = array();
	private const COOKIE = 'mp_form_session';
	public static function hooks(): void {
		add_shortcode( 'inscription_potier', array( self::class, 'shortcode' ) );
		add_action( 'template_redirect', array( self::class, 'request' ) );
		add_action( 'wp_enqueue_scripts', static function () {
			if ( self::is_form_page() ) {
				wp_enqueue_style( 'mp-form', plugins_url( '../assets/form.css', __FILE__ ), array(), '0.12.1' );
				wp_enqueue_script( 'mp-form', plugins_url( '../assets/form.js', __FILE__ ), array(), '0.12.1', true );
			}
		} );
	}
	private static function is_form_page(): bool {
		$post = get_queried_object();
		return is_singular() && $post instanceof \WP_Post && has_shortcode( $post->post_content, 'inscription_potier' );
	}
	public static function resolve( string $year ): int {
		if ( ! preg_match( '/^[2-9][0-9]{3}$/D', $year ) ) { return 0; }
		$matches = array();
		foreach ( get_posts( array( 'post_type' => 'mp_edition', 'post_status' => 'publish', 'posts_per_page' => -1 ) ) as $edition ) {
			if ( Editions::settings( $edition->ID )['year'] === $year ) { $matches[] = $edition->ID; }
		}
		return count( $matches ) === 1 ? $matches[0] : 0;
	}
	private static function session(): string {
		$value = $_COOKIE[ self::COOKIE ] ?? '';
		return is_string( $value ) && preg_match( '/^[a-f0-9]{64}$/D', $value ) ? $value : '';
	}
	private static function signature( int $edition, string $issued, string $random ): string {
		return hash_hmac( 'sha256', self::session() . '|' . $edition . '|' . $issued . '|' . $random, wp_salt( 'nonce' ) );
	}
	public static function request(): void {
		if ( ! self::is_form_page() ) { return; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		nocache_headers();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			$value = self::session() ?: bin2hex( random_bytes( 32 ) );
			setcookie( self::COOKIE, $value, array( 'expires' => time() + DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
			$_COOKIE[ self::COOKIE ] = $value;
			return;
		}
		if ( (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 6 * PrivateFiles::MAX_SIZE + 1048576 ) { self::fail( new \WP_Error( 'size', 'L’envoi est trop volumineux. Chaque fichier est limité à ' . PrivateFiles::size_label() . '.' ) ); return; }
		unset( $_POST['mp_record_nonce'], $_POST['mp_edition_nonce'] );
		$input = wp_unslash( $_POST );
		self::$values = isset( $input['mp_record'] ) && is_array( $input['mp_record'] ) ? $input['mp_record'] : array();
		foreach ( array( 'mp_edition', 'mp_issued', 'mp_random', 'mp_signature', 'mp_nonce' ) as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) ) { self::fail( new \WP_Error( 'session', 'L’envoi a expiré ou dépasse la limite du serveur. Rechargez la page pour retrouver les pièces déjà reçues.' ) ); return; }
		}
		$edition = ctype_digit( $input['mp_edition'] ) ? (int) $input['mp_edition'] : 0;
		$issued = $input['mp_issued'];
		if ( ! self::session() || ! ctype_digit( $issued ) || (int) $issued > time() || time() - (int) $issued > DAY_IN_SECONDS || ! preg_match( '/^[a-f0-9]{32}$/D', $input['mp_random'] ) || ! hash_equals( self::signature( $edition, $issued, $input['mp_random'] ), $input['mp_signature'] ) || ! wp_verify_nonce( $input['mp_nonce'], 'mp_public_' . $edition ) ) {
			self::fail( new \WP_Error( 'session', 'La session a expiré. Rechargez la page ; les cookies doivent être autorisés pour envoyer une candidature.' ) ); return;
		}
		if ( ! empty( $input['mp_fax'] ) ) { self::fail( new \WP_Error( 'spam', 'Envoi refusé. Rechargez le formulaire.' ) ); return; }
		setcookie( self::COOKIE, self::session(), array( 'expires' => time() + DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		$draft = UploadDrafts::key( self::session(), $edition );
		$operation = $input['mp_upload_operation'] ?? '';
		if ( self::async() && in_array( $operation, array( 'status', 'upload' ), true ) ) {
			if ( ! SubmissionLock::acquire() ) { self::fail( new \WP_Error( 'busy', 'Un enregistrement est en cours. Réessayez dans quelques instants.' ) ); return; }
			try {
				if ( 'status' === $operation ) {
					$result = UploadDrafts::state( UploadDrafts::read( $draft ) );
					if ( UploadDrafts::receipt( $draft ) ) { $result['submitted'] = true; }
				} elseif ( ! Editions::is_open( $edition ) ) {
					$result = new \WP_Error( 'closed', 'Les candidatures sont fermées pour cette édition.' );
				} else {
					$rate = 'mp_upload_rate_' . hash_hmac( 'sha256', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), wp_salt() );
					$attempts = (int) get_transient( $rate );
					if ( $attempts >= 90 ) { $result = new \WP_Error( 'rate', 'Trop de transferts. Réessayez dans quinze minutes.' ); }
					else {
						set_transient( $rate, $attempts + 1, 900 );
						$result = UploadDrafts::upload( $draft, is_string( $input['mp_slot'] ?? null ) ? $input['mp_slot'] : '', $_FILES );
					}
				}
			} finally { SubmissionLock::release(); }
			if ( is_wp_error( $result ) ) { self::fail( $result ); return; }
			if ( ! empty( $result['submitted'] ) ) { self::success( $edition ); }
			wp_send_json_success( $result );
		}
		// Débit limité par adresse réseau hachée et fenêtre courte ; aucun IP brut conservé.
		$rate = 'mp_rate_' . hash_hmac( 'sha256', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), wp_salt() );
		$attempts = (int) get_transient( $rate );
		if ( $attempts >= 15 ) { self::fail( new \WP_Error( 'rate', 'Trop de tentatives. Réessayez dans quinze minutes.' ) ); return; }
		set_transient( $rate, $attempts + 1, 900 );
		$result = self::submit( $edition, self::$values, $_FILES, isset( $input['mp_photo_consent'] ) && '1' === $input['mp_photo_consent'], self::async() ? $draft : '', is_string( $input['mp_revision'] ?? null ) ? $input['mp_revision'] : '' );
		if ( is_wp_error( $result ) ) { self::fail( $result ); return; }
		self::success( $edition );
	}
	private static function async(): bool { return isset( $_GET['mp_async'] ) && '1' === $_GET['mp_async']; }
	private static function fail( \WP_Error $error ): void {
		self::$error = $error;
		if ( self::async() ) { wp_send_json_error( array( 'code' => $error->get_error_code(), 'message' => $error->get_error_message() ), 400 ); }
	}
	private static function success( int $edition ): void {
		set_transient( 'mp_receipt_' . hash( 'sha256', self::session() ), $edition, 600 );
		$url = add_query_arg( 'mp_sent', '1', get_permalink() ) . '#mp-inscription';
		if ( self::async() ) { wp_send_json_success( array( 'redirect' => $url ) ); }
		wp_safe_redirect( $url, 303 );
		exit;
	}
	/** Les coordonnées publiques ne peuvent jamais remplacer un profil existant. */
	public static function submit( int $edition, array $raw, array $uploads, bool $consent = false, string $draft = '', string $revision = '' ): int|\WP_Error {
		if ( $draft && ( $receipt = UploadDrafts::receipt( $draft ) ) ) { return $receipt; }
		if ( ! $consent ) { return new \WP_Error( 'consent', 'Pour envoyer votre candidature, veuillez cocher l’autorisation de présentation publique et de localisation sur la carte.' ); }
		if ( ! Editions::is_open( $edition ) ) { return new \WP_Error( 'closed', 'Les candidatures ne sont pas ouvertes pour cette édition.' ); }
		if ( isset( $raw['activity'] ) && is_array( $raw['activity'] ) ) {
			$raw['activity']['stand_length'] = Editions::stand_value( $edition, $raw['activity']['stand_length'] ?? null );
		}
		$clean = array();
		foreach ( array( 'identity' => Fields::identity(), 'activity' => Fields::activity() ) as $group => $schema ) {
			if ( ! isset( $raw[ $group ] ) || ! is_array( $raw[ $group ] ) ) { return new \WP_Error( 'fields', 'Complétez vos coordonnées et votre activité.' ); }
			$clean[ $group ] = Fields::validate( $raw[ $group ], $schema, true );
			if ( is_wp_error( $clean[ $group ] ) ) { return $clean[ $group ]; }
		}
		if ( ! SubmissionLock::acquire() ) { return new \WP_Error( 'busy', 'Un autre enregistrement est en cours. Réessayez dans quelques instants.' ); }
		$files = array(); $created = array(); $ok = false;
		try {
			if ( $draft && ( $receipt = UploadDrafts::receipt( $draft ) ) ) { $ok = true; return $receipt; }
			if ( ! Editions::is_open( $edition ) ) { return new \WP_Error( 'closed', 'La période de candidature vient de se terminer.' ); }
			$email = strtolower( $clean['identity']['email'] );
			if ( self::duplicate( $edition, $email ) ) { return new \WP_Error( 'duplicate', 'Une candidature utilise déjà cette adresse email pour cette édition. Pour une correction, contactez l’organisateur.' ); }
			$potier = self::find_potier( $clean['identity'] );
			$files = $draft ? UploadDrafts::copies( $draft, $revision ) : PrivateFiles::store( $uploads );
			if ( is_wp_error( $files ) ) { $error = $files; $files = array(); return $error; }
			// Recontrôle après le traitement des images : un formulaire ancien ne contourne pas la fermeture.
			if ( ! Editions::is_open( $edition ) ) { return new \WP_Error( 'closed', 'La période de candidature vient de se terminer.' ); }
			$title = $clean['identity']['last_name'] . ' ' . $clean['identity']['first_name'];
			if ( ! $potier ) {
				$potier = wp_insert_post( wp_slash( array( 'post_type' => 'mp_potier', 'post_status' => 'publish', 'post_title' => $title, 'post_author' => 0 ) ), true );
				if ( is_wp_error( $potier ) ) { return new \WP_Error( 'save', 'Enregistrement impossible. Réessayez plus tard.' ); }
				$created[] = $potier;
				if ( ! update_post_meta( $potier, Records::META, wp_slash( array( 'identity' => Records::minimal_identity( $clean['identity'] ) ) ) ) ) { throw new \RuntimeException( 'profile' ); }
			}
			$app = wp_insert_post( wp_slash( array( 'post_type' => 'mp_candidature', 'post_status' => 'publish', 'post_title' => $title . ' — ' . get_the_title( $edition ), 'post_author' => 0 ) ), true );
			if ( is_wp_error( $app ) ) { throw new \RuntimeException( 'application' ); }
			$created[] = $app;
			$data = $clean + array( 'potier_id' => (int) $potier, 'edition_id' => $edition, 'decision' => 'pending', 'internal' => array( 'notes' => '', 'social_date' => '' ), 'files' => $files, 'submitted_at' => gmdate( 'c' ), 'publication_consent' => $consent, 'source' => 'public', 'email_verified' => false );
			if ( ! update_post_meta( $app, Records::META, wp_slash( $data ) ) || ! update_post_meta( $app, '_mp_potier_id', $potier ) || ! update_post_meta( $app, '_mp_edition_id', $edition ) || ! update_post_meta( $app, '_mp_decision', 'pending' ) ) { throw new \RuntimeException( 'metadata' ); }
			if ( $draft && ! update_post_meta( $app, '_mp_upload_key', $draft ) ) { throw new \RuntimeException( 'receipt' ); }
			$ok = true;
			if ( $draft ) { UploadDrafts::finish( $draft ); }
			return $app;
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'save', 'L’enregistrement n’a pas abouti. Réessayez plus tard ; les pièces envoyées séparément restent disponibles pendant leur durée de conservation.' );
		} finally {
			if ( ! $ok ) { foreach ( array_reverse( $created ) as $id ) { wp_delete_post( $id, true ); } PrivateFiles::remove( $files ); }
			SubmissionLock::release();
			if ( $ok && ! empty( $created ) && isset( $app ) && is_int( $app ) ) {
				try { Notifications::send( $app ); } catch ( \Throwable $error ) { update_post_meta( $app, '_mp_mail_error', 'failed' ); }
			}
		}
	}
	/** Une identité différente avec le même email obtient sa propre fiche. */
	public static function find_potier( array $submitted ): int {
		$email = strtolower( trim( $submitted['email'] ?? '' ) );
		if ( '' === $email ) { return 0; }
		foreach ( get_posts( array( 'post_type' => 'mp_potier', 'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future' ), 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) ) as $id ) {
			$identity = Records::data( $id )['identity'] ?? array();
			if ( strtolower( trim( $identity['email'] ?? '' ) ) === $email && self::name_key( $identity ) === self::name_key( $submitted ) ) { return (int) $id; }
		}
		return 0;
	}
	private static function name_key( array $identity ): string {
		return strtolower( remove_accents( trim( $identity['last_name'] ?? '' ) . '|' . trim( $identity['first_name'] ?? '' ) ) );
	}
	public static function duplicate( int $edition, string $email, int $exclude = 0 ): bool {
		foreach ( get_posts( array( 'post_type' => 'mp_candidature', 'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future', 'trash' ), 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_mp_edition_id', 'meta_value' => $edition ) ) as $id ) {
			if ( $id !== $exclude && strtolower( Records::data( $id )['identity']['email'] ?? '' ) === strtolower( $email ) ) { return true; }
		}
		return false;
	}
	public static function shortcode( $attributes ): string {
		$atts = shortcode_atts( array( 'edition' => '' ), $attributes );
		$edition = self::resolve( (string) $atts['edition'] );
		if ( ! $edition ) { return '<p>Cette édition est indisponible. Contactez l’organisateur.</p>'; }
		ob_start();
		echo '<section id="mp-inscription" class="mp-public"><p class="mp-eyebrow">Marché de potiers · Inscription</p><h2>Votre candidature — ' . esc_html( (string) $atts['edition'] ) . '</h2>';
		echo Editions::introduction( $edition );
		if ( isset( $_GET['mp_sent'] ) && self::session() && (int) get_transient( 'mp_receipt_' . hash( 'sha256', self::session() ) ) === $edition ) {
			echo '<div class="mp-success" role="status">' . nl2br( esc_html( Editions::thank_you( $edition ) ) ) . '</div></section>';
			return ob_get_clean();
		}
		if ( self::$error ) { echo '<div class="mp-error" role="alert">' . esc_html( self::$error->get_error_message() ) . '<p>Vos réponses restent ci-dessous. Sélectionnez à nouveau les fichiers avant de renvoyer.</p></div>'; }
		if ( ! Editions::is_open( $edition ) ) {
			$settings = Editions::settings( $edition );
			$opens = Editions::parse_date( $settings['opens'] ); $closes = Editions::parse_date( $settings['closes'] );
			echo '<p>Les candidatures sont actuellement fermées.</p>';
			if ( $opens && $closes ) { echo '<p>Période prévue : du ' . esc_html( wp_date( 'd/m/Y à H:i', $opens->getTimestamp() ) ) . ' au ' . esc_html( wp_date( 'd/m/Y à H:i', $closes->getTimestamp() ) ) . ' (' . esc_html( wp_timezone_string() ) . ').</p>'; }
			echo '</section>'; return ob_get_clean();
		}
		$issued = (string) time(); $random = bin2hex( random_bytes( 16 ) );
		echo '<p class="mp-form-intro">Présentez votre atelier, vos créations et votre savoir-faire.</p><p class="mp-required-note">Les champs marqués * sont obligatoires.</p><form method="post" enctype="multipart/form-data" action="' . esc_url( get_permalink() . '#mp-inscription' ) . '">';
		foreach ( array( 'mp_edition' => $edition, 'mp_issued' => $issued, 'mp_random' => $random, 'mp_signature' => self::signature( $edition, $issued, $random ), 'mp_nonce' => wp_create_nonce( 'mp_public_' . $edition ) ) as $key => $value ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">'; }
		echo '<div class="mp-honey" aria-hidden="true"><label>Ne pas remplir <input name="mp_fax" tabindex="-1" autocomplete="off"></label></div>';
		self::render_group( Fields::identity(), 'identity', 'Vos coordonnées' );
		self::render_group( Fields::activity(), 'activity', 'Votre activité', $edition );
		echo '<fieldset class="mp-section mp-files-section"><legend><span class="mp-section-number" aria-hidden="true">03</span> Photos et justificatifs</legend><p>' . esc_html( PrivateFiles::size_label() ) . ' maximum par fichier. Photos : JPEG, PNG ou WebP. Justificatifs : PDF ou image JPEG, PNG, WebP. Les fichiers restent réservés aux organisateurs.</p><div class="mp-field-grid">';
		foreach ( PrivateFiles::slots() as $slot => $label ) {
			$pdf = in_array( $slot, array( 'status', 'insurance' ), true );
			echo '<div class="mp-field mp-upload-card"><label for="mp-file-' . esc_attr( $slot ) . '">' . esc_html( $label ) . ' *</label><input id="mp-file-' . esc_attr( $slot ) . '" type="file" data-max-size="' . esc_attr( (string) PrivateFiles::max_size() ) . '" data-max-label="' . esc_attr( PrivateFiles::size_label() ) . '" name="' . esc_attr( $slot ) . '" accept="' . ( $pdf ? '.pdf,.jpg,.jpeg,.png,.webp' : '.jpg,.jpeg,.png,.webp' ) . '" required></div>';
		}
		echo '</div></fieldset><div class="mp-consent"><p><label><input type="checkbox" name="mp_photo_consent" value="1" required ' . checked( isset( $_POST['mp_photo_consent'] ) && '1' === $_POST['mp_photo_consent'], true, false ) . '> J’autorise l’affichage de ma présentation, de mes photos de créations et la localisation publique de l’adresse fournie sur la carte si je suis sélectionné(e). (Obligatoire)</label></p><p>Votre email, votre téléphone et vos justificatifs restent réservés aux organisateurs. Avec votre autorisation, votre nom, votre ville, vos liens et vos photos pourront être présentés, et votre adresse localisée sur la carte. La localisation des adresses françaises utilise le service IGN.</p></div>';
		$privacy = get_privacy_policy_url();
		if ( $privacy ) { echo '<p><a href="' . esc_url( $privacy ) . '">Politique de confidentialité</a></p>'; }
		echo '<button type="submit">Envoyer ma candidature</button></form></section>';
		return ob_get_clean();
	}
	private static function render_group( array $schema, string $group, string $title, int $edition = 0 ): void {
		echo '<fieldset class="mp-section"><legend><span class="mp-section-number" aria-hidden="true">' . ( 'identity' === $group ? '01' : '02' ) . '</span> ' . esc_html( $title ) . '</legend><div class="mp-field-grid">';
		$data = self::$values[ $group ] ?? array(); if ( ! is_array( $data ) ) { $data = array(); }
		foreach ( $schema as $key => $field ) {
			$value = $data[ $key ] ?? ( 'stand_length' === $key ? '5' : '' );
			$locked = 'stand_length' === $key && $edition && ! Editions::settings( $edition )['stand_editable'];
			if ( 'stand_length' === $key && $edition ) { $value = Editions::stand_value( $edition, $data[ $key ] ?? null ); }
			$value = 'multiple' === $field[1] ? ( is_array( $value ) ? array_filter( $value, 'is_string' ) : array() ) : ( is_string( $value ) ? $value : '' );
			$id = 'mp-public-' . $key; $name = 'mp_record[' . $group . '][' . $key . ']';
			$condition = isset( $field[4] ) ? ' data-parent="' . esc_attr( $field[4][0] ) . '" data-choice="' . esc_attr( $field[4][1] ) . '"' : '';
			$required = $field[2] ? ' required' : '';
			echo '<div class="mp-field' . ( in_array( $field[1], array( 'textarea', 'multiple' ), true ) || 'company' === $key || isset( $field[4] ) ? ' mp-field-wide' : '' ) . '"' . $condition . '>';
			if ( 'multiple' === $field[1] ) {
				echo '<fieldset data-multiple="' . esc_attr( $key ) . '"><legend>' . esc_html( $field[0] ) . ' *</legend>';
				foreach ( $field[3] as $option => $label ) { echo '<label class="mp-choice"><input type="checkbox" name="' . esc_attr( $name . '[]' ) . '" value="' . esc_attr( $option ) . '" ' . checked( in_array( $option, $value, true ), true, false ) . '> ' . esc_html( $label ) . '</label>'; }
				echo '</fieldset>';
			} else {
				echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $field[0] . ( $field[2] ? ' *' : '' ) ) . '</label>';
				if ( 'association_details' === $key ) { echo '<small id="mp-association-help">Avez vous une implication dans la vie associative de la Céramique? (Ex: organisation de marché, implication active dans boutique, CA d’asso, …)</small>'; }
				$attrs = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $required;
				if ( 'association_details' === $key ) { $attrs .= ' aria-describedby="mp-association-help"'; }
				if ( $locked ) { $attrs .= ' readonly aria-describedby="mp-stand-fixed"'; echo '<small id="mp-stand-fixed">Taille fixée par l’organisateur.</small>'; }
				if ( 'textarea' === $field[1] ) { echo '<textarea rows="4" maxlength="10000"' . $attrs . '>' . esc_textarea( $value ) . '</textarea>'; }
				elseif ( 'select' === $field[1] ) {
					echo '<select' . $attrs . '><option value="">Choisir…</option>';
					foreach ( $field[3] as $option => $label ) { echo '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( $label ) . '</option>'; }
					echo '</select>';
				} else { echo '<input type="' . esc_attr( $field[1] ) . '"' . $attrs . ' value="' . esc_attr( $value ) . '"' . ( 'number' === $field[1] ? ' min="0.01" max="1000" step="0.01"' : ' maxlength="1000"' ) . '>'; }
			}
			if ( isset( $field[4] ) ) { echo '<small>À préciser si la réponse correspondante est « ' . esc_html( $schema[ $field[4][0] ][3][ $field[4][1] ] ) . ' ».</small>'; }
			echo '</div>';
		}
		echo '</div></fieldset>';
	}
}
