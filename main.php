<?php
/*
Plugin Name: Add Update End of Post Title
Description: Adds month name and "UPDATE" at the end of posts specified in the list.
Version: 1.3
Author: Ramin Mansouri Pouya
Text Domain: update-post-titles
Domain Path: /languages
Website: www.viragraphics.com
*/

// Activation and Deactivation Hooks
register_activation_hook(__FILE__, 'uptm_schedule_monthly_update');
register_deactivation_hook(__FILE__, 'uptm_unschedule_monthly_update');

// Load text domain for translations
add_action('plugins_loaded', 'uptm_load_textdomain');
function uptm_load_textdomain() {
    load_plugin_textdomain('update-post-titles', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

// Schedule the monthly update event
function uptm_schedule_monthly_update() {
    if (!wp_next_scheduled('uptm_update_post_titles')) {
        wp_schedule_event(time(), 'monthly', 'uptm_update_post_titles');
    }
}

// Unschedule the monthly update event
function uptm_unschedule_monthly_update() {
    $timestamp = wp_next_scheduled('uptm_update_post_titles');
    wp_unschedule_event($timestamp, 'uptm_update_post_titles');
}

// Create the settings menu
add_action('admin_menu', 'uptm_create_menu');
function uptm_create_menu() {
    add_menu_page(
        __('Update Post Titles', 'update-post-titles'),
        __('Update Post Titles', 'update-post-titles'),
        'manage_options',
        'uptm-update-post-titles',
        'uptm_settings_page'
    );
}

// Register the settings
add_action('admin_init', 'uptm_register_settings');
function uptm_register_settings() {
    register_setting('uptm-settings-group', 'uptm_post_ids');
}

// Display the settings page
function uptm_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php _e('Manage Post Titles to Update', 'update-post-titles'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('uptm-settings-group');
            do_settings_sections('uptm-settings-group');
            ?>
            <h3><?php _e('Search and Add Post', 'update-post-titles'); ?></h3>
            <input type="text" id="uptm_post_search" name="uptm_post_search" style="width: 100%;" />
            <ul id="uptm_search_results" style="margin-top: 10px; max-height: 150px; overflow-y: auto;"></ul>
            <h3><?php _e('Posts to be updated:', 'update-post-titles'); ?></h3>
            <ul id="uptm_post_list">
                <?php echo uptm_get_saved_posts_html(); ?>
            </ul>
            <input type="hidden" id="uptm_post_ids" name="uptm_post_ids" value="<?php echo esc_attr(uptm_get_saved_post_ids_string()); ?>" />
            <?php submit_button(); ?>
        </form>

        <!-- Button to manually trigger the update function -->
        <form method="post">
            <input type="hidden" name="uptm_manual_update" value="1">
            <?php submit_button(__('Update Titles Now', 'update-post-titles')); ?>
        </form>
    </div>
    <?php
}

// Generate the saved posts list HTML
function uptm_get_saved_posts_html() {
    $saved_post_ids = uptm_get_saved_post_ids();

    $html = '';
    if ($saved_post_ids) {
        foreach ($saved_post_ids as $post_id) {
            $post_title = get_the_title($post_id);
            $html .= '<li>' . esc_html($post_title) . ' <a href="#" class="uptm-remove-post" data-post-id="' . esc_attr($post_id) . '">Remove</a></li>';
        }
    }

    return $html;
}

// Get saved post IDs
function uptm_get_saved_post_ids() {
    $saved_post_ids = get_option('uptm_post_ids', []);
    if (!is_array($saved_post_ids)) {
        $saved_post_ids = explode(',', $saved_post_ids);
    }
    return $saved_post_ids;
}

// Get saved post IDs as a comma-separated string
function uptm_get_saved_post_ids_string() {
    return esc_attr(implode(',', uptm_get_saved_post_ids()));
}

// Handle manual update trigger
add_action('admin_init', 'uptm_handle_manual_update');
function uptm_handle_manual_update() {
    if (isset($_POST['uptm_manual_update'])) {
        uptm_update_post_titles();
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Post titles have been updated.', 'update-post-titles') . '</p></div>';
        });
    }
}

// AJAX live search function
add_action('wp_ajax_uptm_live_search_posts', 'uptm_live_search_posts');
function uptm_live_search_posts() {
    if(!isset($_POST['search_text'])) {
        wp_send_json_error();
    }

    $search_text = sanitize_text_field($_POST['search_text']);
    $args = array(
        'post_type' => 'post',
        's' => $search_text,
        'posts_per_page' => 10,
        'post_status' => 'publish',
    );

    $posts = get_posts($args);
    $results = array();

    if($posts) {
        foreach ($posts as $post) {
            $results[] = array(
                'ID' => $post->ID,
                'post_title' => $post->post_title
            );
        }
        wp_send_json_success($results);
    } else {
        wp_send_json_error();
    }
}

// Update post titles function
add_action('uptm_update_post_titles', 'uptm_update_post_titles');
function uptm_update_post_titles() {
    $post_ids_to_update = uptm_get_saved_post_ids();
    $current_month_i18n = date_i18n('F');

    if (empty($post_ids_to_update)) {
        error_log('No post IDs to update.');
        return;
    }

    foreach ($post_ids_to_update as $post_id) {
        $post = get_post($post_id);
        if ($post) { // Ensure the post exists
            $new_title = preg_replace('/\(.*?\)$/', '', $post->post_title); // Remove old "(...)" if exists
            $new_title .= " (" . sprintf(__('updated in %s', 'update-post-titles'), $current_month_i18n) . ")";
            wp_update_post(array(
                'ID' => $post->ID,
                'post_title' => $new_title
            ));
            error_log('Updated post ID: ' . $post_id . ' with title: ' . $new_title);
        } else {
            error_log('Post ID ' . $post_id . ' does not exist.');
        }
    }
}

// Add custom interval for monthly schedule
add_filter('cron_schedules', 'uptm_add_monthly_cron_schedule');
function uptm_add_monthly_cron_schedule($schedules) {
    $schedules['monthly'] = array(
        'interval' => 30 * DAY_IN_SECONDS, // Approximate monthly interval
        'display' => __('Once Monthly', 'update-post-titles')
    );
    return $schedules;
}

// JavaScript and AJAX for the settings page
add_action('admin_footer', 'uptm_admin_footer_scripts');
function uptm_admin_footer_scripts() {
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#uptm_post_search').on('input', function() {
                var searchText = $(this).val();
                if(searchText) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'uptm_live_search_posts',
                            search_text: searchText
                        },
                        success: function(response) {
                            if(response.success && response.data) {
                                var resultsList = $('#uptm_search_results');
                                resultsList.empty();
                                $.each(response.data, function(index, post) {
                                    resultsList.append('<li><a href="#" class="uptm-add-post" data-post-id="' + post.ID + '" data-post-title="' + post.post_title + '">' + post.post_title + '</a></li>');
                                });
                            } else {
                                $('#uptm_search_results').html('<li><?php _e('No posts found.', 'update-post-titles'); ?></li>');
                            }
                        }
                    });
                } else {
                    $('#uptm_search_results').empty();
                }
            });

            $('#uptm_search_results').on('click', '.uptm-add-post', function(e) {
                e.preventDefault();
                var postId = $(this).data('post-id');
                var postTitle = $(this).data('post-title');
                var postList = $('#uptm_post_list');
                var postIds = $('#uptm_post_ids').val().split(',');

                if(!postIds.includes(postId.toString())) {
                    postList.append('<li>' + postTitle + ' <a href="#" class="uptm-remove-post" data-post-id="' + postId + '">Remove</a></li>');
                    postIds.push(postId);
                    $('#uptm_post_ids').val(postIds.join(','));
                }
            });

            $('#uptm_post_list').on('click', '.uptm-remove-post', function(e) {
                e.preventDefault();
                var postId = $(this).data('post-id');
                var postIds = $('#uptm_post_ids').val().split(',');

                postIds = postIds.filter(function(id) {
                    return id != postId;
                });

                $('#uptm_post_ids').val(postIds.join(','));
                $(this).parent().remove();
            });
        });
    </script>
    <?php
}
?>
