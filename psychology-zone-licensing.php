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
        add_action('wp_ajax_pz_process_checkout', array($this, 'process_checkout'));
        add_action('wp_ajax_nopriv_pz_process_checkout', array($this, 'process_checkout'));
        
        // Add rewrite rules for custom dashboards
        add_action('init', array($this, 'add_rewrite_rules'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_action('template_redirect', array($this, 'handle_dashboard_access'));
        
        // Payment gateway hooks
        add_action('init', array($this, 'handle_payment_return'));
        
        // WooCommerce order status hooks
        add_action('woocommerce_order_status_completed', array($this, 'wc_order_completed'), 10, 1);
        add_action('woocommerce_payment_complete', array($this, 'wc_payment_complete'), 10, 1);
        
        // Admin notice for rewrite rules flush
        add_action('admin_notices', array($this, 'activation_notice'));
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
        
        // Set a flag to show admin notice
        set_transient('pz_activation_notice', true, 30);
        
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
     * Admin notice for activation
     */
    public function activation_notice() {
        if (get_transient('pz_activation_notice')) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>Psychology Zone Licensing:</strong> Plugin activated successfully! Please go to <strong>Settings → Permalinks</strong> and click "Save Changes" to ensure custom URLs work correctly.</p>
            </div>
            <?php
            delete_transient('pz_activation_notice');
        }
    }
    
    /**
     * Process checkout - Main AJAX handler
     */
    public function process_checkout() {
        // Verify nonce
        if (!isset($_POST['pz_nonce']) || !wp_verify_nonce($_POST['pz_nonce'], 'pz_checkout')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $package_type = sanitize_text_field($_POST['package_type']);
        $payment_method = sanitize_text_field($_POST['payment_method']);
        
        if ($package_type === 'school') {
            $this->process_school_checkout($_POST, $payment_method);
        } else {
            $this->process_student_checkout($_POST, $payment_method);
        }
    }
    
    /**
     * Process school checkout
     */
    private function process_school_checkout($data, $payment_method) {
        global $wpdb;
        
        // Check if creating new account or using existing
        $is_new_account = !empty($data['school_email']);
        
        if ($is_new_account) {
            // Validate new account data
            $school_name = sanitize_text_field($data['school_name']);
            $school_email = sanitize_email($data['school_email']);
            $password = $data['school_password'];
            $contact_person = sanitize_text_field($data['contact_person']);
            
            // Check if email already exists
            if (email_exists($school_email)) {
                wp_send_json_error(array('message' => 'This email is already registered. Please use the "I Have an Account" tab.'));
            }
            
            // Create user account
            $user_id = wp_create_user($school_email, $password, $school_email);
            
            if (is_wp_error($user_id)) {
                wp_send_json_error(array('message' => $user_id->get_error_message()));
            }
            
            // Update user meta
            wp_update_user(array(
                'ID' => $user_id,
                'display_name' => $contact_person,
                'first_name' => $contact_person
            ));
            
            // Set user role
            $user = new WP_User($user_id);
            $user->set_role('pz_school_admin');
            
        } else {
            // Existing account
            $existing_email = sanitize_email($data['existing_email']);
            $existing_password = $data['existing_password'];
            
            // Authenticate user
            $user = wp_authenticate($existing_email, $existing_password);
            
            if (is_wp_error($user)) {
                wp_send_json_error(array('message' => 'Invalid email or password'));
            }
            
            $user_id = $user->ID;
            $school_name = sanitize_text_field($data['assign_school_name']);
        }
        
        // Generate license key
        $license_key = $this->generate_license_key('SCH');
        
        // Create pending license (will be activated after payment)
        $end_date = date('Y-m-d H:i:s', strtotime('+1 year'));
        
        $wpdb->insert(
            $wpdb->prefix . 'pz_school_licenses',
            array(
                'user_id' => $user_id,
                'school_name' => $school_name,
                'school_email' => $is_new_account ? $school_email : $existing_email,
                'license_key' => $license_key,
                'status' => 'pending',
                'end_date' => $end_date
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s')
        );
        
        $license_id = $wpdb->insert_id;
        
        // Store license info in session for payment processing
        if (!session_id()) {
            session_start();
        }
        $_SESSION['pz_pending_license'] = array(
            'license_id' => $license_id,
            'user_id' => $user_id,
            'type' => 'school',
            'amount' => 199.00
        );
        
        // Process payment
        $payment_url = $this->create_payment_url($payment_method, 'school', $license_id);
        
        wp_send_json_success(array(
            'payment_url' => $payment_url,
            'message' => 'Account created successfully. Redirecting to payment...'
        ));
    }
    
    /**
     * Process student checkout
     */
    private function process_student_checkout($data, $payment_method) {
        global $wpdb;
        
        $is_new_account = !empty($data['student_email']);
        
        if ($is_new_account) {
            $student_email = sanitize_email($data['student_email']);
            $password = $data['student_password'];
            $first_name = sanitize_text_field($data['first_name']);
            $last_name = sanitize_text_field($data['last_name']);
            
            if (email_exists($student_email)) {
                wp_send_json_error(array('message' => 'This email is already registered. Please use the "I Have an Account" tab.'));
            }
            
            $user_id = wp_create_user($student_email, $password, $student_email);
            
            if (is_wp_error($user_id)) {
                wp_send_json_error(array('message' => $user_id->get_error_message()));
            }
            
            wp_update_user(array(
                'ID' => $user_id,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'display_name' => $first_name . ' ' . $last_name
            ));
            
            $user = new WP_User($user_id);
            $user->set_role('pz_student');
            
        } else {
            $existing_email = sanitize_email($data['existing_email']);
            $existing_password = $data['existing_password'];
            
            $user = wp_authenticate($existing_email, $existing_password);
            
            if (is_wp_error($user)) {
                wp_send_json_error(array('message' => 'Invalid email or password'));
            }
            
            $user_id = $user->ID;
        }
        
        $license_key = $this->generate_license_key('STU');
        $end_date = date('Y-m-d H:i:s', strtotime('+1 year'));
        
        $wpdb->insert(
            $wpdb->prefix . 'pz_student_licenses',
            array(
                'user_id' => $user_id,
                'license_key' => $license_key,
                'status' => 'pending',
                'end_date' => $end_date
            ),
            array('%d', '%s', '%s', '%s')
        );
        
        $license_id = $wpdb->insert_id;
        
        if (!session_id()) {
            session_start();
        }
        $_SESSION['pz_pending_license'] = array(
            'license_id' => $license_id,
            'user_id' => $user_id,
            'type' => 'student',
            'amount' => 49.99
        );
        
        $payment_url = $this->create_payment_url($payment_method, 'student', $license_id);
        
        wp_send_json_success(array(
            'payment_url' => $payment_url,
            'message' => 'Account created successfully. Redirecting to payment...'
        ));
    }
    
    /**
     * Create payment URL using WooCommerce payment gateways
     */
    private function create_payment_url($payment_method, $package_type, $license_id) {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            // Fallback: create simple payment page
            return home_url('/pz-payment/?method=' . $payment_method . '&type=' . $package_type . '&license=' . $license_id);
        }
        
        // Create a WooCommerce order
        $order = wc_create_order();
        
        // Add product to order
        $product_name = ($package_type === 'school') ? 'School Licence' : 'Student Package';
        $amount = ($package_type === 'school') ? 199.00 : 49.99;
        
        $order->add_product(
            null,
            1,
            array(
                'name' => $product_name,
                'total' => $amount
            )
        );
        
        // Set order meta
        $order->update_meta_data('_pz_license_id', $license_id);
        $order->update_meta_data('_pz_package_type', $package_type);
        
        // Set billing email
        if (!session_id()) {
            session_start();
        }
        
        if (isset($_SESSION['pz_pending_license']['user_id'])) {
            $user = get_user_by('id', $_SESSION['pz_pending_license']['user_id']);
            if ($user) {
                $order->set_billing_email($user->user_email);
            }
        }
        
        // Calculate totals
        $order->calculate_totals();
        
        // Set payment method
        $order->set_payment_method($payment_method);
        
        $order->save();
        
        // Return checkout payment URL
        return $order->get_checkout_payment_url();
    }
    
    /**
     * Handle payment return
     */
    public function handle_payment_return() {
        if (isset($_GET['pz_payment_complete']) && isset($_GET['order_id'])) {
            $order_id = absint($_GET['order_id']);
            $order = wc_get_order($order_id);
            
            if ($order && $order->is_paid()) {
                $license_id = $order->get_meta('_pz_license_id');
                $package_type = $order->get_meta('_pz_package_type');
                
                // Activate license
                $this->activate_license($license_id, $package_type);
                
                // Log user in and redirect to dashboard
                if (!session_id()) {
                    session_start();
                }
                
                if (isset($_SESSION['pz_pending_license']['user_id'])) {
                    wp_set_auth_cookie($_SESSION['pz_pending_license']['user_id']);
                    
                    // Redirect to appropriate dashboard
                    if ($package_type === 'school') {
                        wp_redirect(home_url('/school-dashboard/'));
                    } else {
                        wp_redirect(home_url('/student-dashboard/'));
                    }
                    exit;
                }
            }
        }
    }
    
    /**
     * Activate license after payment
     */
    private function activate_license($license_id, $package_type) {
        global $wpdb;
        
        if ($package_type === 'school') {
            $wpdb->update(
                $wpdb->prefix . 'pz_school_licenses',
                array('status' => 'active'),
                array('id' => $license_id),
                array('%s'),
                array('%d')
            );
        } else {
            $wpdb->update(
                $wpdb->prefix . 'pz_student_licenses',
                array('status' => 'active'),
                array('id' => $license_id),
                array('%s'),
                array('%d')
            );
        }
    }
    
    /**
     * WooCommerce payment complete hook
     */
    public function wc_payment_complete($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $license_id = $order->get_meta('_pz_license_id');
        $package_type = $order->get_meta('_pz_package_type');
        
        if ($license_id && $package_type) {
            $this->activate_license($license_id, $package_type);
            
            // Send welcome email
            $this->send_welcome_email($license_id, $package_type);
        }
    }
    
    /**
     * WooCommerce order completed hook
     */
    public function wc_order_completed($order_id) {
        $this->wc_payment_complete($order_id);
    }
    
    /**
     * Send welcome email after activation
     */
    private function send_welcome_email($license_id, $package_type) {
        global $wpdb;
        
        if ($package_type === 'school') {
            $license = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}pz_school_licenses WHERE id = %d",
                $license_id
            ));
            
            if ($license) {
                $user = get_user_by('id', $license->user_id);
                $dashboard_url = home_url('/school-dashboard/');
                
                $subject = 'Welcome to Psychology Zone - School Licence Activated';
                $message = "
                    <h2>Welcome to Psychology Zone!</h2>
                    <p>Your School Licence has been activated successfully.</p>
                    <p><strong>School Name:</strong> {$license->school_name}</p>
                    <p><strong>License Key:</strong> {$license->license_key}</p>
                    <p><strong>Login Email:</strong> {$license->school_email}</p>
                    <p><strong>Valid Until:</strong> " . date('F j, Y', strtotime($license->end_date)) . "</p>
                    <p><a href='{$dashboard_url}' style='background: #E94B3C; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block;'>Access Your Dashboard</a></p>
                    <p>You can now add teachers and students to your school.</p>
                ";
                
                $this->send_html_email($license->school_email, $subject, $message);
            }
        } else {
            $license = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}pz_student_licenses WHERE id = %d",
                $license_id
            ));
            
            if ($license) {
                $user = get_user_by('id', $license->user_id);
                $dashboard_url = home_url('/student-dashboard/');
                
                $subject = 'Welcome to Psychology Zone - Student Package Activated';
                $message = "
                    <h2>Welcome to Psychology Zone!</h2>
                    <p>Your Student Package has been activated successfully.</p>
                    <p><strong>License Key:</strong> {$license->license_key}</p>
                    <p><strong>Valid Until:</strong> " . date('F j, Y', strtotime($license->end_date)) . "</p>
                    <p><a href='{$dashboard_url}' style='background: #4A90E2; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block;'>Access Your Dashboard</a></p>
                    <p>You now have access to all study materials and resources.</p>
                ";
                
                $this->send_html_email($user->user_email, $subject, $message);
            }
        }
    }
    
    /**
     * Send HTML email
     */
    private function send_html_email($to, $subject, $message) {
        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($to, $subject, $message, $headers);
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