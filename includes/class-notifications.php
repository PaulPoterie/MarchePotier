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
			$view_url = '';
			if ( ! $candidate ) {
				$view_url = Records::view_url( $app, array( 'marcpo_from' => 'gestion', 'marcpo_edition' => (int) $data['edition_id'] ) );
				$body .= "\n\nExaminer le dossier (connexion à votre compte requise) :\n" . $view_url;
			}
			$reply = $candidate ? $organizer : $data['identity']['email'];
			// Le candidat reçoit un texte simple ; les organisateurs et votants reçoivent une
			// version HTML avec miniatures, doublée du texte ci-dessus comme alternative.
			if ( $candidate ) {
				$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
				if ( is_email( $reply ) ) { $headers[] = 'Reply-To: ' . $reply; }
				try { $sent = wp_mail( $email, $subject, $body, $headers ); }
				catch ( \Throwable $error ) { $sent = false; }
			} else {
				$headers = array( 'Content-Type: text/html; charset=UTF-8' );
				if ( is_email( $reply ) ) { $headers[] = 'Reply-To: ' . $reply; }
				$message = self::html_body( $title, $app, $summary, $data, $view_url );
				$alternative = static function ( $phpmailer ) use ( $body ): void { $phpmailer->AltBody = $body; };
				add_action( 'phpmailer_init', $alternative );
				try { $sent = wp_mail( $email, $subject, $message, $headers ); }
				catch ( \Throwable $error ) { $sent = false; }
				finally { remove_action( 'phpmailer_init', $alternative ); }
			}
			update_post_meta( $app, '_marcpo_mail_' . $kind, $sent ? 'accepted' : 'failed' );
		}
	}
	/** Version HTML réservée aux organisateurs et votants ; seules les photos publiques y figurent. */
	private static function html_body( string $title, int $app, string $summary, array $data, string $view_url ): string {
		$message = '<!doctype html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0;background:#f6f3ef;color:#292524;font-family:Arial,sans-serif;font-size:16px;line-height:1.6">';
		$message .= '<table role="presentation" style="width:100%;border-collapse:collapse"><tr><td style="padding:24px 12px"><table role="presentation" style="width:100%;max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e7e0d8;border-collapse:collapse"><tr><td style="padding:28px">';
		$message .= '<p style="margin:0;color:#76533c;font-size:13px;font-weight:bold">POTERIE NAVARRAISE · CANDIDATURE</p><h1 style="margin:8px 0 6px;font-size:22px;line-height:1.3">' . esc_html( $title ) . '</h1>';
		$message .= '<p style="margin:0;color:#686059">Dossier n° ' . esc_html( (string) $app ) . '</p>';
		$images = self::image_previews( $data );
		if ( '' !== $images ) { $message .= '<h2 style="margin:24px 0 8px;font-size:17px">Photos</h2><table role="presentation" style="border-collapse:collapse;width:100%">' . $images . '</table>'; }
		$message .= '<h2 style="margin:24px 0 8px;font-size:17px">Réponses</h2><p style="margin:0;white-space:pre-line">' . esc_html( $summary ) . '</p>';
		if ( '' !== $view_url ) { $message .= '<p style="margin:28px 0 0"><a href="' . esc_url( $view_url ) . '" style="display:inline-block;background:#76533c;color:#ffffff;padding:12px 20px;border-radius:4px;text-decoration:none;font-weight:bold">Examiner le dossier</a></p><p style="margin:8px 0 0;color:#686059;font-size:14px">La connexion à votre compte est requise.</p>'; }
		return $message . '</td></tr></table></td></tr></table></body></html>';
	}
	/** Miniatures des photos produit et du stand uniquement ; jamais les justificatifs. */
	private static function image_previews( array $data ): string {
		$cells = array();
		foreach ( array( 'product1', 'product2', 'product3', 'stand' ) as $slot ) {
			$file = $data['files'][ $slot ] ?? null;
			if ( ! is_array( $file ) ) { continue; }
			$preview = self::preview_url( $file );
			if ( '' === $preview ) { continue; }
			$full = PrivateFiles::file_url( $file );
			$label = PrivateFiles::slots()[ $slot ];
			$cells[] = '<td style="padding:6px;vertical-align:top;width:50%"><a href="' . esc_url( $full ?: $preview ) . '"><img src="' . esc_url( $preview ) . '" alt="' . esc_attr( $label ) . '" style="display:block;width:100%;max-width:260px;height:auto;border:1px solid #e7e0d8;border-radius:4px"></a><p style="margin:6px 0 0;color:#686059;font-size:13px">' . esc_html( $label ) . '</p></td>';
		}
		if ( ! $cells ) { return ''; }
		$rows = '';
		for ( $index = 0, $count = count( $cells ); $index < $count; $index += 2 ) {
			$rows .= '<tr>' . $cells[ $index ] . ( $cells[ $index + 1 ] ?? '<td style="width:50%"></td>' ) . '</tr>';
		}
		return $rows;
	}
	/** Taille « medium » de WordPress, avec repli sur le fichier complet (URL publique). */
	private static function preview_url( array $file ): string {
		if ( ! empty( $file['attachment_id'] ) ) {
			$url = wp_get_attachment_image_url( (int) $file['attachment_id'], 'medium' );
			if ( is_string( $url ) && '' !== $url ) { return $url; }
		}
		return PrivateFiles::file_url( $file );
	}
}
