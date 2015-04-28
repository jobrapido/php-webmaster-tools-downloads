<?php

/*
PARAMETERS:
- $startDate: data da cui iniziare a controllare se ci sono o no dati


CREATE TABLE datelog (
	country VARCHAR(10),
	logtype VARCHAR(50),
	timestamp DATE,
  filesize NUMBER,
  rowcount NUMBER,
	got NUMBER(1),
  downloader VARCHAR(50)
);

--ALTER TABLE datelog ADD COLUMN downloader VARCHAR(50);


CREATE TABLE downloaders (
  rank NUMBER,
  email VARCHAR(50),
  password VARCHAR(50)
);

CREATE TABLE status (
 last_downloader VARCHAR(50)
);

CREATE TABLE user_bandwidth (
  time_stamp timestamp,
  email varchar(50)
);


--INSERT INTO user_bandwidth (time_stamp, email) VALUES (CURRENT_TIMESTAMP, 'ex@am.ple');
--SELECT abs(julianday((SELECT t FROM a WHERE id=2))-julianday(CURRENT_TIMESTAMP))

INSERT INTO downloaders (email, password, rank) VALUES ('email01@gmail.com','password1',1);
INSERT INTO downloaders (email, password, rank) VALUES ('email02@gmail.com','password2',2);

INSERT INTO status (last_downloader) VALUES ('');

INSERT INTO datelog (country, logtype, timestamp, got)
VALUES ('it','MAZZIMAZZI','2010-01-01',0)

*/
    $MAX_RETRIES = 3;
    $WAIT_SECONDS = 5;
    date_default_timezone_set("Europe/Rome");

    if ($argc <= 1) {
      $startDate = strftime("%Y-%m-%d", strtotime(date("Y-m-d")." -3 months"));
      $endDate = date('Y-m-d').'';
    } else if ($argc <= 2) {
      $startDate=$argv[1];
      if (!is_date($startDate)) {
        show_usage($argv[0]);
        die();
      }
      $endDate=date('Y-m-d').'';
    } else if ($argc <= 3) {
      $startDate=$argv[1];
      if (!is_date($startDate)) {
        show_usage($argv[0]);
        die();
      }
      $endDate=$argv[2];
      if ($endDate == '.') {$endDate=date('Y-m-d');}
      if (!is_date($endDate)) {
        show_usage($argv[0]);
        die();
      }
    }

    if ($startDate > $endDate) {
      $t = $startDate;
      $startDate = $endDate;
      $endDate = $t;
    }

    include 'gwtdata.php';
    $pdo = new PDO('sqlite:download_tracking.db');
    $userCredentials = get_next_downloader($pdo);
    $email = $userCredentials[0];
    $password = $userCredentials[1];
    print date("Y-m-d h:i:s")." DOWNLOAD STARTED, startDate=$startDate, endDate=$endDate, user=$email =========================\n";


    $tables = array('TOTAL_QUERIES', 'TOP_PAGES', 'TOP_QUERIES');
    $cwd = getcwd();
    $gdata = new GWTdata();
    if($gdata->LogIn($email, $password) !== true) {
      print date("Y-m-d h:i:s")." GWT Login failed!\n";
      die();
    }

    $gdata->SetTables($tables);
    $sites = $gdata->GetSites();
    for ($day=$startDate; $day<=$endDate; $day=strftime("%Y-%m-%d", strtotime("$day +1 day"))) {
      try {
        echo date("Y-m-d h:i:s")." Processing day $day\n";
        chdir($cwd);
        if (!file_exists("GWT-$day")) {
          mkdir("GWT-$day");
        }
        chdir("GWT-$day");
        $daterange = array($day, $day);
        $gdata->SetDaterange($daterange);
        foreach($sites as $site)
        {
            foreach($tables as $table) {
              $gdata->SetTables(array($table));
              $domain = str_replace('http://','',$site);
              $domain = str_replace('https://','',$domain);
              $domain = str_replace('/','',$domain);
              print date("Y-m-d h:i:s")." Processing: site=$site, table=$table\t";
              if (to_be_downloaded($pdo, $day, $site, $table)) {

                $ntries = 0;
                do {
                  $success = 1;
                  $gdata->DownloadCSV($site);
                  if (!glob("$table-$domain*.csv")) {
                    $success = 0;
                    ++$ntries;
                    print "try $ntries failed. RETRYING... ";
                    sleep($WAIT_SECONDS);
                  } else {
                    increment_download_count_for_user($pdo,$email);
                  }
                } while (($ntries <= $MAX_RETRIES) and ($success == 0));

                foreach(glob("$table-$domain*.csv") as $fdownloaded) {
                  $inFor = 1;
                  $nrows = countrows($fdownloaded);
                  if ($nrows==0) {
                    print "WARNING: NO FILE FOR $day/$table/$domain\n";
                  } else if ($nrows==1) {
                    print "EMPTY FILE FOR $day/$table/$domain\n";
                    unlink($fdownloaded);
                    unset_downloaded($pdo, $day, $site, $table);
                  } else {
                    $filesize = filesize($fdownloaded);
                    set_downloaded($pdo, $day, $site, $table, $nrows-1, $filesize, $email);
                    print "OK: size=$filesize\n";
                  }
                }
                if ($success == 0) {
                  print "DOWNLOAD FAILED.\n";
                }
              } else {
                print "ALREADY DOWNLOADED.\n";
              }
            }
        }
        chdir($cwd);
        if (!glob("GWT-$day/*")) {
          rmdir("GWT-$day");
        }
      } catch (Exception $e) {
          die($e->getMessage());
      }
    }

    chdir($cwd);
    print date("Y-m-d h:i:s")." DOWNLOAD ENDED --------------------------------------\n";



    function countrows($filename) {
      if (!file_exists($filename)) {
        return 0;
      }
      $handle = fopen($filename,"r");
      $b = 0;
      while($a = fgets($handle)) {
          $b++;
      }
      return $b;
    }

    function get_next_downloader($pdo) {

      // ROUND ROBIN
      /*
      $queryText = "SELECT ifnull(max(email),(SELECT email FROM downloaders WHERE rank=(SELECT min(rank) FROM downloaders))) as email FROM downloaders WHERE email>(SELECT ifnull(max(last_downloader),'') FROM status) AND rank = (SELECT min(rank) FROM downloaders WHERE email>(SELECT ifnull(max(last_downloader),'') FROM status))";
      foreach($pdo->query($queryText) as $row) {
        $email = $row['email'];
      }
      $queryText = "SELECT password FROM downloaders WHERE email='$email'";
      foreach($pdo->query($queryText) as $row) {
        $password = $row['password'];
      }
      $queryText = "UPDATE status SET last_downloader=(SELECT ifnull(max(email),(SELECT email FROM downloaders WHERE rank=(SELECT min(rank) FROM downloaders))) FROM downloaders WHERE email>(SELECT ifnull(max(last_downloader),'') FROM status) AND rank = (SELECT min(rank) FROM downloaders WHERE email>(SELECT ifnull(max(last_downloader),'') FROM status)));";
      $pdo->exec($queryText);
      */

      // PRIORITY QUEUE
      $queryText = "SELECT d.email AS email, count(u.time_stamp) AS rank FROM downloaders d LEFT JOIN user_bandwidth u ON d.email=u.email GROUP BY d.email ORDER BY count(u.time_stamp) LIMIT 1";
      foreach($pdo->query($queryText) as $row) {
        $email = $row['email'];
      }
      $queryText = "SELECT password FROM downloaders WHERE email='$email'";
      foreach($pdo->query($queryText) as $row) {
        $password = $row['password'];
      }
      $queryText = "DELETE FROM user_bandwidth WHERE abs(julianday(CURRENT_TIMESTAMP)-julianday(time_stamp)) > 8";
      $pdo->exec($queryText);

      return array($email, $password);
    }

    function to_be_downloaded($pdo, $day, $site, $table) {
      $queryText = "SELECT IFNULL(max(got),0) AS got FROM datelog WHERE country='$site' AND logtype='$table' AND timestamp='$day'";
      foreach($pdo->query($queryText) as $row) {
        $got = $row['got'];
      }
      $result = ($got==0);
#      print "got=$got, result=$result, query=$queryText\n";
      return $result;
    }

    function set_downloaded($pdo, $day, $site, $table, $rowcount, $filesize, $email) {
      $queryText = "SELECT count(*) AS nrows FROM datelog WHERE country='$site' AND logtype='$table' AND timestamp='$day'";
      foreach($pdo->query($queryText) as $row) {
        $nrows = $row['nrows'];
      }
      if ($nrows == 0) {
        $queryText = "INSERT INTO datelog (country, logtype, timestamp, got, filesize, rowcount, downloader) VALUES ('$site', '$table','$day', 1, $filesize, $rowcount, '$email')";
      } else {
        $queryText = "UPDATE datelog SET got=1, filesize=$filesize, rowcount=$rowcount WHERE country='$site' AND logtype='$table' AND timestamp='$day'";
      }
      $pdo->exec($queryText);
    }

    function unset_downloaded($pdo, $day, $site, $table) {
      $queryText = "UPDATE datelog SET got=0 WHERE country='$site' AND logtype='$table' AND timestamp='$day'";
      $pdo->exec($queryText);
    }

    function is_date($x) {
        return (date('Y-m-d', strtotime($x)) == $x);
    }

    function show_usage($argv0) {
      print "Usage:\nphp $argv0 <start-date> [end-date]\n";
      print "<start-date> = YYYY-MM-DD\n";
      print "<end-date>   = YYYY-MM-DD or (null) or just a dot (.). If null or dot (.) it means 'till today'\n";
      print "\n";
    }


    function increment_download_count_for_user($pdo,$email) {
      $queryText = "INSERT INTO user_bandwidth (time_stamp, email) VALUES (CURRENT_TIMESTAMP, '$email')";
      $pdo->exec($queryText);
    }

?>
