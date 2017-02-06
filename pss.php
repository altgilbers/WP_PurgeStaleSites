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




function pss_remove_options(){
	echo "<h1>Purge Stale Sites</h1>";
	$all_sites=get_sites(array("number"=>1000000));
        foreach($all_sites as $site)
        {
                switch_to_blog($site->blog_id);
		delete_option('pss_status');
	}

}
function pss_init(){
	echo "<h1>Purge Stale Sites</h1>";
	if($_GET['updated']=="true")
		echo "<div id='message' class='updated'>updated</div>";
	if(isset($_GET['error']))
		echo "<div id='message' class='error'>".urldecode($_GET['error'])."</div>";

	$num_days=365*4;
	$all_sites=get_sites(array("number"=>100));
	print_r($all_sites);

	foreach($all_sites as $site)
	{
		pss_notify_users($site->blog_id);		
		switch_to_blog($site->blog_id);
		$pss_status=get_option('pss_status');
		if ($pss_status==false)
		{
			$pss_status=array("flag"=>0,"timestamp"=>time());
		}

		print("<p>".$site->path." -- ".((time()-strtotime($site->last_updated))/3600/24)." --  ".$pss_status[flag]."</p>\n");

		// if blog is stale, let's process it
                if((time()-strtotime($site->last_updated))>3600*24*$num_days)
		{
	                update_option('pss_status',array("flag"=>++$pss_status[flag],"timestamp"=>time()));
			pss_log($site->path." is old\n");
		}


	}

}	

function pss_notify_users($blog_id)
{
	switch_to_blog($blog_id);
	$owner_email=get_option('admin_email');
	$admins=get_users( array('blog_id'=>$blog_id, 'role'=>'administrator'));
	$log_message='';
	foreach($admins as $admin)
	{
		if(strcasecmp($admin->data->user_email,$owner_email) !=0 )
			$log_message.=$admin->data->user_email.",";
	}
	pss_log("blog_id: ".$blog_id."\tOwner: ".$owner_email."  admins: ".$log_message);	

}

	
function pss_log($msg)
{
 	$pss_log_location=__DIR__."/logs/pss.log";
	error_log("[".date("Y-m-d H:i:s T")."] - ".$msg."\n",3,$pss_log_location);
}


?>
