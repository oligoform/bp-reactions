<?php
/**
 * Reaction Functions!
 *
 * @since  1.0.0
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Register the Activity reactions used by the plugin.
 *
 * @see bp_reactions_register_default_reactions() for examples of use
 *
 * @since  1.0.0
 *
 * @param  string $name The name of the reaction
 * @param  array  $args The attributes of the reaction
 */
function bp_reactions_register_reaction( $name = '', $args = array() ) {
	if ( empty( $name ) || empty( $args['emoji'] ) ) {
		return false;
	}

	$name = sanitize_key( $name );

	if ( ! isset( bp_reactions()->reactions[ $name ] ) ) {
		$params = (object) wp_parse_args( $args, array(
			'emoji'           => '',
			'reaction_name'   => $name,
			'can_comment'     => false,
			'component_id'    => 'reactions',
			'description'     => '',
			'format_callback' => 'bp_reactions_default_format_callback',
			'label'           => '',
			'context'         => array(),
			'position'        => 0
		) );

		$params->reaction_type = 'bp_activity_reaction_' . $name;

		bp_reactions()->reactions[ $name ] = $params;
		return true;
	} else {
		return false;
	}
}

/**
 * Register Default reactions (favorites/likes)
 *
 * NB: Hook bp_init to register yours
 *
 * @since 1.0.0
 */
function bp_reactions_register_default_reactions() {
	/**
	 * The plugin is by default replacing BuddyPress favorites
	 * If you wish to keep the BuddyPress favorites, use:
	 * add_filter( 'bp_reactions_do_not_override_bp_favorites', '__return_true' )
	 *
	 * NB: we are using twemojis to display the emoji of the reaction, see /includes/emojis.php
	 * for available twemoji codes
	 */
	if ( ! bp_activity_can_favorite() ) {
		bp_reactions_register_reaction( 'favorite', array(
			'emoji'           => '0x2B50',
			'description'     => __( 'Favorited an update', 'bp-reactions' ),
			'label'           => __( 'Favorites', 'bp-reactions' ),
			'format_callback' => 'bp_reactions_favorite_format_callback'
		) );
	}

	bp_reactions_register_reaction( 'like', array(
		'emoji'           => '0x2764',
		'description'     => __( 'Liked an update', 'bp-reactions' ),
		'label'           => __( 'Likes', 'bp-reactions' ),
		'format_callback' => 'bp_reactions_like_format_callback'
	) );
}

/**
 * Get all registered reactions
 *
 * @since  1.0.0
 *
 * @return array the list of registered reaction objects
 */
function bp_reactions_get_reactions() {
	return bp_reactions()->reactions;
}

/**
 * Get a specific reaction
 *
 * @since  1.0.0
 *
 * @param  string $name The name of the reaction
 * @return object       The reaction object
 */
function bp_reactions_get_reaction( $name = '' ) {
	$reaction = null;

	if ( isset( bp_reactions()->reactions[ $name ] ) ) {
		$reaction = bp_reactions()->reactions[ $name ];
	}

	return $reaction;
}

/**
 * Get a specific reaction using its activity type
 *
 * @since  1.0.0
 *
 * @param  string $type The activity type
 * @return object       The reaction object
 */
function bp_reactions_get_reaction_by_activity_type( $type = '' ) {
	$reaction = wp_filter_object_list( bp_reactions_get_reactions(), array( 'reaction_type' => $type ) );

	if ( ! empty( $reaction ) ) {
		$reaction = reset( $reaction );
	} else {
		$reaction = null;
	}

	return $reaction;
}

/**
 * Register Activity actions for the reactions
 *
 * @since 1.0.0
 */
function bp_reactions_register_activity_actions() {
	$reactions = bp_reactions_get_reactions();

	foreach ( $reactions as $reaction ) {
		bp_activity_set_action(
			$reaction->component_id,
			$reaction->reaction_type,
			$reaction->description,
			$reaction->format_callback,
			$reaction->label,
			$reaction->context,
			$reaction->position
		);
	}
}

/**
 * List the users who reacted to an activity
 *
 * @since  1.0.0
 *
 * @global $wpdb
 * @param  int $activity_id The activity ID
 * @return array            An associative array of users who reacted to reaction types
 */
function bp_reactions_activity_get_users( $activity_id ) {
	global $wpdb;

	$activity_table = buddypress()->activity->table_name;
	$data           = array();

	$activity_reactions = $wpdb->get_results( $wpdb->prepare( "SELECT a.user_id, a.type FROM {$activity_table} a WHERE a.component = 'reactions' AND a.item_id = %d", $activity_id ) );

	foreach ( $activity_reactions as $reaction ) {
		$data[ $reaction->type ]['users'][] = $reaction->user_id;
	}

	return $data;
}

/**
 * Get all the activity IDs a user reacted to.
 *
 * @since  1.0.0
 *
 * @global $wpdb
 * @param  int   $user_id The user ID.
 * @return array          The list of activity IDs the user reacted to.
 */
function bp_reactions_get_user_reactions( $user_id = 0 ) {
	global $wpdb;

	if ( empty( $user_id ) ) {
		return array();
	}

	$activity_table = buddypress()->activity->table_name;

	return $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT a.item_id FROM {$activity_table} a WHERE a.component = 'reactions' AND a.user_id = %d", $user_id ) );
}

/**
 * Get All reactions count for an Activity
 *
 * @since  1.0.0
 *
 * @global $wpdb
 * @param  int $activity_id The activity ID.
 * @return int              The reactions count.
 */
function bp_reactions_get_reactions_count( $activity_id = 0 ) {
	global $wpdb;

	$activity_table = buddypress()->activity->table_name;

	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$activity_table} a WHERE a.component = 'reactions' AND a.item_id = %d", $activity_id ) );
}

/**
 * Update the reactions count (when a reaction is added or removed)
 *
 * @since  1.0.0
 *
 * @param  int $activity_id The activity ID.
 * @return int              The updated count.
 */
function bp_reactions_update_reactions_count( $activity_id = 0 ) {
	if ( empty( $activity_id ) ) {
		return false;
	}

	$count = bp_reactions_get_reactions_count( $activity_id );

	if ( 0 !== $count ) {
		bp_activity_update_meta( $activity_id, 'bp_reactions_count', $count );

	// We need to delete so that the activity is not listed into the popular tab
	} else {
		bp_activity_delete_meta( $activity_id, 'bp_reactions_count' );
	}

	return $count;
}

/**
 * Is this an Activity screen?
 *
 * @since 1.0.0
 *
 * @return  bool True if viewing an Activity screen. False otherwise.
 */
function bp_reactions_is_activity() {
	return (bool) apply_filters( 'bp_reactions_is_activity', bp_is_activity_component() || bp_is_group_activity() );
}

/**
 * Check if the deleted activities contains reactions and
 * update the reactions count if needed.
 *
 * @since  1.0.0
 *
 * @param  array  $activities An array of deleted activity objects
 */
function bp_reactions_update_activity_reactions( $activities = array() ) {
	$reactions = wp_filter_object_list( $activities, array( 'component' => 'reactions' ), 'AND', 'item_id' );

	if ( ! empty( $reactions ) ) {
		foreach ( $reactions as $activity_id ) {
			bp_reactions_update_reactions_count( $activity_id );
		}
	}

	// Then make sure to delete reactions about the deleted activities
	foreach ( $activities as $activity ) {
		if ( 'reactions' === $activity->type ) {
			continue;
		}

		// Prevent recursion
		remove_action( 'bp_activity_after_delete', 'bp_reactions_update_activity_reactions', 10, 1 );

		bp_activity_delete( array(
			'item_id'           => $activity->id,
			'component'         => 'reactions',
		) );

		add_action( 'bp_activity_after_delete', 'bp_reactions_update_activity_reactions', 10, 1 );
	}
}

/**
 * Format the generic reaction action string.
 *
 * @since  1.0.0
 *
 * @param  string               $action   The action string.
 * @param  BP_Activity_Activity $activity The Activity object.
 * @return string                         The action string.
 */
function bp_reactions_default_format_callback( $action, $activity ) {
	$action = sprintf( _x( '%s reacted to an update', 'default reaction action string', 'bp-reactions' ), bp_core_get_userlink( $activity->user_id ) );

	/**
	 * Filters the formatted activity reaction string.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $action   Activity action string value.
	 * @param BP_Activity_Activity $activity Activity item object.
	 */
	return apply_filters( 'bp_activity_reaction_default_action', $action, $activity );
}

/**
 * Format the favorite reaction action string.
 *
 * @since  1.0.0
 *
 * @param  string               $action   The action string.
 * @param  BP_Activity_Activity $activity The Activity object.
 * @return string                         The action string.
 */
function bp_reactions_favorite_format_callback( $action, $activity ) {
	$action = sprintf( __( '%s favorited an update', 'bp-reactions' ), bp_core_get_userlink( $activity->user_id ) );

	/**
	 * Filters the formatted activity favorite reaction string.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $action   Activity action string value.
	 * @param BP_Activity_Activity $activity Activity item object.
	 */
	return apply_filters( 'bp_activity_reaction_favorite_action', $action, $activity );
}

/**
 * Format the like reaction action string.
 *
 * @since  1.0.0
 *
 * @param  string               $action   The action string.
 * @param  BP_Activity_Activity $activity The Activity object.
 * @return string                         The action string.
 */
function bp_reactions_like_format_callback( $action, $activity ) {
	$action = sprintf( __( '%s liked an update', 'bp-reactions' ), bp_core_get_userlink( $activity->user_id ) );

	/**
	 * Filters the formatted activity like reaction string.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $action   Activity action string value.
	 * @param BP_Activity_Activity $activity Activity item object.
	 */
	return apply_filters( 'bp_activity_reaction_like_action', $action, $activity );
}

/**
 * Add a new reaction.
 *
 * @since  1.0.0
 *
 * @param  int      $activity_id The parent Activity ID.
 * @param  array    $args        The activity attributes.
 * @return bool|int              The activity ID on success. False otherwise.
 */
function bp_activity_reactions_add( $activity_id = 0, $args = array() ) {
	// Parent activity exists ?
	$activity = new BP_Activity_Activity( $activity_id );

	if ( empty( $activity->type ) || 'reactions' === $activity->component ) {
		return false;
	}

	$params = wp_parse_args( $args, array(
		'user_id'           => bp_loggedin_user_id(),
		'component'         => 'reactions',
		'type'              => '',
		'action'            => '',
		'content'           => '',
		'primary_link'      => bp_activity_get_permalink( $activity_id, $activity ),
		'item_id'           => $activity_id,
		'secondary_item_id' => $activity->user_id,
		'hide_sitewide'     => $activity->hide_sitewide,
	) );

	if ( empty( $params['type'] ) || 'reactions' !== $params['component'] ) {
		return false;
	}

	if ( empty( $params['action'] ) ) {
		$reaction_object = (object) $params;
		$params['action'] = bp_reactions_default_format_callback( '', $reaction_object );
	}

	// Reaction already exists? (shouldn't happen)
	$existing = bp_activity_get( array(
		'filter' => array(
			'user_id'    => $params['user_id'],
			'object'     => 'reactions',
			'action'     => $params['type'],
			'primary_id' => $activity_id,
		),
	) );

	// Simply return the ID of the existing reaction.
	if ( ! empty( $existing['activities'][0]->id ) ) {
		return $existing['activities'][0]->id;
	}

	$added = bp_activity_add( $params );

	if ( ! empty( $added ) ) {
		bp_reactions_update_reactions_count( $activity_id );
	}

	/**
	 * Hook here to do specific actions once the reaction was created.
	 *
	 * @since  1.0.0
	 *
	 * @param  int   $added  True if the reaction was successfully created. False otherwise.
	 * @param  array $params The activity attributes.
	 */
	do_action( 'bp_activity_reactions_added', $added, $params );

	return $added;
}

/**
 * Remove a reaction.
 *
 * @since  1.0.0
 *
 * @param  int      $activity_id The parent Activity ID.
 * @param  string   $reaction_id The reaction name.
 * @param  int      $user_id     The user ID.
 * @return bool                  True on success. False otherwise.
 */
function bp_activity_reactions_remove( $activity_id = 0, $reaction_id = '', $user_id = 0 ) {
	if ( empty( $activity_id ) || empty( $reaction_id ) ) {
		return false;
	}

	$reaction = bp_reactions_get_reaction( $reaction_id );

	if ( empty( $reaction->reaction_type ) ) {
		return false;
	}

	if ( empty( $user_id ) ) {
		$user_id = bp_loggedin_user_id();
	}

	$reaction_activity = bp_activity_get( array(
		'filter' => array(
			'user_id'    => $user_id,
			'object'     => 'reactions',
			'action'     => $reaction->reaction_type,
			'primary_id' => $activity_id,
		),
	) );

	if ( ! isset( $reaction_activity['activities'] ) || ! is_array( $reaction_activity['activities'] ) ) {
		return false;
	}

	$activity = reset( $reaction_activity['activities'] );

	if ( empty( $activity->id ) ) {
		return false;
	}

	$deleted = bp_activity_delete( array( 'id' => $activity->id ) );

	/**
	 * Hook here to do specific actions once the reaction was deleted.
	 *
	 * @since  1.0.0
	 *
	 * @param  bool                 $deleted  True if the reaction was successfully deleted. False otherwise.
	 * @param  BP_Activity_Activity $activity The activity object for the reaction.
	 */
	do_action( 'bp_activity_reactions_removed', $deleted, $activity );

	return $deleted;
}

function bp_reactions_activity_can_react() {
	if ( ! isset( $GLOBALS['activities_template'] ) ) {
		$retval = false;
	} else {
		$reaction = bp_reactions_get_reaction_by_activity_type( bp_get_activity_type() );
		$retval   = ! isset( $reaction );
	}

	return $retval;
}
