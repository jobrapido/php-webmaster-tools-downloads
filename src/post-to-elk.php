<?php

$elk = $argv[1];

if (!$elk) {
  print "Specify elastic search SERVER:PORT as first parameter\n\n";
  die();
}

$prefix="";
if ($argv[2]) $prefix=$argv[2]."-";

$dirs = glob("GWT-????-??-??");
$cwd = getcwd();

foreach ($dirs as $dir) {
  $match = preg_match('/GWT-(\d\d\d\d)-(\d\d)-(\d\d)/',$dir,$res);
  if(!$match) continue;
  $y = ''.$res[1];
  $m = ''.$res[2];
  $d = ''.$res[3];
  $indexName = "$y$m$d";
  chdir($cwd);
  chdir($dir);
  $files = glob("*.csv");
  foreach ($files as $file) {
    $match = preg_match('/^([^-]+)-([^\.]+)\..*$/',$file,$res);
    if(!$match) continue;
    $docType=strtolower($res[1]);
    $country=$res[2];
    $rows = get_json_array($file, 'country', $country, 'date', "$y-$m-$d");

    // BUILD URL
    $url = "http://$elk/$prefix$docType-$indexName/gwt";
    print "log POST $url\n";
    foreach ($rows as $row) {
      $ntries = 0;
      do {
        // PUT
        $chlead = curl_init();
        curl_setopt($chlead, CURLOPT_URL, $url);
        curl_setopt($chlead, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8','Content-Length: ' . strlen($row)));
        curl_setopt($chlead, CURLOPT_VERBOSE, 0);
        curl_setopt($chlead, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chlead, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($chlead, CURLOPT_POSTFIELDS,$row);
        curl_setopt($chlead, CURLOPT_SSL_VERIFYPEER, 0);
        $chleadresult = curl_exec($chlead);
        $chleadapierr = curl_errno($chlead);
        $chleaderrmsg = curl_error($chlead);
        curl_close($chlead);
        if ($chleadapierr != 0) {
          print "DEBUG INPUT=$row\n";
          print "DEBUG: $chleadapierr, RESULT: $chleadresult\n";
        }
        $match = preg_match('/error/', $chleadresult);
        if ($match) {
          echo "LOG ERR dir: '$dir', file: '$file'\n";
          echo "LOG ERR $row\n";
          echo "LOG ERR ERROR:\n";
          echo "LOG ERR $chleadresult\n";
          echo "LOG ERR -------------------------------------------------------------------------------------\n";
        }
        if ($chleadapierr==7) {
          $ntries++;
          print "CONNECTION ERROR. RETRY N. $ntries for: $row\n";
          sleep(1);
        }
      } while ($chleadapierr==7);
    }
  }
}

function get_json_array($filename, $addParam1=null, $addValue1=null, $addParam2=null, $addValue2=null) {
  $result = array();
  $csv= file_get_contents($filename);
  $array = array_map("str_getcsv", explode("\n", $csv));
  $types = get_types($array);

  $fields = $array[0];
  if ($addParam2) {
      array_unshift($fields, $addParam2);
      array_unshift($types,"s");
  }
  if ($addParam1) {
      array_unshift($fields, $addParam1);
      array_unshift($types,"s");
  }

  for ($i=1; $i<count($array); ++$i) {
    $row = $array[$i];

    if ($addParam2) {
      array_unshift($row, $addValue2);
    }
    if ($addParam1) {
      array_unshift($row, $addValue1);
    }
    if (count($row)<count($fields)) continue;
    $json = '{';
    $flag=0;
    for ($j=0; $j<count($row); ++$j) {
      if ($fields[$j]=='Change') continue;
      if ($flag>0) $json .= ', ';
      $json .= '"'.$fields[$j].'":';// '".$row[$j].'"';
      if ($types[$j]=='s') $json .= '"';
      $value = ($types[$j]=='p' ? intval($row[$j])/100 : $row[$j]);
      if ($types[$j]=='s')
        $json .= get_quote($value);
      else
        $json .= ($value ? get_quote($value) : 'null');
      if ($types[$j]=='s') $json .= '"';
      $flag++;
    }
    $json .= '}';
    array_push($result, $json);
  }
  return $result;
}

function get_quote($value) {
  $value = str_replace("\\","",$value);
  $value = str_replace('"','\"',$value);
  $value = str_replace("\t"," ",$value);

  return $value;
}

/*
s = string
n = number
p = percentage
*/
function get_types($array) {
  $retvalue = array();
  $fields = $array[0];
  for ($f=0; $f<count($fields); ++$f) {
    $matches_percentage = 1;
    $matches_number = 1;
    for ($k=1; $k<count($array); ++$k) {
      $row = $array[$k];
      if (count($row)<count($fields)) continue;
      $mpercentage = preg_match('/(^[0-9]+%$)/',$row[$f]);
      $mnumber = preg_match('/^[0-9]*([,\.][0-9]+)?$/',$row[$f]);
      if (!$mpercentage) $matches_percentage = 0;
      if (!$mnumber) $matches_number = 0;
    }
    if ($mpercentage==1)
      $retvalue[$f]='p';
    else if ($mnumber==1)
      $retvalue[$f]='n';
    else
      $retvalue[$f]='s';
  }
  return $retvalue;
}

?>
