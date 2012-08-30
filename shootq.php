<?php
/*
Plugin Name: Gravity Forms ShootQ Add-On
Plugin URI: http://www.pussycatintimates.com/gravity-forms-shootq-add-on-wordpress-plugin/
Description: Connects your WordPress web site to your ShootQ account for collecting leads using the power of Gravity Forms.
Version: 1.1.3
Author: pussycatdev
Author URI: http://www.pussycatintimates.com/

------------------------------------------------------------------------
Copyright 2012 Pussycat Intimate Portraiture

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

    private static $version = "1.1.3";
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
		if (self::is_gravity_page()) {
			require_once(GFCommon::get_base_path() . "/tooltips.php");
			add_filter('gform_tooltips', array('GFShootQ', 'tooltips'));
		}

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
             //Handling post submission. This is where the integration will happen 
			 //(will get fired right after the form gets submitted)
            add_action("gform_post_submission", array('GFShootQ', 'export'), 10, 2);
        }

    }

    public static function update_feed_active(){
        check_ajax_referer('rg_update_feed_active','rg_update_feed_active');
        $id = rgpost("feed_id");
        $feed = GFShootQData::get_feed($id);
        GFShootQData::update_feed($id, $feed["form_id"], rgpost("is_active"), $feed["meta"]);
    }


    //Returns true if the current page is a feed page. Returns false if not.
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
            "shootq_api" => "<h6>" . __("ShootQ API Key", "gravityformsshootq") . "</h6>" . __("Enter the API Access Key associated with your ShootQ account.", "gravityformsshootq"),
            "shootq_brand" => "<h6>" . __("ShootQ Brand Abbreviation", "gravityformsshootq") . "</h6>" . __("Enter the ShootQ Brand Abbreviation for the brand you wish to connect.", "gravityformsshootq"),
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

        $is_valid_api = true;
		$validation_icon = ($is_valid_api) ? "/images/tick.png" : "/images/error.png";
		
		if(!rgempty("uninstall")){
            check_admin_referer("uninstall", "gf_shootq_uninstall");
            self::uninstall();

            ?>
            <div class="updated fade" style="padding:20px;"><?php _e(sprintf("Gravity Forms ShootQ Add-On has been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>","</a>"), "gravityformsshootq")?></div>
            <?php
            return;
        } else if(!rgempty("gf_shootq_submit")){
            check_admin_referer("update", "gf_shootq_update");
            $settings = array(
				"apikey" => trim(rgpost("gf_shootq_apikey")), 
				"brand" => trim(rgpost("gf_shootq_brand"))
			);
			
			//validate the API key to make sure it's the right format
			$valid_pattern = "/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i";
			$is_valid_api = (preg_match($valid_pattern,$settings["apikey"]) != 0);
			
			if (!$is_valid_api) {
				$validation_icon = "/images/error.png";
				?>
				<div class="delete-alert alert_red" style="padding:6px"><?php _e("The API Key you provided is in the <i>wrong format!</i> You may not have copied the entire string, or have made a typo if manually entering the Key. Please try again.", "gravityformsshootq") ?></div>
				<?php
			} else {
				update_option("gf_shootq_settings", $settings);
				?>
				<div class="updated fade" style="padding:6px"><?php echo sprintf(__("Your ShootQ Settings have been saved. Now you can %sconfigure a new feed%s!", "gravityformsshootq"), "<a href='?page=gf_shootq&view=edit&id=0'>", "</a>") ?></div>
				<?php
			}

        } else {
            $settings = get_option("gf_shootq_settings");
        }
        ?>

        <form method="post" action="">
            <?php wp_nonce_field("update", "gf_shootq_update") ?>
            <h3><?php _e("ShootQ Settings", "gravityformsshootq") ?></h3>
			
			<p style="text-align: left;"><?php _e("Here is where you will connect your ShootQ account to the plugin. To find your API Key and Brand Abbreviation, visit the Public API page from the bottom of your Settings tab on ShootQ (You can", "gravityformsshootq") ?> <a href="https://app.shootq.com/controlpanels/integrations/api" title="Go to the Public API page on ShootQ" target="_blank"><?php _e("head straight there", "gravityformsshootq") ?></a> <?php _e("if you are already logged in.) Copy the API Key and Brand Abbreviation into their respective fields below.", "gravityformsshootq") ?></p>
			
			<div class="gforms_help_alert alert_yellow"><?php _e("<strong>IMPORTANT:</strong> You <i>must</i> make sure you check the checkbox on the Public API page that says &quot;Enable Public API Access&quot; so the plugin can talk to ShootQ!", "gravityformsshootq") ?></div>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="gf_shootq_apikey"><?php _e("ShootQ API Access Key", "gravityformsshootq"); ?>  <?php gform_tooltip("shootq_api") ?></label></th>
                    <td>
                        <input type="text" id="gf_shootq_apikey" name="gf_shootq_apikey" value="<?php echo esc_attr($settings["apikey"]) ?>" size="50"/>
						<?php if (strlen($settings["apikey"]) != 0) echo "<img src=\"" . self::get_base_url() . "/" . $validation_icon . "\" />"; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_shootq_brand"><?php _e("ShootQ Brand Abbreviation", "gravityformsshootq"); ?>  <?php gform_tooltip("shootq_brand") ?></label></th>
                    <td>
                        <input type="text" id="gf_shootq_brand" name="gf_shootq_brand" value="<?php echo esc_attr($settings["brand"]) ?>" size="50"/>
						<?php if (strlen($settings["brand"]) != 0) echo "<img src=\"" . self::get_base_url() . "/images/tick.png\" />"; ?>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" ><input type="submit" name="gf_shootq_submit" class="button-primary" value="<?php _e("Save Settings", "gravityformsshootq") ?>" /></td>
                </tr>
            </table>
        </form>
		<div class="hr-divider"></div>
		<?php self::support_section(); ?>

        <form action="" method="post">
            <?php wp_nonce_field("uninstall", "gf_shootq_uninstall") ?>
            <?php
            if(GFCommon::current_user_can_any("gravityforms_shootq_uninstall")){ ?>
                <div class="hr-divider"></div>
				
                <h3><?php _e("Uninstall ShootQ Add-On", "gravityformsshootq") ?></h3>
                <div class="delete-alert"><?php _e("Warning! This operation deletes ALL ShootQ Feeds, disconnecting your Gravity Forms from your ShootQ account.", "gravityformsshootq") ?>
                    <?php
                    $uninstall_button = '<input type="submit" name="uninstall" value="' . __("Uninstall ShootQ Add-On", "gravityformsshootq") . '" class="button" onclick="return confirm(\'' . __("Warning! ALL ShootQ Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravityformsshootq") . '\');"/>';
                    echo apply_filters("gform_shootq_uninstall_button", $uninstall_button);
                    ?>
                </div>
            <?php
            } ?>
        </form>
		<div style="clear: both;"></div>
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
		<?php self::support_section(); ?>			
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
            .shootq_col_heading{padding-bottom:2px; font-weight:bold; width:150px;}
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
						<p><?php _e("For each ShootQ Field below, select or &quot;map&quot; the corresponding Form Field from the list of fields. You <b>must</b> map the ShootQ Fields in red, but the remaining Fields are optional. Any Form Fields that are not mapped will also be sent to ShootQ and will appear below the Remarks in the Additional Information section.", "gravityformsshootq");?></p>
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

	public static function support_section() {
	?>
		<style>.support-list-icon { margin: 4px 4px 2px  10px; } </style>
		<form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_blank">
			<div id="shootq-donate-button" style="float:right; width:150px; height:60px; padding:10px; text-align:center;"><input type="hidden" name="cmd" value="_s-xclick">
			<input type="hidden" name="hosted_button_id" value="YKDX6YSJRWW2L">
			<input type="image" src="https://www.paypalobjects.com/en_US/i/btn/btn_donateCC_LG.gif" border="0" name="submit" alt="PayPal - The safer, easier way to pay online!">
			<img alt="" border="0" src="https://www.paypalobjects.com/en_US/i/scr/pixel.gif" width="1" height="1"></div>
			<h3><?php _e("Help Support This Plugin!", "gravityformsshootq") ?></h3>
			<div id="donation-div">
			<p><?php echo __("The Gravity Forms ShootQ Add-On was developed for the benefit of the ShootQ commmunity by a fellow photographer. If this plugin has helped you turn even ", "gravityformsshootq") . "<i>" . __("one single lead", "gravityformsshootq") . "</i>" . __(" into a client, please support the developer by making a small donation. After all, all of his time and effort behind the scenes should not go unrewarded any more than yours, right? Thank you for your generosity!", "gravityformsshootq"); ?></p>
			<p><?php echo "<b>" . __("Also, if you please...", "gravityformsshootq") . "</b><br /><img class=\"support-list-icon\" src=\"" . self::get_base_url() . "/images/star.png\" width=\"16\" height=\"16\" border=\"0\" align=\"absbottom\"><a href=\"http://wordpress.org/extend/plugins/gravity-forms-shootq-add-on/\" target=\"_blank\">" . __("Rate this plugin 5 STARS on WordPress.org","gravityformsshootq") . "</a>" ?><br />
			<?php echo "<img class=\"support-list-icon\" src=\"" . self::get_base_url() . "/images/page_white_world.png\" width=\"16\" height=\"16\" border=\"0\" align=\"absbottom\"><a href=\"http://www.pussycatintimates.com/gravity-forms-shootq-add-on-wordpress-plugin/\">" . __("Blog about it & link to the plugin page","gravityformsshootq") . "</a>" ?>
			</p>
			<div>
		</form>
		<div style="clear: both;"></div>
	<?php
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
                else if(!rgar($field, "displayOnly")){
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
		if (isset($feed) && isset($feed[0])) {		
			$form = RGFormsModel::get_form_meta($entry["form_id"]);
			// TESTING ONLY
			//echo "<b>ENTRY:</b><br /><pre>"; print_r($entry); echo "</pre>";
			self::send_to_shootq($entry, $form, $feed);
		}
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
		
		/* determine phone types, using generic only when necessary */
		$lead["contact"]["phones"] = array();
		/* we favor the specific fields over the legacy generic one */
		if (isset($map["home_phone"]) || isset($map["work_phone"]) || isset($map["cell_phone"])) {
			$tmpI = 0;
			if (isset($map["home_phone"])) {
				$lead["contact"]["phones"][$tmpI] = array();
				$lead["contact"]["phones"][$tmpI]["number"] = self::get_entry_data("home_phone", $entry, $map);
				$lead["contact"]["phones"][$tmpI]["type"] = "Home";
				$tmpI++;
			}
			if (isset($map["cell_phone"])) {
				$lead["contact"]["phones"][$tmpI] = array();
				$lead["contact"]["phones"][$tmpI]["number"] = self::get_entry_data("cell_phone", $entry, $map);
				$lead["contact"]["phones"][$tmpI]["type"] = "Cell";
				$tmpI++;
			}
			if (isset($map["work_phone"])) {
				$lead["contact"]["phones"][$tmpI] = array();
				$lead["contact"]["phones"][$tmpI]["number"] = self::get_entry_data("work_phone", $entry, $map);
				$lead["contact"]["phones"][$tmpI]["type"] = "Work";
				$tmpI++;
			}
		} else {
			$lead["contact"]["phones"][0]["number"] = self::get_entry_data("phone", $entry, $map);
			$lead["contact"]["phones"][0]["type"] = self::get_entry_data("phonetype", $entry, $map, "Cell");
		}
				
		//$lead["contact"]["phones"][0]["type"] = self::get_entry_data("phonetype", $entry, $map, "Cell");
		//$lead["contact"]["phones"][0]["number"] = self::get_entry_data("phone", $entry, $map);
		
		$lead["contact"]["emails"] = array();
		$lead["contact"]["emails"][0] = array();
		$lead["contact"]["emails"][0]["type"] = "Main";
		$lead["contact"]["emails"][0]["email"] = self::get_entry_data("email", $entry, $map, $admin_email);
		$lead["contact"]["role"] = self::get_entry_data("role", $entry, $map);
		
		$lead["event"] = array();
		$lead["event"]["type"] = self::get_entry_data("type", $entry, $map, "Other");
		$lead["event"]["date"] = self::get_entry_data("date", $entry, $map);
		$lead["event"]["referred_by"] = self::get_entry_data("referrer", $entry, $map);
		$lead["event"]["remarks"] = self::get_entry_data("remarks", $entry, $map);
		
		$lead["event"]["wedding"] = array();
		$lead["event"]["wedding"]["ceremony_location"] = self::get_entry_data("ceremony_location", $entry, $map);
		$lead["event"]["wedding"]["ceremony_start_time"] = self::get_entry_data("ceremony_start_time", $entry, $map);
		$lead["event"]["wedding"]["ceremony_end_time"]= self::get_entry_data("ceremony_end_time", $entry, $map);
		$lead["event"]["wedding"]["reception_location"] = self::get_entry_data("reception_location", $entry, $map);
		$lead["event"]["wedding"]["reception_start_time"] = self::get_entry_data("reception_start_time", $entry, $map);
		$lead["event"]["wedding"]["reception_end_time"]= self::get_entry_data("reception_end_time", $entry, $map);
		$lead["event"]["wedding"]["groomsmen_count"] = self::get_entry_data("groomsmen_count", $entry, $map);
		$lead["event"]["wedding"]["bridesmaids_count"] = self::get_entry_data("bridesmaids_count", $entry, $map);
		$lead["event"]["wedding"]["guests_count"] = self::get_entry_data("guests_count", $entry, $map);
		
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
		$useless = "html,section,captcha,page"; //form "fields" that don't gather useful info
		$extras = array(); //array to be returned
		
		for ($id = 0; $id < count($fields); $id++) {
			$tmpField = $fields[$id];
			//if it's not a mapped or useless field, collect the info...
			if (array_search($tmpField["id"], $map) === false && 
					strpos($useless, $tmpField["type"]) === false) {
				$tmpLabel = (isset($tmpField["adminLabel"]) && strlen($tmpField["adminLabel"]) > 0) ? $tmpField["adminLabel"] : $tmpField["label"];
				//$tmpLabel = $id . " " . $tmpLabel; //show ShootQ Support the IDs for sorting. Remove when done.
				
				$tmpValue = "";
				if ($tmpField["type"] == "checkbox") {
					$fieldInputs = $tmpField["inputs"];
					$choices = array();
					for ($cb = 0; $cb < count($fieldInputs); $cb++) {
						$tmpID = (string) $fieldInputs[$cb]["id"];
						if (strlen($entry[$tmpID]) > 0) {
							$choices[] = trim($entry[$tmpID]);
						}
					}
					$tmpValue = implode(", ", $choices);
					
				} elseif ($tmpField["type"] == "list") {
					$tmpValue = unserialize(trim($entry[$tmpField["id"]]));
					if (is_array($tmpValue[0])) {
						$columns = array();
						$valArray = $tmpValue;
						while(list($key, $val) = each($valArray[0])) {
							$columns[] = $key;
						}
						
						for ($col = 0; $col < count($valArray[0]); $col++) {
							$rowVals = array();
							for ($row = 0; $row < count($valArray); $row++) {
								$rowVals[] = $valArray[$row][$columns[$col]];
							}
							$tmpValues[] = $columns[$col] . " = " . implode(", ", $rowVals);
							unset($rowVals);
						}
						$tmpValue = implode("; ", $tmpValues);
					} else {
						$tmpValue = @implode(", ", $tmpValue); //don't raise error if empty
					}
				} else {
					$tmpValue = trim($entry[$tmpField["id"]]);
				}
			
				//eliminate blank values
				if (strlen($tmpValue) > 0) {
					$extras[$tmpLabel] = $tmpValue;
				}
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
		return array(
			array("name" => "type", "label" => "Shoot Type", "required" => "true"), 
			array("name" => "first_name", "label" => "First Name", "required" => "true"), 
			array("name" => "last_name", "label" =>"Last Name", "required" => "true"),
			array("name" => "email", "label" =>"Email", "required" => "true"), 
			array("name" => "date", "label" => "Session Date"),
			array("name" => "referrer", "label" => "Referrer"),
			array("name" => "remarks", "label" => "Remarks"),
			array("name" => "phone", "label" =>"Phone"), 
			array("name" => "phonetype", "label" => "Phone Type"),
			array("name" => "home_phone", "label" =>"Home Phone"), 
			array("name" => "work_phone", "label" =>"Work Phone"), 
			array("name" => "cell_phone", "label" =>"Cell Phone"), 
			array("name" => "role", "label" => "Role"),
			array("name" => "bridesmaids_count", "label" =>"Bridesmaids Count"), 
			array("name" => "groomsmen_count", "label" =>"Groomsmen Count"), 
			array("name" => "guests_count", "label" =>"Guests Count"),
			array("name" => "ceremony_location", "label" =>"Ceremony Location"), 
			array("name" => "ceremony_start_time", "label" =>"Ceremony Start Time"), 
			array("name" => "ceremony_end_time", "label" =>"Ceremony End Time"), 
			array("name" => "reception_location", "label" =>"Reception Location"), 
			array("name" => "reception_start_time", "label" =>"Reception Start Time"), 
			array("name" => "reception_end_time", "label" =>"Reception End Time")
		);
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
            die(__("You don't have adequate permission to uninstall the ShootQ Add-On.", "gravityformsshootq"));

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
	
	//Returns whether or not we are on a Gravity Forms admin page.
	protected static function is_gravity_page() {
        $current_page = trim( strtolower( RGForms::get( "page" ) ) );
        $gf_pages = array( "gf_edit_forms", "gf_new_form", "gf_entries", "gf_settings", "gf_export", "gf_help", "gf_shootq" );
        return in_array( $current_page, $gf_pages );
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