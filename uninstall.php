<?php
/**
 * Désinstallation : supprime le registre des opérations, le verrou et les sauvegardes
 * (métadonnées _effi_bbr_backup_*). Après suppression de l'extension, les opérations
 * ne peuvent donc plus être restaurées.
 *
 * @package Effi_Bulk_Block_Remover
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Nettoie le site courant.
 *
 * @return void
 */
function effi_bbr_uninstall_site() {
	global $wpdb;

	// Une clé par opération : delete_post_meta_by_key() vide aussi le cache des métadonnées.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_effi_bbr_backup_' ) . '%' ) );
	foreach ( (array) $keys as $key ) {
		delete_post_meta_by_key( $key );
	}

	delete_option( 'effi_bbr_operations' );
	delete_option( 'effi_bbr_lock' );
}

if ( is_multisite() ) {
	$effi_bbr_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $effi_bbr_site_ids as $effi_bbr_site_id ) {
		switch_to_blog( $effi_bbr_site_id );
		effi_bbr_uninstall_site();
		restore_current_blog();
	}
} else {
	effi_bbr_uninstall_site();
}
