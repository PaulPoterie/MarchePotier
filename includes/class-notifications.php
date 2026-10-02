<?php
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class Notifications {
	/** Résumé en texte brut des réponses validées ; les emails ne contiennent pas les pièces en annexe. */
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
		$lines[] = 'Autorisation de présentation publique : ' . ( ! empty( $data['publication_consent'] ) ? 'Oui' : 'Non' );
		$lines[] = 'Autorisation de localisation sur la carte : ' . ( true === ( $data['map_consent'] ?? false ) ? 'Oui' : 'Non' );
		$lines[] = "\nPièces reçues :";
		foreach ( PrivateFiles::slots() as $slot => $label ) {
			$file = $data['files'][ $slot ] ?? null;
			$name = is_array( $file ) ? sanitize_text_field( $file['original_name'] ?? $file['label'] ?? '' ) : '';
			$lines[] = $label . ' : ' . ( null === $file ? 'Absente' : ( '' !== $name ? $name . ' — bien reçu' : 'Reçue' ) );
		}
		return implode( "\n", $lines );
	}
	/**
	 * Une tentative par destinataire, réservée avant wp_mail pour éviter les doubles envois.
	 * Un échec n’est pas relancé automatiquement ; « accepted » ne confirme pas la livraison.
	 */
	public static function send( int $app ): void {
		$data = Records::data( $app );
		$title = sanitize_text_field( wp_specialchars_decode( get_the_title( $data['edition_id'] ), ENT_QUOTES ) );
		$organizer = Jury::contact_email( (int) $data['edition_id'] );
		$summary = self::summary( $data );
		$administrator = Jury::administrator( (int) $data['edition_id'] );
		$recipients = array( 'candidate' => $data['identity']['email'] );
		$seen = array();
		$members = Jury::members( (int) $data['edition_id'] );
		foreach ( $members as $uid => $member ) {
			if ( ! Jury::multiple( (int) $data['edition_id'] ) && $uid !== $administrator ) { continue; }
			$email = get_userdata( $uid )->user_email;
			if ( ! isset( $seen[ strtolower( $email ) ] ) ) { $recipients[ 'jury_' . $uid ] = $email; $seen[ strtolower( $email ) ] = true; }
		}
		foreach ( $recipients as $kind => $email ) {
			if ( ! $email || ! is_email( $email ) ) { update_post_meta( $app, '_marcpo_mail_' . $kind, 'missing_recipient' ); continue; }
			// Réservation sous verrou, puis envoi hors verrou pour ne pas bloquer les votes.
			if ( ! SubmissionLock::acquire() ) { update_post_meta( $app, '_marcpo_mail_error', 'busy' ); continue; }
			try { $claimed = add_post_meta( $app, '_marcpo_mail_attempt_' . $kind, gmdate( 'c' ), true ); }
			finally { SubmissionLock::release(); }
			if ( ! $claimed ) { continue; }
			$candidate = 'candidate' === $kind;
			$subject = ( $candidate ? 'Confirmation de candidature — ' : 'Nouvelle candidature — ' ) . $title;
			$body = $candidate ? Editions::thank_you( (int) $data['edition_id'] ) . "\n\nVotre candidature est enregistrée. Cette confirmation ne vaut pas sélection.\n\n" : "Une nouvelle candidature a été enregistrée.\n\n";
			$body .= 'Édition : ' . $title . "\nDossier n° " . $app . "\n\n" . $summary;
			if ( ! $candidate ) { $body .= "\n\nExaminer le dossier (connexion à votre compte requise) :\n" . Records::view_url( $app, array( 'marcpo_from' => 'gestion', 'marcpo_edition' => (int) $data['edition_id'] ) ); }
			$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
			$reply = $candidate ? $organizer : $data['identity']['email'];
			if ( is_email( $reply ) ) { $headers[] = 'Reply-To: ' . $reply; }
			try { $sent = wp_mail( $email, $subject, $body, $headers ); }
			catch ( \Throwable $error ) { $sent = false; }
			update_post_meta( $app, '_marcpo_mail_' . $kind, $sent ? 'accepted' : 'failed' );
		}
	}
}
