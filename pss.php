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
	After another interval, archive site and delete it

  pss_status flag values
  0 - initialized, site active
  1 - site stale, first warning issued
  2 - second warning issued
  3 - site has been marked archived
  4 - site has been marked deleted (to let external tool create .tar.gz before deleting).
  5 - site has been offloaded and ready for deletion 
*/


add_action('network_admin_menu','pss_setup_menu');

function pss_setup_menu(){
        add_menu_page( $page_title='PSS Plugin Page',
                        $menu_title='PSS Options',
                        $capability='manage_network',
                        $menu_slug='pss-options',
                        $function='pss_admin_page' );
}


function pss_admin_page()
{
//	pss_deactivate();
//	pss_activate();
//	pss_process();
//pss_notify_users(75);

echo "<h3>Purge Stale Sites</h3>";


	if($_GET['updated']=="true")
		echo "<div id='message' class='updated'>updated</div>";
	if(isset($_GET['error']))
		echo "<div id='message' class='error'>".urldecode($_GET['error'])."</div>";

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


add_action('admin_init','pss_admin_init');
function pss_admin_init()
{
	if ( ! is_super_admin() ) {
		wp_redirect( site_url() ); 
		exit;
	}
	
	 add_settings_section($id='pss_options',
                        $title='PSS Options',
                        $callback='',
                        $page='pss-options' );

        register_setting($option_group='pss_options',
			$option_name='pss_stale_age');
        register_setting($option_group='pss_options',
			$option_name='pss_warn_interval');

        add_settings_field($id='pss_stale_age',
			$title='Stale Age (seconds)',
			$callback='pss_stale_age_cb',
			$page='pss-options',
			$section='pss_options');
        add_settings_field($id='pss_warn_interval',
			$title='Warn Interval (seconds)',
			$callback='pss_warn_interval_cb',
			$page='pss-options',
			$section='pss_options');
	add_settings_field($id='pss_cron_enable',
			$title='Enable cron task',
			$callback='pss_cron_enable_cb',
			$page='pss-options',
			$section='pss_options');
}


function pss_stale_age_cb(){
	echo "<input type='text' name='pss_stale_age' value='".get_site_option('pss_stale_age')."'/>";
}
function pss_warn_interval_cb(){
	echo "<input type='text' name='pss_warn_interval' value='".get_site_option('pss_warn_interval')."'/>";
}

function pss_cron_enable_cb(){
	echo "<input type='checkbox' name='pss_cron_enable' ";
	if(get_site_option('pss_cron_enable')=="true")
		echo "checked";
	echo ">";
}

// this action runs when /wp-admin/network/edit.php is called with ?action=pss-options
// could use some validation...
add_action('network_admin_edit_pss-options', 'pss_save_network_options');
function pss_save_network_options(){
	
	$redirect_query_string_array=array( 'page' => 'pss-options');
        $error_msg="";
	// superadmins shouldn't ever see this, but if somehow they do, bail...
	if(!is_super_admin()){
		exit;
	}
	pss_log("Saving network options...");
	if(isset($_POST["pss_stale_age"])){
		update_site_option("pss_stale_age",$_POST["pss_stale_age"]);
	}
	if(isset($_POST["pss_warn_interval"])){
		update_site_option("pss_warn_interval",$_POST["pss_warn_interval"]);
	}
	if(isset($_POST["pss_cron_enable"])){
		sync_log("pss_cron_enable=".$_POST["pss_cron_enable"]);
                update_site_option("pss_cron_enable","true");
	        if (wp_next_scheduled ( 'pss_sync_event' )) {
                	wp_clear_scheduled_hook('pss_sync_event');
                }
		wp_schedule_event(time(), 'hourly', 'pss_sync_event');
	}
	else
	{
		update_site_option("pss_cron_enable","false");
		wp_clear_scheduled_hook('pss_sync_event');
	}
	
	$redirect_url=add_query_arg($redirect_query_string_array,(is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' )));
	sync_log("redirecting to: ".$redirect_url);
	wp_redirect($redirect_url);
	
	//must exit, otherwise another redirect will trump the one we set here.
	exit;
}

// define action that is called by wp-cron
add_action('pss_sync_event', 'pss_process');

register_activation_hook( __FILE__, 'pss_activation_hook' );
function pss_activation_hook()
{
        pss_log("Activating plugin...");
        pss_log("initializing options...");
	update_site_option('pss_stale_age',3600*24*365*2);
	update_site_option('pss_warn_interval',3600*24*14);
	update_site_option('pss_log_file',"./logs/pss.log");
}

register_deactivation_hook( __FILE__, 'pss_deactivation_hook' );
function pss_deactivation_hook(){
	pss_log("Deactivating plugin...");
	pss_log("Removing pss_status option from all blogs");
	$all_sites=get_sites();
        foreach($all_sites as $site)
        {
                switch_to_blog($site->blog_id);
		delete_option('pss_status');
		restore_current_blog();   // always restore after switch_to_blog()
	}
	delete_site_option('pss_stale_age');
	delete_site_option('pss_warn_interval');
	delete_site_option('pss_log_file');
}


function pss_process(){
	global $wpdb;

	$pss_stale_age=get_site_option('pss_stale_age');
	$pss_warn_interval=get_site_option('pss_warn_interval');
	$all_sites=get_sites(array("number"=>20));

	foreach($all_sites as $site)
	{
		switch_to_blog($site->blog_id);
		$pss_status=get_option('pss_status');
		if ($pss_status==false)  //initialize if it is not set..
		{
			$pss_status=array("flag"=>0,"timestamp"=>time());
			update_option('pss_status',$pss_status);
		}

                if((time()-strtotime($site->last_updated))>$pss_stale_age)    // if blog is stale, let's process it
		{
			switch ($pss_status[flag])
			{
				case 0:  // newly stale blog, lets warn
					$pss_status[timestamp]=time();
					$pss_status[flag]++;
					update_option('pss_status',$pss_status);
					pss_notify_users($site->blog_id);
				break;
				case 1:  //  already warned once, lets warn again if enough time elapsed
					if((time()-$pss_status[timestamp])>$pss_warn_interval)
					{
						$pss_status[timestamp]=time();
						$pss_status[flag]++;
						update_option('pss_status',$pss_status);
						pss_notify_users($site->blog_id);
					}
				break;
				case 2:  //  warned twice, now we "archive" to take site offline, if enough time elapsed
					if((time()-$pss_status[timestamp])>$pss_warn_interval)
					{   
						$pss_status[timestamp]=time();
						$pss_status[flag]++;
						update_option('pss_status',$pss_status);
						pss_notify_users($site->blog_id);

						// this takes the blog off-line, and users will see a "blog unavailable message" 
						update_blog_status($site->blog_id,'archived',1);
						// we have to re-set the last_updated field, otherwise this makes the blog look like it has had activity
						$wpdb->update('wp_blogs', array('last_updated'=>$site->last_updated),array('blog_id'=>$site->blog_id));
					}   
				break;
				case 3:  // "archived" site..  now we mark it as deleted  so an external script or manual process an make a copy and then purge the site.
					if((time()-$pss_status[timestamp])>$pss_warn_interval*2)
					{
						$pss_status[timestamp]=time();
                                                $pss_status[flag]++;
                                                update_option('pss_status',$pss_status);

                                                // this this marks the site
						update_blog_status($site->blog_id,'deleted',1);
                                                // we have to re-set the last_updated field, otherwise this makes the blog look like it has had activity
                                                $wpdb->update('wp_blogs', array('last_updated'=>$site->last_updated),array('blog_id'=>$site->blog_id));
					}
				break;
				case 4:
					//noting to do here... just a place holder for a state that currently requires no action..   This is handled by a shell script
				break;
				case 5:  // once the external script archives the attachments, dumps the tables, and updates the flag to 5, then we can actually purge the site from 
					pss_purge_site($site->blog_id);
				break;
				default:  // Shouldn't get here... just throw an error.
					pss_log("Unexpected value flag=".$pss_status[flag]." for: ".$site->blog_id);
			}
		}
		else  //if blog is not stale
		{
			if($pss_status[flag]!=0)  // and was previously marked, we'll clear the flag
			{
                                $pss_status[timestamp]=time();
                                $pss_status[flag]=0;
				update_option('pss_status',$pss_status);
			}
		}
	restore_current_blog();   // always restore after switch_to_blog()
	}
}	


//
function pss_notify_users($blog_id)
{
	switch_to_blog($blog_id);

        $pss_status=get_option('pss_status');
        if ($pss_status==false) 
        {
		restore_current_blog();   // always restore after switch_to_blog()
		return;
        }

	$owner_email=get_option('admin_email');
	$owner=get_user_by('user_email',$owner_email);

	// get all users who are admins, excluding the owner, so we don't duplicate them
	$admins=get_users( array('blog_id'=>$blog_id, 'role'=>'administrator', 'exclude'=>$owner->data->ID));
	array_push($admins,$owner);
	$recipients=array();

	foreach($admins as $admin)
	{
		// if user is not marked as "inactive", add their email address to recipient list.
		// TODO:  make filter, so other methods of determing inactive users can be used
		if( get_user_meta($admin->data->ID,'slu_user_status',true)!=='inactive')
		{
			array_push($recipients,$admin->data->user_email);	
		}
	}

	if(empty($recipients))
	{
		pss_log("No reachable users for ".$blog_id);
	}

	$sites=get_sites(array("ID"=>$blog_id));
	$this_site=$sites[0];

	pss_log("Notifying: blog_id: ".$blog_id."\tpath: ".$this_site->path."\tStatus: ".$pss_status[flag]."\tOwner: ".$owner_email."  admins: [".implode(',',$recipients)."]");	
	
	$headers[] = "From: ".$this_site->domain." cleanup robot <noreply@tufts.edu>";
        $headers[] = "Content-Type: text/html; charset=UTF-8";
        $to="ian.altgilbers@tufts.edu";
        $subject="Inactive WordPress Site (Action requested)";
        $message="<!DOCTYPE html>
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
     
	if (is_readable(__DIR__."/email_template/warn.html"))
		$message=file_get_contents(__DIR__."/email_template/warn.html");

	$pss_warn_interval=get_site_option('pss_warn_interval');
	switch($pss_status[flag])
	{
		case 1: 
			$subject="Inactive WordPress Site (Action requested)";
			break;
        	case 2: 
			$subject="Inactive WordPress Site (Second warning)";
			break;
        	case 3: 
			$subject="Inactive WordPress Site (Final Notice)";
			break;
		default: 
			pss_log("we don't send notifications for this flag level");
			return;
	}

	$pss_dates=pss_get_archive_delete_dates($blog_id);
	$message=preg_replace('/##BLOG_ARCHIVEDATE##/',date("Y-m-d",$pss_dates[archive_date]),$message);  
	$message=preg_replace('/##BLOG_DELETEDATE##/',date("Y-m-d",$pss_dates[delete_date]),$message);  
        $message=preg_replace('/##BLOG_URL##/',"https://".$this_site->domain.$this_site->path,$message);
        $message=preg_replace('/##BLOG_LASTUPDATE##/',$this_site->last_updated,$message);
        $message=preg_replace('/##BLOG_DOMAIN##/',$this_site->domain,$message);
 
	//wp_mail($to,$subject,$message,$headers);
	restore_current_blog();   // always restore after switch_to_blog()
}

function pss_get_archive_delete_dates($blog_id)
{
	$pss_warn_interval=get_site_option('pss_warn_interval');
	$pss_status=get_option('pss_status');
	if($pss_status[flag]==1) {
	        $pss_archive_date=$pss_status[timestamp]+$pss_warn_interval*2;
	        $pss_delete_date=$pss_status[timestamp]+$pss_warn_interval*4;
	}
	else if($pss_status[flag]==2) {
		$pss_archive_date=$pss_status[timestamp]+$pss_warn_interval*1;
		$pss_delete_date=$pss_status[timestamp]+$pss_warn_interval*3;
	}
	else if($pss_status[flag]==3) {
		$pss_archive_date=$pss_status[timestamp];
		$pss_delete_date=$pss_status[timestamp]+$pss_warn_interval*2;
	}
	else {
        	pss_log("we don't send notifications for this flag level");
        	return false;
	}
	return array("archive_date"=>$pss_archive_date,"delete_date"=>$pss_delete_date);
}

	
// generic php error log is noisy and doesn't timestamp, making it tough to track actions.  This function just
// isolates our messages to a separate file, if it's writable.
function pss_log($msg)
{
 	$pss_log_location=__DIR__."/logs/pss.log";
	if(is_writable($pss_log_location))
	{
		error_log("[".date("Y-m-d H:i:s T")."] - ".$msg."\n",3,$pss_log_location);
	}
	else
	{
		error_log("[".date("Y-m-d H:i:s T")."]-PurgeStaleSites- ".$msg."\n");
	}
}


// actual deletion of site..   Logs deletion and sends email.
function pss_purge_site($blog_id)
{
	$email_to=array();
	$admins=get_super_admins();
	foreach($admins as $admin)
	{
		$user=get_user_by('login',$admin);
		array_push($email_to,$user->user_email);
	}
	error_log(implode(',',$email_to));
	$headers[] = "From: sites.tufts.edu cleanup robot <noreply@tufts.edu>";
	//$headers[] = "Content-Type: text/html; charset=UTF-8";
	$to="ian.altgilbers@tufts.edu";
	$subject="blog: ".$blog_id." path: ".$site->path." purged";
        $message=$subject

	wp_mail($to,$subject,$message,$headers);
	pss_log($message);
	//wpmu_delete_blog($blog_id,true);
}


// adds a warning to the dashboard of a site when the site is in danger of being retired
function pss_admin_notice() {
	$blog_id = get_current_blog_id();
	$sites=get_sites(array("ID"=>get_current_blog_id()));
	$site=$sites[0];
        $pss_status=get_option('pss_status');
	$pss_dates=pss_get_archive_delete_dates($blog_id);
	

	$class = 'notice notice-warning';
	$message.="This site hasn't been updated since ".$site->last_updated.".  If you would like to keep this site active, please create or edit a post/page.";
	global $pss_stale_age;	

	// if we're halfway-to-stale or more, we'll put a warning on the blog's admin page.
	if((time()-strtotime($site->last_updated))>$pss_stale_age/2)
	{
	        if((time()-strtotime($site->last_updated))>$pss_stale_age)
		{
			$message="<h3>Action required</h3>".$message;
			$message.="<br/>If you take no action, this site will be <strong>disabled</strong> on ".date("Y-m-d",$pss_dates[archive_date])." and <strong>deleted</strong> on ".date("Y-m-d",$pss_dates[delete_date]);
			$class='notice notice-error';
		}
		printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $message ); 
	}
}
add_action( 'admin_notices', 'pss_admin_notice' );

add_action('wpmu_blog_updated','pss_site_updated',10,1);
function pss_site_updated($blog_id)
{
	switch_to_blog($blog_id);
       	$pss_status=array("flag"=>0,"timestamp"=>time());
        update_option('pss_status',$pss_status);
	restore_current_blog();   // always restore after switch_to_blog()
}


// show status of a blog in the network admin sites page:
add_filter('wpmu_blogs_columns','pss_add_blog_status_column');
function pss_add_blog_status_column($columns)
{
    $columns['pss_status'] = 'PSS Status';
    
    return $columns;
}
add_action('manage_sites_custom_column','pss_populate_blog_status_column',10,2);
function pss_populate_blog_status_column($col_name,$blog_id)
{
	if($col_name=='pss_status')
	{
	        switch_to_blog($blog_id);
		$pss_status=get_option('pss_status');
		switch ($pss_status[flag])
		{
			case 0: $message="active";break;
			case 1: $message="warned";break;
			case 2: $message="warned2";break;
			case 3: $message="archived";break;
			case 4: $message="deleted";break;
			case 5: $message="ready_to_purge";break;
			default: $message="no status";
		}
		if(empty($pss_status))
			$message="no status";

		echo "<p>$message</p>";
		restore_current_blog();   // always restore after switch_to_blog()
	}
}
?>
