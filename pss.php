<?php
/*
   Plugin Name: Purge Stale Sites
   Description: Purge stale sites in a network installation.
   Author: Ian Altgilbers  ian@altgilbers.com
   Source: https://github.com/altgilbers/WP_PurgeStaleSites
   Version: 0.1


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



//if(is_super_admin())

add_action('network_admin_menu','pss_setup_menu');

function pss_setup_menu(){
        add_menu_page( $page_title='PSS Plugin Page',
                        $menu_title='PSS Options',
                        $capability='manage_network',
                        $menu_slug='pss-options',
                        $function='pss_admin_page' );
}


$pss_stale_age=2*365*24*3600;



function pss_admin_page()
{
//pss_remove_options();
//	pss_process();
//pss_notify_users(220);
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
}


function pss_stale_age_cb(){
	echo "<input type='text' name='pss_stale_age' value='".get_site_option('pss_stale_age')."'/>";
}
function pss_warn_interval_cb(){
	echo "<input type='text' name='pss_warn_interval' value='".get_site_option('pss_warn_interval')."'/>";
}



register_activation_hook( __FILE__, 'pss_activate' );
function pss_activate()
{
        pss_log("Activating plugin...");
        pss_log("initializing options...");
	update_site_option('pss_stale_age',3600*24*365*2);
	update_site_option('pss_warn_interval',3600*24*30);
	update_site_option('pss_log_file',"./logs/pss.log");
}

register_deactivation_hook( __FILE__, 'pss_deactivate' );
function pss_deactivate(){
	pss_log("Deactivating plugin...");
	pss_log("Removing pss_status option from all blogs");
	$all_sites=get_sites();
        foreach($all_sites as $site)
        {
                switch_to_blog($site->blog_id);
		delete_option('pss_status');
	}
	delete_site_option('pss_stale_age');
	delete_site_option('pss_warn_interval');
	delete_site_option('pss_log_file');
}


function pss_process(){
	global $wpdb;

	
	$pss_stale_age=get_site_option('pss_stale_age');
	$pss_warn_interval=get_site_option('pss_warn_interval');

	$all_sites=get_sites(array("number"=>100));

	foreach($all_sites as $site)
	{
		//pss_notify_users($site->blog_id);		
		switch_to_blog($site->blog_id);

		$pss_status=get_option('pss_status');
		if ($pss_status==false)  //initialize if it is not set..
		{
			$pss_status=array("flag"=>0,"timestamp"=>time());
			update_option('pss_status',$pss_status);
		}


		// if blog is stale, let's process it
                if((time()-strtotime($site->last_updated))>$pss_stale_age)
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
						$ouptput=$wpdb->update('wp_blogs', array('last_updated'=>$site->last_updated),array('blog_id'=>$site->blog_id));
						error_log($output);
						$wpdb->print_error();

					}   
				break;

				case 3:  // "archived" site..  now we mark it as deleted  so an external script or manual process an make a copy and then purge the site.
					if((time()-$pss_status[timestamp])>$pss_warn_interval)
					{
						$pss_status[timestamp]=time();
                                                $pss_status[flag]++;
                                                update_option('pss_status',$pss_status);


                                                update_blog_status($site->blog_id,'deleted',1);
                                                // we have to re-set the last_updated field, otherwise this makes the blog look like it has had activity
                                                $ouptput=$wpdb->update('wp_blogs', array('last_updated'=>$site->last_updated),array('blog_id'=>$site->blog_id));
                                                error_log($output);
                                                $wpdb->print_error();

					}
				break;
				case 4:
					//noting to do here... just a place holder for a state that currently requires no action
				break;
				case 5:  // once the external script archives the attachments, dumps the tables, and updates the flag to 5, then we can actually purge the site from 
					pss_purge_site($site->blog_id);
				break;

				default:  // Shouldn't get here... just throw an error.
					pss_log("Default case... unexpected value of flag for: ".$site->blog_id);
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

	}

}	

function pss_notify_users($blog_id)
{
	switch_to_blog($blog_id);

        $pss_status=get_option('pss_status');
        if ($pss_status==false) 
        {
//	   return;
        }

	$owner_email=get_option('admin_email');
	$owner=get_user_by('user_email',$owner_email);
	//pss_log(print_r($owner,true));

	// get all users who are admins, excluding the owner, so we don't duplicate them
	$admins=get_users( array('blog_id'=>$blog_id, 'role'=>'administrator', 'exclude'=>$owner->data->ID));
	array_push($admins,$owner);

	$recipients=array();

	foreach($admins as $admin)
	{
		//pss_log(print_r($admin,true));
		// if user is not marked as "inactive", add their email address to recipient list
		if( get_user_meta($admin->data->ID,'lus_user_status',true)!=='inactive')
		{
			array_push($recipients,$admin->data->user_email);	
		}
	}

	$sites=get_sites(array("ID"=>$blog_id));
	$this_site=$sites[0];

	pss_log("blog_id: ".$blog_id."\tStatus: ".$pss_status[flag]."\tOwner: ".$owner_email."  admins: ".implode(',',$recipients));	

	
	$headers[] = "From: sites.tufts.edu cleanup robot <noreply@tufts.edu>";
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
      
$message=preg_replace('/##BLOG_URL##/',"https://".$this_site->domain.$this_site->path,$message);  
$message=preg_replace('/##BLOG_LASTUPDATE##/',$this_site->last_updated,$message);  
        
        //wp_mail($to,$subject,$message,$headers);



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
		error_log("[".date("Y-m-d H:i:s T")."] - ".$msg."\n");
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
	$headers[] = "Content-Type: text/html; charset=UTF-8";
	$to="ian.altgilbers@tufts.edu";
	$subject="This is a test";
//	$message="You can ignore this email if you aren't Ian... Ready to delete blog: ".$blog_id;
        $message="<!DOCTYPE html>
<html>
<head>
<title>Title of the document</title>
</head>

<body>
<h1>Attention required:</h1>
<p>The content of the document......<p>

<table>
<tr><td>boo</td><td>hoo</td></tr>
</table>
</body>

</html>";


	//wp_mail($to,$subject,$message,$headers);

	//wpmu_delete_blog($blog_id,true);
}



// adds a warning to the dashboard of a site when the site is in danger of being retired
function pss_admin_notice() {
	$blog_id = get_current_blog_id();
	$sites=get_sites(array("ID"=>get_current_blog_id()));
	$site=$sites[0];
        $pss_status=get_option('pss_status');

	$class = 'notice notice-warning';
	$message ="This site hasn't been updated since ".$site->last_updated.".  If you would like to keep this site active, please make an edit somewhere to keep this site from going stale.";
	global $pss_stale_age;	
	if((time()-strtotime($site->last_updated))>$pss_stale_age)
		printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $message ); 
}
add_action( 'admin_notices', 'pss_admin_notice' );

// wpmu_blog_updated - action to consider to clear flag proactively


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
			echo "no status";
		else
		//	echo "<p>$message ".date("Y-m-d H:i:s",$pss_status[timestamp])."</p>";
			echo "<p>$message</p>";
	}
}
?>
