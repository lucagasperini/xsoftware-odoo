<?php
if(!defined("ABSPATH")) die;

if (!class_exists("xs_users_odoo")) :

class xs_users_odoo
{

        function __construct()
        {
                add_action('xs_user_register', [$this, 'user_register'], 1, 2);
        }

        function user_register($user_id, $values)
        {
                global $xs_odoo;

                $user_data = get_userdata($user_id);

                if(!empty($values['first_name']) && !empty($values['last_name']))
                        $name = $values['first_name'] . ' ' . $values['last_name'];
                else
                        $name = $user_data->display_name;

                $email = $user_data->user_email;

                $partner_id = $xs_odoo->create(
                'res.partner',
                [
                        [
                                'name' => $name,
                                'email' => $email
                        ]
                ]);

                update_user_meta( $user_id, 'xs_odoo_partner_id', $partner_id[0]);
        }
}

$xs_users_odoo = new xs_users_odoo();

endif;

?>
