<?php

namespace GroundhoggBookingCalendar;

use GroundhoggBookingCalendar\Classes\Calendar;
use GroundhoggBookingCalendar\Classes\Google_Calendar;
use GroundhoggBookingCalendar\Classes\Google_Connection;
use function Groundhogg\array_map_to_class;
use function Groundhogg\get_db;

class Calendar_Sync {

	public function __construct() {

		add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );
		add_action( 'groundhogg/calendar/sync_with_google', [ $this, 'google_sync' ] );
		add_action( 'init', [ $this, 'add_cron_jobs' ] );

	}

	public static function sync(){
		do_action( 'groundhogg/calendar/sync_with_google' );
	}

	/**
	 * Add a 15 minute schedule
	 *
	 * @param array $schedules
	 *
	 * @return array|mixed
	 */
	public function add_cron_schedules( $schedules = [] ) {
		$schedules['every_15_minutes'] = array(
			'interval' => MINUTE_IN_SECONDS * 15,
			'display'  => _x( 'Every 15 Minutes', 'cron_schedule', 'groundhogg-calendar' )
		);

		return $schedules;
	}

	/**
	 * Add the cron job
	 */
	public function add_cron_jobs() {

		if ( ! wp_next_scheduled( 'groundhogg/calendar/sync_with_google' ) ) {
			wp_schedule_event( time(), 'every_15_minutes', 'groundhogg/calendar/sync_with_google' );
		}

	}

	/**
	 * Sync all the google calendars
	 * Delete old synced events
	 * Sync all the events for calendars with sync enabled
	 */
	function google_sync() {
		$connections = get_db( 'google_connections' )->query();

		foreach ( $connections as $connection ){
			$connection = new Google_Connection( $connection->ID );
			$connection->sync_calendars();
		}

		get_db( 'synced_events' )->delete_old_events();

		$calendars = get_db( 'calendars' )->query();
		array_map_to_class( $calendars, Calendar::class );
		$gcal_ids_to_sync = array_reduce( $calendars, function ( $carry, $calendar ){
			/**
			 * @var $calendar Calendar
			 */
			return array_unique( array_merge( $carry, $calendar->get_google_calendar_list() ) );
		}, [] );

		$gcals = get_db( 'google_calendars' )->query( [
			'ID' => $gcal_ids_to_sync
		] );

		foreach ( $gcals as $gcal ) {
			$gcal = new Google_Calendar( $gcal );

			$gcal->sync_events();
		}
	}

}