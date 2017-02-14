<?php
/*
   Plugin Name: WP Purge Stale Sites
   Description: purge stale sites in a network installation
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
                        $function='pss_init' );
}
        $pss_stale_age=2*365*24*3600;



function pss_init()
{
//pss_remove_options();
//	pss_main();
pss_notify_users(220);
}


function pss_remove_options(){
	echo "<h1>Purge Stale Sites</h1>";
	$all_sites=get_sites(array("number"=>1000000));
        foreach($all_sites as $site)
        {
                switch_to_blog($site->blog_id);
		delete_option('pss_status');
	}

}

function pss_main(){
	global $wpdb;

	echo "<h1>Purge Stale Sites</h1>";
	if($_GET['updated']=="true")
		echo "<div id='message' class='updated'>updated</div>";
	if(isset($_GET['error']))
		echo "<div id='message' class='error'>".urldecode($_GET['error'])."</div>";

	$pss_stale_age=2*365*24*3600;
	$pss_warn_interval=10;
	$all_sites=get_sites(array("number"=>100));

	print("<table><tr><th>blog_id</th><th>blog_name</th><th>last_modified</th><th>flag</th></tr>");

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
		print("<tr>");
                        print("<td>".$site->blog_id."</td>\n");
                        print("<td>".$site->path."</td>\n");
                        print("<td>".$site->last_updated."</td>\n");
                        print("<td>".$pss_status[flag]."</td>\n");
                print("</tr>\n");

	}
	print("</table>");

}	

function pss_notify_users($blog_id)
{
	switch_to_blog($blog_id);

        $pss_status=get_option('pss_status');
        if ($pss_status==false) 
        {
	   return;
        }

	$owner_email=get_option('admin_email');
	$admins=get_users( array('blog_id'=>$blog_id, 'role'=>'administrator'));
	$log_message='';
	foreach($admins as $admin)
	{
		if(strcasecmp($admin->data->user_email,$owner_email) !=0 )
			$log_message.=$admin->data->user_email.",";
	}

	$sites=get_sites(array("ID"=>get_current_blog_id()));
	$this_site=$sites[0];

	pss_log("blog_id: ".$blog_id."\tStatus: ".$pss_status[flag]."\tOwner: ".$owner_email."  admins: ".$log_message);	

	
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
        
        wp_mail($to,$subject,$message,$headers);



}

	
function pss_log($msg)
{
 	$pss_log_location=__DIR__."/logs/pss.log";
	error_log("[".date("Y-m-d H:i:s T")."] - ".$msg."\n",3,$pss_log_location);
}


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


	wp_mail($to,$subject,$message,$headers);

	wpmu_delete_blog($blog_id,true);
}




function pss_admin_notice_error() {
	$blog_id = get_current_blog_id();
	$sites=get_sites(array("ID"=>get_current_blog_id()));

	$class = 'notice notice-error';
	$message ="This site hasn't been updated since ".$sites[0]->last_updated.".  If you would like to keep this site active, please make an edit somewhere to keep this site from going stale.";
	$pss_status=get_option('pss_status');
	global $pss_stale_age;	
	if((time()-strtotime($sites[0]->last_updated))>$pss_stale_age)
		printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $message ); 
}
add_action( 'admin_notices', 'pss_admin_notice_error' );

// wpmu_blog_updated - action to consider to clear flag proactively
?>
