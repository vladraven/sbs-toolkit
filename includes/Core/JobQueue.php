<?php

declare(strict_types=1);

namespace Vlad\SBS\Core;

final class JobQueue {
	private \wpdb $wpdb;
	private string $table;

	public function __construct() {
		global $wpdb;
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'sbs_queue';

		add_action( 'sbs_process_queue_cron', [ $this, 'process' ] );
		if ( ! wp_next_scheduled( 'sbs_process_queue_cron' ) ) {
			wp_schedule_event( time(), 'every_minute', 'sbs_process_queue_cron' );
		}
	}

	public function push( string $task_class, array $payload ): int {
		$this->wpdb->insert(
			$this->table,
			[
				'task'       => $task_class,
				'payload'    => wp_json_encode( $payload ),
				'status'     => 'pending',
				'attempts'   => 0,
				'created_at' => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%d', '%s' ]
		);
		return (int) $this->wpdb->insert_id;
	}

	public function process(): void {
		$job = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE status = 'pending' AND (locked_at IS NULL OR locked_at < %s) ORDER BY id ASC LIMIT 1",
				wp_date( 'Y-m-d H:i:s', time() - ( 5 * MINUTE_IN_SECONDS ) )
			)
		);

		if ( ! $job ) {
			return;
		}

		$this->wpdb->update(
			$this->table,
			[ 'status' => 'processing', 'locked_at' => current_time( 'mysql' ) ],
			[ 'id' => $job->id ]
		);

		if ( class_exists( $job->task ) && method_exists( $job->task, 'handle' ) ) {
			try {
				$instance = new $job->task();
				$payload  = json_decode( $job->payload, true ) ?? [];
				$instance->handle( $payload );

				$this->wpdb->update( $this->table, [ 'status' => 'completed' ], [ 'id' => $job->id ] );
			} catch ( \Throwable $e ) {
				Logger::log( 'core', 'error', 'Job failed: ' . $e->getMessage(), [ 'job_id' => $job->id ] );
				$this->wpdb->update(
					$this->table,
					[ 'status' => 'failed', 'attempts' => $job->attempts + 1 ],
					[ 'id' => $job->id ]
				);
			}
		}
	}
}