<?php
/**
* Plugin Name: PublishPress Revisions integration with Posts To Posts
* Description: Allows many-to-many connections to be edited, submitted and approved via PublishPress Revisions Pro
* Version: 0.0.1
* Requires at least: 5.6
* Author: Hans Czajkowski Jørgensen
**/

namespace Tekno_PP_Rev_P2P_Integration;

 // Filters

 /**
 * Enables the posts-to-posts admin boxes to work correctly when editing a revision
 *
 * Requires you to use of this fork of the posts to posts plugin to function:
 * https://github.com/teknologi/wp-posts-to-posts (publishpress-revisions-integration branch)
 */

add_filter('p2p_other_query_vars', function($qv) {
    $qv['is_revisions_query'] = true;
    return $qv;
});

// Hooks

/**
 * PublishPress Revisions - Copy any posts-to-posts relations on the original post
 * to the revision.
 *
 * intended to be fired on revision creation (hook name: 'revisionary_new_revision').
 *
 * Note: this only works on many-to-many connections
 *
 * @param int $post_id id of the revision post
 * @param int $post_status unused
 * @return void
 */
 function revisionary_created_revision_add_connected_posts($post_id, $post_status) {
    // Get the base post in order to be able to copy the p2p connections from it to the revision post
    $revision_post = \get_post($post_id);

    if ($revision_post) {
        $base_post_id = \get_post_meta($revision_post->ID, '_rvy_base_post_id', true);

        if ($base_post_id) {
            $base_post = \get_post($base_post_id);
        }

        // check if post_to_posts is active. Bail if it isn't
        if (!class_exists('\P2P_Connection_Type_Factory')) {
            return;
        }

        $revision_post_connections = get_post_connection_instances($revision_post->ID);

        if (isset($base_post)) {
            foreach ($revision_post_connections as $revision_post_connection) {
                // get the posts connected to the base post

                $connected_posts = get_posts(array(
                'connected_type' => $revision_post_connection->name,
                'connected_items' => $base_post,
                'nopaging' => true,
                'suppress_filters' => false
                ));

                // Connect the posts connected to the base post to the revision post
                foreach ($connected_posts as $connected_post) {
                    $new_connection_id = p2p_type($revision_post_connection->name)->connect($revision_post->ID, $connected_post->ID);

                    // also copy over post connection meta for projects
                    if ($revision_post_connection->name == 'contacts_to_projects' && is_int($new_connection_id) ) {
                        $project_meta = p2p_get_meta($connected_post->p2p_id, 'content_roles');
                        if ($project_meta) {
                            foreach ($project_meta as $project_meta_item) {
                                p2p_add_meta($new_connection_id, 'content_roles', $project_meta_item);
                            }
                        }
                    }
                }
            }
        }
    }

    log('Created revision of "' . $revision_post->post_title . '" ID: ' . strval($revision_post->ID) . ' Original ID: ' . $base_post_id . ' Original title: "' . \get_the_title($base_post_id) . '"');

}

add_action('revisionary_new_revision', __NAMESPACE__ . '\revisionary_created_revision_add_connected_posts', 10, 2);

/**
 * PublishPress Revisions - Copy any posts-to-posts relations on revision post
 * to the original post.
 *
 * intended to be fired on revision application, before the evision post
 * type is changed to 'revision' (hook name: 'pre_revision_post_type_changed').
 *
 * Note: this only works on many-to-many connections
 *
 * For this to work, it requires a hook to be manually inserted in the
 * publishpress revisions pro plugin at the appropriate spot. This hook is
 * not currently (01-13-2022 version 1.6.6) a part of the official plugin release.
 *
 *   Here's how to do it:
 *
 *   1. Inside the publishpress revisions pro plugin folder, open the file
 *      admin/revision-action_rvy.php
 *
 *   2. Insert the line
 *
 *      do_action('pre_revision_post_type_change', $published->ID, $revision);
 *
 *      immediately before the revision post type gets changed to 'revision'.
 *      In v1.6.6 this would be after line 712
 *
 * @param int $post_id id of the revision post
 * @param int $post_status unused
 * @return void
 */
function revision_applied_update_connected_posts(int $base_post_id = 0, $revision)
{
    log('pre_revision_post_type_change triggered - revision_parent: "' . $base_post_id . '" Revision ID: ' . strval($revision->ID));

    // check if post_to_posts is active. Bail if it isn't
    if (!class_exists('\P2P_Connection_Type_Factory')) {
        return;
    }

    // Get the base post in order to be able to copy the p2p connections from it to the revision post
    $revision_post = $revision;

    if ($revision_post) {
        // Note: The following only works on the assumption
        // that the revision post and the base post are
        // of the same post type. As far as I know,
        // This is always be the case.
        // - hcj

        $revision_post_connections = get_post_connection_instances($revision_post->ID);

        if ($base_post_id > 0) {
            $base_post = \get_post($base_post_id);
        }

        if (isset($base_post)) {

            $r = get_post($revision_post->ID);
            foreach ($revision_post_connections as $revision_post_connection) {
                // get the posts connected to the revision post
                $revision_connected_posts = get_posts(array(
                'connected_type' => $revision_post_connection->name,
                'connected_items' => $revision_post->ID,
                'nopaging' => true,
                'suppress_filters' => false,
                ));

                // get the posts connected to the base post
                $base_connected_posts = get_posts(array(
                    'connected_type' => $revision_post_connection->name,
                    'connected_items' => $base_post,
                    'nopaging' => true,
                    'suppress_filters' => false
                ));

                // remove the now outdated base post connections
                foreach ($base_connected_posts as $base_connected_post) {
                    p2p_type($revision_post_connection->name)->disconnect($base_post->ID, $base_connected_post->ID);
                }

                // Copy the now current and approved posts connections of the revision post to the base post
                foreach ($revision_connected_posts as $connected_post) {
                    $new_connection_id = p2p_type($revision_post_connection->name)->connect($base_post->ID, $connected_post->ID);

                    // also copy over post connection meta for projects
                    if ($revision_post_connection->name == 'contacts_to_projects' && is_int($new_connection_id) ) {
                        $project_meta = p2p_get_meta($connected_post->p2p_id, 'content_roles');
                        if ($project_meta) {
                            foreach ($project_meta as $project_meta_item) {
                                p2p_add_meta($new_connection_id, 'content_roles', $project_meta_item);
                            }
                        }
                    }
                }
            }
        }
    }
}

add_action('pre_revision_post_type_change', __NAMESPACE__ . '\revision_applied_update_connected_posts', 10, 2);

// Temporary logging hooks for debugging

function revision_published($base_post_id, $revision_id)
{
    log("revision_published fired. Base post id: " . $base_post_id . " - Revision post id: " . $revision_id);
}

add_action('revision_published', __NAMESPACE__ . '\revision_published', 10, 2);

function revision_applied($base_post_id, $revision)
{
    log("revision_applied fired. Base post id: " . $base_post_id . " - Revision post id: " . $revision->ID);
}

add_action('revision_applied', __NAMESPACE__ . '\revision_applied', 10, 2);

function unsubmitted_revision_updated(int $post_ID, \WP_Post $post, bool $update)
{
    // do nothing on autosave
    if (wp_is_post_autosave($post)) {
        log('Autosaved "' . $post->post_title . '" ID: ' . strval($post->ID));
        return $post_ID;
    }

    // do nothing for WordPress revisions
    if (\wp_is_post_revision($post)) {
        return $post_ID;
    }

    if ($update && \get_post_mime_type($post) == 'draft-revision') {
        log('Updated unsubmitted revision of "' . $post->post_title . '" ID: ' . strval($post->ID));
    }
}

add_action('wp_insert_post', __NAMESPACE__ . '\unsubmitted_revision_updated', 10, 3);

function submitted_revision_updated(int $post_ID, \WP_Post $post, bool $update)
{
    // do nothing on autosave
    if (wp_is_post_autosave($post)) {
        log('Autosaved "' . $post->post_title . '" ID: ' . strval($post->ID));
        return $post_ID;
    }

    // do nothing for WordPress revisions
    if (\wp_is_post_revision($post)) {
        return $post_ID;
    }

    if ($update && \get_post_mime_type($post) == 'pending-revision') {
        log('Updated submitted revision of "' . $post->post_title . '" ID: ' . strval($post->ID));
    }
}

add_action('wp_insert_post', __NAMESPACE__ . '\submitted_revision_updated', 10, 3);

function future_revision_updated(int $post_ID, \WP_Post $post, bool $update)
{
    // do nothing on autosave
    if (wp_is_post_autosave($post)) {
        log('Autosaved "' . $post->post_title . '" ID: ' . strval($post->ID));
        return $post_ID;
    }

    // do nothing for WordPress revisions
    if (\wp_is_post_revision($post)) {
        return $post_ID;
    }

    if ($update && \get_post_mime_type($post) == 'future-revision') {
        log('Updated future revision of "' . $post->post_title . '" ID: ' . strval($post->ID));
    }
}

add_action('wp_insert_post', __NAMESPACE__ . '\future_revision_updated', 10, 3);

function pp_revision_submitted($revision_parent, $revision_id)
{
    log('pp_revision_submitted triggered "' . $revision_parent . '" Revision ID: ' . strval($revision_id));
}

add_action('revision_submitted', __NAMESPACE__ . '\pp_revision_submitted', 10, 2);

// Utility and debug

if (! function_exists(__NAMESPACE__ . '\log')) {
    function log($entry, $mode = 'a', $file = 'prototype')
    {
        if (defined('WP_ENV') && WP_ENV == 'development') {

            // Get WordPress uploads directory.
            $upload_dir = wp_upload_dir();
            $upload_dir = $upload_dir['basedir'];

            // If the entry is array, json_encode.
            if (is_array($entry)) {
                $entry = json_encode($entry);
            }
            // Write the log file.
            $file  = $upload_dir . '/' . $file . '.log';
            $file  = fopen($file, $mode);
            $bytes = fwrite($file, current_time('mysql') . "::" . $entry . "\n");
            fclose($file);

            return $bytes;
        } else {
            return current_time('mysql') . "::" . $entry;
        }
    }
}
/**
 * Get all posts to posts connection names that have the post
 * given by $post_id's post type in either the 'from' or 'to'
 * end of the connection.
 *
 * note: this only works on many-to-many connections
 *
 * @param int $post_id post id of revision post
 * @return array of posts to posts connection instances or empty
 */
function get_post_connection_instances(int $post_id) {
    $revision_post_connections = array();
    $revision_post_type = \get_post_type($post_id);

    if (class_exists('\P2P_Connection_Type_Factory')) {
        $connection_instances = \P2P_Connection_Type_Factory::get_all_instances();

        foreach ($connection_instances as $connection_instance) {
            // Get from and to post types
            $to_post_type = $connection_instance->side["to"]->query_vars["post_type"][0];
            $from_post_type = $connection_instance->side["from"]->query_vars["post_type"][0];

            // if either match $revision_post post type - note: this only works on many-to-many connections
            if ($to_post_type == $revision_post_type || $from_post_type == $revision_post_type) {
                // store its name in a $revision_post_connections[]
                $revision_post_connections[] = $connection_instance;
            }
        }
    }

    return $revision_post_connections;
}
