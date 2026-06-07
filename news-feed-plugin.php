<?php
/*
Plugin Name: The News Feed — Auto Post
Plugin URI: https://thenewsfeeds.in
Description: Fetches approved Malayalam news from The News Feed backend and publishes it to WordPress.
Version: 1.0.0
Requires at least: 6.0
Requires PHP: 8.0
Author: darsansraj
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: the-news-feed
*/







if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/settings-page.php';


function tnf_fetch_articles() {


    $backend_url = "https://api.thenewsfeeds.in";
    $api_key = get_option('tnf_api_key');
    $domain = trailingslashit(home_url());
    
    # STARTING
    $response = wp_remote_get(
        $backend_url . '/feed?api_key=' . urlencode($api_key) . '&domain=' . urlencode($domain),
        [
            'timeout' => 15
        ]
    );


    if (is_wp_error($response)) {
        return;
    }

    #ENDING

    $articles = json_decode(wp_remote_retrieve_body($response), true);


    if (!$articles || empty($articles)) {
        return;
    }

    $created_count = 0;
    $successfully_posted_ids = [];

    foreach ($articles as $article) {

        $article_hash = md5(
            strtolower(trim($article['title'])) .
            strtolower(trim($article['published_at']))
        );

        $existing_posts = get_posts([
            'post_type'   => 'post',
            'meta_key'    => 'tnf_article_hash',
            'meta_value'  => $article_hash,
            'numberposts' => 1
        ]);

        if (!empty($existing_posts)) {
            update_option(
                'tnf_last_feed_response',
                'Skipped duplicate article ID: ' . $article['id']
            );
            continue;
        }
        

        $parsed_url = wp_parse_url($article['link']);

        $source_slug = basename(
            $parsed_url['path'] ?? ''
        );

        $scheduled_time = !empty(
            $article['scheduled_publish_time']
        )
            ? strtotime($article['scheduled_publish_time'])
            : current_time('timestamp');

        if (!$scheduled_time) {
            $scheduled_time = current_time('timestamp');
        }

        $post_id = wp_insert_post([
            'post_title'   => $article['title'],
            'post_content' => wp_kses_post($article['content']),
            'post_status'  => 'future',
            'post_date' => wp_date(
                'Y-m-d H:i:s',
                $scheduled_time
            ),
            'post_date_gmt' => gmdate(
                'Y-m-d H:i:s',
                $scheduled_time
            ),
            'post_type'    => 'post',
            'post_name'    => $source_slug
        ]);


        if (!is_wp_error($post_id) && $post_id) {

            if (!empty($article['category'])) {

                $category_map = get_option('tnf_category_map', []);
                $backend_category = $article['category'];

                if (!empty($category_map[$backend_category])) {

                    wp_set_post_categories(
                        $post_id,
                        [(int)$category_map[$backend_category]]
                    );

                } else {

                    $term = term_exists($backend_category, 'category');

                    if (!$term) {
                        $term = wp_insert_term($backend_category, 'category');
                    }

                    if (!is_wp_error($term)) {
                        wp_set_post_categories($post_id, [(int)$term['term_id']]);
                    }
                }
            }

            update_post_meta(
                $post_id,
                'tnf_article_hash',
                $article_hash
            );


            if (!empty($article['image_url'])) {

                $image_id = media_sideload_image(
                    $article['image_url'],
                    $post_id,
                    null,
                    'id'
                );

                if (!is_wp_error($image_id)) {
                    set_post_thumbnail($post_id, $image_id);
                }
            }
            
            
            $successfully_posted_ids[] = $article['id'];
            
            $created_count++;
        }
    }

    update_option(
        'tnf_last_feed_response',
        "Successfully created {$created_count} post(s)."
    );
    
    if (!empty($successfully_posted_ids)) {

        wp_remote_post(
            $backend_url . '/ack-delivery',
            [
                'timeout' => 15,
                'headers' => [
                    'Content-Type' => 'application/json'
                ],
                'body' => wp_json_encode([
                    'api_key' => $api_key,
                    'article_ids' => $successfully_posted_ids
                ])
            ]
        );
    }
}


add_action('tnf_fetch_articles_cron', 'tnf_fetch_articles');

add_filter('cron_schedules', 'tnf_add_cron_interval');


register_activation_hook(__FILE__, 'tnf_schedule_cron');
register_deactivation_hook(__FILE__, 'tnf_unschedule_cron');


function tnf_add_cron_interval($schedules) {
    $schedules['thirty_minutes'] = [
        'interval' => 1800,      # change to 1800 for 30 minutes in production
        'display'  => 'Every 30 Minutes'
    ];

    return $schedules;
}


function tnf_schedule_cron() {
    if (!wp_next_scheduled('tnf_fetch_articles_cron')) {
        wp_schedule_event(time(), 'thirty_minutes', 'tnf_fetch_articles_cron');
    }
}


function tnf_unschedule_cron() {
    $timestamp = wp_next_scheduled('tnf_fetch_articles_cron');

    if ($timestamp) {
        wp_unschedule_event($timestamp, 'tnf_fetch_articles_cron');
    }
}
