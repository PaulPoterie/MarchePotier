<?php
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class IdentityMigration {
	public static function run(): void {
		if ( '1' === get_option( 'mp_identity_model_version' ) || ! current_user_can( 'manage_options' ) || ! SubmissionLock::acquire() ) { return; }
		try {
			$statuses = array( 'publish', 'private', 'draft', 'pending', 'future', 'trash' );
			foreach ( get_posts( array( 'post_type' => 'mp_candidature', 'post_status' => $statuses, 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
				$old = Records::data( $id );
				if ( empty( $old['potier_id'] ) ) { continue; }
				$data = $old;
				$profile = get_post_meta( $old['potier_id'], '_mp_before_identity_v1', true ) ?: Records::data( $old['potier_id'] );
				$data['identity'] = ( $data['identity'] ?? array() ) + ( $profile['identity'] ?? array() );
				$identity = Fields::validate( $data['identity'], Records::potier_schema(), true );
				if ( ! is_wp_error( $identity ) ) {
					$potier = PublicForm::find_potier( $identity );
					if ( ! $potier ) { $potier = Records::create_potier( $identity ); }
					if ( is_wp_error( $potier ) ) { return; }
					$data['potier_id'] = $potier;
				}
				if ( $data !== $old ) {
					if ( ! metadata_exists( 'post', $id, '_mp_before_identity_v1' ) && ! add_post_meta( $id, '_mp_before_identity_v1', wp_slash( $old ), true ) ) { return; }
					update_post_meta( $id, Records::META, wp_slash( $data ) );
					if ( Records::data( $id ) !== $data ) { return; }
				}
				update_post_meta( $id, '_mp_potier_id', $data['potier_id'] );
				if ( (int) get_post_meta( $id, '_mp_potier_id', true ) !== $data['potier_id'] ) { return; }
			}
			foreach ( get_posts( array( 'post_type' => 'mp_potier', 'post_status' => $statuses, 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
				$old = Records::data( $id );
				$data = array( 'identity' => Records::minimal_identity( $old['identity'] ?? array() ) );
				if ( $data === $old ) { continue; }
				if ( ! metadata_exists( 'post', $id, '_mp_before_identity_v1' ) && ! add_post_meta( $id, '_mp_before_identity_v1', wp_slash( $old ), true ) ) { return; }
				update_post_meta( $id, Records::META, wp_slash( $data ) );
				if ( Records::data( $id ) !== $data ) { return; }
			}
			update_option( 'mp_identity_model_version', '1', false );
		} finally { SubmissionLock::release(); }
	}
}
