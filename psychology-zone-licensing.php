<?php
/**
 * Plugin Name: Psychology Zone Licensing System
 * Description: Student and School licensing management system with separate dashboards
 * Version: 1.0.0
 * Author: Your Name
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PZ_LICENSE_VERSION', '1.0.0');
define('PZ_LICENSE_PATH', plugin_dir_path(__FILE__));
define('PZ_LICENSE_URL', plugin_dir_url(__FILE__));

/**
 * Main Plugin Class
 */
class PZ_License_System {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Initialize plugin
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Register shortcode for package display
        add_shortcode('pz_license_packages', array($this, 'render_packages'));
        
        // Register custom post type for licenses
        add_action('init', array($this, 'register_license_post_type'));
        
        // Create custom database tables
        register_activation_hook(__FILE__, array($this, 'create_tables'));
        
        // AJAX handlers
        add_action('wp_ajax_pz_checkout_school', array($this, 'handle_school_checkout'));
        add_action('wp_ajax_nopriv_pz_checkout_school', array($this, 'handle_school_checkout'));
        add_action('wp_ajax_pz_checkout_student', array($this, 'handle_student_checkout'));
        add_action('wp_ajax_nopriv_pz_checkout_student', array($this, 'handle_student_checkout'));
        
        // Add rewrite rules for custom dashboards
        add_action('init', array($this, 'add_rewrite_rules'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_action('template_redirect', array($this, 'handle_dashboard_access'));
    }
    
    public function init() {
        // Register user roles
        $this->register_user_roles();
    }
    
    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // School licenses table
        $table_school = $wpdb->prefix . 'pz_school_licenses';
        $sql1 = "CREATE TABLE IF NOT EXISTS $table_school (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            school_name varchar(255) NOT NULL,
            school_email varchar(255) NOT NULL,
            license_key varchar(100) NOT NULL,
            status varchar(20) DEFAULT 'active',
            start_date datetime DEFAULT CURRENT_TIMESTAMP,
            end_date datetime NOT NULL,
            max_students int(11) DEFAULT 9999,
            max_teachers int(11) DEFAULT 9999,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY license_key (license_key),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        // Student licenses table
        $table_student = $wpdb->prefix . 'pz_student_licenses';
        $sql2 = "CREATE TABLE IF NOT EXISTS $table_student (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            license_key varchar(100) NOT NULL,
            status varchar(20) DEFAULT 'active',
            start_date datetime DEFAULT CURRENT_TIMESTAMP,
            end_date datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY license_key (license_key),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        // School members table (teachers and students under school license)
        $table_members = $wpdb->prefix . 'pz_school_members';
        $sql3 = "CREATE TABLE IF NOT EXISTS $table_members (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            school_license_id bigint(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            member_type varchar(20) NOT NULL,
            email varchar(255) NOT NULL,
            status varchar(20) DEFAULT 'active',
            invited_at datetime DEFAULT CURRENT_TIMESTAMP,
            joined_at datetime,
            PRIMARY KEY (id),
            KEY school_license_id (school_license_id),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    public function register_user_roles() {
        // Add custom capabilities to existing roles or create new ones
        if (!get_role('pz_school_admin')) {
            add_role('pz_school_admin', 'School Administrator', array(
                'read' => true,
                'pz_manage_school' => true,
                'pz_add_teachers' => true,
                'pz_add_students' => true,
            ));
        }
        
        if (!get_role('pz_teacher')) {
            add_role('pz_teacher', 'Teacher', array(
                'read' => true,
                'pz_access_materials' => true,
                'pz_view_students' => true,
            ));
        }
        
        if (!get_role('pz_student')) {
            add_role('pz_student', 'Student', array(
                'read' => true,
                'pz_access_materials' => true,
            ));
        }
    }
    
    public function register_license_post_type() {
        // This can be used for storing license-related content if needed
    }
    
    public function enqueue_scripts() {
        wp_enqueue_style('pz-license-styles', PZ_LICENSE_URL . 'assets/css/styles.css', array(), PZ_LICENSE_VERSION);
        wp_enqueue_script('pz-license-scripts', PZ_LICENSE_URL . 'assets/js/scripts.js', array('jquery'), PZ_LICENSE_VERSION, true);
        
        wp_localize_script('pz-license-scripts', 'pzLicense', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pz_license_nonce'),
            'checkout_url' => home_url('/pz-checkout/'),
        ));
    }
    
    public function add_rewrite_rules() {
        add_rewrite_rule('^pz-checkout/?$', 'index.php?pz_page=checkout', 'top');
        add_rewrite_rule('^school-dashboard/?$', 'index.php?pz_page=school_dashboard', 'top');
        add_rewrite_rule('^student-dashboard/?$', 'index.php?pz_page=student_dashboard', 'top');
        add_rewrite_rule('^teacher-dashboard/?$', 'index.php?pz_page=teacher_dashboard', 'top');
    }
    
    public function add_query_vars($vars) {
        $vars[] = 'pz_page';
        $vars[] = 'package';
        return $vars;
    }
    
    public function handle_dashboard_access() {
        $pz_page = get_query_var('pz_page');
        
        if ($pz_page === 'checkout') {
            include PZ_LICENSE_PATH . 'templates/checkout.php';
            exit;
        } elseif ($pz_page === 'school_dashboard') {
            include PZ_LICENSE_PATH . 'templates/school-dashboard.php';
            exit;
        } elseif ($pz_page === 'student_dashboard') {
            include PZ_LICENSE_PATH . 'templates/student-dashboard.php';
            exit;
        } elseif ($pz_page === 'teacher_dashboard') {
            include PZ_LICENSE_PATH . 'templates/teacher-dashboard.php';
            exit;
        }
    }
    
    /**
     * Render package selection interface
     */
    public function render_packages($atts) {
        ob_start();
        include PZ_LICENSE_PATH . 'templates/packages.php';
        return ob_get_clean();
    }
    
    /**
     * Generate unique license key
     */
    public function generate_license_key($prefix = 'PZ') {
        return $prefix . '-' . strtoupper(wp_generate_password(16, false, false));
    }
    
    /**
     * Handle school checkout
     */
    public function handle_school_checkout() {
        check_ajax_referer('pz_license_nonce', 'nonce');
        
        // This will be expanded in the next part
        wp_send_json_success(array(
            'message' => 'School checkout initiated',
            'redirect' => home_url('/pz-checkout/?package=school')
        ));
    }
    
    /**
     * Handle student checkout
     */
    public function handle_student_checkout() {
        check_ajax_referer('pz_license_nonce', 'nonce');
        
        wp_send_json_success(array(
            'message' => 'Student checkout initiated',
            'redirect' => home_url('/pz-checkout/?package=student')
        ));
    }
}

// Initialize the plugin
function pz_license_system() {
    return PZ_License_System::get_instance();
}
pz_license_system();

// Dummy login credentials for development
// School Admin: school@test.com / SchoolPass123!
// Student: student@test.com / StudentPass123!
// Teacher: teacher@test.com / TeacherPass123!
?>