<?php
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class Notifications {
	public static function render_status( int $app ): void {
		if ( ! current_user_can( 'mp_manage_applications' ) ) { return; }
		$labels = array( 'accepted' => 'confié au service d’envoi', 'failed' => 'échec de l’envoi', 'missing_recipient' => 'adresse destinataire manquante ou invalide' );
		$titles = array( 'candidate' => 'Confirmation au candidat', 'organizer' => 'Notification à l’organisateur' );
		foreach ( Jury::settings( (int) ( Records::data( $app )['edition_id'] ?? 0 ) )['members'] as $uid => $member ) { $titles[ 'jury_' . $uid ] = 'Notification à ' . $member['name']; }
		foreach ( $titles as $kind => $title ) {
			$status = get_post_meta( $app, '_mp_mail_' . $kind, true );
			if ( $status ) { echo '<p><strong>' . esc_html( $title ) . ' :</strong> ' . esc_html( $labels[ $status ] ?? $status ) . '.</p>'; }
		}
		if ( get_post_meta( $app, '_mp_mail_error', true ) ) { echo '<p>Une erreur est survenue lors de la préparation des emails. La candidature est bien enregistrée.</p>'; }
	}
	public static function summary( array $data ): string {
		$lines = array();
		foreach ( array( 'identity' => Fields::identity(), 'activity' => Fields::activity() ) as $group => $schema ) {
			foreach ( $schema as $key => $field ) {
				$value = $data[ $group ][ $key ] ?? '';
				if ( is_array( $value ) ) { $value = implode( ', ', array_map( static fn( $item ) => $field[3][ $item ] ?? $item, $value ) ); }
				elseif ( 'select' === $field[1] ) { $value = $field[3][ $value ] ?? $value; }
				$lines[] = $field[0] . ' : ' . ( '' === $value ? 'Non renseigné' : $value );
			}
		}
		$lines[] = 'Autorisation de présentation publique et de localisation : ' . ( ! empty( $data['publication_consent'] ) ? 'Oui' : 'Non' );
		$lines[] = "\nPièces reçues :";
		foreach ( PrivateFiles::slots() as $slot => $label ) {
			$file = $data['files'][ $slot ] ?? null;
			$name = is_array( $file ) ? sanitize_text_field( $file['original_name'] ?? $file['label'] ?? '' ) : '';
			$lines[] = $label . ' : ' . ( null === $file ? 'Absente' : ( '' !== $name ? $name . ' — bien reçu' : 'Reçue' ) );
		}
		return implode( "\n", $lines );
	}
	/** Une tentative par destinataire : un nouvel appel ne duplique pas les emails. */
	public static function send( int $app ): void {
		$data = Records::data( $app );
		$edition = Editions::settings( (int) $data['edition_id'] );
		$title = sanitize_text_field( wp_specialchars_decode( get_the_title( $data['edition_id'] ), ENT_QUOTES ) );
		$organizer = $edition['organizer_email'] ?: get_option( 'admin_email' );
		$summary = self::summary( $data );
		$recipients = array( 'candidate' => $data['identity']['email'], 'organizer' => $organizer );
		$seen = array( strtolower( $organizer ) => true );
		foreach ( Jury::members( (int) $data['edition_id'] ) as $uid => $member ) {
			$email = get_userdata( $uid )->user_email;
			if ( ! isset( $seen[ strtolower( $email ) ] ) ) { $recipients[ 'jury_' . $uid ] = $email; $seen[ strtolower( $email ) ] = true; }
		}
		foreach ( $recipients as $kind => $email ) {
			if ( ! $email || ! is_email( $email ) ) { update_post_meta( $app, '_mp_mail_' . $kind, 'missing_recipient' ); continue; }
			// Réservation sous verrou, puis envoi hors verrou pour ne pas bloquer les votes.
			if ( ! SubmissionLock::acquire() ) { update_post_meta( $app, '_mp_mail_error', 'busy' ); continue; }
			try { $claimed = add_post_meta( $app, '_mp_mail_attempt_' . $kind, gmdate( 'c' ), true ); }
			finally { SubmissionLock::release(); }
			if ( ! $claimed ) { continue; }
			$candidate = 'candidate' === $kind;
			$subject = ( $candidate ? 'Confirmation de candidature — ' : 'Nouvelle candidature — ' ) . $title;
			$body = $candidate ? Editions::thank_you( (int) $data['edition_id'] ) . "\n\nVotre candidature est enregistrée. Cette confirmation ne vaut pas sélection.\n\n" : "Une nouvelle candidature a été enregistrée.\n\n";
			$body .= 'Édition : ' . $title . "\nDossier n° " . $app . "\n\n" . $summary;
			if ( ! $candidate ) { $body .= "\n\nExaminer le dossier et voter si les votes sont ouverts (connexion organisateur requise) :\n" . Records::view_url( $app, array( 'mp_from' => 'gestion', 'mp_edition' => (int) $data['edition_id'] ) ); }
			$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
			$reply = $candidate ? $organizer : $data['identity']['email'];
			if ( is_email( $reply ) ) { $headers[] = 'Reply-To: ' . $reply; }
			try { $sent = wp_mail( $email, $subject, $body, $headers ); }
			catch ( \Throwable $error ) { $sent = false; }
			update_post_meta( $app, '_mp_mail_' . $kind, $sent ? 'accepted' : 'failed' );
		}
	}
}
