<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Backup;

use Vlad\SBS\Core\SettingsManager;
use Vlad\SBS\Core\Logger;
use Vlad\SBS\Utils\Http;

final class RemoteUploader {
	public function upload( string $file_path ): void {
		if ( ! file_exists( $file_path ) ) {
			return;
		}

		$settings = SettingsManager::get( 'backup', 'remote', [] );

		if ( ! empty( $settings['ftp_enabled'] ) ) {
			$this->upload_ftp( $file_path, $settings );
		}

		if ( ! empty( $settings['s3_enabled'] ) ) {
			$this->upload_s3( $file_path, $settings );
		}
	}

	private function upload_ftp( string $file_path, array $settings ): void {
		$host = $settings['ftp_host'] ?? '';
		$user = $settings['ftp_user'] ?? '';
		$pass = $settings['ftp_pass'] ?? '';
		$dir  = $settings['ftp_dir'] ?? '/';

		if ( empty( $host ) || empty( $user ) || ! function_exists( 'ftp_connect' ) ) {
			return;
		}

		$conn = @ftp_connect( $host );
		if ( ! $conn ) {
			Logger::log( 'backup', 'error', 'FTP connection failed.', [ 'host' => $host ] );
			return;
		}

		if ( @ftp_login( $conn, $user, $pass ) ) {
			ftp_pasv( $conn, true );
			
			$remote_file = rtrim( $dir, '/' ) . '/' . basename( $file_path );
			
			if ( ftp_put( $conn, $remote_file, $file_path, FTP_BINARY ) ) {
				Logger::log( 'backup', 'info', 'Successfully uploaded backup to FTP.', [ 'file' => basename( $file_path ) ] );
			} else {
				Logger::log( 'backup', 'error', 'FTP upload failed.', [ 'file' => basename( $file_path ) ] );
			}
		} else {
			Logger::log( 'backup', 'error', 'FTP login failed.' );
		}

		ftp_close( $conn );
	}

	private function upload_s3( string $file_path, array $settings ): void {
		$key    = $settings['s3_key'] ?? '';
		$secret = $settings['s3_secret'] ?? '';
		$bucket = $settings['s3_bucket'] ?? '';
		$region = $settings['s3_region'] ?? 'us-east-1';
		
		if ( empty( $key ) || empty( $secret ) || empty( $bucket ) ) {
			return;
		}

		$file_name = basename( $file_path );
		$host      = "{$bucket}.s3.{$region}.amazonaws.com";
		$uri       = '/' . $file_name;
		$date      = gmdate( 'Ymd\THis\Z' );
		$date_only = gmdate( 'Ymd' );

		$payload_hash = hash_file( 'sha256', $file_path );
		if ( ! $payload_hash ) {
			return;
		}

		$headers = [
			'Host'                 => $host,
			'x-amz-content-sha256' => $payload_hash,
			'x-amz-date'           => $date,
		];

		$canonical_headers = "host:{$host}\nx-amz-content-sha256:{$payload_hash}\nx-amz-date:{$date}\n";
		$signed_headers    = 'host;x-amz-content-sha256;x-amz-date';
		$canonical_request = "PUT\n{$uri}\n\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";

		$credential_scope = "{$date_only}/{$region}/s3/aws4_request";
		$string_to_sign   = "AWS4-HMAC-SHA256\n{$date}\n{$credential_scope}\n" . hash( 'sha256', $canonical_request );

		$k_secret  = 'AWS4' . $secret;
		$k_date    = hash_hmac( 'sha256', $date_only, $k_secret, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', 's3', $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
		
		$signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

		$auth_header = "AWS4-HMAC-SHA256 Credential={$key}/{$credential_scope}, SignedHeaders={$signed_headers}, Signature={$signature}";
		$headers['Authorization'] = $auth_header;

		$endpoint = "https://{$host}{$uri}";
		
		// Использование обертки Utils\Http в соответствии с архитектурой проекта
		$response = Http::stream_put( $endpoint, $file_path, $headers );

		if ( $response['code'] === 200 ) {
			Logger::log( 'backup', 'info', 'Successfully uploaded backup to AWS S3.', [ 'file' => $file_name ] );
		} else {
			Logger::log( 'backup', 'error', 'AWS S3 upload failed.', [ 'http_code' => $response['code'], 'response' => $response['body'] ] );
		}
	}
}