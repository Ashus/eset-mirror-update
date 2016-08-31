**ESET mirror update 2.0**

This is a tool to create and keep your own ESET mirror update server up to date. You can use a valid purchased key and password or use a function to retrieve credentials automatically. This is an original work completely written by me.

**Requirements:**

PHP 5.4+/7, mod_curl, php_rar v2+ or unrar-free.

**Installation:**
- Download and install php pecl module rar v2 or higher -or- linux executable unrar-free (Debian package name is unrar-free; it should be compatible with other distributions as well though).
- Download the source, adjust the config, use `chmod +x esetupdate.php getFreshCredentials.php` and test the script.
- Configure the webserver to access the mirror update directory at certain path, .htpasswd can be set up to protect the mirror.
- Add the script execution hourly to crontab (ex. `15 * * * * root /root/scripts/esetupdate.php >> /var/log/esetupdate.log &`).
- Configure ESET update server on client computers to use local mirror.

**Features include:**
- downloading update mirrors for ESET versions 3/4/5, 9 or both
- cleaning old and unreferenced update files
- extra function to retrieve fresh update credentials if you do not set your own or current don't work anymore
- extensive info in logfile, counting download sizes
- better security while using mod_rar and having exec function blocked
- improved detection of incompletely downloaded files
- forcing full check on next run if some files look suspicious (ensures quality, updating from server works fine on unstable connections)
- automatic selecting of eset servers, 3 tries further harden stability
- can be set up on Linux or even Windows (with some effort)


Created by Ashus, all rights reserved

https://ashus.ashus.net/viewtopic.php?f=3&t=153