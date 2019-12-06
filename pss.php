<?php
/*
	Plugin Name: Purge Stale Sites
	Description: Purge stale sites in a network installation.
	Author: Ian Altgilbers  ian@altgilbers.com
	Source: https://github.com/altgilbers/WP_PurgeStaleSites
	Version: 0.5

	Basic idea:  Iterate through sites checking their last_modified value.
	If the site has been idle for longer than your set threshold,
	notify owner/admins of impending action.

	After your set interval, warn again.
	After another interval, mark site as deactivated
	After deletion interval, archive site with external process and delete it

	pss_status flag values
	0 - initialized, site active
	1 - site stale, first warning issued
	2 - second warning issued
	3 - site has been marked archived
	4 - site has been marked deleted (to let external tool create .tar.gz before deleting).
	5 - site has been offloaded and ready for deletion 
*/

// this require is necessary for wpmu_delete_blog() to work from cron
require_once(ABSPATH . 'wp-admin/includes/admin.php');

add_action('network_admin_menu', 'pss_setup_menu');
function pss_setup_menu()
{
    add_menu_page($page_title = 'PSS Plugin Page',
    	$menu_title = 'PSS Options',
    	$capability = 'manage_network',
    	$menu_slug = 'pss-options',
    	$function = 'pss_admin_page');
}

function pss_admin_page()
{
    echo "<h3>Purge Stale Sites</h3>";
        //  add pss_reset to query string to reset plugin settings
    if (isset($_GET['pss_reset'])) {
        pss_deactivation_hook();
        pss_activation_hook();
        echo "<div id='message' class='updated'>Settings reset</div>";
    }
    if ($_GET['updated'] == "true")
        echo "<div id='message' class='updated'>updated</div>";
    if (isset($_GET['error']))
        echo "<div id='message' class='error'>" . urldecode($_GET['error']) . "</div>";   
?>
	<form action="/wp-admin/network/edit.php?action=pss-options" method="post">
	<?php
    settings_fields('pss_options');
    do_settings_sections('pss-options');
    submit_button();
?>
	</form>
<?php
}


add_action('admin_init', 'pss_admin_init');
function pss_admin_init()
{
    add_settings_section($id = 'pss_options',
    	$title = 'PSS Options',
    	$callback = '',
    	$page = 'pss-options');
    
    register_setting($option_group = 'pss_options', $option_name = 'pss_stale_age');
    register_setting($option_group = 'pss_options', $option_name = 'pss_warn_interval');
    
    add_settings_field($id = 'pss_stale_age',
    	$title = 'Stale Age (days)',
    	$callback = 'pss_stale_age_cb',
    	$page = 'pss-options',
    	$section = 'pss_options');
    add_settings_field($id = 'pss_warn_interval',
    	$title = 'Warn Interval (days)',
    	$callback = 'pss_warn_interval_cb',
    	$page = 'pss-options',
    	$section = 'pss_options');
    add_settings_field($id = 'pss_cron_enable',
    	$title = 'Enable cron task',
    	$callback = 'pss_cron_enable_cb',
    	$page = 'pss-options',
    	$section = 'pss_options');
}


function pss_stale_age_cb()
{
    echo "<input type='text' name='pss_stale_age' value='" . (get_site_option('pss_stale_age') / 86400) . "'/>";
}
function pss_warn_interval_cb()
{
    echo "<input type='text' name='pss_warn_interval' value='" . (get_site_option('pss_warn_interval') / 86400) . "'/>";
}

function pss_cron_enable_cb()
{
    echo "<input type='checkbox' name='pss_cron_enable' ";
    if (get_site_option('pss_cron_enable') == "true")
        echo "checked";
    echo ">";
}

// this action runs when /wp-admin/network/edit.php is called with ?action=pss-options
// could use some validation...
add_action('network_admin_edit_pss-options', 'pss_save_network_options');
function pss_save_network_options()
{    
    $redirect_query_string_array = array(
        'page' => 'pss-options'
    );
    $error_msg = "";
    // non-superadmins shouldn't ever see this, but if somehow they do, bail...
    if (!is_super_admin()) {
        exit;
    }
    pss_log("Saving network options...");
    if (isset($_POST["pss_stale_age"])) {
        update_site_option("pss_stale_age", $_POST["pss_stale_age"] * 86400);
    }
    if (isset($_POST["pss_warn_interval"])) {
        update_site_option("pss_warn_interval", $_POST["pss_warn_interval"] * 86400);
    }
    if (isset($_POST["pss_cron_enable"])) {
        pss_log("pss_cron_enable=" . $_POST["pss_cron_enable"]);
        update_site_option("pss_cron_enable", "true");
        if (wp_next_scheduled('pss_sync_event')) {
            wp_clear_scheduled_hook('pss_sync_event');
        }
        wp_schedule_event(time(), 'twicedaily', 'pss_sync_event');
    } else {
        pss_log("disabling cron");
        update_site_option("pss_cron_enable", "false");
        wp_clear_scheduled_hook('pss_sync_event');
    }
    
    $redirect_url = add_query_arg($redirect_query_string_array, (is_multisite() ? network_admin_url('admin.php') : admin_url('admin.php')));
    pss_log("redirecting to: " . $redirect_url);
    wp_redirect($redirect_url);   
    //must exit, otherwise another redirect will trump the one we set here.
    exit;
}

register_activation_hook(__FILE__, 'pss_activation_hook');
function pss_activation_hook()
{
    pss_log("Activating plugin...");
    pss_log("initializing options...");
    update_site_option('pss_stale_age', 3600 * 24 * 365 * 2);
    update_site_option('pss_warn_interval', 3600 * 24 * 14);
    update_site_option('pss_log_file', "./logs/pss.log");
}

register_deactivation_hook(__FILE__, 'pss_deactivation_hook');
function pss_deactivation_hook()
{
    pss_log("Deactivating plugin...");
    pss_log("Removing pss_status option from all blogs");
    $all_sites = get_sites(array(
        "number" => 50000
    ));
    foreach ($all_sites as $site) {
        switch_to_blog($site->blog_id);
        delete_option('pss_status');
        restore_current_blog(); // always restore after switch_to_blog()
    }
    delete_site_option('pss_stale_age');
    delete_site_option('pss_warn_interval');
    delete_site_option('pss_log_file');
}

// define action that is called by wp-cron
add_action('pss_sync_event', 'pss_process');

//  The business end of this mess...  
function pss_process()
{
    $pss_stale_age     = get_site_option('pss_stale_age');
    $pss_warn_interval = get_site_option('pss_warn_interval');
    $pss_deletion_wait = 3600 * 24 * 30 * 6;

    // probably need to break this up into smaller chunks somehow..
    $all_sites = get_sites(array("number" => 50000));
    
    pss_log("--------------------------------------------------------------------------------");
    pss_log("processing " . count($all_sites) . " blogs, with stale age of: " . $pss_stale_age . " and warn interval of: " . $pss_warn_interval);
    pss_log("--------------------------------------------------------------------------------");

    foreach ($all_sites as $site) {
        switch_to_blog($site->blog_id);
        $staleness          = time() - strtotime($site->last_updated);
        $since_last_warning = time() - $pss_status[timestamp];

        $pss_status = get_option('pss_status');        
        if ($pss_status == false) //initialize if it is not set..
        {
            $pss_status = array("flag" => 0, "timestamp" => time());
            update_option('pss_status', $pss_status);
        }
        
        if ($site->deleted == 1) {
            if ($pss_status[flag] <= 2) {
                pss_log("This site has been marked for deletion by a user.." . $site->blog_id . " - " . $site->path);
                // mark archived and update pss_status to 4 so the archiving script can handle this one
                update_option('pss_status', array("flag" => 4, "timestamp" => time()));
				pss_archive_blog($site);
            } else if ($pss_status[flag] == 5) {
                // has been archived and ready to purge
                pss_purge_site($site->blog_id);
            }
            restore_current_blog();
            continue; // skip the rest for this site..
        }
        
        $active_admins = pss_get_active_admins($site->blog_id);
        if (count($active_admins) == 0){   // if there are no reachable admins        
        	if ($pss_status[flag] <= 2){   // site not yet archived
				update_option('pss_status', array("flag" => 3, "timestamp" => time()));
				pss_archive_blog($site);

		        $subject = "No reachable users for " . $site->path . " (id:" . $site->blog_id . ")";
		        pss_log($subject);
		        $message = $subject;
		        $to      = "sites.tufts.edu@elist.tufts.edu";
		        if ($site->domain == "sites.tufts.edu")
        			wp_mail($to, $subject, $message, $headers);
        	}
            restore_current_blog();
            continue;
        }

		if ($pss_status[flag] == 3 &&  $since_last_warning> $pss_deletion_wait){
			update_option('pss_status', array("flag" => 4, "timestamp" => time()));
        	pss_delete_blog($site);   // soft delete...
            restore_current_blog();
            continue;
        }

        
        if ($staleness > $pss_stale_age) // if blog is stale, let's process it
      	{
      		$pss_status[timestamp] = time();
        	if ($pss_status[flag] == 0 ) {
                $pss_status[flag]++;
				update_option('pss_status', $pss_status);
                pss_notify_users($site->blog_id);
            }
            else {
                if ($since_last_warning > $pss_warn_interval) {
                    $pss_status[flag]++;
                    update_option('pss_status', $pss_status);
                    pss_notify_users($site->blog_id);
	                if ($pss_status[flag]==3)
						pss_archive_blog($site);
    	        }
            }
        }
        else //if blog is not stale
	    {
            if ($pss_status[flag] == 1 || $pss_status[flag] == 2){ // and was previously marked, we'll clear the flag
                update_option('pss_status', array("flag" => 0, "timestamp" => time()));
            }
	    }

        restore_current_blog(); // always restore after switch_to_blog()
    }
    pss_log("--------------------------------------------------------------------------------");
    pss_log("Completed this run.......");
    pss_log("--------------------------------------------------------------------------------");
}

function pss_archive_blog($site)
{
    global $wpdb;
	// this takes the blog off-line, and users will see a "blog unavailable message" 
	update_blog_status($site->blog_id, 'archived', 1);
	// we have to re-set the last_updated field, otherwise this makes the blog look like it has had activity
	$wpdb->update('wp_blogs', 
		array('last_updated' => $site->last_updated),
		array('blog_id' => $site->blog_id)
	);
}
function pss_delete_blog($site)
{	
    global $wpdb;
	// this takes the blog off-line, and users will see a "blog unavailable message" 
	update_blog_status($site->blog_id, 'deleted', 1);
	// we have to re-set the last_updated field, otherwise this makes the blog look like it has had activity
	$wpdb->update('wp_blogs', 
		array('last_updated' => $site->last_updated),
		array('blog_id' => $site->blog_id)
	);
}

function  pss_get_active_admins($blog_id)
{
	switch_to_blog($blog_id);
    $owner_email = get_option('admin_email');
    $owner       = get_user_by('email', $owner_email);
    // get all users who are admins, excluding the owner, so we don't duplicate them
    $admins      = get_users(array(
        'blog_id' => $blog_id,
        'role' => 'administrator',
        'exclude' => $owner->data->ID
    ));
    array_push($admins, $owner);

    $active_admins=array();
    foreach ($admins as $admin) {
    	if (get_user_meta($admin->data->ID, 'slu_user_status', true) == 'active') {
            array_push($active_admins, $admin);
        }
    }
    restore_current_blog(); // always restore after switch_to_blog()
    return $active_admins;
}

function pss_notify_users($blog_id)
{
    $sites = get_sites(array("ID" => $blog_id));
    $site  = $sites[0];
    
    switch_to_blog($blog_id);
    
    $pss_status = get_option('pss_status');
    $recipients = pss_get_active_admins($blog_id);    
    $to_addresses=array();
    foreach ($recipients as $recipient) {
    	array_push($to_addresses,$recipient->data->user_email);
    }

    pss_log("Notifying: blog_id: " . $blog_id . "\tStatus: " . $pss_status[flag] . "\tpath: " . $site->path . "\tStatus: " . $pss_status[flag] . "admins: [" . implode(',', $to_addresses) . "]");
    
    $headers[] = "From: " . $site->domain . " cleanup robot <sites.tufts.edu@elist.tufts.edu>";
    $headers[] = "Content-Type: text/html; charset=UTF-8";
    $headers[] = "Reply-To: edtech@tufts.edu";
    $headers[] = "Bcc: sites.tufts.edu@elist.tufts.edu";
    $to        = $to_addresses;
    $subject   = "Inactive WordPress Site (Action requested)";
    $message   = "<!DOCTYPE html>
<html>
<head></head>
<body>
<h2>Inactive WordPress Site</h2>
<p>You are receiving this email because you are an administrator or site owner of the following site:</p>
<p><a href=\"##BLOG_URL##\">##BLOG_URL##</a></p>
<p>According to our records, this site has not been updated since ##BLOG_LASTUPDATE##.  This site will soon be disabled and then deleted if no action is taken.</p>
<p>If this site is no longer needed you can delete it yourself here:</p>
<p><a href=\"##BLOG_URL##wp-admin/ms-delete-site.php\">##BLOG_URL##wp-admin/ms-delete-site.php</a></p>
<p>If you would like to keep this site, please login and take action (simply resaving an existing page/post is sufficient)</p>
</body>
</html>";
    
    
    if (is_readable(__DIR__ . "/email_template/warn.html"))
        $message = file_get_contents(__DIR__ . "/email_template/warn.html");
    
    switch ($pss_status[flag]) {
        case 1:
            $subject = "Inactive WordPress Site (Action requested)";
            break;
        case 2:
            $subject = "Inactive WordPress Site (Second warning)";
            break;
        case 3:
            $subject = "Inactive WordPress Site (Final Notice)";
            break;
        default:
            pss_log("we don't send notifications for this flag level: " . $pss_status[flag] . " site:" . $site->path . " id: " . $blog_id);
            return;
    }
    
    $pss_dates = pss_get_current_blog_archive_delete_dates();
    $message   = preg_replace('/##BLOG_ARCHIVEDATE##/', date("Y-m-d", $pss_dates[archive_date]), $message);
    $message   = preg_replace('/##BLOG_DELETEDATE##/', date("Y-m-d", $pss_dates[delete_date]), $message);
    $message   = preg_replace('/##BLOG_URL##/', "https://" . $site->domain . $site->path, $message);
    $message   = preg_replace('/##BLOG_LASTUPDATE##/', $site->last_updated, $message);
    $message   = preg_replace('/##BLOG_DOMAIN##/', $site->domain, $message);
    
    if ($site->domain == "sites.tufts.edu")
        wp_mail($to, $subject, $message, $headers);
    restore_current_blog(); // always restore after switch_to_blog()
}

function pss_get_current_blog_archive_delete_dates()
{
    $pss_warn_interval = get_site_option('pss_warn_interval');
    $pss_status        = get_option('pss_status');
    $flag              = $pss_status[flag];
    if ($flag == 1 || $flag == 2 || $flag == 3) {
        $pss_archive_date = $pss_status[timestamp] + $pss_warn_interval * (3 - $flag);
        $pss_delete_date  = $pss_status[timestamp] + $pss_warn_interval * (5 - $flag);
    } else {
        // this function gets called by any admin page, so we don't wanna log this every time...
        //pss_log("we don't send notifications for this flag level");
        return false;
    }
    return array(
        "archive_date" => $pss_archive_date,
        "delete_date" => $pss_delete_date
    );
}

// generic php error log is noisy and doesn't timestamp, making it tough to track actions.  This function just
// isolates our messages to a separate file, if it's writable.
function pss_log($msg)
{
    $pss_log_location = __DIR__ . "/logs/pss.log";
    if (is_writable($pss_log_location)) {
        error_log("[" . date("Y-m-d H:i:s T") . "] - " . $msg . "\n", 3, $pss_log_location);
    } else {
        error_log("[" . date("Y-m-d H:i:s T") . "]-PurgeStaleSites- " . $msg . "\n");
    }
}

// actual deletion of site..   Logs deletion and sends email.
function pss_purge_site($blog_id)
{
    $sites = get_sites(array(
        "ID" => $blog_id
    ));
    $site  = $sites[0];
    
    $headers[] = "From: sites.tufts.edu cleanup robot <noreply@tufts.edu>";
    $to        = "sites.tufts.edu@elist.tufts.edu";
    $subject   = "Site purged: blog: " . $site->blog_id . " path: " . $site->path;
    $message   = $subject;
    if ($site->domain == "sites.tufts.edu")
        wp_mail($to, $subject, $message, $headers);
    pss_log($message);
    wpmu_delete_blog($blog_id, true);
    pss_log("deletion complete for " . $site->path . " id: " . $site->blog_id);
}


// adds a warning to the dashboard of a site when the site is in danger of being retired
add_action('admin_notices', 'pss_admin_notice');
function pss_admin_notice()
{
    $blog_id       = get_current_blog_id();
    $sites         = get_sites(array(
        "ID" => get_current_blog_id()
    ));
    $site          = $sites[0];
    $pss_status    = get_option('pss_status');
    $pss_dates     = pss_get_current_blog_archive_delete_dates();
    $pss_stale_age = get_site_option('pss_stale_age');
    
    $class = 'notice notice-warning';
    $message .= "This site hasn't been updated since " . $site->last_updated . ".  If you would like to keep this site active, please create or edit a post/page.";
    
    // if we're halfway-to-stale or more, we'll put a warning on the blog's admin page.
    if ((time() - strtotime($site->last_updated)) > $pss_stale_age / 2) {
        // if we're past stale age, upgrade to error (red) and give dates of impending deletion
        if ((time() - strtotime($site->last_updated)) > $pss_stale_age) {
            $message = "<h3>Action required</h3>" . $message;
            $message .= "<br/>If you take no action, this site will be <strong>disabled</strong> on " . date("Y-m-d", $pss_dates[archive_date]) . " and <strong>deleted</strong> on " . date("Y-m-d", $pss_dates[delete_date]);
            $class = 'notice notice-error';
        }
        printf('<div class="%1$s"><p>%2$s</p></div>', $class, $message);
    }
}

// when a site is updated, immediately reset the status 
add_action('wpmu_blog_updated', 'pss_site_updated', 10, 1);
function pss_site_updated($blog_id)
{
    switch_to_blog($blog_id);
    $pss_status = array(
        "flag" => 0,
        "timestamp" => time()
    );
    update_option('pss_status', $pss_status);
    restore_current_blog(); // always restore after switch_to_blog()
}

// show status of a blog in the network admin sites page:
add_filter('wpmu_blogs_columns', 'pss_add_blog_status_column');
function pss_add_blog_status_column($columns)
{
    $columns['pss_status'] = 'PSS Status';
    return $columns;
}

// populate status column in network admin sites page
add_action('manage_sites_custom_column', 'pss_populate_blog_status_column', 10, 2);
function pss_populate_blog_status_column($col_name, $blog_id)
{
    if ($col_name == 'pss_status') {
        switch_to_blog($blog_id);
        $pss_status = get_option('pss_status');
        switch ($pss_status[flag]) {
            case 0:
                $message = "active";
                break;
            case 1:
                $message = "warned";
                break;
            case 2:
                $message = "warned2";
                break;
            case 3:
                $message = "archived";
                break;
            case 4:
                $message = "deleted";
                break;
            case 5:
                $message = "ready_to_purge";
                break;
            default:
                $message = "no status";
        }
        if (empty($pss_status))
            $message = "no status";
        
        echo "<p>$message</p>";
        restore_current_blog(); // always restore after switch_to_blog()
    }
}
?>