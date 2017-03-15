#!/bin/bash

# this script goes through all sites in the wordpress database and checks to see if they need
# to be archived (checks pss_status in each blogs wp_xxx_options table, then it mysqldumps and tars
#

# db password
mysql_pass=
# db userame
mysql_user=
# db hostname
mysql_host=
# db name
mysql_DB=
# desination dir to save archives in
archive_dir=
# wordpress root directory
wordpress_dir=
# email address to notify of failures
notify_email=


mysql_short="mysql -h $mysql_host -u $mysql_user -p$mysql_pass -B --disable-column-names -D $mysql_DB -e"



# get list of blogs that are marked archived and delted
blogs_to_process=`$mysql_short 'select blog_id from wp_blogs where deleted=1 and archived=1;'`

echo "`date +"%F %X"` - preparing to process ${blogs_to_process}" >> ${archive_dir}/pss_archive.log

for x in $blogs_to_process
do
	# check blog status... if 4 archive, otherwise do nothing
	blog_status=`$mysql_short "select option_value from wp_${x}_options where option_name='pss_status'"`
	if [[ ! "$blog_status" =~ "flag\";i:4" ]]
	then
		echo "`date +"%F %X"` - $x archived and deleted, but status not 4..  {${blog_status}}" >> ${archive_dir}/pss_archive.log
		continue
	fi
	
	#get tables to dump - if a plugin creates a new table that doesn't use the standard prefix it will be missed 
	tables=`$mysql_short "show tables like 'wp\_$x\_%'"`
	
	[[ -d ${archive_dir}/blog_${x} ]] || mkdir -p ${archive_dir}/blog_${x}
	
	echo "`date +"%F %X"` - mysqldump of $x" >> ${archive_dir}/pss_archive.log
	mysqldump -u $mysql_user -p$mysql_pass $mysql_DB $tables > ${archive_dir}/blog_${x}/tables.sql
	mysqldump_return_code=$?
	

        echo "`date +"%F %X"` - tar of $x" >> ${archive_dir}/pss_archive.log
	tar czf ${archive_dir}/blog_${x}/files.tar.gz ${wordpress_dir}/wp-content/blogs.dir/${x}
	tar_return_code=$?

	
	# if both archive steps completed, we can mark the site for purging
	if [ ${mysqldump_return_code} == 0 -a  ${tar_return_code} == 0 ]
	then
	        echo `date +"%F %X"` - $x archived successfully >> ${archive_dir}/pss_archive.log	
		option_value="a:2:{s:4:\\\"flag\\\";i:5;s:9:\\\"timestamp\\\";i:`date +%s`;}"
		$mysql_short "update wp_${x}_options set option_value='${option_value}' where option_name='pss_status';"
		if [ $? == 0 ]
		then
		       echo "`date +"%F %X"` - $x marked for final deletion" >> ${archive_dir}/pss_archive.log
		fi

	else
	        echo "`date +"%F %X"` - $x not archived successfully" >> ${archive_dir}/pss_archive.log
		echo $x | mail -s "Blog $x failed to archive" $notify_email
	fi

done

