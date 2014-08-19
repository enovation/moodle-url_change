#moodle-url_change
--------------------------------

A script to change hardcoded URLs in Moodle databases

##Description

urlmove is a commandline utility that can be used to replace all occurrences of a specified URL with another throughout a Moodle installation database.

In order to use the script, please follow the steps:

* Delete replace.sql if it exists.
* Delete revert.sql if it exists.
* Create / update the file '''urlmove_fields_X.X''', where '''X.X''' is the Moodle version (e.g., 2.4). This file specifies the tables / columns to consider when generating the SQL script to update fields (i.e., when using the '''replace''' and '''revert''' commands; see below). Note that this does not apply to the '''search''' command - which will examine all tables and columns.
* Create / update the file '''urlmove_exclude_X.X''', where '''X.X''' is the Moodle version (e.g., 2.4). This file specifies which tables and / or columns to exclude for the search function. The format is one string of the form '''tablename.columnname''' per line. Note that the table name or the column name may be '*' in any instance, to match all tables or columns. For example:

mdl_upgrade_log.details
mdl_upgrade_log.backtrace
*.targetversion
mdl_sessions.*

* Then execute the apprpriate command. There are three commands available:

- search - searches for the target URL and outputs any matches found. All tables and columns will be examined for matches of the URL, except those specified in the exclude file or tables which do not have a field named '''id''', and both plaintext and base64 encoded representations of the string will be searched for.
- replace - generates a file '''replace.sql''' containing a set of SQL statements which, if executed against the database as-is, will replace occurrences of the old URL with the new URL. Both plaintext and base64 encoded representations of the source string will be matched, and base64 representations of the old URL will be replaced by the base64 encoded representation of the new URL.  Only tables which do not have a field named '''id''' and tables / columns which are both specified in the fields file and not specified in the excluded file will be considered.
- revert - generates a file '''revert.sql''' containing  a set of SQL statements which, if executed immediately after '''replace.sql''' (and assuming no external DB updates) will return the database to the initial state. Only tables which have a field named '''id''' and tables / columns which are specified in the fields file and not specified in the excluded file will be considered.

##Usage

urlmove.php [OPTIONS] replace URL1 URL2

urlmove.php [OPTIONS] revert URL1 URL2

urlmove.php [OPTIONS] search URL1

Options: 

	-h, --dbhost [<value>]	database hostname (default 'localhost')
	-d, --dbname <value>	database name
	-u, --dbuser <value>	database user
	-p, --dbpassword <value>	database password
	-t, --dbprefix [<value>]	table prefix (default 'mdl_')
	-v, --version [<value>]	moodle version. If not set, try to determine Moodle version from database in 'config' table
	-r, --with-revert	'replace' command generates both files: revert.sql and out.sql
	-s, --strip-newlines	strip new line characters from search results
	--help	show help
