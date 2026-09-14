<?php
/** Schéma partagé par les fiches internes et le futur formulaire public. */
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class Fields {
	public static function identity(): array {
		return array(
			'last_name' => array( 'Nom', 'text', true ),
			'first_name' => array( 'Prénom', 'text', true ),
			'company' => array( 'Nom d’atelier', 'text', false ),
			'address' => array( 'Adresse', 'textarea', true ),
			'postcode' => array( 'Code postal', 'text', true ),
			'city' => array( 'Ville', 'text', true ),
			'country' => array( 'Pays', 'text', true ),
			'phone' => array( 'Téléphone', 'tel', true ),
			'email' => array( 'Email', 'email', true ),
			'website' => array( 'Site web', 'url', false ),
			'facebook' => array( 'Facebook', 'url', false ),
			'instagram' => array( 'Instagram', 'url', false ),
		);
	}

	public static function activity(): array {
		return array(
			'presentation' => array( 'Présentation', 'textarea', true ),
			'production' => array( 'Production', 'multiple', true, array( 'utilitaire' => 'Utilitaire', 'jardin' => 'Extérieur / Jardin', 'modelage' => 'Sculpture / Modelage', 'decoratif' => 'Décoratif', 'sculpture' => 'Sculpture', 'bijoux' => 'Bijoux', 'musique' => 'Instrument de musique', 'autre' => 'Autre' ) ),
			'production_other' => array( 'Autre production', 'text', false, array(), array( 'production', 'autre' ) ),
			'technique' => array( 'Technique', 'multiple', true, array( 'cristallisations' => 'Cristallisations', 'faience' => 'Faïence', 'terre-vernissee' => 'Terre Vernissée', 'sigillee' => 'Sigillée', 'gres' => 'Grès', 'plaque' => 'Plaque', 'terres-melees' => 'Terres Mêlées', 'terre-cuite' => 'Terre cuite', 'terre-enfumee' => 'Terre enfumée', 'bois' => 'Cuisson bois', 'porcelaine' => 'Porcelaine', 'raku' => 'Raku', 'recolte' => 'Récolte de matériaux', 'autre' => 'Autre' ) ),
			'technique_other' => array( 'Autre technique', 'text', false, array(), array( 'technique', 'autre' ) ),
			'professional_status' => array( 'Statut professionnel', 'select', true, array( 'artisan' => 'Artisan, chambre des métiers', 'artiste' => 'Artiste maison des artistes', 'liberal' => 'Profession Libérale (hors auto-entrepreneur)', 'auto-entrepreneur' => 'Auto-entrepreneur', 'micro-entreprise' => 'Auto-entrepreneur / Micro-entreprise / entreprise individuelle', 'autre' => 'Autre' ) ),
			'status_other' => array( 'Autre statut professionnel', 'text', false, array(), array( 'professional_status', 'autre' ) ),
			'aaf_member' => array( 'Adhérent Ateliers d’Art de France', 'select', true, array( 'yes' => 'Oui', 'no' => 'Non' ) ),
			'association_member' => array( 'Adhérent association professionnelle', 'select', true, array( 'yes' => 'Oui', 'no' => 'Non' ) ),
			'association_details' => array( 'Détails de l’association', 'textarea', false, array(), array( 'association_member', 'yes' ) ),
			'stand_length' => array( 'Longueur du stand (m)', 'number', false ),
		);
	}

	public static function internal(): array {
		return array(
			'social_date' => array( 'Date de diffusion réseaux sociaux', 'date', false ),
			'notes' => array( 'Notes internes', 'textarea', false ),
		);
	}

	/** Valide les types avant nettoyage. Les brouillons internes peuvent être incomplets. */
	public static function validate( array $raw, array $schema, bool $complete = false ): array|\WP_Error {
		$result = array();
		foreach ( $schema as $key => $field ) {
			$value = $raw[ $key ] ?? ( 'multiple' === $field[1] ? array() : '' );
			$error = new \WP_Error( 'mp_field', sprintf( 'Valeur invalide : %s.', $field[0] ) );
			if ( 'multiple' === $field[1] ) {
				if ( ! is_array( $value ) ) { return $error; }
				foreach ( $value as $choice ) {
					if ( ! is_string( $choice ) || ! isset( $field[3][ $choice ] ) ) { return $error; }
				}
				$result[ $key ] = array_values( array_unique( $value ) );
			} else {
				if ( ! is_string( $value ) ) { return $error; }
				if ( strlen( $value ) > ( 'textarea' === $field[1] ? 40000 : 4000 ) ) { return new \WP_Error( 'mp_length', sprintf( 'Texte trop long : %s.', $field[0] ) ); }
				$value = trim( $value );
				if ( '' !== $value ) {
					if ( 'email' === $field[1] && ! is_email( $value ) ) { return $error; }
					if ( 'url' === $field[1] && ( ! preg_match( '~^https?://~i', $value ) || ! filter_var( $value, FILTER_VALIDATE_URL ) ) ) { return $error; }
					if ( 'select' === $field[1] && ! isset( $field[3][ $value ] ) ) { return $error; }
					if ( 'number' === $field[1] ) {
						$value = str_replace( ',', '.', $value );
						if ( ! preg_match( '/^[0-9]+(?:\.[0-9]{1,2})?$/D', $value ) || (float) $value <= 0 || (float) $value > 1000 ) { return $error; }
					}
					if ( 'date' === $field[1] && ! Editions::parse_date( $value . 'T12:00' ) ) { return $error; }
				}
				$result[ $key ] = 'textarea' === $field[1] ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			}
			if ( $complete && $field[2] && ( '' === $result[ $key ] || array() === $result[ $key ] ) ) {
				return new \WP_Error( 'mp_required', sprintf( 'Champ obligatoire : %s.', $field[0] ) );
			}
		}
		foreach ( $schema as $key => $field ) {
			if ( ! isset( $field[4] ) ) { continue; }
			list( $parent, $expected ) = $field[4];
			$active = in_array( $expected, (array) ( $result[ $parent ] ?? array() ), true );
			if ( ! $active ) { $result[ $key ] = ''; }
			if ( $complete && $active && '' === $result[ $key ] ) {
				return new \WP_Error( 'mp_required', sprintf( 'Précision obligatoire : %s.', $field[0] ) );
			}
		}
		return $result;
	}

	public static function render( array $schema, array $data, string $group ): void {
		echo '<table class="form-table" role="presentation">';
		foreach ( $schema as $key => $field ) {
			$value = $data[ $key ] ?? ( 'multiple' === $field[1] ? array() : ( 'stand_length' === $key ? '5' : '' ) );
			$name = 'mp_record[' . $group . '][' . $key . ']';
			$id = 'mp-' . $group . '-' . $key;
			echo '<tr><th><label for="' . esc_attr( $id ) . '">' . esc_html( $field[0] . ( $field[2] ? ' *' : '' ) ) . '</label></th><td>';
			if ( 'textarea' === $field[1] ) {
				echo '<textarea class="large-text" rows="4" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>';
			} elseif ( 'multiple' === $field[1] ) {
				echo '<fieldset id="' . esc_attr( $id ) . '"><legend class="screen-reader-text">' . esc_html( $field[0] ) . '</legend>';
				foreach ( $field[3] as $option => $label ) {
					echo '<label><input type="checkbox" name="' . esc_attr( $name . '[]' ) . '" value="' . esc_attr( $option ) . '" ' . checked( in_array( $option, (array) $value, true ), true, false ) . '> ' . esc_html( $label ) . '</label><br>';
				}
				echo '</fieldset>';
			} elseif ( 'select' === $field[1] ) {
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"><option value="">—</option>';
				foreach ( $field[3] as $option => $label ) {
					echo '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
			} else {
				echo '<input class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $field[1] ) . '" value="' . esc_attr( $value ) . '"' . ( 'number' === $field[1] ? ' min="0.01" max="1000" step="0.01"' : '' ) . '>';
			}
			if ( isset( $field[4] ) ) {
				echo '<p class="description">' . esc_html( 'À remplir uniquement si la réponse correspondante est « ' . ( $schema[ $field[4][0] ][3][ $field[4][1] ] ?? $field[4][1] ) . ' ».' ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
	}

	public static function summary( array $schema, array $data ): void {
		echo '<table class="widefat striped"><tbody>';
		foreach ( $schema as $key => $field ) {
			$value = $data[ $key ] ?? '';
			if ( in_array( $field[1], array( 'select', 'multiple' ), true ) ) {
				$value = implode( ', ', array_map( static fn( $item ) => $field[3][ $item ] ?? '', (array) $value ) );
			}
			echo '<tr><th scope="row">' . esc_html( $field[0] ) . '</th><td>';
			if ( 'url' === $field[1] && '' !== $value ) {
				echo '<a href="' . esc_url( $value, array( 'http', 'https' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $value ) . '</a>';
			} else {
				echo nl2br( esc_html( '' !== $value ? $value : '—' ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
