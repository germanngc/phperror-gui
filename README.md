# phperror-gui (v2)

A clean and effective single-file GUI for viewing entries in the PHP error log, allowing for filtering by path and by type.

### NOTE: 2020-04-16

Adapted to work with multiple logs and structure in AWS Cloud Watch format Error Log

If you like to donate the original creator you can do it here: [Donate](https://flattr.com/submit/auto?user_id=acollington&url=https://github.com/amnuts/phperror-gui&title=phperror-gui&language=&tags=github&category=software)

### Getting Started

1. Simply to copy/paste or download the phperror-gui/index.php to your server.

### usage

Simply load the script up in your browser and it should show you the entries from the PHP log file.  It will find the error log from the ini settings, though if you want to specify the log file you can change the `$error_log` variable to the path of your error log file.

You can select the types of errors you want displaying, sort in different ways or filter based on the path of the file producing the error (as recoded in the log).

The interface will also attempt to show you the snippet of code where the error has occurred and also the stack trace if it's recorded in the log file.

### cache

There is a very rudimentary option to cache the results.  This is set by using the $cache variable and setting it to the path of the cache file (must be a writable location). It will store the results of the file scan and then the position in the file it read up to. On subsequent reads of the file it will seek to that position and start to read the file. This works so long as you are not doing log rotation as the seek position could suddenly become much greater than the file size. So it's only recommended that you use the cache if you keep the one log file.

# License

MIT: http://acollington.mit-license.org/
