<?php
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class PublicForm {
	private static ?\WP_Error $error = null;
	private static array $values = array();
	private static bool $consent = false;
	private static bool $map_consent = false;
	private const COOKIE = 'marcpo_form_session';
	public static function hooks(): void {
		add_action( 'template_redirect', array( self::class, 'request' ) );
		add_action( 'wp_enqueue_scripts', static function () {
			if ( self::is_form_page() ) {
				wp_enqueue_style( 'marcpo-form', plugins_url( '../assets/form.css', __FILE__ ), array(), '0.19.1' );
				wp_enqueue_script( 'marcpo-draft', plugins_url( '../assets/draft.js', __FILE__ ), array(), '0.19.1.1', true );
				wp_enqueue_script( 'marcpo-form', plugins_url( '../assets/form.js', __FILE__ ), array( 'marcpo-draft' ), '0.19.1', true );
			}
		} );
	}
	private static function is_form_page(): bool {
		$post = get_queried_object();
		return is_singular() && $post instanceof \WP_Post && Blocks::contains( $post->post_content, Blocks::FORM );
	}
	private static function session(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reject anything except the exact 64-character session token below.
		$value = isset( $_COOKIE[ self::COOKIE ] ) && is_string( $_COOKIE[ self::COOKIE ] ) ? wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		return is_string( $value ) && preg_match( '/^[a-f0-9]{64}$/D', $value ) ? $value : '';
	}
	private static function signature( int $edition, string $issued, string $random ): string {
		return hash_hmac( 'sha256', self::session() . '|' . $edition . '|' . $issued . '|' . $random, wp_salt( 'nonce' ) );
	}
	/** Frontière HTTP commune au dépôt et aux transferts asynchrones : même session et formulaire signé. */
	public static function request(): void {
		if ( ! self::is_form_page() ) { return; }
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress page-cache interoperability constant; must keep this external name.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		nocache_headers();
		if ( 'INVALID' === Request::method() ) { status_header( 405 ); return; }
		if ( 'POST' !== Request::method() ) {
			$value = self::session() ?: bin2hex( random_bytes( 32 ) );
			setcookie( self::COOKIE, $value, array( 'expires' => time() + DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
			$_COOKIE[ self::COOKIE ] = $value;
			return;
		}
		if ( Request::content_length() > 6 * PrivateFiles::MAX_SIZE + 1048576 ) { self::fail( new \WP_Error( 'size', 'L’envoi est trop volumineux. Chaque fichier est limité à ' . PrivateFiles::size_label() . '.' ) ); return; }
		unset( $_POST['marcpo_record_nonce'], $_POST['marcpo_edition_nonce'] );
		self::$consent = '1' === Request::post( 'marcpo_photo_consent' );
		self::$map_consent = '1' === Request::post( 'marcpo_map_consent' );
		$edition = (int) ( Request::post( 'marcpo_edition' ) ?? 0 );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The two schema validators immediately below sanitize allowed fields; the raw copy is never forwarded to submit or rendered.
		$raw = isset( $_POST['marcpo_record'] ) && is_array( $_POST['marcpo_record'] ) ? wp_unslash( $_POST['marcpo_record'] ) : array();
		self::$values = array();
		foreach ( array( 'identity' => Fields::identity(), 'activity' => Fields::activity() ) as $group => $schema ) {
			self::$values[ $group ] = Fields::for_display( is_array( $raw[ $group ] ?? null ) ? $raw[ $group ] : array(), $schema );
		}
		$answers = self::validate_answers( $raw, $edition );
		unset( $raw );
		$post = get_queried_object();
		if ( ! in_array( $edition, Blocks::edition_ids( $post->post_content, Blocks::FORM ), true ) ) {
			self::fail( new \WP_Error( 'edition', 'L’édition affichée sur cette page a changé. Rechargez la page avant de renvoyer votre candidature.' ) ); return;
		}
		$issued = Request::post( 'marcpo_issued' ) ?? '';
		$random = Request::post( 'marcpo_random' ) ?? '';
		$signature = Request::post( 'marcpo_signature' ) ?? '';
		if ( ! self::session() || ! preg_match( '/^[0-9]{1,12}$/D', $issued ) || (int) $issued > time() || time() - (int) $issued > DAY_IN_SECONDS || ! preg_match( '/^[a-f0-9]{32}$/D', $random ) || ! preg_match( '/^[a-f0-9]{64}$/D', $signature ) || ! hash_equals( self::signature( $edition, $issued, $random ), $signature ) ) {
			self::fail( new \WP_Error( 'session', 'La session a expiré. Rechargez la page ; les cookies doivent être autorisés pour envoyer une candidature.' ) ); return;
		}
		if ( ! isset( $_POST['marcpo_nonce'] ) || ! is_string( $_POST['marcpo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['marcpo_nonce'] ) ), 'marcpo_public_' . $edition ) ) {
			self::fail( new \WP_Error( 'session', 'La session a expiré. Rechargez la page pour retrouver les pièces déjà reçues.' ) ); return;
		}
		if ( isset( $_POST['marcpo_fax'] ) && '' !== Request::post( 'marcpo_fax' ) ) { self::fail( new \WP_Error( 'spam', 'Envoi refusé. Rechargez le formulaire.' ) ); return; }
		setcookie( self::COOKIE, self::session(), array( 'expires' => time() + DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		$draft = UploadDrafts::key( self::session(), $edition );
		$operation = isset( $_POST['marcpo_upload_operation'] ) ? Request::post( 'marcpo_upload_operation' ) : '';
		if ( ! in_array( $operation, array( '', 'status', 'upload' ), true ) || ( '' !== $operation && ! self::async() ) ) { self::fail( new \WP_Error( 'operation', 'Opération invalide. Rechargez le formulaire.' ) ); return; }
		if ( self::async() && in_array( $operation, array( 'status', 'upload' ), true ) ) {
			if ( ! SubmissionLock::acquire() ) { self::fail( new \WP_Error( 'busy', 'Un enregistrement est en cours. Réessayez dans quelques instants.' ) ); return; }
			try {
				if ( 'status' === $operation ) {
					$result = UploadDrafts::state( UploadDrafts::read( $draft ) );
					if ( UploadDrafts::receipt( $draft ) ) { $result['submitted'] = true; }
				} elseif ( ! Editions::is_open( $edition ) ) {
					$result = new \WP_Error( 'closed', 'Les candidatures sont fermées pour cette édition.' );
				} else {
					$rate = 'marcpo_upload_rate_' . hash_hmac( 'sha256', Request::remote_address(), wp_salt() );
					$attempts = (int) get_transient( $rate );
					if ( $attempts >= 90 ) { $result = new \WP_Error( 'rate', 'Trop de transferts. Réessayez dans quinze minutes.' ); }
					else {
						set_transient( $rate, $attempts + 1, 900 );
						$slot = Request::post( 'marcpo_slot' ) ?? '';
						$uploads = Request::uploads();
						$result = is_wp_error( $uploads ) ? $uploads : UploadDrafts::upload( $draft, $slot, $uploads );
					}
				}
			} finally { SubmissionLock::release(); }
			if ( is_wp_error( $result ) ) { self::fail( $result ); return; }
			if ( ! empty( $result['submitted'] ) ) { self::success( $edition ); }
			wp_send_json_success( $result );
		}
		// Débit limité par adresse réseau hachée et fenêtre courte ; aucun IP brut conservé.
		$rate = 'marcpo_rate_' . hash_hmac( 'sha256', Request::remote_address(), wp_salt() );
		$attempts = (int) get_transient( $rate );
		if ( $attempts >= 15 ) { self::fail( new \WP_Error( 'rate', 'Trop de tentatives. Réessayez dans quinze minutes.' ) ); return; }
		set_transient( $rate, $attempts + 1, 900 );
		if ( is_wp_error( $answers ) ) { self::fail( $answers ); return; }
		$revision = Request::post( 'marcpo_revision' ) ?? '';
		if ( self::async() && ! preg_match( '/^[a-f0-9]{32}$/D', $revision ) ) { self::fail( new \WP_Error( 'draft', 'Les pièces ont changé ou expiré. Rechargez la page pour retrouver les pièces disponibles.' ) ); return; }
		$uploads = self::async() ? array() : Request::uploads();
		if ( is_wp_error( $uploads ) ) { self::fail( $uploads ); return; }
		$result = self::submit( $edition, $answers, $uploads, self::$consent, self::async() ? $draft : '', $revision, self::$map_consent );
		if ( is_wp_error( $result ) ) { self::fail( $result ); return; }
		self::success( $edition );
	}
	private static function async(): bool { return ( null !== Request::query( 'marcpo_async' ) ) && '1' === Request::query( 'marcpo_async' ); }
	private static function fail( \WP_Error $error ): void {
		self::$error = $error;
		if ( self::async() ) { wp_send_json_error( array( 'code' => $error->get_error_code(), 'message' => $error->get_error_message() ), 400 ); }
	}
	private static function success( int $edition ): void {
		set_transient( 'marcpo_receipt_' . hash( 'sha256', self::session() ), $edition, 600 );
		$url = add_query_arg( 'marcpo_sent', '1', get_permalink() ) . '#marcpo-inscription';
		if ( self::async() ) { wp_send_json_success( array( 'redirect' => $url ) ); }
		wp_safe_redirect( $url, 303 );
		exit;
	}
	/** Shared by the HTTP boundary and direct callers; invalid choices are rejected before cleaning. */
	private static function validate_answers( array $raw, int $edition ): array|\WP_Error {
		if ( isset( $raw['activity'] ) && is_array( $raw['activity'] ) ) {
			$raw['activity']['stand_length'] = Editions::stand_value( $edition, $raw['activity']['stand_length'] ?? null );
		}
		$clean = array();
		foreach ( array( 'identity' => Fields::identity(), 'activity' => Fields::activity() ) as $group => $schema ) {
			if ( ! isset( $raw[ $group ] ) || ! is_array( $raw[ $group ] ) ) { return new \WP_Error( 'fields', 'Complétez vos coordonnées et votre activité.' ); }
			$clean[ $group ] = Fields::validate( $raw[ $group ], $schema, true );
			if ( is_wp_error( $clean[ $group ] ) ) { return $clean[ $group ]; }
		}
		return $clean;
	}
	/**
	 * Enregistre les réponses et pièces sous verrou ; l'appelant HTTP a déjà vérifié session et nonce.
	 * Les coordonnées publiques ne remplacent jamais un profil existant. Le reçu rend un nouvel envoi
	 * du même brouillon sans effet après une réponse réseau perdue ; les emails partent hors verrou.
	 */
	public static function submit( int $edition, array $raw, array $uploads, bool $consent = false, string $draft = '', string $revision = '', bool $map_consent = false ): int|\WP_Error {
		if ( $draft && ( $receipt = UploadDrafts::receipt( $draft ) ) ) { return $receipt; }
		if ( ! $consent ) { return new \WP_Error( 'consent', 'Pour envoyer votre candidature, veuillez cocher l’autorisation de présentation publique. La localisation sur la carte reste facultative.' ); }
		if ( ! Editions::is_open( $edition ) ) { return new \WP_Error( 'closed', 'Les candidatures ne sont pas ouvertes pour cette édition.' ); }
		$clean = self::validate_answers( $raw, $edition );
		if ( is_wp_error( $clean ) ) { return $clean; }
		if ( ! SubmissionLock::acquire() ) { return new \WP_Error( 'busy', 'Un autre enregistrement est en cours. Réessayez dans quelques instants.' ); }
		$files = array(); $created = array(); $ok = false;
		try {
			// Une autre requête a pu enregistrer ce brouillon pendant l'attente du verrou.
			if ( $draft && ( $receipt = UploadDrafts::receipt( $draft ) ) ) { $ok = true; return $receipt; }
			if ( ! Editions::is_open( $edition ) ) { return new \WP_Error( 'closed', 'La période de candidature vient de se terminer.' ); }
			$email = strtolower( $clean['identity']['email'] );
			if ( self::duplicate( $edition, $email ) ) { return new \WP_Error( 'duplicate', 'Une candidature utilise déjà cette adresse email pour cette édition. Pour une correction, contactez l’organisateur.' ); }
			$potier = self::find_potier( $clean['identity'] );
			$files = $draft ? UploadDrafts::files( $draft, $revision ) : PrivateFiles::store( $uploads );
			if ( is_wp_error( $files ) ) { $error = $files; $files = array(); return $error; }
			// Recontrôle après le traitement des images : un formulaire ancien ne contourne pas la fermeture.
			if ( ! Editions::is_open( $edition ) ) { return new \WP_Error( 'closed', 'La période de candidature vient de se terminer.' ); }
			$title = $clean['identity']['last_name'] . ' ' . $clean['identity']['first_name'];
			if ( ! $potier ) {
				$potier = wp_insert_post( wp_slash( array( 'post_type' => 'marcpo_potier', 'post_status' => 'publish', 'post_title' => $title, 'post_author' => 0 ) ), true );
				if ( is_wp_error( $potier ) ) { return new \WP_Error( 'save', 'Enregistrement impossible. Réessayez plus tard.' ); }
				$created[] = $potier;
				if ( ! update_post_meta( $potier, Records::META, wp_slash( array( 'identity' => Records::minimal_identity( $clean['identity'] ) ) ) ) ) { throw new \RuntimeException( 'profile' ); }
			}
			$app = wp_insert_post( wp_slash( array( 'post_type' => 'marcpo_candidature', 'post_status' => 'publish', 'post_title' => $title . ' — ' . get_the_title( $edition ), 'post_author' => 0 ) ), true );
			if ( is_wp_error( $app ) ) { throw new \RuntimeException( 'application' ); }
			$created[] = $app;
			$data = $clean + array( 'potier_id' => (int) $potier, 'edition_id' => $edition, 'decision' => 'pending', 'internal' => array( 'notes' => '', 'social_date' => '' ), 'files' => $files, 'submitted_at' => gmdate( 'c' ), 'publication_consent' => $consent, 'map_consent' => $map_consent, 'map_consent_at' => $map_consent ? gmdate( 'c' ) : '', 'source' => 'public', 'email_verified' => false );
			if ( ! update_post_meta( $app, Records::META, wp_slash( $data ) ) || ! update_post_meta( $app, '_marcpo_potier_id', $potier ) || ! update_post_meta( $app, '_marcpo_edition_id', $edition ) || ! update_post_meta( $app, '_marcpo_decision', 'pending' ) ) { throw new \RuntimeException( 'metadata' ); }
			if ( $draft && ! update_post_meta( $app, '_marcpo_upload_key', $draft ) ) { throw new \RuntimeException( 'receipt' ); }
			// Les réponses et références sont durables : ne plus les annuler si la finalisation échoue.
			$ok = true;
			MediaLibrary::finalize( $app );
			update_post_meta( $app, '_marcpo_media_migrated', 1 );
			if ( $draft ) { UploadDrafts::finish( $draft ); }
			return $app;
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'save', 'L’enregistrement n’a pas abouti. Réessayez plus tard ; les pièces envoyées séparément restent disponibles pendant leur durée de conservation.' );
		} finally {
			// En cas d'échec, les pièces du brouillon restent disponibles pour une nouvelle tentative.
			if ( ! $ok ) { foreach ( array_reverse( $created ) as $id ) { wp_delete_post( $id, true ); } if ( ! $draft ) { PrivateFiles::remove( $files ); } }
			SubmissionLock::release();
			if ( $ok && ! empty( $created ) && isset( $app ) && is_int( $app ) ) {
				try { Notifications::send( $app ); } catch ( \Throwable $error ) { update_post_meta( $app, '_marcpo_mail_error', 'failed' ); }
			}
		}
	}
	/** Une identité différente avec le même email obtient sa propre fiche. */
	public static function find_potier( array $submitted ): int {
		$email = strtolower( trim( $submitted['email'] ?? '' ) );
		if ( '' === $email ) { return 0; }
		$ids = get_posts( array( 'post_type' => 'marcpo_potier', 'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future' ), 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );
		update_meta_cache( 'post', $ids );
		foreach ( $ids as $id ) {
			$identity = Records::data( $id )['identity'] ?? array();
			if ( strtolower( trim( $identity['email'] ?? '' ) ) === $email && self::name_key( $identity ) === self::name_key( $submitted ) ) { return (int) $id; }
		}
		return 0;
	}
	private static function name_key( array $identity ): string {
		return strtolower( remove_accents( trim( $identity['last_name'] ?? '' ) . '|' . trim( $identity['first_name'] ?? '' ) ) );
	}
	public static function duplicate( int $edition, string $email, int $exclude = 0 ): bool {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Complete edition-scoped duplicate check, including trash; batch metadata loading below avoids N+1 reads.
		$ids = get_posts( array( 'post_type' => 'marcpo_candidature', 'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future', 'trash' ), 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_marcpo_edition_id', 'meta_value' => $edition ) );
		update_meta_cache( 'post', $ids );
		foreach ( $ids as $id ) {
			if ( $id !== $exclude && strtolower( Records::data( $id )['identity']['email'] ?? '' ) === strtolower( $email ) ) { return true; }
		}
		return false;
	}
	/** Le bloc désigne directement l’édition par son identifiant. */
	public static function render( int $edition ): string {
		if ( 'marcpo_edition' !== get_post_type( $edition ) || 'publish' !== get_post_status( $edition ) ) { return '<p>Cette édition est indisponible. Contactez l’organisateur.</p>'; }
		ob_start();
		echo '<section id="marcpo-inscription" class="marcpo-public"><p class="marcpo-eyebrow">Marché de potiers · Inscription</p><h2>Votre candidature — ' . esc_html( Blocks::edition_label( $edition ) ) . '</h2>';
		echo wp_kses_post( Editions::introduction( $edition ) );
		if ( ( null !== Request::query( 'marcpo_sent' ) ) && self::session() && (int) get_transient( 'marcpo_receipt_' . hash( 'sha256', self::session() ) ) === $edition ) {
			echo '<div class="marcpo-success" data-marcpo-edition="' . esc_attr( $edition ) . '" role="status">' . nl2br( esc_html( Editions::thank_you( $edition ) ) ) . '</div></section>';
			return ob_get_clean();
		}
		if ( self::$error ) { echo '<div class="marcpo-error" role="alert">' . esc_html( self::$error->get_error_message() ) . '<p>Vos réponses restent ci-dessous. Sélectionnez à nouveau les fichiers avant de renvoyer.</p></div>'; }
		if ( ! Editions::is_open( $edition ) ) {
			$settings = Editions::settings( $edition );
			$opens = Editions::parse_date( $settings['opens'] ); $closes = Editions::parse_date( $settings['closes'] );
			echo '<p>Les candidatures sont actuellement fermées.</p>';
			if ( $opens && $closes ) { echo '<p>Période prévue : du ' . esc_html( wp_date( 'd/m/Y à H:i', $opens->getTimestamp() ) ) . ' au ' . esc_html( wp_date( 'd/m/Y à H:i', $closes->getTimestamp() ) ) . ' (' . esc_html( wp_timezone_string() ) . ').</p>'; }
			echo '</section>'; return ob_get_clean();
		}
		$issued = (string) time(); $random = bin2hex( random_bytes( 16 ) );
		echo '<p class="marcpo-form-intro">Présentez votre atelier, vos créations et votre savoir-faire.</p><p class="marcpo-required-note">Les champs marqués * sont obligatoires.</p><form method="post" enctype="multipart/form-data" action="' . esc_url( get_permalink() . '#marcpo-inscription' ) . '">';
		foreach ( array( 'marcpo_edition' => $edition, 'marcpo_issued' => $issued, 'marcpo_random' => $random, 'marcpo_signature' => self::signature( $edition, $issued, $random ), 'marcpo_nonce' => wp_create_nonce( 'marcpo_public_' . $edition ) ) as $key => $value ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">'; }
		echo '<div class="marcpo-honey" aria-hidden="true"><label>Ne pas remplir <input name="marcpo_fax" tabindex="-1" autocomplete="off"></label></div>';
		self::render_group( Fields::identity(), 'identity', 'Vos coordonnées' );
		self::render_group( Fields::activity(), 'activity', 'Votre activité', $edition );
		echo '<fieldset class="marcpo-section marcpo-files-section"><legend><span class="marcpo-section-number" aria-hidden="true">03</span> Photos et justificatifs</legend><p>' . esc_html( PrivateFiles::size_label() ) . ' maximum par fichier. Photos : JPEG, PNG ou WebP. Justificatifs : PDF ou image JPEG, PNG, WebP. Vos justificatifs de statut et d’assurance sont destinés à l’équipe organisatrice et ne sont pas affichés dans la présentation publique. Vos photos de créations pourront être utilisées pour présenter votre travail si votre candidature est sélectionnée.</p><div class="marcpo-field-grid">';
		foreach ( PrivateFiles::slots() as $slot => $label ) {
			$pdf = in_array( $slot, array( 'status', 'insurance' ), true );
			echo '<div class="marcpo-field marcpo-upload-card"><label for="marcpo-file-' . esc_attr( $slot ) . '">' . esc_html( $label ) . ' *</label><input id="marcpo-file-' . esc_attr( $slot ) . '" type="file"' . ( $pdf ? ' aria-describedby="marcpo-file-' . esc_attr( $slot ) . '-help"' : '' ) . ' data-max-size="' . esc_attr( (string) PrivateFiles::max_size() ) . '" data-max-label="' . esc_attr( PrivateFiles::size_label() ) . '" name="' . esc_attr( $slot ) . '" accept="' . ( $pdf ? '.pdf,.jpg,.jpeg,.png,.webp' : '.jpg,.jpeg,.png,.webp' ) . '" required>';
			if ( 'status' === $slot ) { self::status_help( 'marcpo-file-status-help' ); }
			if ( 'insurance' === $slot ) { echo '<small id="marcpo-file-insurance-help">Joignez une attestation d’assurance responsabilité civile professionnelle valide au jour de l’envoi de votre dossier et comportant la mention « Marchés ou foires en extérieur ».</small>'; }
			echo '</div>';
		}
		echo '</div></fieldset><div class="marcpo-consent"><p><label><input type="checkbox" name="marcpo_photo_consent" value="1" required ' . checked( self::$consent, true, false ) . '> J’autorise l’affichage de ma présentation, de mon nom, de ma ville, de mes liens et de mes photos de créations si je suis sélectionné(e). (Obligatoire)</label></p>';
		echo '<p><label><input type="checkbox" name="marcpo_map_consent" value="1" aria-describedby="marcpo-map-consent-help" ' . checked( self::$map_consent, true, false ) . '> J’autorise la transmission de mon adresse à l’IGN pour la localiser et son affichage sur la carte publique si je suis sélectionné(e). (Facultatif)</label></p>';
		echo '<p id="marcpo-map-consent-help">Vous pouvez candidater et apparaître dans la galerie sans autoriser la carte. Si vous acceptez et que l’organisateur active la cartographie, votre rue, votre code postal et votre ville seront transmis à l’IGN ; votre adresse et sa position pourront être affichées sur une carte OpenStreetMap. Votre téléphone et votre email ne sont pas transmis à ces services. Pour retirer cet accord, contactez l’organisateur.</p>';
		echo '<p><a href="https://cartes.gouv.fr/donnees-personnelles/" target="_blank" rel="noopener noreferrer">Données personnelles IGN</a> · <a href="https://osmfoundation.org/wiki/Privacy_Policy" target="_blank" rel="noopener noreferrer">Confidentialité OpenStreetMap</a></p><p>Votre email et votre téléphone servent au suivi de votre candidature par l’équipe organisatrice et ne sont pas affichés dans la présentation publique.</p></div>';
		$privacy = get_privacy_policy_url();
		if ( $privacy ) { echo '<p><a href="' . esc_url( $privacy ) . '">Politique de confidentialité</a></p>'; }
		echo '<button type="submit">Envoyer ma candidature</button></form></section>';
		return ob_get_clean();
	}
	private static function render_group( array $schema, string $group, string $title, int $edition = 0 ): void {
		echo '<fieldset class="marcpo-section"><legend><span class="marcpo-section-number" aria-hidden="true">' . ( 'identity' === $group ? '01' : '02' ) . '</span> ' . esc_html( $title ) . '</legend><div class="marcpo-field-grid">';
		$data = self::$values[ $group ] ?? array(); if ( ! is_array( $data ) ) { $data = array(); }
		foreach ( $schema as $key => $field ) {
			$value = $data[ $key ] ?? ( 'stand_length' === $key ? '5' : '' );
			$locked = 'stand_length' === $key && $edition && ! Editions::settings( $edition )['stand_editable'];
			if ( 'stand_length' === $key ) { $field[0] = $locked ? 'Mètres linéaires' : sprintf( 'Mètres linéaires souhaités (par défaut : %s m)', str_replace( '.', ',', $edition ? Editions::stand_value( $edition ) : '5' ) ); }
			if ( 'stand_length' === $key && $edition ) { $value = Editions::stand_value( $edition, $data[ $key ] ?? null ); }
			$value = 'multiple' === $field[1] ? ( is_array( $value ) ? array_filter( $value, 'is_string' ) : array() ) : ( is_string( $value ) ? $value : '' );
			if ( 'address' === $key ) { $field[1] = 'text'; }
			if ( 'instagram' === $key ) { $field[0] = 'Instagram — nom de compte'; $field[1] = 'text'; }
			$id = 'marcpo-public-' . $key; $name = 'marcpo_record[' . $group . '][' . $key . ']';
			echo '<div class="marcpo-field' . ( 'activity' === $group || in_array( $field[1], array( 'textarea', 'multiple' ), true ) || in_array( $key, array( 'company', 'address' ), true ) || ( isset( $field[4] ) && 'association_names' !== $key ) ? ' marcpo-field-wide' : '' ) . '"' . ( isset( $field[4] ) ? ' data-parent="' . esc_attr( $field[4][0] ) . '" data-choice="' . esc_attr( $field[4][1] ) . '"' : '' ) . '>';
			if ( 'multiple' === $field[1] ) {
				echo '<fieldset data-multiple="' . esc_attr( $key ) . '"><legend>' . esc_html( $field[0] ) . ' *</legend>';
				foreach ( $field[3] as $option => $label ) { echo '<label class="marcpo-choice"><input type="checkbox" name="' . esc_attr( $name . '[]' ) . '" value="' . esc_attr( $option ) . '" ' . checked( in_array( $option, $value, true ), true, false ) . '> ' . esc_html( $label ) . '</label>'; }
				echo '</fieldset>';
			} else {
				echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $field[0] . ( $field[2] || in_array( $key, array( 'status_other', 'association_names' ), true ) ? ' *' : '' ) ) . '</label>';
				if ( 'address' === $key ) { echo '<small id="marcpo-address-help">Cette adresse sert au traitement de votre candidature. Son affichage sur la carte nécessite votre accord facultatif en fin de formulaire.</small>'; }
				if ( 'presentation' === $key ) { echo '<small id="marcpo-presentation-help">Décrivez votre parcours, votre démarche et votre travail (300 mots maximum).</small>'; }
				if ( 'association_details' === $key ) { echo '<small id="marcpo-association-help">(Ex: organisation de marché, implication active dans boutique, CA d’asso, …)</small>'; }
				if ( $locked ) { echo '<small id="marcpo-stand-fixed">Taille fixée par l’organisateur.</small>'; }
				if ( 'textarea' === $field[1] ) { echo '<textarea rows="4" maxlength="10000"'; self::render_attributes( $id, $name, $key, (bool) $field[2], $locked ); echo '>' . esc_textarea( $value ) . '</textarea>'; }
				elseif ( 'select' === $field[1] ) {
					echo '<select'; self::render_attributes( $id, $name, $key, (bool) $field[2], $locked ); echo '><option value="">Choisir…</option>';
					foreach ( $field[3] as $option => $label ) { echo '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( $label ) . '</option>'; }
					echo '</select>';
					if ( 'professional_status' === $key ) { self::status_help( 'marcpo-professional-status-help' ); }
				} else { echo '<input type="' . esc_attr( $field[1] ) . '"'; self::render_attributes( $id, $name, $key, (bool) $field[2], $locked ); echo ' value="' . esc_attr( $value ) . '"' . ( 'number' === $field[1] ? ' min="0.01" max="1000" step="0.01"' : ' maxlength="1000"' ) . '>'; }
			}
			if ( 'presentation' === $key ) { echo '<small id="marcpo-presentation-count" role="status" hidden></small>'; }
			if ( isset( $field[4] ) ) { echo '<small>À préciser si la réponse correspondante est « ' . esc_html( $schema[ $field[4][0] ][3][ $field[4][1] ] ) . ' ».</small>'; }
			echo '</div>';
		}
		echo '</div></fieldset>';
	}
	private static function render_attributes( string $id, string $name, string $key, bool $required, bool $locked ): void {
		echo ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . ( $required ? ' required' : '' );
		$placeholders = array( 'website' => 'https://www.monatelier.fr', 'facebook' => 'https://www.facebook.com/monatelier', 'instagram' => '@monatelier' );
		if ( isset( $placeholders[ $key ] ) ) { echo ' placeholder="' . esc_attr( $placeholders[ $key ] ) . '"'; }
		if ( 'instagram' === $key ) { echo ' autocapitalize="none" spellcheck="false"'; }
		$help = array( 'professional_status' => 'marcpo-professional-status-help', 'address' => 'marcpo-address-help', 'presentation' => 'marcpo-presentation-help marcpo-presentation-count', 'association_details' => 'marcpo-association-help' );
		if ( $locked ) { echo ' readonly aria-describedby="marcpo-stand-fixed"'; }
		elseif ( isset( $help[ $key ] ) ) { echo ' aria-describedby="' . esc_attr( $help[ $key ] ) . '"'; }
	}
	private static function status_help( string $id ): void {
		echo '<div id="' . esc_attr( $id ) . '" aria-live="polite">';
		echo '<p data-status-help="artisan micro-entreprise"><small><strong>Artisan ou micro-entreprise :</strong> joignez un extrait DATA INPI disponible sur <a href="https://data.inpi.fr/" target="_blank" rel="noopener noreferrer">data.inpi.fr</a>.</small></p>';
		echo '<p data-status-help="artiste"><small><strong>Artiste, Maison des artistes :</strong> joignez votre dernière attestation d’affiliation ou d’assujettissement comportant vos noms, prénoms et numéro d’ordre.</small></p>';
		echo '<p data-status-help="autre"><small><strong>Autres statuts ou installation à l’étranger :</strong> joignez les justificatifs ou attestations disponibles prouvant que vous exercez une activité professionnelle.</small></p>';
		echo '</div>';
	}

}
