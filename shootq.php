<?php
/*
Plugin Name: Gravity Forms ShootQ Add-On
Plugin URI: http://www.pussycatintimates.com/gravity-forms-shootq-add-on/
Description: Connects your Gravity Forms to your ShootQ account for collecting leads.
Version: 1.0
Author: pussycatdev
Author URI: http://www.pussycatintimates.com

------------------------------------------------------------------------
Copyright 2011 Pussycat Intimate Portraiture

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

add_action('init',  array('GFShootQ', 'init'));
register_activation_hook( __FILE__, array("GFShootQ", "add_permissions"));

class GFShootQ {

    private static $version = "1.0";
    private static $min_gravityforms_version = "1.5";

    //Plugin starting point. Will load appropriate files
    public static function init(){

        //if gravity forms version is not supported, abort
        if(!self::is_gravityforms_supported()){
           return;
        }

        if(is_admin()){
            //loading translations
            load_plugin_textdomain('gravityformsshootq', FALSE, '/gravityforms_shootq/languages' );

            //creates a new Settings page on Gravity Forms' settings screen
            if(self::has_access("gravityforms_shootq")){
                RGForms::add_settings_page("ShootQ", array("GFShootQ", "settings_page"), self::get_base_url() . "/images/shootq_wordpress_icon_32.png");
            }
        }

        //integrating with Members plugin
        if(function_exists('members_get_capabilities'))
            add_filter('members_get_capabilities', array("GFShootQ", "members_get_capabilities"));

        //creates the subnav left menu
        add_filter("gform_addon_navigation", array('GFShootQ', 'create_menu'));
		//adds Settings link in the plugins list
		add_filter('plugin_action_links', array('GFShootQ', 'plugin_settings_link'), 10, 2);

        //loading Gravity Forms tooltips
        require_once(GFCommon::get_base_path() . "/tooltips.php");
        add_filter('gform_tooltips', array('GFShootQ', 'tooltips'));

        if(self::is_shootq_page()){

            //loading data lib
            require_once(self::get_base_path() . "/data.php");

            //runs the setup when version changes
            self::setup();

        }
        else if(in_array(RG_CURRENT_PAGE, array("admin-ajax.php"))){

            //loading data class
            require_once(self::get_base_path() . "/data.php");

            //Hooks for AJAX operations.
            //1- When clicking the active/inactive icon on the Feed list page
            add_action('wp_ajax_rg_update_feed_active', array('GFShootQ', 'update_feed_active'));

            //2- When selecting a form on the feed edit page
            add_action('wp_ajax_gf_select_shootq_form', array('GFShootQ', 'select_shootq_form'));

        }
        else{
             //Handling post submission. This is where the integration will happen (will get fired right after the form gets submitted)
            add_action("gform_post_submission", array('GFShootQ', 'export'), 10, 2);
        }

    }

    public static function update_feed_active(){
        check_ajax_referer('rg_update_feed_active','rg_update_feed_active');
        $id = rgpost("feed_id");
        $feed = GFShootQData::get_feed($id);
        GFShootQData::update_feed($id, $feed["form_id"], rgpost("is_active"), $feed["meta"]);
    }


    //Returns true if the current page is an Feed pages. Returns false if not
    private static function is_shootq_page(){
        $current_page = trim(strtolower(rgget("page")));
        $shootq_pages = array("gf_shootq");

        return in_array($current_page, $shootq_pages);
    }

    //Creates or updates database tables. Will only run when version changes
    private static function setup(){

        if(get_option("gf_shootq_version") != self::$version)
            GFShootQData::update_table();

        update_option("gf_shootq_version", self::$version);
    }

    //Adds feed tooltips to the list of tooltips
    public static function tooltips($tooltips){
        $shootq_tooltips = array(
            "shootq_gravity_form" => "<h6>" . __("Gravity Form", "gravityformsshootq") . "</h6>" . __("Select the Gravity Form you would like to integrate with ShootQ.", "gravityformsshootq"),
            "shootq_api" => "<h6>" . __("ShootQ API Key", "gravityformsshootq") . "</h6>" . __("Enter the ShootQ API Key associated with your account.", "gravityformsshootq"),
            "shootq_brand" => "<h6>" . __("ShootQ Brand Abbreviation", "gravityformsshootq") . "</h6>" . __("Enter the ShootQ Brand Abbreviation you wish to connect to.", "gravityformsshootq"),
            "shootq_mapping" => "<h6>" . __("Field Mapping", "gravityformsshootq") . "</h6>" . __("Map your Form Fields to the available ShootQ contact fields. Fields in red are required by ShootQ.", "gravityformsshootq")

        );
        return array_merge($tooltips, $shootq_tooltips);
    }

    //Creates ShootQ left nav menu under Forms
    public static function create_menu($menus){

        // Adding submenu if user has access
        $permission = self::has_access("gravityforms_shootq");
        if(!empty($permission))
            $menus[] = array("name" => "gf_shootq", "label" => __("ShootQ", "gravityformsshootq"), "callback" =>  array("GFShootQ", "shootq_page"), "permission" => $permission);

        return $menus;
    }

    public static function settings_page(){

        if(!rgempty("uninstall")){
            check_admin_referer("uninstall", "gf_shootq_uninstall");
            self::uninstall();

            ?>
            <div class="updated fade" style="padding:20px;"><?php _e(sprintf("Gravity Forms ShootQ Add-On have been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>","</a>"), "gravityformsshootq")?></div>
            <?php
            return;
        }
        else if(!rgempty("gf_shootq_submit")){
            check_admin_referer("update", "gf_shootq_update");
            $settings = array("apikey" => rgpost("gf_shootq_apikey"), "brand" => rgpost("gf_shootq_brand"));

            update_option("gf_shootq_settings", $settings);
			?>
			<div class="updated fade" style="padding:6px"><?php echo sprintf(__("Your ShootQ Settings have been saved. Now you can %sconfigure a new feed%s!", "gravityformsshootq"), "<a href='?page=gf_shootq&view=edit&id=0'>", "</a>") ?></div>
			<?php
        }
        else{
            $settings = get_option("gf_shootq_settings");
        }

        ?>

        <form method="post" action="">
            <?php wp_nonce_field("update", "gf_shootq_update") ?>
            <h3><?php _e("ShootQ Settings", "gravityformsshootq") ?></h3>
			
			<p style="text-align: left;"><?php _e("Here is where you will connect your ShootQ account to the plugin. To find your API Key and Brand Abbreviation, visit the Public API page from the bottom of your Settings tab on ShootQ (You can", "gravityformsshootq") ?> <a href="https://app.shootq.com/controlpanels/integrations/api" title="Go to the Public API page on ShootQ" target="_blank"><?php _e("head straight there", "gravityformsshootq") ?></a> <?php _e("if you are already logged in.) Copy the API Key and Brand Abbreviation into their respecive fields below.", "gravityformsshootq") ?></p>
			
			<div class="gforms_help_alert alert_yellow"><?php _e("<strong>IMPORTANT:</strong> You <i>must</i> make sure you check the checkbox on the Public API page that says &quot;Enable Public API Access&quot; so the plugin can talk to ShootQ!", "gravityformsshootq") ?></div>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="gf_shootq_apikey"><?php _e("ShootQ API Access Key", "gravityformsshootq"); ?>  <?php gform_tooltip("shootq_api") ?></label></th>
                    <td>
                        <input type="text" id="gf_shootq_apikey" name="gf_shootq_apikey" value="<?php echo esc_attr($settings["apikey"]) ?>" size="50"/>
						<?php if (strlen($settings["apikey"]) != 0) echo "<img src=\"" . self::get_base_url() . "/images/tick.png\" />";; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_shootq_brand"><?php _e("ShootQ Brand Abbreviation", "gravityformsshootq"); ?>  <?php gform_tooltip("shootq_brand") ?></label></th>
                    <td>
                        <input type="text" id="gf_shootq_brand" name="gf_shootq_brand" value="<?php echo esc_attr($settings["brand"]) ?>" size="50"/>
						<?php if (strlen($settings["brand"]) != 0) echo "<img src=\"" . self::get_base_url() . "/images/tick.png\" />";; ?>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" ><input type="submit" name="gf_shootq_submit" class="button-primary" value="<?php _e("Save Settings", "gravityformsshootq") ?>" /></td>
                </tr>
            </table>
        </form>
		
		
		<style type="text/css" media="all">
			#shootq-donate-button { float: right; width: 150px; height: 60px; padding:10px; text-align: center; }
		</style>
		<form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_blank">
			<div class="hr-divider"></div>
			<div id="shootq-donate-button"><input type="hidden" name="cmd" value="_s-xclick">
			<input type="hidden" name="hosted_button_id" value="YKDX6YSJRWW2L">
			<input type="image" src="https://www.paypalobjects.com/en_US/i/btn/btn_donateCC_LG.gif" border="0" name="submit" alt="PayPal - The safer, easier way to pay online!">
			<img alt="" border="0" src="https://www.paypalobjects.com/en_US/i/scr/pixel.gif" width="1" height="1"></div>
			<h3><?php _e("Make a Donation", "gravityformsshootq") ?></h3>
			<div class="update-fade">
			<p><?php _e("The Gravity Forms ShootQ add-on was developed for the benefit of the ShootQ commmunity by a fellow photographer. If it has helped your business in any way, <i>please</i> support the maintenance and further development of this plugin by making a donation to the developer. Thank you!", "gravityformsshootq") ?></p>
			<div>
			<div style="clear: all;"></div>
		</form>


        <form action="" method="post">
            <?php wp_nonce_field("uninstall", "gf_shootq_uninstall") ?>
            <?php
            if(GFCommon::current_user_can_any("gravityforms_shootq_uninstall")){ ?>
                <div class="hr-divider"></div>
				
                <h3><?php _e("Uninstall ShootQ Add-On", "gravityformsshootq") ?></h3>
                <div class="delete-alert alert_red"><h3><?php _e("Warning", "gravityformsshootq") ?></h3><p><?php _e("This operation deletes ALL ShootQ Feeds, disconnecting your Gravity Forms from your ShootQ account.", "gravityformsshootq") ?></p>
                    <input type="submit" name="uninstall" value="<?php _e("Uninstall ShootQ Add-On", "gravityformsshootq") ?>" class="button" onclick="return confirm('<?php _e("Warning! ALL ShootQ Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravityformsshootq") ?>'); "/>
                </div>
            <?php
            } ?>
        </form>
        <?php
    }

    public static function shootq_page(){
        $view = rgget("view");
        if($view == "edit")
            self::edit_page(rgget("id"));
        else
            self::list_page();
    }

    //Displays the shootq feeds list page
    private static function list_page(){
        if(!self::is_gravityforms_supported()){
            die(__(sprintf("ShootQ Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.", self::$min_gravityforms_version, "<a href='plugins.php'>", "</a>"), "gravityformsshootq"));
        }

        if(rgpost("action") == "delete"){
            check_admin_referer("list_action", "gf_shootq_list");

            $id = absint(rgpost("action_argument"));
            GFShootQData::delete_feed($id);
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feed deleted.", "gravityformsshootq") ?></div>
            <?php
        }
        else if (!rgempty("bulk_action")){
            check_admin_referer("list_action", "gf_shootq_list");
            $selected_feeds = rgpost("feed");
            if(is_array($selected_feeds)){
                foreach($selected_feeds as $feed_id)
                    GFShootQData::delete_feed($feed_id);
            }
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feeds deleted.", "gravityformsshootq") ?></div>
            <?php
        }

        ?>
        <div class="wrap">
            <img alt="<?php _e("ShootQ Feeds", "gravityformsshootq") ?>" src="<?php echo self::get_base_url()?>/images/shootq_wordpress_icon_32.png" style="float:left; margin:15px 7px 0 0;"/>
            <h2><?php _e("ShootQ Feeds", "gravityformsshootq"); ?>
            <a class="button add-new-h2" href="admin.php?page=gf_shootq&view=edit&id=0"><?php _e("Add New", "gravityformsshootq") ?></a>
            </h2>
			
			
            <form id="feed_form" method="post">
                <?php wp_nonce_field('list_action', 'gf_shootq_list') ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>

                <div class="tablenav">
                    <div class="alignleft actions" style="padding:8px 0 7px; 0">
                        <label class="hidden" for="bulk_action"><?php _e("Bulk action", "gravityformsshootq") ?></label>
                        <select name="bulk_action" id="bulk_action">
                            <option value=''> <?php _e("Bulk action", "gravityformsshootq") ?> </option>
                            <option value='delete'><?php _e("Delete", "gravityformsshootq") ?></option>
                        </select>
                        <input type="submit" class="button" value="<?php _e("Apply", "gravityformsshootq") ?>" onclick="if( jQuery('#bulk_action').val() == 'delete' && !confirm('<?php  echo __("Delete selected feeds? \'Cancel\' to stop, \'OK\' to delete.", "gravityformsshootq") ?>')) { return false; } return true;" />
                    </div>
                </div>
                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravityformsshootq") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Message", "gravityformsshootq") ?></th>
                        </tr>
                    </thead>

                    <tfoot>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravityformsshootq") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Message", "gravityformsshootq") ?></th>
                        </tr>
                    </tfoot>

                    <tbody class="list:user user-list">
                        <?php
                        $settings = get_option("gf_shootq_settings");
                        $feeds = GFShootQData::get_feeds();
                        if(is_array($feeds) && sizeof($feeds) > 0){
                            foreach($feeds as $feed){
                                ?>
                                <tr class='author-self status-inherit' valign="top">
                                    <th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo $feed["id"] ?>"/></th>
                                    <td><img src="<?php echo self::get_base_url() ?>/images/active<?php echo intval($feed["is_active"]) ?>.png" alt="<?php echo $feed["is_active"] ? __("Active", "gravityformsshootq") : __("Inactive", "gravityformsshootq");?>" title="<?php echo $feed["is_active"] ? __("Active", "gravityformsshootq") : __("Inactive", "gravityformsshootq");?>" onclick="ToggleActive(this, <?php echo $feed['id'] ?>); " /></td>
                                    <td class="column-title">
                                        <a href="admin.php?page=gf_shootq&view=edit&id=<?php echo $feed["id"] ?>" title="<?php _e("Edit", "gravityformsshootq") ?>"><?php echo $feed["form_title"] ?></a>
                                        <div class="row-actions">
                                            <span class="edit">
                                            <a title="Edit this setting" href="admin.php?page=gf_shootq&view=edit&id=<?php echo $feed["id"] ?>" title="<?php _e("Edit", "gravityformsshootq") ?>"><?php _e("Edit", "gravityformsshootq") ?></a>
                                            |
                                            </span>

                                            <span class="edit">
                                            <a title="<?php _e("Delete", "gravityformsshootq") ?>" href="javascript: if(confirm('<?php _e("Delete this feed? ", "gravityformsshootq") ?> <?php _e("\'Cancel\' to stop, \'OK\' to delete.", "gravityformsshootq") ?>')){ DeleteSetting(<?php echo $feed["id"] ?>);}"><?php _e("Delete", "gravityformsshootq")?></a>

                                            </span>
                                        </div>
                                    </td>
                                    <td class="column-date"><?php echo strlen($feed["meta"]["brand"]) > 100 ? substr($feed["meta"]["brand"], 0, 100) : $feed["meta"]["brand"] ?></td>
                                </tr>
                                <?php
                            }
                        }
                        else if(!empty($settings["apikey"]) && !empty($settings["brand"])){
                            ?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                                    <?php _e(sprintf("You don't have any ShootQ feeds configured. Let's go %screate one%s!", '<a href="admin.php?page=gf_shootq&view=edit&id=0">', "</a>"), "gravityformsshootq"); ?>
                                </td>
                            </tr>
                            <?php
                        }
                        else{
                            ?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                                    <?php _e(sprintf("To get started, please configure your %sShootQ Settings%s.", '<a href="admin.php?page=gf_settings&addon=ShootQ">', "</a>"), "gravityformsshootq"); ?>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
            </form>
			
			<form action="https://www.paypal.com/cgi-bin/webscr" method="post">
				
				<table id="shootq-donate" width="100%" border="0" cellspacing="0" cellpadding="12">
					<tr><td width="100%" align="right"><?php _e("Please support this plugin by making a donation. <i>Thank you!</i> ", "gravityformsshootq"); ?></td>
					<td><input type="image" src="https://www.paypalobjects.com/en_US/i/btn/btn_donate_SM.gif" border="0" name="submit" alt="PayPal - The safer, easier way to pay online!" /></td></tr>
				</table>
				<input type="hidden" name="cmd" value="_s-xclick" />
				<input type="hidden" name="hosted_button_id" value="PD5VXLZ9ZFV24" />
				<img alt="" border="0" src="https://www.paypalobjects.com/en_US/i/scr/pixel.gif" width="1" height="1" />
			</form>
			
        </div>
        <script type="text/javascript">
            function DeleteSetting(id){
                jQuery("#action_argument").val(id);
                jQuery("#action").val("delete");
                jQuery("#feed_form")[0].submit();
            }
            function ToggleActive(img, feed_id){
                var is_active = img.src.indexOf("active1.png") >=0
                if(is_active){
                    img.src = img.src.replace("active1.png", "active0.png");
                    jQuery(img).attr('title','<?php _e("Inactive", "gravityformsshootq") ?>').attr('alt', '<?php _e("Inactive", "gravityformsshootq") ?>');
                }
                else{
                    img.src = img.src.replace("active0.png", "active1.png");
                    jQuery(img).attr('title','<?php _e("Active", "gravityformsshootq") ?>').attr('alt', '<?php _e("Active", "gravityformsshootq") ?>');
                }

                jQuery.post(ajaxurl,{action:"rg_update_feed_active", rg_update_feed_active:"<?php echo wp_create_nonce("rg_update_feed_active") ?>",
                                    feed_id: feed_id,
                                    is_active: is_active ? 0 : 1,
                                    cookie: encodeURIComponent(document.cookie)});

                return true;
            }

        </script>
        <?php
    }

    private static function edit_page(){
        ?>
		<link rel="stylesheet" href="<?php echo GFCommon::get_base_url()?>/css/admin.css" />
        <style>
            #shootq_submit_container{clear:both;}
            #shootq_field_group div{float:left;}
            .shootq_col_heading{padding-bottom:2px; font-weight:bold; width:120px;}
            .shootq_field_cell {padding: 6px 17px 0 0; margin-right:15px;}
			.shootq_required_field {color: #c00;}
            .left_header{float:left; width:200px;}
            .margin_vertical_10{margin: 10px 0; padding-left:5px;}
            .margin_vertical_30{margin: 30px 0; padding-left:5px;}
            .width-1{width:300px;}
            .left_header{float:left; width:200px;}
        </style>
        <script type="text/javascript">
            var form = Array();
        </script>
        <div class="wrap">
            <img alt="<?php _e("ShootQ", "gravityformsshootq") ?>" style="margin: 15px 7px 0pt 0pt; float: left;" src="<?php echo self::get_base_url() ?>/images/shootq_wordpress_icon_32.png"/>
            <h2><?php _e("Add/Edit ShootQ Feed", "gravityformsshootq") ?></h2>

        <?php

        //getting setting id (0 when creating a new one)
        $id = !rgempty("shootq_setting_id") ? rgpost("shootq_setting_id") : absint(rgget("id"));
        $config = empty($id) ? array("is_active" => true, "meta" => array()) : GFShootQData::get_feed($id);

        //updating meta information
        if(!rgempty("gf_shootq_submit")){

            $config["form_id"] = absint(rgpost("gf_shootq_form"));

            $lead_fields = self::get_lead_fields();
            $config["meta"]["lead_fields"] = array();
            foreach($lead_fields as $field){
                $config["meta"]["lead_fields"][$field["name"]] = $_POST["shootq_lead_field_{$field["name"]}"];
            }
            //-----------------
			// TODO: Add validation for required fields
            $id = GFShootQData::update_feed($id, $config["form_id"], $config["is_active"], $config["meta"]);
			
            ?>
            <div class="updated fade" style="padding:6px"><?php echo sprintf(__("Congratulations! Your feed was saved successfully. You can now %sgo back to the feeds list%s", "gravityformsshootq"), "<a href='?page=gf_shootq'>", "</a>") ?></div>
            <input type="hidden" name="shootq_setting_id" value="<?php echo $id ?>"/>
            <?php
        }
		
		$settings = get_option("gf_shootq_settings");
		if (!empty($settings["apikey"]) && !empty($settings["brand"])) {
		
		$form = isset($config["form_id"]) && $config["form_id"] ? RGFormsModel::get_form_meta($config["form_id"]) : array();
		require_once(self::get_base_path() . "/data.php");
        ?>
            <form method="post" action="">
                <input type="hidden" name="shootq_setting_id" value="<?php echo $id ?>"/>
				
                <div id="shootq_form_container" valign="top" class="margin_vertical_10">
                    <label for="gf_shootq_form" class="left_header"><?php _e("Gravity Form", "gravityformsshootq"); ?> <?php gform_tooltip("shootq_gravity_form") ?></label>
                    <div style="margin-top:25px;">
                        <select id="gf_shootq_form" name="gf_shootq_form" onchange="SelectForm(jQuery(this).val());">
                            <option value=""><?php _e("Select a form", "gravityformsshootq"); ?> </option>
                            <?php
							$active_form = rgar($config, 'form_id');
							$forms = GFShootQData::get_available_forms($active_form);
                            foreach($forms as $current_form){
                                $selected = absint($current_form->id) == $config["form_id"] ? "selected='selected'" : "";
                                ?>
                                <option value="<?php echo absint($current_form->id) ?>"  <?php echo $selected ?>><?php echo esc_html($current_form->title) ?></option>
                                <?php
                            }
                            ?>
                        </select>
                        &nbsp;&nbsp;
                        <img src="<?php echo GFShootQ::get_base_url() ?>/images/loading.gif" id="shootq_wait" style="display: none;"/>
                    </div>
					
				<div id="shootq_field_group" valign="top" <?php echo empty($config["form_id"]) ? "style='display:none;'" : "" ?>>
					
					<div id="shootq_mapping_notice">
						<p><?php _e("For each ShootQ Field below, select or &quot;map&quot; the corresponding Form Field from the list of fields. You <b>must</b> map the ShootQ Fields in red, but the remaining Fields are optional. Any Form Fields that are not mapped will also be sent to ShootQ and will appear below the Remarks in the Shoot Summary.", "gravityformsshootq");?></p>
					</div>
					<div style="clear: all;"></div>
					
				   <div class="margin_vertical_10">
						<label class="left_header"><?php _e("Field Mapping", "gravityformsshootq"); ?> <?php gform_tooltip("shootq_mapping") ?></label>
						<div id="gf_shootq_brand_variable_select">
							<?php
								if(!empty($form))
									echo self::get_lead_information($form, $config);
							?>
						</div>
					</div>

                </div>

                    <div id="shootq_submit_container" class="margin_vertical_30" style="clear:both;">
                        <input type="submit" name="gf_shootq_submit" value="<?php echo empty($id) ? __("Save Feed", "gravityformsshootq") : __("Update Feed", "gravityformsshootq"); ?>" class="button-primary"/>
						<input type="button" value="<?php _e("Cancel", "gravityformsshootq"); ?>" class="button" onclick="javascript:document.location='admin.php?page=gf_shootq'" />
                    </div>
                </div>
            </form>
        </div>
        <script type="text/javascript">
			
            function SelectForm(formId){
                if(!formId){
                    jQuery("#shootq_field_group").slideUp();
                    return;
                }

                jQuery("#shootq_wait").show();
                jQuery("#shootq_field_group").slideUp();
                jQuery.post(ajaxurl,{action:"gf_select_shootq_form", gf_select_shootq_form:"<?php echo wp_create_nonce("gf_select_shootq_form") ?>",
                                    form_id: formId,
                                    cookie: encodeURIComponent(document.cookie)},

                                    function(data){
                                        //setting global form object
                                        //form = data.form;
                                        //fields = data["fields"];
                                        jQuery("#gf_shootq_brand_variable_select").html(data);

                                        jQuery("#shootq_field_group").slideDown();
                                        jQuery("#shootq_wait").hide();
                                    }, "json"
                );
            }
			
            function InsertVariable(element_id, callback, variable){
                if(!variable)
                    variable = jQuery('#' + element_id + '_variable_select').val();

                var brandElement = jQuery("#" + element_id);

                if(document.selection) {
                    // Go the IE way
                    brandElement[0].focus();
                    document.selection.createRange().text=variable;
                }
                else if(brandElement[0].selectionStart) {
                    // Go the Gecko way
                    obj = brandElement[0]
                    obj.value = obj.value.substr(0, obj.selectionStart) + variable + obj.value.substr(obj.selectionEnd, obj.value.length);
                }
                else {
                    brandElement.val(variable + brandElement.val());
                }

                jQuery('#' + element_id + '_variable_select')[0].selectedIndex = 0;

                if(callback && window[callback])
                    window[callback].call();
            }

        </script>

        <?php
		} else {
			?>
			<div class="gforms_help_alert alert_yellow" style="margin-top: 20px;">
			<?php _e(sprintf("Whoa there! You must configure your %sShootQ Settings%s before creating any feeds.", '<a href="admin.php?page=gf_settings&addon=ShootQ">', "</a>"), "gravityformsshootq"); ?>
			</div>
			<?php
		}
    }

    public static function add_permissions(){
        global $wp_roles;
        $wp_roles->add_cap("administrator", "gravityforms_shootq");
        $wp_roles->add_cap("administrator", "gravityforms_shootq_uninstall");
    }

    //Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
    public static function members_get_capabilities( $caps ) {
        return array_merge($caps, array("gravityforms_shootq", "gravityforms_shootq_uninstall"));
    }

    public static function disable_shootq(){
        delete_option("gf_shootq_settings");
    }

    public static function select_shootq_form(){

        check_ajax_referer("gf_select_shootq_form", "gf_select_shootq_form");
        $form_id =  intval($_POST["form_id"]);

        //fields meta
        $form = RGFormsModel::get_form_meta($form_id);
        $fields = self::get_lead_information($form);

        $result = $fields;
        die(GFCommon::json_encode($result));
    }

    private static function get_form_fields($form){
        $fields = array();

        if(is_array($form) && is_array($form["fields"])){
            foreach($form["fields"] as $field){
                if(is_array($field["inputs"])){

                    foreach($field["inputs"] as $input)
                        $fields[] =  array($input["id"], GFCommon::get_label($field, $input["id"]));
                }
                else if(!rgar($field, 'displayOnly')){
                    $fields[] =  array($field["id"], GFCommon::get_label($field));
                }
            }
        }
        return $fields;
    }
	
    public static function export($entry, $form){

		//loading data class
		require_once(self::get_base_path() . "/data.php");
		$feed = GFShootQData::get_feed_by_form($form["id"], true);
		if (isset($feed[0]))
			self::send_to_shootq($entry, $form, $feed);
    }
	
    public static function send_to_shootq($entry, $form, $feed){

		$settings = get_option("gf_shootq_settings");
		$admin_email = get_option("admin_email");
		$url = "https://app.shootq.com/api/" . $settings["brand"] . "/leads";
		$map = $feed[0]["meta"]["lead_fields"];

		/* create a data structure to send to ShootQ */
		$lead = array();
		$lead["api_key"] = $settings["apikey"];
		$lead["contact"] = array();
		$lead["contact"]["first_name"] = self::get_entry_data("first_name", $entry, $map, "First Name");
		$lead["contact"]["last_name"] = self::get_entry_data("last_name", $entry, $map, "Last Name");
		$lead["contact"]["phones"] = array();
		$lead["contact"]["phones"][0] = array();
		$lead["contact"]["phones"][0]["type"] = self::get_entry_data("phonetype", $entry, $map, "Cell");
		$lead["contact"]["phones"][0]["number"] = self::get_entry_data("phone", $entry, $map);
		$lead["contact"]["emails"] = array();
		$lead["contact"]["emails"][0] = array();
		$lead["contact"]["emails"][0]["type"] = "Main";
		$lead["contact"]["emails"][0]["email"] = self::get_entry_data("email", $entry, $map, $admin_email);
		//$lead["contact"]["role"] = "Groom";
		$lead["event"] = array();
		$lead["event"]["type"] = self::get_entry_data("type", $entry, $map, "Other");
		$lead["event"]["date"] = self::get_entry_data("date", $entry, $map);
		$lead["event"]["referred_by"] = self::get_entry_data("referrer", $entry, $map);
		$lead["event"]["remarks"] = self::get_entry_data("remarks", $entry, $map);
		//$lead["event"]["wedding"] = array();
		//$lead["event"]["wedding"]["ceremony_location"] = "First Church of ShootQ";
		//$lead["event"]["wedding"]["ceremony_start_time"] = "17:30";
		//$lead["event"]["wedding"]["ceremony_end_time"]= "18:30";
		//$lead["event"]["wedding"]["reception_location"] = "ShootQ Party Central";
		//$lead["event"]["wedding"]["reception_start_time"] = "19:00";
		//$lead["event"]["wedding"]["reception_end_time"]= "22:00";
		//$lead["event"]["wedding"]["groomsmen_count"] = 5;
		//$lead["event"]["wedding"]["bridesmaids_count"] = 5;
		//$lead["event"]["wedding"]["guests_count"] = 200;
		$lead["event"]["extra"] = self::get_extras($entry, $map);
		
		/* encode this data structure as JSON */
		$lead_json = json_encode($lead);

		/* send this data to ShootQ via the API */
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, Array("Content-Type: application/json"));
		curl_setopt($ch, CURLOPT_POST, TRUE);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $lead_json);

		/* get the response from the ShootQ API */
		$response_json = curl_exec($ch);
		$response = json_decode($response_json);
		$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		/* the HTTP code will be 200 if there is a success */
		//if ($httpcode == 200) {
			//echo "SUCCESS!\n";
		//} else {
			//echo "There was a problem: ".$httpcode."\n\n";
			//echo $response_json;
		//}

		/* close the connection */
		curl_close($ch);

    }
	
	//retrieves the requested field information from the submitted form
	public static function get_entry_data($key, $entry, $map, $default=null) {
		
		if (isset($map[$key])) $str = trim($entry[$map[$key]]);
		
		//the ShootQ API expects dates in the "mm/dd/yyyy" format.
		//GF gives us the "yyyy-mm-dd" format...
		if ($key == "date") {
			$str = preg_replace('/(\d{4})-(\d{2})-(\d{2})(.?)/', '\2/\3/\1', $str);
		}
		
		//if the fields was empty and we provided a default, use the default
		if (strlen($str) == 0) $str = $default;
		return $str;
	}
	
	//collects all of the form fields not mapped to the ShootQ fields
	public static function get_extras($entry, $map) {
		
		$form = RGFormsModel::get_form_meta($entry["form_id"]);
		$fields = $form["fields"];
		$map_ids = implode(",", $map); //get a list of the mapped fields
		
		//create the array to be sent back
		$extras = array();
		
		//get a list of labels with the field IDs as array keys for easy matching later
		$count = count($fields);
		$labels = array();
		for ($index = 0; $index < $count; $index++) {
			$labels[$fields[$index]["id"]] = $fields[$index]["label"];
		}
		
		//find the IDs for entries that were not mapped
		$entries = $entry; //make a copy
		
		//remove all of the unnecessary form entries
		$bad_entries = array("id", "form_id", "date_created", "is_starred", "is_read", "ip", "source_url", "post_id", "currency", "payment_status", "payment_date", "transaction_id", "payment_amount", "is_fulfilled", "created_by", "transaction_type", "user_agent");
		for ($index = 0; $index < count($bad_entries); $index++) {
			$entries[$bad_entries[$index]] = "";
		}
		
		//remove the already mapped entries, too
		$mapped_entries = array_flip(array_flip(explode(",", $map_ids)));
		for ($index = 0; $index < count($mapped_entries); $index++) {
			$entries[$mapped_entries[$index]] = "";
		}
		
		$extra_keys = array_flip($entries);
		$extra_keys = explode(",", implode(",", $extra_keys));
		
		for ($index = 0; $index < count($extra_keys); $index++) {
			$field_id = trim($extra_keys[$index]);
			
			//if we get the occasional empty key, skip it
			if (strlen($field_id) == 0) continue;
			
			if (preg_match("/[0-9]+\.[0-9]+/i", $field_id) > 0) {
				
				//field is a fragment so collect them all
				$tmp_id = intval($field_id); //to match up with the labels array
				if (!isset($extras[$labels[$tmp_id]])) {
					$extras[$labels[$tmp_id]] = $entry[$field_id];
				} else {
					$extras[$labels[$tmp_id]] = $extras[$labels[$tmp_id]] . ", " . $entry[$field_id];
				}
			} else {
				$extras[$labels[$field_id]] = $entry[$field_id];
			}
		}
				
		return $extras;
	}
	
    private static function get_lead_information($form, $config=null){

        //getting list of all fields for the selected form
        $form_fields = self::get_form_fields($form);

        $str = "<table cellpadding=\"0\" cellspacing=\"0\"><tr><td class=\"shootq_col_heading\">" . __("ShootQ Fields", "gravityformsshootq") . "</td><td class=\"shootq_col_heading\">" . __("Form Fields", "gravityformsshootq") . "</td></tr>";
        $lead_fields = self::get_lead_fields();
		
        foreach($lead_fields as $field){
            $selected_field = $config ? $config["meta"]["lead_fields"][$field["name"]] : "";
			$required = array_key_exists("required", $field) ? " shootq_required_field" : "";
            $str .= "<tr><td class=\"shootq_field_cell$required\">" . $field["label"]  . "</td><td class=\"shootq_field_cell\">" . self::get_mapped_field_list($field["name"], $selected_field, $form_fields) . "</td></tr>";
        }
        $str .= "<tr><td colspan=\"2\" style=\"padding: 6px 0;\">Missing a required field? Go <a href=\"admin.php?page=gf_edit_forms&id=" . $form["id"] . "\">edit this form</a></td></tr>";
		$str .= "</table>";

        return $str;
    }

    private static function get_lead_fields(){
        //the "required" key exists only for fields that are required by the ShootQ API
		return array(array("name" => "type" , "label" => "Shoot Type", "required" => "true"), 
		array("name" => "first_name" , "label" => "First Name", "required" => "true"), array("name" => "last_name" , "label" =>"Last Name", "required" => "true"),
        array("name" => "email" , "label" =>"Email", "required" => "true"), 
		array("name" => "date" , "label" => "Session Date"),
		array("name" => "referrer" , "label" => "Referrer"),
		array("name" => "remarks" , "label" => "Remarks"),
		array("name" => "phone" , "label" =>"Phone"), array("name" => "phonetype" , "label" => "Phone Type"));
    }
	
    private static function get_mapped_field_list($variable_name, $selected_field, $fields){
        $field_name = "shootq_lead_field_" . $variable_name;
        $str = "<select name='$field_name' id='$field_name'><option value=''></option>";
        foreach($fields as $field){
            $field_id = $field[0];
            $field_label = esc_html(GFCommon::truncate_middle($field[1], 40));

            $selected = $field_id == $selected_field ? "selected='selected'" : "";
            $str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
        }
        $str .= "</select>";
        return $str;
    }

    public static function uninstall(){

        //loading data lib
        require_once(self::get_base_path() . "/data.php");

        if(!GFShootQ::has_access("gravityforms_shootq_uninstall"))
            die(__("You don't have adequate permission to uninstall ShootQ Add-On.", "gravityformsshootq"));

        //droping all tables
        GFShootQData::drop_tables();

        //removing options
        delete_option("gf_shootq_settings");
        delete_option("gf_shootq_version");

        //Deactivating plugin
        $plugin = "gravity-forms-shootq-add-on/shootq.php";
        deactivate_plugins($plugin);
        update_option('recently_activated', array($plugin => time()) + (array)get_option('recently_activated'));
    }

    private static function is_gravityforms_installed(){
        return class_exists("RGForms");
    }

    private static function is_gravityforms_supported(){
        if(class_exists("GFCommon")){
            $is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
            return $is_correct_version;
        }
        else{
            return false;
        }
    }

    protected static function has_access($required_permission){
        $has_members_plugin = function_exists('members_get_capabilities');
        $has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
        if($has_access)
            return $has_members_plugin ? $required_permission : "level_7";
        else
            return false;
    }

    //Returns the url of the plugin's root folder
    protected function get_base_url(){
        return plugins_url(null, __FILE__);
    }

    //Returns the physical path of the plugin's root folder
    protected function get_base_path(){
        $folder = basename(dirname(__FILE__));
        return WP_PLUGIN_DIR . "/" . $folder;
    }

	public static function plugin_settings_link( $links, $file ) {
	if ( $file != plugin_basename( __FILE__ ))
		return $links;

	array_unshift($links, '<a href="' . admin_url("admin.php") . '?page=gf_settings&addon=ShootQ">' . __( 'Settings', 'gravityformsshootq' ) . '</a>');

	return $links;
    }

}
?>