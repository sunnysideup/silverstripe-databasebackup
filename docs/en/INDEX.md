database backup
================================================================================


[![Build Status](https://travis-ci.org/sunnysideup/silverstripe-databasebackup.svg?branch=master)](https://travis-ci.org/sunnysideup/silverstripe-databasebackup)


Backup, download or restore an entire database from within the Silverstripe CMS.
Great for making a backup before a big change.

The module provide a modeladmin interface for creating, downloading and restoring backups.

Developer
-----------------------------------------------
Nicolaas Francken [at] sunnysideup.co.nz


Requirements
-----------------------------------------------
see composer.json

There are two other BIG requirements:

- Your PHP environment allows you to run the exec command.
- You are running a standard *nix flavour

The commands being run are:
 - mysqldump ....
 - mysql ... < myFile

With or without compression.

Compression Support
-----------------------------------------------
This module is supports `gzip` compression only at the moment.


Warning
-----------------------------------------------
This module is a DANGEROUS module because it runs PHP code on the command line
and because you can replace the entire database with a (faulty / out-of-date) backup.



Installation Instructions
-----------------------------------------------
1. Find out how to add modules to SS and add module as per usual.

2. Review configs and add entries to mysite/_config/config.yml
(or similar) as necessary.
In the _config/ folder of this module
you can usually find some examples of config options (if any).

3. make sure to review databasebackup.yml.example file.

4. it is a requirement to set the filelocation for the
backup file (e.g. /var/backups/mybackupfile.sql).
Dont worry about adding the .gz bit for gzip compression.
