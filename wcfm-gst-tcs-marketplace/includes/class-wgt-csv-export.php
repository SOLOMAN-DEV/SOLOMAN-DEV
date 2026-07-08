<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WGT_CSV_Export {

	/**
	 * Streams a CSV file to the browser and exits. Call only before any output
	 * has been sent (e.g. from an admin_post handler).
	 *
	 * @param string  $filename Suggested download filename, without extension.
	 * @param array   $headers  Column headings.
	 * @param array[] $rows     Rows of scalar values, same order as $headers.
	 */
	public static function stream( $filename, $headers, $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $filename ) . '.csv' );

		$fh = fopen( 'php://output', 'w' );
		fputs( $fh, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel opens Indian-locale numbers/names cleanly.
		fputcsv( $fh, $headers );

		foreach ( $rows as $row ) {
			fputcsv( $fh, $row );
		}

		fclose( $fh );
		exit;
	}
}
