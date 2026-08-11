<?php

declare(strict_types=1);

namespace Vlad\SBS\Utils;

final class Http {
	/**
	 * Потоковая загрузка файла на удаленный сервер.
	 * Инкапсулирует cURL, чтобы скрыть его реализацию и избежать загрузки огромных архивов бэкапов в ОЗУ
	 * (нативный WP_Http требует передачи тела файла строкой, что вызывает Fatal Error Memory Exhausted).
	 */
	public static function stream_put( string $url, string $file_path, array $headers = [] ): array {
		if ( ! file_exists( $file_path ) ) {
			return [ 'code' => 404, 'body' => 'File not found' ];
		}

		$file_handle = fopen( $file_path, 'r' );
		$file_size   = filesize( $file_path );

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_PUT, true );
		curl_setopt( $ch, CURLOPT_INFILE, $file_handle );
		curl_setopt( $ch, CURLOPT_INFILESIZE, $file_size );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

		$curl_headers = [];
		foreach ( $headers as $k => $v ) {
			$curl_headers[] = "{$k}: {$v}";
		}
		if ( ! empty( $curl_headers ) ) {
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $curl_headers );
		}

		$response  = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		curl_close( $ch );
		fclose( $file_handle );

		return [
			'code' => $http_code,
			'body' => (string) $response
		];
	}
}