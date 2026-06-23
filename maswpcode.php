<?php

/**
 * Plugin Name: Maswpcode
 * Description: form processing + private page redirect
 * Version:     1.0.2
 * Author:      Brian Flett
 */

// Form processor functionality
function mas_form_processor($form_actions_registrar)
{
    include_once(__DIR__ .  '/form-actions/Mas_Form_Processor.php');
    $form_actions_registrar->register(new Maswpcode\Mas_Form_Processor());
}
add_action('elementor_pro/forms/actions/register', 'mas_form_processor');

// Allow all logged-in users to view private pages regardless of role
function mas_allow_private_page_access($allcaps, $caps, $args)
{
    // Only apply to logged-in users
    if (!is_user_logged_in()) {
        return $allcaps;
    }

    // Check if this is a request to read a private page
    if (isset($args[0]) && $args[0] === 'read_private_pages') {
        $allcaps['read_private_pages'] = true;
    }

    // Also allow reading private posts
    if (isset($args[0]) && $args[0] === 'read_private_posts') {
        $allcaps['read_private_posts'] = true;
    }

    return $allcaps;
}
add_filter('user_has_cap', 'mas_allow_private_page_access', 10, 3);

// Make WordPress query private pages for logged-in users
function mas_include_private_pages_in_query($query)
{
    // Only for logged-in users on the frontend
    if (!is_admin() && is_user_logged_in() && $query->is_main_query()) {
        // If querying for a page, include private status
        if ($query->get('post_type') === 'page' || $query->is_page()) {
            $post_status = $query->get('post_status');
            if (empty($post_status)) {
                $query->set('post_status', array('publish', 'private'));
            } elseif (is_array($post_status) && !in_array('private', $post_status)) {
                $post_status[] = 'private';
                $query->set('post_status', $post_status);
            }
        }
    }
}
add_action('pre_get_posts', 'mas_include_private_pages_in_query');

// Private page redirect functionality - WordPress native approach
function mas_redirect_private_pages_to_login()
{
    // Skip admin, AJAX, REST API, and cron contexts
    if (is_admin() ||
        wp_doing_ajax() ||
        (defined('REST_REQUEST') && REST_REQUEST) ||
        (defined('DOING_CRON') && DOING_CRON)) {
        return;
    }

    // Skip WPO365 authentication flows
    if (isset($_GET['action']) && $_GET['action'] === 'openidredirect') {
        return;
    }

    // Skip Microsoft login callback parameters
    if (isset($_GET['code']) || isset($_GET['state']) || isset($_GET['session_state'])) {
        return;
    }

    // Skip login page itself
    if (strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false) {
        return;
    }

    // Only proceed if user is not logged in
    if (is_user_logged_in()) {
        return;
    }

    global $post, $wpdb;

    // Check if current page/post is private (when WordPress has loaded it)
    if (is_singular() && $post && $post->post_status === 'private') {
        $redirect_url = home_url($_SERVER['REQUEST_URI']);
        $login_url = wp_login_url($redirect_url);
        wp_redirect($login_url);
        exit;
    }

    // For 404 cases, check if the URL path corresponds to a private page
    if (is_404()) {
        $request_uri = trim($_SERVER['REQUEST_URI'], '/');
        if (!empty($request_uri)) {
            // Get the first part of the URL path (the page slug)
            $path_parts = explode('/', $request_uri);
            $page_slug = $path_parts[0];

            // Check if a private page exists with this slug
            $private_page = $wpdb->get_row($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'private' AND post_type = 'page'",
                $page_slug
            ));

            if ($private_page) {
                $redirect_url = home_url($_SERVER['REQUEST_URI']);
                $login_url = wp_login_url($redirect_url);
                wp_redirect($login_url);
                exit;
            }
        }
    }
}
add_action('template_redirect', 'mas_redirect_private_pages_to_login');

// Login page customization using WordPress native hooks
function mas_add_login_message($message)
{
    // Only add message on the login page (not registration, lost password, etc.)
    if (isset($_GET['action'])) {
        return $message;
    }

    $custom_message = '<div class="mas-signin-text" style="background: #f0f6fc; border: 1px solid #c3d4e8; border-radius: 6px; padding: 15px; margin: 20px 0; text-align: center; color: #0f4c75; font-size: 14px; line-height: 1.5;">
        Click the <strong>Sign in with Microsoft</strong> button below.<br>
        On the following screen sign in with your <strong>firstname.lastname@masadvise.org</strong> account.<br>
        Contact <a href="mailto:brian.flett@masadvise.org" style="color: #0078d4; text-decoration: none;">brian.flett@masadvise.org</a> if you do not have an account.
    </div>';

    return $custom_message . $message;
}
add_filter('login_message', 'mas_add_login_message');

// Add minimal styling to login page
function mas_add_login_styles()
{
    ?>
    <style type="text/css">
        /* Hide standard login fields - only show WPO365 button */
        #loginform > p,
        #loginform > label,
        #loginform .user-pass-wrap,
        .login #nav,
        .login #backtoblog {
            display: none !important;
        }

        /* Style the custom message and WPO365 button */
        .mas-signin-text a:hover {
            text-decoration: underline !important;
        }

        .wpo365-button {
            margin-top: 15px;
        }
    </style>
    <?php
}
add_action('login_head', 'mas_add_login_styles');

function modify_category_search_query($query)
{
    if (!is_admin() && $query->is_main_query()) {
        if (isset($_GET['category_search']) && isset($_GET['include_children'])) {
            $category_slug = sanitize_text_field($_GET['category_search']);
            $category = get_category_by_slug($category_slug);

            if ($category) {
                $child_categories = get_term_children($category->term_id, 'category');
                $all_categories = array_merge(array($category->term_id), $child_categories);

                // Debug logging
                // error_log("Category Search Debug:");
                // error_log("Category: " . $category->name . " (ID: " . $category->term_id . ")");
                // error_log("Child categories: " . print_r($child_categories, true));
                // error_log("All categories: " . print_r($all_categories, true));

                // Modify the existing query instead of completely replacing it
                $query->set('tax_query', array(
                    array(
                        'taxonomy' => 'category',
                        'field'    => 'term_id',
                        'terms'    => $all_categories,
                        'operator' => 'IN'
                    )
                ));
                $query->set('post_type', 'post');
                $query->set('post_status', 'publish');
                $query->set('posts_per_page', 10);

                // Clear conflicting query vars that might interfere
                $query->set('p', 0);
                $query->set('page_id', 0);
                $query->set('name', '');
                $query->set('pagename', '');
                $query->set('s', '');

                // Set query flags properly
                $query->is_home = false;
                $query->is_front_page = false;
                $query->is_page = false;
                $query->is_single = false;
                $query->is_archive = true;
                $query->is_category = true;
                $query->is_404 = false;

                // Set the queried object
                $query->queried_object = $category;
                $query->queried_object_id = $category->term_id;
                
                // Ensure query_vars is properly initialized
                if (!is_array($query->query_vars)) {
                    $query->query_vars = array();
                }
            }
        }
    }
}
add_action('pre_get_posts', 'modify_category_search_query');

// Add rewrite rule for category search
function add_category_search_rewrite_rule()
{
    add_rewrite_rule('^category-search/?$', 'index.php?category_search_page=1', 'top');
    add_rewrite_tag('%category_search_page%', '([^&]+)');
}
add_action('init', 'add_category_search_rewrite_rule');

// Handle category search page template
function handle_category_search_template($template)
{
    if (get_query_var('category_search_page')) {
        // Use the index template for now
        return get_home_template();
    }
    return $template;
}
add_filter('template_include', 'handle_category_search_template');

// Prevent 404 status for category search with no results
function prevent_category_search_404($preempt, $wp_query)
{
    if (isset($_GET['category_search']) && isset($_GET['include_children'])) {
        status_header(200);
        return true;
    }
    return $preempt;
}
add_filter('pre_handle_404', 'prevent_category_search_404', 10, 2);

// Ensure wp-api is enqueued on the front-end for any logged-in user, so the
// VC portal widgets always see `wpApiSettings.nonce` for REST cookie auth.
// Without this, non-admin users (admin bar hidden) hit 401 on /wp-json/wp/v2/users/me.
function mas_enqueue_wp_api_for_logged_in_users()
{
    if (is_user_logged_in()) {
        wp_enqueue_script('wp-api');
    }
}
add_action('wp_enqueue_scripts', 'mas_enqueue_wp_api_for_logged_in_users');

// Expose user_meta `civicrm_contact_id` on /wp-json/wp/v2/users/me so the
// VC portal chat + update widgets can read it without an extra round-trip.
function mas_register_civicrm_contact_id_field()
{
    register_rest_field('user', 'civicrm_contact_id', array(
        'get_callback' => function ($user) {
            $id = get_user_meta($user['id'], 'civicrm_contact_id', true);
            return $id !== '' ? (int) $id : null;
        },
        'schema' => array(
            'description' => 'Cached CiviCRM Contact ID for this WP user.',
            'type'        => array('integer', 'null'),
            'context'     => array('edit'),
        ),
    ));
}
add_action('rest_api_init', 'mas_register_civicrm_contact_id_field');

// POST /wp-json/mas/v1/users/me/civicrm-contact-id  body: {contact_id: N}
// Stores the CiviCRM Contact ID for the currently logged-in user. A user can
// only write their own mapping; no privilege escalation via this route.
function mas_register_civicrm_contact_id_route()
{
    register_rest_route('mas/v1', '/users/me/civicrm-contact-id', array(
        'methods'             => 'POST',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
        'args' => array(
            'contact_id' => array(
                'required' => true,
                'type'     => 'integer',
            ),
        ),
        'callback' => function (WP_REST_Request $req) {
            $uid = get_current_user_id();
            $cid = (int) $req->get_param('contact_id');
            if ($cid <= 0) {
                return new WP_Error('invalid_contact_id', 'contact_id must be positive', array('status' => 400));
            }
            update_user_meta($uid, 'civicrm_contact_id', $cid);
            return array('user_id' => $uid, 'civicrm_contact_id' => $cid);
        },
    ));
}
add_action('rest_api_init', 'mas_register_civicrm_contact_id_route');

// Shortcode: [mas_teams_buttons]
//
// Renders the two action buttons for the VC Portal "Microsoft Teams" card:
//   - "Open Teams" -> Microsoft Teams (web)
//   - "Copilot"    -> Microsoft 365 Copilot Chat (the WORK Copilot)
//
// Both links carry the signed-in user's masadvise.org address so Microsoft uses
// their MAS work account instead of defaulting to a personal Microsoft account:
//   - domain_hint=masadvise.org  forces the MAS Entra tenant (always sent).
//   - login_hint=<email>         pre-selects the exact account, but ONLY when the
//                                session email is an @masadvise.org address.
// Pointing "Copilot" at https://m365.cloud.microsoft/chat (not the consumer
// copilot.microsoft.com) is the primary fix -- that endpoint is Entra-only, so
// it can never fall back to a personal MSA. The email comes from the WordPress
// session, which is established via WPO365 Microsoft OAuth.
//
// Attributes (all optional):
//   domain      - tenant domain for domain_hint (default: masadvise.org)
//   teams_url   - base Teams URL    (default: https://teams.microsoft.com/)
//   copilot_url - base Copilot URL  (default: https://m365.cloud.microsoft/chat)
function mas_teams_buttons_shortcode($atts)
{
    $atts = shortcode_atts(array(
        'domain'      => 'masadvise.org',
        'teams_url'   => 'https://teams.microsoft.com/',
        'copilot_url' => 'https://m365.cloud.microsoft/chat',
    ), $atts, 'mas_teams_buttons');

    // domain_hint forces the MAS tenant; login_hint pre-selects the exact account.
    $hint_args = array('domain_hint' => $atts['domain']);

    if (is_user_logged_in()) {
        $email  = wp_get_current_user()->user_email;
        $suffix = '@' . strtolower($atts['domain']);
        if ($email && substr(strtolower($email), -strlen($suffix)) === $suffix) {
            $hint_args['login_hint'] = $email;
        }
    }

    $teams_link   = esc_url(add_query_arg($hint_args, $atts['teams_url']));
    $copilot_link = esc_url(add_query_arg($hint_args, $atts['copilot_url']));

    // Inline styles so the buttons render correctly inside any Elementor widget
    // without depending on the global button kit. Colour matches the portal's
    // existing blue action buttons.
    $btn = 'display:inline-block;margin:6px 4px;padding:10px 24px;border-radius:6px;'
         . 'background:#5e72e4;color:#fff;font-weight:600;text-decoration:none;'
         . 'font-size:14px;line-height:1.2;';

    ob_start();
    ?>
    <div class="mas-teams-buttons" style="text-align:center;">
        <a class="mas-teams-btn" style="<?php echo esc_attr($btn); ?>"
           href="<?php echo $teams_link; ?>" target="_blank" rel="noopener noreferrer">Open Teams</a>
        <a class="mas-copilot-btn" style="<?php echo esc_attr($btn); ?>"
           href="<?php echo $copilot_link; ?>" target="_blank" rel="noopener noreferrer">Copilot</a>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('mas_teams_buttons', 'mas_teams_buttons_shortcode');
