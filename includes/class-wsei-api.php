<?php
/**
 * Client for the W System e-invoice API.
 *
 * @package WSEI
 */

defined( 'ABSPATH' ) || exit;

/**
 * API client.
 */
class WSEI_API {

	const BASE = 'https://api.wsystem.dev';

	/**
	 * POST JSON to an API path.
	 *
	 * @param string $path Path such as /einvoice/create.
	 * @param array  $body Request body.
	 * @return array{ok:bool,status:int,data:array,error:string}
	 */
	public static function post( $path, array $body ) {
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
			'User-Agent'   => 'w-system-e-invoice/' . WSEI_VERSION . '; ' . home_url( '/' ),
		);
		$key     = trim( WSEI_Settings::get( 'api_key' ) );
		if ( '' !== $key ) {
			$headers['X-API-Key'] = $key;
		}
		$res = wp_remote_post(
			self::BASE . $path,
			array(
				'timeout' => 45,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'data'   => array(),
				'error'  => $res->get_error_message(),
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $res );
		$data   = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$data   = is_array( $data ) ? $data : array();
		$error  = '';
		if ( $status < 200 || $status >= 300 ) {
			$error = self::error_text( $status, $data );
		}
		return array(
			'ok'     => '' === $error,
			'status' => $status,
			'data'   => $data,
			'error'  => $error,
		);
	}

	/**
	 * Human-readable error for a non-2xx answer.
	 *
	 * @param int   $status HTTP status.
	 * @param array $data   Decoded body.
	 * @return string
	 */
	public static function error_text( $status, array $data ) {
		if ( 402 === $status || 429 === $status ) {
			return __( 'The free daily quota of the W System API is used up. Add an API key in the settings or try again tomorrow.', 'w-system-e-invoice' );
		}
		$detail = isset( $data['detail'] ) ? $data['detail'] : ( isset( $data['error'] ) ? $data['error'] : '' );
		if ( is_array( $detail ) ) {
			if ( isset( $detail['message'] ) ) {
				$detail = $detail['message'];
			} else {
				$detail = wp_json_encode( $detail );
			}
		}
		/* translators: 1: HTTP status code, 2: error detail from the API */
		return sprintf( __( 'W System API error %1$d: %2$s', 'w-system-e-invoice' ), $status, (string) $detail );
	}
}
