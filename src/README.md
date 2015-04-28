OVERVIEW
========

PURPOSE
----------------------
The purpose for this fork is download-and-store data from Google Webmaster Tools with **daily granularity**: the original project **gwtdata.php** is great, but we solved some issues

CHANGES / IMPROVEMENTS
----------------------
* two more metrics have been added (TOTAL_PAGES, TOTAL_QUERIES). Those metrics should be the same although they have different URLs for retrieve.
* managing retry of downloads in case of errors, using a SQLite database to track download status
* using multiple Google accounts to optimize bandwidth usage and overcome Google download quota limit
* maximum number of downloadable metrics changed from 2 to 10
* downloading data in UTF-8 format instead of original's ISO-8859-1 for handling multi-language data
An additional difference is the naming of output files: we have cut the timestamp from filenames.

NEW FEATURES
------------
This project provides two stand-alone php scripts:
* **download.php**: downloads TOP_PAGES, TOP_QUERIES and TOTAL_PAGES metrics for the latest three months
* **post-to-elk.php**: loads downloaded data over elastic search installation (given IP:PORT)

HOW-TO
======

FILE LIST
---------
* **gwtdata.php**: php library for downloading GWT data, fork from original project
* **download_tracking.db**: SQLite database, it tracks download activities, it's needed by "download.php"
* **download.php**: php script to launch, it downloads GWT data depending on user accounts settings
* **post-to-elk.php**: php script to load data (previously downloaded by "download.php") on Elastic Search

PRE REQUISITES
--------------
* PHP 5.6.x
* PDO library for PHP
* XML library for PHP

e.g. to satisfy these on a YUM based linux distro, launch these commands:

    sudo rpm -Uvh http://mirror.webtatic.com/yum/el6/latest.rpm  
    sudo yum remove php-common
    sudo yum install php56w
    sudo yum install php56w-pdo
    sudo yum install php56w-xml
    php --version

INSTALL
-------
Put the 4 files enlisted in FILE LIST paragraph in a certain installation dir, we call this *$DIR* in this document.

CONFIGURATION
-------------
In order not to exceed download quota from GWT site, you need to configure the table `downloaders` by inserting rows like these:

    INSERT INTO downloaders (rank,email,password) VALUES (1, 'account1@gmail.com',' password1');
    INSERT INTO downloaders (rank,email,password) VALUES (2, 'account2@gmail.com', 'password2');
    INSERT INTO downloaders (rank,email,password) VALUES (3, 'account3@gmail.com', 'password3');
    INSERT INTO downloaders (rank,email,password) VALUES (4, 'account4@gmail.com', 'password4');
`rank` is a conventional value no more used: the priority is computed by how many time a single account has been used.

The sites that will be downloaded are determined by which sites are *owned* on **Google Webmaster Tools** by that user accounts. It is recommended to own the same websites for every user account specified in `downloaders` table.

Data downloaded will be relative to tables:
* **TOP_QUERIES**
* **TOP_PAGES**

PRACTICAL USAGE
================

DOWNLOAD CSV DATA
-----------------
    cd $DIR
    php download.php [start-date [end-date]]
both `start-date` and `end-date` are optional and in `YYYY-MM-DD` format:
* default value for `start-date` is the current date *minus* **3 months**
* default value for `end-date` is the *current date*

Data is downloaded **once**: a single download for each (date, table, site) will be tracked into **download_tracking.db** file.

The output is a series of directories put into **$DIR** for *each day*, e.g.:
* **GWT-2010-01-01**
* **GWT-2010-01-02**
* **GWT-2010-01-03**
* ...

Each of these directory will contain two types of CSV files for each site:
* **TOP_QUERIES**-*sitename*.**csv**
* **TOP_PAGES**-*sitename*.**csv**

UPLOAD KIBANA DATA
------------------
    cd $DIR
    php post-to-elk.php <server:port> [index-prefix]
* **server:port** points to the *elastich search* server
* **index-prefix** is an optional prefix that will form the index name on elastic search

### Index names on Elastic Search
    [index-prefix-]<table-name>-YYYY-MM-DD

*for example*:

    top_queries-2010-01-01
    top_pages-2010-01-01
    x1-top_queries-2010-01-01
