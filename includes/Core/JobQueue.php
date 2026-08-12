<?php

declare(strict_types=1);

namespace Vlad\SBS\Core;

use Vlad\SBS\Modules\Analytics\AggregatorJob;
use Vlad\SBS\Modules\Backup\ArchiveBuilder;

final class JobQueue {
	private \wpdb $wpdb;
	private string $table;

	/** Only these classes may be executed from the queue (prevents arbitrary RCE via DB row). */
	private const ALLOWED_TASKS = [
		ArchiveBuilder::class,
		AggregatorJob::class,
	];

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
		if ( ! in_array( $task_class, self::ALLOWED_TASKS, true ) ) {
			Logger::log( 'core', 'error', 'Rejected non-whitelisted queue task.', [ 'task' => $task_class ] );
			return 0;
		}

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
				"SELECT * FROM {$this->table} WHERE status = %s AND (locked_at IS NULL OR locked_at < %s) ORDER BY id ASC LIMIT 1",
				'pending',
				wp_date( 'Y-m-d H:i:s', time() - ( 5 * MINUTE_IN_SECONDS ) )
			)
		);

		if ( ! $job ) {
			return;
		}

		$this->wpdb->update(
			$this->table,
			[
				'status'    => 'processing',
				'locked_at' => current_time( 'mysql' ),
			],
			[ 'id' => $job->id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		$task = (string) $job->task;

		if ( ! in_array( $task, self::ALLOWED_TASKS, true ) ) {
			$this->wpdb->update(
				$this->table,
				[ 'status' => 'failed', 'attempts' => (int) $job->attempts + 1 ],
				[ 'id' => $job->id ],
				[ '%s', '%d' ],
				[ '%d' ]
			);
			Logger::log( 'core', 'error', 'Blocked non-whitelisted task at runtime.', [ 'task' => $task ] );
			return;
		}

		if ( ! class_exists( $task ) || ! method_exists( $task, 'handle' ) ) {
			$this->wpdb->update(
				$this->table,
				[ 'status' => 'failed', 'attempts' => (int) $job->attempts + 1 ],
				[ 'id' => $job->id ],
				[ '%s', '%d' ],
				[ '%d' ]
			);
			return;
		}

		try {
			$instance = new $task();
			$payload  = json_decode( (string) $job->payload, true );
			if ( ! is_array( $payload ) ) {
				$payload = [];
			}
			$instance->handle( $payload );

			$this->wpdb->update(
				$this->table,
				[ 'status' => 'completed' ],
				[ 'id' => $job->id ],
				[ '%s' ],
				[ '%d' ]
			);
		} catch ( \Throwable $e ) {
			Logger::log( 'core', 'error', 'Job failed: ' . $e->getMessage(), [ 'job_id' => $job->id ] );
			$this->wpdb->update(
				$this->table,
				[
					'status'   => 'failed',
					'attempts' => (int) $job->attempts + 1,
				],
				[ 'id' => $job->id ],
				[ '%s', '%d' ],
				[ '%d' ]
			);
		}
	}
}