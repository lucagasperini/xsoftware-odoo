<?php

if(!defined("ABSPATH")) die;


if (!class_exists("xs_odoo_options")) :

class xs_odoo_options
{

        private $default = array (
                'conn' => [
                        'url' => 'http://localhost:8069/xmlrpc/2',
                        'db' => 'odoo',
                        'mail' => 'your-mail@email.com',
                        'pass' => 'pass'
                ],
                'cart' => [
                        'payment_term' => 0,
                        'validity_days' => 7,
                        'journal' => 0,
                        'partner_fees' => 0,
                        'confirm_fees' => FALSE,
                        'invoice_report_id' => 0,
                ]
        );


        private $options = array( );

        function __construct()
        {
                add_action('admin_menu', [$this, 'admin_menu']);
                add_action('admin_init', [$this, 'section_menu']);

                $this->options = get_option('xs_options_odoo', $this->default);
        }

        function admin_menu()
        {
                add_submenu_page(
                        'xsoftware',
                        'XSoftware Odoo',
                        'Odoo',
                        'manage_options',
                        'xsoftware_odoo',
                        [$this, 'menu_page']
                );
        }


        public function menu_page()
        {
                if ( !current_user_can( 'manage_options' ) )  {
                        wp_die( __( 'Exit!' ) );
                }




                echo '<div class="wrap">';

                echo '<form action="options.php" method="post">';

                settings_fields('xs_odoo_setting');
                do_settings_sections('xs_odoo');

                submit_button( '', 'primary', 'submit', true, NULL );
                echo '</form>';

                echo '</div>';

        }

        function section_menu()
        {
                register_setting(
                        'xs_odoo_setting',
                        'xs_options_odoo',
                        [$this, 'input']
                );
                add_settings_section(
                        'xs_odoo_section',
                        'Settings',
                        [$this, 'show'],
                        'xs_odoo'
                );
        }

        function show()
        {
                $tab = xs_framework::create_tabs( [
                        'href' => '?page=xsoftware_odoo',
                        'tabs' => [
                                'conn' => 'Connection',
                                'cart' => 'Cart'
                        ],
                        'home' => 'conn',
                        'name' => 'main_tab'
                ]);

                switch($tab) {
                        case 'conn':
                                $this->show_connection();
                                return;
                        case 'cart':
                                $this->show_cart();
                                return;
                }
        }

        function input($input)
        {
                $current = $this->options;

                if(isset($input['conn']) && !empty($input['conn']))
                        foreach($input['conn'] as $key => $value)
                                $current['conn'][$key] = $value;

                if(isset($input['cart']) && !empty($input['cart'])) {
                        foreach($input['cart'] as $key => $value)
                                $current['cart'][$key] = $value;
                        $current['cart']['confirm_fees'] = isset($input['cart']['confirm_fees']);
                }

                return $current;
        }

        function show_connection()
        {
                $options = array(
                        'name' => 'xs_options_odoo[conn][url]',
                        'value' => $this->options['conn']['url'],
                        'echo' => TRUE
                );

                add_settings_field(
                        $options['name'],
                        'Odoo URL',
                        'xs_framework::create_input',
                        'xs_odoo',
                        'xs_odoo_section',
                        $options
                );
                $options = array(
                        'name' => 'xs_options_odoo[conn][db]',
                        'value' => $this->options['conn']['db'],
                        'echo' => TRUE
                );

                add_settings_field(
                        $options['name'],
                        'User Database',
                        'xs_framework::create_input',
                        'xs_odoo',
                        'xs_odoo_section',
                        $options
                );
                $options = array(
                        'name' => 'xs_options_odoo[conn][mail]',
                        'value' => $this->options['conn']['mail'],
                        'echo' => TRUE
                );

                add_settings_field(
                        $options['name'],
                        'User Email',
                        'xs_framework::create_input',
                        'xs_odoo',
                        'xs_odoo_section',
                        $options
                );
                $options = array(
                        'name' => 'xs_options_odoo[conn][pass]',
                        'value' => $this->options['conn']['pass'],
                        'type' => 'password',
                        'echo' => TRUE
                );

                add_settings_field(
                        $options['name'],
                        'User Password',
                        'xs_framework::create_input',
                        'xs_odoo',
                        'xs_odoo_section',
                        $options
                );
        }

        function show_cart()
        {
                $settings = $this->options['cart'];

                $options = array(
                        'name' => 'xs_options_odoo[cart][journal]',
                        'selected' => intval($settings['journal']),
                        'data' => $this->get_journal_list(),
                        'default' => 'Select a journal',
                        'echo' => TRUE
                );

                add_settings_field(
                        $options['name'],
                        'Set journal for online payments',
                        'xs_framework::create_select',
                        'xs_odoo',
                        'xs_odoo_section',
                        $options
                );

                $options = array(
                        'name' => 'xs_options_odoo[cart][partner_fees]',
                        'selected' => intval($settings['partner_fees']),
                        'data' => $this->get_supplier_list(),
                        'default' => 'Select a partner',
                        'echo' => TRUE
                );

                add_settings_field(
                        $options['name'],
                        'Set partner of the fees',
                        'xs_framework::create_select',
                        'xs_odoo',
                        'xs_odoo_section',
                        $options
                );

                $options = array(
                        'name' => 'xs_options_odoo[cart][confirm_fees]',
                        'compare' => $settings['confirm_fees'],
                        'echo' => TRUE
                );

                add_settings_field(
                        $options['name'],
                        'Automatic confirm of fees payment (You cannot edit payment details)',
                        'xs_framework::create_input_checkbox',
                        'xs_odoo',
                        'xs_odoo_section',
                        $options
                );

                $options = array(
                        'name' => 'xs_options_odoo[cart][payment_term]',
                        'selected' => intval($settings['payment_term']),
                        'data' => $this->get_payments_term_list(),
                        'default' => 'Select a payment term',
                        'echo' => TRUE
                );

                add_settings_field(
                        $options['name'],
                        'Set payment term',
                        'xs_framework::create_select',
                        'xs_odoo',
                        'xs_odoo_section',
                        $options
                );

                $options = array(
                        'name' => 'xs_options_odoo[cart][validity_days]',
                        'value' => intval($settings['validity_days']),
                        'echo' => TRUE
                );

                add_settings_field(
                        $options['name'],
                        'Set validity days',
                        'xs_framework::create_input_number',
                        'xs_odoo',
                        'xs_odoo_section',
                        $options
                );

                $options = array(
                        'name' => 'xs_options_odoo[cart][invoice_report_id]',
                        'selected' => intval($settings['invoice_report_id']),
                        'data' => $this->get_report_invoice_list(),
                        'default' => 'Select invoice report type',
                        'echo' => TRUE
                );

                add_settings_field(
                        $options['name'],
                        'Set invoice report type',
                        'xs_framework::create_select',
                        'xs_odoo',
                        'xs_odoo_section',
                        $options
                );

                $options = array(
                        'name' => 'xs_options_odoo[cart][fallback_user]',
                        'selected' => intval($settings['fallback_user']),
                        'data' => $this->get_customer_list(),
                        'default' => 'Select fallback user',
                        'echo' => TRUE
                );

                add_settings_field(
                        $options['name'],
                        'Set fallback user',
                        'xs_framework::create_select',
                        'xs_odoo',
                        'xs_odoo_section',
                        $options
                );
        }

        function get_report_invoice_list()
        {
                global $xs_odoo;

                $offset = $xs_odoo->search_read(
                        'ir.actions.report',
                        [
                                ['model', '=', 'account.invoice']
                        ],
                        ['name']
                );

                foreach($offset as $n => $list) {
                        $return[$list['id']] = $list['name'];
                }

                return $return;
        }

        function get_supplier_list()
        {
                global $xs_odoo;

                $offset = $xs_odoo->search_read(
                        'res.partner',
                        [
                                ['supplier', '=', true]
                        ],
                        ['name']
                );

                foreach($offset as $n => $list) {
                        $return[$list['id']] = $list['name'];
                }

                return $return;
        }

        function get_customer_list()
        {
                global $xs_odoo;

                $offset = $xs_odoo->search_read(
                        'res.partner',
                        [
                                ['customer', '=', true]
                        ],
                        ['name']
                );

                foreach($offset as $n => $list) {
                        $return[$list['id']] = $list['name'];
                }

                return $return;
        }

        function get_journal_list()
        {
                global $xs_odoo;

                $offset = $xs_odoo->search_read('account.journal', [], ['name']);

                foreach($offset as $n => $list) {
                        $return[$list['id']] = $list['name'];
                }

                return $return;
        }


        function get_payments_term_list()
        {
                global $xs_odoo;

                $offset = $xs_odoo->search_read('account.payment.term', [], ['name']);

                foreach($offset as $n => $list) {
                        $return[$list['id']] = $list['name'];
                }

                return $return;
        }

}

endif;

$xs_odoo_options = new xs_odoo_options();

?>