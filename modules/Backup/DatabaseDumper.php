<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Backup;

final class DatabaseDumper {
	public function dump( string $file_path, array $search_replace = [] ): bool {
		global $wpdb;

		$fp = fopen( $file_path, 'w' );
		if ( ! $fp ) {
			return false;
		}

		fwrite( $fp, "-- SBS Toolkit Database Dump\n" );
		fwrite( $fp, "-- Generated: " . gmdate( 'Y-m-d H:i:s' ) . " GMT\n\n" );
		fwrite( $fp, "SET FOREIGN_KEY_CHECKS=0;\n" );
		fwrite( $fp, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n" );

		$tables = $wpdb->get_col( 'SHOW TABLES' );

		foreach ( $tables as $table ) {
			fwrite( $fp, "DROP TABLE IF EXISTS `{$table}`;\n" );
			$create_table = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			fwrite( $fp, $create_table[1] . ";\n\n" );

			// Пакетная выгрузка во избежание нехватки памяти
			$offset = 0;
			$limit  = 1000;

			while ( true ) {
				$rows = $wpdb->get_results( "SELECT * FROM `{$table}` LIMIT {$limit} OFFSET {$offset}", ARRAY_A );
				if ( empty( $rows ) ) {
					break;
				}

				foreach ( $rows as $row ) {
					$values = [];
					foreach ( $row as $val ) {
						// 1. Фикс бага из аудита: корректная обработка NULL
						if ( $val === null ) {
							$values[] = 'NULL';
							continue;
						}

						// 2. Поиск и замена (Search & Replace) с поддержкой сериализации
						if ( ! empty( $search_replace['search'] ) ) {
							$val = $this->safe_search_replace( $val, $search_replace['search'], $search_replace['replace'] );
						}

						// Экранирование для SQL
						$values[] = "'" . esc_sql( $val ) . "'";
					}
					
					$row_sql = implode( ', ', $values );
					fwrite( $fp, "INSERT INTO `{$table}` VALUES ({$row_sql});\n" );
				}
				$offset += $limit;
			}
			fwrite( $fp, "\n" );
		}

		fwrite( $fp, "SET FOREIGN_KEY_CHECKS=1;\n" );
		fclose( $fp );

		return true;
	}

	private function safe_search_replace( string $value, string $search, string $replace ): string {
		$unserialized = @unserialize( $value );
		
		if ( $unserialized !== false || $value === 'b:0;' ) {
			$replaced = $this->recursive_replace( $unserialized, $search, $replace );
			return serialize( $replaced );
		}

		return str_replace( $search, $replace, $value );
	}

	private function recursive_replace( $data, string $search, string $replace ) {
		if ( is_string( $data ) ) {
			return str_replace( $search, $replace, $data );
		}
		if ( is_array( $data ) ) {
			$new = [];
			foreach ( $data as $k => $v ) {
				$new[ $k ] = $this->recursive_replace( $v, $search, $replace );
			}
			return $new;
		}
		if ( is_object( $data ) ) {
			$new = clone $data;
			foreach ( get_object_vars( $data ) as $k => $v ) {
				$new->$k = $this->recursive_replace( $v, $search, $replace );
			}
			return $new;
		}
		return $data;
	}
}