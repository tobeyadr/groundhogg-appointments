<?php

namespace GroundhoggBookingCalendar\Admin\Calendars;

use Groundhogg\Admin\Admin_Page;
use GroundhoggBookingCalendar\Calendar_Sync;
use GroundhoggBookingCalendar\Classes\Email_Reminder;
use GroundhoggBookingCalendar\Classes\Google_Calendar;
use GroundhoggBookingCalendar\Classes\Google_Connection;
use GroundhoggBookingCalendar\Classes\SMS_Reminder;
use WP_Error;
use function Groundhogg\admin_page_url;
use function Groundhogg\current_user_is;
use function Groundhogg\get_array_var;
use function Groundhogg\get_contactdata;
use function Groundhogg\get_db;
use function Groundhogg\get_post_var;
use function Groundhogg\get_request_var;
use function Groundhogg\get_url_var;
use GroundhoggBookingCalendar\Classes\Appointment;
use GroundhoggBookingCalendar\Classes\Calendar;
use function Groundhogg\is_replacement_code_format;
use function Groundhogg\utils;
use function Groundhogg\validate_mobile_number;
use function GroundhoggBookingCalendar\set_calendar_default_settings;
use function GroundhoggBookingCalendar\validate_calendar_slug;
use function GroundhoggBookingCalendar\zoom;


// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Calendar_Page
 *
 * @package GroundhoggBookingCalendar\Admin\Calendars
 */
class Calendar_Page extends Admin_Page {

	public function help() {
		// TODO: Implement help() method.
	}

	protected function add_additional_actions() {
		// TODO: Implement add_additional_actions() method.
	}

	protected function add_ajax_actions() {
		add_action( 'wp_ajax_groundhogg_get_appointments', [ $this, 'get_appointments_ajax' ] );
		add_action( 'wp_ajax_groundhogg_add_appointments', [ $this, 'add_appointment_ajax' ] );
	}

	/**
	 * Process AJAX code for fetching appointments.
	 */
	public function get_appointments_ajax() {

		if ( ! current_user_can( 'add_appointment' ) ) {
			wp_send_json_error();
		}

		$ID = absint( get_request_var( 'calendar' ) );

		$calendar = new Calendar( $ID );

		if ( ! $calendar->exists() ) {
			wp_send_json_error();
		}

		$date = get_request_var( 'date' );

		$slots = $calendar->get_appointment_slots( $date );

		if ( empty( $slots ) ) {
			wp_send_json_error( __( 'No slots available.', 'groundhogg-calendar' ) );
		}

		wp_send_json_success( [ 'slots' => $slots ] );
	}


	/**
	 * Process AJAX call for adding appointments
	 */
	public function add_appointment_ajax() {

		if ( ! current_user_can( 'add_appointment' ) ) {
			wp_send_json_error();
		}

		$calendar = new Calendar( absint( get_post_var( 'calendar_id' ) ) );

		if ( ! $calendar->exists() ) {
			wp_send_json_error( __( 'Calendar not found!', 'groundhogg-calendar' ) );
		}

		$contact = get_contactdata( absint( get_post_var( 'contact_id' ) ) );

		if ( ! $contact || ! $contact->exists() ) {
			wp_send_json_error( __( 'Contact not found!', 'groundhogg-calendar' ) );
		}

		$start = absint( get_request_var( 'start_time' ) );
		$end   = absint( get_request_var( 'end_time' ) );

		if ( ! $start || ! $end ) {
			wp_send_json_error( __( 'Please provide a valid date selection.', 'groundhogg-calendar' ) );
		}

		$additional = sanitize_textarea_field( get_post_var( 'additional' ) );

		$appointment = $calendar->schedule_appointment( [
			'contact_id' => $contact->get_id(),
			'start_time' => absint( $start ),
			'end_time'   => absint( $end ),
			'additional' => $additional
		] );

		if ( ! $appointment->exists() ) {
			wp_send_json_error( __( 'Something went wrong while creating appointment.', 'groundhogg-calendar' ) );
		}

		$response = [
			'appointment' => $appointment->get_for_full_calendar(),
			'msg'         => __( 'Appointment booked successfully.', 'groundhogg-calendar' ),
			'url'         => admin_page_url( 'gh_contacts', [
				'action'  => 'edit',
				'contact' => $appointment->get_contact_id(),
			] )
		];

		wp_send_json_success( $response );
	}

	public function get_slug() {
		return 'gh_calendar';
	}

	public function get_name() {
		return _x( 'Calendars', 'page_title', 'groundhogg-calendar' );
	}

	public function get_cap() {
		return 'view_appointment';
	}

	public function get_item_type() {
		return 'calendar';
	}

	public function get_priority() {
		return 48;
	}

	public function get_title_actions() {
		if ( current_user_is( 'sales_manager' ) ) {
			return [];
		} else {
			return parent::get_title_actions();
		}
	}


	/**
	 * enqueue editor scripts for full calendar
	 */
	public function scripts() {

		wp_enqueue_style( 'groundhogg-admin' );

		if ( $this->get_current_action() === 'edit' && get_url_var( 'tab', 'view' ) === 'view' ) {

			$calendar = new Calendar( absint( get_url_var( 'calendar' ) ) );

			wp_enqueue_script( 'groundhogg-calendar-admin' );
			wp_localize_script( 'groundhogg-calendar-admin', 'GroundhoggCalendar', [
				'calendar_id'    => absint( get_url_var( 'calendar' ) ),
				'start_of_week'  => get_option( 'start_of_week' ),
				'min_date'       => $calendar->get_min_booking_period( true ),
				'max_date'       => $calendar->get_max_booking_period( true ),
				'disabled_days'  => $calendar->get_dates_no_slots(),
				'business_hours' => $calendar->get_business_hours(),
				'events'         => $calendar->get_events_for_full_calendar(),
				'tab'            => get_url_var( 'tab', 'view' ),
				'action'         => $this->get_current_action(),
				'item'           => $calendar
			] );
		}

		// STYLES
		wp_enqueue_style( 'groundhogg-fullcalendar' );
		wp_enqueue_style( 'groundhogg-calender-admin' );
		wp_enqueue_style( 'jquery-ui' );
	}

	public function view() {
		if ( ! class_exists( 'Calendars_Table' ) ) {
			include __DIR__ . '/calendars-table.php';
		}

		$calendars_table = new Calendars_Table();
		$this->search_form( __( 'Search Calendars', 'groundhogg-calendar' ) );
		$calendars_table->prepare_items();
		$calendars_table->display();
	}

	public function edit() {
		if ( ! current_user_can( 'edit_calendar' ) ) {
			$this->wp_die_no_access();
		}
		include __DIR__ . '/edit.php';
	}

	public function add() {
		if ( ! current_user_can( 'add_calendar' ) ) {
			$this->wp_die_no_access();
		}

		include __DIR__ . '/add.php';
	}


	public function process_delete() {

		if ( ! current_user_can( 'delete_calendar' ) ) {
			$this->wp_die_no_access();
		}

		$calendar = new Calendar( get_url_var( 'calendar' ) );

		if ( ! $calendar->exists() ) {
			return new \WP_Error( 'failed', __( 'Operation failed Calendar not Found.', 'groundhogg-calendar' ) );
		}

		if ( $calendar->delete() ) {
			$this->add_notice( 'success', __( 'Calendar deleted successfully!' ), 'success' );
		}

		return true;
	}


	/**
	 * Process add calendar and redirect to settings tab on successful calendar creation.
	 *
	 * @return string|\WP_Error
	 */
	public function process_add() {

		if ( ! current_user_can( 'add_calendar' ) ) {
			$this->wp_die_no_access();
		}

		$name        = sanitize_text_field( get_post_var( 'name' ) );
		$description = wp_kses_post( get_post_var( 'description' ) );

		if ( ( ! $name ) || ( ! $description ) ) {
			return new \WP_Error( 'no_data', __( 'Please enter name and description of calendar.', 'groundhogg-calendar' ) );
		}

		$owner_id = absint( get_request_var( 'owner_id', get_current_user_id() ) );

		$calendar = new Calendar( [
			'user_id'     => $owner_id,
			'name'        => $name,
			'description' => $description,
		] );

		if ( ! $calendar->exists() ) {
			return new \WP_Error( 'no_calendar', __( 'Something went wrong while creating calendar.', 'groundhogg-calendar' ) );
		}

		/* SET DEFAULTS */
		set_calendar_default_settings( $calendar );

		// update meta data to get set sms
		$this->add_notice( 'success', __( 'New calendar created successfully!', 'groundhogg-calendar' ), 'success' );

		return admin_url( 'admin.php?page=gh_calendar&action=edit&calendar=' . $calendar->get_id() . '&tab=settings' );

	}

	/**
	 * Handles button click for syncing calendar.
	 *
	 * @return bool|string|void|WP_Error
	 */
	public function process_google_sync() {

		if ( ! current_user_can( 'edit_appointment' ) ) {
			$this->wp_die_no_access();
		}

		do_action( 'groundhogg/calendar/sync_with_google' );

		$this->add_notice( 'success', __( 'Appointments synced successfully!', 'groundhogg-calendar' ), 'success' );
	}

	/**
	 * manage tab's post request by calling appropriate function.
	 *
	 * @return bool
	 */
	public function process_edit() {
		if ( ! current_user_can( 'edit_calendar' ) ) {
			$this->wp_die_no_access();
		}

		$tab = get_request_var( 'tab', 'view' );

		switch ( $tab ) {

			default:
			case 'view':
				// Update actions from View
				break;
			case 'settings':
				// Update Settings Page
				$this->update_calendar_settings();
				break;
			case 'availability':
				// Update Availability
				$this->update_availability();
				break;
			case 'emails':
				$this->update_emails();
				break;
			case 'notification':
				$this->update_admin_notification();
				break;
			case 'sms' :
				$this->update_sms();
				break;
			case 'integration':
				$this->update_integration_settings();
				break;
		}

		return true;
	}

	/**
	 * Update the admin notification configuration
	 */
	protected function update_admin_notification() {

		if ( ! current_user_can( 'edit_calendar' ) ) {
			$this->wp_die_no_access();
		}

		$calendar = new Calendar( get_url_var( 'calendar' ) );

		$admin_notifications = [
			'sms'                       => (bool) get_post_var( 'admin_sms_notifications' ),
			Email_Reminder::SCHEDULED   => (bool) get_post_var( 'scheduled_notification' ),
			Email_Reminder::RESCHEDULED => (bool) get_post_var( 'rescheduled_notification' ),
			Email_Reminder::CANCELLED   => (bool) get_post_var( 'cancelled_notification' ),
		];

		$calendar->update_meta( 'enabled_admin_notifications', $admin_notifications );

		// Validate and sanitize emails for email admin notifications
		$admin_email_recipients = get_post_var( 'admin_notification_email_recipients' );
		$admin_email_recipients = sanitize_text_field( $admin_email_recipients );
		$admin_email_recipients = array_map( 'trim', explode( ',', $admin_email_recipients ) );
		$admin_email_recipients = array_filter( $admin_email_recipients, function ( $email ) {
			return is_email( $email ) || is_replacement_code_format( $email );
		} );
		$admin_email_recipients = implode( ', ', $admin_email_recipients );
		$calendar->update_meta( 'admin_notification_email_recipients', $admin_email_recipients );

		// Validate and sanitize mobile numbers for SMS notifications
		$admin_sms_recipients = get_post_var( 'admin_notification_sms_recipients' );
		$admin_sms_recipients = sanitize_text_field( $admin_sms_recipients );
		$admin_sms_recipients = array_map( 'trim', explode( ',', $admin_sms_recipients ) );
		$admin_sms_recipients = array_filter( $admin_sms_recipients, function ( $number ) {
			return validate_mobile_number( $number ) || is_replacement_code_format( $number );
		} );
		$admin_sms_recipients = implode( ', ', $admin_sms_recipients );
		$calendar->update_meta( 'admin_notification_sms_recipients', $admin_sms_recipients );

		// Other notification stuff
		$calendar->update_meta( 'subject', sanitize_text_field( get_request_var( 'subject' ) ) );
		$calendar->update_meta( 'notification', sanitize_textarea_field( get_request_var( 'notification' ) ) );
	}

	/**
	 * Udpate the Email reminders
	 */
	protected function update_emails() {
		if ( ! current_user_can( 'edit_calendar' ) ) {
			$this->wp_die_no_access();
		}

		$calendar = new Calendar( get_request_var( 'calendar' ) );

		$calendar->update_meta( 'email_notifications', [
			Email_Reminder::SCHEDULED   => absint( get_post_var( Email_Reminder::SCHEDULED ) ),
			Email_Reminder::RESCHEDULED => absint( get_post_var( Email_Reminder::RESCHEDULED ) ),
			Email_Reminder::CANCELLED   => absint( get_post_var( Email_Reminder::CANCELLED ) )
		] );

		$reminders = get_post_var( 'email_reminders' );

		$operation = get_array_var( $reminders, 'when' );
		$number    = get_array_var( $reminders, 'number' );
		$period    = get_array_var( $reminders, 'period' );
		$email_id  = get_array_var( $reminders, 'email_id' );

		$reminders = [];

		if ( empty( $operation ) ) {
			$calendar->delete_meta( 'email_reminders' );
		} else {
			foreach ( $operation as $i => $op ) {
				$temp_reminders             = [];
				$temp_reminders['when']     = $op;
				$temp_reminders['number']   = $number[ $i ];
				$temp_reminders['period']   = $period[ $i ];
				$temp_reminders['email_id'] = $email_id [ $i ];
				$reminders[]                = $temp_reminders;
			}

			$calendar->update_meta( 'email_reminders', $reminders );
		}
	}

	/**
	 * Save SMS notifications configuration
	 */
	protected function update_sms() {
		if ( ! current_user_can( 'edit_calendar' ) ) {
			$this->wp_die_no_access();
		}

		$calendar = new Calendar( get_request_var( 'calendar' ) );

		$calendar->update_meta( 'enable_sms_notifications', (bool) get_post_var( 'enabled_sms_notifications' ) );

		$calendar->update_meta( 'sms_notifications', [
			SMS_Reminder::SCHEDULED   => absint( get_request_var( SMS_Reminder::SCHEDULED ) ),
			SMS_Reminder::RESCHEDULED => absint( get_request_var( SMS_Reminder::RESCHEDULED ) ),
			SMS_Reminder::CANCELLED   => absint( get_request_var( SMS_Reminder::CANCELLED ) )
		] );

		$reminders = get_request_var( 'sms_reminders' );

		$operation = get_array_var( $reminders, 'when' );
		$number    = get_array_var( $reminders, 'number' );
		$period    = get_array_var( $reminders, 'period' );
		$sms_id    = get_array_var( $reminders, 'sms_id' );

		$reminder = [];
		if ( empty( $operation ) ) {
			$calendar->delete_meta( 'sms_reminders' );
		} else {

			foreach ( $operation as $i => $op ) {
				$temp_reminders           = [];
				$temp_reminders['when']   = $op;
				$temp_reminders['number'] = $number[ $i ];
				$temp_reminders['period'] = $period[ $i ];
				$temp_reminders['sms_id'] = $sms_id [ $i ];
				$reminder[]               = $temp_reminders;
			}
			$calendar->update_meta( 'sms_reminders', $reminder );
		}
	}


	/**
	 * Update calendar availability
	 */
	protected function update_availability() {

		if ( ! current_user_can( 'edit_calendar' ) ) {
			$this->wp_die_no_access();
		}

		$calendar_id = absint( get_request_var( 'calendar' ) );

		$calendar = new Calendar( $calendar_id );

		$rules = get_request_var( 'rules' );

		$days   = get_array_var( $rules, 'day' );
		$starts = get_array_var( $rules, 'start' );
		$ends   = get_array_var( $rules, 'end' );

		$availability = [];

		if ( ! $days ) {
			$this->add_notice( new \WP_Error( 'error', 'Please add at least one availability slot' ) );

			return;
		}

		foreach ( $days as $i => $day ) {

			$temp_rule          = [];
			$temp_rule['day']   = $day;
			$temp_rule['start'] = $starts[ $i ];
			$temp_rule['end']   = $ends[ $i ];

			$availability[] = $temp_rule;

		}

		$calendar->update_meta( 'max_booking_period_count', absint( get_request_var( 'max_booking_period_count', 3 ) ) );
		$calendar->update_meta( 'max_booking_period_type', sanitize_text_field( get_request_var( 'max_booking_period_type', 'months' ) ) );

		$calendar->update_meta( 'min_booking_period_count', absint( get_request_var( 'min_booking_period_count', 0 ) ) );
		$calendar->update_meta( 'min_booking_period_type', sanitize_text_field( get_request_var( 'min_booking_period_type', 'days' ) ) );

		$calendar->update_meta( 'rules', $availability );

		// Save make me look busy
		$calendar->update_meta( 'busy_slot', absint( get_request_var( 'busy_slot', 0 ) ) );

		$this->add_notice( 'updated', __( 'Availability updated.' ) );
	}

	/**
	 *  Updates the calendar settings.
	 */
	protected function update_calendar_settings() {

		$calendar_id = absint( get_url_var( 'calendar' ) );
		$calendar    = new Calendar( $calendar_id );

		$args = array(
			'user_id'     => absint( get_post_var( 'owner_id', get_current_user_id() ) ),
			'name'        => sanitize_text_field( get_post_var( 'name', $calendar->get_name() ) ),
			'slug'        => validate_calendar_slug( get_post_var( 'slug', $calendar->get_slug() ), $calendar->get_id() ),
			'description' => wp_kses_post( get_post_var( 'description' ) ),
		);

		if ( ! $calendar->update( $args ) ) {
			$this->add_notice( new \WP_Error( 'error', 'Unable to update calendar.' ) );

			return;
		}

		// Save 12 hour
		if ( get_request_var( 'time_12hour' ) ) {
			$calendar->update_meta( 'time_12hour', true );
		} else {
			$calendar->delete_meta( 'time_12hour' );
		}

		// Save appointment length
		$calendar->update_meta( 'slot_hour', absint( get_request_var( 'slot_hour', 0 ) ) );
		$calendar->update_meta( 'slot_minute', absint( get_request_var( 'slot_minute', 0 ) ) );

		// Save buffer time
		$calendar->update_meta( 'buffer_time', absint( get_request_var( 'buffer_time', 0 ) ) );

		// save success message
		$calendar->update_meta( 'message', wp_kses_post( get_request_var( 'message' ) ) );

		//save default note
		$calendar->update_meta( 'additional_notes', wp_kses_post( get_request_var( 'additional_notes' ) ) );

		// save thank you page
		$calendar->update_meta( 'redirect_link_status', absint( get_request_var( 'redirect_link_status' ) ) );
		$calendar->update_meta( 'redirect_link', esc_url( get_request_var( 'redirect_link' ) ) );

		$form_override = absint( get_request_var( 'override_form_id', 0 ) );
		$calendar->update_meta( 'override_form_id', $form_override );

		$this->add_notice( 'success', _x( 'Settings updated.', 'notice', 'groundhogg-calendar' ), 'success' );
	}

	public function update_integration_settings() {

		$calendar_id = absint( get_request_var( 'calendar' ) );
		$calendar    = new Calendar( $calendar_id );

		// Save Google Account ID for tokens
		if ( $account_id = absint( get_post_var( 'google_account_id' ) ) ) {
			$calendar->set_google_connection_id( $account_id );
		}

		// Save Google Calendar ID which we add new events to
		if ( $google_calendar_id = absint( get_post_var( 'google_calendar_id' ) ) ) {
			$calendar->update_meta( 'google_calendar_id', $google_calendar_id );
		}

		// Don't turn off main calendar by mistake
		$google_calendar_list   = wp_parse_id_list( get_post_var( 'google_calendar_list', [] ) );
		$google_calendar_list[] = $calendar->get_local_google_calendar_id();
		$google_calendar_list   = array_unique( $google_calendar_list );
		$calendar->update_meta( 'google_calendar_list', $google_calendar_list );

		// Google Appointment overrides
		$calendar->update_meta( 'google_appointment_name', sanitize_text_field( get_request_var( 'google_appointment_name' ) ) );
		$calendar->update_meta( 'google_appointment_description', sanitize_textarea_field( get_request_var( 'google_appointment_description' ) ) );

		// Google Meet Setting
		if ( get_request_var( 'google_meet_enable' ) ) {
			$calendar->update_meta( 'google_meet_enable', true );
		} else {
			$calendar->delete_meta( 'google_meet_enable' );
		}

		//save Zoom Account id
		if ( $account_id = absint( get_post_var( 'zoom_account_id' ) ) ) {
			$calendar->set_zoom_account_id( $account_id );
		}

		$this->add_notice( 'success', _x( 'Integrations updated.', 'notice', 'groundhogg-calendar' ), 'success' );
	}

	/**
	 * Redirects users to GOOGLE oauth authentication URL with all the details.
	 *
	 * @return string
	 */
	public function process_access_code() {

		$redirect_uri = admin_page_url( 'gh_calendar', [
			'action'   => 'verify_google_code',
			'calendar' => get_url_var( 'calendar' ),
			'_wpnonce' => wp_create_nonce()
		] );

		return add_query_arg( [ 'redirect_uri' => urlencode( $redirect_uri ) ], 'https://proxy.groundhogg.io/oauth/google/start/' );
	}

	/**
	 * Retrieves authentication code from the response url and creates authentication token for the GOOGLE.
	 *
	 * @return bool|WP_Error
	 */
	public function process_verify_google_code() {

		if ( ! get_request_var( 'code' ) ) {
			return new \WP_Error( 'no_code', __( 'Authentication code not found!', 'groundhogg-calendar' ) );
		}

		$auth_code   = get_url_var( 'code' );
		$calendar_id = absint( get_url_var( 'calendar' ) );
		$calendar    = new Calendar( $calendar_id );

		$connection = new Google_Connection();
		$connection->create_from_auth( $auth_code );

		if ( $connection->has_errors() ) {
			return $connection->get_last_error();
		}

		$calendar->set_google_connection_id( $connection->get_id() );

		$main_cal = new Google_Calendar( $connection->account_email, 'google_calendar_id' );

		$calendar->set_google_calendar_id( $main_cal->get_id() );
		$calendar->update_meta( 'google_calendar_list', [
			$main_cal->get_id()
		] );

		Calendar_Sync::sync();

		$this->add_notice( 'success', __( 'Connection to Google was successful!', 'groundhogg-calendar' ), 'success' );

		return admin_page_url( 'gh_calendar', [
			'action'   => 'edit',
			'calendar' => $calendar_id,
			'tab'      => 'integration'
		] );
	}


	/**
	 * Redirects users to ZOOM oauth authentication URL with all the details.
	 *
	 * @return string
	 */
	public function process_access_code_zoom() {

		$redirect_uri = admin_page_url( 'gh_calendar', [
			'action'   => 'verify_zoom_code',
			'calendar' => get_url_var( 'calendar' ),
			'_wpnonce' => wp_create_nonce()
		] );

		return add_query_arg( [ 'redirect_uri' => urlencode( $redirect_uri ) ], 'https://proxy.groundhogg.io/oauth/zoom/start/' );
	}


	/**
	 * Retrieves authentication code from the response url and creates authentication token for the ZOOM.
	 *
	 * @return bool|WP_Error
	 */
	public function process_verify_zoom_code() {

		if ( ! get_request_var( 'code' ) ) {
			return new \WP_Error( 'no_code', __( 'Authentication code not found!', 'groundhogg-calendar' ) );
		}

		$auth_code   = get_request_var( 'code' );
		$calendar_id = absint( get_request_var( 'calendar' ) );
		$calendar    = new Calendar( $calendar_id );

		$account_id = zoom()->init_connection( $auth_code );

		if ( is_wp_error( $account_id ) ) {
			return $account_id;
		}

		$calendar->set_zoom_account_id( $account_id );

		$this->add_notice( 'success', __( 'Connection to zoom successfully completed!', 'groundhogg-calendar' ), 'success' );

		return admin_page_url( 'gh_calendar', [
			'action'   => 'edit',
			'calendar' => $calendar_id,
			'tab'      => 'integration'
		] );
	}
}